/* v0.0188 — load route-specific shared behavior only for pages that need it.
 * Also runs after partial shell navigation so scripts are not lost when #pageView is replaced.
 */
(function () {
  'use strict';
  const loaded = new Set();
  const routes = {
    tables: new Set(['records.php','logs.php','tickets.php','fund.php','users.php','offices.php','monthly_report.php','reports.php','trash.php']),
    dashboard: new Set(['dashboard.php']),
    inputs: new Set(['records.php','record_form.php','new_assistance.php','ticket_create.php','ticket_edit.php','ticket_review.php','revision_ticket.php','office_reassignment_ticket.php','fund.php','users.php','account.php','profile.php','monthly_report_settings.php'])
  };
  function addScript(key, src) {
    if (loaded.has(key) || document.querySelector('script[data-fams-lazy="'+key+'"]')) return;
    loaded.add(key);
    const script = document.createElement('script');
    script.src = src;
    script.defer = true;
    script.dataset.famsLazy = key;
    script.onerror = () => { loaded.delete(key); script.remove(); };
    document.body.appendChild(script);
  }
  function addStyle(key, href) {
    if (document.querySelector('link[data-fams-lazy-style="'+key+'"]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet'; link.href = href; link.dataset.famsLazyStyle = key;
    document.head.appendChild(link);
  }
  function sync() {
    const page = (location.pathname.split('/').pop() || '').toLowerCase();
    const prefix = '../core/';
    const version = document.querySelector('link[href*="assets/app.css"]')?.href.match(/[?&]v=([^&]+)/)?.[1] || '0.0188';
    const asset = name => prefix + name + '?v=' + encodeURIComponent(version);
    if (routes.tables.has(page)) {
      addStyle('tables', asset('shared-tables.css'));
      addScript('tables', asset('shared-tables.js'));
    }
    if (routes.dashboard.has(page)) addScript('dashboard-period', asset('shared-dashboard-period.js'));
    if (routes.inputs.has(page)) addScript('input-assist', asset('shared-input-assist.js'));
    if (/\/maifip\//i.test(location.pathname)) {
      addScript('maifip-offline-sync', '../maifip/assets/offline-sync.js?v=' + encodeURIComponent(version));
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sync, { once: true }); else sync();
  document.addEventListener('fams:page-replaced', sync);
  window.addEventListener('popstate', sync);
})();
