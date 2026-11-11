<?php

/**
 * Import a BOQ file (BOQ_MODEL.csv template) as an order of the Orders management plugin.
 *
 * Template columns (any order, header names matter, extra columns are ignored):
 *   Part Number, Spare Part number, Model, Description, Qty, Unit Price, Item type
 *
 * For every line: the part number becomes a catalog reference (created if missing, matched by name),
 * the unit price is stored as the chosen supplier's price, and the order gets Qty lines of that reference.
 * The quantities can be for the whole order, or for one unit multiplied by a number of units.
 */
class PluginProjectreportsBoqimport
{
    public const COLUMNS = [
        'part'   => 'Part Number',
        'spare'  => 'Spare Part number',
        'model'  => 'Model',
        'desc'   => 'Description',
        'qty'    => 'Qty',
        'price'  => 'Unit Price',
        'type'   => 'Item type',
    ];

    private const MAX_UNITS = 20000;

    /** Values of the LOP "Device" column: a whole device of this type; empty means a part. */
    public const DEVICE_TYPES = ['Server', 'Storage', 'Switch', 'PDU', 'Rack'];

    /** "server", "Servers", "network switch", "no", "part" … → Server / … / '' (a part), null when unknown. */
    public static function deviceType(string $v): ?string
    {
        $v = strtolower(trim($v));
        if (in_array($v, ['', 'no', 'n', '0', '-', 'part', 'parts', 'component'], true)) {
            return '';
        }
        foreach (['Server' => 'server', 'Storage' => 'stor|san|nas', 'Switch' => 'switch|network|router', 'PDU' => 'pdu|power', 'Rack' => 'rack|cabinet'] as $t => $re) {
            if (preg_match('/' . $re . '/', $v)) {
                return $t;
            }
        }
        return null;
    }

    public static function isOrderPluginActive(): bool
    {
        return Plugin::isPluginActive('order') && class_exists('PluginOrderOrder');
    }

    /** Creates orders, references and supplier prices, so it needs the Orders plugin's own create rights. */
    public static function canImport(): bool
    {
        return self::isOrderPluginActive()
            && Session::haveRight('plugin_order_order', CREATE)
            && Session::haveRight('plugin_order_reference', CREATE);
    }

    /** Item types the Orders plugin accepts, by lower-case name => class name. */
    public static function itemTypes(): array
    {
        $types = [];
        foreach (PluginOrderOrder_Item::getClasses(true) as $t) {
            if (class_exists($t)) {
                $types[strtolower($t)] = $t;
            }
        }
        // friendly names people may type in the file
        $alias = ['server' => 'Computer', 'switch' => 'NetworkEquipment', 'network equipment' => 'NetworkEquipment',
            'networkequipment' => 'NetworkEquipment', 'other' => 'PluginOrderOther', 'part' => 'PluginOrderOther',
            'service' => 'PluginOrderOther', 'license' => 'SoftwareLicense', 'licence' => 'SoftwareLicense'];
        foreach ($alias as $a => $t) {
            if (isset($types[strtolower($t)]) && !isset($types[$a])) {
                $types[$a] = $t;
            }
        }
        return $types;
    }

    /** "2,100,000,000", "۲۱۰۰", "1 500.50", "included" -> number (null when not a number) */
    public static function number(string $v): ?float
    {
        $v = strtr(trim($v), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => ',', '٫' => '.']);
        if ($v === '' || preg_match('/^(included|incl\.?|free|-|0+)$/i', $v)) {
            return 0.0;
        }
        $v = preg_replace('/[\s\x{00A0}]|rial|irr|usd|\$/iu', '', $v);
        $v = str_replace(',', '', $v);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Columns each kind of file must have: a BOQ, a supplier price list (LOP), a parts list (parts + quantities). */
    public const REQUIRED = [
        'boq' => ['part', 'qty', 'price', 'type'],
        'lop' => ['part'],
        'kit' => ['part', 'qty'],
    ];

    /**
     * Read the file. Returns ['lines' => [...], 'errors' => [...], 'warnings' => [...]].
     * Each line: part, spare, model, desc, qty, price, itemtype, row, reference (existing id or 0).
     * $mode 'lop': Qty is not needed (1). 'kit': Unit Price is optional (the LOP price is used).
     * Item type is optional for 'lop' and 'kit': the catalog's type, else PluginOrderOther.
     */
    public static function parse(string $path, string $mode = 'boq'): array
    {
        $required = self::REQUIRED[$mode] ?? self::REQUIRED['boq'];
        $errors = $warnings = $lines = [];
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return ['lines' => [], 'errors' => ['The file is empty.'], 'warnings' => []];
        }
        // Excel workbook (.xlsx): read the first sheet with its formulas calculated
        if (str_starts_with($raw, "PK\x03\x04")) {
            try {
                $raw = self::xlsxToCsv($path);
            } catch (Throwable $e) {
                return ['lines' => [], 'errors' => ['The Excel file could not be read: ' . $e->getMessage()], 'warnings' => []];
            }
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1256');
        }
        $first = strtok($raw, "\r\n");
        $delim = ',';
        foreach ([';', "\t"] as $d) {
            if (substr_count($first, $d) > substr_count($first, $delim)) {
                $delim = $d;
            }
        }
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = fgetcsv($fh, 0, $delim, '"', '');
        $norm = fn($s) => strtolower(preg_replace('/[^a-z]/i', '', (string) $s));
        $pos = [];
        foreach (self::COLUMNS as $key => $label) {
            foreach ($header ?: [] as $i => $h) {
                if ($norm($h) === $norm($label)) {
                    $pos[$key] = $i;
                }
            }
        }
        // LOP only: optional "Device" column (empty = a part, or Server / Storage / Switch / PDU / Rack)
        // and optional quantity ("Qty", "Quantity" or "Total quantity"; empty = 1)
        if ($mode === 'lop') {
            foreach ($header ?: [] as $i => $h) {
                if (in_array($norm($h), ['device', 'devicetype', 'isdevice'], true)) {
                    $pos['device'] = $i;
                }
                if (!isset($pos['qty']) && in_array($norm($h), ['quantity', 'totalquantity', 'totalqty'], true)) {
                    $pos['qty'] = $i;
                }
                // optional helpers: Qty = Qty per device x Number of devices when Qty is empty (or an Excel formula)
                if (in_array($norm($h), ['qtyperdevice', 'qtyperserver', 'qtyperunit', 'perdevice'], true)) {
                    $pos['per'] = $i;
                }
                if (in_array($norm($h), ['numberofdevices', 'devices', 'numberofservers', 'nbdevices'], true)) {
                    $pos['devices'] = $i;
                }
            }
        }
        foreach ($required as $need) {
            if (!isset($pos[$need])) {
                $errors[] = sprintf('Column "%s" is missing. The first row must be the template header: %s.', self::COLUMNS[$need], implode(', ', self::COLUMNS));
            }
        }
        if ($errors) {
            return ['lines' => [], 'errors' => $errors, 'warnings' => []];
        }

        /** @var DBmysql $DB */
        global $DB;
        $types = self::itemTypes();
        $row = 1;
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $row++;
            $get = fn($k) => isset($pos[$k], $r[$pos[$k]]) ? trim((string) $r[$pos[$k]]) : '';
            if (implode('', array_map('trim', $r)) === '') {
                continue;
            }
            $part = $get('part');
            $qty = isset($pos['qty']) && !($mode === 'lop' && ($get('qty') === '' || str_starts_with($get('qty'), '='))) ? self::number($get('qty')) : 1.0;
            if ($mode === 'lop' && (!isset($pos['qty']) || $get('qty') === '' || str_starts_with($get('qty'), '='))
                && (($get('per') !== '' && !str_starts_with($get('per'), '=')) || ($get('devices') !== '' && !str_starts_with($get('devices'), '=')))) {
                $per = $get('per') === '' ? 1.0 : self::number($get('per'));
                $devs = $get('devices') === '' ? 1.0 : self::number($get('devices'));
                $qty = $per === null || $devs === null ? null : $per * $devs;
            }
            // LOP: a file without a Unit Price column keeps the prices already saved (null = not in the file)
            $has_price = isset($pos['price']);
            $price = $has_price ? self::number($get('price')) : ($mode === 'lop' ? null : 0.0);
            if ($mode === 'kit' && $price === null) {
                $price = 0.0;
            }
            $typename = $get('type');
            if ($part === '') {
                $errors[] = "Row $row: Part Number is empty.";
                continue;
            }
            if ($qty === null || $qty < 0 || floor($qty) != $qty) {
                $errors[] = "Row $row ($part): Qty \"{$get('qty')}\" is not a whole number.";
                continue;
            }
            if (($has_price && $price === null) || ($price !== null && $price < 0)) {
                $errors[] = "Row $row ($part): Unit Price \"{$get('price')}\" is not a number.";
                continue;
            }
            $itemtype = $types[strtolower($typename)] ?? null;
            if ($itemtype === null && $typename === '' && $mode !== 'boq') {
                $itemtype = '';
            } elseif ($itemtype === null) {
                $errors[] = "Row $row ($part): Item type \"$typename\" is not one the Orders plugin accepts (for example Computer, NetworkEquipment, PluginOrderOther).";
                continue;
            }
            if ($mode === 'lop' && $has_price && $get('price') === '') {
                $warnings[] = "Row $row ($part): Unit Price is empty, saved as 0.";
            }
            if ($qty == 0) {
                $warnings[] = "Row $row ($part): Qty is 0, line skipped.";
                continue;
            }
            $ref = $DB->request(['SELECT' => ['id', 'itemtype'], 'FROM' => 'glpi_plugin_order_references',
                'WHERE' => ['name' => $part, 'is_deleted' => 0], 'LIMIT' => 1])->current();
            if ($itemtype === '') {
                $itemtype = $ref ? $ref['itemtype'] : (isset($types['pluginorderother']) ? 'PluginOrderOther' : reset($types));
            } elseif ($ref && $ref['itemtype'] !== $itemtype) {
                $warnings[] = "Row $row ($part): already in the catalog as {$ref['itemtype']}, so it is ordered as {$ref['itemtype']} (file says $itemtype).";
                $itemtype = $ref['itemtype'];
            }
            // LOP: no Device column in the file = the device is not in the file (null, the saved value is kept)
            $device = $mode === 'lop' && !isset($pos['device']) ? null : '';
            if ($mode === 'lop' && isset($pos['device']) && ($raw_device = $get('device')) !== '') {
                $device = self::deviceType($raw_device);
                if ($device === null) {
                    $warnings[] = "Row $row ($part): Device \"$raw_device\" is not one of " . implode(', ', self::DEVICE_TYPES) . ' (or empty for a part): read as a part.';
                    $device = '';
                }
            }
            $lines[] = ['row' => $row, 'part' => $part, 'spare' => $get('spare'), 'model' => $get('model'), 'desc' => $get('desc'), 'device' => $device,
                'qty' => (int) $qty, 'price' => $price, 'itemtype' => $itemtype, 'reference' => $ref ? (int) $ref['id'] : 0];
        }
        fclose($fh);
        if (!$lines && !$errors) {
            $errors[] = 'The file has a header but no lines.';
        }
        return ['lines' => $lines, 'errors' => $errors, 'warnings' => $warnings];
    }

    public static function totalUnits(array $lines, int $mult): int
    {
        return array_sum(array_column($lines, 'qty')) * $mult;
    }

    /**
     * First sheet of an .xlsx workbook as CSV text, with formulas calculated. Rows without a part number after the
     * header (empty formula rows, the TOTAL row) are left out.
     */
    private static function xlsxToCsv(string $path): string
    {
        $reader = new PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);
        $out = fopen('php://memory', 'r+');
        $part_col = null;
        $max_col = PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $max_row = $sheet->getHighestDataRow();
        for ($r = 1; $r <= $max_row; $r++) {
            $row = [];
            for ($c = 1; $c <= $max_col; $c++) {
                $v = $sheet->getCell([$c, $r])->getCalculatedValue();
                if (is_float($v) && floor($v) == $v && abs($v) < 1e15) {
                    $v = (int) $v;
                }
                $row[] = is_scalar($v) ? (string) $v : '';
            }
            if ($r === 1) {
                foreach ($row as $i => $h) {
                    if (strtolower(preg_replace('/[^a-z]/i', '', $h)) === 'partnumber') {
                        $part_col = $i;
                    }
                }
            } elseif ($part_col !== null && trim($row[$part_col] ?? '') === '') {
                continue;
            }
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** Find or create the model of an item type (e.g. ComputerModel) by name. */
    private static function modelId(string $itemtype, string $name): int
    {
        $class = $itemtype . 'Model';
        if ($name === '' || !class_exists($class) || !is_subclass_of($class, 'CommonDropdown')) {
            return 0;
        }
        $m = new $class();
        $id = $m->import(['name' => $name]);
        return max(0, (int) $id);
    }

    /**
     * Add lines to an order: each line's catalog reference (found, else created), Qty x $mult units of it at the
     * line's unit price (before tax) with VAT $taxes_id. $set_prices: the price becomes the supplier's catalog price.
     */
    public static function addLines(int $oid, array $lines, int $entity, int $suppliers_id, int $taxes_id, bool $set_prices, int $mult = 1): array
    {
        $stats = ['new_refs' => 0, 'prices' => 0, 'units' => 0, 'total' => 0.0];
        $reference = new PluginOrderReference();
        $refsup = new PluginOrderReference_Supplier();
        $item = new PluginOrderOrder_Item();
        $seen = [];
        foreach ($lines as $l) {
            $rid = $l['reference'] ?: ($seen[$l['part']] ?? 0);
            if (!$rid) {
                $rid = (int) $reference->add([
                    'entities_id' => $entity, 'is_recursive' => 1, 'is_active' => 1,
                    'name' => $l['part'], 'itemtype' => $l['itemtype'],
                    'manufacturers_reference' => $l['spare'],
                    'models_id' => self::modelId($l['itemtype'], $l['model']),
                    'comment' => $l['desc'],
                ]);
                if (!$rid) {
                    throw new RuntimeException("Could not create the catalog reference {$l['part']}.");
                }
                $stats['new_refs']++;
            }
            $seen[$l['part']] = $rid;
            // the supplier's price for this part = the BOQ unit price
            if ($suppliers_id > 0 && $set_prices) {
                $cur = $refsup->find(['plugin_order_references_id' => $rid, 'suppliers_id' => $suppliers_id], [], 1);
                if ($cur) {
                    $cur = reset($cur);
                    if ((float) $cur['price_taxfree'] != (float) $l['price']) {
                        $refsup->update(['id' => $cur['id'], 'price_taxfree' => $l['price']]);
                        $stats['prices']++;
                    }
                } else {
                    $refsup->add(['entities_id' => $entity, 'is_recursive' => 1, 'plugin_order_references_id' => $rid,
                        'suppliers_id' => $suppliers_id, 'price_taxfree' => $l['price'], 'reference_code' => $l['part']]);
                    $stats['prices']++;
                }
            }
            $qty = $l['qty'] * $mult;
            $item->addDetails($rid, $l['itemtype'], $oid, $qty, $l['price'], 0, $taxes_id, 0);
            $stats['units'] += $qty;
            $stats['total'] += $qty * $l['price'];
        }
        return $stats;
    }

    /**
     * Create the order. $o: name, num_order, budgets_id, suppliers_id, taxes_id, order_date, quote, mult, file,
     * set_prices (default true: the line prices become the supplier's catalog prices; false keeps the LOP untouched).
     * Returns [order id, stats].
     */
    public static function create(array $lines, array $o): array
    {
        $entity = (int) ($o['entities_id'] ?? $_SESSION['glpiactive_entity']);
        $config = PluginOrderConfig::getConfig();
        $mult = max(1, (int) $o['mult']);

        $order = new PluginOrderOrder();
        $oid = $order->add([
            'entities_id' => $entity, 'is_recursive' => 1,
            'name' => $o['name'], 'num_order' => $o['num_order'],
            'budgets_id' => (int) $o['budgets_id'], 'suppliers_id' => (int) $o['suppliers_id'],
            'plugin_order_ordertaxes_id' => (int) $o['taxes_id'],
            'order_date' => $o['order_date'],
            'plugin_order_orderstates_id' => (int) $config->fields['order_status_draft'],
            'comment' => $o['comment'] ?? sprintf('Imported from BOQ file %s%s.', $o['file'], $mult > 1 ? " (quantities for one unit x $mult units)" : ''),
        ]);
        if (!$oid) {
            throw new RuntimeException('The Orders plugin refused to create the order (see the message at the top of the page).');
        }
        if (trim((string) $o['quote']) !== '' && (int) $o['suppliers_id'] > 0) {
            (new PluginOrderOrder_Supplier())->add(['entities_id' => $entity, 'is_recursive' => 1,
                'plugin_order_orders_id' => $oid, 'suppliers_id' => (int) $o['suppliers_id'], 'num_quote' => $o['quote']]);
        }

        $stats = self::addLines($oid, $lines, $entity, (int) $o['suppliers_id'], (int) $o['taxes_id'], (bool) ($o['set_prices'] ?? true), $mult);
        return [$oid, $stats];
    }
}
