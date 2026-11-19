<?php

/**
 * Infrastructure forecast (Tools > Reporting dashboard > پیش‌بینی): what the next 12 months need, from
 *   1. open capacity requests (teams and swaps),
 *   2. device age (DCIM): devices that reach their type's age limit within the period,
 *   3. VirOPS: VM growth per cluster (live API, see PluginProjectreportsVirops),
 *   4. the delivery trend of past years (iCare export, imported here),
 *   5. the warehouse stock, taken off the need.
 * Settings and the iCare history are kept by this class; the tables are created on first use, so updating the plugin
 * files is enough (no new plugin version to install).
 */
class PluginProjectreportsForecast
{
    public const CLUSTERS = 'glpi_plugin_projectreports_viropsclusters';
    public const ICARE = 'glpi_plugin_projectreports_icare';
    private const CONTEXT = 'plugin:projectreports';

    /** Settings with their defaults. */
    public const DEFAULTS = [
        'fc_age_server' => 5, 'fc_age_storage' => 6, 'fc_age_network' => 7,   // years before a device is due for replacement
        'fc_target_util' => 80,                                                // % of a cluster's capacity we plan to use at most
        'virops_url' => '', 'virops_user' => '', 'virops_password' => '', 'virops_authsource' => 'Local Users',
        'virops_verify_ssl' => 1, 'virops_last_sync' => '', 'virops_last_error' => '',
        'icare_imported' => '', 'icare_file' => '',
    ];

    public static function ensureSchema(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign = DBConnection::getDefaultPrimaryKeySignOption();
        if (!$DB->tableExists(self::CLUSTERS, false)) {
            $DB->doQuery("CREATE TABLE `" . self::CLUSTERS . "` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `cluster` varchar(255) NOT NULL DEFAULT '',
                `parent` varchar(255) NOT NULL DEFAULT '',
                `hosts` int NOT NULL DEFAULT '0',
                `vms` int NOT NULL DEFAULT '0',
                `cpu_cap_ghz` decimal(14,2) NOT NULL DEFAULT '0.00',
                `cpu_use_ghz` decimal(14,2) NOT NULL DEFAULT '0.00',
                `cpu_growth_ghz` decimal(14,4) NOT NULL DEFAULT '0.0000',
                `ram_cap_gb` decimal(14,2) NOT NULL DEFAULT '0.00',
                `ram_use_gb` decimal(14,2) NOT NULL DEFAULT '0.00',
                `ram_growth_gb` decimal(14,4) NOT NULL DEFAULT '0.0000',
                `sto_cap_tb` decimal(14,3) NOT NULL DEFAULT '0.000',
                `sto_use_tb` decimal(14,3) NOT NULL DEFAULT '0.000',
                `sto_growth_tb` decimal(14,5) NOT NULL DEFAULT '0.00000',
                `time_remaining_days` int DEFAULT NULL,
                `source` varchar(20) NOT NULL DEFAULT 'virops',
                `date_sync` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
        if (!$DB->tableExists(self::ICARE, false)) {
            $DB->doQuery("CREATE TABLE `" . self::ICARE . "` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `request_no` varchar(100) NOT NULL DEFAULT '',
                `date` date DEFAULT NULL,
                `year` int NOT NULL DEFAULT '0',
                `resource` varchar(20) NOT NULL DEFAULT 'Other',
                `quantity` decimal(14,3) NOT NULL DEFAULT '0.000',
                `request_type` varchar(100) NOT NULL DEFAULT '',
                `project` varchar(255) NOT NULL DEFAULT '',
                `item` varchar(255) NOT NULL DEFAULT '',
                `is_demo` tinyint NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `year` (`year`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->dropTable(self::CLUSTERS, true);
        $DB->dropTable(self::ICARE, true);
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
    }

    /** Settings; the VirOPS password stays encrypted unless $with_secret. */
    public static function config(bool $with_secret = false): array
    {
        $cfg = Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS)) + self::DEFAULTS;
        if ($with_secret) {
            $cfg['virops_password'] = (string) ((new GLPIKey())->decrypt((string) $cfg['virops_password']) ?? '');
        } else {
            $cfg['virops_password'] = $cfg['virops_password'] !== '' ? '********' : '';
        }
        return $cfg;
    }

    public static function setConfig(array $values): void
    {
        $keep = array_intersect_key($values, self::DEFAULTS);
        if (array_key_exists('virops_password', $keep)) {
            $keep['virops_password'] = $keep['virops_password'] === '' ? '' : (new GLPIKey())->encrypt((string) $keep['virops_password']);
        }
        Config::setConfigurationValues(self::CONTEXT, $keep);
    }

    public static function canConfigure(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    // ---------------------------------------------------------------- iCare history import

    /** Header words (lower case, Persian or English) that name each column of the iCare export. */
    private const COLUMNS = [
        'date' => ['تاریخ تحویل', 'تاریخ ارسال', 'تاریخ', 'delivery date', 'delivered', 'date', 'created', 'closed'],
        'year' => ['سال', 'year'],
        'resource' => ['نوع منبع', 'نوع تجهیز', 'نوع کالا', 'نوع', 'منبع', 'resource', 'category', 'item type', 'type'],
        'quantity' => ['تعداد', 'مقدار', 'quantity', 'qty', 'count'],
        'unit' => ['واحد', 'unit'],
        'request_no' => ['شماره درخواست', 'شماره', 'request', 'ticket', 'id'],
        'request_type' => ['نوع درخواست', 'request type'],
        'project' => ['پروژه', 'project'],
        'item' => ['شرح', 'عنوان', 'تجهیز', 'description', 'title', 'item', 'model'],
    ];

    /**
     * Reads an iCare export (CSV or Excel) into rows [request_no, date, year, resource, quantity, request_type, project, item].
     * Dates may be Jalali (1403/05/12) or Gregorian; storage given in GB is turned into TB.
     * @return array{rows: array, skipped: int, columns: array}
     */
    public static function readIcare(string $path, string $name): array
    {
        $table = [];
        if (preg_match('/\.xlsx?$/i', $name)) {
            $reader = PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
            foreach ($sheet->toArray(null, true, false, false) as $r) {
                $table[] = array_map(fn($v) => is_float($v) || is_int($v) ? $v : trim((string) $v), $r);
            }
        } else {
            $raw = (string) file_get_contents($path);
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            $first = strtok($raw, "\n");
            $delim = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (substr_count((string) $first, "\t") > substr_count((string) $first, ',') ? "\t" : ',');
            $fh = fopen('php://temp', 'r+');
            fwrite($fh, $raw);
            rewind($fh);
            while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
                $table[] = array_map(fn($v) => trim((string) $v), $r);
            }
            fclose($fh);
        }
        // header = the first row that names at least a date or year column and a quantity column
        $map = [];
        $start = 0;
        foreach ($table as $i => $r) {
            $found = self::matchColumns($r);
            if ((isset($found['date']) || isset($found['year'])) && isset($found['quantity'])) {
                $map = $found;
                $start = $i + 1;
                break;
            }
            if ($i > 10) {
                break;
            }
        }
        if (!$map) {
            throw new RuntimeException('Could not find the columns: the file needs a date (or year) column and a quantity column, e.g. "تاریخ" and "تعداد".');
        }
        $rows = [];
        $skipped = 0;
        foreach (array_slice($table, $start) as $r) {
            $get = fn($k) => isset($map[$k]) ? ($r[$map[$k]] ?? '') : '';
            $qty = self::number($get('quantity'));
            $date = self::toDate($get('date'));
            $year = $date ? (int) substr($date, 0, 4) : self::toYear($get('year'));
            if ($qty === null || $qty <= 0 || !$year) {
                $skipped++;
                continue;
            }
            $resource = self::resource((string) $get('resource') . ' ' . (string) $get('item') . ' ' . (string) $get('unit'));
            if ($resource === 'Storage' && preg_match('/\bGB\b|گیگ/i', (string) $get('unit'))) {
                $qty /= 1000;
            }
            $rows[] = ['request_no' => mb_substr((string) $get('request_no'), 0, 100), 'date' => $date, 'year' => $year, 'resource' => $resource,
                'quantity' => round($qty, 3), 'request_type' => mb_substr((string) $get('request_type'), 0, 100),
                'project' => mb_substr((string) $get('project'), 0, 255), 'item' => mb_substr((string) $get('item'), 0, 255)];
        }
        return ['rows' => $rows, 'skipped' => $skipped, 'columns' => array_keys($map)];
    }

    /** Replaces the imported iCare history (the sample history stays apart). */
    public static function saveIcare(array $rows, string $file): int
    {
        /** @var DBmysql $DB */
        global $DB;
        self::ensureSchema();
        $DB->delete(self::ICARE, ['is_demo' => 0]);
        foreach ($rows as $r) {
            $DB->insert(self::ICARE, $r + ['is_demo' => 0]);
        }
        self::setConfig(['icare_imported' => date('Y-m-d H:i'), 'icare_file' => $file]);
        return count($rows);
    }

    public static function deleteIcare(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        self::ensureSchema();
        $DB->delete(self::ICARE, ['is_demo' => 0]);
        self::setConfig(['icare_imported' => '', 'icare_file' => '']);
    }

    private static function matchColumns(array $header): array
    {
        $found = [];
        $norm = fn($s) => mb_strtolower(trim(str_replace(["\u{200c}", '_', '-'], [' ', ' ', ' '], (string) $s)));
        foreach (self::COLUMNS as $key => $words) {
            foreach ($words as $w) {     // the longest, most precise words first
                foreach ($header as $i => $h) {
                    if (in_array($i, $found, true)) {
                        continue;
                    }
                    $hn = $norm($h);
                    if ($hn !== '' && ($hn === $w || str_contains($hn, $w))) {
                        $found[$key] = $i;
                        continue 3;
                    }
                }
            }
        }
        return $found;
    }

    public static function resource(string $text): string
    {
        $t = mb_strtolower($text);
        return match (true) {
            (bool) preg_match('/gpu|گرافیک/u', $t) => 'GPU',
            (bool) preg_match('/storage|san\b|nas\b|ذخیره|استوریج|\btb\b|ترابایت/u', $t) => 'Storage',
            (bool) preg_match('/switch|router|firewall|network|سوئیچ|سوییچ|روتر|فایروال|شبکه/u', $t) => 'Network',
            (bool) preg_match('/server|compute|host|blade|سرور|هاست|محاسبات/u', $t) => 'Compute',
            default => 'Other',
        };
    }

    private static function number($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = strtr((string) $v, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => '', ',' => '', '٫' => '.']);
        return preg_match('/-?\d+(\.\d+)?/', $s, $m) ? (float) $m[0] : null;
    }

    private static function toYear($v): int
    {
        $y = (int) self::number($v);
        if ($y >= 1300 && $y < 1500) {      // Jalali year: the Gregorian year it mostly falls in
            return $y + 621;
        }
        return $y >= 1990 && $y < 2100 ? $y : 0;
    }

    /** A date as Y-m-d: Jalali (1403/05/12), Gregorian (2024-08-02, 02/08/2024) or an Excel serial number. */
    public static function toDate($v): ?string
    {
        if (is_int($v) || is_float($v)) {
            if ($v > 20000 && $v < 80000) {
                return PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d');
            }
            return null;
        }
        $s = strtr(trim((string) $v), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y >= 1300 && $y < 1500) {
                [$y, $mo, $d] = self::jalaliToGregorian($y, $mo, $d);
            }
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = (($gy % 4 == 0) && ($gy % 100 != 0)) || ($gy % 400 == 0);
        $months = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) {
            $gd -= $months[$gm];
        }
        return [$gy, $gm, $gd];
    }

    // ---------------------------------------------------------------- data for the dashboard

    public static function data(): array
    {
        /** @var array $CFG_GLPI */
        /** @var DBmysql $DB */
        global $CFG_GLPI, $DB;
        self::ensureSchema();
        $cfg = self::config();
        $clusters = [];
        foreach ($DB->request(['FROM' => self::CLUSTERS, 'ORDER' => 'cluster']) as $c) {
            foreach ($c as $k => $v) {
                if (!in_array($k, ['cluster', 'parent', 'source', 'date_sync'], true) && $v !== null) {
                    $c[$k] = (float) $v;
                }
            }
            $clusters[] = $c;
        }
        $trend = [];
        foreach ($DB->request(['SELECT' => ['year', 'resource', 'SUM' => 'quantity AS qty', 'COUNT' => 'id AS n'], 'FROM' => self::ICARE,
            'GROUPBY' => ['year', 'resource'], 'ORDER' => 'year']) as $r) {
            $trend[] = ['year' => (int) $r['year'], 'resource' => $r['resource'], 'qty' => (float) $r['qty'], 'n' => (int) $r['n']];
        }
        $demo = (int) $DB->request(['COUNT' => 'c', 'FROM' => self::ICARE, 'WHERE' => ['is_demo' => 1]])->current()['c'];
        return [
            'ages' => ['Server' => (float) $cfg['fc_age_server'], 'Storage' => (float) $cfg['fc_age_storage'], 'Network' => (float) $cfg['fc_age_network']],
            'target_util' => (float) $cfg['fc_target_util'],
            'virops' => ['configured' => $cfg['virops_url'] !== '', 'last_sync' => $cfg['virops_last_sync'], 'last_error' => $cfg['virops_last_error'],
                'clusters' => $clusters, 'demo' => (bool) array_filter($clusters, fn($c) => $c['source'] === 'demo')],
            'icare' => ['imported' => $cfg['icare_imported'], 'file' => $cfg['icare_file'], 'trend' => $trend, 'demo' => $demo > 0],
            'settings_url' => self::canConfigure() ? $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/forecast.php' : '',
        ];
    }
}
