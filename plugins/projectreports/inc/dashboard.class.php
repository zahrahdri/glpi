<?php

/**
 * Clean-up of the dashboards of versions 1.11 to 1.18.0, which added four ready-made "Project reports" dashboards and
 * "Project reports" widgets to GLPI's dashboards. Since 1.18.1 the plugin has no dashboards or widgets: installing or
 * updating it removes them, and users whose default dashboard was one of them go back to GLPI's default.
 */
class PluginProjectreportsDashboard
{
    private const KEYS = ['project-reports', 'project-reports-requests', 'project-reports-forecast', 'project-reports-racks'];

    public static function removeAll(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!$DB->tableExists('glpi_dashboards_dashboards')) {
            return;
        }
        $DB->delete('glpi_dashboards_items', ['card_id' => ['LIKE', 'projectreports\_%']]);
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_dashboards_dashboards', 'WHERE' => ['key' => self::KEYS]]) as $row) {
            $DB->delete('glpi_dashboards_items', ['dashboards_dashboards_id' => $row['id']]);
            $DB->delete('glpi_dashboards_rights', ['dashboards_dashboards_id' => $row['id']]);
            if ($DB->tableExists('glpi_dashboards_filters')) {
                $DB->delete('glpi_dashboards_filters', ['dashboards_dashboards_id' => $row['id']]);
            }
            $DB->delete('glpi_dashboards_dashboards', ['id' => $row['id']]);
        }
        // nobody keeps a removed dashboard as their default
        foreach (['default_dashboard_central', 'default_dashboard_assets', 'default_dashboard_helpdesk', 'default_dashboard_mini_ticket'] as $f) {
            if ($DB->fieldExists('glpi_users', $f)) {
                $DB->update('glpi_users', [$f => null], [$f => self::KEYS]);
            }
            $DB->update('glpi_configs', ['value' => 'central'], ['context' => 'core', 'name' => $f, 'value' => self::KEYS]);
        }
    }
}
