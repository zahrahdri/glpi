<?php

/**
 * Tools › Project reports › Approved requests: each approved capacity request, the budget it is paid from, and whether
 * that budget covers the cost of its BOQ.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsDemand::canView()) {
    Html::displayRightError();
}
$can = PluginProjectreportsDemand::canEdit();
$h = fn($v) => htmlescape((string) $v);
$money = fn($v) => PluginProjectreportsAutoboq::fmtNum((float) $v);
$base = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/demand.php';

if (($_POST['action'] ?? '') === 'save') {
    if (!$can) {
        Html::displayRightError();
    }
    // rights are checked above; these forms do not use a token (like the LOM sheet and the Price check)
    foreach ((array) ($_POST['budget'] ?? []) as $tid => $bid) {
        PluginProjectreportsDemand::save((int) $tid, (int) $bid);
    }
    Session::addMessageAfterRedirect('Budgets saved.', false, INFO);
    Html::redirect($base);
    exit;
}

if (($_POST['action'] ?? '') === 'delete_boq') {
    if (!$can) {
        Html::displayRightError();
    }
    // rights are checked above; these forms do not use a token (like the LOM sheet and the Price check)
    $ok = PluginProjectreportsDemand::deleteBoq((int) ($_POST['tickets_id'] ?? 0), (int) ($_POST['orders_id'] ?? 0));
    Session::addMessageAfterRedirect($ok ? 'BOQ order deleted.' : 'Only draft or waiting orders can be deleted here; cancel approved ones in the Orders plugin.', false, $ok ? INFO : ERROR);
    Html::redirect($base);
    exit;
}

$rows = PluginProjectreportsDemand::approved();
$csrf = fn() => '<input type="hidden" name="_glpi_csrf_token" value="' . $h(Session::getNewCSRFToken(true)) . '">';

Html::header('Approved requests', '', 'tools', 'PluginProjectreportsMenu');
?>
<style>
.pd{--ink:#1d2939;--muted:#667085;--line:#e4e7ec;--accent:#4338ca;--ok:#067647;--ok-soft:#ecfdf3;--bad:#b42318;--bad-soft:#fef3f2;color:var(--ink)}
.pd h2{margin:0;font-size:24px;font-weight:700}
.pd .sub{color:var(--muted);font-size:14px;margin-top:4px}
.pd .head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.pd .card-box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:16px}
.pd table{width:100%;border-collapse:collapse;font-size:14px}
.pd th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line)}
.pd td{padding:8px 10px;border-bottom:1px solid #f2f4f7;vertical-align:middle}
.pd .num{text-align:right;font-variant-numeric:tabular-nums}
.pd .tag{display:inline-block;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:600}
.pd .tag.ok{background:var(--ok-soft);color:var(--ok)}
.pd .tag.bad{background:var(--bad-soft);color:var(--bad)}
.pd .tag.none{background:#f2f4f7;color:var(--muted)}
.pd a{color:var(--accent);text-decoration:none;font-weight:600}
</style>

<div class="pd container-fluid p-3">
  <div class="head">
    <div>
      <h2>Approved requests</h2>
      <div class="sub">Each approved capacity request, the budget it is paid from, and whether that budget covers its BOQ. Prices before tax.</div>
    </div>
    <a class="btn btn-outline-secondary" href="<?php echo $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/reports.php'); ?>">← Project reports</a>
  </div>

  <div class="card-box">
    <?php if (!$rows) { ?>
      <div class="text-secondary">No approved capacity request yet.</div>
    <?php } else { ?>
      <?php if ($can) {
          foreach ($rows as $r) {
              foreach ($r['list'] as $o) {
                  if (!$o['deletable']) {
                      continue; ?>
                <form id="dl<?php echo (int) $o['id']; ?>" method="post" action="<?php echo $h($base); ?>" onsubmit="return confirm('Delete this BOQ order?')">
                  <?php echo $csrf(); ?>
                  <input type="hidden" name="action" value="delete_boq">
                  <input type="hidden" name="tickets_id" value="<?php echo (int) $r['id']; ?>">
                  <input type="hidden" name="orders_id" value="<?php echo (int) $o['id']; ?>">
                </form>
          <?php }
              }
          }
      } ?>
      <form method="post" action="<?php echo $h($base); ?>">
        <?php echo $csrf(); ?>
        <input type="hidden" name="action" value="save">
        <table>
          <thead><tr><th>Request</th><th>Project</th><th class="num">BOQ cost</th><th>Budget</th><th>Result</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r) {
              $c = PluginProjectreportsDemand::check($r); ?>
            <tr>
              <td><a href="<?php echo $h($CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int) $r['id']); ?>">#<?php echo (int) $r['id']; ?></a> <?php echo $h($r['name']); ?></td>
              <td><?php echo $r['project'] !== '' ? $h($r['project']) : '<span class="text-secondary">new project</span>'; ?></td>
              <td class="num">
                <?php echo $r['boq'] > 0 ? $money($r['boq']) : '<span class="text-secondary">no BOQ</span>'; ?>
                <?php foreach ($r['list'] as $o) { ?>
                  <div class="small text-secondary text-nowrap">
                    <?php echo $h($o['number'] . ' · ' . $o['supplier'] . ' · ' . $money($o['total'])); ?>
                    <?php if ($can && $o['deletable']) { ?>
                      <button form="dl<?php echo (int) $o['id']; ?>" class="btn btn-sm btn-outline-danger py-0 px-1" type="submit">Delete</button>
                    <?php } ?>
                  </div>
                <?php } ?>
              </td>
              <td style="min-width:260px">
                <?php if ($can) {
                    Dropdown::show('Budget', ['name' => 'budget[' . (int) $r['id'] . ']', 'value' => (int) $r['budgets_id'], 'display_emptychoice' => true]);
                } else {
                    echo $r['budgets_id'] > 0 ? $h(Dropdown::getDropdownName('glpi_budgets', (int) $r['budgets_id'])) : '<span class="text-secondary">none</span>';
                } ?>
              </td>
              <td>
                <?php if ($c['status'] === 'enough') { ?>
                  <span class="tag ok">enough</span> <span class="small">left <?php echo $h($money($c['left'])); ?></span>
                <?php } elseif ($c['status'] === 'short') { ?>
                  <span class="tag bad">short by <?php echo $h($money(-$c['left'])); ?></span>
                <?php } else { ?>
                  <span class="tag none"><?php echo $h($c['status']); ?></span>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        <?php if ($can) { ?>
          <div class="mt-3"><button class="btn btn-primary" type="submit">Save budgets</button></div>
        <?php } ?>
      </form>
    <?php } ?>
  </div>
</div>
<?php
Html::footer();
