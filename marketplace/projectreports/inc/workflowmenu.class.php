<?php

/**
 * Menu entry "Setup > Request workflow" (administrators): the Demand-to-Delivery workflow with the procurement process
 * in detail (order, purchase, warehouse inbound). Requesters only see the summary, through the Workflow button
 * on the request forms.
 */
class PluginProjectreportsWorkflowmenu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Request workflow';
    }

    public static function getIcon()
    {
        return 'ti ti-route';
    }

    public static function canView(): bool
    {
        return PluginProjectreportsForecast::canConfigure();
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/projectreports/front/workflow.php',
            'icon'  => self::getIcon(),
        ];
    }
}
