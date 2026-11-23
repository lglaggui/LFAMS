/* FAMS v0.0175 — mark ordinary data tables for the shared table framework. */
(function(){
  'use strict';
  const excluded = table => table.matches('.sheet-table,.doh-excel-table,.summary,#sheet,#reportTable,#sheetTable') || !!table.closest('.sheet-wrap,.grid-scroll,.fams-sheet,.doh-excel-editor,[data-sheet-editor]');
  function initialize(root){
    const tables=[];
    if(root instanceof Element && root.matches('table')) tables.push(root);
    if(root && root.querySelectorAll) tables.push(...root.querySelectorAll('table'));
    tables.forEach(table=>{
      if(excluded(table)) return;
      table.classList.add('fams-standard-table');
      table.setAttribute('data-fams-standard-table','1');
      const wrapper=table.closest('.masterlist-table-wrap,.table-wrap,.table-responsive,.fams-data-list-table-wrap');
      if(wrapper){ wrapper.classList.add('fams-data-list-table-wrap'); if(!wrapper.hasAttribute('tabindex')) wrapper.setAttribute('tabindex','0'); }
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',()=>initialize(document),{once:true}); else initialize(document);
  if('MutationObserver' in window){
    const observer=new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(node=>{if(node.nodeType===1) initialize(node);} )));
    observer.observe(document.documentElement,{childList:true,subtree:true});
  }
})();
