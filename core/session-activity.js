/* Shared user-activity heartbeat. Background polling does not extend idle sessions. */
(function(){
  let lastPing=0, pending=false;
  const ping=()=>{
    const now=Date.now();
    if(pending || now-lastPing<60000) return;
    lastPing=now; pending=true;
    fetch('../core/session_activity.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-FAMS-Activity':'1'}})
      .catch(()=>{})
      .finally(()=>{pending=false;});
  };
  ['pointerdown','keydown','touchstart','input','scroll'].forEach(type=>document.addEventListener(type,ping,{passive:true,capture:true}));
})();
