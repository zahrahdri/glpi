<?php

/**
 * Infrastructure forecast (Tools > Reporting dashboard > پیش‌بینی): what the next 12 months need, from
 *   1. open capacity requests (teams and swaps),
 *   2. device age (DCIM Excel export, else GLPI's assets): devices that reach their type's age limit within the period,
 *   3. VirOPS: capacity, use and monthly growth per VM cluster (Excel / CSV export, imported here),
 *   4. the delivery trend of past years (iCare export, imported here),
 *   5. the warehouse stock, taken off the need.
 * Settings, the VirOPS figures and the iCare history are kept by this class; the tables are created on first use, so updating the plugin
 * files is enough (no new plugin version to install).
 */
class PluginProjectreportsForecast
{
    public const CLUSTERS = 'glpi_plugin_projectreports_viropsclusters';
    public const ICARE = 'glpi_plugin_projectreports_icare';
    public const DCIM = 'glpi_plugin_projectreports_dcim';
    private const CONTEXT = 'plugin:projectreports';

    /** Settings with their defaults. */
    public const DEFAULTS = [
        'fc_age_server' => 5, 'fc_age_storage' => 6, 'fc_age_network' => 7,   // years before a device is due for replacement
        'fc_target_util' => 80,                                                // % of a cluster's capacity we plan to use at most
        'virops_imported' => '', 'virops_file' => '',
        'icare_imported' => '', 'icare_file' => '',
        'dcim_imported' => '', 'dcim_file' => '',
    ];
    /** Settings of the former live VirOPS link, removed on install. */
    public const OLD_KEYS = ['virops_url', 'virops_user', 'virops_password', 'virops_authsource', 'virops_verify_ssl', 'virops_last_sync', 'virops_last_error'];

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
        if (!$DB->tableExists(self::DCIM, false)) {
            $DB->doQuery("CREATE TABLE `" . self::DCIM . "` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `hostname` varchar(255) NOT NULL DEFAULT '',
                `kind` varchar(20) NOT NULL DEFAULT 'Other',
                `type` varchar(100) NOT NULL DEFAULT '',
                `model` varchar(255) NOT NULL DEFAULT '',
                `manufacturer` varchar(255) NOT NULL DEFAULT '',
                `serial` varchar(255) NOT NULL DEFAULT '',
                `in_service` date DEFAULT NULL,
                `location` varchar(255) NOT NULL DEFAULT '',
                `rack` varchar(100) NOT NULL DEFAULT '',
                `status` varchar(100) NOT NULL DEFAULT '',
                `in_use` tinyint NOT NULL DEFAULT '1',
                `is_demo` tinyint NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `hostname` (`hostname`)
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
        $DB->dropTable(self::DCIM, true);
        Config::deleteConfigurationValues(self::CONTEXT, array_merge(array_keys(self::DEFAULTS), self::OLD_KEYS));
    }

    public static function config(): array
    {
        return Config::getConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS)) + self::DEFAULTS;
    }

    public static function setConfig(array $values): void
    {
        Config::setConfigurationValues(self::CONTEXT, array_intersect_key($values, self::DEFAULTS));
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
        $table = self::readTable($path, $name);
        // header = the first row that names at least a date or year column and a quantity column
        $map = [];
        $start = 0;
        foreach ($table as $i => $r) {
            $found = self::matchColumns($r, self::COLUMNS);
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

    /** Rows of the first sheet of an Excel file, or of a CSV file (comma, semicolon or tab). */
    private static function readTable(string $path, string $name): array
    {
        $table = [];
        if (preg_match('/\.xlsx?$/i', $name)) {
            $reader = PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
            foreach ($sheet->toArray(null, true, false, false) as $r) {
                $table[] = array_map(fn($v) => is_float($v) || is_int($v) ? $v : trim((string) $v), $r);
            }
            return $table;
        }
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $first = (string) strtok($raw, "\n");
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $table[] = array_map(fn($v) => trim((string) $v), $r);
        }
        fclose($fh);
        return $table;
    }

    // ---------------------------------------------------------------- VirOPS cluster export import

    /** Header words of the VirOPS cluster export (capacity and use now; growth per month, optional). */
    private const CLUSTER_COLUMNS = [
        'cluster' => ['کلاستر', 'cluster', 'name'],
        'hosts' => ['تعداد هاست', 'هاست', 'hosts', 'host'],
        'vms' => ['تعداد vm', 'ماشین مجازی', 'vms', 'vm'],
        'cpu_growth_ghz' => ['رشد cpu', 'cpu growth'],
        'cpu_use_ghz' => ['مصرف cpu', 'cpu used', 'cpu use', 'cpu demand'],
        'cpu_cap_ghz' => ['ظرفیت cpu', 'cpu capacity', 'cpu total'],
        'ram_growth_gb' => ['رشد ram', 'رشد حافظه', 'ram growth', 'memory growth'],
        'ram_use_gb' => ['مصرف ram', 'مصرف حافظه', 'ram used', 'ram use', 'memory used', 'memory consumed'],
        'ram_cap_gb' => ['ظرفیت ram', 'ظرفیت حافظه', 'ram capacity', 'memory capacity', 'ram total', 'memory total'],
        'sto_growth_tb' => ['رشد فضا', 'رشد ذخیره', 'storage growth', 'disk growth', 'datastore growth'],
        'sto_use_tb' => ['مصرف فضا', 'فضای مصرفی', 'مصرف ذخیره', 'storage used', 'disk used', 'datastore used'],
        'sto_cap_tb' => ['ظرفیت فضا', 'ظرفیت ذخیره', 'storage capacity', 'disk capacity', 'datastore capacity', 'storage total'],
    ];

    /**
     * Reads the VirOPS export: one row per cluster. CPU in GHz (MHz when the header says MHz), RAM in GB (TB when it
     * says TB), storage in TB (GB when it says GB); growth = how much use grows per month.
     * @return array{rows: array, skipped: int, columns: array}
     */
    public static function readClusters(string $path, string $name): array
    {
        $table = self::readTable($path, $name);
        $map = [];
        $start = 0;
        foreach ($table as $i => $r) {
            $found = self::matchColumns($r, self::CLUSTER_COLUMNS);
            if (isset($found['cluster']) && (isset($found['cpu_cap_ghz']) || isset($found['ram_cap_gb']) || isset($found['sto_cap_tb']))) {
                $map = $found;
                $start = $i + 1;
                $header = $r;
                break;
            }
            if ($i > 10) {
                break;
            }
        }
        if (!$map) {
            throw new RuntimeException('ستون‌ها شناخته نشد: فایل باید ستون «کلاستر» و دست‌کم یکی از ستون‌های ظرفیت (CPU، RAM یا فضا) را داشته باشد. نمونه فایل را ببینید.');
        }
        // unit of each column from its header
        $factor = [];
        foreach ($map as $key => $col) {
            $h = mb_strtolower((string) ($header[$col] ?? ''));
            $factor[$key] = match (true) {
                str_starts_with($key, 'cpu') && str_contains($h, 'mhz') => 0.001,
                str_starts_with($key, 'ram') && str_contains($h, 'tb') => 1024,
                str_starts_with($key, 'ram') && str_contains($h, 'mb') => 1 / 1024,
                str_starts_with($key, 'sto') && preg_match('/\bgb\b|گیگ/u', $h) => 0.001,
                default => 1,
            };
        }
        $rows = [];
        $skipped = 0;
        foreach (array_slice($table, $start) as $r) {
            $name_v = trim((string) ($r[$map['cluster']] ?? ''));
            if ($name_v === '') {
                $skipped++;
                continue;
            }
            $row = ['cluster' => mb_substr($name_v, 0, 255)];
            foreach (array_keys(self::CLUSTER_COLUMNS) as $key) {
                if ($key === 'cluster') {
                    continue;
                }
                $v = isset($map[$key]) ? self::number($r[$map[$key]] ?? '') : null;
                $row[$key] = $v === null ? 0 : ($key === 'hosts' || $key === 'vms' ? (int) round($v) : round($v * $factor[$key], 5));
            }
            $rows[] = $row;
        }
        return ['rows' => $rows, 'skipped' => $skipped, 'columns' => array_keys($map)];
    }

    /** Replaces the imported clusters (the sample clusters stay apart). */
    public static function saveClusters(array $rows, string $file): int
    {
        /** @var DBmysql $DB */
        global $DB;
        self::ensureSchema();
        $DB->delete(self::CLUSTERS, ['NOT' => ['source' => 'demo']]);
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $DB->insert(self::CLUSTERS, $r + ['source' => 'excel', 'date_sync' => $now]);
        }
        self::setConfig(['virops_imported' => date('Y-m-d H:i'), 'virops_file' => $file]);
        return count($rows);
    }

    // ---------------------------------------------------------------- DCIM device list import

    /** Header words of the DCIM device export. */
    private const DCIM_COLUMNS = [
        'in_service' => ['تاریخ بهره‌برداری', 'تاریخ بهره برداری', 'تاریخ نصب', 'تاریخ راه‌اندازی', 'تاریخ راه اندازی', 'تاریخ شروع', 'تاریخ خرید', 'تاریخ',
            'in service', 'commission', 'install date', 'installed', 'go live', 'purchase date', 'date'],
        'age' => ['عمر', 'age'],
        'hostname' => ['نام دستگاه', 'نام میزبان', 'hostname', 'host name', 'device name', 'نام', 'name', 'device'],
        'type' => ['نوع دستگاه', 'نوع تجهیز', 'نوع', 'device type', 'category', 'class', 'type'],
        'model' => ['مدل', 'model'],
        'manufacturer' => ['سازنده', 'برند', 'manufacturer', 'vendor', 'make'],
        'serial' => ['سریال', 'serial'],
        'rack' => ['رک', 'rack', 'cabinet'],
        'location' => ['دیتاسنتر', 'سایت', 'محل', 'location', 'site', 'data center', 'datacenter', 'room'],
        'status' => ['وضعیت', 'status', 'state', 'lifecycle'],
    ];

    /** Server / Storage / Network from a device type text, else Other (not counted). */
    public static function deviceKind(string $text): string
    {
        $t = mb_strtolower($text);
        return match (true) {
            (bool) preg_match('/storage|\bsan\b|\bnas\b|ذخیره|استوریج|disk array|backup appliance/u', $t) => 'Storage',
            (bool) preg_match('/switch|router|firewall|load ?balancer|network|سوئیچ|سوییچ|روتر|فایروال|شبکه/u', $t) => 'Network',
            (bool) preg_match('/server|host|blade|hypervisor|compute|سرور|هاست|بلید/u', $t) => 'Server',
            default => 'Other',
        };
    }

    /**
     * Reads the DCIM export: one row per device with its type and in-service date (Jalali, Gregorian or an Excel
     * date), or its age in years. Devices whose status says stock, spare, retired or decommissioned are kept but not
     * counted as in use.
     * @return array{rows: array, skipped: int, columns: array}
     */
    public static function readDcim(string $path, string $name): array
    {
        $table = self::readTable($path, $name);
        $map = [];
        $start = 0;
        foreach ($table as $i => $r) {
            $found = self::matchColumns($r, self::DCIM_COLUMNS);
            if (isset($found['hostname']) && isset($found['type']) && (isset($found['in_service']) || isset($found['age']))) {
                $map = $found;
                $start = $i + 1;
                break;
            }
            if ($i > 10) {
                break;
            }
        }
        if (!$map) {
            throw new RuntimeException('ستون‌ها شناخته نشد: فایل DCIM باید ستون‌های «نام دستگاه»، «نوع» و «تاریخ بهره‌برداری» (یا «عمر») را داشته باشد. نمونه فایل را ببینید.');
        }
        $rows = [];
        $skipped = 0;
        foreach (array_slice($table, $start) as $r) {
            $get = fn($k) => isset($map[$k]) ? ($r[$map[$k]] ?? '') : '';
            $host = trim((string) $get('hostname'));
            $date = self::toDate($get('in_service'));
            if ($date === null && is_numeric(self::number($get('age')))) {
                $age = (float) self::number($get('age'));
                $date = $age > 0 && $age < 60 ? date('Y-m-d', strtotime('-' . (int) round($age * 365.25) . ' day')) : null;
            }
            if ($host === '' || $date === null) {
                $skipped++;
                continue;
            }
            $status = trim((string) $get('status'));
            $rows[] = [
                'hostname' => mb_substr($host, 0, 255), 'kind' => self::deviceKind((string) $get('type') . ' ' . (string) $get('model')),
                'type' => mb_substr(trim((string) $get('type')), 0, 100), 'model' => mb_substr(trim((string) $get('model')), 0, 255),
                'manufacturer' => mb_substr(trim((string) $get('manufacturer')), 0, 255), 'serial' => mb_substr(trim((string) $get('serial')), 0, 255),
                'in_service' => $date, 'location' => mb_substr(trim((string) $get('location')), 0, 255), 'rack' => mb_substr(trim((string) $get('rack')), 0, 100),
                'status' => mb_substr($status, 0, 100),
                'in_use' => preg_match('/stock|spare|retired|decommission|disposed|removed|broken|انبار|یدک|اسقاط|خارج از سرویس|جمع‌آوری|خراب/iu', $status) ? 0 : 1,
            ];
        }
        return ['rows' => $rows, 'skipped' => $skipped, 'columns' => array_keys($map)];
    }

    /** Replaces the imported DCIM list (the sample list stays apart). */
    public static function saveDcim(array $rows, string $file): int
    {
        /** @var DBmysql $DB */
        global $DB;
        self::ensureSchema();
        $DB->delete(self::DCIM, ['is_demo' => 0]);
        foreach ($rows as $r) {
            $DB->insert(self::DCIM, $r + ['is_demo' => 0]);
        }
        self::setConfig(['dcim_imported' => date('Y-m-d H:i'), 'dcim_file' => $file]);
        return count($rows);
    }

    public static function deleteDcim(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        self::ensureSchema();
        $DB->delete(self::DCIM, ['is_demo' => 0]);
        self::setConfig(['dcim_imported' => '', 'dcim_file' => '']);
    }

    public static function deleteClusters(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        self::ensureSchema();
        $DB->delete(self::CLUSTERS, ['NOT' => ['source' => 'demo']]);
        self::setConfig(['virops_imported' => '', 'virops_file' => '']);
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

    private static function matchColumns(array $header, array $columns): array
    {
        $found = [];
        $norm = fn($s) => mb_strtolower(trim(str_replace(["\u{200c}", '_', '-'], [' ', ' ', ' '], (string) $s)));
        foreach ($columns as $key => $words) {
            foreach ($words as $w) {     // the longest, most precise words first
                foreach ($header as $i => $h) {
                    if (in_array($i, $found, true)) {
                        continue;
                    }
                    $hn = $norm($h);
                    // short words (age, vm, id, qty ...) only as a whole word, so "age" does not match "storage" or "usage"
                    $hit = mb_strlen($w) <= 4 && preg_match('/^[a-z]+$/', $w) ? (bool) preg_match('/(^|[^a-z])' . $w . '([^a-z]|$)/', $hn) : str_contains($hn, $w);
                    if ($hn !== '' && ($hn === $w || $hit)) {
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

    /** The DCIM device list for the age check: the imported file, else the sample list; empty = use GLPI's assets. */
    private static function dcimData(array $cfg): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $real = $DB->request(['COUNT' => 'c', 'FROM' => self::DCIM, 'WHERE' => ['is_demo' => 0]])->current()['c'] > 0;
        $rows = [];
        foreach ($DB->request(['FROM' => self::DCIM, 'WHERE' => ['is_demo' => $real ? 0 : 1], 'ORDER' => 'in_service']) as $r) {
            unset($r['id'], $r['is_demo']);
            $r['in_use'] = (int) $r['in_use'];
            $rows[] = $r;
        }
        return ['imported' => $cfg['dcim_imported'], 'file' => $cfg['dcim_file'], 'demo' => !$real && $rows, 'rows' => $rows];
    }

    public static function data(): array
    {
        /** @var array $CFG_GLPI */
        /** @var DBmysql $DB */
        global $CFG_GLPI, $DB;
        self::ensureSchema();
        $cfg = self::config();
        // once a real file is imported, the DEMO sample of the same kind is left out
        $real_clusters = $DB->request(['COUNT' => 'c', 'FROM' => self::CLUSTERS, 'WHERE' => ['NOT' => ['source' => 'demo']]])->current()['c'] > 0;
        $real_icare = $DB->request(['COUNT' => 'c', 'FROM' => self::ICARE, 'WHERE' => ['is_demo' => 0]])->current()['c'] > 0;
        $clusters = [];
        foreach ($DB->request(['FROM' => self::CLUSTERS, 'WHERE' => $real_clusters ? ['NOT' => ['source' => 'demo']] : [], 'ORDER' => 'cluster']) as $c) {
            foreach ($c as $k => $v) {
                if (!in_array($k, ['cluster', 'parent', 'source', 'date_sync'], true) && $v !== null) {
                    $c[$k] = (float) $v;
                }
            }
            $clusters[] = $c;
        }
        $trend = [];
        // deliveries per month (rows with only a year count in its middle); the dashboard sums them into 12-month periods
        $res = $DB->doQuery("SELECT DATE_FORMAT(COALESCE(`date`, MAKEDATE(`year`, 182)), '%Y-%m') AS ym, resource, SUM(quantity) AS qty, COUNT(*) AS n
            FROM `" . self::ICARE . "`" . ($real_icare ? ' WHERE is_demo = 0' : '') . ' GROUP BY ym, resource ORDER BY ym');
        while ($r = $res->fetch_assoc()) {
            $trend[] = ['ym' => $r['ym'], 'resource' => $r['resource'], 'qty' => (float) $r['qty'], 'n' => (int) $r['n']];
        }
        $demo = $real_icare ? 0 : (int) $DB->request(['COUNT' => 'c', 'FROM' => self::ICARE, 'WHERE' => ['is_demo' => 1]])->current()['c'];
        return [
            'ages' => ['Server' => (float) $cfg['fc_age_server'], 'Storage' => (float) $cfg['fc_age_storage'], 'Network' => (float) $cfg['fc_age_network']],
            'target_util' => (float) $cfg['fc_target_util'],
            'virops' => ['imported' => $cfg['virops_imported'], 'file' => $cfg['virops_file'],
                'clusters' => $clusters, 'demo' => (bool) array_filter($clusters, fn($c) => $c['source'] === 'demo')],
            'dcim' => self::dcimData($cfg),
            'icare' => ['imported' => $cfg['icare_imported'], 'file' => $cfg['icare_file'], 'trend' => $trend, 'demo' => $demo > 0],
            'settings_url' => self::canConfigure() ? $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/forecast.php' : '',
        ];
    }
}
