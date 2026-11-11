<?php

/**
 * Tools › LOM catalog: every device model with its pictures, specs, number in use and LOP prices.
 * Read-only; pictures are managed in GLPI's model forms.
 */

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!PluginProjectreportsLom::canView()) {
    Html::displayRightError();
}

$items = PluginProjectreportsLom::items();
$prices = PluginProjectreportsLom::canSeePrices();

// ---- CSV export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="LOM_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['Category', 'Model', 'Product number', 'Rack units', 'Power (W)', 'Weight (kg)', 'Depth', 'In use', 'Pictures', 'Parts in list'];
    if ($prices) {
        array_push($head, 'Lowest LOP price', 'Supplier', 'Suppliers with a price');
    }
    fputcsv($out, $head);
    foreach ($items as $i) {
        $row = [$i['category_label'], $i['name'], $i['part'], $i['units'] ?: '', $i['power'] ?: '', $i['weight'] ?: '',
            $i['depth'] ?: '', $i['in_use'], count($i['pictures']), $i['parts']];
        if ($prices) {
            $best = $i['offers'][0] ?? null;
            array_push($row, $best ? $best['price'] : '', $best ? $best['supplier'] : '', count($i['offers']));
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$h = fn($v) => htmlescape((string) $v);
$self = $CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lom.php';
// filter chips: Servers, Storage, then everything else under Other
$order = ['servers' => 0, 'storage' => 1, 'network' => 2, 'pdus' => 3, 'racks' => 4, 'enclosures' => 5, 'passive' => 6];
$chip = fn($cat) => in_array($cat, ['servers', 'storage', 'network', 'pdus', 'racks'], true) ? $cat : 'other';
$counts = array_count_values(array_map($chip, array_column($items, 'category')));
$cats = array_filter(['servers' => 'Servers', 'storage' => 'Storage', 'network' => 'Switches', 'pdus' => 'PDUs', 'racks' => 'Racks', 'other' => 'Other'],
    fn($k) => isset($counts[$k]) || in_array($k, ['servers', 'pdus', 'racks'], true), ARRAY_FILTER_USE_KEY);
$other_types = array_values(array_unique(array_column(array_filter($items, fn($i) => $chip($i['category']) === 'other'), 'category_label')));


Html::header(PluginProjectreportsLom::getTypeName(), '', 'tools', 'PluginProjectreportsLom');
echo '<link rel="stylesheet" href="' . $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/css/reports.css?v=' . PLUGIN_PROJECTREPORTS_VERSION) . '">';
?>
<style>
.lom .lom-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 14px}
.lom .lom-chips{display:flex;flex-wrap:wrap;gap:6px}
.lom .lom-chip{border:1px solid var(--tblr-border-color,#dadfe5);background:var(--tblr-bg-surface,#fff);border-radius:999px;padding:5px 12px;font-size:13px;cursor:pointer;color:inherit}
.lom .lom-chip[aria-pressed="true"]{background:#15233A;border-color:#15233A;color:#fff}
.lom .lom-chip .n{opacity:.65;margin-left:4px}
.lom .lom-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.lom .lom-card{background:var(--tblr-bg-surface,#fff);border:1px solid var(--tblr-border-color,#e6e8eb);border-radius:10px;overflow:hidden;display:flex;flex-direction:column;cursor:pointer;text-align:left;padding:0;color:inherit;transition:box-shadow .15s,transform .15s}
.lom .lom-card:hover,.lom .lom-card:focus-visible{box-shadow:0 6px 18px rgba(21,35,58,.12);transform:translateY(-1px)}
.lom .lom-pic{aspect-ratio:4/3;background:linear-gradient(180deg,#f6f7f9,#eceef2);display:flex;align-items:center;justify-content:center;position:relative}
.lom .lom-pic img{max-width:100%;max-height:100%;object-fit:contain;padding:10px}
.lom .lom-pic svg{width:72%;height:72%;color:#8a94a6}
.lom .lom-pic .cnt{position:absolute;right:8px;bottom:8px;background:rgba(21,35,58,.75);color:#fff;font-size:11px;border-radius:999px;padding:2px 8px}
.lom .lom-pic .nopic{position:absolute;left:8px;bottom:8px;font-size:11px;color:#8a94a6}
.lom .lom-body{padding:10px 12px 12px;display:flex;flex-direction:column;gap:6px;flex:1}
.lom .lom-name{font-weight:600;font-size:15px;line-height:1.25}
.lom .lom-sub{font-size:12px;color:#6c7a91}
.lom .lom-specs{display:flex;flex-wrap:wrap;gap:4px}
.lom .lom-specs span{font-size:11.5px;background:#eef1f5;border-radius:4px;padding:1px 6px;color:#3a4659}
.lom .lom-foot{display:flex;justify-content:space-between;align-items:baseline;margin-top:auto;padding-top:6px;border-top:1px solid #eef0f3;font-size:12.5px}
.lom .lom-price{font-weight:600}
.lom .lom-empty{padding:40px;text-align:center;color:#6c7a91}
.lom-modal .lom-main{aspect-ratio:4/3;background:#f3f4f7;border-radius:8px;display:flex;align-items:center;justify-content:center}
.lom-modal .lom-main img{max-width:100%;max-height:100%;object-fit:contain}
.lom-modal .lom-main svg{width:60%;height:60%;color:#8a94a6}
.lom-modal .lom-thumbs{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.lom-modal .lom-thumbs button{width:64px;height:48px;border:2px solid transparent;border-radius:6px;background:#f3f4f7;padding:2px}
.lom-modal .lom-thumbs button[aria-current="true"]{border-color:#15233A}
.lom-modal .lom-thumbs img{width:100%;height:100%;object-fit:contain}
</style>
<div class="pr lom">
  <div class="pr-head">
    <div><h2 class="pr-title">LOM catalog</h2>
      <div class="pr-sub">The device models we use, with pictures, specs<?php echo $prices ? ', number in use and supplier prices from the LOPs' : ' and number in use'; ?>. Click a model for its LOM sheet: parts list with spare part numbers, and every device with its age and photo.</div></div>
  </div>
  <div id="lom-v-models">
  <div class="lom-bar" style="margin-bottom:8px">
    <a class="btn btn-sm btn-primary" href="<?php echo $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/plan.php'); ?>">LOM plans &amp; budget</a>
    <span class="small text-secondary">Read-only parts pages:</span>
    <?php foreach (['servers' => 'Servers', 'storage' => 'Storage', 'network' => 'Switches', 'pdus' => 'PDUs', 'racks' => 'Racks'] as $rk => $rl) { ?>
      <a class="btn btn-sm btn-outline-primary" href="<?php echo $h($CFG_GLPI['root_doc'] . '/plugins/projectreports/front/lomtype.php?group=' . $rk); ?>"><?php echo $h($rl); ?></a>
    <?php } ?>
  </div>
  <div class="lom-bar">
    <div class="lom-chips" role="group" aria-label="Category">
      <button type="button" class="lom-chip" data-cat="" aria-pressed="true">All<span class="n"><?php echo count($items); ?></span></button>
      <?php foreach ($cats as $k => $label) { ?>
        <button type="button" class="lom-chip" data-cat="<?php echo $h($k); ?>" aria-pressed="false"<?php echo $k === 'other' ? ' title="' . $h(implode(', ', $other_types)) . '"' : ''; ?>><?php echo $h($label); ?><span class="n"><?php echo (int) ($counts[$k] ?? 0); ?></span></button>
      <?php } ?>
    </div>
    <input type="search" class="form-control" id="lom-q" placeholder="Search model or product number" style="max-width:280px" aria-label="Search">
    <label class="form-check m-0"><input class="form-check-input" type="checkbox" id="lom-used"> <span class="form-check-label">In use only</span></label>
    <select class="form-select" id="lom-sort" style="max-width:190px" aria-label="Sort">
      <option value="name">Sort: name</option><option value="used">Sort: most used</option>
      <?php if ($prices) { ?><option value="price">Sort: lowest price</option><?php } ?>
    </select>
  </div>
  <div class="lom-grid" id="lom-grid"></div>
  </div>
</div>

<div class="modal fade lom-modal" id="lom-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h3 class="modal-title" id="lom-m-title"></h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><div class="row g-4"><div class="col-md-6"><div class="lom-main" id="lom-m-main"></div><div class="lom-thumbs" id="lom-m-thumbs"></div></div>
  <div class="col-md-6" id="lom-m-info"></div></div></div>
</div></div></div>

<script>
(() => {
  const ITEMS = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const PRICES = <?php echo $prices ? 'true' : 'false'; ?>;
  const ORDER = <?php echo json_encode($order); ?>;
  const DRAW = {
    server: '<svg viewBox="0 0 200 80" fill="none" stroke="currentColor" stroke-width="3"><rect x="4" y="10" width="192" height="60" rx="4"/><line x1="4" y1="40" x2="196" y2="40"/>' + [0,1,2,3,4,5].map(i => `<rect x="${14+i*20}" y="16" width="16" height="18" rx="2"/><rect x="${14+i*20}" y="46" width="16" height="18" rx="2"/>`).join('') + '<line x1="144" y1="20" x2="186" y2="20"/><line x1="144" y1="28" x2="186" y2="28"/><circle cx="178" cy="54" r="5"/></svg>',
    switch: '<svg viewBox="0 0 200 50" fill="none" stroke="currentColor" stroke-width="3"><rect x="4" y="8" width="192" height="34" rx="4"/>' + [...Array(12).keys()].map(i => `<rect x="${12+i*12}" y="15" width="9" height="8" rx="1"/><rect x="${12+i*12}" y="27" width="9" height="8" rx="1"/>`).join('') + '<rect x="166" y="16" width="22" height="18" rx="2"/></svg>',
    storage: '<svg viewBox="0 0 200 80" fill="none" stroke="currentColor" stroke-width="3"><rect x="4" y="8" width="192" height="64" rx="4"/>' + [...Array(12).keys()].map(i => `<rect x="${12+i*15}" y="16" width="11" height="48" rx="2"/><line x1="${15+i*15}" y1="22" x2="${20+i*15}" y2="22"/>`).join('') + '</svg>',
    patch: '<svg viewBox="0 0 200 40" fill="none" stroke="currentColor" stroke-width="3"><rect x="4" y="6" width="192" height="28" rx="3"/>' + [...Array(16).keys()].map(i => `<rect x="${14+i*11}" y="14" width="7" height="12" rx="1"/>`).join('') + '</svg>',
    pdu: '<svg viewBox="0 0 60 200" fill="none" stroke="currentColor" stroke-width="3"><rect x="14" y="4" width="32" height="192" rx="4"/>' + [...Array(9).keys()].map(i => `<rect x="21" y="${22+i*18}" width="18" height="12" rx="2"/>`).join('') + '<line x1="30" y1="6" x2="30" y2="14"/></svg>',
    enclosure: '<svg viewBox="0 0 200 110" fill="none" stroke="currentColor" stroke-width="3"><rect x="4" y="4" width="192" height="102" rx="4"/>' + [...Array(8).keys()].map(i => `<rect x="${12+i*23}" y="12" width="18" height="86" rx="2"/><circle cx="${21+i*23}" cy="88" r="2.5"/>`).join('') + '</svg>',
    rack: '<svg viewBox="0 0 100 200" fill="none" stroke="currentColor" stroke-width="3"><rect x="10" y="4" width="80" height="188" rx="4"/><line x1="20" y1="4" x2="20" y2="192"/><line x1="80" y1="4" x2="80" y2="192"/>' + [...Array(7).keys()].map(i => `<rect x="24" y="${14+i*24}" width="52" height="16" rx="2"/>`).join('') + '<line x1="16" y1="192" x2="16" y2="198"/><line x1="84" y1="192" x2="84" y2="198"/></svg>',
    device: '<svg viewBox="0 0 200 80" fill="none" stroke="currentColor" stroke-width="3"><rect x="4" y="10" width="192" height="60" rx="6"/><circle cx="174" cy="40" r="7"/><line x1="20" y1="30" x2="120" y2="30"/><line x1="20" y1="44" x2="100" y2="44"/></svg>',
  };
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const fmt = n => Number(n).toLocaleString('en-US', {maximumFractionDigits: 2});
  const st = {cat: '', q: '', used: false, sort: 'name'};
  const grid = document.getElementById('lom-grid');
  const depth = d => !d ? '' : d >= 1 ? 'full depth' : d === 0.5 ? 'half depth' : Math.round(d * 100) + '% depth';
  const specs = i => [i.units ? i.units + 'U' : '', i.power ? fmt(i.power) + ' W' : '', i.weight ? fmt(i.weight) + ' kg' : '', depth(i.depth)].filter(Boolean);
  const pic = (i, big) => i.pictures.length ? `<img src="${esc(i.pictures[0])}" alt="${esc(i.name)}" loading="lazy">` : DRAW[i.drawing] || DRAW.device;

  function render() {
    const q = st.q.toLowerCase();
    const chip = c => ['servers', 'storage', 'network', 'pdus', 'racks'].includes(c) ? c : 'other';
    let L = ITEMS.filter(i => (!st.cat || chip(i.category) === st.cat) && (!st.used || i.in_use > 0)
      && (!q || (i.name + ' ' + i.part + ' ' + i.category_label + ' ' + i.glpi_type).toLowerCase().includes(q)));
    L.sort(st.sort === 'used' ? (a, b) => b.in_use - a.in_use || a.name.localeCompare(b.name)
      : st.sort === 'price' ? (a, b) => (a.offers[0]?.price ?? Infinity) - (b.offers[0]?.price ?? Infinity)
      : (a, b) => (ORDER[a.category] ?? 9) - (ORDER[b.category] ?? 9) || a.category.localeCompare(b.category) || a.name.localeCompare(b.name));
    if (!L.length) {
      grid.innerHTML = `<div class="lom-empty" style="grid-column:1/-1">${ITEMS.length ? 'No model matches these filters.' : 'No device models yet. Add them in Setup › Dropdowns › Models (e.g. Computer models), with a picture each.'}</div>`;
      return;
    }
    grid.innerHTML = L.map(i => {
      const best = i.offers[0];
      return `<button type="button" class="lom-card" data-id="${esc(i.category + ':' + i.id)}">
        <div class="lom-pic">${pic(i)}${i.pictures.length > 1 ? `<span class="cnt"><i class="ti ti-photo"></i> ${i.pictures.length}</span>` : ''}${i.pictures.length ? '' : '<span class="nopic">no picture yet</span>'}</div>
        <div class="lom-body"><div class="lom-name">${esc(i.name)}</div>
          <div class="lom-sub">${esc(i.category_label)}${i.part ? ' · ' + esc(i.part) : ''}</div>
          <div class="lom-specs">${specs(i).map(s => `<span>${esc(s)}</span>`).join('')}</div>
          <div class="lom-foot"><span>In use: <b>${i.in_use}</b>${i.parts ? ` · ${i.parts} parts` : ''}</span>${PRICES ? (best ? `<span class="lom-price" title="${esc(best.supplier)} · ${esc(best.lop)}">${fmt(best.price)}</span>` : '<span class="lom-sub">no LOP price</span>') : ''}</div>
        </div></button>`;
    }).join('');
  }

  function open(key) {
    const i = ITEMS.find(x => x.category + ':' + x.id === key);
    if (!i) return;
    document.getElementById('lom-m-title').textContent = i.name;
    const main = document.getElementById('lom-m-main'), th = document.getElementById('lom-m-thumbs');
    const show = n => { main.innerHTML = i.pictures.length ? `<img src="${esc(i.pictures[n])}" alt="${esc(i.name)} picture ${n + 1}">` : (DRAW[i.drawing] || DRAW.device);
      th.querySelectorAll('button').forEach((b, k) => b.setAttribute('aria-current', k === n ? 'true' : 'false')); };
    th.innerHTML = i.pictures.length > 1 ? i.pictures.map((p, n) => `<button type="button" data-n="${n}" aria-label="Picture ${n + 1}"><img src="${esc(p)}" alt=""></button>`).join('') : '';
    th.onclick = e => { const b = e.target.closest('button'); if (b) show(+b.dataset.n); };
    show(0);
    const rows = [['Category', i.category_label], ['Product number', i.part || '—'], ['Rack units', i.units ? i.units + ' U' : '—'],
      ['Power', i.power ? fmt(i.power) + ' W' : '—'], ['Weight', i.weight ? fmt(i.weight) + ' kg' : '—'], ['Depth in rack', depth(i.depth) || '—'], ['Recorded in GLPI as', i.glpi_type], ['In use', i.in_use]];
    let html = `<table class="table table-sm"><tbody>${rows.map(([k, v]) => `<tr><th style="width:40%" class="text-secondary fw-normal">${esc(k)}</th><td>${esc(v)}</td></tr>`).join('')}</tbody></table>`;
    if (i.comment) html += `<div class="mb-3" style="white-space:pre-wrap">${esc(i.comment)}</div>`;
    if (false) {
      html += i.offers.length ? `<h4 class="mt-2">Price in the LOPs</h4><table class="table table-sm"><thead><tr><th>Supplier</th><th>LOP</th><th class="text-end">Unit price</th></tr></thead><tbody>${i.offers.map((o, k) =>
          `<tr${k === 0 ? ' class="table-success"' : ''}><td>${esc(o.supplier)}${k === 0 ? ' <span class="badge bg-green text-white">lowest</span>' : ''}</td><td>${esc(o.lop)}</td><td class="text-end">${fmt(o.price)}</td></tr>`).join('')}</tbody></table>`
        : `<div class="alert alert-secondary py-2">No LOP has a price for ${i.part ? 'product number <b>' + esc(i.part) + '</b>' : 'this model (set its product number to match the LOP part number)'}.</div>`;
    }
    html += '<div class="d-flex gap-2 flex-wrap mt-2">';
    html += `<a class="btn btn-primary" href="${esc(i.sheet_url)}"><i class="ti ti-list-details"></i> Open LOM sheet: parts, devices, age, photos</a>`;
    if (i.in_use && i.assets_url) html += `<a class="btn btn-outline-secondary" href="${esc(i.assets_url)}"><i class="ti ti-list"></i> See the ${i.in_use} device(s)</a>`;
    html += '</div>';
    document.getElementById('lom-m-info').innerHTML = html;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('lom-modal')).show();
  }

  document.querySelector('#lom-v-models .lom-chips').addEventListener('click', e => {
    const b = e.target.closest('.lom-chip'); if (!b) return;
    document.querySelectorAll('#lom-v-models .lom-chip').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
    st.cat = b.dataset.cat; render();
  });
  document.getElementById('lom-q').addEventListener('input', e => { st.q = e.target.value; render(); });
  document.getElementById('lom-used').addEventListener('change', e => { st.used = e.target.checked; render(); });
  document.getElementById('lom-sort').addEventListener('change', e => { st.sort = e.target.value; render(); });
  grid.addEventListener('click', e => { const c = e.target.closest('.lom-card'); if (c) open(c.dataset.id); });
  render();

})();
</script>
<?php
Html::footer();
