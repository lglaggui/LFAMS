/* FAMS Region II v0.0039 — strict, ordered adaptive header fitting. */
(function(){
 'use strict';
 const compactMoney=n=>{n=Number(n);if(!Number.isFinite(n))return '';const sign=n<0?'-':'';n=Math.abs(n);const compact=(v,digits)=>String(Math.floor((v+Number.EPSILON)*Math.pow(10,digits))/Math.pow(10,digits));if(n>=1e6){const v=n/1e6;return sign+'₱'+compact(v,v>=10?0:1)+'M';}if(n>=1e3){const v=n/1e3;return sign+'₱'+compact(v,v>=10?0:1)+'K';}return sign+'₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});};
 function init(){document.querySelectorAll('.topbar.fams-branded-topbar').forEach(header=>{
  const left=header.querySelector('.topbar-left'),fund=header.querySelector('.header-fund-summary'),right=header.querySelector('.topbar-right');
  let running=false;
  const update=()=>{if(running)return;running=true;
   header.classList.remove('header-identity-compact','header-fund-compact','header-fit-tight','header-fit-ultra','header-summary-expanded','header-summary-collapsed');
   header.querySelectorAll('.header-fund-card strong[data-amount]').forEach(el=>{if(!el.dataset.originalText)el.dataset.originalText=el.textContent;el.textContent=el.dataset.originalText;});
   const available=header.clientWidth||window.innerWidth;
   header.classList.add(available >= 1120 ? 'header-summary-expanded' : 'header-summary-collapsed');
   if(available < 1120) { header.classList.add('header-fund-compact','header-fit-tight'); header.querySelectorAll('.header-fund-card strong[data-amount]').forEach(el=>{el.textContent=compactMoney(el.dataset.amount);}); }
   // Measure intrinsic group widths, not flex-shrunk widths, to avoid premature truncation.
   const intrinsic=e=>{if(!e)return 0;const r=e.getBoundingClientRect();return Math.max(e.scrollWidth,r.width);};
   const groupsWidth=()=>intrinsic(left)+intrinsic(fund)+intrinsic(right)+24;
   // Nothing is shortened while all full-size groups fit.
   if(groupsWidth()>available+2)header.classList.add('header-identity-compact');
   // Only then shorten fund labels; amounts remain full length at this stage.
   if(groupsWidth()>available+2)header.classList.add('header-fund-compact','header-fit-tight');
   // Only as a last resort abbreviate the amounts.
   if(groupsWidth()>available+2){header.classList.add('header-fit-ultra');header.querySelectorAll('.header-fund-card strong[data-amount]').forEach(el=>{el.textContent=compactMoney(el.dataset.amount);});}
   running=false;
  };
  if(window.ResizeObserver){const ro=new ResizeObserver(update);ro.observe(header);[left,fund,right].filter(Boolean).forEach(e=>ro.observe(e));}
  window.addEventListener('resize',update,{passive:true});if(document.fonts?.ready)document.fonts.ready.then(update).catch(()=>{});update();
 });}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
