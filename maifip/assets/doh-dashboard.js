(() => {
  'use strict';
  const root = document.getElementById('dohDashboard');
  const table = document.getElementById('dohExcelTable');
  const ruler = document.getElementById('dohColumnRuler');
  if (!root || !table || !window.DOH_SHEET_STATE) return;
  const canEdit = root.dataset.canEdit === '1';
  const csrf = root.dataset.csrf || '';
  const status = document.getElementById('dohSaveStatus');
  const rowCount = document.getElementById('dohRowCount');
  const clone = value => JSON.parse(JSON.stringify(value));
  let state = clone(window.DOH_SHEET_STATE);
  state.styles ||= {};
  let saveTimer = null, saving = false, pendingSave = false;
  let selectionAnchor = null;
  let selectedCells = new Set();
  const blankRow = () => ['', '', '', '', '', '', '', ''];
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const clean = value => String(value ?? '').trim();
  const parseAmount = value => {
    if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
    const normalized = String(value ?? '').replace(/[₱,\s]/g, '').replace(/[^0-9.()\-]/g, '');
    if (!normalized) return 0;
    if (normalized.startsWith('(') && normalized.endsWith(')')) return -Number(normalized.slice(1,-1) || 0);
    const n = Number(normalized); return Number.isFinite(n) ? n : 0;
  };
  const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
  const isBlankRow = row => row.every(cell => clean(cell) === '');
  const isGroupSeparator = index => isBlankRow(state.rows[index] || []) && !(state.addedRows || []).includes(index);
  const totalRows = () => state.rows.map((r,i) => clean(r[0]).toUpperCase() === 'TOTAL' ? i : -1).filter(i => i >= 0);
  function calculatedValue(r, c) {
    const raw = state.rows[r]?.[c] ?? '';
    if (typeof raw !== 'string' || !raw.startsWith('=')) return raw;
    const label = clean(state.rows[r]?.[0]).toUpperCase();
    if (label === 'TOTAL' && c === 2) { let start=r-1; while(start>=0 && !isGroupSeparator(start)) start--; start++; let total=0; for(let i=start;i<r;i++) total+=parseAmount(state.rows[i][2]); return total; }
    if (label.startsWith('REMAINING') && c === 5) { let t=r-1; while(t>=0 && clean(state.rows[t][0]).toUpperCase()!=='TOTAL') t--; return t>=0 ? parseAmount(calculatedValue(t,2))-parseAmount(calculatedValue(t,6)) : 0; }
    return raw;
  }
  function rowKind(row,index) {
    if ((state.addedRows||[]).includes(index)) return 'added-row';
    const label=clean(row[0]).toUpperCase();
    if(index===0)return'title-main'; if(index===1)return'title-and'; if(index===2)return'title-sub'; if(index===3)return'date-row';
    if(label==='HEALTH FACILITY')return'column-head'; if(label==='TOTAL')return'total'; if(label.startsWith('REMAINING'))return'balance';
    if(label.startsWith('TOTAL DOWNLOADED'))return'grand-total'; if(label.startsWith('TOTAL UTILIZED'))return'grand-total-strong'; if(label.startsWith('TOTAL REMAINING'))return'grand-total-final';
    if(label && !isBlankRow(row))return'facility'; return'spacer';
  }
  function cellKind(r,c,value,kind) {
    const classes=[]; if(kind)classes.push('doh-'+kind);
    if([2,5,6].includes(c) && (typeof value==='number'||(typeof value==='string'&&value.startsWith('='))))classes.push('doh-money');
    if(c===3)classes.push('doh-date'); if(c===4)classes.push('doh-source'); if(c===7)classes.push('doh-remarks'); if(kind==='spacer')classes.push('doh-empty-spacer'); return classes.join(' ');
  }
  function displayValue(r,c,raw) {
    const value=calculatedValue(r,c);
    if(typeof raw==='string'&&raw.startsWith('='))return peso(parseAmount(value));
    if([2,5,6].includes(c)&&(typeof value==='number'||(typeof value==='string'&&/^\s*₱?\s*-?[\d,]+(?:\.\d+)?\s*$/.test(value))))return peso(parseAmount(value));
    return String(value??'');
  }
  function styleString(style) {
    if(!style)return'';
    const css=[];
    if(style.fontFamily)css.push(`font-family:${style.fontFamily},sans-serif`);
    if(style.fontSize)css.push(`font-size:${Number(style.fontSize)}px`);
    if(style.color)css.push(`color:${style.color}`);
    if(style.backgroundColor)css.push(`background-color:${style.backgroundColor}`);
    if(style.bold)css.push('font-weight:700');
    if(style.italic)css.push('font-style:italic');
    if(style.underline)css.push('text-decoration:underline');
    return css.join(';');
  }
  function renderRuler() {
    if(!ruler)return;
    ruler.style.gridTemplateColumns=state.widths.map(w=>`${Math.max(36,Number(w)||80)}px`).join(' ');
    ruler.innerHTML=state.widths.map((w,c)=>`<div class="doh-ruler-col"><span>${String.fromCharCode(65+c)} · ${Math.round(Number(w)||80)}px</span>${canEdit?`<i class="doh-col-resize" data-col="${c}" title="Drag to resize column ${String.fromCharCode(65+c)}"></i>`:''}</div>`).join('');
    if(canEdit) ruler.querySelectorAll('.doh-col-resize').forEach(handle=>handle.addEventListener('pointerdown',event=>{
      event.preventDefault(); const c=Number(handle.dataset.col), startX=event.clientX, startW=Number(state.widths[c])||80;
      const move=e=>{state.widths[c]=Math.max(36,Math.min(900,startW+e.clientX-startX));ruler.style.gridTemplateColumns=state.widths.map(w=>`${w}px`).join(' ');const col=table.querySelectorAll('col')[c];if(col)col.style.width=state.widths[c]+'px';};
      const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up);renderRuler();scheduleSave();setStatus('Column width updated.','saving');};
      document.addEventListener('pointermove',move);document.addEventListener('pointerup',up,{once:true});
    }));
  }
  function paintSelection() {
    table.querySelectorAll('.doh-selected-cell').forEach(td=>td.classList.remove('doh-selected-cell'));
    selectedCells.forEach(key=>{const [r,c]=key.split(':');table.querySelector(`td[data-r="${r}"][data-c="${c}"]`)?.classList.add('doh-selected-cell');});
  }
  function selectCell(r,c,shift) {
    if(shift&&selectionAnchor){
      selectedCells.clear(); const r1=Math.min(selectionAnchor.r,r),r2=Math.max(selectionAnchor.r,r),c1=Math.min(selectionAnchor.c,c),c2=Math.max(selectionAnchor.c,c);
      for(let rr=r1;rr<=r2;rr++)for(let cc=c1;cc<=c2;cc++)selectedCells.add(`${rr}:${cc}`);
    } else {selectionAnchor={r,c};selectedCells=new Set([`${r}:${c}`]);}
    paintSelection();
  }
  function render() {
    const covered=new Set(),mergeStarts=new Map();
    (state.merges||[]).forEach(m=>{mergeStarts.set(`${m.r},${m.c}`,m);for(let rr=m.r;rr<m.r+m.rs;rr++)for(let cc=m.c;cc<m.c+m.cs;cc++)if(rr!==m.r||cc!==m.c)covered.add(`${rr},${cc}`);});
    let html='<colgroup>'+state.widths.map(w=>`<col style="width:${Math.max(36,Number(w)||80)}px">`).join('')+'</colgroup><tbody>';
    state.rows.forEach((row,r)=>{
      const kind=rowKind(row,r),h=Number(state.heights[r]||20);
      html+=`<tr data-row="${r}" class="${kind==='spacer'?'doh-spacer-row':(kind==='added-row'?'doh-added-row':'')}" style="height:${Math.max(4,h)}px">`;
      let lastVisible=-1;
      for(let c=0;c<8;c++)if(!covered.has(`${r},${c}`))lastVisible=c;
      for(let c=0;c<8;c++){
        if(covered.has(`${r},${c}`))continue;
        const merge=mergeStarts.get(`${r},${c}`),raw=row[c]??'',val=displayValue(r,c,raw),formula=typeof raw==='string'&&raw.startsWith('=');
        const editable=canEdit&&!formula;
        const style=styleString(state.styles[`${r}:${c}`]);
        const attrs=`${merge?` rowspan="${merge.rs}" colspan="${merge.cs}"`:''} data-r="${r}" data-c="${c}" class="${cellKind(r,c,raw,kind)}" ${editable?'contenteditable="true" spellcheck="false"':''} ${formula?'data-formula="1"':''} ${style?`style="${esc(style)}"`:''}`;
        html+=`<td ${attrs}>${esc(val)}${canEdit&&c===lastVisible?`<i class="doh-row-resize" data-row="${r}" title="Drag to resize row ${r+1}"></i>`:''}</td>`;
      }
      html+='</tr>';
    });
    html+='</tbody>'; table.innerHTML=html; rowCount.textContent=`${state.rows.length} worksheet rows`; renderRuler(); paintSelection();
    table.querySelectorAll('td[data-r][data-c]').forEach(td=>{
      td.addEventListener('click',event=>selectCell(Number(td.dataset.r),Number(td.dataset.c),event.shiftKey));
      if(td.hasAttribute('contenteditable')){
        td.addEventListener('input',()=>setStatus('Unsaved changes…','saving'));
        td.addEventListener('blur',()=>{const r=Number(td.dataset.r),c=Number(td.dataset.c);if(!state.rows[r])return;state.rows[r][c]=td.textContent.replace(/\u00a0/g,' ').trim();scheduleSave();setStatus('Cell updated.','saving');});
        td.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();td.blur();}if(event.key==='Escape'){event.preventDefault();td.textContent=displayValue(Number(td.dataset.r),Number(td.dataset.c),state.rows[Number(td.dataset.r)][Number(td.dataset.c)]);td.blur();}});
      }
    });
    if(canEdit)table.querySelectorAll('.doh-row-resize').forEach(handle=>handle.addEventListener('pointerdown',event=>{
      event.preventDefault();event.stopPropagation();const r=Number(handle.dataset.row),startY=event.clientY,startH=Number(state.heights[r]||20);
      const move=e=>{state.heights[r]=Math.max(4,Math.min(500,startH+e.clientY-startY));const tr=table.querySelector(`tr[data-row="${r}"]`);if(tr)tr.style.height=state.heights[r]+'px';};
      const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up);scheduleSave();setStatus('Row height updated.','saving');};
      document.addEventListener('pointermove',move);document.addEventListener('pointerup',up,{once:true});
    }));
  }
  function setStatus(message,cls=''){status.textContent=message;status.className='doh-save-status'+(cls?' doh-'+cls:'');}
  function scheduleSave(){if(!canEdit)return;clearTimeout(saveTimer);saveTimer=setTimeout(()=>saveState('save'),350);}
  async function saveState(action){
    if(!canEdit)return;if(saving){pendingSave=true;return;}saving=true;setStatus(action==='reset'?'Resetting…':'Saving changes…','saving');
    const body=new FormData();body.append('csrf_token',csrf);body.append('doh_action',action);if(action==='save')body.append('sheet_json',JSON.stringify(state));
    try{const response=await fetch('doh_dashboard.php',{method:'POST',body,credentials:'same-origin',headers:{'X-Requested-With':'fetch'}});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Could not save the dashboard.');if(action==='reset'&&result.data){state=clone(result.data);state.styles||={};selectedCells.clear();selectionAnchor=null;render();}setStatus(result.message||'All changes saved.','saved');}
    catch(error){setStatus(error.message||'Save failed. Please retry.','save-error');}finally{saving=false;if(pendingSave){pendingSave=false;saveState('save');}}
  }
  function applyStyle(patch){if(!canEdit||!selectedCells.size){setStatus('Select one or more cells first.','save-error');return;}selectedCells.forEach(key=>{state.styles[key]={...(state.styles[key]||{}),...patch};});render();scheduleSave();setStatus('Cell formatting updated.','saving');}
  document.getElementById('dohApplyFormat')?.addEventListener('click',()=>applyStyle({fontFamily:document.getElementById('dohFontFamily').value,fontSize:Number(document.getElementById('dohFontSize').value),color:document.getElementById('dohTextColor').value,backgroundColor:document.getElementById('dohCellColor').value}));
  function toggleStyle(flag){if(!canEdit||!selectedCells.size){setStatus('Select one or more cells first.','save-error');return;}const allOn=[...selectedCells].every(key=>Boolean(state.styles[key]?.[flag]));const patch={[flag]:!allOn};selectedCells.forEach(key=>{state.styles[key]={...(state.styles[key]||{}),...patch};});render();scheduleSave();setStatus('Cell formatting updated.','saving');}
  document.getElementById('dohBold')?.addEventListener('click',()=>toggleStyle('bold'));
  document.getElementById('dohItalic')?.addEventListener('click',()=>toggleStyle('italic'));
  document.getElementById('dohUnderline')?.addEventListener('click',()=>toggleStyle('underline'));
  document.getElementById('dohAddRow')?.addEventListener('click',()=>{
    if(!canEdit)return;const insertAt=state.rows.findIndex(row=>clean(row[0]).toUpperCase().startsWith('TOTAL DOWNLOADED'));const index=insertAt>=0?insertAt:state.rows.length;
    state.rows.splice(index,0,blankRow());state.heights.splice(index,0,22);state.addedRows=(state.addedRows||[]).map(r=>r>=index?r+1:r);state.addedRows.push(index);
    state.merges=state.merges.map(m=>{const next={...m};if(next.r>=index)next.r++;else if(next.r<index&&next.r+next.rs===index)next.rs++;return next;});
    const shifted={};Object.entries(state.styles||{}).forEach(([key,val])=>{const [r,c]=key.split(':').map(Number);shifted[`${r>=index?r+1:r}:${c}`]=val;});state.styles=shifted;
    render();scheduleSave();setStatus('New row added.','saving');const target=table.querySelector(`td[data-r="${index}"][data-c="2"]`);if(target)target.focus();
  });
  document.getElementById('dohReset')?.addEventListener('click',async()=>{if(!canEdit)return;if(!window.confirm('Reset the DOH MAIFIP worksheet to current system facilities and fund values? This discards shared edits and formatting for every dashboard user.'))return;clearTimeout(saveTimer);await saveState('reset');});
  document.getElementById('dohExport')?.addEventListener('click',()=>{
    render();const cloneTable=table.cloneNode(true);cloneTable.querySelectorAll('[contenteditable]').forEach(cell=>cell.removeAttribute('contenteditable'));cloneTable.querySelectorAll('.doh-row-resize').forEach(el=>el.remove());cloneTable.querySelectorAll('[data-r],[data-c],[data-formula]').forEach(cell=>{cell.removeAttribute('data-r');cell.removeAttribute('data-c');cell.removeAttribute('data-formula');});
    const exportCss=`table{border-collapse:collapse;table-layout:fixed;font-family:Arial,sans-serif;font-size:10pt}td{border:1px solid #7d8992;padding:4px;white-space:pre-wrap;vertical-align:middle}.doh-title-main,.doh-title-and,.doh-title-sub,.doh-date-row{background:#0b3155;color:#fff;font-weight:bold;text-align:center}.doh-title-main,.doh-title-sub{font-size:16pt}.doh-column-head{background:#e7eef4;font-weight:bold;text-align:center}.doh-facility,.doh-balance,.doh-grand-total{background:#f5faff;font-weight:bold;text-align:center}.doh-total{font-weight:bold}.doh-grand-total-strong{background:#eaf2f8;font-weight:bold;text-align:center}.doh-grand-total-final{background:#153f5e;color:#fff;font-weight:bold;text-align:center}.doh-money{text-align:right;white-space:nowrap}.doh-date,.doh-source,.doh-remarks{text-align:center}`;
    const html=`<!doctype html><html><head><meta charset="utf-8"><style>${exportCss}</style></head><body>${cloneTable.outerHTML}</body></html>`;const blob=new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8;'});const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download='DOH-MAIFIP-Region-II-Funds.xls';document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  render();
})();
