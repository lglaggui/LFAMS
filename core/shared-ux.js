/* FAMS v0.0177 — shared accessibility, form safety and user-facing request feedback. */
(function(){
  'use strict';
  window.FAMS_DEBOUNCE_MS = 260;
  const reducedMotion = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function toastHost(){
    let host=document.getElementById('famsUxToastStack');
    if(!host){host=document.createElement('div');host.id='famsUxToastStack';host.className='fams-ux-toast-stack';host.setAttribute('aria-label','Notifications');host.setAttribute('aria-live','polite');host.setAttribute('aria-relevant','additions text');document.body.appendChild(host);}
    return host;
  }
  window.FAMSShowToast=function(message,type='info',duration=5600){
    const text=String(message||'').trim(); if(!text)return;
    const host=toastHost(), item=document.createElement('div');
    const kind=['success','warning','error'].includes(type)?type:'info';
    item.className='fams-ux-toast is-'+kind; item.setAttribute('role',kind==='error'?'alert':'status');
    const mark=document.createElement('span');mark.className='fams-ux-toast-mark';mark.setAttribute('aria-hidden','true');mark.textContent=kind==='success'?'✓':kind==='warning'?'!':kind==='error'?'×':'i';
    const copy=document.createElement('span');copy.className='fams-ux-toast-copy';copy.textContent=text;
    const close=document.createElement('button');close.type='button';close.className='fams-ux-toast-close';close.setAttribute('aria-label','Dismiss notification');close.textContent='×';
    item.append(mark,copy,close);host.appendChild(item);
    let timer=0;const remove=()=>{clearTimeout(timer);item.classList.add('is-leaving');window.setTimeout(()=>item.remove(),reducedMotion()?0:180);};
    close.addEventListener('click',remove,{once:true});if(duration>0)timer=window.setTimeout(remove,duration);
    return item;
  };
  // A skip link gives keyboard users a direct route to the main content.
  function installSkipLink(){
    const main=document.getElementById('lfamsMainContent')||document.querySelector('main');
    if(!main||document.getElementById('famsSkipToContent'))return;
    if(!main.id)main.id='famsMainContent';
    const link=document.createElement('a');link.id='famsSkipToContent';link.className='fams-skip-link';link.href='#'+main.id;link.textContent='Skip to main content';
    document.body.insertBefore(link,document.body.firstChild);
    link.addEventListener('click',()=>{main.setAttribute('tabindex','-1');main.focus({preventScroll:true});});
  }
  // Apply native confirm prompts to actions explicitly marked as destructive.
  document.addEventListener('submit',event=>{
    const form=event.target;if(!(form instanceof HTMLFormElement))return;
    const message=form.dataset.confirm;
    if(message && form.dataset.famsConfirmAccepted!=='1'){
      if(!window.confirm(message)){event.preventDefault();event.stopImmediatePropagation();return;}
      form.dataset.famsConfirmAccepted='1';
    }
  },true);
  document.addEventListener('click',event=>{
    const link=event.target.closest?.('a[data-confirm]');if(!link)return;
    if(!window.confirm(link.dataset.confirm||'Are you sure you want to continue?')){event.preventDefault();event.stopImmediatePropagation();}
  },true);
  // Warn only for edited POST forms; GET/search, chat, filters and explicitly excluded forms stay quiet.
  const dirtyForms=new Set();
  const excluded=form=>form.method.toLowerCase()!=='post'||form.matches('[data-no-unsaved-warning],.search-form,.masterlist-filterbar,.chat-form,#chatForm,[data-status-form],.inline-status-form')||!!form.closest('[data-sheet-editor],.fams-sheet,.doh-excel-editor');
  function bindForm(form){
    if(!(form instanceof HTMLFormElement)||form.dataset.famsDirtyBound==='1'||excluded(form))return;
    if(!form.querySelector('input:not([type="hidden"]),select,textarea'))return;
    form.dataset.famsDirtyBound='1';
    const mark=()=>{if(form.dataset.famsSubmitting==='1')return;form.dataset.famsDirty='1';dirtyForms.add(form);};
    form.addEventListener('input',mark);form.addEventListener('change',mark);
    form.addEventListener('reset',()=>{delete form.dataset.famsDirty;delete form.dataset.famsSubmitting;dirtyForms.delete(form);});
    form.addEventListener('submit',()=>{form.dataset.famsSubmitting='1';delete form.dataset.famsDirty;dirtyForms.delete(form);},true);
  }
  function bindAll(scope=document){if(scope instanceof HTMLFormElement)bindForm(scope);scope.querySelectorAll?.('form').forEach(bindForm);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{installSkipLink();bindAll();},{once:true});else{installSkipLink();bindAll();}
  document.addEventListener('fams:page-replaced',()=>{bindAll();});
  // AJAX/partial navigation does not trigger beforeunload, so guard ordinary in-app links too.
  document.addEventListener('click',event=>{
    if(!dirtyForms.size||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
    const link=event.target.closest?.('a[href]');if(!link||link.target==='_blank'||link.hasAttribute('download')||link.hasAttribute('data-fams-popup'))return;
    const href=link.getAttribute('href')||'';if(!href||href.startsWith('#')||/^(?:javascript:|mailto:|tel:)/i.test(href))return;
    let destination;try{destination=new URL(href,location.href);}catch(_e){return;}
    if(destination.href===location.href)return;
    if(!window.confirm('You have unsaved changes. Leave this page and discard them?')){event.preventDefault();event.stopImmediatePropagation();return;}
    dirtyForms.clear();document.querySelectorAll('form[data-fams-dirty="1"]').forEach(form=>delete form.dataset.famsDirty);
  },true);
  if('MutationObserver' in window){const observer=new MutationObserver(records=>records.forEach(record=>record.addedNodes.forEach(node=>{if(node.nodeType===1)bindAll(node);})));observer.observe(document.documentElement,{childList:true,subtree:true});}
  window.addEventListener('beforeunload',event=>{
    for(const form of Array.from(dirtyForms)){if(!form.isConnected||form.dataset.famsSubmitting==='1'||form.dataset.famsDirty!=='1')dirtyForms.delete(form);}
    if(!dirtyForms.size)return;
    event.preventDefault();event.returnValue='';return '';
  });
})();
