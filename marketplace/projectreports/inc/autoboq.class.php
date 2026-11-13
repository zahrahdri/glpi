<?php

/**
 * BOQ of a capacity request, built from a LOM parts list and priced with the suppliers' LOPs.
 *
 * On the ticket's Price check tab the user chooses a device model of the LOM catalog (it must have a parts list) and
 * the number of devices. The model's parts list x number of devices is priced with EVERY supplier's current LOP, and
 * a user with the right to create orders creates the draft BOQ with the supplier they choose (order number LOP-<ticket>).
 *
 * Budget: the project's budget (Costs tab), if any.
 */
class PluginProjectreportsAutoboq
{
    /** Resource category of the form => [question with the quantity, unit] */
    public const CATEGORIES = [
        'Compute' => ['quantity' => 'Quantity', 'unit' => 'server(s)'],
        'Storage' => ['quantity' => 'Capacity', 'unit' => 'TB'],
        'GPU'     => ['quantity' => 'Number of GPUs', 'unit' => 'GPU(s)'],
    ];

    /** Answers of the form, by question label, with choice keys (cat1, rt1 ...) turned into their labels. */
    public static function answers(Glpi\Form\AnswersSet $set): array
    {
        $out = [];
        foreach ($set->getAnswers() as $answer) {
            $raw = $answer->getRawAnswer();
            $value = $raw;
            if (is_string($raw) || (is_array($raw) && array_is_list($raw) && count($raw) && is_string($raw[0]))) {
                $question = new Glpi\Form\Question();
                if ($question->getFromDB($answer->getQuestionId())) {
                    $extra = json_decode((string) ($question->fields['extra_data'] ?? ''), true);
                    $options = $extra['options'] ?? null;
                    if (is_array($options)) {
                        $keys = (array) $raw;
                        $labels = array_map(fn($k) => $options[$k] ?? $k, $keys);
                        $value = is_array($raw) ? $labels : $labels[0];
                    }
                }
            }
            $out[$answer->getQuestionLabel()] = $value;
        }
        return $out;
    }

    /** Answers of the capacity request form that created this ticket, or null for any other ticket. */
    public static function answersForTicket(int $tickets_id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;
        if ($tickets_id <= 0 || !$DB->tableExists('glpi_forms_destinations_answerssets_formdestinationitems')) {
            return null;
        }
        $row = $DB->request(['SELECT' => 'forms_answerssets_id', 'FROM' => 'glpi_forms_destinations_answerssets_formdestinationitems',
            'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $tickets_id], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        if (!$row) {
            return null;
        }
        $set = new Glpi\Form\AnswersSet();
        if (!$set->getFromDB((int) $row['forms_answerssets_id'])) {
            return null;
        }
        $a = self::answers($set);
        return array_key_exists('Request type', $a) ? $a : null;
    }

    private static function first($v): string
    {
        return trim((string) (is_array($v) ? ($v[0] ?? '') : $v));
    }

    /** What the request asks for (as typed in the form). */
    public static function request(array $a): array
    {
        $category = self::first($a['Resource category'] ?? '');
        $qty = 0.0;
        if (isset(self::CATEGORIES[$category])) {
            $qty = (float) PluginProjectreportsBoqimport::number(self::first($a[self::CATEGORIES[$category]['quantity']] ?? '0'));
            if ($category === 'Storage' && self::first($a['Unit'] ?? 'TB') === 'GB') {
                $qty /= 1000;
            }
        }
        $project = $a['Project'] ?? null;
        return [
            'request_type'  => self::first($a['Request type'] ?? ''),
            'category'      => $category,
            'resource_type' => $category === 'Compute' ? self::first($a['Resource type'] ?? '') : '',
            'qty'           => $qty,
            'unit'          => self::CATEGORIES[$category]['unit'] ?? '',
            'projects_id'   => is_array($project) && ($project['itemtype'] ?? '') === Project::class ? (int) $project['items_id'] : 0,
        ];
    }

    /** Why this request cannot be priced, or '' when it can. */
    public static function problem(array $req): string
    {
        if ($req['request_type'] === 'Release old resources') {
            return 'Release requests give resources back: there is nothing to price.';
        }
        return '';
    }

    /** LOM models that have a parts list: [key "type:id" => item of PluginProjectreportsLom::items()], servers first. */
    public static function models(): array
    {
        $rank = ['servers' => 0, 'storage' => 1, 'network' => 2, 'pdus' => 3, 'racks' => 4];
        $items = array_filter(PluginProjectreportsLom::items(), fn($i) => $i['parts'] > 0);
        usort($items, fn($a, $b) => ($rank[$a['category']] ?? 9) <=> ($rank[$b['category']] ?? 9) ?: strcasecmp($a['name'], $b['name']));
        $out = [];
        foreach ($items as $i) {
            $out[$i['type'] . ':' . $i['id']] = $i;
        }
        return $out;
    }

    /** The model suggested for a request: Compute -> first server model, Storage -> first storage model. */
    public static function suggest(array $req, array $models): string
    {
        $want = ['Compute' => 'servers', 'GPU' => 'servers', 'Storage' => 'storage'][$req['category']] ?? '';
        foreach ($models as $k => $m) {
            if ($m['category'] === $want) {
                return $k;
            }
        }
        return '';
    }

    /**
     * $devices x the parts list of a LOM model, priced with every supplier that has an LOP for some of its parts,
     * best first (all parts in the LOP, then cheapest). Each supplier row: suppliers_id, supplier, lop, total (one
     * device), covered, parts, complete, lines (priced), all_total.
     */
    /**
     * Price a model with every supplier's LOP. $sources: [suppliers_id => source] chosen in the Price check
     * (see PluginProjectreportsLop::SOURCE_RULES); a supplier not in it is priced with its active LOP.
     */
    public static function estimate(string $key, int $devices, array $sources = []): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $model = self::models()[$key] ?? null;
        if ($model === null) {
            return ['model' => null];
        }
        $mc = $model['model_class'];
        $id = (int) $model['id'];
        // each supplier is priced with the parts list entered for it; a supplier without one uses the standard list (0)
        $standard = self::partLines($mc, $id, 0);
        $own = [];
        foreach (array_keys(PluginProjectreportsLompart::variants($mc, $id)) as $sid) {
            if ((int) $sid > 0) {
                $own[] = (int) $sid;
            }
        }
        $sids = array_values(array_unique(array_merge(PluginProjectreportsLop::suppliers(), $own)));
        $rows = [];
        foreach ($sids as $sid) {
            $lines = self::partLines($mc, $id, $sid) ?: $standard;
            if (!$lines) {
                continue;
            }
            $source = PluginProjectreportsLop::normSource($sid, (string) ($sources[$sid] ?? 'active'));
            // prices, and spare part numbers for parts whose parts-list line has none, from the chosen LOP(s)
            $priced = PluginProjectreportsLop::priceLines($lines, $sid, $source);
            $covered = count(array_filter($priced, fn($l) => $l['source'] === 'LOP'));
            if ($covered === 0) {
                continue;
            }
            $one = array_sum(array_map(fn($l) => $l['qty'] * $l['price'], $priced));
            $rows[] = ['suppliers_id' => $sid, 'supplier' => Dropdown::getDropdownName('glpi_suppliers', $sid),
                'lop' => PluginProjectreportsLop::sourceLabel($sid, $source), 'source' => $source, 'total' => $one, 'covered' => $covered, 'parts' => count($lines),
                'complete' => $covered === count($lines), 'lines' => $priced, 'all_total' => $one * $devices,
                'vat' => PluginProjectreportsLop::sourceVat($sid, $source)];
        }
        usort($rows, fn($a, $b) => (int) $b['complete'] <=> (int) $a['complete'] ?: $b['covered'] <=> $a['covered']
            ?: $a['total'] * (1 + $a['vat'] / 100) <=> $b['total'] * (1 + $b['vat'] / 100));
        $top = $standard ?: ($own ? self::partLines($mc, $id, $own[0]) : []);
        return ['model' => $model, 'devices' => $devices, 'lines' => $top, 'suppliers' => $rows];
    }

    /** The parts list of a model for one supplier (0 = the standard list), as lines for the price check. */
    private static function partLines(string $model_class, int $models_id, int $suppliers_id): array
    {
        $lines = [];
        foreach (PluginProjectreportsLompart::forModel($model_class, $models_id, false, $suppliers_id) as $p) {
            if ((int) $p['qty'] <= 0) {
                continue;
            }
            $lines[] = ['part' => (string) $p['part'], 'spare' => (string) $p['spare'], 'desc' => (string) ($p['description'] ?? ''),
                'qty' => (int) $p['qty'], 'price' => 0.0, 'model' => '', 'itemtype' => 'PluginOrderOther', 'reference' => 0];
        }
        return $lines;
    }

    public static function projectBudget(int $projects_id): int
    {
        /** @var DBmysql $DB */
        global $DB;
        if ($projects_id <= 0) {
            return 0;
        }
        $row = $DB->request(['SELECT' => 'budgets_id', 'FROM' => 'glpi_projectcosts',
            'WHERE' => ['projects_id' => $projects_id, 'budgets_id' => ['>', 0]], 'ORDER' => 'id', 'LIMIT' => 1])->current();
        return $row ? (int) $row['budgets_id'] : 0;
    }

    private static function note(int $tickets_id, string $html): void
    {
        $fup = new ITILFollowup();
        $fup->add(['itemtype' => Ticket::class, 'items_id' => $tickets_id, 'is_private' => 1,
            'content' => $html, '_disablenotif' => true]);
    }

    /** BOQ orders linked to the ticket (Items tab): id, name, num_order, supplier, state, total. */
    public static function linkedOrders(int $tickets_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!$DB->tableExists('glpi_plugin_order_orders')) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['o.id', 'o.name', 'o.num_order', 'o.suppliers_id', 'o.plugin_order_orderstates_id AS state', 'o.port_price'],
            'FROM' => 'glpi_items_tickets AS it',
            'INNER JOIN' => ['glpi_plugin_order_orders AS o' => ['ON' => ['o' => 'id', 'it' => 'items_id', ['AND' => ['it.itemtype' => 'PluginOrderOrder']]]]],
            'WHERE' => ['it.tickets_id' => $tickets_id, 'o.is_deleted' => 0],
            'ORDER' => 'o.id',
        ]) as $o) {
            $sum = $DB->request(['SELECT' => ['SUM' => 'price_discounted AS t'], 'FROM' => 'glpi_plugin_order_orders_items',
                'WHERE' => ['plugin_order_orders_id' => $o['id']]])->current();
            $o['total'] = (float) ($sum['t'] ?? 0) + (float) $o['port_price'];
            $o['state_name'] = Dropdown::getDropdownName('glpi_plugin_order_orderstates', (int) $o['state']);
            $out[] = $o;
        }
        return $out;
    }

    private static function freeNumber(string $base): string
    {
        /** @var DBmysql $DB */
        global $DB;
        $num = $base;
        for ($n = 2; $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_plugin_order_orders',
            'WHERE' => ['num_order' => $num, 'is_deleted' => 0]])->current()['c'] > 0; $n++) {
            $num = $base . '-' . $n;
        }
        return $num;
    }

    /**
     * Create the draft BOQ order of a request ticket: $devices x the parts list of LOM model $key, priced with
     * supplier $sid's LOP, and link it to the ticket (order number LOP-<ticket>).
     * @return int order id
     * @throws RuntimeException when the request cannot be priced
     */
    public static function create(int $tickets_id, array $a, string $key, int $devices, int $sid, array $sources = []): int
    {
        $req = self::request($a);
        if (($why = self::problem($req)) !== '') {
            throw new RuntimeException($why);
        }
        if ($devices < 1) {
            throw new RuntimeException('Enter the number of devices (1 or more).');
        }
        $est = self::estimate($key, $devices, $sources);
        if ($est['model'] === null) {
            throw new RuntimeException('Choose a device model of the LOM catalog that has a parts list.');
        }
        $model = $est['model'];
        $row = null;
        foreach ($est['suppliers'] as $s) {
            if ($s['suppliers_id'] === $sid) {
                $row = $s;
            }
        }
        if ($row === null) {
            throw new RuntimeException('This supplier has no LOP prices for the parts of "' . $model['name'] . '".');
        }

        $ticket = new Ticket();
        $ticket->getFromDB($tickets_id);
        $budget = self::projectBudget($req['projects_id']);

        /** @var DBmysql $DB */
        global $DB;
        // what the chosen LOP(s) say about each part: whole device? spare part number?
        $lop = PluginProjectreportsLop::partPrices($sid, array_column($row['lines'], 'part'), (string) $row['source']);
        $asset = PluginProjectreportsLom::categories()[$model['type']]['asset_class'] ?? '';
        $lines = [];
        foreach ($row['lines'] as $l) {
            $info = $lop[$l['part']] ?? null;
            if ($l['spare'] === '' && $info) {
                $l['spare'] = (string) $info['spare'];
            }
            // the line that is the device itself (LOP Device column) is ordered as that asset type, with the model
            if ($info && $info['device'] !== '' && $asset !== '' && !str_contains($asset, '\\')) {
                $l['itemtype'] = $asset;
                $l['model'] = $model['name'];
            }
            // the catalog reference of the part, when it already exists
            $ref = $DB->request(['SELECT' => ['id', 'itemtype'], 'FROM' => 'glpi_plugin_order_references',
                'WHERE' => ['name' => $l['part'], 'is_deleted' => 0], 'LIMIT' => 1])->current();
            if ($ref) {
                $l['reference'] = (int) $ref['id'];
                $l['itemtype'] = $ref['itemtype'];
            }
            $lines[] = $l;
        }
        $not_in_lop = array_values(array_unique(array_map(fn($l) => $l['part'], array_filter($lines, fn($l) => $l['source'] !== 'LOP'))));
        $user = getUserName(Session::getLoginUserID());

        [$oid, $st] = PluginProjectreportsBoqimport::create($lines, [
            'set_prices' => false,
            'name' => sprintf('BOQ #%d %s x %d', $tickets_id, $model['name'], $devices),
            'num_order' => self::freeNumber(sprintf('LOP-%d', $tickets_id)),
            'budgets_id' => $budget, 'suppliers_id' => $sid, 'taxes_id' => PluginProjectreportsLop::orderTaxId((float) $row['vat']),
            'order_date' => date('Y-m-d'), 'quote' => '', 'mult' => $devices, 'file' => '',
            'entities_id' => (int) $ticket->fields['entities_id'],
            'comment' => sprintf('Estimate for ticket #%d: %d x %s (LOM parts list, %d parts per device). Supplier chosen in the Price check by %s. Draft until approved.',
                $tickets_id, $devices, $model['name'], count($lines), $user) . self::compareText($est['suppliers'], $devices),
        ]);

        (new Item_Ticket())->add(['tickets_id' => $tickets_id, 'itemtype' => 'PluginOrderOrder', 'items_id' => $oid]);

        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $url = $CFG_GLPI['root_doc'] . '/plugins/order/front/order.form.php?id=' . $oid;
        $priced = sprintf('Supplier <b>%s</b>, prices from LOP <i>%s</i>, VAT ' . htmlescape(self::fmtNum((float) $row['vat'])) . '%%.', htmlescape(Dropdown::getDropdownName('glpi_suppliers', $sid)), htmlescape($row['lop']));
        if (isset(PluginProjectreportsLop::SOURCE_RULES[$row['source']])) {
            $from = array_count_values(array_filter(array_column($row['lines'], 'lop'), 'strlen'));
            $priced .= ' Prices taken from: ' . htmlescape(implode(', ', array_map(fn($n, $c) => "$n ($c parts)", array_keys($from), $from))) . '.';
        }
        if ($not_in_lop) {
            $priced .= ' <b>Not in the LOP</b> (price 0): ' . htmlescape(implode(', ', $not_in_lop)) . '.';
        }
        $order = new PluginOrderOrder();
        $order->getFromDB($oid);
        self::note($tickets_id, sprintf(
            '<p><b>BOQ created from the Price check: %s</b> (before tax)</p><p>%d × <i>%s</i> (LOM parts list), %d order lines.<br>%s<br>Supplier chosen by %s.<br>Charged to %s. Draft order: <a href="%s">%s</a>.</p>',
            htmlescape(self::fmtNum($st['total'])), $devices, htmlescape($model['name']), $st['units'], $priced, htmlescape($user),
            htmlescape($budget ? 'project budget (' . Dropdown::getDropdownName('glpi_budgets', $budget) . ')' : 'no budget yet'),
            htmlescape($url), htmlescape($order->fields['num_order'] ?? '')
        ) . self::compareHtml($est['suppliers'], $sid, $devices));
        return $oid;
    }

    /** The estimate with every supplier, as an HTML table for the ticket note. */
    private static function compareHtml(array $compare, int $chosen, int $devices): string
    {
        if (!$compare) {
            return '';
        }
        $rows = '';
        foreach ($compare as $c) {
            $used = $c['suppliers_id'] === $chosen;
            $rows .= sprintf('<tr><td>%s%s</td><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td>%s</td></tr>',
                $used ? '<b>' : '', htmlescape($c['supplier']) . ($used ? '</b>' : ''), htmlescape($c['lop']),
                htmlescape(self::fmtNum($c['total'])), $used ? '<b>' . htmlescape(self::fmtNum($c['total'] * $devices)) . '</b>' : htmlescape(self::fmtNum($c['total'] * $devices)),
                ($c['covered'] < $c['parts'] ? sprintf('%d of %d parts in LOP', $c['covered'], $c['parts']) : 'all parts')
                . ($used ? ' · <b>used</b>' : ''));
        }
        return '<p><b>Estimate per supplier</b> (supplier chosen in the Price check):</p>'
            . '<table border="1" cellpadding="4" style="border-collapse:collapse"><tr><th>Supplier</th><th>LOP</th><th>One device</th><th>' . $devices . ' device(s)</th><th></th></tr>'
            . $rows . '</table>';
    }

    /** Same comparison as plain text, for the order's comment. */
    private static function compareText(array $compare, int $devices): string
    {
        if (count($compare) < 2) {
            return '';
        }
        $parts = array_map(fn($c) => sprintf('%s %s%s', $c['supplier'], self::fmtNum($c['total'] * $devices),
            $c['covered'] < $c['parts'] ? sprintf(' (%d/%d parts)', $c['covered'], $c['parts']) : ''), $compare);
        return ' Suppliers compared: ' . implode('; ', $parts) . '.';
    }

    public static function fmtNum(float $n): string
    {
        $n = round($n, 2);
        return number_format($n, floor($n) == $n ? 0 : 2);
    }
}
