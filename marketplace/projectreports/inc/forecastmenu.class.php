<?php

/**
 * Menu entry "Setup > Forecast data": the DCIM, VirOPS and iCare Excel imports behind the forecast tab of the
 * Reporting dashboard. Administrators only (Setup > General UPDATE); not linked from the dashboard itself.
 */
class PluginProjectreportsForecastmenu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Forecast data';
    }

    public static function getIcon()
    {
        return 'ti ti-file-spreadsheet';
    }

    public static function canView(): bool
    {
        return PluginProjectreportsForecast::canConfigure();
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/projectreports/front/forecast.php',
            'icon'  => self::getIcon(),
        ];
    }
}
