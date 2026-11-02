<?php

/**
 * LOP (list of prices): a supplier's price list, kept in its own table (glpi_plugin_projectreports_lopprices).
 * It prices the LOM parts lists in the ticket's Price check and the BOQs created from it only; it never changes existing orders or the Orders
 * catalog prices.
 *
 * File: "Part Number" and "Unit Price" are required; "Description", "Device" (empty for a part, or Server, Storage,
 * Switch, PDU, Rack for a whole device), "Spare Part number" and "Qty" (empty = 1; total price = Qty x Unit Price)
 * are optional.
 */
class PluginProjectreportsLop
{
    public const TABLE = 'glpi_plugin_projectreports_lopprices';

    public static function install(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            $charset = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign = DBConnection::getDefaultPrimaryKeySignOption();
            $DB->doQuery("CREATE TABLE `" . self::TABLE . "` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `suppliers_id` int {$sign} NOT NULL DEFAULT '0',
                `part` varchar(255) NOT NULL DEFAULT '',
                `description` text,
                `price` decimal(20,6) NOT NULL DEFAULT '0.000000',
                `device` varchar(20) NOT NULL DEFAULT '',
                `spare` varchar(255) NOT NULL DEFAULT '',
                `qty` int NOT NULL DEFAULT '1',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `supplier_part` (`suppliers_id`, `part`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
        // 1.17.0: whether the part number is a whole device (Server, Storage, Switch, PDU, Rack) or a part ('')
        if (!$DB->fieldExists(self::TABLE, 'device')) {
            $DB->doQuery('ALTER TABLE `' . self::TABLE . "` ADD `device` varchar(20) NOT NULL DEFAULT '' AFTER `price`");
        }
        // 1.18.3: the spare part number of the part (from the "Spare Part number" column)
        if (!$DB->fieldExists(self::TABLE, 'spare')) {
            $DB->doQuery('ALTER TABLE `' . self::TABLE . "` ADD `spare` varchar(255) NOT NULL DEFAULT '' AFTER `device`");
        }
        // 1.19.2: quantity of each line (total price = Qty x Unit Price)
        if (!$DB->fieldExists(self::TABLE, 'qty')) {
            $DB->doQuery('ALTER TABLE `' . self::TABLE . "` ADD `qty` int NOT NULL DEFAULT '1' AFTER `price`");
        }
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->dropTable(self::TABLE, true);
        Config::deleteConfigurationValues('plugin:projectreports', ['lop_info']);
    }

    /** LOP details per supplier: [suppliers_id => ['name', 'date', 'parts', 'imported']] */
    public static function info(): array
    {
        $cfg = Config::getConfigurationValues('plugin:projectreports', ['lop_info']);
        $info = json_decode((string) ($cfg['lop_info'] ?? ''), true);
        return is_array($info) ? $info : [];
    }

    private static function saveInfo(int $suppliers_id, array $row): void
    {
        $info = self::info();
        $info[$suppliers_id] = $row;
        Config::setConfigurationValues('plugin:projectreports', ['lop_info' => json_encode($info, JSON_UNESCAPED_UNICODE)]);
    }

    /** Administrators (Setup › General update right) may edit, add and delete LOP lines by hand. */
    public static function canEdit(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /** Keep the "Part numbers" count of a supplier's LOP in step with its lines. */
    private static function recount(int $suppliers_id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $info = self::info();
        if (!isset($info[$suppliers_id])) {
            return;
        }
        $info[$suppliers_id]['parts'] = (int) $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['suppliers_id' => $suppliers_id]])->current()['c'];
        Config::setConfigurationValues('plugin:projectreports', ['lop_info' => json_encode($info, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Add ($id 0) or change one line of a supplier's LOP, typed by an administrator.
     * $in: part, device, spare, description, price. Returns '' when saved, else the problem.
     */
    public static function saveLine(int $suppliers_id, int $id, array $in): string
    {
        /** @var DBmysql $DB */
        global $DB;
        $part = mb_substr(trim((string) ($in['part'] ?? '')), 0, 255);
        $price = PluginProjectreportsBoqimport::number(trim((string) ($in['price'] ?? '')));
        $qty = trim((string) ($in['qty'] ?? '')) === '' ? 1.0 : PluginProjectreportsBoqimport::number(trim((string) $in['qty']));
        $device = PluginProjectreportsBoqimport::deviceType((string) ($in['device'] ?? ''));
        if ($part === '') {
            return 'The part number is empty.';
        }
        if ($price === null || $price < 0) {
            return "Part $part: the unit price must be a number (0 or more).";
        }
        if ($qty === null || $qty < 0 || floor($qty) != $qty) {
            return "Part $part: Qty must be a whole number (0 or more).";
        }
        if ($device === null) {
            return "Part $part: Device must be empty (a part) or one of " . implode(', ', PluginProjectreportsBoqimport::DEVICE_TYPES) . '.';
        }
        $twin = $DB->request(['SELECT' => 'id', 'FROM' => self::TABLE, 'WHERE' => ['suppliers_id' => $suppliers_id, 'part' => $part,
            'NOT' => ['id' => $id]], 'LIMIT' => 1])->current();
        if ($twin) {
            return "Part $part is already in this LOP.";
        }
        $row = ['part' => $part, 'price' => $price, 'qty' => (int) $qty, 'device' => $device, 'spare' => mb_substr(trim((string) ($in['spare'] ?? '')), 0, 255),
            'description' => trim((string) ($in['description'] ?? '')), 'date_mod' => $_SESSION['glpi_currenttime']];
        if ($id > 0) {
            $DB->update(self::TABLE, $row, ['id' => $id, 'suppliers_id' => $suppliers_id]);
        } else {
            $DB->insert(self::TABLE, $row + ['suppliers_id' => $suppliers_id]);
            self::recount($suppliers_id);
        }
        return '';
    }

    public static function deleteLine(int $suppliers_id, int $id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->delete(self::TABLE, ['id' => $id, 'suppliers_id' => $suppliers_id]);
        self::recount($suppliers_id);
    }

    /** Delete a supplier's whole LOP (its lines and its name). Existing orders are not changed. */
    public static function deleteLop(int $suppliers_id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->delete(self::TABLE, ['suppliers_id' => $suppliers_id]);
        $info = self::info();
        unset($info[$suppliers_id]);
        Config::setConfigurationValues('plugin:projectreports', ['lop_info' => json_encode($info, JSON_UNESCAPED_UNICODE)]);
    }

    /** VAT rate (%) of a supplier's LOP: added on top of its prices, which are before tax. */
    public static function vat(int $suppliers_id): float
    {
        return (float) (self::info()[$suppliers_id]['vat'] ?? 0);
    }

    /** VAT (%) typed in a form: 0-100, Persian digits accepted. null when it is not a number. */
    public static function vatInput($v): ?float
    {
        $v = trim(str_replace('%', '', (string) $v));
        if ($v === '') {
            return 0.0;
        }
        $n = PluginProjectreportsBoqimport::number($v);
        return $n === null || $n < 0 || $n > 100 ? null : round($n, 2);
    }

    /** The Orders plugin VAT entry with this rate (created when missing), for order lines; 0 for no VAT. */
    public static function orderTaxId(float $rate): int
    {
        /** @var DBmysql $DB */
        global $DB;
        if ($rate <= 0 || !$DB->tableExists('glpi_plugin_order_ordertaxes')) {
            return 0;
        }
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_plugin_order_ordertaxes']) as $t) {
            if (abs((float) str_replace(',', '.', (string) $t['name']) - $rate) < 0.001) {
                return (int) $t['id'];
            }
        }
        $tax = new PluginOrderOrderTax();
        return (int) $tax->add(['name' => rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.')]);
    }

    /** Rename a supplier's LOP and set its VAT rate (null = keep). */
    public static function rename(int $suppliers_id, string $name, ?float $vat = null): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $info = self::info();
        if (trim($name) !== '') {
            $info[$suppliers_id] = ($info[$suppliers_id] ?? ['imported' => $_SESSION['glpi_currenttime']]) + ['name' => ''];
            $info[$suppliers_id]['name'] = mb_substr(trim($name), 0, 255);
            if ($vat !== null) {
                $info[$suppliers_id]['vat'] = $vat;
            }
            $info[$suppliers_id]['parts'] = (int) $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['suppliers_id' => $suppliers_id]])->current()['c'];
            Config::setConfigurationValues('plugin:projectreports', ['lop_info' => json_encode($info, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** @return array<string, float> part number => price, for the parts of this supplier's LOP */
    public static function prices(int $suppliers_id, array $parts): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $parts = array_values(array_unique(array_filter(array_map('strval', $parts), 'strlen')));
        if ($suppliers_id <= 0 || !$parts) {
            return [];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => ['part', 'price'], 'FROM' => self::TABLE,
            'WHERE' => ['suppliers_id' => $suppliers_id, 'part' => $parts]]) as $row) {
            $out[(string) $row['part']] = (float) $row['price'];
        }
        return $out;
    }

    /**
     * Price parts-list lines with the supplier's LOP. Each line gets 'price' (used), 'file_price' and
     * 'source': 'LOP', 'kit file' (part not in the LOP, a price given with the line) or 'missing'.
     */
    public static function priceLines(array $lines, int $suppliers_id): array
    {
        $lop = self::prices($suppliers_id, array_column($lines, 'part'));
        foreach ($lines as &$l) {
            $l['file_price'] = (float) ($l['price'] ?? 0);
            if (array_key_exists($l['part'], $lop)) {
                $l['price'] = $lop[$l['part']];
                $l['source'] = 'LOP';
            } else {
                $l['price'] = $l['file_price'];
                $l['source'] = $l['file_price'] > 0 ? 'kit file' : 'missing';
            }
        }
        return $lines;
    }

    /** Suppliers that have at least one LOP price. */
    public static function suppliers(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => 'suppliers_id', 'DISTINCT' => true, 'FROM' => self::TABLE, 'WHERE' => ['suppliers_id' => ['>', 0]]]) as $r) {
            $out[] = (int) $r['suppliers_id'];
        }
        return $out;
    }

    /** What importing this LOP would change: new parts, changed and unchanged prices. */
    public static function compare(array $lines, int $suppliers_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $old = self::prices($suppliers_id, array_column($lines, 'part'));
        $old_dev = $old_spare = $old_qty = [];
        if ($lines) {
            foreach ($DB->request(['SELECT' => ['part', 'device', 'spare', 'qty'], 'FROM' => self::TABLE,
                'WHERE' => ['suppliers_id' => $suppliers_id, 'part' => array_column($lines, 'part')]]) as $r) {
                $old_dev[(string) $r['part']] = (string) $r['device'];
                $old_qty[(string) $r['part']] = (int) $r['qty'];
                $old_spare[(string) $r['part']] = (string) $r['spare'];
            }
        }
        foreach ($lines as &$l) {
            $l['old_price'] = $old[$l['part']] ?? null;
            // not in the file (null): the saved device is kept
            $l['device'] = $l['device'] ?? ($old_dev[$l['part']] ?? '');
            $l['old_device'] = $old_dev[$l['part']] ?? null;
            $l['spare'] = trim((string) ($l['spare'] ?? ''));
            $l['old_spare'] = $old_spare[$l['part']] ?? null;
            $l['qty'] = (int) ($l['qty'] ?? 1);
            $l['old_qty'] = $old_qty[$l['part']] ?? null;
            if ($l['price'] === null) {
                // no Unit Price in the file: the saved price is kept
                $l['change'] = $l['old_price'] === null ? 'new part' : 'same';
                $l['price'] = $l['old_price'] ?? 0.0;
            } else {
                $l['change'] = $l['old_price'] === null ? 'new part' : ((float) $l['old_price'] == (float) $l['price'] ? 'same' : 'changed');
            }
        }
        return $lines;
    }

    /** Save the LOP prices (own table only). Returns stats. */
    public static function apply(array $lines, int $suppliers_id, string $name, float $vat = 0.0): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $st = ['new' => 0, 'changed' => 0, 'same' => 0];
        $now = $_SESSION['glpi_currenttime'];
        foreach ($lines as $l) {
            $cur = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['suppliers_id' => $suppliers_id, 'part' => $l['part']], 'LIMIT' => 1])->current();
            // not in the file (null): keep the saved value; a new part gets 0
            $device = $l['device'] === null ? (string) ($cur['device'] ?? '') : (string) $l['device'];
            $price = $l['price'] ?? ($cur ? (float) $cur['price'] : 0.0);
            $spare = trim((string) ($l['spare'] ?? ''));
            $qty = (int) ($l['qty'] ?? 1);
            if (!$cur) {
                $DB->insert(self::TABLE, ['suppliers_id' => $suppliers_id, 'part' => $l['part'], 'description' => $l['desc'], 'price' => $price,
                    'device' => $device, 'spare' => $spare, 'qty' => $qty, 'date_mod' => $now]);
                $st['new']++;
            } elseif ((float) $cur['price'] != $price || ($l['desc'] !== '' && $l['desc'] !== $cur['description']) || $device !== (string) $cur['device']
                || ($spare !== '' && $spare !== (string) $cur['spare']) || $qty !== (int) $cur['qty']) {
                // an empty Description or Spare Part number in the file keeps the saved one
                $DB->update(self::TABLE, ['price' => $price, 'description' => $l['desc'] !== '' ? $l['desc'] : $cur['description'],
                    'device' => $device, 'spare' => $spare !== '' ? $spare : $cur['spare'], 'qty' => $qty, 'date_mod' => $now], ['id' => $cur['id']]);
                (float) $cur['price'] != $price ? $st['changed']++ : $st['same']++;
            } else {
                $st['same']++;
            }
        }
        $parts = (int) $DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['suppliers_id' => $suppliers_id]])->current()['c'];
        self::saveInfo($suppliers_id, ['name' => $name, 'parts' => $parts, 'imported' => $now, 'vat' => $vat]);
        return $st;
    }
}
