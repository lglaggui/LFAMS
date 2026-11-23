/* Shared Help & Support FAQ search for MAIFIP and AICS/SWAD. */
(function () {
  'use strict';
  function initHelpSearch() {
    const input = document.getElementById('helpFaqSearch');
    if (!input || input.dataset.helpSearchReady === '1') return;
    input.dataset.helpSearchReady = '1';
    const status = document.getElementById('helpSearchStatus');
    const topicCount = document.getElementById('helpTopicCount');
    const sections = Array.from(document.querySelectorAll('.help-section'));
    const normalize = value => String(value || '').toLocaleLowerCase().replace(/\s+/g, ' ').trim();
    function apply() {
      const query = normalize(input.value);
      let matched = 0, total = 0;
      sections.forEach(section => {
        const details = Array.from(section.querySelectorAll('details'));
        if (!details.length) {
          section.classList.toggle('is-filtered-empty', Boolean(query) && !normalize(section.textContent).includes(query));
          return;
        }
        let sectionMatches = 0;
        details.forEach(detail => {
          total++;
          const hit = !query || normalize(detail.textContent).includes(query);
          detail.classList.toggle('help-match-hidden', !hit);
          if (hit) sectionMatches++;
          if (query && hit) detail.open = true;
        });
        section.classList.toggle('is-filtered-empty', Boolean(query) && sectionMatches === 0);
        matched += sectionMatches;
      });
      if (status) status.textContent = query ? `${matched} matching topic${matched === 1 ? '' : 's'}` : 'Showing all Help & Support topics';
      if (topicCount) topicCount.textContent = query ? `${matched} match${matched === 1 ? '' : 'es'}` : `${total} topics`;
    }
    input.addEventListener('input', apply);
    input.addEventListener('search', apply);
    apply();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initHelpSearch, { once: true });
  else initHelpSearch();
})();
