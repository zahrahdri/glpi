<?php

/**
 * Orders › order › "Export" tab: download the lines of this order as a CSV file (opens in Excel).
 * Columns: Part Number, Part Description, Spare Part Number, Unit price tax free, QTY per device, Quantity
 * (see COLUMNS and lines()). Only for users who may view the order.
 */
class PluginProjectreportsOrderexport extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Export';
    }

    public static function getIcon()
    {
        return 'ti ti-file-spreadsheet';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginOrderOrder || $item->isNewItem() || !PluginProjectreportsBoqimport::canImport()) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof PluginOrderOrder) {
            self::show($item);
        }
        return true;
    }

    public static function show(PluginOrderOrder $order): void
    {
        $h = fn($v) => htmlescape((string) $v);
        $url = $GLOBALS['CFG_GLPI']['root_doc'] . '/plugins/projectreports/front/orderexport.php?orders_id=' . (int) $order->getID();
        echo '<div class="card m-3"><div class="card-body">';
        echo '<h3 class="card-title">Export order lines</h3>';
        echo '<p class="text-muted">Downloads the lines of order ' . $h($order->fields['name'] ?? ('#' . $order->getID())) . ' as a CSV file. Open it in Excel.</p>';
        echo '<p class="text-muted small">Columns: ' . $h(implode(', ', self::COLUMNS)) . '. QTY per device comes from the LOM parts lists; it is empty when a part is in no list or the lists disagree.</p>';
        echo '<a class="btn btn-primary" href="' . $h($url) . '"><i class="ti ti-download"></i> Download CSV</a>';
        echo '</div></div>';
    }

    /** Header of the CSV file, in the same order as the values returned by lines(). */
    public const COLUMNS = ['Part Number', 'Part Description', 'Spare Part Number', 'Unit price tax free', 'QTY per device', 'Quantity'];

    /**
     * Lines of an order: one row per reference and tax-free unit price.
     * Part Number = reference name, Part Description = reference comments, Spare Part Number = manufacturer reference,
     * Quantity = number of units of that reference in the order, QTY per device = from the LOM parts lists (see qtyPerDevice()).
     */
    public static function lines(int $orders_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['r.name', 'r.comment', 'r.manufacturers_reference', 'i.price_taxfree',
                new Glpi\DBAL\QueryExpression('COUNT(' . $DB->quoteName('i.id') . ') AS qty')],
            'FROM' => 'glpi_plugin_order_orders_items AS i',
            'INNER JOIN' => ['glpi_plugin_order_references AS r' => ['ON' => ['r' => 'id', 'i' => 'plugin_order_references_id']]],
            'WHERE' => ['i.plugin_order_orders_id' => $orders_id],
            'GROUPBY' => ['r.id', 'i.price_taxfree'],
            'ORDER' => ['r.itemtype', 'r.name'],
        ]) as $l) {
            $rows[] = $l;
        }
        $per_device = self::qtyPerDevice($rows);
        $out = [];
        foreach ($rows as $l) {
            $part = trim((string) $l['name']);
            $spare = trim((string) $l['manufacturers_reference']);
            $kp = self::key($part);
            $ks = self::key($spare);
            if (array_key_exists($kp, $per_device['part'])) {
                $per = $per_device['part'][$kp];
            } else {
                $per = $ks !== '' ? ($per_device['spare'][$ks] ?? '') : '';
            }
            $out[] = [$part, trim((string) $l['comment']), $spare, (float) $l['price_taxfree'], $per, (int) $l['qty']];
        }
        return $out;
    }

    /**
     * Quantity per device of the order's parts, read from the LOM parts lists (Tools › LOM, every model and supplier list).
     * A part is matched on its part number, else on its spare part number. When the lists disagree (for example 2 per
     * device in one model and 4 in another), the value is empty: [ 'part' => [key => qty], 'spare' => [key => qty] ].
     */
    private static function qtyPerDevice(array $rows): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $found = ['part' => [], 'spare' => []];
        if (!$rows || !$DB->tableExists(PluginProjectreportsLompart::TABLE)) {
            return ['part' => [], 'spare' => []];
        }
        $keys = [];
        foreach ($rows as $l) {
            foreach ([(string) $l['name'], (string) $l['manufacturers_reference']] as $k) {
                if (trim($k) !== '') {
                    $keys[trim($k)] = true;
                }
            }
        }
        $keys = array_keys($keys);
        foreach ($DB->request([
            'SELECT' => ['part', 'spare', 'qty'],
            'FROM' => PluginProjectreportsLompart::TABLE,
            'WHERE' => ['OR' => ['part' => $keys, 'spare' => $keys]],
        ]) as $p) {
            foreach (['part', 'spare'] as $field) {
                $k = self::key((string) $p[$field]);
                if ($k !== '') {
                    $found[$field][$k][(int) $p['qty']] = true;
                }
            }
        }
        // one quantity = that quantity; several = '' (the lists disagree)
        $single = fn(array $by) => array_map(fn($q) => count($q) === 1 ? (int) array_key_first($q) : '', $by);
        return ['part' => $single($found['part']), 'spare' => $single($found['spare'])];
    }

    private static function key(string $s): string
    {
        return mb_strtolower(trim($s));
    }
}
