<?php

/**
 * LOM of an order: the lines of an order of the Orders plugin read as a list of materials (part number, spare part
 * number, description, quantity), so each purchase of servers or storage has its own LOM. The user chooses whether the
 * order is a Server or a Storage LOM and, optionally, which device model it is for; that choice is kept in
 * glpi_plugin_projectreports_lomorders (orders_id, category, model_type, models_id). The order itself is never changed.
 */
class PluginProjectreportsLomorder
{
    public const TABLE = 'glpi_plugin_projectreports_lomorders';
    public const CATEGORIES = ['server' => 'Server', 'storage' => 'Storage', 'switch' => 'Switch', 'pdu' => 'PDU', 'rack' => 'Rack'];
    /** Badge colour per category. */
    public const BADGES = ['server' => 'bg-blue-lt', 'storage' => 'bg-purple-lt', 'switch' => 'bg-teal-lt', 'pdu' => 'bg-orange-lt', 'rack' => 'bg-yellow-lt'];

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
                `orders_id` int {$sign} NOT NULL DEFAULT '0',
                `category` varchar(20) NOT NULL DEFAULT '',
                `model_type` varchar(100) NOT NULL DEFAULT '',
                `models_id` int {$sign} NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `orders_id` (`orders_id`),
                KEY `model` (`model_type`, `models_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->dropTable(self::TABLE, true);
    }

    public static function canView(): bool
    {
        return PluginProjectreportsBoqimport::isOrderPluginActive() && Session::haveRight('plugin_order_order', READ);
    }

    public static function canEdit(): bool
    {
        return self::canView() && Session::haveRight('plugin_order_order', UPDATE);
    }

    /** Models a LOM can be linked to: the Server, Storage, Switch, PDU and Rack models of the catalog, [key => label]. */
    public static function modelChoices(): array
    {
        $out = [];
        $rank = ['servers' => 0, 'storage' => 1, 'network' => 2, 'pdus' => 3, 'racks' => 4];
        $items = array_filter(PluginProjectreportsLom::items(), fn($i) => isset($rank[$i['category']]));
        usort($items, fn($a, $b) => $rank[$a['category']] <=> $rank[$b['category']] ?: strcasecmp($a['name'], $b['name']));
        foreach ($items as $i) {
            if (isset($rank[$i['category']])) {
                $out[$i['type'] . ':' . $i['id']] = ['label' => $i['name'], 'group' => $i['category_label'], 'sheet' => $i['sheet_url']];
            }
        }
        return $out;
    }

    /** Category and model chosen for each order: [orders_id => row]. */
    private static function choices(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $out = [];
        if ($DB->tableExists(self::TABLE)) {
            foreach ($DB->request(['FROM' => self::TABLE]) as $r) {
                $out[(int) $r['orders_id']] = $r;
            }
        }
        return $out;
    }

    public static function save(int $orders_id, string $category, string $model_key): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $category = isset(self::CATEGORIES[$category]) ? $category : '';
        [$type, $mid] = array_pad(explode(':', $model_key, 2), 2, '0');
        $row = ['category' => $category, 'model_type' => $mid > 0 ? $type : '', 'models_id' => (int) $mid,
            'date_mod' => $_SESSION['glpi_currenttime']];
        if ($DB->request(['COUNT' => 'c', 'FROM' => self::TABLE, 'WHERE' => ['orders_id' => $orders_id]])->current()['c'] > 0) {
            $DB->update(self::TABLE, $row, ['orders_id' => $orders_id]);
        } else {
            $DB->insert(self::TABLE, $row + ['orders_id' => $orders_id]);
        }
    }

    /** Every order the user may see, with its LOM category, model, number of parts and units, and total. */
    public static function orders(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        if (!self::canView()) {
            return [];
        }
        $choices = self::choices();
        $models = self::modelChoices();
        $prices = PluginProjectreportsLom::canSeePrices();
        $out = [];
        $where = ['o.is_deleted' => 0, 'o.is_template' => 0] + getEntitiesRestrictCriteria('o', '', '', true);
        foreach ($DB->request([
            'SELECT' => ['o.id', 'o.name', 'o.num_order', 'o.order_date', 'o.suppliers_id', 'o.plugin_order_orderstates_id AS state',
                new Glpi\DBAL\QueryExpression('COUNT(' . $DB->quoteName('i.id') . ') AS units'),
                new Glpi\DBAL\QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('i.plugin_order_references_id') . ') AS parts'),
                new Glpi\DBAL\QueryExpression('COALESCE(SUM(' . $DB->quoteName('i.price_discounted') . '), 0) AS total')],
            'FROM' => 'glpi_plugin_order_orders AS o',
            'LEFT JOIN' => ['glpi_plugin_order_orders_items AS i' => ['ON' => ['i' => 'plugin_order_orders_id', 'o' => 'id']]],
            'WHERE' => $where, 'GROUPBY' => 'o.id', 'ORDER' => 'o.id DESC',
        ]) as $o) {
            $c = $choices[(int) $o['id']] ?? null;
            $mkey = $c && $c['models_id'] ? $c['model_type'] . ':' . $c['models_id'] : '';
            $out[] = [
                'id' => (int) $o['id'], 'name' => (string) $o['name'], 'num_order' => (string) $o['num_order'],
                'date' => (string) ($o['order_date'] ?? ''),
                'supplier' => $o['suppliers_id'] ? Dropdown::getDropdownName('glpi_suppliers', (int) $o['suppliers_id']) : '',
                'state' => Dropdown::getDropdownName('glpi_plugin_order_orderstates', (int) $o['state']),
                'category' => $c['category'] ?? '', 'model_key' => $mkey, 'model' => $models[$mkey]['label'] ?? '',
                'parts' => (int) $o['parts'], 'units' => (int) $o['units'], 'total' => $prices ? (float) $o['total'] : null,
                'url' => $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomorder.php?id=' . (int) $o['id'],
            ];
        }
        return $out;
    }

    public static function order(int $orders_id): ?array
    {
        foreach (self::orders() as $o) {
            if ($o['id'] === $orders_id) {
                return $o;
            }
        }
        return null;
    }

    /** Lines of an order grouped per part: part (reference name), spare (manufacturer reference), description, type, qty, unit price, total. */
    public static function lines(int $orders_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $prices = PluginProjectreportsLom::canSeePrices();
        $types = [];
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['r.id', 'r.name', 'r.manufacturers_reference', 'r.comment', 'r.itemtype', 'i.price_discounted',
                new Glpi\DBAL\QueryExpression('COUNT(' . $DB->quoteName('i.id') . ') AS qty')],
            'FROM' => 'glpi_plugin_order_orders_items AS i',
            'INNER JOIN' => ['glpi_plugin_order_references AS r' => ['ON' => ['r' => 'id', 'i' => 'plugin_order_references_id']]],
            'WHERE' => ['i.plugin_order_orders_id' => $orders_id],
            'GROUPBY' => ['r.id', 'i.price_discounted'], 'ORDER' => ['r.itemtype', 'r.name'],
        ]) as $l) {
            $t = (string) $l['itemtype'];
            $types[$t] ??= class_exists($t) ? $t::getTypeName(1) : ($t ?: '—');
            $qty = (int) $l['qty'];
            [$part, $spare, $desc] = self::split((string) $l['name'], (string) $l['manufacturers_reference'], (string) $l['comment']);
            $out[] = ['part' => $part, 'spare' => $spare,
                'description' => $desc, 'type' => $types[$t], 'qty' => $qty,
                'price' => $prices ? (float) $l['price_discounted'] : null, 'total' => $prices ? (float) $l['price_discounted'] * $qty : null];
        }
        return $out;
    }

    /**
     * Part number, spare part number and description of a catalog reference. Imported BOQs keep them in the reference
     * name, manufacturer reference and comment; references created by hand often have "Part Number: X / Spare Part: Y"
     * in the comment and "X - description" as name, which is read too.
     */
    public static function split(string $name, string $mref, string $comment): array
    {
        $part = $name;
        $spare = trim($mref);
        $desc = trim($comment);
        if (preg_match('~Part\s*(?:Number|No\.?|#)\s*:\s*([^/;,\n]+)~i', $comment, $m)) {
            $part = trim($m[1]);
            $desc = '';
        }
        if ($spare === '' && preg_match('~Spare\s*Part(?:\s*(?:Number|No\.?|#))?\s*:\s*([^/;,\n]+)~i', $comment, $m)) {
            $spare = trim($m[1]);
            $desc = '';
        }
        if ($desc === '' && $part !== $name && str_starts_with($name, $part)) {
            $desc = trim(ltrim(substr($name, strlen($part)), " -–:\t"));
        }
        if ($desc === '' && $part === $name && preg_match('~^(\S+)\s+[-–]\s+(.+)$~', $name, $m)) {
            [$part, $desc] = [$m[1], $m[2]];
        }
        return [$part, $spare, $desc];
    }

    /** Order LOMs linked to a model. */
    public static function forModel(string $type, int $models_id): array
    {
        return array_values(array_filter(self::orders(), fn($o) => $o['model_key'] === $type . ':' . $models_id));
    }
}
