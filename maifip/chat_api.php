<?php
require_once __DIR__.'/config.php';
require_once dirname(__DIR__).'/core/chat_runtime.php';
header('Content-Type: application/json; charset=utf-8');
function chat_verify_csrf_json(): void { fams_chat_verify_request_csrf(); }
function chat_json(array $payload): never { fams_chat_json_response($payload,'maifip'); }
ini_set('display_errors','0');
ob_start();
if (!is_logged_in()) { http_response_code(401); chat_json(['ok'=>false,'error'=>'Your session has expired. Please sign in again.']); exit; }
require_once __DIR__.'/chat_unread_helper.php';
set_exception_handler(function(Throwable $e){ while(ob_get_level()>0){ob_end_clean();} http_response_code(500); chat_json(['ok'=>false,'error'=>'Chat service error. Please try again.']); });
register_shutdown_function(function(){ $e=error_get_last(); if($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){ while(ob_get_level()>0){ob_end_clean();} http_response_code(500); chat_json(['ok'=>false,'error'=>'Chat service error. Please try again.']); }});
require_login();if(!fams_current_type_allows('MAIFIP','use_chat')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
sync_current_user_from_db($pdo);
if (!is_logged_in()) { http_response_code(401); chat_json(['ok'=>false,'error'=>'Your session has expired. Please sign in again.']); }

fams_chat_ensure_schema($pdo,'maifip');

$me=(int)current_user()['id']; $role=(string)(current_user()['role']??'');
$readOnlyManager=false;
$action=$_GET['action']??$_POST['action']??''; $other=(int)($_GET['user_id']??$_POST['user_id']??0); $group=strtoupper(trim((string)($_GET['group']??$_POST['group']??'')));
$validGroups=array_merge(['LPGMA'], array_keys(facility_options()),['ALL']);
$myFacility=user_facility(current_user());

$conversationKey=function($otherId,$groupKey){ if($groupKey!=='') return 'g:'.$groupKey; return 'u:'.(int)$otherId; };
$markConversationRead=static function($key,$maxId) use($pdo,$me){ fams_chat_mark_read_cursor($pdo,'maifip',$me,(string)$key,(int)$maxId); };
$getCursor=static function($key) use($pdo,$me){ return fams_chat_get_read_cursor($pdo,'maifip',$me,(string)$key); };

if($action==='send'){
 chat_verify_csrf_json(); $msg=trim($_POST['message']??'');
 if($msg===''){http_response_code(400);chat_json(['ok'=>false,'error'=>'Empty message']);exit;}
 if($group!==''){
   if(!in_array($group,$validGroups,true)){http_response_code(400);chat_json(['ok'=>false,'error'=>'Invalid chat group']);exit;}
   if($group!=='ALL' && $group!=='LPGMA' && !facility_is_active($pdo,$group)){http_response_code(410);chat_json(['ok'=>false,'error'=>'That facility is currently disabled.']);exit;}
   $pdo->prepare('INSERT INTO user_messages(sender_id,recipient_id,group_key,message) VALUES(?,NULL,?,?)')->execute([$me,$group,$msg]);
   $others=[];
   if($group==='ALL'){
     $activeFacilities=array_keys(facility_options());
     if($activeFacilities){$ph=implode(',',array_fill(0,count($activeFacilities),'?'));$st=$pdo->prepare("SELECT id FROM users WHERE is_active=1 AND id<>? AND (facility='LPGMA' OR facility IN ($ph))");$st->execute(array_merge([$me],$activeFacilities));$others=$st->fetchAll(PDO::FETCH_COLUMN);}
     else{$st=$pdo->prepare("SELECT id FROM users WHERE is_active=1 AND id<>? AND facility='LPGMA'");$st->execute([$me]);$others=$st->fetchAll(PDO::FETCH_COLUMN);}
   } else { $st=$pdo->prepare("SELECT id FROM users WHERE is_active=1 AND id<>? AND facility=?"); $st->execute([$me,$group]); $others=$st->fetchAll(PDO::FETCH_COLUMN); }
   $n=$pdo->prepare("INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)");
   $title='New '.$group.' chat message';
   foreach($others as $uid) $n->execute([(int)$uid,$title,(string)current_user()['full_name'].' posted in the '.$group.' facility chat.','chat.php']);
   log_action($pdo,'SEND_CHAT','Sent a '.$group.' office group chat message.'); chat_json(['ok'=>true]); exit;
 }
 if($other<=0){http_response_code(400);chat_json(['ok'=>false,'error'=>'Select a user or office group first.']);exit;}
 $otherSt=$pdo->prepare('SELECT id,facility FROM users WHERE id=? AND is_active=1 LIMIT 1');
 $otherSt->execute([$other]); $otherUser=$otherSt->fetch();
 if(!$otherUser || (($otherUser['facility']??'LPGMA')!=='LPGMA' && !facility_is_active($pdo,(string)$otherUser['facility']))){http_response_code(404);chat_json(['ok'=>false,'error'=>'User is unavailable because their facility is disabled.']);exit;}
 $pdo->prepare('INSERT INTO user_messages(sender_id,recipient_id,group_key,message) VALUES(?,?,NULL,?)')->execute([$me,$other,$msg]);
 $pdo->prepare("INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)")->execute([$other,'New chat message',(string)current_user()['full_name'].' sent you a message.','chat.php']);
 log_action($pdo,'SEND_CHAT','Sent a user-to-user chat message.'); chat_json(['ok'=>true]); exit;
}

if($action==='mark_read'){
 chat_verify_csrf_json();
 $requestedMax=max(0,(int)($_POST['max_message_id']??0));
 if($group!==''){
   $key=$conversationKey(0,$group);
   if($requestedMax>0){
     if($group==='ALL'){$st=$pdo->prepare("SELECT COALESCE(MAX(id),0) FROM user_messages WHERE id<=? AND (group_key='ALL' OR (group_key IS NULL AND recipient_id IS NULL))");$st->execute([$requestedMax]);}
     else{$st=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE id<=? AND group_key=?');$st->execute([$requestedMax,$group]);}
     $max=(int)$st->fetchColumn();
   } else {
     if($group==='ALL'){$st=$pdo->query("SELECT COALESCE(MAX(id),0) FROM user_messages WHERE group_key='ALL' OR (group_key IS NULL AND recipient_id IS NULL)");}
     else{$st=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE group_key=?');$st->execute([$group]);}
     $max=(int)$st->fetchColumn();
   }
   $markConversationRead($key,$max);
   $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0 AND title LIKE ?")->execute([$me,'New '.$group.' chat%']);
 } else if($other>0){
   $key=$conversationKey($other,'');
   if($requestedMax>0){$st=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE id<=? AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)) AND group_key IS NULL');$st->execute([$requestedMax,$me,$other,$other,$me]);}
   else{$st=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)) AND group_key IS NULL');$st->execute([$me,$other,$other,$me]);}
   $max=(int)$st->fetchColumn();$markConversationRead($key,$max);
   $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0 AND title='New chat message' AND message LIKE CONCAT('%', (SELECT full_name FROM users WHERE id=?), '%')")->execute([$me,$other]);
 }
 chat_json(['ok'=>true,'last_read_message_id'=>$max??0]); exit;
}

if($action==='unread'){
 $u=fams_chat_unread_state($pdo,$me,$role);
 chat_json(['ok'=>true]+$u);exit;
}
if($action==='edit'){
 chat_verify_csrf_json();$id=(int)($_POST['message_id']??0);$msg=trim($_POST['message']??'');if($msg===''){http_response_code(400);chat_json(['ok'=>false,'error'=>'Message cannot be empty']);exit;}$st=$pdo->prepare('SELECT * FROM user_messages WHERE id=?');$st->execute([$id]);$row=$st->fetch();if(!$row||((int)$row['sender_id']!==$me && $role!=='admin')){http_response_code(403);chat_json(['ok'=>false,'error'=>'Not allowed']);exit;}if($row['deleted_at']){http_response_code(400);chat_json(['ok'=>false,'error'=>'Deleted messages cannot be edited']);exit;}$pdo->prepare('INSERT INTO user_message_trails(message_id,actor_id,action,message_before) VALUES(?,?,?,?)')->execute([$id,$me,'EDIT',$row['message']]);$pdo->prepare('UPDATE user_messages SET message=?,edited_at=NOW() WHERE id=?')->execute([$msg,$id]);chat_json(['ok'=>true]);exit;
}
if($action==='delete'){
 chat_verify_csrf_json();$id=(int)($_POST['message_id']??0);$st=$pdo->prepare('SELECT * FROM user_messages WHERE id=?');$st->execute([$id]);$row=$st->fetch();$admin=$role==='admin';if(!$row||((int)$row['sender_id']!==$me&&!$admin)){http_response_code(403);chat_json(['ok'=>false,'error'=>'Not allowed']);exit;}if($admin){$pdo->prepare('INSERT INTO user_message_trails(message_id,actor_id,action,message_before) VALUES(?,?,?,?)')->execute([$id,$me,'ADMIN_DELETE',$row['message']]);$pdo->prepare('DELETE FROM user_messages WHERE id=?')->execute([$id]);}else{$pdo->prepare('INSERT INTO user_message_trails(message_id,actor_id,action,message_before) VALUES(?,?,?,?)')->execute([$id,$me,'DELETE',$row['message']]);$pdo->prepare("UPDATE user_messages SET message='[Message deleted]',deleted_at=NOW() WHERE id=?")->execute([$id]);}chat_json(['ok'=>true]);exit;
}
if($action==='clear_all'){
 chat_verify_csrf_json();
 if($role!=='admin'){http_response_code(403);chat_json(['ok'=>false,'error'=>'Administrator access required.']);exit;}
 if($group!==''){
   if(!in_array($group,$validGroups,true)){http_response_code(400);chat_json(['ok'=>false,'error'=>'Invalid chat group']);exit;}
   $st=$pdo->prepare('SELECT id,message FROM user_messages WHERE group_key=?');$st->execute([$group]);$rows=$st->fetchAll();
   $count=count($rows);
   $trail=$pdo->prepare('INSERT INTO user_message_trails(message_id,actor_id,action,message_before) VALUES(?,?,?,?)');
   foreach($rows as $row)$trail->execute([(int)$row['id'],$me,'CLEAR_ALL',$row['message']]);
   $maxBefore=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE group_key='.$pdo->quote($group))->fetchColumn();
   if($maxBefore>0){
     $eligible=$pdo->query('SELECT id FROM users WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN);
     foreach($eligible as $uid)fams_chat_mark_read_cursor($pdo,'maifip',(int)$uid,'g:'.$group,$maxBefore);
   }
   $del=$pdo->prepare('DELETE FROM user_messages WHERE group_key=?');$del->execute([$group]);
   log_action($pdo,'CLEAR_CHAT','Administrator cleared the '.$group.' office chat.');
 } elseif($other>0){
   $st=$pdo->prepare('SELECT id,message FROM user_messages WHERE group_key IS NULL AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))');$st->execute([$me,$other,$other,$me]);$rows=$st->fetchAll();
   $count=count($rows);
   $trail=$pdo->prepare('INSERT INTO user_message_trails(message_id,actor_id,action,message_before) VALUES(?,?,?,?)');
   foreach($rows as $row)$trail->execute([(int)$row['id'],$me,'CLEAR_ALL',$row['message']]);
   $maxBefore=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE group_key IS NULL AND ((sender_id='.(int)$me.' AND recipient_id='.(int)$other.') OR (sender_id='.(int)$other.' AND recipient_id='.(int)$me.'))')->fetchColumn();
   if($maxBefore>0){
     fams_chat_mark_read_cursor($pdo,'maifip',$me,'u:'.$other,$maxBefore);
     fams_chat_mark_read_cursor($pdo,'maifip',$other,'u:'.$me,$maxBefore);
   }
   $del=$pdo->prepare('DELETE FROM user_messages WHERE group_key IS NULL AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))');$del->execute([$me,$other,$other,$me]);
   log_action($pdo,'CLEAR_CHAT','Administrator cleared the selected private chat.');
 } else {http_response_code(400);chat_json(['ok'=>false,'error'=>'Select a chat before clearing messages.']);exit;}
 chat_json(['ok'=>true,'cleared'=>$count]);exit;
}
if($action==='messages'){
 if($group!==''){
   if(!in_array($group,$validGroups,true)){http_response_code(400);chat_json(['ok'=>false,'error'=>'Invalid chat group']);exit;}
   $st=$pdo->prepare("SELECT um.*,u.full_name AS sender_name FROM user_messages um LEFT JOIN users u ON u.id=um.sender_id WHERE (um.group_key=? OR (um.group_key IS NULL AND ?='ALL' AND um.recipient_id IS NULL)) ORDER BY um.created_at ASC LIMIT 500");$st->execute([$group,$group]);
 } else if($other>0){$st=$pdo->prepare('SELECT um.*,u.full_name AS sender_name FROM user_messages um LEFT JOIN users u ON u.id=um.sender_id WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)) AND group_key IS NULL ORDER BY um.created_at ASC');$st->execute([$me,$other,$other,$me]);}
 else { $st=$pdo->query('SELECT um.*,u.full_name AS sender_name FROM user_messages um LEFT JOIN users u ON u.id=um.sender_id WHERE um.group_key="ALL" OR (um.group_key IS NULL AND um.recipient_id IS NULL) ORDER BY um.created_at ASC LIMIT 500'); }
 $msgs=$st->fetchAll();
 $conversationKey=$group!==''?$conversationKey(0,$group):$conversationKey($other,'');
 $readCursor=$getCursor($conversationKey);
 chat_json(['ok'=>true,'messages'=>$msgs,'read_cursor'=>$readCursor]);exit;
}
if($action==='trail'){ $id=(int)($_GET['message_id']??0);$st=$pdo->prepare('SELECT t.*,u.full_name FROM user_message_trails t LEFT JOIN users u ON u.id=t.actor_id WHERE t.message_id=? ORDER BY t.created_at DESC');$st->execute([$id]);chat_json(['ok'=>true,'trails'=>$st->fetchAll()]);exit;}
http_response_code(400);chat_json(['ok'=>false,'error'=>'Unknown action']);
