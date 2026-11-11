<?php

/**
 * Install / update: creates the LOP, LOM parts list and LOM order tables.
 */
function plugin_projectreports_install()
{
    /** @var DBmysql $DB */
    global $DB;
    $migration = new Migration(PLUGIN_PROJECTREPORTS_VERSION);
    // 1.19.0: standard kits and the automatic BOQ are removed (the Price check uses the LOM parts lists)
    $DB->dropTable('glpi_plugin_projectreports_kits', true);
    Config::deleteConfigurationValues('plugin:projectreports', ['autoboq_enabled', 'plan_budget_new', 'plan_budget_expansion',
        'plan_budget_modernization', 'price_check_1100']);
    PluginProjectreportsLop::install();
    PluginProjectreportsLompart::install();
    PluginProjectreportsLomorder::install();
    PluginProjectreportsPlan::install(); // 1.22.13: LOM plans checked against budgets
    PluginProjectreportsDemand::install(); // 1.22.16: approved requests linked to a budget
    $migration->executeMigration();
    PluginProjectreportsDashboard::removeAll(); // 1.18.1: no more dashboards or widgets
    return true;
}

function plugin_projectreports_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;
    $DB->dropTable('glpi_plugin_projectreports_kits', true);
    PluginProjectreportsLop::uninstall();
    PluginProjectreportsLompart::uninstall();
    PluginProjectreportsLomorder::uninstall();
    PluginProjectreportsPlan::uninstall();
    PluginProjectreportsDemand::uninstall();
    PluginProjectreportsDashboard::removeAll();
    return true;
}

/**
 * A form answer set was linked to the ticket it created: if one of the answers
 * is a GLPI project (an "Item" question of type Project), link the ticket to that project.
 */
function plugin_projectreports_link_form_project(CommonDBTM $link)
{
    if (($link->fields['itemtype'] ?? '') !== Ticket::class) {
        return;
    }
    try {
        $answers_set = new Glpi\Form\AnswersSet();
        if (!$answers_set->getFromDB((int) $link->fields['forms_answerssets_id'])) {
            return;
        }
        foreach ($answers_set->getAnswers() as $answer) {
            $raw = $answer->getRawAnswer();
            if (
                is_array($raw)
                && ($raw['itemtype'] ?? '') === Project::class
                && (int) ($raw['items_id'] ?? 0) > 0
            ) {
                $data = ['itemtype' => Ticket::class, 'items_id' => (int) $link->fields['items_id'], 'projects_id' => (int) $raw['items_id']];
                $itil_project = new Itil_Project();
                if (!$itil_project->getFromDBByCrit($data)) {
                    $itil_project->add($data, [], false);
                }
            }
        }
    } catch (Throwable $e) {
        // Never block ticket creation because of the report link.
        trigger_error('Project reports: could not link ticket to project: ' . $e->getMessage(), E_USER_WARNING);
    }
}

