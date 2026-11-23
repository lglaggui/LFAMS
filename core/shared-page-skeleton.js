/* FAMS v0.0087 — one shared page-specific loading skeleton builder for MAIFIP and AICS. */
(function(){
  function esc(t){return String(t||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
  function line(w,cls=''){return '<span class="fams-skel-line '+cls+'" style="--skel-w:'+w+'"></span>'; }
  function rows(n=8){return '<div class="fams-skel-rows">'+Array.from({length:n},(_,i)=>'<i class="fams-skel-row" style="--skel-delay:'+((i%5)*.05)+'s"></i>').join('')+'</div>'; }
  function table(){return '<div class="fams-skel-table-grid"><div class="fams-skel-table-head">'+Array.from({length:6},()=>'<i></i>').join('')+'</div>'+Array.from({length:7},()=>'<div class="fams-skel-table-row">'+Array.from({length:6},()=>'<i></i>').join('')+'</div>').join('')+'</div>'; }
  function cards(n=4){return '<div class="fams-skel-cards">'+Array.from({length:n},()=>'<span class="fams-skel-card"><b></b><i></i></span>').join('')+'</div>'; }
  function build(path){
    const file=(new URL(path,location.href)).pathname.split('/').pop().toLowerCase(); let title='Loading page',body='';
    if(file==='chat.php'){title='Chat';body='<div class="fams-skel-chat"><aside>'+line('58%')+line('42%')+rows(6)+'</aside><section><div class="fams-skel-chat-head">'+line('34%')+'</div><div class="fams-skel-messages">'+Array.from({length:6},(_,i)=>'<i style="--skel-w:'+(35+(i%3)*12)+'%;--skel-side:'+(i%2?'auto':'0')+'"></i>').join('')+'</div><div class="fams-skel-composer">'+line('100%')+'</div></section></div>'; }
    else if(['records.php','masterlist.php','masterlist_sheet.php','fams_sheet_editor.php'].includes(file)){title='FAMS Masterlist';body=cards(3)+'<section class="fams-skel-panel">'+line('28%')+line('18%')+table()+'</section>'; }
    else if(file==='dashboard.php'){title='Dashboard';body=cards(4)+'<section class="fams-skel-panel fams-skel-chart">'+line('25%')+'<div></div></section><section class="fams-skel-panel">'+line('32%')+rows(5)+'</section>'; }
    else if(file==='users.php'||file==='account.php'){title='Accounts Management';body=cards(2)+'<section class="fams-skel-panel">'+line('30%')+table()+'</section>'; }
    else if(file==='help.php'){title='Help & Support';body='<section class="fams-skel-panel">'+line('32%')+line('72%')+'<div class="fams-skel-help-grid">'+Array.from({length:6},()=>'<i></i>').join('')+'</div></section>'; }
    else if(['monthly_sheet_editor.php','monthly_report.php'].includes(file)){title='Monthly Disbursement';body=cards(3)+'<section class="fams-skel-panel">'+line('24%')+table()+'</section>'; }
    else if(file.includes('report')||file.includes('monthly')){title='Report';body=cards(3)+'<section class="fams-skel-panel">'+line('26%')+table()+'</section>'; }
    else if(['tickets.php','ticket_review.php','ticket_create.php'].includes(file)){title='Ticket Management';body=cards(2)+'<section class="fams-skel-panel">'+line('30%')+table()+'</section>'; }
    else if(['fund_management.php','fund.php'].includes(file)){title='Fund Management';body=cards(4)+'<section class="fams-skel-panel">'+line('30%')+rows(7)+'</section>'; }
    else if(file==='offices.php'){title='Facility / Office Management';body=cards(2)+'<section class="fams-skel-panel">'+line('30%')+table()+'</section>'; }
    else if(file==='logs.php'){title='Action Logs';body=cards(2)+'<section class="fams-skel-panel">'+line('30%')+table()+'</section>'; }
    else {body=cards(3)+'<section class="fams-skel-panel">'+line('30%')+line('62%')+rows(8)+'</section>'; }
    return '<div class="fams-page-skeleton is-page-specific" data-skeleton-page="'+esc(file)+'" aria-hidden="true"><div class="fams-skel-toolbar"><span class="fams-skel-chip"></span><strong>'+esc(title)+'</strong><span class="fams-skel-action"></span><span class="fams-skel-action short"></span></div>'+body+'</div>';
  }
  window.FAMSBuildPageSkeleton=build;
  document.addEventListener('click',function(e){
    const a=e.target.closest&&e.target.closest('.sidebar nav a[href],.nav a[href]'); if(!a||a.target==='_blank'||a.hasAttribute('data-fams-popup')||a.hasAttribute('data-ajax-navigation')||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
    const url=new URL(a.href,location.href); if(url.origin!==location.origin||url.pathname===location.pathname&&url.search===location.search)return;
    const view=document.getElementById('pageView'); if(!view)return;
    view.innerHTML=build(url.href);view.classList.add('fams-loading-page-view');view.setAttribute('aria-busy','true');
    const title=view.querySelector('.fams-skel-toolbar strong')?.textContent; if(title){const h=document.querySelector('.topbar-title h1');if(h)h.textContent=title;document.title=title+' | LPGMA';}
  },true);
})();
