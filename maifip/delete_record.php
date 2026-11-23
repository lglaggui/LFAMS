<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_role(['admin','manager','encoder']);
if(!fams_current_type_allows('MAIFIP','edit_records')){http_response_code(403);exit('Your user type cannot delete MAIFIP records.');}
$activeFacility=active_data_facility($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Method not allowed.');}
verify_csrf();
$id=(int)($_POST['id']??0);
if($id<=0){flash('error','Invalid FAMS record selected for deletion.');redirect('records.php');}
try{
 $result=fams_shared_transaction($pdo,function(PDO $db) use($id,$activeFacility){
  $stmt=$db->prepare('SELECT * FROM assistance_records WHERE id=? AND facility=? LIMIT 1 FOR UPDATE');
  $stmt->execute([$id,$activeFacility]);$record=$stmt->fetch(PDO::FETCH_ASSOC);
  if(!$record)throw new RuntimeException('FAMS record not found. It may already have been deleted.');
  if(!can_edit_existing_record($record))throw new RuntimeException('Only authorized users may directly delete editable WAITING entries. Locked entries require an administrator ticket.');
  $control=(string)($record['control_number']??('Record #'.$id));
  $name=trim((string)($record['patient_first_name']??'').' '.(string)($record['patient_last_name']??''));
  fams_archive_record_with_history($db,'maifip',$activeFacility,$record,(int)(current_user()['id']??0));
  $del=$db->prepare('DELETE FROM assistance_records WHERE id=? AND facility=?');$del->execute([$id,$activeFacility]);
  if($del->rowCount()!==1)throw new RuntimeException('The FAMS record could not be deleted.');
  log_action($db,'ARCHIVE_RECORD','Moved FAMS record '.$control.($name!==''?' ('.$name.')':'').' to Trash.',$id);
  return $control;
 });
 flash('success','FAMS record '.$result.' was moved to Trash. It can be restored as a new entry from Deleted Entries.');
}catch(Throwable $e){flash('error',$e->getMessage());}
redirect('records.php');
