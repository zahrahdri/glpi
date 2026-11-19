<?php

/**
 * Tools > Reporting dashboard: management dashboard (overview + report pages).
 * Data comes from PluginProjectreportsData::all(); charts use the bundled ECharts (works offline).
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsDashboardmenu::canView()) {
    Html::displayRightError();
}

Html::header('Reporting dashboard', $_SERVER['PHP_SELF'], 'tools', 'PluginProjectreportsDashboardmenu');

$data = PluginProjectreportsData::all();
$data['root'] = $CFG_GLPI['root_doc'];
// form address of each kind of record the dashboard links to (Ticket, Project, ...; asset types as found in the data)
$data['urls'] = [];
$types = ['Ticket', 'Project', 'Group', 'User', 'Supplier', 'Budget', 'Datacenter', 'ITILCategory', 'Rack', 'Cable'];
foreach ($data['assets'] ?? [] as $a) {
    $types[] = (string) ($a['itemtype'] ?? '');
}
foreach (array_unique(array_filter($types)) as $type) {
    $item = getItemForItemtype($type);
    if ($item) {
        $data['urls'][$type] = $item::getFormURL();
    }
}
$data['urls']['search_ticket'] = Ticket::getSearchURL();

$pages = [
    'overview' => 'نمای کلی',
    'requests' => 'درخواست‌ها',
    'budget'   => 'بودجه و هزینه',
    'assets'   => 'دارایی‌ها و تمدید',
];
$page = $_GET['page'] ?? 'overview';
if (!isset($pages[$page])) {
    $page = 'overview';
}

$plugin_root = $CFG_GLPI['root_doc'] . '/plugins/projectreports';
// file dates in the address, so browsers fetch the new files after every update
$ver_of = fn(string $f) => '?v=' . PLUGIN_PROJECTREPORTS_VERSION . '-' . @filemtime(__DIR__ . '/../public/' . $f);
echo '<link rel="stylesheet" href="' . htmlescape($plugin_root . '/css/dashboard.css' . $ver_of('css/dashboard.css')) . '">';
?>
<div id="db-app" class="db" dir="rtl" lang="fa" data-page="<?php echo htmlescape($page); ?>">
  <header class="db-top">
    <img class="db-logo" src="<?php echo htmlescape($plugin_root . '/img/logo.png'); ?>" alt="لوگو">
    <div class="db-title">
      <div class="db-h">داشبورد وضعیت موجودی انبار و پیگیری درخواست‌های تجهیزات زیرساخت انفورماتیک</div>
      <div class="db-sub"><span id="db-asof"></span></div>
    </div>
    <nav class="db-nav">
      <?php foreach ($pages as $key => $label) { ?>
        <a href="?page=<?php echo htmlescape($key); ?>" class="<?php echo $key === $page ? 'active' : ''; ?>"><?php echo htmlescape($label); ?></a>
      <?php } ?>
    </nav>
    <a class="db-btn" href="<?php echo htmlescape($plugin_root . '/front/demand.php'); ?>">درخواست‌های تأییدشده و بودجه</a>
  </header>
  <div class="db-layout">
    <aside class="db-slicers" id="db-slicers"></aside>
    <main class="db-main" id="db-body"></main>
  </div>
</div>
<script type="application/json" id="pr-data"><?php
echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?></script>
<?php
echo '<script src="' . htmlescape($plugin_root . '/lib/echarts.min.js?v=5.6.0') . '"></script>';
echo '<script src="' . htmlescape($plugin_root . '/js/dashboard.js' . $ver_of('js/dashboard.js')) . '"></script>';

Html::footer();
