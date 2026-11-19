<?php

/**
 * Tools > Reporting dashboard > پیش‌بینی > تنظیمات پیش‌بینی (administrators: Setup > General UPDATE):
 *   - age limit per device type and the target use of the VM clusters,
 *   - the VirOPS connection (test, sync now),
 *   - the iCare history: import an Excel / CSV export, or delete it.
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

if ($action === 'save') {
    $v = [];
    foreach (['fc_age_server', 'fc_age_storage', 'fc_age_network'] as $k) {
        $v[$k] = max(1, min(30, (float) ($_POST[$k] ?? 0)));
    }
    $v['fc_target_util'] = max(30, min(100, (float) ($_POST['fc_target_util'] ?? 80)));
    $v['virops_url'] = rtrim(trim((string) ($_POST['virops_url'] ?? '')), '/');
    $v['virops_user'] = trim((string) ($_POST['virops_user'] ?? ''));
    $v['virops_authsource'] = trim((string) ($_POST['virops_authsource'] ?? ''));
    $v['virops_verify_ssl'] = !empty($_POST['virops_verify_ssl']) ? 1 : 0;
    if ($v['virops_url'] !== '' && !preg_match('#^https?://#i', $v['virops_url'])) {
        $note('VirOPS address must start with https://', false);
        Html::redirect($self);
    }
    $pw = (string) ($_POST['virops_password'] ?? '');
    if ($pw !== '' && $pw !== '********') {
        $v['virops_password'] = $pw;
    } elseif (!empty($_POST['virops_password_clear'])) {
        $v['virops_password'] = '';
    }
    PluginProjectreportsForecast::setConfig($v);
    $note('تنظیمات ذخیره شد.');
    Html::redirect($self);
}
if ($action === 'test' || $action === 'sync') {
    try {
        $note($action === 'test' ? PluginProjectreportsVirops::test() : sprintf('VirOPS: %d کلاستر خوانده شد.', PluginProjectreportsVirops::sync()));
    } catch (Throwable $e) {
        $note('VirOPS: ' . $e->getMessage(), false);
    }
    Html::redirect($self);
}
if ($action === 'import') {
    if (empty($_FILES['icare']['tmp_name']) || !is_uploaded_file($_FILES['icare']['tmp_name'])) {
        $note('فایلی انتخاب نشده است.', false);
        Html::redirect($self);
    }
    try {
        $name = (string) $_FILES['icare']['name'];
        $read = PluginProjectreportsForecast::readIcare($_FILES['icare']['tmp_name'], $name);
        if (!$read['rows']) {
            throw new RuntimeException('هیچ ردیف قابل استفاده‌ای در فایل نبود (تاریخ یا سال و تعداد لازم است).');
        }
        $n = PluginProjectreportsForecast::saveIcare($read['rows'], $name);
        $note(sprintf('%d ردیف iCare وارد شد%s. ستون‌های شناخته‌شده: %s', $n, $read['skipped'] ? sprintf(' (%d ردیف بدون تاریخ یا تعداد کنار گذاشته شد)', $read['skipped']) : '', implode(', ', $read['columns'])));
    } catch (Throwable $e) {
        $note('iCare: ' . $e->getMessage(), false);
    }
    Html::redirect($self);
}
if ($action === 'icare_delete') {
    PluginProjectreportsForecast::deleteIcare();
    $note('سوابق واردشده iCare حذف شد.');
    Html::redirect($self);
}

/** @var DBmysql $DB */
global $DB;
$cfg = PluginProjectreportsForecast::config();
$years = iterator_to_array($DB->request(['SELECT' => ['year', 'is_demo', 'COUNT' => 'id AS n', 'SUM' => 'quantity AS qty'],
    'FROM' => PluginProjectreportsForecast::ICARE, 'GROUPBY' => ['year', 'is_demo'], 'ORDER' => 'year']), false);
$clusters = iterator_to_array($DB->request(['FROM' => PluginProjectreportsForecast::CLUSTERS, 'ORDER' => 'cluster']), false);

Html::header('Forecast settings', '', 'tools', 'PluginProjectreportsDashboardmenu');
echo '<link rel="stylesheet" href="' . $h($root . '/css/dashboard.css?v=' . @filemtime(__DIR__ . '/../public/css/dashboard.css')) . '">';
$field = fn($label, $input, $help = '') => '<label class="fc-f"><span>' . $h($label) . '</span>' . $input . ($help ? '<small>' . $h($help) . '</small>' : '') . '</label>';
$num = fn($name, $step = '0.5') => '<input class="form-control" type="number" step="' . $step . '" name="' . $name . '" value="' . $h($cfg[$name]) . '">';
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
    </section>
    <section class="db-card">
      <h3>اتصال VirOPS (Aria / vRealize Operations)</h3>
      <div class="fc-grid">
        <?php
        echo $field('آدرس', '<input class="form-control" dir="ltr" name="virops_url" placeholder="https://virops.example.local" value="' . $h($cfg['virops_url']) . '">');
        echo $field('نام کاربری', '<input class="form-control" dir="ltr" name="virops_user" autocomplete="off" value="' . $h($cfg['virops_user']) . '">', 'یک حساب فقط‌خواندنی کافی است.');
        echo $field('رمز عبور', '<input class="form-control" dir="ltr" type="password" name="virops_password" autocomplete="new-password" value="' . $h($cfg['virops_password']) . '">'
            . ($cfg['virops_password'] !== '' ? '<label class="fc-chk"><input type="checkbox" name="virops_password_clear" value="1"> پاک کردن رمز</label>' : ''), 'رمزنگاری‌شده با کلید GLPI ذخیره می‌شود.');
        echo $field('منبع احراز هویت', '<input class="form-control" dir="ltr" name="virops_authsource" value="' . $h($cfg['virops_authsource']) . '">', 'Local Users یا نام منبع LDAP/AD در VirOPS');
        echo $field('بررسی گواهی SSL', '<label class="fc-chk"><input type="checkbox" name="virops_verify_ssl" value="1"' . ($cfg['virops_verify_ssl'] ? ' checked' : '') . '> فعال</label>', 'برای گواهی خودامضا خاموش کنید.');
        ?>
      </div>
      <p class="fc-help">
        آخرین همگام‌سازی: <b><?php echo $h($cfg['virops_last_sync'] ?: '—'); ?></b>
        <?php if ($cfg['virops_last_error']) { echo '<br><span class="fc-err">آخرین خطا: ' . $h($cfg['virops_last_error']) . '</span>'; } ?>
        <br>برای به‌روزرسانی روزانه: <code dir="ltr">php bin/console plugins:projectreports:virops-sync</code>
      </p>
    </section>
    <p><button class="btn btn-primary" type="submit">ذخیره تنظیمات</button></p>
  <?php Html::closeForm(); ?>

  <section class="db-card">
    <h3>VirOPS: کلاسترها</h3>
    <form method="post" action="<?php echo $h($self); ?>" class="fc-inline">
      <button class="btn btn-outline-secondary" name="action" value="test" type="submit">آزمایش اتصال</button>
      <button class="btn btn-outline-primary" name="action" value="sync" type="submit">همگام‌سازی اکنون</button>
    <?php Html::closeForm(); ?>
    <?php if ($clusters) { ?>
      <table class="db-tbl"><thead><tr><th>کلاستر</th><th>هاست</th><th>VM</th><th>CPU (GHz)</th><th>RAM (GB)</th><th>فضا (TB)</th><th>منبع</th><th>زمان</th></tr></thead><tbody>
      <?php foreach ($clusters as $c) {
          echo '<tr><td>' . $h($c['cluster']) . '</td><td>' . (int) $c['hosts'] . '</td><td>' . (int) $c['vms'] . '</td><td>'
            . $h(round($c['cpu_use_ghz']) . ' / ' . round($c['cpu_cap_ghz'])) . '</td><td>' . $h(round($c['ram_use_gb']) . ' / ' . round($c['ram_cap_gb'])) . '</td><td>'
            . $h(round($c['sto_use_tb'], 1) . ' / ' . round($c['sto_cap_tb'], 1)) . '</td><td>' . ($c['source'] === 'demo' ? 'نمونه (DEMO)' : 'VirOPS') . '</td><td>' . $h($c['date_sync']) . '</td></tr>';
      } ?>
      </tbody></table>
    <?php } else { echo '<p class="fc-help">هنوز داده‌ای خوانده نشده است.</p>'; } ?>
  </section>

  <section class="db-card">
    <h3>سوابق تحویل iCare</h3>
    <p class="fc-help">خروجی Excel یا CSV درخواست‌های تحویل‌شده iCare را وارد کنید. ستون‌ها با سرستون فارسی یا انگلیسی شناخته می‌شوند:
      تاریخ (شمسی یا میلادی) یا سال، نوع منبع (سرور، ذخیره‌سازی، شبکه، GPU)، تعداد، و در صورت وجود واحد (TB/GB)، شماره درخواست، نوع درخواست، پروژه و شرح.
      هر بار وارد کردن، سوابق واردشده قبلی را جایگزین می‌کند.
      <a href="<?php echo $h($root . '/templates/ICARE_TEMPLATE.csv?v=' . @filemtime(__DIR__ . '/../public/templates/ICARE_TEMPLATE.csv')); ?>">دانلود نمونه فایل</a></p>
    <p class="fc-help">فایل فعلی: <b><?php echo $h($cfg['icare_file'] ?: '—'); ?></b> <?php echo $cfg['icare_imported'] ? '(' . $h($cfg['icare_imported']) . ')' : ''; ?></p>
    <form method="post" enctype="multipart/form-data" action="<?php echo $h($self); ?>" class="fc-inline">
      <input type="hidden" name="action" value="import">
      <input class="form-control" type="file" name="icare" accept=".csv,.xlsx,.xls,.txt" required>
      <button class="btn btn-primary" type="submit">وارد کردن</button>
    <?php Html::closeForm(); ?>
    <?php if ($years) { ?>
      <table class="db-tbl"><thead><tr><th>سال میلادی</th><th>ردیف‌ها</th><th>جمع مقدار</th><th>منبع</th></tr></thead><tbody>
      <?php foreach ($years as $y) {
          echo '<tr><td>' . (int) $y['year'] . '</td><td>' . (int) $y['n'] . '</td><td>' . $h(round((float) $y['qty'], 1)) . '</td><td>' . ($y['is_demo'] ? 'نمونه (DEMO)' : 'iCare') . '</td></tr>';
      } ?>
      </tbody></table>
      <form method="post" action="<?php echo $h($self); ?>" onsubmit="return confirm('سوابق واردشده iCare حذف شود؟');">
        <button class="btn btn-outline-danger" name="action" value="icare_delete" type="submit">حذف سوابق واردشده</button>
      <?php Html::closeForm(); ?>
    <?php } ?>
  </section>
</div>
<?php
Html::footer();
