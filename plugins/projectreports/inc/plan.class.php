<?php

/**
 * LOM plans: the device models a project plans to buy, each with its number of devices and the supplier whose LOP
 * prices it (the cheapest complete LOP by default). A plan is checked against a GLPI budget with the same money as the
 * Budget & cost page: value minus committed orders, pipeline orders, other costs and assets bought with that budget.
 * What does not fit is shown as a shortfall, to be ordered in the next budget year.
 * Tables: glpi_plugin_projectreports_plans (name, budgets_id) and glpi_plugin_projectreports_planlines
 * (plans_id, model_key "type:id" of the LOM catalog, devices, suppliers_id where 0 = cheapest complete LOP).
 */
class PluginProjectreportsPlan
{
    public const PLANS = 'glpi_plugin_projectreports_plans';
    public const LINES = 'glpi_plugin_projectreports_planlines';

    public static function install(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign = DBConnection::getDefaultPrimaryKeySignOption();
        if (!$DB->tableExists(self::PLANS)) {
            $DB->doQuery("CREATE TABLE `" . self::PLANS . "` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL DEFAULT '',
                `budgets_id` int {$sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
        if (!$DB->tableExists(self::LINES)) {
            $DB->doQuery("CREATE TABLE `" . self::LINES . "` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `plans_id` int {$sign} NOT NULL DEFAULT '0',
                `model_key` varchar(64) NOT NULL DEFAULT '',
                `devices` int NOT NULL DEFAULT '1',
                `suppliers_id` int {$sign} NOT NULL DEFAULT '0',
                `sort_order` int NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plan` (`plans_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
        }
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->dropTable(self::LINES, true);
        $DB->dropTable(self::PLANS, true);
    }

    /** Plans are read with the Budget right, and changed with the right to update budgets. */
    public static function canView(): bool
    {
        return Session::haveRight(Budget::$rightname, READ);
    }

    public static function canEdit(): bool
    {
        return Session::haveRight(Budget::$rightname, UPDATE);
    }

    /** @return array<int, array> all plans, by name */
    public static function all(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::PLANS, 'ORDER' => ['name', 'id']]), false);
    }

    public static function getPlan(int $id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;
        if ($id <= 0) {
            return null;
        }
        $row = $DB->request(['FROM' => self::PLANS, 'WHERE' => ['id' => $id]])->current();
        return $row ?: null;
    }

    /**
     * Money of one GLPI budget, with the rules of the Budget & cost page: orders by stage (canceled ones left out,
     * draft and waiting-approval ones are pipeline, the rest committed), other costs, and the value of assets bought
     * with the budget that did not come from an order.
     */
    public static function budgetInfo(int $budgets_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $info = ['name' => '', 'value' => 0.0, 'committed' => 0.0, 'pipeline' => 0.0, 'other' => 0.0, 'assets' => 0.0, 'available' => 0.0];
        $budget = $budgets_id > 0 ? $DB->request(['FROM' => 'glpi_budgets', 'WHERE' => ['id' => $budgets_id, 'is_deleted' => 0]])->current() : null;
        if (!$budget) {
            return $info;
        }
        $info['name'] = (string) $budget['name'];
        $info['value'] = (float) $budget['value'];

        if ($DB->tableExists('glpi_plugin_order_orders') && $DB->tableExists('glpi_plugin_order_configs')) {
            $cfg = $DB->request(['FROM' => 'glpi_plugin_order_configs', 'LIMIT' => 1])->current() ?: [];
            $canceled = (int) ($cfg['order_status_canceled'] ?? -1);
            $draft = [(int) ($cfg['order_status_draft'] ?? -1), (int) ($cfg['order_status_waiting_approval'] ?? -1)];
            foreach ($DB->request(['FROM' => 'glpi_plugin_order_orders',
                'WHERE' => ['budgets_id' => $budgets_id, 'is_deleted' => 0, 'is_template' => 0]]) as $order) {
                $state = (int) $order['plugin_order_orderstates_id'];
                if ($state === $canceled) {
                    continue;
                }
                $total = (float) $order['port_price'];
                foreach ($DB->request(['SELECT' => 'price_discounted', 'FROM' => 'glpi_plugin_order_orders_items',
                    'WHERE' => ['plugin_order_orders_id' => (int) $order['id']]]) as $item) {
                    $total += (float) $item['price_discounted'];
                }
                $info[in_array($state, $draft, true) ? 'pipeline' : 'committed'] += $total;
            }
        }

        foreach ($DB->request(['SELECT' => 'cost', 'FROM' => 'glpi_projectcosts', 'WHERE' => ['budgets_id' => $budgets_id]]) as $cost) {
            $info['other'] += (float) $cost['cost'];
        }
        if ($DB->tableExists('glpi_plugin_order_orders_items')) {
            foreach ($DB->request(['FROM' => 'glpi_infocoms', 'WHERE' => ['budgets_id' => $budgets_id]]) as $ic) {
                $from_order = (int) $ic['items_id'] > 0 && $DB->request(['FROM' => 'glpi_plugin_order_orders_items',
                    'WHERE' => ['itemtype' => $ic['itemtype'], 'items_id' => (int) $ic['items_id']], 'LIMIT' => 1])->current();
                if (!$from_order && (int) $ic['items_id'] > 0) {
                    $info['assets'] += (float) $ic['value'];
                }
            }
        }

        $info['available'] = $info['value'] - $info['committed'] - $info['pipeline'] - $info['other'] - $info['assets'];
        return $info;
    }

    /** @return array<int, array> the lines of a plan, in order */
    public static function lines(int $plans_id): array
    {
        /** @var DBmysql $DB */
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::LINES, 'WHERE' => ['plans_id' => $plans_id],
            'ORDER' => ['sort_order', 'id']]), false);
    }

    /**
     * Prices one line from the LOM catalog's parts list. Supplier 0 takes the cheapest LOP that covers the whole list
     * (if no LOP covers it, the cheapest partial one, flagged as incomplete). Money is before tax, as on the orders.
     */
    public static function price(array $line): array
    {
        $est = PluginProjectreportsAutoboq::estimate((string) $line['model_key'], max(1, (int) $line['devices']));
        $model = $est['model'] ?? null;
        if ($model === null) {
            return ['ok' => false, 'message' => 'This model has no parts list in the LOM catalog', 'model' => null, 'options' => [], 'total' => 0.0];
        }
        $options = $est['suppliers'];
        $sid = (int) $line['suppliers_id'];
        $pick = null;
        if ($sid > 0) {
            foreach ($options as $o) {
                if ((int) $o['suppliers_id'] === $sid) {
                    $pick = $o;
                }
            }
        } else {
            $pick = $options[0] ?? null; // estimate() lists complete LOPs first, then the cheapest
        }
        if ($pick === null) {
            return ['ok' => false, 'model' => $model, 'options' => $options, 'total' => 0.0,
                'message' => $sid > 0 ? 'This supplier\'s LOP has no price for this parts list' : 'No LOP has a price for this parts list'];
        }
        return ['ok' => true, 'model' => $model, 'options' => $options, 'message' => '',
            'supplier_id' => (int) $pick['suppliers_id'], 'supplier' => (string) $pick['supplier'],
            'complete' => (bool) $pick['complete'], 'covered' => (int) $pick['covered'], 'parts' => (int) $pick['parts'],
            'per_device' => (float) $pick['total'], 'total' => (float) $pick['all_total'], 'vat' => (float) $pick['vat']];
    }

    /** Totals of a plan: the sum of its priced lines, and how many lines could not be priced. */
    public static function totals(int $plans_id): array
    {
        $total = 0.0;
        $unpriced = 0;
        foreach (self::lines($plans_id) as $line) {
            $p = self::price($line);
            if ($p['ok']) {
                $total += $p['total'];
            } else {
                $unpriced++;
            }
        }
        return ['total' => $total, 'unpriced' => $unpriced];
    }

    public static function createPlan(string $name, int $budgets_id): int
    {
        /** @var DBmysql $DB */
        global $DB;
        $now = date('Y-m-d H:i:s');
        $name = trim($name) !== '' ? trim($name) : 'Untitled plan';
        $DB->insert(self::PLANS, ['name' => mb_substr($name, 0, 255), 'budgets_id' => max(0, $budgets_id),
            'date_creation' => $now, 'date_mod' => $now]);
        return (int) $DB->insertId();
    }

    public static function updatePlan(int $id, string $name, int $budgets_id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!self::getPlan($id)) {
            return;
        }
        $name = trim($name) !== '' ? trim($name) : 'Untitled plan';
        $DB->update(self::PLANS, ['name' => mb_substr($name, 0, 255), 'budgets_id' => max(0, $budgets_id), 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function deletePlan(int $id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->delete(self::LINES, ['plans_id' => $id]);
        $DB->delete(self::PLANS, ['id' => $id]);
    }

    /** Adds a line; the model must be one of the LOM models that have a parts list. */
    public static function addLine(int $plans_id, string $model_key, int $devices, int $suppliers_id): bool
    {
        /** @var DBmysql $DB */
        global $DB;
        if (!self::getPlan($plans_id) || !isset(PluginProjectreportsAutoboq::models()[$model_key])) {
            return false;
        }
        $DB->insert(self::LINES, ['plans_id' => $plans_id, 'model_key' => $model_key, 'devices' => self::devices($devices),
            'suppliers_id' => max(0, $suppliers_id), 'sort_order' => count(self::lines($plans_id)) + 1, 'date_mod' => date('Y-m-d H:i:s')]);
        return true;
    }

    public static function updateLine(int $id, int $devices, int $suppliers_id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->update(self::LINES, ['devices' => self::devices($devices), 'suppliers_id' => max(0, $suppliers_id),
            'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
    }

    public static function deleteLine(int $id): void
    {
        /** @var DBmysql $DB */
        global $DB;
        $DB->delete(self::LINES, ['id' => $id]);
    }

    public static function devices(int $n): int
    {
        return max(1, min(100000, $n));
    }
}
