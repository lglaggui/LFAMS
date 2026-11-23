'use strict';
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(__dirname + '/../../core/shared-sheet.js', 'utf8');
let passed = 0;
function check(ok, label) { if (!ok) throw new Error('FAIL: ' + label); passed++; console.log('PASS: ' + label); }
function makeCell(width) { return { style: {}, getBoundingClientRect() { return { width: Number.parseFloat(this.style.width) || width }; } }; }
function makeRow(height, cells) { return { style: {}, cells, classList: { contains: () => false }, getBoundingClientRect() { return { height: Number.parseFloat(this.style.height) || height }; } }; }
function environment(readOnly, initial) {
  const cells = [makeCell(100), makeCell(200)];
  const colgroup = { children: [{ style: {} }, { style: {} }] };
  const rows = [makeRow(30, [makeCell(100), makeCell(200)]), makeRow(40, [makeCell(100), makeCell(200)])];
  const table = { tHead: { rows: [{ cells }] }, tBodies: [{ rows }], querySelector: selector => selector === 'colgroup' ? colgroup : null };
  const body = { dataset: { sheetKey: 'workspace:facility:2026:11', sheetReadonly: readOnly ? '1' : '0' }, querySelector: selector => selector === '.sheet-table' ? table : null };
  const store = new Map(); if (initial) store.set('fams.sheet.dimensions.v1:' + body.dataset.sheetKey, JSON.stringify(initial));
  const listeners = {};
  const document = { body, readyState: 'complete', addEventListener: (name, fn) => { (listeners[name] ||= []).push(fn); } };
  const window = { addEventListener: (name, fn) => { (listeners['window:' + name] ||= []).push(fn); } };
  const localStorage = { getItem: key => store.get(key) ?? null, setItem: (key, value) => store.set(key, value) };
  vm.runInNewContext(source, { document, window, localStorage, Number, JSON, Math });
  return { cells, colgroup, rows, listeners, store, key: 'fams.sheet.dimensions.v1:' + body.dataset.sheetKey };
}
const env = environment(false, { columns: [145, 255], rows: [52, 64] });
check(env.cells[0].style.width === '145px' && env.cells[1].style.width === '255px', 'saved column widths restore to worksheet headers');
check(env.colgroup.children[0].style.width === '145px', 'saved widths restore to explicit column groups');
check(env.rows[0].style.height === '52px' && env.rows[1].style.height === '64px', 'saved row heights restore to worksheet rows');
env.cells[0].style.width = '170px'; env.rows[0].style.height = '70px';
for (const listener of env.listeners.pointerup || []) listener();
const saved = JSON.parse(env.store.get(env.key));
check(saved.columns[0] === 170 && saved.rows[0] === 70, 'pointer resize persists current column and row dimensions');
const readOnly = environment(true, null);
for (const listener of readOnly.listeners.mouseup || []) listener();
check(!readOnly.store.has(readOnly.key), 'read-only worksheet does not persist dimension changes');
console.log(`Shared worksheet dimension tests: ${passed} passed, 0 failed.`);
