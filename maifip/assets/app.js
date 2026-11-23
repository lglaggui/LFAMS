document.addEventListener('DOMContentLoaded', () => {
    const flash = document.getElementById('famsFlashToast');
    if (flash) {
        window.setTimeout(() => {
            if (!flash.isConnected) return;
            flash.style.transition = 'opacity .18s ease, transform .18s ease';
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-6px)';
            window.setTimeout(() => flash.remove(), 190);
        }, 4500);
    }
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.dataset.keepText) {
                btn.classList.add('is-loading');
            }
        });
    });
});

/* Version 2.9 — all floating status editors are portaled to <body> so table/scroll containers cannot clip them. */
function getStatusField(form, popover, selector){
    return popover?.querySelector(selector) || form?.querySelector(selector);
}

function positionStatusPopover(form, popover) {
    if(!popover) return;
    // True viewport modal: always above the records table and near the top of
    // the visible viewport so the editor is immediately visible.
    const edge = 18;
    const width = Math.min(440, Math.max(300, window.innerWidth - edge * 2));
    popover.style.width = width + 'px';
    popover.style.left = '50%';
    popover.style.top = edge + 'px';
    popover.style.transform = 'translateX(-50%)';
    popover.style.maxHeight = Math.max(180, window.innerHeight - edge * 2) + 'px';
}

function updateStatusPopover(form, popover) {
    const select = form.querySelector('[data-status-select]');
    const release = getStatusField(form,popover,'[data-release-date]');
    const remarks = getStatusField(form,popover,'[data-status-remarks]');
    const releaseRequired = getStatusField(form,popover,'[data-release-required]');
    const remarksRequired = getStatusField(form,popover,'[data-remarks-required]');
    const help = getStatusField(form,popover,'[data-status-help]');
    if(!select||!release||!remarks) return;
    const status = select.value;
    const submissionDate = form.dataset.submissionDate || '';

    select.classList.remove('status-waiting','status-released','status-cancelled');
    select.classList.add(status === 'RELEASED' ? 'status-released' : status === 'CANCELLED' ? 'status-cancelled' : 'status-waiting');

    if (status === 'RELEASED') {
        release.disabled = false;
        if (submissionDate) {
            const minDate = new Date(submissionDate + 'T00:00:00');
            const yyyy = minDate.getFullYear();
            const mm = String(minDate.getMonth()+1).padStart(2,'0');
            const dd = String(minDate.getDate()).padStart(2,'0');
            release.min = `${yyyy}-${mm}-${dd}`;
        }
        release.required = true;
        if(releaseRequired) releaseRequired.hidden = false;
        remarks.disabled = false;
        remarks.required = false;
        if(remarksRequired) remarksRequired.hidden = true;
        if(help) help.textContent = 'Release Date is required. Remarks are now editable and optional.';
    } else if (status === 'CANCELLED') {
        release.value = '';
        release.removeAttribute('min');
        release.disabled = true;
        release.required = false;
        if(releaseRequired) releaseRequired.hidden = true;
        remarks.disabled = false;
        remarks.required = true;
        if(remarksRequired) remarksRequired.hidden = false;
        if(help) help.textContent = 'Please enter the reason for cancellation. Release Date is disabled.';
    } else {
        release.disabled = true;
        release.removeAttribute('min');
        release.required = false;
        if(releaseRequired) releaseRequired.hidden = true;
        remarks.disabled = true;
        remarks.required = false;
        if(remarksRequired) remarksRequired.hidden = true;
        if(help) help.textContent = 'WAITING has no release date. Remarks are locked until the status is changed.';
    }
}

window.FAMSCloseStatusPopovers=function(){
    document.querySelectorAll('.fams-status-backdrop').forEach(p=>p.remove());
    document.querySelectorAll('.fams-status-portal').forEach(p=>p.remove());
    document.querySelectorAll('[data-status-popover]').forEach(p=>{p.hidden=true;});
};

window.FAMSInitStatusForms=function(){
    // Remove stale portals left behind by live-search/table replacement.
    document.querySelectorAll('.fams-status-portal').forEach(portal=>{
        const owner=document.getElementById(portal.dataset.ownerFormId||'');
        if(!owner || !owner.isConnected) portal.remove();
    });

    document.querySelectorAll('[data-status-form]').forEach(form => {
        if(form.dataset.statusBound==='1') return;
        form.dataset.statusBound='1';
        const select=form.querySelector('[data-status-select]'), popover=form.querySelector('[data-status-popover]');
        const cancel=popover?.querySelector('[data-status-cancel]');
        const save=popover?.querySelector('button[type="submit"]');
        const release=popover?.querySelector('[data-release-date]'), remarks=popover?.querySelector('[data-status-remarks]');
        const releaseRequired=popover?.querySelector('[data-release-required]'), remarksRequired=popover?.querySelector('[data-remarks-required]'), help=popover?.querySelector('[data-status-help]');
        if(!select||!popover||!release||!remarks)return;

        if(!form.id) form.id='famsStatusForm-'+(select.getAttribute('aria-label')||'record').replace(/[^a-z0-9_-]+/gi,'-')+'-'+Math.random().toString(36).slice(2,8);
        popover.dataset.ownerFormId=form.id;
        popover.classList.add('fams-status-portal');
        popover.setAttribute('role','dialog');
        // The editor is portaled to <body>. Do NOT rely on the HTML form="..."
        // association for the moved controls: some browsers/table DOM layouts can
        // serialize those controls inconsistently. Instead, keep the visible editor
        // completely independent and mirror its values into hidden controls that stay
        // inside the original form. This makes the POST deterministic.
        const mirror=(name)=>{
            let input=form.querySelector('input[data-status-mirror="'+name+'"]');
            if(!input){
                input=document.createElement('input');
                input.type='hidden';
                input.name=name;
                input.dataset.statusMirror=name;
                form.appendChild(input);
            }
            return input;
        };
        const releaseMirror=mirror('release_date');
        const remarksMirror=mirror('remarks');
        popover.querySelectorAll('input,textarea,button[type="submit"]').forEach(el=>{
            el.removeAttribute('form');
            if(el.matches('input,textarea')) el.removeAttribute('name');
        });
        const syncMirrors=()=>{
            const status=select.value;
            releaseMirror.value=(status==='RELEASED' ? (release.value||'') : '');
            remarksMirror.value=remarks.value||'';
        };
        document.body.appendChild(popover);
        popover.hidden=true;

        // The committed values are the values that must be restored when the
        // editor is cancelled or dismissed. Capture them BEFORE the select
        // changes so a cancelled change never becomes the new baseline.
        const captureSnapshot=()=>{
            form.dataset.statusSnapshot=select.value;
            form.dataset.releaseSnapshot=release.value||'';
            form.dataset.remarksSnapshot=remarks.value||'';
        };
        const restoreSnapshot=()=>{
            if(form.dataset.statusSnapshot!==undefined) select.value=form.dataset.statusSnapshot;
            if(form.dataset.releaseSnapshot!==undefined) release.value=form.dataset.releaseSnapshot;
            if(form.dataset.remarksSnapshot!==undefined) remarks.value=form.dataset.remarksSnapshot;
            updateStatusPopover(form,popover);
        };
        const close=(restore=true)=>{
            if(restore) restoreSnapshot();
            popover.hidden=true;
            document.querySelectorAll('.fams-status-backdrop[data-owner-form-id="'+form.id+'"]').forEach(b=>b.remove());
        };
        const open=()=>{
            // A change event fires after the select value has already changed.
            // The snapshot was captured on focus/pointerdown, before that change.
            document.querySelectorAll('.fams-status-portal').forEach(other=>{if(other!==popover)other.hidden=true;});
            document.querySelectorAll('.fams-status-backdrop').forEach(other=>other.remove());
            const backdrop=document.createElement('div');
            backdrop.className='fams-status-backdrop';
            backdrop.setAttribute('aria-hidden','true');
            backdrop.dataset.ownerFormId=form.id;
            document.body.appendChild(backdrop);
            updateStatusPopover(form,popover);
            popover.hidden=false;
            requestAnimationFrame(()=>positionStatusPopover(form,popover));
        };

        captureSnapshot();
        select.addEventListener('pointerdown',()=>{if(popover.hidden) captureSnapshot();});
        select.addEventListener('focus',()=>{
            if(popover.hidden) captureSnapshot();
        });
        select.addEventListener('change',()=>{
            const originalStatus=String(form.dataset.recordStatus||form.dataset.statusSnapshot||'WAITING').toUpperCase();
            const role=String(form.dataset.userRole||'');
            if(role!=='admin' && originalStatus!=='WAITING' && select.value!==originalStatus){
                const id=Number(form.dataset.recordId||0);
                if(id>0) window.location.href='ticket_create.php?id='+id+'&popup=1&requested_status='+encodeURIComponent(select.value);
                restoreSnapshot(); return;
            }
            open();
        });

        cancel?.addEventListener('click',event=>{
            event.preventDefault();
            close(true);
            select.focus();
        });

        // The portaled Save button is explicitly wired to the original form.
        // This avoids browser-specific issues with a submit button moved out of
        // its original DOM subtree and still preserves the form submit handler.
        save?.addEventListener('click',event=>{
            event.preventDefault();
            // The popup is moved to <body>, so do not depend on the moved
            // button's form association. Submit the original form directly;
            // requestSubmit() still fires the validation/submit pipeline.
            try {
                if(typeof form.requestSubmit==='function') form.requestSubmit();
                else HTMLFormElement.prototype.submit.call(form);
            } catch(err) {
                console.warn('Status requestSubmit fallback:',err);
                HTMLFormElement.prototype.submit.call(form);
            }
        });

        form.addEventListener('submit',event=>{
            updateStatusPopover(form,popover);
            const status=select.value, releaseValue=release.value.trim(), remarksValue=remarks.value.trim();
            if(status==='RELEASED'){
                if(!releaseValue){event.preventDefault();alert('Release Date is required when status is RELEASED. Please enter a date on or after the submission date and not later than today.');release.focus();return;}
                if(release.min&&releaseValue<release.min){event.preventDefault();alert('Invalid Release Date. It must be on or after the submission date.');release.focus();return;}
                if(release.max&&releaseValue>release.max){event.preventDefault();alert('Invalid Release Date. It cannot be later than today.');release.focus();return;}
            }
            if(status==='CANCELLED'&&!remarksValue){event.preventDefault();alert('Remarks are required when status is CANCELLED. Please enter the reason for cancellation.');remarks.focus();return;}
            if(status==='CANCELLED'&&!confirm('Cancel this assistance record? The cancellation reason will be saved in the remarks and audit history.')){
                event.preventDefault();
                restoreSnapshot();
                close(true);
                return;
            }
            // Copy the visible popup values into controls that are physically inside
            // the original form before the browser sends the POST.
            syncMirrors();
        });

        window.addEventListener('resize',()=>{if(!popover.hidden)positionStatusPopover(form,popover);});
        window.addEventListener('scroll',()=>{if(!popover.hidden)positionStatusPopover(form,popover);},true);
        updateStatusPopover(form,popover);
        syncMirrors();
    });
};

// Close floating editors when the user clicks anywhere outside them.
document.addEventListener('click',e=>{
    const openStatus=[...document.querySelectorAll('.fams-status-portal:not([hidden])')];
    if(openStatus.length && !e.target.closest('.fams-status-portal') && !e.target.closest('[data-status-select]')){
        openStatus.forEach(p=>{
            const owner=document.getElementById(p.dataset.ownerFormId||'');
            if(owner && owner.dataset.statusBound==='1'){
                const select=owner.querySelector('[data-status-select]');
                const release=p.querySelector('[data-release-date]');
                const remarks=p.querySelector('[data-status-remarks]');
                if(owner.dataset.statusSnapshot!==undefined && select) select.value=owner.dataset.statusSnapshot;
                if(owner.dataset.releaseSnapshot!==undefined && release) release.value=owner.dataset.releaseSnapshot;
                if(owner.dataset.remarksSnapshot!==undefined && remarks) remarks.value=owner.dataset.remarksSnapshot;
                updateStatusPopover(owner,p);
            }
            p.hidden=true;
            document.querySelectorAll('.fams-status-backdrop[data-owner-form-id="'+(p.dataset.ownerFormId||'')+'"]').forEach(b=>b.remove());
        });
    }
});
document.addEventListener('keydown',e=>{
    if(e.key!=='Escape') return;
    document.querySelectorAll('.fams-status-portal:not([hidden])').forEach(p=>{
        const owner=document.getElementById(p.dataset.ownerFormId||'');
        if(owner){
            const select=owner.querySelector('[data-status-select]');
            const release=p.querySelector('[data-release-date]');
            const remarks=p.querySelector('[data-status-remarks]');
            if(owner.dataset.statusSnapshot!==undefined && select) select.value=owner.dataset.statusSnapshot;
            if(owner.dataset.releaseSnapshot!==undefined && release) release.value=owner.dataset.releaseSnapshot;
            if(owner.dataset.remarksSnapshot!==undefined && remarks) remarks.value=owner.dataset.remarksSnapshot;
            updateStatusPopover(owner,p);
        }
        p.hidden=true;
        document.querySelectorAll('.fams-status-backdrop[data-owner-form-id="'+(p.dataset.ownerFormId||'')+'"]').forEach(b=>b.remove());
    });
    document.querySelectorAll('.fams-notification-portal:not([hidden])').forEach(p=>p.hidden=true);
});
document.addEventListener('DOMContentLoaded',()=>window.FAMSInitStatusForms());

/* V5.11 live FAMS search + non-refresh reporting period + user chat. */
window.FAMSInitRecordsUI=function(){
    const form=document.getElementById('famsSearchForm'), body=document.getElementById('famsRecordBody'), count=document.getElementById('famsRecordCount');
    if(form && body){
        const q=form.querySelector('input[name="q"]'), status=form.querySelector('select[name="status"]'); let timer;
        let requestId=0; const refresh=async(page=1,writeUrl=false)=>{ const id=++requestId; const tableRegion=body.closest('.table-wrap'); tableRegion?.setAttribute('aria-busy','true'); const params=new URLSearchParams({q:q?.value||'',status:status?.value||'',page:String(page)}); try{const r=await fetch('records_search.php?'+params.toString(),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});if(!r.ok)throw new Error('Search request failed ('+r.status+')');const d=await r.json();if(id!==requestId)return;if(window.FAMSCloseStatusPopovers)window.FAMSCloseStatusPopovers();body.innerHTML=d.html; if(window.FAMSInitStatusForms)window.FAMSInitStatusForms(); if(count)count.textContent=d.count.toLocaleString()+' record(s) found';const pager=document.getElementById('famsMasterlistPagination');if(pager)pager.innerHTML=d.pagination||'';const sheet=document.getElementById('displayFamsSheet'); if(sheet)sheet.href='fams_sheet_editor.php?'+new URLSearchParams({q:q?.value||'',status:status?.value||''}).toString();if(writeUrl){const url=new URL(window.location.href);['q','status','page'].forEach(k=>url.searchParams.delete(k));params.forEach((v,k)=>{if(v!==''&&!(k==='page'&&v==='1'))url.searchParams.set(k,v)});history.replaceState({},'',url);} }catch(e){if(id===requestId){console.warn('Live FAMS search failed',e);window.FAMSShowToast?.('Unable to refresh the masterlist. Check your connection and try again.','error');}}finally{if(id===requestId)tableRegion?.removeAttribute('aria-busy');} };
        q?.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>refresh(1,true),Number(window.FAMS_DEBOUNCE_MS)||260);}); status?.addEventListener('change',()=>refresh(1,true));
        form.addEventListener('submit',e=>{e.preventDefault();refresh(1,true);});
        document.getElementById('famsMasterlistPagination')?.addEventListener('click',e=>{const a=e.target.closest('a[data-masterlist-page]');if(!a)return;e.preventDefault();refresh(Number(a.dataset.masterlistPage)||1,true);});
    }

    const chatLayout=document.querySelector('.chat-layout');
    if(chatLayout){
      // The fragment's server-rendered identity is authoritative for this page.
      // Never retain a previous page's CHAT_USER_ID after partial navigation.
      const pageUserId=Number(chatLayout.dataset.chatUserId||0);
      if(pageUserId>0) window.CHAT_USER_ID=pageUserId;
      window.CHAT_IS_ADMIN=chatLayout.dataset.chatAdmin==='1';
      window.CHAT_READ_ONLY=false;
    }
    if(window.CHAT_USER_ID && chatLayout && !window.FAMS_SHARED_CHAT_CONTROLLER){
      window.FAMSChatDispose?.();
      const chatAbortController=new AbortController();
      const chatSignal=chatAbortController.signal;
      window.FAMSChatDispose=()=>{ try{chatAbortController.abort();}catch(_){} try{if(window.__famsChatInterval)clearInterval(window.__famsChatInterval);}catch(_){} window.__famsChatInterval=null; document.body.classList.remove('chat-keyboard-active','chat-trail-open'); };
      const chatUsersPanel=document.querySelector('.chat-users'), chatGroups=document.querySelector('.chat-office-groups'), title=document.getElementById('chatTitle'), modeLabel=document.getElementById('chatModeLabel'), box=document.getElementById('chatMessages'), boxWrap=document.querySelector('.chat-messages-wrap'), jumpLatest=document.getElementById('chatJumpLatest'), recipient=document.getElementById('chatRecipient'), groupInput=document.getElementById('chatGroup'), form=document.getElementById('chatForm'), input=document.getElementById('chatMessage'), sendButton=document.getElementById('chatSendButton'), status=document.getElementById('chatComposeStatus'), clearAll=document.getElementById('chatClearAll'), unreadSummary=document.getElementById('chatUnreadSummary');
      const chatSearch=document.getElementById('chatOptionsSearch'), chatSearchClear=document.getElementById('chatOptionsSearchClear');
      const applyChatSearch=()=>{
        const q=(chatSearch?.value||'').trim().toLowerCase();
        if(chatSearchClear) chatSearchClear.hidden=!q;
        document.querySelectorAll('.chat-office-section').forEach(section=>{
          const heading=section.querySelector('.chat-office-heading');
          const members=section.querySelector('.chat-office-members');
          const items=[...section.querySelectorAll('.chat-user')];
          if(!q){ items.forEach(item=>item.hidden=false); section.hidden=false; return; }
          let matched=false;
          items.forEach(item=>{
            const text=(item.textContent||'').toLowerCase();
            const hit=text.includes(q);
            item.hidden=!hit;
            if(hit) matched=true;
          });
          section.hidden=!matched;
          if(matched && members && members.hidden){
            members.hidden=false;
            heading?.setAttribute('aria-expanded','true');
            const chev=heading?.querySelector('.chat-office-chevron'); if(chev)chev.textContent='▾';
          }
        });
      };
      chatSearch?.addEventListener('input',applyChatSearch,{signal:chatSignal});
      chatSearchClear?.addEventListener('click',()=>{if(chatSearch){chatSearch.value='';chatSearch.focus();}applyChatSearch();},{signal:chatSignal});

      const trailModal=document.getElementById('chatTrailModal'), trailList=document.getElementById('chatTrailList'), trailCurrent=document.getElementById('chatTrailCurrent'), trailTitle=document.getElementById('chatTrailTitle');
      let selected=null, loading=false, lastMessageSignature='', pressTimer=null, pressBubble=null, pressStarted=false, longPressActivated=false, suppressNextBubbleClick=false, lastReadCursor=0, pressDuration=1000;

      const clearButtonState=()=>{ if(clearAll)clearAll.disabled=!selected; };

      const applyOfficeCollapseState=()=>{
        document.querySelectorAll('.chat-office-heading').forEach(head=>{
          const section=head.closest('.chat-office-section'), members=section?.querySelector('.chat-office-members'), key='fams-chat-office-'+(section?.dataset.officeGroup||'').toLowerCase();
          let collapsed=true; try{const saved=localStorage.getItem(key); collapsed=(saved!=='expanded');}catch(e){}
          if(!members)return; members.hidden=collapsed; head.setAttribute('aria-expanded',String(!collapsed)); const chev=head.querySelector('.chat-office-chevron'); if(chev)chev.textContent=collapsed?'▸':'▾';
        });
      };
      applyOfficeCollapseState();

      const csrf=()=>document.querySelector('input[name="csrf_token"]')?.value||document.getElementById('notificationBell')?.dataset.csrf||'';
      const setStatus=(msg,kind='')=>{if(status){status.textContent=msg;status.className='chat-compose-status '+kind;}};
      const api=async(url,options={})=>{
        const r=await fetch(url,{cache:'no-store',credentials:'same-origin',...options});
        const text=await r.text(); let d=null;
        try{d=JSON.parse(text);}catch(e){
          if(r.status===401||r.redirected||/login\.php/i.test(r.url||'')||/<html[\s>]/i.test(text)) throw new Error('Your login session is no longer valid. Reload the page; if this continues, sign in again.');
          if(r.status===419||/expired request token|request token expired/i.test(text)) throw new Error('Your chat request token expired. Refresh the page and try again.');
          throw new Error('The chat server returned an invalid response. Please try again.');
        }
        if(!r.ok||d?.ok===false)throw new Error(d?.error||'The chat request could not be completed.');
        // The server session is authoritative. Refresh the client identity on
        // every successful Chat response so a stale fragment/global can never
        // make another account's messages appear as the logged-in user's.
        if(d?.identity?.id){
          const serverId=Number(d.identity.id);
          if(serverId>0) window.CHAT_USER_ID=serverId;
          if(typeof d.identity.role==='string') window.CHAT_IS_ADMIN=d.identity.role==='admin';
          if(typeof d.identity.full_name==='string') window.CHAT_USER_NAME=d.identity.full_name;
          if(typeof d.identity.facility==='string') window.CHAT_USER_FACILITY=d.identity.facility;
        }
        return d;
      };

      const updateChatBadges=async()=>{
        try{
          const d=await api('chat_api.php?action=unread'), counts=d.counts||{};
          document.querySelectorAll('[data-chat-badge]').forEach(b=>{
            const n=Math.max(0,Number(counts[b.dataset.chatBadge]||0)); b.textContent=n>99?'99+':String(n); b.hidden=n===0;
          });
          // Keep an unread indicator on the collapsed office/group heading itself,
          // so users can see new activity even when the member list is closed.
          document.querySelectorAll('[data-chat-office-badge]').forEach(b=>{
            const section=b.closest('.chat-office-section');
            const office=String(b.dataset.chatOfficeBadge||'').toUpperCase();
            let n=0;
            if(office==='ALL'){
              n=Math.max(0,Number(counts['g:ALL']||0));
            }else if(section){
              n=Math.max(0,Number(counts['g:'+office]||0));
              section.querySelectorAll('[data-chat-badge^="u:"]').forEach(item=>{ n+=Math.max(0,Number(item.textContent||0)); });
            }
            b.textContent=n>99?'99+':String(n); b.hidden=n===0;
          });
          const total=Math.max(0,Number(d.unread_messages||0));
          document.querySelectorAll('[data-notif-category="chat"]').forEach(b=>{b.textContent=total>99?'99+':String(total);b.hidden=total===0;});
          const bell=document.getElementById('notificationBell');
          if(bell){
            bell.classList.toggle('has-chat-unread',total>0);
            bell.setAttribute('aria-label',total>0?`Notifications, ${total>99?'more than 99':total} unread chat message${total===1?'':'s'}`:'Notifications');
          }
          if(unreadSummary){unreadSummary.textContent=total?`${total} unread message${total===1?'':'s'}`:'';unreadSummary.hidden=total===0;}
        }catch(e){
          document.querySelectorAll('[data-chat-badge],[data-notif-category="chat"]').forEach(b=>{b.textContent='';b.hidden=true;});
          if(unreadSummary){unreadSummary.textContent='';unreadSummary.hidden=true;}
        }
      };

      const markReadThrough=async(maxId)=>{
        if(!selected||!maxId)return;
        const mark=new FormData(); mark.append('csrf_token',csrf()); mark.append('action','mark_read'); mark.append('max_message_id',String(maxId));
        if(selected.type==='group')mark.append('group',selected.group);else mark.append('user_id',selected.id);
        await api('chat_api.php',{method:'POST',body:mark});
      };

      const closeTrail=()=>{
        if(!trailModal)return;
        trailModal.hidden=true; trailModal.setAttribute('aria-hidden','true'); document.body.classList.remove('chat-trail-open');
      };
      const openTrail=async(messageId)=>{
        if(!trailModal)return;
        trailModal.hidden=false; trailModal.setAttribute('aria-hidden','false'); document.body.classList.add('chat-trail-open');
        if(trailTitle)trailTitle.textContent='Edit Trail';
        if(trailCurrent)trailCurrent.innerHTML='<div class="chat-trail-loading">Loading message history…</div>';
        if(trailList)trailList.innerHTML='';
        try{
          const d=await api('chat_api.php?action=trail&message_id='+encodeURIComponent(messageId));
          const trails=d.trails||[];
          const original=trails.length?trails[trails.length-1].message_before||'':'';
          if(trailCurrent)trailCurrent.innerHTML=original?`<span class="chat-trail-label">Original message</span><p>${escapeHtml(original)}</p>`:'<span class="chat-trail-label">Message history</span><p>No previous version was recorded.</p>';
          if(!trails.length){
            if(trailList)trailList.innerHTML='<div class="chat-trail-empty">No edit or deletion changes have been recorded for this message.</div>';
            return;
          }
          if(trailList)trailList.innerHTML=trails.map((t,i)=>{
            const action=escapeHtml(t.action||'Changed'), who=escapeHtml(t.full_name||'User'), when=escapeHtml(t.created_at||'');
            const before=escapeHtml(t.message_before||'');
            return `<article class="chat-trail-item"><div class="chat-trail-dot"></div><div class="chat-trail-item-body"><div class="chat-trail-meta"><strong>${action}</strong><span>${who}</span><time>${when}</time></div>${before?`<div class="chat-trail-version"><span>Previous text</span><p>${before}</p></div>`:''}</div></article>`;
          }).join('');
        }catch(err){
          if(trailCurrent)trailCurrent.innerHTML=`<div class="chat-trail-error">${escapeHtml(err.message||'Unable to load message trail.')}</div>`;
        }
      };
      trailModal?.addEventListener('click',e=>{if(e.target.closest('[data-chat-trail-close]'))closeTrail();},{signal:chatSignal});
      document.addEventListener('keydown',e=>{
        if(e.key==='Escape'){
          document.querySelectorAll('.chat-message-actions.visible').forEach(a=>a.classList.remove('visible'));
          document.querySelectorAll('.chat-bubble.long-press-target').forEach(b=>b.classList.remove('long-press-target'));
          releasePress();
          if(!trailModal?.hidden)closeTrail();
        }
      },{signal:chatSignal});

      const releasePress=()=>{
        if(pressTimer){clearTimeout(pressTimer);pressTimer=null;}
        if(pressBubble){
          pressBubble.classList.remove('pressing');
          if(longPressActivated)suppressNextBubbleClick=true;
          pressBubble=null;
        }
        pressStarted=false;
        longPressActivated=false;
      };
      const beginLongPress=(bubble)=>{
        releasePress();
        pressBubble=bubble; pressStarted=true; longPressActivated=false; suppressNextBubbleClick=false;
        bubble.classList.add('pressing');
        pressTimer=setTimeout(()=>{
          if(!pressBubble)return;
          const actions=pressBubble.querySelector('.chat-message-actions');
          if(!actions){
            pressBubble.classList.remove('pressing');
            pressBubble=null; pressStarted=false;
            return;
          }
          document.querySelectorAll('.chat-message-actions.visible').forEach(a=>a.classList.remove('visible'));
          document.querySelectorAll('.chat-bubble.long-press-target').forEach(b=>b.classList.remove('long-press-target'));
          pressBubble.classList.remove('pressing');
          pressBubble.classList.add('long-press-target');
          actions.classList.add('visible');
          longPressActivated=true;
          pressStarted=false;
          pressTimer=null;
        },pressDuration);
      };

      const updateJumpLatest=()=>{
        if(!jumpLatest||!box)return;
        const distance=box.scrollHeight-box.scrollTop-box.clientHeight;
        jumpLatest.hidden=distance<140;
      };
      const scrollLatest=()=>{if(box){box.scrollTo({top:box.scrollHeight,behavior:'smooth'});updateJumpLatest();}};

      const getChatKey=chat=>chat?.type==='group'?`g:${chat.group}`:(chat?`u:${chat.id}`:'');
      let selectionToken=0;
      const load=async(expectedKey=null,token=selectionToken)=>{
        if(!selected||loading)return;
        const requestKey=expectedKey||getChatKey(selected);
        loading=true;
        try{
          const requested=selected;
          const query=requested.type==='group'?'group='+encodeURIComponent(requested.group):'user_id='+encodeURIComponent(requested.id);
          const d=await api('chat_api.php?action=messages&'+query), messages=d.messages||[];
          if(token!==selectionToken || getChatKey(selected)!==requestKey){ return; }
          const signature=messages.map(m=>`${m.id}|${m.message}|${m.edited_at||''}|${m.deleted_at||''}|${m.created_at||''}`).join('§');
          const shouldRender=signature!==lastMessageSignature;
          if(shouldRender){
            const wasNearBottom=(box.scrollHeight-box.scrollTop-box.clientHeight)<70;
            const oldHeight=box.scrollHeight;
            const cursor=Math.max(0,Number(d.read_cursor)||0);
            let firstUnreadIndex=-1;
            if(lastMessageSignature==='' && cursor>0){
              firstUnreadIndex=messages.findIndex(m=>Number(m.id)>cursor && Number(m.sender_id)!==Number(window.CHAT_USER_ID));
            }
            if(firstUnreadIndex<0 && lastMessageSignature!=='' && cursor>lastReadCursor){
              firstUnreadIndex=messages.findIndex(m=>Number(m.id)>lastReadCursor && Number(m.sender_id)!==Number(window.CHAT_USER_ID));
            }
            const html=[];
            messages.forEach((m,i)=>{
              const mine=Number(m.sender_id)===Number(window.CHAT_USER_ID),deleted=!!m.deleted_at;
              const prev=messages[i-1], next=messages[i+1];
              const samePrev=i!==firstUnreadIndex && !!prev && Number(prev.sender_id)===Number(m.sender_id) && !prev.deleted_at && !deleted;
              const sameNext=!!next && Number(next.sender_id)===Number(m.sender_id) && !next.deleted_at && !deleted;
              const groupClass=(samePrev?' chat-bubble-continuation':' chat-bubble-first')+(sameNext?' chat-bubble-has-next':' chat-bubble-last');
              if(i===firstUnreadIndex)html.push('<div class="chat-unread-divider" role="separator"><span>New messages</span></div>');
              const actions=window.CHAT_READ_ONLY?'':(!deleted&&mine?`<span class="chat-message-actions"><button type="button" data-chat-edit="${m.id}">Edit</button><button type="button" data-chat-delete="${m.id}">Delete</button><button type="button" data-chat-trail="${m.id}">Trail</button></span>`:(window.CHAT_IS_ADMIN&&!deleted?`<span class="chat-message-actions"><button type="button" data-chat-delete="${m.id}">Delete</button><button type="button" data-chat-trail="${m.id}">Trail</button></span>`:''));
              html.push(`<div class="chat-bubble ${mine?'mine':''} ${deleted?'deleted':''}${groupClass}" data-message-id="${m.id}"><span class="chat-press-progress" aria-hidden="true"></span><span class="chat-message-text">${escapeHtml(m.message)}</span><small>${escapeHtml(m.sender_name||'')} • ${escapeHtml(m.created_at)} ${m.edited_at?'• edited':''}</small>${actions}</div>`);
            });
            box.innerHTML=html.join('')||'<div class="chat-empty">No Conversations Yet</div>';
            if(wasNearBottom||!lastMessageSignature)box.scrollTop=box.scrollHeight;
            else box.scrollTop=Math.max(0,box.scrollTop+(box.scrollHeight-oldHeight));
            lastMessageSignature=signature;
            lastReadCursor=cursor;
            updateJumpLatest();
          }
          const maxDisplayed=messages.reduce((max,m)=>Math.max(max,Number(m.id)||0),0);
          if(maxDisplayed)await markReadThrough(maxDisplayed);
          setStatus('',''); await updateChatBadges();
        }catch(err){setStatus(err.message||'Unable to load chat.','error');}
        finally{loading=false;updateJumpLatest();}
      };
      const syncActiveChatGroup=()=>{
        document.querySelectorAll('.chat-office-section.active-chat-group').forEach(section=>section.classList.remove('active-chat-group'));
        if(selected?.type==='group'){
          const section=document.querySelector(`.chat-office-section[data-office-group=\"${CSS.escape(String(selected.group))}\"]`);
          section?.classList.add('active-chat-group');
        }
      };

      const selectChatOption=(u)=>{
        if(!u)return;
        document.querySelectorAll('.chat-message-actions.visible').forEach(a=>a.classList.remove('visible'));
        document.querySelectorAll('.chat-bubble.long-press-target').forEach(b=>b.classList.remove('long-press-target'));
        suppressNextBubbleClick=false; releasePress();
        document.querySelectorAll('.chat-user.selected').forEach(x=>x.classList.remove('selected')); u.classList.add('selected');
        const type=u.dataset.chatType||'user';
        selected=type==='group'?{type:'group',group:u.dataset.chatGroup}:{type:'user',id:Number(u.dataset.userId)};
        syncActiveChatGroup();
        recipient.value=selected.type==='user'?selected.id:''; groupInput.value=selected.type==='group'?selected.group:'';
        const name=u.querySelector('strong')?.textContent||'Chat'; title.textContent=name; modeLabel.textContent=selected.type==='group'?'OFFICE GROUP CHAT':'PRIVATE CHAT';
        selectionToken++; const newKey=getChatKey(selected); lastMessageSignature=''; lastReadCursor=0;
        if(box)box.innerHTML='<div class="chat-empty">Loading conversation…</div>';
        if(jumpLatest)jumpLatest.hidden=true; if(unreadSummary){unreadSummary.textContent='';unreadSummary.hidden=true;}
        clearButtonState(); setStatus('Loading conversation…','loading'); load(newKey,selectionToken);
      };
      chatUsersPanel?.addEventListener('click',e=>{
        const heading=e.target.closest('.chat-office-heading');
        if(heading && chatUsersPanel.contains(heading)){
          e.preventDefault(); e.stopPropagation();
          const section=heading.closest('.chat-office-section'), members=section?.querySelector('.chat-office-members');
          if(!members)return; const next=!members.hidden; members.hidden=next; heading.setAttribute('aria-expanded',String(!next));
          const key='fams-chat-office-'+(section?.dataset.officeGroup||'').toLowerCase(); try{localStorage.setItem(key,next?'collapsed':'expanded');}catch(err){}
          const chev=heading.querySelector('.chat-office-chevron'); if(chev)chev.textContent=next?'▸':'▾'; return;
        }
        const u=e.target.closest('.chat-user'); if(u && chatUsersPanel.contains(u)){e.preventDefault();e.stopPropagation();selectChatOption(u);}
      },{signal:chatSignal});

      form?.addEventListener('submit',async e=>{
        e.preventDefault();if(window.CHAT_READ_ONLY)return;
        if(!selected){setStatus('Select a user or office group first.','error');return;}
        const msg=input.value.trim();if(!msg)return;
        const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','send');fd.append('message',msg);
        if(selected.type==='group')fd.append('group',selected.group);else fd.append('user_id',selected.id);
        if(sendButton)sendButton.disabled=true;setStatus('Sending…','loading');
        try{await api('chat_api.php',{method:'POST',body:fd});input.value='';setStatus('Message sent.','success');await load();}
        catch(err){setStatus(err.message||'Message could not be sent.','error');}
        finally{if(sendButton)sendButton.disabled=false;input.focus();}
      },{signal:chatSignal});

      const emojiButton=document.getElementById('chatEmojiButton'), emojiPicker=document.getElementById('chatEmojiPicker');
      const emojiList=['😀','😃','😄','😁','😂','😊','🙂','😉','😍','🥰','😘','😎','🤗','🤔','😅','😭','😢','😡','👍','👏','🙏','❤️','💖','🎉','✨','🔥','💯','✅','🌸','🎂'];
      const insertEmoji=(emoji)=>{ if(!input)return; const start=input.selectionStart??input.value.length,end=input.selectionEnd??start; input.value=input.value.slice(0,start)+emoji+input.value.slice(end); const pos=start+emoji.length; input.setSelectionRange(pos,pos); input.focus(); resizeComposer(); };
      if(emojiPicker){ emojiPicker.innerHTML=emojiList.map(e=>`<button type="button" class="chat-emoji" data-emoji="${e}" aria-label="Insert ${e}">${e}</button>`).join(''); emojiPicker.hidden=true; }
      emojiButton?.addEventListener('click',e=>{e.stopPropagation(); if(emojiPicker)emojiPicker.hidden=!emojiPicker.hidden;},{signal:chatSignal});
      emojiPicker?.addEventListener('click',e=>{const b=e.target.closest('[data-emoji]');if(b){insertEmoji(b.dataset.emoji);emojiPicker.hidden=true;}},{signal:chatSignal});
      document.addEventListener('click',e=>{if(emojiPicker&&!emojiPicker.hidden&&!e.target.closest('.chat-emoji-picker')&&!e.target.closest('#chatEmojiButton'))emojiPicker.hidden=true;},{signal:chatSignal});

      const resizeComposer=()=>{
        if(!input)return;
        input.style.height='auto';
        input.style.height=Math.min(input.scrollHeight,150)+'px';
      };
      input?.addEventListener('input',resizeComposer,{signal:chatSignal});
      resizeComposer();

      input?.addEventListener('keydown',e=>{
        if(e.key==='Enter'){
          if(e.shiftKey){
            // Shift+Enter intentionally inserts a newline in the composer.
            return;
          }
          e.preventDefault();
          form?.requestSubmit();
        }
      },{signal:chatSignal});

      box?.addEventListener('pointerdown',e=>{
        const bubble=e.target.closest('.chat-bubble'); if(!bubble||e.target.closest('.chat-message-actions'))return;
        beginLongPress(bubble);
      },{signal:chatSignal});
      box?.addEventListener('pointerup',releasePress,{signal:chatSignal});
      box?.addEventListener('pointercancel',releasePress,{signal:chatSignal});
      box?.addEventListener('pointerleave',releasePress,{signal:chatSignal});
      box?.addEventListener('scroll',releasePress,{signal:chatSignal});

      box?.addEventListener('click',async e=>{
        const clickedBubble=e.target.closest('.chat-bubble');
        if(clickedBubble && suppressNextBubbleClick && !e.target.closest('.chat-message-actions')){
          suppressNextBubbleClick=false;
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        if(clickedBubble && !e.target.closest('.chat-message-actions')){
          document.querySelectorAll('.chat-message-actions.visible').forEach(a=>{
            if(!clickedBubble.contains(a)) a.classList.remove('visible');
          });
          document.querySelectorAll('.chat-bubble.long-press-target').forEach(b=>{
            if(b!==clickedBubble)b.classList.remove('long-press-target');
          });
        }
        const eb=e.target.closest('[data-chat-edit]'), db=e.target.closest('[data-chat-delete]'), tb=e.target.closest('[data-chat-trail]');
        if(!eb&&!db&&!tb)return;
        e.stopPropagation();
        const bubble=e.target.closest('.chat-bubble');
        if(eb){
          const old=bubble?.querySelector('span')?.textContent||'', val=prompt('Edit your message:',old);
          if(val===null||!val.trim())return;
          const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','edit');fd.append('message_id',eb.dataset.chatEdit);fd.append('message',val.trim());
          try{await api('chat_api.php',{method:'POST',body:fd});lastMessageSignature='';await load();}catch(err){setStatus(err.message||'Message could not be edited.','error');}
        }
        if(db){
          if(!confirm('Delete this message? An edit trail will be retained.'))return;
          const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','delete');fd.append('message_id',db.dataset.chatDelete);
          try{await api('chat_api.php',{method:'POST',body:fd});lastMessageSignature='';await load();}catch(err){setStatus(err.message||'Message could not be deleted.','error');}
        }
        if(tb)openTrail(tb.dataset.chatTrail);
      },{signal:chatSignal});

      clearAll?.addEventListener('click',async()=>{
        if(!selected){setStatus('Select a chat first.','error');return;}
        const label=selected.type==='group'?(selected.group==='ALL'?'All Users':selected.group+' Office'):(title?.textContent||'selected private chat');
        if(!confirm(`Clear all messages in ${label}? This only clears the selected chat. The audit trail will retain the action.`))return;
        const fd=new FormData();fd.append('csrf_token',csrf());fd.append('action','clear_all');
        if(selected.type==='group')fd.append('group',selected.group);else fd.append('user_id',selected.id);
        clearAll.disabled=true;
        try{const d=await api('chat_api.php',{method:'POST',body:fd});setStatus(`${d.cleared||0} message${Number(d.cleared)===1?'':'s'} cleared from this chat.`,'success');lastMessageSignature='';await load();await updateChatBadges();}
        catch(err){setStatus(err.message||'Messages could not be cleared.','error');}
        finally{clearButtonState();}
      },{signal:chatSignal});

      jumpLatest?.addEventListener('click',scrollLatest,{signal:chatSignal});
      box?.addEventListener('scroll',updateJumpLatest,{passive:true,signal:chatSignal});
      window.visualViewport?.addEventListener('resize',()=>{
        if(window.innerWidth<=767 && document.activeElement===input){
          requestAnimationFrame(()=>input?.scrollIntoView({block:'nearest'}));
        }
      },{signal:chatSignal});
      input?.addEventListener('focus',()=>document.body.classList.add('chat-keyboard-active'),{signal:chatSignal});
      input?.addEventListener('blur',()=>document.body.classList.remove('chat-keyboard-active'),{signal:chatSignal});

      let chatDirectorySignature='';
      const refreshChatDirectory=async()=>{
        const currentChatDirectory=document.querySelector('.chat-office-groups');
        if(!currentChatDirectory || document.visibilityState!=='visible') return;
        try{
          const r=await fetch('chat.php?fragment=chat-options',{cache:'no-store',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
          if(!r.ok)return; const html=await r.text();
          const wrap=document.createElement('div'); wrap.innerHTML=html.trim(); const next=wrap.querySelector('.chat-office-groups'); if(!next)return;
          const signature=next.innerHTML; if(signature===chatDirectorySignature)return; chatDirectorySignature=signature;
          const selectedKey=getChatKey(selected);
          currentChatDirectory.replaceWith(next);
          const replacement=document.querySelector('.chat-office-groups');
          if(replacement){
            replacement.querySelectorAll('.chat-user').forEach(u=>{if(getChatKey(u.dataset.chatType==='group'?{type:'group',group:u.dataset.chatGroup}:{type:'user',id:Number(u.dataset.userId)})===selectedKey)u.classList.add('selected');});
            applyOfficeCollapseState();
            syncActiveChatGroup();
            if(chatSearch) applyChatSearch();
          }
        }catch(e){}
      };
      updateChatBadges();
      refreshChatDirectory();
      window.__famsChatInterval=setInterval(()=>{if(document.visibilityState==='visible'){updateChatBadges();refreshChatDirectory();if(selected)load();}},5000);
    }
    function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
};

document.addEventListener('DOMContentLoaded',()=>window.FAMSInitRecordsUI());

/* Version 0.928 — preserve sidebar navigation scroll position by percentage. */
(function(){
  const KEY='fams.sidebar.nav.scrollPercent';
  const getNav=()=>document.querySelector('.sidebar-nav-scroll');
  const save=()=>{
    const nav=getNav(); if(!nav) return;
    const max=Math.max(0,nav.scrollHeight-nav.clientHeight);
    const pct=max>0 ? Math.max(0,Math.min(1,nav.scrollTop/max)) : 0;
    try{localStorage.setItem(KEY,String(pct));}catch(e){}
  };
  const restore=()=>{
    const nav=getNav(); if(!nav) return;
    let pct=0; try{pct=Number(localStorage.getItem(KEY)||0);}catch(e){}
    if(!Number.isFinite(pct)) pct=0;
    const apply=()=>{const max=Math.max(0,nav.scrollHeight-nav.clientHeight);nav.scrollTop=Math.round(Math.max(0,Math.min(1,pct))*max);};
    requestAnimationFrame(()=>{apply(); requestAnimationFrame(apply);});
  };
  const bind=()=>{
    const nav=getNav(); if(!nav || nav.dataset.scrollPercentBound==='1') return;
    nav.dataset.scrollPercentBound='1';
    nav.addEventListener('scroll',save,{passive:true});
    nav.querySelectorAll('a').forEach(link=>link.addEventListener('click',save,{capture:true}));
    restore();
  };
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',bind,{once:true}); else bind();
  document.addEventListener('fams:page-replaced',()=>setTimeout(bind,0));
  window.addEventListener('beforeunload',save);
})();


/* V5.13 — facility-aware account editor identity and role locking. */
document.addEventListener('DOMContentLoaded',()=>{
  const facility=document.getElementById('userFacility'), role=document.getElementById('userRole'), logo=document.getElementById('userFacilityLogo');
  if(!facility||!role)return;
  const logos=window.FAMS_ACCOUNT_FACILITY_LOGOS||{};
  const labels=window.FAMS_ACCOUNT_FACILITY_LABELS||{};
  const sync=()=>{
    const key=facility.value||'LPGMA', medical=key!=='LPGMA';
    const url=logos[key]||logos.LPGMA, label=labels[key]||key;
    if(logo){logo.innerHTML=url?`<img src="${String(url).replace(/&/g,'&amp;').replace(/"/g,'&quot;')}" alt="${label} logo" decoding="sync">`:`<span class="facility-initials">${label}</span>`;}
    [...role.options].forEach(o=>{o.hidden=medical ? !o.dataset.medicalRole : !o.dataset.officeRole;o.disabled=o.hidden;});
    const selected=role.selectedOptions[0];
    if(!selected || selected.disabled) role.value=medical?'social_worker':'encoder';
    role.classList.remove('role-admin','role-manager','role-staff','role-simc');
    role.classList.add(role.value==='admin'?'role-admin':(role.value==='manager'?'role-manager':(medical?'role-simc':'role-staff')));
  };
  facility.addEventListener('change',sync); sync();
});

/* v0.0170: MAIFIP masterlist uses the native single table viewport.
   No mirrored scrollbar elements or scroll-position synchronization are needed. */
window.FAMSInitRecordsScroll=function(){ return; };


/* Version 1.1: action menus are fully independent of table/panel overflow. */
document.addEventListener('DOMContentLoaded',()=>{
  const triggers=[...document.querySelectorAll('.action-menu-trigger')];
  if(!triggers.length)return;
  let portal=null, activeTrigger=null;
  const close=()=>{
    if(portal){ portal.remove(); portal=null; }
    if(activeTrigger){ activeTrigger.setAttribute('aria-expanded','false'); activeTrigger=null; }
  };
  const position=()=>{
    if(!portal||!activeTrigger)return;
    const r=activeTrigger.getBoundingClientRect(), edge=8, gap=6;
    portal.style.visibility='hidden';
    portal.style.left='0px'; portal.style.top='0px';
    const w=Math.min(Math.max(portal.scrollWidth,210),Math.max(210,window.innerWidth-edge*2));
    const h=Math.min(Math.max(portal.scrollHeight,60),Math.max(60,window.innerHeight-edge*2));
    portal.style.width=w+'px';
    let left=r.right-w;
    left=Math.max(edge,Math.min(left,window.innerWidth-w-edge));
    let top=r.bottom+gap;
    if(top+h>window.innerHeight-edge) top=r.top-h-gap;
    top=Math.max(edge,Math.min(top,window.innerHeight-h-edge));
    portal.style.left=Math.round(left)+'px';
    portal.style.top=Math.round(top)+'px';
    portal.style.visibility='visible';
  };
  const open=(trigger)=>{
    close();
    const source=trigger.parentElement?.querySelector('.action-menu-panel');
    if(!source)return;
    portal=document.createElement('div');
    portal.className='fams-action-portal action-menu-panel';
    portal.setAttribute('role','menu');
    portal.innerHTML=source.innerHTML;
    portal.style.display='grid';
    portal.style.visibility='hidden';
    document.body.appendChild(portal);
    activeTrigger=trigger;
    trigger.setAttribute('aria-expanded','true');
    portal.addEventListener('click',e=>e.stopPropagation());
    portal.addEventListener('mousedown',e=>e.stopPropagation());
    portal.querySelectorAll('a,button,input,select,textarea').forEach(el=>{
      el.addEventListener('click',e=>e.stopPropagation());
    });
    requestAnimationFrame(position);
  };
  triggers.forEach(trigger=>{
    trigger.addEventListener('click',e=>{
      e.preventDefault();
      e.stopPropagation();
      if(activeTrigger===trigger) close(); else open(trigger);
    });
    trigger.addEventListener('mousedown',e=>e.stopPropagation());
  });
  document.addEventListener('click',e=>{
    if(portal && portal.contains(e.target)) return;
    if(activeTrigger && e.target!==activeTrigger) close();
  });
  document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
  window.addEventListener('resize',position);
  window.addEventListener('scroll',position,true);
});


/* FAMS navigation: preserve the application shell and replace page content with a fragment when supported. */
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('a[data-full-navigation="1"]').forEach(a=>a.addEventListener('click',e=>{
    if(e.defaultPrevented || e.button!==0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault(); window.location.assign(a.href);
  }));
});

/* FAMS 1.8 — preserve the application shell while navigating to Masterlist. */
document.addEventListener('DOMContentLoaded',()=>{
  const initFamsNavigation=()=>{
    document.querySelectorAll('a[data-ajax-navigation="1"]').forEach(a=>{
      if(a.dataset.famsBound==='1') return;
      a.dataset.famsBound='1';
      a.addEventListener('click',async e=>{
        if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey) return;
        e.preventDefault();
        const clickedUrl=new URL(a.href,location.href); if(clickedUrl.pathname===location.pathname && clickedUrl.search===location.search) return;
        const target=a.href;
        // InfinityFree may reject fragment/AJAX requests for chat.php and redirect
        // them to its external 403 page. Chat is stateful and should use a normal
        // same-origin document navigation rather than the generic fragment loader.
        if(clickedUrl.pathname.toLowerCase().endsWith('/chat.php')){
          window.location.assign(target);
          return;
        }
        // Keep the facility-switch return target synchronized with the page
        // currently displayed in the shell. FAMS uses partial navigation, so
        // the PHP-rendered hidden return_to field may still contain the
        // previous sidebar page (e.g. dashboard.php).
        try {
          const targetUrl = new URL(target, location.href);
          const returnField = document.querySelector('.sidebar-facility-selector input[name="return_to"]');
          if (returnField) returnField.value = targetUrl.pathname.split('/').pop() + (targetUrl.search || '');
        } catch (_) {}
        document.body.classList.add('fams-navigating');
        if(document.querySelector('.chat-layout')) window.FAMSChatDispose?.();
        let bar=document.querySelector('.fams-nav-progress');
        if(!bar){bar=document.createElement('div');bar.className='fams-nav-progress';document.body.appendChild(bar);}
        const currentPageView=document.querySelector('#pageView');
        let loadingPageView=null;
        if(currentPageView){
          // Replace the old page immediately. The shell remains visible, but no
          // previous-page controls, cards, tables, chat messages, or forms are
          // allowed to remain underneath the loading state.
          loadingPageView=document.createElement('div');
          loadingPageView.id='pageView';
          loadingPageView.className='page-view fams-loading-page-view';
          loadingPageView.setAttribute('data-page-view','');
          loadingPageView.setAttribute('aria-busy','true');
          loadingPageView.setAttribute('aria-label','Loading selected page');
          loadingPageView.innerHTML=window.FAMSBuildPageSkeleton ? window.FAMSBuildPageSkeleton(target) : '<div class="fams-page-skeleton is-generic" aria-hidden="true"></div>';
          currentPageView.replaceWith(loadingPageView);
          const loadingTitle=loadingPageView.querySelector('.fams-skel-toolbar strong')?.textContent?.trim();
          if(loadingTitle){ const shellHeading=document.querySelector('.topbar-title h1'); if(shellHeading) shellHeading.textContent=loadingTitle; document.title=loadingTitle+' | LPGMA'; }
        }
        const loadingView=loadingPageView;
        try{
          const url=new URL(target,location.href); url.searchParams.set('fragment','1'); const r=await fetch(url.toString(),{headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
          if(!r.ok) throw new Error('Navigation failed');
          const text=await r.text();
          const doc=new DOMParser().parseFromString(text,'text/html');
          const next=doc.querySelector('#pageView');
          const current=document.querySelector('#pageView');
          if(!next||!current) throw new Error('Page content unavailable');
          // Preload incoming media before replacing #pageView. This prevents the
          // avatar/logo flash that occurred when a newly-created <img> had to
          // decode after the old page fragment was removed. Browser caching and
          // ETags handle subsequent requests efficiently.
          const incomingImages=[...next.querySelectorAll('img[src]')];
          await Promise.all(incomingImages.map(el=>new Promise(resolve=>{
            const src=el.currentSrc||el.getAttribute('src');
            if(!src){resolve();return;}
            const probe=new Image();
            probe.decoding='sync';
            probe.onload=async()=>{ try { if (probe.decode) await probe.decode(); } catch (_) {} resolve(); };
            probe.onerror=()=>resolve();
            probe.src=src;
          })));
          // Reuse already-painted media nodes when the same URL survives a
          // fragment navigation. This avoids throwing away a decoded avatar
          // or logo just to create an identical <img> element.
          const currentMedia=[...current.querySelectorAll('img[src]')];
          const mediaPool=new Map();
          currentMedia.forEach(img=>{ const key=img.currentSrc||img.getAttribute('src'); if(key && !mediaPool.has(key)) mediaPool.set(key,img); });
          incomingImages.forEach(img=>{ const key=img.currentSrc||img.getAttribute('src'); const old=key?mediaPool.get(key):null; if(old && old.parentNode){ old.className=img.className; old.setAttribute('alt',img.getAttribute('alt')||''); old.width=img.width||old.width; old.height=img.height||old.height; img.replaceWith(old); mediaPool.delete(key); } });
          current.replaceWith(next);
          const shellTitle=next.dataset.shellTitle || next.querySelector('[data-shell-title]')?.dataset.shellTitle;
          if(shellTitle){
            const shellHeading=document.querySelector('.topbar-title h1');
            if(shellHeading) shellHeading.textContent=shellTitle;
            document.title=shellTitle+' | LPGMA';
          }
          document.querySelectorAll('.sidebar nav a').forEach(n=>n.classList.remove('active'));
          const targetUrlForNav=new URL(target,location.href);
          document.querySelectorAll('.sidebar nav a').forEach(n=>{
            try{ const nu=new URL(n.href,location.href); if(nu.pathname===targetUrlForNav.pathname && nu.search===targetUrlForNav.search) n.classList.add('active'); }catch(_){}
          });
          history.pushState({famsPartial:true},'',target);
          const incomingChat=next.querySelector('.chat-layout');
          if(incomingChat){
            window.CHAT_USER_ID=Number(incomingChat.dataset.chatUserId||0);
            window.CHAT_IS_ADMIN=incomingChat.dataset.chatAdmin==='1';
            window.CHAT_READ_ONLY=false;
          } else {
            window.FAMSChatDispose?.();
          }
          // Keep the shell and page fragment in place. Do NOT perform a full
          // window.location navigation here: doing so discarded the preloaded
          // images and caused visible avatar/logo blinking on every FAMS click.
          // The fragment replacement above is the completed navigation.
          // The shell itself is retained during partial navigation; update
          // its facility-switch return target as well so a facility change
          // returns to the page that is actually visible, not the page that
          // was visible before FAMS was selected.
          const returnFieldAfterNav = document.querySelector('.sidebar-facility-selector input[name="return_to"]');
          if (returnFieldAfterNav) {
            const visibleUrl = new URL(target, location.href);
            returnFieldAfterNav.value = visibleUrl.pathname.split('/').pop() + (visibleUrl.search || '');
          }
          if(!shellTitle) document.title=doc.title||document.title;
          document.dispatchEvent(new Event('fams:page-replaced'));
          initFamsNavigation();
          // Re-bind live FAMS search and scroll helpers to the newly inserted content.
          if(window.FAMSInitRecordsUI) window.FAMSInitRecordsUI();
          if(window.FAMSInitRecordsScroll) window.FAMSInitRecordsScroll();
          if(window.FAMSInitStatusForms) window.FAMSInitStatusForms();
        }catch(err){
          // Keep the chosen-page skeleton in place on failure instead of
          // revealing the previous page underneath it.
          if(loadingView){
            loadingView.setAttribute('aria-busy','false');
            loadingView.classList.add('is-load-error');
          }
          console.error(err);
          // A transient fragment failure should not unexpectedly tear down the
          // entire application shell. Retry once, then present a clear recovery
          // action. Full navigation remains available through the retry button.
          let retry= document.querySelector('.fams-nav-retry');
          if(!retry){
            retry=document.createElement('button');
            retry.type='button'; retry.className='fams-nav-retry';
            retry.textContent='Reload this page';
            retry.title='Retry loading the selected page';
            retry.addEventListener('click',()=>window.location.assign(target),{once:true});
            document.body.appendChild(retry);
            setTimeout(()=>retry?.remove(),7000);
          }
        }finally{
          document.body.classList.remove('fams-navigating');
          bar?.remove();
        }
      });
    });
  };
  initFamsNavigation();
  window.addEventListener('popstate',()=>{ if(location.pathname.endsWith('/records.php')) window.location.reload(); });
});


/* Accounts & Access: open self-account panel as a lightweight modal. */
(function(){function init(){const modal=document.getElementById('accountSelfModal');if(!modal||modal.dataset.bound==='1')return;modal.dataset.bound='1';const openers=[...document.querySelectorAll('[data-account-modal-open]')];let previous=null;function open(){previous=document.activeElement;modal.hidden=false;document.body.classList.add('fams-popup-open');modal.querySelector('button,[href],input')?.focus();}function close(){modal.hidden=true;document.body.classList.remove('fams-popup-open');previous?.focus?.();}openers.forEach(a=>a.addEventListener('click',e=>{e.preventDefault();open();}));modal.querySelectorAll('[data-account-modal-close]').forEach(el=>el.addEventListener('click',close));document.addEventListener('keydown',e=>{if(!modal.hidden&&e.key==='Escape')close();});}document.addEventListener('DOMContentLoaded',init);document.addEventListener('fams:page-replaced',init);})();

/* FAMS 4.9 — avatar position/crop editor: supports the current saved photo */
(function(){
  function initAvatarCrop(){
    const form=document.getElementById('avatarUploadForm'), file=document.getElementById('avatarFile'), open=document.getElementById('openAvatarCrop');
    const modal=document.getElementById('avatarCropModal'), stage=document.getElementById('avatarCropStage'), img=document.getElementById('avatarCropImage'), zoom=document.getElementById('avatarZoom'), zoomValue=document.getElementById('avatarZoomValue'), data=document.getElementById('avatarCropData');
    if(!form||!file||!open||!modal||!stage||!img||!zoom||!data||form.dataset.avatarInit==='1') return; form.dataset.avatarInit='1';
    if(modal.parentElement!==document.body) document.body.appendChild(modal);
    let lastAvatarFocus=null;

    let baseScale=1, scale=1, x=0, y=0, dragging=false, sx=0, sy=0, ox=0, oy=0, naturalW=0, naturalH=0;
    let sourceUrl='';
    const customPhoto=form.dataset.currentAvatar || form.closest('.account-self-panel')?.querySelector('.account-profile-card .custom-avatar img')?.getAttribute('src') || '';
    const defaultPhoto=form.dataset.defaultAvatar || 'media.php?key=default_user_avatar';
    const currentPhoto=customPhoto || defaultPhoto;

    function draw(){
      const sw=stage.clientWidth, sh=stage.clientHeight;
      if(!naturalW||!naturalH||sw<=0||sh<=0) return;
      // Recalculate cover scale whenever the stage becomes visible/resizes.
      baseScale=Math.max(sw/naturalW,sh/naturalH);
      const iw=naturalW*baseScale*scale, ih=naturalH*baseScale*scale;
      img.style.width=iw+'px'; img.style.height=ih+'px';
      img.style.left=((sw-iw)/2+x)+'px'; img.style.top=((sh-ih)/2+y)+'px';
      zoomValue.textContent=Math.round(scale*100)+'%';
    }

    function loadSource(src, reset=true){
      if(!src) return;
      sourceUrl=src;
      img.onload=function(){
        naturalW=img.naturalWidth; naturalH=img.naturalHeight;
        if(!naturalW||!naturalH){ open.disabled=true; return; }
        // The stage may still be hidden here; draw() will calculate the real scale once visible.
        baseScale=1;
        if(reset){ scale=1; x=0; y=0; zoom.value='1'; data.value=''; }
        open.disabled=false;
        open.textContent=customPhoto && src===customPhoto && !file.files.length ? 'Reposition / Crop Current Photo' : (!file.files.length ? 'Resize / Crop Default Photo' : 'Position / Crop Photo');
        requestAnimationFrame(draw);
      };
      img.onerror=function(){
        open.disabled=true;
        if (src === defaultPhoto && defaultPhoto !== 'assets/avatars/default-user-silhouette.webp') {
          sourceUrl='assets/avatars/default-user-silhouette.webp';
          img.src=sourceUrl;
        }
      };
      img.src=src;
    }

    function loadSelected(){
      const f=file.files&&file.files[0];
      if(!f){
        data.value='';
        if(currentPhoto) loadSource(currentPhoto,true);
        return;
      }
      if(!/^image\/(jpeg|png|webp)$/i.test(f.type)) return;
      const url=URL.createObjectURL(f);
      open.disabled=false;
      loadSource(url,true);
      img.addEventListener('load',()=>URL.revokeObjectURL(url),{once:true});
    }

    // Enable editing immediately when a user already has a saved custom photo.
    if(currentPhoto) loadSource(currentPhoto,true);
    file.addEventListener('change',()=>{data.value='';loadSelected();});
    form.addEventListener('submit',e=>{const f=file.files&&file.files[0];if(f&&f.size>2*1024*1024&&!data.value){e.preventDefault();alert('This photo is large. Open Position / Crop and apply the crop first; only the optimized cropped image will be sent.');return;}if(data.value){try{const raw=data.value.split(',')[1]||'';if(Math.ceil(raw.length*3/4)>2*1024*1024){e.preventDefault();alert('Cropped photo exceeds 2 MB. Please crop again.');}}catch(_){e.preventDefault();alert('Invalid cropped photo. Please crop again.');}}});

    open.addEventListener('click',()=>{
      const hasSelected=!!(file.files&&file.files[0]);
      const src=hasSelected ? sourceUrl : currentPhoto;
      if(!src) return;
      if(img.src!==new URL(src,window.location.href).href) loadSource(src,false);
      lastAvatarFocus=document.activeElement;
      modal.hidden=false;
      document.body.classList.add('fams-popup-open');
      requestAnimationFrame(()=>{
        document.getElementById('closeAvatarCrop')?.focus();
        // The modal was hidden while the image was loaded; now the stage has real dimensions.
        draw();
      });
    });

    function closeAvatar(){modal.hidden=true;document.body.classList.remove('fams-popup-open');if(lastAvatarFocus&&typeof lastAvatarFocus.focus==='function')lastAvatarFocus.focus();}
    document.getElementById('closeAvatarCrop')?.addEventListener('click',closeAvatar);
    modal.addEventListener('click',e=>{if(e.target===modal)closeAvatar();});
    document.addEventListener('keydown',e=>{if(modal.hidden)return;if(e.key==='Escape'){e.preventDefault();closeAvatar();return;}if(e.key==='Tab'){const focusables=[...modal.querySelectorAll('button,input,[href],[tabindex]:not([tabindex="-1"])')].filter(el=>!el.disabled);if(!focusables.length)return;const first=focusables[0],last=focusables[focusables.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});

    zoom.addEventListener('input',()=>{scale=Math.min(5,Math.max(0.1,parseFloat(zoom.value)||1));draw();});
    document.getElementById('centerAvatarCrop')?.addEventListener('click',()=>{x=0;y=0;scale=1;zoom.value='1';draw();});

    stage.addEventListener('pointerdown',e=>{dragging=true;sx=e.clientX;sy=e.clientY;ox=x;oy=y;stage.setPointerCapture?.(e.pointerId);e.preventDefault();});
    stage.addEventListener('pointermove',e=>{if(!dragging)return;x=ox+(e.clientX-sx);y=oy+(e.clientY-sy);draw();});
    const stopDrag=()=>{dragging=false;};
    stage.addEventListener('pointerup',stopDrag); stage.addEventListener('pointercancel',stopDrag); stage.addEventListener('pointerleave',()=>{if(!stage.hasPointerCapture?.(0)) dragging=false;});

    document.getElementById('applyAvatarCrop')?.addEventListener('click',()=>{
      if(!img.naturalWidth||!img.naturalHeight){alert('The current photo could not be loaded for editing.');return;}
      const canvas=document.createElement('canvas'); canvas.width=512; canvas.height=512;
      const ctx=canvas.getContext('2d'); if(!ctx){alert('Your browser cannot create the cropped image.');return;}
      const sw=stage.clientWidth, sh=stage.clientHeight;
      const iw=naturalW*baseScale*scale, ih=naturalH*baseScale*scale;
      const left=(sw-iw)/2+x, top=(sh-ih)/2+y;
      const displayScale=iw/naturalW;
      let srcX=(-left)/displayScale, srcY=(-top)/displayScale, srcW=sw/displayScale, srcH=sh/displayScale;
      // The stage is always covered by the image, so the source rectangle should be valid.
      srcX=Math.max(0,Math.min(naturalW,srcX)); srcY=Math.max(0,Math.min(naturalH,srcY));
      srcW=Math.min(naturalW-srcX,srcW); srcH=Math.min(naturalH-srcY,srcH);
      if(srcW<=0||srcH<=0){alert('Invalid crop position. Please reposition the photo.');return;}
      ctx.imageSmoothingEnabled=true; ctx.imageSmoothingQuality='high';
      ctx.fillStyle='#e9eef5'; ctx.fillRect(0,0,512,512);
      ctx.drawImage(img,srcX,srcY,srcW,srcH,0,0,512,512);
      data.value=canvas.toDataURL('image/jpeg',.88);
      // Keep the selected image local only; submit the optimized 512px crop instead of the original file.
      if(file.files&&file.files.length) file.value='';
      closeAvatar();
      open.textContent=customPhoto && sourceUrl===customPhoto ? 'Adjust Current Photo Crop' : 'Adjust Crop';
    });

    form.addEventListener('submit',e=>{
      if(!data.value && !(file.files&&file.files.length) && !currentPhoto){
        e.preventDefault(); alert('Please choose a profile photo.');
      }
    });
  }
  document.addEventListener('DOMContentLoaded',initAvatarCrop);
  document.addEventListener('fams:page-replaced',initAvatarCrop);
})();


/* FAMS 5.9.5 — mobile navigation drawer and resilient sidebar behavior. */
(function(){
  function initSidebar(){
    const body=document.body;
    const sidebar=document.getElementById('famsSidebar');
    const toggle=document.getElementById('sidebarMobileToggle');
    const close=document.getElementById('sidebarMobileClose');
    const backdrop=document.getElementById('sidebarMobileBackdrop');
    if(!sidebar||!toggle||!close||!backdrop||toggle.dataset.sidebarInit==='1') return;
    toggle.dataset.sidebarInit='1';
    const mobileQuery=window.matchMedia('(max-width: 767px)');
    let lastFocus=null;
    function isMobile(){return mobileQuery.matches;}
    function setOpen(open){
      if(!isMobile()) open=false;
      body.classList.toggle('sidebar-menu-open',open);
      toggle.setAttribute('aria-expanded',open?'true':'false');
      if(open){
        lastFocus=document.activeElement;
        requestAnimationFrame(()=>close.focus());
      }else if(lastFocus&&typeof lastFocus.focus==='function'){
        requestAnimationFrame(()=>lastFocus.focus());
      }
    }
    toggle.addEventListener('click',()=>setOpen(true));
    close.addEventListener('click',()=>setOpen(false));
    backdrop.addEventListener('click',()=>setOpen(false));
    sidebar.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{if(isMobile())setOpen(false);}));
    document.addEventListener('keydown',e=>{
      if(e.key==='Escape'&&body.classList.contains('sidebar-menu-open')){e.preventDefault();setOpen(false);}
    });
    function sync(){if(!isMobile())setOpen(false);}
    mobileQuery.addEventListener?.('change',sync);
    window.addEventListener('resize',sync,{passive:true});
  }
  document.addEventListener('DOMContentLoaded',initSidebar);
  document.addEventListener('fams:page-replaced',initSidebar);
})();


/* Version 1.12 — keep the focused sidebar destination visible after navigation/refresh. */
document.addEventListener('DOMContentLoaded',()=>{
  const nav=document.querySelector('.sidebar nav'); if(!nav) return;
  const links=[...nav.querySelectorAll('a[href]')];
  links.forEach(link=>{
    link.addEventListener('click',()=>{ try{localStorage.setItem('fams:last-nav',link.getAttribute('href')||'');}catch(e){} });
  });
  const active=nav.querySelector('a.active');
  if(active){ try{localStorage.setItem('fams:last-nav',active.getAttribute('href')||''); active.scrollIntoView({block:'nearest'});}catch(e){} }
});

/* v0.0137: CRUD popups are handled by the single canonical controller in core/shared-shell.js. */

/* FAMS 0.916 — resilient avatar/logo image handling.
   Successful DB-backed media is never replaced. If a media endpoint returns a
   broken image during migration, retry once through the appropriate bundled
   media endpoint so the UI never leaves a broken-image icon behind. */
(function(){
  function bindMediaFallbacks(root){
    const scope=root||document;
    scope.querySelectorAll('img[data-avatar-fallback], img[data-fams-media-key], img[data-avatar-user-id]').forEach(function(img){
      if(img.dataset.mediaFallbackBound==='1') return;
      img.dataset.mediaFallbackBound='1';
      img.addEventListener('error',function(){
        if(img.dataset.mediaFallbackTried==='1') return;
        img.dataset.mediaFallbackTried='1';
        // Per-user avatar failures must never use the logged-in user's role to
        // choose a fallback. Otherwise a non-admin could briefly receive the
        // Site Admin avatar when their image request fails.
        const key=img.dataset.avatarUserId ? 'default_user_avatar' : (img.dataset.avatarFallback || img.dataset.famsMediaKey || '');
        if(!key) return;
        const fallback='media.php?key='+encodeURIComponent(key);
        if(img.src===new URL(fallback,location.href).href) return;
        img.src=fallback;
      });
    });
  }
  document.addEventListener('DOMContentLoaded',()=>bindMediaFallbacks());
  document.addEventListener('fams:page-replaced',()=>bindMediaFallbacks());
  window.FAMSBindMediaFallbacks=bindMediaFallbacks;
})();

/* Version 0.924 — keep fixed header offset synchronized with its real content height. */
(function(){
  function syncTopbarHeight(){
    const bar=document.querySelector('.topbar');
    if(!bar)return;
    const h=Math.ceil(bar.getBoundingClientRect().height||0);
    if(h>0)document.documentElement.style.setProperty('--topbar-height',h+'px');
  }
  function init(){
    syncTopbarHeight();
    const bar=document.querySelector('.topbar');
    if(window.ResizeObserver&&bar){new ResizeObserver(syncTopbarHeight).observe(bar);}
    window.addEventListener('resize',syncTopbarHeight,{passive:true});
    document.addEventListener('fams:page-replaced',syncTopbarHeight);
    setTimeout(syncTopbarHeight,50);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();

/* Version 0.925 — lightweight local presence state for the signed-in header profile. */
(function(){
  function initHeaderPresence(){
    const profile=document.getElementById('topProfile');
    if(!profile || profile.dataset.presenceBound==='1') return;
    profile.dataset.presenceBound='1';
    const label=profile.querySelector('[data-presence-label]');
    const idleMs=2*60*1000;
    let lastActivity=Date.now();
    let timer=null;
    function setState(state){
      profile.classList.remove('is-online','is-away','is-offline');
      profile.classList.add('is-'+state);
      profile.dataset.presence=state;
      if(label) label.textContent=state==='online'?'Online':(state==='away'?'Away':'Offline');
      profile.setAttribute('aria-label','Signed-in user — '+(state==='online'?'Online':state==='away'?'Away':'Offline'));
    }
    function evaluate(){
      if(!navigator.onLine){setState('offline');return;}
      if(document.visibilityState!=='visible' || Date.now()-lastActivity>=idleMs){setState('away');return;}
      setState('online');
    }
    function activity(){lastActivity=Date.now();evaluate();}
    ['pointerdown','keydown','mousemove','touchstart','scroll'].forEach(ev=>window.addEventListener(ev,activity,{passive:true}));
    document.addEventListener('visibilitychange',evaluate);
    window.addEventListener('online',evaluate);
    window.addEventListener('offline',evaluate);
    timer=window.setInterval(evaluate,15000);
    evaluate();
    window.addEventListener('beforeunload',()=>{if(timer)window.clearInterval(timer);},{once:true});
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initHeaderPresence,{once:true}); else initHeaderPresence();
  document.addEventListener('fams:page-replaced',initHeaderPresence);
})();


/* Version 0.929 — modern interaction layer; delegated so partial navigation remains safe. */
(function(){
  function initModernInteractions(){
    const records=document.querySelector('.records-page');
    if(records && records.dataset.modernRowsBound!=='1'){
      records.dataset.modernRowsBound='1';
      records.addEventListener('click',e=>{
        const row=e.target.closest('.wide-table tbody tr');
        if(!row || e.target.closest('a,button,input,select,textarea,form')) return;
        records.querySelectorAll('.wide-table tbody tr.is-selected').forEach(r=>{if(r!==row)r.classList.remove('is-selected');});
        row.classList.toggle('is-selected');
      });
    }

    const sidebar=document.querySelector('.sidebar-nav-scroll');
    if(sidebar){
      const saveSidebarPercent=()=>{
        const max=Math.max(0,sidebar.scrollHeight-sidebar.clientHeight);
        const pct=max>0?Math.max(0,Math.min(1,sidebar.scrollTop/max)):0;
        try{localStorage.setItem('fams.sidebar.nav.scrollPercent',String(pct));}catch(e){}
      };
      sidebar.querySelectorAll('a').forEach(a=>{
        if(a.dataset.modernScrollBound==='1')return;
        a.dataset.modernScrollBound='1';
        a.addEventListener('pointerdown',saveSidebarPercent,{capture:true,passive:true});
        a.addEventListener('click',saveSidebarPercent,{capture:true,passive:true});
      });
    }
  }
  document.addEventListener('DOMContentLoaded',initModernInteractions);
  document.addEventListener('fams:page-replaced',initModernInteractions);
})();

/* FAMS 0.934 — Site Admin avatar positioning/crop editor. */
(function(){
 function initAdminAvatarCrop(){
  const form=document.querySelector('.admin-user-editor-form input[name="action"][value="admin_update_user"]')?.closest('form');
  const file=document.getElementById('adminAvatarFile'),open=document.getElementById('openAdminAvatarCrop'),modal=document.getElementById('adminAvatarCropModal'),stage=document.getElementById('adminAvatarCropStage'),img=document.getElementById('adminAvatarCropImage'),zoom=document.getElementById('adminAvatarZoom'),zoomValue=document.getElementById('adminAvatarZoomValue'),data=document.getElementById('adminAvatarCropData');
  if(!form||!file||!open||!modal||!stage||!img||!zoom||!data||open.dataset.init==='1')return; open.dataset.init='1';
  if(modal.parentElement!==document.body)document.body.appendChild(modal);
  let scale=1,x=0,y=0,dragging=false,sx=0,sy=0,ox=0,oy=0,naturalW=0,naturalH=0,baseScale=1,sourceUrl='';
  const userId=form.querySelector('input[name="user_id"]')?.value||'';
  const current='avatar.php?user='+encodeURIComponent(userId);
  function draw(){const sw=stage.clientWidth,sh=stage.clientHeight;if(!naturalW||!naturalH||!sw||!sh)return;baseScale=Math.max(sw/naturalW,sh/naturalH);const iw=naturalW*baseScale*scale,ih=naturalH*baseScale*scale;img.style.width=iw+'px';img.style.height=ih+'px';img.style.left=((sw-iw)/2+x)+'px';img.style.top=((sh-ih)/2+y)+'px';zoomValue.textContent=Math.round(scale*100)+'%';}
  function load(src,reset=true){sourceUrl=src;img.onload=()=>{naturalW=img.naturalWidth;naturalH=img.naturalHeight;if(reset){scale=1;x=0;y=0;zoom.value='1';data.value='';}requestAnimationFrame(draw);};img.src=src;}
  load(current);
  file.addEventListener('change',()=>{const f=file.files?.[0];if(!f)return;if(!/^image\/(jpeg|png|webp)$/i.test(f.type))return;const u=URL.createObjectURL(f);load(u,true);img.addEventListener('load',()=>URL.revokeObjectURL(u),{once:true});});
  open.addEventListener('click',()=>{const src=file.files?.[0]?sourceUrl:current;if(!src)return;modal.hidden=false;document.body.classList.add('fams-popup-open');requestAnimationFrame(()=>{document.getElementById('closeAdminAvatarCrop')?.focus();draw();});});
  function close(){modal.hidden=true;document.body.classList.remove('fams-popup-open');}
  document.getElementById('closeAdminAvatarCrop')?.addEventListener('click',close);modal.addEventListener('click',e=>{if(e.target===modal)close();});
  zoom.addEventListener('input',()=>{scale=Math.min(5,Math.max(0.1,parseFloat(zoom.value)||1));draw();});
  document.getElementById('centerAdminAvatarCrop')?.addEventListener('click',()=>{x=0;y=0;scale=1;zoom.value='1';draw();});
  stage.addEventListener('pointerdown',e=>{dragging=true;sx=e.clientX;sy=e.clientY;ox=x;oy=y;stage.setPointerCapture?.(e.pointerId);e.preventDefault();});
  stage.addEventListener('pointermove',e=>{if(!dragging)return;x=ox+(e.clientX-sx);y=oy+(e.clientY-sy);draw();});
  ['pointerup','pointercancel','pointerleave'].forEach(ev=>stage.addEventListener(ev,()=>dragging=false));
  document.getElementById('applyAdminAvatarCrop')?.addEventListener('click',()=>{if(!img.naturalWidth||!img.naturalHeight)return;const canvas=document.createElement('canvas');canvas.width=512;canvas.height=512;const ctx=canvas.getContext('2d');if(!ctx)return;const sw=stage.clientWidth,sh=stage.clientHeight,iw=naturalW*baseScale*scale,ih=naturalH*baseScale*scale,left=(sw-iw)/2+x,top=(sh-ih)/2+y,displayScale=iw/naturalW;let srcX=(-left)/displayScale,srcY=(-top)/displayScale,srcW=sw/displayScale,srcH=sh/displayScale;srcX=Math.max(0,Math.min(naturalW,srcX));srcY=Math.max(0,Math.min(naturalH,srcY));srcW=Math.min(naturalW-srcX,srcW);srcH=Math.min(naturalH-srcY,srcH);if(srcW<=0||srcH<=0)return;ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality='high';ctx.fillStyle='#e9eef5';ctx.fillRect(0,0,512,512);ctx.drawImage(img,srcX,srcY,srcW,srcH,0,0,512,512);data.value=canvas.toDataURL('image/jpeg',.92);close();open.textContent='Adjust Avatar Crop';});
 }
 document.addEventListener('DOMContentLoaded',initAdminAvatarCrop);document.addEventListener('fams:page-replaced',initAdminAvatarCrop);
})();

/* Version 0.942 — make the LPGMA facility button reliably open the native picker. */
(function(){
  function initFacilityPicker(){
    document.querySelectorAll('.sidebar-facility-selector').forEach(function(form){
      const button=form.querySelector('.sidebar-facility-select-cue');
      const select=form.querySelector('select[name="fams_facility"]');
      if(!button||!select||button.dataset.pickerBound==='1') return;
      button.dataset.pickerBound='1';
      button.addEventListener('click',function(ev){
        ev.preventDefault();
        ev.stopPropagation();
        try{
          if(typeof select.showPicker==='function') select.showPicker();
          else { select.focus(); select.click(); }
        }catch(_){
          try{ select.focus(); select.click(); }catch(__){}
        }
      });
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initFacilityPicker,{once:true});else initFacilityPicker();
  document.addEventListener('fams:page-replaced',initFacilityPicker);
})();

/* V0.940 — profile uses a slow CSS slide between initials and full identity; no typing animation. */
