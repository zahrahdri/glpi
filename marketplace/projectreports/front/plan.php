<?php

/**
 * Tools › LOM plans: the device models a project plans to buy, priced with each supplier's LOP and checked against a
 * GLPI budget. What does not fit is shown as a shortfall, to be ordered in the next budget year.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsPlan::canView()) {
    Html::displayRightError();
}
$can = PluginProjectreportsPlan::canEdit();
$h = fn($v) => htmlescape((string) $v);
$money = fn($v) => PluginProjectreportsAutoboq::fmtNum((float) $v);
$base = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/plan.php';
$plan_id = (int) ($_REQUEST['id'] ?? 0);

// ---- changes
$action = (string) ($_POST['action'] ?? '');
if ($action !== '') {
    if (!$can) {
        Html::displayRightError();
    }
    // rights are checked above; these forms do not use a token (like the LOM sheet and the Price check)
    $msg = '';
    switch ($action) {
        case 'create_plan':
            $plan_id = PluginProjectreportsPlan::createPlan((string) ($_POST['name'] ?? ''), (int) ($_POST['budgets_id'] ?? 0));
            $msg = 'Plan created.';
            break;
        case 'update_plan':
            PluginProjectreportsPlan::updatePlan($plan_id, (string) ($_POST['name'] ?? ''), (int) ($_POST['budgets_id'] ?? 0));
            $msg = 'Plan saved.';
            break;
        case 'delete_plan':
            PluginProjectreportsPlan::deletePlan($plan_id);
            Session::addMessageAfterRedirect('Plan deleted.', false, INFO);
            Html::redirect($base);
            exit;
        case 'add_line':
            $ok = PluginProjectreportsPlan::addLine($plan_id, (string) ($_POST['model_key'] ?? ''), (int) ($_POST['devices'] ?? 1), (int) ($_POST['suppliers_id'] ?? 0));
            $msg = $ok ? 'Line added.' : 'Choose a model that has a parts list.';
            break;
        case 'update_line':
            PluginProjectreportsPlan::updateLine((int) ($_POST['line_id'] ?? 0), (int) ($_POST['devices'] ?? 1), (int) ($_POST['suppliers_id'] ?? 0));
            $msg = 'Line saved.';
            break;
        case 'delete_line':
            PluginProjectreportsPlan::deleteLine((int) ($_POST['line_id'] ?? 0));
            $msg = 'Line removed.';
            break;
    }
    Session::addMessageAfterRedirect($h($msg), false, INFO);
    Html::redirect($base . ($plan_id > 0 ? '?id=' . $plan_id : ''));
    exit;
}

$plan = PluginProjectreportsPlan::getPlan($plan_id);
$csrf = fn() => '<input type="hidden" name="_glpi_csrf_token" value="' . $h(Session::getNewCSRFToken(true)) . '">';

Html::header('LOM plans', '', 'tools', 'PluginProjectreportsLom');
?>
<style>
.pp{--ink:#1d2939;--muted:#667085;--line:#e4e7ec;--accent:#4338ca;--accent-soft:#eef2ff;--ok:#067647;--ok-soft:#ecfdf3;--bad:#b42318;--bad-soft:#fef3f2;color:var(--ink)}
.pp h2{margin:0;font-size:24px;font-weight:700}
.pp .sub{color:var(--muted);font-size:14px;margin-top:4px}
.pp .head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.pp .card-box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.pp .card-box h3{margin:0 0 10px;font-size:16px;font-weight:700}
.pp .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.pp .kpi{background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #e0e7ff;border-radius:12px;padding:12px 14px}
.pp .kpi .v{font-size:20px;font-weight:700;color:var(--accent);font-variant-numeric:tabular-nums}
.pp .kpi .l{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.pp .verdict{border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:15px}
.pp .verdict.ok{background:var(--ok-soft);color:var(--ok);border:1px solid #abefc6}
.pp .verdict.bad{background:var(--bad-soft);color:var(--bad);border:1px solid #fecdca}
.pp .verdict.none{background:#f9fafb;color:var(--muted);border:1px dashed #d0d5dd}
.pp table{width:100%;border-collapse:collapse;font-size:14px}
.pp th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line)}
.pp td{padding:8px 10px;border-bottom:1px solid #f2f4f7;vertical-align:middle}
.pp .num{text-align:right;font-variant-numeric:tabular-nums}
.pp .warn{color:var(--bad);font-size:12px}
.pp .tag{display:inline-block;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:600}
.pp .tag.ok{background:var(--ok-soft);color:var(--ok)}
.pp .tag.bad{background:var(--bad-soft);color:var(--bad)}
.pp .row-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pp .row-form input[type=number]{width:90px}
.pp a.plan-link{font-weight:600;color:var(--accent);text-decoration:none}
</style>

<div class="pp container-fluid p-3">
<?php if ($plan === null) { ?>
  <div class="head">
    <div>
      <h2>LOM plans</h2>
      <div class="sub">Devices a project plans to buy, priced per supplier and checked against a budget. Shortfalls go to next year's order.</div>
    </div>
    <a class="btn btn-outline-secondary" href="<?php echo $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lom.php'); ?>">← LOM catalog</a>
  </div>

  <div class="card-box">
    <h3>Plans</h3>
    <?php $plans = PluginProjectreportsPlan::all(); ?>
    <?php if (!$plans) { ?>
      <div class="text-secondary">No plan yet. Create one below.</div>
    <?php } else { ?>
      <table>
        <thead><tr><th>Plan</th><th>Budget</th><th class="num">Available</th><th class="num">Plan total</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($plans as $p) {
            $info = PluginProjectreportsPlan::budgetInfo((int) $p['budgets_id']);
            $t = PluginProjectreportsPlan::totals((int) $p['id']);
            $fits = $info['name'] !== '' && $t['total'] <= $info['available']; ?>
          <tr>
            <td><a class="plan-link" href="<?php echo $h($base . '?id=' . (int) $p['id']); ?>"><?php echo $h($p['name']); ?></a></td>
            <td><?php echo $info['name'] !== '' ? $h($info['name']) : '<span class="text-secondary">no budget</span>'; ?></td>
            <td class="num"><?php echo $info['name'] !== '' ? $money($info['available']) : '—'; ?></td>
            <td class="num"><?php echo $money($t['total']); ?></td>
            <td><?php if ($info['name'] === '') { echo '<span class="text-secondary">choose a budget</span>'; } elseif ($fits) { echo '<span class="tag ok">fits</span>'; } else { echo '<span class="tag bad">short ' . $h($money($t['total'] - $info['available'])) . '</span>'; } ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    <?php } ?>
  </div>

  <?php if ($can) { ?>
    <div class="card-box">
      <h3>New plan</h3>
      <form method="post" action="<?php echo $h($base); ?>" class="row-form">
        <?php echo $csrf(); ?>
        <input type="hidden" name="action" value="create_plan">
        <input class="form-control" style="max-width:300px" name="name" placeholder="Plan name, e.g. DL380 for 2027" required>
        <div style="min-width:260px"><?php Dropdown::show('Budget', ['name' => 'budgets_id', 'value' => 0, 'display_emptychoice' => true]); ?></div>
        <button class="btn btn-primary" type="submit">Create plan</button>
      </form>
    </div>
  <?php } ?>

<?php } else {
    $info = PluginProjectreportsPlan::budgetInfo((int) $plan['budgets_id']);
    $lines = PluginProjectreportsPlan::lines($plan_id);
    $priced = [];
    $total = 0.0;
    foreach ($lines as $line) {
        $p = PluginProjectreportsPlan::price($line);
        $priced[(int) $line['id']] = $p;
        if ($p['ok']) {
            $total += $p['total'];
        }
    }
    $models = PluginProjectreportsAutoboq::models();
    $suppliers = PluginProjectreportsLop::suppliers();
    $after = $info['available'] - $total;
    $self = $base . '?id=' . $plan_id; ?>

  <div class="head">
    <div>
      <h2><?php echo $h($plan['name']); ?></h2>
      <div class="sub">Plan · <?php echo $info['name'] !== '' ? 'budget ' . $h($info['name']) : 'no budget chosen yet'; ?> · prices before tax</div>
    </div>
    <a class="btn btn-outline-secondary" href="<?php echo $h($base); ?>">← All plans</a>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="v"><?php echo $money($info['value']); ?></div><div class="l">Budget value</div></div>
    <div class="kpi"><div class="v"><?php echo $money($info['committed']); ?></div><div class="l">Committed orders</div></div>
    <div class="kpi"><div class="v"><?php echo $money($info['pipeline']); ?></div><div class="l">Pipeline orders</div></div>
    <div class="kpi"><div class="v"><?php echo $money($info['other'] + $info['assets']); ?></div><div class="l">Other costs &amp; assets</div></div>
    <div class="kpi"><div class="v"><?php echo $money($info['available']); ?></div><div class="l">Available</div></div>
    <div class="kpi"><div class="v"><?php echo $money($total); ?></div><div class="l">This plan</div></div>
  </div>

  <?php if ($info['name'] === '') { ?>
    <div class="verdict none">Choose a budget for this plan to check it.</div>
  <?php } elseif ($total <= $info['available']) { ?>
    <div class="verdict ok">Fits the budget: <b><?php echo $money($after); ?></b> left after this plan.</div>
  <?php } else { ?>
    <div class="verdict bad">Does not fit: short by <b><?php echo $money(-$after); ?></b>. Submit a new order for this amount next year.</div>
  <?php } ?>

  <div class="card-box">
    <h3>Plan details</h3>
    <?php if ($can) { ?>
      <form method="post" action="<?php echo $h($self); ?>" class="row-form">
        <?php echo $csrf(); ?>
        <input type="hidden" name="action" value="update_plan">
        <input class="form-control" style="max-width:300px" name="name" value="<?php echo $h($plan['name']); ?>" required>
        <div style="min-width:260px"><?php Dropdown::show('Budget', ['name' => 'budgets_id', 'value' => (int) $plan['budgets_id'], 'display_emptychoice' => true]); ?></div>
        <button class="btn btn-primary" type="submit">Save plan</button>
      </form>
      <form method="post" action="<?php echo $h($self); ?>" class="mt-2" onsubmit="return confirm('Delete this plan and all its lines?')">
        <?php echo $csrf(); ?>
        <input type="hidden" name="action" value="delete_plan">
        <button class="btn btn-sm btn-outline-danger" type="submit">Delete plan</button>
      </form>
    <?php } else { ?>
      <div class="text-secondary">Budget: <?php echo $info['name'] !== '' ? $h($info['name']) : '—'; ?></div>
    <?php } ?>
  </div>

  <div class="card-box">
    <h3>Devices and suppliers</h3>
    <?php if (!$lines) { ?>
      <div class="text-secondary">No device line yet.</div>
    <?php } ?>
    <?php foreach ($lines as $line) {
        $lid = (int) $line['id'];
        $p = $priced[$lid];
        $sid = (int) $line['suppliers_id'];
        $cur = $models[$line['model_key']] ?? null; ?>
      <form id="lf<?php echo $lid; ?>" method="post" action="<?php echo $h($self); ?>"><?php echo $csrf(); ?><input type="hidden" name="action" value="update_line"><input type="hidden" name="line_id" value="<?php echo $lid; ?>"></form>
      <form id="ld<?php echo $lid; ?>" method="post" action="<?php echo $h($self); ?>" onsubmit="return confirm('Remove this line?')"><?php echo $csrf(); ?><input type="hidden" name="action" value="delete_line"><input type="hidden" name="line_id" value="<?php echo $lid; ?>"></form>
    <?php } ?>
    <?php if ($lines) { ?>
      <table>
        <thead><tr><th>Model</th><th>Devices</th><th>Supplier</th><th class="num">Per device</th><th class="num">Plan line total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $line) {
            $lid = (int) $line['id'];
            $p = $priced[$lid];
            $sid = (int) $line['suppliers_id'];
            $name = $p['model']['name'] ?? ($models[$line['model_key']]['name'] ?? $line['model_key']); ?>
          <tr>
            <td><b><?php echo $h($name); ?></b><?php if (!empty($p['model']['part'])) { ?><div class="text-secondary small"><?php echo $h($p['model']['part']); ?></div><?php } ?></td>
            <td><?php if ($can) { ?><input form="lf<?php echo $lid; ?>" class="form-control form-control-sm" type="number" min="1" max="100000" name="devices" value="<?php echo (int) $line['devices']; ?>" style="width:90px"><?php } else { echo (int) $line['devices']; } ?></td>
            <td>
              <?php if ($can) { ?>
                <select form="lf<?php echo $lid; ?>" class="form-select form-select-sm" name="suppliers_id" style="min-width:260px">
                  <option value="0"<?php echo $sid === 0 ? ' selected' : ''; ?>>Cheapest complete LOP</option>
                  <?php $shown = [];
                  foreach ($p['options'] ?? [] as $o) {
                      $shown[] = (int) $o['suppliers_id']; ?>
                    <option value="<?php echo (int) $o['suppliers_id']; ?>"<?php echo $sid === (int) $o['suppliers_id'] ? ' selected' : ''; ?>><?php echo $h($o['supplier'] . ($o['complete'] ? '' : ' (partial)') . ' · ' . $money($o['total']) . ' / device'); ?></option>
                  <?php } ?>
                  <?php if ($sid > 0 && !in_array($sid, $shown, true)) { ?>
                    <option value="<?php echo $sid; ?>" selected><?php echo $h(Dropdown::getDropdownName('glpi_suppliers', $sid) . ' (no LOP price)'); ?></option>
                  <?php } ?>
                </select>
              <?php } else {
                  echo $p['ok'] ? $h($p['supplier']) : '<span class="text-secondary">—</span>';
              } ?>
              <?php if ($p['ok'] && !$p['complete']) { ?><div class="warn">Partial: the LOP covers <?php echo (int) $p['covered']; ?> of <?php echo (int) $p['parts']; ?> parts.</div><?php } ?>
              <?php if (!$p['ok']) { ?><div class="warn"><?php echo $h($p['message']); ?></div><?php } ?>
            </td>
            <td class="num"><?php echo $p['ok'] ? $money($p['per_device']) : '—'; ?></td>
            <td class="num"><?php echo $p['ok'] ? $money($p['total']) : '—'; ?></td>
            <td>
              <?php if ($can) { ?>
                <div class="d-flex gap-1">
                  <button form="lf<?php echo $lid; ?>" class="btn btn-sm btn-primary" type="submit">Save</button>
                  <button form="ld<?php echo $lid; ?>" class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                </div>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
        <tfoot><tr><th colspan="4" class="text-end">Plan total</th><th class="num"><?php echo $money($total); ?></th><th></th></tr></tfoot>
      </table>
    <?php } ?>
  </div>

  <?php if ($can) { ?>
    <div class="card-box">
      <h3>Add a device line</h3>
      <form method="post" action="<?php echo $h($self); ?>" class="row-form">
        <?php echo $csrf(); ?>
        <input type="hidden" name="action" value="add_line">
        <select class="form-select" name="model_key" style="min-width:320px" required>
          <option value="">Choose a model with a parts list…</option>
          <?php $groups = [];
          foreach ($models as $key => $m) { $groups[$m['category_label']][$key] = $m; }
          foreach ($groups as $label => $items) { ?>
            <optgroup label="<?php echo $h($label); ?>">
              <?php foreach ($items as $key => $m) { ?>
                <option value="<?php echo $h($key); ?>"><?php echo $h($m['name'] . ($m['part'] ? ' · ' . $m['part'] : '')); ?></option>
              <?php } ?>
            </optgroup>
          <?php } ?>
        </select>
        <input class="form-control" type="number" min="1" max="100000" name="devices" value="1" style="width:110px" aria-label="Devices">
        <select class="form-select" name="suppliers_id" style="min-width:260px">
          <option value="0">Cheapest complete LOP</option>
          <?php foreach ($suppliers as $sid) { ?>
            <option value="<?php echo (int) $sid; ?>"><?php echo $h(Dropdown::getDropdownName('glpi_suppliers', (int) $sid)); ?></option>
          <?php } ?>
        </select>
        <button class="btn btn-primary" type="submit">Add line</button>
      </form>
    </div>
  <?php } ?>
<?php } ?>
</div>
<?php
Html::footer();
