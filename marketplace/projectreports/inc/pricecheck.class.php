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

    /** Styles of the Price check tab (cards, summary tiles, compact tables). */
    private static function styles(): string
    {
        return '<style>
.pr-pc{max-width:1200px}
.pr-pc .pr-req{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:baseline}
.pr-pc .pr-req .k{color:var(--tblr-secondary,#667382);font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;margin-right:6px}
.pr-pc .pr-req .v{font-weight:600}
.pr-pc .pr-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.pr-pc .pr-tile{border:1px solid var(--tblr-border-color,#e6e7e9);border-radius:8px;padding:10px 14px}
.pr-pc .pr-tile .l{color:var(--tblr-secondary,#667382);font-size:.75rem;text-transform:uppercase;letter-spacing:.03em}
.pr-pc .pr-tile .n{font-size:1.35rem;font-weight:700;line-height:1.3}
.pr-pc .pr-tile .s{font-size:.8rem;color:var(--tblr-secondary,#667382)}
.pr-pc table.pr-cmp td,.pr-pc table.pr-cmp th{vertical-align:middle}
.pr-pc table.pr-cmp tr.pr-pick{background:rgba(32,107,196,.06)}
.pr-pc .pr-muted{color:var(--tblr-secondary,#667382);font-size:.8rem}
.pr-pc details>summary{cursor:pointer;color:var(--tblr-primary,#206bc4);font-size:.875rem}
</style>';
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
        echo self::styles() . '<div class="pr-pc p-2">';

        // ---- the request, on one line
        $project = $req['projects_id'] ? Dropdown::getDropdownName('glpi_projects', $req['projects_id'])
            : (is_scalar($a['New project name'] ?? null) && trim((string) $a['New project name']) !== '' ? 'New: ' . $a['New project name'] : '—');
        echo '<div class="card mb-3"><div class="card-body py-2 pr-req">';
        foreach ([
            ['Request', $req['request_type'] ?: '—'],
            ['Resource', trim($req['category'] . ($req['resource_type'] ? ' · ' . $req['resource_type'] : '')) ?: '—'],
            ['Requested', $req['qty'] > 0 ? self::money($req['qty']) . ' ' . $req['unit'] : '—'],
            ['Project', $project],
        ] as [$k, $v]) {
            echo '<div><span class="k">' . $h($k) . '</span><span class="v">' . $h($v) . '</span></div>';
        }
        echo '</div></div>';

        if (($why = PluginProjectreportsAutoboq::problem($req)) !== '') {
            echo '<div class="alert alert-info">' . $h($why) . '</div></div>';
            return;
        }
        $models = PluginProjectreportsAutoboq::models();
        if (!$models) {
            echo '<div class="alert alert-warning">No device model has a parts list yet. Add one in <a href="' . $h($base . 'lom.php') . '">Tools › LOM catalog</a>.</div></div>';
            return;
        }
        $sel = $_SESSION['projectreports_pc'][$tickets_id] ?? [];
        $key = isset($models[$sel['key'] ?? '']) ? $sel['key'] : PluginProjectreportsAutoboq::suggest($req, $models);
        $devices = max(1, (int) ($sel['devices'] ?? ($req['category'] === 'Compute' && $req['qty'] >= 1 ? (int) round($req['qty']) : 1)));
        $budget = array_key_exists('budget', $sel) ? (int) $sel['budget'] : PluginProjectreportsDemand::budgetOf($tickets_id);
        $sources = (array) ($sel['lops'] ?? []);

        // ---- what to price: model, devices, budget (and, folded away, which LOP prices a supplier with several)
        echo '<div class="card mb-3"><div class="card-body py-3">';
        echo '<form method="post" action="' . $h($base . 'pricecheck.form.php') . '"><input type="hidden" name="tickets_id" value="' . $tickets_id . '"><input type="hidden" name="action" value="select">';
        echo '<div class="row g-2 align-items-end">';
        echo '<div class="col-md-5"><label class="form-label" for="pr-pc-model">Device model</label><select class="form-select" id="pr-pc-model" name="model"><option value="">— choose —</option>';
        $group = null;
        foreach ($models as $k => $m) {
            if ($m['category_label'] !== $group) {
                echo ($group === null ? '' : '</optgroup>') . '<optgroup label="' . $h($m['category_label']) . '">';
                $group = $m['category_label'];
            }
            echo '<option value="' . $h($k) . '"' . ($k === $key ? ' selected' : '') . '>' . $h($m['name'] . ($m['part'] !== '' ? ' · ' . $m['part'] : '')) . '</option>';
        }
        echo '</optgroup></select></div>';
        echo '<div class="col-md-2"><label class="form-label" for="pr-pc-dev">Devices</label><input class="form-control" type="number" min="1" step="1" id="pr-pc-dev" name="devices" value="' . $devices . '"></div>';
        echo '<div class="col-md-3"><label class="form-label">Budget</label>';
        Dropdown::show('Budget', ['name' => 'budget', 'value' => $budget, 'display_emptychoice' => true, 'emptylabel' => '— none —']);
        echo '</div>';
        echo '<div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="ti ti-calculator me-1"></i>Show prices</button></div>';
        echo '</div>';
        $multi = [];
        foreach (PluginProjectreportsLop::suppliers() as $lsid) {
            $choices = PluginProjectreportsLop::sourceChoices($lsid);
            if (count($choices) > 1) {
                $multi[$lsid] = $choices;
            }
        }
        if ($multi) {
            $changed = count(array_filter(array_keys($multi), fn($lsid) => PluginProjectreportsLop::normSource($lsid, (string) ($sources[$lsid] ?? 'active')) !== 'active'));
            echo '<details class="mt-2"' . ($changed ? ' open' : '') . '><summary>Which LOP to use' . ($changed ? ' · ' . $changed . ' changed' : '') . '</summary>'
                . '<div class="pr-muted my-1">Suppliers with several LOPs use their active LOP unless you choose another one, or all of them (newest or lowest price of each part).</div><div class="row g-2">';
            foreach ($multi as $lsid => $choices) {
                $cur = PluginProjectreportsLop::normSource($lsid, (string) ($sources[$lsid] ?? 'active'));
                $fid = 'pr-pc-lop-' . $lsid;
                echo '<div class="col-md-6"><label class="form-label small mb-1" for="' . $fid . '">' . $h(Dropdown::getDropdownName('glpi_suppliers', $lsid)) . '</label>'
                    . '<select class="form-select form-select-sm" id="' . $fid . '" name="lops[' . $lsid . ']">';
                foreach ($choices as $v => $label) {
                    echo '<option value="' . $h($v) . '"' . ($v === $cur ? ' selected' : '') . '>' . $h($label) . '</option>';
                }
                echo '</select></div>';
            }
            echo '</div></details>';
        }
        Html::closeForm();
        echo '</div></div>';
        if ($key === '') {
            echo '</div>';
            return;
        }

        // ---- BOQs already linked, one line each
        $orders = PluginProjectreportsAutoboq::linkedOrders($tickets_id);
        if ($orders) {
            echo '<div class="alert alert-warning py-2"><b>This request already has ' . count($orders) . ' BOQ' . (count($orders) > 1 ? 's' : '') . '.</b> A new one would be counted too; cancel the old order in the Orders plugin if you replace it.<ul class="mb-0 mt-1">';
            foreach ($orders as $o) {
                echo '<li><a href="' . $h($CFG_GLPI['root_doc'] . '/plugins/order/front/order.form.php?id=' . $o['id']) . '">' . $h($o['num_order'] ?: $o['name']) . '</a> · '
                    . $h($o['suppliers_id'] ? Dropdown::getDropdownName('glpi_suppliers', (int) $o['suppliers_id']) : '—') . ' · ' . $h($o['state_name']) . ' · <b>' . $h(self::money($o['total'])) . '</b> before tax</li>';
            }
            echo '</ul></div>';
        }

        $est = PluginProjectreportsAutoboq::estimate($key, $devices, $sources);
        $model = $est['model'];
        $S = $est['suppliers'];
        $complete = array_values(array_filter($S, fn($s) => $s['complete']));
        $best = $complete[0] ?? null;
        $ttc = fn($x) => $x['all_total'] * (1 + $x['vat'] / 100);

        // money left in the budget (before tax), adding back what this request's own BOQs already use
        $free = null;
        $bname = '';
        if ($budget > 0) {
            $binfo = PluginProjectreportsPlan::budgetInfo($budget);
            $own = 0.0;
            foreach (PluginProjectreportsDemand::boq($tickets_id)['list'] as $o) {
                if ($o['budgets_id'] === $budget) {
                    $own += $o['total'];
                }
            }
            $free = $binfo['available'] + $own;
            $bname = $binfo['name'];
        }

        echo '<div class="card mb-3"><div class="card-header"><div><h3 class="card-title m-0">' . $devices . ' × <a href="' . $h($model['sheet_url']) . '">' . $h($model['name']) . '</a></h3>'
            . '<div class="pr-muted">' . count($est['lines']) . ' parts per device · priced with each supplier\'s LOP</div></div></div>';
        if (!$S) {
            echo '<div class="card-body"><div class="alert alert-warning m-0">No supplier\'s LOP has any part of this model. <a href="' . $h($base . 'lop.php') . '">Import or update the LOPs</a>.</div></div></div></div>';
            return;
        }

        // ---- summary tiles
        echo '<div class="card-body pb-2"><div class="pr-tiles">';
        if ($best) {
            echo '<div class="pr-tile"><div class="l">Cheapest complete offer</div><div class="n text-success">' . $h(self::money($ttc($best))) . '</div><div class="s">' . $h($best['supplier']) . ' · with VAT</div></div>';
        } else {
            echo '<div class="pr-tile"><div class="l">Cheapest complete offer</div><div class="n text-warning">none</div><div class="s">No LOP has all the parts; missing parts count as 0</div></div>';
        }
        echo '<div class="pr-tile"><div class="l">Suppliers compared</div><div class="n">' . count($S) . '</div><div class="s">' . count($complete) . ' with every part</div></div>';
        if ($free !== null) {
            echo '<div class="pr-tile"><div class="l">Budget left · before tax</div><div class="n">' . $h(self::money($free)) . '</div><div class="s">' . $h($bname) . '</div></div>';
        }
        echo '</div></div>';

        // ---- one row per supplier
        $can = PluginProjectreportsBoqimport::canImport();
        $pick = $best['suppliers_id'] ?? ($S[0]['suppliers_id'] ?? 0);
        echo '<form method="post" action="' . $h($base . 'pricecheck.form.php') . '"><input type="hidden" name="tickets_id" value="' . $tickets_id . '">'
            . '<input type="hidden" name="action" value="create"><input type="hidden" name="model" value="' . $h($key) . '"><input type="hidden" name="devices" value="' . $devices . '">';
        foreach ($S as $s) {
            echo '<input type="hidden" name="lops[' . (int) $s['suppliers_id'] . ']" value="' . $h($s['source']) . '">';
        }
        echo '<div class="table-responsive"><table class="table table-vcenter card-table pr-cmp m-0"><thead><tr><th style="width:36px"></th><th>Supplier</th><th>Parts</th>'
            . '<th class="text-end">Before tax</th><th class="text-end">With VAT</th><th class="text-end">vs cheapest</th>' . ($free !== null ? '<th class="text-end">Budget</th>' : '') . '<th></th></tr></thead><tbody>';
        foreach ($S as $i => $s) {
            $diff = $best && $ttc($best) > 0 && $s['complete'] ? ($ttc($s) - $ttc($best)) / $ttc($best) : null;
            $is_best = $s['suppliers_id'] === ($best['suppliers_id'] ?? -1);
            $rid = 'pr-pc-' . $tickets_id . '-' . $i;
            $missing = $s['parts'] - $s['covered'];
            echo '<tr' . ($s['suppliers_id'] === $pick ? ' class="pr-pick"' : '') . '>'
                . '<td><input class="form-check-input" type="radio" name="suppliers_id" value="' . (int) $s['suppliers_id'] . '"' . ($s['suppliers_id'] === $pick ? ' checked' : '') . ($can ? '' : ' disabled') . ' aria-label="Choose ' . $h($s['supplier']) . '"></td>'
                . '<td><div class="fw-bold">' . $h($s['supplier']) . ($is_best ? ' <span class="badge bg-green-lt ms-1">cheapest</span>' : '') . '</div><div class="pr-muted">' . $h($s['lop']) . '</div></td>'
                . '<td>' . ($s['complete'] ? '<span class="badge bg-green-lt">all ' . $s['parts'] . '</span>' : '<span class="badge bg-orange-lt" title="' . $missing . ' part(s) not in this LOP, counted as 0">' . $s['covered'] . ' / ' . $s['parts'] . '</span>') . '</td>'
                . '<td class="text-end">' . $h(self::money($s['all_total'])) . '</td>'
                . '<td class="text-end"><div class="fw-bold">' . $h(self::money($ttc($s))) . '</div><div class="pr-muted">VAT ' . $h(self::money($s['vat'])) . '%</div></td>'
                . '<td class="text-end">' . ($diff === null ? '<span class="pr-muted">' . ($s['complete'] ? '' : 'incomplete') . '</span>' : ($diff == 0 ? '—' : '<span class="text-danger">+' . round($diff * 100, 1) . '%</span>')) . '</td>';
            if ($free !== null) {
                $left = $free - $s['all_total'];
                echo '<td class="text-end">' . ($left >= 0 ? '<span class="text-success" title="Left after this BOQ: ' . $h(self::money($left)) . '">fits</span>'
                    : '<span class="text-danger" title="Short by ' . $h(self::money(-$left)) . '">short ' . $h(self::money(-$left)) . '</span>') . '</td>';
            }
            echo '<td class="text-end"><a class="btn btn-sm btn-ghost-secondary" data-bs-toggle="collapse" href="#' . $rid . '" role="button" aria-expanded="false" aria-controls="' . $rid . '">Parts <i class="ti ti-chevron-down"></i></a></td></tr>';
            // the parts of this supplier, folded
            $cols = $free !== null ? 8 : 7;
            echo '<tr class="collapse" id="' . $rid . '"><td colspan="' . $cols . '" class="p-0"><div class="px-4 py-2 bg-light"><table class="table table-sm m-0 bg-transparent"><thead><tr><th>Part Number</th><th>Description</th>'
                . '<th class="text-end">Qty / device</th><th class="text-end">Unit price</th><th class="text-end">' . $devices . ' device(s)</th></tr></thead><tbody>';
            foreach ($s['lines'] as $l) {
                $from = $l['source'] !== 'LOP' ? ' <span class="badge bg-red-lt">not in LOP</span>'
                    : (isset(PluginProjectreportsLop::SOURCE_RULES[$s['source']]) && ($l['lop'] ?? '') !== '' ? ' <span class="pr-muted">· ' . $h($l['lop']) . '</span>' : '');
                echo '<tr><td class="text-nowrap">' . $h($l['part']) . $from . ($l['spare'] !== '' && $l['spare'] !== $l['part'] ? '<div class="pr-muted">' . $h($l['spare']) . '</div>' : '') . '</td>'
                    . '<td class="small">' . $h($l['desc'] ?? '') . '</td><td class="text-end">' . $h(self::money((float) $l['qty'])) . '</td>'
                    . '<td class="text-end">' . $h(self::money((float) $l['price'])) . '</td><td class="text-end">' . $h(self::money($l['qty'] * $l['price'] * $devices)) . '</td></tr>';
            }
            echo '</tbody></table></div></td></tr>';
        }
        echo '</tbody></table></div>';
        echo "<script>document.querySelectorAll('table.pr-cmp input[name=suppliers_id]').forEach(r=>r.addEventListener('change',()=>{"
            . "r.closest('tbody').querySelectorAll('tr').forEach(t=>t.classList.remove('pr-pick'));r.closest('tr').classList.add('pr-pick');}));</script>";

        // ---- actions
        echo '<div class="card-footer d-flex flex-wrap gap-2 align-items-center">';
        if ($can) {
            echo '<button type="submit" class="btn btn-primary"><i class="ti ti-file-plus me-1"></i>Create draft BOQ</button>';
        }
        echo '<button type="submit" formaction="' . $h($base . 'pricecheckexport.php') . '" class="btn btn-outline-secondary"><i class="ti ti-download me-1"></i>Export CSV</button>'
            . '<span class="pr-muted ms-1">' . ($can ? 'For the selected supplier. The BOQ is a draft order linked to this ticket.' : 'Creating a BOQ needs the right to create orders.') . '</span>';
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
        echo '<div class="pr-pc p-2"><div class="card mb-3" id="pr-calc"><div class="card-header"><div><h3 class="card-title m-0">LOP calculator</h3>'
            . '<div class="pr-muted">Start from a LOP\'s QTY per device, change any quantity, check the total. Nothing is saved.</div></div></div>';
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

        echo '<div class="card-body py-3"><form method="post" action="' . $h($base . 'pricecheck.form.php') . '" class="row g-2 align-items-end">'
            . '<input type="hidden" name="tickets_id" value="' . $tickets_id . '"><input type="hidden" name="action" value="calc">';
        echo '<div class="col-md-7"><label class="form-label" for="pr-calc-lop">LOP</label><select class="form-select" id="pr-calc-lop" name="calc">';
        foreach ($options as $osid => $choices) {
            echo '<optgroup label="' . $h(Dropdown::getDropdownName('glpi_suppliers', $osid)) . '">';
            foreach ($choices as $v => $label) {
                echo '<option value="' . $osid . '|' . $h($v) . '"' . ($osid === $sid && $v === $src ? ' selected' : '') . '>' . $h($label) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select></div>';
        echo '<div class="col-md-2"><label class="form-label" for="pr-calc-dev">Devices</label><input class="form-control" type="number" min="1" step="1" id="pr-calc-dev" name="devices" value="' . $devices . '"></div>';
        echo '<div class="col-md-3"><button type="submit" class="btn btn-outline-primary w-100"><i class="ti ti-list-numbers me-1"></i>Load</button></div>';
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

        echo '<div class="px-3 pb-2 d-flex flex-wrap gap-2 align-items-center border-bottom">'
            . '<label class="form-check form-switch m-0 me-2"><input class="form-check-input" type="checkbox" id="pr-calc-hide"> <span class="form-check-label">Hide 0</span></label>'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" id="pr-calc-reset"><i class="ti ti-restore me-1"></i>Reset</button>'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" id="pr-calc-csv"><i class="ti ti-download me-1"></i>Export CSV</button>'
            . ($empty ? '<span class="pr-muted ms-auto" title="Fill QTY per device in Price lists (LOP) › Edit">' . $empty . ' part(s) without QTY per device start at 0</span>' : '')
            . '</div>';
        echo '<div class="table-responsive" style="max-height:560px;overflow:auto"><table class="table table-sm table-vcenter card-table m-0"><thead class="sticky-top"><tr>'
            . '<th>Part</th><th>Description</th><th class="text-end">Unit price</th><th class="text-center" style="width:150px">Per device</th><th class="text-end">Line total</th></tr></thead><tbody>';
        foreach ($lines as $i => $l) {
            $q = (int) ($l['qty_per_device'] ?? 0);
            $id = 'pr-calc-q' . $i;
            echo '<tr data-price="' . $h($l['price']) . '" data-lop="' . $q . '" data-part="' . $h($l['part']) . '" data-desc="' . $h($l['description']) . '" data-spare="' . $h($l['spare']) . '">'
                . '<td class="text-nowrap"><span class="fw-bold">' . $h($l['part']) . '</span>' . ($l['device'] !== '' ? ' <span class="badge bg-blue-lt">' . $h($l['device']) . '</span>' : '')
                . ($l['spare'] !== '' && $l['spare'] !== $l['part'] ? '<div class="pr-muted">' . $h($l['spare']) . '</div>' : '') . ($rule ? '<div class="pr-muted">' . $h($l['lop']) . '</div>' : '') . '</td>'
                . '<td class="small">' . $h($l['description']) . '</td>'
                . '<td class="text-end">' . $h(self::money($l['price'])) . '</td>'
                . '<td><div class="input-group input-group-sm flex-nowrap justify-content-center">'
                . '<button type="button" class="btn btn-outline-secondary pr-calc-minus" aria-label="One less ' . $h($l['part']) . '">−</button>'
                . '<input type="number" min="0" step="1" class="form-control text-center pr-calc-qty" style="max-width:60px" id="' . $id . '" value="' . $q . '" aria-label="QTY per device of ' . $h($l['part']) . '">'
                . '<button type="button" class="btn btn-outline-secondary pr-calc-plus" aria-label="One more ' . $h($l['part']) . '">+</button></div>'
                . '<div class="small text-center pr-calc-was text-warning" hidden>LOP: ' . ($l['qty_per_device'] === null ? 'empty' : $q) . '</div></td>'
                . '<td class="text-end"><div class="fw-bold pr-calc-line"></div><div class="pr-muted"><span class="pr-calc-all"></span> pcs</div></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="card-body"><div class="pr-tiles">'
            . '<div class="pr-tile"><div class="l">One device</div><div class="n" id="pr-calc-one"></div><div class="s">before tax</div></div>'
            . '<div class="pr-tile"><div class="l">' . $devices . ' device(s)</div><div class="n" id="pr-calc-total"></div><div class="s">before tax · VAT ' . $h(self::money($vat)) . '% = <span id="pr-calc-vat"></span></div></div>'
            . '<div class="pr-tile" style="border-color:var(--tblr-primary,#206bc4)"><div class="l">Total with VAT</div><div class="n text-primary" id="pr-calc-ttc"></div><div class="s" id="pr-calc-changed">&nbsp;</div></div>'
            . ($free !== null ? '<div class="pr-tile"><div class="l">Budget · ' . $h($budget_name) . '</div><div class="n" id="pr-calc-budget"></div><div class="s">' . $h(self::money($free)) . ' left before tax</div></div>' : '')
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
            . "const b=box.querySelector('#pr-calc-budget');if(b&&C.free!==null){const left=C.free-tot;b.textContent=left>=0?'fits':'short '+nf.format(-left);b.className='n '+(left>=0?'text-success':'text-danger');}"
            . "box.querySelector('#pr-calc-changed').textContent=changed?changed+' quantit'+(changed>1?'ies':'y')+' changed from the LOP':'LOP quantities';}"
            . "box.addEventListener('click',e=>{const m=e.target.closest('.pr-calc-minus,.pr-calc-plus');if(!m)return;const i=m.parentNode.querySelector('.pr-calc-qty');"
            . "i.value=Math.max(0,(parseInt(i.value,10)||0)+(m.classList.contains('pr-calc-plus')?1:-1));calc();});"
            . "box.addEventListener('input',e=>{if(e.target.matches('.pr-calc-qty,#pr-calc-hide'))calc();});"
            . "box.querySelector('#pr-calc-hide').addEventListener('change',calc);"
            . "box.querySelector('#pr-calc-reset').addEventListener('click',()=>{rows.forEach(r=>{r.querySelector('.pr-calc-qty').value=r.dataset.lop;});calc();});"
            . "box.querySelector('#pr-calc-csv').addEventListener('click',()=>{const esc=v=>{v=String(v);return /[\",\\n]/.test(v)?'\"'+v.replace(/\"/g,'\"\"')+'\"':v;};"
            . "const out=[C.head,[],['Part Number','Part Description','Spare Part Number','Unit price tax free','QTY per device','QTY per device in the LOP','Quantity','Line total']];let one=0;"
            . "rows.forEach(r=>{const q=qty(r),p=parseFloat(r.dataset.price)||0;one+=q*p;"
            . "out.push([r.dataset.part,r.dataset.desc,r.dataset.spare,p,q,r.dataset.lop,q*C.devices,q*p*C.devices]);});"
            . "const tot=one*C.devices;out.push([],['','','','','','','Total before tax',tot],['','','','','','','VAT (%)',C.vat],['','','','','','','Total with VAT',tot*(1+C.vat/100)]);"
            . "const blob=new Blob(['\\ufeff'+out.map(r=>r.map(esc).join(',')).join('\\r\\n')],{type:'text/csv;charset=utf-8'});"
            . "const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=C.name+'.csv';document.body.appendChild(a);a.click();a.remove();});"
            . "calc();})();</script>";
    }
}
