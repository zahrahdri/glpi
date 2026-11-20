/**
 * Home page (front/central.php) for users who can see the Reporting dashboard:
 * the "Reporting dashboard" tab comes first and GLPI's own "Dashboard" tab is hidden, so the tabs read
 * Reporting dashboard · Personal View · Global View · All. The Reporting dashboard is also the tab that opens
 * (set on the server, see setup.php), so GLPI's dashboard is never loaded.
 */
(function () {
    'use strict';
    // GLPI's tab id ("Central$0", "PluginProjectreportsCentral$1", ...) from the tab's ajax URL or an option value
    function tabId(s) {
        var m = /[?&]_glpi_tab=([^&]+)/.exec(s || '');
        try { return m ? decodeURIComponent(m[1]) : decodeURIComponent(s || ''); } catch (e) { return s || ''; }
    }
    function isOurs(s) { return tabId(s).indexOf('PluginProjectreportsCentral$') === 0; }
    function isGlpiDashboard(s) { return tabId(s) === 'Central$0'; }

    function arrange() {
        var list = document.getElementById('tabspanel');
        if (!list) { return false; }
        var links = Array.prototype.slice.call(list.querySelectorAll('a.nav-link'));
        var ours = links.filter(function (a) { return isOurs(a.getAttribute('data-glpi-ajax-content')); })[0];
        if (!ours) { return true; }   // user without the Reporting dashboard: leave GLPI's tabs alone
        links.forEach(function (a) {
            if (isGlpiDashboard(a.getAttribute('data-glpi-ajax-content'))) {
                var li = a.closest('li');
                if (li) { li.parentNode.removeChild(li); }
            }
        });
        var oursLi = ours.closest('li');
        if (oursLi && list.firstElementChild !== oursLi) { list.insertBefore(oursLi, list.firstElementChild); }
        // small screens: GLPI shows the tabs as a <select> whose values are tab positions; rebuild it in the new order
        var sel = document.getElementById('tabspanel-select');
        if (sel) {
            var items = Array.prototype.slice.call(list.querySelectorAll('a.nav-link'));
            var current = list.querySelector('a.nav-link.active') || ours;
            sel.innerHTML = '';
            items.forEach(function (a, i) {
                var o = document.createElement('option');
                o.value = String(i);
                o.textContent = (a.getAttribute('aria-label') || a.textContent || '').trim();
                if (a === current) { o.selected = true; }
                sel.appendChild(o);
            });
        }
        // The tab that opens is chosen on the server (setup.php), and GLPI opens it itself once its own tab
        // script has started. Only when an old link asked for GLPI's Dashboard is no tab open: then open ours,
        // after GLPI's start-up (a click before it would leave the tab empty).
        if (window.jQuery) {
            window.jQuery(function () {
                setTimeout(function () {
                    if (!list.querySelector('a.nav-link.active')) { window.jQuery(ours).tab('show'); }
                }, 0);
            });
        }
        return true;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrange);
    } else {
        arrange();
    }
})();
