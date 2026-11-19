/* Project dashboard (Persian, RTL): slicers, KPI cards, ECharts charts and report tables.
 * Data: #pr-data (PluginProjectreportsData::all). Dates are shown in the Jalali (Persian) calendar. */
(function () {
  'use strict';

  var app = document.getElementById('db-app');
  var body = document.getElementById('db-body');
  var slicers = document.getElementById('db-slicers');
  var dataEl = document.getElementById('pr-data');
  if (!app || !body || !slicers || !dataEl) {
    return;
  }

  var D = JSON.parse(dataEl.textContent || '{}');
  var S = D.sections || {};
  var PAGE = app.getAttribute('data-page') || 'overview';
  var TODAY = new Date((D.today || '') + 'T00:00:00');
  var REQ = D.requests || [];
  var DEM = D.demands || [];
  var FIN = D.finance || [];
  var AST = D.assets || [];

  var charts = [];
  var pendingCharts = [];

  // slicer state; time is 'all' or a number of days
  var F = { time: 'all', team: '', project: '', status: '', category: '', supplier: '' };
  var TIMES = [['all', 'همه زمان‌ها'], ['30', '۳۰ روز اخیر'], ['90', '۹۰ روز اخیر'], ['365', '۱۲ ماه اخیر']];
  // drill-down filters set by clicking a chart or a card (not in the filter panel)
  var X = { group: '', age: '', sla: '', open: '', atype: '', rtype: '', rcat: '', ikind: '', istate: '' };
  var U = D.urls || {};
  (function readAddress() {
    var q = new URLSearchParams(window.location.search);
    Object.keys(F).forEach(function (k) { if (q.get(k)) { F[k] = q.get(k); } });
    Object.keys(X).forEach(function (k) { if (q.get(k)) { X[k] = q.get(k); } });
  })();

  var GROUP = { New: 'Waiting', Assigned: 'In progress', Planned: 'In progress', Pending: 'In progress', Solved: 'Completed', Closed: 'Completed' };
  var GROUP_LABEL = { Waiting: 'در انتظار اقدام', 'In progress': 'در حال پیگیری', Completed: 'تکمیل‌شده' };
  var GROUP_CLS = { Waiting: 'warn', 'In progress': 'info', Completed: 'ok' };
  var STATUS_FA = { New: 'جدید', Assigned: 'واگذار شده', Planned: 'برنامه‌ریزی', Pending: 'در انتظار', Solved: 'حل‌شده', Closed: 'بسته' };
  var STATUSES = ['New', 'Assigned', 'Planned', 'Pending', 'Solved', 'Closed'];
  var STAGE_FA = { committed: 'تأییدشده', estimate: 'برآورد', 'no BOQ': 'بدون BOQ', canceled: 'لغو شده' };
  var ASSET_FA = { Server: 'سرور', 'Network device': 'تجهیز شبکه', Storage: 'فضای ذخیره‌سازی', 'SAN storage': 'فضای ذخیره‌سازی', 'Patch panel': 'پچ‌پنل', 'Core switch': 'سوئیچ هسته', Switch: 'سوئیچ' };
  var AGE_FA = ['۰ تا ۷ روز', '۸ تا ۳۰ روز', '۳۱ تا ۹۰ روز', 'بیش از ۹۰ روز'];
  // capacity request types (one form each) and the resources they ask for
  var RTYPES = ['New project', 'Expansion', 'Modernization', 'Release old resources'];
  var RTYPE_FA = { 'New project': 'پروژه جدید', Expansion: 'توسعه', Modernization: 'نوسازی و جایگزینی (Swap)', 'Release old resources': 'آزادسازی منابع' };
  var RCATS = ['Compute', 'Storage', 'GPU', 'Network'];
  var RCAT_FA = { Compute: 'سرور', Storage: 'فضای ذخیره‌سازی', GPU: 'GPU', Network: 'تجهیز شبکه', Other: 'سایر' };
  var RCAT_COL = { Compute: '#2f7de1', Storage: '#f5b800', GPU: '#7c5cf0', Network: '#0fb5a6', Other: '#94a3b8' };
  function rcatOf(d) { return RCATS.indexOf(d.category) >= 0 ? d.category : 'Other'; }
  var DEMAP = {};
  DEM.forEach(function (d) { DEMAP[d.ticket_id] = d; });
  var PAGE_FA = { overview: 'نمای کلی', requests: 'درخواست‌ها', budget: 'بودجه و هزینه', assets: 'دارایی‌ها', forecast: 'پیش‌بینی' };
  var COL = { gold: '#f5b800', blue: '#2f7de1', green: '#16a34a', red: '#e5484d', grey: '#cbd5e1', ink: '#334155', muted: '#64748b' };
  var PALETTE = ['#f5b800', '#2f7de1', '#16a34a', '#7c5cf0', '#e5484d', '#0fb5a6', '#f97316', '#64748b'];
  var ICONS = {
    list: '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    check: '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.5"/>',
    warn: '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
    coins: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    chart: '<path d="M4 19V5M4 19h16M8 15v-4M12 15V8M16 15v-6"/>'
  };
  var TONE_ICON = { gold: 'list', blue: 'clock', green: 'check', red: 'warn', violet: 'coins' };
  var TIP = { backgroundColor: '#0f172a', borderWidth: 0, padding: [8, 12], textStyle: { color: '#fff', fontFamily: 'Vazirmatn, Tahoma, sans-serif', fontSize: 12 }, extraCssText: 'border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.18);' };

  // ---------- helpers ----------
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
  function fa(x) { return String(x).replace(/[0-9]/g, function (c) { return FA_DIGITS[c]; }); }
  function num(n) { return Number(n || 0).toLocaleString('fa-IR'); }
  function decimal(n, digits) {
    return Number(n || 0).toLocaleString('fa-IR', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  }
  function compact(n) {
    n = Number(n || 0);
    var a = Math.abs(n);
    if (a >= 1e9) return decimal(n / 1e9, 1) + ' میلیارد';
    if (a >= 1e6) return decimal(n / 1e6, 1) + ' میلیون';
    if (a >= 1e3) return decimal(n / 1e3, 1) + ' هزار';
    return num(Math.round(n));
  }
  // Gregorian -> Jalali (Persian calendar)
  function toJalali(gy, gm, gd) {
    var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var gy2 = gm > 2 ? gy + 1 : gy;
    var days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
      + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
    var jy = -1595 + 33 * Math.floor(days / 12053);
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
      jy += Math.floor((days - 1) / 365);
      days = (days - 1) % 365;
    }
    var jm, jd;
    if (days < 186) {
      jm = 1 + Math.floor(days / 31);
      jd = 1 + (days % 31);
    } else {
      jm = 7 + Math.floor((days - 186) / 30);
      jd = 1 + ((days - 186) % 30);
    }
    return [jy, jm, jd];
  }
  function jdate(d) {
    var p = String(d || '').slice(0, 10).split('-').map(Number);
    if (p.length !== 3 || isNaN(p[0])) { return ''; }
    var j = toJalali(p[0], p[1], p[2]);
    function two(x) { return (x < 10 ? '0' : '') + x; }
    return fa(j[0] + '/' + two(j[1]) + '/' + two(j[2]));
  }
  function ts(d) { return d ? Date.parse(String(d).replace(' ', 'T')) : NaN; }
  function ageDays(d) { var t = ts(d); return isNaN(t) ? NaN : (TODAY - t) / 864e5; }
  function uniq(list) {
    var s = {};
    list.forEach(function (v) { if (v) { s[v] = 1; } });
    return Object.keys(s).sort();
  }
  function countBy(list, fn) {
    var m = {};
    list.forEach(function (x) { var k = fn(x); m[k] = (m[k] || 0) + 1; });
    return m;
  }
  function sum(list, fn) { return list.reduce(function (a, x) { return a + Number(fn(x) || 0); }, 0); }
  function byDateDesc(a, b) { return String(b.opened || '').localeCompare(String(a.opened || '')); }

  // share of the filtered list opened in the last 30 days vs the 30 days before
  function growth(list) {
    var now = 0, prev = 0;
    list.forEach(function (r) {
      var a = ageDays(r.opened);
      if (isNaN(a)) { return; }
      if (a <= 30) { now++; } else if (a <= 60) { prev++; }
    });
    if (!prev) { return now ? { up: true, txt: num(now) + ' مورد در ۳۰ روز اخیر' } : null; }
    var p = Math.round((now - prev) / prev * 100);
    return { up: p >= 0, txt: (p >= 0 ? '+' : '') + fa(p) + '٪ نسبت به ۳۰ روز قبل' };
  }

  // ---------- sections / notices ----------
  function stateMsg(sec) {
    var s = S[sec];
    if (!s) { return 'اطلاعات در دسترس نیست.'; }
    if (s.state === 'ok') { return ''; }
    if (s.state === 'no_right') { return 'دسترسی به این بخش ندارید.'; }
    if (s.state === 'missing') { return 'جدول‌های موردنیاز یافت نشد: ' + (s.tables || []).join('، '); }
    return 'خطا: ' + (s.message || 'نامشخص');
  }
  function ok(sec) { return !stateMsg(sec); }
  function need(secs) {
    var out = '';
    secs.forEach(function (s) {
      var m = stateMsg(s);
      if (m) { out += '<div class="db-notice">' + esc(m) + '</div>'; }
    });
    return out;
  }

  // ---------- filters ----------
  function inTime(r) {
    if (F.time === 'all') { return true; }
    var a = ageDays(r.opened);
    return !isNaN(a) && a <= Number(F.time);
  }
  function passReq(r) {
    return inTime(r)
      && (!F.team || r.requesting_team === F.team)
      && (!F.project || r.project === F.project)
      && (!F.status || r.status === F.status)
      && (!F.category || r.category === F.category)
      && (!X.group || GROUP[r.status] === X.group)
      && (!X.age || String(r.open_age || '').charAt(0) === X.age)
      && (!X.sla || r.sla === X.sla)
      && (!X.open || String(r.is_open) === X.open)
      && (!X.rtype || (DEMAP[r.ticket_id] && DEMAP[r.ticket_id].request_type === X.rtype))
      && (!X.rcat || (DEMAP[r.ticket_id] && rcatOf(DEMAP[r.ticket_id]) === X.rcat));
  }
  function reqs() { return REQ.filter(passReq); }
  function reqIds() {
    var s = {};
    reqs().forEach(function (r) { s[r.ticket_id] = 1; });
    return s;
  }
  function dems() {
    var ids = reqIds();
    return DEM.filter(function (d) {
      return ids[d.ticket_id] && (!F.supplier || String(d.supplier || '').split(', ').indexOf(F.supplier) >= 0);
    });
  }
  function fins() { return FIN.filter(function (f) { return !F.project || f.project === F.project; }); }
  function asts() {
    return AST.filter(function (a) { return (!F.project || a.project === F.project) && (!X.atype || a.asset_type === X.atype); });
  }

  // ---------- links: to a filtered page of the dashboard, or to the GLPI record itself ----------
  function query(page, changes) {
    var q = new URLSearchParams();
    q.set('page', page);
    var all = {};
    Object.keys(F).forEach(function (k) { all[k] = F[k]; });
    Object.keys(X).forEach(function (k) { all[k] = X[k]; });
    Object.keys(changes || {}).forEach(function (k) { all[k] = changes[k]; });
    Object.keys(all).forEach(function (k) { if (all[k] && !(k === 'time' && all[k] === 'all')) { q.set(k, all[k]); } });
    return '?' + q.toString();
  }
  function go(page, changes) { window.location.href = query(page, changes); }
  function rec(type, id) { return U[type] && id ? U[type] + '?id=' + encodeURIComponent(id) : ''; }
  function link(url, html, title) {
    return url ? '<a class="db-link" href="' + esc(url) + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' + html + '</a>' : html;
  }
  function links(list) {
    return (list || []).map(function (x) { return link(x.url, esc(x.name)); }).join('، ');
  }
  function filterLink(changes, html, page) {
    return '<a class="db-link db-flt" href="' + esc(query(page || PAGE, changes)) + '" title="نمایش همه موارد مشابه">' + html + '</a>';
  }
  function syncAddress() {
    try { window.history.replaceState(null, '', query(PAGE, {})); } catch (e) { /* not important */ }
    // page tabs keep the filter panel's choices (the drill-down filters belong to the page they were set on)
    document.querySelectorAll('.db-nav a').forEach(function (a) {
      var k = new URLSearchParams((a.getAttribute('href') || '').split('?')[1] || '').get('page');
      if (k) { a.setAttribute('href', query(k, { group: '', age: '', sla: '', open: '', atype: '', rtype: '', rcat: '', ikind: '', istate: '' })); }
    });
  }
  // the drill-down filters in force, each removable
  function activeBar() {
    var names = {
      group: function (v) { return GROUP_LABEL[v] || v; },
      age: function (v) { return 'سن درخواست: ' + (AGE_FA[Number(v) - 1] || v); },
      sla: function (v) { return v === 'Overdue' ? 'دیرکرد' : 'به‌موقع'; },
      open: function (v) { return v === '1' ? 'فقط باز' : 'بسته'; },
      atype: function (v) { return 'نوع دارایی: ' + (ASSET_FA[v] || v); },
      rtype: function (v) { return 'نوع درخواست: ' + (RTYPE_FA[v] || v); },
      rcat: function (v) { return 'منبع: ' + (RCAT_FA[v] || v); },
      ikind: function (v) { return 'تجهیز: ' + (IKIND_FA[v] || v); },
      istate: function (v) { return 'وضعیت: ' + stLabel(v); }
    };
    var on = Object.keys(X).filter(function (k) { return X[k]; });
    if (!on.length) { return ''; }
    return '<div class="db-active"><span>نمایش:</span>' + on.map(function (k) {
      var c = {}; c[k] = '';
      return '<a class="db-active-chip" href="' + esc(query(PAGE, c)) + '" title="حذف این فیلتر">' + esc(names[k](X[k])) + ' <b>×</b></a>';
    }).join('') + '</div>';
  }

  // ---------- slicer column ----------
  function sel(key, label, opts, labelOf) {
    var o = '<option value="">همه</option>' + opts.map(function (v) {
      var txt = labelOf ? labelOf(v) : v;
      return '<option value="' + esc(v) + '"' + (String(F[key]) === String(v) ? ' selected' : '') + '>' + esc(txt) + '</option>';
    }).join('');
    return '<label class="db-sl"><span>' + esc(label) + '</span><select data-key="' + key + '">' + o + '</select></label>';
  }
  function renderSlicers() {
    var teams = uniq(REQ.map(function (r) { return r.requesting_team; }));
    var projects = uniq(REQ.map(function (r) { return r.project; }).concat(FIN.map(function (f) { return f.project; })));
    var statuses = STATUSES.filter(function (s) { return REQ.some(function (r) { return r.status === s; }); });
    var cats = uniq(REQ.map(function (r) { return r.category; }));
    var sups = uniq(DEM.reduce(function (a, d) { return a.concat(String(d.supplier || '').split(', ')); }, []));

    var time = '<label class="db-sl"><span>زمان ثبت درخواست</span><select data-key="time">' + TIMES.map(function (t) {
      return '<option value="' + t[0] + '"' + (String(F.time) === t[0] ? ' selected' : '') + '>' + esc(t[1]) + '</option>';
    }).join('') + '</select></label>';

    slicers.innerHTML = '<div class="db-sl-inner"><div class="db-sl-head">فیلترها</div>' + time
      + sel('team', 'تیم درخواست‌دهنده', teams)
      + sel('project', 'پروژه', projects)
      + sel('status', 'وضعیت درخواست', statuses, function (s) { return STATUS_FA[s] || s; })
      + sel('category', 'دسته‌بندی', cats)
      + sel('supplier', 'تأمین‌کننده', sups)
      + '<button type="button" class="db-reset">بازنشانی فیلترها</button></div>';
  }

  // ---------- building blocks ----------
  function chart(id, opt, onClick) { pendingCharts.push([id, opt, onClick]); }
  function draw(id, opt, onClick) {
    var el = document.getElementById(id);
    if (!el || !window.echarts) { return; }
    var c = echarts.init(el);
    c.setOption(opt);
    if (onClick) {
      // a gauge has no clickable item: the whole chart area is the target
      if (opt.series && opt.series[0] && opt.series[0].type === 'gauge') { el.addEventListener('click', onClick); } else { c.on('click', onClick); }
      el.classList.add('db-click');
      el.title = 'برای جزئیات کلیک کنید';
    }
    charts.push(c);
  }
  window.addEventListener('resize', function () { charts.forEach(function (c) { c.resize(); }); });

  function card(span, title, inner) {
    return '<section class="db-card s' + span + '"><div class="db-card-h">' + esc(title) + '</div><div class="db-card-b">' + inner + '</div></section>';
  }
  function chartHtml(id, h) { return '<div id="' + id + '" class="db-chart" style="height:' + (h || 260) + 'px"></div>'; }
  function noticeCard(span, title, sec) {
    return card(span, title, '<div class="db-empty">' + esc(stateMsg(sec)) + '</div>');
  }
  function kpi(label, value, delta, tone, href) {
    var icon = ICONS[TONE_ICON[tone] || 'chart'];
    var tag = href ? 'a' : 'div';
    return '<' + tag + ' class="db-kpi tone-' + tone + (href ? ' db-kpi-link' : '') + '"' + (href ? ' href="' + esc(href) + '" title="نمایش جزئیات"' : '') + '><div class="db-kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + icon + '</svg></div>'
      + '<div class="db-kpi-v">' + value + '</div><div class="db-kpi-l">' + esc(label) + '</div>'
      + (delta && delta.plain ? '<div class="db-kpi-s">' + esc(delta.txt) + '</div>'
        : (delta ? '<div class="db-delta ' + (delta.up ? 'up' : 'down') + '">' + (delta.up ? '▲ ' : '▼ ') + esc(delta.txt) + '</div>' : ''))
      + '</' + tag + '>';
  }
  function chip(text, cls) { return '<span class="db-chip ' + (cls || '') + '">' + esc(text) + '</span>'; }
  function table(id, cols, rows, limit) {
    if (!rows.length) { return '<div class="db-empty">موردی با فیلترهای انتخابی یافت نشد.</div>'; }
    var shown = limit ? rows.slice(0, limit) : rows;
    var head = cols.map(function (c) { return '<th class="' + (c.num ? 'num' : '') + '">' + esc(c.h) + '</th>'; }).join('');
    var bodyRows = shown.map(function (r) {
      return '<tr>' + cols.map(function (c) { return '<td class="' + (c.num ? 'num' : '') + '">' + c.f(r) + '</td>'; }).join('') + '</tr>';
    }).join('');
    var note = limit && rows.length > limit ? '<div class="db-empty">نمایش ' + num(limit) + ' مورد از ' + num(rows.length) + '</div>' : '';
    return '<div class="db-tbl-wrap"><table id="' + id + '" class="db-tbl"><thead><tr>' + head + '</tr></thead><tbody>' + bodyRows + '</tbody></table></div>' + note;
  }

  // ---------- chart options (light theme) ----------
  var FONT = 'Vazirmatn, Tahoma, "Segoe UI", sans-serif';
  function donutOpt(items, caption) {
    var total = sum(items, function (i) { return i.value; });
    return {
      textStyle: { fontFamily: FONT },
      tooltip: Object.assign({ trigger: 'item', formatter: '{b}: {c} ({d}%)' }, TIP),
      legend: { bottom: 0, left: 'center', itemWidth: 12, itemHeight: 12, textStyle: { color: COL.ink, fontSize: 12 } },
      color: PALETTE,
      series: [{
        type: 'pie', radius: ['50%', '70%'], center: ['50%', '44%'], avoidLabelOverlap: false,
        label: {
          show: true, position: 'center', formatter: '{a|' + num(total) + '}\n{b|' + caption + '}',
          rich: { a: { fontSize: 22, fontWeight: 'bold', color: COL.ink }, b: { fontSize: 12, color: COL.muted } }
        },
        labelLine: { show: false },
        data: items
      }]
    };
  }
  function hbarOpt(labels, values, color) {
    return {
      textStyle: { fontFamily: FONT },
      grid: { left: 8, right: 8, top: 8, bottom: 8, containLabel: true },
      xAxis: { type: 'value', show: false, splitLine: { show: false } },
      yAxis: { type: 'category', data: labels, inverse: true, axisTick: { show: false }, axisLine: { show: false },
        axisLabel: { color: COL.ink, fontSize: 12 } },
      tooltip: Object.assign({ trigger: 'axis', axisPointer: { type: 'shadow' } }, TIP),
      series: [{ type: 'bar', data: values, barWidth: 14, itemStyle: { color: color, borderRadius: [0, 4, 4, 0] },
        label: { show: true, position: 'right', color: COL.ink, formatter: function (p) { return num(p.value); } } }]
    };
  }
  function vbarOpt(labels, series) {
    var multi = series.length > 1;
    return {
      textStyle: { fontFamily: FONT },
      color: series.map(function (s) { return s.color; }),
      tooltip: Object.assign({ trigger: 'axis', axisPointer: { type: 'shadow' } }, TIP),
      legend: multi ? { top: 0, textStyle: { color: COL.ink } } : { show: false },
      grid: { left: 8, right: 12, top: multi ? 30 : 16, bottom: 8, containLabel: true },
      xAxis: { type: 'category', data: labels, axisTick: { show: false }, inverse: false,
        axisLabel: { color: COL.muted, interval: 0, fontSize: 11, width: 90, overflow: 'truncate' } },
      yAxis: { type: 'value', axisLabel: { color: COL.muted, formatter: function (v) { return compact(v); } },
        splitLine: { lineStyle: { color: '#eef0f3' } } },
      series: series.map(function (s) {
        return {
          name: s.name, type: 'bar', stack: s.stack, data: s.data, barMaxWidth: 36,
          itemStyle: { color: s.color, borderRadius: s.stack ? [0, 0, 0, 0] : [3, 3, 0, 0] },
          label: multi ? { show: false } : { show: true, position: 'top', color: COL.ink, formatter: function (p) { return compact(p.value); } }
        };
      })
    };
  }
  function gaugeOpt(pct) {
    return {
      series: [{
        type: 'gauge', startAngle: 180, endAngle: 0, center: ['50%', '78%'], radius: '105%', min: 0, max: 100,
        progress: { show: true, width: 22, itemStyle: { color: COL.gold } },
        axisLine: { lineStyle: { width: 22, color: [[1, '#e5e7eb']] } },
        pointer: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
        anchor: { show: false }, title: { show: false }, detail: { show: false },
        data: [{ value: pct }]
      }]
    };
  }
  function gaugeCard(pct, done, total) {
    chart('c-gauge', gaugeOpt(pct), function () { go('requests', { group: 'Completed' }); });
    return card(3, 'پیشرفت کلی',
      '<div class="db-gauge">' + chartHtml('c-gauge', 210)
      + '<div class="db-gauge-txt"><div class="db-gauge-v">' + fa(pct) + '٪</div><span>' + num(done) + ' از ' + num(total) + ' درخواست تکمیل شده</span></div></div>');
  }

  // ---------- comparison of the request types (New project / Expansion / Modernization / Release) ----------
  function compareCards(Dm) {
    var title = 'مقایسه نوع درخواست‌ها و منابع موردنیاز';
    if (!ok('demands')) { return { table: noticeCard(12, title, 'demands'), chart: '' }; }
    if (!Dm.length) {
      return { table: card(12, title, '<div class="db-empty">هنوز درخواست ظرفیتی از فرم‌های «Capacity request» ثبت نشده است.</div>'), chart: '' };
    }
    var types = RTYPES.filter(function (t) { return Dm.some(function (d) { return d.request_type === t; }); });
    Dm.forEach(function (d) { if (types.indexOf(d.request_type) < 0) { types.push(d.request_type); } });
    var cats = RCATS.concat(['Other']).filter(function (c) { return Dm.some(function (d) { return d.request_type !== 'Release old resources' && rcatOf(d) === c; }); });

    // chart: requests per type, split by the resource asked for
    var series = cats.map(function (c) {
      return { name: RCAT_FA[c], color: RCAT_COL[c], stack: 'req', cat: c,
        data: types.map(function (t) { return Dm.filter(function (d) { return d.request_type === t && rcatOf(d) === c && t !== 'Release old resources'; }).length; }) };
    });
    var rel = types.indexOf('Release old resources');
    if (rel >= 0) {
      series.push({ name: RTYPE_FA['Release old resources'], color: '#cbd5e1', stack: 'req', cat: '',
        data: types.map(function (t) { return t === 'Release old resources' ? Dm.filter(function (d) { return d.request_type === t; }).length : 0; }) });
    }
    var opt = {
      textStyle: { fontFamily: FONT },
      tooltip: Object.assign({ trigger: 'axis', axisPointer: { type: 'shadow' } }, TIP),
      legend: { bottom: 0, itemWidth: 12, itemHeight: 12, textStyle: { color: COL.ink, fontSize: 12 } },
      grid: { left: 8, right: 16, top: 8, bottom: 56, containLabel: true },
      xAxis: { type: 'value', minInterval: 1, axisLabel: { show: false }, splitLine: { lineStyle: { color: '#eef0f3' } } },
      yAxis: { type: 'category', inverse: true, data: types.map(function (t) { return String(RTYPE_FA[t] || t).replace(' (Swap)', ''); }), axisTick: { show: false },
        axisLine: { show: false }, axisLabel: { color: COL.ink, fontSize: 12, fontWeight: 600 } },
      series: series.map(function (sr) {
        return { name: sr.name, type: 'bar', stack: 'req', data: sr.data, barWidth: 22, itemStyle: { color: sr.color },
          label: { show: false } };
      })
    };
    chart('c-rtype', opt, function (e) {
      var c = series[e.seriesIndex] ? series[e.seriesIndex].cat : '';
      go('requests', { rtype: types[e.dataIndex], rcat: c });
    });

    // table: per type, requests by approval and the resources asked for, in their own units
    // quantity of one resource: servers, GPUs and network devices in units (a swapped device counts as one new unit),
    // storage in TB, and storage swaps (devices, not TB) apart
    var qty = function (list, c, swaps) {
      return sum(list.filter(function (d) {
        return rcatOf(d) === c && d.request_type !== 'Release old resources' && (c !== 'Storage' || (d.request_type === 'Modernization') === !!swaps);
      }), function (d) { return d.quantity; });
    };
    var cell = function (t, c, v, unit) {
      return v ? filterLink({ rtype: t, rcat: c }, num(Math.round(v * 10) / 10) + (unit ? ' <small>' + unit + '</small>' : ''), 'requests') : '<span class="db-dash">—</span>';
    };
    var rows = types.map(function (t) {
      var L = Dm.filter(function (d) { return d.request_type === t; });
      var by = countBy(L, function (d) { return d.approval; });
      return { t: t, L: L, n: L.length, ok: by.Approved || 0, wait: (by.Waiting || 0) + (by['Not required'] || 0), no: by.Refused || 0,
        cost: sum(L, function (d) { return d.boq_cost; }), est: sum(L, function (d) { return d.estimate; }) };
    });
    var total = { t: '', L: Dm, n: Dm.length, ok: sum(rows, function (r) { return r.ok; }), wait: sum(rows, function (r) { return r.wait; }),
      no: sum(rows, function (r) { return r.no; }), cost: sum(rows, function (r) { return r.cost; }), est: sum(rows, function (r) { return r.est; }) };
    var unitOf = { Compute: 'عدد', Storage: 'TB', GPU: 'عدد', Network: 'عدد' };
    var head = '<tr><th>نوع درخواست</th><th class="num">درخواست</th><th class="num">تأییدشده</th><th class="num">در انتظار</th><th class="num">ردشده</th>'
      + RCATS.map(function (c) { return '<th class="num">' + esc(RCAT_FA[c]) + '</th>'; }).join('')
      + '<th class="num">هزینه برآورد BOQ</th></tr>';
    var line = function (r, isTotal) {
      var name = isTotal ? '<b>جمع</b>' : filterLink({ rtype: r.t, rcat: '' }, '<span class="db-rtype"><i style="background:' + (r.t === 'Release old resources' ? '#cbd5e1' : '#f5b800') + '"></i>' + esc(RTYPE_FA[r.t] || r.t) + '</span>', 'requests');
      var t = isTotal ? '' : r.t;
      return '<tr' + (isTotal ? ' class="db-total"' : '') + '><td>' + name + '</td>'
        + '<td class="num">' + (r.n ? filterLink({ rtype: t, rcat: '' }, num(r.n), 'requests') : '0') + '</td>'
        + '<td class="num">' + num(r.ok) + '</td><td class="num">' + num(r.wait) + '</td><td class="num">' + num(r.no) + '</td>'
        + RCATS.map(function (c) {
            if (c !== 'Storage') { return '<td class="num">' + cell(t, c, qty(r.L, c), '') + '</td>'; }
            var tb = qty(r.L, c, false), dev = qty(r.L, c, true);
            if (tb && dev) { return '<td class="num">' + cell(t, c, tb, 'TB') + ' + ' + cell(t, c, dev, 'دستگاه') + '</td>'; }
            return '<td class="num">' + (dev ? cell(t, c, dev, 'دستگاه') : cell(t, c, tb, 'TB')) + '</td>';
          }).join('')
        + '<td class="num">' + (r.cost ? num(Math.round(r.cost)) : '<span class="db-dash">—</span>') + '</td></tr>';
    };
    var tbl = '<div class="db-tbl-wrap"><table id="db-rtype-tbl" class="db-tbl db-compare"><thead>' + head + '</thead><tbody>'
      + rows.map(function (r) { return line(r, false); }).join('') + line(total, true) + '</tbody></table></div>'
      + '<div class="db-note">سرور، GPU و تجهیز شبکه به عدد و فضای ذخیره‌سازی به ترابایت. «نوسازی و جایگزینی» هم زیرساخت جدید است: هر درخواست یک دستگاه نو است و در جمع حساب می‌شود (ذخیره‌سازی آن به دستگاه، کنار ترابایت). برای دیدن درخواست‌ها روی هر عدد کلیک کنید.</div>';

    return { table: card(12, title, tbl), chart: card(6, 'تعداد درخواست به تفکیک نوع و منبع', chartHtml('c-rtype', 300)) };
  }

  // ---------- pages ----------
  function renderOverview() {
    var top = need(['requests']);
    if (top) { return top; }
    var R = reqs();
    var moneyOk = ok('money');
    var demOk = ok('demands');
    var Dm = demOk ? dems() : [];
    var Fn = moneyOk ? fins() : [];
    var A = moneyOk ? asts() : [];

    var grp = countBy(R, function (r) { return GROUP[r.status] || 'Other'; });
    var n = R.length;
    var done = grp.Completed || 0;
    var prog = grp['In progress'] || 0;
    var wait = grp.Waiting || 0;
    var pct = n ? Math.round(done / n * 100) : 0;
    var cost = sum(Dm, function (d) { return d.boq_cost; });
    var cmp = compareCards(Dm);

    var kpis = kpi('کل درخواست‌ها', num(n), growth(R), 'gold', query('requests', { group: '' }))
      + kpi('در حال پیگیری / اجرا', num(prog), growth(R.filter(function (r) { return GROUP[r.status] === 'In progress'; })), 'blue', query('requests', { group: 'In progress' }))
      + kpi('تکمیل‌شده', num(done), growth(R.filter(function (r) { return GROUP[r.status] === 'Completed'; })), 'green', query('requests', { group: 'Completed' }))
      + kpi('در انتظار اقدام', num(wait), growth(R.filter(function (r) { return GROUP[r.status] === 'Waiting'; })), 'red', query('requests', { group: 'Waiting' }))
      + kpi('جمع هزینه خرید (BOQ)', demOk ? compact(cost) : '—', demOk ? growth(Dm) : null, 'green', D.root + '/plugins/projectreports/front/demand.php');

    // status donut
    var order = ['Completed', 'In progress', 'Waiting'];
    var colors = { Completed: COL.green, 'In progress': COL.blue, Waiting: COL.gold };
    var statusItems = order.filter(function (g) { return grp[g]; }).map(function (g) {
      return { name: GROUP_LABEL[g], value: grp[g], key: g, itemStyle: { color: colors[g] } };
    });
    chart('c-status', donutOpt(statusItems, 'درخواست'), function (e) { if (e.data && e.data.key) { go('requests', { group: e.data.key }); } });

    // requests by team
    var teamC = countBy(R, function (r) { return r.requesting_team; });
    var teams = Object.keys(teamC).sort(function (a, b) { return teamC[b] - teamC[a]; }).slice(0, 8);
    chart('c-team', vbarOpt(teams, [{ name: 'درخواست', data: teams.map(function (t) { return teamC[t]; }), color: COL.gold }]),
      function (e) { go('requests', { team: teams[e.dataIndex] }); });

    // requests by status
    var sc = countBy(R, function (r) { return r.status; });
    var stl = STATUSES.filter(function (s) { return sc[s]; });
    chart('c-hstatus', hbarOpt(stl.map(function (s) { return STATUS_FA[s]; }), stl.map(function (s) { return sc[s]; }), COL.gold),
      function (e) { go('requests', { status: stl[e.dataIndex] }); });

    var assetsCard = moneyOk
      ? (function () {
          var ac = countBy(A, function (a) { return a.asset_type; });
          var items = Object.keys(ac).map(function (k) { return { name: ASSET_FA[k] || k, value: ac[k], key: k }; });
          chart('c-assets', donutOpt(items, 'دارایی'), function (e) { if (e.data && e.data.key) { go('assets', { atype: e.data.key }); } });
          return card(3, 'دارایی‌ها بر اساس نوع', chartHtml('c-assets', 260));
        })()
      : noticeCard(3, 'دارایی‌ها بر اساس نوع', 'money');

    var costCard = moneyOk
      ? (function () {
          var P = Fn.slice().sort(function (a, b) { return b.allocated - a.allocated; }).slice(0, 8);
          chart('c-cost', vbarOpt(P.map(function (p) { return p.project; }), [
            { name: 'تخصیص‌یافته', data: P.map(function (p) { return Number(p.allocated); }), color: COL.blue, stack: 'cost' },
            { name: 'پیش‌نویس‌ها', data: P.map(function (p) { return Number(p.pipeline); }), color: COL.gold, stack: 'cost' }
          ]), function (e) { go('budget', { project: P[e.dataIndex].project }); });
          return card(6, 'هزینه بر اساس پروژه (بدون مالیات)', chartHtml('c-cost', 260));
        })()
      : noticeCard(6, 'هزینه بر اساس پروژه (بدون مالیات)', 'money');

    // latest requests with requester, equipment, order (PO, cost, warehouse), data center, installation and follow-up
    var DET = D.details || {};
    var det = function (r, k) { var x = DET[r.ticket_id]; return x && x[k] !== undefined && x[k] !== '' ? x[k] : null; };
    var dash = '<span class="db-dash">—</span>';
    var txt = function (k) { return function (r) { var v = det(r, k); return v === null ? dash : esc(v); }; };
    var many = function (k) { return function (r) { var l = det(r, k + '_links'); return l ? links(l) : (det(r, k) === null ? dash : esc(det(r, k))); }; };
    var person = function (k) { return function (r) { var v = det(r, k); return v === null ? dash : link(det(r, k + '_url'), esc(v)); }; };
    var day = function (k) { return function (r) { var v = det(r, k); return v === null ? dash : jdate(v); }; };
    var latest = R.slice().sort(byDateDesc);
    var latestCols = [
      { h: 'درخواست', f: function (r) { return link(rec('Ticket', r.ticket_id), '<span class="db-id">REQ-' + esc(fa(r.ticket_id)) + '</span><div class="db-sub-t">' + esc(r.title) + '</div>', 'باز کردن درخواست در GLPI'); } },
      { h: 'درخواست‌کننده', f: person('requester') },
      { h: 'معاونت', f: function (r) { return r.requesting_team && r.requesting_team !== '(no team)' ? link(rec('Group', r.team_id), esc(r.requesting_team)) : dash; } },
      { h: 'تجهیزات', f: many('equipment') }
    ];
    if (D.sections && D.sections.money && D.sections.money.state === 'ok') {
      latestCols.push({ h: 'هزینه خرید (تومان)', num: true, f: function (r) { var v = det(r, 'cost'); return v === null ? dash : num(v); } });
      latestCols.push({ h: 'شماره PO', f: function (r) { var l = det(r, 'po_links'); return l ? '<span class="db-id">' + links(l) + '</span>' : (det(r, 'po') === null ? dash : esc(det(r, 'po'))); } });
    }
    latestCols.push(
      { h: 'تاریخ ورود به انبار', f: day('warehouse') },
      { h: 'دیتاسنتر', f: many('datacenter') },
      { h: 'برنامه نصب', f: day('install') },
      { h: 'پیگیری‌کننده', f: function (r) { var v = det(r, 'follower'); return v ? link(det(r, 'follower_url'), esc(v)) : (r.assigned_to ? esc(r.assigned_to) : dash); } },
      { h: 'وضعیت', f: function (r) { return filterLink({ status: r.status }, chip(STATUS_FA[r.status] || r.status, GROUP_CLS[GROUP[r.status]]), 'requests'); } }
    );
    var latestCard = card(12, 'آخرین وضعیت درخواست‌ها', table('db-latest', latestCols, latest, 10));

    return '<div class="db-kpis">' + kpis + '</div>'
      + '<div class="db-grid">'
      + card(4, 'وضعیت کلی درخواست‌ها', chartHtml('c-status', 260))
      + card(5, 'درخواست‌ها بر اساس تیم', chartHtml('c-team', 260))
      + card(3, 'درخواست‌ها بر اساس مرحله', chartHtml('c-hstatus', 260))
      + cmp.table
      + cmp.chart
      + gaugeCard(pct, done, n)
      + assetsCard
      + costCard.replace('db-card s6', 'db-card s12')
      + latestCard
      + '</div>';
  }

  function renderRequests() {
    var miss = need(['requests']);
    if (miss) { return miss; }
    var R = reqs().sort(byDateDesc);
    var open = R.filter(function (r) { return r.is_open === 1; });
    var over = R.filter(function (r) { return r.is_overdue === 1; });
    var solved = R.filter(function (r) { return r.is_open === 0 && r.days_to_solve != null; });
    var avg = solved.length ? sum(solved, function (r) { return r.days_to_solve; }) / solved.length : 0;

    var kpis = kpi('درخواست‌ها', num(R.length), growth(R), 'gold', query('requests', { open: '', sla: '', age: '' }))
      + kpi('باز', num(open.length), growth(open), 'blue', query('requests', { open: '1' }))
      + kpi('دیرکرد', num(over.length), null, 'red', query('requests', { sla: 'Overdue' }))
      + kpi('میانگین روز تا حل', decimal(avg, 1), null, 'green');

    var keys = ['1', '2', '3', '4'];
    var ac = countBy(open.filter(function (r) { return r.open_age; }), function (r) { return String(r.open_age).charAt(0); });
    chart('c-age', vbarOpt(AGE_FA, [
      { name: 'باز', data: keys.map(function (k) { return ac[k] || 0; }), color: COL.blue }
    ]), function (e) { go('requests', { age: keys[e.dataIndex], open: '1' }); });

    var cc = countBy(R, function (r) { return r.category; });
    var cats = Object.keys(cc).sort(function (a, b) { return cc[b] - cc[a]; }).slice(0, 10);
    chart('c-cat', hbarOpt(cats, cats.map(function (c) { return cc[c]; }), COL.gold), function (e) { go('requests', { category: cats[e.dataIndex] }); });

    var tbl = table('db-req-tbl', [
      { h: 'درخواست', f: function (r) { return link(rec('Ticket', r.ticket_id), 'REQ-' + esc(fa(r.ticket_id)), 'باز کردن درخواست در GLPI'); } },
      { h: 'عنوان', f: function (r) { return link(rec('Ticket', r.ticket_id), esc(r.title)); } },
      { h: 'پروژه', f: function (r) { return link(rec('Project', r.project_id), esc(r.project)); } },
      { h: 'تیم', f: function (r) { return link(rec('Group', r.team_id), esc(r.requesting_team)); } },
      { h: 'دسته‌بندی', f: function (r) { return filterLink({ category: r.category }, esc(r.category)); } },
      { h: 'وضعیت', f: function (r) { return filterLink({ status: r.status }, chip(STATUS_FA[r.status] || r.status, GROUP_CLS[GROUP[r.status]])); } },
      { h: 'SLA', f: function (r) { return r.sla ? chip(r.sla === 'Overdue' ? 'دیرکرد' : 'به‌موقع', r.sla === 'Overdue' ? 'bad' : 'ok') : ''; } },
      { h: 'تاریخ ثبت', f: function (r) { return jdate(r.opened); } },
      { h: 'سررسید', f: function (r) { return jdate(r.due); } }
    ], R, 500);

    return '<div class="db-kpis">' + kpis + '</div>'
      + '<div class="db-grid">'
      + card(4, 'درخواست‌های باز بر اساس سن', chartHtml('c-age', 240))
      + card(8, 'درخواست‌ها بر اساس دسته‌بندی (ده مورد اول)', chartHtml('c-cat', 240))
      + card(12, 'همه درخواست‌ها', '<input type="search" class="db-search" placeholder="جستجو در درخواست‌ها">' + tbl)
      + '</div>';
  }

  function renderBudget() {
    var miss = need(['money']);
    if (miss) { return miss; }
    var Fn = fins().sort(function (a, b) { return b.budget - a.budget; });
    var B = sum(Fn, function (f) { return f.budget; });
    var Al = sum(Fn, function (f) { return f.allocated; });
    var Pi = sum(Fn, function (f) { return f.pipeline; });
    var Rm = sum(Fn, function (f) { return f.remaining; });

    var kpis = kpi('کل بودجه', compact(B), null, 'gold')
      + kpi('تخصیص‌یافته (BOQ + دارایی)', compact(Al), null, 'blue')
      + kpi('پیش‌نویس‌ها', compact(Pi), null, 'gold')
      + kpi('باقی‌مانده', compact(Rm), null, Rm < 0 ? 'red' : 'green');

    var labels = Fn.map(function (f) { return f.project; });
    chart('c-bud', vbarOpt(labels, [
      { name: 'بودجه', data: Fn.map(function (f) { return Number(f.budget); }), color: COL.grey },
      { name: 'تخصیص‌یافته', data: Fn.map(function (f) { return Number(f.allocated); }), color: COL.blue }
    ]), function (e) { var u = rec('Project', Fn[e.dataIndex].project_id); if (u) { window.location.href = u; } });

    var RUNS = { 'lasts to end': 'تا پایان دوره کافی است', 'no BOQ spend yet': 'هنوز هزینه‌ای ثبت نشده', 'already exceeded': 'از بودجه گذشته' };
    var tbl = table('db-bud-tbl', [
      { h: 'پروژه', f: function (f) { return link(rec('Project', f.project_id), esc(f.project), 'باز کردن پروژه در GLPI'); } },
      { h: 'دسته‌بندی', f: function (f) { return esc(f.category); } },
      { h: 'بودجه', num: true, f: function (f) { return num(f.budget); } },
      { h: 'تخصیص‌یافته', num: true, f: function (f) { return num(f.allocated); } },
      { h: 'مصرف', f: function (f) {
          var p = Math.round((Number(f.pct_used) || 0) * 100);
          return '<div class="db-meter ' + (f.is_over_budget ? 'over' : '') + '"><i style="width:' + Math.min(100, p) + '%"></i></div>' + fa(p) + '٪';
        } },
      { h: 'پیش‌نویس‌ها', num: true, f: function (f) { return num(f.pipeline); } },
      { h: 'باقی‌مانده', num: true, f: function (f) { return num(f.remaining); } },
      { h: 'تمام شدن بودجه', f: function (f) { return esc(RUNS[f.budget_runs_out] || fa(f.budget_runs_out || '')); } },
      { h: 'پیش‌بینی پایان', num: true, f: function (f) { return num(f.forecast_at_end); } },
      { h: 'تمدیدها (۱۲ ماه)', num: true, f: function (f) { return num(f.renewals_12m); } }
    ], Fn);

    return '<div class="db-kpis">' + kpis + '</div>'
      + '<div class="db-grid">'
      + card(12, 'بودجه در مقابل تخصیص‌یافته، به تفکیک پروژه', chartHtml('c-bud', 280))
      + card(12, 'جزئیات بودجه', tbl)
      + '</div>';
  }

  function renderAssets() {
    // the current inventory first, then value, warranties and renewals
    return inventorySection()
      + (ok('money') ? '<div class="db-section-h">ارزش، گارانتی و تمدید دارایی‌ها</div>' + renderAssetMoney() : '');
  }

  // ---------- current inventory (servers, storage, switches, racks, cables, spare RAM and disks) ----------
  // broken / under repair equipment is not shown (status words: broken, repair, faulty, خراب, تعمیر, معیوب)
  var notBroken = function (x) { return stKey(x.state) !== 'broken'; };
  var INV = (D.inventory || []).filter(notBroken);
  var SPARES = (D.spares || []).filter(notBroken);
  var CABLES = (D.cables || []).filter(notBroken);
  var IKINDS = ['Server', 'Storage', 'Switch', 'Core switch', 'Patch panel'];
  var IKIND_FA = { Server: 'سرور', Storage: 'فضای ذخیره‌سازی', Switch: 'سوئیچ', 'Core switch': 'سوئیچ هسته', 'Patch panel': 'پچ‌پنل', Cable: 'کابل', RAM: 'رم یدکی', Disk: 'دیسک یدکی' };
  var ST_ORDER = ['use', 'stock', 'spare'];
  var ST_FA = { use: 'در حال استفاده', stock: 'در انبار', spare: 'یدکی', broken: 'خراب / تعمیر', none: 'بدون وضعیت' };
  var ST_CLS = { use: 'ok', stock: 'info', spare: 'warn', broken: 'bad', none: '' };
  // a GLPI status name -> in use / in stock / spare / broken (by its words), else the name itself
  function stKey(name) {
    var n = String(name || '').toLowerCase();
    if (!n) { return 'none'; }
    if (/broken|repair|faulty|خراب|تعمیر|معیوب/.test(n)) { return 'broken'; }
    if (/spare|یدک/.test(n)) { return 'spare'; }
    if (/stock|warehouse|store|انبار/.test(n)) { return 'stock'; }
    if (/use|production|active|operational|استفاده|فعال|عملیاتی/.test(n)) { return 'use'; }
    return 'x:' + name;
  }
  function stLabel(k) { return ST_FA[k] || String(k).replace(/^x:/, '').replace(/^DEMO /, ''); }
  function stChip(name) { var k = stKey(name); return chip(stLabel(k), ST_CLS[k] || ''); }
  function gb(mb) { return Number(mb || 0) / 1024; }
  function tb(mb) { return Number(mb || 0) / 1048576; }
  function size(mb) { var t = tb(mb); return t >= 1 ? decimal(t, t >= 10 ? 0 : 1) + ' TB' : num(Math.round(gb(mb))) + ' GB'; }
  function invRows() {
    return INV.filter(function (r) {
      return (!F.project || String(r.project || '').split(', ').indexOf(F.project) >= 0)
        && (!X.ikind || r.kind === X.ikind) && (!X.istate || stKey(r.state) === X.istate);
    });
  }

  function inventorySection() {
    var title = '<div class="db-section-h">موجودی فعلی: سرور، ذخیره‌سازی، رک، کابل، دیسک و رم</div>';
    if (!ok('inventory')) { return title + need(['inventory']); }
    var all = INV.filter(function (r) { return !F.project || String(r.project || '').split(', ').indexOf(F.project) >= 0; });
    var srv = all.filter(function (r) { return r.kind === 'Server'; });
    var sto = all.filter(function (r) { return r.kind === 'Storage'; });
    var nUse = srv.filter(function (r) { return stKey(r.state) === 'use'; }).length;
    var nSpare = sum(SPARES, function (x) { return x.n; });
    var kpis = kpi('سرورها', num(srv.length), { plain: true, txt: num(nUse) + ' در حال استفاده' }, 'gold', query('assets', { ikind: 'Server', istate: '' }) + '#db-inv')
      + kpi('فضای ذخیره‌سازی (خام)', size(sum(sto, function (r) { return r.disk_mb; })), { plain: true, txt: num(sto.length) + ' دستگاه' }, 'blue', query('assets', { ikind: 'Storage', istate: '' }) + '#db-inv')
      + kpi('رم نصب‌شده', size(sum(srv, function (r) { return r.ram_mb; })), { plain: true, txt: num(sum(srv, function (r) { return r.ram_modules; })) + ' ماژول' }, 'violet', query('assets', { ikind: 'Server', istate: '' }) + '#db-inv')
      + kpi('دیسک سرورها', size(sum(srv, function (r) { return r.disk_mb; })), { plain: true, txt: num(sum(srv, function (r) { return r.disks; })) + ' دیسک' }, 'green', query('assets', { ikind: 'Server', istate: '' }) + '#db-inv')
      + kpi('کابل‌ها', num(CABLES.length), { plain: true, txt: num(Object.keys(countBy(CABLES, function (c) { return c.type || '-'; })).length) + ' نوع کابل' }, 'gold', query('assets', {}) + '#db-cab')
      + kpi('قطعات یدکی', num(nSpare), { plain: true, txt: 'رم و دیسک در انبار' }, 'red', query('assets', {}) + '#db-spare');

    // 1. how many of each kind, by status (every count opens the list below)
    var states = ST_ORDER.slice();
    var extra = uniq(all.map(function (r) { return stKey(r.state); }).concat(CABLES.map(function (c) { return stKey(c.state); }), SPARES.map(function (x) { return stKey(x.state); })))
      .filter(function (k) { return states.indexOf(k) < 0 && k !== 'none'; });
    states = states.concat(extra, ['none']).filter(function (k) {
      return all.some(function (r) { return stKey(r.state) === k; }) || CABLES.some(function (c) { return stKey(c.state) === k; }) || SPARES.some(function (x) { return stKey(x.state) === k; });
    });
    var countRow = function (label, items, stateOf, hrefOf, nOf) {
      var n = function (list) { return nOf ? sum(list, nOf) : list.length; };
      return '<tr><td><b>' + esc(label) + '</b></td>' + states.map(function (k) {
        var v = n(items.filter(function (x) { return stKey(stateOf(x)) === k; }));
        return '<td class="num">' + (v ? link(hrefOf(k), num(v)) : '<span class="db-dash">—</span>') + '</td>';
      }).join('') + '<td class="num"><b>' + link(hrefOf(''), num(n(items))) + '</b></td></tr>';
    };
    var kindRows = IKINDS.filter(function (k) { return all.some(function (r) { return r.kind === k; }); }).map(function (k) {
      return countRow(IKIND_FA[k], all.filter(function (r) { return r.kind === k; }), function (r) { return r.state; },
        function (st) { return query('assets', { ikind: k, istate: st }) + '#db-inv'; });
    }).join('')
      + (CABLES.length ? countRow('کابل', CABLES, function (c) { return c.state; }, function () { return query('assets', {}) + '#db-cab'; }) : '')
      + ['RAM', 'Disk'].filter(function (k) { return SPARES.some(function (x) { return x.kind === k; }); }).map(function (k) {
          return countRow(IKIND_FA[k], SPARES.filter(function (x) { return x.kind === k; }), function (x) { return x.state; },
            function () { return query('assets', {}) + '#db-spare'; }, function (x) { return x.n; });
        }).join('');
    var byState = '<div class="db-tbl-wrap"><table class="db-tbl db-compare"><thead><tr><th>نوع</th>'
      + states.map(function (k) { return '<th class="num">' + esc(stLabel(k)) + '</th>'; }).join('') + '<th class="num">جمع</th></tr></thead><tbody>'
      + kindRows + '</tbody></table></div>';

    // 2. the equipment list (servers, storage, switches, patch panels), with RAM and disks
    var R = invRows().sort(function (a, b) { return (IKINDS.indexOf(a.kind) - IKINDS.indexOf(b.kind)) || (b.ram_mb - a.ram_mb) || (b.disk_mb - a.disk_mb) || String(a.name).localeCompare(String(b.name)); });
    var invTbl = table('db-inv-tbl', [
      { h: 'تجهیز', f: function (r) { return link(r.url, '<b>' + esc(r.name) + '</b>', 'باز کردن در GLPI') + (r.serial ? '<div class="db-sub-t">' + esc(r.serial) + '</div>' : ''); } },
      { h: 'نوع', f: function (r) { return filterLink({ ikind: r.kind }, esc(IKIND_FA[r.kind] || r.kind)); } },
      { h: 'مدل', f: function (r) { return esc(r.model || '') + (r.manufacturer ? '<div class="db-sub-t">' + esc(r.manufacturer) + '</div>' : ''); } },
      { h: 'وضعیت', f: function (r) { return filterLink({ istate: stKey(r.state) }, stChip(r.state)); } },
      { h: 'محل', f: function (r) {
          return r.rack ? link(r.rack_url, esc(r.datacenter) + ' / رک ' + esc(r.rack)) + (r.position ? '<div class="db-sub-t">U' + fa(r.position) + '</div>' : '')
            : esc(String(r.location || '').replace(/^DEMO /, '')) || '<span class="db-dash">—</span>';
        } },
      { h: 'پروژه', f: function (r) { return r.project ? esc(r.project) : '<span class="db-dash">—</span>'; } },
      { h: 'رم', num: true, f: function (r) { return r.ram_mb ? num(Math.round(gb(r.ram_mb))) + ' GB<div class="db-sub-t">' + num(r.ram_modules) + ' ماژول</div>' : '<span class="db-dash">—</span>'; } },
      { h: 'دیسک', num: true, f: function (r) { return r.disk_mb ? size(r.disk_mb) + '<div class="db-sub-t">' + num(r.disks) + ' دیسک</div>' : '<span class="db-dash">—</span>'; } }
    ], R, 2000);

    // 3. racks: units, power and weight used against each rack's limits, fullest first
    var rackCard = '';
    if (ok('racks')) {
      var used = {};
      (D.rack_items || []).forEach(function (x) {
        var u = used[x.rack_id] || (used[x.rack_id] = { u: 0, w: 0, kg: 0, n: 0 });
        u.u += Number(x.units || 0); u.w += Number(x.power_w || 0); u.kg += Number(x.weight_kg || 0); u.n++;
      });
      var meter = function (v, max, unit) {
        if (!max) { return '<span class="db-dash">—</span>'; }
        var pc = Math.round(v / max * 100);
        return '<div class="db-meter ' + (pc >= 85 ? 'over' : '') + '"><i style="width:' + Math.min(100, pc) + '%"></i></div>' + num(Math.round(v)) + ' از ' + num(max) + ' ' + unit;
      };
      var racks = (D.racks || []).map(function (r) {
        var u = used[r.rack_id] || { u: 0, w: 0, kg: 0, n: 0 };
        var ratio = function (v, max) { return Number(max) ? v / Number(max) : 0; };
        // fullest = the limit (units, power or weight) closest to being reached
        return { r: r, u: u.u + Number(r.reserved || 0), w: u.w, kg: u.kg, n: u.n,
          pc: Math.max(ratio(u.u + Number(r.reserved || 0), r.rack_units), ratio(u.w, r.max_power), ratio(u.kg, r.max_weight)) };
      }).sort(function (a, b) { return b.pc - a.pc; });
      var rackTbl = table('db-rack-tbl', [
        { h: 'رک', f: function (x) { return link(rec('Rack', x.r.rack_id) || '', '<b>' + esc(x.r.rack) + '</b>') + '<div class="db-sub-t">' + esc(x.r.datacenter) + ' / ' + esc(x.r.room) + '</div>'; } },
        { h: 'تجهیزات', num: true, f: function (x) { return num(x.n); } },
        { h: 'یونیت (U)', f: function (x) { return meter(x.u, Number(x.r.rack_units), 'U'); } },
        { h: 'برق', f: function (x) { return meter(x.w, Number(x.r.max_power), 'W'); } },
        { h: 'وزن', f: function (x) { return meter(x.kg, Number(x.r.max_weight), 'kg'); } }
      ], racks, 500);
      rackCard = card(12, 'ظرفیت رک‌ها (نزدیک‌ترین به سقف یونیت، برق یا وزن اول)', rackTbl);
    }

    // 4. spare RAM and disks in stock
    var spareTbl = table('db-spare-tbl', [
      { h: 'قطعه', f: function (x) { return link(x.url, esc(x.model)) + '<div class="db-sub-t">' + esc(x.kind === 'RAM' ? 'رم' : 'دیسک') + ' · ' + size(x.size_mb) + '</div>'; } },
      { h: 'وضعیت', f: function (x) { return stChip(x.state); } },
      { h: 'محل', f: function (x) { return esc(String(x.location || '').replace(/^DEMO /, '')) || '<span class="db-dash">—</span>'; } },
      { h: 'تعداد', num: true, f: function (x) { return '<b>' + num(x.n) + '</b>'; } }
    ], SPARES.slice().sort(function (a, b) { return b.n - a.n; }), 200);

    // 5. cables, with both ends
    var C = CABLES;
    var ctypes = countBy(CABLES, function (c) { return c.type || 'بدون نوع'; });
    var end = function (e) { return e ? link(e.url, esc(e.name)) : '<span class="db-dash">—</span>'; };
    var cabTbl = '<div class="db-cab-types">' + Object.keys(ctypes).map(function (t) { return chip(String(t).replace(/^DEMO /, '') + ': ' + num(ctypes[t]), 'info'); }).join(' ')
      + '</div>'
      + table('db-cab-tbl', [
        { h: 'کابل', f: function (c) { return link(c.url, '<b>' + esc(c.name) + '</b>') + (c.color ? ' <i class="db-swatch" style="background:' + esc(c.color) + '"></i>' : ''); } },
        { h: 'نوع', f: function (c) { return esc(String(c.type || '').replace(/^DEMO /, '')); } },
        { h: 'سر A', f: function (c) { return end(c.a); } },
        { h: 'سر B', f: function (c) { return end(c.b); } },
        { h: 'وضعیت', f: function (c) { return stChip(c.state); } }
      ], C, 5000);

    return title + '<div class="db-kpis db-kpis-6">' + kpis + '</div><div class="db-grid">'
      + card(12, 'موجودی بر اساس نوع و وضعیت', byState + '<div class="db-note">روی هر عدد کلیک کنید تا فهرست همان تجهیزات را ببینید. وضعیت‌ها از فیلد «وضعیت» GLPI خوانده می‌شوند.</div>')
      + '<section class="db-card s12" id="db-inv"><div class="db-card-h">فهرست تجهیزات (سرور، ذخیره‌سازی، سوئیچ، پچ‌پنل)</div><div class="db-card-b">'
      + '<input type="search" class="db-search" placeholder="جستجو: نام، مدل، رک، پروژه...">' + invTbl + '</div></section>'
      + rackCard
      + '<section class="db-card s12" id="db-spare"><div class="db-card-h">قطعات یدکی در انبار (رم و دیسک)</div><div class="db-card-b">'
      + (SPARES.length ? spareTbl : '<div class="db-empty">قطعه‌ای بدون دستگاه (در انبار) ثبت نشده است.</div>') + '</div></section>'
      + '<section class="db-card s12" id="db-cab"><div class="db-card-h">کابل‌ها</div><div class="db-card-b">'
      + (CABLES.length ? '<input type="search" class="db-search" placeholder="جستجو: کابل، دستگاه...">' + cabTbl : '<div class="db-empty">کابلی ثبت نشده است (Assets › Cables).</div>') + '</div></section>'
      + '</div>';
  }

  function renderAssetMoney() {
    var A = asts().sort(function (a, b) { return String(a.asset_type).localeCompare(String(b.asset_type)); });
    var value = sum(A, function (a) { return a.asset_value; });
    var renew = sum(A, function (a) { return a.renew_next_24m; });
    var eol = sum(A, function (a) { return a.eol_next_5y; });

    var kpis = kpi('تعداد دارایی', num(A.length), null, 'gold')
      + kpi('ارزش کل دارایی‌ها', compact(value), null, 'blue')
      + kpi('گارانتی تا ۲۴ ماه آینده', num(renew), null, 'red')
      + kpi('پایان عمر تا ۵ سال آینده', num(eol), null, 'gold');

    var tc = countBy(A, function (a) { return a.asset_type; });
    var types = Object.keys(tc).sort(function (a, b) { return tc[b] - tc[a]; });
    chart('c-types', vbarOpt(types.map(function (t) { return ASSET_FA[t] || t; }), [
      { name: 'دارایی', data: types.map(function (t) { return tc[t]; }), color: COL.blue }
    ]), function (e) { go('assets', { atype: types[e.dataIndex] }); });

    var tbl = table('db-asset-tbl', [
      { h: 'نوع', f: function (a) { return filterLink({ atype: a.asset_type }, esc(ASSET_FA[a.asset_type] || a.asset_type)); } },
      { h: 'دارایی', f: function (a) { return link(rec(a.itemtype, a.asset_id), esc(a.asset_name || fa(a.asset_id)), 'باز کردن دارایی در GLPI'); } },
      { h: 'پروژه', f: function (a) { return link(rec('Project', a.project_id), esc(a.project)); } },
      { h: 'تأمین‌کننده', f: function (a) { return link(rec('Supplier', a.supplier_id), esc(a.supplier)); } },
      { h: 'ارزش', num: true, f: function (a) { return num(a.asset_value); } },
      { h: 'بهره‌برداری', f: function (a) { return jdate(a.in_service); } },
      { h: 'پایان گارانتی', f: function (a) { return jdate(a.warranty_end); } },
      { h: 'هزینه تمدید', num: true, f: function (a) { return num(a.renewal_cost); } },
      { h: 'پایان عمر', f: function (a) { return jdate(a.end_of_life); } },
      { h: 'هشدارها', f: function (a) {
          return (a.renew_next_24m ? chip('تمدید ۲۴ ماهه', 'warn') : '') + ' ' + (a.eol_next_5y ? chip('پایان عمر ۵ ساله', 'info') : '');
        } }
    ], A, 300);

    return '<div class="db-kpis">' + kpis + '</div>'
      + '<div class="db-grid">'
      + card(5, 'دارایی‌ها بر اساس نوع', chartHtml('c-types', 240))
      + card(7, 'راهنمای هشدارها', '<div class="db-empty">تمدید ۲۴ ماهه: گارانتی تا ۲۴ ماه آینده تمام می‌شود. پایان عمر ۵ ساله: پنج سال پس از تاریخ بهره‌برداری.</div>')
      + card(12, 'فهرست دارایی‌ها', tbl)
      + '</div>';
  }

  // ---------- forecast: infrastructure needed in the next 12 months ----------
  // need = open requests + devices reaching their age limit + VM cluster growth (VirOPS) + the iCare delivery trend
  // beyond what is already known, minus what is in the warehouse.
  var FC = D.forecast || {};
  var FC_RES = ['Compute', 'Storage', 'StorageDev', 'GPU', 'Network'];
  var FC_FA = { Compute: 'سرور', Storage: 'فضای ذخیره‌سازی', StorageDev: 'دستگاه ذخیره‌سازی', GPU: 'GPU', Network: 'تجهیز شبکه' };
  var FC_UNIT = { Compute: 'عدد', Storage: 'TB', StorageDev: 'دستگاه', GPU: 'عدد', Network: 'عدد' };
  var FC_SRC = [['dem', 'درخواست‌های باز', '#f5b800'], ['mod', 'نوسازی (عمر تجهیز)', '#7c5cf0'], ['vm', 'توسعه کلاسترهای VM', '#2f7de1'], ['trend', 'روند iCare (پیش‌بینی‌نشده)', '#0fb5a6']];
  var AGE_KIND = { Server: 'Server', Storage: 'Storage', Switch: 'Network', 'Core switch': 'Network' };
  var KIND_RES = { Server: 'Compute', Storage: 'StorageDev', Switch: 'Network', 'Core switch': 'Network' };

  function fcModel() {
    var H = 12;   // months
    var end = new Date(TODAY.getTime()); end.setMonth(end.getMonth() + H);
    var m = {};
    FC_RES.forEach(function (r) { m[r] = { dem: 0, mod: 0, vm: 0, vmRaw: 0, trend: 0, trendProj: null, stock: 0, total: 0, net: 0, cost: null }; });

    // 1. open capacity requests (teams, new projects, swaps)
    var open = DEM.filter(function (d) { return d.status_id < 5 && !d.refused && d.request_type !== 'Release old resources'; });
    var resOf = function (d) {
      var c = rcatOf(d);
      if (c === 'Storage') { return d.request_type === 'Modernization' ? 'StorageDev' : 'Storage'; }
      return m[c] ? c : '';
    };
    open.forEach(function (d) { var r = resOf(d); if (r) { m[r].dem += Number(d.quantity || 0); } });
    var openExp = {};
    open.forEach(function (d) { if (d.request_type === 'Expansion') { var r = resOf(d); openExp[r] = (openExp[r] || 0) + Number(d.quantity || 0); } });

    // 2. devices in use that reach their type's age limit before the end of the period (not already asked for in a swap request)
    var asked = {};
    open.forEach(function (d) { if (d.request_type === 'Modernization' && d.hostname) { asked[String(d.hostname).toLowerCase()] = d; } });
    var ages = FC.ages || {};
    // the DCIM device list when one is imported (linked to the GLPI asset of the same name), else GLPI's own assets
    var DC = (FC.dcim || {}).rows || [];
    var byName = {};
    INV.forEach(function (r) { byName[String(r.name).toLowerCase()] = r; });
    var DCKIND = { Server: 'Server', Storage: 'Storage', Network: 'Switch' };
    var devices = DC.length ? DC.filter(function (d) { return d.in_use && DCKIND[d.kind]; }).map(function (d) {
      var g = byName[String(d.hostname).toLowerCase()] || {};
      return { name: d.hostname, kind: DCKIND[d.kind], model: d.model || g.model || '', in_service: d.in_service, url: g.url || '',
        rack: d.rack, rack_url: '', datacenter: d.location, location: d.location, dcim: true, typeLabel: d.type };
    }) : INV.filter(function (r) { return AGE_KIND[r.kind] && stKey(r.state) === 'use' && r.in_service; });
    var mod = devices.map(function (r) {
      var limit = Number(ages[AGE_KIND[r.kind]] || 0);
      var start = new Date(String(r.in_service).slice(0, 10) + 'T00:00:00');
      var due = new Date(start.getTime()); due.setMonth(due.getMonth() + Math.round(limit * 12));
      return { r: r, limit: limit, age: (TODAY - start) / (365.25 * 864e5), due: due, req: asked[String(r.name).toLowerCase()] || null };
    }).filter(function (x) { return x.limit > 0 && x.due <= end; }).sort(function (a, b) { return a.due - b.due; });
    mod.forEach(function (x) { if (!x.req) { m[KIND_RES[x.r.kind]].mod += 1; } });

    // 3. VM clusters: use in 12 months at the current growth, against the target use; hosts and TB missing
    var target = Number(FC.target_util || 80) / 100;
    var CL = ((FC.virops || {}).clusters || []).map(function (c) {
      var proj = function (use, g) { return Math.max(0, Number(use) + Number(g) * H); };
      var cpuP = proj(c.cpu_use_ghz, c.cpu_growth_ghz), ramP = proj(c.ram_use_gb, c.ram_growth_gb), stoP = proj(c.sto_use_tb, c.sto_growth_tb);
      var hosts = Number(c.hosts) || 1;
      var cpuShort = Math.max(0, cpuP / target - c.cpu_cap_ghz), ramShort = Math.max(0, ramP / target - c.ram_cap_gb);
      var needHosts = Math.ceil(Math.max(c.cpu_cap_ghz ? cpuShort / (c.cpu_cap_ghz / hosts) : 0, c.ram_cap_gb ? ramShort / (c.ram_cap_gb / hosts) : 0) - 1e-9);
      var needTb = Math.max(0, stoP / target - c.sto_cap_tb);
      var months = function (use, cap, g) { var room = cap * target - use; return room <= 0 ? 0 : (g > 0 ? room / g : Infinity); };
      var full = Math.min(months(c.cpu_use_ghz, c.cpu_cap_ghz, c.cpu_growth_ghz), months(c.ram_use_gb, c.ram_cap_gb, c.ram_growth_gb), months(c.sto_use_tb, c.sto_cap_tb, c.sto_growth_tb));
      return { c: c, cpuP: cpuP, ramP: ramP, stoP: stoP, hosts: Math.max(0, needHosts), tb: needTb, full: full };
    });
    m.Compute.vmRaw = sum(CL, function (x) { return x.hosts; });
    m.Storage.vmRaw = sum(CL, function (x) { return x.tb; });
    // open expansion requests are already counted above: only what they do not cover is added
    ['Compute', 'Storage'].forEach(function (r) { m[r].vm = Math.max(0, m[r].vmRaw - (openExp[r] || 0)); });

    // 4. iCare: deliveries per 12-month period ending this month (works for Jalali and Gregorian files alike);
    //    a straight line through the past full periods gives the next 12 months
    var trend = (FC.icare || {}).trend || [];
    var nowM = TODAY.getFullYear() * 12 + TODAY.getMonth();
    var ago = function (ym) { var p = String(ym).split('-'); return nowM - (Number(p[0]) * 12 + Number(p[1]) - 1); };
    var periodOf = function (t) { return Math.floor(ago(t.ym) / 12); };   // 0 = the last 12 months, 1 = the 12 before ...
    var past = trend.filter(function (t) { return ago(t.ym) >= 0; });
    var oldest = past.length ? Math.max.apply(null, past.map(function (t) { return ago(t.ym); })) : -1;
    // the oldest period counts only if the data covers it (almost) entirely
    var nP = oldest < 0 ? 0 : (oldest % 12 >= 10 ? Math.floor(oldest / 12) + 1 : Math.floor(oldest / 12));
    var periods = [];
    for (var pi = Math.min(nP, 8) - 1; pi >= 0; pi--) { periods.push(pi); }
    var series = {};
    ['Compute', 'Storage', 'GPU', 'Network'].forEach(function (r) {
      series[r] = periods.map(function (pi) { return sum(past.filter(function (t) { return t.resource === r && periodOf(t) === pi; }), function (t) { return t.qty; }); });
      var pts = periods.map(function (pi, i) { return [-pi, series[r][i]]; });
      if (!pts.length || !pts.some(function (p) { return p[1] > 0; })) { return; }
      var proj;
      if (pts.length < 2) { proj = pts[0][1]; } else {
        var mx = sum(pts, function (p) { return p[0]; }) / pts.length, my = sum(pts, function (p) { return p[1]; }) / pts.length;
        var nu = sum(pts, function (p) { return (p[0] - mx) * (p[1] - my); }), de = sum(pts, function (p) { return (p[0] - mx) * (p[0] - mx); });
        proj = Math.max(0, my + (de ? nu / de : 0) * (1 - mx));
      }
      m[r].trendProj = proj;
      m[r].trend = Math.max(0, proj - (m[r].dem + m[r].mod + m[r].vm));
    });
    // label of each period: the Jalali month it ends with
    var years = periods.map(function (pi) {
      var d = new Date(TODAY.getFullYear(), TODAY.getMonth() - 12 * pi, 15);
      return 'تا ' + jdate(d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-15').slice(0, 7);
    });

    // 5. warehouse: in stock or spare, ready to use
    var stock = INV.filter(function (r) { var k = stKey(r.state); return (k === 'stock' || k === 'spare') && KIND_RES[r.kind]; });
    stock.forEach(function (r) { m[KIND_RES[r.kind]].stock += 1; });
    m.Storage.stock = sum(stock.filter(function (r) { return r.kind === 'Storage'; }), function (r) { return tb(r.disk_mb); });

    // average cost of one unit, from the priced requests
    var unitCost = function (r) {
      var L = DEM.filter(function (d) { return resOf(d) === r && Number(d.boq_cost) > 0 && Number(d.quantity) > 0; });
      var q = sum(L, function (d) { return d.quantity; });
      return q ? sum(L, function (d) { return d.boq_cost; }) / q : null;
    };
    FC_RES.forEach(function (r) {
      var x = m[r];
      x.total = x.dem + x.mod + x.vm + x.trend;
      x.net = Math.max(0, x.total - x.stock);
      var uc = unitCost(r);
      x.cost = uc === null ? null : x.net * uc;
    });
    return { m: m, open: open, mod: mod, fromDcim: DC.length > 0, CL: CL, years: years, series: series, stock: stock, end: end, target: target };
  }

  function renderForecast() {
    var title = '<div class="db-section-h">پیش‌بینی زیرساخت موردنیاز برای ۱۲ ماه آینده (تا ' + jdate(new Date(TODAY.getTime() + 365 * 864e5).toISOString().slice(0, 10)) + ')</div>';
    if (!ok('forecast')) { return title + need(['forecast']); }
    var M = fcModel(), m = M.m;
    var settings = FC.settings_url ? link(FC.settings_url, 'تنظیمات پیش‌بینی', 'عمر مجاز و ورود فایل‌های DCIM، VirOPS و iCare') : '';
    var q = function (v, r) { return r === 'Storage' ? decimal(v, v >= 100 ? 0 : 1) : num(Math.round(v)); };
    var dash = '<span class="db-dash">—</span>';
    var cost = sum(FC_RES, function (r) { return m[r].cost || 0; });

    var kpis = kpi('سرور موردنیاز', num(Math.round(m.Compute.net)), { plain: true, txt: 'پس از کسر ' + num(m.Compute.stock) + ' سرور انبار' }, 'gold', '#fc-sum')
      + kpi('فضای ذخیره‌سازی', decimal(m.Storage.net, 0) + ' TB', { plain: true, txt: num(Math.round(m.StorageDev.net)) + ' دستگاه برای نوسازی' }, 'blue', '#fc-sum')
      + kpi('GPU', num(Math.round(m.GPU.net)), { plain: true, txt: 'درخواست‌ها و روند' }, 'violet', '#fc-sum')
      + kpi('تجهیز شبکه', num(Math.round(m.Network.net)), { plain: true, txt: num(m.Network.mod) + ' مورد پایان عمر' }, 'green', '#fc-sum')
      + kpi('نوسازی بر اساس عمر', num(M.mod.length), { plain: true, txt: 'دستگاه به سقف عمر می‌رسد' }, 'red', '#fc-mod')
      + kpi('هزینه برآوردی', cost ? '<span class="db-kpi-sm">' + compact(cost) + '</span>' : '—', { plain: true, txt: 'بر اساس میانگین قیمت BOQ' }, 'gold', '#fc-sum');

    // breakdown: one row per resource, one column per factor
    var cell = function (v, r, anchor) { return v > 0.0001 ? link(anchor, q(v, r)) : dash; };
    var rows = FC_RES.filter(function (r) { var x = m[r]; return x.total > 0 || x.stock > 0 || x.trendProj; }).map(function (r) {
      var x = m[r];
      return '<tr><td><b>' + esc(FC_FA[r]) + '</b> <small class="db-sub-t">' + esc(FC_UNIT[r]) + '</small></td>'
        + '<td class="num">' + cell(x.dem, r, '#fc-dem') + '</td><td class="num">' + cell(x.mod, r, '#fc-mod') + '</td>'
        + '<td class="num">' + cell(x.vm, r, '#fc-vm') + (x.vmRaw > x.vm + 0.01 ? '<div class="db-sub-t">کمبود ' + q(x.vmRaw, r) + '</div>' : '') + '</td>'
        + '<td class="num">' + cell(x.trend, r, '#fc-trend') + (x.trendProj !== null ? '<div class="db-sub-t">روند: ' + q(x.trendProj, r) + '</div>' : '') + '</td>'
        + '<td class="num"><b>' + q(x.total, r) + '</b></td>'
        + '<td class="num">' + (x.stock > 0.0001 ? link('#fc-stock', '− ' + q(x.stock, r)) : dash) + '</td>'
        + '<td class="num"><b class="db-fc-net">' + q(x.net, r) + '</b></td>'
        + '<td class="num">' + (x.cost ? compact(x.cost) : dash) + '</td></tr>';
    }).join('');
    var sumTbl = '<div class="db-tbl-wrap"><table class="db-tbl db-compare" id="db-fc-sum"><thead><tr><th>منبع</th>'
      + FC_SRC.map(function (s) { return '<th class="num"><i class="db-dot" style="background:' + s[2] + '"></i>' + esc(s[1]) + '</th>'; }).join('')
      + '<th class="num">جمع نیاز</th><th class="num">موجودی انبار</th><th class="num">نیاز خالص</th><th class="num">هزینه برآوردی</th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="9" class="db-empty">نیازی پیش‌بینی نشد.</td></tr>') + '</tbody></table></div>'
      + '<div class="db-note">درخواست‌های باز: درخواست‌های ظرفیت تأییدنشده یا در جریان (به‌جز ردشده و آزادسازی). نوسازی: دستگاه‌های در حال استفاده (فهرست DCIM) که تا پایان دوره به عمر مجاز نوع خود می‌رسند (سرور '
      + fa((FC.ages || {}).Server || 0) + '، ذخیره‌سازی ' + fa((FC.ages || {}).Storage || 0) + '، شبکه ' + fa((FC.ages || {}).Network || 0) + ' سال)، مگر درخواست جایگزینی آن ثبت شده باشد. '
      + 'توسعه VM: کمبود کلاسترها (فایل VirOPS) با رشد ماهانه و سقف بهره‌برداری ' + fa(Math.round(M.target * 100)) + '٪، منهای درخواست‌های توسعه باز. '
      + 'روند iCare: آنچه روند تحویل دوره‌های ۱۲ماهه گذشته بیش از موارد شناخته‌شده پیش‌بینی می‌کند. ' + (settings ? settings : '') + '</div>';

    // share of each factor per resource (units differ, so the bars show shares; the tooltip shows the amounts)
    var shown = FC_RES.filter(function (r) { return m[r].total > 0; });
    chart('c-fc-mix', {
      textStyle: { fontFamily: FONT },
      tooltip: Object.assign({ trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: function (ps) {
        var r = shown[ps[0].dataIndex];
        return '<b>' + esc(FC_FA[r]) + '</b><br>' + ps.filter(function (p) { return p.value > 0; }).map(function (p) {
          return p.marker + esc(p.seriesName) + ': ' + q(m[r][FC_SRC[p.seriesIndex][0]], r) + ' ' + esc(FC_UNIT[r]);
        }).join('<br>');
      } }, TIP),
      legend: { bottom: 0, itemWidth: 12, itemHeight: 12, textStyle: { color: COL.ink, fontSize: 12 } },
      grid: { left: 8, right: 16, top: 8, bottom: 56, containLabel: true },
      xAxis: { type: 'value', max: 100, axisLabel: { show: false }, splitLine: { lineStyle: { color: '#eef0f3' } } },
      yAxis: { type: 'category', inverse: true, data: shown.map(function (r) { return FC_FA[r]; }), axisTick: { show: false }, axisLine: { show: false },
        axisLabel: { color: COL.ink, fontSize: 12, fontWeight: 600 } },
      series: FC_SRC.map(function (s) {
        return { name: s[1], type: 'bar', stack: 'fc', barWidth: 22, itemStyle: { color: s[2] }, label: { show: false },
          data: shown.map(function (r) { return m[r].total ? Math.round(m[r][s[0]] / m[r].total * 1000) / 10 : 0; }) };
      })
    }, function (e) { var a = { dem: '#fc-dem', mod: '#fc-mod', vm: '#fc-vm', trend: '#fc-trend' }[FC_SRC[e.seriesIndex][0]]; var t = document.querySelector(a); if (t) { t.scrollIntoView({ behavior: 'smooth' }); } });

    // iCare trend chart: deliveries per year (Jalali years), with the projection for the next 12 months
    var trendCard;
    var ic = FC.icare || {};
    if (M.years.length) {
      var labels = M.years.concat(['۱۲ ماه آینده']);
      var line = function (r, color, axis) {
        return { name: RCAT_FA[r] + (r === 'Storage' ? ' (TB)' : ''), type: 'line', yAxisIndex: axis, smooth: true, symbolSize: 7, itemStyle: { color: color }, lineStyle: { width: 2.5 },
          data: M.series[r].map(function (v) { return Math.round(v * 10) / 10; }).concat([m[r].trendProj === null ? null : Math.round(m[r].trendProj * 10) / 10]) };
      };
      chart('c-fc-trend', {
        textStyle: { fontFamily: FONT },
        tooltip: Object.assign({ trigger: 'axis' }, TIP),
        legend: { top: 0, textStyle: { color: COL.ink } },
        grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
        xAxis: { type: 'category', data: labels, axisTick: { show: false }, axisLabel: { color: COL.muted } },
        yAxis: [{ type: 'value', name: 'عدد', axisLabel: { color: COL.muted }, splitLine: { lineStyle: { color: '#eef0f3' } } },
          { type: 'value', name: 'TB', axisLabel: { color: COL.muted }, splitLine: { show: false } }],
        series: [line('Compute', RCAT_COL.Compute, 0), line('GPU', RCAT_COL.GPU, 0), line('Network', RCAT_COL.Network, 0), line('Storage', RCAT_COL.Storage, 1)]
      });
      trendCard = card(6, 'روند تحویل ۱۲ماهه (iCare) و پیش‌بینی', chartHtml('c-fc-trend', 300)
        + '<div class="db-note">' + (ic.demo ? chip('داده نمونه', 'warn') + ' ' : '') + (ic.file ? 'فایل: ' + esc(ic.file) + ' · ' : '') + 'هر نقطه جمع تحویل ۱۲ ماه منتهی به آن ماه است؛ نقطه آخر، ادامه خط روند برای ۱۲ ماه آینده است.</div>');
    } else {
      trendCard = card(6, 'روند تحویل سالانه (iCare)', '<div class="db-empty">سوابق iCare وارد نشده است. ' + (settings ? 'از ' + settings + ' فایل Excel یا CSV خروجی iCare را وارد کنید.' : 'از مدیر سیستم بخواهید خروجی iCare را وارد کند.') + '</div>');
    }

    // VirOPS clusters
    var vo = FC.virops || {};
    var pct = function (u, c) { return c ? Math.round(u / c * 100) : 0; };
    var meter = function (u, c, p) {
      var a = pct(u, c), b = pct(p, c);
      return '<div class="db-meter ' + (b >= M.target * 100 ? 'over' : '') + '"><i style="width:' + Math.min(100, a) + '%"></i></div>' + fa(a) + '٪ ← ' + fa(b) + '٪';
    };
    var vmBody = M.CL.length ? table('db-fc-vm-tbl', [
      { h: 'کلاستر', f: function (x) { return '<b>' + esc(x.c.cluster) + '</b><div class="db-sub-t">' + num(x.c.hosts) + ' هاست · ' + num(x.c.vms) + ' VM</div>'; } },
      { h: 'CPU (اکنون ← ۱۲ ماه)', f: function (x) { return meter(x.c.cpu_use_ghz, x.c.cpu_cap_ghz, x.cpuP); } },
      { h: 'RAM', f: function (x) { return meter(x.c.ram_use_gb, x.c.ram_cap_gb, x.ramP); } },
      { h: 'فضای دیتااستور', f: function (x) { return meter(x.c.sto_use_tb, x.c.sto_cap_tb, x.stoP); } },
      { h: 'رسیدن به سقف', f: function (x) { return x.full === 0 ? chip('اکنون', 'bad') : (x.full === Infinity ? dash : (x.full <= 12 ? chip(fa(Math.ceil(x.full)) + ' ماه', 'warn') : fa(Math.ceil(x.full)) + ' ماه')); } },
      { h: 'هاست موردنیاز', num: true, f: function (x) { return x.hosts ? '<b>' + num(x.hosts) + '</b>' : dash; } },
      { h: 'فضای موردنیاز', num: true, f: function (x) { return x.tb > 0.05 ? decimal(x.tb, 1) + ' TB' : dash; } }
    ], M.CL, 500) : '<div class="db-empty">' + 'فایل کلاسترهای VirOPS وارد نشده است.' + (settings ? ' ' + settings : '') + '</div>';
    var vmNote = '<div class="db-note">' + (vo.demo ? chip('داده نمونه', 'warn') + ' ' : '') + (vo.file ? 'فایل VirOPS: ' + esc(vo.file) + (vo.imported ? ' (' + esc(vo.imported) + ')' : '') + '. ' : '')
      + 'نوار: بهره‌برداری فعلی؛ عدد دوم: پیش‌بینی ۱۲ ماه بعد با رشد ماهانه فایل.</div>';

    // devices reaching their age limit
    var modTbl = M.mod.length ? table('db-fc-mod-tbl', [
      { h: 'تجهیز', f: function (x) { return link(x.r.url, '<b>' + esc(x.r.name) + '</b>', 'باز کردن در GLPI') + '<div class="db-sub-t">' + esc(x.r.model || '') + '</div>'; } },
      { h: 'نوع', f: function (x) { return esc(x.r.typeLabel || IKIND_FA[x.r.kind] || x.r.kind); } },
      { h: 'تاریخ بهره‌برداری', f: function (x) { return jdate(x.r.in_service); } },
      { h: 'عمر فعلی', num: true, f: function (x) { return decimal(x.age, 1) + ' سال'; } },
      { h: 'عمر مجاز', num: true, f: function (x) { return fa(x.limit) + ' سال'; } },
      { h: 'موعد نوسازی', f: function (x) { return x.due <= TODAY ? chip('گذشته', 'bad') + ' ' + jdate(x.due.toISOString().slice(0, 10)) : jdate(x.due.toISOString().slice(0, 10)); } },
      { h: 'محل', f: function (x) { return x.r.rack ? link(x.r.rack_url, esc(String(x.r.datacenter || '').replace(/^DEMO /, '')) + ' / رک ' + esc(x.r.rack)) : (esc(String(x.r.location || '').replace(/^DEMO /, '')) || dash); } },
      { h: 'درخواست جایگزینی', f: function (x) { return x.req ? link(rec('Ticket', x.req.ticket_id), chip('ثبت شده #' + fa(x.req.ticket_id), 'ok')) : chip('ثبت نشده', 'warn'); } }
    ], M.mod, 2000) : '<div class="db-empty">هیچ دستگاه در حال استفاده‌ای تا ۱۲ ماه آینده به عمر مجاز نمی‌رسد.</div>';

    var dc = FC.dcim || {};
    var dcimNote = '<div class="db-note">' + (M.fromDcim
      ? (dc.demo ? chip('داده نمونه', 'warn') + ' ' : '') + 'منبع: فهرست دستگاه‌های DCIM' + (dc.file ? ' (' + esc(dc.file) + (dc.imported ? '، ' + esc(dc.imported) : '') + ')' : '') + '؛ دستگاه‌هایی که در GLPI هم هستند به رکورد GLPI پیوند دارند.'
      : 'منبع: دارایی‌های GLPI (تاریخ شروع به کار در اطلاعات مالی). برای استفاده از DCIM، فایل Excel آن را در ' + (settings || 'تنظیمات پیش‌بینی') + ' وارد کنید.') + '</div>';

    // open requests
    var demTbl = M.open.length ? table('db-fc-dem-tbl', [
      { h: 'درخواست', f: function (d) { return link(rec('Ticket', d.ticket_id), '<b>' + esc(d.title) + '</b>') + '<div class="db-sub-t">#' + fa(d.ticket_id) + ' · ' + jdate(d.opened) + '</div>'; } },
      { h: 'نوع', f: function (d) { return esc(RTYPE_FA[d.request_type] || d.request_type); } },
      { h: 'منبع', f: function (d) { return esc(RCAT_FA[rcatOf(d)] || d.category); } },
      { h: 'مقدار', num: true, f: function (d) { return '<b>' + q(d.quantity, rcatOf(d) === 'Storage' && d.request_type !== 'Modernization' ? 'Storage' : '') + '</b> <small>' + esc(d.unit === 'TB' ? 'TB' : (d.unit === 'device' ? 'دستگاه' : 'عدد')) + '</small>'; } },
      { h: 'تیم', f: function (d) { return esc(d.team); } },
      { h: 'پروژه', f: function (d) { return esc(d.project); } },
      { h: 'تأیید', f: function (d) { return chip({ Approved: 'تأییدشده', Waiting: 'در انتظار', 'Not required': 'بدون نیاز به تأیید' }[d.approval] || d.approval || '—', d.approval === 'Approved' ? 'ok' : 'warn'); } },
      { h: 'موعد', f: function (d) { return d.required_by ? jdate(d.required_by) : dash; } }
    ], M.open.slice().sort(function (a, b) { return String(a.required_by || '9').localeCompare(String(b.required_by || '9')); }), 2000)
      : '<div class="db-empty">' + (ok('demands') ? 'درخواست ظرفیت بازی وجود ندارد.' : esc(stateMsg('demands'))) + '</div>';

    // warehouse stock that can be used
    var groups = {};
    M.stock.forEach(function (r) {
      var k = r.kind + '|' + (r.model || '') + '|' + stKey(r.state);
      var g = groups[k] || (groups[k] = { kind: r.kind, model: r.model || '—', state: r.state, n: 0, mb: 0 });
      g.n++; g.mb += Number(r.disk_mb || 0);
    });
    var stockTbl = Object.keys(groups).length ? table('db-fc-stock-tbl', [
      { h: 'نوع', f: function (g) { return link(query('assets', { ikind: g.kind, istate: stKey(g.state) }) + '#db-inv', esc(IKIND_FA[g.kind] || g.kind)); } },
      { h: 'مدل', f: function (g) { return esc(g.model); } },
      { h: 'وضعیت', f: function (g) { return stChip(g.state); } },
      { h: 'تعداد', num: true, f: function (g) { return '<b>' + num(g.n) + '</b>' + (g.kind === 'Storage' && g.mb ? '<div class="db-sub-t">' + size(g.mb) + '</div>' : ''); } }
    ], Object.keys(groups).map(function (k) { return groups[k]; }).sort(function (a, b) { return b.n - a.n; }), 500)
      : '<div class="db-empty">تجهیز آماده‌ای در انبار (وضعیت «در انبار» یا «یدکی») نیست.</div>';

    var sec = function (id, span, head, inner) { return '<section class="db-card s' + span + '" id="' + id + '"><div class="db-card-h">' + esc(head) + '</div><div class="db-card-b">' + inner + '</div></section>'; };
    return title + '<div class="db-kpis db-kpis-6">' + kpis + '</div><div class="db-grid">'
      + sec('fc-sum', 12, 'نیاز ۱۲ ماه آینده به تفکیک عامل', sumTbl)
      + card(6, 'سهم هر عامل در نیاز هر منبع', chartHtml('c-fc-mix', 300))
      + trendCard.replace('<section class="db-card s6"', '<section class="db-card s6" id="fc-trend"')
      + sec('fc-vm', 12, 'کلاسترهای مجازی (VirOPS): ظرفیت و رشد', vmBody + vmNote)
      + sec('fc-mod', 12, 'نوسازی: دستگاه‌هایی که تا ۱۲ ماه آینده به عمر مجاز می‌رسند', modTbl + dcimNote)
      + sec('fc-dem', 12, 'درخواست‌های ظرفیت باز (تیم‌ها، پروژه‌های جدید و جایگزینی)', demTbl)
      + sec('fc-stock', 12, 'موجودی آماده در انبار', stockTbl)
      + '</div>';
  }

  var PAGES = { overview: renderOverview, requests: renderRequests, budget: renderBudget, assets: renderAssets, forecast: renderForecast };

  function render() {
    charts.forEach(function (c) { c.dispose(); });
    charts = [];
    pendingCharts = [];
    var fn = PAGES[PAGE] || renderOverview;
    body.innerHTML = activeBar() + fn();
    pendingCharts.forEach(function (p) { draw(p[0], p[1], p[2]); });
    syncAddress();
    pendingCharts = [];

    ['db-req-tbl', 'db-inv-tbl', 'db-cab-tbl', 'db-rack-tbl', 'db-asset-tbl', 'db-fc-mod-tbl', 'db-fc-dem-tbl', 'db-fc-vm-tbl', 'db-fc-stock-tbl'].forEach(function (id) {
      var t = document.getElementById(id);
      var b = t && t.closest('.db-card-b');
      if (t) { pager(t, b ? b.querySelector('.db-search') : null, 10); }
    });
    if (window.location.hash) { var target = document.querySelector(window.location.hash); if (target) { target.scrollIntoView(); } }
  }

  // Shows a table PER rows at a time, with previous / next buttons; the search box filters before paging.
  function pager(tbl, search, PER) {
    var rows = Array.prototype.slice.call(tbl.tBodies[0].rows);
    var text = rows.map(function (tr) { return tr.textContent.toLowerCase(); });
    var page = 0;
    var box = document.createElement('div');
    box.className = 'db-pager';
    tbl.parentNode.parentNode.insertBefore(box, tbl.parentNode.nextSibling);
    var empty = document.createElement('div');
    empty.className = 'db-empty';
    empty.textContent = 'موردی با این جستجو یافت نشد.';
    tbl.parentNode.parentNode.insertBefore(empty, box);

    function show() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var match = rows.filter(function (tr, i) { return !q || text[i].indexOf(q) >= 0; });
      var pages = Math.max(1, Math.ceil(match.length / PER));
      if (page > pages - 1) { page = pages - 1; }
      rows.forEach(function (tr) { tr.style.display = 'none'; });
      match.slice(page * PER, (page + 1) * PER).forEach(function (tr) { tr.style.display = ''; });
      tbl.parentNode.style.display = match.length ? '' : 'none';
      empty.style.display = match.length ? 'none' : '';
      var from = page * PER + 1, to = Math.min((page + 1) * PER, match.length);
      box.style.display = match.length > PER ? '' : 'none';
      box.innerHTML = '<button type="button" class="db-pg-btn" data-go="-1"' + (page === 0 ? ' disabled' : '') + '>‹ ۱۰ مورد قبلی</button>'
        + '<span class="db-pg-info">' + num(from) + ' تا ' + num(to) + ' از ' + num(match.length) + '</span>'
        + '<button type="button" class="db-pg-btn" data-go="1"' + (page >= pages - 1 ? ' disabled' : '') + '>۱۰ مورد بعدی ›</button>';
    }
    box.addEventListener('click', function (e) {
      var b = e.target.closest('.db-pg-btn');
      if (!b || b.disabled) { return; }
      page += Number(b.getAttribute('data-go'));
      show();
    });
    if (search) { search.addEventListener('input', function () { page = 0; show(); }); }
    show();
  }

  // ---------- events ----------
  slicers.addEventListener('change', function (e) {
    var key = e.target.getAttribute('data-key');
    if (!key) { return; }
    F[key] = e.target.value;
    render();
  });
  slicers.addEventListener('click', function (e) {
    if (!e.target.classList.contains('db-reset')) { return; }
    Object.keys(F).forEach(function (k) { F[k] = k === 'time' ? 'all' : ''; });
    Object.keys(X).forEach(function (k) { X[k] = ''; });
    renderSlicers();
    render();
  });

  var asof = document.getElementById('db-asof');
  if (asof) { asof.textContent = 'آخرین به‌روزرسانی: ' + jdate(D.today); }
  renderSlicers();
  render();
})();
