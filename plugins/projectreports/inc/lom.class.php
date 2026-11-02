<?php

/**
 * LOM (list of materials): the catalog of device models the company uses (servers, storage, network, passive
 * equipment, PDUs, enclosures, racks), with their pictures, specs, how many are in use and, for users who may see
 * costs, the price of the model's product number in each supplier's LOP.
 *
 * Read-only. Everything comes from GLPI's own model dropdowns (Setup › Dropdowns › Models), so pictures are added
 * in the model form: "Pictures", "Front picture", "Rear picture". A category is shown only to users who may see that
 * asset type.
 */
class PluginProjectreportsLom extends CommonGLPI
{
    /** key => [label, asset class, model class, model table, asset table, asset fk, drawing] */
    private const CORE = [
        'servers'  => ['Servers', 'Computer', 'ComputerModel', 'glpi_computermodels', 'glpi_computers', 'computermodels_id', 'server'],
        'network'  => ['Network equipment', 'NetworkEquipment', 'NetworkEquipmentModel', 'glpi_networkequipmentmodels', 'glpi_networkequipments', 'networkequipmentmodels_id', 'switch'],
        'passive'  => ['Passive equipment', 'PassiveDCEquipment', 'PassiveDCEquipmentModel', 'glpi_passivedcequipmentmodels', 'glpi_passivedcequipments', 'passivedcequipmentmodels_id', 'patch'],
        'pdus'     => ['PDUs', 'PDU', 'PDUModel', 'glpi_pdumodels', 'glpi_pdus', 'pdumodels_id', 'pdu'],
        'enclosures' => ['Enclosures', 'Enclosure', 'EnclosureModel', 'glpi_enclosuremodels', 'glpi_enclosures', 'enclosuremodels_id', 'enclosure'],
        'racks'    => ['Racks', 'Rack', 'RackModel', 'glpi_rackmodels', 'glpi_racks', 'rackmodels_id', 'rack'],
    ];

    /** Model names that are storage, whatever GLPI type they were recorded as (often Network equipment). */
    private const STORAGE_WORDS = '/\\b(storage|san|nas|powerstore|powervault|unity|vnx|disk ?shelf|dae|aff|fas|netapp|emc2?|3par|primera|nimble|alletra|msa|oceanstor|dorado|pure ?storage|flashsystem|storwize|array)\\b/i';

    public static function getTypeName($nb = 0)
    {
        return 'LOM catalog';
    }

    public static function getIcon()
    {
        return 'ti ti-books';
    }

    public static function canView(): bool
    {
        return count(self::categories()) > 0;
    }

    public static function getMenuContent()
    {
        return ['title' => self::getTypeName(), 'page' => '/plugins/projectreports/front/lom.php', 'icon' => self::getIcon()];
    }

    /** Parts lists of a model per supplier (0 = standard), each with its lines: the catalog window shows them. */
    public static function lists(string $model_class, int $models_id): array
    {
        $out = [];
        foreach (PluginProjectreportsLompart::variants($model_class, $models_id) as $sid => $v) {
            $v['rows'] = array_map(fn($r) => ['part' => (string) $r['part'], 'spare' => (string) $r['spare'],
                'description' => (string) $r['description'], 'qty' => (int) $r['qty']],
                PluginProjectreportsLompart::forModel($model_class, $models_id, false, (int) $sid));
            $out[$sid] = $v;
        }
        return $out;
    }

    /** LOM pages show no prices (1.22.4): the Price check still prices parts lists with the LOPs. */
    public static function canSeePrices(): bool
    {
        return false;
    }

    /**
     * Categories this user may see, core types first, then every custom asset type (e.g. SAN Storage).
     * Each: key, label, asset_class, model_class, model_table, asset_table, fk, drawing, where (extra model filter).
     */
    public static function categories(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $out = [];
        foreach (self::CORE as $key => [$label, $asset, $model, $mtable, $atable, $fk, $drawing]) {
            // Servers are always listed in the catalog (1.22.1), other types only where the user may view them
            if (class_exists($asset) && ($key === 'servers' || $asset::canView()) && $DB->tableExists($mtable)) {
                $out[$key] = compact('key', 'label', 'drawing') + ['asset_class' => $asset, 'model_class' => $model,
                    'model_table' => $mtable, 'asset_table' => $atable, 'fk' => $fk, 'where' => []];
            }
        }
        if ($DB->tableExists('glpi_assets_assetdefinitions') && class_exists(Glpi\Asset\AssetDefinition::class)) {
            foreach ($DB->request(['FROM' => 'glpi_assets_assetdefinitions', 'WHERE' => ['is_active' => 1], 'ORDER' => 'label']) as $d) {
                $def = new Glpi\Asset\AssetDefinition();
                if (!$def->getFromDB($d['id'])) {
                    continue;
                }
                $asset = $def->getAssetClassName();
                if (!class_exists($asset) || !$asset::canView()) {
                    continue;
                }
                $key = 'custom_' . $d['id'];
                $name = strtolower($d['system_name'] . ' ' . $d['label']);
                $out[$key] = ['key' => $key, 'label' => $d['label'] ?: $d['system_name'],
                    'drawing' => str_contains($name, 'stor') || str_contains($name, 'san') || str_contains($name, 'nas') ? 'storage' : 'device',
                    'asset_class' => $asset, 'model_class' => $def->getAssetModelClassName(),
                    'model_table' => 'glpi_assets_assetmodels', 'asset_table' => 'glpi_assets_assets', 'fk' => 'assets_assetmodels_id',
                    'where' => ['assets_assetdefinitions_id' => (int) $d['id']]];
            }
        }
        return $out;
    }

    private static function pictures(array $m): array
    {
        $pics = [];
        foreach (array_merge(importArrayFromDB((string) ($m['pictures'] ?? '')), [$m['picture_front'] ?? '', $m['picture_rear'] ?? '']) as $p) {
            if (is_string($p) && $p !== '' && !in_array($p, $pics, true)) {
                $pics[] = $p;
            }
        }
        return array_map(fn($p) => Toolbox::getPictureUrl($p), $pics);
    }

    /** Search option of the asset type that points to its model (differs between types). */
    private static function modelOption(string $asset_class, string $model_table): int
    {
        static $cache = [];
        if (!isset($cache[$asset_class])) {
            $cache[$asset_class] = 0;
            foreach (Search::getOptions($asset_class) as $id => $opt) {
                if (is_array($opt) && ($opt['table'] ?? '') === $model_table && ($opt['field'] ?? '') === 'name') {
                    $cache[$asset_class] = (int) $id;
                    break;
                }
            }
        }
        return $cache[$asset_class];
    }

    /** Link to the list of assets of this model. */
    private static function assetsUrl(array $c, int $models_id): string
    {
        // no link for users who may not read this kind of device (GLPI would answer "no permission")
        if (!class_exists($c['asset_class']) || !$c['asset_class']::canView()) {
            return '';
        }
        $opt = self::modelOption($c['asset_class'], $c['model_table']);
        $url = $c['asset_class']::getSearchURL();
        return $opt ? $url . (str_contains($url, '?') ? '&' : '?') . Toolbox::append_params(['criteria' => [['field' => $opt,
            'searchtype' => 'equals', 'value' => $models_id]], 'reset' => 'reset']) : $url;
    }

    /** All models of all visible categories, with pictures, specs, number in use and LOP prices. */
    public static function items(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $prices = self::canSeePrices() && $DB->tableExists(PluginProjectreportsLop::TABLE);
        $lop = PluginProjectreportsLop::info();
        $parts_count = PluginProjectreportsLompart::counts();
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $sheet = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomsheet.php';
        $out = [];
        foreach (self::categories() as $c) {
            $fields = ['id', 'name', 'comment', 'product_number', 'weight', 'required_units', 'depth', 'power_consumption',
                'picture_front', 'picture_rear', 'pictures'];
            $fields = array_values(array_filter($fields, fn($f) => $DB->fieldExists($c['model_table'], $f)));
            $used = [];
            if ($DB->tableExists($c['asset_table']) && $DB->fieldExists($c['asset_table'], $c['fk'])) {
                $where = [$c['fk'] => ['>', 0]];
                if ($DB->fieldExists($c['asset_table'], 'is_deleted')) {
                    $where['is_deleted'] = 0;
                }
                if ($DB->fieldExists($c['asset_table'], 'is_template')) {
                    $where['is_template'] = 0;
                }
                foreach ($DB->request(['SELECT' => [$c['fk'] . ' AS m', 'COUNT' => '* AS n'], 'FROM' => $c['asset_table'],
                    'WHERE' => $where, 'GROUPBY' => $c['fk']]) as $u) {
                    $used[(int) $u['m']] = (int) $u['n'];
                }
            }
            foreach ($DB->request(['SELECT' => $fields, 'FROM' => $c['model_table'], 'WHERE' => $c['where'], 'ORDER' => 'name']) as $m) {
                $part = trim((string) ($m['product_number'] ?? ''));
                $offers = [];
                if ($prices) {
                    $keys = array_values(array_unique(array_filter([$part, trim((string) $m['name'])], 'strlen')));
                    foreach ($DB->request(['SELECT' => ['suppliers_id', 'part', 'price', 'date_mod'], 'FROM' => PluginProjectreportsLop::TABLE,
                        'WHERE' => ['part' => $keys], 'ORDER' => 'price']) as $p) {
                        $sid = (int) $p['suppliers_id'];
                        if (isset($offers[$sid])) {
                            continue;
                        }
                        $offers[$sid] = ['supplier' => Dropdown::getDropdownName('glpi_suppliers', $sid), 'lop' => $lop[$sid]['name'] ?? '',
                            'part' => $p['part'], 'price' => (float) $p['price']];
                    }
                }
                $model_class = $c['model_class'];
                [$group, $group_label, $drawing] = [$c['key'], $c['key'] === 'network' ? 'Switches' : $c['label'], $c['drawing']];
                if (in_array($c['key'], ['network', 'enclosures'], true) || str_starts_with($c['key'], 'custom_')) {
                    if (preg_match(self::STORAGE_WORDS, $m['name'] . ' ' . $part) || $c['drawing'] === 'storage') {
                        [$group, $group_label, $drawing] = ['storage', 'Storage', 'storage'];
                    }
                }
                $out[] = [
                    'type' => $c['key'], 'model_class' => $model_class,
                    'parts' => $parts_count[$model_class][(int) $m['id']] ?? 0,
                    'sheet_url' => $sheet . '?' . http_build_query(['type' => $c['key'], 'id' => (int) $m['id']]),
                    'category' => $group, 'category_label' => $group_label, 'drawing' => $drawing, 'glpi_type' => $c['label'],
                    'id' => (int) $m['id'], 'name' => (string) $m['name'], 'part' => $part,
                    'comment' => (string) ($m['comment'] ?? ''),
                    'units' => (int) ($m['required_units'] ?? 0), 'power' => (int) ($m['power_consumption'] ?? 0),
                    'weight' => (int) ($m['weight'] ?? 0), 'depth' => (float) ($m['depth'] ?? 0),
                    'pictures' => self::pictures($m),
                    'in_use' => $used[(int) $m['id']] ?? 0,
                    'offers' => array_values($offers),
                    'lists' => self::lists($model_class, (int) $m['id']),
                    'url' => class_exists($model_class) ? $model_class::getFormURLWithID((int) $m['id']) : '',
                    'can_edit' => class_exists($model_class) && $model_class::canUpdate(),
                    'assets_url' => self::assetsUrl($c, (int) $m['id']),
                ];
            }
        }
        return $out;
    }

    /** One model of the catalog by category key and id, or null if this user may not see it. */
    public static function model(string $type, int $id): ?array
    {
        foreach (self::items() as $i) {
            if ($i['type'] === $type && $i['id'] === $id) {
                return $i + ['cat' => self::categories()[$type]];
            }
        }
        return null;
    }

    /**
     * The real devices of a model: name, serial, inventory number, location, rack, status, in-service date, age (years),
     * warranty end, photos (image documents attached in the device's Documents tab) and links.
     */
    public static function devices(array $c, int $models_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $t = $c['asset_table'];
        $class = $c['asset_class'];
        if (!$DB->tableExists($t) || !$DB->fieldExists($t, $c['fk'])) {
            return [];
        }
        $fields = array_values(array_filter(['id', 'name', 'serial', 'otherserial', 'locations_id', 'states_id'], fn($f) => $DB->fieldExists($t, $f)));
        $where = [$c['fk'] => $models_id];
        foreach (['is_deleted', 'is_template'] as $f) {
            if ($DB->fieldExists($t, $f)) {
                $where[$f] = 0;
            }
        }
        $rows = [];
        foreach ($DB->request(['SELECT' => $fields, 'FROM' => $t, 'WHERE' => $where, 'ORDER' => 'name']) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        if (!$rows) {
            return [];
        }
        $ids = array_keys($rows);
        $info = [];
        foreach ($DB->request(['FROM' => 'glpi_infocoms', 'WHERE' => ['itemtype' => $class, 'items_id' => $ids]]) as $ic) {
            $info[(int) $ic['items_id']] = $ic;
        }
        $racks = [];
        if ($DB->tableExists('glpi_items_racks')) {
            foreach ($DB->request(['SELECT' => ['ir.items_id', 'ir.position', 'r.name'], 'FROM' => 'glpi_items_racks AS ir',
                'INNER JOIN' => ['glpi_racks AS r' => ['ON' => ['r' => 'id', 'ir' => 'racks_id']]],
                'WHERE' => ['ir.itemtype' => $class, 'ir.items_id' => $ids]]) as $x) {
                $racks[(int) $x['items_id']] = $x['name'] . ' · U' . $x['position'];
            }
        }
        $photos = [];
        foreach ($DB->request(['SELECT' => ['di.items_id', 'd.id', 'd.filename'], 'FROM' => 'glpi_documents_items AS di',
            'INNER JOIN' => ['glpi_documents AS d' => ['ON' => ['d' => 'id', 'di' => 'documents_id']]],
            'WHERE' => ['di.itemtype' => $class, 'di.items_id' => $ids, 'd.is_deleted' => 0, 'd.mime' => ['LIKE', 'image/%']],
            'ORDER' => 'd.id DESC']) as $d) {
            $photos[(int) $d['items_id']][] = $CFG_GLPI['root_doc'] . '/front/document.send.php?' . http_build_query(
                ['docid' => (int) $d['id'], 'itemtype' => $class, 'items_id' => (int) $d['items_id']]);
        }
        $today = new DateTimeImmutable('today');
        $out = [];
        foreach ($rows as $id => $r) {
            $ic = $info[$id] ?? [];
            $since = $ic['use_date'] ?? null ?: ($ic['buy_date'] ?? null ?: ($ic['delivery_date'] ?? null));
            $age = $since ? round(((new DateTimeImmutable($since))->diff($today)->days) / 365.25, 1) : null;
            $wend = !empty($ic['warranty_date']) && (int) ($ic['warranty_duration'] ?? 0) > 0
                ? (new DateTimeImmutable($ic['warranty_date']))->modify('+' . (int) $ic['warranty_duration'] . ' month')->format('Y-m-d') : null;
            $form = $class::getFormURLWithID($id);
            $out[] = [
                'id' => $id, 'name' => (string) ($r['name'] ?? ''), 'serial' => (string) ($r['serial'] ?? ''),
                'otherserial' => (string) ($r['otherserial'] ?? ''),
                'location' => !empty($r['locations_id']) ? Dropdown::getDropdownName('glpi_locations', (int) $r['locations_id']) : '',
                'status' => !empty($r['states_id']) ? Dropdown::getDropdownName('glpi_states', (int) $r['states_id']) : '',
                'rack' => $racks[$id] ?? '', 'in_service' => $since, 'age' => $age, 'warranty_end' => $wend,
                'warranty_over' => $wend !== null && $wend < $today->format('Y-m-d'),
                'photos' => $photos[$id] ?? [], 'url' => $form,
                'photo_url' => $form . (str_contains($form, '?') ? '&' : '?') . 'forcetab=Document_Item$1',
                'finance_url' => $form . (str_contains($form, '?') ? '&' : '?') . 'forcetab=Infocom$1',
            ];
        }
        return $out;
    }
}
