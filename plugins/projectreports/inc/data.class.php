<?php

/**
 * Read-only SQL behind the reports. Every query only SELECTs from GLPI tables.
 */
class PluginProjectreportsData
{
    /** Tables each report section needs; a missing one is reported on screen instead of failing. */
    public const NEEDS = [
        'requests' => ['glpi_tickets', 'glpi_itils_projects', 'glpi_projects', 'glpi_itilcategories', 'glpi_groups_tickets', 'glpi_groups'],
        'money'    => ['glpi_budgets', 'glpi_projects', 'glpi_projecttypes', 'glpi_projectcosts', 'glpi_infocoms', 'glpi_plugin_order_orders', 'glpi_plugin_order_orders_items', 'glpi_plugin_order_configs', 'glpi_plugin_order_orderstates', 'glpi_suppliers'],
        'demands'  => ['glpi_tickets', 'glpi_forms_answerssets', 'glpi_forms_destinations_answerssets_formdestinationitems', 'glpi_forms_questions', 'glpi_items_tickets', 'glpi_plugin_order_orders', 'glpi_plugin_order_orders_items', 'glpi_plugin_order_configs'],
        'racks'    => ['glpi_racks', 'glpi_items_racks', 'glpi_dcrooms', 'glpi_datacenters', 'glpi_infocoms', 'glpi_items_projects', 'glpi_computers', 'glpi_networkequipments', 'glpi_passivedcequipments', 'glpi_assets_assets'],
    ];

    private const SQL_REQUESTS = <<<'PRSQL'
SELECT
  t.id AS ticket_id,
  t.name AS title,
  COALESCE(p.name, '(no project)') AS project,
  COALESCE(rg.name, '(no team)') AS requesting_team,
  COALESCE(ag.names, '') AS assigned_to,
  COALESCE(c.completename, '(no category)') AS category,
  ELT(t.type, 'Incident', 'Request') AS request_type,
  ELT(t.priority, '1 Very low', '2 Low', '3 Medium', '4 High', '5 Very high', '6 Major') AS priority,
  ELT(t.status, 'New', 'Assigned', 'Planned', 'Pending', 'Solved', 'Closed') AS status,
  IF(t.status < 5, 'Open', 'Solved / closed') AS state,
  IF(t.status < 5, 1, 0) AS is_open,
  IF(t.status < 5 AND t.priority >= 4, 1, 0) AS is_open_high,
  IF(t.status < 5 AND t.time_to_resolve < NOW(), 1, 0) AS is_overdue,
  CASE WHEN t.status >= 5 THEN '' WHEN t.time_to_resolve < NOW() THEN 'Overdue' ELSE 'On time' END AS sla,
  t.date AS opened,
  t.solvedate AS solved,
  t.time_to_resolve AS due,
  DATEDIFF(NOW(), t.date) AS age_days,
  CASE WHEN t.status >= 5 THEN ''
       WHEN DATEDIFF(NOW(), t.date) <= 7 THEN '1: up to 7 days'
       WHEN DATEDIFF(NOW(), t.date) <= 30 THEN '2: 8-30 days'
       WHEN DATEDIFF(NOW(), t.date) <= 90 THEN '3: 31-90 days'
       ELSE '4: over 90 days' END AS open_age,
  ROUND(TIMESTAMPDIFF(MINUTE, t.date, t.solvedate) / 1440, 1) AS days_to_solve
FROM glpi_tickets t
LEFT JOIN glpi_itils_projects ip ON ip.itemtype = 'Ticket' AND ip.items_id = t.id
LEFT JOIN glpi_projects p ON p.id = ip.projects_id
LEFT JOIN glpi_itilcategories c ON c.id = t.itilcategories_id
LEFT JOIN (SELECT gt.tickets_id, MIN(gt.groups_id) AS groups_id FROM glpi_groups_tickets gt
           WHERE gt.type = 1 GROUP BY gt.tickets_id) rq ON rq.tickets_id = t.id
LEFT JOIN glpi_groups rg ON rg.id = rq.groups_id
LEFT JOIN (SELECT gt.tickets_id, GROUP_CONCAT(g.name SEPARATOR ', ') AS names FROM glpi_groups_tickets gt
           JOIN glpi_groups g ON g.id = gt.groups_id WHERE gt.type = 2 GROUP BY gt.tickets_id) ag ON ag.tickets_id = t.id
WHERE t.is_deleted = 0
PRSQL;

    private const SQL_FINANCE = <<<'PRSQL'
WITH
cfg AS (SELECT * FROM glpi_plugin_order_configs LIMIT 1),
ord AS (  -- every BOQ / order with its total (tax-free lines + shipping) and stage
  SELECT o.id, o.name, o.num_order, o.budgets_id, o.order_date, o.plugin_order_orderstates_id AS state_id,
    CASE WHEN o.plugin_order_orderstates_id = cfg.order_status_canceled THEN 'canceled'
         WHEN o.plugin_order_orderstates_id IN (cfg.order_status_draft, cfg.order_status_waiting_approval) THEN 'pipeline'
         ELSE 'committed' END AS stage,
    o.port_price + COALESCE(SUM(i.price_discounted), 0) AS total,
    COUNT(i.id) AS items
  FROM glpi_plugin_order_orders o
  JOIN cfg
  LEFT JOIN glpi_plugin_order_orders_items i ON i.plugin_order_orders_id = o.id
  WHERE o.is_deleted = 0 AND o.is_template = 0
  GROUP BY o.id),
pb AS (   -- project <-> budget link = a line on the project's Costs tab
  SELECT DISTINCT projects_id, budgets_id FROM glpi_projectcosts WHERE budgets_id > 0),
legacy AS (  -- assets with financial info on a budget that were NOT created from an order
  SELECT ic.* FROM glpi_infocoms ic
  WHERE ic.budgets_id > 0 AND NOT EXISTS (SELECT 1 FROM glpi_plugin_order_orders_items oi
        WHERE oi.itemtype = ic.itemtype AND oi.items_id = ic.items_id AND oi.items_id > 0)),
bf AS (   -- money per budget
  SELECT b.id AS budgets_id, b.value AS budget, b.begin_date, b.end_date,
    COALESCE((SELECT SUM(total) FROM ord WHERE ord.budgets_id = b.id AND stage = 'committed'), 0) AS committed,
    COALESCE((SELECT SUM(total) FROM ord WHERE ord.budgets_id = b.id AND stage = 'pipeline'), 0) AS pipeline,
    COALESCE((SELECT SUM(value) FROM legacy WHERE legacy.budgets_id = b.id), 0) AS assets,
    COALESCE((SELECT SUM(cost) FROM glpi_projectcosts pc WHERE pc.budgets_id = b.id), 0) AS other_costs
  FROM glpi_budgets b WHERE b.is_deleted = 0),
pf AS (   -- money per project
  SELECT p.id AS project_id, p.name AS project,
    COALESCE(pt.name, '(no category)') AS category,
    SUM(bf.budget) AS budget, SUM(bf.committed) AS committed, SUM(bf.pipeline) AS pipeline,
    SUM(bf.assets) AS assets, SUM(bf.other_costs) AS other_costs,
    SUM(bf.committed + bf.assets + bf.other_costs) AS allocated,
    MIN(COALESCE(bf.begin_date, DATE(p.plan_start_date))) AS begin_date,
    MAX(COALESCE(bf.end_date, DATE(p.plan_end_date))) AS end_date,
    SUM(bf.committed + bf.other_costs)
      / GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(bf.begin_date, DATE(p.plan_start_date))), CURDATE())) AS burn,
    GREATEST(0, TIMESTAMPDIFF(MONTH, CURDATE(), MAX(COALESCE(bf.end_date, DATE(p.plan_end_date))))) AS months_left
  FROM glpi_projects p
  JOIN pb ON pb.projects_id = p.id
  JOIN bf ON bf.budgets_id = pb.budgets_id
  LEFT JOIN glpi_projecttypes pt ON pt.id = p.projecttypes_id
  WHERE p.is_deleted = 0
  GROUP BY p.id, p.name, pt.name)
SELECT pf.project, pf.category,
  pf.budget, pf.committed AS boq_orders, pf.assets, pf.other_costs, pf.allocated, pf.pipeline,
  pf.budget - pf.allocated AS remaining,
  pf.budget - pf.allocated - pf.pipeline AS remaining_after_pipeline,
  ROUND(pf.allocated / NULLIF(pf.budget, 0), 3) AS pct_used,
  ROUND((pf.allocated + pf.pipeline) / NULLIF(pf.budget, 0), 3) AS pct_incl_pipeline,
  IF(pf.allocated + pf.pipeline > pf.budget, 1, 0) AS is_over_budget,
  pf.begin_date, pf.end_date,
  ROUND(pf.burn) AS boq_spend_per_month,
  pf.months_left,
  ROUND(pf.allocated + GREATEST(pf.pipeline, pf.burn * pf.months_left)) AS forecast_at_end,
  ROUND(pf.budget - pf.allocated - GREATEST(pf.pipeline, pf.burn * pf.months_left)) AS budget_minus_forecast,
  CASE WHEN pf.allocated >= pf.budget THEN 'already exceeded'
       WHEN pf.allocated + pf.pipeline >= pf.budget THEN 'exceeded if drafts approved'
       WHEN pf.burn = 0 THEN 'no BOQ spend yet'
       WHEN DATE_ADD(CURDATE(), INTERVAL FLOOR((pf.budget - pf.allocated) / pf.burn) MONTH) < pf.end_date
         THEN DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL FLOOR((pf.budget - pf.allocated) / pf.burn) MONTH), '%Y-%m')
       ELSE 'lasts to end' END AS budget_runs_out,
  (SELECT COUNT(DISTINCT ip.items_id) FROM glpi_itils_projects ip JOIN glpi_tickets t ON t.id = ip.items_id
     WHERE ip.itemtype = 'Ticket' AND ip.projects_id = pf.project_id AND t.is_deleted = 0
       AND t.status < 5 AND t.type = 2) AS open_requests,
  (SELECT ROUND(COALESCE(SUM(IF(ic.warranty_value > 0, ic.warranty_value, ic.value * 0.10)), 0))
     FROM glpi_infocoms ic JOIN pb ON pb.budgets_id = ic.budgets_id
     WHERE pb.projects_id = pf.project_id AND ic.warranty_duration > 0
       AND DATE_ADD(ic.warranty_date, INTERVAL ic.warranty_duration MONTH)
           BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)) AS renewals_12m,
  (SELECT ROUND(COALESCE(SUM(ic.value), 0)) FROM glpi_infocoms ic JOIN pb ON pb.budgets_id = ic.budgets_id
     WHERE pb.projects_id = pf.project_id
       AND DATE_ADD(COALESCE(ic.use_date, ic.buy_date), INTERVAL 5 YEAR)
           BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 12 MONTH)) AS refresh_12m,
  ROUND(pf.burn * LEAST(12, pf.months_left)) AS runrate_spend_12m
FROM pf
PRSQL;

    private const SQL_ORDERS = <<<'PRSQL'
WITH
cfg AS (SELECT * FROM glpi_plugin_order_configs LIMIT 1),
ord AS (  -- every BOQ / order with its total (tax-free lines + shipping) and stage
  SELECT o.id, o.name, o.num_order, o.budgets_id, o.order_date, o.plugin_order_orderstates_id AS state_id,
    CASE WHEN o.plugin_order_orderstates_id = cfg.order_status_canceled THEN 'canceled'
         WHEN o.plugin_order_orderstates_id IN (cfg.order_status_draft, cfg.order_status_waiting_approval) THEN 'pipeline'
         ELSE 'committed' END AS stage,
    o.port_price + COALESCE(SUM(i.price_discounted), 0) AS total,
    COUNT(i.id) AS items
  FROM glpi_plugin_order_orders o
  JOIN cfg
  LEFT JOIN glpi_plugin_order_orders_items i ON i.plugin_order_orders_id = o.id
  WHERE o.is_deleted = 0 AND o.is_template = 0
  GROUP BY o.id),
pb AS (   -- project <-> budget link = a line on the project's Costs tab
  SELECT DISTINCT projects_id, budgets_id FROM glpi_projectcosts WHERE budgets_id > 0),
legacy AS (  -- assets with financial info on a budget that were NOT created from an order
  SELECT ic.* FROM glpi_infocoms ic
  WHERE ic.budgets_id > 0 AND NOT EXISTS (SELECT 1 FROM glpi_plugin_order_orders_items oi
        WHERE oi.itemtype = ic.itemtype AND oi.items_id = ic.items_id AND oi.items_id > 0)),
bf AS (   -- money per budget
  SELECT b.id AS budgets_id, b.value AS budget, b.begin_date, b.end_date,
    COALESCE((SELECT SUM(total) FROM ord WHERE ord.budgets_id = b.id AND stage = 'committed'), 0) AS committed,
    COALESCE((SELECT SUM(total) FROM ord WHERE ord.budgets_id = b.id AND stage = 'pipeline'), 0) AS pipeline,
    COALESCE((SELECT SUM(value) FROM legacy WHERE legacy.budgets_id = b.id), 0) AS assets,
    COALESCE((SELECT SUM(cost) FROM glpi_projectcosts pc WHERE pc.budgets_id = b.id), 0) AS other_costs
  FROM glpi_budgets b WHERE b.is_deleted = 0),
pf AS (   -- money per project
  SELECT p.id AS project_id, p.name AS project,
    SUM(bf.budget) AS budget, SUM(bf.committed) AS committed, SUM(bf.pipeline) AS pipeline,
    SUM(bf.assets) AS assets, SUM(bf.other_costs) AS other_costs,
    SUM(bf.committed + bf.assets + bf.other_costs) AS allocated,
    MIN(COALESCE(bf.begin_date, DATE(p.plan_start_date))) AS begin_date,
    MAX(COALESCE(bf.end_date, DATE(p.plan_end_date))) AS end_date,
    SUM(bf.committed + bf.other_costs)
      / GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(bf.begin_date, DATE(p.plan_start_date))), CURDATE())) AS burn,
    GREATEST(0, TIMESTAMPDIFF(MONTH, CURDATE(), MAX(COALESCE(bf.end_date, DATE(p.plan_end_date))))) AS months_left
  FROM glpi_projects p
  JOIN pb ON pb.projects_id = p.id
  JOIN bf ON bf.budgets_id = pb.budgets_id
  WHERE p.is_deleted = 0
  GROUP BY p.id, p.name)
SELECT pr.name AS project, ord.name AS boq_order, ord.num_order AS order_number,
  s.name AS order_state, ord.stage, ord.order_date, ord.items, ord.total AS total_tax_free,
  bu.name AS budget_name
FROM ord
JOIN glpi_budgets bu ON bu.id = ord.budgets_id
JOIN pb ON pb.budgets_id = ord.budgets_id
JOIN glpi_projects pr ON pr.id = pb.projects_id
LEFT JOIN glpi_plugin_order_orderstates s ON s.id = ord.state_id
PRSQL;

    private const SQL_TIMELINE = <<<'PRSQL'
WITH
cfg AS (SELECT * FROM glpi_plugin_order_configs LIMIT 1),
ord AS (  -- every BOQ / order with its total (tax-free lines + shipping) and stage
  SELECT o.id, o.name, o.num_order, o.budgets_id, o.order_date, o.plugin_order_orderstates_id AS state_id,
    CASE WHEN o.plugin_order_orderstates_id = cfg.order_status_canceled THEN 'canceled'
         WHEN o.plugin_order_orderstates_id IN (cfg.order_status_draft, cfg.order_status_waiting_approval) THEN 'pipeline'
         ELSE 'committed' END AS stage,
    o.port_price + COALESCE(SUM(i.price_discounted), 0) AS total,
    COUNT(i.id) AS items
  FROM glpi_plugin_order_orders o
  JOIN cfg
  LEFT JOIN glpi_plugin_order_orders_items i ON i.plugin_order_orders_id = o.id
  WHERE o.is_deleted = 0 AND o.is_template = 0
  GROUP BY o.id),
pb AS (   -- project <-> budget link = a line on the project's Costs tab
  SELECT DISTINCT projects_id, budgets_id FROM glpi_projectcosts WHERE budgets_id > 0),
legacy AS (  -- assets with financial info on a budget that were NOT created from an order
  SELECT ic.* FROM glpi_infocoms ic
  WHERE ic.budgets_id > 0 AND NOT EXISTS (SELECT 1 FROM glpi_plugin_order_orders_items oi
        WHERE oi.itemtype = ic.itemtype AND oi.items_id = ic.items_id AND oi.items_id > 0)),
bf AS (   -- money per budget
  SELECT b.id AS budgets_id, b.value AS budget, b.begin_date, b.end_date,
    COALESCE((SELECT SUM(total) FROM ord WHERE ord.budgets_id = b.id AND stage = 'committed'), 0) AS committed,
    COALESCE((SELECT SUM(total) FROM ord WHERE ord.budgets_id = b.id AND stage = 'pipeline'), 0) AS pipeline,
    COALESCE((SELECT SUM(value) FROM legacy WHERE legacy.budgets_id = b.id), 0) AS assets,
    COALESCE((SELECT SUM(cost) FROM glpi_projectcosts pc WHERE pc.budgets_id = b.id), 0) AS other_costs
  FROM glpi_budgets b WHERE b.is_deleted = 0),
pf AS (   -- money per project
  SELECT p.id AS project_id, p.name AS project,
    SUM(bf.budget) AS budget, SUM(bf.committed) AS committed, SUM(bf.pipeline) AS pipeline,
    SUM(bf.assets) AS assets, SUM(bf.other_costs) AS other_costs,
    SUM(bf.committed + bf.assets + bf.other_costs) AS allocated,
    MIN(COALESCE(bf.begin_date, DATE(p.plan_start_date))) AS begin_date,
    MAX(COALESCE(bf.end_date, DATE(p.plan_end_date))) AS end_date,
    SUM(bf.committed + bf.other_costs)
      / GREATEST(1, TIMESTAMPDIFF(MONTH, MIN(COALESCE(bf.begin_date, DATE(p.plan_start_date))), CURDATE())) AS burn,
    GREATEST(0, TIMESTAMPDIFF(MONTH, CURDATE(), MAX(COALESCE(bf.end_date, DATE(p.plan_end_date))))) AS months_left
  FROM glpi_projects p
  JOIN pb ON pb.projects_id = p.id
  JOIN bf ON bf.budgets_id = pb.budgets_id
  WHERE p.is_deleted = 0
  GROUP BY p.id, p.name),
nums AS (SELECT a.n + 10 * b.n AS n
  FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b),
ev AS (   -- dated spend: committed orders by order date, other assets by buy date
  SELECT pb.projects_id, ord.order_date AS d, ord.total AS amt FROM ord JOIN pb ON pb.budgets_id = ord.budgets_id
  WHERE ord.stage = 'committed'
  UNION ALL
  SELECT pb.projects_id, COALESCE(legacy.buy_date, legacy.order_date, DATE(legacy.date_creation)), legacy.value
  FROM legacy JOIN pb ON pb.budgets_id = legacy.budgets_id),
rng AS (SELECT DATE_FORMAT(MIN(begin_date), '%Y-%m-01') AS first_month, MAX(end_date) AS last_day FROM pf),
pm AS (SELECT pf.*, CAST(DATE_ADD(rng.first_month, INTERVAL nums.n MONTH) AS DATE) AS month
  FROM pf JOIN rng JOIN nums
  WHERE DATE_ADD(rng.first_month, INTERVAL nums.n MONTH) <= rng.last_day),
series AS (SELECT 'Allocated (actual)' AS series UNION ALL SELECT 'Budget' UNION ALL SELECT 'Forecast')
SELECT pm.project, pm.month, series.series,
  CASE series.series
    WHEN 'Allocated (actual)' THEN IF(pm.month <= CURDATE(),
      (SELECT COALESCE(SUM(ev.amt), 0) FROM ev WHERE ev.projects_id = pm.project_id
         AND ev.d < DATE_ADD(pm.month, INTERVAL 1 MONTH)), NULL)
    WHEN 'Budget' THEN pm.budget
    ELSE IF(pm.month >= DATE_FORMAT(CURDATE(), '%Y-%m-01'),
      pm.allocated + IF(pm.month = DATE_FORMAT(CURDATE(), '%Y-%m-01'), 0,
        GREATEST(pm.pipeline, pm.burn * LEAST(pm.months_left,
          TIMESTAMPDIFF(MONTH, DATE_FORMAT(CURDATE(), '%Y-%m-01'), pm.month)))), NULL)
  END AS amount
FROM pm JOIN series
PRSQL;

    private const SQL_ASSETS = <<<'PRSQL'
WITH pb AS (SELECT DISTINCT projects_id, budgets_id FROM glpi_projectcosts WHERE budgets_id > 0)
SELECT pr.name AS project,
  CASE WHEN ic.itemtype = 'Computer' THEN 'Server'
       WHEN ic.itemtype = 'NetworkEquipment' THEN 'Network device'
       WHEN ic.itemtype LIKE '%SANStorage%' THEN 'SAN storage'
       ELSE ic.itemtype END AS asset_type,
  ic.items_id AS asset_id,
  ic.value AS asset_value,
  COALESCE(ic.use_date, ic.buy_date) AS in_service,
  DATE_ADD(ic.warranty_date, INTERVAL ic.warranty_duration MONTH) AS warranty_end,
  IF(ic.warranty_value > 0, ic.warranty_value, ic.value * 0.10) AS renewal_cost,
  IF(DATE_ADD(ic.warranty_date, INTERVAL ic.warranty_duration MONTH)
     BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 24 MONTH), 1, 0) AS renew_next_24m,
  DATE_ADD(COALESCE(ic.use_date, ic.buy_date), INTERVAL 5 YEAR) AS end_of_life,
  IF(YEAR(DATE_ADD(COALESCE(ic.use_date, ic.buy_date), INTERVAL 5 YEAR))
     BETWEEN YEAR(CURDATE()) AND YEAR(CURDATE()) + 4, 1, 0) AS eol_next_5y,
  s.name AS supplier
FROM glpi_infocoms ic
JOIN pb ON pb.budgets_id = ic.budgets_id
JOIN glpi_projects pr ON pr.id = pb.projects_id
LEFT JOIN glpi_suppliers s ON s.id = ic.suppliers_id
WHERE ic.budgets_id > 0
PRSQL;

    private const SQL_RACKS = <<<'PRSQL'
SELECT dc.name AS datacenter, rm.name AS room, rm.vis_cols, rm.vis_rows, r.id AS rack_id, r.name AS rack, r.position AS rack_pos,
  r.number_units AS rack_units, r.max_power, r.max_weight,
  (SELECT COUNT(*) FROM glpi_items_racks ir WHERE ir.racks_id=r.id AND ir.is_reserved=1) AS reserved
FROM glpi_racks r JOIN glpi_dcrooms rm ON rm.id=r.dcrooms_id JOIN glpi_datacenters dc ON dc.id=rm.datacenters_id
WHERE r.is_deleted=0
PRSQL;

    private const SQL_RACK_ITEMS = <<<'PRSQL'
SELECT dc.name AS datacenter, rm.name AS room, r.id AS rack_id, r.name AS rack, r.position AS rack_pos,
  r.number_units AS rack_units, r.max_power, r.max_weight,
  x.position, x.units, x.power_w, x.weight_kg, x.kind, x.itemtype, x.item_id, x.name, x.serial, x.model, x.manufacturer,
  COALESCE((SELECT GROUP_CONCAT(DISTINCT p.name) FROM glpi_items_projects ipj JOIN glpi_projects p ON p.id=ipj.projects_id
            WHERE ipj.itemtype=x.itemtype AND ipj.items_id=x.item_id),'') AS project,
  ic.value, s.name AS supplier, ic.buy_date,
  DATE_ADD(ic.warranty_date, INTERVAL ic.warranty_duration MONTH) AS warranty_end,
  (SELECT COUNT(*) FROM glpi_items_devicememories d WHERE d.itemtype=x.itemtype AND d.items_id=x.item_id) AS ram_modules,
  (SELECT COALESCE(SUM(d.size),0) FROM glpi_items_devicememories d WHERE d.itemtype=x.itemtype AND d.items_id=x.item_id) AS ram_mb,
  (SELECT COUNT(*) FROM glpi_items_deviceharddrives d WHERE d.itemtype=x.itemtype AND d.items_id=x.item_id) AS disks,
  (SELECT COALESCE(SUM(d.capacity),0) FROM glpi_items_deviceharddrives d WHERE d.itemtype=x.itemtype AND d.items_id=x.item_id) AS disk_mb,
  (SELECT COUNT(*) FROM glpi_items_devicenetworkcards d WHERE d.itemtype=x.itemtype AND d.items_id=x.item_id) AS nics,
  (SELECT COUNT(*) FROM glpi_items_devicebatteries d WHERE d.itemtype=x.itemtype AND d.items_id=x.item_id) AS batteries
FROM (SELECT ir.racks_id, ir.position, ir.orientation, ir.is_reserved, 'Computer' AS itemtype, i.id AS item_id, i.name, i.serial,
    m.name AS model, COALESCE(m.required_units,1) AS units, COALESCE(m.power_consumption,0) AS power_w, COALESCE(m.weight,0) AS weight_kg,
    'Server' AS kind, mf.name AS manufacturer
  FROM glpi_items_racks ir
  JOIN glpi_computers i ON i.id=ir.items_id AND ir.itemtype='Computer'
  LEFT JOIN glpi_computermodels m ON m.id=i.computermodels_id
  LEFT JOIN glpi_manufacturers mf ON mf.id=i.manufacturers_id
  WHERE i.is_deleted=0 UNION ALL SELECT ir.racks_id, ir.position, ir.orientation, ir.is_reserved, 'NetworkEquipment' AS itemtype, i.id AS item_id, i.name, i.serial,
    m.name AS model, COALESCE(m.required_units,1) AS units, COALESCE(m.power_consumption,0) AS power_w, COALESCE(m.weight,0) AS weight_kg,
    (SELECT CASE WHEN t.name LIKE '%SAN%' THEN 'SAN storage' WHEN t.name LIKE '%Core%' THEN 'Core switch' ELSE 'Switch' END FROM glpi_networkequipmenttypes t WHERE t.id=i.networkequipmenttypes_id) AS kind, mf.name AS manufacturer
  FROM glpi_items_racks ir
  JOIN glpi_networkequipments i ON i.id=ir.items_id AND ir.itemtype='NetworkEquipment'
  LEFT JOIN glpi_networkequipmentmodels m ON m.id=i.networkequipmentmodels_id
  LEFT JOIN glpi_manufacturers mf ON mf.id=i.manufacturers_id
  WHERE i.is_deleted=0 UNION ALL SELECT ir.racks_id, ir.position, ir.orientation, ir.is_reserved, 'PassiveDCEquipment' AS itemtype, i.id AS item_id, i.name, i.serial,
    m.name AS model, COALESCE(m.required_units,1) AS units, COALESCE(m.power_consumption,0) AS power_w, COALESCE(m.weight,0) AS weight_kg,
    'Patch panel' AS kind, mf.name AS manufacturer
  FROM glpi_items_racks ir
  JOIN glpi_passivedcequipments i ON i.id=ir.items_id AND ir.itemtype='PassiveDCEquipment'
  LEFT JOIN glpi_passivedcequipmentmodels m ON m.id=i.passivedcequipmentmodels_id
  LEFT JOIN glpi_manufacturers mf ON mf.id=i.manufacturers_id
  WHERE i.is_deleted=0 UNION ALL SELECT ir.racks_id, ir.position, ir.orientation, ir.is_reserved, 'Glpi\CustomAsset\SANStorageAsset' AS itemtype, i.id AS item_id, i.name, i.serial,
    m.name AS model, COALESCE(m.required_units,1) AS units, COALESCE(m.power_consumption,0) AS power_w, COALESCE(m.weight,0) AS weight_kg,
    'SAN storage' AS kind, mf.name AS manufacturer
  FROM glpi_items_racks ir
  JOIN glpi_assets_assets i ON i.id=ir.items_id AND ir.itemtype='Glpi\\CustomAsset\\SANStorageAsset'
  LEFT JOIN glpi_assets_assetmodels m ON m.id=i.assets_assetmodels_id
  LEFT JOIN glpi_manufacturers mf ON mf.id=i.manufacturers_id
  WHERE i.is_deleted=0) x
JOIN glpi_racks r ON r.id=x.racks_id
JOIN glpi_dcrooms rm ON rm.id=r.dcrooms_id
JOIN glpi_datacenters dc ON dc.id=rm.datacenters_id
LEFT JOIN glpi_infocoms ic ON ic.itemtype=x.itemtype AND ic.items_id=x.item_id
LEFT JOIN glpi_suppliers s ON s.id=ic.suppliers_id
WHERE r.is_deleted=0
PRSQL;

    private const SQL_RESERVED = <<<'PRSQL'
SELECT ir.racks_id AS rack_id, ir.position FROM glpi_items_racks ir WHERE ir.is_reserved=1
PRSQL;

    /** @return string[] tables of a section that do not exist in this database */
    public static function missing(string $section): array
    {
        /** @var DBmysql $DB */
        global $DB;
        return array_values(array_filter(self::NEEDS[$section], static fn($t) => !$DB->tableExists($t)));
    }

    /** Runs one named query and returns its rows (numbers as numbers). */
    public static function rows(string $name): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $sql = constant('self::SQL_' . strtoupper($name));
        $res = $DB->doQuery($sql);
        $out = [];
        $fields = $res->fetch_fields();
        while ($row = $res->fetch_assoc()) {
            foreach ($fields as $f) {
                $v = $row[$f->name];
                if ($v !== null && in_array($f->type, [MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24], true)) {
                    $row[$f->name] = (int) $v;
                } elseif ($v !== null && in_array($f->type, [MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_FLOAT], true)) {
                    $row[$f->name] = (float) $v;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Capacity requests side by side with their cost: one row per ticket created by a form that has a
     * "Request type" question, with its answers (type, resource, quantity) and the
     * BOQ orders linked to the ticket (Ticket > Items), split by stage.
     */
    public static function demands(): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $cfg = $DB->request(['FROM' => 'glpi_plugin_order_configs', 'LIMIT' => 1])->current() ?: [];
        $committed = array_filter([(int) ($cfg['order_status_approved'] ?? 0), (int) ($cfg['order_status_partially_delivred'] ?? 0),
            (int) ($cfg['order_status_completly_delivered'] ?? 0), (int) ($cfg['order_status_paid'] ?? 0)]);
        $canceled = (int) ($cfg['order_status_canceled'] ?? 0);

        $res = $DB->doQuery("
            SELECT t.id AS ticket_id, t.name AS title, t.date AS opened, t.status, t.priority, t.global_validation,
                   a.answers,
                   COALESCE((SELECT GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') FROM glpi_itils_projects ip JOIN glpi_projects p ON p.id = ip.projects_id
                             WHERE ip.itemtype = 'Ticket' AND ip.items_id = t.id), '(no project)') AS project,
                   COALESCE((SELECT GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') FROM glpi_groups_tickets gt JOIN glpi_groups g ON g.id = gt.groups_id
                             WHERE gt.tickets_id = t.id AND gt.type = 1), '') AS team
            FROM glpi_forms_destinations_answerssets_formdestinationitems l
            JOIN glpi_forms_answerssets a ON a.id = l.forms_answerssets_id
            JOIN glpi_tickets t ON t.id = l.items_id AND l.itemtype = 'Ticket'
            WHERE t.is_deleted = 0 AND a.answers LIKE '%\"Request type\"%'
            ORDER BY t.id");
        $rows = [];
        $qids = [];
        while ($r = $res->fetch_assoc()) {
            $r['answers'] = json_decode((string) $r['answers'], true) ?: [];
            foreach ($r['answers'] as $a) {
                $qids[(int) ($a['question_id'] ?? 0)] = true;
            }
            $rows[] = $r;
        }
        if (!$rows) {
            return [];
        }
        // choice keys (cat1, rt1 ...) -> labels
        $options = [];
        foreach ($DB->request(['SELECT' => ['id', 'extra_data'], 'FROM' => 'glpi_forms_questions', 'WHERE' => ['id' => array_keys($qids)]]) as $q) {
            $x = json_decode((string) $q['extra_data'], true);
            if (is_array($x['options'] ?? null)) {
                $options[(int) $q['id']] = $x['options'];
            }
        }
        // orders linked to the tickets
        $orders = [];
        $ids = array_map(fn($r) => (int) $r['ticket_id'], $rows);
        $ores = $DB->doQuery('
            SELECT it.tickets_id, o.id, o.name, o.plugin_order_orderstates_id AS state, o.port_price, o.suppliers_id,
                   COALESCE((SELECT SUM(i.price_discounted) FROM glpi_plugin_order_orders_items i WHERE i.plugin_order_orders_id = o.id), 0) AS items_total
            FROM glpi_items_tickets it JOIN glpi_plugin_order_orders o ON o.id = it.items_id AND it.itemtype = \'PluginOrderOrder\'
            WHERE o.is_deleted = 0 AND it.tickets_id IN (' . implode(',', $ids) . ')');
        while ($o = $ores->fetch_assoc()) {
            $orders[(int) $o['tickets_id']][] = $o;
        }

        $status = [1 => 'New', 2 => 'Assigned', 3 => 'Planned', 4 => 'Pending', 5 => 'Solved', 6 => 'Closed'];
        $approval = [1 => 'Not required', 2 => 'Waiting', 3 => 'Approved', 4 => 'Refused'];
        $out = [];
        foreach ($rows as $r) {
            $ans = [];
            foreach ($r['answers'] as $a) {
                $raw = $a['raw_answer'] ?? null;
                $qid = (int) ($a['question_id'] ?? 0);
                if (isset($options[$qid]) && (is_string($raw) || (is_array($raw) && array_is_list($raw)))) {
                    $labels = array_map(fn($k) => is_string($k) ? ($options[$qid][$k] ?? $k) : '', (array) $raw);
                    $raw = implode(', ', array_filter($labels));
                }
                $ans[(string) ($a['question_label'] ?? '')] = $raw;
            }
            $get = fn($k) => is_scalar($ans[$k] ?? null) ? trim((string) $ans[$k]) : '';
            $num = fn($k) => (float) (PluginProjectreportsBoqimport::number($get($k)) ?? 0);
            $cat = $get('Resource category');
            $qty = 0.0;
            $unit = '';
            if ($cat === 'Compute') {
                $qty = $num('Quantity');
                $unit = 'server(s)';
            } elseif ($cat === 'Storage') {
                $qty = $num('Capacity') / ($get('Unit') === 'GB' ? 1000 : 1);
                $unit = 'TB';
            } elseif ($cat === 'GPU') {
                $qty = $num('Number of GPUs');
                $unit = 'GPU(s)';
            }
            $boq = $committed_amt = $pipeline = $canceled_amt = 0.0;
            $names = [];
            $suppliers = [];
            foreach ($orders[(int) $r['ticket_id']] ?? [] as $o) {
                $amt = (float) $o['items_total'] + (float) $o['port_price'];
                $st = (int) $o['state'];
                $names[] = $o['name'];
                if ($st === $canceled) {
                    $canceled_amt += $amt;
                    continue;
                }
                $boq += $amt;
                if ((int) $o['suppliers_id'] > 0) {
                    $suppliers[(int) $o['suppliers_id']] = true;
                }
                if (in_array($st, $committed, true)) {
                    $committed_amt += $amt;
                } else {
                    $pipeline += $amt;
                }
            }
            $stage = !$names ? 'no BOQ' : ($boq == 0 ? 'canceled' : ($committed_amt > 0 ? 'committed' : 'estimate'));
            $out[] = [
                'ticket_id' => (int) $r['ticket_id'],
                'title' => $get('Server role') ?: ($get('Storage purpose') ?: ($get('Workload type') ?: ($get('New project name') ?: $r['title']))),
                'opened' => substr((string) $r['opened'], 0, 10),
                'project' => $r['project'] !== '(no project)' ? $r['project'] : ($get('New project name') ? 'New: ' . $get('New project name') : '(no project)'),
                'team' => $r['team'] ?: '(no team)',
                'request_type' => $get('Request type') ?: '(not set)',
                'category' => $cat ?: ($get('Request type') === 'Modernization' ? 'Modernization' : '(other)'),
                'resource_type' => $get('Resource type'),
                'quantity' => $qty,
                'unit' => $unit,
                'boq_cost' => round($boq, 2),
                'committed' => round($committed_amt, 2),
                'pipeline' => round($pipeline, 2),
                'canceled' => round($canceled_amt, 2),
                'cost_per_unit' => $qty > 0 && $boq > 0 ? round($boq / $qty, 2) : null,
                'orders' => implode(', ', $names),
                'supplier' => implode(', ', array_map(fn($id) => Dropdown::getDropdownName('glpi_suppliers', $id), array_keys($suppliers))),
                'stage' => $stage,
                'approval' => $approval[(int) $r['global_validation']] ?? '',
                'status' => $status[(int) $r['status']] ?? '',
                'required_by' => $get('Required by (go-live date)'),
            ];
        }
        return $out;
    }

    /** Everything the page needs, for the sections this user may see. */
    public static function all(): array
    {
        $data = ['today' => date('Y-m-d'), 'sections' => []];
        $sections = [
            'requests' => [PluginProjectreportsMenu::canSeeRequests(), ['requests']],
            'money'    => [PluginProjectreportsMenu::canSeeMoney(), ['finance', 'orders', 'timeline', 'assets']],
            'racks'    => [PluginProjectreportsMenu::canSeeRacks(), ['racks', 'rack_items', 'reserved']],
            'demands'  => [PluginProjectreportsMenu::canSeeRequests() && PluginProjectreportsMenu::canSeeMoney(), ['demands']],
        ];
        foreach ($sections as $section => [$allowed, $queries]) {
            if (!$allowed) {
                $data['sections'][$section] = ['state' => 'no_right'];
                continue;
            }
            $missing = self::missing($section);
            if ($missing) {
                $data['sections'][$section] = ['state' => 'missing', 'tables' => $missing];
                continue;
            }
            try {
                foreach ($queries as $q) {
                    $data[$q] = $q === 'demands' ? self::demands() : self::rows($q);
                }
                $data['sections'][$section] = ['state' => 'ok'];
            } catch (Throwable $e) {
                $data['sections'][$section] = ['state' => 'error', 'message' => $e->getMessage()];
            }
        }
        return $data;
    }
}
