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
        self::showEstimate($tickets_id);
        if (PluginProjectreportsAutoboq::answersForTicket($tickets_id) !== null) {
            self::showCalculator($tickets_id);
        }
    }

    private static function showEstimate(int $tickets_id): void
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

    /**
     * LOP calculator: the lines of one supplier's LOP (or of all its LOPs together) with their QTY per device from the
     * LOP; quantities can be raised or lowered (e.g. more RAM, fewer disks) and the totals follow, in the browser.
     * Nothing is saved: the quantities are a what-if, which can be exported as CSV.
     */
    private static function showCalculator(int $tickets_id): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $h = fn($v) => htmlescape((string) $v);
        $base = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/';
        $sel = $_SESSION['projectreports_pc'][$tickets_id] ?? [];
        $calc = $_SESSION['projectreports_calc'][$tickets_id] ?? [];

        // every supplier's LOPs, as "supplier|source" choices
        $options = [];
        foreach (PluginProjectreportsLop::suppliers() as $sid) {
            foreach (PluginProjectreportsLop::sourceChoices($sid) as $src => $label) {
                $options[$sid][$src] = $label;
            }
        }
        echo '<div class="p-2" style="max-width:1200px"><div class="card mb-3" id="pr-calc"><div class="card-header d-block"><h4 class="card-title m-0">LOP calculator</h4>'
            . '<div class="small text-secondary mt-1">Price a supplier\'s LOP with the QTY per device saved in it, then add or remove parts (for example more RAM or fewer disks) and check the total. Nothing is saved.</div></div>';
        if (!$options) {
            echo '<div class="alert alert-warning m-3">No LOP imported yet. <a href="' . $h($base . 'lop.php') . '">Import a LOP</a>.</div></div></div>';
            return;
        }
        $sid = (int) ($calc['sid'] ?? 0);
        if (!isset($options[$sid])) {
            $sid = (int) array_key_first($options);
        }
        $src = PluginProjectreportsLop::normSource($sid, (string) ($calc['src'] ?? 'active'));
        $devices = max(1, (int) ($calc['devices'] ?? ($sel['devices'] ?? 1)));

        echo '<div class="card-body pb-0"><form method="post" action="' . $h($base . 'pricecheck.form.php') . '" class="row g-2 align-items-end">'
            . '<input type="hidden" name="tickets_id" value="' . $tickets_id . '"><input type="hidden" name="action" value="calc">';
        echo '<div class="col-md-7"><label class="form-label" for="pr-calc-lop">Supplier and LOP</label><select class="form-select" id="pr-calc-lop" name="calc">';
        foreach ($options as $osid => $choices) {
            echo '<optgroup label="' . $h(Dropdown::getDropdownName('glpi_suppliers', $osid)) . '">';
            foreach ($choices as $v => $label) {
                echo '<option value="' . $osid . '|' . $h($v) . '"' . ($osid === $sid && $v === $src ? ' selected' : '') . '>' . $h($label) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select></div>';
        echo '<div class="col-md-2"><label class="form-label" for="pr-calc-dev">Number of devices</label><input class="form-control" type="number" min="1" step="1" id="pr-calc-dev" name="devices" value="' . $devices . '"></div>';
        echo '<div class="col-md-3"><button type="submit" class="btn btn-outline-primary"><i class="ti ti-list-numbers"></i> Load LOP</button></div>';
        Html::closeForm();
        echo '</div>';

        $lines = PluginProjectreportsLop::calcLines($sid, $src);
        if (!$lines) {
            echo '<div class="alert alert-warning m-3">This LOP has no lines.</div></div></div>';
            return;
        }
        $vat = PluginProjectreportsLop::sourceVat($sid, $src);
        $rule = isset(PluginProjectreportsLop::SOURCE_RULES[$src]);
        $empty = count(array_filter($lines, fn($l) => $l['qty_per_device'] === null));

        // money left in the request's budget (before tax), as in the price comparison above
        $budget = array_key_exists('budget', $sel) ? (int) $sel['budget'] : PluginProjectreportsDemand::budgetOf($tickets_id);
        $free = null;
        $budget_name = '';
        if ($budget > 0) {
            $binfo = PluginProjectreportsPlan::budgetInfo($budget);
            $own = 0.0;
            foreach (PluginProjectreportsDemand::boq($tickets_id)['list'] as $o) {
                if ($o['budgets_id'] === $budget) {
                    $own += $o['total'];
                }
            }
            $free = $binfo['available'] + $own;
            $budget_name = $binfo['name'];
        }

        echo '<div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">'
            . '<label class="form-check m-0"><input class="form-check-input" type="checkbox" id="pr-calc-hide"> <span class="form-check-label">Hide parts with quantity 0</span></label>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" id="pr-calc-reset"><i class="ti ti-restore"></i> Reset to the LOP quantities</button>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" id="pr-calc-csv"><i class="ti ti-download"></i> Export CSV</button>'
            . ($empty ? '<span class="small text-secondary">' . $empty . ' part(s) have no QTY per device in the LOP: they start at 0.</span>' : '')
            . '</div>';
        echo '<div class="table-responsive" style="max-height:560px;overflow:auto"><table class="table table-sm table-vcenter card-table m-0"><thead class="sticky-top bg-white"><tr>'
            . '<th>Part Number</th><th>Description</th><th>Spare Part Number</th>' . ($rule ? '<th>Price from</th>' : '')
            . '<th class="text-end">Unit price</th><th class="text-center" style="min-width:170px">QTY per device</th><th class="text-end">Qty for <span class="pr-calc-n">' . $devices . '</span> device(s)</th><th class="text-end">Line total</th></tr></thead><tbody>';
        foreach ($lines as $i => $l) {
            $q = (int) ($l['qty_per_device'] ?? 0);
            $id = 'pr-calc-q' . $i;
            echo '<tr data-price="' . $h($l['price']) . '" data-lop="' . $q . '" data-part="' . $h($l['part']) . '">'
                . '<td class="text-nowrap fw-bold">' . $h($l['part']) . ($l['device'] !== '' ? ' <span class="badge bg-blue-lt">' . $h($l['device']) . '</span>' : '') . '</td>'
                . '<td class="small">' . $h($l['description']) . '</td><td class="text-nowrap">' . $h($l['spare']) . '</td>'
                . ($rule ? '<td class="small text-secondary">' . $h($l['lop']) . '</td>' : '')
                . '<td class="text-end">' . $h(self::money($l['price'])) . '</td>'
                . '<td><div class="input-group input-group-sm flex-nowrap justify-content-center">'
                . '<button type="button" class="btn btn-outline-secondary pr-calc-minus" aria-label="One less ' . $h($l['part']) . '">−</button>'
                . '<input type="number" min="0" step="1" class="form-control text-center pr-calc-qty" style="max-width:70px" id="' . $id . '" value="' . $q . '" aria-label="QTY per device of ' . $h($l['part']) . '">'
                . '<button type="button" class="btn btn-outline-secondary pr-calc-plus" aria-label="One more ' . $h($l['part']) . '">+</button></div>'
                . '<div class="small text-center pr-calc-was text-warning" hidden>LOP: ' . ($l['qty_per_device'] === null ? 'empty' : $q) . '</div></td>'
                . '<td class="text-end pr-calc-all"></td><td class="text-end fw-bold pr-calc-line"></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="card-footer"><div class="row g-2 text-end" style="max-width:640px;margin-left:auto">'
            . '<div class="col-8">One device (before tax)</div><div class="col-4 fw-bold" id="pr-calc-one"></div>'
            . '<div class="col-8"><span class="pr-calc-n">' . $devices . '</span> device(s), before tax</div><div class="col-4 fw-bold" id="pr-calc-total"></div>'
            . '<div class="col-8">VAT ' . $h(self::money($vat)) . '%</div><div class="col-4" id="pr-calc-vat"></div>'
            . '<div class="col-8 fs-3">Total with VAT</div><div class="col-4 fs-3 fw-bold" id="pr-calc-ttc"></div>'
            . ($free !== null ? '<div class="col-8">Budget ' . $h($budget_name) . ': money left ' . $h(self::money($free)) . ' (before tax)</div><div class="col-4 fw-bold" id="pr-calc-budget"></div>' : '')
            . '<div class="col-12 small text-secondary" id="pr-calc-changed"></div>'
            . '</div></div>';
        echo '</div></div>';

        $js = [
            'vat' => $vat, 'free' => $free, 'devices' => $devices,
            'name' => 'LOP-calculator-' . $tickets_id . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', Dropdown::getDropdownName('glpi_suppliers', $sid) . '-' . PluginProjectreportsLop::sourceLabel($sid, $src)),
            'head' => ['Supplier', Dropdown::getDropdownName('glpi_suppliers', $sid), 'LOP', PluginProjectreportsLop::sourceLabel($sid, $src), 'Devices', $devices],
        ];
        echo '<script>(function(){const C=' . json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
            . "const box=document.getElementById('pr-calc');if(!box)return;"
            . "const nf=new Intl.NumberFormat('en-US',{maximumFractionDigits:2});"
            . "const rows=[...box.querySelectorAll('tbody tr')];"
            . "const qty=r=>Math.max(0,parseInt(r.querySelector('.pr-calc-qty').value,10)||0);"
            . "function calc(){let one=0,changed=0;const hide=box.querySelector('#pr-calc-hide').checked;"
            . "rows.forEach(r=>{const q=qty(r),p=parseFloat(r.dataset.price)||0;one+=q*p;"
            . "r.querySelector('.pr-calc-all').textContent=nf.format(q*C.devices);r.querySelector('.pr-calc-line').textContent=nf.format(q*p*C.devices);"
            . "const diff=q!==parseInt(r.dataset.lop,10);if(diff)changed++;r.querySelector('.pr-calc-was').hidden=!diff;r.classList.toggle('table-warning',diff);"
            . "r.classList.toggle('text-secondary',q===0);r.hidden=hide&&q===0;});"
            . "const tot=one*C.devices,v=tot*C.vat/100;"
            . "box.querySelector('#pr-calc-one').textContent=nf.format(one);box.querySelector('#pr-calc-total').textContent=nf.format(tot);"
            . "box.querySelector('#pr-calc-vat').textContent=nf.format(v);box.querySelector('#pr-calc-ttc').textContent=nf.format(tot+v);"
            . "const b=box.querySelector('#pr-calc-budget');if(b&&C.free!==null){const left=C.free-tot;b.textContent=left>=0?'enough, left '+nf.format(left):'short by '+nf.format(-left);b.className='col-4 fw-bold '+(left>=0?'text-success':'text-danger');}"
            . "box.querySelector('#pr-calc-changed').textContent=changed?changed+' quantit'+(changed>1?'ies':'y')+' changed from the LOP (highlighted).':'';}"
            . "box.addEventListener('click',e=>{const m=e.target.closest('.pr-calc-minus,.pr-calc-plus');if(!m)return;const i=m.parentNode.querySelector('.pr-calc-qty');"
            . "i.value=Math.max(0,(parseInt(i.value,10)||0)+(m.classList.contains('pr-calc-plus')?1:-1));calc();});"
            . "box.addEventListener('input',e=>{if(e.target.matches('.pr-calc-qty,#pr-calc-hide'))calc();});"
            . "box.querySelector('#pr-calc-hide').addEventListener('change',calc);"
            . "box.querySelector('#pr-calc-reset').addEventListener('click',()=>{rows.forEach(r=>{r.querySelector('.pr-calc-qty').value=r.dataset.lop;});calc();});"
            . "box.querySelector('#pr-calc-csv').addEventListener('click',()=>{const esc=v=>{v=String(v);return /[\",\\n]/.test(v)?'\"'+v.replace(/\"/g,'\"\"')+'\"':v;};"
            . "const out=[C.head,[],['Part Number','Part Description','Spare Part Number','Unit price tax free','QTY per device','QTY per device in the LOP','Quantity','Line total']];let one=0;"
            . "rows.forEach(r=>{const q=qty(r),p=parseFloat(r.dataset.price)||0;one+=q*p;const c=r.children;"
            . "out.push([r.dataset.part,c[1].textContent,c[2].textContent,p,q,r.dataset.lop,q*C.devices,q*p*C.devices]);});"
            . "const tot=one*C.devices;out.push([],['','','','','','','Total before tax',tot],['','','','','','','VAT (%)',C.vat],['','','','','','','Total with VAT',tot*(1+C.vat/100)]);"
            . "const blob=new Blob(['\\ufeff'+out.map(r=>r.map(esc).join(',')).join('\\r\\n')],{type:'text/csv;charset=utf-8'});"
            . "const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=C.name+'.csv';document.body.appendChild(a);a.click();a.remove();});"
            . "calc();})();</script>";
    }
}
