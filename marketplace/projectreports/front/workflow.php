<?php

/**
 * Workflow of the capacity requests (New project, Expansion, Modernization) as a full page, to share or print.
 * The "Workflow" button on each request form opens the same content in a dialog (public/js/form-workflow.js).
 *   front/workflow.php?type=new|expansion|modernization[&print=1]
 * Any logged-in user may see it (it describes the process, no data).
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

$type = in_array($_GET['type'] ?? '', ['new', 'expansion', 'modernization'], true) ? $_GET['type'] : 'new';
$root = $CFG_GLPI['root_doc'] . '/plugins/projectreports';
$ver = fn(string $f) => '?m=' . (int) @filemtime(__DIR__ . '/../public/' . $f);

if (Session::getCurrentInterface() === 'helpdesk') {
    Html::helpHeader('Request workflow');
} else {
    Html::header('Request workflow', $_SERVER['PHP_SELF'], 'helpdesk', 'ticket');
}
echo '<link rel="stylesheet" href="' . htmlescape($root . '/css/form-workflow.css' . $ver('css/form-workflow.css')) . '">';
?>
<div class="prwf-page" id="prwf-page" data-type="<?php echo htmlescape($type); ?>" data-print="<?php echo !empty($_GET['print']) ? '1' : '0'; ?>">
  <div class="prwf-page-top">
    <h1>Infrastructure request workflow</h1>
    <div class="prwf-page-actions">
      <div class="prwf-tabs" role="tablist" aria-label="Request type"></div>
      <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer"></i> Print</button>
    </div>
  </div>
  <div class="prwf-view"></div>
</div>
<?php
echo '<script src="' . htmlescape($root . '/js/form-workflow.js' . $ver('js/form-workflow.js')) . '"></script>';
Session::getCurrentInterface() === 'helpdesk' ? Html::helpFooter() : Html::footer();
