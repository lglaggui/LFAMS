<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
if(!fams_current_type_allows('MAIFIP','edit_records')||!user_is_admin()){http_response_code(403);exit('Administrator access required.');}
$activeFacility=active_data_facility($pdo);$pageTitle='Deleted Entries';$system='maifip';
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='restore_record'){
 verify_csrf();$id=(int)($_POST['id']??0);if($id<1){http_response_code(400);exit('Invalid archived entry.');}$lockName='fams:maifip:'.substr(hash('sha256',$activeFacility),0,48);
 try{
  $lock=$pdo->prepare('SELECT GET_LOCK(?,8)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('The facility control-number sequence is busy. Please retry.');
  try{$control=fams_shared_transaction($pdo,function(PDO $db) use($id,$system,$activeFacility){
   $st=$db->prepare('SELECT * FROM fams_deleted_records WHERE id=? AND system_key=? AND facility=? AND restored_at IS NULL LIMIT 1 FOR UPDATE');$st->execute([$id,$system,$activeFacility]);$arch=$st->fetch(PDO::FETCH_ASSOC);
   if(!$arch)throw new RuntimeException('Deleted entry not found or already restored.');
   $row=json_decode((string)$arch['record_payload'],true);if(!is_array($row))throw new RuntimeException('Archived record data is invalid.');
   $columnRows=$db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assistance_records'")->fetchAll(PDO::FETCH_COLUMN);
   $allowedColumns=array_fill_keys($columnRows,true);
   foreach(array_keys($row) as $column){if(!isset($allowedColumns[$column]))throw new RuntimeException('Archived record contains an unsupported field: '.(string)$column);}
   unset($row['id'],$row['created_at'],$row['updated_at']);$year=(int)date('Y');
   $st=$db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(control_number,'-',-1) AS UNSIGNED)),0) FROM assistance_records WHERE facility=? AND control_number REGEXP '^[0-9]{4}-[0-9]+$' AND LEFT(control_number,4)=?");$st->execute([$activeFacility,(string)$year]);$seq=(int)$st->fetchColumn()+1;
   if($seq>9999)throw new RuntimeException('Control number limit reached for this facility.');
   $control=sprintf('%04d-%04d',$year,$seq);$row['control_number']=$control;$row['date_submission']=date('Y-m-d');$row['status']='WAITING';$row['release_date']=null;$row['created_by']=(int)(current_user()['id']??0);$row['updated_by']=null;$row['facility']=$activeFacility;$row['record_version']=1;
   $cols=array_keys($row);$sql='INSERT INTO assistance_records (`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')';$db->prepare($sql)->execute(array_values($row));$newId=(int)$db->lastInsertId();
   $mark=$db->prepare('UPDATE fams_deleted_records SET restored_at=NOW(),restored_by=? WHERE id=? AND restored_at IS NULL');$mark->execute([(int)(current_user()['id']??0),$id]);if($mark->rowCount()!==1)throw new RuntimeException('This archived entry was already restored.');
   log_action($db,'RESTORE_RECORD','Restored archived entry as new record '.$control.'.',$newId);return $control;
  });}finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
  flash('success','Restored as new entry '.$control.' dated today. Original archive retained.');
 }catch(Throwable $e){flash('error','Restore failed: '.$e->getMessage());}
 redirect('trash.php');
}
$st=$pdo->prepare('SELECT * FROM fams_deleted_records WHERE system_key=? AND facility=? ORDER BY deleted_at DESC');$st->execute([$system,$activeFacility]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);require __DIR__.'/partials/header.php';?>
<section class="panel form-panel"><div class="panel-heading"><div><span class="section-kicker">ADMINISTRATOR</span><h3>Deleted Entries</h3></div><a class="ghost-btn" href="records.php">← Back to Masterlist</a></div><div class="notice">Archived records remain available for audit history. Restoring creates a separate new entry with a new control number and today's submission date.</div><div class="table-wrap wide-table"><table class="fams-data-list-table"><thead><tr><th>Original Control No.</th><th>Deleted</th><th>Archive Status</th><th>Patient</th><th>Action</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="5" class="empty-state">No deleted entries.</td></tr><?php endif;?><?php foreach($rows as $a):$r=json_decode($a['record_payload'],true)?:[];$name=trim(($r['patient_first_name']??'').' '.($r['patient_last_name']??''));?><tr><td><?=e($a['control_number']??'—')?></td><td><?=e($a['deleted_at'])?></td><td><?=$a['restored_at']?'Restored '.e($a['restored_at']):'In Trash'?></td><td><?=e($name)?></td><td><?php if(!$a['restored_at']):?><form method="post" onsubmit="return confirm('Restore this as a new entry with a new control number and today\'s date?');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="restore_record"><input type="hidden" name="id" value="<?=(int)$a['id']?>"><button class="primary-btn" type="submit">Restore as New</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php require __DIR__.'/partials/footer.php';?>
