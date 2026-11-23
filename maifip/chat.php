<?php
require_once __DIR__.'/config.php'; require_login();if(!fams_current_type_allows('MAIFIP','use_chat')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
sync_current_user_from_db($pdo);
if (!is_logged_in()) { http_response_code(401); exit('Your session has expired. Please sign in again.'); }
$pdo->exec("CREATE TABLE IF NOT EXISTS user_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sender_id INT UNSIGNED NULL,recipient_id INT UNSIGNED NULL,group_key VARCHAR(32) NULL,message TEXT NOT NULL,edited_at TIMESTAMP NULL DEFAULT NULL,deleted_at TIMESTAMP NULL DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(sender_id),INDEX(recipient_id),INDEX(group_key),INDEX(created_at)) ENGINE=InnoDB");
// Avoid an INFORMATION_SCHEMA lookup on every chat page view. Legacy schemas are
// upgraded by the one-time deployment migration; chat_api.php retains compatibility checks.
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_read_state (user_id INT UNSIGNED NOT NULL,conversation_key VARCHAR(64) NOT NULL,last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(user_id,conversation_key),INDEX(idx_chat_read_user,user_id)) ENGINE=InnoDB");
$chatFacilityKeys=array_keys(facility_options());
$chatFacilities=array_merge(['LPGMA'], $chatFacilityKeys);
$chatFacilityPlaceholders=implode(',',array_fill(0,count($chatFacilityKeys),'?'));
if($chatFacilityKeys){
    $st=$pdo->prepare("SELECT id,full_name,username,role,facility,is_active FROM users WHERE is_active=1 AND (facility='LPGMA' OR facility IN ($chatFacilityPlaceholders)) ORDER BY full_name");
    $st->execute($chatFacilityKeys); $users=$st->fetchAll();
}else{
    $users=$pdo->query("SELECT id,full_name,username,role,facility,is_active FROM users WHERE is_active=1 AND facility='LPGMA' ORDER BY full_name")->fetchAll();
}
$role=(string)(current_user()['role']??'');
$myFacility=user_facility(current_user());
$isFragment = isset($_GET['fragment']) && $_GET['fragment'] === '1';

if(isset($_GET['fragment']) && $_GET['fragment']==='chat-options'){
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <div class="chat-office-groups" aria-label="Office chat groups">
    <?php foreach($chatFacilities as $g):
      $info=facility_info($g); $logo=$info['logo']; $logoUrl=facility_logo_url($g);
      $officeUsers=array_values(array_filter($users,fn($u)=>user_facility($u)===$g && (int)$u['id']!==(int)current_user()['id']));
    ?>
      <section class="chat-office-section" data-office-group="<?=e($g)?>">
        <button class="chat-office-heading" type="button" aria-expanded="false" aria-controls="chat-office-<?=e(strtolower($g))?>">
          <span class="chat-office-heading-main"><span class="chat-office-chevron" aria-hidden="true">▸</span><span class="chat-office-heading-logo"><?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" decoding="sync" loading="eager" alt=""><?php else: ?><span class="chat-facility-initials"><?=e($g)?></span><?php endif; ?></span><strong><?=e($g)?> Office</strong><small><?=count($officeUsers)?> user<?=count($officeUsers)===1?'':'s'?></small><span class="chat-office-unread-badge" data-chat-office-badge="<?=e($g)?>" hidden>0</span></span>
        </button>
        <div id="chat-office-<?=e(strtolower($g))?>" class="chat-office-members" hidden>
          <button class="chat-user chat-group-user" type="button" data-chat-type="group" data-chat-group="<?=e($g)?>" data-chat-key="g:<?=e($g)?>"><span class="chat-avatar-wrap"><span class="chat-user-avatar chat-group-avatar"><?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" decoding="sync" loading="eager" alt="<?=e($g)?>"><?php else: ?><span class="chat-facility-initials chat-facility-avatar"><?=e($g)?></span><?php endif; ?></span><span class="chat-office-badge" style="--office-accent:<?=e((string)($info['accent']??'#4d7fa5'))?>"><?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" decoding="sync" loading="eager" alt="<?=e($g)?> office"><?php else: ?><span><?=e($g)?></span><?php endif; ?></span></span><span class="chat-user-copy"><strong><?=e($g)?> Office</strong><small>Office Group Chat</small></span><span class="chat-unread-badge" data-chat-badge="g:<?=e($g)?>" hidden>0</span></button>
          <div class="chat-office-user-list">
          <?php foreach($officeUsers as $u): $officeKey=user_facility($u); $officeLogo=facility_logo($officeKey); $officeLogoUrl=facility_logo_url($officeKey); $officeName=facility_label($officeKey); ?>
            <button class="chat-user" type="button" data-chat-type="user" data-user-id="<?=$u['id']?>" data-chat-key="u:<?=$u['id']?>"><span class="chat-avatar-wrap"><?=avatar_markup($u['role'] ?? '', 'chat-large-avatar', $u['id'] ?? null)?><span class="chat-office-badge" style="--office-accent:<?=e((string)(facility_info($officeKey)['accent']??'#4d7fa5'))?>"><?php if($officeLogoUrl): ?><img src="<?=e($officeLogoUrl)?>" decoding="sync" loading="eager" alt="<?=e($officeName)?> office"><?php else: ?><span><?=e($officeName)?></span><?php endif; ?></span></span><span class="chat-user-copy"><strong><?=e($u['full_name'])?></strong><small><?=e(role_label($u['role']))?></small></span><span class="chat-unread-badge" data-chat-badge="u:<?=$u['id']?>" hidden>0</span></button>
          <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
    <section class="chat-office-section chat-all-section" data-office-group="ALL">
      <button class="chat-office-heading" type="button" aria-expanded="false" aria-controls="chat-office-all"><span class="chat-office-heading-main"><span class="chat-office-chevron" aria-hidden="true">▸</span><span class="chat-office-heading-logo chat-office-heading-all">ALL</span><strong>All Users</strong><small>All FAMS Users</small><span class="chat-office-unread-badge" data-chat-office-badge="ALL" hidden>0</span></span></button>
      <div id="chat-office-all" class="chat-office-members" hidden>
        <button class="chat-user chat-group-user chat-all-group" type="button" data-chat-type="group" data-chat-group="ALL" data-chat-key="g:ALL"><span class="chat-avatar-wrap"><span class="chat-user-avatar chat-group-avatar chat-all-avatar">ALL</span><span class="chat-office-badge chat-group-badge">G</span></span><span class="chat-user-copy"><strong>All Users</strong><small>All Users Group Chat</small></span><span class="chat-unread-badge" data-chat-badge="g:ALL" hidden>0</span></button>
        <div class="chat-office-user-list chat-all-user-list">
        <?php foreach($users as $u): if((int)$u['id']===(int)current_user()['id']) continue; $officeKey=user_facility($u); $officeLogo=facility_logo($officeKey); $officeLogoUrl=facility_logo_url($officeKey); $officeName=facility_label($officeKey); ?>
          <button class="chat-user" type="button" data-chat-type="user" data-user-id="<?=$u['id']?>" data-chat-key="u:<?=$u['id']?>"><span class="chat-avatar-wrap"><?=avatar_markup($u['role'] ?? '', 'chat-large-avatar', $u['id'] ?? null)?><span class="chat-office-badge" style="--office-accent:<?=e((string)(facility_info($officeKey)['accent']??'#4d7fa5'))?>"><?php if($officeLogoUrl): ?><img src="<?=e($officeLogoUrl)?>" decoding="sync" loading="eager" alt="<?=e($officeName)?> office"><?php else: ?><span><?=e($officeName)?></span><?php endif; ?></span></span><span class="chat-user-copy"><strong><?=e($u['full_name'])?></strong><small><?=e(role_label($u['role']))?> · <?=e($officeName)?></small></span><span class="chat-unread-badge" data-chat-badge="u:<?=$u['id']?>" hidden>0</span></button>
        <?php endforeach; ?>
        </div>
      </div>
    </section>
    </div>
    <?php exit;
}
$pageTitle='Chat';
if (!$isFragment) require __DIR__.'/partials/header.php';
?>
<?php if ($isFragment): ?><div id="pageView" class="page-view" data-page-view data-shell-title="Chat"><?php endif; ?>
<div class="chat-layout" data-chat-user-id="<?= (int)current_user()['id'] ?>" data-chat-admin="<?= (($role==='admin') ? '1' : '0') ?>">
<section class="panel chat-users">
  <div class="panel-heading compact"><div><span class="section-kicker">MESSAGING</span><h3>Chats</h3></div></div>
  <div class="chat-search-wrap">
    <label class="chat-search-field" for="chatOptionsSearch"><span aria-hidden="true">⌕</span><input id="chatOptionsSearch" type="search" placeholder="Search chats or users…" autocomplete="off" aria-label="Search chats or users"><button type="button" id="chatOptionsSearchClear" aria-label="Clear chat search" title="Clear search" hidden>×</button></label>
  </div>
  <div class="chat-office-groups" aria-label="Office chat groups">
  <?php foreach($chatFacilities as $g):
    $info=facility_info($g); $logo=$info['logo']; $logoUrl=facility_logo_url($g);
    $officeUsers=array_values(array_filter($users,fn($u)=>user_facility($u)===$g && (int)$u['id']!==(int)current_user()['id']));
  ?>
    <section class="chat-office-section" data-office-group="<?=e($g)?>">
      <button class="chat-office-heading" type="button" aria-expanded="false" aria-controls="chat-office-<?=e(strtolower($g))?>">
        <span class="chat-office-heading-main"><span class="chat-office-chevron" aria-hidden="true">▸</span><span class="chat-office-heading-logo"><?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" decoding="sync" loading="eager" alt=""><?php else: ?><span class="chat-facility-initials"><?=e($g)?></span><?php endif; ?></span><strong><?=e($g)?> Office</strong><small><?=count($officeUsers)?> user<?=count($officeUsers)===1?'':'s'?></small><span class="chat-office-unread-badge" data-chat-office-badge="<?=e($g)?>" hidden>0</span></span>
      </button>
      <div id="chat-office-<?=e(strtolower($g))?>" class="chat-office-members" hidden>
        <button class="chat-user chat-group-user" type="button" data-chat-type="group" data-chat-group="<?=e($g)?>" data-chat-key="g:<?=e($g)?>"><span class="chat-avatar-wrap"><span class="chat-user-avatar chat-group-avatar"><?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" decoding="sync" loading="eager" alt="<?=e($g)?>"><?php else: ?><span class="chat-facility-initials chat-facility-avatar"><?=e($g)?></span><?php endif; ?></span><span class="chat-office-badge" style="--office-accent:<?=e((string)($info['accent']??'#4d7fa5'))?>"><?php if($logoUrl): ?><img src="<?=e($logoUrl)?>" decoding="sync" loading="eager" alt="<?=e($g)?> office"><?php else: ?><span><?=e($g)?></span><?php endif; ?></span></span><span class="chat-user-copy"><strong><?=e($g)?> Office</strong><small>Office Group Chat</small></span><span class="chat-unread-badge" data-chat-badge="g:<?=e($g)?>" hidden>0</span></button>
        <div class="chat-office-user-list">
        <?php foreach($officeUsers as $u): $officeKey=user_facility($u); $officeLogo=facility_logo($officeKey); $officeLogoUrl=facility_logo_url($officeKey); $officeName=facility_label($officeKey); ?>
          <button class="chat-user" type="button" data-chat-type="user" data-user-id="<?=$u['id']?>" data-chat-key="u:<?=$u['id']?>"><span class="chat-avatar-wrap"><?=avatar_markup($u['role'] ?? '', 'chat-large-avatar', $u['id'] ?? null)?><span class="chat-office-badge" style="--office-accent:<?=e((string)(facility_info($officeKey)['accent']??'#4d7fa5'))?>"><?php if($officeLogoUrl): ?><img src="<?=e($officeLogoUrl)?>" decoding="sync" loading="eager" alt="<?=e($officeName)?> office"><?php else: ?><span><?=e($officeName)?></span><?php endif; ?></span></span><span class="chat-user-copy"><strong><?=e($u['full_name'])?></strong><small><?=e(role_label($u['role']))?></small></span><span class="chat-unread-badge" data-chat-badge="u:<?=$u['id']?>" hidden>0</span></button>
        <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
  <section class="chat-office-section chat-all-section" data-office-group="ALL">
    <button class="chat-office-heading" type="button" aria-expanded="false" aria-controls="chat-office-all">
      <span class="chat-office-heading-main"><span class="chat-office-chevron" aria-hidden="true">▸</span><span class="chat-office-heading-logo chat-office-heading-all">ALL</span><strong>All Users</strong><small>All FAMS Users</small><span class="chat-office-unread-badge" data-chat-office-badge="ALL" hidden>0</span></span>
    </button>
    <div id="chat-office-all" class="chat-office-members" hidden>
      <button class="chat-user chat-group-user chat-all-group" type="button" data-chat-type="group" data-chat-group="ALL" data-chat-key="g:ALL"><span class="chat-avatar-wrap"><span class="chat-user-avatar chat-group-avatar chat-all-avatar">ALL</span><span class="chat-office-badge chat-group-badge">G</span></span><span class="chat-user-copy"><strong>All Users</strong><small>All Users Group Chat</small></span><span class="chat-unread-badge" data-chat-badge="g:ALL" hidden>0</span></button>
      <div class="chat-office-user-list chat-all-user-list">
      <?php foreach($users as $u): if((int)$u['id']===(int)current_user()['id']) continue; $officeKey=user_facility($u); $officeLogo=facility_logo($officeKey); $officeLogoUrl=facility_logo_url($officeKey); $officeName=facility_label($officeKey); ?>
        <button class="chat-user" type="button" data-chat-type="user" data-user-id="<?=$u['id']?>" data-chat-key="u:<?=$u['id']?>"><span class="chat-avatar-wrap"><?=avatar_markup($u['role'] ?? '', 'chat-large-avatar', $u['id'] ?? null)?><span class="chat-office-badge" style="--office-accent:<?=e((string)(facility_info($officeKey)['accent']??'#4d7fa5'))?>"><?php if($officeLogoUrl): ?><img src="<?=e($officeLogoUrl)?>" decoding="sync" loading="eager" alt="<?=e($officeName)?> office"><?php else: ?><span><?=e($officeName)?></span><?php endif; ?></span></span><span class="chat-user-copy"><strong><?=e($u['full_name'])?></strong><small><?=e(role_label($u['role']))?> · <?=e($officeName)?></small></span><span class="chat-unread-badge" data-chat-badge="u:<?=$u['id']?>" hidden>0</span></button>
      <?php endforeach; ?>
      </div>
    </div>
  </section>
  </div>
</section>
<section class="panel chat-panel">
  <div class="panel-heading compact"><div><span class="section-kicker" id="chatModeLabel">PRIVATE CHAT</span><h3 id="chatTitle">Select a chat</h3></div><span class="chat-header-unread" id="chatUnreadSummary" hidden></span></div>
  <div class="chat-messages-wrap">
    <div class="chat-messages" id="chatMessages" aria-live="polite" aria-label="Chat messages"><div class="chat-empty">Select a user or office group to start a conversation.</div></div>
    <button type="button" class="chat-jump-latest" id="chatJumpLatest" hidden aria-label="Jump to latest messages">↓ <span>Latest</span></button>
  </div>
  <div class="chat-compose-status" id="chatComposeStatus" role="status" aria-live="polite"></div>
  <form class="chat-compose" id="chatForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" id="chatRecipient"><input type="hidden" id="chatGroup"><div class="chat-composer-field"><input id="chatMessage" placeholder="Type a message..." autocomplete="off" maxlength="2000" aria-label="Message"><button type="button" class="chat-emoji-button" id="chatEmojiButton" aria-label="Open emoji picker" title="Add emoji">😊</button><div class="chat-emoji-picker" id="chatEmojiPicker" role="dialog" aria-label="Emoji picker"></div></div><button class="primary-btn" id="chatSendButton" type="submit">Send</button></form>
  <?php if((current_user()['role']??'')==='admin'): ?><button type="button" class="danger-btn chat-clear-all" id="chatClearAll" disabled>Clear Selected Chat</button><?php endif; ?>
</section>
</div>

<div class="chat-trail-modal" id="chatTrailModal" hidden aria-hidden="true">
  <div class="chat-trail-backdrop" data-chat-trail-close></div>
  <section class="chat-trail-dialog" role="dialog" aria-modal="true" aria-labelledby="chatTrailTitle">
    <header class="chat-trail-dialog-head">
      <div>
        <span class="section-kicker">MESSAGE HISTORY</span>
        <h3 id="chatTrailTitle">Edit Trail</h3>
      </div>
      <button type="button" class="chat-trail-close" data-chat-trail-close aria-label="Close edit trail">×</button>
    </header>
    <div class="chat-trail-current" id="chatTrailCurrent"></div>
    <div class="chat-trail-list" id="chatTrailList"></div>
  </section>
</div>

<?php if ($isFragment): ?></div><?php else: ?>
<script>window.CHAT_USER_ID=<?=json_encode((int)current_user()['id'])?>;window.CHAT_IS_ADMIN=<?=json_encode((current_user()['role']??'')==='admin')?>;window.CHAT_READ_ONLY=false;</script>
<script src="../core/shared-chat.js?v=20261103-v0175" defer></script>
<?php require __DIR__.'/partials/footer.php'; endif; ?>
