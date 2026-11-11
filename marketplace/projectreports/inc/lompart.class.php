<?php

/**
 * Standard parts list (bill of materials) of a device model, for the LOM catalog: what one device of this model is
 * made of. Table glpi_plugin_projectreports_lomparts: model_class (e.g. ComputerModel), models_id, part (part number),
 * spare (spare part number), description, qty, sort_order.
 * Edited by users who may update that model; read by everyone who may see the model in the LOM catalog.
 */
class PluginProjectreportsLompart
{
    public const TABLE = 'glpi_plugin_projectreports_lomparts';

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
                `model_class` varchar(255) NOT NULL DEFAULT '',
                `models_id` int {$sign} NOT NULL DEFAULT '0',
                `suppliers_id` int {$sign} NOT NULL DEFAULT '0',
                `part` varchar(255) NOT NULL DEFAULT '',
                `spare` varchar(255) NOT NULL DEFAULT '',
                `description` text,
                `qty` int NOT NULL DEFAULT '1',
                `sort_order` int NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `model` (`model_class`, `models_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
        // 1.22.0: a parts list per supplier (0 = the standard list of the model)
        if (!$DB->fieldExists(self::TABLE, 'suppliers_id')) {
            $sign = DBConnection::getDefaultPrimaryKeySignOption();
            $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD `suppliers_id` int {$sign} NOT NULL DEFAULT '0' AFTER `models_id`");
        }
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->dropTable(self::TABLE, true);
    }

    /** Parts lists of a model: [suppliers_id => ['name' => supplier or 'Standard list', 'parts' => count]]. */
    public static function variants(string $model_class, int $models_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $out = [];
        if (!$DB->tableExists(self::TABLE)) {
            return $out;
        }
        foreach ($DB->request(['SELECT' => ['suppliers_id', 'COUNT' => '* AS n'], 'FROM' => self::TABLE,
            'WHERE' => ['model_class' => $model_class, 'models_id' => $models_id], 'GROUPBY' => 'suppliers_id', 'ORDER' => 'suppliers_id']) as $r) {
            $sid = (int) $r['suppliers_id'];
            $out[$sid] = ['name' => $sid ? Dropdown::getDropdownName('glpi_suppliers', $sid) : 'Standard list', 'parts' => (int) $r['n']];
        }
        return $out;
    }

    /** Parts of a model's list (the standard one, or a supplier's), in order, each with its lowest LOP price (when prices may be shown). */
    public static function forModel(string $model_class, int $models_id, bool $prices, int $suppliers_id = 0): array
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }
        $rows = iterator_to_array($DB->request(['FROM' => self::TABLE,
            'WHERE' => ['model_class' => $model_class, 'models_id' => $models_id, 'suppliers_id' => $suppliers_id], 'ORDER' => ['sort_order', 'id']]), false);
        $best = [];
        if ($prices && $rows && $DB->tableExists(PluginProjectreportsLop::TABLE)) {
            $keys = array_values(array_unique(array_filter(array_merge(array_column($rows, 'part'), array_column($rows, 'spare')), 'strlen')));
            foreach ($DB->request(['SELECT' => ['suppliers_id', 'part', 'price'], 'FROM' => PluginProjectreportsLop::TABLE,
                'WHERE' => ['part' => $keys], 'ORDER' => 'price DESC']) as $p) {
                $best[(string) $p['part']] = ['price' => (float) $p['price'], 'supplier' => Dropdown::getDropdownName('glpi_suppliers', (int) $p['suppliers_id'])];
            }
        }
        foreach ($rows as &$r) {
            $r['qty'] = (int) $r['qty'];
            $r['lop'] = $best[$r['part']] ?? ($best[$r['spare']] ?? null);
        }
        return $rows;
    }

    /** Number of parts per model: [model_class][models_id] => rows. */
    public static function counts(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $out = [];
        if ($DB->tableExists(self::TABLE)) {
            foreach ($DB->request(['SELECT' => ['model_class', 'models_id', 'COUNT' => '* AS n'], 'FROM' => self::TABLE,
                'GROUPBY' => ['model_class', 'models_id']]) as $r) {
                $out[$r['model_class']][(int) $r['models_id']] = (int) $r['n'];
            }
        }
        return $out;
    }

    private static function clean(array $in): array
    {
        $qty = PluginProjectreportsBoqimport::number((string) ($in['qty'] ?? '1'));
        return [
            'part' => mb_substr(trim((string) ($in['part'] ?? '')), 0, 255),
            'spare' => mb_substr(trim((string) ($in['spare'] ?? '')), 0, 255),
            'description' => trim((string) ($in['description'] ?? '')),
            'qty' => $qty !== null && $qty >= 0 ? (int) round($qty) : 1,
        ];
    }

    public static function add(string $model_class, int $models_id, array $in, int $suppliers_id = 0): bool
    {
        /** @var DBmysql $DB */
        global $DB;
        $row = self::clean($in);
        if ($row['part'] === '') {
            return false;
        }
        $rank = (int) ($DB->request(['SELECT' => ['MAX' => 'sort_order AS r'], 'FROM' => self::TABLE,
            'WHERE' => ['model_class' => $model_class, 'models_id' => $models_id, 'suppliers_id' => $suppliers_id]])->current()['r'] ?? 0) + 1;
        return $DB->insert(self::TABLE, $row + ['model_class' => $model_class, 'models_id' => $models_id, 'suppliers_id' => $suppliers_id, 'sort_order' => $rank,
            'date_mod' => $_SESSION['glpi_currenttime']]);
    }

    public static function update(string $model_class, int $models_id, int $id, array $in): bool
    {
        /** @var DBmysql $DB */
        global $DB;
        $row = self::clean($in);
        if ($row['part'] === '') {
            return false;
        }
        return $DB->update(self::TABLE, $row + ['date_mod' => $_SESSION['glpi_currenttime']],
            ['id' => $id, 'model_class' => $model_class, 'models_id' => $models_id]);
    }

    /** Save every line of one list at once: the lines of this list that are sent, and a new line when its part number is filled. */
    public static function saveAll(string $model_class, int $models_id, int $suppliers_id, array $lines, array $new): int
    {
        /** @var DBmysql $DB */
        global $DB;
        $mine = array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => self::TABLE,
            'WHERE' => ['model_class' => $model_class, 'models_id' => $models_id, 'suppliers_id' => $suppliers_id]]), false), 'id'));
        $n = 0;
        foreach ($lines as $lid => $row) {
            if (in_array((int) $lid, $mine, true) && self::update($model_class, $models_id, (int) $lid, (array) $row)) {
                $n++;
            }
        }
        if (trim((string) ($new['part'] ?? '')) !== '' && self::add($model_class, $models_id, $new, $suppliers_id)) {
            $n++;
        }
        return $n;
    }

    /** Replace a whole parts list (standard or a supplier's) with these rows, in one save. Returns the rows saved. */
    public static function replace(string $model_class, int $models_id, int $suppliers_id, array $rows): int
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->delete(self::TABLE, ['model_class' => $model_class, 'models_id' => $models_id, 'suppliers_id' => $suppliers_id]);
        $n = 0;
        foreach ($rows as $r) {
            $n += self::add($model_class, $models_id, (array) $r, $suppliers_id) ? 1 : 0;
        }
        return $n;
    }

    public static function delete(string $model_class, int $models_id, int $id): bool
    {
        /** @var DBmysql $DB */
        global $DB;
        return $DB->delete(self::TABLE, ['id' => $id, 'model_class' => $model_class, 'models_id' => $models_id]);
    }

    /** Import a parts list file (BOQ template: Part Number, Spare Part number, Description, Qty). Returns [added, errors]. */
    public static function import(string $model_class, int $models_id, string $path, bool $replace, int $suppliers_id = 0): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $parsed = PluginProjectreportsBoqimport::parse($path, 'kit');
        if ($parsed['errors']) {
            return [0, $parsed['errors']];
        }
        if ($replace) {
            $DB->delete(self::TABLE, ['model_class' => $model_class, 'models_id' => $models_id, 'suppliers_id' => $suppliers_id]);
        }
        $n = 0;
        foreach ($parsed['lines'] as $l) {
            $n += self::add($model_class, $models_id, ['part' => $l['part'], 'spare' => $l['spare'] ?? '',
                'description' => $l['desc'] ?? '', 'qty' => $l['qty']], $suppliers_id) ? 1 : 0;
        }
        return [$n, []];
    }
}
