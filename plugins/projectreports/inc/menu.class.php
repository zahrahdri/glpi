<?php

/**
 * Menu entry "Tools > Project reports".
 * Visibility follows native GLPI rights: Projects, Budgets or Data centers (read).
 */
class PluginProjectreportsMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Project reports';
    }

    public static function getIcon()
    {
        return 'ti ti-chart-bar';
    }

    public static function canView(): bool
    {
        return self::canSeeRequests() || self::canSeeMoney() || self::canSeeRacks();
    }

    /** Lists every ticket of every project, so it needs the "see all" rights, not "my tickets" only. */
    public static function canSeeRequests(): bool
    {
        return Session::haveRight(Project::$rightname, Project::READALL)
            && Session::haveRight(Ticket::$rightname, Ticket::READALL);
    }

    /** Shows every project's budget, so it needs "see all projects" and budget read. */
    public static function canSeeMoney(): bool
    {
        return Session::haveRight(Project::$rightname, Project::READALL)
            && Session::haveRight(Budget::$rightname, READ);
    }

    public static function canSeeRacks(): bool
    {
        return Datacenter::canView();
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/projectreports/front/reports.php',
            'icon'  => self::getIcon(),
        ];
    }
}
