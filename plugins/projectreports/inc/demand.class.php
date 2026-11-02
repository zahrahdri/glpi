<?php

/**
 * Approved capacity requests linked to a budget. Each approved request ticket gets the budget it is paid from, and the
 * page checks its BOQ cost (the orders linked in the ticket's Items tab, canceled ones left out) against that budget:
 * enough, or short by how much. The BOQ of a request already counted on the same budget is added back first, so it is
 * not counted twice. Table glpi_plugin_projectreports_demands (tickets_id, budgets_id).
 */
class PluginProjectreportsDemand
{
    public const TABLE = 'glpi_plugin_projectreports_demands';
    private const COST_NAME = 'BOQ (Project reports)';

    public static function install(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            $charset = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign = DBConnection::getDefaultPrimaryKeySignOption();
            $DB->doQuery("CREATE TABLE `" . self::TABLE . "` (
                `tickets_id` int {$sign} NOT NULL DEFAULT '0',
                `budgets_id` int {$sign} NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->dropTable(self::TABLE, true);
    }

    public static function canView(): bool
    {
        return Session::haveRight(Budget::$rightname, READ);
    }

    public static function canEdit(): bool
    {
        return Session::haveRight(Budget::$rightname, UPDATE);
    }

    /**
     * Approved capacity requests: tickets (not deleted) with global approval "Approved" that were created with the
     * capacity request form, with their project and the budget chosen for them.
     */
    public static function approved(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $forms = [];
        foreach ($DB->request(['SELECT' => 's.forms_forms_id', 'FROM' => 'glpi_forms_questions AS q',
            'INNER JOIN' => ['glpi_forms_sections AS s' => ['ON' => ['s' => 'id', 'q' => 'forms_sections_id']]],
            'WHERE' => ['q.name' => 'Resource category']]) as $f) {
            $forms[] = (int) $f['forms_forms_id'];
        }
        if (!$forms || !$DB->tableExists('glpi_forms_destinations_answerssets_formdestinationitems')) {
            return [];
        }
        $sql = "SELECT DISTINCT t.id, t.name, t.date, p.name AS project
            FROM glpi_tickets t
            JOIN glpi_forms_destinations_answerssets_formdestinationitems d ON d.itemtype = 'Ticket' AND d.items_id = t.id
            JOIN glpi_forms_answerssets a ON a.id = d.forms_answerssets_id
            LEFT JOIN glpi_itils_projects ip ON ip.itemtype = 'Ticket' AND ip.items_id = t.id
            LEFT JOIN glpi_projects p ON p.id = ip.projects_id
            WHERE t.is_deleted = 0 AND t.global_validation = 3 AND a.forms_forms_id IN (" . implode(',', $forms) . ")
            ORDER BY t.date, t.id";
        $out = [];
        $res = $DB->doQuery($sql);
        while ($r = $DB->fetchAssoc($res)) {
            $tid = (int) $r['id'];
            $boq = self::boq($tid);
            $out[] = [
                'id' => $tid, 'name' => (string) $r['name'], 'date' => (string) $r['date'], 'project' => (string) ($r['project'] ?? ''),
                'budgets_id' => self::budgetOf($tid), 'boq' => $boq['total'], 'boq_budget' => $boq['budgets_id'], 'orders' => $boq['orders'], 'list' => $boq['list'],
            ];
        }
        return $out;
    }

    /** The budget chosen for a request ticket (0 = none). */
    public static function budgetOf(int $tickets_id): int
    {
        /** @var DBmysql $DB */
        global $DB;
        $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['tickets_id' => $tickets_id]])->current();
        return $row ? (int) $row['budgets_id'] : 0;
    }

    /** Saves the budget of a request and sets it on its BOQ orders, so GLPI's budget tab counts them. */
    public static function save(int $tickets_id, int $budgets_id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $budgets_id = max(0, $budgets_id);
        $values = ['budgets_id' => $budgets_id, 'date_mod' => date('Y-m-d H:i:s')];
        if ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['tickets_id' => $tickets_id]])->current()) {
            $DB->update(self::TABLE, $values, ['tickets_id' => $tickets_id]);
        } else {
            $DB->insert(self::TABLE, $values + ['tickets_id' => $tickets_id]);
        }
        if ($budgets_id > 0) {
            foreach (self::boq($tickets_id)['list'] as $o) {
                $DB->update('glpi_plugin_order_orders', ['budgets_id' => $budgets_id], ['id' => $o['id']]);
            }
        }
        self::syncTicketCost($tickets_id);
    }

    /**
     * GLPI's budget tab counts ticket costs (Ticket column). One line per approved request holds its BOQ total as material
     * cost on the chosen budget; it is replaced each time, and removed when the request has no budget or no BOQ.
     */
    public static function syncTicketCost(int $tickets_id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->delete('glpi_ticketcosts', ['tickets_id' => $tickets_id, 'name' => self::COST_NAME]);
        $budget = self::budgetOf($tickets_id);
        $total = self::boq($tickets_id)['total'];
        if ($budget <= 0 || $total <= 0) {
            return;
        }
        $ticket = $DB->request(['SELECT' => 'entities_id', 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $tickets_id]])->current();
        $DB->insert('glpi_ticketcosts', ['tickets_id' => $tickets_id, 'name' => self::COST_NAME, 'comment' => 'BOQ of the approved request (Project reports).',
            'begin_date' => date('Y-m-d'), 'actiontime' => 0, 'cost_time' => 0, 'cost_fixed' => 0, 'cost_material' => $total,
            'budgets_id' => $budget, 'entities_id' => (int) ($ticket['entities_id'] ?? 0)]);
    }

    /**
     * Deletes one BOQ order of a request (soft delete, and its link to the ticket). Only orders still in draft or
     * waiting for approval can be deleted here; approved ones are canceled in the Orders plugin.
     */
    public static function deleteBoq(int $tickets_id, int $orders_id): bool
    {
        /** @var DBmysql $DB */
        global $DB;
        $link = $DB->request(['FROM' => 'glpi_items_tickets', 'WHERE' => ['itemtype' => 'PluginOrderOrder', 'items_id' => $orders_id, 'tickets_id' => $tickets_id]])->current();
        $order = $link ? $DB->request(['FROM' => 'glpi_plugin_order_orders', 'WHERE' => ['id' => $orders_id, 'is_deleted' => 0]])->current() : null;
        if (!$order) {
            return false;
        }
        $cfg = $DB->request(['FROM' => 'glpi_plugin_order_configs', 'LIMIT' => 1])->current() ?: [];
        $pending = [(int) ($cfg['order_status_draft'] ?? -1), (int) ($cfg['order_status_waiting_approval'] ?? -1)];
        if (!in_array((int) $order['plugin_order_orderstates_id'], $pending, true)) {
            return false;
        }
        $DB->update('glpi_plugin_order_orders', ['is_deleted' => 1, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $orders_id]);
        $DB->delete('glpi_items_tickets', ['itemtype' => 'PluginOrderOrder', 'items_id' => $orders_id, 'tickets_id' => $tickets_id]);
        self::syncTicketCost($tickets_id);
        return true;
    }

    /** BOQ of a request: the orders linked in the ticket's Items tab (canceled ones left out), their total before tax. */
    public static function boq(int $tickets_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $out = ['total' => 0.0, 'budgets_id' => 0, 'orders' => 0, 'list' => []];
        if (!$DB->tableExists('glpi_items_tickets') || !$DB->tableExists('glpi_plugin_order_orders')) {
            return $out;
        }
        $cfg = $DB->request(['FROM' => 'glpi_plugin_order_configs', 'LIMIT' => 1])->current() ?: [];
        $canceled = (int) ($cfg['order_status_canceled'] ?? -1);
        $pending = [(int) ($cfg['order_status_draft'] ?? -1), (int) ($cfg['order_status_waiting_approval'] ?? -1)];
        foreach ($DB->request(['SELECT' => 'items_id', 'FROM' => 'glpi_items_tickets',
            'WHERE' => ['itemtype' => 'PluginOrderOrder', 'tickets_id' => $tickets_id]]) as $link) {
            $order = $DB->request(['FROM' => 'glpi_plugin_order_orders', 'WHERE' => ['id' => (int) $link['items_id'], 'is_deleted' => 0]])->current();
            if (!$order || (int) $order['plugin_order_orderstates_id'] === $canceled) {
                continue;
            }
            $total = (float) $order['port_price'];
            foreach ($DB->request(['SELECT' => 'price_discounted', 'FROM' => 'glpi_plugin_order_orders_items',
                'WHERE' => ['plugin_order_orders_id' => (int) $order['id']]]) as $item) {
                $total += (float) $item['price_discounted'];
            }
            $state = (int) $order['plugin_order_orderstates_id'];
            $out['total'] += $total;
            $out['budgets_id'] = $out['budgets_id'] ?: (int) $order['budgets_id'];
            $out['orders']++;
            $out['list'][] = ['id' => (int) $order['id'], 'name' => (string) $order['name'], 'number' => (string) $order['num_order'],
                'supplier' => (string) ($DB->request(['SELECT' => 'name', 'FROM' => 'glpi_suppliers', 'WHERE' => ['id' => (int) $order['suppliers_id']]])->current()['name'] ?? ''), 'total' => $total,
                'budgets_id' => (int) $order['budgets_id'],
                'deletable' => in_array($state, $pending, true)];
        }
        return $out;
    }

    /**
     * The check of one approved request against its budget: 'none' (no budget chosen), 'no BOQ', or 'enough' / 'short'
     * with the money left (or missing) after the BOQ.
     */
    public static function check(array $row): array
    {
        if ($row['budgets_id'] <= 0) {
            return ['status' => 'none', 'left' => 0.0];
        }
        if ($row['boq'] <= 0) {
            return ['status' => 'no BOQ', 'left' => 0.0];
        }
        $info = PluginProjectreportsPlan::budgetInfo((int) $row['budgets_id']);
        // the BOQ is already counted in the budget's pipeline or committed money when it is on this budget: add it back
        $free = $info['available'] + ((int) $row['boq_budget'] === (int) $row['budgets_id'] ? $row['boq'] : 0.0);
        $left = $free - $row['boq'];
        return ['status' => $left >= 0 ? 'enough' : 'short', 'left' => $left, 'budget' => $info['name'], 'free' => $free];
    }
}
