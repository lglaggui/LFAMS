/* v0.0090 — single shared chat controller for MAIFIP and AICS; each workspace supplies its own local chat API and directory. */
window.FAMS_SHARED_CHAT_CONTROLLER=true;
window.FAMSInitSharedChat=function(){
const chatLayout=document.querySelector('.chat-layout');
if(chatLayout){
  // The fragment's server-rendered identity is authoritative for this page.
  // Never retain a previous page's CHAT_USER_ID after partial navigation.
  const pageUserId=Number(chatLayout.dataset.chatUserId||0);
  if(pageUserId>0) window.CHAT_USER_ID=pageUserId;
  window.CHAT_IS_ADMIN=chatLayout.dataset.chatAdmin==='1';
  window.CHAT_READ_ONLY=false;
}
if(window.CHAT_USER_ID && chatLayout){
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
  const bindChatAvatarFallbacks=(scope=document)=>scope.querySelectorAll('.chat-layout img.avatar-img').forEach(img=>{if(img.dataset.famsAvatarFallback==='1')return;img.dataset.famsAvatarFallback='1';img.addEventListener('error',()=>{if(img.dataset.famsFallbackApplied==='1')return;img.dataset.famsFallbackApplied='1';img.src='assets/avatars/default-user-silhouette.webp';},{once:true});});
  bindChatAvatarFallbacks();

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
  bindChatAvatarFallbacks(replacement);
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
document.addEventListener('DOMContentLoaded',()=>window.FAMSInitSharedChat());
document.addEventListener('fams:page-replaced',()=>window.FAMSInitSharedChat());
