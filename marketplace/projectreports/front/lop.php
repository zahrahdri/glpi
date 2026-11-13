<?php

/**
 * Management > Price lists (LOP): import a supplier's list of prices.
 * The LOP prices the LOM parts lists in the ticket's Price check, so all requests are compared with the same prices.
 * The Orders catalog prices are not changed.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsBoqimport::canImport()) {
    Html::displayRightError();
}

$h = fn($v) => htmlescape((string) $v);
$money = fn($v) => number_format(round((float) $v, 2), floor(round((float) $v, 2)) == round((float) $v, 2) ? 0 : 2);
$self = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lop.php';
$template = $CFG_GLPI['root_doc'] . '/plugins/projectreports/templates/LOM_TEMPLATE.xlsx?v=' . PLUGIN_PROJECTREPORTS_VERSION
    . '-' . (int) @filemtime(__DIR__ . '/../public/templates/LOM_TEMPLATE.xlsx');
$action = $_POST['action'] ?? '';
PluginProjectreportsLop::ensureSchema();

// ---- CSV export of a supplier's LOP (same columns as the LOM template, so it can be imported again)
if (($_GET['export'] ?? '') === 'csv' && (int) ($_GET['view'] ?? 0) > 0) {
    $sid = (int) $_GET['view'];
    $lop_name = (string) (PluginProjectreportsLop::info()[$sid]['name'] ?? '') ?: Dropdown::getDropdownName('glpi_suppliers', $sid);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="LOP_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $lop_name) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, PluginProjectreportsLop::EXPORT_COLUMNS);
    foreach (PluginProjectreportsLop::exportRows($sid) as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ---- save the previewed LOP
if ($action === 'apply' && isset($_SESSION['projectreports_lop'])) {
    $lop = $_SESSION['projectreports_lop'];
    try {
        $st = PluginProjectreportsLop::apply($lop['lines'], $lop['suppliers_id'], $lop['name'], (float) ($lop['vat'] ?? 0));
        $msg = sprintf('LOP "%s" saved: %d new parts, %d prices changed, %d unchanged.', $lop['name'], $st['new'], $st['changed'], $st['same']);
        unset($_SESSION['projectreports_lop']);
        Session::addMessageAfterRedirect(htmlescape($msg));
    } catch (RuntimeException $e) {
        Session::addMessageAfterRedirect(htmlescape($e->getMessage()), false, ERROR);
    }
    Html::redirect($self);
}

// ---- administrators: edit the LOP lines by hand
$edit_sid = (int) ($_POST['suppliers_id'] ?? 0);
if (in_array($action, ['line_save', 'lines_save', 'line_delete', 'lop_rename', 'lop_delete'], true) && $edit_sid > 0) {
    if (!PluginProjectreportsLop::canEdit()) {
        Html::displayRightError();
    }
    $back = $self . '?view=' . $edit_sid . '&edit=1';
    if ($action === 'line_save') {
        $why = PluginProjectreportsLop::saveLine($edit_sid, (int) ($_POST['id'] ?? 0), $_POST);
        Session::addMessageAfterRedirect(htmlescape($why !== '' ? $why : 'Line ' . trim((string) $_POST['part']) . ' saved.'), false, $why !== '' ? ERROR : INFO);
    } elseif ($action === 'line_delete') {
        PluginProjectreportsLop::deleteLine($edit_sid, (int) ($_POST['id'] ?? 0));
        Session::addMessageAfterRedirect(htmlescape('Line deleted.'));
    } elseif ($action === 'lines_save') {
        // every line of the table in one save: lines[id][field]; lines[new] = the new line (when a part number is typed);
        // lines[id][delete] = delete that line
        $saved = $added = $deleted = 0;
        $problems = [];
        $lines = (array) ($_POST['lines'] ?? []);
        foreach ($lines as $lid => $fields) {
            if ($lid !== 'new' && !empty($fields['delete'])) {
                PluginProjectreportsLop::deleteLine($edit_sid, (int) $lid);
                $deleted++;
                unset($lines[$lid]);
            }
        }
        foreach ($lines as $lid => $fields) {
            $fields = (array) $fields;
            if ($lid === 'new') {
                if (trim((string) ($fields['part'] ?? '')) === '') {
                    continue;
                }
                $why = PluginProjectreportsLop::saveLine($edit_sid, 0, $fields);
                $why !== '' ? $problems[] = $why : $added++;
                continue;
            }
            $why = PluginProjectreportsLop::saveLine($edit_sid, (int) $lid, $fields);
            $why !== '' ? $problems[] = $why : $saved++;
        }
        $done = "$saved line(s) saved" . ($added ? ", $added added" : '') . ($deleted ? ", $deleted deleted" : '') . '.';
        Session::addMessageAfterRedirect(htmlescape($problems ? implode(' ', $problems) . " ($done)" : $done), false, $problems ? ERROR : INFO);
    } elseif ($action === 'lop_rename') {
        $vat = PluginProjectreportsLop::vatInput($_POST['vat'] ?? '');
        PluginProjectreportsLop::rename($edit_sid, (string) ($_POST['name'] ?? ''), $vat);
        Session::addMessageAfterRedirect(htmlescape($vat === null ? 'LOP name saved. VAT must be a number between 0 and 100: not changed.' : 'LOP name and VAT saved.'), false, $vat === null ? WARNING : INFO);
    } else {
        PluginProjectreportsLop::deleteLop($edit_sid);
        Session::addMessageAfterRedirect(htmlescape('LOP deleted. Existing orders keep their prices.'));
        $back = $self;
    }
    Html::redirect($back);
}

$preview = null;
if ($action === 'preview') {
    $sid = (int) ($_POST['suppliers_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $problems = [];
    if ($sid <= 0) {
        $problems[] = 'Choose the supplier this LOP belongs to.';
    }
    if ($name === '') {
        $problems[] = 'Give the LOP a name (e.g. "HPE LOP 2026-Q4").';
    }
    $vat = PluginProjectreportsLop::vatInput($_POST['vat'] ?? '');
    if ($vat === null) {
        $problems[] = 'VAT must be a number between 0 and 100 (e.g. 10).';
    }
    if (empty($_FILES['lop']['tmp_name']) || !is_uploaded_file($_FILES['lop']['tmp_name'])) {
        $problems[] = 'Choose the LOP file (.xlsx or .csv).';
    } else {
        $parsed = PluginProjectreportsBoqimport::parse($_FILES['lop']['tmp_name'], 'lop');
        $problems = array_merge($problems, $parsed['errors']);
    }
    if ($problems) {
        foreach ($problems as $p) {
            Session::addMessageAfterRedirect(htmlescape($p), false, ERROR);
        }
        Html::redirect($self);
    }
    // one price per part: the last row wins
    $by = [];
    foreach ($parsed['lines'] as $l) {
        $by[$l['part']] = $l;
    }
    $lines = PluginProjectreportsLop::compare(array_values($by), $sid);
    $preview = ['lines' => $lines, 'suppliers_id' => $sid, 'name' => $name, 'vat' => $vat,
        'warnings' => $parsed['warnings'], 'dupes' => count($parsed['lines']) - count($by)];
    $_SESSION['projectreports_lop'] = $preview;
}

Html::header('Price lists (LOP)', '', 'management', 'PluginProjectreportsLopmenu');
echo '<link rel="stylesheet" href="' . $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/css/reports.css?v=' . PLUGIN_PROJECTREPORTS_VERSION) . '">';
echo '<div class="pr pr-boq">';
echo '<div class="pr-head"><div><h2 class="pr-title">Price lists (LOP)</h2><div class="pr-sub">The supplier\'s price list prices the LOM parts lists in each request\'s Price check, so every request\'s BOQ is estimated with the same prices</div></div>'
    . '<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="' . $h($CFG_GLPI['root_doc'] . '/plugins/order/front/order.php') . '"><i class="ti ti-file-invoice"></i> Orders</a>'
    . '<a class="btn btn-outline-secondary" href="' . $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lom.php') . '"><i class="ti ti-list-details"></i> LOM catalog</a>'
    . '</div></div>';

if ($preview) {
    $L = $preview['lines'];
    $count = fn($c) => count(array_filter($L, fn($l) => $l['change'] === $c));
    echo '<div class="pr-card"><h3>Check the LOP before saving</h3><div class="pr-sub" style="margin-bottom:10px">' . $h($preview['name']) . ' · '
        . $h(Dropdown::getDropdownName('glpi_suppliers', $preview['suppliers_id'])) . ' · ' . count($L) . ' parts</div>';
    echo '<div class="pr-k6" style="grid-template-columns:repeat(3,1fr)">';
    foreach ([['new part', 'New parts in this LOP', '#118DFF'], ['changed', 'Prices changed', '#E66C37'], ['same', 'Unchanged', '#1AAB40']] as [$k, $label, $c]) {
        echo '<div class="pr-card pr-kpi"><div class="v" style="color:' . $c . '">' . $count($k) . '</div><div class="l">' . $h($label) . '</div></div>';
    }
    echo '</div>';
    foreach ($preview['warnings'] as $w) {
        echo '<div class="alert alert-warning py-1 my-1">' . $h($w) . '</div>';
    }
    if ($preview['dupes']) {
        echo '<div class="alert alert-warning py-1 my-1">' . $preview['dupes'] . ' part(s) appear more than once in the file: the last price is used.</div>';
    }
    echo '<div class="pr-tbl" style="max-height:520px;overflow:auto"><table><thead><tr><th>Part Number</th><th>Device</th><th>Spare Part number</th><th>Description</th><th class="r">QTY per device</th><th class="r">Qty</th><th class="r">Current LOP price</th><th class="r">New LOP price</th><th class="r">Change</th><th></th></tr></thead><tbody>';
    $order = ['changed' => 0, 'new part' => 1, 'same' => 2];
    usort($L, fn($a, $b) => $order[$a['change']] <=> $order[$b['change']] ?: strcmp($a['part'], $b['part']));
    foreach ($L as $l) {
        $qty = $h($l['qty']) . ($l['old_qty'] !== null && $l['old_qty'] !== $l['qty'] ? ' <span class="small text-secondary">was ' . $h($l['old_qty']) . '</span>' : '');
        $diff = $l['old_price'] !== null && $l['old_price'] > 0 ? ($l['price'] - $l['old_price']) / $l['old_price'] : null;
        $badge = ['new part' => 'bg-blue-lt', 'changed' => 'bg-orange-lt', 'same' => 'bg-green-lt'][$l['change']];
        $dev = $l['device'] !== '' ? '<span class="badge bg-blue-lt">' . $h($l['device']) . '</span>' : '<span class="text-secondary">part</span>';
        if ($l['old_device'] !== null && $l['old_device'] !== $l['device']) {
            $dev .= ' <span class="small text-secondary">was ' . $h($l['old_device'] ?: 'part') . '</span>';
        }
        $spare = $h($l['spare']);
        if ($l['spare'] === '' && ($l['old_spare'] ?? '') !== '') {
            $spare = '<span class="text-secondary">' . $h($l['old_spare']) . ' (kept)</span>';
        } elseif ($l['old_spare'] !== null && $l['old_spare'] !== '' && $l['old_spare'] !== $l['spare']) {
            $spare .= ' <span class="small text-secondary">was ' . $h($l['old_spare']) . '</span>';
        }
        $per = $l['per'] === null ? '<span class="text-secondary">—</span>' : $h($l['per']);
        if ($l['old_per'] !== null && $l['per'] !== $l['old_per']) {
            $per .= ' <span class="small text-secondary">was ' . $h($l['old_per']) . '</span>';
        }
        echo '<tr><td><b>' . $h($l['part']) . '</b></td><td>' . $dev . '</td><td>' . $spare . '</td><td style="max-width:380px" title="' . $h($l['desc']) . '">' . $h($l['desc']) . '</td>'
            . '<td class="r">' . $per . '</td><td class="r">' . $qty . '</td><td class="r">' . ($l['old_price'] === null ? '—' : $h($money($l['old_price']))) . '</td><td class="r"><b>' . $h($money($l['price'])) . '</b></td>'
            . '<td class="r">' . ($diff === null || $l['change'] === 'same' ? '' : '<span class="' . ($diff > 0 ? 'text-danger' : 'text-success') . '">' . ($diff > 0 ? '+' : '') . round($diff * 100, 1) . '%</span>') . '</td>'
            . '<td><span class="badge ' . $badge . '">' . $h($l['change']) . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<form method="post" action="' . $h($self) . '" class="d-flex gap-2 mt-3 align-items-center"><input type="hidden" name="action" value="apply">'
        . '<a class="btn btn-outline-secondary" href="' . $h($self) . '">Cancel</a><button type="submit" class="btn btn-primary"><i class="ti ti-check"></i> Save LOP prices</button>'
        . '<span class="pr-sub">Existing BOQs and orders keep their prices.</span>';
    Html::closeForm();
    echo '</div>';
} elseif ((int) ($_GET['view'] ?? 0) > 0) {
    /** @var DBmysql $DB */
    global $DB;
    $sid = (int) $_GET['view'];
    $i = PluginProjectreportsLop::info()[$sid] ?? ['name' => ''];
    $admin = PluginProjectreportsLop::canEdit();
    $editing = $admin && !empty($_GET['edit']);
    $view_url = $self . '?view=' . $sid;
    echo '<div class="pr-card"><div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap"><h3 class="m-0">' . $h($i['name']) . ' · ' . $h(Dropdown::getDropdownName('glpi_suppliers', $sid)) . '</h3><div class="d-flex gap-2">';
    if ($admin) {
        echo $editing ? '<a class="btn btn-primary btn-sm" href="' . $h($view_url) . '"><i class="ti ti-check"></i> Done editing</a>'
            : '<a class="btn btn-outline-primary btn-sm" href="' . $h($view_url . '&edit=1') . '"><i class="ti ti-pencil"></i> Edit</a>';
    }
    echo '<a class="btn btn-outline-secondary btn-sm" href="' . $h($view_url . '&export=csv') . '"><i class="ti ti-download"></i> Export CSV</a>';
    echo '<a class="btn btn-outline-secondary btn-sm" href="' . $h($self) . '">Back</a></div></div>';
    $rows = iterator_to_array($DB->request(['FROM' => PluginProjectreportsLop::TABLE, 'WHERE' => ['suppliers_id' => $sid], 'ORDER' => ['device DESC', 'part']]), false);
    $hidden = '<input type="hidden" name="suppliers_id" value="' . $sid . '">';

    if ($editing) {
        // LOP name and delete
        echo '<div class="d-flex flex-wrap gap-2 align-items-end mb-3"><form method="post" action="' . $h($self) . '" class="d-flex gap-2 align-items-end m-0">' . $hidden . '<input type="hidden" name="action" value="lop_rename">'
            . '<div><label class="form-label small mb-1" for="lop-name">LOP name / version</label><input class="form-control form-control-sm" id="lop-name" name="name" value="' . $h($i['name']) . '" required style="min-width:260px"></div>'
            . '<div><label class="form-label small mb-1" for="lop-vat">VAT %</label><input class="form-control form-control-sm" id="lop-vat" name="vat" inputmode="decimal" value="' . $h($money($i['vat'] ?? 0)) . '" style="width:80px"></div>'
            . '<button class="btn btn-sm btn-outline-primary" type="submit">Save name and VAT</button>';
        Html::closeForm();
        echo '<form method="post" action="' . $h($self) . '" class="m-0 ms-auto" onsubmit="return confirm(\'Delete the whole LOP of this supplier (' . count($rows) . ' lines)? Existing orders keep their prices.\')">' . $hidden . '<input type="hidden" name="action" value="lop_delete">'
            . '<button class="btn btn-sm btn-outline-danger" type="submit"><i class="ti ti-trash"></i> Delete this LOP</button>';
        Html::closeForm();
        echo '</div>';
        $devsel = function (string $form, string $cur, string $name = 'device') use ($h) {
            $o = '<select class="form-select form-select-sm" name="' . $name . '" form="' . $form . '" aria-label="Device"><option value="">part</option>';
            foreach (PluginProjectreportsBoqimport::DEVICE_TYPES as $t) {
                $o .= '<option' . ($t === $cur ? ' selected' : '') . '>' . $h($t) . '</option>';
            }
            return $o . '</select>';
        };
        $cell = fn(string $form, string $name, $v, string $extra = '') => '<input class="form-control form-control-sm" name="' . $name . '" form="' . $form . '" value="' . $h($v) . '" ' . $extra . '>';
        echo '<form id="lop-edit" method="post" action="' . $h($self) . '" class="d-none" onsubmit="const n=this.ownerDocument.querySelectorAll(\'input.lop-del:checked\').length;return !n||confirm(\'Delete \'+n+\' line(s)?\')">' . $hidden . '<input type="hidden" name="action" value="lines_save">';
        Html::closeForm();
        $f = 'lop-edit';
        $save_bar = '<div class="d-flex flex-wrap gap-2 align-items-center my-2"><button type="submit" form="' . $f . '" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Save all lines</button>'
            . '<span class="pr-sub">Change any lines, type a new line in the first row, tick Delete on lines to remove, then press Save all lines once. QTY per device may stay empty. '
            . 'Changes apply to new Price checks and BOQs; existing orders keep their prices.</span></div>';
        echo $save_bar;
        echo '<div class="pr-tbl" style="max-height:640px;overflow:auto"><table><thead><tr><th style="min-width:130px">Part Number</th><th style="min-width:110px">Device</th><th style="min-width:120px">Spare Part number</th><th style="min-width:260px">Description</th>'
            . '<th class="r" style="min-width:90px">QTY per device</th><th class="r" style="min-width:80px">Qty</th><th class="r" style="min-width:110px">Unit Price</th><th class="text-center">Delete</th></tr></thead><tbody>';
        // new line: saved with the others when a part number is typed
        $n = 'lines[new]';
        echo '<tr class="table-light"><td>' . $cell($f, $n . '[part]', '', 'placeholder="new part number" aria-label="New part number"') . '</td><td>' . $devsel($f, '', $n . '[device]') . '</td>'
            . '<td>' . $cell($f, $n . '[spare]', '', 'aria-label="New spare part number"') . '</td><td>' . $cell($f, $n . '[description]', '', 'aria-label="New description"') . '</td>'
            . '<td>' . $cell($f, $n . '[qty_per_device]', '', 'inputmode="numeric" aria-label="New QTY per device"') . '</td>'
            . '<td>' . $cell($f, $n . '[qty]', '1', 'inputmode="numeric" aria-label="New Qty"') . '</td>'
            . '<td>' . $cell($f, $n . '[price]', '', 'inputmode="decimal" aria-label="New unit price"') . '</td>'
            . '<td class="text-center small text-secondary">new</td></tr>';
        foreach ($rows as $r) {
            $n = 'lines[' . (int) $r['id'] . ']';
            echo '<tr><td>' . $cell($f, $n . '[part]', $r['part'], 'required aria-label="Part Number"') . '</td><td>' . $devsel($f, (string) $r['device'], $n . '[device]') . '</td>'
                . '<td>' . $cell($f, $n . '[spare]', $r['spare'], 'aria-label="Spare Part number"') . '</td><td>' . $cell($f, $n . '[description]', $r['description'], 'aria-label="Description"') . '</td>'
                . '<td>' . $cell($f, $n . '[qty_per_device]', $r['qty_per_device'] === null ? '' : (int) $r['qty_per_device'], 'inputmode="numeric" aria-label="QTY per device"') . '</td>'
                . '<td>' . $cell($f, $n . '[qty]', (int) $r['qty'], 'inputmode="numeric" aria-label="Qty"') . '</td>'
                . '<td>' . $cell($f, $n . '[price]', rtrim(rtrim(number_format((float) $r['price'], 6, '.', ''), '0'), '.'), 'required inputmode="decimal" aria-label="Unit Price"') . '</td>'
                . '<td class="text-center"><input type="checkbox" class="form-check-input lop-del" name="' . $n . '[delete]" value="1" form="' . $f . '" aria-label="Delete ' . $h($r['part']) . '"></td></tr>';
        }
        echo '</tbody></table></div>';
        echo $save_bar . '</div>';
    } else {
        $dc = array_count_values(array_map(fn($r) => $r['device'] ?: 'part', $rows));
        echo '<div class="d-flex flex-wrap gap-2 mb-2" id="lop-dev" role="group" aria-label="Device filter">';
        foreach (['' => 'All', 'part' => 'Parts'] + array_combine(PluginProjectreportsBoqimport::DEVICE_TYPES, PluginProjectreportsBoqimport::DEVICE_TYPES) as $k => $label) {
            $n = $k === '' ? count($rows) : (int) ($dc[$k] ?? 0);
            if ($k !== '' && !$n) {
                continue;
            }
            echo '<button type="button" class="btn btn-sm ' . ($k === '' ? 'btn-primary' : 'btn-outline-secondary') . '" data-dev="' . $h($k) . '">' . $h($label) . ' <span class="opacity-75">' . $n . '</span></button>';
        }
        echo '</div><div class="pr-tbl" style="max-height:640px;overflow:auto"><table><thead><tr><th>Part Number</th><th>Device</th><th>Spare Part number</th><th>Description</th><th class="r">QTY per device</th><th class="r">Qty</th><th class="r">Unit Price</th><th>Updated</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr data-dev="' . $h($r['device'] ?: 'part') . '" data-q="' . (int) $r['qty'] . '"><td><b>' . $h($r['part']) . '</b></td><td>' . ($r['device'] !== '' ? '<span class="badge bg-blue-lt">' . $h($r['device']) . '</span>' : '<span class="text-secondary">part</span>') . '</td><td>' . ($r['spare'] !== '' ? $h($r['spare']) : '<span class="text-secondary">—</span>') . '</td><td>' . $h($r['description']) . '</td><td class="r">' . ($r['qty_per_device'] === null ? '<span class="text-secondary">—</span>' : (int) $r['qty_per_device']) . '</td><td class="r">' . (int) $r['qty'] . '</td><td class="r">' . $h($money($r['price'])) . '</td><td>' . $h(substr((string) $r['date_mod'], 0, 16)) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
        echo "<script>document.getElementById('lop-dev').addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;"
            . "document.querySelectorAll('#lop-dev button').forEach(x=>{x.classList.toggle('btn-primary',x===b);x.classList.toggle('btn-outline-secondary',x!==b);});"
            . "document.querySelectorAll('tr[data-dev]').forEach(t=>{t.hidden=!!b.dataset.dev&&t.dataset.dev!==b.dataset.dev;});});</script>";
    }
} else {
    echo '<form method="post" enctype="multipart/form-data" action="' . $h($self) . '"><input type="hidden" name="action" value="preview">';
    echo '<div class="pr-card"><h3>Import a LOM</h3><div class="row g-3" style="max-width:1000px">';
    echo '<div class="col-md-5"><label class="form-label">Supplier <span class="text-danger">*</span></label>';
    Supplier::dropdown(['name' => 'suppliers_id', 'value' => 0, 'entity' => $_SESSION['glpiactive_entity'], 'width' => '100%']);
    echo '</div>';
    echo '<div class="col-md-4"><label class="form-label">LOP name / version <span class="text-danger">*</span></label><input name="name" class="form-control" required placeholder="e.g. HPE LOP 2026-Q4"></div>';
    echo '<div class="col-md-2"><label class="form-label" for="lop-vat-new">VAT %</label><input id="lop-vat-new" name="vat" class="form-control" inputmode="decimal" value="10"><div class="form-text">Added on top of the prices.</div></div>';
    echo '<div class="col-md-6"><label class="form-label">LOM file (.xlsx or .csv) <span class="text-danger">*</span></label><input type="file" name="lop" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control" required>'
        . '<div class="form-text">Columns: <b>Part Number</b> (required), Part Description, <b>Spare Part Number</b>, Unit price tax free, QTY per device, Quantity (empty = QTY per device, else 1): '
        . 'the same columns as the CSV from an order\'s Export tab, so that file can be imported here as it is. '
        . 'Unit price tax free is optional: without that column, the prices already saved for these parts are kept. '
        . '<a href="' . $h($template) . '" download>Download the LOM template</a>. Prices before tax.</div></div>';
    echo '</div><button type="submit" class="btn btn-primary mt-3"><i class="ti ti-eye"></i> Preview</button></div>';
    Html::closeForm();

    // ---- LOPs in use
    /** @var DBmysql $DB */
    global $DB;
    $info = PluginProjectreportsLop::info();
    echo '<div class="pr-card"><h3>LOPs in use</h3>';
    // every supplier that has LOP lines, even when its LOP name was lost
    $sids = array_map('intval', array_keys($info));
    foreach ($DB->request(['SELECT' => 'suppliers_id', 'DISTINCT' => true, 'FROM' => PluginProjectreportsLop::TABLE, 'WHERE' => ['suppliers_id' => ['>', 0]]]) as $r) {
        if (!in_array((int) $r['suppliers_id'], $sids, true)) {
            $sids[] = (int) $r['suppliers_id'];
            $info[(int) $r['suppliers_id']] = ['name' => '(LOP without a name)', 'imported' => '',
                'parts' => (int) $DB->request(['COUNT' => 'c', 'FROM' => PluginProjectreportsLop::TABLE, 'WHERE' => ['suppliers_id' => (int) $r['suppliers_id']]])->current()['c']];
        }
    }
    if (!$sids) {
        echo '<p class="pr-sub">No LOP imported yet.</p>';
    } else {
        echo '<div class="pr-tbl"><table><thead><tr><th>Supplier</th><th>LOP</th><th class="r">Part numbers</th><th>Imported</th><th></th></tr></thead><tbody>';
        foreach ($sids as $sid) {
            $i = $info[$sid] ?? null;
            echo '<tr><td>' . $h($sid ? Dropdown::getDropdownName('glpi_suppliers', $sid) : '(no supplier)') . '</td><td>' . ($i ? '<a href="' . $h($self . '?view=' . $sid) . '" title="Show the parts and prices of this LOP"><b>' . $h($i['name']) . '</b></a>' : '<span class="text-danger">no LOP imported</span>') . '</td>'
                . '<td class="r">' . $h($i['parts'] ?? '') . '</td>'
                . '<td>' . $h(substr((string) ($i['imported'] ?? ''), 0, 16)) . '</td><td>';
            if ($sid) {
                echo '<div class="d-flex gap-2">';
                if ($i) {
                    echo '<a class="btn btn-sm btn-outline-primary" href="' . $h($self . '?view=' . $sid) . '"><i class="ti ti-list-details"></i> View</a>';
                    if (PluginProjectreportsLop::canEdit()) {
                        echo '<a class="btn btn-sm btn-outline-secondary" href="' . $h($self . '?view=' . $sid . '&edit=1') . '"><i class="ti ti-pencil"></i> Edit</a>';
                    }
                }
                echo '</div>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}
echo '</div>';
Html::footer();
