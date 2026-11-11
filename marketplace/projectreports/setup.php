<?php

/**
 * Project reports - dashboards for GLPI projects: team requests, budget & cost,
 * forecast & renewals, and rack view. It writes in three places only: linking tickets created by a form to the
 * project chosen in that form (Ticket > Projects tab); Management > Price lists (LOP); the LOM catalog and its parts
 * lists; and the ticket's Price check tab, where such a user prices a LOM parts list with every supplier's LOP and
 * creates the draft BOQ with the chosen supplier.
 */

define('PLUGIN_PROJECTREPORTS_VERSION', '1.22.28');
define('PLUGIN_PROJECTREPORTS_MIN_GLPI', '11.0.0');
define('PLUGIN_PROJECTREPORTS_MAX_GLPI', '11.0.99');

function plugin_init_projectreports()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['projectreports'] = true;

    // When a form creates a ticket, link the ticket to the project chosen in the form.
    $PLUGIN_HOOKS['item_add']['projectreports'] = [
        'Glpi\\Form\\Destination\\AnswersSet_FormDestinationItem' => 'plugin_projectreports_link_form_project',
    ];

    // Ticket › Price check tab (capacity requests only, users who may create orders)
    Plugin::registerClass('PluginProjectreportsPricecheck', ['addtabon' => ['Ticket']]);
    // Orders › order › Add from LOP tab
    if (class_exists('PluginOrderOrder')) {
        Plugin::registerClass('PluginProjectreportsOrderlop', ['addtabon' => ['PluginOrderOrder']]);
        Plugin::registerClass('PluginProjectreportsOrderexport', ['addtabon' => ['PluginOrderOrder']]);
    }

    if (Session::getLoginUserID()) {
        $menus = [];
        if (PluginProjectreportsMenu::canView()) {
            $menus[] = 'PluginProjectreportsMenu';
        }
        if (PluginProjectreportsLom::canView()) {
            $menus[] = 'PluginProjectreportsLom';
        }
        $add = $menus ? ['tools' => $menus] : [];
        // Management › Price lists (LOP), next to the Orders plugin
        if (PluginProjectreportsLopmenu::canView()) {
            $add['management'] = ['PluginProjectreportsLopmenu'];
        }
        if ($add) {
            $PLUGIN_HOOKS['menu_toadd']['projectreports'] = $add;
        }
    }
}

function plugin_version_projectreports()
{
    return [
        'name'         => 'Project reports',
        'version'      => PLUGIN_PROJECTREPORTS_VERSION,
        'author'       => 'Project reports',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PROJECTREPORTS_MIN_GLPI,
                'max' => PLUGIN_PROJECTREPORTS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_projectreports_check_prerequisites()
{
    return true;
}

function plugin_projectreports_check_config($verbose = false)
{
    return true;
}
