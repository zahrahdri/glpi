<?php

/**
 * Orders › order › Add from LOP: add the selected LOP lines to the order.
 */

Session::checkLoginUser();
if (!PluginProjectreportsBoqimport::canImport()) {
    Html::displayRightError();
}

$oid = (int) ($_POST['orders_id'] ?? 0);
$order = new PluginOrderOrder();
if (!$order->getFromDB($oid) || !$order->can($oid, UPDATE)) {
    Html::displayRightError();
}
$back = PluginOrderOrder::getFormURLWithID($oid) . '&forcetab=PluginProjectreportsOrderlop$1';

try {
    if (!$order->canUpdateOrder()) {
        throw new RuntimeException('Lines can only be added while the order is Draft (or waiting for approval).');
    }
    $sid = (int) ($_POST['suppliers_id'] ?? 0);
    $chosen = array_intersect_key((array) ($_POST['qty'] ?? []), (array) ($_POST['line'] ?? []));
    $st = PluginProjectreportsOrderlop::add($order, $sid, $chosen);
    Session::addMessageAfterRedirect(htmlescape(sprintf('%d unit(s) added to the order from the LOP, %s before tax (%d new catalog reference(s)).',
        $st['units'], PluginProjectreportsAutoboq::fmtNum($st['total']), $st['new_refs'])));
} catch (Throwable $e) {
    Session::addMessageAfterRedirect(htmlescape('Nothing added: ' . $e->getMessage()), false, ERROR);
}
Html::redirect($back);
