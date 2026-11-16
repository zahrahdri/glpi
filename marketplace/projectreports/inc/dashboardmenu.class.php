<?php

/**
 * Menu entry "Tools > Reporting dashboard" (the management dashboard, separate from "Project reports").
 * Same visibility as Project reports: it shows the same data.
 */
class PluginProjectreportsDashboardmenu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Reporting dashboard';
    }

    public static function getIcon()
    {
        return 'ti ti-layout-dashboard';
    }

    public static function canView(): bool
    {
        return PluginProjectreportsMenu::canView();
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/projectreports/front/dashboard.php',
            'icon'  => self::getIcon(),
        ];
    }
}
