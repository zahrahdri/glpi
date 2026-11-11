<?php

/**
 * LOM sheet of one device model: pictures and specs, the standard parts list (part number, spare part number,
 * description, quantity per device, lowest LOP price) and the real devices of the model with their photo, age and
 * warranty. Parts are edited by users who may update the model; photos are the image documents of each device.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
$type = (string) ($_REQUEST['type'] ?? '');
$id = (int) ($_REQUEST['id'] ?? 0);
$sup = (int) ($_REQUEST['supplier'] ?? 0); // 0 = the standard parts list, else that supplier's list
$from_order = (int) ($_REQUEST['from_order'] ?? 0);
$devices_in_order = max(1, (int) ($_REQUEST['devices'] ?? 1));
$m = PluginProjectreportsLom::model($type, $id);
if ($m === null) {
    Html::displayRightError();
}
$c = $m['cat'];
$model_class = $m['model_class'];
$can_edit = class_exists($model_class) && $model_class::canUpdate();
$prices = PluginProjectreportsLom::canSeePrices();
$self = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomsheet.php?' . http_build_query(['type' => $type, 'id' => $id] + ($sup ? ['supplier' => $sup] : []));
$catalog = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lom.php';
$h = fn($v) => htmlescape((string) $v);
$money = fn($v) => number_format((float) $v, floor((float) $v) == (float) $v ? 0 : 2);

// ---- changes to the parts list
$action = $_POST['action'] ?? '';
if ($action !== '') {
    if (!$can_edit) {
        Html::displayRightError();
    }
    $ok = true;
    if ($action === 'add') {
        $ok = PluginProjectreportsLompart::add($model_class, $id, $_POST, $sup);
        $msg = $ok ? 'Part added.' : 'Part Number is required.';
    } elseif ($action === 'update') {
        $ok = PluginProjectreportsLompart::update($model_class, $id, (int) $_POST['part_id'], $_POST);
        $msg = $ok ? 'Part saved.' : 'Part Number is required.';
    } elseif ($action === 'lines_save') {
        $saved = PluginProjectreportsLompart::saveAll($model_class, $id, $sup, (array) ($_POST['lines'] ?? []), (array) ($_POST['new'] ?? []));
        $msg = sprintf('%d line(s) saved.', $saved);
    } elseif ($action === 'order_save') {
        $rows = array_values(array_filter((array) ($_POST['lines'] ?? []), fn($r) => trim((string) ($r['part'] ?? '')) !== ''));
        $n = PluginProjectreportsLompart::replace($model_class, $id, $sup, $rows);
        $msg = sprintf('The parts list was saved: %d part(s).', $n);
    } elseif ($action === 'delete') {
        PluginProjectreportsLompart::delete($model_class, $id, (int) $_POST['part_id']);
        $msg = 'Part removed.';
    } elseif ($action === 'import') {
        if (empty($_FILES['parts']['tmp_name']) || !is_uploaded_file($_FILES['parts']['tmp_name'])) {
            [$ok, $msg] = [false, 'Choose the parts list file (.csv).'];
        } else {
            [$n, $errors] = PluginProjectreportsLompart::import($model_class, $id, $_FILES['parts']['tmp_name'], !empty($_POST['replace']), $sup);
            $ok = !$errors;
            $msg = $errors ? 'Not imported: ' . implode(' ', array_slice($errors, 0, 5)) : sprintf('%d part(s) imported.', $n);
        }
    }
    Session::addMessageAfterRedirect(htmlescape($msg ?? ''), false, $ok ? INFO : ERROR);
    Html::redirect($self . '#parts');
}

$parts = PluginProjectreportsLompart::forModel($model_class, $id, $prices, $sup);
$devices = PluginProjectreportsLom::devices($c, $id);
$n = count($devices);

// ---- CSV export of the sheet
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="LOM_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $m['name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Model', $m['name'], 'Product number', $m['part'], 'Devices in use', $n]);
    fputcsv($out, []);
    $head = ['Part Number', 'Spare Part number', 'Description', 'Qty per device', 'Qty for all devices'];
    if ($prices) {
        array_push($head, 'Lowest LOP unit price', 'Supplier');
    }
    fputcsv($out, $head);
    foreach ($parts as $p) {
        $row = [$p['part'], $p['spare'], $p['description'], $p['qty'], $p['qty'] * $n];
        if ($prices) {
            array_push($row, $p['lop']['price'] ?? '', $p['lop']['supplier'] ?? '');
        }
        fputcsv($out, $row);
    }
    fputcsv($out, []);
    fputcsv($out, ['Device', 'Serial number', 'Inventory number', 'Location', 'Rack', 'Status', 'In service since', 'Age (years)', 'Warranty end', 'Photos']);
    foreach ($devices as $d) {
        fputcsv($out, [$d['name'], $d['serial'], $d['otherserial'], $d['location'], $d['rack'], $d['status'], $d['in_service'] ?? '', $d['age'] ?? '', $d['warranty_end'] ?? '', count($d['photos'])]);
    }
    fclose($out);
    exit;
}

$ages = array_values(array_filter(array_column($devices, 'age'), fn($a) => $a !== null));
$avg_age = $ages ? round(array_sum($ages) / count($ages), 1) : null;
$old = count(array_filter($ages, fn($a) => $a >= 5));
$wexp = count(array_filter($devices, fn($d) => $d['warranty_over']));
$no_photo = count(array_filter($devices, fn($d) => !$d['photos']));
$unit_cost = array_sum(array_map(fn($p) => $p['qty'] * ($p['lop']['price'] ?? 0), $parts));
$priced = count(array_filter($parts, fn($p) => $p['lop'] !== null));

Html::header('LOM · ' . $m['name'], '', 'tools', 'PluginProjectreportsLom');
echo '<link rel="stylesheet" href="' . $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/css/reports.css?v=' . PLUGIN_PROJECTREPORTS_VERSION) . '">';
?>
<style>
.lms .lms-top{display:grid;grid-template-columns:minmax(260px,420px) 1fr;gap:20px}
@media (max-width:900px){.lms .lms-top{grid-template-columns:1fr}}
.lms .lms-main{aspect-ratio:4/3;background:linear-gradient(180deg,#f6f7f9,#eceef2);border-radius:10px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.lms .lms-main img{max-width:100%;max-height:100%;object-fit:contain}
.lms .lms-thumbs{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}
.lms .lms-thumbs img{width:64px;height:48px;object-fit:contain;background:#f3f4f7;border-radius:6px;cursor:pointer;border:2px solid transparent}
.lms .lms-thumbs img.on{border-color:#15233A}
.lms .lms-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin:12px 0}
.lms .lms-kpi{background:var(--tblr-bg-surface,#fff);border:1px solid #e6e8eb;border-radius:8px;padding:10px 12px}
.lms .lms-kpi .v{font-size:22px;font-weight:600;line-height:1.2}
.lms .lms-kpi .l{font-size:12px;color:#6c7a91}
.lms .lms-photo{width:84px;height:63px;object-fit:cover;border-radius:6px;cursor:zoom-in;background:#f3f4f7}
.lms .lms-nophoto{width:84px;height:63px;border:1px dashed #c3c9d3;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;color:#6c7a91;text-decoration:none;text-align:center}
.lms .age{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12.5px;font-weight:600}
.lms .age.a1{background:#d3f9d8;color:#1b6b2a}.lms .age.a2{background:#fff3bf;color:#7a5b00}.lms .age.a3{background:#ffc9c9;color:#9b1c1c}
.lms table input.form-control{min-width:90px}
@media print{.lms .no-print,.navigation,aside,header,.lms form .btn{display:none!important}.lms .lms-top{grid-template-columns:300px 1fr}}

.pl{background:#fff;border:1px solid #e6e8ec;border-radius:12px;padding:16px 18px}
.pl-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px}
.pl-head h3{margin:0;font-size:18px}
.pl-sub{color:#667085;font-size:13px;margin-top:2px}
.pl-tabs{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.pl-tab{padding:6px 12px;border-radius:999px;border:1px solid #d0d5dd;text-decoration:none;color:#344054;font-size:13px;background:#fff}
.pl-tab span{margin-left:6px;color:#667085}
.pl-tab.on{background:#1d2939;border-color:#1d2939;color:#fff}
.pl-tab.on span{color:#d0d5dd}
.pl-pick{border:1px dashed #98a2b3;border-radius:999px;padding:6px 12px;font-size:13px;background:#fff;color:#344054}
.pl-wrap{overflow:auto}
.pl-table{width:100%;border-collapse:separate;border-spacing:0;min-width:720px}
.pl-table th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#667085;font-weight:600;text-align:left;padding:8px;border-bottom:1px solid #e6e8ec}
.pl-table td{padding:6px 8px;border-bottom:1px solid #f0f2f5;vertical-align:middle}
.pl-table input{min-width:120px}
.pl-num{color:#98a2b3;width:36px}
.pl-q{width:96px}
.pl-all{text-align:right;color:#344054;white-space:nowrap;font-variant-numeric:tabular-nums}
.pl-del{width:44px;text-align:center}
.pl-new td{background:#f9fafb}
.pl-empty{padding:18px;text-align:center;color:#667085}
.pl-bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding-top:12px;margin-top:10px;border-top:1px solid #e6e8ec}
</style>
<div class="pr lms">
  <div class="pr-head">
    <div><h2 class="pr-title"><?php echo $h($m['name']); ?></h2>
      <div class="pr-sub"><?php echo $h($m['category_label'] . ($m['part'] ? ' · ' . $m['part'] : '') . ' · recorded in GLPI as ' . $m['glpi_type']); ?></div></div>
    <div class="d-flex gap-2 no-print">
      <a class="btn btn-outline-secondary" href="<?php echo $h($catalog); ?>"><i class="ti ti-arrow-left"></i> LOM catalog</a>
      <a class="btn btn-outline-secondary" href="<?php echo $h($self . '&export=csv'); ?>"><i class="ti ti-download"></i> CSV</a>
    </div>
  </div>

  <div class="lms-top">
    <div>
      <div class="lms-main" id="lms-main"><?php
        echo $m['pictures'] ? '<img src="' . $h($m['pictures'][0]) . '" alt="' . $h($m['name']) . '">'
            : '<div class="text-secondary p-4 text-center"><i class="ti ti-photo-off" style="font-size:40px"></i><br>No model picture yet' . ($m['can_edit'] ? '<br><a href="' . $h($m['url']) . '">Add one in the model form</a>' : '') . '</div>';
      ?></div>
      <?php if (count($m['pictures']) > 1) { ?><div class="lms-thumbs" id="lms-thumbs"><?php foreach ($m['pictures'] as $k => $p) { ?><img src="<?php echo $h($p); ?>" alt="Picture <?php echo $k + 1; ?>" class="<?php echo $k ? '' : 'on'; ?>"><?php } ?></div><?php } ?>
    </div>
    <div>
      <div class="lms-kpis">
        <div class="lms-kpi"><div class="v"><?php echo $n; ?></div><div class="l">Devices in use</div></div>
        <div class="lms-kpi"><div class="v"><?php echo $avg_age === null ? '—' : $h($avg_age) . ' y'; ?></div><div class="l">Average age</div></div>
        <div class="lms-kpi"><div class="v" style="color:<?php echo $wexp ? '#c92a2a' : 'inherit'; ?>"><?php echo $wexp; ?></div><div class="l">Warranty expired</div></div>
        <div class="lms-kpi"><div class="v"><?php echo count($parts); ?></div><div class="l">Parts in the list</div></div>
        <?php if ($prices) { ?><div class="lms-kpi"><div class="v"><?php echo $priced ? $h($money($unit_cost)) : '—'; ?></div><div class="l">Parts cost per device (LOP<?php echo $priced < count($parts) ? ', ' . $priced . ' of ' . count($parts) . ' priced' : ''; ?>)</div></div><?php } ?>
      </div>
      <table class="table table-sm" style="max-width:640px"><tbody>
        <?php foreach ([['Product number', $m['part'] ?: '—'], ['Rack units', $m['units'] ? $m['units'] . ' U' : '—'], ['Power', $m['power'] ? $money($m['power']) . ' W' : '—'],
            ['Weight', $m['weight'] ? $money($m['weight']) . ' kg' : '—'], ['Depth in rack', !$m['depth'] ? '—' : ($m['depth'] >= 1 ? 'full depth' : ($m['depth'] == 0.5 ? 'half depth' : round($m['depth'] * 100) . '%'))]] as [$k, $v]) { ?>
          <tr><th class="text-secondary fw-normal" style="width:40%"><?php echo $h($k); ?></th><td><?php echo $h($v); ?></td></tr>
        <?php } ?>
      </tbody></table>
      <?php if ($m['comment'] !== '') { ?><div style="white-space:pre-wrap" class="mb-2"><?php echo $h($m['comment']); ?></div><?php } ?>
    </div>
  </div>

  <div class="pl" id="parts">
    <?php
    /** @var DBmysql $DB */
    global $DB;
    $base = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomsheet.php?' . http_build_query(['type' => $type, 'id' => $id]);
    $variants = PluginProjectreportsLompart::variants($model_class, $id);
    $list_name = $sup ? Dropdown::getDropdownName('glpi_suppliers', $sup) : 'Standard list';
    ?>
    <div class="pl-head">
      <div>
        <h3>Parts list</h3>
        <div class="pl-sub"><b><?php echo $h($list_name); ?></b> · quantities for one <?php echo $h($m['name']); ?>. "All devices" = quantity × <?php echo $n; ?> in use.</div>
      </div>
      <div class="pl-tabs">
        <?php foreach ($variants as $vs => $v) { ?>
          <a class="pl-tab<?php echo $vs === $sup ? ' on' : ''; ?>" href="<?php echo $h($base . ($vs ? '&supplier=' . $vs : '')); ?>"><?php echo $h($v['name']); ?><span><?php echo (int) $v['parts']; ?></span></a>
        <?php } ?>
        <?php if ($can_edit) { ?>
        <form method="get" action="<?php echo $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomsheet.php'); ?>" class="m-0">
          <input type="hidden" name="type" value="<?php echo $h($type); ?>"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
          <select name="supplier" class="pl-pick" onchange="this.form.submit()" aria-label="Open or add a supplier's list">
            <option value="">+ Supplier's list…</option>
            <?php foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_suppliers', 'WHERE' => ['is_deleted' => 0], 'ORDER' => 'name']) as $sr) { ?>
              <option value="<?php echo (int) $sr['id']; ?>"><?php echo $h($sr['name']); ?></option>
            <?php } ?>
          </select>
        </form>
        <?php } ?>
      </div>
    </div>

    <?php if ($can_edit) { ?>
      <form id="pl-form" method="post" action="<?php echo $h($self); ?>" class="d-none"><input type="hidden" name="action" value="lines_save"><?php Html::closeForm(); ?>
    <?php } ?>
    <div class="pl-wrap"><table class="pl-table">
      <thead><tr><th class="pl-num">#</th><th>Part number</th><th>Spare part number</th><th>Description</th><th class="text-end">Qty per device</th><th class="pl-all">All devices</th><?php if ($can_edit) { ?><th class="pl-del"></th><?php } ?></tr></thead>
      <tbody>
      <?php if (!$parts && !$can_edit) { ?>
        <tr><td colspan="6" class="pl-empty">No parts list has been entered for this list yet.</td></tr>
      <?php } ?>
      <?php foreach ($parts as $k => $p) { $pid = (int) $p['id']; $ln = 'lines[' . $pid . ']'; ?>
        <tr>
          <td class="pl-num"><?php echo $k + 1; ?></td>
          <?php if ($can_edit) { ?>
            <td><input form="pl-form" name="<?php echo $ln; ?>[part]" class="form-control form-control-sm" value="<?php echo $h($p['part']); ?>" required aria-label="Part number"></td>
            <td><input form="pl-form" name="<?php echo $ln; ?>[spare]" class="form-control form-control-sm" value="<?php echo $h($p['spare']); ?>" aria-label="Spare part number"></td>
            <td><input form="pl-form" name="<?php echo $ln; ?>[description]" class="form-control form-control-sm" value="<?php echo $h($p['description']); ?>" aria-label="Description" style="min-width:220px"></td>
            <td><input form="pl-form" name="<?php echo $ln; ?>[qty]" type="number" min="0" step="1" class="form-control form-control-sm text-end pl-q" value="<?php echo (int) $p['qty']; ?>" aria-label="Quantity per device"></td>
            <td class="pl-all"><?php echo $p['qty'] * $n; ?></td>
            <td class="pl-del"><button form="pd<?php echo $pid; ?>" class="btn btn-sm btn-ghost-danger" title="Remove this part" aria-label="Remove <?php echo $h($p['part']); ?>"><i class="ti ti-trash"></i></button></td>
          <?php } else { ?>
            <td><b><?php echo $h($p['part']); ?></b></td><td><?php echo $h($p['spare']); ?></td><td><?php echo $h($p['description']); ?></td>
            <td class="text-end"><?php echo (int) $p['qty']; ?></td><td class="pl-all"><?php echo $p['qty'] * $n; ?></td>
          <?php } ?>
        </tr>
      <?php } ?>
      <?php if ($can_edit) { ?>
        <tr class="pl-new">
          <td class="pl-num"><i class="ti ti-plus"></i></td>
          <td><input form="pl-form" name="new[part]" class="form-control form-control-sm" placeholder="New part number" aria-label="New part number"></td>
          <td><input form="pl-form" name="new[spare]" class="form-control form-control-sm" placeholder="Spare part number" aria-label="New spare part number"></td>
          <td><input form="pl-form" name="new[description]" class="form-control form-control-sm" placeholder="Description" aria-label="New description"></td>
          <td><input form="pl-form" name="new[qty]" type="number" min="0" step="1" value="1" class="form-control form-control-sm text-end pl-q" aria-label="New quantity per device"></td>
          <td class="pl-all"></td><td></td>
        </tr>
      <?php } ?>
      </tbody>
    </table></div>
    <?php if ($can_edit) { ?>
      <div class="pl-bar">
        <span class="pl-sub"><?php echo count($parts); ?> part(s) in this list. Changes are kept only when you press Save all.</span>
        <button type="submit" form="pl-form" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Save all</button>
      </div>
      <?php foreach ($parts as $p) { ?>
        <form id="pd<?php echo (int) $p['id']; ?>" method="post" action="<?php echo $h($self); ?>" class="d-none" onsubmit="return confirm('Remove this part from the list?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="part_id" value="<?php echo (int) $p['id']; ?>"><?php Html::closeForm(); ?>
      <?php } ?>
    <?php } ?>
  <?php if ($can_edit || $from_order) { ?>
  <div class="pr-card mt-3 no-print" id="from-order">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h3 class="m-0">Parts from an order</h3>
      <span class="pr-sub">Part number, spare part number, description and quantity per device come from the order's lines. Save all replaces the list shown above (<?php echo $h($sup ? Dropdown::getDropdownName('glpi_suppliers', $sup) : 'Standard list'); ?>).</span>
    </div>
    <?php
    /** @var DBmysql $DB */
    global $DB;
    $order_choices = iterator_to_array($DB->request(['SELECT' => ['id', 'name', 'num_order'], 'FROM' => 'glpi_plugin_order_orders',
        'WHERE' => ['is_deleted' => 0, 'is_template' => 0], 'ORDER' => 'id DESC']));
    $order_rows = [];
    if ($from_order > 0) {
        foreach ($DB->request(['SELECT' => ['r.name AS part', 'r.manufacturers_reference AS spare', 'r.comment AS description', 'COUNT' => '* AS units'],
            'FROM' => 'glpi_plugin_order_orders_items AS i',
            'INNER JOIN' => ['glpi_plugin_order_references AS r' => ['ON' => ['r' => 'id', 'i' => 'plugin_order_references_id']]],
            'WHERE' => ['i.plugin_order_orders_id' => $from_order], 'GROUPBY' => 'r.id', 'ORDER' => 'r.name']) as $r) {
            $order_rows[] = ['part' => (string) $r['part'], 'spare' => (string) $r['spare'], 'description' => (string) $r['description'],
                'qty' => (int) ceil($r['units'] / $devices_in_order)];
        }
    }
    ?>
    <form method="get" action="<?php echo $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomsheet.php'); ?>" class="d-flex flex-wrap gap-2 align-items-end mb-2">
      <input type="hidden" name="type" value="<?php echo $h($type); ?>"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
      <?php if ($sup) { ?><input type="hidden" name="supplier" value="<?php echo $sup; ?>"><?php } ?>
      <div><label class="form-label small mb-1" for="fo-order">Order</label>
        <select id="fo-order" name="from_order" class="form-select form-select-sm" style="min-width:280px">
          <option value="0">Choose an order…</option>
          <?php foreach ($order_choices as $oc) { ?>
            <option value="<?php echo (int) $oc['id']; ?>"<?php echo (int) $oc['id'] === $from_order ? ' selected' : ''; ?>><?php echo $h($oc['num_order'] . ' · ' . $oc['name']); ?></option>
          <?php } ?>
        </select></div>
      <div><label class="form-label small mb-1" for="fo-devices">Devices in this order</label>
        <input id="fo-devices" name="devices" type="number" min="1" step="1" value="<?php echo $devices_in_order; ?>" class="form-control form-control-sm" style="width:120px"></div>
      <button class="btn btn-sm btn-outline-primary" type="submit"><i class="ti ti-file-import"></i> Load the order's lines</button>
    </form>
    <?php if ($from_order > 0 && $can_edit) { ?>
      <form method="post" action="<?php echo $h($self); ?>" onsubmit="return confirm('Replace the parts list shown above with these lines?')">
        <input type="hidden" name="action" value="order_save">
        <div class="pr-tbl"><table class="table table-vcenter"><thead><tr><th>#</th><th>Part Number</th><th>Spare part number</th><th>Description</th><th class="text-end">Qty per device</th></tr></thead><tbody>
          <?php if (!$order_rows) { ?><tr><td colspan="5" class="text-secondary">This order has no lines.</td></tr><?php } ?>
          <?php foreach ($order_rows as $k => $r) { $ln = 'lines[' . $k . ']'; ?>
            <tr>
              <td><?php echo $k + 1; ?></td>
              <td><input name="<?php echo $ln; ?>[part]" class="form-control form-control-sm" value="<?php echo $h($r['part']); ?>" required aria-label="Part Number"></td>
              <td><input name="<?php echo $ln; ?>[spare]" class="form-control form-control-sm" value="<?php echo $h($r['spare']); ?>" aria-label="Spare part number"></td>
              <td><input name="<?php echo $ln; ?>[description]" class="form-control form-control-sm" value="<?php echo $h($r['description']); ?>" aria-label="Description" style="min-width:220px"></td>
              <td class="text-end"><input name="<?php echo $ln; ?>[qty]" type="number" min="0" step="1" value="<?php echo (int) $r['qty']; ?>" class="form-control form-control-sm text-end" style="width:90px" aria-label="Qty per device"></td>
            </tr>
          <?php } ?>
        </tbody></table></div>
        <?php if ($order_rows) { ?><button class="btn btn-primary mt-2" type="submit"><i class="ti ti-device-floppy"></i> Save all</button><?php } ?>
        <?php Html::closeForm(); ?>
    <?php } ?>
  </div>
  <?php } ?>

    <?php if ($can_edit) { ?>
    <details class="mt-2 no-print"><summary class="fw-bold" style="cursor:pointer">Import the parts list from a file</summary>
      <form method="post" enctype="multipart/form-data" action="<?php echo $h($self); ?>" class="row g-2 align-items-end mt-1" style="max-width:900px">
        <input type="hidden" name="action" value="import">
        <div class="col-md-6"><label class="form-label">File (.csv)</label><input type="file" name="parts" accept=".csv,text/csv" class="form-control" required>
          <div class="form-text">Columns: <b>Part Number</b>, <b>Qty</b>, Spare Part number, Description (same as the BOQ template).
            <a href="<?php echo $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/templates/LOM_PARTS_TEMPLATE.csv'); ?>" download>Download the template</a>.</div></div>
        <div class="col-md-3"><label class="form-check"><input class="form-check-input" type="checkbox" name="replace" value="1"> <span class="form-check-label">Replace the current list</span></label></div>
        <div class="col-md-3"><button class="btn btn-primary" type="submit"><i class="ti ti-upload"></i> Import</button></div>
      <?php Html::closeForm(); ?>
    </details>
    <?php } ?>
  </div>

</div>

<div class="modal fade" id="lms-photo" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h3 class="modal-title" id="lms-photo-title"></h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body text-center"><img id="lms-photo-img" src="" alt="" style="max-width:100%;max-height:75vh;object-fit:contain"><div id="lms-photo-nav" class="d-flex gap-2 justify-content-center mt-2"></div></div>
</div></div></div>
<script>
(() => {
  const th = document.getElementById('lms-thumbs'), main = document.getElementById('lms-main');
  if (th) th.addEventListener('click', e => { const i = e.target.closest('img'); if (!i) return;
    th.querySelectorAll('img').forEach(x => x.classList.toggle('on', x === i)); main.querySelector('img').src = i.src; });
  const img = document.getElementById('lms-photo-img'), nav = document.getElementById('lms-photo-nav');
  document.querySelectorAll('.lms-photo').forEach(p => p.addEventListener('click', () => {
    const L = JSON.parse(p.dataset.photos); document.getElementById('lms-photo-title').textContent = p.dataset.title; img.src = L[0]; img.alt = 'Photo of ' + p.dataset.title;
    nav.innerHTML = L.length > 1 ? L.map((u, k) => `<button type="button" class="btn btn-sm btn-outline-secondary" data-u="${u.replace(/"/g, '&quot;')}">${k + 1}</button>`).join('') : '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('lms-photo')).show(); }));
  nav.addEventListener('click', e => { const b = e.target.closest('button'); if (b) img.src = b.dataset.u; });
})();
</script>
<?php
Html::footer();
