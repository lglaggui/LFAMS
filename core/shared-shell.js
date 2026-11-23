/* FAMS v0.0084 — shared shell interactions; profile sizing includes animated logout-label geometry. */
(function(){
  'use strict';
  const sidebar=document.getElementById('famsSidebar');
  const toggle=document.getElementById('sidebarMobileToggle');
  const close=document.getElementById('sidebarMobileClose');
  const backdrop=document.getElementById('sidebarMobileBackdrop');
  const layout=document.querySelector('.fams-shared-layout');
  const pageView=document.querySelector('[data-page-view]');
  // Reserve the real fixed-header height, not a guessed desktop/mobile constant.
  const mainContent=document.getElementById('lfamsMainContent');
  const topbar=mainContent?.querySelector(':scope > .topbar.fams-branded-topbar');
  function syncHeaderReservation(){
    if(!mainContent||!topbar)return;
    const height=Math.ceil(topbar.getBoundingClientRect().height);
    if(height>0)mainContent.style.setProperty('--fams-header-reserved-height',height+'px');
  }
  if(topbar&&mainContent){
    syncHeaderReservation();
    if('ResizeObserver' in window){
      const headerObserver=new ResizeObserver(syncHeaderReservation);
      headerObserver.observe(topbar);
    }
    window.addEventListener('resize',syncHeaderReservation,{passive:true});
    window.addEventListener('load',syncHeaderReservation,{once:true});
    if(document.fonts?.ready)document.fonts.ready.then(syncHeaderReservation).catch(()=>{});
    topbar.querySelectorAll('img').forEach(img=>img.addEventListener('load',syncHeaderReservation,{once:true}));
  }
  const navScroll=sidebar?.querySelector('.sidebar-nav-scroll')||sidebar?.querySelector('nav');
  const userKey=layout?.dataset.famsUserKey||'session';
  const stateKey='fams-shell-state-v1:'+userKey;
  const restoreKey='fams-shell-restore-v1:'+userKey;
  const activePageKey='fams-shell-active-page-v1:'+userKey;
  const mobile=()=>window.matchMedia('(max-width: 767px)').matches;
  const onSheet=document.body.classList.contains('sheet-page')||document.body.classList.contains('fams-popup-page');

  function setOpen(open){
    if(!sidebar||!toggle||!backdrop)return;
    if(!mobile())open=false;
    document.body.classList.toggle('sidebar-menu-open',open);
    sidebar.classList.toggle('open',open);
    toggle.setAttribute('aria-expanded',open?'true':'false');
    backdrop.classList.toggle('visible',open);
    backdrop.tabIndex=open?0:-1;
  }
  toggle?.addEventListener('click',()=>setOpen(true));
  close?.addEventListener('click',()=>setOpen(false));
  backdrop?.addEventListener('click',()=>setOpen(false));
  sidebar?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{if(mobile())setOpen(false)}));
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){setOpen(false);closeGlobalPopup();}});
  window.addEventListener('resize',()=>{if(!mobile())setOpen(false)});

  // Sidebar profile card opens the workspace's account-management destination.
  document.querySelectorAll('.fams-shared-profile-card[data-account-href]').forEach(card=>{
    if(card.dataset.accountLinkBound==='1')return; card.dataset.accountLinkBound='1';
    const go=e=>{if(e.target.closest('.fams-profile-logout,a,button'))return;const href=card.dataset.accountHref;if(href)window.location.href=href;};
    card.addEventListener('click',go);card.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();go(e);}});
  });
  // Canonical profile expansion/collapse behavior shared by MAIFIP and AICS.
  function initSharedProfile(){
    const profile=document.getElementById('topProfile');
    if(!profile || profile.dataset.sharedProfileBound==='1')return;
    const collapsed=profile.querySelector('.top-profile-collapsed-copy');
    const expanded=profile.querySelector('.top-profile-expanded-copy');
    const avatarWrap=profile.querySelector('.top-profile-user-wrap');
    const logout=profile.querySelector('.top-logout');
    const logoutLabel=logout?.querySelector('span');
    if(!collapsed||!expanded)return;
    profile.dataset.sharedProfileBound='1';
    const accountHref=profile.dataset.accountHref||'';
    const coarse=()=>window.matchMedia('(pointer: coarse)').matches;
    let open=false, pendingFrame=0, closeTimer=0, leaveTimer=0;
    const cssNum=(el,prop)=>parseFloat(getComputedStyle(el)[prop])||0;
    const outerWidth=el=>el?el.getBoundingClientRect().width+cssNum(el,'marginLeft')+cssNum(el,'marginRight'):0;
    const gap=()=>cssNum(profile,'columnGap')||cssNum(profile,'gap');
    const chromeWidth=()=>cssNum(profile,'paddingLeft')+cssNum(profile,'paddingRight')+cssNum(profile,'borderLeftWidth')+cssNum(profile,'borderRightWidth');
    function setLogout(isOpen){
      if(!logout)return;
      logout.classList.toggle('profile-logout-open',isOpen);
      if(logoutLabel){logoutLabel.setAttribute('aria-hidden',isOpen?'false':'true');}
      if(isOpen && logoutLabel){
        const label=Math.max(logoutLabel.scrollWidth,logoutLabel.getBoundingClientRect().width);
        const width=Math.ceil(label+cssNum(logout,'paddingLeft')+cssNum(logout,'paddingRight')+gap());
        profile.style.setProperty('--profile-logout-width',Math.max(30,width)+'px');
      }else{profile.style.setProperty('--profile-logout-width','30px');}
    }
    function measureExpandedCopy(){
      const previous={width:expanded.style.width,minWidth:expanded.style.minWidth,maxWidth:expanded.style.maxWidth,flexBasis:expanded.style.flexBasis,visibility:expanded.style.visibility,position:expanded.style.position};
      Object.assign(expanded.style,{width:'max-content',minWidth:'0',maxWidth:'none',flexBasis:'auto',visibility:'hidden',position:'absolute'});
      const width=Math.max(expanded.scrollWidth,expanded.getBoundingClientRect().width,...Array.from(expanded.children).map(el=>el.scrollWidth));
      Object.assign(expanded.style,previous);return width;
    }
    function closedWidth(){
      const avatar=outerWidth(avatarWrap),copy=outerWidth(collapsed);
      const logoutWidth=logout?Math.max(30,cssNum(logout,'height')):0;
      return Math.ceil(avatar+copy+logoutWidth+gap()*2+chromeWidth()+2);
    }
    function setTarget(width){profile.style.setProperty('--profile-runtime-width',Math.ceil(width)+'px');}
    function expand(){
      clearTimeout(closeTimer);clearTimeout(leaveTimer);
      /* mouseenter and pointerenter can both fire for the same hover. Treat expansion as idempotent. */
      if(open)return;
      cancelAnimationFrame(pendingFrame);
      const closed=closedWidth();
      const content=measureExpandedCopy()+10; /* reserve breathing room so long profile names never touch the right edge */
      const labelWidth=logoutLabel?Math.max(logoutLabel.scrollWidth,logoutLabel.getBoundingClientRect().width):0;
      const logoutFull=logout?Math.max(30,labelWidth+cssNum(logout,'paddingLeft')+cssNum(logout,'paddingRight')+gap()):0;
      profile.style.setProperty('--profile-logout-width',Math.ceil(logoutFull)+'px');
      const maxWidth=Math.max(closed,window.innerWidth-24);
      const rightEdge=profile.getBoundingClientRect().right;
      /* The expanded profile width includes the full logout control, not only the user copy. */
      const collapsedCopyWidth=outerWidth(collapsed);
      const requiredWidth=Math.max(closed,closed-collapsedCopyWidth+content+Math.max(0,logoutFull-30)+28);
      const available=Math.max(closed,Math.min(maxWidth,rightEdge-8));
      const desired=Math.min(available,requiredWidth);
      /* Always animate from the canonical collapsed width; never use an in-flight width as a new baseline. */
      setTarget(closed);void profile.offsetWidth;open=true;profile.classList.add('is-profile-open');setLogout(true);
      profile.style.setProperty('--profile-expanded-content-width',Math.max(0,desired-closed-Math.max(0,logoutFull-30))+'px');
      pendingFrame=requestAnimationFrame(()=>setTarget(desired));
    }
    function collapse(){
      clearTimeout(closeTimer);clearTimeout(leaveTimer);cancelAnimationFrame(pendingFrame);
      if(!open){setTarget(closedWidth());return;}
      const current=profile.getBoundingClientRect().width;
      setTarget(current);void profile.offsetWidth;open=false;profile.classList.remove('is-profile-open');setTarget(closedWidth());
      closeTimer=window.setTimeout(()=>{if(!open){setLogout(false);profile.style.removeProperty('--profile-expanded-content-width');setTarget(closedWidth());}},700);
    }
    function refresh(){if(!open)setTarget(closedWidth());}
    profile.addEventListener('mouseenter',()=>{if(!coarse())expand();});
    profile.addEventListener('mouseleave',()=>{if(!coarse()&&!profile.contains(document.activeElement)){clearTimeout(leaveTimer);leaveTimer=window.setTimeout(()=>{const hovered=profile.matches(':hover');const focused=profile.contains(document.activeElement);if(!hovered&&!focused)collapse();},180);}});
    profile.addEventListener('focusin',expand);
    profile.addEventListener('focusout',e=>{if(!profile.contains(e.relatedTarget)&&!profile.matches(':hover'))collapse();});
    profile.addEventListener('click',e=>{
      if(e.target.closest('.top-logout,a.top-logout,button.top-logout,[data-profile-logout]'))return;
      if(e.target.closest('a,button,input,select,textarea'))return;
      if(coarse()){if(open)collapse();else expand();return;}
      if(accountHref)window.location.href=accountHref;
    });
    profile.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&!e.target.closest('.top-logout')){e.preventDefault();if(coarse()){open?collapse():expand();}else if(accountHref)window.location.href=accountHref;}});
    window.addEventListener('resize',refresh,{passive:true});
    window.addEventListener('load',refresh,{once:true});
    if(document.fonts?.ready)document.fonts.ready.then(refresh).catch(()=>{});
    profile.querySelector('.top-profile-user img')?.addEventListener('load',refresh);
    setLogout(false);profile.classList.remove('is-profile-open');refresh();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initSharedProfile,{once:true});else initSharedProfile();
  document.addEventListener('fams:page-replaced',initSharedProfile);

  function activeNavDescriptor(){
    const active=sidebar?.querySelector('a.active, a[aria-current="page"]');
    if(!active)return null;
    return {href:active.getAttribute('href')||'',label:(active.textContent||'').replace(/\s+/g,' ').trim().toLowerCase()};
  }
  function snapshot(){
    if(onSheet)return null;
    return {
      userKey,
      system:layout?.dataset.lfamsSystem||'',
      pathname:location.pathname,
      windowY:Math.max(0,window.scrollY||document.documentElement.scrollTop||0),
      pageViewY:Math.max(0,pageView?.scrollTop||0),
      navY:Math.max(0,navScroll?.scrollTop||0),
      activeNav:activeNavDescriptor(),
      savedAt:Date.now()
    };
  }
  function saveState(){
    const state=snapshot(); if(!state)return;
    try{
      const previous=JSON.parse(sessionStorage.getItem(stateKey)||'null');
      // Preserve the clicked destination while pagehide/scroll events save the old document.
      if(previous&&previous.userKey===userKey&&previous.pathname===state.pathname&&previous.targetPathname&&previous.targetPathname!==state.pathname){state.targetPathname=previous.targetPathname;state.activeNav=previous.activeNav||state.activeNav;}
      sessionStorage.setItem(stateKey,JSON.stringify(state));
      if(state.activeNav)sessionStorage.setItem(activePageKey,JSON.stringify({href:state.activeNav.href,label:state.activeNav.label,pathname:state.targetPathname||state.pathname,system:state.system}));
    }catch(_e){}
  }
  function markRestore(){
    saveState();
    try{sessionStorage.setItem(restoreKey,'1');}catch(_e){}
  }
  // Save the selected navigation target and sidebar scroll before full-page navigation/refresh.
  document.addEventListener('click',e=>{
    const a=e.target.closest?.('.sidebar nav a[href],.nav a[href]');
    if(!a||a.target==='_blank'||a.hasAttribute('data-fams-popup')||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
    const href=a.getAttribute('href')||'';
    if(!href||href.startsWith('#')||/^(?:javascript:|mailto:|tel:)/i.test(href))return;
    const state=snapshot(); if(!state)return;
    state.activeNav={href,label:(a.textContent||'').replace(/\s+/g,' ').trim().toLowerCase()};
    state.targetPathname=(new URL(href,location.href)).pathname;
    try{sessionStorage.setItem(stateKey,JSON.stringify(state));sessionStorage.setItem(activePageKey,JSON.stringify({href:state.activeNav.href,label:state.activeNav.label,pathname:state.targetPathname,system:state.system}));if(!a.hasAttribute('data-ajax-navigation'))sessionStorage.setItem(restoreKey,'1');}catch(_e){}
  },true);
  // Preserve view position only for workspace/facility transitions, not ordinary navigation.
  document.addEventListener('click',e=>{
    const a=e.target.closest?.('a[href]');
    if(!a)return;
    const href=a.getAttribute('href')||'';
    if(a.matches('.fams-brand-switch-button,.sidebar-system-switch-btn')||/switch\.php(?:\?|$)/i.test(href)||/select-system\.php(?:\?|$)/i.test(href)){markRestore();if(/switch\.php(?:\?|$)/i.test(href)){try{const target=new URL(href,location.href);const page=location.pathname.split('/').pop();if(page&&page.endsWith('.php')&&!['switch.php','logout.php','login.php','index.php','select-system.php'].includes(page))target.searchParams.set('return_page',page);a.href=target.pathname+target.search+target.hash;}catch(_e){}}}
  },true);
  document.addEventListener('submit',e=>{
    const form=e.target;
    if(form.matches?.('.sidebar-facility-selector,[data-preserve-shell-state]'))markRestore();
  },true);
  // MAIFIP's facility dropdown submits with native form.submit(), which bypasses submit events.
  document.getElementById('sidebarFacilitySelect')?.addEventListener('change',markRestore);
  // Save continuously so a quick facility/workspace change keeps the latest scroll positions.
  let saveFrame=0;
  const queueSave=()=>{if(saveFrame)return;saveFrame=requestAnimationFrame(()=>{saveFrame=0;saveState();});};
  window.addEventListener('scroll',queueSave,{passive:true});
  navScroll?.addEventListener('scroll',queueSave,{passive:true});
  pageView?.addEventListener('scroll',queueSave,{passive:true});
  window.addEventListener('pagehide',saveState);

  function restoreState(){
    let pending=false,state=null;
    try{pending=sessionStorage.getItem(restoreKey)==='1';state=JSON.parse(sessionStorage.getItem(stateKey)||'null');}catch(_e){}
    if(!state||state.userKey!==userKey||onSheet)return;
    const samePage=state.pathname===location.pathname;
    const targetPage=state.targetPathname===location.pathname;
    if(!pending&&!samePage&&!targetPage)return;
    try{sessionStorage.removeItem(restoreKey);}catch(_e){}
    // Keep server-selected active navigation authoritative. If the same link exists, preserve it explicitly.
    if(state.activeNav && pending){
      const links=[...(sidebar?.querySelectorAll('a')||[])];
      const savedPage=(()=>{try{return JSON.parse(sessionStorage.getItem(activePageKey)||'null')}catch(_e){return null}})();
      const match=links.find(a=>(a.getAttribute('href')||'')===state.activeNav.href) || links.find(a=>savedPage && (a.textContent||'').replace(/\s+/g,' ').trim().toLowerCase()===savedPage.label) || links.find(a=>savedPage && (a.getAttribute('href')||'').split('?')[0].split('/').pop()===savedPage.pathname.split('/').pop());
      if(match && (state.pathname===location.pathname || state.system!== (layout?.dataset.lfamsSystem||'') || match.classList.contains('active') || match.getAttribute('aria-current')==='page')){
        links.forEach(a=>{if(a!==match){a.classList.remove('active');if(a.getAttribute('aria-current')==='page')a.removeAttribute('aria-current');}});
        match.classList.add('active');match.setAttribute('aria-current','page');
      }
    }
    const apply=()=>{
      if(navScroll)navScroll.scrollTop=state.navY||0;
      const sameWorkspace=(state.system||'')===(layout?.dataset.lfamsSystem||'');
      if(samePage || (!sameWorkspace && pending)){if(pageView)pageView.scrollTop=state.pageViewY||0;window.scrollTo(0,state.windowY||0);}
      else {if(pageView)pageView.scrollTop=0;window.scrollTo(0,0);}
    };
    requestAnimationFrame(()=>{apply();setTimeout(apply,120);});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',restoreState,{once:true});else restoreState();

  window.openRecordModal=function(url){window.openGlobalPopup(url)};
  // All popup links are routed through the canonical shared controller below.
  let popupReturnFocus=null;
  window.openGlobalPopup=function(url,title){
    const box=document.getElementById('globalPopup'),frame=document.getElementById('globalPopupFrame');
    if(!box||!frame){window.location.href=url;return;}
    popupReturnFocus=document.activeElement;
    const target=new URL(url,window.location.href);target.searchParams.set('popup','1');
    const titleNode=document.getElementById('globalPopupTitle');
    if(titleNode)titleNode.textContent=title||'Financial Assistance';
    frame.src=target.toString();box.hidden=false;document.body.classList.add('fams-popup-open');
    box.querySelector('.fams-shared-dialog-close')?.focus();
  };
  window.closeRecordModal=function(){window.closeGlobalPopup()};
  window.closeGlobalPopup=function(){
    const box=document.getElementById('globalPopup'),frame=document.getElementById('globalPopupFrame');
    if(box)box.hidden=true;if(frame)frame.src='about:blank';document.body.classList.remove('fams-popup-open','fams-modal-submitting');
    if(popupReturnFocus&&typeof popupReturnFocus.focus==='function')popupReturnFocus.focus();popupReturnFocus=null;
  };
  const globalPopup=document.getElementById('globalPopup');
  globalPopup?.addEventListener('click',e=>{if(e.target===globalPopup)window.closeGlobalPopup();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&globalPopup&&!globalPopup.hidden){e.preventDefault();window.closeGlobalPopup();}});
  document.addEventListener('click',e=>{
    const link=e.target.closest?.('a[data-fams-popup]');
    if(!link||link.target==='_blank'||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
    e.preventDefault();window.openGlobalPopup(link.href,link.dataset.popupTitle||link.textContent.trim());
  });
  // Preserve the dashboard shortcut while using this single shared popup controller.
  const params=new URLSearchParams(window.location.search);
  if(params.get('open_add')==='1'){
    const addLink=document.querySelector('.fams-add-record-btn[data-fams-popup]');
    if(addLink){params.delete('open_add');history.replaceState({},document.title,window.location.pathname+(params.toString()?'?'+params.toString():'')+window.location.hash);setTimeout(()=>window.openGlobalPopup(addLink.href,addLink.dataset.popupTitle||addLink.textContent.trim()),0);}
  }
  try{
    const popupSuccess=sessionStorage.getItem('fams_popup_success');
    if(popupSuccess){sessionStorage.removeItem('fams_popup_success');setTimeout(()=>{
      const toast=document.createElement('div');toast.className='fams-popup-success-toast';toast.setAttribute('role','status');toast.textContent=popupSuccess;document.body.appendChild(toast);
      requestAnimationFrame(()=>toast.classList.add('is-visible'));setTimeout(()=>{toast.classList.remove('is-visible');setTimeout(()=>toast.remove(),240);},3000);
    },80);}
  }catch(e){}
  window.addEventListener('message',e=>{
    const popupFrame=document.getElementById('globalPopupFrame');
    if(e.origin!==window.location.origin || !popupFrame || e.source!==popupFrame.contentWindow || !e.data)return;
    if(e.data.type==='fams-popup-submitting'){document.body.classList.add('fams-modal-submitting');return;}
    if(e.data.type==='fams-popup-success'){
      try{sessionStorage.setItem('fams_popup_success',String(e.data.message||'Changes saved successfully.'));}catch(err){}
      window.closeGlobalPopup();window.location.reload();return;
    }
    if(e.data.recordSaved){window.closeGlobalPopup();window.location.reload();return;}
    if(e.data.type==='fams-close-popup')window.closeGlobalPopup();
    if(e.data.type==='fams-popup-error')window.alert(e.data.message||'Unable to save changes.');
  });
  document.addEventListener('submit',e=>{
    const form=e.target; if(form instanceof HTMLFormElement && form.matches('form[data-confirm]') && !window.confirm(form.dataset.confirm)) e.preventDefault();
  },true);
})();


/* v0.0219 — consistent, accessible contextual icons for navigation, actions and section headings.
   Icons are decorative enhancements: visible labels remain intact and are still the accessible name. */
(function famsInstallContextualIcons(){
  'use strict';
  if (window.__famsContextualIconsInstalled) return;
  window.__famsContextualIconsInstalled = true;
  const svgNS = 'http://www.w3.org/2000/svg';
  const paths = {
    dashboard: '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.2"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
    records: '<path d="M7 3h8l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M15 3v5h5M9 12h6m-6 4h6"/>',
    users: '<path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7" r="4"/><path d="M17 11a4 4 0 0 0 0-8m4 17v-1.5a4 4 0 0 0-3-3.87"/>',
    fund: '<path d="M3 7.5h18v12H3zM3 7.5l2-3h14l2 3"/><path d="M15 13h6m-18 0h3"/><circle cx="12" cy="13.5" r="2.5"/>',
    report: '<path d="M5 3h10l4 4v14H5zM15 3v5h5"/><path d="M8 17v-3m4 3v-6m4 6v-4"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-13 4h2m3 0h2m-7 3h2"/>',
    ticket: '<path d="M4 7a2 2 0 0 0 2-2h12a2 2 0 0 0 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 0-2 2H6a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-4Z"/><path d="M12 8v2m0 3v2"/>',
    chat: '<path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H6l-3 2v-6.5A7.5 7.5 0 1 1 20 11.5Z"/><path d="M8 11h8m-8 3h5"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z" transform="translate(-1 -1) scale(1.08)"/>',
    add: '<path d="M12 5v14m-7-7h14"/>',
    search: '<circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/>',
    filter: '<path d="M3 5h18l-7 8v5l-4 2v-7Z"/>',
    download: '<path d="M12 3v12m-5-5 5 5 5-5M4 20h16"/>',
    upload: '<path d="M12 16V4m-5 5 5-5 5 5M4 20h16"/>',
    print: '<path d="M6 9V3h12v6M6 17H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/>',
    save: '<path d="M4 3h13l3 3v15H4z"/><path d="M8 3v6h8V3m-8 18v-7h8v7"/>',
    back: '<path d="m14 5-7 7 7 7M7 12h14"/>',
    refresh: '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.5 9a7 7 0 0 1 12-2L20 12M4 12l2.5 5a7 7 0 0 0 12-2"/>',
    view: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.6 9a2.5 2.5 0 1 1 4.5 1.5c-1 1.2-2.1 1.4-2.1 3m0 3h.01"/>',
    logout: '<path d="M10 17l5-5-5-5m5 5H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
    medical: '<path d="M12 21s-8-4.5-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 6.5-8 11-8 11Z"/><path d="M8 12h8m-4-4v8"/>',
    office: '<path d="M3 21h18M5 21V5l7-3 7 3v16M9 8h1m4 0h1m-6 4h1m4 0h1m-5 9v-4h4v4"/>',
    money: '<circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.8-1.7-1.2-3-1.2-1.7 0-2.8.8-2.8 2s1.1 1.8 2.8 2.2 2.8 1 2.8 2.2-1.1 2-2.8 2c-1.3 0-2.4-.4-3.2-1.3M12 5v14"/>'
  };
  const navRules = [
    [/dashboard|home|overview/, 'dashboard'], [/masterlist|assistance records|records/, 'records'],
    [/user|account|staff|personnel/, 'users'], [/fund|allotment|disbursement/, 'fund'],
    [/monthly report|reports|analytics|summary/, 'report'], [/calendar|schedule|reporting period/, 'calendar'],
    [/ticket|approval|revision request/, 'ticket'], [/chat|message|conversation/, 'chat'],
    [/setting|configuration|permission|user type/, 'settings'], [/office|facility/, 'office'],
    [/help|support|guide/, 'help'], [/medical|maifip|health/, 'medical']
  ];
  const actionRules = [
    [/^(add|new|create|register)\b/, 'add'], [/^(search|find)\b/, 'search'], [/^(filter|advanced filter)\b/, 'filter'],
    [/^(export|download|save as csv|save as excel)\b/, 'download'], [/^(import|upload)\b/, 'upload'],
    [/^(print|print report)\b/, 'print'], [/^(save|update|submit|apply changes)\b/, 'save'],
    [/^(back|return)\b/, 'back'], [/^(refresh|reload)\b/, 'refresh'], [/^(view|open|details|display)\b/, 'view'],
    [/^(help|learn more)\b/, 'help'], [/^(sign out|logout|log out)\b/, 'logout'],
    [/^(release|disburse|payment)\b/, 'money'], [/^(fund|balance|downloaded balance)\b/, 'fund'],
    [/^(report|monthly report)\b/, 'report'], [/^(submit ticket|ticket)\b/, 'ticket']
  ];
  const headingRules = [
    [/dashboard|overview/, 'dashboard'], [/masterlist|assistance records|record list/, 'records'],
    [/fund|allotment|balance|disbursement/, 'fund'], [/monthly report|reporting|report summary|reports/, 'report'],
    [/user|account|staff management/, 'users'], [/ticket|approval request/, 'ticket'],
    [/office|facility/, 'office'], [/setting|configuration|permissions/, 'settings'],
    [/medical assistance/, 'medical'], [/financial assistance/, 'money']
  ];
  function makeIcon(kind, className){
    if(!paths[kind]) return null;
    const svg=document.createElementNS(svgNS,'svg');
    svg.setAttribute('viewBox','0 0 24 24'); svg.setAttribute('aria-hidden','true');
    svg.setAttribute('focusable','false'); svg.classList.add('fams-context-icon',className||'');
    const group=document.createElementNS(svgNS,'g'); group.innerHTML=paths[kind]; svg.appendChild(group);
    return svg;
  }
  function labelOf(el){return (el.getAttribute('aria-label')||el.getAttribute('title')||el.textContent||'').replace(/\s+/g,' ').trim().toLowerCase();}
  function resolve(rules,label){for(const [pattern,kind] of rules){if(pattern.test(label))return kind;}return '';}
  function addTo(el,kind,className){
    if(!kind||el.dataset.famsContextIcon||el.querySelector('svg,img,.fams-context-icon')||el.matches('.fams-icon-action,.icon-button,.action-icon,.table-action,[aria-hidden="true"]'))return;
    const icon=makeIcon(kind,className); if(!icon)return;
    el.insertBefore(icon,el.firstChild); el.dataset.famsContextIcon=kind;
  }
  function selectWithin(root,selector){
    const found=[];
    if(root instanceof Element && root.matches(selector))found.push(root);
    if(typeof root.querySelectorAll==='function')found.push(...root.querySelectorAll(selector));
    return found;
  }
  function enhance(root){
    if(!(root instanceof Element||root===document))return;
    selectWithin(root,'.sidebar nav a,.nav a,.sidebar-nav-scroll a').forEach(el=>addTo(el,resolve(navRules,labelOf(el)),'fams-nav-icon'));
    selectWithin(root,'button,a.btn,a.button,.btn,button[type="submit"]').forEach(el=>{
      if(el.closest('.sidebar,.nav,.sidebar-nav-scroll'))return;
      addTo(el,resolve(actionRules,labelOf(el)),'fams-action-icon');
    });
    selectWithin(root,'.section-head h2,.page-header h1,.page-heading h1,.card-header h2,.panel-heading h2').forEach(el=>addTo(el,resolve(headingRules,labelOf(el)),'fams-heading-icon'));
  }
  const run=()=>enhance(document);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run,{once:true});else run();
  const observer=new MutationObserver(mutations=>mutations.forEach(m=>m.addedNodes.forEach(n=>{if(n.nodeType===1)enhance(n);} )));
  observer.observe(document.documentElement,{childList:true,subtree:true});
})();

/* Shared icon-only action controls: edit, delete and submit-ticket actions. */
(function () {
  const icons = {
    edit: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>',
    delete: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg>',
    ticket: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7a2 2 0 0 0 2-2h12a2 2 0 0 0 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 0-2 2H6a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-4Z"/><path d="M12 8v2m0 3v2"/></svg>'
  };
  function standardizeActionIcons(root) {
    (root || document).querySelectorAll('a,button').forEach(function (el) {
      if (el.dataset.actionIconReady === '1' || el.querySelector('input,select,textarea') || el.closest('.fams-shared-dialog-head')) return;
      const label = (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || '').replace(/\s+/g, ' ').trim();
      const normalized = label.toLowerCase().replace(/[.…]+$/g, '').replace(/^[^a-z0-9]+/i, '').trim();
      let kind = '';
      if (/^(edit|edit record|edit ticket|edit fund|edit facility|edit account|edit user|review \/ edit|review\/edit)(\b|\s)/i.test(normalized)) kind = 'edit';
      else if (/^(delete|delete ticket|delete record|delete entry|delete request|move to trash|request deletion)(\b|\s)/i.test(normalized)) kind = 'delete';
      else if (/^(submit ticket|submit revision ticket|submit record update ticket|create approval ticket|submit assistance record ticket)(\b|\s)/i.test(normalized)) kind = 'ticket';
      if (!kind) return;
      const accessible = el.getAttribute('aria-label') || label;
      el.dataset.actionIconReady = '1';
      el.classList.add('fams-icon-action', 'fams-icon-action-' + kind);
      el.setAttribute('aria-label', accessible);
      el.setAttribute('title', el.getAttribute('title') || accessible);
      el.innerHTML = icons[kind];
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { standardizeActionIcons(document); });
  else standardizeActionIcons(document);
  const observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) standardizeActionIcons(n); }); });
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();


/* v0.0173 — wide-table-only edge navigation.
   Edge controls are eligible only on an explicit table wrapper containing a table.
   Page sections, cards, cells, and generic overflow containers are never scroll targets. */
(function famsInstallHorizontalTableNavigation(){
  if (window.__famsHorizontalTableNavigationInstalled) return;
  window.__famsHorizontalTableNavigationInstalled = true;

  const EDGE_TOLERANCE = 1.25;
  const POINTER_EDGE = 42;
  const WHEEL_EDGE = 30;
  let activeViewport = null;
  let activeDirection = 0;
  let activePointerY = 0;
  let hideTimer = 0;
  let frame = 0;
  let resizeObserver = null;
  let mutationObserver = null;

  const maxScroll = el => Math.max(0, el.scrollWidth - el.clientWidth);
  const isWideTableViewport = el => {
    if (!(el instanceof Element) || !el.isConnected) return false;
    // Explicit table wrappers only. Never infer eligibility from a section/card
    // that happens to overflow because of unrelated content or CSS overrides.
    if (!el.matches('.masterlist-table-wrap, .table-wrap, .table-responsive, .fams-data-list-table-wrap')) return false;
    const table = el.querySelector('table');
    if (!table) return false;
    const overflow = getComputedStyle(el).overflowX;
    if (!(overflow === 'auto' || overflow === 'scroll' || overflow === 'overlay')) return false;
    // The table itself must be wider than the wrapper; section-level overflow
    // and oversized non-table descendants must not create edge controls.
    const tableWidth = Math.max(table.scrollWidth, table.getBoundingClientRect().width);
    return maxScroll(el) > EDGE_TOLERANCE && tableWidth > el.clientWidth + EDGE_TOLERANCE;
  };
  function state(el) {
    const max = maxScroll(el);
    const left = Math.max(0, Math.min(max, el.scrollLeft));
    return {
      max, left,
      canLeft: max > EDGE_TOLERANCE && left > EDGE_TOLERANCE,
      canRight: max > EDGE_TOLERANCE && (max - left) > EDGE_TOLERANCE
    };
  }
  function resolveViewport(target) {
    if (!(target instanceof Element)) return null;
    // Resolve only the nearest explicit table wrapper. No generic ancestor
    // fallback: sections/cards must never acquire their own edge arrows.
    const wrapper = target.closest('.masterlist-table-wrap, .table-wrap, .table-responsive, .fams-data-list-table-wrap');
    return wrapper && isWideTableViewport(wrapper) ? wrapper : null;
  }
  function makeArrow(direction, label) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'fams-edge-scroll-arrow fams-edge-scroll-arrow-' + (direction < 0 ? 'left' : 'right');
    button.setAttribute('aria-label', label);
    button.title = label;
    button.hidden = true;
    button.innerHTML = direction < 0
      ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 5-7 7 7 7"/><path d="M8 12h11"/></svg>'
      : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 5 7 7-7 7"/><path d="M5 12h11"/></svg>';
    document.body.appendChild(button);
    button.addEventListener('pointerenter', () => clearTimeout(hideTimer));
    button.addEventListener('pointerleave', scheduleHide);
    button.addEventListener('click', event => {
      event.preventDefault(); event.stopPropagation();
      if (!activeViewport || !activeViewport.isConnected) { clearTracking(); return; }
      const current = state(activeViewport);
      const available = direction < 0 ? current.canLeft : current.canRight;
      if (!available) { positionButtons(); return; }
      const next = Math.max(0, Math.min(current.max, current.left + direction * Math.max(180, activeViewport.clientWidth * .72)));
      activeViewport.scrollTo({ left: next, behavior: 'smooth' });
      queueRefresh();
    });
    return button;
  }
  const leftButton = makeArrow(-1, 'Scroll table left');
  const rightButton = makeArrow(1, 'Scroll table right');
  function hideArrows() { leftButton.hidden = true; rightButton.hidden = true; }
  function clearTracking() {
    clearTimeout(hideTimer);
    activeViewport = null; activeDirection = 0;
    hideArrows();
    if (resizeObserver) resizeObserver.disconnect();
    if (mutationObserver) mutationObserver.disconnect();
    resizeObserver = mutationObserver = null;
  }
  function scheduleHide() {
    clearTimeout(hideTimer);
    hideTimer = window.setTimeout(clearTracking, 260);
  }
  function positionButtons() {
    const viewport = activeViewport;
    if (!viewport || !viewport.isConnected || !activeDirection) { hideArrows(); return; }
    const rect = viewport.getBoundingClientRect();
    if (rect.width < 80 || rect.right <= 0 || rect.left >= window.innerWidth || rect.bottom <= 0 || rect.top >= window.innerHeight) {
      hideArrows(); return;
    }
    const current = state(viewport);
    // Evaluate the true remaining distance on every refresh. At the right edge,
    // the right control is hidden even if the pointer remains parked there.
    leftButton.hidden = activeDirection >= 0 || !current.canLeft;
    rightButton.hidden = activeDirection <= 0 || !current.canRight;
    const y = Math.max(8, Math.min(window.innerHeight - 44,
      (activePointerY || (rect.top + Math.min(rect.height / 2, 180))) - 18));
    leftButton.style.left = Math.round(Math.max(6, rect.left + 7)) + 'px';
    rightButton.style.left = Math.round(Math.min(window.innerWidth - 44, rect.right - 43)) + 'px';
    leftButton.style.top = rightButton.style.top = Math.round(y) + 'px';
  }
  function queueRefresh() {
    if (frame) return;
    frame = requestAnimationFrame(() => { frame = 0; positionButtons(); });
  }
  function activate(viewport, direction, pointerY) {
    clearTimeout(hideTimer);
    if (activeViewport !== viewport) {
      if (resizeObserver) resizeObserver.disconnect();
      if (mutationObserver) mutationObserver.disconnect();
      activeViewport = viewport;
      if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(queueRefresh);
        resizeObserver.observe(viewport);
        if (viewport.firstElementChild) resizeObserver.observe(viewport.firstElementChild);
      }
      if (typeof MutationObserver !== 'undefined') {
        mutationObserver = new MutationObserver(queueRefresh);
        mutationObserver.observe(viewport, { childList:true, subtree:true, attributes:true, characterData:true });
      }
    }
    activeDirection = direction;
    if (Number.isFinite(pointerY)) activePointerY = pointerY;
    positionButtons();
  }

  document.addEventListener('pointermove', event => {
    if (event.target instanceof Element && event.target.closest('.fams-edge-scroll-arrow')) {
      clearTimeout(hideTimer); positionButtons(); return;
    }
    const viewport = resolveViewport(event.target);
    if (!viewport) { scheduleHide(); return; }
    const rect = viewport.getBoundingClientRect();
    const current = state(viewport);
    if (current.max <= EDGE_TOLERANCE) { if (activeViewport === viewport) clearTracking(); return; }
    if (event.clientX <= rect.left + POINTER_EDGE) activate(viewport, -1, event.clientY);
    else if (event.clientX >= rect.right - POINTER_EDGE) activate(viewport, 1, event.clientY);
    else scheduleHide();
  }, { passive:true });

  document.addEventListener('scroll', event => {
    if (!activeViewport) return;
    if (event.target === activeViewport || event.target === document || event.target === document.documentElement) queueRefresh();
  }, true);
  document.addEventListener('scrollend', event => {
    if (activeViewport && (event.target === activeViewport || event.target === document || event.target === document.documentElement)) positionButtons();
  }, true);
  window.addEventListener('resize', queueRefresh, { passive:true });
  document.addEventListener('pointerdown', event => {
    if (!(event.target instanceof Element && event.target.closest('.fams-edge-scroll-arrow'))) scheduleHide();
  }, { passive:true });
  document.addEventListener('wheel', event => {
    if (event.ctrlKey || event.shiftKey) return;
    const viewport = resolveViewport(event.target);
    if (!viewport) return;
    const rect = viewport.getBoundingClientRect();
    const nearLeft = event.clientX <= rect.left + WHEEL_EDGE;
    const nearRight = event.clientX >= rect.right - WHEEL_EDGE;
    if (!nearLeft && !nearRight) return;
    const current = state(viewport);
    const direction = nearLeft ? -1 : 1;
    if (!(direction < 0 ? current.canLeft : current.canRight)) return;
    const delta = Math.max(24, Math.min(120, Math.abs(event.deltaY) || Math.abs(event.deltaX) || 48));
    const next = Math.max(0, Math.min(current.max, current.left + direction * delta));
    if (Math.abs(next - current.left) > EDGE_TOLERANCE / 2) {
      viewport.scrollLeft = next;
      event.preventDefault();
      activate(viewport, direction, event.clientY);
      queueRefresh();
    }
  }, { passive:false, capture:true });
})();
