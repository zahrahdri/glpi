<?php

/**
 * Demo data for the Project reports, so every chart has something to show:
 * projects, budgets, servers, requests, a demo data center with racks, and
 * (with the Orders management plugin) BOQ orders.
 *
 *   php bin/console plugins:projectreports:demo            create demo data
 *   php bin/console plugins:projectreports:demo --remove   delete all demo data again
 *
 * Everything it creates is named with "DEMO" ("[DEMO] ..." for tickets) and is removed by --remove.
 * It writes straight to the tables, so no e-mail notifications are sent.
 */

use Glpi\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PluginProjectreportsDemocommand extends AbstractCommand
{
    private array $refs = [];
    private array $order_state = [];
    private int $tax_id = 0;
    private int $supplier = 0;

    private const TEAMS = ['DEMO NOC', 'DEMO Core Network', 'DEMO Data Center Ops', 'DEMO IT Applications', 'DEMO Security', 'DEMO Transmission'];

    private const CATEGORIES = [
        'DEMO Capacity expansion'          => [2, 'Add {n} ports on uplink switch'],
        'DEMO New server'                  => [2, 'New server for {p}'],
        'DEMO Storage request'             => [2, 'Extend SAN LUN by {n} TB'],
        'DEMO Network change'              => [2, 'Firewall rule change for {p}'],
        'DEMO Access request'              => [2, 'VPN access for vendor ({p})'],
        'DEMO Hardware incident'           => [1, 'Disk failure in storage array'],
        'DEMO Link / uplink incident'      => [1, 'Uplink flapping on site'],
        'DEMO License / support renewal'   => [2, 'Renew support contract for {p}'],
    ];

    // name => [share of budget already allocated, start (months ago), length (months), weight of requests, assets]
    // The budget value is computed at the end so each project lands in a different state.
    private const PROJECTS = [
        'DEMO 5G-CORE'         => [0.62, 12, 24, 4, 14],
        'DEMO BSS-UPGRADE'     => [0.88, 9, 12, 3, 10],
        'DEMO CRM'             => [1.04, 20, 36, 2, 8],
        'DEMO DC-EXPANSION'    => [0.55, 7, 16, 3, 12],
    ];

    // BOQ catalog: reference => [item type, unit price]
    private const REFERENCES = [
        'DEMO-SRV-2U  Rack server 2U, 2x CPU, 512 GB'   => ['Computer', 18500],
        'DEMO-SW-25G  25G top-of-rack switch'           => ['NetworkEquipment', 9800],
        'DEMO-SSD-3T8 3.84 TB NVMe SSD'                 => ['PluginOrderOther', 650],
        'DEMO-NIC-25G 25GbE dual-port NIC'              => ['PluginOrderOther', 480],
        'DEMO-SFP-25G SFP28 25G SR transceiver'         => ['PluginOrderOther', 95],
        'DEMO-SVC-INS Installation and commissioning'   => ['PluginOrderOther', 15000],
        'DEMO-GPU-80G 80 GB data-center GPU'            => ['PluginOrderOther', 32000],
    ];

    // Other suppliers' LOPs (supplier => [LOP name, part => price]): B is complete, C has only the GPU
    private const OTHER_LOPS = [
        'DEMO Supplier B' => ['DEMO B LOP 2026-10', ['DEMO-SRV-2U' => 19800, 'DEMO-SW-25G' => 9500, 'DEMO-SSD-3T8' => 700,
            'DEMO-NIC-25G' => 450, 'DEMO-SFP-25G' => 110, 'DEMO-SVC-INS' => 14000, 'DEMO-GPU-80G' => 29500]],
        'DEMO Supplier C' => ['DEMO C GPU offer', ['DEMO-GPU-80G' => 25000]],
    ];

    // Standard kits for the automatic BOQ: name => [category, resource type, units per kit, [reference index => qty per kit]]
    private const KITS = [
        'DEMO Compute server kit'      => ['Compute', 'Physical server', 1, [0 => 1, 2 => 4, 3 => 2, 4 => 4]],
        'DEMO Virtualisation host kit' => ['Compute', 'Virtual machine', 10, [0 => 1, 2 => 6, 3 => 2]],
        'DEMO SAN shelf 10 TB'         => ['Storage', '', 10, [2 => 3, 4 => 2]],
        'DEMO GPU node (4 GPUs)'       => ['GPU', '', 4, [0 => 1, 6 => 4]],
    ];

    // --mix: the requested capacity requests: [type, category, resource type, quantity, project (DEMO name) or new:name, approval, title]
    private const MIX = [
        ['Expansion', 'Compute', 'Physical server', 4, 'DEMO CRM', 0, 'Waiting', 'CRM expansion: 4 servers'],
        ['Expansion', 'Compute', 'Physical server', 4, 'DEMO HCBS', 0, 'Approved', 'HCBS expansion: 4 servers'],
        ['New project', 'Compute', 'Physical server', 6, 'new:AI platform', 0, 'Waiting', 'AI platform: 6 servers'],
        ['Modernization', 'Compute', 'Physical server', 8, 'DEMO XIO', 0, 'Waiting', 'XIO modernization: 8 servers'],
    ];

    // Capacity requests: [type, category, resource type, quantity, project (DEMO name) or new project name, approval, title]
    private const DEMANDS = [
        ['Expansion', 'Compute', 'Physical server', 12, 'DEMO 5G-CORE', 230000, 'Approved', 'UPF scale-out for 5G core'],
        ['Expansion', 'Compute', 'Physical server', 6, 'DEMO BSS-UPGRADE', 100000, 'Waiting', 'Billing batch nodes'],
        ['New project', 'Compute', 'Virtual machine', 20, 'new:DEMO AI analytics', 180000, 'Waiting', 'Analytics VM farm'],
        ['Expansion', 'Storage', '', 120, 'DEMO CRM', 40000, 'Approved', 'CRM archive storage'],
        ['Modernization', 'Compute', 'Physical server', 10, 'DEMO DC-EXPANSION', 150000, 'Waiting', 'Replace end-of-life hypervisors'],
        ['New project', 'GPU', '', 16, 'new:DEMO LLM platform', 0, 'Waiting', 'LLM inference cluster'],
        ['Expansion', 'Storage', '', 40, 'DEMO 5G-CORE', 15000, 'Refused', 'CDR retention extension'],
        ['Expansion', 'Compute', 'Container / Kubernetes', 8, 'DEMO CRM', 60000, 'Waiting', 'CRM microservices nodes'],
        ['Modernization', 'Storage', '', 60, 'DEMO BSS-UPGRADE', 30000, 'Approved', 'Move billing DB to flash'],
        ['Expansion', 'GPU', '', 8, 'DEMO DC-EXPANSION', 140000, 'Waiting', 'Video analytics GPUs'],
        ['Release old resources', '', '', 0, 'DEMO CRM', 0, 'Approved', 'Return old CRM test VMs'],
        ['New project', 'Compute', 'Physical server', 20, 'new:DEMO Edge sites', 300000, 'Refused', 'Edge computing pilot'],
    ];

    // BOQs per project: [name suffix, state key, month offset (0..1 of elapsed time, >1 = future), [ref index => qty]]
    private const BOQS = [
        'DEMO 5G-CORE' => [
            ['PH1 core servers', 'delivered', 0.10, [0 => 8, 1 => 2, 4 => 16, 5 => 1]],
            ['PH2 storage expansion', 'approved', 0.55, [0 => 4, 2 => 24, 3 => 8]],
            ['PH3 user-plane scale-out', 'draft', 1.05, [0 => 10, 1 => 2, 4 => 20]],
        ],
        'DEMO BSS-UPGRADE' => [
            ['App tier', 'paid', 0.15, [0 => 6, 3 => 6, 5 => 1]],
            ['DB tier', 'partial', 0.60, [0 => 4, 2 => 32]],
            ['DR site', 'waiting', 1.02, [0 => 4, 1 => 2]],
        ],
        'DEMO CRM' => [
            ['Site 1', 'delivered', 0.20, [0 => 5, 1 => 2, 4 => 12, 5 => 1]],
            ['Site 2', 'approved', 0.70, [0 => 3, 2 => 12]],
            ['Old quote (replaced)', 'canceled', 0.40, [0 => 10]],
            ['Analytics add-on', 'draft', 1.03, [0 => 3, 2 => 8]],
        ],
        'DEMO DC-EXPANSION' => [
            ['Racks and network', 'delivered', 0.20, [1 => 6, 4 => 48, 5 => 1]],
            ['Compute', 'approved', 0.70, [0 => 6, 3 => 12]],
        ],
    ];

    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:projectreports:demo');
        $this->setDescription('Create (or with --remove, delete) demo projects, budgets, assets and requests for the Project reports.');
        $this->addOption('remove', null, InputOption::VALUE_NONE, 'Delete all demo data created by this command');
        $this->addOption('requests', null, InputOption::VALUE_REQUIRED, 'Number of demo requests to create', 120);
        $this->addOption('mix', null, InputOption::VALUE_NONE, 'Only the requested capacity requests: CRM and HCBS expansion, AI platform new project, XIO modernization');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->remove($output);
        if ($input->getOption('remove')) {
            $output->writeln('<info>All demo data removed.</info>');
            return 0;
        }
        mt_srand(2026);
        if ($input->getOption('mix')) {
            $this->createMix($output);
            $output->writeln('<info>Done. Open Tools > Project reports. Remove it later with: php bin/console plugins:projectreports:demo --remove</info>');
            return 0;
        }
        $this->create($output, max(10, (int) $input->getOption('requests')));
        $output->writeln('<info>Done. Open Tools > Project reports. Remove it later with: php bin/console plugins:projectreports:demo --remove</info>');
        return 0;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function ins(string $table, array $values): int
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->insert($table, $values);
        return (int) $DB->insertId();
    }

    private function create(OutputInterface $output, int $nb_requests): void
    {
        $now = $this->now();
        $today = new DateTime('today');

        // teams and categories
        $teams = [];
        foreach (self::TEAMS as $t) {
            $teams[] = $this->ins('glpi_groups', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $t, 'completename' => $t, 'level' => 1,
                'is_requester' => 1, 'is_assign' => 1, 'is_notify' => 1, 'is_itemgroup' => 1, 'is_usergroup' => 1, 'is_manager' => 1,
                'date_creation' => $now, 'date_mod' => $now]);
        }
        $cats = [];
        foreach (self::CATEGORIES as $name => [$type, $title]) {
            $cats[$name] = $this->ins('glpi_itilcategories', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $name, 'completename' => $name,
                'level' => 1, 'is_helpdeskvisible' => 1, 'is_incident' => 1, 'is_request' => 1, 'is_problem' => 1, 'is_change' => 1,
                'date_creation' => $now, 'date_mod' => $now]);
        }
        $output->writeln(sprintf('Teams: %d, categories: %d', count($teams), count($cats)));

        // projects, budgets, budget link, assets with financial information
        $projects = [];
        $computer_model = $this->ins('glpi_computermodels', ['name' => 'DEMO Rack server 2U', 'required_units' => 2, 'power_consumption' => 900, 'weight' => 28,
            'date_creation' => $now, 'date_mod' => $now]);
        $asset_no = 0;
        $alloc = [];
        $servers = [];
        foreach (self::PROJECTS as $name => [$target, $ago, $length, $weight, $nb_assets]) {
            $start = (clone $today)->modify("first day of -$ago month");
            $end = (clone $start)->modify("+$length month")->modify('-1 day');
            $pid = $this->ins('glpi_projects', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $name, 'code' => str_replace('DEMO ', 'PRJ-', $name),
                'plan_start_date' => $start->format('Y-m-d 00:00:00'), 'plan_end_date' => $end->format('Y-m-d 00:00:00'),
                'percent_done' => 0, 'date' => $now, 'date_creation' => $now, 'date_mod' => $now]);
            $bid = $this->ins('glpi_budgets', ['entities_id' => 0, 'is_recursive' => 1, 'name' => str_replace('DEMO ', 'DEMO BUD-', $name), 'value' => 0,
                'begin_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'), 'date_creation' => $now, 'date_mod' => $now]);
            $this->ins('glpi_projectcosts', ['projects_id' => $pid, 'name' => 'DEMO Budget link', 'cost' => 0, 'budgets_id' => $bid,
                'entities_id' => 0, 'begin_date' => $start->format('Y-m-d')]);
            // a few dated service costs
            $alloc[$pid] = 0;
            for ($k = 0; $k < 2; $k++) {
                $d = (clone $start)->modify('+' . mt_rand(1, max(1, $ago - 1)) . ' month');
                $cost = mt_rand(3, 9) * 10000;
                $alloc[$pid] += $cost;
                $this->ins('glpi_projectcosts', ['projects_id' => $pid, 'name' => 'DEMO Installation services ' . ($k + 1),
                    'cost' => $cost, 'budgets_id' => $bid, 'entities_id' => 0, 'begin_date' => $d->format('Y-m-d')]);
            }
            // servers bought over the life of the project (some old ones, for renewals and refresh)
            for ($k = 0; $k < $nb_assets; $k++) {
                $asset_no++;
                $old = $k < 3;
                $buy = $old ? (clone $today)->modify('-' . mt_rand(34, 58) . ' month') : (clone $start)->modify('+' . mt_rand(0, max(0, $ago - 1)) . ' month');
                $buy->modify('+' . mt_rand(0, 25) . ' day');
                if ($buy > $today) {
                    $buy = clone $today;
                }
                $value = [9800, 18500, 18500, 45000][mt_rand(0, 3)];
                $cid = $this->ins('glpi_computers', ['entities_id' => 0, 'name' => sprintf('DEMO-SRV-%03d', $asset_no),
                    'serial' => 'DEMO' . strtoupper(substr(md5((string) $asset_no), 0, 8)), 'computermodels_id' => $computer_model,
                    'is_deleted' => 0, 'is_template' => 0, 'date_creation' => $now, 'date_mod' => $now]);
                $this->ins('glpi_infocoms', ['items_id' => $cid, 'itemtype' => 'Computer', 'entities_id' => 0,
                    'buy_date' => $buy->format('Y-m-d'), 'use_date' => $buy->format('Y-m-d'), 'warranty_date' => $buy->format('Y-m-d'),
                    'warranty_duration' => $old ? 36 : 60, 'value' => $value, 'warranty_value' => round($value * 0.08),
                    'budgets_id' => $bid, 'alert' => 4, 'date_creation' => $now, 'date_mod' => $now]);
                $this->ins('glpi_items_projects', ['itemtype' => 'Computer', 'items_id' => $cid, 'projects_id' => $pid]);
                $alloc[$pid] += $value;
                $servers[$name][] = $cid;
            }
            $projects[] = ['id' => $pid, 'name' => $name, 'weight' => $weight, 'budget' => $bid, 'target' => $target,
                'start' => $start, 'ago' => $ago];
        }
        $output->writeln(sprintf('Projects with budgets: %d, servers with financial information: %d', count($projects), $asset_no));

        $this->createBoqs($output, $projects, $alloc);
        $this->createRacks($output, $projects, $servers, $alloc);

        // budget value = allocated / target share, rounded to 10,000
        foreach ($projects as $p) {
            /** @var DBmysql $DB */
            global $DB;
            $DB->update('glpi_budgets', ['value' => max(10000, round($alloc[$p['id']] / $p['target'] / 10000) * 10000)], ['id' => $p['budget']]);
        }

        // requests
        $pool = [];
        foreach ($projects as $p) {
            $pool = array_merge($pool, array_fill(0, $p['weight'], $p));
        }
        $cat_names = array_keys(self::CATEGORIES);
        $created = 0;
        for ($i = 0; $i < $nb_requests; $i++) {
            $p = $pool[mt_rand(0, count($pool) - 1)];
            $cat = $cat_names[mt_rand(0, count($cat_names) - 1)];
            [$type, $title] = self::CATEGORIES[$cat];
            $days = mt_rand(0, 360);
            $opened = (new DateTime())->modify("-$days day")->modify('-' . mt_rand(0, 20) . ' hour');
            $urgency = [1, 2, 3, 3, 3, 4, 4, 5][mt_rand(0, 7)];
            $impact = [2, 3, 3, 4][mt_rand(0, 3)];
            $priority = ($type === 1 && $urgency === 5 && $impact >= 4) ? 6 : max(1, min(5, (int) round(($urgency + $impact) / 2)));
            $weights = $days > 60 ? [1, 2, 1, 2, 4, 10] : [4, 5, 3, 3, 1, 1];
            $status = $this->pick([1, 2, 3, 4, 5, 6], $weights);
            $solve = $status >= 5 ? (clone $opened)->modify('+' . mt_rand(2, 24 * 20) . ' hour') : null;
            if ($solve !== null && $solve > new DateTime()) {
                $solve = new DateTime();
            }
            $close = $status === 6 && $solve !== null ? (clone $solve)->modify('+1 day') : null;
            $ttr = (clone $opened)->modify('+' . [1 => 30, 2 => 15, 3 => 7, 4 => 3, 5 => 1, 6 => 1][$priority] . ' day');
            $name = '[DEMO] ' . strtr($title, ['{n}' => (string) [4, 8, 16, 20][mt_rand(0, 3)], '{p}' => str_replace('DEMO ', '', $p['name'])]);
            $tid = $this->ins('glpi_tickets', ['entities_id' => 0, 'name' => $name, 'content' => '<p>' . htmlspecialchars($name) . '</p>',
                'date' => $opened->format('Y-m-d H:i:s'), 'date_creation' => $opened->format('Y-m-d H:i:s'), 'date_mod' => $now,
                'solvedate' => $solve?->format('Y-m-d H:i:s'), 'closedate' => $close?->format('Y-m-d H:i:s'),
                'status' => $status, 'type' => $type, 'urgency' => $urgency, 'impact' => $impact, 'priority' => $priority,
                'itilcategories_id' => $cats[$cat], 'requesttypes_id' => 1, 'time_to_resolve' => $ttr->format('Y-m-d H:i:s'),
                'users_id_recipient' => 0, 'is_deleted' => 0]);
            $this->ins('glpi_itils_projects', ['itemtype' => 'Ticket', 'items_id' => $tid, 'projects_id' => $p['id']]);
            $this->ins('glpi_groups_tickets', ['tickets_id' => $tid, 'groups_id' => $teams[mt_rand(0, count($teams) - 1)], 'type' => 1]);
            $this->ins('glpi_groups_tickets', ['tickets_id' => $tid, 'groups_id' => $teams[mt_rand(0, 2)], 'type' => 2]);
            $created++;
        }
        $output->writeln(sprintf('Requests: %d', $created));

        $this->createDemands($output, $projects, $teams);
    }

    private function createBoqs(OutputInterface $output, array $projects, array &$alloc): void
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!$DB->tableExists('glpi_plugin_order_orders') || !$DB->tableExists('glpi_plugin_order_configs')) {
            $output->writeln('<comment>Orders management plugin not installed: no demo BOQs created.</comment>');
            return;
        }
        $now = $this->now();
        $cfg = $DB->request(['FROM' => 'glpi_plugin_order_configs', 'LIMIT' => 1])->current();
        $state = [
            'draft' => $cfg['order_status_draft'], 'waiting' => $cfg['order_status_waiting_approval'],
            'approved' => $cfg['order_status_approved'], 'partial' => $cfg['order_status_partially_delivred'],
            'delivered' => $cfg['order_status_completly_delivered'], 'canceled' => $cfg['order_status_canceled'],
            'paid' => $cfg['order_status_paid'],
        ];
        $committed = ['approved', 'partial', 'delivered', 'paid'];
        $tax = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_plugin_order_ordertaxes', 'WHERE' => ['name' => '20'], 'LIMIT' => 1])->current();
        $tax_id = $tax ? (int) $tax['id'] : 0;
        $this->order_state = $state;
        $this->tax_id = $tax_id;

        $supplier = $this->supplier = $this->ins('glpi_suppliers', ['entities_id' => 0, 'is_recursive' => 1, 'name' => 'DEMO Supplier', 'is_active' => 1,
            'date_creation' => $now, 'date_mod' => $now]);
        $refs = [];
        foreach (self::REFERENCES as $name => [$itemtype, $price]) {
            // like a real catalog: the name is the part number (what LOPs and kits refer to)
            $rid = $this->ins('glpi_plugin_order_references', ['entities_id' => 0, 'is_recursive' => 1, 'name' => explode(' ', $name)[0],
                'itemtype' => $itemtype, 'is_active' => 1, 'comment' => trim(substr($name, strpos($name, ' '))), 'date_mod' => $now]);
            $this->ins('glpi_plugin_order_references_suppliers', ['entities_id' => 0, 'is_recursive' => 1,
                'plugin_order_references_id' => $rid, 'suppliers_id' => $supplier, 'price_taxfree' => $price,
                'reference_code' => explode(' ', $name)[0]]);
            $refs[] = [$rid, $itemtype, $price];
            $this->refs[] = ['part' => explode(' ', $name)[0], 'desc' => trim(substr($name, strpos($name, ' '))), 'id' => $rid, 'itemtype' => $itemtype, 'price' => $price];
        }
        // the demo supplier's LOP (same prices as its catalog)
        foreach ($this->refs as $r) {
            $this->ins(PluginProjectreportsLop::TABLE, ['suppliers_id' => $supplier, 'part' => $r['part'], 'description' => $r['desc'], 'price' => $r['price'], 'date_mod' => $now]);
        }
        $info = PluginProjectreportsLop::info();
        $info[$supplier] = ['name' => 'DEMO LOP 2026-Q4', 'parts' => count(self::REFERENCES), 'imported' => $now];
        // two more suppliers so the automatic BOQ can compare: B has every part, C only GPUs
        foreach (self::OTHER_LOPS as $sname => [$lname, $prices]) {
            $sid = $this->ins('glpi_suppliers', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $sname, 'is_active' => 1,
                'date_creation' => $now, 'date_mod' => $now]);
            foreach ($prices as $part => $price) {
                $this->ins(PluginProjectreportsLop::TABLE, ['suppliers_id' => $sid, 'part' => $part, 'description' => '', 'price' => $price, 'date_mod' => $now]);
            }
            $info[$sid] = ['name' => $lname, 'parts' => count($prices), 'imported' => $now];
        }
        Config::setConfigurationValues('plugin:projectreports', ['lop_info' => json_encode($info, JSON_UNESCAPED_UNICODE)]);

        $nb_orders = $nb_lines = 0;
        $today = new DateTime('today');
        foreach ($projects as $p) {
            foreach (self::BOQS[$p['name']] ?? [] as [$suffix, $st, $at, $lines]) {
                $months = (int) round($p['ago'] * $at);
                $date = (clone $p['start'])->modify("+$months month")->modify('+' . mt_rand(1, 20) . ' day');
                if ($at < 1 && $date > $today) {
                    $date = clone $today;
                }
                $nb_orders++;
                $oid = $this->ins('glpi_plugin_order_orders', ['entities_id' => 0, 'is_recursive' => 1,
                    'name' => 'DEMO BOQ-' . str_replace('DEMO ', '', $p['name']) . ' ' . $suffix,
                    'num_order' => sprintf('DEMO-PO-%04d', 4500 + $nb_orders), 'budgets_id' => $p['budget'],
                    'plugin_order_ordertaxes_id' => $tax_id, 'order_date' => $date->format('Y-m-d'),
                    'duedate' => (clone $date)->modify('+45 day')->format('Y-m-d'), 'suppliers_id' => $supplier,
                    'plugin_order_orderstates_id' => $state[$st], 'port_price' => 0, 'global_discount' => 0,
                    'is_deleted' => 0, 'is_template' => 0, 'date_mod' => $now]);
                $this->ins('glpi_plugin_order_orders_suppliers', ['entities_id' => 0, 'is_recursive' => 1,
                    'plugin_order_orders_id' => $oid, 'suppliers_id' => $supplier, 'num_quote' => sprintf('DEMO-Q-%04d', 4500 + $nb_orders)]);
                $total = 0;
                foreach ($lines as $ri => $qty) {
                    [$rid, $itemtype, $price] = $refs[$ri];
                    for ($u = 0; $u < $qty; $u++) {
                        $delivered = $st === 'delivered' || $st === 'paid' || ($st === 'partial' && $u < $qty / 2);
                        $this->ins('glpi_plugin_order_orders_items', ['entities_id' => 0, 'is_recursive' => 1,
                            'plugin_order_orders_id' => $oid, 'itemtype' => $itemtype, 'items_id' => 0,
                            'plugin_order_references_id' => $rid, 'plugin_order_deliverystates_id' => 0,
                            'plugin_order_ordertaxes_id' => $tax_id, 'price_taxfree' => $price, 'price_discounted' => $price,
                            'discount' => 0, 'price_ati' => round($price * 1.2, 2), 'states_id' => $delivered ? 2 : 1,
                            'delivery_date' => $delivered ? (clone $date)->modify('+30 day')->format('Y-m-d') : null]);
                        $total += $price;
                        $nb_lines++;
                    }
                }
                if (in_array($st, $committed, true)) {
                    $alloc[$p['id']] += $total;
                }
            }
        }
        $output->writeln(sprintf('BOQ orders: %d (%d order lines), catalog references: %d', $nb_orders, $nb_lines, count($refs)));
    }

    // Demo data center: room => [columns, rows, racks]; rack => [x, y, contents]
    // contents: [kind, project or null, count]; kinds are defined in RACK_KINDS. Filled from the top down.
    private const ROOMS = [
        'DEMO Hall A' => [10, 4, [
            'A01' => [1, 1, [['pp', null, 2], ['core', 'DEMO DC-EXPANSION', 1], ['core', 'DEMO 5G-CORE', 1], ['fiber', null, 2]]],
            'A02' => [2, 1, [['pp', null, 1], ['tor', 'DEMO 5G-CORE', 2], ['mgmt', 'DEMO 5G-CORE', 1], ['srv', 'DEMO 5G-CORE', 7]]],
            'A03' => [3, 1, [['pp', null, 1], ['tor', 'DEMO 5G-CORE', 2], ['srv', 'DEMO 5G-CORE', 7]]],
            'A04' => [4, 1, [['tor', 'DEMO 5G-CORE', 2], ['san', 'DEMO 5G-CORE', 3]]],
            'A05' => [5, 1, []],
            'B01' => [1, 3, [['pp', null, 1], ['tor', 'DEMO DC-EXPANSION', 2], ['srv', 'DEMO DC-EXPANSION', 6]]],
            'B02' => [2, 3, [['pp', null, 1], ['tor', 'DEMO DC-EXPANSION', 2], ['srv', 'DEMO DC-EXPANSION', 6]]],
            'B03' => [3, 3, [['tor', 'DEMO DC-EXPANSION', 2], ['san', 'DEMO DC-EXPANSION', 2]]],
            'B04' => [4, 3, [['pp', null, 1]]],
        ]],
        'DEMO Hall B' => [8, 3, [
            'C01' => [2, 2, [['pp', null, 1], ['tor', 'DEMO BSS-UPGRADE', 2], ['mgmt', 'DEMO BSS-UPGRADE', 1], ['srv', 'DEMO BSS-UPGRADE', 10]]],
            'C02' => [3, 2, [['tor', 'DEMO BSS-UPGRADE', 2], ['san', 'DEMO BSS-UPGRADE', 2]]],
            'C03' => [4, 2, [['pp', null, 1], ['tor', 'DEMO CRM', 2], ['srv', 'DEMO CRM', 8]]],
            'C04' => [5, 2, [['san', 'DEMO CRM', 1], ['cm', null, 2]]],
            'C05' => [6, 2, []],
        ]],
    ];

    // kind => [itemtype, model name, units, power W, weight kg, value, type name (network) or null, name prefix]
    private const RACK_KINDS = [
        'tor'   => ['NetworkEquipment', 'DEMO 25G top-of-rack switch', 1, 350, 10, 9800, 'DEMO Top-of-rack switch', 'DEMO-SW'],
        'mgmt'  => ['NetworkEquipment', 'DEMO 1G management switch', 1, 60, 5, 1500, 'DEMO Management switch', 'DEMO-SW'],
        'core'  => ['NetworkEquipment', 'DEMO Core switch 8-slot', 6, 2400, 95, 145000, 'DEMO Core switch', 'DEMO-CORE'],
        'san'   => ['NetworkEquipment', 'DEMO All-flash SAN 2U', 2, 1000, 48, 98000, 'DEMO SAN storage', 'DEMO-SAN'],
        'pp'    => ['PassiveDCEquipment', 'DEMO 48-port Cat6 patch panel', 1, 0, 2, 120, null, 'DEMO-PP'],
        'fiber' => ['PassiveDCEquipment', 'DEMO 24-port LC fiber panel', 1, 0, 2, 260, null, 'DEMO-PP'],
        'cm'    => ['PassiveDCEquipment', 'DEMO Cable manager 1U', 1, 0, 1, 40, null, 'DEMO-PP'],
    ];

    private function createRacks(OutputInterface $output, array $projects, array $servers, array &$alloc): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $now = $this->now();
        $today = new DateTime('today');
        $byname = array_column($projects, null, 'name');

        $supplier = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_suppliers', 'WHERE' => ['name' => 'DEMO Supplier'], 'LIMIT' => 1])->current();
        $supplier = $supplier ? (int) $supplier['id'] : $this->ins('glpi_suppliers', ['entities_id' => 0, 'is_recursive' => 1,
            'name' => 'DEMO Supplier', 'is_active' => 1, 'date_creation' => $now, 'date_mod' => $now]);
        $vendors = [];
        foreach (['DEMO Huawei', 'DEMO Dell'] as $v) {
            $vendors[] = $this->ins('glpi_manufacturers', ['name' => $v, 'date_creation' => $now, 'date_mod' => $now]);
        }

        // models and types
        $models = [];
        $types = [];
        foreach (self::RACK_KINDS as $kind => [$itemtype, $model, $u, $w, $kg, , $type]) {
            $table = $itemtype === 'NetworkEquipment' ? 'glpi_networkequipmentmodels' : 'glpi_passivedcequipmentmodels';
            $models[$kind] = $this->ins($table, ['name' => $model, 'required_units' => $u, 'power_consumption' => $w, 'weight' => $kg,
                'depth' => 1, 'date_creation' => $now, 'date_mod' => $now]);
            if ($type !== null && !isset($types[$type])) {
                $types[$type] = $this->ins('glpi_networkequipmenttypes', ['name' => $type, 'date_creation' => $now, 'date_mod' => $now]);
            }
        }
        $model_1u = $this->ins('glpi_computermodels', ['name' => 'DEMO Rack server 1U', 'required_units' => 1, 'power_consumption' => 600,
            'weight' => 18, 'date_creation' => $now, 'date_mod' => $now]);

        // server components, so the device card shows RAM, disks, NICs and batteries
        $dev = [
            'memory'  => $this->ins('glpi_devicememories', ['designation' => 'DEMO DDR5 64 GB RDIMM', 'size_default' => 65536, 'date_creation' => $now, 'date_mod' => $now]),
            'disk'    => $this->ins('glpi_deviceharddrives', ['designation' => 'DEMO 3.84 TB NVMe SSD', 'capacity_default' => 3932160, 'date_creation' => $now, 'date_mod' => $now]),
            'nic'     => $this->ins('glpi_devicenetworkcards', ['designation' => 'DEMO 25GbE dual-port NIC', 'bandwidth' => '25 Gb/s', 'date_creation' => $now, 'date_mod' => $now]),
            'battery' => $this->ins('glpi_devicebatteries', ['designation' => 'DEMO RAID controller battery', 'date_creation' => $now, 'date_mod' => $now]),
        ];

        $dc = $this->ins('glpi_datacenters', ['entities_id' => 0, 'is_recursive' => 1, 'name' => 'DEMO DC-1', 'is_deleted' => 0,
            'date_creation' => $now, 'date_mod' => $now]);
        $nb_racks = $nb_items = 0;
        $next_server = array_map(fn() => 0, $servers);
        $serial = fn(string $seed) => 'DEMO' . strtoupper(substr(md5($seed), 0, 8));
        foreach (self::ROOMS as $room => [$cols, $rows, $racks]) {
            $rmid = $this->ins('glpi_dcrooms', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $room, 'datacenters_id' => $dc,
                'vis_cols' => $cols, 'vis_rows' => $rows, 'is_deleted' => 0, 'date_creation' => $now, 'date_mod' => $now]);
            foreach ($racks as $rack => [$x, $y, $contents]) {
                $rid = $this->ins('glpi_racks', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $rack, 'dcrooms_id' => $rmid,
                    'number_units' => 42, 'position' => "$x,$y", 'max_power' => 8000, 'max_weight' => 1000, 'is_deleted' => 0,
                    'is_template' => 0, 'date_creation' => $now, 'date_mod' => $now]);
                $nb_racks++;
                $top = 42;
                foreach ($contents as [$kind, $pname, $count]) {
                    for ($k = 0; $k < $count; $k++) {
                        $p = $pname !== null ? $byname[$pname] : null;
                        if ($kind === 'srv') {
                            // place one of this project's demo servers
                            if (!isset($servers[$pname][$next_server[$pname]])) {
                                break;
                            }
                            $iid = $servers[$pname][$next_server[$pname]];
                            $one_u = $next_server[$pname] % 3 === 2;
                            $next_server[$pname]++;
                            $u = $one_u ? 1 : 2;
                            $itemtype = 'Computer';
                            $DB->update('glpi_computers', ['manufacturers_id' => $vendors[$iid % 2]] + ($one_u ? ['computermodels_id' => $model_1u] : []), ['id' => $iid]);
                            $DB->update('glpi_infocoms', ['suppliers_id' => $supplier], ['itemtype' => 'Computer', 'items_id' => $iid]);
                            $s = $serial("srv$iid");
                            for ($m = 0, $n = [8, 12, 16][$iid % 3]; $m < $n; $m++) {
                                $this->ins('glpi_items_devicememories', ['items_id' => $iid, 'itemtype' => 'Computer', 'devicememories_id' => $dev['memory'], 'size' => 65536, 'serial' => "M$s$m"]);
                            }
                            for ($m = 0, $n = [2, 4, 6][$iid % 3]; $m < $n; $m++) {
                                $this->ins('glpi_items_deviceharddrives', ['items_id' => $iid, 'itemtype' => 'Computer', 'deviceharddrives_id' => $dev['disk'], 'capacity' => 3932160, 'serial' => "D$s$m"]);
                            }
                            for ($m = 0; $m < 2; $m++) {
                                $this->ins('glpi_items_devicenetworkcards', ['items_id' => $iid, 'itemtype' => 'Computer', 'devicenetworkcards_id' => $dev['nic'], 'serial' => "N$s$m"]);
                            }
                            $this->ins('glpi_items_devicebatteries', ['items_id' => $iid, 'itemtype' => 'Computer', 'devicebatteries_id' => $dev['battery'], 'serial' => "B$s"]);
                        } else {
                            [$itemtype, , $u, , , $value, $type, $prefix] = self::RACK_KINDS[$kind];
                            $nb_items++;
                            $name = sprintf('%s-%03d', $prefix, $nb_items);
                            $fields = ['entities_id' => 0, 'name' => $name, 'serial' => $serial($name), 'manufacturers_id' => $vendors[$nb_items % 2],
                                'is_deleted' => 0, 'is_template' => 0, 'date_creation' => $now, 'date_mod' => $now];
                            if ($itemtype === 'NetworkEquipment') {
                                $iid = $this->ins('glpi_networkequipments', $fields + ['networkequipmentmodels_id' => $models[$kind], 'networkequipmenttypes_id' => $types[$type]]);
                            } else {
                                $iid = $this->ins('glpi_passivedcequipments', $fields + ['passivedcequipmentmodels_id' => $models[$kind]]);
                            }
                            // bought with the project (a few older ones so warranty colours vary); infrastructure items have no budget
                            $old = $nb_items % 5 === 0;
                            $buy = $old ? (clone $today)->modify('-' . mt_rand(34, 50) . ' month')
                                : ($p ? (clone $p['start'])->modify('+' . mt_rand(0, max(0, $p['ago'] - 1)) . ' month') : (clone $today)->modify('-' . mt_rand(12, 30) . ' month'));
                            if ($buy > $today) {
                                $buy = clone $today;
                            }
                            $this->ins('glpi_infocoms', ['items_id' => $iid, 'itemtype' => $itemtype, 'entities_id' => 0,
                                'buy_date' => $buy->format('Y-m-d'), 'use_date' => $buy->format('Y-m-d'), 'warranty_date' => $buy->format('Y-m-d'),
                                'warranty_duration' => $old ? 36 : 60, 'value' => $value, 'warranty_value' => round($value * 0.08),
                                'budgets_id' => $p ? $p['budget'] : 0, 'suppliers_id' => $supplier, 'alert' => 4,
                                'date_creation' => $now, 'date_mod' => $now]);
                            if ($p) {
                                $this->ins('glpi_items_projects', ['itemtype' => $itemtype, 'items_id' => $iid, 'projects_id' => $p['id']]);
                                $alloc[$p['id']] += $value;
                            }
                        }
                        $pos = $top - $u + 1;
                        $this->ins('glpi_items_racks', ['racks_id' => $rid, 'itemtype' => $itemtype, 'items_id' => $iid,
                            'position' => $pos, 'orientation' => 0, 'hpos' => 0, 'is_reserved' => 0]);
                        $top = $pos - 1;
                    }
                }
            }
        }
        $placed = array_sum($next_server);
        $output->writeln(sprintf('Data center: %d racks, %d servers placed, %d switches/SAN/panels', $nb_racks, $placed, $nb_items));
    }

    /**
     * Demo capacity requests, as if submitted with the capacity request form: answers, ticket, project link and,
     * when a kit matches, a BOQ estimate (draft / approved / canceled) linked to the ticket. Needs the form
     * (a form with "Request type" and "Resource category" questions) and the Orders plugin.
     */
    private function createDemands(OutputInterface $output, array $projects, array $teams, ?array $demands = null): void
    {
        /** @var DBmysql $DB */
        global $DB;
        if ($demands === null && (!$this->refs || !$DB->tableExists('glpi_plugin_projectreports_kits'))) {
            $output->writeln('<comment>No demo capacity requests: needs the Orders plugin and Project reports 1.6 or later.</comment>');
            return;
        }
        $form = $DB->request([
            'SELECT' => 's.forms_forms_id', 'FROM' => 'glpi_forms_questions AS q',
            'INNER JOIN' => ['glpi_forms_sections AS s' => ['ON' => ['s' => 'id', 'q' => 'forms_sections_id']]],
            'WHERE' => ['q.name' => 'Resource category'], 'ORDER' => 's.forms_forms_id DESC', 'LIMIT' => 1,
        ])->current();
        if (!$form) {
            $output->writeln('<comment>No demo capacity requests: import the capacity request form first (Administration › Forms › Import).</comment>');
            return;
        }
        $form_id = (int) $form['forms_forms_id'];
        $questions = [];
        foreach ($DB->request([
            'SELECT' => ['q.id', 'q.name', 'q.type', 'q.extra_data'], 'FROM' => 'glpi_forms_questions AS q',
            'INNER JOIN' => ['glpi_forms_sections AS s' => ['ON' => ['s' => 'id', 'q' => 'forms_sections_id']]],
            'WHERE' => ['s.forms_forms_id' => $form_id],
        ]) as $q) {
            $questions[$q['name']] ??= ['id' => (int) $q['id'], 'type' => $q['type'], 'options' => json_decode((string) $q['extra_data'], true)['options'] ?? []];
        }
        $answer = function (string $label, $value) use ($questions) {
            $q = $questions[$label] ?? null;
            if ($q === null) {
                return null;
            }
            if ($q['options'] && is_string($value)) {
                $key = array_search($value, $q['options'], true);
                $value = $key === false ? $value : $key;
                $value = str_ends_with($q['type'], 'QuestionTypeRadio') ? [$value] : $value;
            }
            return ['question_id' => $q['id'], 'question_label' => $label, 'raw_question_type' => $q['type'], 'raw_answer' => $value];
        };

        // demo kits
        $now = $this->now();
        $kits = [];
        foreach ($demands === null ? self::KITS : [] as $name => [$cat, $type, $per, $parts]) {
            $lines = [];
            foreach ($parts as $ri => $qty) {
                $r = $this->refs[$ri];
                $lines[] = ['part' => $r['part'], 'spare' => '', 'model' => '', 'desc' => $r['desc'], 'qty' => $qty, 'price' => $r['price'], 'itemtype' => $r['itemtype']];
            }
            $id = $this->ins('glpi_plugin_projectreports_kits', ['name' => $name, 'category' => $cat, 'resource_type' => $type,
                'units_per_kit' => $per, 'suppliers_id' => $this->supplier, 'lines' => json_encode($lines), 'is_active' => 1, 'date_mod' => $now]);
            $kits[$cat . '|' . $type] = ['id' => $id, 'name' => $name, 'per' => $per, 'parts' => $parts];
        }

        $by_name = array_column($projects, null, 'name');
        $index = (int) ($DB->request(['SELECT' => ['MAX' => '`index` AS m'], 'FROM' => 'glpi_forms_answerssets', 'WHERE' => ['forms_forms_id' => $form_id]])->current()['m'] ?? 0);
        $approval = ['Waiting' => 2, 'Approved' => 3, 'Refused' => 4];
        $nb = $nb_boq = 0;
        foreach (($demands ?? self::DEMANDS) as $n => [$rtype, $cat, $restype, $qty, $proj, $estimate, $appr, $title]) {
            $opened = (new DateTime())->modify('-' . (3 + $n * 4) . ' day')->format('Y-m-d H:i:s');
            $p = str_starts_with($proj, 'new:') ? null : ($by_name[$proj] ?? null);
            $ans = array_filter([
                $answer('Request type', $rtype),
                $p ? $answer('Project', ['itemtype' => 'Project', 'items_id' => (string) $p['id']]) : $answer('New project name', substr($proj, 4)),
                $answer('Required by (go-live date)', (new DateTime())->modify('+' . (2 + $n % 6) . ' month')->format('Y-m-15')),
                $cat ? $answer('Resource category', $cat) : null,
                $cat === 'Compute' ? $answer('Resource type', $restype) : null,
                $cat === 'Compute' ? $answer('Quantity', (string) $qty) : null,
                $cat === 'Compute' ? $answer('Server role', $title) : null,
                $cat === 'Storage' ? $answer('Capacity', (string) $qty) : null,
                $cat === 'Storage' ? $answer('Unit', 'TB') : null,
                $cat === 'GPU' ? $answer('Number of GPUs', (string) $qty) : null,
                $rtype === 'Release old resources' ? $answer('Resources to release', $title) : null,
            ]);
            $tid = $this->ins('glpi_tickets', ['entities_id' => 0, 'name' => '[DEMO] ' . $title, 'content' => '<p>' . htmlspecialchars($title) . '</p>',
                'date' => $opened, 'date_creation' => $opened, 'date_mod' => $now, 'status' => $appr === 'Refused' ? 6 : 2, 'type' => 2,
                'urgency' => 3, 'impact' => 3, 'priority' => 3, 'global_validation' => $approval[$appr], 'requesttypes_id' => 1, 'is_deleted' => 0]);
            $set = $this->ins('glpi_forms_answerssets', ['forms_forms_id' => $form_id, 'entities_id' => 0, 'users_id' => 0,
                'name' => 'DEMO capacity request #' . $tid, 'date_creation' => $opened, 'date_mod' => $opened, 'index' => ++$index,
                'answers' => json_encode(array_values($ans))]);
            $this->ins('glpi_forms_destinations_answerssets_formdestinationitems', ['forms_answerssets_id' => $set, 'itemtype' => 'Ticket', 'items_id' => $tid]);
            if ($p) {
                $this->ins('glpi_itils_projects', ['itemtype' => 'Ticket', 'items_id' => $tid, 'projects_id' => $p['id']]);
            }
            $this->ins('glpi_groups_tickets', ['tickets_id' => $tid, 'groups_id' => $teams[$n % count($teams)], 'type' => 1]);
            $nb++;
            if (!$kits) {
                continue; // --mix: no standard kits, so no BOQ estimates
            }

            $kit = $kits[$cat . '|' . $restype] ?? ($kits[$cat . '|'] ?? null);
            if ($rtype === 'Release old resources' || !$cat) {
                continue;
            }
            if ($kit === null) {
                $this->note($tid, $opened, '<p><b>Automatic BOQ not created:</b> there is no active standard kit for <i>' . htmlspecialchars(trim("$cat $restype")) . '</i>.</p>');
                continue;
            }
            $count = (int) ceil($qty / $kit['per']);
            $state = $appr === 'Approved' ? 'approved' : ($appr === 'Refused' ? 'canceled' : 'draft');
            $budget = $p ? $p['budget'] : 0;
            $oid = $this->ins('glpi_plugin_order_orders', ['entities_id' => 0, 'is_recursive' => 1,
                'name' => sprintf('DEMO BOQ-AUTO #%d %s x %d', $tid, $kit['name'], $count), 'num_order' => 'AUTO-' . $tid,
                'budgets_id' => $budget, 'plugin_order_ordertaxes_id' => $this->tax_id, 'order_date' => substr($opened, 0, 10),
                'suppliers_id' => $this->supplier, 'plugin_order_orderstates_id' => $this->order_state[$state], 'port_price' => 0,
                'global_discount' => 0, 'is_deleted' => 0, 'is_template' => 0, 'date_mod' => $now,
                'comment' => sprintf('Automatic estimate for ticket #%d: %s x kit "%s".', $tid, $count, $kit['name'])]);
            $total = 0;
            foreach ($kit['parts'] as $ri => $per_kit) {
                $r = $this->refs[$ri];
                for ($u = 0; $u < $per_kit * $count; $u++) {
                    $this->ins('glpi_plugin_order_orders_items', ['entities_id' => 0, 'is_recursive' => 1, 'plugin_order_orders_id' => $oid,
                        'itemtype' => $r['itemtype'], 'items_id' => 0, 'plugin_order_references_id' => $r['id'], 'plugin_order_deliverystates_id' => 0,
                        'plugin_order_ordertaxes_id' => $this->tax_id, 'price_taxfree' => $r['price'], 'price_discounted' => $r['price'],
                        'discount' => 0, 'price_ati' => round($r['price'] * 1.2, 2), 'states_id' => 1]);
                    $total += $r['price'];
                }
            }
            $this->ins('glpi_items_tickets', ['itemtype' => 'PluginOrderOrder', 'items_id' => $oid, 'tickets_id' => $tid]);
            $this->note($tid, $opened, sprintf('<p><b>Automatic BOQ estimate: %s</b> (before tax)</p><p>%s requested → %d × kit <i>%s</i>.<br>Prices from LOP <i>DEMO LOP 2026-Q4</i>.</p>',
                number_format($total), $qty, $count, htmlspecialchars($kit['name'])));
            $nb_boq++;
        }
        $output->writeln(sprintf('Capacity requests: %d (%d with a BOQ estimate), standard kits: %d', $nb, $nb_boq, count($kits)));
    }

    /** --mix: the requested capacity requests on their own projects (teams, projects, budgets and requests only). */
    private function createMix(OutputInterface $output): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $now = $this->now();
        $teams = [];
        foreach (self::TEAMS as $t) {
            $teams[] = $this->ins('glpi_groups', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $t, 'completename' => $t, 'level' => 1,
                'is_requester' => 1, 'is_assign' => 1, 'is_notify' => 1, 'is_itemgroup' => 1, 'is_usergroup' => 1, 'is_manager' => 1,
                'date_creation' => $now, 'date_mod' => $now]);
        }
        $start = (new DateTime('first day of -12 month'))->setTime(0, 0);
        $end = (clone $start)->modify('+36 month')->modify('-1 day');
        $projects = [];
        foreach (['DEMO CRM', 'DEMO HCBS', 'DEMO XIO'] as $name) {
            $pid = $this->ins('glpi_projects', ['entities_id' => 0, 'is_recursive' => 1, 'name' => $name, 'code' => str_replace('DEMO ', 'PRJ-', $name),
                'plan_start_date' => $start->format('Y-m-d 00:00:00'), 'plan_end_date' => $end->format('Y-m-d 00:00:00'),
                'percent_done' => 0, 'date' => $now, 'date_creation' => $now, 'date_mod' => $now]);
            $bid = $this->ins('glpi_budgets', ['entities_id' => 0, 'is_recursive' => 1, 'name' => str_replace('DEMO ', 'DEMO BUD-', $name), 'value' => 1000000,
                'begin_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'), 'date_creation' => $now, 'date_mod' => $now]);
            $this->ins('glpi_projectcosts', ['projects_id' => $pid, 'name' => 'DEMO Budget link', 'cost' => 0, 'budgets_id' => $bid,
                'entities_id' => 0, 'begin_date' => $start->format('Y-m-d')]);
            $projects[] = ['id' => $pid, 'name' => $name, 'budget' => $bid];
        }
        $output->writeln('Mix: projects DEMO CRM, DEMO HCBS, DEMO XIO created (AI platform is a new project, not created in advance)');
        $this->createDemands($output, $projects, $teams, self::MIX);
    }

    private function note(int $tid, string $date, string $html): void
    {
        $this->ins('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tid, 'date' => $date, 'date_creation' => $date,
            'date_mod' => $date, 'users_id' => 0, 'content' => $html, 'is_private' => 1, 'requesttypes_id' => 1]);
    }

    private function pick(array $values, array $weights)
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($values as $k => $v) {
            $r -= $weights[$k];
            if ($r <= 0) {
                return $v;
            }
        }
        return end($values);
    }

    private function remove(OutputInterface $output): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $ids = fn(string $table, string $field, string $like) => array_column(iterator_to_array(
            $DB->request(['SELECT' => 'id', 'FROM' => $table, 'WHERE' => [$field => ['LIKE', $like]]])
        ), 'id');

        $tickets = $ids('glpi_tickets', 'name', '[DEMO]%');
        if ($tickets) {
            $DB->delete('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $tickets]);
            $DB->delete('glpi_items_tickets', ['tickets_id' => $tickets]);
            if ($DB->tableExists('glpi_forms_destinations_answerssets_formdestinationitems')) {
                $sets = array_column(iterator_to_array($DB->request(['SELECT' => 'forms_answerssets_id', 'FROM' => 'glpi_forms_destinations_answerssets_formdestinationitems',
                    'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tickets]])), 'forms_answerssets_id');
                $DB->delete('glpi_forms_destinations_answerssets_formdestinationitems', ['itemtype' => 'Ticket', 'items_id' => $tickets]);
                if ($sets) {
                    $DB->delete('glpi_forms_answerssets', ['id' => $sets]);
                }
            }
            $DB->delete('glpi_itils_projects', ['itemtype' => 'Ticket', 'items_id' => $tickets]);
            $DB->delete('glpi_groups_tickets', ['tickets_id' => $tickets]);
            $DB->delete('glpi_tickets_users', ['tickets_id' => $tickets]);
            $DB->delete('glpi_tickets', ['id' => $tickets]);
        }
        // data center, racks and the items in them
        $rooms = $ids('glpi_dcrooms', 'name', 'DEMO %');
        $racks = $rooms ? array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_racks', 'WHERE' => ['dcrooms_id' => $rooms]])), 'id') : [];
        if ($racks) {
            $DB->delete('glpi_items_racks', ['racks_id' => $racks]);
            $DB->delete('glpi_racks', ['id' => $racks]);
        }
        $DB->delete('glpi_dcrooms', ['name' => ['LIKE', 'DEMO %']]);
        $DB->delete('glpi_datacenters', ['name' => ['LIKE', 'DEMO %']]);
        foreach (['NetworkEquipment' => 'glpi_networkequipments', 'PassiveDCEquipment' => 'glpi_passivedcequipments'] as $itemtype => $table) {
            $items = $ids($table, 'name', 'DEMO-%');
            if ($items) {
                $DB->delete('glpi_infocoms', ['itemtype' => $itemtype, 'items_id' => $items]);
                $DB->delete('glpi_items_projects', ['itemtype' => $itemtype, 'items_id' => $items]);
                $DB->delete('glpi_items_racks', ['itemtype' => $itemtype, 'items_id' => $items]);
                $DB->delete($table, ['id' => $items]);
            }
        }
        foreach (['glpi_networkequipmentmodels', 'glpi_passivedcequipmentmodels', 'glpi_networkequipmenttypes', 'glpi_manufacturers'] as $table) {
            $DB->delete($table, ['name' => ['LIKE', 'DEMO %']]);
        }
        foreach (['memories', 'harddrives', 'networkcards', 'batteries'] as $d) {
            $devs = $ids("glpi_device$d", 'designation', 'DEMO %');
            if ($devs) {
                $DB->delete("glpi_items_device$d", ["device{$d}_id" => $devs]);
                $DB->delete("glpi_device$d", ['id' => $devs]);
            }
        }

        $computers = $ids('glpi_computers', 'name', 'DEMO-SRV-%');
        if ($computers) {
            $DB->delete('glpi_items_racks', ['itemtype' => 'Computer', 'items_id' => $computers]);
            $DB->delete('glpi_infocoms', ['itemtype' => 'Computer', 'items_id' => $computers]);
            $DB->delete('glpi_items_projects', ['itemtype' => 'Computer', 'items_id' => $computers]);
            $DB->delete('glpi_computers', ['id' => $computers]);
        }
        $projects = $ids('glpi_projects', 'name', 'DEMO %');
        if ($projects) {
            $DB->delete('glpi_projectcosts', ['projects_id' => $projects]);
            $DB->delete('glpi_itils_projects', ['projects_id' => $projects]);
            $DB->delete('glpi_items_projects', ['projects_id' => $projects]);
            $DB->delete('glpi_projectteams', ['projects_id' => $projects]);
            $DB->delete('glpi_projects', ['id' => $projects]);
        }
        if ($DB->tableExists('glpi_plugin_order_orders')) {
            $orders = $ids('glpi_plugin_order_orders', 'name', 'DEMO BOQ-%');
            if ($orders) {
                $DB->delete('glpi_plugin_order_orders_items', ['plugin_order_orders_id' => $orders]);
                $DB->delete('glpi_plugin_order_orders_suppliers', ['plugin_order_orders_id' => $orders]);
                $DB->delete('glpi_plugin_order_orders', ['id' => $orders]);
                $output->writeln(sprintf('Removed %d demo BOQ orders', count($orders)));
            }
            $refs = $ids('glpi_plugin_order_references', 'name', 'DEMO-%');
            if ($refs) {
                $DB->delete('glpi_plugin_order_references_suppliers', ['plugin_order_references_id' => $refs]);
                $DB->delete('glpi_plugin_order_references', ['id' => $refs]);
            }
        }
        if ($DB->tableExists('glpi_plugin_projectreports_kits')) {
            $DB->delete('glpi_plugin_projectreports_kits', ['name' => ['LIKE', 'DEMO %']]);
        }
        $info = PluginProjectreportsLop::info();
        foreach ($ids('glpi_suppliers', 'name', 'DEMO Supplier%') as $sid) {
            unset($info[$sid]);
            if ($DB->tableExists(PluginProjectreportsLop::TABLE)) {
                $DB->delete(PluginProjectreportsLop::TABLE, ['suppliers_id' => $sid]);
            }
        }
        Config::setConfigurationValues('plugin:projectreports', ['lop_info' => json_encode($info, JSON_UNESCAPED_UNICODE)]);
        $DB->delete('glpi_suppliers', ['name' => ['LIKE', 'DEMO Supplier%']]);
        $DB->delete('glpi_budgets', ['name' => ['LIKE', 'DEMO BUD-%']]);
        $groups = $ids('glpi_groups', 'name', 'DEMO %');
        if ($groups) {
            $DB->delete('glpi_groups_tickets', ['groups_id' => $groups]);
            $DB->delete('glpi_groups_users', ['groups_id' => $groups]);
            $DB->delete('glpi_groups', ['id' => $groups]);
        }
        $DB->delete('glpi_itilcategories', ['name' => ['LIKE', 'DEMO %']]);
        $DB->delete('glpi_computermodels', ['name' => ['LIKE', 'DEMO Rack server %']]);
        if ($tickets || $computers || $projects) {
            $output->writeln(sprintf('Removed old demo data: %d requests, %d servers, %d projects', count($tickets), count($computers), count($projects)));
        }
    }
}
