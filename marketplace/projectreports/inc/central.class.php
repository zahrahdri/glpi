<?php

/**
 * "Reporting dashboard" tab on GLPI's Home page (next to Dashboard, Personal View, Group View, Global View).
 * The tab shows Tools > Reporting dashboard inside a frame (front/dashboard.php?embed=1: no GLPI menu around it),
 * so its tabs, filters and charts work exactly as on its own page. Links to GLPI records open in the main window,
 * and the frame grows with its content.
 */
class PluginProjectreportsCentral extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Central && PluginProjectreportsDashboardmenu::canView()) {
            return self::createTabEntry('Reporting dashboard', 0, null, PluginProjectreportsDashboardmenu::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        if (!$item instanceof Central || !PluginProjectreportsDashboardmenu::canView()) {
            return false;
        }
        $src = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/dashboard.php?embed=1';
        echo '<iframe id="pr-home-dashboard" src="' . htmlescape($src) . '" title="Reporting dashboard"'
            . ' style="width:100%;height:1400px;border:0;display:block;background:transparent" loading="eager"></iframe>';
        echo Html::scriptBlock(<<<'JS'
            (function () {
                var f = document.getElementById('pr-home-dashboard');
                if (!f || window.prHomeDashboard) { return; }
                window.prHomeDashboard = true;
                window.addEventListener('message', function (e) {
                    var fr = document.getElementById('pr-home-dashboard');
                    if (!fr || e.source !== fr.contentWindow || !e.data || e.data.type !== 'pr-dashboard-height') { return; }
                    fr.style.height = Math.max(600, Math.ceil(e.data.height)) + 'px';
                });
            })();
JS);
        return true;
    }
}
