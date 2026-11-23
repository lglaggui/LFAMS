<?php
require_once __DIR__.'/config.php'; require_login();if(!fams_current_type_allows('AICS','use_chat')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
$me=(int)current_user()['id']; $role=(string)(current_user()['role']??'');
// Keep the chat directory aligned with Account Management: refresh active, approved
// central LPGMA identities into their AICS bridge rows before building recipients.
try {
    $centralDb = function_exists('fams_lpgma_db') ? fams_lpgma_db() : null;
    if ($centralDb) {
        $centralUsers = $centralDb->query("SELECT id,username,full_name,email,password_hash,role,facility,is_active,approval_status,auth_version FROM users WHERE is_active=1 AND role IN ('admin','manager','encoder','representative','staff') AND COALESCE(approval_status,'APPROVED')='APPROVED' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($centralUsers as $centralUser) { try { fams_sync_aics_shadow($pdo, $centralUser, 1); } catch (Throwable $syncError) { /* Keep chat available if one stale identity cannot sync. */ } }
    }
} catch (Throwable $directorySyncError) { /* Local AICS chat remains available if the central registry is offline. */ }
$users=$pdo->query("SELECT u.id,u.full_name,u.username,u.role,u.office_id,u.identity_source FROM aics_users u WHERE u.is_active=1 ORDER BY u.office_id,u.full_name")->fetchAll();
$officeRows=$pdo->query("SELECT o.*,p.name parent_name FROM aics_offices o LEFT JOIN aics_offices p ON p.id=o.parent_id WHERE o.is_active=1 AND o.parent_id=2 AND UPPER(TRIM(o.name)) NOT IN ('LPGMA','LPGMA OFFICE') AND UPPER(TRIM(o.short_name)) NOT IN ('LPGMA','LPGMA OFFICE') ORDER BY o.sort_order,o.id")->fetchAll();
$officeById=[];foreach($officeRows as $o)$officeById[(int)$o['id']]=$o;
$provinceMap=['Isabela'=>['SWAD Isabela / SWAD Ilagan','SWAD Cauayan','SWAD Echague','SWAD Roxas','SWAD Santiago'],'Cagayan'=>['SWAD Cagayan'],'Nueva Vizcaya'=>['SWAD Nueva Vizcaya'],'Quirino'=>['SWAD Quirino'],'Batanes'=>['SWAD Batanes']];
$groups=[];foreach($provinceMap as $province=>$names){$ids=[];foreach($officeRows as $o)if((int)($o['parent_id']??0)===2&&in_array($o['name'],$names,true))$ids[]=(int)$o['id'];if($ids)$groups[]=['key'=>'g:SWAD-PROV-'.preg_replace('/[^A-Z0-9]+/','-',strtoupper($province)),'name'=>'SWAD '.$province,'province'=>$province,'office_ids'=>$ids,'kind'=>'province'];}
if(isset($_GET['fragment'])&&$_GET['fragment']==='chat-options'){header('Content-Type:text/html; charset=utf-8');require __DIR__.'/_chat_directory.php';exit;}
$pageTitle='Chat';require __DIR__.'/partials/header.php';
?>
<div class="chat-layout" data-chat-user-id="<?=$me?>" data-chat-admin="<?=$role==='admin'?'1':'0'?>">
<section class="panel chat-users"><div class="panel-heading compact"><div><span class="section-kicker">MESSAGING</span><h3>Chats</h3></div></div>
<div class="chat-search-wrap"><label class="chat-search-field" for="chatOptionsSearch"><span aria-hidden="true">⌕</span><input id="chatOptionsSearch" type="search" placeholder="Search chats or users…" autocomplete="off" aria-label="Search chats or users"><button type="button" id="chatOptionsSearchClear" aria-label="Clear chat search" title="Clear search" hidden>×</button></label></div>
<?php require __DIR__.'/_chat_directory.php'; ?>
</section>
<section class="panel chat-panel"><div class="panel-heading compact"><div><span class="section-kicker" id="chatModeLabel">PRIVATE CHAT</span><h3 id="chatTitle">Select a chat</h3></div><span class="chat-header-unread" id="chatUnreadSummary" hidden></span></div>
<div class="chat-messages-wrap"><div class="chat-messages" id="chatMessages" aria-live="polite" aria-label="Chat messages"><div class="chat-empty">Select a user or office group to start a conversation.</div></div><button type="button" class="chat-jump-latest" id="chatJumpLatest" hidden aria-label="Jump to latest messages">↓ <span>Latest</span></button></div>
<div class="chat-compose-status" id="chatComposeStatus" role="status" aria-live="polite"></div>
<form class="chat-compose" id="chatForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" id="chatRecipient"><input type="hidden" id="chatGroup"><div class="chat-composer-field"><input id="chatMessage" placeholder="Type a message..." autocomplete="off" maxlength="2000" aria-label="Message"><button type="button" class="chat-emoji-button" id="chatEmojiButton" aria-label="Open emoji picker" title="Add emoji">😊</button><div class="chat-emoji-picker" id="chatEmojiPicker" role="dialog" aria-label="Emoji picker"></div></div><button class="primary-btn" id="chatSendButton" type="submit">Send</button></form>
<?php if($role==='admin'): ?><button type="button" class="danger-btn chat-clear-all" id="chatClearAll" disabled>Clear Selected Chat</button><?php endif; ?></section></div>
<div class="chat-trail-modal" id="chatTrailModal" hidden aria-hidden="true"><div class="chat-trail-backdrop" data-chat-trail-close></div><section class="chat-trail-dialog" role="dialog" aria-modal="true" aria-labelledby="chatTrailTitle"><header class="chat-trail-dialog-head"><div><span class="section-kicker">MESSAGE HISTORY</span><h3 id="chatTrailTitle">Edit Trail</h3></div><button type="button" class="chat-trail-close" data-chat-trail-close aria-label="Close edit trail">×</button></header><div class="chat-trail-current" id="chatTrailCurrent"></div><div class="chat-trail-list" id="chatTrailList"></div></section></div>
<script>window.CHAT_USER_ID=<?=$me?>;window.CHAT_IS_ADMIN=<?=$role==='admin'?'true':'false'?>;window.CHAT_READ_ONLY=false;</script>
<script src="../core/shared-chat.js?v=20261103-v0175" defer></script>
<?php require __DIR__.'/partials/footer.php'; ?>
