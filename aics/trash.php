<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
if(!fams_current_type_allows('AICS','edit_records')||!user_is_admin()){http_response_code(403);exit('Administrator access required.');}
$pageTitle='Deleted Entries';$system='aics';$facility='';
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='restore_record'){
 verify_csrf();$id=(int)($_POST['id']??0);if($id<1){http_response_code(400);exit('Invalid archived entry.');}$lockName='fams:aics:control-sequence';
 aics_ensure_control_number_unique($pdo);
 try{
  $lock=$pdo->prepare('SELECT GET_LOCK(?,8)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('The control-number sequence is busy. Please retry.');
  try{$control=fams_shared_transaction($pdo,function(PDO $db) use($id,$system,$facility){
   $st=$db->prepare('SELECT * FROM fams_deleted_records WHERE id=? AND system_key=? AND facility=? AND restored_at IS NULL LIMIT 1 FOR UPDATE');$st->execute([$id,$system,$facility]);$arch=$st->fetch(PDO::FETCH_ASSOC);
   if(!$arch)throw new RuntimeException('Deleted entry not found or already restored.');
   $row=json_decode((string)$arch['record_payload'],true);if(!is_array($row))throw new RuntimeException('Archived record data is invalid.');
   $columnRows=$db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_assistance_records'")->fetchAll(PDO::FETCH_COLUMN);
   $allowedColumns=array_fill_keys($columnRows,true);
   foreach(array_keys($row) as $column){if(!isset($allowedColumns[$column]))throw new RuntimeException('Archived record contains an unsupported field: '.(string)$column);}
   unset($row['id'],$row['created_at'],$row['updated_at']);$year=(int)date('Y');
   $allocated=aics_next_control_number($db,$year);$seq=$allocated['source_no'];$control=$allocated['control'];$row['control_number']=$control;$row['source_no']=$seq;$row['date_submission']=date('Y-m-d');$row['status']='WAITING';$row['release_date']=null;$row['month_of_release']=null;$row['cancelled_flag']=0;$row['created_by']=(int)(current_user()['id']??0);$row['updated_by']=null;$row['validity_status']='VALID';$row['validity_expires_at']=date('Y-m-d',strtotime('+'.max(1,(int)($row['validity_days']??7)).' days'));$row['record_version']=1;
   $cols=array_keys($row);$sql='INSERT INTO aics_assistance_records (`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')';$db->prepare($sql)->execute(array_values($row));$newId=(int)$db->lastInsertId();
   $mark=$db->prepare('UPDATE fams_deleted_records SET restored_at=NOW(),restored_by=? WHERE id=? AND restored_at IS NULL');$mark->execute([(int)(current_user()['id']??0),$id]);if($mark->rowCount()!==1)throw new RuntimeException('This archived entry was already restored.');
   log_action($db,'RESTORE_RECORD','Restored archived entry as new record '.$control.'.',$newId);return $control;
  });}finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}
  flash('success','Restored as new entry '.$control.' dated today. Original archive retained.');
 }catch(Throwable $e){flash('error','Restore failed: '.$e->getMessage());}
 redirect('trash.php');
}
$st=$pdo->prepare('SELECT * FROM fams_deleted_records WHERE system_key=? AND facility=? ORDER BY deleted_at DESC');$st->execute([$system,$facility]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
require __DIR__.'/partials/header.php';?>
<section class="card card-pad"><span class="section-kicker">ADMINISTRATOR</span><h2>🗑 Deleted Entries</h2><p>Archived records remain here for audit history. Restoring creates a separate new entry with a new control number and today's submission date; the original archive is retained.</p><a class="btn btn-secondary" href="records.php">← Back to Masterlist</a><div class="table-wrap"><table class="table refined-table"><thead><tr><th>System</th><th>Original Control No.</th><th>Deleted</th><th>Archive Status</th><th>Record</th><th>Action</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6" class="empty">No deleted entries.</td></tr><?php endif;?><?php foreach($rows as $a):$r=json_decode($a['record_payload'],true)?:[];$name=trim(($r['beneficiary_first_name']??'').' '.($r['beneficiary_last_name']??''));?><tr><td><?=e(strtoupper($a['system_key']))?></td><td><?=e($a['control_number']??'—')?></td><td><?=e($a['deleted_at'])?></td><td><?= $a['restored_at']?'Restored '.$a['restored_at']:'In Trash' ?></td><td><?=e($name)?></td><td><?php if(!$a['restored_at']):?><form method="post" onsubmit="return confirm('Restore this as a new entry with a new control number and today\'s date?');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="restore_record"><input type="hidden" name="id" value="<?=(int)$a['id']?>"><button class="btn btn-primary" type="submit">Restore as New</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php require __DIR__.'/partials/footer.php';?>
