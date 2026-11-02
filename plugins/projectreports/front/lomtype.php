<?php

/**
 * Tools › LOM catalog › read-only page per device type (Servers, Storage, Switches, PDUs, Racks): every model of that
 * type with its parts list per supplier. Nothing on this page can be changed.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsLom::canView()) {
    Html::displayRightError();
}

$types = ['servers' => 'Servers', 'storage' => 'Storage', 'network' => 'Switches', 'pdus' => 'PDUs', 'racks' => 'Racks'];
$type = (string) ($_GET['group'] ?? 'servers');
if (!isset($types[$type])) {
    $type = 'servers';
}
$h = fn($v) => htmlescape((string) $v);
$base = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomtype.php';
$catalog = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lom.php';

$models = array_values(array_filter(PluginProjectreportsLom::items(), fn($i) => $i['category'] === $type));
$list_count = 0;
$line_count = 0;
foreach ($models as $mod) {
    $list_count += count($mod['lists'] ?? []);
    foreach ($mod['lists'] ?? [] as $l) {
        $line_count += count($l['rows'] ?? []);
    }
}

Html::header('LOM · ' . $types[$type], '', 'tools', 'PluginProjectreportsLom');
?>
<style>
.lt{--ink:#1d2939;--muted:#667085;--line:#e4e7ec;--soft:#f8fafc;--accent:#4338ca;--accent-soft:#eef2ff;--ok:#067647;color:var(--ink)}
.lt .lt-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.lt h2{margin:0;font-size:24px;font-weight:700}
.lt .sub{color:var(--muted);font-size:14px;margin-top:4px}
.lt .types{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}
.lt .types a{padding:7px 14px;border-radius:999px;border:1px solid #d0d5dd;text-decoration:none;color:var(--ink);font-size:14px;background:#fff}
.lt .types a:hover{border-color:var(--accent);color:var(--accent)}
.lt .types a.on{background:var(--accent);border-color:var(--accent);color:#fff}
.lt .kpis{display:grid;grid-template-columns:repeat(3,minmax(140px,1fr));gap:12px;margin-bottom:18px}
.lt .kpi{background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #e0e7ff;border-radius:12px;padding:14px 16px}
.lt .kpi .v{font-size:24px;font-weight:700;color:var(--accent)}
.lt .kpi .l{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.lt .model{background:#fff;border:1px solid var(--line);border-radius:14px;margin-bottom:16px;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.lt .model-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;padding:14px 18px;background:linear-gradient(90deg,#f8fafc,#fff);border-bottom:1px solid var(--line)}
.lt .model-head b{font-size:16px}
.lt .tag{display:inline-block;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;background:var(--accent-soft);color:var(--accent);margin-left:6px}
.lt .list{padding:12px 18px 6px}
.lt .list-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.lt .list-head b{color:var(--ok)}
.lt .list-head span{color:var(--muted);font-size:13px}
.lt table{width:100%;border-collapse:collapse;font-size:14px}
.lt th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);font-weight:600;padding:8px 10px;border-bottom:1px solid var(--line)}
.lt td{padding:8px 10px;border-bottom:1px solid #f2f4f7;vertical-align:top}
.lt tr:last-child td{border-bottom:0}
.lt .num{text-align:right;font-variant-numeric:tabular-nums}
.lt .pn{font-weight:600}
.lt .none{padding:14px 18px;color:var(--muted);font-size:14px}
.lt .empty{background:#fff;border:1px dashed #d0d5dd;border-radius:12px;padding:28px;text-align:center;color:var(--muted)}
@media print{.lt .types,.lt .no-print{display:none}.lt .model{break-inside:avoid}}
</style>

<div class="lt container-fluid p-3">
  <div class="lt-head">
    <div>
      <h2>LOM · <?php echo $h($types[$type]); ?></h2>
      <div class="sub">Read-only: every <?php echo $h(strtolower($types[$type])); ?> model with its parts list per supplier.</div>
    </div>
    <a class="btn btn-outline-secondary no-print" href="<?php echo $h($catalog); ?>">← LOM catalog</a>
  </div>

  <nav class="types no-print" aria-label="Device types">
    <?php foreach ($types as $k => $lbl) { ?>
      <a class="<?php echo $k === $type ? 'on' : ''; ?>" href="<?php echo $h($base . '?group=' . $k); ?>"><?php echo $h($lbl); ?></a>
    <?php } ?>
  </nav>

  <div class="kpis">
    <div class="kpi"><div class="v"><?php echo count($models); ?></div><div class="l">Models</div></div>
    <div class="kpi"><div class="v"><?php echo $list_count; ?></div><div class="l">Supplier lists</div></div>
    <div class="kpi"><div class="v"><?php echo $line_count; ?></div><div class="l">Part lines</div></div>
  </div>

  <?php if (!$models) { ?>
    <div class="empty">No <?php echo $h(strtolower($types[$type])); ?> model in GLPI yet.</div>
  <?php } ?>

  <?php foreach ($models as $mod) { ?>
    <div class="model">
      <div class="model-head">
        <div><b><?php echo $h($mod['name']); ?></b><?php if ($mod['part']) { ?><span class="tag"><?php echo $h($mod['part']); ?></span><?php } ?></div>
        <div class="sub">In use: <b><?php echo (int) $mod['in_use']; ?></b><?php echo $mod['parts'] ? ' · ' . (int) $mod['parts'] . ' parts (standard)' : ''; ?></div>
      </div>
      <?php if (!$mod['lists']) { ?>
        <div class="none">No parts list yet for this model.</div>
      <?php } ?>
      <?php foreach ($mod['lists'] as $l) { ?>
        <div class="list">
          <div class="list-head"><b><?php echo $h($l['name']); ?></b><span><?php echo count($l['rows']); ?> part(s)</span></div>
          <table>
            <thead><tr><th style="width:40px">#</th><th>Part number</th><th>Spare part number</th><th>Description</th><th class="num">Qty per device</th></tr></thead>
            <tbody>
              <?php foreach ($l['rows'] as $k => $r) { ?>
                <tr>
                  <td><?php echo $k + 1; ?></td>
                  <td class="pn"><?php echo $h($r['part']); ?></td>
                  <td><?php echo $h($r['spare']); ?></td>
                  <td><?php echo $h($r['description']); ?></td>
                  <td class="num"><?php echo (int) $r['qty']; ?></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } ?>
    </div>
  <?php } ?>
</div>
<?php
Html::footer();
