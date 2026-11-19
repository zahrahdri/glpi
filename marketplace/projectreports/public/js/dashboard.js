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
  var X = { group: '', age: '', sla: '', open: '', atype: '', rtype: '', rcat: '' };
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
  var PAGE_FA = { overview: 'نمای کلی', requests: 'درخواست‌ها', budget: 'بودجه و هزینه', assets: 'دارایی‌ها و تمدید' };
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
      if (k) { a.setAttribute('href', query(k, { group: '', age: '', sla: '', open: '', atype: '', rtype: '', rcat: '' })); }
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
      rcat: function (v) { return 'منبع: ' + (RCAT_FA[v] || v); }
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
      + (delta ? '<div class="db-delta ' + (delta.up ? 'up' : 'down') + '">' + (delta.up ? '▲ ' : '▼ ') + esc(delta.txt) + '</div>' : '')
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
    var miss = need(['money']);
    if (miss) { return miss; }
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

  var PAGES = { overview: renderOverview, requests: renderRequests, budget: renderBudget, assets: renderAssets };

  function render() {
    charts.forEach(function (c) { c.dispose(); });
    charts = [];
    pendingCharts = [];
    var fn = PAGES[PAGE] || renderOverview;
    body.innerHTML = activeBar() + fn();
    pendingCharts.forEach(function (p) { draw(p[0], p[1], p[2]); });
    syncAddress();
    pendingCharts = [];

    var reqTbl = document.getElementById('db-req-tbl');
    if (reqTbl) { pager(reqTbl, document.querySelector('.db-search'), 10); }
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
