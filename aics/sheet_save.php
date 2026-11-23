<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if(!fams_current_type_allows('AICS','edit_records')){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Record editing is not permitted for this user type.']);exit;}
try{
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required.');
 $token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
 if(!$token||!hash_equals($_SESSION['csrf_token']??'', $token)){http_response_code(419);throw new RuntimeException('Invalid security token.');}
 $payload=json_decode((string)file_get_contents('php://input'),true);
 $changes=$payload['changes']??[];
 if(!is_array($changes)||!$changes)throw new RuntimeException('No changes submitted.');
 $allowed=['date_submission','client_first_name','client_middle_name','client_last_name','barangay','municipality','province','beneficiary_first_name','beneficiary_last_name','assistance_type','guarantee_amount','remarks'];
 $count=fams_shared_transaction($pdo,function(PDO $db) use($changes,$allowed){
  $saved=0;
  foreach($changes as $id=>$fields){
   $id=(int)$id;if($id<=0||!is_array($fields))continue;
   $rec=$db->prepare('SELECT * FROM aics_assistance_records WHERE id=? LIMIT 1 FOR UPDATE');$rec->execute([$id]);$record=$rec->fetch(PDO::FETCH_ASSOC);
   if(!$record||!can_edit_record($record))continue;
   if(!user_is_admin()&&strtoupper((string)($record['status']??''))!=='WAITING')continue;
   $sets=[];$values=[];
   foreach($fields as $field=>$value){
    if(!in_array($field,$allowed,true))continue;
    if($field==='assistance_type'&&!in_array($value,fa_types(),true))continue;
    if($field==='guarantee_amount'&&(!is_numeric($value)||(float)$value<0))continue;
    $sets[]='`'.$field.'`=?';$values[]=$field==='guarantee_amount'?(float)$value:trim((string)$value);
   }
   if(!$sets)continue;
   $values[]=current_user()['id'];$values[]=$id;$values[]=(int)($record['record_version']??1);
   $update=$db->prepare('UPDATE aics_assistance_records SET '.implode(',',$sets).',updated_by=?,record_version=record_version+1 WHERE id=? AND record_version=?');
   $update->execute($values);
   if($update->rowCount()!==1)throw new RuntimeException('A record changed while the editable sheet was being saved. Reload the sheet and retry.');
   log_action($db,'SHEET_EDIT','Edited masterlist record from editable sheet.',$id);$saved++;
  }
  return $saved;
 });
 echo json_encode(['ok'=>true,'message'=>"Saved changes to {$count} record(s)."]);
}catch(Throwable $e){if($e instanceof RuntimeException && http_response_code()===419){echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);exit;}http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
