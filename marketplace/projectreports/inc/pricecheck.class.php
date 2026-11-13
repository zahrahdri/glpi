<?php

/**
 * Ticket › "Price check" tab, on tickets created by a capacity request form.
 * The user chooses a device model of the LOM catalog (with a parts list) and the number of devices; the parts list
 * is priced with every supplier's current LOP, part by part, and a user who may create orders creates the draft BOQ
 * with the supplier they choose. Nothing is written until that button is pressed.
 */
class PluginProjectreportsPricecheck extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Price check';
    }

    public static function getIcon()
    {
        return 'ti ti-scale';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || $item->isNewItem() || !PluginProjectreportsBoqimport::canImport()) {
            return '';
        }
        if (PluginProjectreportsAutoboq::answersForTicket((int) $item->getID()) === null) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Ticket) {
            self::show((int) $item->getID());
        }
        return true;
    }

    private static function money(float $v): string
    {
        return PluginProjectreportsAutoboq::fmtNum($v);
    }

    public static function show(int $tickets_id): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $h = fn($v) => htmlescape((string) $v);
        $base = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/';
        $a = PluginProjectreportsAutoboq::answersForTicket($tickets_id);
        if ($a === null) {
            echo '<div class="alert alert-info">This ticket was not created by a capacity request form.</div>';
            return;
        }
        $req = PluginProjectreportsAutoboq::request($a);

        echo '<div class="p-2" style="max-width:1200px">';
        // ---- the request
        $project = $req['projects_id'] ? Dropdown::getDropdownName('glpi_projects', $req['projects_id'])
            : (is_scalar($a['New project name'] ?? null) && trim((string) $a['New project name']) !== '' ? 'New: ' . $a['New project name'] : '—');
        echo '<div class="card mb-3"><div class="card-body py-2"><div class="row g-2">';
        foreach ([
            ['Request type', $req['request_type'] ?: '—'],
            ['Resource', trim($req['category'] . ($req['resource_type'] ? ' · ' . $req['resource_type'] : '')) ?: '—'],
            ['Requested', $req['qty'] > 0 ? self::money($req['qty']) . ' ' . $req['unit'] : '—'],
            ['Project', $project],
        ] as [$k, $v]) {
            echo '<div class="col-md-3"><div class="text-secondary small">' . $h($k) . '</div><div class="fw-bold">' . $h($v) . '</div></div>';
        }
        echo '</div></div></div>';

        // ---- cannot be priced
        if (($why = PluginProjectreportsAutoboq::problem($req)) !== '') {
            echo '<div class="alert alert-info">' . $h($why) . '</div></div>';
            return;
        }
        $models = PluginProjectreportsAutoboq::models();
        if (!$models) {
            echo '<div class="alert alert-warning">No device model has a parts list yet, so nothing can be priced. Open a model in '
                . '<a href="' . $h($base . 'lom.php') . '">Tools › LOM catalog</a> and add its parts list (part numbers and quantities).</div></div>';
            return;
        }
        $sel = $_SESSION['projectreports_pc'][$tickets_id] ?? [];
        $key = isset($models[$sel['key'] ?? '']) ? $sel['key'] : PluginProjectreportsAutoboq::suggest($req, $models);
        $devices = max(1, (int) ($sel['devices'] ?? ($req['category'] === 'Compute' && $req['qty'] >= 1 ? (int) round($req['qty']) : 1)));
        $budget = array_key_exists('budget', $sel) ? (int) $sel['budget'] : PluginProjectreportsDemand::budgetOf($tickets_id);
        $sources = (array) ($sel['lops'] ?? []);

        // ---- choose the device model and the number of devices
        echo '<div class="card mb-3"><div class="card-body py-2">';
        echo '<form method="post" action="' . $h($base . 'pricecheck.form.php') . '" class="row g-2 align-items-end"><input type="hidden" name="tickets_id" value="' . $tickets_id . '"><input type="hidden" name="action" value="select">';
        echo '<div class="col-md-5"><label class="form-label" for="pr-pc-model">Device model (LOM parts list)</label><select class="form-select" id="pr-pc-model" name="model"><option value="">— choose —</option>';
        $group = null;
        foreach ($models as $k => $m) {
            if ($m['category_label'] !== $group) {
                echo ($group === null ? '' : '</optgroup>') . '<optgroup label="' . $h($m['category_label']) . '">';
                $group = $m['category_label'];
            }
            echo '<option value="' . $h($k) . '"' . ($k === $key ? ' selected' : '') . '>' . $h($m['name'] . ($m['part'] !== '' ? ' · ' . $m['part'] : '') . ' (' . $m['parts'] . ' parts)') . '</option>';
        }
        echo '</optgroup></select></div>';
        echo '<div class="col-md-2"><label class="form-label" for="pr-pc-dev">Number of devices</label><input class="form-control" type="number" min="1" step="1" id="pr-pc-dev" name="devices" value="' . $devices . '"></div>';
        echo '<div class="col-md-3"><label class="form-label">Budget to check the price</label>';
        Dropdown::show('Budget', ['name' => 'budget', 'value' => $budget, 'display_emptychoice' => true, 'emptylabel' => '— none —']);
        echo '</div>';
        echo '<div class="col-md-2"><button type="submit" class="btn btn-outline-primary"><i class="ti ti-calculator"></i> Show prices</button></div>';
        // suppliers with more than one LOP: which one (or which rule) prices them
        foreach (PluginProjectreportsLop::suppliers() as $lsid) {
            $choices = PluginProjectreportsLop::sourceChoices($lsid);
            if (count($choices) < 2) {
                continue;
            }
            $cur = PluginProjectreportsLop::normSource($lsid, (string) ($sources[$lsid] ?? 'active'));
            $fid = 'pr-pc-lop-' . $lsid;
            echo '<div class="col-md-5"><label class="form-label" for="' . $fid . '">Prices from: ' . $h(Dropdown::getDropdownName('glpi_suppliers', $lsid)) . '</label>'
                . '<select class="form-select" id="' . $fid . '" name="lops[' . $lsid . ']">';
            foreach ($choices as $v => $label) {
                echo '<option value="' . $h($v) . '"' . ($v === $cur ? ' selected' : '') . '>' . $h($label) . '</option>';
            }
            echo '</select></div>';
        }
        Html::closeForm();
        echo '<div class="small text-secondary mt-1">Only models with a parts list are offered. Parts lists are edited in <a href="' . $h($base . 'lom.php') . '">Tools › LOM catalog</a>. '
            . 'A supplier with several LOPs is priced with its active LOP unless you choose another LOP, or all its LOPs (newest or lowest price of each part), under Prices from.</div>';
        echo '</div></div>';
        if ($key === '') {
            echo '</div>';
            return;
        }

        // ---- BOQs already linked
        $orders = PluginProjectreportsAutoboq::linkedOrders($tickets_id);
        if ($orders) {
            echo '<div class="card mb-3"><div class="card-header"><h4 class="card-title m-0">BOQ already linked to this request</h4></div><div class="table-responsive"><table class="table table-sm table-vcenter card-table m-0"><thead><tr><th>Order</th><th>Number</th><th>Supplier</th><th>Status</th><th class="text-end">Total before tax</th></tr></thead><tbody>';
            foreach ($orders as $o) {
                echo '<tr><td><a href="' . $h($CFG_GLPI['root_doc'] . '/plugins/order/front/order.form.php?id=' . $o['id']) . '">' . $h($o['name']) . '</a></td><td>' . $h($o['num_order']) . '</td>'
                    . '<td>' . $h($o['suppliers_id'] ? Dropdown::getDropdownName('glpi_suppliers', (int) $o['suppliers_id']) : '—') . '</td><td>' . $h($o['state_name']) . '</td>'
                    . '<td class="text-end fw-bold">' . $h(self::money($o['total'])) . '</td></tr>';
            }
            echo '</tbody></table></div><div class="card-footer small text-secondary">Demand &amp; cost uses these orders as the request\'s cost. Cancel an order in the Orders plugin if you replace it with another supplier.</div></div>';
        }

        $est = PluginProjectreportsAutoboq::estimate($key, $devices, $sources);
        $model = $est['model'];
        $S = $est['suppliers'];
        $complete = array_values(array_filter($S, fn($s) => $s['complete']));
        $best = $complete[0] ?? null;
        $info = PluginProjectreportsLop::info();

        echo '<div class="card mb-3"><div class="card-header d-block"><h4 class="card-title m-0">Price with each supplier\'s LOP</h4>'
            . '<div class="small text-secondary mt-1"><b>' . $devices . ' × </b><a href="' . $h($model['sheet_url']) . '">' . $h($model['name']) . '</a> ('
            . count($est['lines']) . ' parts per device). Unit prices before tax; VAT from each supplier\'s LOP.</div></div>';
        if (!$S) {
            echo '<div class="alert alert-warning m-3">No supplier\'s LOP has any part of this parts list. <a href="' . $h($base . 'lop.php') . '">Import or update the LOPs</a>.</div></div></div>';
            return;
        }
        if (!$complete) {
            echo '<div class="alert alert-warning m-3">No supplier\'s LOP has all the parts. Parts missing from an LOP are priced 0. '
                . '<a href="' . $h($base . 'lop.php') . '">Import or update the LOPs</a>.</div>';
        }

        $can = PluginProjectreportsBoqimport::canImport();
        echo '<form method="post" action="' . $h($base . 'pricecheck.form.php') . '"><input type="hidden" name="tickets_id" value="' . $tickets_id . '">'
            . '<input type="hidden" name="action" value="create"><input type="hidden" name="model" value="' . $h($key) . '"><input type="hidden" name="devices" value="' . $devices . '">';
        foreach ($S as $s) {
            echo '<input type="hidden" name="lops[' . (int) $s['suppliers_id'] . ']" value="' . $h($s['source']) . '">';
        }
        echo '<div class="table-responsive"><table class="table table-vcenter card-table m-0"><thead><tr><th style="width:36px"></th><th>Supplier</th><th>LOP</th><th>Parts in LOP</th>'
            . '<th class="text-end">One device</th><th class="text-end">' . $devices . ' device(s)<div class="small fw-normal">before tax</div></th><th class="text-end">VAT</th><th class="text-end">' . $devices . ' device(s)<div class="small fw-normal">with VAT</div></th><th class="text-end">vs. cheapest</th></tr></thead><tbody>';
        $pick = $best['suppliers_id'] ?? ($S[0]['suppliers_id'] ?? 0);
        foreach ($S as $i => $s) {
            $lop = $info[$s['suppliers_id']] ?? null;
            $tt = fn($x) => $x['all_total'] * (1 + $x['vat'] / 100);
            $diff = $best && $tt($best) > 0 && $s['complete'] ? ($tt($s) - $tt($best)) / $tt($best) : null;
            $badge = $s['complete'] ? '<span class="badge bg-green-lt">all ' . $s['parts'] . '</span>'
                : '<span class="badge bg-orange-lt">' . $s['covered'] . ' of ' . $s['parts'] . '</span>';
            $rid = 'pr-pc-' . $tickets_id . '-' . $i;
            echo '<tr' . ($s['suppliers_id'] === ($best['suppliers_id'] ?? -1) ? ' class="table-success"' : '') . '>'
                . '<td><input class="form-check-input" type="radio" name="suppliers_id" value="' . (int) $s['suppliers_id'] . '"' . ($s['suppliers_id'] === $pick ? ' checked' : '') . ($can ? '' : ' disabled') . ' aria-label="Choose ' . $h($s['supplier']) . '"></td>'
                . '<td class="fw-bold">' . $h($s['supplier']) . ($s['suppliers_id'] === ($best['suppliers_id'] ?? -1) ? ' <span class="badge bg-green text-white ms-1">cheapest</span>' : '') . '</td>'
                . '<td>' . $h($s['lop']) . ($s['source'] === 'active' && $lop ? '<div class="small text-secondary">' . $h(substr((string) ($lop['imported'] ?? ''), 0, 10)) . '</div>' : '') . '</td>'
                . '<td>' . $badge . ' <a class="small ms-1" data-bs-toggle="collapse" href="#' . $rid . '" role="button">parts</a></td>'
                . '<td class="text-end">' . $h(self::money($s['total'])) . '</td>'
                . '<td class="text-end">' . $h(self::money($s['all_total'])) . '</td>'
                . '<td class="text-end">' . $h(self::money($s['vat'])) . '%</td>'
                . '<td class="text-end fw-bold">' . $h(self::money($s['all_total'] * (1 + $s['vat'] / 100))) . '</td>'
                . '<td class="text-end">' . ($diff === null ? '<span class="text-secondary small">' . ($s['complete'] ? '' : 'incomplete') . '</span>' : ($diff == 0 ? '—' : '<span class="text-danger">+' . round($diff * 100, 1) . '%</span>')) . '</td></tr>';
            // parts of this supplier
            echo '<tr class="collapse" id="' . $rid . '"><td></td><td colspan="8" class="bg-light"><table class="table table-sm m-0"><thead><tr><th>Part Number</th><th>Spare Part number</th><th>Description</th><th class="text-end">Qty per device</th><th class="text-end">Unit price</th><th>Price from</th><th class="text-end">' . $devices . ' device(s)</th></tr></thead><tbody>';
            foreach ($s['lines'] as $l) {
                $src = $l['source'] === 'LOP' ? '<span class="badge bg-green-lt">LOP</span>' . (isset(PluginProjectreportsLop::SOURCE_RULES[$s['source']]) && ($l['lop'] ?? '') !== '' ? ' <span class="small">' . $h($l['lop']) . '</span>' : '')
                    : '<span class="badge bg-red-lt">not in LOP</span>';
                echo '<tr><td class="text-nowrap">' . $h($l['part']) . '</td><td class="text-nowrap">' . $h($l['spare']) . '</td><td class="small">' . $h($l['desc'] ?? '') . '</td><td class="text-end">' . $h(self::money((float) $l['qty'])) . '</td>'
                    . '<td class="text-end">' . $h(self::money((float) $l['price'])) . '</td><td>' . $src . '</td><td class="text-end">' . $h(self::money($l['qty'] * $l['price'] * $devices)) . '</td></tr>';
            }
            echo '</tbody></table></td></tr>';
        }
        echo '</tbody></table></div>';
        // budget check: each supplier's price before tax against the money the budget has left
        if ($budget > 0) {
            $info = PluginProjectreportsPlan::budgetInfo($budget);
            $own = 0.0;
            foreach (PluginProjectreportsDemand::boq($tickets_id)['list'] as $o) {
                if ($o['budgets_id'] === $budget) {
                    $own += $o['total']; // already counted in this budget (pipeline or committed): add it back
                }
            }
            $free = $info['available'] + $own;
            echo '<div class="card-footer"><div class="fw-bold mb-1">Budget check: ' . $h($info['name']) . ' · money left ' . $h(self::money($free)) . ' (before tax)</div>';
            foreach ($S as $s) {
                $left = $free - $s['all_total'];
                echo '<div class="small">' . $h($s['supplier']) . ': ' . $h(self::money($s['all_total'])) . ' → '
                    . ($left >= 0 ? '<span class="text-success fw-bold">enough, left ' . $h(self::money($left)) . '</span>'
                        : '<span class="text-danger fw-bold">short by ' . $h(self::money(-$left)) . '</span>') . '</div>';
            }
            echo '</div>';
        }
        echo '<div class="card-footer d-flex flex-wrap gap-3 align-items-center">';
        if ($can) {
            echo '<button type="submit" class="btn btn-primary"' . ($S ? '' : ' disabled') . '><i class="ti ti-file-plus"></i> Create draft BOQ with the selected supplier</button>'
                . '<span class="small text-secondary">Creates a draft order (number LOP-' . $tickets_id . ') linked to this ticket, with a private note. '
                . ($orders ? '<b class="text-warning">This request already has a BOQ: a second one would be counted too.</b>' : 'Nothing is created until you press the button.') . '</span>';
        } else {
            echo '<span class="small text-secondary">You can compare the prices. Creating the BOQ needs the right to create orders.</span>';
        }
        echo '<button type="submit" formaction="' . $h($base . 'pricecheckexport.php') . '" class="btn btn-outline-secondary"'
            . ($S ? '' : ' disabled') . '><i class="ti ti-download"></i> Export CSV for the selected supplier</button>';
        echo '</div>';
        Html::closeForm();
        echo '</div></div>';
    }
}
