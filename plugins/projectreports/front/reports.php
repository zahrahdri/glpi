<?php

/**
 * Tools > Project reports: team requests, budget & cost, forecast & renewals, rack view.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsMenu::canView()) {
    Html::displayRightError();
}

Html::header('Project reports', $_SERVER['PHP_SELF'], 'tools', 'PluginProjectreportsMenu');

$data = PluginProjectreportsData::all();
$data['root'] = $CFG_GLPI['root_doc'];

$tab = $_GET['tab'] ?? 'requests';
if (!in_array($tab, ['requests', 'demands', 'budget', 'forecast', 'racks'], true)) {
    $tab = 'requests';
}

$plugin_root = $CFG_GLPI['root_doc'] . '/plugins/projectreports';
echo '<link rel="stylesheet" href="' . htmlescape($plugin_root . '/css/reports.css?v=' . PLUGIN_PROJECTREPORTS_VERSION) . '">';
?>
<div id="pr-app" class="pr" data-tab="<?php echo htmlescape($tab); ?>">
  <div class="d-flex justify-content-end mb-2">
    <a class="btn btn-primary" href="<?php echo htmlescape($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/demand.php'); ?>">Approved requests &amp; budgets</a>
  </div>
  <div class="pr-head">
    <div>
      <h2 class="pr-title">Project reports</h2>
      <div class="pr-sub">Live from GLPI · requests, budgets, BOQ orders, assets and racks · <span id="pr-asof"></span></div>
    </div>
    <nav class="pr-tabs" role="tablist">
      <button type="button" role="tab" data-tab="requests">Team requests</button>
      <button type="button" role="tab" data-tab="demands">Demand &amp; cost</button>
      <button type="button" role="tab" data-tab="budget">Budget &amp; cost</button>
      <button type="button" role="tab" data-tab="forecast">Forecast &amp; renewals</button>
      <button type="button" role="tab" data-tab="racks">Rack view</button>
    </nav>
  </div>
  <div class="pr-filters" id="pr-filters"></div>
  <section class="pr-page" data-page="requests"></section>
  <section class="pr-page" data-page="demands"></section>
  <section class="pr-page" data-page="budget"></section>
  <section class="pr-page" data-page="forecast"></section>
  <section class="pr-page" data-page="racks"></section>
</div>
<script type="application/json" id="pr-data"><?php
echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?></script>
<?php
echo '<script src="' . htmlescape($plugin_root . '/lib/echarts.min.js?v=5.6.0') . '"></script>';
echo '<script src="' . htmlescape($plugin_root . '/js/reports.js?v=' . PLUGIN_PROJECTREPORTS_VERSION) . '"></script>';
echo '<script src="' . htmlescape($plugin_root . '/js/rackview.js?v=' . PLUGIN_PROJECTREPORTS_VERSION) . '"></script>';

Html::footer();
