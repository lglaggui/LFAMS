/* FAMS v0.0140 — shared beginner-friendly form guidance and anticipatory search suggestions. */
(function () {
  'use strict';
  const root = document.querySelector('.fams-shared-layout') || document.body;
  const all = (selector, scope = root) => Array.from(scope.querySelectorAll(selector));
  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();

  // Make browser autofill and virtual keyboards useful without changing field names or values.
  all('input:not([type="hidden"]), textarea, select').forEach(field => {
    const key = [field.name, field.id, field.getAttribute('aria-label'), field.placeholder].join(' ').toLowerCase();
    if (!field.hasAttribute('autocomplete')) {
      if (/current.?password/.test(key)) field.autocomplete = 'current-password';
      else if (/new.?password|confirm.?password/.test(key)) field.autocomplete = 'new-password';
      else if (/e.?mail/.test(key)) field.autocomplete = 'email';
      else if (/username|user.?name|login/.test(key)) field.autocomplete = 'username';
      else if (/full.?name|beneficiary|client.?name|patient.?name/.test(key)) field.autocomplete = 'name';
      else if (/phone|mobile|contact.?number/.test(key)) field.autocomplete = 'tel';
      else if (/address/.test(key)) field.autocomplete = 'street-address';
      else if (/search|filter|query|\bq\b/.test(key) && field.type === 'search') field.autocomplete = 'off';
    }
    if (field.type === 'email' || /username|control.?no|ip.?address/.test(key)) field.spellcheck = false;
    if (/phone|mobile|contact.?number/.test(key) && !field.hasAttribute('inputmode')) field.inputMode = 'tel';
    if (field.required && !field.getAttribute('aria-label') && !field.labels?.length) {
      field.setAttribute('aria-label', clean(field.placeholder) || clean(field.name).replace(/[_-]+/g, ' '));
    }
    field.addEventListener('input', () => {
      if (field.checkValidity()) field.removeAttribute('aria-invalid');
    });
    field.addEventListener('change', () => {
      if (field.checkValidity()) field.removeAttribute('aria-invalid');
    });
  });

  // Give forms a predictable, accessible first-error path; keep native validation messages.
  all('form').forEach(form => {
    if (form.dataset.famsInputAssistBound === '1') return;
    form.dataset.famsInputAssistBound = '1';
    form.addEventListener('submit', event => {
      const fields = Array.from(form.querySelectorAll('input,select,textarea')).filter(el => !el.disabled && el.type !== 'hidden' && typeof el.checkValidity === 'function');
      const invalid = fields.find(el => !el.checkValidity());
      if (!invalid) return;
      event.preventDefault();
      invalid.setAttribute('aria-invalid', 'true');
      invalid.focus({ preventScroll: true });
      invalid.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
      invalid.reportValidity();
    });
  });

  // Native datalist suggestions anticipate likely values using content already on this page.
  // This is a convenience only: it never replaces server-side search or access checks.
  const searchable = all('input[type="search"], input[name="q"], input[id*="search" i], input[placeholder*="search" i], input[data-anticipatory-search="true"]');
  const sourceValues = () => {
    const values = new Set();
    all('table tbody tr:not([hidden])').forEach(row => {
      all('td', row).forEach(cell => {
        const value = clean(cell.innerText || cell.textContent);
        if (value.length >= 2 && value.length <= 100 && !/^(edit|delete|view|restore|submit ticket)$/i.test(value)) values.add(value);
      });
    });
    all('select option').forEach(option => {
      const value = clean(option.textContent);
      if (value.length >= 2 && value.length <= 100 && option.value) values.add(value);
    });
    all('details > summary, .help-search-wrap ~ details summary').forEach(el => {
      const value = clean(el.textContent); if (value.length >= 2 && value.length <= 100) values.add(value);
    });
    return Array.from(values).slice(0, 500);
  };
  const values = sourceValues();
  searchable.forEach((field, index) => {
    if (!values.length || field.dataset.famsSuggestionsBound === '1') return;
    field.dataset.famsSuggestionsBound = '1';
    const listId = 'famsSearchSuggestions-' + index + '-' + Math.random().toString(36).slice(2, 7);
    const list = document.createElement('datalist'); list.id = listId;
    field.insertAdjacentElement('afterend', list); field.setAttribute('list', listId);
    field.setAttribute('aria-autocomplete', 'list');
    const update = () => {
      const q = clean(field.value).toLocaleLowerCase(); list.replaceChildren();
      if (q.length < 2) return;
      let count = 0;
      for (const value of values) {
        if (value.toLocaleLowerCase().includes(q)) {
          const option = document.createElement('option'); option.value = value; list.appendChild(option);
          if (++count >= 8) break;
        }
      }
    };
    field.addEventListener('input', update); field.addEventListener('focus', update);
  });
})();
