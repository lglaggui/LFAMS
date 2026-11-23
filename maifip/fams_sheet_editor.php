<?php
require_once __DIR__.'/config.php'; require_login();if(!fams_current_type_allows('MAIFIP','view_records')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
$user=current_user(); if(!in_array($user['role'],['admin','encoder','manager'],true)){http_response_code(403);exit('Access denied.');}
$readOnly = $user['role']==='manager' || !fams_current_type_allows('MAIFIP','edit_records');
$q=trim($_GET['q']??''); $status=trim($_GET['status']??'');
$activeFacility=active_data_facility($pdo); $sql="SELECT * FROM assistance_records WHERE facility=?";$params=[$activeFacility]; if($q!==''){ $sql.=" AND (control_number LIKE ? OR patient_first_name LIKE ? OR patient_middle_name LIKE ? OR patient_last_name LIKE ? OR barangay LIKE ? OR municipality LIKE ? OR guardian LIKE ? OR contact_number LIKE ? OR remarks LIKE ?)";$n="%{$q}%";for($i=0;$i<9;$i++)$params[]=$n;} if($status!==''){ $sql.=' AND status=?';$params[]=$status;} $sql.=' ORDER BY date_submission DESC,id DESC LIMIT 500';$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
$payload=['rows'=>array_map(fn($r)=>['control_number'=>$r['control_number'],'date_submission'=>$r['date_submission'],'patient_first_name'=>$r['patient_first_name'],'patient_middle_name'=>$r['patient_middle_name'],'patient_last_name'=>$r['patient_last_name'],'patient_name_extension'=>$r['patient_name_extension'],'barangay'=>$r['barangay'],'municipality'=>$r['municipality'],'province'=>$r['province'],'guardian'=>$r['guardian'],'contact_number'=>$r['contact_number'],'bill_amount'=>(float)$r['bill_amount'],'guarantee_amount'=>(float)$r['guarantee_amount'],'remarks'=>$r['remarks'],'status'=>$r['status'],'release_date'=>$r['release_date']],$rows),'generated'=>date('Y-m-d H:i:s')];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $readOnly ? "FAMS View Sheet" : "FAMS Editable Sheet" ?></title><link rel="stylesheet" href="assets/app.css"><style>:root{--navy:#082f63;--navy2:#0b3b78;--line:#cbd6e2;--ink:#1d2d3d}
html,body{margin:0;padding:0;background:#eef3f8;color:var(--ink);font-family:"Inter","Aptos","Segoe UI",Roboto,Arial,sans-serif;overflow-x:hidden}
body.sheet-page{min-width:0;overflow-x:hidden}
.sheet-toolbar{position:sticky;top:0;z-index:1000;isolation:isolate;pointer-events:auto;background:#fff;border-bottom:1px solid #d4dee9;padding:7px 10px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;box-shadow:0 2px 8px rgba(15,42,70,.08)}
.sheet-toolbar h1{font-size:13px;margin:0 6px 0 0;color:var(--navy);white-space:nowrap}.sheet-toolbar .meta{font-size:8px;color:#718398;margin-right:auto;white-space:nowrap}
.sheet-toolbar button{position:relative;z-index:1001;pointer-events:auto;touch-action:manipulation;cursor:pointer;white-space:nowrap;font-size:9px!important;padding:6px 9px!important}
.sheet-actions{display:flex;gap:6px;margin:0 8px 4px;padding:3px 0}.sheet-actions span{font-size:8px!important;color:#718398}
.editable{background:#fffdf0;outline:none}.editable:focus{box-shadow:inset 0 0 0 1.5px #6c9bc8;background:#fff}
/* Compact masterlist: no inner scrolling, no fixed-height viewport, and all columns share one table width. */
.fams-sheet-wrap{position:relative;z-index:0;padding:0 6px 8px;overflow:visible;width:auto;max-width:none}
.fams-sheet{background:#fff;width:100%;max-width:100%;display:block;border:1px solid #c8d4e0;box-shadow:0 3px 12px rgba(20,50,80,.06);box-sizing:border-box}
.fams-sheet table{border-collapse:collapse;border-spacing:0;width:100%;max-width:100%;display:table;table-layout:fixed;font-size:6.7px;line-height:1.03}
.fams-sheet col.c1{width:6.0%}.fams-sheet col.c2{width:6.0%}.fams-sheet col.c3{width:6.0%}.fams-sheet col.c4{width:6.0%}.fams-sheet col.c5{width:6.2%}.fams-sheet col.c6{width:3.0%}.fams-sheet col.c7{width:7.5%}.fams-sheet col.c8{width:7.5%}.fams-sheet col.c9{width:6.5%}.fams-sheet col.c10{width:7.0%}.fams-sheet col.c11{width:6.5%}.fams-sheet col.c12{width:7.0%}.fams-sheet col.c13{width:7.0%}.fams-sheet col.c14{width:9.0%}.fams-sheet col.c15{width:5.5%}.fams-sheet col.c16{width:7.3%}
.fams-sheet th{position:sticky;top:0;z-index:10;background:var(--navy)!important;color:#fff!important;padding:2.5px 2px;border:1px solid #174a82;text-align:center;font-size:6.4px;font-weight:800;line-height:1.0;white-space:normal;vertical-align:middle;box-sizing:border-box}
.fams-sheet td{padding:2px 2px;border:1px solid #d9e2eb;vertical-align:top;line-height:1.04;overflow-wrap:anywhere;word-break:break-word;box-sizing:border-box;background:#fff}
.fams-sheet tr:nth-child(even) td{background:#f7f9fc}.fams-sheet td[data-field="bill_amount"],.fams-sheet td[data-field="guarantee_amount"]{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.col-resizer{position:absolute;right:0;top:0;width:8px;height:100%;cursor:col-resize;opacity:.35;display:block}.row-resizer{display:block;height:2px;cursor:row-resize}
.sheet-sign{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;padding:12px 22px 16px}.sheet-sign .sign-box{min-height:42px;border-bottom:1px solid #52667d;text-align:center;padding:9px 5px 3px}.sheet-sign .sign-box div{min-height:16px}.sheet-sign small{display:block;margin-top:3px;font-weight:800;font-size:7px}.print-only{display:none}
@media(max-width:900px){.sheet-toolbar{overflow-x:visible;flex-wrap:wrap}.sheet-toolbar .meta{order:2;width:100%;margin-right:0}.fams-sheet table{font-size:6.3px}.fams-sheet th{font-size:6px}.fams-sheet td{padding:1.7px}.sheet-sign{gap:10px;padding:10px 10px 14px}}
@media print{
 html,body{overflow:visible!important;width:100%!important;min-width:0!important;background:#fff!important}
 .sheet-toolbar,.sheet-actions,.no-print{display:none!important}
 .fams-sheet-wrap{padding:0!important;margin:0!important;overflow:visible!important;width:100%!important;max-width:none!important}
 .fams-sheet{width:100%!important;max-width:none!important;min-width:0!important;border:0!important;box-shadow:none!important;overflow:visible!important}
 .fams-sheet table{width:100%!important;max-width:none!important;min-width:0!important;display:table!important;table-layout:fixed!important;border-collapse:collapse!important;font-size:6.7px!important;line-height:1.03!important}
 .fams-sheet th,.fams-sheet td{font-size:6.7px!important;padding:2px 2px!important;line-height:1.03!important;word-break:break-word!important;overflow-wrap:anywhere!important}
 .fams-sheet th{position:static!important;background:#082f63!important;color:#fff!important;white-space:normal!important}
 .fams-sheet thead{display:table-header-group!important}
 .fams-sheet tbody{display:table-row-group!important}
 .fams-sheet tr{break-inside:avoid!important;page-break-inside:avoid!important}
 .col-resizer,.row-resizer{display:none!important}
 .sheet-sign{gap:18px;padding:12px 18px 8px!important;break-inside:avoid;page-break-inside:avoid}
 .sheet-sign .sign-box{min-height:40px;padding:8px 4px 3px!important}.sheet-sign small{font-size:7px!important;margin-top:3px}
 @page{size:13in 8.5in landscape;margin:.45cm .22cm .22cm .22cm}
}</style><style>
/* V4.6 — editable print preview + Excel export */
.fams-sheet-wrap{padding:0!important;overflow:visible!important;max-width:100%!important;width:100%!important}
.fams-sheet{width:100%!important;min-width:0!important;max-width:100%!important;overflow:visible!important}
.fams-sheet table{width:100%!important;min-width:0!important;max-width:100%!important;table-layout:fixed!important}
.fams-sheet th,.fams-sheet td{padding:1.2px 1.6px!important;font-size:6.1px!important;line-height:1.02!important}
.fams-sheet th{position:sticky!important;top:0!important;padding-top:1.8px!important;padding-bottom:1.8px!important;background:#082f63!important;color:#fff!important}
.sheet-page .fams-sheet-wrap{overflow:visible!important;max-height:none!important}
.sheet-page .fams-sheet{min-width:0!important}
.sheet-page .fams-sheet table{min-width:0!important}
@media print{
 @page{size:8.5in 13in landscape;margin:.25in .18in .25in .18in}
 html,body,.sheet-page{width:auto!important;min-width:0!important;max-width:none!important;overflow:visible!important;background:#fff!important}
 .sheet-page .fams-sheet-wrap,.sheet-page .fams-sheet{width:100%!important;min-width:0!important;max-width:none!important;overflow:visible!important}
 .sheet-page .fams-sheet table{width:100%!important;min-width:0!important;max-width:none!important;table-layout:fixed!important}
 .sheet-page .fams-sheet th,.sheet-page .fams-sheet td{padding:1.1px 1.5px!important;font-size:6px!important;line-height:1.0!important}
 .sheet-page .fams-sheet th{position:static!important;background:#082f63!important;color:#fff!important;font-weight:800!important}
 .sheet-page .fams-sheet thead{display:table-header-group!important}
 .sheet-page .fams-sheet tbody{display:table-row-group!important}
 .sheet-page .fams-sheet tr{break-inside:avoid!important;page-break-inside:avoid!important}
 .sheet-page .sheet-sign{padding:8px 12px 4px!important;gap:14px!important}
 .sheet-page .sheet-sign .sign-box{min-height:28px!important;padding:4px 3px 2px!important}
}
</style>

<style>
.preview-modal{display:none;position:fixed;inset:0;z-index:1000;background:rgba(8,30,55,.58);padding:12px;box-sizing:border-box}
.preview-modal.open{display:block}
.preview-dialog{height:100%;max-width:1400px;margin:0 auto;background:#e9eef4;border-radius:10px;box-shadow:0 16px 50px rgba(0,0,0,.28);display:flex;flex-direction:column;overflow:hidden}
.preview-head{background:#fff;border-bottom:1px solid #cbd6e2;padding:8px 10px;display:flex;align-items:center;gap:12px;justify-content:space-between}
.preview-head strong{display:block;color:#082f63;font-size:12px}.preview-head span{display:block;color:#718398;font-size:8px;margin-top:2px}.preview-head-actions{display:flex;gap:6px;align-items:center}.preview-paper-wrap{flex:1;overflow:auto;padding:14px}.preview-paper{background:#fff;min-width:980px;width:100%;box-sizing:border-box;padding:7mm 5mm 5mm;box-shadow:0 2px 12px rgba(20,50,80,.15);color:#1d2d3d}.preview-paper table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:6.1px;line-height:1.02}.preview-paper th{background:#082f63;color:#fff;border:1px solid #174a82;padding:1.8px 1.6px;text-align:center;font-weight:800;line-height:1.0}.preview-paper td{border:1px solid #d9e2eb;padding:1.2px 1.6px;vertical-align:top;overflow-wrap:anywhere;word-break:break-word;background:#fff}.preview-paper tr:nth-child(even) td{background:#f7f9fc}.preview-paper td.editable-preview,.preview-paper .preview-sign-name{background:#fffdf0;outline:none}.preview-paper td.editable-preview:focus,.preview-paper .preview-sign-name:focus{box-shadow:inset 0 0 0 1.5px #6c9bc8;background:#fff}.preview-paper td.money{text-align:right;white-space:nowrap}.preview-paper .preview-sign{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;padding:12px 18px 3px}.preview-paper .preview-sign-box{min-height:42px;border-bottom:1px solid #52667d;text-align:center;padding:9px 5px 3px}.preview-paper .preview-sign-name{min-height:16px;padding:2px}.preview-paper .preview-sign small{display:block;margin-top:3px;font-weight:800;font-size:7px}.preview-paper .preview-note{font-size:7px;color:#718398;margin-top:5px}.preview-paper .preview-title{font-size:9px;font-weight:800;text-align:center;color:#082f63;margin:0 0 5px}.preview-paper .preview-meta{font-size:7px;text-align:right;color:#718398;margin-bottom:5px}
@media print{.preview-modal{display:none!important}}
</style>
<link rel="stylesheet" href="../core/shared-sheet.css?v=<?=e(APP_VERSION)?>">
</head><body class="sheet-page" style="--facility-accent: <?=e(facility_accent($activeFacility))?>;"><div class="sheet-toolbar"><h1>FAMS — Editable Sheet</h1><span class="meta"><?=e($q!==''?'Filtered records':'All displayed records')?> • <?=count($rows)?> row(s)</span><button class="secondary-btn" id="addRow" <?= $readOnly ? "hidden" : "" ?>>+ Add Row</button><button class="secondary-btn" id="reset" <?= $readOnly ? "hidden" : "" ?>>Reset</button><button class="secondary-btn" id="print">Print Edited Sheet</button><button class="primary-btn" id="pdf">Export Edited Sheet to PDF</button><button class="secondary-btn" id="excel">Export to Excel</button><button class="ghost-btn" onclick="window.close()">Close</button></div><div class="sheet-actions no-print"><span style="font-size:10px;color:#718398"><?= $readOnly ? "Read-only view. Editing controls are disabled for LPGMA Manager." : "Drag a column edge to resize. Edit any yellow cell. Changes are local to this report." ?></span></div><div class="fams-sheet-wrap"><div class="sheet-facility-banner" aria-label="Selected facility"><div class="sheet-facility-logo"><?=facility_logo_markup($activeFacility)?></div><div class="sheet-facility-copy"><span>FACILITY</span><strong><?=e(facility_info($activeFacility)['short'])?></strong><b><?=e(facility_info($activeFacility)['name'])?></b></div></div><div class="fams-sheet"><table id="sheet"><colgroup><?php for($i=1;$i<=16;$i++): ?><col class="c<?=$i?>"><?php endfor; ?></colgroup><thead><tr><?php $headers=['CONTROL NO.','DATE SUBMISSION','FIRST NAME','MIDDLE NAME','LAST NAME','EXT.','BARANGAY','MUNICIPALITY','PROVINCE','GUARDIAN','CONTACT','BILL AMOUNT','GUARANTEE AMOUNT','REMARKS','STATUS','RELEASE DATE']; foreach($headers as $i=>$h):?><th data-col="<?=$i?>"><?=e($h)?><span class="col-resizer"></span></th><?php endforeach;?></tr></thead><tbody id="body"></tbody></table><div class="sheet-sign"><div class="sign-box"><div contenteditable="<?= $readOnly ? "false" : "true" ?>" class="editable">MARY JANE C. MABAZZA</div><small>LPGMA STAFF</small></div><div class="sign-box"><div contenteditable="<?= $readOnly ? "false" : "true" ?>" class="editable">CRISTINA P. ASUNCION</div><small>LPGMA MANAGER</small></div><div class="sign-box"><div contenteditable="<?= $readOnly ? "false" : "true" ?>" class="editable">HON. ALLAN U. TY</div><small>LPGMA REPRESENTATIVE</small></div></div><div class="print-only">Generated from FAMS editable reporting workspace. Database records are not modified by this sheet.</div></div></div><div id="previewModal" class="preview-modal no-print" aria-hidden="true"><div class="preview-dialog"><div class="preview-head"><div><strong>Editable Print Preview</strong><span>Edit the cells or signatures here before printing.</span></div><div class="preview-head-actions"><button class="secondary-btn" id="previewReset">Refresh Preview</button><button class="primary-btn" id="previewPrint">Print Preview</button><button class="ghost-btn" id="previewClose">Close</button></div></div><div class="preview-paper-wrap"><div class="preview-paper" id="previewPaper"></div></div></div></div><script>
const READ_ONLY=<?= $readOnly ? "true" : "false" ?>;
const DATA=<?=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const FACILITY=<?=json_encode($activeFacility)?>;const COLKEY='fams.master.sheet.cols.v47.'+encodeURIComponent(FACILITY)+'.'+encodeURIComponent(<?=json_encode($q)?>)+'.'+encodeURIComponent(<?=json_encode($status)?>);const KEY='fams.master.sheet.v47.'+encodeURIComponent(FACILITY)+'.'+encodeURIComponent(<?=json_encode($q)?>)+'.'+encodeURIComponent(<?=json_encode($status)?>);const body=document.getElementById('body');
const fields=['control_number','date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','bill_amount','guarantee_amount','remarks','status','release_date'];
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));} function num(v){const n=parseFloat(String(v??'').replace(/[^0-9.-]/g,''));return Number.isFinite(n)?n:0;} function fmtMoney(v){return num(v).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function read(){return [...body.querySelectorAll('tr')].map(tr=>{const o={};fields.forEach(f=>o[f]=tr.querySelector('[data-field="'+f+'"]')?.innerText.trim()||'');return o;});}
function naturalColumnWidths(){
 const table=document.getElementById('sheet'); if(!table)return [];
 const heads=[...table.querySelectorAll('thead th')], rows=[...table.querySelectorAll('tbody tr')];
 const weights=heads.map((th,i)=>{let max=String(th.innerText||'').replace(/\s+/g,' ').trim().length; rows.forEach(tr=>{const c=tr.children[i]; if(c) max=Math.max(max,String(c.innerText||'').replace(/\s+/g,' ').trim().length);}); return Math.max(max,4);});
 const total=weights.reduce((a,b)=>a+b,0)||1; return weights.map(w=>w/total*100);
}
function applyColumnWidths(widths){
 const table=document.getElementById('sheet'); if(!table||!Array.isArray(widths)||widths.length!==16)return;
 const cols=[...table.querySelectorAll('col')], heads=[...table.querySelectorAll('thead th')], rows=[...table.querySelectorAll('tbody tr')];
 cols.forEach((c,i)=>c.style.width=widths[i].toFixed(4)+'%');
 heads.forEach((th,i)=>th.style.width=widths[i].toFixed(4)+'%');
 rows.forEach(tr=>[...tr.children].forEach((td,i)=>{if(widths[i]!=null)td.style.width=widths[i].toFixed(4)+'%';}));
}
function getColumnWidths(){return [...document.querySelectorAll('#sheet col')].map(c=>parseFloat(c.style.width)||0);}
function saveColumnWidths(){try{localStorage.setItem(COLKEY,JSON.stringify(getColumnWidths()));}catch(e){}}
function fitColumns(){
 const table=document.getElementById('sheet'); if(!table)return;
 let saved=null; try{saved=JSON.parse(localStorage.getItem(COLKEY)||'null')}catch(e){}
 if(Array.isArray(saved)&&saved.length===16&&saved.every(v=>Number.isFinite(v)&&v>0)){applyColumnWidths(saved);return;}
 applyColumnWidths(naturalColumnWidths()); saveColumnWidths();
}
function rowHtml(r){return '<tr>'+fields.map(f=>'<td contenteditable="'+(!READ_ONLY)+'" class="editable" data-field="'+f+'">'+esc((f==='bill_amount'||f==='guarantee_amount')?fmtMoney(r[f]):r[f])+'</td>').join('')+'</tr>';}
function render(rows){body.innerHTML=rows.map(rowHtml).join('');body.querySelectorAll('.editable').forEach(c=>c.addEventListener('input',()=>{save();if(!localStorage.getItem(COLKEY))fitColumns();}));body.querySelectorAll('tr').forEach(tr=>{const last=tr.lastElementChild; if(last && !last.querySelector('.row-resizer')) last.insertAdjacentHTML('beforeend','<span class="row-resizer"></span>');});}
function save(){if(READ_ONLY)return;localStorage.setItem(KEY,JSON.stringify(read()));}
let saved=null;if(!READ_ONLY){try{saved=JSON.parse(localStorage.getItem(KEY)||'null')}catch(e){}} render(Array.isArray(saved)?saved:DATA.rows); fitColumns();
function syncPreviewToMain(){const table=document.querySelector('#previewPaper table');if(!table)return;const rows=[...table.querySelectorAll('tbody tr')];body.innerHTML='';rows.forEach(pr=>{const tr=document.createElement('tr');fields.forEach((f,i)=>{const td=document.createElement('td');td.contentEditable='true';td.className='editable';td.dataset.field=f;td.innerText=pr.children[i]?.innerText.trim()||'';tr.appendChild(td);});body.appendChild(tr);});body.querySelectorAll('.editable').forEach(c=>c.addEventListener('input',()=>{save();}));const names=[...document.querySelectorAll('#previewPaper .preview-sign-name')];document.querySelectorAll('.sheet-sign .sign-box .editable').forEach((el,i)=>{if(names[i])el.innerText=names[i].innerText;});render(read());save();fitColumns();}
function buildPreview(){save();fitColumns();const paper=document.getElementById('previewPaper');const mainTable=document.getElementById('sheet');const clone=mainTable.cloneNode(true);clone.removeAttribute('id');clone.querySelectorAll('.col-resizer,.row-resizer').forEach(el=>el.remove());clone.querySelectorAll('td').forEach(td=>{td.classList.add('editable-preview');td.contentEditable='true';if(td.dataset.field==='bill_amount'||td.dataset.field==='guarantee_amount')td.classList.add('money');});paper.innerHTML='<div class="preview-title">FAMS — Editable Print Preview</div><div class="preview-meta">Generated <?=e(date('Y-m-d H:i'))?> • '+body.querySelectorAll('tr').length+' row(s)</div>';paper.appendChild(clone);const sign=document.createElement('div');sign.className='preview-sign';const mainSigns=[...document.querySelectorAll('.sheet-sign .sign-box')];mainSigns.forEach(box=>{const b=document.createElement('div');b.className='preview-sign-box';const n=document.createElement('div');n.className='preview-sign-name';n.contentEditable='true';n.innerText=box.querySelector('.editable')?.innerText.trim()||'';const small=document.createElement('small');small.innerText=box.querySelector('small')?.innerText.trim()||'';b.append(n,small);sign.appendChild(b);});paper.appendChild(sign);const note=document.createElement('div');note.className='preview-note';note.innerText='Edits made in this preview are applied to the editable sheet when you print or close the preview.';paper.appendChild(note);document.getElementById('previewModal').classList.add('open');document.getElementById('previewModal').setAttribute('aria-hidden','false');}
if(READ_ONLY){document.querySelectorAll('#sheet .editable, .sheet-sign .editable').forEach(el=>el.contentEditable='false');document.querySelectorAll('.col-resizer,.row-resizer').forEach(el=>el.style.display='none');}
document.getElementById('addRow').onclick=()=>{if(READ_ONLY)return;body.insertAdjacentHTML('beforeend',rowHtml({}));const tr=body.lastElementChild;tr.style.height='38px';tr.lastElementChild.insertAdjacentHTML('beforeend','<span class="row-resizer"></span>');tr.querySelector('[data-field="patient_first_name"]').focus();tr.querySelectorAll('.editable').forEach(c=>c.addEventListener('input',save));save();fitColumns();};
document.getElementById('reset').onclick=()=>{if(READ_ONLY)return;if(confirm('Reset this sheet to FAMS database values and restore automatic column widths?')){localStorage.removeItem(KEY);localStorage.removeItem(COLKEY);render(DATA.rows);fitColumns();}};
document.getElementById('preview').onclick=()=>buildPreview();
document.getElementById('previewClose').onclick=()=>{syncPreviewToMain();document.getElementById('previewModal').classList.remove('open');document.getElementById('previewModal').setAttribute('aria-hidden','true');};
document.getElementById('previewReset').onclick=()=>buildPreview();
document.getElementById('previewPrint').onclick=async()=>{if(!await auditSheetExport('pdf'))return;syncPreviewToMain();document.getElementById('previewModal').classList.remove('open');document.getElementById('previewModal').setAttribute('aria-hidden','true');save();fitColumns();window.print();};
document.getElementById('print').onclick=async()=>{if(!await auditSheetExport('print'))return;save();fitColumns();window.print()};
document.getElementById('pdf').onclick=async()=>{if(!await auditSheetExport('pdf'))return;save();fitColumns();window.print()};
document.getElementById('excel').onclick=async()=>{if(!await auditSheetExport('xls'))return;save();exportExcel();};
window.addEventListener('keydown',e=>{if(e.key==='Escape'&&document.getElementById('previewModal').classList.contains('open'))document.getElementById('previewClose').click();});
const exportAuditCsrf=<?=json_encode(csrf_token())?>;
async function auditSheetExport(format){try{const payload=new URLSearchParams({csrf_token:exportAuditCsrf,format,row_count:String(document.querySelectorAll('#body tr').length)});const response=await fetch('export_event.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:payload,credentials:'same-origin'});if(!response.ok)throw new Error('Export authorization or audit request was denied.');return true;}catch(e){alert(e.message);return false;}}
function exportExcel(){const rows=read();const headers=['CONTROL NO.','DATE SUBMISSION','FIRST NAME','MIDDLE NAME','LAST NAME','EXT.','BARANGAY','MUNICIPALITY','PROVINCE','GUARDIAN','CONTACT','BILL AMOUNT','GUARANTEE AMOUNT','REMARKS','STATUS','RELEASE DATE'];const safeText=v=>{const value=String(v??'');return /^[\x00-\x20]*[=+\-@]/.test(value)?"'"+value:value};const escXml=v=>String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');let html='<!DOCTYPE html><html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"><style>table{border-collapse:collapse}th{background:#082f63;color:#fff;font-weight:bold;border:1px solid #777;padding:5px}td{border:1px solid #aaa;padding:4px;vertical-align:top}.money{text-align:right;mso-number-format:"#,##0.00"}.text{mso-number-format:"\@"}</style></head><body><table><thead><tr>'+headers.map(h=>'<th>'+escXml(h)+'</th>').join('')+'</tr></thead><tbody>'+rows.map(r=>'<tr>'+fields.map(f=>{let v=r[f]??'';let cls='';if(f==='bill_amount'||f==='guarantee_amount'){v=num(v).toFixed(2);cls='money';}else {if(f==='control_number'||f==='contact_number')cls='text';v=safeText(v);}return '<td class="'+cls+'">'+escXml(v)+'</td>';}).join('')+'</tr>').join('')+'</tbody></table></body></html>';const blob=new Blob([html],{type:'application/vnd.ms-excel;charset=utf-8'});const a=document.createElement('a');const stamp=new Date().toISOString().slice(0,10);a.href=URL.createObjectURL(blob);a.download='FAMS_Editable_Sheet_'+stamp+'.xls';document.body.appendChild(a);a.click();setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove();},1000);}
body.addEventListener('pointerdown',e=>{if(!e.target.classList.contains('row-resizer'))return;const tr=e.target.closest('tr'),startY=e.clientY,startH=tr.offsetHeight;const move=ev=>tr.style.height=Math.max(30,startH+ev.clientY-startY)+'px';const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up);save();};document.addEventListener('pointermove',move);document.addEventListener('pointerup',up,{once:true});});
document.querySelectorAll('.col-resizer').forEach(r=>{r.addEventListener('pointerdown',e=>{
 e.preventDefault(); e.stopPropagation();
 const th=r.parentElement, idx=th.cellIndex, table=document.getElementById('sheet');
 const startX=e.clientX, tableW=table.getBoundingClientRect().width||1;
 let widths=getColumnWidths(); if(widths.length!==16||widths.some(v=>!v)){widths=naturalColumnWidths();}
 const startLeft=widths[idx], startRight=idx<widths.length-1?widths[idx+1]:null;
 const minPct=Math.max(2.2,45/tableW*100);
 const move=ev=>{
   const delta=(ev.clientX-startX)/tableW*100;
   let next=Math.max(minPct,startLeft+delta);
   if(idx<widths.length-1){
      const totalPair=startLeft+startRight;
      next=Math.min(totalPair-minPct,next);
      widths[idx]=next; widths[idx+1]=totalPair-next;
   } else {
      next=Math.max(minPct,Math.min(100-minPct*(widths.length-1),next));
      const diff=next-startLeft; const others=widths.length-1; const available=100-next;
      widths[idx]=next;
      const oldOther=100-startLeft; const scale=available/oldOther;
      for(let i=0;i<idx;i++) widths[i]*=scale;
   }
   applyColumnWidths(widths);
 };
 const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up);saveColumnWidths();};
 document.addEventListener('pointermove',move); document.addEventListener('pointerup',up,{once:true});
});});
</script></body></html>
