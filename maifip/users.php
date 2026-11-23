<?php
require_once __DIR__.'/config.php'; if(!fams_current_type_allows('MAIFIP','manage_users')){http_response_code(403);exit('User management is not permitted.');}
require_login();
$user = current_user();
if (($user['role'] ?? '') !== 'admin') { header('Location: account.php'); exit; }
$isAdmin = ($user['role'] ?? '') === 'admin';
$isManager = ($user['role'] ?? '') === 'manager';
$userId = (int)($user['id'] ?? 0);
$roleOptions=fams_list_user_types($pdo,'MAIFIP',false); $createDefaultRole='encoder'; foreach(['encoder','staff','manager','social_worker','admin'] as $preferred){foreach($roleOptions as $type){if($type['role_key']===$preferred && (int)$type['is_active']===1){$createDefaultRole=$preferred;break 2;}}}


/**
 * Avatar storage intentionally does not require the PHP GD extension.
 * XAMPP installations often have GD disabled, and calling imagecreatefromstring()
 * would then cause a fatal error. We validate the image with PHP's built-in
 * getimagesize* functions and store the original valid bytes instead.
 */
function fams_validate_avatar_file(array $upload): ?array {
    return fams_shared_validate_uploaded_image($upload,2*1024*1024);
}
function fams_validate_avatar_bytes(string $raw): ?array {
    if($raw==='' || strlen($raw)>2*1024*1024) return null;
    $info=@getimagesizefromstring($raw); $mime=strtolower((string)($info['mime']??''));
    if(!$info || !in_array($mime,['image/jpeg','image/png','image/webp'],true) || (int)$info[0]>6000 || (int)$info[1]>6000) return null;
    return [$raw,$mime];
}

$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,link VARCHAR(255) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_user_read(user_id,is_read),INDEX idx_notif_created(created_at)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS email_change_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,requested_email VARCHAR(150) NOT NULL,status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_by INT UNSIGNED NULL,reviewed_at TIMESTAMP NULL DEFAULT NULL,INDEX idx_email_change_user_status(user_id,status),INDEX idx_email_change_status(status),INDEX idx_email_change_email(requested_email)) ENGINE=InnoDB");

if($_SERVER['REQUEST_METHOD']==='POST'){
    // Reject oversized profile-photo requests before CSRF validation; PHP may discard POST fields when post_max_size is exceeded.
    $contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);
    if($contentLength > 5*1024*1024){ flash('error','Upload too large. Please crop/resize the photo first. Maximum request size is 5 MB.'); redirect('users.php'); }
    verify_csrf();
    $action=$_POST['action']??'';
    if($action==='revert_avatar'){
        $pdo->prepare('UPDATE users SET avatar_data=NULL, avatar_mime=NULL, avatar_filename=NULL WHERE id=?')->execute([(int)current_user()['id']]);
        log_action($pdo,'REVERT_AVATAR','Reverted profile avatar to the default avatar.');
        flash('success','Profile photo reverted to the default avatar.');
        redirect('users.php');
    }
    if($action==='avatar'){
        $cropData=trim((string)($_POST['avatar_crop_data']??''));
        $saved=null;
        if($cropData!==''){
            if(!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i',$cropData)){flash('error','Invalid cropped avatar image.');redirect('users.php');}
            $encoded=preg_replace('#^data:image/[^;]+;base64,#i','',$cropData);
            $raw=base64_decode($encoded,true);
            $saved=fams_validate_avatar_bytes($raw===false?'':$raw);
            if(!$saved){flash('error','The cropped avatar is invalid or could not be saved.');redirect('users.php');}
        } else {
            if(!isset($_FILES['avatar']) || $_FILES['avatar']['error']!==UPLOAD_ERR_OK){flash('error','Please choose a valid image.');redirect('users.php');}
            $saved=fams_validate_avatar_file($_FILES['avatar']);
            if(!$saved){flash('error','Use a valid JPG, PNG, or WebP image up to 2 MB.');redirect('users.php');}
        }
        [$avatarData,$avatarMime]=$saved;
        $avatarOwnerId=(int)current_user()['id'];
        $avatarFilename=fams_avatar_filename((string)(current_user()['username']??'user'),$avatarOwnerId,$avatarMime);
        $pdo->prepare('UPDATE users SET avatar_data=?, avatar_mime=?, avatar_filename=? WHERE id=?')->execute([$avatarData,$avatarMime,$avatarFilename,$avatarOwnerId]);
        log_action($pdo,'UPDATE_AVATAR','Updated profile avatar.'); flash('success','Avatar updated.'); redirect('users.php');
    }
    if($action==='request_email'){
        $email=trim((string)($_POST['email']??''));
        if($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','Please enter a valid email address.');redirect('users.php');}
        $uid=(int)current_user()['id'];
        try{
            fams_shared_transaction($pdo,function(PDO $db) use($email,$uid){
                $lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$uid]);if(!$lock->fetchColumn())throw new RuntimeException('Your account could not be found.');
                $dup=$db->prepare('SELECT COUNT(*) FROM users WHERE email=? AND id<>?');$dup->execute([$email,$uid]);
                if((int)$dup->fetchColumn()>0)throw new RuntimeException('That email address is already assigned to another user.');
                $pending=$db->prepare("SELECT id FROM email_change_requests WHERE user_id=? AND status='PENDING' ORDER BY id DESC LIMIT 1");$pending->execute([$uid]);
                if($pending->fetchColumn())throw new RuntimeException('You already have an email change awaiting Site Admin approval.');
                $db->prepare("INSERT INTO email_change_requests(user_id,requested_email,status) VALUES(?,?, 'PENDING')")->execute([$uid,$email]);
                $nn=$db->prepare("INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)");
                foreach($db->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN) as $adminId){$nn->execute([(int)$adminId,'Email update awaiting approval',current_user()['full_name'].' requested to add or update their email address.','users.php']);}
                log_action($db,'REQUEST_EMAIL_CHANGE','Requested Site Admin approval to add/update the account email.');
            });
            flash('success','Email change submitted for Site Admin approval. Your current email remains unchanged until approved.');
        }catch(Throwable $e){flash('error',$e->getMessage());}
        redirect('users.php');
    }
    if(in_array($action,['approve_email','reject_email'],true) && !$isAdmin){
        http_response_code(403); exit('Only the Site Admin can approve or reject email change requests.');
    }
    if($action==='approve_email'){
        $requestId=(int)($_POST['request_id']??0);
        try{
            fams_shared_transaction($pdo,function(PDO $db) use($requestId){
                $st=$db->prepare("SELECT r.id,r.user_id,r.requested_email,u.full_name FROM email_change_requests r JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.status='PENDING' LIMIT 1 FOR UPDATE");
                $st->execute([$requestId]);$req=$st->fetch(PDO::FETCH_ASSOC);
                if(!$req)throw new RuntimeException('Email request is no longer pending.');
                $dup=$db->prepare('SELECT COUNT(*) FROM users WHERE email=? AND id<>?');$dup->execute([$req['requested_email'],(int)$req['user_id']]);
                if((int)$dup->fetchColumn()>0)throw new RuntimeException('That email address is already assigned to another user.');
                $mark=$db->prepare("UPDATE email_change_requests SET status='APPROVED',reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='PENDING'");
                $mark->execute([(int)current_user()['id'],$requestId]);if($mark->rowCount()!==1)throw new RuntimeException('Email request was already reviewed.');
                $db->prepare('UPDATE users SET email=? WHERE id=?')->execute([$req['requested_email'],(int)$req['user_id']]);
                $db->prepare("INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)")->execute([(int)$req['user_id'],'Email update approved','Your requested email address has been approved by Site Admin.','users.php']);
                log_action($db,'APPROVE_EMAIL_CHANGE','Approved email update for '.($req['full_name']??('user ID '.(int)$req['user_id'])).'.');
            });
            flash('success','Email update approved.');
        }catch(Throwable $e){flash('error',$e->getMessage());}
        redirect('users.php');
    }
    if($action==='reject_email'){
        $requestId=(int)($_POST['request_id']??0);
        try{
            fams_shared_transaction($pdo,function(PDO $db) use($requestId){
                $st=$db->prepare("SELECT user_id FROM email_change_requests WHERE id=? AND status='PENDING' LIMIT 1 FOR UPDATE");$st->execute([$requestId]);$req=$st->fetch(PDO::FETCH_ASSOC);
                if(!$req)throw new RuntimeException('Email request is no longer pending.');
                $mark=$db->prepare("UPDATE email_change_requests SET status='REJECTED',reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='PENDING'");
                $mark->execute([(int)current_user()['id'],$requestId]);if($mark->rowCount()!==1)throw new RuntimeException('Email request was already reviewed.');
                $db->prepare("INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)")->execute([(int)$req['user_id'],'Email update rejected','Your requested email address was not approved by Site Admin. You may submit another request.','users.php']);
                log_action($db,'REJECT_EMAIL_CHANGE','Rejected an email update request.');
            });
            flash('success','Email update request rejected.');
        }catch(Throwable $e){flash('error',$e->getMessage());}
        redirect('users.php');
    }
    if($action==='profile'){
        $uid=(int)current_user()['id']; $name=fams_uppercase_text($_POST['full_name']??''); $username=trim((string)($_POST['username']??''));
        if($name===''){flash('error','Name is required.');redirect('users.php');}
        if($username===''){flash('error','Username is required.');redirect('users.php');}
        $dup=$pdo->prepare('SELECT COUNT(*) FROM users WHERE username=? AND id<>?'); $dup->execute([$username,$uid]);
        if((int)$dup->fetchColumn()>0){flash('error','That username is already in use.');redirect('users.php');}
        fams_shared_transaction($pdo,function(PDO $db) use($name,$username,$uid){$lock=$db->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$uid]);if(!$lock->fetchColumn())throw new RuntimeException('Account not found.');$db->prepare('UPDATE users SET full_name=?,username=?,auth_version=auth_version+1 WHERE id=?')->execute([$name,$username,$uid]);log_action($db,'UPDATE_PROFILE','Updated own account profile.');});
        $_SESSION['user']['auth_version']=(int)($_SESSION['user']['auth_version']??1)+1;$_SESSION['user']['full_name']=$name; $_SESSION['user']['username']=$username;
        flash('success','Profile updated.'); redirect('users.php');
    }
    if($action==='password'){ $old=$_POST['current_password']??''; $new=$_POST['new_password']??''; $confirm=$_POST['confirm_password']??''; $st=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$st->execute([(int)current_user()['id']]);$hash=(string)$st->fetchColumn(); if(!password_verify($old,$hash)){flash('error','Current password is incorrect.');redirect('users.php');} try{fams_shared_validate_password((string)$new);if($new!==$confirm)throw new RuntimeException('New passwords do not match.');}catch(Throwable $validationError){flash('error',$validationError->getMessage());redirect('users.php');} $uid=(int)current_user()['id'];fams_shared_transaction($pdo,function(PDO $db) use($new,$uid){$db->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$uid]);log_action($db,'CHANGE_OWN_PASSWORD','Changed own account password.');});$_SESSION['user']['auth_version']=(int)($_SESSION['user']['auth_version']??1)+1; flash('success','Password changed.'); redirect('users.php'); }
    if(!$isAdmin && in_array($action,['create','approve','reject','remove','change_password','toggle','approve_email','reject_email'],true)){ http_response_code(403); exit('Access denied.'); }
    if($action==='create'){
        $name=fams_uppercase_text($_POST['full_name']??'');
        $username=trim((string)($_POST['username']??''));
        $password=(string)($_POST['password']??'');
        $role=(string)($_POST['role']??'encoder');
        $facility=trim((string)($_POST['facility']??'LPGMA'));
        $popup=!empty($_POST['popup_mode']);
        $back=function(string $msg) use ($popup){ redirect($popup ? 'users.php?create_user=1&popup=1&error='.rawurlencode($msg) : 'users.php'); };
        $allowedFacilities=array_merge(['LPGMA'], array_keys(facility_options()));
        $baseRole=fams_base_role($pdo,'MAIFIP',$role);
        if($name==='') $back('Full name is required.');
        if($username==='') $back('Username is required.');
        try{fams_shared_validate_password((string)$password);}catch(Throwable $validationError){$back($validationError->getMessage());}
        if($baseRole==='__disabled__') $back('This user type is disabled or unavailable.');
        if(!in_array($facility,$allowedFacilities,true)) $back('Invalid office or facility selected.');
        try { fams_type_assignment_valid($pdo,'MAIFIP',$role,$facility); } catch(Throwable $e) { $back($e->getMessage()); }
        try{fams_shared_transaction($pdo,function(PDO $db) use($name,$username,$password,$role,$facility){
            $s=$db->prepare('SELECT COUNT(*) FROM users WHERE username=?');$s->execute([$username]);if((int)$s->fetchColumn()>0)throw new RuntimeException('Username already exists.');
            $db->prepare("INSERT INTO users(full_name,username,email,password_hash,role,facility,is_active,approval_status) VALUES(?,?,NULL,?,?,?,1,'APPROVED')")->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT),$role,$facility]);
            log_action($db,'CREATE_USER',"Created user {$username}.");
        });}catch(Throwable $e){$back($e->getMessage());}
        if($popup){ redirect('users.php?create_user=1&popup=1&saved=1'); }
        flash('success','User account created.'); redirect('users.php');
    }
    if($action==='approve'){
        $uid=(int)($_POST['user_id']??0);
        try{$changed=fams_shared_transaction($pdo,function(PDO $db) use($uid){$s=$db->prepare("UPDATE users SET is_active=1, approval_status='APPROVED',auth_version=auth_version+1 WHERE id=? AND approval_status='PENDING'");$s->execute([$uid]);if($s->rowCount()!==1)throw new RuntimeException('Registration is no longer pending.');log_action($db,'APPROVE_USER',"Approved registration for user ID {$uid}.");return true;});flash('success','Registration approved.');}catch(Throwable $e){flash('error',$e->getMessage());}
        redirect('users.php');
    }
    if($action==='reject'){
        $uid=(int)($_POST['user_id']??0);
        try{fams_shared_transaction($pdo,function(PDO $db) use($uid){$s=$db->prepare("UPDATE users SET is_active=0, approval_status='REJECTED',auth_version=auth_version+1 WHERE id=? AND approval_status='PENDING'");$s->execute([$uid]);if($s->rowCount()!==1)throw new RuntimeException('Registration is no longer pending.');log_action($db,'REJECT_USER',"Rejected registration for user ID {$uid}.");});flash('success','Registration rejected.');}catch(Throwable $e){flash('error',$e->getMessage());}
        redirect('users.php');
    }
    if($action==='remove'){
        $uid=(int)($_POST['user_id']??0);
        if($uid===(int)current_user()['id']){flash('error','You cannot remove your own account.');redirect('users.php');}
        try{fams_shared_transaction($pdo,function(PDO $db) use($uid){$lock=$db->prepare('SELECT username,full_name,facility,role FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$uid]);$target=$lock->fetch(PDO::FETCH_ASSOC);if(!$target)throw new RuntimeException('User account was not found.');$stmt=$db->prepare('DELETE FROM users WHERE id=?');$stmt->execute([$uid]);if($stmt->rowCount()!==1)throw new RuntimeException('User account was changed before removal.');log_action($db,'REMOVE_USER',"Removed MAIFIP account ID {$uid} / {$target['username']} ({$target['full_name']}; {$target['role']}; {$target['facility']}).");});flash('success','User account removed.');}catch(Throwable $e){flash('error',$e->getMessage());}redirect('users.php');
    }
    if($action==='change_password'){
        $uid=(int)($_POST['user_id']??0); $password=$_POST['new_password']??'';
        try{fams_shared_validate_password((string)$password);}catch(Throwable $validationError){flash('error',$validationError->getMessage());redirect('users.php');}
        try{fams_shared_transaction($pdo,function(PDO $db) use($uid,$password){$stmt=$db->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?');$stmt->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);if($stmt->rowCount()!==1)throw new RuntimeException('User account was not found or password was unchanged.');log_action($db,'CHANGE_PASSWORD',"Changed password for user ID {$uid}.");});flash('success','Password changed successfully.');}catch(Throwable $e){flash('error',$e->getMessage());}redirect('users.php');
    }
    if($action==='toggle'){
        $uid=(int)($_POST['user_id']??0);if($uid===(int)current_user()['id']){flash('error','You cannot disable your own account.');redirect('users.php');}
        try{fams_shared_transaction($pdo,function(PDO $db) use($uid){
            $ts=$db->prepare('SELECT role,is_active FROM users WHERE id=? LIMIT 1 FOR UPDATE');$ts->execute([$uid]);$toggleTarget=$ts->fetch(PDO::FETCH_ASSOC);if(!$toggleTarget)throw new RuntimeException('Account not found.');
            if(!(int)$toggleTarget['is_active']){$typeDef=fams_type_definition($db,'MAIFIP',(string)$toggleTarget['role']);if(!$typeDef||(int)$typeDef['is_active']!==1)throw new RuntimeException('This account uses a disabled user type. Enable the type or assign an active type before enabling the account.');}
            $u=$db->prepare("UPDATE users SET is_active=IF(is_active=1,0,1), approval_status=IF(is_active=1,'REJECTED','APPROVED'),auth_version=auth_version+1 WHERE id=?");$u->execute([$uid]);if($u->rowCount()!==1)throw new RuntimeException('Account status could not be updated.');
            log_action($db,'TOGGLE_USER',"Changed active state of user ID {$uid}.");
        });flash('success','User account status updated.');}catch(Throwable $e){flash('error',$e->getMessage());}redirect('users.php');
    }
    if($action==='admin_update_user' && $isAdmin){
        $uid=(int)($_POST['user_id']??0);
        $name=fams_uppercase_text($_POST['full_name']??'');
        $username=trim((string)($_POST['username']??''));
        $email=trim((string)($_POST['email']??''));
        $role=strtolower(trim((string)($_POST['role']??'')));
        $facility=strtoupper(trim((string)($_POST['facility']??'LPGMA')));
        // Include configured, active, and legacy facility keys. Account editing must not
        // fail simply because a facility was deactivated after the account was created.
        $allowedFacilities=array_values(array_unique(array_merge(['LPGMA'], array_keys(FAMS_FACILITIES), array_keys(facility_options()))));
        try {
            if (facility_table_available($pdo)) {
                $facilityRows=$pdo->query('SELECT facility_key FROM fams_facilities')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($facilityRows as $facilityKey) $allowedFacilities[]=strtoupper(trim((string)$facilityKey));
                $allowedFacilities=array_values(array_unique($allowedFacilities));
            }
        } catch (Throwable $ignored) {}
        $baseRole=fams_base_role($pdo,'MAIFIP',$role);
        if($uid<=0){flash('error','The user account identifier is missing or invalid. Please reopen the account and try again.');redirect('users.php');}
        try{$accountFields=fams_shared_validate_account_fields($name,$username,$email);$name=$accountFields['full_name'];$username=$accountFields['username'];$email=$accountFields['email'];}catch(Throwable $validationError){flash('error',$validationError->getMessage());redirect('users.php?edit_user='.$uid);}
        if($baseRole==='__disabled__'){flash('error','Please select an enabled user type.');redirect('users.php?edit_user='.$uid);}
        if($uid===(int)current_user()['id'] && $baseRole!=='admin'){flash('error','Your Site Administrator account must remain an Administrator.');redirect('users.php?edit_user='.$uid);}
        if(!in_array($facility,$allowedFacilities,true)){flash('error','Please select a recognized office or facility.');redirect('users.php?edit_user='.$uid);}
        try { fams_type_assignment_valid($pdo,'MAIFIP',$role,$facility); } catch(Throwable $e) { flash('error',$e->getMessage());redirect('users.php?edit_user='.$uid); }
        if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)){flash('error','Please enter a valid email address or leave it blank.');redirect('users.php');}
        $dup=$pdo->prepare("SELECT username,email FROM users WHERE id<>? AND (username=? OR (email IS NOT NULL AND email<>'' AND email=?)) LIMIT 1");
        $dup->execute([$uid,$username,$email]);
        if($dup->fetch()){flash('error','Username or email is already assigned to another user.');redirect('users.php');}
        $old=$pdo->prepare('SELECT full_name,username,email,role,facility,avatar_mime,avatar_filename FROM users WHERE id=? LIMIT 1');$old->execute([$uid]);$oldUser=$old->fetch();
        if(!$oldUser){flash('error','User account was not found.');redirect('users.php');}
        $avatarData=null; $avatarMime=null; $changeAvatar=false;
        $adminCropData=trim((string)($_POST['admin_avatar_crop_data']??''));
        if($adminCropData!=='') {
            if(!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i',$adminCropData)){flash('error','Invalid cropped avatar image.');redirect('users.php?edit_user='.$uid);}
            $raw=base64_decode(preg_replace('#^data:image/(jpeg|jpg|png|webp);base64,#i','',$adminCropData),true);
            $saved=fams_validate_avatar_bytes($raw===false?'':$raw);
            if(!$saved){flash('error','The cropped avatar is invalid or could not be saved.');redirect('users.php?edit_user='.$uid);}
            [$avatarData,$avatarMime]=$saved; $changeAvatar=true;
        } elseif(isset($_FILES['admin_avatar']) && $_FILES['admin_avatar']['error']!==UPLOAD_ERR_NO_FILE){
            if($_FILES['admin_avatar']['error']!==UPLOAD_ERR_OK){flash('error','Avatar upload failed.');redirect('users.php?edit_user='.$uid);}
            $saved=fams_validate_avatar_file($_FILES['admin_avatar']);
            if(!$saved){flash('error','Use a valid JPG, PNG, or WebP image up to 2 MB.');redirect('users.php?edit_user='.$uid);}
            [$avatarData,$avatarMime]=$saved; $changeAvatar=true;
        }
        if(!empty($_POST['admin_use_default_avatar'])){$avatarData=null; $avatarMime=null; $changeAvatar=true;}
        try{
            fams_shared_transaction($pdo,function(PDO $db) use($uid,$name,$username,$email,$role,$facility,$changeAvatar,$avatarData,$avatarMime,$oldUser){
                $lock=$db->prepare('SELECT avatar_mime,avatar_filename FROM users WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$uid]);$lockedUser=$lock->fetch(PDO::FETCH_ASSOC);
                if(!$lockedUser)throw new RuntimeException('User account was not found.');
                $dup=$db->prepare("SELECT COUNT(*) FROM users WHERE id<>? AND (username=? OR (email IS NOT NULL AND email<>'' AND email=?))");$dup->execute([$uid,$username,$email]);
                if((int)$dup->fetchColumn()>0)throw new RuntimeException('Username or email is already assigned to another user.');
                if($changeAvatar){
                    $avatarFilename=$avatarData!==null ? fams_avatar_filename($username,$uid,$avatarMime) : null;
                    $db->prepare('UPDATE users SET full_name=?,username=?,email=?,role=?,facility=?,avatar_data=?,avatar_mime=?,avatar_filename=?,auth_version=auth_version+1 WHERE id=?')->execute([$name,$username,$email!==''?$email:null,$role,$facility,$avatarData,$avatarMime,$avatarFilename,$uid]);
                }else{
                    $existingFilename=(string)($lockedUser['avatar_filename']??'');
                    if(($lockedUser['avatar_mime']??'')!=='' && $existingFilename!=='')$existingFilename=fams_avatar_filename($username,$uid,(string)$lockedUser['avatar_mime']);
                    $db->prepare('UPDATE users SET full_name=?,username=?,email=?,role=?,facility=?,avatar_filename=?,auth_version=auth_version+1 WHERE id=?')->execute([$name,$username,$email!==''?$email:null,$role,$facility,$existingFilename!==''?$existingFilename:null,$uid]);
                }
                log_action($db,'ADMIN_UPDATE_USER',"Updated account details for user ID {$uid}.");
            });
            if($uid===(int)current_user()['id']){$_SESSION['user']['auth_version']=(int)$_SESSION['user']['auth_version']+1;$_SESSION['user']['full_name']=$name;$_SESSION['user']['username']=$username;$_SESSION['user']['email']=$email!==''?$email:null;$_SESSION['user']['role']=$role;$_SESSION['user']['facility']=$facility;}
            flash('success','User account details updated successfully.');
        }catch(Throwable $e){flash('error',$e->getMessage());}
        redirect(!empty($_POST['popup_mode']) ? 'users.php?edit_user='.$uid.'&popup=1&saved=1' : 'users.php');
    }
}
$users=($isAdmin || $isManager) ? $pdo->query("SELECT id,full_name,username,email,role,facility,is_active,approval_status,created_at FROM users ORDER BY CASE role WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 WHEN 'encoder' THEN 3 WHEN 'social_worker' THEN 4 ELSE 9 END, full_name ASC")->fetchAll() : [];
$usersByOffice=[];
foreach(array_merge(['LPGMA'], array_keys(facility_options())) as $facilityKey){ $usersByOffice[facility_label($facilityKey).' Office']=[]; }
foreach($users as $r){ $key=user_facility($r); $office=facility_label($key).' Office'; if(!isset($usersByOffice[$office])) $usersByOffice[$office]=[]; $usersByOffice[$office][]=$r; }
$editUserId=$isAdmin ? (int)($_GET['edit_user']??0) : 0; $editUser=null; if($editUserId>0){$st=$pdo->prepare('SELECT id,full_name,username,email,role,facility,is_active,approval_status FROM users WHERE id=? LIMIT 1');$st->execute([$editUserId]);$editUser=$st->fetch() ?: null;}
$pendingEmailRequests=[]; if($isAdmin){ foreach($pdo->query("SELECT r.id,r.user_id,r.requested_email,r.requested_at,u.full_name,u.username,u.email FROM email_change_requests r JOIN users u ON u.id=r.user_id WHERE r.status='PENDING' ORDER BY r.requested_at ASC")->fetchAll() as $er){$pendingEmailRequests[(int)$er['user_id']]=$er;} }
$myAccountRow=$pdo->prepare('SELECT email,full_name,username FROM users WHERE id=? LIMIT 1'); $myAccountRow->execute([$userId]); $myAccountRow=$myAccountRow->fetch() ?: [];
$myEmail=(string)($myAccountRow['email']??'');
$myPendingEmail=null; $st=$pdo->prepare("SELECT requested_email,requested_at FROM email_change_requests WHERE user_id=? AND status='PENDING' ORDER BY id DESC LIMIT 1"); $st->execute([$userId]); $myPendingEmail=$st->fetch() ?: null;

$pageTitle=$isAdmin ? 'Accounts Management' : 'My Account';
$createPopup = $isAdmin && isset($_GET['create_user']) && $_GET['create_user']==='1' && isset($_GET['popup']) && $_GET['popup']==='1';
$isPopup = $isAdmin && $editUser && isset($_GET['popup']) && $_GET['popup']==='1';
if($createPopup || $isPopup){ echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($createPopup?'Create User Account':'Edit User Account').'</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="users-popup-page">'; } else { require __DIR__.'/partials/header.php'; }
$avatarExistsSt=$pdo->prepare('SELECT avatar_data IS NOT NULL AND OCTET_LENGTH(avatar_data)>0 FROM users WHERE id=?'); $avatarExistsSt->execute([$userId]);
$hasCustomAvatar=(bool)$avatarExistsSt->fetchColumn();
$currentAvatarUrl=$hasCustomAvatar ? 'avatar.php?user='.$userId : '';
$defaultAvatarUrl=system_media_url((($user['role']??'')==='admin') ? 'default_admin_avatar' : 'default_user_avatar');
?>
<?php if($isAdmin): ?>
<section class="panel account-management-page maifip-account-access-bar"><div class="section-head"><div><span class="section-kicker">ACCOUNT MANAGEMENT</span><h2>Accounts &amp; Access</h2><span class="muted">Manage system accounts, registration approval, and access across the shared LPGMA identity registry.</span></div><div class="account-management-header-actions"><a class="secondary-btn" href="account.php"><span aria-hidden="true">◉</span> My Account</a><a class="secondary-btn" href="usertypes.php?system=MAIFIP">User Types &amp; Permissions</a><a class="primary-btn" data-fams-popup data-popup-title="Create User Account" href="users.php?create_user=1&amp;popup=1"><span aria-hidden="true">＋</span> New Account</a></div></div></section>
<?php endif; ?>
<div class="account-user-management-grid">
<div class="account-self-modal" id="accountSelfModal" hidden><div class="account-self-modal-backdrop" data-account-modal-close></div><section class="panel account-self-panel" id="my-account" role="dialog" aria-modal="true" aria-labelledby="accountSelfTitle"><button type="button" class="account-self-modal-close" aria-label="Close account details" data-account-modal-close>×</button><div class="panel-heading compact"><div><span class="section-kicker">MY ACCOUNT</span><h3 id="accountSelfTitle"><?= $isAdmin ? 'Accounts Management' : 'My Account' ?></h3></div></div><div class="account-profile-card"><?=avatar_markup($user['role']??'', 'account-avatar', $user['id'] ?? null)?><div><strong><?=e($user['full_name']??'')?></strong><span><?=e(role_label($user['role']??''))?></span><small><?=e($user['username']??'')?></small></div></div><form method="post" enctype="multipart/form-data" class="avatar-upload-form" id="avatarUploadForm" data-current-avatar="<?=e($currentAvatarUrl)?>" data-default-avatar="<?=e($defaultAvatarUrl)?>"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="avatar"><input type="hidden" name="avatar_crop_data" id="avatarCropData"><div class="avatar-upload-controls"><label for="avatarFile">Profile Photo</label><input type="file" name="avatar" id="avatarFile" accept="image/jpeg,image/png,image/webp" data-max-bytes="2097152"><button class="secondary-btn" type="button" id="openAvatarCrop"><?= $hasCustomAvatar ? 'Reposition / Crop Current Photo' : 'Resize / Crop Default Photo' ?></button><button class="primary-btn" type="submit">Save Avatar</button><button class="secondary-btn avatar-revert-btn" type="submit" name="action" value="revert_avatar" <?= $hasCustomAvatar ? '' : 'disabled' ?> onclick="return confirm('Revert your profile photo to the default avatar?')">Revert to Default Photo</button></div><small class="avatar-help">Choose a photo, then reposition it inside the circular crop window and use the zoom control. The crop preview is circular. You can resize and reposition your current photo or the default avatar before saving.</small></form>
<div class="avatar-crop-modal" id="avatarCropModal" hidden><div class="avatar-crop-card"><div class="avatar-crop-head"><div><span class="section-kicker">AVATAR EDITOR</span><h3>Position & Crop</h3></div><button type="button" class="avatar-crop-close" id="closeAvatarCrop" aria-label="Close">×</button></div><div class="avatar-crop-stage" id="avatarCropStage"><img id="avatarCropImage" alt="Avatar crop preview"></div><div class="avatar-crop-tools"><label for="avatarZoom">Zoom</label><input type="range" id="avatarZoom" min="0.1" max="5" step="0.01" value="1"><span id="avatarZoomValue">100%</span><button type="button" class="secondary-btn" id="centerAvatarCrop">Center</button><button type="button" class="primary-btn" id="applyAvatarCrop">Apply Crop</button></div><p class="avatar-crop-tip">Drag the image to choose the visible area. The saved avatar is square.</p></div></div><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="profile"><label>Full Name</label><input name="full_name" value="<?=e($user['full_name']??'')?>" required><label>Username</label><input name="username" value="<?=e($user['username']??'')?>" autocomplete="username" required><small class="form-help">You can update your own name and username. Your user type remains controlled by Site Admin.</small><button class="primary-btn" type="submit">Save Profile</button></form><form method="post" class="record-form email-request-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="request_email"><label>Email Address</label><input type="email" name="email" value="<?=e($myEmail)?>" placeholder="Add your email address" required <?= $myPendingEmail ? 'disabled' : '' ?>><small class="form-help">Email changes require Site Admin approval before your login email is changed.</small><?php if($myPendingEmail): ?><div class="pending-email-note">Pending approval: <strong><?=e($myPendingEmail['requested_email'])?></strong></div><?php endif; ?><button class="primary-btn" type="submit" <?= $myPendingEmail ? 'disabled' : '' ?>><?= $myEmail ? 'Request Email Update' : 'Request Email Address' ?></button></form><hr class="soft-divider"><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="password"><label>Current Password</label><input type="password" name="current_password" required><label>New Password</label><input type="password" name="new_password" minlength="6" required><label>Confirm New Password</label><input type="password" name="confirm_password" minlength="6" required><button class="primary-btn" type="submit">Change My Password</button></form></section></div>
<?php if($isAdmin): ?>
<?php if($isAdmin): ?><div class="panel-heading compact"><div><span class="section-kicker">NEW ACCOUNT</span><h3>Create User</h3></div><span class="editor-state-chip">Focused editor</span></div><div class="editor-launch-card"><div class="editor-launch-icon">+</div><div><strong>Create a new system account</strong><span>Set the user's identity, office/facility, user type, and password in a focused editor.</span></div><a class="primary-btn" data-fams-popup data-popup-title="Create User Account" href="users.php?create_user=1&popup=1">Create Account</a></div></section>
<?php elseif($isManager): ?>
<section class="panel"><div class="panel-heading compact"><div><span class="section-kicker">MANAGER ACCESS</span><h3>Accounts Management</h3></div></div><div class="notice"><strong>Read-only:</strong> LPGMA Manager accounts can view system users and account status, but only Site Admin can create, edit, approve, disable, or remove accounts.</div></section>
<?php endif; ?>
<?php if($createPopup): ?>
<section class="panel admin-user-editor-panel user-create-popup-panel"><div class="panel-heading compact"><div><span class="section-kicker">NEW ACCOUNT</span><h3>Create User Account</h3></div></div>
<?php if(isset($_GET['error'])): ?><div class="notice error popup-form-notice"><?=e((string)$_GET['error'])?></div><?php endif; ?>
<form method="post" class="admin-user-editor-form modern-editor-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><input type="hidden" name="popup_mode" value="1"><div class="editor-form-intro"><span class="editor-form-icon">+</span><div><strong>New system account</strong><small>Create an account and assign it to the correct office or medical facility.</small></div></div><div class="admin-user-editor-grid create-user-grid"><div><label>Full Name</label><input name="full_name" required autocomplete="name"></div><div><label>Username</label><input name="username" required autocomplete="username"></div><div><label>Password</label><input type="password" name="password" minlength="6" required autocomplete="new-password"><small>Password must be at least 6 characters.</small></div><div><label>Office / Facility</label><div class="role-select-wrap facility-select-wrap"><span id="userFacilityLogo" class="user-office-logo" aria-hidden="true"><?=facility_logo_markup('LPGMA')?></span><select name="facility" id="userFacility" class="facility-select" required><option value="LPGMA" selected>LPGMA Office</option><?php foreach(facility_options() as $fk=>$fi): ?><option value="<?=e($fk)?>"><?=e($fi['name'])?> (<?=e($fk)?>)</option><?php endforeach; ?></select></div></div><div><label>User Type</label><div class="role-select-wrap"><select name="role" id="userRole" class="role-colored-select"><?php foreach($roleOptions as $type): if(!(int)$type['is_active']) continue; $med=$type['base_role']==='social_worker'; ?><option value="<?=e($type['role_key'])?>" data-base-role="<?=e($type['base_role'])?>" data-office-role="<?=$med?'0':'1'?>" data-medical-role="<?=$med?'1':'0'?>" <?=($type['role_key']===$createDefaultRole?'selected':'')?>><?=e($type['label'])?></option><?php endforeach; ?></select></div></div></div><div class="form-actions"><button class="secondary-btn" type="button" onclick="parent.postMessage({type:'fams-close-popup'},'*')">Cancel</button><button class="primary-btn" type="submit">Create Account</button></div></form></section>
<?php endif; ?>
<section class="panel admin-user-editor-panel">
<div class="panel-heading compact"><div><span class="section-kicker">ADMINISTRATION</span><h3><?= $editUser ? 'Edit User Account' : 'Manage User Accounts' ?></h3></div><?php if($editUser): ?><a class="ghost-btn" href="users.php">Close Editor</a><?php endif; ?></div>
<?php if($editUser): ?>
<form method="post" enctype="multipart/form-data" class="admin-user-editor-form">
<input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="admin_update_user"><input type="hidden" name="user_id" value="<?=$editUser['id']?>">
<div class="admin-user-editor-grid">
<div class="admin-editor-avatar"><div class="admin-editor-avatar-preview"><?=avatar_markup($editUser['role']??'', 'admin-edit-avatar', $editUser['id'] ?? null)?></div><label>Avatar</label><input type="file" name="admin_avatar" id="adminAvatarFile" accept="image/jpeg,image/png,image/webp"><input type="hidden" name="admin_avatar_crop_data" id="adminAvatarCropData"><button class="secondary-btn" type="button" id="openAdminAvatarCrop">Position / Crop Avatar</button><label class="admin-default-avatar-check"><input type="checkbox" name="admin_use_default_avatar" value="1"> Use default avatar</label><small>Upload a JPG, PNG, or WebP image up to 2 MB, then position the visible area before saving.</small></div>
<div><label>Full Name</label><input name="full_name" value="<?=e($editUser['full_name'])?>" required></div>
<div><label>Username</label><input name="username" value="<?=e($editUser['username'])?>" required></div>
<div><label>Email</label><input type="email" name="email" value="<?=e($editUser['email']??'')?>" placeholder="Leave blank if none"></div>
<div><label>Facility</label><div class="role-select-wrap facility-select-wrap"><span id="userFacilityLogo" class="user-office-logo" aria-hidden="true"><?=facility_logo_markup(user_facility($editUser))?></span><select name="facility" id="userFacility" required><?php $accountFacilities=array_merge(FAMS_FACILITIES, facility_options()); $currentFacilityKey=user_facility($editUser); if(!isset($accountFacilities[$currentFacilityKey])) $accountFacilities[$currentFacilityKey]=['name'=>$currentFacilityKey,'short'=>$currentFacilityKey]; foreach($accountFacilities as $fk=>$fi): ?><option value="<?=e($fk)?>" <?=user_facility($editUser)===$fk?'selected':''?>><?=e($fi['name'])?> (<?=e($fk)?>)</option><?php endforeach; ?></select></div></div>
<div><label>User Type</label><select name="role" id="userRole" required><?php foreach($roleOptions as $type): $med=$type['base_role']==='social_worker'; $assigned=((string)$editUser['role']===(string)$type['role_key']); ?><option value="<?=e($type['role_key'])?>" data-base-role="<?=e($type['base_role'])?>" data-office-role="<?=$med?'0':'1'?>" data-medical-role="<?=$med?'1':'0'?>" <?=$assigned?'selected':''?> <?=(!(int)$type['is_active'])?'disabled':''?>><?=e($type['label'])?><?=(!(int)$type['is_active']?' (Disabled)':'')?></option><?php endforeach; ?></select></div>
</div><div class="form-actions"><a class="ghost-btn" href="users.php">Cancel</a><button class="primary-btn" type="submit">Save User Changes</button></div>
</form>
<div class="avatar-crop-modal" id="adminAvatarCropModal" hidden><div class="avatar-crop-card"><div class="avatar-crop-head"><div><span class="section-kicker">AVATAR EDITOR</span><h3>Position &amp; Crop User Avatar</h3></div><button type="button" class="avatar-crop-close" id="closeAdminAvatarCrop" aria-label="Close">×</button></div><div class="avatar-crop-stage" id="adminAvatarCropStage"><img id="adminAvatarCropImage" alt="User avatar crop preview"></div><div class="avatar-crop-tools"><label for="adminAvatarZoom">Zoom</label><input type="range" id="adminAvatarZoom" min="0.1" max="5" step="0.01" value="1"><span id="adminAvatarZoomValue">100%</span><button type="button" class="secondary-btn" id="centerAdminAvatarCrop">Center</button><button type="button" class="primary-btn" id="applyAdminAvatarCrop">Apply Crop</button></div><p class="avatar-crop-tip">Drag the image to choose the visible area. The saved avatar is square.</p></div></div>
<?php else: ?><div class="notice"><strong>Administrator controls:</strong> Select <strong>Edit User</strong> in any row to change the user's Full Name, Username, Email, User Type, or Avatar.</div><?php endif; ?>
</section>
<section class="panel system-users-panel"><div class="panel-heading compact"><div><span class="section-kicker">REGISTRATION & ACCESS</span><h3>System Users</h3></div><span class="pending-panel-badge" data-notif-category="account" hidden>0 pending</span></div>
<div class="users-directory-toolbar"><div class="users-directory-search"><span class="search-icon">⌕</span><input type="search" id="usersDirectorySearch" placeholder="Search users, usernames, offices, or facilities..." autocomplete="off"><button type="button" class="secondary-btn" id="usersDirectoryReset">Reset</button></div><span class="users-directory-count" id="usersDirectoryCount"></span></div>
<div class="users-groups">
<?php foreach($usersByOffice as $officeName=>$officeUsers): ?>
<div class="user-office-group">
<button type="button" class="user-office-group-head" aria-expanded="false"><span class="user-office-group-toggle" aria-hidden="true">+</span><span><span class="section-kicker"><?=e(strtoupper($officeName))?></span><strong><?=count($officeUsers)?> system user<?=count($officeUsers)===1?'':'s'?></strong></span><span class="user-office-pill"><?=e($officeName)?></span></button>
<div class="table-wrap compact-users-table"><?php if(!$officeUsers): ?><div class="empty-office-card"><strong>No users registered yet.</strong><span>This office is ready for its first account.</span></div><?php else: ?><table class="fams-data-list-table"><thead><tr><th>User</th><th>Username / Email</th><th>User Type</th><th>Access</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($officeUsers as $r):?>
<tr>
<td><div class="user-table-identity"><?=avatar_markup($r['role']??'', 'user-table-avatar', $r['id'] ?? null)?><span class="user-table-office-logo" style="--office-accent:<?=e((string)(facility_info(user_facility($r))['accent']??'#2b587d'))?>"><?=facility_logo_markup(user_facility($r), 'user-table-office-logo-image')?></span><div><strong><?=e($r['full_name'])?></strong><small><?=e($officeName)?></small></div></div></td><td><div class="user-login-stack"><span class="user-login-value"><?=e($r['email'] ?: 'No email')?></span><small>Username: <?=e($r['username'])?></small><?php if(isset($pendingEmailRequests[(int)$r['id']])): ?><small class="pending-email-inline">Pending: <?=e($pendingEmailRequests[(int)$r['id']]['requested_email'])?></small><?php endif; ?></div></td><td><span class="user-role-chip role-<?=e($r['role'])?>"><?=e(role_label($r['role']))?></span></td><td><span class="user-access-chip <?=strtoupper($r['approval_status']??'APPROVED')==='APPROVED'?'approved':'pending'?>"><?=e($r['approval_status'] ?? 'APPROVED')?></span></td><td><span class="user-status-chip <?=((int)$r['is_active']===1)?'active':'disabled'?>"><?=((int)$r['is_active']===1)?'Active':'Disabled'?></span></td>
<td class="admin-action-cell admin-action-links-cell"><div class="admin-action-links" aria-label="Actions for <?=e($r['full_name'])?>">
<?php if($isAdmin): ?>
<a class="admin-action-link icon-action" data-fams-popup data-popup-title="Edit User Account" href="users.php?edit_user=<?=$r['id']?>&popup=1" title="Edit user account" aria-label="Edit user account"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 16.5V20h3.5L19 8.5 15.5 5 4 16.5Z"/><path d="m13.8 6.7 3.5 3.5"/></svg></a>
<?php if(isset($pendingEmailRequests[(int)$r['id']])): $er=$pendingEmailRequests[(int)$r['id']]; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="approve_email"><input type="hidden" name="request_id" value="<?=$er['id']?>"><button class="admin-action-link icon-action fams-icon-action fams-icon-action-approve" type="submit" title="Approve email change" aria-label="Approve email change"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12.5 4.2 4.2L19 7"/></svg></button></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reject_email"><input type="hidden" name="request_id" value="<?=$er['id']?>"><button class="admin-action-link danger-link icon-action fams-icon-action fams-icon-action-delete" type="submit" title="Reject email change" aria-label="Reject email change"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg></button></form>
<?php endif; ?>
<?php if(($r['approval_status']??'APPROVED')==='PENDING'):?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?=$r['id']?>"><button class="admin-action-link icon-action fams-icon-action fams-icon-action-approve" type="submit" title="Approve account" aria-label="Approve account"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12.5 4.2 4.2L19 7"/></svg></button></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="user_id" value="<?=$r['id']?>"><button class="admin-action-link danger-link icon-action fams-icon-action fams-icon-action-delete" type="submit" title="Reject account" aria-label="Reject account"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg></button></form>
<?php elseif((int)$r['id']!==(int)current_user()['id']):?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?=$r['id']?>"><button class="admin-action-link" type="submit"><?=((int)$r['is_active']===1)?'Disable':'Enable'?></button></form>
<form method="post" class="admin-password-action"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="change_password"><input type="hidden" name="user_id" value="<?=$r['id']?>"><input type="password" name="new_password" minlength="6" placeholder="New password" required class="mini-password"><button class="admin-action-link icon-action" type="submit" title="Replace password" aria-label="Replace password"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8 2 2-2 2 2 2-3 3-2-2-2 2"/></svg></button></form>
<form method="post" onsubmit="return confirm('Remove this user account? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?=$r['id']?>"><button class="admin-action-link danger-link icon-action fams-icon-action fams-icon-action-delete" type="submit" title="Remove user" aria-label="Remove user"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg></button></form>
<?php else:?><span class="muted admin-current-user">Current user</span><?php endif;?>
<?php else: ?><span class="muted">View only</span><?php endif; ?>
</div></td></tr>
<?php endforeach;?></tbody></table><?php endif; ?></div></div>
<?php endforeach; ?>
</div></section>
<?php endif; ?>
</div>
<script>document.addEventListener('DOMContentLoaded',()=>{const root=document.querySelector('.users-groups');if(!root)return;const search=document.getElementById('usersDirectorySearch'),reset=document.getElementById('usersDirectoryReset'),count=document.getElementById('usersDirectoryCount');const groups=[...root.querySelectorAll('.user-office-group')];const setOpen=(g,open)=>{g.classList.toggle('is-open',open);const b=g.querySelector('.user-office-group-head');if(b)b.setAttribute('aria-expanded',open?'true':'false');const t=g.querySelector('.user-office-group-toggle');if(t)t.textContent=open?'−':'+';};groups.forEach(g=>{setOpen(g,false);g.querySelector('.user-office-group-head')?.addEventListener('click',()=>setOpen(g,!g.classList.contains('is-open')))});const apply=()=>{const q=(search?.value||'').trim().toLowerCase();let visible=0;groups.forEach(g=>{const text=g.textContent.toLowerCase();const match=!q||text.includes(q);g.hidden=!match;if(match){visible++;setOpen(g,!!q)}});if(count)count.textContent=q?`${visible} office group${visible===1?'':'s'} found`:`${groups.length} office groups`;};search?.addEventListener('input',apply);reset?.addEventListener('click',()=>{if(search){search.value='';search.focus()}apply()});apply();});</script>
<script>
window.FAMS_ACCOUNT_FACILITY_LOGOS=<?=json_encode(array_merge(['LPGMA'=>facility_logo_url('LPGMA')],array_combine(array_keys(facility_options()),array_map(fn($k)=>facility_logo_url($k),array_keys(facility_options())))),JSON_UNESCAPED_SLASHES)?>;
window.FAMS_ACCOUNT_FACILITY_LABELS=<?=json_encode(array_merge(['LPGMA'=>'LPGMA'],array_combine(array_keys(facility_options()),array_map(fn($v)=>$v['short'],facility_options()))),JSON_UNESCAPED_SLASHES)?>;
(()=>{
 const facility=document.getElementById('userFacility'),role=document.getElementById('userRole'),logo=document.getElementById('userFacilityLogo');
 if(!facility||!role)return;
 const logos=window.FAMS_ACCOUNT_FACILITY_LOGOS||{}, labels=window.FAMS_ACCOUNT_FACILITY_LABELS||{};
 const sync=()=>{
   const key=facility.value||'LPGMA', medical=key!=='LPGMA', url=logos[key]||null, label=labels[key]||key;
   if(logo){logo.innerHTML=url?`<img src=\"${String(url).replace(/&/g,'&amp;').replace(/\"/g,'&quot;')}\" alt=\"${label} logo\" decoding=\"sync\">`:`<span class=\"facility-initials\">${label}</span>`;}
   [...role.options].forEach(o=>{const allowed=medical?o.dataset.medicalRole==='1':o.dataset.officeRole==='1';o.hidden=!allowed;o.disabled=!allowed;});
   const selected=role.selectedOptions[0]; if(!selected||selected.disabled){const wanted=medical?'social_worker':'encoder';const fallback=[...role.options].find(o=>!o.disabled&&((o.dataset.baseRole||o.value)===wanted));if(fallback)role.value=fallback.value;}
 };
 facility.addEventListener('change',sync); sync();
 document.querySelector('.admin-user-editor-form')?.addEventListener('submit',()=>parent.postMessage({type:'fams-popup-submitting'},'*'));
 <?php if(isset($_GET['saved']) && $_GET['saved']==='1'): ?>window.parent.postMessage({type:'fams-popup-success',message:'<?=e($createPopup?'User account created successfully.':'User account updated successfully.')?>'},'*');<?php endif; ?>
})();
</script><?php if($createPopup || $isPopup): ?></body></html><?php else: require __DIR__.'/partials/footer.php'; endif; ?>
