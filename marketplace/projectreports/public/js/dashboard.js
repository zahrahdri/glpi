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

  var GROUP = { New: 'Waiting', Assigned: 'In progress', Planned: 'In progress', Pending: 'In progress', Solved: 'Completed', Closed: 'Completed' };
  var GROUP_LABEL = { Waiting: 'در انتظار اقدام', 'In progress': 'در حال پیگیری', Completed: 'تکمیل‌شده' };
  var GROUP_CLS = { Waiting: 'warn', 'In progress': 'info', Completed: 'ok' };
  var STATUS_FA = { New: 'جدید', Assigned: 'واگذار شده', Planned: 'برنامه‌ریزی', Pending: 'در انتظار', Solved: 'حل‌شده', Closed: 'بسته' };
  var STATUSES = ['New', 'Assigned', 'Planned', 'Pending', 'Solved', 'Closed'];
  var STAGE_FA = { committed: 'تأییدشده', estimate: 'برآورد', 'no BOQ': 'بدون BOQ', canceled: 'لغو شده' };
  var ASSET_FA = { Server: 'سرور', 'Network device': 'تجهیز شبکه', 'SAN storage': 'ذخیره‌ساز SAN', 'Patch panel': 'پچ‌پنل', 'Core switch': 'سوئیچ هسته', Switch: 'سوئیچ' };
  var AGE_FA = ['۰ تا ۷ روز', '۸ تا ۳۰ روز', '۳۱ تا ۹۰ روز', 'بیش از ۹۰ روز'];
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
      && (!F.category || r.category === F.category);
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
  function asts() { return AST.filter(function (a) { return !F.project || a.project === F.project; }); }

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
  function chart(id, opt) { pendingCharts.push([id, opt]); }
  function draw(id, opt) {
    var el = document.getElementById(id);
    if (!el || !window.echarts) { return; }
    var c = echarts.init(el);
    c.setOption(opt);
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
  function kpi(label, value, delta, tone) {
    var icon = ICONS[TONE_ICON[tone] || 'chart'];
    return '<div class="db-kpi tone-' + tone + '"><div class="db-kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + icon + '</svg></div>'
      + '<div class="db-kpi-v">' + value + '</div><div class="db-kpi-l">' + esc(label) + '</div>'
      + (delta ? '<div class="db-delta ' + (delta.up ? 'up' : 'down') + '">' + (delta.up ? '▲ ' : '▼ ') + esc(delta.txt) + '</div>' : '')
      + '</div>';
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
    chart('c-gauge', gaugeOpt(pct));
    return card(3, 'پیشرفت کلی',
      '<div class="db-gauge">' + chartHtml('c-gauge', 210)
      + '<div class="db-gauge-txt"><div class="db-gauge-v">' + fa(pct) + '٪</div><span>' + num(done) + ' از ' + num(total) + ' درخواست تکمیل شده</span></div></div>');
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

    var kpis = kpi('کل درخواست‌ها', num(n), growth(R), 'gold')
      + kpi('در حال پیگیری / اجرا', num(prog), growth(R.filter(function (r) { return GROUP[r.status] === 'In progress'; })), 'blue')
      + kpi('تکمیل‌شده', num(done), growth(R.filter(function (r) { return GROUP[r.status] === 'Completed'; })), 'green')
      + kpi('در انتظار اقدام', num(wait), growth(R.filter(function (r) { return GROUP[r.status] === 'Waiting'; })), 'red')
      + kpi('جمع هزینه خرید (BOQ)', demOk ? compact(cost) : '—', demOk ? growth(Dm) : null, 'green');

    // status donut
    var order = ['Completed', 'In progress', 'Waiting'];
    var colors = { Completed: COL.green, 'In progress': COL.blue, Waiting: COL.gold };
    var statusItems = order.filter(function (g) { return grp[g]; }).map(function (g) {
      return { name: GROUP_LABEL[g], value: grp[g], itemStyle: { color: colors[g] } };
    });
    chart('c-status', donutOpt(statusItems, 'درخواست'));

    // requests by team
    var teamC = countBy(R, function (r) { return r.requesting_team; });
    var teams = Object.keys(teamC).sort(function (a, b) { return teamC[b] - teamC[a]; }).slice(0, 8);
    chart('c-team', vbarOpt(teams, [{ name: 'درخواست', data: teams.map(function (t) { return teamC[t]; }), color: COL.gold }]));

    // requests by status
    var sc = countBy(R, function (r) { return r.status; });
    var stl = STATUSES.filter(function (s) { return sc[s]; });
    chart('c-hstatus', hbarOpt(stl.map(function (s) { return STATUS_FA[s]; }), stl.map(function (s) { return sc[s]; }), COL.gold));

    var assetsCard = moneyOk
      ? (function () {
          var ac = countBy(A, function (a) { return a.asset_type; });
          var items = Object.keys(ac).map(function (k) { return { name: ASSET_FA[k] || k, value: ac[k] }; });
          chart('c-assets', donutOpt(items, 'دارایی'));
          return card(3, 'دارایی‌ها بر اساس نوع', chartHtml('c-assets', 260));
        })()
      : noticeCard(3, 'دارایی‌ها بر اساس نوع', 'money');

    var costCard = moneyOk
      ? (function () {
          var P = Fn.slice().sort(function (a, b) { return b.allocated - a.allocated; }).slice(0, 8);
          chart('c-cost', vbarOpt(P.map(function (p) { return p.project; }), [
            { name: 'تخصیص‌یافته', data: P.map(function (p) { return Number(p.allocated); }), color: COL.blue, stack: 'cost' },
            { name: 'پیش‌نویس‌ها', data: P.map(function (p) { return Number(p.pipeline); }), color: COL.gold, stack: 'cost' }
          ]));
          return card(6, 'هزینه بر اساس پروژه (بدون مالیات)', chartHtml('c-cost', 260));
        })()
      : noticeCard(6, 'هزینه بر اساس پروژه (بدون مالیات)', 'money');

    // latest requests with their status (and BOQ cost when the request has a BOQ)
    var boqBy = {};
    Dm.forEach(function (d) { boqBy[d.ticket_id] = d; });
    var latest = R.slice().sort(byDateDesc);
    var latestCols = [
      { h: 'درخواست', f: function (r) { return '<span class="db-id">REQ-' + esc(fa(r.ticket_id)) + '</span>'; } },
      { h: 'عنوان', f: function (r) { return esc(r.title); } },
      { h: 'پروژه', f: function (r) { return esc(r.project); } },
      { h: 'تیم', f: function (r) { return esc(r.requesting_team); } },
      { h: 'وضعیت', f: function (r) { return chip(STATUS_FA[r.status] || r.status, GROUP_CLS[GROUP[r.status]]); } }
    ];
    // BOQ cost only when some of the listed requests have a BOQ
    if (latest.slice(0, 10).some(function (r) { return boqBy[r.ticket_id] && boqBy[r.ticket_id].boq_cost; })) {
      latestCols.push({ h: 'هزینه BOQ', num: true, f: function (r) { var d = boqBy[r.ticket_id]; return d && d.boq_cost ? num(d.boq_cost) : '—'; } });
    }
    latestCols.push({ h: 'تاریخ ثبت', f: function (r) { return jdate(r.opened); } });
    var latestCard = card(12, 'آخرین وضعیت درخواست‌ها', table('db-latest', latestCols, latest, 10));

    return '<div class="db-kpis">' + kpis + '</div>'
      + '<div class="db-grid">'
      + card(4, 'وضعیت کلی درخواست‌ها', chartHtml('c-status', 260))
      + card(5, 'درخواست‌ها بر اساس تیم', chartHtml('c-team', 260))
      + card(3, 'درخواست‌ها بر اساس مرحله', chartHtml('c-hstatus', 260))
      + gaugeCard(pct, done, n)
      + assetsCard
      + costCard
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

    var kpis = kpi('درخواست‌ها', num(R.length), growth(R), 'gold')
      + kpi('باز', num(open.length), growth(open), 'blue')
      + kpi('دیرکرد', num(over.length), null, 'red')
      + kpi('میانگین روز تا حل', decimal(avg, 1), null, 'green');

    var keys = ['1', '2', '3', '4'];
    var ac = countBy(open.filter(function (r) { return r.open_age; }), function (r) { return String(r.open_age).charAt(0); });
    chart('c-age', vbarOpt(AGE_FA, [
      { name: 'باز', data: keys.map(function (k) { return ac[k] || 0; }), color: COL.blue }
    ]));

    var cc = countBy(R, function (r) { return r.category; });
    var cats = Object.keys(cc).sort(function (a, b) { return cc[b] - cc[a]; }).slice(0, 10);
    chart('c-cat', hbarOpt(cats, cats.map(function (c) { return cc[c]; }), COL.gold));

    var tbl = table('db-req-tbl', [
      { h: 'درخواست', f: function (r) { return 'REQ-' + esc(fa(r.ticket_id)); } },
      { h: 'عنوان', f: function (r) { return esc(r.title); } },
      { h: 'پروژه', f: function (r) { return esc(r.project); } },
      { h: 'تیم', f: function (r) { return esc(r.requesting_team); } },
      { h: 'دسته‌بندی', f: function (r) { return esc(r.category); } },
      { h: 'وضعیت', f: function (r) { return chip(STATUS_FA[r.status] || r.status, GROUP_CLS[GROUP[r.status]]); } },
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
    ]));

    var RUNS = { 'lasts to end': 'تا پایان دوره کافی است', 'no BOQ spend yet': 'هنوز هزینه‌ای ثبت نشده', 'already exceeded': 'از بودجه گذشته' };
    var tbl = table('db-bud-tbl', [
      { h: 'پروژه', f: function (f) { return esc(f.project); } },
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
    ]));

    var tbl = table('db-asset-tbl', [
      { h: 'نوع', f: function (a) { return esc(ASSET_FA[a.asset_type] || a.asset_type); } },
      { h: 'شماره دارایی', f: function (a) { return esc(fa(a.asset_id)); } },
      { h: 'پروژه', f: function (a) { return esc(a.project); } },
      { h: 'تأمین‌کننده', f: function (a) { return esc(a.supplier); } },
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
    body.innerHTML = fn();
    pendingCharts.forEach(function (p) { draw(p[0], p[1]); });
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
    renderSlicers();
    render();
  });

  var asof = document.getElementById('db-asof');
  if (asof) { asof.textContent = 'آخرین به‌روزرسانی: ' + jdate(D.today); }
  renderSlicers();
  render();
})();
