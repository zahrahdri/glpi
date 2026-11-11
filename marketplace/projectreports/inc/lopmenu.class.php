<?php

/**
 * Menu entry "Management › Price lists (LOP)", next to the Orders plugin: import and view the suppliers' LOPs.
 * Shown to users who may create orders and catalog references.
 */
class PluginProjectreportsLopmenu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Price lists (LOP)';
    }

    public static function getIcon()
    {
        return 'ti ti-report-money';
    }

    public static function canView(): bool
    {
        return PluginProjectreportsBoqimport::canImport();
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(),
            'page'  => '/plugins/projectreports/front/lop.php',
            'icon'  => self::getIcon(),
        ];
    }
}
