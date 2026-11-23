(function(){
  function initAvatarEditor(){
    const mainForm=document.getElementById('avatarUploadForm');
    const popupForm=document.getElementById('accountManagementForm');
    const file=document.getElementById('avatarFile') || document.getElementById('accountPopupAvatarFile');
    const open=document.getElementById('openAvatarCrop') || document.getElementById('openAccountPopupAvatar');
    const modal=document.getElementById('avatarCropModal');
    const stage=document.getElementById('avatarCropStage'), img=document.getElementById('avatarCropImage');
    const zoom=document.getElementById('avatarZoom'), zoomValue=document.getElementById('avatarZoomValue');
    const data=document.getElementById('avatarCropData') || document.getElementById('accountPopupAvatarData');
    if(!file||!open||!modal||!stage||!img||!zoom||!data) return;
    let src='',scale=1,x=0,y=0,dragging=false,sx=0,sy=0,ox=0,oy=0,naturalW=0,naturalH=0;
    function apply(){const w=naturalW*scale,h=naturalH*scale;img.style.width=w+'px';img.style.height=h+'px';img.style.left=x+'px';img.style.top=y+'px';zoomValue.textContent=Math.round(scale*100)+'%';}
    function center(){const w=naturalW*scale,h=naturalH*scale;x=(stage.clientWidth-w)/2;y=(stage.clientHeight-h)/2;apply();}
    function loadImage(url){src=url;img.onload=function(){naturalW=img.naturalWidth;naturalH=img.naturalHeight;scale=Math.max(stage.clientWidth/naturalW,stage.clientHeight/naturalH);scale=Math.min(5,Math.max(0.1,scale));zoom.value=scale;center();};img.src=url;}
    function openEditor(){
      if(!src) src=(mainForm&&mainForm.dataset.currentAvatar)||(mainForm&&mainForm.dataset.defaultAvatar)||(popupForm&&document.getElementById('accountPopupAvatarPreview')?.src)||'assets/avatars/default-user-silhouette.webp';
      modal.hidden=false; document.body.classList.add('fams-popup-open'); loadImage(src);
    }
    file.addEventListener('change',()=>{const f=file.files&&file.files[0];if(!f)return;const reader=new FileReader();reader.onload=e=>{src=e.target.result;modal.hidden=false;document.body.classList.add('fams-popup-open');loadImage(src);};reader.readAsDataURL(f);});
    open.addEventListener('click',openEditor);
    document.getElementById('closeAvatarCrop')?.addEventListener('click',()=>{modal.hidden=true;document.body.classList.remove('fams-popup-open');});
    document.getElementById('centerAvatarCrop')?.addEventListener('click',center);
    zoom.addEventListener('input',()=>{const old=scale||1;const ns=parseFloat(zoom.value);const cx=stage.clientWidth/2,cy=stage.clientHeight/2;const rx=(cx-x)/old,ry=(cy-y)/old;scale=ns;x=cx-rx*scale;y=cy-ry*scale;apply();});
    stage.addEventListener('pointerdown',e=>{dragging=true;stage.setPointerCapture(e.pointerId);sx=e.clientX;sy=e.clientY;ox=x;oy=y;img.classList.add('dragging');});
    stage.addEventListener('pointermove',e=>{if(!dragging)return;x=ox+e.clientX-sx;y=oy+e.clientY-sy;apply();});
    const stop=()=>{dragging=false;img.classList.remove('dragging');};
    stage.addEventListener('pointerup',stop); stage.addEventListener('pointercancel',stop); stage.addEventListener('pointerleave',()=>{if(dragging)stop();});
    document.getElementById('applyAvatarCrop')?.addEventListener('click',()=>{
      const size=512,canvas=document.createElement('canvas');canvas.width=size;canvas.height=size;const ctx=canvas.getContext('2d');
      const outScale=size/stage.clientWidth;ctx.drawImage(img,x*outScale,y*outScale,naturalW*scale*outScale,naturalH*scale*outScale);
      data.value=canvas.toDataURL('image/webp',.88);modal.hidden=true;document.body.classList.remove('fams-popup-open');
      const preview=document.getElementById('accountPopupAvatarPreview'); if(preview) preview.src=data.value;
      if(mainForm) { const save=mainForm.querySelector('button[type=submit]'); if(save) save.focus(); }
    });
  }
  window.toggleSidebar=function(){const s=document.getElementById('sidebar'),b=document.querySelector('.mobile-menu-btn');if(!s)return;const open=s.classList.toggle('open');if(b)b.setAttribute('aria-expanded',open?'true':'false');};
  window.closeSidebar=function(){const s=document.getElementById('sidebar'),b=document.querySelector('.mobile-menu-btn');if(s)s.classList.remove('open');if(b)b.setAttribute('aria-expanded','false');};
  function closeTopPopover(id){const p=document.getElementById(id);if(p)p.hidden=true;const b=p?.previousElementSibling;if(b)b.setAttribute('aria-expanded','false');}
  window.closeTopPopover=closeTopPopover;
  function closeAllTopPopovers(){document.querySelectorAll('.top-popover').forEach(p=>{p.hidden=true;const b=p.previousElementSibling;if(b)b.setAttribute('aria-expanded','false');});}
  window.toggleNotifications=function(e){e?.stopPropagation();const p=document.getElementById('notificationPanel');if(!p)return;const willOpen=p.hidden;closeAllTopPopovers();p.hidden=!willOpen;const b=p.previousElementSibling;if(b)b.setAttribute('aria-expanded',willOpen?'true':'false');};
  window.toggleChatQuick=function(e){e?.stopPropagation();const p=document.getElementById('chatQuickPanel');if(!p)return;const willOpen=p.hidden;closeAllTopPopovers();p.hidden=!willOpen;const b=p.previousElementSibling;if(b)b.setAttribute('aria-expanded',willOpen?'true':'false');};
  document.addEventListener('click',e=>{if(!e.target.closest('.top-popover-wrap'))closeAllTopPopovers();});
  document.querySelectorAll('.nav a').forEach(a=>a.addEventListener('click',()=>window.closeSidebar()));
  initAvatarEditor();
})();
/* v0.0096 client-side photo upload guard: reject oversized files before form submission. */
document.addEventListener('change',function(e){const el=e.target;if(el&&el.type==='file'&&/avatar|photo|image/i.test((el.id||'')+' '+(el.name||''))){const f=el.files&&el.files[0];if(f&&f.size>2*1024*1024){alert('Please choose a photo no larger than 2 MB. The file was not uploaded.');el.value='';}}});
document.addEventListener('submit',function(e){const form=e.target;if(!(form instanceof HTMLFormElement))return;const f=form.querySelector('input[type=file]');const d=form.querySelector('input[name*=crop_data],input[id*=AvatarData]');if(f&&f.files&&f.files[0]&&f.files[0].size>2*1024*1024){e.preventDefault();alert('Photo exceeds the 2 MB limit. Please choose a smaller photo or crop it first.');return;}if(d&&d.value){const raw=d.value.split(',')[1]||'';if(Math.ceil(raw.length*3/4)>2*1024*1024){e.preventDefault();alert('Cropped photo exceeds 2 MB. Please crop again.');}}},true);
