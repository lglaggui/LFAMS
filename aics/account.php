<?php
require_once __DIR__.'/config.php'; require_login(); $pageTitle='My Account'; $user=current_user();
ensure_avatar_columns($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); $action=(string)($_POST['action']??'');
 try{
  if($action==='avatar'){
   $crop=(string)($_POST['avatar_crop_data']??'');
   if($crop==='') throw new RuntimeException('Please choose a profile photo and position/crop it first.');
   fams_shared_transaction($pdo,function(PDO $db) use($user,$crop){fams_account_store_own_avatar($db,'aics',(int)$user['id'],$crop,2*1024*1024);log_action($db,'UPDATE_AVATAR','Updated profile avatar.');}); flash('success','Profile photo updated.'); redirect('users.php');
  }
  if($action==='revert_avatar'){ fams_shared_transaction($pdo,function(PDO $db) use($user){fams_account_revert_own_avatar($db,'aics',(int)$user['id']);log_action($db,'REVERT_AVATAR','Reverted profile avatar to default.');}); flash('success','Profile photo reverted to default.'); redirect('users.php'); }
  if($action==='profile'){
   $accountFields=fams_shared_validate_account_fields((string)($_POST['full_name']??''),(string)($_POST['username']??'')); $name=$accountFields['full_name']; $username=$accountFields['username'];
   fams_shared_transaction($pdo,function(PDO $db) use($name,$username,$user){$lock=$db->prepare('SELECT id FROM aics_users WHERE id=? FOR UPDATE');$lock->execute([(int)$user['id']]);if(!$lock->fetchColumn())throw new RuntimeException('Account not found.');$dup=$db->prepare('SELECT COUNT(*) FROM aics_users WHERE username=? AND id<>?');$dup->execute([$username,(int)$user['id']]);if((int)$dup->fetchColumn()>0)throw new RuntimeException('That username is already in use. Please choose another.');$db->prepare('UPDATE aics_users SET full_name=?,username=?,auth_version=auth_version+1 WHERE id=?')->execute([$name,$username,$user['id']]);log_action($db,'UPDATE_ACCOUNT','Updated personal profile.');}); $_SESSION['user']['auth_version']=(int)($_SESSION['user']['auth_version']??1)+1; flash('success','Profile updated.'); redirect('users.php');
  }
  if($action==='password'){
   $cur=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$conf=(string)($_POST['confirm_password']??'');
   fams_shared_transaction($pdo,function(PDO $db) use($user,$cur,$new,$conf){fams_account_change_own_password($db,'aics',(int)$user['id'],$cur,$new,$conf);log_action($db,'CHANGE_PASSWORD','Changed account password.');}); $_SESSION['user']['auth_version']=(int)($_SESSION['user']['auth_version']??1)+1; flash('success','Password changed successfully.'); redirect('users.php');
  }
 }catch(Throwable $e){flash('error',fams_shared_user_error($e,'Unable to update your account. Please try again.'));redirect('users.php');}
}
$st=$pdo->prepare('SELECT u.*,o.name office_name FROM aics_users u LEFT JOIN aics_offices o ON o.id=u.office_id WHERE u.id=?');$st->execute([$user['id']]);$u=$st->fetch();
$hasAvatar=!empty($u['avatar_data']);$roleLabel=function_exists('role_label') ? role_label((string)($u['role']??'')) : ucfirst(str_replace('_',' ',(string)($u['role']??'user')));$currentAvatar='avatar.php?user='.(int)$u['id'].'&v='.(int)time();$defaultAvatar='assets/avatars/'.($u['role']==='admin'?'default-administrator-avatar.webp':'default-user-avatar.webp');
require __DIR__.'/partials/header.php'; ?>
<div class="account-page-actions"><?php if(user_is_admin()): ?><a class="btn btn-secondary account-page-action" href="user.php">← Back to Users Management</a><?php endif; ?></div>
<div class="account-grid">
<section class="card profile-card">
 <div class="profile-avatar-large"><img src="<?=e($currentAvatar)?>" alt="Profile photo"><i class="profile-presence"></i></div>
 <h2><?=e($u['full_name'])?></h2><p><?=e($roleLabel)?></p>
 <div class="profile-meta"><div><span>Username</span><strong><?=e($u['username'])?></strong></div><div><span>Office</span><strong><?=e($u['office_name']??'SWAD Region II')?></strong></div><div><span>Status</span><strong class="online-text">● ONLINE</strong></div></div>
 <div class="avatar-editor-current"><div></div></div>
 <form method="post" enctype="multipart/form-data" class="avatar-upload-form" id="avatarUploadForm" data-current-avatar="<?=e($currentAvatar)?>" data-default-avatar="<?=e($defaultAvatar)?>">
  <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="avatar"><input type="hidden" name="avatar_crop_data" id="avatarCropData">
  <label>Profile Photo</label><input type="file" name="avatar" id="avatarFile" accept="image/jpeg,image/png,image/webp">
  <div class="avatar-upload-controls"><button class="secondary-btn btn" type="button" id="openAvatarCrop">Position / Crop</button><button class="primary-btn btn btn-primary" type="submit">Save Photo</button></div>
  <small class="avatar-help">Upload a photo, then drag it inside the circular crop window and use Zoom to position it like the FAMS profile-photo editor.</small>
 </form>
 <form method="post" style="margin-top:8px" data-confirm="Revert your profile photo to the default avatar?"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="revert_avatar"><button class="secondary-btn btn" type="submit" <?=$hasAvatar?'':'disabled'?>>Revert to Default Avatar</button></form>
 <a class="btn btn-secondary account-page-action account-logout-action" href="logout.php" style="display:flex;margin-top:12px">Log Out</a>
</section>
<section class="card card-pad"><div class="section-head"><div><h2>Account Settings</h2><span class="muted">Manage your profile and security settings.</span></div></div>
 <form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="profile"><label>Full Name</label><input class="input" name="full_name" value="<?=e($u['full_name'])?>" required><label>Username</label><input class="input" name="username" value="<?=e($u['username'])?>" required><label>Email Address</label><input class="input" type="email" value="<?=e($u['email']??'')?>" disabled><small class="form-help">Role and SWAD Office assignment are controlled by the administrator. Your username must be unique.</small><button class="btn btn-primary">Save Profile</button></form>
 <hr class="soft-divider"><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="password"><h3>Change Password</h3><p class="muted">Use at least 6 characters. Your current password is required to protect this change.</p><label>Current Password</label><input class="input" type="password" name="current_password" required><label>New Password</label><input class="input" type="password" name="new_password" minlength="6" required><label>Confirm New Password</label><input class="input" type="password" name="confirm_password" minlength="6" required><button class="btn btn-primary">Change Password</button></form>
</section></div>
<div class="avatar-crop-modal" id="avatarCropModal" hidden><div class="avatar-crop-card"><div class="avatar-crop-head"><div><span class="section-kicker">AVATAR EDITOR</span><h3>Position &amp; Crop</h3></div><button type="button" class="avatar-crop-close" id="closeAvatarCrop">×</button></div><div class="avatar-crop-stage" id="avatarCropStage"><img id="avatarCropImage" alt="Avatar crop preview"></div><div class="avatar-crop-tools"><label for="avatarZoom">Zoom</label><input type="range" id="avatarZoom" min="0.1" max="5" step="0.01" value="1"><span id="avatarZoomValue">100%</span><button type="button" class="btn btn-secondary" id="centerAvatarCrop">Center</button><button type="button" class="btn btn-primary" id="applyAvatarCrop">Apply Crop</button></div><p class="avatar-crop-tip">Drag the image to choose the visible area. The saved avatar is square.</p></div></div>
<?php require __DIR__.'/partials/footer.php'; ?>
