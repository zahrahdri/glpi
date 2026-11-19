/**
 * "Workflow" for the capacity request forms (New project, Expansion, Modernization): what happens to a request
 * after it is submitted, step by step, who does what and what the requester sees in GLPI.
 *
 *  - On a form page (/Form/Render/<id>) a "Workflow" button is added next to the form title; it opens the
 *    workflow of that form's request type in a dialog.
 *  - front/workflow.php shows the same workflows as a full page (to share or print).
 * The steps are plain data (WORKFLOWS below): edit the texts there.
 */
(function () {
  'use strict';

  var ROLES = {
    requester: { label: 'You (requester)', color: '#2f6fde' },
    manager: { label: 'Your approving manager', color: '#7a4fd6' },
    pm: { label: 'Project manager', color: '#c98a00' },
    planning: { label: 'Infrastructure planning', color: '#0d9488' },
    procurement: { label: 'Procurement & finance', color: '#d9622b' },
    delivery: { label: 'Infrastructure delivery', color: '#2e9e4f' }
  };

  // what the requester sees on the ticket at each step
  var STATUS = {
    created: 'Ticket created (New)',
    approval: 'Waiting for approval',
    approved: 'Approved → assigned',
    pending: 'Pending (waiting for information)',
    processing: 'Processing (assigned)',
    vendor: 'Pending (waiting for vendor)',
    solved: 'Solved → Closed'
  };

  var submit = {
    id: 'submit', phase: 'Request', role: 'requester', title: 'Submit the request',
    does: ['Fill in this form: one request per resource type (compute, storage or GPU).',
      'Attach the sizing evidence and, for a new platform, the technical design.'],
    status: 'created', you: 'You get a ticket number. Every next step is visible in that ticket.'
  };
  var approval = {
    id: 'approval', phase: 'Approval', role: 'manager', title: 'Manager approval',
    does: ['The manager you chose in the form receives an approval request in GLPI.',
      'They approve or refuse it, with a comment.'],
    status: 'approval', you: 'If it is refused, the request is closed and the reason is shown in the ticket.'
  };
  var procurement = {
    id: 'procurement', phase: 'Procurement', role: 'procurement', title: 'Procurement', optional: 'Only when the resources are not available',
    does: ['Budget approval of the bill of quantities (BOQ).',
      'Purchase order (PO) to the vendor; the PO is linked to your request.',
      'Delivery to the warehouse and acceptance check of the equipment.'],
    status: 'vendor', you: 'This is usually the longest step; the expected delivery date is noted in the ticket.'
  };
  var handover = {
    id: 'handover', phase: 'Handover', role: 'requester', title: 'Handover & acceptance',
    does: ['You receive the handover details (names, addresses, access, capacity delivered).',
      'You check them and accept the solution, or reopen the ticket if something is not right.'],
    status: 'solved', you: 'After your acceptance the ticket is closed and you may get a short satisfaction survey.'
  };
  var decision = {
    decision: true, question: 'Can it be delivered from what we already have?',
    yes: 'The resources are reserved for you and delivery starts; procurement is skipped.',
    no: 'The missing equipment is procured first.'
  };

  var WORKFLOWS = {
    'new': {
      label: 'New project',
      intro: 'Infrastructure for a new business project, from your request to the handover of the delivered resources.',
      steps: [submit, approval, {
        id: 'review', phase: 'Project review', role: 'pm', title: 'Project review',
        does: ['Checks that the request belongs to an approved business project.',
          'Creates the project in GLPI and links your request to it.',
          'Confirms priority and go-live date, and asks you if anything is missing.'],
        status: 'processing', you: 'If information is missing the ticket goes to Pending until you answer the follow-up.'
      }, {
        id: 'planning', phase: 'Planning', role: 'planning', title: 'Design & capacity check',
        does: ['Reviews the sizing and the technical design.',
          'Checks available capacity: virtual clusters, warehouse stock, rack space and power.',
          'Prepares the bill of quantities (BOQ) and the cost estimate.'],
        status: 'processing'
      }, decision, procurement, {
        id: 'delivery', phase: 'Delivery', role: 'delivery', title: 'Build & deliver',
        does: ['Installation in the data center: racking, cabling, configuration.',
          'Or provisioning on an existing platform (virtual machines, storage).',
          'The delivered equipment is recorded in GLPI and linked to your request.'],
        status: 'processing'
      }, handover]
    },
    expansion: {
      label: 'Expansion',
      intro: 'More capacity for an existing project or platform, from your request to the handover of the added capacity.',
      steps: [submit, approval, {
        id: 'review', phase: 'Project review', role: 'pm', title: 'Project review',
        does: ['Confirms the existing project and the current use of its resources.',
          'Checks the request against the project plan and priorities.',
          'Asks you if anything is missing.'],
        status: 'processing', you: 'If information is missing the ticket goes to Pending until you answer the follow-up.'
      }, {
        id: 'planning', phase: 'Planning', role: 'planning', title: 'Capacity check',
        does: ['Checks the current use and growth of the platform (virtual clusters, storage).',
          'Decides how to expand: free capacity, extra hosts or disks, or new equipment.',
          'Prepares the BOQ and the cost estimate when equipment is needed.'],
        status: 'processing'
      }, decision, procurement, {
        id: 'delivery', phase: 'Delivery', role: 'delivery', title: 'Expand the platform',
        does: ['Adds the capacity to the existing platform (hosts, disks, storage, quota).',
          'Work that affects running services is done in an agreed maintenance window.',
          'The added equipment is recorded in GLPI and linked to your request.'],
        status: 'processing'
      }, handover]
    },
    modernization: {
      label: 'Modernization & swap',
      intro: 'Replacement of an old or unsupported device, from your request to the removal of the old device.',
      steps: [submit, approval, {
        id: 'review', phase: 'Project review', role: 'pm', title: 'Project review',
        does: ['Confirms the device to replace (hostname) and the services running on it.',
          'Agrees the business impact and a migration window with you.'],
        status: 'processing', you: 'If information is missing the ticket goes to Pending until you answer the follow-up.'
      }, {
        id: 'planning', phase: 'Planning', role: 'planning', title: 'Replacement plan',
        does: ['Checks the device age and support status (DCIM).',
          'Chooses the replacement model and plans the migration (swap).',
          'Prepares the BOQ and the cost estimate.'],
        status: 'processing'
      }, decision, procurement, {
        id: 'delivery', phase: 'Delivery', role: 'delivery', title: 'Install & migrate',
        does: ['Installs the new device and migrates the services in the agreed window.',
          'Checks that everything runs on the new device; the old one stays ready for a rollback.'],
        status: 'processing'
      }, handover, {
        id: 'decommission', phase: 'Decommission', role: 'delivery', title: 'Decommission the old device',
        does: ['After your acceptance the old device is switched off and removed.',
          'Its data is wiped and its status is updated in GLPI (warehouse, spare or disposal).'],
        status: 'solved'
      }]
    }
  };

  // ---------------------------------------------------------------- rendering
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  function render(type) {
    var wf = WORKFLOWS[type];
    if (!wf) { return ''; }
    var n = 0;
    var steps = wf.steps.filter(function (s) { return !s.decision; });
    var phases = '<ol class="prwf-phases" aria-label="Phases">' + steps.map(function (s, i) {
      return '<li style="--c:' + ROLES[s.role].color + '"><span class="prwf-ph-n">' + (i + 1) + '</span><span class="prwf-ph-t">' + esc(s.phase) + '</span>'
        + (s.optional ? '<span class="prwf-ph-o">if needed</span>' : '') + '</li>';
    }).join('') + '</ol>';

    var body = wf.steps.map(function (s) {
      if (s.decision) {
        return '<li class="prwf-decision"><div class="prwf-diamond" aria-hidden="true">?</div><div class="prwf-dec-b">'
          + '<div class="prwf-dec-q">' + esc(s.question) + '</div>'
          + '<div class="prwf-dec-paths"><div class="prwf-yes"><b>Yes</b> ' + esc(s.yes) + '</div>'
          + '<div class="prwf-no"><b>No</b> ' + esc(s.no) + '</div></div></div></li>';
      }
      n++;
      var role = ROLES[s.role];
      return '<li class="prwf-step' + (s.optional ? ' is-optional' : '') + '" style="--c:' + role.color + '">'
        + '<div class="prwf-num" aria-hidden="true">' + n + '</div>'
        + '<div class="prwf-card"><div class="prwf-card-h"><h3>' + esc(s.title) + '</h3>'
        + '<span class="prwf-role"><i></i>' + esc(role.label) + '</span></div>'
        + (s.optional ? '<div class="prwf-opt">' + esc(s.optional) + '</div>' : '')
        + '<ul class="prwf-does">' + s.does.map(function (d) { return '<li>' + esc(d) + '</li>'; }).join('') + '</ul>'
        + '<div class="prwf-foot"><span class="prwf-status" title="What you see on the ticket">' + esc(STATUS[s.status]) + '</span>'
        + (s.you ? '<span class="prwf-you">' + esc(s.you) + '</span>' : '') + '</div></div></li>';
    }).join('');

    var used = {};
    steps.forEach(function (s) { used[s.role] = true; });
    var legend = '<div class="prwf-legend">' + Object.keys(ROLES).filter(function (k) { return used[k]; }).map(function (k) {
      return '<span style="--c:' + ROLES[k].color + '"><i></i>' + esc(ROLES[k].label) + '</span>';
    }).join('') + '</div>';

    return '<div class="prwf">'
      + '<p class="prwf-intro">' + esc(wf.intro) + '</p>' + phases + legend
      + '<ol class="prwf-steps">' + body + '</ol>'
      + '<div class="prwf-track"><b>Follow your request:</b> Assistance › Tickets (or "My tickets"). The ticket shows its status, '
      + 'the approvals, and every follow-up; answer follow-ups there so the request does not stay pending.</div>'
      + '</div>';
  }

  function typeOfTitle(text) {
    var t = String(text || '').toLowerCase();
    if (t.indexOf('capacity request') < 0) { return ''; }
    if (t.indexOf('new project') >= 0) { return 'new'; }
    if (t.indexOf('expansion') >= 0) { return 'expansion'; }
    if (t.indexOf('modernization') >= 0 || t.indexOf('modernisation') >= 0) { return 'modernization'; }
    return '';
  }

  var BASE = (function () {
    var s = document.currentScript && document.currentScript.src;
    return s ? s.replace(/\/js\/form-workflow\.js.*$/, '') : '';
  })();
  var VERSION = (function () {
    var s = document.currentScript && document.currentScript.src;
    var m = s && s.match(/[?&]m=(\d+)/);
    return m ? m[1] : '';
  })();

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
    btn.className = 'btn btn-outline-secondary prwf-btn';
    btn.innerHTML = '<i class="ti ti-route" aria-hidden="true"></i><span>Workflow</span>';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.title = 'What happens after you submit this request';
    row.appendChild(btn);

    var page = BASE.replace(/\/plugins\/projectreports$/, '') + '/plugins/projectreports/front/workflow.php?type=' + type;
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
    if (!root) { return; }
    var type = root.getAttribute('data-type');
    if (!WORKFLOWS[type]) { type = 'new'; }
    var tabs = root.querySelector('.prwf-tabs');
    var view = root.querySelector('.prwf-view');
    var show = function (t) {
      type = t;
      view.innerHTML = '<h2 class="prwf-page-h">Workflow: ' + esc(WORKFLOWS[t].label) + ' request</h2>' + render(t);
      tabs.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-type') === t ? 'true' : 'false'); });
      try { window.history.replaceState(null, '', '?type=' + t); } catch (e) { /* not important */ }
    };
    tabs.innerHTML = Object.keys(WORKFLOWS).map(function (k) {
      return '<button type="button" role="tab" data-type="' + k + '">' + esc(WORKFLOWS[k].label) + '</button>';
    }).join('');
    tabs.addEventListener('click', function (e) { var b = e.target.closest('button[data-type]'); if (b) { show(b.getAttribute('data-type')); } });
    show(type);
    if (root.getAttribute('data-print') === '1') { setTimeout(function () { window.print(); }, 400); }
  }

  window.PRWorkflow = { render: render, workflows: WORKFLOWS };

  function boot() { initForm(); initPage(); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  // the form page may build its header after load
  var tries = 0;
  var timer = setInterval(function () { initForm(); if (++tries > 20 || document.querySelector('.prwf-btn')) { clearInterval(timer); } }, 300);
})();
