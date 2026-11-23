/* FAMS v0.0213 — shared editable-sheet dimension persistence. */
(function () {
    'use strict';

    function initSheetDimensions() {
        const page = document.body;
        const table = page?.querySelector('.sheet-table');
        const key = page?.dataset.sheetKey;
        if (!table || !key) return;

        const readOnly = page.dataset.sheetReadonly === '1';
        const storageKey = 'fams.sheet.dimensions.v1:' + key;
        const getRows = () => [...(table.tBodies[0]?.rows || [])].filter(row => !row.classList.contains('empty-row'));
        const getColumns = () => [...(table.tHead?.rows[0]?.cells || [])];

        function applyDimensions() {
            let saved;
            try { saved = JSON.parse(localStorage.getItem(storageKey) || 'null'); }
            catch (_) { saved = null; }
            if (!saved || typeof saved !== 'object') return;

            const columns = getColumns();
            const colgroup = table.querySelector('colgroup');
            (Array.isArray(saved.columns) ? saved.columns : []).forEach((width, index) => {
                const value = Number(width);
                if (!Number.isFinite(value) || value < 40 || value > 1600 || !columns[index]) return;
                columns[index].style.width = value + 'px';
                if (colgroup?.children[index]) colgroup.children[index].style.width = value + 'px';
                getRows().forEach(row => {
                    if (row.cells[index]) row.cells[index].style.width = value + 'px';
                });
            });

            const rows = getRows();
            (Array.isArray(saved.rows) ? saved.rows : []).forEach((height, index) => {
                const value = Number(height);
                if (!Number.isFinite(value) || value < 24 || value > 1200 || !rows[index]) return;
                rows[index].style.height = value + 'px';
            });
        }

        function persistDimensions() {
            if (readOnly) return;
            const columns = getColumns().map(cell => {
                const width = Math.round(cell.getBoundingClientRect().width);
                return width >= 40 && width <= 1600 ? width : null;
            });
            const rows = getRows().map(row => {
                const height = Math.round(row.getBoundingClientRect().height);
                return height >= 24 && height <= 1200 ? height : null;
            });
            try { localStorage.setItem(storageKey, JSON.stringify({ columns, rows })); }
            catch (_) { /* Worksheet editing remains usable when storage is unavailable. */ }
        }

        applyDimensions();
        if (!readOnly) {
            // Both existing worksheet controllers support pointer or mouse resizing.
            document.addEventListener('pointerup', persistDimensions, true);
            document.addEventListener('mouseup', persistDimensions, true);
            window.addEventListener('beforeprint', persistDimensions);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSheetDimensions, { once: true });
    } else {
        initSheetDimensions();
    }
})();
