<?php

/**
 * Infrastructure Demand-to-Delivery workflow (New project, Expansion, Modernization) as a full page.
 *   front/workflow.php?type=new|expansion|modernization[&view=customer][&print=1]
 * Requesters (and ?view=customer) see the procurement process as one step, as in the Workflow button of the
 * request forms. Administrators (Setup > Request workflow) see it in detail: order, purchase, warehouse inbound.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

$type = in_array($_GET['type'] ?? '', ['new', 'expansion', 'modernization'], true) ? $_GET['type'] : 'new';
$admin = PluginProjectreportsWorkflowmenu::canView();
$detailed = $admin && ($_GET['view'] ?? '') !== 'customer';
$root = $CFG_GLPI['root_doc'] . '/plugins/projectreports';
$self = $root . '/front/workflow.php';
$ver = fn(string $f) => '?m=' . (int) @filemtime(__DIR__ . '/../public/' . $f);
$helpdesk = Session::getCurrentInterface() === 'helpdesk';

if ($helpdesk) {
    Html::helpHeader('Request workflow');
} elseif ($admin) {
    Html::header('Request workflow', $_SERVER['PHP_SELF'], 'config', 'PluginProjectreportsWorkflowmenu');
} else {
    Html::header('Request workflow', $_SERVER['PHP_SELF'], 'helpdesk', 'ticket');
}
echo '<link rel="stylesheet" href="' . htmlescape($root . '/css/form-workflow.css' . $ver('css/form-workflow.css')) . '">';
?>
<div class="prwf-page" id="prwf-page" data-type="<?php echo htmlescape($type); ?>" data-detailed="<?php echo $detailed ? '1' : '0'; ?>"
     data-view="<?php echo $admin && !$detailed ? 'customer' : ''; ?>" data-print="<?php echo !empty($_GET['print']) ? '1' : '0'; ?>">
  <div class="prwf-page-top">
    <h1>Infrastructure request workflow</h1>
    <div class="prwf-page-actions">
      <?php if ($admin) { ?>
        <div class="prwf-viewswitch" aria-label="View">
          <a href="<?php echo htmlescape($self . '?type=' . $type); ?>" aria-current="<?php echo $detailed ? 'true' : 'false'; ?>">Detailed (admin)</a>
          <a href="<?php echo htmlescape($self . '?view=customer&type=' . $type); ?>" aria-current="<?php echo $detailed ? 'false' : 'true'; ?>">As requesters see it</a>
        </div>
      <?php } ?>
      <div class="prwf-tabs" role="tablist" aria-label="Request type"></div>
      <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer"></i> Print</button>
    </div>
  </div>
  <div class="prwf-view"></div>
</div>
<?php
echo '<script src="' . htmlescape($root . '/js/form-workflow.js' . $ver('js/form-workflow.js')) . '"></script>';
$helpdesk ? Html::helpFooter() : Html::footer();
