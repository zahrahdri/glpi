<?php

/**
 * Tools > Reporting dashboard > پیش‌بینی > تنظیمات پیش‌بینی (administrators: Setup > General UPDATE):
 *   - age limit per device type and the target use of the VM clusters,
 *   - VirOPS clusters: import an Excel / CSV export, or delete it,
 *   - the iCare history: import an Excel / CSV export, or delete it.
 * Both imports have a template filled with sample figures (public/templates).
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsForecast::canConfigure()) {
    Html::displayRightError();
}
PluginProjectreportsForecast::ensureSchema();

$h = fn($v) => htmlescape((string) $v);
$root = $CFG_GLPI['root_doc'] . '/plugins/projectreports';
$self = $root . '/front/forecast.php';
$action = $_POST['action'] ?? '';
$note = fn(string $msg, bool $ok = true) => Session::addMessageAfterRedirect(htmlescape($msg), false, $ok ? INFO : ERROR);
$template = fn(string $f) => $root . '/templates/' . $f . '?v=' . @filemtime(__DIR__ . '/../public/templates/' . $f);

if ($action === 'save') {
    $v = [];
    foreach (['fc_age_server', 'fc_age_storage', 'fc_age_network'] as $k) {
        $v[$k] = max(1, min(30, (float) ($_POST[$k] ?? 0)));
    }
    $v['fc_target_util'] = max(30, min(100, (float) ($_POST['fc_target_util'] ?? 80)));
    PluginProjectreportsForecast::setConfig($v);
    $note('تنظیمات ذخیره شد.');
    Html::redirect($self);
}
if ($action === 'import' || $action === 'virops_import') {
    $field = $action === 'import' ? 'icare' : 'virops';
    $label = $action === 'import' ? 'iCare' : 'VirOPS';
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        $note('فایلی انتخاب نشده است.', false);
        Html::redirect($self);
    }
    try {
        $name = (string) $_FILES[$field]['name'];
        if ($field === 'icare') {
            $read = PluginProjectreportsForecast::readIcare($_FILES[$field]['tmp_name'], $name);
            if (!$read['rows']) {
                throw new RuntimeException('هیچ ردیف قابل استفاده‌ای در فایل نبود (تاریخ یا سال و تعداد لازم است).');
            }
            $n = PluginProjectreportsForecast::saveIcare($read['rows'], $name);
            $what = 'ردیف';
        } else {
            $read = PluginProjectreportsForecast::readClusters($_FILES[$field]['tmp_name'], $name);
            if (!$read['rows']) {
                throw new RuntimeException('هیچ کلاستری در فایل نبود.');
            }
            $n = PluginProjectreportsForecast::saveClusters($read['rows'], $name);
            $what = 'کلاستر';
        }
        $note(sprintf('%s: %d %s وارد شد%s. ستون‌های شناخته‌شده: %s', $label, $n, $what,
            $read['skipped'] ? sprintf(' (%d ردیف ناقص کنار گذاشته شد)', $read['skipped']) : '', implode(', ', $read['columns'])));
    } catch (Throwable $e) {
        $note($label . ': ' . $e->getMessage(), false);
    }
    Html::redirect($self);
}
if ($action === 'icare_delete') {
    PluginProjectreportsForecast::deleteIcare();
    $note('سوابق واردشده iCare حذف شد.');
    Html::redirect($self);
}
if ($action === 'virops_delete') {
    PluginProjectreportsForecast::deleteClusters();
    $note('کلاسترهای واردشده VirOPS حذف شد.');
    Html::redirect($self);
}

/** @var DBmysql $DB */
global $DB;
$cfg = PluginProjectreportsForecast::config();
$years = iterator_to_array($DB->request(['SELECT' => ['year', 'is_demo', 'COUNT' => 'id AS n', 'SUM' => 'quantity AS qty'],
    'FROM' => PluginProjectreportsForecast::ICARE, 'GROUPBY' => ['year', 'is_demo'], 'ORDER' => 'year']), false);
$clusters = iterator_to_array($DB->request(['FROM' => PluginProjectreportsForecast::CLUSTERS, 'ORDER' => 'cluster']), false);
$imported_clusters = array_filter($clusters, fn($c) => $c['source'] !== 'demo');
$imported_years = array_filter($years, fn($y) => !$y['is_demo']);

Html::header('Forecast settings', '', 'tools', 'PluginProjectreportsDashboardmenu');
echo '<link rel="stylesheet" href="' . $h($root . '/css/dashboard.css?v=' . @filemtime(__DIR__ . '/../public/css/dashboard.css')) . '">';
$field = fn($label, $input, $help = '') => '<label class="fc-f"><span>' . $h($label) . '</span>' . $input . ($help ? '<small>' . $h($help) . '</small>' : '') . '</label>';
$num = fn($name, $step = '0.5') => '<input class="form-control" type="number" step="' . $step . '" name="' . $name . '" value="' . $h($cfg[$name]) . '">';
$src = fn($demo) => $demo ? 'نمونه (DEMO)' : 'فایل واردشده';
?>
<div class="db fc-set" dir="rtl" lang="fa">
  <p><a href="<?php echo $h($root . '/front/dashboard.php?page=forecast'); ?>">← بازگشت به پیش‌بینی</a></p>
  <h2>تنظیمات پیش‌بینی زیرساخت</h2>

  <form method="post" action="<?php echo $h($self); ?>">
    <input type="hidden" name="action" value="save">
    <section class="db-card">
      <h3>عمر مجاز تجهیزات (سال)</h3>
      <p class="fc-help">دستگاهی که عمرش (از تاریخ شروع به کار، یا تاریخ تحویل/خرید در اطلاعات مالی) تا ۱۲ ماه آینده به این حد برسد، نیازمند نوسازی شمرده می‌شود.</p>
      <div class="fc-grid">
        <?php echo $field('سرور', $num('fc_age_server')) . $field('فضای ذخیره‌سازی', $num('fc_age_storage')) . $field('تجهیزات شبکه', $num('fc_age_network')); ?>
      </div>
      <h3>کلاسترهای مجازی</h3>
      <div class="fc-grid">
        <?php echo $field('حداکثر بهره‌برداری هدف (٪)', $num('fc_target_util', '1'), 'کمبود ظرفیت بر اساس این سقف حساب می‌شود (پیش‌فرض ۸۰٪).'); ?>
      </div>
      <p><button class="btn btn-primary" type="submit">ذخیره تنظیمات</button></p>
    </section>
  <?php Html::closeForm(); ?>

  <section class="db-card">
    <h3>VirOPS: کلاسترهای مجازی (فایل Excel)</h3>
    <p class="fc-help">برای هر کلاستر یک ردیف: تعداد هاست و VM، ظرفیت و مصرف فعلی CPU (GHz)، RAM (GB) و فضای دیتااستور (TB)،
      و رشد ماهانه مصرف هر کدام (اختیاری). این اعداد را می‌توانید از گزارش Capacity در VirOPS خروجی بگیرید.
      سرستون فارسی یا انگلیسی پذیرفته می‌شود؛ اگر واحد سرستون MHz یا GB باشد خودکار تبدیل می‌شود. هر بار وارد کردن، کلاسترهای واردشده قبلی را جایگزین می‌کند.
      <a href="<?php echo $h($template('VIROPS_TEMPLATE.xlsx')); ?>">دانلود نمونه فایل (با داده نمونه)</a></p>
    <p class="fc-help">فایل فعلی: <b><?php echo $h($cfg['virops_file'] ?: '—'); ?></b> <?php echo $cfg['virops_imported'] ? '(' . $h($cfg['virops_imported']) . ')' : ''; ?></p>
    <form method="post" enctype="multipart/form-data" action="<?php echo $h($self); ?>" class="fc-inline">
      <input type="hidden" name="action" value="virops_import">
      <input class="form-control" type="file" name="virops" accept=".xlsx,.xls,.csv,.txt" required>
      <button class="btn btn-primary" type="submit">وارد کردن</button>
    <?php Html::closeForm(); ?>
    <?php if ($clusters) { ?>
      <table class="db-tbl"><thead><tr><th>کلاستر</th><th>هاست</th><th>VM</th><th>CPU مصرف / ظرفیت (GHz)</th><th>RAM مصرف / ظرفیت (GB)</th><th>فضا مصرف / ظرفیت (TB)</th><th>رشد ماهانه CPU / RAM / فضا</th><th>منبع</th></tr></thead><tbody>
      <?php foreach ($clusters as $c) {
          echo '<tr><td>' . $h($c['cluster']) . '</td><td>' . (int) $c['hosts'] . '</td><td>' . (int) $c['vms'] . '</td><td>'
            . $h(round($c['cpu_use_ghz']) . ' / ' . round($c['cpu_cap_ghz'])) . '</td><td>' . $h(round($c['ram_use_gb']) . ' / ' . round($c['ram_cap_gb'])) . '</td><td>'
            . $h(round($c['sto_use_tb'], 1) . ' / ' . round($c['sto_cap_tb'], 1)) . '</td><td>'
            . $h(round($c['cpu_growth_ghz'], 1) . ' / ' . round($c['ram_growth_gb']) . ' / ' . round($c['sto_growth_tb'], 1)) . '</td><td>' . $src($c['source'] === 'demo') . '</td></tr>';
      } ?>
      </tbody></table>
    <?php } else { echo '<p class="fc-help">هنوز کلاستری وارد نشده است.</p>'; } ?>
    <?php if ($imported_clusters) { ?>
      <form method="post" action="<?php echo $h($self); ?>" onsubmit="return confirm('کلاسترهای واردشده حذف شود؟');">
        <button class="btn btn-outline-danger" name="action" value="virops_delete" type="submit">حذف کلاسترهای واردشده</button>
      <?php Html::closeForm(); ?>
    <?php } ?>
  </section>

  <section class="db-card">
    <h3>iCare: سوابق تحویل (فایل Excel)</h3>
    <p class="fc-help">خروجی Excel یا CSV درخواست‌های تحویل‌شده iCare را وارد کنید. ستون‌ها با سرستون فارسی یا انگلیسی شناخته می‌شوند:
      تاریخ (شمسی یا میلادی) یا سال، نوع منبع (سرور، ذخیره‌سازی، شبکه، GPU)، تعداد، و در صورت وجود واحد (TB/GB)، شماره درخواست، نوع درخواست، پروژه و شرح.
      هر بار وارد کردن، سوابق واردشده قبلی را جایگزین می‌کند.
      <a href="<?php echo $h($template('ICARE_TEMPLATE.xlsx')); ?>">دانلود نمونه فایل (با داده نمونه)</a></p>
    <p class="fc-help">فایل فعلی: <b><?php echo $h($cfg['icare_file'] ?: '—'); ?></b> <?php echo $cfg['icare_imported'] ? '(' . $h($cfg['icare_imported']) . ')' : ''; ?></p>
    <form method="post" enctype="multipart/form-data" action="<?php echo $h($self); ?>" class="fc-inline">
      <input type="hidden" name="action" value="import">
      <input class="form-control" type="file" name="icare" accept=".xlsx,.xls,.csv,.txt" required>
      <button class="btn btn-primary" type="submit">وارد کردن</button>
    <?php Html::closeForm(); ?>
    <?php if ($years) { ?>
      <table class="db-tbl"><thead><tr><th>سال میلادی</th><th>ردیف‌ها</th><th>جمع مقدار</th><th>منبع</th></tr></thead><tbody>
      <?php foreach ($years as $y) {
          echo '<tr><td>' . (int) $y['year'] . '</td><td>' . (int) $y['n'] . '</td><td>' . $h(round((float) $y['qty'], 1)) . '</td><td>' . $src((bool) $y['is_demo']) . '</td></tr>';
      } ?>
      </tbody></table>
    <?php } ?>
    <?php if ($imported_years) { ?>
      <form method="post" action="<?php echo $h($self); ?>" onsubmit="return confirm('سوابق واردشده iCare حذف شود؟');">
        <button class="btn btn-outline-danger" name="action" value="icare_delete" type="submit">حذف سوابق واردشده</button>
      <?php Html::closeForm(); ?>
    <?php } ?>
    <p class="fc-help">وقتی فایل واقعی وارد شود، داده نمونه همان بخش در پیش‌بینی حساب نمی‌شود. داده نمونه (DEMO) با دستور <code dir="ltr">php bin/console plugins:projectreports:demo</code> ساخته و با <code dir="ltr">--remove</code> حذف می‌شود.</p>
  </section>
</div>
<?php
Html::footer();
