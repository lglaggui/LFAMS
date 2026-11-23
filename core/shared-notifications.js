/* Shared notification center for AICS and MAIFIP. */
/* Version 0.926 — resilient notifications + re-bindable shell controls. */
(function(){
  function initNotifications(){
    const bell=document.getElementById('notificationBell'), pop=document.getElementById('notificationPopover');
    if(!bell||!pop) return;
    if(bell.dataset.notificationBound==='1') return;
    bell.dataset.notificationBound='1';
    const badge=document.getElementById('notificationBadge'), list=document.getElementById('notificationList'), readAll=document.getElementById('notificationReadAll'), deleteAll=document.getElementById('notificationDeleteAll');
    const escN=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    const csrf=()=>bell.dataset.csrf||document.querySelector('input[name="csrf_token"]')?.value||'';
    const position=()=>{
      if(pop.hidden)return;
      const r=bell.getBoundingClientRect(), edge=10, gap=8;
      const width=Math.min(560,Math.max(260,window.innerWidth-edge*2));
      pop.style.width=width+'px';
      const w=pop.offsetWidth||width, h=pop.offsetHeight||300;
      let left=r.right-w; if(left<edge)left=edge; if(left+w>window.innerWidth-edge)left=window.innerWidth-w-edge;
      let top=r.bottom+gap; if(top+h>window.innerHeight-edge){const above=r.top-h-gap;top=above>=edge?above:Math.max(edge,window.innerHeight-h-edge);}
      pop.style.left=Math.round(Math.max(edge,left))+'px'; pop.style.top=Math.round(Math.max(edge,top))+'px';
    };
    pop.classList.add('fams-notification-portal');
    if(pop.parentElement!==document.body) document.body.appendChild(pop);
    pop.hidden=true;
    const load=async()=>{try{
      const r=await fetch('notifications_api.php?action=list',{cache:'no-store',credentials:'same-origin'}); const d=await r.json();
      const n=Number(d.unread||0); const chatUnread=Number(d.chat_unread||0);
      if(badge){badge.textContent=n>99?'99+':n;badge.hidden=n===0;}
      bell.classList.toggle('has-chat-unread',chatUnread>0);
      bell.setAttribute('aria-label',chatUnread>0?`Notifications, ${chatUnread>99?'more than 99':chatUnread} unread chat message${chatUnread===1?'':'s'}`:(n>0?`Notifications, ${n>99?'more than 99':n} unread notification${n===1?'':'s'}`:'Notifications'));
      document.querySelectorAll('[data-notif-category="chat"]').forEach(b=>{const c=chatUnread;b.textContent=c>99?'99+':c;b.hidden=c===0;});
      document.querySelectorAll('[data-notif-category="ticket"]').forEach(b=>{const c=Number(d.pending_tickets||0);b.textContent=c>99?'99+':c;b.hidden=c===0;});
      document.querySelectorAll('[data-notif-category="account"]').forEach(b=>{const c=Number(d.pending_accounts||0);b.textContent=c>99?'99+':c;b.hidden=c===0;});
      if(list)list.innerHTML=(d.items||[]).map(x=>`<div class="notification-item ${x.is_read?'':'unread'}" data-notification-id="${Number(x.id)||0}" data-link="${escN(x.link||'#')}"><button type="button" class="notification-open" data-notification-open><strong>${escN(x.title)}</strong><span>${escN(x.message)}</span><small>${escN(x.created_at)}</small></button><button type="button" class="notification-remove" data-notification-delete aria-label="Remove notification" title="Remove notification">×</button></div>`).join('')||'<div class="notification-empty">No notifications.</div>';
      if(!pop.hidden)requestAnimationFrame(position);
    }catch(e){ if(list)list.innerHTML='<div class="notification-empty">Notifications are temporarily unavailable.</div>'; }};
    bell.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();pop.hidden=!pop.hidden;if(!pop.hidden){position();load();}});
    list?.addEventListener('click',async e=>{
      const remove=e.target.closest('[data-notification-delete]');
      if(remove){e.preventDefault();e.stopPropagation();const item=remove.closest('[data-notification-id]');if(!item)return;const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','delete');fd.append('id',item.dataset.notificationId);await fetch('notifications_api.php',{method:'POST',credentials:'same-origin',body:fd});await load();return;}
      const opener=e.target.closest('[data-notification-open]'); if(!opener)return; const item=opener.closest('[data-notification-id]');if(!item)return;
      const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','read');fd.append('id',item.dataset.notificationId);await fetch('notifications_api.php',{method:'POST',credentials:'same-origin',body:fd});const link=item.dataset.link;if(link&&link!=='#')window.location.href=link;else load();
    });
    readAll?.addEventListener('click',async()=>{const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','read_all');await fetch('notifications_api.php',{method:'POST',credentials:'same-origin',body:fd});load();});
    deleteAll?.addEventListener('click',async()=>{if(!confirm('Remove all notifications from your notification panel?'))return;const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','delete_all');await fetch('notifications_api.php',{method:'POST',credentials:'same-origin',body:fd});load();});
    document.addEventListener('click',e=>{if(!pop.hidden&&!pop.contains(e.target)&&e.target!==bell)pop.hidden=true;});
    window.addEventListener('resize',position);window.addEventListener('scroll',position,true);load();setInterval(load,5000);
  }
  window.FAMSInitNotifications=initNotifications;
  document.addEventListener('DOMContentLoaded',initNotifications);
  document.addEventListener('fams:page-replaced',initNotifications);
})();


