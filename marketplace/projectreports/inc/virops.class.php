<?php

/**
 * Live link to VMware Aria Operations / vRealize Operations ("VirOPS") through its REST API (suite-api):
 * for every vSphere cluster, the capacity and use of CPU, memory and datastore space today, and how fast use grew
 * over the last 90 days (a straight line through the daily averages). The result is kept in
 * glpi_plugin_projectreports_viropsclusters so the dashboard opens fast; it is refreshed by the "Sync now" button
 * (Forecast settings) or the console command plugins:projectreports:virops-sync (e.g. from cron, daily).
 */
class PluginProjectreportsVirops
{
    private const DAYS = 90;
    private const STATS = [
        'cpu_cap' => 'cpu|totalCapacity_average',     // MHz
        'cpu_use' => 'cpu|demandmhz',                 // MHz
        'ram_cap' => 'mem|totalCapacity_average',     // KB
        'ram_use' => 'mem|consumed_average',          // KB
        'sto_cap' => 'diskspace|total_capacity',      // GB
        'sto_use' => 'diskspace|total_usage',         // GB
        'vms' => 'summary|number_running_vms',
        'hosts' => 'summary|total_number_hosts',
        'time_left' => 'OnlineCapacityAnalytics|timeRemaining',   // days, when the capacity engine has it
    ];

    private GuzzleHttp\Client $http;
    private string $token = '';

    public function __construct(private array $cfg)
    {
        $this->http = new GuzzleHttp\Client([
            'base_uri' => rtrim((string) $cfg['virops_url'], '/') . '/suite-api/api/',
            'timeout' => 60,
            'connect_timeout' => 15,
            'verify' => (bool) $cfg['virops_verify_ssl'],
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        ]);
    }

    private function call(string $method, string $path, array $options = []): array
    {
        if ($this->token !== '') {
            $options['headers']['Authorization'] = 'vRealizeOpsToken ' . $this->token;
        }
        $res = $this->http->request($method, $path, $options);
        return json_decode((string) $res->getBody(), true) ?: [];
    }

    public function login(): void
    {
        $body = ['username' => $this->cfg['virops_user'], 'password' => $this->cfg['virops_password']];
        if (trim((string) $this->cfg['virops_authsource']) !== '' && strcasecmp($this->cfg['virops_authsource'], 'Local Users') !== 0) {
            $body['authSource'] = $this->cfg['virops_authsource'];
        }
        $r = $this->call('POST', 'auth/token/acquire', ['json' => $body]);
        $this->token = (string) ($r['token'] ?? '');
        if ($this->token === '') {
            throw new RuntimeException('VirOPS did not return a token.');
        }
    }

    /** @return array<string, array{name: string, parent: string}> resource id => cluster */
    public function clusters(): array
    {
        $out = [];
        $page = 0;
        do {
            $r = $this->call('GET', 'resources', ['query' => ['resourceKind' => 'ClusterComputeResource', 'adapterKind' => 'VMWARE', 'pageSize' => 1000, 'page' => $page]]);
            foreach ($r['resourceList'] ?? [] as $c) {
                $parent = '';
                foreach ($c['resourceKey']['resourceIdentifiers'] ?? [] as $idf) {
                    if (($idf['identifierType']['name'] ?? '') === 'VMEntityVCID') {
                        $parent = (string) ($idf['value'] ?? '');
                    }
                }
                $out[(string) $c['identifier']] = ['name' => (string) ($c['resourceKey']['name'] ?? $c['identifier']), 'parent' => $parent];
            }
            $total = (int) ($r['pageInfo']['totalCount'] ?? 0);
            $page++;
        } while ($page * 1000 < $total && $page < 20);
        return $out;
    }

    /** Daily averages of the stats for the given resources: id => statKey => [[timestamp ms, value], ...] */
    public function history(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $query = 'begin=' . ((time() - self::DAYS * 86400) * 1000) . '&end=' . (time() * 1000)
                . '&rollUpType=AVG&intervalType=DAYS&intervalQuantifier=1';
            foreach ($chunk as $id) {
                $query .= '&resourceId=' . rawurlencode($id);
            }
            foreach (self::STATS as $key) {
                $query .= '&statKey=' . rawurlencode($key);
            }
            $r = $this->call('GET', 'resources/stats?' . $query);
            foreach ($r['values'] ?? [] as $res) {
                $id = (string) ($res['resourceId'] ?? '');
                foreach ($res['stat-list']['stat'] ?? [] as $st) {
                    $key = (string) ($st['statKey']['key'] ?? '');
                    $points = [];
                    foreach ($st['timestamps'] ?? [] as $i => $ts) {
                        if (isset($st['data'][$i]) && is_numeric($st['data'][$i])) {
                            $points[] = [(float) $ts, (float) $st['data'][$i]];
                        }
                    }
                    $out[$id][$key] = $points;
                }
            }
        }
        return $out;
    }

    /** Growth per 30 days of a series of [timestamp ms, value] (least squares), and its last value. */
    public static function trend(array $points): array
    {
        $n = count($points);
        if ($n === 0) {
            return [0.0, 0.0];
        }
        $last = $points[$n - 1][1];
        if ($n < 2) {
            return [$last, 0.0];
        }
        $mx = array_sum(array_column($points, 0)) / $n;
        $my = array_sum(array_column($points, 1)) / $n;
        $num = $den = 0.0;
        foreach ($points as [$x, $y]) {
            $num += ($x - $mx) * ($y - $my);
            $den += ($x - $mx) ** 2;
        }
        $per_ms = $den > 0 ? $num / $den : 0.0;
        return [$last, $per_ms * 30 * 86400 * 1000];
    }

    /** Fetches every cluster and replaces the cached figures. Returns the number of clusters. */
    public static function sync(): int
    {
        /** @var DBmysql $DB */
        global $DB;
        PluginProjectreportsForecast::ensureSchema();
        $cfg = PluginProjectreportsForecast::config(true);
        if (trim((string) $cfg['virops_url']) === '') {
            throw new RuntimeException('VirOPS address is not set (Forecast settings).');
        }
        try {
            $api = new self($cfg);
            $api->login();
            $clusters = $api->clusters();
            $hist = $clusters ? $api->history(array_keys($clusters)) : [];
            $DB->delete(PluginProjectreportsForecast::CLUSTERS, ['source' => 'virops']);
            $now = date('Y-m-d H:i:s');
            foreach ($clusters as $id => $c) {
                $s = $hist[$id] ?? [];
                $t = fn($k) => self::trend($s[self::STATS[$k]] ?? []);
                [$cpu_cap] = $t('cpu_cap');
                [$cpu_use, $cpu_g] = $t('cpu_use');
                [$ram_cap] = $t('ram_cap');
                [$ram_use, $ram_g] = $t('ram_use');
                [$sto_cap] = $t('sto_cap');
                [$sto_use, $sto_g] = $t('sto_use');
                [$vms] = $t('vms');
                [$hosts] = $t('hosts');
                [$left] = $t('time_left');
                $DB->insert(PluginProjectreportsForecast::CLUSTERS, [
                    'cluster' => mb_substr($c['name'], 0, 255), 'parent' => mb_substr($c['parent'], 0, 255),
                    'hosts' => (int) round($hosts), 'vms' => (int) round($vms),
                    'cpu_cap_ghz' => round($cpu_cap / 1000, 2), 'cpu_use_ghz' => round($cpu_use / 1000, 2), 'cpu_growth_ghz' => round($cpu_g / 1000, 4),
                    'ram_cap_gb' => round($ram_cap / 1048576, 2), 'ram_use_gb' => round($ram_use / 1048576, 2), 'ram_growth_gb' => round($ram_g / 1048576, 4),
                    'sto_cap_tb' => round($sto_cap / 1000, 3), 'sto_use_tb' => round($sto_use / 1000, 3), 'sto_growth_tb' => round($sto_g / 1000, 5),
                    'time_remaining_days' => isset($s[self::STATS['time_left']]) && $s[self::STATS['time_left']] ? (int) round($left) : null,
                    'source' => 'virops', 'date_sync' => $now,
                ]);
            }
            PluginProjectreportsForecast::setConfig(['virops_last_sync' => date('Y-m-d H:i'), 'virops_last_error' => '']);
            return count($clusters);
        } catch (Throwable $e) {
            $msg = $e instanceof GuzzleHttp\Exception\RequestException && $e->hasResponse()
                ? $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ' (' . $e->getRequest()->getUri()->getPath() . ')'
                : $e->getMessage();
            PluginProjectreportsForecast::setConfig(['virops_last_error' => date('Y-m-d H:i') . ': ' . mb_substr($msg, 0, 400)]);
            throw new RuntimeException($msg, 0, $e);
        }
    }

    /** Checks the address and the account without changing anything. */
    public static function test(): string
    {
        $api = new self(PluginProjectreportsForecast::config(true));
        $api->login();
        return sprintf('Connected. %d cluster(s) found.', count($api->clusters()));
    }
}
