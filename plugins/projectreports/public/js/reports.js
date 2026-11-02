/* Project reports - renders the report tabs from the JSON the page embeds. */
(function () {
  'use strict';
  const D = JSON.parse(document.getElementById('pr-data').textContent);
  const app = document.getElementById('pr-app');
  const ROOT = D.root || '';

  // ---------------------------------------------------------------- helpers
  const PAL = ['#118DFF', '#E66C37', '#12239E', '#6B007B', '#1AAB40', '#D9B300', '#744EC2', '#D64550', '#8AD4EB', '#E044A7'];
  const PRIO = {'1 Very low': '#8AD4EB', '2 Low': '#1AAB40', '3 Medium': '#D9B300', '4 High': '#E66C37', '5 Very high': '#D64550', '6 Major': '#6B007B'};
  const STAT = {New: '#118DFF', Assigned: '#12239E', Planned: '#8AD4EB', Pending: '#D9B300', Solved: '#1AAB40', Closed: '#A0A0A0'};
  const TYPES = {'Server': '#118DFF', 'Network device': '#12239E', 'SAN storage': '#E66C37'};
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const fmt = (n, d) => { d = typeof d === 'number' ? d : 0; return (n == null || isNaN(n)) ? '' : Number(n).toLocaleString('en-US', {minimumFractionDigits: d, maximumFractionDigits: d}); };
  const trim = (v, d) => v.toFixed(d).replace(/\.0+$|(\.\d*?)0+$/, '$1');
  const short = v => { const a = Math.abs(v); if (a >= 1e12) return trim(v / 1e12, 2) + 'T'; if (a >= 1e9) return trim(v / 1e9, 2) + 'B'; if (a >= 1e6) return trim(v / 1e6, 2) + 'M'; if (a >= 1e3) return Math.round(v / 1e3) + 'K'; return fmt(v); };
  const pct = v => (v == null ? '' : (v * 100).toFixed(1) + '%');
  const uniq = a => [...new Set(a)];
  const sum = (a, f) => a.reduce((s, r) => s + (Number(f(r)) || 0), 0);
  const monthLabel = m => new Date(m + '-01T00:00:00').toLocaleDateString('en-US', {month: 'short', year: 'numeric'});

  const allProjects = uniq([].concat((D.requests || []).map(r => r.project), (D.finance || []).map(r => r.project), (D.demands || []).map(r => r.project))).filter(p => p && p !== '(no project)').sort();
  const PROJC = {}; allProjects.forEach((p, i) => PROJC[p] = PAL[i % PAL.length]); PROJC['(no project)'] = '#A0A0A0';

  let charts = [];
  function chart(el, option, onClick) {
    const c = echarts.init(el, null, {renderer: 'canvas'});
    option.textStyle = {fontFamily: 'inherit'};
    option.animationDuration = 300;
    c.setOption(option);
    if (onClick) c.on('click', onClick);
    charts.push(c);
    return c;
  }
  window.addEventListener('resize', () => charts.forEach(c => c.resize()));

  const AX = {axisLabel: {color: '#605E5C', fontSize: 11}, axisLine: {show: false}, axisTick: {show: false}, splitLine: {lineStyle: {color: '#EDEBE9'}}};
  const LEG = {top: 0, left: 0, type: 'scroll', icon: 'circle', itemWidth: 8, itemHeight: 8, itemGap: 12, textStyle: {color: '#605E5C', fontSize: 11}};

  function colOpt(cats, series, o = {}) {
    const ca = Object.assign({}, AX, {type: 'category', data: cats, splitLine: {show: false}, axisLabel: Object.assign({}, AX.axisLabel, {interval: 0, hideOverlap: true})});
    const va = Object.assign({}, AX, {type: 'value', axisLabel: Object.assign({}, AX.axisLabel, o.money ? {formatter: short} : {})});
    const ss = series.map(s => ({
      name: s.name, type: 'bar', data: s.data, itemStyle: {color: s.color}, barMaxWidth: 44, barGap: '8%',
      stack: o.stacked === false ? undefined : 'a', emphasis: {focus: 'series'},
      label: o.labels ? {show: true, position: o.horizontal ? 'right' : 'top', fontSize: 10, color: '#252423', formatter: p => p.value ? short(p.value) : ''} : undefined,
    }));
    const opt = {grid: {left: 8, right: 16, top: 30, bottom: 6, containLabel: true}, legend: LEG,
      tooltip: {trigger: 'axis', axisPointer: {type: 'shadow'}, valueFormatter: v => o.money ? fmt(v) : fmt(v)}, series: ss};
    if (o.horizontal) { ca.inverse = true; opt.xAxis = va; opt.yAxis = ca; } else { opt.xAxis = ca; opt.yAxis = va; }
    return opt;
  }
  function donutOpt(items) {
    return {tooltip: {trigger: 'item'}, legend: LEG,
      series: [{type: 'pie', radius: ['45%', '70%'], center: ['50%', '58%'], itemStyle: {borderColor: '#fff', borderWidth: 1},
        label: {formatter: p => short(p.value) + ' (' + p.percent + '%)', color: '#605E5C', fontSize: 10}, labelLine: {length: 8, length2: 8},
        data: items.map(([n, v, c]) => ({name: n, value: v, itemStyle: {color: c}}))}]};
  }
  function gaugeOpt(v) {
    const col = v >= 1 ? '#D64550' : v >= 0.8 ? '#D9B300' : '#118DFF';
    return {series: [
      {type: 'gauge', startAngle: 200, endAngle: -20, min: 0, max: 1.2, radius: '95%', center: ['50%', '60%'],
        progress: {show: true, width: 18, itemStyle: {color: col}}, axisLine: {lineStyle: {width: 18, color: [[1, '#EDEBE9']]}},
        pointer: {show: false}, axisTick: {show: false}, splitLine: {show: false},
        axisLabel: {show: false},
        detail: {offsetCenter: [0, '5%'], fontSize: 26, fontWeight: 600, color: '#252423', formatter: x => (x * 100).toFixed(1) + '%'},
        title: {offsetCenter: [0, '42%'], fontSize: 11, color: '#605E5C'},
        data: [{value: Math.round(v * 1000) / 1000, name: 'Budget used'}]}]};
  }
  function lineOpt(xs, series, budget) {
    const opt = {grid: {left: 8, right: 90, top: 30, bottom: 6, containLabel: true}, legend: LEG, tooltip: {trigger: 'axis', valueFormatter: v => v == null ? '–' : fmt(v)},
      xAxis: Object.assign({}, AX, {type: 'category', data: xs, boundaryGap: false, splitLine: {show: false}}),
      yAxis: Object.assign({}, AX, {type: 'value', axisLabel: Object.assign({}, AX.axisLabel, {formatter: short})}),
      series: series.map(s => ({name: s.name, type: 'line', data: s.data, symbol: 'none', connectNulls: false, itemStyle: {color: s.color},
        lineStyle: {width: 2.5, type: s.dashed ? 'dashed' : 'solid'}, areaStyle: s.area ? {color: s.color + '22'} : undefined}))};
    if (budget) opt.yAxis.max = v => Math.ceil(Math.max(v.max, budget) * 1.08 / 100000) * 100000;
    if (budget) opt.series[0].markLine = {symbol: 'none', silent: true, lineStyle: {color: '#D64550', type: 'dashed', width: 1.5},
      label: {formatter: 'Budget ' + short(budget), color: '#D64550', position: 'end', fontSize: 11}, data: [{yAxis: budget}]};
    return opt;
  }

  // table: cols = [{k, label, right, fmt(v,row), cls(v,row), bar:max, html(v,row)}]
  function table(cols, rows, maxRows) {
    let sortK = null, sortDir = 1;
    const wrap = document.createElement('div'); wrap.className = 'pr-tbl';
    function draw() {
      let rs = rows.slice();
      if (sortK) rs.sort((a, b) => { const x = a[sortK], y = b[sortK]; return (x > y ? 1 : x < y ? -1 : 0) * sortDir; });
      if (maxRows) rs = rs.slice(0, maxRows);
      wrap.innerHTML = '<table><thead><tr>' + cols.map(c => `<th data-k="${esc(c.k)}" class="${c.right ? 'r' : ''}" title="Sort">${esc(c.label)}${sortK === c.k ? (sortDir > 0 ? ' ▲' : ' ▼') : ''}</th>`).join('') + '</tr></thead><tbody>' +
        (rs.length ? rs.map(r => '<tr>' + cols.map(c => {
          const v = r[c.k];
          let txt = c.html ? c.html(v, r) : esc(c.fmt ? c.fmt(v, r) : (v == null ? '' : v));
          if (c.bar && v != null) txt = `<span class="pr-bar"><i style="width:${Math.max(0, Math.min(100, 100 * v / c.bar))}%"></i><span>${txt}</span></span>`;
          const cls = [c.right ? 'r' : '', c.cls ? c.cls(v, r) : ''].join(' ');
          return `<td class="${cls}">${txt}</td>`;
        }).join('') + '</tr>').join('') : `<tr><td colspan="${cols.length}" class="pr-empty">No rows for these filters.</td></tr>`) + '</tbody></table>';
      wrap.querySelectorAll('th').forEach(th => th.onclick = () => { const k = th.dataset.k; sortDir = sortK === k ? -sortDir : 1; sortK = k; draw(); });
    }
    draw();
    return wrap;
  }

  // ---------------------------------------------------------------- layout helpers
  function el(html) { const t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; }
  function card(grid, cls, title, note) {
    const c = el(`<div class="pr-card ${cls}">${title ? `<h3>${esc(title)}</h3>` : ''}${note ? `<div class="pr-note">${esc(note)}</div>` : ''}</div>`);
    grid.appendChild(c); return c;
  }
  function chartCard(grid, cls, title, height, option, onClick, note) {
    const c = card(grid, cls, title, note);
    const div = el(`<div class="pr-chart" style="min-height:${height}px"></div>`); c.appendChild(div);
    requestAnimationFrame(() => chart(div, option, onClick));
    return c;
  }
  function kpis(grid, cls, items) {
    const row = el(`<div class="${cls}"></div>`); grid.appendChild(row);
    items.forEach(([v, l, color]) => row.appendChild(el(`<div class="pr-card pr-kpi"><div class="v" style="color:${color || 'inherit'}">${esc(v)}</div><div class="l">${esc(l)}</div></div>`)));
  }
  function notice(page, section) {
    const s = (D.sections || {})[section] || {};
    if (s.state === 'ok') return false;
    let msg = 'This report could not be loaded.';
    if (s.state === 'no_right') msg = 'Your GLPI profile cannot see this report. It needs ' + (section === 'requests' ? '"See all" on Projects and on Tickets' : section === 'money' ? '"See all" on Projects and read on Budgets' : section === 'demands' ? '"See all" on Projects and on Tickets, and read on Budgets' : 'read on Data centers') + '.';
    if (s.state === 'missing') msg = 'These GLPI tables are missing, so this report cannot run: <code>' + s.tables.map(esc).join('</code>, <code>') + '</code>. ' +
      (s.tables.some(t => t.startsWith('glpi_plugin_order')) ? 'Install and activate the Orders management plugin. ' : '') +
      (s.tables.some(t => !t.startsWith('glpi_plugin_order')) ? 'Restore the missing core tables (see <code>restore_missing_tables.php</code>).' : '');
    if (s.state === 'error') msg = 'The database returned an error: <code>' + esc(s.message) + '</code>';
    page.innerHTML = `<div class="pr-msg">${msg}</div>`;
    return true;
  }

  // ---------------------------------------------------------------- state & filters
  const state = {tab: app.dataset.tab || 'requests', project: '', team: '', priority: '', category: '', from: '', to: '', dtype: '', dcat: '', dappr: '', dstage: ''};
  try { const s = JSON.parse(sessionStorage.getItem('pr-state') || '{}'); Object.assign(state, s, {tab: app.dataset.tab || s.tab || 'requests'}); } catch (e) { /* ignore */ }
  const save = () => { try { sessionStorage.setItem('pr-state', JSON.stringify(state)); } catch (e) { /* ignore */ } };

  function select(id, label, values, cur) {
    return `<label for="pr-${id}">${label}<select id="pr-${id}" data-f="${id}"><option value="">All</option>${values.map(v => `<option${v === cur ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>`;
  }
  function renderFilters() {
    const f = document.getElementById('pr-filters');
    if (state.tab === 'racks') { f.innerHTML = ''; return; }
    const req = D.requests || [];
    const dem = D.demands || [];
    const projects = state.tab === 'requests' ? uniq(req.map(r => r.project)).sort() : state.tab === 'demands' ? uniq(dem.map(r => r.project)).sort() : uniq((D.finance || []).map(r => r.project)).sort();
    let h = select('project', 'Project', projects, state.project);
    if (state.tab === 'demands') {
      h += select('dtype', 'Request type', uniq(dem.map(r => r.request_type)).sort(), state.dtype);
      h += select('dcat', 'Resource', uniq(dem.map(r => r.category)).sort(), state.dcat);
      h += select('dappr', 'Approval', uniq(dem.map(r => r.approval)).sort(), state.dappr);
      h += select('dstage', 'BOQ stage', uniq(dem.map(r => r.stage)).sort(), state.dstage);
    }
    if (state.tab === 'requests') {
      h += select('team', 'Requesting team', uniq(req.map(r => r.requesting_team)).sort(), state.team);
      h += select('priority', 'Priority', uniq(req.map(r => r.priority)).sort(), state.priority);
      h += select('category', 'Category', uniq(req.map(r => r.category)).sort(), state.category);
      h += `<label for="pr-from">Opened from<input type="date" id="pr-from" data-f="from" value="${esc(state.from)}"></label>`;
      h += `<label for="pr-to">to<input type="date" id="pr-to" data-f="to" value="${esc(state.to)}"></label>`;
    }
    h += '<button type="button" class="pr-reset" id="pr-reset">Clear filters</button>';
    if (state.project) h += `<span class="pr-chip">Project: ${esc(state.project)}</span>`;
    f.innerHTML = h;
    f.querySelectorAll('[data-f]').forEach(i => i.onchange = () => { state[i.dataset.f] = i.value; save(); render(); });
    document.getElementById('pr-reset').onclick = () => { Object.assign(state, {project: '', team: '', priority: '', category: '', from: '', to: '', dtype: '', dcat: '', dappr: '', dstage: ''}); save(); render(); };
  }
  const setProject = p => { if (!p || !allProjects.concat(['(no project)']).includes(p)) return; state.project = state.project === p ? '' : p; save(); render(); };
  const clickProject = e => setProject(e.name && PROJC[e.name] ? e.name : e.seriesName);

  // ---------------------------------------------------------------- page 1: team requests
  function pageRequests(page) {
    if (notice(page, 'requests')) return;
    const R = (D.requests || []).filter(r =>
      (!state.project || r.project === state.project) && (!state.team || r.requesting_team === state.team) &&
      (!state.priority || r.priority === state.priority) && (!state.category || r.category === state.category) &&
      (!state.from || r.opened >= state.from) && (!state.to || r.opened.slice(0, 10) <= state.to));
    const tickets = {}; R.forEach(r => tickets[r.ticket_id] = r);
    const T = Object.values(tickets);
    const cnt = rows => uniq(rows.map(r => r.ticket_id)).length;
    const solved = T.filter(r => r.days_to_solve != null);
    const grid = el('<div class="pr-grid"></div>'); page.appendChild(grid);
    kpis(grid, 'pr-k5', [
      [fmt(T.length), 'Requests'], [fmt(T.filter(r => r.is_open).length), 'Open now'],
      [fmt(T.filter(r => r.is_open_high).length), 'Open – high priority', '#E66C37'],
      [fmt(T.filter(r => r.is_overdue).length), 'Overdue', '#D64550'],
      [solved.length ? fmt(sum(solved, r => r.days_to_solve) / solved.length, 1) : '–', 'Avg. days to solve']]);
    if (!R.length) { card(grid, 'c12', '').innerHTML = '<div class="pr-empty">No requests match these filters.</div>'; return; }
    const ps = uniq(R.map(r => r.project)).sort((a, b) => cnt(R.filter(r => r.project === b)) - cnt(R.filter(r => r.project === a)));
    const by = (key, colors) => Object.keys(colors).filter(k => R.some(r => r[key] === k)).map(k => ({name: k, color: colors[k], data: ps.map(p => cnt(R.filter(r => r.project === p && r[key] === k)))}));
    chartCard(grid, 'c6', 'Requests by project and priority', 280, colOpt(ps, by('priority', PRIO)), clickProject, 'Click a bar to filter every chart by that project');
    chartCard(grid, 'c6', 'Requests by project and status', 280, colOpt(ps, by('status', STAT)), clickProject);
    const months = uniq(R.map(r => r.opened.slice(0, 7))).sort();
    chartCard(grid, 'c8', 'Requests opened per month, by project', 270, colOpt(months.map(monthLabel),
      uniq(R.map(r => r.project)).sort().map(p => ({name: p, color: PROJC[p] || '#A0A0A0', data: months.map(m => cnt(R.filter(r => r.project === p && r.opened.slice(0, 7) === m)))}))), clickProject);
    const cats = uniq(R.map(r => r.category)).sort((a, b) => cnt(R.filter(r => r.category === b)) - cnt(R.filter(r => r.category === a)));
    chartCard(grid, 'c4', 'Requests by category', 270, donutOpt(cats.map((c, i) => [c, cnt(R.filter(r => r.category === c)), PAL[i % PAL.length]])),
      e => { state.category = state.category === e.name ? '' : e.name; save(); render(); });
    const teams = uniq(R.map(r => r.requesting_team)).sort((a, b) => cnt(R.filter(r => r.requesting_team === b)) - cnt(R.filter(r => r.requesting_team === a)));
    chartCard(grid, 'c6', 'Requests by requesting team', 250, colOpt(teams, [['Request', '#118DFF'], ['Incident', '#D64550']].map(([t, c]) => ({name: t, color: c,
      data: teams.map(tm => cnt(R.filter(r => r.requesting_team === tm && r.request_type === t)))})), {horizontal: true}),
      e => { state.team = state.team === e.name ? '' : e.name; save(); render(); });
    const ages = ['1: up to 7 days', '2: 8-30 days', '3: 31-90 days', '4: over 90 days'];
    const O = R.filter(r => r.is_open);
    chartCard(grid, 'c6', 'Open requests – waiting time', 250, colOpt(ages.map(a => a.slice(3)),
      uniq(O.map(r => r.project)).sort().map(p => ({name: p, color: PROJC[p] || '#A0A0A0', data: ages.map(a => cnt(O.filter(r => r.project === p && r.open_age === a)))}))), clickProject);
    const c = card(grid, 'c12', `Open requests (${uniq(O.map(r => r.ticket_id)).length})`, 'Most urgent first · click a column header to sort · click the number to open the ticket');
    const openRows = O.slice().sort((a, b) => b.priority.localeCompare(a.priority) || a.opened.localeCompare(b.opened));
    c.appendChild(table([
      {k: 'ticket_id', label: 'ID', right: true, html: v => `<a href="${esc(ROOT)}/front/ticket.form.php?id=${esc(v)}">${esc(v)}</a>`},
      {k: 'title', label: 'Title'}, {k: 'project', label: 'Project'}, {k: 'requesting_team', label: 'Requesting team'},
      {k: 'assigned_to', label: 'Assigned to'}, {k: 'category', label: 'Category'}, {k: 'request_type', label: 'Type'},
      {k: 'priority', label: 'Priority', html: v => `<span class="pr-dot" style="background:${PRIO[v] || '#A0A0A0'}"></span>${esc(v)}`},
      {k: 'status', label: 'Status'}, {k: 'opened', label: 'Opened', fmt: v => v.slice(0, 10)},
      {k: 'age_days', label: 'Age (days)', right: true, cls: v => v > 90 ? 'bad' : v > 30 ? 'warn' : ''},
      {k: 'due', label: 'Due', fmt: v => v ? v.slice(0, 10) : ''}, {k: 'sla', label: 'SLA', cls: v => v === 'Overdue' ? 'bad' : 'good'},
    ], openRows));
  }

  // ---------------------------------------------------------------- page: demand & cost
  const RTYPE = {'New project': '#118DFF', 'Expansion': '#E66C37', 'Modernization': '#744EC2'};
  const STAGE = {estimate: '#E66C37', committed: '#6B007B', canceled: '#A0A0A0', 'no BOQ': '#C8C6C4'};
  const APPR = {Approved: '#1AAB40', Waiting: '#D9B300', Refused: '#D64550', 'Not required': '#8A8886'};
  function pageDemands(page) {
    if (notice(page, 'demands')) return;
    const R = (D.demands || []).filter(r => (!state.project || r.project === state.project) && (!state.dtype || r.request_type === state.dtype) &&
      (!state.dcat || r.category === state.dcat) && (!state.dappr || r.approval === state.dappr) && (!state.dstage || r.stage === state.dstage));
    const grid = el('<div class="pr-grid"></div>'); page.appendChild(grid);
    if (!(D.demands || []).length) { page.innerHTML = '<div class="pr-msg">No capacity requests yet. Requests submitted with the capacity request form appear here with the cost of their BOQ (automatic BOQ or an order linked in the ticket › Items tab).</div>'; return; }
    const total = sum(R, r => r.boq_cost), appr = sum(R.filter(r => r.approval === 'Approved'), r => r.boq_cost),
      wait = sum(R.filter(r => r.approval === 'Waiting'), r => r.boq_cost), refused = sum(R.filter(r => r.approval === 'Refused'), r => r.boq_cost || r.canceled);
    kpis(grid, 'pr-k6', [[fmt(R.length), 'Requests'], [short(total), 'Estimated cost (BOQ, excl. canceled)'], [short(appr), 'Approved requests', '#1AAB40'],
      [short(wait), 'Waiting for approval', '#D9B300'], [short(refused), 'Refused', '#D64550'], [fmt(R.filter(r => r.stage === 'no BOQ').length), 'Requests without a BOQ', '#8A8886']]);
    // budget position for the same project filter
    const F = money();
    if (F.length) kpis(grid, 'pr-k6', [[short(sum(F, r => r.budget)), 'Budget'], [short(sum(F, r => r.committed)), 'Committed (ordered)', '#6B007B'],
      [short(sum(F, r => r.pipeline)), 'Pipeline (draft & waiting BOQs)', '#E66C37'], [short(sum(F, r => r.allocated)), 'Allocated (committed + assets + other)', '#118DFF'],
      [short(sum(F, r => r.remaining)), 'Remaining', '#1AAB40'], [short(sum(F, r => r.remaining_after_pipeline)), 'Remaining after pipeline', '#D9B300']]);
    if (!R.length) { card(grid, 'c12', '').innerHTML = '<div class="pr-empty">No requests match these filters.</div>'; return; }
    const openT = id => window.open(`${ROOT}/front/ticket.form.php?id=${id}`, '_self');
    const types = Object.keys(RTYPE).concat(uniq(R.map(r => r.request_type)).filter(t => !RTYPE[t]));
    // ranking
    const top = R.filter(r => r.boq_cost > 0).sort((a, b) => b.boq_cost - a.boq_cost).slice(0, 20);
    const lab = r => `#${r.ticket_id} ${r.title}`.slice(0, 48);
    if (!top.length) card(grid, 'c12', 'Requests ranked by estimated cost').appendChild(el('<div class="pr-empty">None of these requests has a BOQ yet. Create the BOQ from the request ticket\'s Price check tab (it prices a LOM parts list with the LOPs), or link an order in the ticket › Items tab.</div>'));
    else chartCard(grid, 'c12', 'Requests ranked by estimated cost' + (top.length < R.length ? ` (top ${top.length})` : ''), Math.max(160, 34 + top.length * 26),
      colOpt(top.map(lab), types.filter(t => top.some(r => r.request_type === t)).map((t, i) => ({name: t, color: RTYPE[t] || PAL[(i + 5) % PAL.length],
        data: top.map(r => r.request_type === t ? r.boq_cost : null)})), {horizontal: true, money: true, labels: true}),
      e => { const r = top[e.dataIndex]; if (r) openT(r.ticket_id); }, 'Colour = request type · click a bar to open the request');
    // compare chosen request types with the total cost
    const CMP = ['New project', 'Expansion', 'Modernization'];
    const cmp = (state.cmp || []).filter(t => CMP.includes(t)); const sel = cmp.length ? cmp : CMP;
    const costOf = t => sum(R.filter(r => r.request_type === t), r => r.boq_cost);
    const cc = card(grid, 'c7', 'Compare request types with the total cost', 'Choose the types to compare. The Total bar is all requests shown (excluding those filtered out).');
    const chips = el('<div style="display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 12px"></div>');
    CMP.forEach(t => {
      const on = sel.includes(t);
      const b = el(`<button type="button" style="height:30px;border-radius:15px;padding:0 12px;font-size:12.5px;cursor:pointer;border:1px solid ${RTYPE[t]};background:${on ? RTYPE[t] : '#fff'};color:${on ? '#fff' : RTYPE[t]}">${esc(t)}</button>`);
      b.onclick = () => { state.cmp = on ? sel.filter(x => x !== t) : CMP.filter(x => x === t || sel.includes(x)); save(); render(); };
      chips.appendChild(b);
    });
    cc.appendChild(chips);
    if (!sel.length) { cc.appendChild(el('<div class="pr-empty">Select at least one request type.</div>')); }
    else {
      const cats = sel.concat(['Total']);
      const vals = cats.map(c => c === 'Total' ? {value: total, itemStyle: {color: '#605E5C'}} : {value: costOf(c), itemStyle: {color: RTYPE[c]}});
      const div = el(`<div class="pr-chart" style="min-height:${Math.max(160, 60 + cats.length * 48)}px"></div>`); cc.appendChild(div);
      requestAnimationFrame(() => chart(div, colOpt(cats, [{name: 'Estimated cost', color: '#605E5C', data: vals}], {horizontal: true, money: true, labels: true}), null));
      const rows = cats.map(c => ({name: c, count: c === 'Total' ? R.filter(r => r.boq_cost > 0).length : R.filter(r => r.request_type === c && r.boq_cost > 0).length,
        cost: c === 'Total' ? total : costOf(c), share: total ? (c === 'Total' ? 1 : costOf(c) / total) : 0}));
      cc.appendChild(table([
        {k: 'name', label: 'Type'}, {k: 'count', label: 'Requests with BOQ', right: true, fmt: fmt},
        {k: 'cost', label: 'Estimated cost', right: true, fmt: fmt},
        {k: 'share', label: '% of total', right: true, fmt: v => v ? (v * 100).toFixed(1) + '%' : ''}], rows));
    }
    // by resource
    const cats = uniq(R.map(r => r.category + (r.resource_type ? ' · ' + r.resource_type : ''))).sort();
    if (total > 0) chartCard(grid, 'c5', 'Estimated cost by resource', 290, donutOpt(cats.map((c, i) => [c, Math.round(sum(R.filter(r => r.category + (r.resource_type ? ' · ' + r.resource_type : '') === c), r => r.boq_cost)), PAL[i % PAL.length]]).filter(x => x[1] > 0)));
    // comparison table
    const c = card(grid, 'c12', `All requests compared (${R.length})`, 'Ranked by estimated cost · click a column header to sort · cost per unit compares requests of the same resource');
    const maxC = Math.max(1, ...R.map(r => r.boq_cost));
    const ranked = R.slice().sort((a, b) => b.boq_cost - a.boq_cost).map((r, i) => Object.assign({}, r, {rank: r.boq_cost > 0 ? i + 1 : null, share: total ? r.boq_cost / total : 0}));
    c.appendChild(table([
      {k: 'rank', label: 'Rank', right: true},
      {k: 'ticket_id', label: 'ID', right: true, html: v => `<a href="${esc(ROOT)}/front/ticket.form.php?id=${esc(v)}">${esc(v)}</a>`},
      {k: 'title', label: 'Request'}, {k: 'project', label: 'Project'}, {k: 'team', label: 'Team'},
      {k: 'request_type', label: 'Type', html: v => `<span class="pr-dot" style="background:${RTYPE[v] || '#A0A0A0'}"></span>${esc(v)}`},
      {k: 'category', label: 'Resource', fmt: (v, r) => v + (r.resource_type ? ' · ' + r.resource_type : '')},
      {k: 'quantity', label: 'Quantity', right: true, fmt: (v, r) => v ? fmt(v, v % 1 ? 1 : 0) + ' ' + r.unit : ''},
      {k: 'boq_cost', label: 'Estimated cost', right: true, fmt: fmt, bar: maxC},
      {k: 'cost_per_unit', label: 'Cost / unit', right: true, fmt: fmt},
      {k: 'supplier', label: 'Supplier'},
      {k: 'share', label: '% of total', right: true, fmt: v => v ? (v * 100).toFixed(1) + '%' : '', bar: Math.max(0.0001, ...ranked.map(r => r.share))},
      {k: 'stage', label: 'BOQ stage', html: v => `<span class="pr-dot" style="background:${STAGE[v] || '#A0A0A0'}"></span>${esc(v)}`},
      {k: 'approval', label: 'Approval', html: v => `<span class="pr-dot" style="background:${APPR[v] || '#A0A0A0'}"></span>${esc(v)}`},
      {k: 'required_by', label: 'Required by'}, {k: 'opened', label: 'Opened'}], ranked));
  }

  // ---------------------------------------------------------------- page 2: budget & cost
  function money() { return (D.finance || []).filter(r => !state.project || r.project === state.project).sort((a, b) => b.budget - a.budget); }
  function pageBudget(page) {
    if (notice(page, 'money')) return;
    const F = money(); const grid = el('<div class="pr-grid"></div>'); page.appendChild(grid);
    if (!F.length) { page.innerHTML = '<div class="pr-msg">No project is linked to a budget yet. In GLPI open the project › <b>Costs</b> tab › add a line whose <b>Budget</b> is the project budget (cost 0).</div>'; return; }
    const tb = sum(F, r => r.budget), ta = sum(F, r => r.allocated), tp = sum(F, r => r.pipeline), over = sum(F, r => r.is_over_budget);
    const rem = tb - ta - tp;
    const row = el('<div class="pr-k6"></div>'); grid.appendChild(row);
    [[short(tb), 'Total budget', ''], [short(ta), 'Allocated (approved BOQs + assets + costs)', '#6B007B'], [short(tp), 'Pipeline (draft BOQs)', '#E66C37'],
      [short(rem), 'Remaining after pipeline', rem < 0 ? '#D64550' : '#1AAB40'], [fmt(over), 'Projects over budget (with drafts)', over ? '#D64550' : '#1AAB40']]
      .forEach(([v, l, c]) => row.appendChild(el(`<div class="pr-card pr-kpi"><div class="v" style="color:${c || 'inherit'}">${esc(v)}</div><div class="l">${esc(l)}</div></div>`)));
    const g = el('<div class="pr-card" style="padding:4px"><div class="pr-chart" style="min-height:104px"></div></div>'); row.appendChild(g);
    requestAnimationFrame(() => chart(g.firstChild, gaugeOpt(tb ? ta / tb : 0)));
    const ps = F.map(r => r.project);
    chartCard(grid, 'c7', 'Budget vs allocated vs pipeline', 300, colOpt(ps, [
      {name: 'Budget', color: '#118DFF', data: F.map(r => r.budget)}, {name: 'Allocated', color: '#6B007B', data: F.map(r => r.allocated)},
      {name: 'Pipeline (draft BOQs)', color: '#E66C37', data: F.map(r => r.pipeline)}], {stacked: false, money: true, labels: true}), clickProject, 'Click a project to focus the page on it');
    chartCard(grid, 'c5', 'Where the allocated money went', 300, colOpt(ps, [
      {name: 'BOQ orders', color: '#6B007B', data: F.map(r => r.boq_orders)}, {name: 'Assets (not from orders)', color: '#B887C9', data: F.map(r => r.assets)},
      {name: 'Other project costs', color: '#8AD4EB', data: F.map(r => r.other_costs)}], {horizontal: true, money: true}), clickProject);
    // by category: the GLPI project type of each project (e.g. Modernization, Expansion)
    const byCat = {};
    F.forEach(r => {
      const k = r.category || '(no category)';
      const c0 = byCat[k] ??= {category: k, projects: 0, budget: 0, allocated: 0, pipeline: 0};
      c0.projects++; c0.budget += +r.budget; c0.allocated += +r.allocated; c0.pipeline += +r.pipeline;
    });
    const cats = Object.values(byCat).map(c0 => ({...c0, remaining: c0.budget - c0.allocated, pct_used: c0.budget ? c0.allocated / c0.budget : 0}))
      .sort((a, b) => b.budget - a.budget);
    const cc = card(grid, 'c12', 'By category', 'Category = the project type set on each GLPI project (e.g. Modernization, Expansion)');
    cc.appendChild(table([
      {k: 'category', label: 'Category'}, {k: 'projects', label: 'Projects', right: true},
      {k: 'budget', label: 'Budget', right: true, fmt: fmt}, {k: 'allocated', label: 'Allocated', right: true, fmt: fmt},
      {k: 'pipeline', label: 'Pipeline', right: true, fmt: fmt},
      {k: 'remaining', label: 'Remaining', right: true, fmt: fmt, cls: v => v < 0 ? 'neg' : ''},
      {k: 'pct_used', label: '% used', right: true, fmt: pct, cls: v => v >= 1 ? 'bad' : v >= 0.8 ? 'warn' : 'good'}], cats));
    const c = card(grid, 'c12', 'Project budget scorecard', 'Green under 80 % · yellow 80–100 % · red over budget');
    const heat = (lo) => v => v >= 1 ? 'bad' : v >= lo ? 'warn' : 'good';
    const maxB = Math.max(...F.map(r => r.budget));
    c.appendChild(table([
      {k: 'project', label: 'Project'}, {k: 'category', label: 'Category'}, {k: 'budget', label: 'Budget', right: true, fmt: fmt},
      {k: 'allocated', label: 'Allocated', right: true, fmt: fmt, bar: maxB}, {k: 'pipeline', label: 'Pipeline', right: true, fmt: fmt},
      {k: 'remaining', label: 'Remaining', right: true, fmt: fmt, cls: v => v < 0 ? 'neg' : ''},
      {k: 'pct_used', label: '% used', right: true, fmt: pct, cls: heat(0.8)}, {k: 'pct_incl_pipeline', label: '% incl. pipeline', right: true, fmt: pct, cls: heat(0.9)},
      {k: 'begin_date', label: 'Budget start'}, {k: 'end_date', label: 'Budget end'}], F));
    const T = (D.timeline || []).filter(r => !state.project || r.project === state.project);
    const months = uniq(T.map(r => r.month)).sort();
    const ser = n => months.map(m => { const v = T.filter(r => r.month === m && r.series === n && r.amount != null); return v.length ? Math.round(sum(v, r => r.amount)) : null; });
    const budget = Math.max(0, ...ser('Budget').filter(v => v != null));
    chartCard(grid, 'c12', 'Allocated to date and forecast vs budget' + (state.project ? ' · ' + state.project : ' · all projects'), 280,
      lineOpt(months.map(m => monthLabel(m.slice(0, 7))), [
        {name: 'Allocated (actual)', data: ser('Allocated (actual)'), color: '#6B007B', area: true},
        {name: 'Forecast', data: ser('Forecast'), color: '#E66C37', dashed: true}], budget), null,
      'Forecast = allocated + the larger of draft BOQs and (monthly BOQ spend × months left)');
  }

  // ---------------------------------------------------------------- page 3: forecast & renewals
  function pageForecast(page) {
    if (notice(page, 'money')) return;
    const F = money(); const grid = el('<div class="pr-grid"></div>'); page.appendChild(grid);
    if (!F.length) { page.innerHTML = '<div class="pr-msg">No project is linked to a budget yet (Project › Costs tab › line with a Budget).</div>'; return; }
    const ps = F.map(r => r.project);
    chartCard(grid, 'c12', 'Next 12 months: cost outlook vs remaining budget', 300, colOpt(ps, [
      {name: 'Draft BOQs', color: '#E66C37', data: F.map(r => r.pipeline)}, {name: 'Renewals', color: '#D9B300', data: F.map(r => r.renewals_12m)},
      {name: 'Hardware refresh', color: '#744EC2', data: F.map(r => r.refresh_12m)}, {name: 'Run-rate BOQ spend', color: '#12239E', data: F.map(r => r.runrate_spend_12m)},
      {name: 'Budget remaining', color: '#1AAB40', data: F.map(r => r.remaining)}], {stacked: false, money: true}), clickProject,
      'Compare the first four bars with the green one to decide on a budget extension');
    const c = card(grid, 'c12', 'Forecast per project', 'Monthly BOQ spend since the budget start, projected to the budget end date');
    c.appendChild(table([
      {k: 'project', label: 'Project'}, {k: 'boq_spend_per_month', label: 'BOQ spend / month', right: true, fmt: fmt},
      {k: 'months_left', label: 'Months left', right: true}, {k: 'forecast_at_end', label: 'Forecast at end', right: true, fmt: fmt},
      {k: 'budget_minus_forecast', label: 'Budget − forecast', right: true, fmt: fmt, cls: v => v < 0 ? 'bad' : 'good'},
      {k: 'budget_runs_out', label: 'Budget runs out', cls: v => v === 'already exceeded' ? 'bad' : v === 'exceeded if drafts approved' ? 'warn' : v === 'lasts to end' ? 'good' : 'warn'},
      {k: 'open_requests', label: 'Open requests', right: true}], F));
    const A = (D.assets || []).filter(r => !state.project || r.project === state.project);
    const rs = A.filter(r => r.renew_next_24m), rm = uniq(rs.map(r => r.warranty_end.slice(0, 7))).sort();
    const types = Object.keys(TYPES).concat(uniq(A.map(r => r.asset_type)).filter(t => !TYPES[t]));
    chartCard(grid, 'c6', 'Warranty / support renewals due – next 24 months', 260, colOpt(rm.map(monthLabel),
      types.filter(t => rs.some(r => r.asset_type === t)).map((t, i) => ({name: t, color: TYPES[t] || PAL[(i + 4) % PAL.length],
        data: rm.map(m => Math.round(sum(rs.filter(r => r.asset_type === t && r.warranty_end.slice(0, 7) === m), r => r.renewal_cost)))})), {money: true}), null,
      'Warranty extension value, or 10 % of the asset value when it is empty');
    const es = A.filter(r => r.eol_next_5y), ey = uniq(es.map(r => r.end_of_life.slice(0, 4))).sort();
    chartCard(grid, 'c6', 'Hardware refresh (end of life) – next 5 years', 260, colOpt(ey,
      types.filter(t => es.some(r => r.asset_type === t)).map((t, i) => ({name: t, color: TYPES[t] || PAL[(i + 4) % PAL.length],
        data: ey.map(y => Math.round(sum(es.filter(r => r.asset_type === t && r.end_of_life.slice(0, 4) === y), r => r.asset_value)))})), {money: true}), null,
      '5 years after the asset went into service, at its purchase price');
    const O = (D.orders || []).filter(r => !state.project || r.project === state.project);
    const sc = {committed: '#6B007B', pipeline: '#E66C37', canceled: '#A0A0A0'};
    const c2 = card(grid, 'c12', `BOQs / orders (${O.length})`, 'Committed = validated, delivered or paid · pipeline = draft or waiting for approval');
    c2.appendChild(table([
      {k: 'project', label: 'Project'}, {k: 'boq_order', label: 'BOQ / order'}, {k: 'order_number', label: 'Order no.'},
      {k: 'order_state', label: 'State'}, {k: 'stage', label: 'Stage', html: v => `<span class="pr-dot" style="background:${sc[v]}"></span>${esc(v)}`},
      {k: 'order_date', label: 'Order date'}, {k: 'items', label: 'Items', right: true},
      {k: 'total_tax_free', label: 'Total (tax free)', right: true, fmt: fmt, bar: Math.max(1, ...O.map(r => r.total_tax_free))},
      {k: 'budget_name', label: 'Budget'}], O.slice().sort((a, b) => a.project.localeCompare(b.project) || String(a.order_date).localeCompare(String(b.order_date)))));
  }

  // ---------------------------------------------------------------- page 4: racks (rackview.js)
  function pageRacks(page) {
    if (notice(page, 'racks')) return;
    if (window.PRRack) window.PRRack.render(page, D, PROJC);
  }

  // ---------------------------------------------------------------- render
  function render() {
    charts.forEach(c => c.dispose()); charts = [];
    app.querySelectorAll('.pr-tabs button').forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === state.tab)));
    app.querySelectorAll('.pr-page').forEach(p => { p.classList.toggle('on', p.dataset.page === state.tab); p.innerHTML = ''; });
    renderFilters();
    const page = app.querySelector(`.pr-page[data-page="${state.tab}"]`);
    ({requests: pageRequests, demands: pageDemands, budget: pageBudget, forecast: pageForecast, racks: pageRacks})[state.tab](page);
  }
  app.querySelectorAll('.pr-tabs button').forEach(b => b.onclick = () => {
    state.tab = b.dataset.tab; save();
    try { history.replaceState(null, '', '?tab=' + state.tab); } catch (e) { /* ignore */ }
    render();
  });
  document.getElementById('pr-asof').textContent = 'data as of ' + new Date().toLocaleString('en-US', {dateStyle: 'medium', timeStyle: 'short'});
  window.PRReports = {render};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render); else render();
})();
