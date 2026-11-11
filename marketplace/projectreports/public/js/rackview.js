/* Project reports - Rack view tab: floor plan, rack elevations, capacity, device details. */
(function () {
  'use strict';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const fmt = n => Math.round(n || 0).toLocaleString('en-US');
  const shortv = v => v >= 1e6 ? (v / 1e6).toFixed(2).replace(/\.?0+$/, '') + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'K' : fmt(v);
  const TYPE = {'Server': '#3E7CC0', 'Switch': '#2E9E8F', 'Core switch': '#1D5E7A', 'SAN storage': '#C7663A', 'Patch panel': '#8C97A5'};
  const W = {ok: '#1AAB40', warn: '#D9B300', bad: '#D64550', none: '#A0A0A0'};
  const lvl = x => x >= 0.9 ? W.bad : x >= 0.75 ? W.warn : W.ok;
  const FORM = {Computer: 'computer', NetworkEquipment: 'networkequipment', PassiveDCEquipment: 'passivedcequipment'};
  const st = {site: null, mode: 'project', rack: null, dev: null, hidden: new Set()};

  function render(root, D, PROJC) {
    const racks = D.racks || [], items = D.rack_items || [], reserved = D.reserved || [];
    const today = new Date(D.today + 'T00:00:00');
    if (!racks.length) { root.innerHTML = '<div class="pr-msg">No racks yet. Create data centers, server rooms and racks in GLPI (Assets › Data centers), then place devices in the racks.</div>'; return; }
    const projColor = p => (p && PROJC[p]) || '#8C97A5';
    const days = d => d ? Math.round((new Date(d + 'T00:00:00') - today) / 864e5) : null;
    const wState = it => { const d = days(it.warranty_end); if (d === null) return ['No warranty data', W.none]; if (d < 0) return ['Expired', W.bad]; if (d <= 180) return ['Ends within 6 months', W.warn]; return ['Under warranty', W.ok]; };
    const colorOf = it => st.mode === 'project' ? projColor(it.project) : st.mode === 'type' ? (TYPE[it.kind] || '#8C97A5') : wState(it)[1];
    const sites = [...new Set(racks.map(r => r.datacenter + ' · ' + r.room))].sort();
    if (!sites.includes(st.site)) st.site = sites[0];
    const siteRacks = () => racks.filter(r => r.datacenter + ' · ' + r.room === st.site);
    const itemsOf = id => items.filter(i => i.rack_id === id);
    const stats = r => { const it = itemsOf(r.rack_id); const u = it.reduce((a, i) => a + i.units, 0), res = reserved.filter(x => x.rack_id === r.rack_id).length;
      const p = it.reduce((a, i) => a + i.power_w, 0), w = it.reduce((a, i) => a + i.weight_kg, 0);
      return {it, u, res, free: r.rack_units - u - res, p, w, up: u / r.rack_units, pp: r.max_power ? p / r.max_power : 0, wp: r.max_weight ? w / r.max_weight : 0}; };
    const projects = [...new Set(items.map(i => i.project || ''))].sort();

    const rs = siteRacks();
    if (!rs.some(r => r.rack_id === st.rack)) { st.rack = rs.map(r => [r, stats(r)]).sort((a, b) => b[1].up - a[1].up)[0][0].rack_id; st.dev = null; }
    const all = rs.map(stats), its = all.flatMap(s => s.it);
    const U = rs.reduce((a, r) => a + r.rack_units, 0), used = all.reduce((a, s) => a + s.u, 0), P = rs.reduce((a, r) => a + r.max_power, 0), pw = all.reduce((a, s) => a + s.p, 0);
    const exp = its.filter(i => days(i.warranty_end) !== null && days(i.warranty_end) < 0).length;
    const soon = its.filter(i => { const d = days(i.warranty_end); return d !== null && d >= 0 && d <= 180; }).length;

    let h = `<div class="pr-rack">
      <div class="rk-controls">
        <div><div class="rk-lbl">Site</div><div class="rk-seg">${sites.map(s => `<button type="button" data-site="${esc(s)}" aria-pressed="${s === st.site}">${esc(s)}</button>`).join('')}</div></div>
        <div><div class="rk-lbl">Colour by</div><div class="rk-seg">${[['project', 'Project'], ['warranty', 'Warranty'], ['type', 'Device type']].map(([m, l]) => `<button type="button" data-mode="${m}" aria-pressed="${m === st.mode}">${l}</button>`).join('')}</div></div>
      </div>
      <div class="pr-k6" style="margin-bottom:12px">
        ${[[rs.length, 'Racks', `${all.filter(s => s.u === 0).length} empty`], [its.length, 'Devices', `${its.filter(i => i.kind === 'Server').length} servers · ${its.filter(i => i.kind === 'SAN storage').length} SAN`],
          [Math.round(100 * used / U) + '%', 'Space used', `${used} of ${U} U`], [(pw / 1000).toFixed(1) + ' kW', 'Power planned', `of ${(P / 1000).toFixed(0)} kW capacity`],
          [exp, 'Warranties expired', `${soon} more end within 6 months`, exp ? W.bad : W.ok], [fmt(its.reduce((a, i) => a + (i.value || 0), 0)), 'Installed value', 'from Financial information']]
          .map(([v, l, s, c]) => `<div class="pr-card pr-kpi"><div class="v" style="color:${c || 'inherit'}">${esc(v)}</div><div class="l">${esc(l)} · ${esc(s)}</div></div>`).join('')}
      </div>
      <div class="rk-main"><div class="rk-left">
        <div class="pr-card"><h3>Floor plan · ${esc(st.site)}</h3><div class="rk-legend"><span><i style="background:${W.ok}"></i>under 75 % full</span><span><i style="background:${W.warn}"></i>75–90 %</span><span><i style="background:${W.bad}"></i>over 90 % (space or power)</span></div><div class="rk-floor"><div class="rk-grid" id="rk-floor"></div></div></div>
        <div class="pr-card"><h3>Rack elevations · front</h3>
          <div class="chips" style="margin:4px 0 8px">${projects.map(p => `<button type="button" data-proj="${esc(p)}" aria-pressed="${!st.hidden.has(p)}"><i style="background:${projColor(p)}"></i>${esc(p || 'No project')}</button>`).join('')}</div>
          <div class="rk-legend" id="rk-legend"></div><div id="rk-rows"></div></div>
      </div><div class="rk-side"><div class="pr-card" id="rk-rack"></div><div class="pr-card" id="rk-dev"></div><div class="pr-card" id="rk-fp"></div></div></div></div>`;
    root.innerHTML = h;
    const q = s => root.querySelector(s);
    root.querySelectorAll('[data-site]').forEach(b => b.onclick = () => { st.site = b.dataset.site; st.rack = null; st.dev = null; render(root, D, PROJC); });
    root.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => { st.mode = b.dataset.mode; render(root, D, PROJC); });
    root.querySelectorAll('[data-proj]').forEach(b => b.onclick = () => { const p = b.dataset.proj; st.hidden.has(p) ? st.hidden.delete(p) : st.hidden.add(p); render(root, D, PROJC); });

    // floor plan
    const cols = rs[0].vis_cols || 8, rows = rs[0].vis_rows || 3, g = q('#rk-floor');
    g.style.gridTemplateColumns = `repeat(${cols}, minmax(52px, 1fr))`;
    const byPos = {}; rs.forEach(r => byPos[r.rack_pos] = r);
    const usedRows = [...new Set(rs.map(r => +String(r.rack_pos).split(',')[1]))].sort((a, b) => a - b);
    let fh = '';
    for (let y = 1; y <= Math.max(rows, ...usedRows); y++) {
      if (!usedRows.includes(y)) { if (y > usedRows[0] && y < usedRows[usedRows.length - 1]) fh += '<div class="rk-aisle">aisle</div>'; continue; }
      for (let x = 1; x <= cols; x++) {
        const r = byPos[x + ',' + y];
        if (!r) { fh += '<div class="rk-cell"></div>'; continue; }
        const s = stats(r), shares = {};
        s.it.forEach(i => { const c = colorOf(i); shares[c] = (shares[c] || 0) + i.units; });
        fh += `<button type="button" class="rk-tile" data-rack="${r.rack_id}" aria-pressed="${st.rack === r.rack_id}" title="${esc(r.rack)}: ${s.u} U used, ${s.free} U free, ${(s.p / 1000).toFixed(1)} kW">
          <span class="dot" style="background:${lvl(Math.max(s.up, s.pp))}"></span><b>${esc(r.rack)}</b><small>${Math.round(s.up * 100)}% U · ${(s.p / 1000).toFixed(1)} kW</small>
          <span class="bar">${Object.entries(shares).map(([c, u]) => `<i style="width:${100 * u / r.rack_units}%;background:${c}"></i>`).join('')}</span></button>`;
      }
    }
    g.innerHTML = fh;
    g.querySelectorAll('[data-rack]').forEach(b => b.onclick = () => { st.rack = +b.dataset.rack; st.dev = null; render(root, D, PROJC); const e = root.querySelector('#rk' + st.rack); if (e) e.scrollIntoView({block: 'nearest', inline: 'center'}); });

    // legend
    const leg = st.mode === 'project' ? projects.map(p => [p || 'No project (infrastructure)', projColor(p)]) : st.mode === 'type' ? Object.entries(TYPE)
      : [['Under warranty', W.ok], ['Ends within 6 months', W.warn], ['Expired', W.bad], ['No warranty data', W.none]];
    q('#rk-legend').innerHTML = leg.map(([l, c]) => `<span><i style="background:${c}"></i>${esc(l)}</span>`).join('') + '<span><i style="background:repeating-linear-gradient(135deg,#7a8591 0 3px,transparent 3px 6px)"></i>Reserved U</span>';

    // elevations by row
    const byRow = {}; rs.forEach(r => { const y = +String(r.rack_pos).split(',')[1]; (byRow[y] = byRow[y] || []).push(r); });
    let rh = '';
    Object.keys(byRow).sort((a, b) => a - b).forEach((y, idx) => {
      rh += `<div class="rk-rowname">Row ${String.fromCharCode(65 + idx)} · ${byRow[y].length} racks</div><div class="rk-elev">`;
      byRow[y].sort((a, b) => +String(a.rack_pos).split(',')[0] - +String(b.rack_pos).split(',')[0]).forEach(r => {
        const s = stats(r), n = r.rack_units || 42;
        rh += `<div class="rk${st.rack === r.rack_id ? ' sel' : ''}" id="rk${r.rack_id}"><div class="rk-h"><b>${esc(r.rack)}</b><span>${s.free} U free</span></div>
          <div class="rk-frame"><div class="rk-units" style="grid-template-rows:repeat(${n},13px)">${Array.from({length: n}, (_, i) => `<div>${n - i}</div>`).join('')}</div>
          <div class="rk-slots" style="grid-template-rows:repeat(${n},13px)">${'<div class="s"></div>'.repeat(n)}
          ${reserved.filter(x => x.rack_id === r.rack_id).map(x => `<div class="dev res" style="top:${(n - x.position) * 13 + 1}px;height:11px" title="Reserved U${x.position}"><span>reserved</span></div>`).join('')}
          ${s.it.map((it, k) => `<button type="button" class="dev${st.hidden.has(it.project || '') ? ' dim' : ''}${st.dev && st.dev.itemtype === it.itemtype && st.dev.item_id === it.item_id ? ' pick' : ''}" data-i="${r.rack_id}:${k}"
              style="top:${(n - (it.position + it.units - 1)) * 13 + 1}px;height:${it.units * 13 - 2}px;background:${colorOf(it)}"
              title="U${it.position}${it.units > 1 ? '–' + (it.position + it.units - 1) : ''} · ${esc(it.name)} · ${esc(it.model || it.kind)} · ${esc(it.project || 'No project')}"><span>${esc(it.name)}</span>${it.kind !== 'Patch panel' ? '<i class="led"></i>' : ''}</button>`).join('')}
          </div></div>
          <div class="rk-foot"><div>space<b>${Math.round(s.up * 100)}%</b></div><div>power<b>${(s.p / 1000).toFixed(1)} kW</b></div><div>weight<b>${fmt(s.w)} kg</b></div></div></div>`;
      });
      rh += '</div>';
    });
    q('#rk-rows').innerHTML = rh;
    root.querySelectorAll('[data-i]').forEach(b => b.onclick = () => { const [rid, k] = b.dataset.i.split(':'); st.rack = +rid; st.dev = itemsOf(+rid)[+k]; render(root, D, PROJC); });

    // rack panel
    const r = racks.find(x => x.rack_id === st.rack), s = stats(r), byP = {};
    s.it.forEach(i => { const k = i.project || ''; byP[k] = byP[k] || {n: 0, u: 0, v: 0}; byP[k].n++; byP[k].u += i.units; byP[k].v += i.value || 0; });
    const m = (l, v, max, unit, x) => `<div><div class="t"><span>${l}</span><span>${v} / ${max} ${unit}</span></div><div class="rk-meter"><i style="width:${Math.min(100, x * 100)}%;background:${lvl(x)}"></i></div></div>`;
    q('#rk-rack').innerHTML = `<h3>Rack ${esc(r.rack)} <span class="pr-note">${esc(r.datacenter)} · ${esc(r.room)}</span></h3>
      <div class="rk-cap">${m('Space', s.u + (s.res ? ` (+${s.res} reserved)` : ''), r.rack_units, 'U', s.up)}${m('Power', fmt(s.p), fmt(r.max_power), 'W', s.pp)}${m('Weight', fmt(s.w), fmt(r.max_weight), 'kg', s.wp)}</div>
      <div class="pr-tbl" style="margin-top:10px;max-height:none"><table><thead><tr><th>Project</th><th class="r">Devices</th><th class="r">U</th><th class="r">Value</th></tr></thead><tbody>
      ${Object.entries(byP).sort((a, b) => b[1].u - a[1].u).map(([k, v]) => `<tr><td><span class="pr-dot" style="background:${projColor(k)}"></span>${esc(k || 'No project')}</td><td class="r">${v.n}</td><td class="r">${v.u}</td><td class="r">${fmt(v.v)}</td></tr>`).join('') || `<tr><td colspan="4" class="pr-empty">Empty rack</td></tr>`}</tbody></table></div>`;

    // device panel
    const it = st.dev;
    if (!it) q('#rk-dev').innerHTML = '<h3>Device</h3><div class="pr-note">Click a device in a rack to see its model, serial number, supplier, warranty and parts.</div>';
    else {
      const [ws, wc] = wState(it), d = days(it.warranty_end), form = FORM[it.itemtype];
      q('#rk-dev').innerHTML = `<h3 style="display:flex;justify-content:space-between;gap:8px"><span style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px">${esc(it.name)}</span><span class="pill" style="background:${wc}22;color:${wc}">${ws}</span></h3>
        <dl><dt>Type</dt><dd>${esc(it.kind)}</dd><dt>Model</dt><dd>${esc(it.model || '–')}</dd><dt>Manufacturer</dt><dd>${esc(it.manufacturer || '–')}</dd>
        <dt>Serial</dt><dd>${esc(it.serial || '–')}</dd><dt>Position</dt><dd>${esc(it.rack)} · U${it.position}${it.units > 1 ? '–' + (it.position + it.units - 1) : ''}</dd>
        <dt>Project</dt><dd><span class="pill" style="background:${projColor(it.project)}22;color:${projColor(it.project)}">${esc(it.project || 'No project')}</span></dd>
        <dt>Supplier</dt><dd>${esc(it.supplier || '–')}</dd><dt>Purchase value</dt><dd>${it.value != null ? fmt(it.value) : '–'}</dd><dt>Bought</dt><dd>${esc(it.buy_date || '–')}</dd>
        <dt>Warranty ends</dt><dd>${esc(it.warranty_end || '–')}${d !== null ? ` (${d < 0 ? -d + ' days ago' : 'in ' + d + ' days'})` : ''}</dd>
        <dt>Power · weight</dt><dd>${fmt(it.power_w)} W · ${fmt(it.weight_kg)} kg</dd></dl>
        ${it.kind === 'Server' ? `<div class="parts"><div class="part"><b>${Math.round(it.ram_mb / 1024)} GB</b>RAM · ${it.ram_modules} modules</div><div class="part"><b>${(it.disk_mb / 1048576).toFixed(1)} TB</b>disks · ${it.disks} drives</div>
          <div class="part"><b>${it.nics}</b>network cards</div><div class="part"><b>${it.batteries}</b>controller batteries</div></div>` : ''}
        ${form ? `<a class="btn btn-primary btn-sm btn-open" href="${esc(D.root)}/front/${form}.form.php?id=${it.item_id}">Open in GLPI</a>` : ''}`;
    }

    // project footprint for the site
    const fp = {};
    its.forEach(i => { const k = i.project || ''; const b = fp[k] = fp[k] || {n: 0, u: 0, p: 0, v: 0, e: 0}; b.n++; b.u += i.units; b.p += i.power_w; b.v += i.value || 0; const dd = days(i.warranty_end); if (dd !== null && dd <= 180) b.e++; });
    q('#rk-fp').innerHTML = `<h3>Project footprint <span class="pr-note">${esc(st.site.split(' · ')[0])}</span></h3><div class="pr-tbl" style="max-height:none"><table><thead><tr><th>Project</th><th class="r">Dev.</th><th class="r">U</th><th class="r">kW</th><th class="r">Value</th><th class="r" title="Warranty expired or ending within 6 months">Warr.</th></tr></thead><tbody>
      ${Object.entries(fp).sort((a, b) => b[1].u - a[1].u).map(([k, v]) => `<tr><td><span class="pr-dot" style="background:${projColor(k)}"></span>${esc(k || 'Infra')}</td><td class="r">${v.n}</td><td class="r">${v.u}</td><td class="r">${(v.p / 1000).toFixed(1)}</td><td class="r">${shortv(v.v)}</td><td class="r"${v.e ? ` style="color:${W.warn};font-weight:600"` : ''}>${v.e}</td></tr>`).join('')}</tbody></table></div>`;
  }
  window.PRRack = {render};
})();
