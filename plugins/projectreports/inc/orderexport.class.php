<?php

/**
 * Orders › order › "Export" tab: download the lines of this order as a CSV file (opens in Excel).
 * Columns follow the order item list: quantity, type, reference, manufacturer reference, unit price (before tax),
 * discounted unit price, line total. Only for users who may view the order.
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
        echo '<a class="btn btn-primary" href="' . $h($url) . '"><i class="ti ti-download"></i> Download CSV</a>';
        echo '</div></div>';
    }

    /** Lines of an order: one row per reference and unit price, with quantity. */
    public static function lines(int $orders_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['r.name', 'r.manufacturers_reference', 'r.itemtype', 'i.price_taxfree', 'i.price_discounted',
                new Glpi\DBAL\QueryExpression('COUNT(' . $DB->quoteName('i.id') . ') AS qty')],
            'FROM' => 'glpi_plugin_order_orders_items AS i',
            'INNER JOIN' => ['glpi_plugin_order_references AS r' => ['ON' => ['r' => 'id', 'i' => 'plugin_order_references_id']]],
            'WHERE' => ['i.plugin_order_orders_id' => $orders_id],
            'GROUPBY' => ['r.id', 'i.price_taxfree', 'i.price_discounted'],
            'ORDER' => ['r.itemtype', 'r.name'],
        ]) as $l) {
            $t = (string) $l['itemtype'];
            $type = class_exists($t) ? $t::getTypeName(1) : ($t ?: '');
            $qty = (int) $l['qty'];
            $unit = (float) $l['price_discounted'];
            $out[] = [$qty, $type, (string) $l['name'], (string) $l['manufacturers_reference'],
                (float) $l['price_taxfree'], $unit, $unit * $qty];
        }
        return $out;
    }
}
