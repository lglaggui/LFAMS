<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
$activeFacility=active_data_facility($pdo);
if (!fams_current_type_allows('MAIFIP','create_records') && !fams_current_type_allows('MAIFIP','edit_records')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Offline record changes are not permitted for this user type.']); exit; }
header('Content-Type: application/json; charset=utf-8');
verify_csrf_json();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required.']);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid JSON.']);exit;}
$changes=$input['changes']??[];
if(!is_array($changes)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Changes must be an array.']);exit;}
if(count($changes)>100){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'A synchronization batch may contain at most 100 changes. Split the queue into smaller batches.']);exit;}
$user=current_user();
$allowed=['date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','bill_amount','guarantee_amount','remarks'];
$results=[];
foreach($changes as $change){
 $clientId=trim((string)($change['client_change_id']??''));
 $operation=strtoupper(trim((string)($change['operation']??'')));
 $recordId=(int)($change['record_id']??0);
 $baseVersion=max(0,(int)($change['base_version']??0));
 $payload=$change['payload']??null;
 if($clientId===''||strlen($clientId)>80||!preg_match('/^[A-Za-z0-9._:-]+$/',$clientId)||!in_array($operation,['CREATE','UPDATE'],true)||!is_array($payload)){
  $results[]=['client_change_id'=>$clientId,'status'=>'REJECTED','message'=>'Invalid offline change.'];continue;
 }
 if(($operation==='CREATE'&&!fams_current_type_allows('MAIFIP','create_records'))||($operation==='UPDATE'&&!fams_current_type_allows('MAIFIP','edit_records'))){
  $results[]=['client_change_id'=>$clientId,'status'=>'REJECTED','message'=>'This offline operation is not permitted for your user type.'];continue;
 }
 $data=[];foreach($allowed as $f)$data[$f]=trim((string)($payload[$f]??''));
 $data['bill_amount']=(float)($payload['bill_amount']??0);$data['guarantee_amount']=(float)($payload['guarantee_amount']??0);
 $data=fams_uppercase_fields($data,['patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks']);
 $date=DateTime::createFromFormat('!Y-m-d',$data['date_submission']);
 if(!$date||$date->format('Y-m-d')!==$data['date_submission']||$data['date_submission']>date('Y-m-d')||$data['patient_first_name']===''||$data['patient_last_name']===''||$data['municipality']===''||$data['bill_amount']<0||$data['guarantee_amount']<0){
  $results[]=['client_change_id'=>$clientId,'status'=>'REJECTED','message'=>'Required record fields are missing or invalid.'];continue;
 }
 try{
  // The unique client_change_id index is the final concurrency guard. The log row
  // and business change commit together, so a retry cannot create a second record.
  $sequenceLockName='fams:maifip:'.substr(hash('sha256',$activeFacility),0,48);
  if($operation==='CREATE'){$sequenceLock=$pdo->prepare('SELECT GET_LOCK(?,5)');$sequenceLock->execute([$sequenceLockName]);if((int)$sequenceLock->fetchColumn()!==1)throw new RuntimeException('The control-number sequence is busy. Please retry synchronization.');}
  try{$outcome=fams_shared_transaction($pdo,function(PDO $db) use($clientId,$operation,$recordId,$baseVersion,$payload,$data,$user,$activeFacility){
   $dup=$db->prepare('SELECT status,record_id,conflict_json FROM offline_sync_log WHERE client_change_id=? AND user_id=? LIMIT 1 FOR UPDATE');
   $dup->execute([$clientId,(int)$user['id']]);$already=$dup->fetch(PDO::FETCH_ASSOC);
   if($already)return ['duplicate'=>true,'status'=>$already['status'],'record_id'=>(int)($already['record_id']??0),'conflict'=>json_decode((string)($already['conflict_json']??''),true)];
   if($operation==='CREATE'){
    $q=$db->prepare('INSERT INTO assistance_records(facility,date_submission,patient_first_name,patient_middle_name,patient_last_name,patient_name_extension,barangay,municipality,province,guardian,contact_number,bill_amount,guarantee_amount,remarks,status,created_by,updated_by,record_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
    $q->execute([$activeFacility,$data['date_submission'],$data['patient_first_name'],$data['patient_middle_name'],$data['patient_last_name'],$data['patient_name_extension'],$data['barangay'],$data['municipality'],$data['province']?:'ISABELA',$data['guardian'],$data['contact_number'],$data['bill_amount'],$data['guarantee_amount'],$data['remarks'],'WAITING',$user['id'],$user['id']]);
    $newId=(int)$db->lastInsertId();
    $year=(int)substr($data['date_submission'],0,4);
    $st=$db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(control_number,'-',-1) AS UNSIGNED)),0) FROM assistance_records WHERE facility=? AND control_number REGEXP '^[0-9]{4}-[0-9]+$' AND LEFT(control_number,4)=?");
    $st->execute([$activeFacility,(string)$year]);$seq=(int)$st->fetchColumn()+1;
    if($seq>9999)throw new RuntimeException('Control number limit reached.');
    $control=$year.'-'.str_pad((string)$seq,4,'0',STR_PAD_LEFT);
    $db->prepare('UPDATE assistance_records SET control_number=? WHERE id=? AND facility=?')->execute([$control,$newId,$activeFacility]);
    fams_status_history_write($db,'maifip',$newId,null,'WAITING',(int)$user['id'],'Created through offline synchronization.');
    log_action($db,'OFFLINE_SYNC_CREATE','Created assistance record '.$control.' through offline synchronization.',$newId);
    $db->prepare("INSERT INTO offline_sync_log(client_change_id,user_id,operation,record_id,base_version,payload_json,status,processed_at) VALUES(?,?,?,?,?,?, 'APPLIED',NOW())")->execute([$clientId,$user['id'],'CREATE',$newId,0,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    return ['duplicate'=>false,'status'=>'APPLIED','record_id'=>$newId,'control_number'=>$control,'record_version'=>1];
   }
   if($recordId<1)throw new RuntimeException('A valid record identifier is required.');
   $q=$db->prepare('SELECT * FROM assistance_records WHERE id=? AND facility=? FOR UPDATE');$q->execute([$recordId,$activeFacility]);$current=$q->fetch(PDO::FETCH_ASSOC);
   if(!$current)throw new RuntimeException('Record not found in your assigned facility.');
   if(!can_edit_existing_record($current))throw new RuntimeException('This record is locked for your role/status.');
   $payloadJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
   if((int)$current['record_version']!==$baseVersion){
    $conflict=['server'=>['record_version'=>(int)$current['record_version'],'record'=>$current],'offline'=>['base_version'=>$baseVersion,'payload'=>$payload]];
    $db->prepare("INSERT INTO offline_sync_log(client_change_id,user_id,operation,record_id,base_version,payload_json,status,conflict_json,processed_at) VALUES(?,?,?,?,?,?, 'CONFLICT',?,NOW())")->execute([$clientId,$user['id'],'UPDATE',$recordId,$baseVersion,$payloadJson,json_encode($conflict,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    return ['duplicate'=>false,'status'=>'CONFLICT','record_id'=>$recordId,'conflict'=>$conflict];
   }
   $u=$db->prepare('UPDATE assistance_records SET date_submission=?,patient_first_name=?,patient_middle_name=?,patient_last_name=?,patient_name_extension=?,barangay=?,municipality=?,province=?,guardian=?,contact_number=?,bill_amount=?,guarantee_amount=?,remarks=?,updated_by=?,record_version=record_version+1 WHERE id=? AND facility=? AND record_version=?');
   $u->execute([$data['date_submission'],$data['patient_first_name'],$data['patient_middle_name'],$data['patient_last_name'],$data['patient_name_extension'],$data['barangay'],$data['municipality'],$data['province'],$data['guardian'],$data['contact_number'],$data['bill_amount'],$data['guarantee_amount'],$data['remarks'],$user['id'],$recordId,$activeFacility,$baseVersion]);
   if($u->rowCount()!==1)throw new RuntimeException('Record changed while synchronizing.');
   $newVersion=$baseVersion+1;log_action($db,'OFFLINE_SYNC_UPDATE','Updated assistance record through offline synchronization.',$recordId);
   $db->prepare("INSERT INTO offline_sync_log(client_change_id,user_id,operation,record_id,base_version,payload_json,status,processed_at) VALUES(?,?,?,?,?,?, 'APPLIED',NOW())")->execute([$clientId,$user['id'],'UPDATE',$recordId,$baseVersion,$payloadJson]);
   return ['duplicate'=>false,'status'=>'APPLIED','record_id'=>$recordId,'record_version'=>$newVersion];
  });}finally{if($operation==='CREATE'){$releaseLock=$pdo->prepare('SELECT RELEASE_LOCK(?)');$releaseLock->execute([$sequenceLockName]);}}
  if(!empty($outcome['duplicate'])){$results[]=['client_change_id'=>$clientId,'status'=>$outcome['status'],'record_id'=>$outcome['record_id'],'conflict'=>$outcome['conflict']??null,'duplicate'=>true];}
  else{$results[]=['client_change_id'=>$clientId]+$outcome;}
 }catch(PDOException $e){
  if((string)$e->getCode()==='23000'){
   $dup=$pdo->prepare('SELECT status,record_id,conflict_json FROM offline_sync_log WHERE client_change_id=? AND user_id=? LIMIT 1');$dup->execute([$clientId,(int)$user['id']]);$already=$dup->fetch(PDO::FETCH_ASSOC);
   if($already){$results[]=['client_change_id'=>$clientId,'status'=>$already['status'],'record_id'=>(int)($already['record_id']??0),'conflict'=>json_decode((string)($already['conflict_json']??''),true),'duplicate'=>true];continue;}
  }
  $results[]=['client_change_id'=>$clientId,'status'=>'REJECTED','record_id'=>$recordId,'message'=>'The change could not be synchronized. Please retry.'];
 }catch(Throwable $e){$results[]=['client_change_id'=>$clientId,'status'=>'REJECTED','record_id'=>$recordId,'message'=>$e->getMessage()];}
}
echo json_encode(['ok'=>true,'results'=>$results,'server_time'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
