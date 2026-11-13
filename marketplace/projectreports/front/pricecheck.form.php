<?php

/**
 * Ticket › Price check: remember the chosen LOM model and number of devices, or create the draft BOQ with the supplier
 * chosen by the user.
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
$back = Ticket::getFormURLWithID($tickets_id) . '&forcetab=PluginProjectreportsPricecheck$1';
$key = (string) ($_POST['model'] ?? '');
$devices = max(1, (int) ($_POST['devices'] ?? 1));
// which LOP prices each supplier: lops[suppliers_id] = active | v<id> | newest | lowest
$sources = [];
foreach ((array) ($_POST['lops'] ?? []) as $s => $src) {
    if ((int) $s > 0 && preg_match('/^(active|newest|lowest|v\d+)$/', (string) $src)) {
        $sources[(int) $s] = (string) $src;
    }
}

// LOP calculator: remember the supplier, LOP and number of devices chosen
if (($_POST['action'] ?? '') === 'calc') {
    [$csid, $csrc] = array_pad(explode('|', (string) ($_POST['calc'] ?? ''), 2), 2, '');
    $_SESSION['projectreports_calc'][$tickets_id] = ['sid' => (int) $csid,
        'src' => preg_match('/^(active|newest|lowest|v\d+)$/', $csrc) ? $csrc : 'active', 'devices' => $devices];
    Html::redirect($back . '#pr-calc');
}

// remember the model and number of devices chosen for this ticket, then show the prices
if (($_POST['action'] ?? '') === 'select') {
    $budget = max(0, (int) ($_POST['budget'] ?? 0));
    $_SESSION['projectreports_pc'][$tickets_id] = ['key' => $key, 'devices' => $devices, 'budget' => $budget, 'lops' => $sources];
    // the budget chosen here is the budget of the request: its BOQ is then counted in GLPI's budget
    PluginProjectreportsDemand::save($tickets_id, $budget);
    Html::redirect($back);
}

if (!isset($_POST['suppliers_id'])) {
    Session::addMessageAfterRedirect('Choose a supplier first.', false, ERROR);
    Html::redirect($back);
}

$answers = PluginProjectreportsAutoboq::answersForTicket($tickets_id);
try {
    if ($answers === null) {
        throw new RuntimeException('This ticket was not created by a capacity request form.');
    }
    $oid = PluginProjectreportsAutoboq::create($tickets_id, $answers, $key, $devices, (int) $_POST['suppliers_id'], $sources);
    $order = new PluginOrderOrder();
    $order->getFromDB($oid);
    $sid = (int) $_POST['suppliers_id'];
    // a new BOQ is counted on the chosen budget of the request (and in GLPI's budget tab)
    PluginProjectreportsDemand::save($tickets_id, PluginProjectreportsDemand::budgetOf($tickets_id));
    Session::addMessageAfterRedirect(htmlescape(sprintf('Draft BOQ %s created with supplier %s and linked to this ticket.',
        $order->fields['num_order'] ?? '', $sid ? Dropdown::getDropdownName('glpi_suppliers', $sid) : '(none)')));
} catch (Throwable $e) {
    Session::addMessageAfterRedirect(htmlescape('BOQ not created: ' . $e->getMessage()), false, ERROR);
}
Html::redirect($back);
