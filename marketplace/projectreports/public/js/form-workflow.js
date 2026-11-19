/**
 * Infrastructure Demand-to-Delivery (D2D) workflow for the capacity request forms (New project, Expansion,
 * Modernization): what happens to a request after it is submitted, who does each step, the decision gates,
 * what each step produces and what the requester sees on the ticket.
 * (ITIL 4: service request management with capacity and supplier management; IT4IT: Request to Fulfill.)
 *
 *  - On a form page (/Form/Render/<id>) a "Workflow" button is added next to the form title; it opens the
 *    workflow of that form's request type in a dialog.
 *  - front/workflow.php shows the same workflows as a full page (to share or print).
 * The steps are plain data (ROLES, GATES, WORKFLOWS below): edit the texts there.
 */
(function () {
  'use strict';

  var NAME = 'Infrastructure Demand-to-Delivery (D2D)';

  // one swimlane per role, top to bottom
  var ROLES = {
    requester: { label: 'You (requester)', short: 'Requester', color: '#2f6fde' },
    manager: { label: 'Your approving manager', short: 'Approving manager', color: '#7a4fd6' },
    pm: { label: 'Project manager', short: 'Project manager', color: '#b97f00' },
    planning: { label: 'Infrastructure planning', short: 'Infra planning', color: '#0d9488' },
    procurement: { label: 'Procurement', short: 'Procurement', color: '#d9622b' },
    warehouse: { label: 'Warehouse', short: 'Warehouse', color: '#8a6a3b' },
    delivery: { label: 'Infrastructure delivery', short: 'Infra delivery', color: '#2e9e4f' }
  };

  // decision gates: points where a request can be stopped or sent back
  var GATES = {
    G1: 'Manager approval',
    G2: 'Technical check',
    G3: 'Purchase approval',
    G4: 'Delivery'
  };

  // what the requester sees on the ticket at each step
  var STATUS = {
    created: 'New',
    approval: 'Waiting for approval',
    processing: 'Processing (assigned)',
    supply: 'Pending (waiting for equipment)',
    solved: 'Solved → Closed'
  };

  var submit = {
    id: 'submit', role: 'requester', title: 'Submit the request', box: 'Submit request',
    does: ['Fill in this form: one request per resource type (compute, storage or GPU).',
      'Attach the sizing evidence and, for a new platform, the technical design.'],
    out: 'A ticket with all the details',
    status: 'created', you: 'You get a ticket number. Every next step is visible in that ticket.'
  };
  var approval = {
    id: 'approval', role: 'manager', title: 'Manager approval', box: 'Manager approval', gate: 'G1',
    does: ['The manager you chose in the form receives an approval request in GLPI.',
      'They approve or refuse it, with a comment.'],
    out: 'Request approved by your manager',
    status: 'approval', you: 'If it is refused, the request is closed and the reason is shown in the ticket.'
  };
  var NEED = 'Only when the hardware is not available';
  var order = {
    id: 'order', role: 'procurement', title: 'Order the resources', box: 'Order', optional: NEED,
    does: ['The missing hardware is ordered from the BOQ prepared in the technical check.',
      'The order is registered in GLPI and linked to your request.'],
    out: 'Order registered with quantities and models',
    status: 'supply'
  };
  var purchase = {
    id: 'purchase', role: 'procurement', title: 'Purchase', box: 'Purchase', gate: 'G3', optional: NEED,
    does: ['The purchase is approved and the purchase order (PO) is sent to the vendor.',
      'The vendor confirms the delivery date; it is noted in your ticket.'],
    out: 'Purchase order sent; delivery date confirmed',
    status: 'supply', you: 'Usually the longest part: it depends on the vendor\'s delivery time.'
  };
  var inbound = {
    id: 'inbound', role: 'warehouse', title: 'Warehouse inbound', box: 'Warehouse inbound', optional: NEED,
    does: ['The equipment is received in the warehouse and checked against the order (models, quantities, serial numbers).',
      'It is registered in GLPI as stock and reserved for your request until installation.'],
    out: 'Equipment in stock, registered in GLPI and reserved for you',
    status: 'supply'
  };
  // what the requester sees: one step. The detailed version (order, purchase, inbound) is for administrators only
  // (Setup > Request workflow).
  var procurementProcess = {
    id: 'procurement', role: 'procurement', title: 'Procurement process', box: 'Procurement process', gate: 'G3', optional: NEED,
    does: ['The missing hardware is ordered and purchased from the vendor.',
      'It is received in the warehouse, checked and reserved for your request until installation.'],
    out: 'Equipment received in the warehouse and reserved for you',
    status: 'supply', you: 'Usually the longest part: it depends on the vendor\'s delivery time. The expected date is noted in your ticket.',
    detail: [order, purchase, inbound]
  };
  var delivery = {
    id: 'delivery', role: 'requester', title: 'Delivery', box: 'Delivery', gate: 'G4',
    does: ['You receive the delivery details: names, addresses, access and the capacity delivered.',
      'You check them and confirm the delivery, or reopen the ticket if something is not right.'],
    out: 'Delivery confirmed; request closed',
    status: 'solved', you: 'After your confirmation the ticket is closed and you may get a short satisfaction survey.'
  };
  // the Delivery step with the iCare PLO the requester raises for it
  function deliveryWith(plo) {
    var d = {};
    Object.keys(delivery).forEach(function (k) { d[k] = delivery[k]; });
    d.does = delivery.does.concat(['You can raise the "' + plo + '" PLO in iCare for the delivery.']);
    return d;
  }
  var decision = {
    decision: true, id: 'decision', role: 'planning', question: 'Resource available?',
    hint: 'Can the request be delivered from what we already have: warehouse stock and free capacity on the platforms?',
    yes: 'The resources are reserved for you and installation starts right away.',
    no: 'The missing hardware goes through the procurement process first, then it is installed.'
  };
  // HW installation in detail (administrators only, Setup > Request workflow)
  var INSTALL_DETAIL = [
    { id: 'material', role: 'delivery', title: 'Material request', box: 'Material request',
      does: ['The installation team requests the equipment and accessories (rails, cables, transceivers, PDUs) from the warehouse for this request.'],
      out: 'Material issued from the warehouse', status: 'processing' },
    { id: 'physical', role: 'delivery', title: 'Physical installation', box: 'Physical installation',
      does: ['Mounts the hardware in the planned rack and position (DCIM).', 'Work on production racks is done in the approved Change window.'],
      out: 'Hardware mounted in the rack', status: 'processing' },
    { id: 'cabling', role: 'delivery', title: 'Cabling', box: 'Cabling',
      does: ['Connects power, network and storage cables according to the cabling plan.'],
      out: 'Cabling done as planned', status: 'processing' },
    { id: 'labeling', role: 'delivery', title: 'Labeling', box: 'Labeling',
      does: ['Labels the device and both ends of every cable; rack position and connections are recorded in GLPI / DCIM.'],
      out: 'Device and cables labeled and recorded', status: 'processing' },
    { id: 'network', role: 'delivery', title: 'Network configuration', box: 'Network configuration',
      does: ['Prepares the network side: switch ports, VLANs, IP addresses and management access, and storage zoning when needed.'],
      out: 'Network ready for the device', status: 'processing' },
    { id: 'qc', role: 'delivery', title: 'QC process', box: 'QC process',
      does: ['Quality check against the checklist before power is applied: mounting, cabling, labels, network configuration and the GLPI record.',
        'Findings are fixed before the device is powered on.'],
      out: 'QC checklist passed; ready to power on', status: 'processing' },
    { id: 'poweron', role: 'delivery', title: 'Power on', box: 'Power on',
      does: ['Powers the device on and checks hardware health, firmware level and both power feeds.',
        'Confirms the device is reachable on its networks and added to monitoring; it is then ready for delivery.'],
      out: 'Device running and reachable; ready for delivery', status: 'processing' }
  ];
  var MIGRATION = { id: 'migration', role: 'delivery', title: 'Migration', box: 'Migration',
    does: ['Migrates the services to the new device in the agreed window and checks them; the old device stays ready for a rollback.'],
    out: 'Services running on the new device', status: 'processing' };

  var change = 'Work on production systems is done through a GLPI Change: planned, approved and scheduled, so you know when it happens.';

  var WORKFLOWS = {
    'new': {
      label: 'New project',
      intro: 'Infrastructure for a new business project, from your request to the delivery of the resources.',
      steps: [submit, approval, {
        id: 'review', role: 'pm', title: 'Project review', box: 'Project review',
        does: ['Checks that the request belongs to an approved business project.',
          'Creates the project in GLPI and links your request to it.',
          'Confirms priority and go-live date, and asks you if anything is missing.'],
        out: 'Request linked to the project; priority and date confirmed',
        status: 'processing', you: 'If information is missing the ticket goes to Pending until you answer the follow-up.'
      }, {
        id: 'technical', role: 'planning', title: 'Technical check', box: 'Technical check', gate: 'G2',
        does: ['Reviews the sizing and the technical design.',
          'Checks available capacity: virtual clusters, warehouse stock, rack space and power.',
          'Prepares the bill of quantities (BOQ) and the cost estimate when new equipment is needed.'],
        out: 'Technical design confirmed; BOQ with cost if equipment is needed',
        status: 'processing'
      }, decision, procurementProcess, {
        id: 'install', role: 'delivery', title: 'HW installation', box: 'HW installation',
        does: ['Installs the hardware in the data center: racking, cabling, power and configuration.',
          'Or provisions the resources on an existing platform (virtual machines, storage).',
          'The installed equipment is recorded in GLPI and linked to your request.', change],
        out: 'Hardware installed, configured and recorded in GLPI',
        status: 'processing', detail: INSTALL_DETAIL
      }, deliveryWith('New Infrastructure')]
    },
    expansion: {
      label: 'Expansion',
      intro: 'More capacity for an existing project or platform, from your request to the delivery of the added capacity.',
      steps: [submit, approval, {
        id: 'review', role: 'pm', title: 'Project review', box: 'Project review',
        does: ['Confirms the existing project and the current use of its resources.',
          'Checks the request against the project plan and priorities.',
          'Asks you if anything is missing.'],
        out: 'Request confirmed against the project plan',
        status: 'processing', you: 'If information is missing the ticket goes to Pending until you answer the follow-up.'
      }, {
        id: 'technical', role: 'planning', title: 'Technical check', box: 'Technical check', gate: 'G2',
        does: ['Checks the current use and growth of the platform (virtual clusters, storage).',
          'Decides how to expand: free capacity, extra hosts or disks, or new equipment.',
          'Prepares the BOQ and the cost estimate when new equipment is needed.'],
        out: 'Expansion plan confirmed; BOQ with cost if equipment is needed',
        status: 'processing'
      }, decision, procurementProcess, {
        id: 'install', role: 'delivery', title: 'HW installation', box: 'HW installation',
        does: ['Installs the extra hardware (hosts, disks, storage) or adds capacity on the existing platform.',
          'The installed equipment is recorded in GLPI and linked to your request.', change],
        out: 'Capacity installed and recorded in GLPI',
        status: 'processing', detail: INSTALL_DETAIL
      }, deliveryWith('Hardware Expansion')]
    },
    modernization: {
      label: 'Modernization & swap',
      intro: 'Replacement of an old or unsupported device, from your request to the delivery of the new device and the dismantling of the old one.',
      steps: [submit, approval, {
        id: 'review', role: 'pm', title: 'Project review', box: 'Project review',
        does: ['Confirms the device to replace (hostname) and the services running on it.',
          'Agrees the business impact and a migration window with you.'],
        out: 'Device, impact and migration window agreed',
        status: 'processing', you: 'If information is missing the ticket goes to Pending until you answer the follow-up.'
      }, {
        id: 'technical', role: 'planning', title: 'Technical check', box: 'Technical check', gate: 'G2',
        does: ['Checks the device age and support status (DCIM).',
          'Chooses the replacement model and plans the migration (swap).',
          'Prepares the BOQ and the cost estimate.'],
        out: 'Replacement model and migration plan confirmed; BOQ with cost',
        status: 'processing'
      }, decision, procurementProcess, {
        id: 'install', role: 'delivery', title: 'HW installation', box: 'HW installation',
        does: ['Installs the new hardware and migrates the services in the agreed window.',
          'Checks that everything runs on the new device; the old one stays ready for a rollback.', change],
        out: 'New hardware installed; services running on it',
        status: 'processing', detail: INSTALL_DETAIL.concat([MIGRATION])
      }, deliveryWith('New Infrastructure'), {
        id: 'dismantle', role: 'delivery', title: 'Dismantle the old device', box: 'Dismantle old one',
        does: ['After your confirmation of the delivery, the old device is switched off and dismantled from the rack.',
          'Its data is wiped and its status is updated in GLPI (warehouse, spare or disposal).'],
        out: 'Old device dismantled; asset record updated',
        status: 'solved'
      }]
    }
  };

  // ---------------------------------------------------------------- helpers
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function anchor(type, id) { return 'prwf-' + type + '-' + id; }

  // the steps shown: the procurement process in one box, or in detail (administrators)
  function stepsOf(type, detailed) {
    var out = [];
    WORKFLOWS[type].steps.forEach(function (s) {
      if (detailed && Array.isArray(s.detail)) { out.push.apply(out, s.detail); } else { out.push(s); }
    });
    return out;
  }

  // ---------------------------------------------------------------- swimlane diagram (SVG)
  // box label, on two lines when it is long
  function boxText(label, x, y) {
    var words = String(label).split(' ');
    if (label.length <= 13 || words.length < 2) {
      return '<text class="prwf-box-t" x="' + x + '" y="' + (y + 4) + '">' + esc(label) + '</text>';
    }
    var best = 1, diff = 1e9;
    for (var i = 1; i < words.length; i++) {
      var d = Math.abs(words.slice(0, i).join(' ').length - words.slice(i).join(' ').length);
      if (d < diff) { diff = d; best = i; }
    }
    return '<text class="prwf-box-t" x="' + x + '" y="' + (y - 2) + '"><tspan x="' + x + '">' + esc(words.slice(0, best).join(' ')) + '</tspan>'
      + '<tspan x="' + x + '" dy="13">' + esc(words.slice(best).join(' ')) + '</tspan></text>';
  }
  function swimlane(type, detailed) {
    var wf = WORKFLOWS[type];
    var items = stepsOf(type, detailed);
    var used = {};
    items.forEach(function (s) { used[s.role] = true; });
    var lanes = Object.keys(ROLES).filter(function (k) { return used[k]; });
    var LABEL = 132, COL = 118, LANE = 56, TOP = 30, BW = 104, BH = 40;
    var W = LABEL + items.length * COL + 12, H = TOP + lanes.length * LANE + 8;
    var laneY = function (role) { return TOP + lanes.indexOf(role) * LANE + LANE / 2; };
    var colX = function (i) { return LABEL + i * COL + COL / 2; };
    var pos = items.map(function (s, i) { return { x: colX(i), y: laneY(s.role), s: s }; });
    var idx = function (id) { for (var i = 0; i < items.length; i++) { if (items[i].id === id) { return i; } } return -1; };

    var out = [];
    out.push('<svg class="prwf-lanes" viewBox="0 0 ' + W + ' ' + H + '" style="min-width:' + Math.round(Math.max(760, W * 0.78)) + 'px" role="img" aria-label="' + esc(NAME + ': ' + wf.label) + ' swimlane diagram">');
    var arrowId = 'prwf-arr-' + type + (detailed ? '-d' : '');
    out.push('<defs><marker id="' + arrowId + '" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">'
      + '<path d="M0,0 L10,5 L0,10 z" class="prwf-arrhead"/></marker></defs>');
    // lanes
    lanes.forEach(function (r, i) {
      var y = TOP + i * LANE;
      out.push('<rect class="prwf-lane' + (i % 2 ? ' odd' : '') + '" x="0" y="' + y + '" width="' + W + '" height="' + LANE + '"/>');
      out.push('<rect x="0" y="' + y + '" width="4" height="' + LANE + '" fill="' + ROLES[r].color + '"/>');
      out.push('<text class="prwf-lane-t" x="12" y="' + (y + LANE / 2 + 4) + '">' + esc(ROLES[r].short) + '</text>');
    });
    out.push('<line class="prwf-lane-sep" x1="' + LABEL + '" y1="' + TOP + '" x2="' + LABEL + '" y2="' + (H - 8) + '"/>');
    // gates along the top
    pos.forEach(function (p) {
      if (p.s.gate) {
        out.push('<g class="prwf-gate-top"><rect x="' + (p.x - 15) + '" y="6" width="30" height="17" rx="8"/><text x="' + p.x + '" y="18.5">' + p.s.gate + '</text></g>');
        out.push('<line class="prwf-gate-line" x1="' + p.x + '" y1="23" x2="' + p.x + '" y2="' + (p.y - BH / 2) + '"/>');
      }
    });
    // arrows
    var mk = 'marker-end="url(#' + arrowId + ')"';
    var DW = 50, DH = 23;   // half width and height of the decision diamond
    var edge = function (a, b, cls, label, lx, ly) {
      var A = pos[a], B = pos[b];
      var x1 = A.x + (A.s.decision ? DW : BW / 2), x2 = B.x - (B.s.decision ? DW : BW / 2) - 2;
      var d;
      if (A.y === B.y) { d = 'M' + x1 + ',' + A.y + ' H' + x2; } else {
        var mx = (x1 + x2) / 2;
        d = 'M' + x1 + ',' + A.y + ' H' + mx + ' V' + B.y + ' H' + x2;
      }
      out.push('<path class="prwf-edge ' + (cls || '') + '" d="' + d + '" ' + mk + '/>');
      if (label) { out.push('<text class="prwf-edge-t ' + cls + '" x="' + (lx || (x1 + 6)) + '" y="' + (ly || (A.y - 6)) + '">' + label + '</text>'); }
    };
    var iDec = idx('decision'), iProc = idx(detailed ? 'order' : 'procurement'), iDel = idx(detailed ? 'material' : 'install');
    for (var i = 0; i < items.length - 1; i++) {
      if (i === iDec) { continue; }
      edge(i, i + 1);
    }
    if (iDec >= 0) {
      // No -> procurement
      edge(iDec, iProc, 'no', 'No', pos[iDec].x + DW + 4, pos[iDec].y + 14);
      // Yes -> delivery, along the planning lane above procurement, then down
      var D = pos[iDec], E = pos[iDel];
      var yy = D.y - DH - 4;
      out.push('<path class="prwf-edge yes" d="M' + D.x + ',' + (D.y - DH) + ' V' + yy + ' H' + E.x + ' V' + (E.y - BH / 2 - 2) + '" ' + mk + '/>');
      out.push('<text class="prwf-edge-t yes" x="' + (D.x + 8) + '" y="' + (yy - 5) + '">Yes</text>');
    }
    // boxes
    pos.forEach(function (p) {
      var s = p.s, c = ROLES[s.role].color;
      if (s.decision) {
        out.push('<a href="#' + anchor(type, 'decision') + '" class="prwf-node"><polygon class="prwf-dia" points="' + p.x + ',' + (p.y - DH) + ' ' + (p.x + DW) + ',' + p.y + ' '
          + p.x + ',' + (p.y + DH) + ' ' + (p.x - DW) + ',' + p.y + '" style="stroke:' + c + '"/>'
          + '<text class="prwf-dia-t" x="' + p.x + '" y="' + (p.y - 2) + '"><tspan x="' + p.x + '">Resource</tspan><tspan x="' + p.x + '" dy="11">available?</tspan></text>'
          + '<title>' + esc(s.hint) + '</title></a>');
        return;
      }
      out.push('<a href="#' + anchor(type, s.id) + '" class="prwf-node"><rect class="prwf-box' + (s.optional ? ' opt' : '') + '" x="' + (p.x - BW / 2) + '" y="' + (p.y - BH / 2)
        + '" width="' + BW + '" height="' + BH + '" rx="8" style="stroke:' + c + ';fill:color-mix(in srgb,' + c + ' 12%, var(--prwf-surface))"/>'
        + boxText(s.box, p.x, p.y) + '<title>' + esc(s.title) + '</title></a>');
    });
    out.push('</svg>');
    return '<div class="prwf-lanes-wrap" tabindex="0" aria-label="Diagram (scroll sideways on small screens)">' + out.join('') + '</div>';
  }

  // ---------------------------------------------------------------- step cards
  function render(type, detailed) {
    var wf = WORKFLOWS[type];
    if (!wf) { return ''; }
    var n = 0;
    var body = stepsOf(type, detailed).map(function (s) {
      if (s.decision) {
        return '<li class="prwf-decision" id="' + anchor(type, 'decision') + '"><div class="prwf-diamond" aria-hidden="true">?</div><div class="prwf-dec-b">'
          + '<div class="prwf-dec-q">' + esc(s.question) + ' <span>' + esc(s.hint) + '</span></div>'
          + '<div class="prwf-dec-paths"><div class="prwf-yes"><b>Yes</b> ' + esc(s.yes) + '</div>'
          + '<div class="prwf-no"><b>No</b> ' + esc(s.no) + '</div></div></div></li>';
      }
      n++;
      var role = ROLES[s.role];
      return '<li class="prwf-step' + (s.optional ? ' is-optional' : '') + '" id="' + anchor(type, s.id) + '" style="--c:' + role.color + '">'
        + '<div class="prwf-num" aria-hidden="true">' + n + '</div>'
        + '<div class="prwf-card"><div class="prwf-card-h"><h3>' + esc(s.title)
        + (s.gate ? ' <span class="prwf-gate" title="Decision gate: the request can be stopped or sent back here">' + s.gate + (GATES[s.gate].toLowerCase() === s.title.toLowerCase() ? '' : ' · ' + esc(GATES[s.gate])) + '</span>' : '') + '</h3>'
        + '<span class="prwf-role"><i></i>' + esc(role.label) + '</span></div>'
        + (s.optional ? '<div class="prwf-opt">' + esc(s.optional) + '</div>' : '')
        + '<ul class="prwf-does">' + s.does.map(function (d) { return '<li' + (d === change ? ' class="prwf-change"' : '') + '>' + esc(d) + '</li>'; }).join('') + '</ul>'
        + '<div class="prwf-foot"><span class="prwf-out"><em>Output</em>' + esc(s.out) + '</span>'
        + '<span class="prwf-status" title="What you see on the ticket"><em>Ticket</em>' + esc(STATUS[s.status]) + '</span>'
        + (s.you ? '<span class="prwf-you">' + esc(s.you) + '</span>' : '') + '</div></div></li>';
    }).join('');

    var gates = '<div class="prwf-gates">' + Object.keys(GATES).map(function (g) {
      return '<span><b>' + g + '</b>' + esc(GATES[g]) + '</span>';
    }).join('') + '<small>Decision gates: a request can be stopped or sent back at these points.</small></div>';

    return '<div class="prwf">'
      + '<p class="prwf-intro"><span class="prwf-kicker">' + esc(NAME) + '</span>' + esc(wf.intro) + '</p>'
      + (detailed ? '<div class="prwf-admin-note"><i class="ti ti-lock"></i> Administrator view: the procurement process and the HW installation are shown in detail. Requesters see each of them as one step.</div>' : '')
      + swimlane(type, detailed) + gates
      + '<ol class="prwf-steps">' + body + '</ol>'
      + '<div class="prwf-track"><b>Follow your request:</b> Assistance › Tickets (or "My tickets"). The ticket shows its status, '
      + 'the approvals and every follow-up; answer follow-ups there so the request does not stay pending.</div>'
      + '</div>';
  }

  function bindJumps(root) {
    root.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a.prwf-node');
      if (!a) { return; }
      var id = (a.getAttribute('href') || '').slice(1);
      var t = id && document.getElementById(id);
      if (t) {
        e.preventDefault();
        t.scrollIntoView({ behavior: 'smooth', block: 'center' });
        t.classList.add('prwf-flash');
        setTimeout(function () { t.classList.remove('prwf-flash'); }, 1400);
      }
    });
  }

  function typeOfTitle(text) {
    var t = String(text || '').toLowerCase();
    if (t.indexOf('capacity request') < 0) { return ''; }
    if (t.indexOf('new project') >= 0) { return 'new'; }
    if (t.indexOf('expansion') >= 0) { return 'expansion'; }
    if (t.indexOf('modernization') >= 0 || t.indexOf('modernisation') >= 0) { return 'modernization'; }
    return '';
  }

  var SRC = (document.currentScript && document.currentScript.src) || '';
  var BASE = SRC.replace(/\/js\/form-workflow\.js.*$/, '');
  var VERSION = (SRC.match(/[?&]m=(\d+)/) || [])[1] || '';

  function addCss() {
    if (document.getElementById('prwf-css') || !BASE) { return; }
    var l = document.createElement('link');
    l.id = 'prwf-css';
    l.rel = 'stylesheet';
    l.href = BASE + '/css/form-workflow.css' + (VERSION ? '?m=' + VERSION : '');
    document.head.appendChild(l);
  }

  // ---------------------------------------------------------------- form page: button + dialog
  function initForm() {
    var title = document.querySelector('h1.form-title, [data-testid="form-title"]');
    if (!title || title.getAttribute('data-prwf')) { return; }
    if (/capacity request/i.test(title.textContent)) {   // all capacity request forms: compact text
      document.documentElement.classList.add('prwf-compact');
      addCss();
    }
    var type = typeOfTitle(title.textContent);
    if (!type) { return; }
    title.setAttribute('data-prwf', '1');
    addCss();

    var row = document.createElement('div');
    row.className = 'prwf-title-row';
    title.parentNode.insertBefore(row, title);
    row.appendChild(title);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-primary prwf-btn';   // same style as GLPI's own buttons (e.g. "Continue")
    btn.innerHTML = '<i class="ti ti-route" aria-hidden="true"></i><span>Workflow</span>';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.title = 'What happens after you submit this request';
    row.appendChild(btn);

    var page = BASE + '/front/workflow.php?view=customer&type=' + type;
    var modal = document.createElement('div');
    modal.className = 'modal fade prwf-modal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-labelledby', 'prwf-modal-title');
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">'
      + '<div class="modal-header"><h2 class="modal-title" id="prwf-modal-title">Workflow: ' + esc(WORKFLOWS[type].label) + ' request</h2>'
      + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
      + '<div class="modal-body">' + render(type) + '</div>'
      + '<div class="modal-footer"><a class="btn btn-link" href="' + esc(page) + '" target="_blank" rel="noopener">'
      + '<i class="ti ti-external-link"></i> Open as a page</a>'
      + '<a class="btn btn-link" href="' + esc(page + '&print=1') + '" target="_blank" rel="noopener"><i class="ti ti-printer"></i> Print</a>'
      + '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Back to the form</button></div>'
      + '</div></div>';
    document.body.appendChild(modal);
    bindJumps(modal);
    btn.addEventListener('click', function () {
      if (window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
      } else {
        window.open(page, '_blank', 'noopener');
      }
    });
  }

  // ---------------------------------------------------------------- full page (front/workflow.php)
  function initPage() {
    var root = document.getElementById('prwf-page');
    if (!root || root.getAttribute('data-ready')) { return; }
    root.setAttribute('data-ready', '1');
    var type = root.getAttribute('data-type');
    if (!WORKFLOWS[type]) { type = 'new'; }
    var tabs = root.querySelector('.prwf-tabs');
    var view = root.querySelector('.prwf-view');
    var title = root.querySelector('.prwf-page-top h1');
    if (title) { title.textContent = NAME; }
    var detailed = root.getAttribute('data-detailed') === '1';
    var show = function (t) {
      type = t;
      view.innerHTML = '<h2 class="prwf-page-h">' + esc(WORKFLOWS[t].label) + ' request</h2>' + render(t, detailed);
      tabs.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-type') === t ? 'true' : 'false'); });
      root.querySelectorAll('.prwf-viewswitch a').forEach(function (l) { l.setAttribute('href', l.getAttribute('href').replace(/type=[a-z]+/, 'type=' + t)); });
      try { window.history.replaceState(null, '', '?' + (root.getAttribute('data-view') ? 'view=' + root.getAttribute('data-view') + '&' : '') + 'type=' + t); } catch (e) { /* not important */ }
    };
    tabs.innerHTML = Object.keys(WORKFLOWS).map(function (k) {
      return '<button type="button" role="tab" data-type="' + k + '">' + esc(WORKFLOWS[k].label) + '</button>';
    }).join('');
    tabs.addEventListener('click', function (e) { var b = e.target.closest('button[data-type]'); if (b) { show(b.getAttribute('data-type')); } });
    bindJumps(view);
    show(type);
    if (root.getAttribute('data-print') === '1') { setTimeout(function () { window.print(); }, 500); }
  }

  window.PRWorkflow = { name: NAME, render: render, workflows: WORKFLOWS };

  function boot() { initForm(); initPage(); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  // the form page may build its header after load
  var tries = 0;
  var timer = setInterval(function () { initForm(); if (++tries > 20 || document.querySelector('.prwf-btn')) { clearInterval(timer); } }, 300);
})();
