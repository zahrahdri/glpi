<?php

/**
 * Orders › order › Export: download the lines of an order as CSV (UTF-8 with BOM, opens in Excel).
 */

Session::checkLoginUser();
if (!PluginProjectreportsBoqimport::canImport()) {
    Html::displayRightError();
}

$oid = (int) ($_GET['orders_id'] ?? 0);
$order = new PluginOrderOrder();
if (!$order->getFromDB($oid) || !$order->can($oid, READ)) {
    Html::displayRightError();
}

$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($order->fields['name'] ?: 'order-' . $oid));
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $name . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, PluginProjectreportsOrderexport::COLUMNS);
foreach (PluginProjectreportsOrderexport::lines($oid) as $row) {
    fputcsv($out, $row);
}
fclose($out);
exit;
