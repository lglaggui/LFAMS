<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
$activeFacility=active_data_facility($pdo);
if (!fams_current_type_allows('MAIFIP','view_records')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Offline conflict access is not permitted.']); exit; }
header('Content-Type: application/json; charset=utf-8');
verify_csrf_json();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
$in=json_decode((string)file_get_contents('php://input'),true);
$clientId=trim((string)($in['client_change_id']??''));
$choice=strtoupper(trim((string)($in['choice']??'')));
if($clientId===''||strlen($clientId)>80||!in_array($choice,['KEEP_SERVER','USE_OFFLINE'],true)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid conflict resolution.']);exit;}
if($choice==='USE_OFFLINE'&&!fams_current_type_allows('MAIFIP','edit_records')){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Applying offline changes is not permitted for this user type.']);exit;}
try{
 $result=fams_shared_transaction($pdo,function(PDO $db) use($clientId,$choice,$activeFacility){
  $st=$db->prepare("SELECT * FROM offline_sync_log WHERE client_change_id=? AND user_id=? AND status='CONFLICT' LIMIT 1 FOR UPDATE");
  $st->execute([$clientId,(int)current_user()['id']]);$log=$st->fetch(PDO::FETCH_ASSOC);
  if(!$log)throw new RuntimeException('Conflict not found or already resolved.');
  if($choice==='KEEP_SERVER'){
   $u=$db->prepare("UPDATE offline_sync_log SET status='RESOLVED',processed_at=NOW() WHERE id=? AND user_id=? AND status='CONFLICT'");
   $u->execute([(int)$log['id'],(int)current_user()['id']]);
   if($u->rowCount()!==1)throw new RuntimeException('Conflict was already resolved by another request.');
   log_action($db,'OFFLINE_CONFLICT_KEEP_SERVER','Resolved offline conflict by keeping the server version.',(int)$log['record_id']);
   return ['ok'=>true,'status'=>'RESOLVED','action'=>'KEEP_SERVER'];
  }
  $conf=json_decode((string)$log['conflict_json'],true)?:[];
  $serverVersion=(int)($conf['server']['record_version']??0);
  $payload=is_array($conf['offline']['payload']??null)?$conf['offline']['payload']:[];
  $recordId=(int)$log['record_id'];
  $allowed=['date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'];
  $data=[];foreach($allowed as $f)$data[$f]=trim((string)($payload[$f]??''));
  $data['bill_amount']=(float)($payload['bill_amount']??0);$data['guarantee_amount']=(float)($payload['guarantee_amount']??0);
  $data=fams_uppercase_fields($data,['patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks']);
  $date=DateTime::createFromFormat('!Y-m-d',$data['date_submission']);
  if(!$date||$date->format('Y-m-d')!==$data['date_submission']||$data['date_submission']>date('Y-m-d')||$data['patient_first_name']===''||$data['patient_last_name']===''||$data['municipality']===''||$data['bill_amount']<0||$data['guarantee_amount']<0)throw new RuntimeException('The offline values are incomplete or invalid.');
  $q=$db->prepare('SELECT * FROM assistance_records WHERE id=? AND facility=? FOR UPDATE');$q->execute([$recordId,$activeFacility]);$current=$q->fetch(PDO::FETCH_ASSOC);
  if(!$current)throw new RuntimeException('Record no longer exists in your assigned facility.');
  if(!can_edit_existing_record($current))throw new RuntimeException('This record is locked for your role/status.');
  if((int)$current['record_version']!==$serverVersion)throw new RuntimeException('The server record changed again. A fresh conflict review is required.');
  $u=$db->prepare('UPDATE assistance_records SET date_submission=?,patient_first_name=?,patient_middle_name=?,patient_last_name=?,patient_name_extension=?,barangay=?,municipality=?,province=?,guardian=?,contact_number=?,bill_amount=?,guarantee_amount=?,remarks=?,updated_by=?,record_version=record_version+1 WHERE id=? AND facility=? AND record_version=?');
  $u->execute([$data['date_submission'],$data['patient_first_name'],$data['patient_middle_name'],$data['patient_last_name'],$data['patient_name_extension'],$data['barangay'],$data['municipality'],$data['province'],$data['guardian'],$data['contact_number'],$data['bill_amount'],$data['guarantee_amount'],$data['remarks'],current_user()['id'],$recordId,$activeFacility,$serverVersion]);
  if($u->rowCount()!==1)throw new RuntimeException('The record changed while applying the conflict resolution.');
  $mark=$db->prepare("UPDATE offline_sync_log SET status='RESOLVED',processed_at=NOW() WHERE id=? AND user_id=? AND status='CONFLICT'");
  $mark->execute([(int)$log['id'],(int)current_user()['id']]);
  if($mark->rowCount()!==1)throw new RuntimeException('Conflict was already resolved by another request.');
  log_action($db,'OFFLINE_CONFLICT_USE_OFFLINE','Resolved offline conflict by applying the offline version.',(int)$recordId);
  return ['ok'=>true,'status'=>'RESOLVED','action'=>'USE_OFFLINE','record_version'=>$serverVersion+1];
 });
 echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){http_response_code(409);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
