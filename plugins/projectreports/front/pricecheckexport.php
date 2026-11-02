<?php

/**
 * Ticket › Price check: download the BOQ lines of the selected supplier as CSV (nothing is created).
 */

Session::checkLoginUser();
if (!PluginProjectreportsBoqimport::canImport()) {
    Html::displayRightError();
}

$tickets_id = (int) ($_POST['tickets_id'] ?? 0);
$ticket = new Ticket();
if (!$ticket->getFromDB($tickets_id) || !$ticket->can($tickets_id, READ)) {
    Html::displayRightError();
}
$key = (string) ($_POST['model'] ?? '');
$devices = max(1, (int) ($_POST['devices'] ?? 1));
$sid = (int) ($_POST['suppliers_id'] ?? 0);

$est = PluginProjectreportsAutoboq::estimate($key, $devices);
$sup = null;
foreach ($est['suppliers'] as $s) {
    if ((int) $s['suppliers_id'] === $sid) {
        $sup = $s;
        break;
    }
}
if ($sup === null) {
    Session::addMessageAfterRedirect('Choose a supplier first.', false, ERROR);
    Html::redirect(Ticket::getFormURLWithID($tickets_id) . '&forcetab=PluginProjectreportsPricecheck$1');
}

$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'BOQ-ticket-' . $tickets_id . '-' . $sup['supplier']);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $name . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Supplier', $sup['supplier']]);
fputcsv($out, ['Model', $est['model']['name'] ?? $key, 'Devices', $devices]);
fputcsv($out, []);
fputcsv($out, ['Part number', 'Spare part number', 'Description', 'Source', 'Qty per device', 'Unit price (before tax)', 'Line total for all devices (before tax)']);
foreach ($sup['lines'] as $l) {
    fputcsv($out, [$l['part'], $l['spare'], $l['desc'] ?? '', $l['source'], $l['qty'], $l['price'], $l['qty'] * $l['price'] * $devices]);
}
fputcsv($out, []);
fputcsv($out, ['', '', '', '', '', 'Total before tax', $sup['all_total']]);
fputcsv($out, ['', '', '', '', '', 'VAT (%)', $sup['vat']]);
fputcsv($out, ['', '', '', '', '', 'Total with VAT', $sup['all_total'] * (1 + $sup['vat'] / 100)]);
fclose($out);
exit;
