<?php

/**
 * Orders › order › "Add from LOP" tab: add the lines of a supplier's LOP to this order, with the LOP's quantities
 * (changeable), unit prices (before tax) and VAT. Only while the order may still be changed (Draft or Waiting for
 * approval), and only for users who may create orders and catalog references.
 */
class PluginProjectreportsOrderlop extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Add from LOP';
    }

    public static function getIcon()
    {
        return 'ti ti-file-import';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginOrderOrder || $item->isNewItem() || !PluginProjectreportsBoqimport::canImport() || !PluginProjectreportsLop::info()) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(), 0, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof PluginOrderOrder) {
            self::show($item);
        }
        return true;
    }

    /** Orders plugin item type for a LOP line: the catalog's type, else from the Device column, else PluginOrderOther. */
    public static function itemtype(array $row, array $types): string
    {
        $map = ['Server' => 'Computer', 'Storage' => 'NetworkEquipment', 'Switch' => 'NetworkEquipment', 'PDU' => 'PDU', 'Rack' => 'Rack'];
        $t = $map[(string) $row['device']] ?? '';
        if ($t !== '' && isset($types[strtolower($t)])) {
            return $types[strtolower($t)];
        }
        return isset($types['pluginorderother']) ? 'PluginOrderOther' : (string) reset($types);
    }

    private static function money(float $v): string
    {
        return PluginProjectreportsAutoboq::fmtNum($v);
    }

    public static function show(PluginOrderOrder $order): void
    {
        /** @var array $CFG_GLPI */
        /** @var DBmysql $DB */
        global $CFG_GLPI, $DB;
        $h = fn($v) => htmlescape((string) $v);
        $oid = (int) $order->getID();
        $info = PluginProjectreportsLop::info();
        $order_sid = (int) $order->fields['suppliers_id'];
        $sid = isset($info[$order_sid]) ? $order_sid : (int) array_key_first($info);
        $editable = $order->canUpdateOrder() && $order->can($oid, UPDATE);

        echo '<div class="p-2" style="max-width:1300px">';
        if (!$editable) {
            echo '<div class="alert alert-warning">This order\'s status is <b>' . $h(Dropdown::getDropdownName('glpi_plugin_order_orderstates', (int) $order->fields['plugin_order_orderstates_id']))
                . '</b>: the Orders plugin only lets you add lines while an order is <b>Draft</b> (or waiting for approval). '
                . 'Change the status back to Draft in the order\'s main tab, or create a new order with status Draft, then add the LOP here.</div>';
        }
        echo '<div class="d-flex flex-wrap gap-3 align-items-end mb-2"><div><label class="form-label" for="pr-ol-sel">LOP</label><select class="form-select" id="pr-ol-sel">';
        foreach ($info as $s => $i) {
            echo '<option value="' . (int) $s . '"' . ((int) $s === $sid ? ' selected' : '') . '>' . $h(Dropdown::getDropdownName('glpi_suppliers', (int) $s) . ' · ' . $i['name']) . '</option>';
        }
        echo '</select></div><div class="small text-secondary pb-2">Order supplier: <b>' . $h($order_sid ? Dropdown::getDropdownName('glpi_suppliers', $order_sid) : 'none yet (it is set to the LOP\'s supplier)') . '</b></div></div>';

        foreach ($info as $s => $i) {
            $s = (int) $s;
            $vat = (float) ($i['vat'] ?? 0);
            $rows = iterator_to_array($DB->request(['FROM' => PluginProjectreportsLop::TABLE, 'WHERE' => ['suppliers_id' => $s], 'ORDER' => ['device DESC', 'part']]), false);
            echo '<div class="pr-ol" data-sid="' . $s . '"' . ($s === $sid ? '' : ' hidden') . '>';
            if ($order_sid && $order_sid !== $s) {
                echo '<div class="alert alert-warning py-2">This LOP is from another supplier than the order\'s supplier.</div>';
            }
            echo '<form method="post" action="' . $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/orderlop.form.php') . '">'
                . '<input type="hidden" name="orders_id" value="' . $oid . '"><input type="hidden" name="suppliers_id" value="' . $s . '">';
            echo '<div class="table-responsive"><table class="table table-sm table-vcenter card-table pr-ol-t" data-vat="' . $h($vat) . '"><thead><tr>'
                . '<th style="width:30px"><input class="form-check-input pr-ol-all" type="checkbox" checked aria-label="Select all"></th><th>Part Number</th><th>Device</th><th>Spare Part number</th><th>Description</th>'
                . '<th class="text-end" style="width:100px">Qty</th><th class="text-end">Unit price</th><th class="text-end">Total before tax</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                echo '<tr><td><input class="form-check-input pr-ol-c" type="checkbox" name="line[' . $id . ']" value="1" checked aria-label="Add ' . $h($r['part']) . '"></td>'
                    . '<td class="text-nowrap fw-bold">' . $h($r['part']) . '</td><td>' . ($r['device'] !== '' ? '<span class="badge bg-blue-lt">' . $h($r['device']) . '</span>' : '<span class="text-secondary">part</span>') . '</td>'
                    . '<td class="text-nowrap">' . $h($r['spare']) . '</td><td class="small">' . $h($r['description']) . '</td>'
                    . '<td><input class="form-control form-control-sm text-end pr-ol-q" type="number" min="0" step="1" name="qty[' . $id . ']" value="' . (int) $r['qty'] . '" aria-label="Qty ' . $h($r['part']) . '"></td>'
                    . '<td class="text-end pr-ol-p" data-p="' . $h($r['price']) . '">' . $h(self::money((float) $r['price'])) . '</td><td class="text-end pr-ol-l"></td></tr>';
            }
            echo '</tbody><tfoot><tr class="fw-bold"><td colspan="5">Total before tax</td><td class="text-end pr-ol-tq"></td><td></td><td class="text-end pr-ol-tp"></td></tr>'
                . '<tr><td colspan="7">VAT ' . $h(self::money($vat)) . '%</td><td class="text-end pr-ol-tv"></td></tr>'
                . '<tr class="fw-bold"><td colspan="7">Total with VAT</td><td class="text-end pr-ol-tt"></td></tr></tfoot></table></div>';
            echo '<div class="d-flex flex-wrap gap-3 align-items-center mt-2"><button type="submit" class="btn btn-primary"' . ($editable && $rows ? '' : ' disabled') . '><i class="ti ti-plus"></i> Add the selected lines to this order</button>'
                . '<span class="small text-secondary">Each line is added with its Qty, unit price before tax and VAT ' . $h(self::money($vat)) . '%. Lines already in the order are not removed: adding twice adds the quantities again.</span></div>';
            Html::closeForm();
            echo '</div>';
        }
        echo '</div>';
        echo <<<'JS'
<script>
(() => {
    const f = n => n.toLocaleString('en-US', {maximumFractionDigits: 2});
    const calc = t => {
        let q = 0, p = 0;
        t.querySelectorAll('tbody tr').forEach(r => {
            const n = Math.max(0, parseInt(r.querySelector('.pr-ol-q').value || '0', 10)), line = n * parseFloat(r.querySelector('.pr-ol-p').dataset.p);
            r.querySelector('.pr-ol-l').textContent = f(line);
            if (r.querySelector('.pr-ol-c').checked) { q += n; p += line; }
        });
        const v = parseFloat(t.dataset.vat) || 0;
        t.querySelector('.pr-ol-tq').textContent = f(q); t.querySelector('.pr-ol-tp').textContent = f(p);
        t.querySelector('.pr-ol-tv').textContent = f(p * v / 100); t.querySelector('.pr-ol-tt').textContent = f(p * (1 + v / 100));
    };
    document.querySelectorAll('.pr-ol-t').forEach(t => {
        t.addEventListener('input', () => calc(t));
        t.addEventListener('change', e => {
            if (e.target.classList.contains('pr-ol-all')) t.querySelectorAll('.pr-ol-c').forEach(c => c.checked = e.target.checked);
            calc(t);
        });
        calc(t);
    });
    const sel = document.getElementById('pr-ol-sel');
    sel && sel.addEventListener('change', () => document.querySelectorAll('.pr-ol').forEach(d => d.hidden = d.dataset.sid !== sel.value));
})();
</script>
JS;
    }

    /**
     * Add the chosen lines of supplier $sid's LOP to order $oid. $qty: [LOP line id => quantity].
     * @return array stats of PluginProjectreportsBoqimport::addLines
     */
    public static function add(PluginOrderOrder $order, int $sid, array $qty): array
    {
        /** @var DBmysql $DB */
        global $DB;
        $qty = array_filter(array_map('intval', $qty), fn($q) => $q > 0);
        if (!$qty) {
            throw new RuntimeException('Select at least one line with a quantity of 1 or more.');
        }
        $types = PluginProjectreportsBoqimport::itemTypes();
        $lines = [];
        foreach ($DB->request(['FROM' => PluginProjectreportsLop::TABLE, 'WHERE' => ['suppliers_id' => $sid, 'id' => array_keys($qty)], 'ORDER' => ['device DESC', 'part']]) as $r) {
            $ref = $DB->request(['SELECT' => ['id', 'itemtype'], 'FROM' => 'glpi_plugin_order_references',
                'WHERE' => ['name' => $r['part'], 'is_deleted' => 0], 'LIMIT' => 1])->current();
            $lines[] = ['part' => (string) $r['part'], 'spare' => (string) $r['spare'], 'desc' => (string) $r['description'], 'model' => '',
                'qty' => $qty[(int) $r['id']], 'price' => (float) $r['price'],
                'reference' => $ref ? (int) $ref['id'] : 0, 'itemtype' => $ref ? (string) $ref['itemtype'] : self::itemtype($r, $types)];
        }
        if (!$lines) {
            throw new RuntimeException('These lines are no longer in the LOP.');
        }
        $oid = (int) $order->getID();
        if ((int) $order->fields['suppliers_id'] === 0) {
            $order->update(['id' => $oid, 'suppliers_id' => $sid]);
        }
        return PluginProjectreportsBoqimport::addLines($oid, $lines, (int) $order->fields['entities_id'], $sid,
            PluginProjectreportsLop::orderTaxId(PluginProjectreportsLop::vat($sid)), true);
    }
}
