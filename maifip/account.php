<?php
require_once __DIR__.'/config.php';
require_login();
$user=current_user();
$userId=(int)($user['id']??0);
$pageTitle='My Account';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $len=(int)($_SERVER['CONTENT_LENGTH']??0);
  if($len>5*1024*1024){flash('error','Upload too large. Please crop/resize the photo first. Maximum request size is 5 MB.');redirect('account.php');}
  verify_csrf(); $action=(string)($_POST['action']??'');
  try {
    if($action==='avatar'){
      $data=trim((string)($_POST['avatar_crop_data']??''));
      fams_shared_transaction($pdo,function(PDO $db) use($userId,$data){fams_account_store_own_avatar($db,'maifip',$userId,$data,2*1024*1024);log_action($db,'UPDATE_AVATAR','Updated own account avatar.');}); flash('success','Profile photo updated.'); redirect('account.php');
    }
    if($action==='revert_avatar'){
      fams_shared_transaction($pdo,function(PDO $db) use($userId){fams_account_revert_own_avatar($db,'maifip',$userId);log_action($db,'REVERT_AVATAR','Reverted own account avatar.');}); flash('success','Profile photo reverted to the default avatar.'); redirect('account.php');
    }
    if($action==='profile'){
      $accountFields=fams_shared_validate_account_fields((string)($_POST['full_name']??''),(string)($_POST['username']??'')); $name=$accountFields['full_name']; $username=$accountFields['username'];
      fams_shared_transaction($pdo,function(PDO $db) use($username,$name,$userId){$lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);if(!$lock->fetchColumn())throw new RuntimeException('Account not found.');$dup=$db->prepare('SELECT COUNT(*) FROM users WHERE username=? AND id<>?');$dup->execute([$username,$userId]);if((int)$dup->fetchColumn()>0)throw new RuntimeException('That username is already in use. Please choose another.');$db->prepare('UPDATE users SET full_name=?,username=?,auth_version=auth_version+1 WHERE id=?')->execute([$name,$username,$userId]);log_action($db,'UPDATE_PROFILE','Updated own account profile.');}); $_SESSION['user']['auth_version']=(int)($_SESSION['user']['auth_version']??1)+1; flash('success','Profile updated.'); redirect('account.php');
    }
    if($action==='password'){
      $old=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
      fams_shared_transaction($pdo,function(PDO $db) use($userId,$old,$new,$confirm){fams_account_change_own_password($db,'maifip',$userId,$old,$new,$confirm);log_action($db,'CHANGE_OWN_PASSWORD','Changed own account password.');}); $_SESSION['user']['auth_version']=(int)($_SESSION['user']['auth_version']??1)+1; flash('success','Password changed successfully.'); redirect('account.php');
    }
  } catch(Throwable $e){flash('error',fams_shared_user_error($e,'Unable to update your account. Please try again.'));redirect('account.php');}
}
$st=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$st->execute([$userId]);$u=$st->fetch()?:$user;
$hasAvatar=!empty($u['avatar_data']);
$currentAvatar='avatar.php?user='.$userId.'&v='.(int)time();
$defaultAvatar=system_media_url((($u['role']??'')==='admin')?'default_admin_avatar':'default_user_avatar');
$roleText=role_label((string)($u['role']??''));
require __DIR__.'/partials/header.php';
?>
<div class="account-page-actions"><?php if (($user['role'] ?? '') === 'admin'): ?><a class="btn btn-secondary account-page-action" href="user.php">← Back to Users Management</a><?php endif; ?></div>
<div class="account-grid maifip-my-account-page">
 <section class="card profile-card">
  <div class="profile-avatar-large"><img src="<?=e($currentAvatar)?>" alt="Profile photo"><i class="profile-presence"></i></div>
  <h2><?=e((string)($u['full_name']??''))?></h2><p><?=e($roleText)?></p>
  <div class="profile-meta"><div><span>Username</span><strong><?=e((string)($u['username']??''))?></strong></div><div><span>Office / Facility</span><strong><?=e(facility_label((string)($u['facility']??'LPGMA')))?></strong></div><div><span>Status</span><strong class="online-text">● ONLINE</strong></div></div>
  <form method="post" enctype="multipart/form-data" class="avatar-upload-form" id="avatarUploadForm" data-current-avatar="<?=e($currentAvatar)?>" data-default-avatar="<?=e($defaultAvatar)?>">
   <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="avatar"><input type="hidden" name="avatar_crop_data" id="avatarCropData">
   <label for="avatarFile">Profile Photo</label><input type="file" name="avatar" id="avatarFile" accept="image/jpeg,image/png,image/webp" data-max-bytes="2097152">
   <div class="avatar-upload-controls"><button class="secondary-btn" type="button" id="openAvatarCrop">Postion / Crop</button><button class="primary-btn" type="submit">Save Photo</button></div>
   <small class="avatar-help">Choose a photo, then drag it inside the crop window and use Zoom to position it before saving.</small>
  </form>
  <form method="post" style="margin-top:8px" data-confirm="Revert your profile photo to the default avatar?"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="revert_avatar"><button class="secondary-btn btn" type="submit" <?=$hasAvatar?'':'disabled'?>>Revert to Default Avatar</button></form>
  <a class="btn btn-secondary account-page-action account-logout-action" href="logout.php" style="display:flex;margin-top:12px">Log Out</a>
 </section>
 <section class="card card-pad"><div class="section-head"><div><h2>Account Settings</h2><span class="muted">Manage your profile and security settings.</span></div></div>
  <form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="profile"><label>Full Name</label><input class="input" name="full_name" value="<?=e((string)($u['full_name']??''))?>" required><label>Username</label><input class="input" name="username" value="<?=e((string)($u['username']??''))?>" required><label>Email Address</label><input class="input" type="email" value="<?=e((string)($u['email']??''))?>" disabled><small class="form-help">Role and office/facility assignment are managed by the administrator. Username must be unique.</small><button class="btn btn-primary">Save Profile</button></form>
  <hr class="soft-divider"><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="password"><h3>Change Password</h3><p class="muted">Use at least 6 characters. Your current password is required to protect this change.</p><label>Current Password</label><input class="input" type="password" name="current_password" required><label>New Password</label><input class="input" type="password" name="new_password" minlength="6" required><label>Confirm New Password</label><input class="input" type="password" name="confirm_password" minlength="6" required><button class="btn btn-primary">Change Password</button></form>
 </section>
</div>
<div class="avatar-crop-modal" id="avatarCropModal" hidden><div class="avatar-crop-card"><div class="avatar-crop-head"><div><span class="section-kicker">AVATAR EDITOR</span><h3>Position &amp; Crop</h3></div><button type="button" class="avatar-crop-close" id="closeAvatarCrop">×</button></div><div class="avatar-crop-stage" id="avatarCropStage"><img id="avatarCropImage" alt="Avatar crop preview"></div><div class="avatar-crop-tools"><label for="avatarZoom">Zoom</label><input type="range" id="avatarZoom" min="0.1" max="5" step="0.01" value="1"><span id="avatarZoomValue">100%</span><button type="button" class="btn btn-secondary" id="centerAvatarCrop">Center</button><button type="button" class="btn btn-primary" id="applyAvatarCrop">Apply Crop</button></div><p class="avatar-crop-tip">Drag the image to choose the visible area. The saved avatar is square.</p></div></div>
<?php require __DIR__.'/partials/footer.php'; ?>
