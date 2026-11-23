/* Shared delegated reporting-period refresh for AICS and MAIFIP.
 * Delegation is intentional: dashboard fragments replace their own forms, so
 * direct listeners on the original select elements would stop working after refresh.
 */
(function () {
  'use strict';
  if (document.documentElement.dataset.sharedDashboardPeriodReady === '1') return;
  document.documentElement.dataset.sharedDashboardPeriodReady = '1';

  const getForm = target => target instanceof Element ? target.closest('form.dashboard-filter-form[data-dashboard-period-form]') : null;
  const setBusy = (form, busy, message) => {
    form.dataset.sharedPeriodBusy = busy ? '1' : '0';
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    form.querySelectorAll('select,button,input[type="submit"]').forEach(control => {
      if (busy) {
        control.dataset.periodWasDisabled = control.disabled ? '1' : '0';
        control.disabled = true;
      } else {
        control.disabled = control.dataset.periodWasDisabled === '1';
        delete control.dataset.periodWasDisabled;
      }
    });
    let status = form.querySelector('.fams-period-status');
    if (!status) {
      status = document.createElement('span');
      status.className = 'fams-period-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      form.appendChild(status);
    }
    status.textContent = message || '';
    status.hidden = !message;
  };

  async function refresh(form, submitter) {
    if (!form || !form.isConnected || form.dataset.sharedPeriodBusy === '1') return;
    const params = new URLSearchParams(new FormData(form));
    if (submitter && submitter.name && submitter.value) params.set(submitter.name, submitter.value);
    params.set('fragment', '1');
    const action = new URL(form.getAttribute('action') || 'dashboard.php', window.location.href);
    action.search = params.toString();
    setBusy(form, true, 'Updating dashboard…');
    try {
      const response = await fetch(action.toString(), {
        credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }
      });
      if (!response.ok) throw new Error(`Dashboard update failed (${response.status}).`);
      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const next = parsed.querySelector('#dashboardLayout.fams-dashboard');
      const current = document.querySelector('#dashboardLayout.fams-dashboard');
      if (!next || !current) throw new Error('The refreshed dashboard was incomplete.');

      // Preserve the persistent hero action if a fragment omits it for a role.
      const currentHero = current.querySelector('[data-dashboard-item="hero"] .hero-banner');
      const nextHero = next.querySelector('[data-dashboard-item="hero"] .hero-banner');
      if (currentHero && nextHero && !nextHero.querySelector('.hero-action')) {
        const currentAction = currentHero.querySelector('.hero-action');
        if (currentAction) nextHero.appendChild(currentAction.cloneNode(true));
      }
      current.replaceWith(document.importNode(next, true));
      const refreshedForm = document.querySelector('#dashboardLayout .dashboard-filter-form[data-dashboard-period-form]');
      if (refreshedForm) setBusy(refreshedForm, false, 'Updated');
      const url = new URL(window.location.href);
      ['year', 'month'].forEach(key => {
        const value = params.get(key);
        if (value) url.searchParams.set(key, value);
      });
      url.searchParams.delete('fragment');
      window.history.replaceState({}, '', url.pathname + url.search + url.hash);
      document.dispatchEvent(new CustomEvent('fams:dashboard-replaced', {
        detail: { year: params.get('year'), month: params.get('month') }
      }));
    } catch (error) {
      console.error('Shared reporting-period update failed:', error);
      setBusy(form, false, 'Automatic refresh failed. Reloading the dashboard with the selected period…');
      const fallback = new URL(form.getAttribute('action') || 'dashboard.php', window.location.href);
      fallback.search = new URLSearchParams(new FormData(form)).toString();
      window.location.assign(fallback.toString());
      return;
    }
    if (form.isConnected) setBusy(form, false, 'Updated');
  }

  document.addEventListener('change', event => {
    const field = event.target;
    if (!(field instanceof HTMLSelectElement) || !field.matches('select[name="year"],select[name="month"]')) return;
    const form = getForm(field);
    if (!form || form.dataset.sharedPeriodBusy === '1') return;
    refresh(form, null);
  });

  document.addEventListener('submit', event => {
    const form = getForm(event.target);
    if (!form) return;
    event.preventDefault();
    refresh(form, event.submitter || null);
  });
})();
