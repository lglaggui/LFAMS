<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login(); if(!fams_current_type_allows('MAIFIP','submit_tickets')){http_response_code(403);exit('Deletion ticket submission is not permitted.');} require_role(['encoder','staff','manager','social_worker']);
$activeFacility=active_data_facility($pdo); $formIdempotencyKey=fams_shared_idempotency_key(); $id=(int)($_GET['id']??$_POST['id']??0); $popup=isset($_GET['popup'])||isset($_POST['popup_mode']);
$q=$pdo->prepare('SELECT id,control_number,patient_first_name,patient_last_name,status,record_version FROM assistance_records WHERE id=? AND facility=? LIMIT 1'); $q->execute([$id,$activeFacility]); $record=$q->fetch(PDO::FETCH_ASSOC);
if(!$record){http_response_code(404);exit('Record not found.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); $reason=trim((string)($_POST['reason']??''));
 if($reason===''){flash('error','Please provide a reason for the delete-entry request.');redirect('delete_request.php?id='.$id.($popup?'&popup=1':''));}
 try{
  $ticket='REV-'.date('Y').'-'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT);
  $proposal=['request_type'=>'DELETE','current_status'=>strtoupper((string)$record['status']),'_base_record_version'=>(int)($record['record_version']??1),'control_number'=>$record['control_number'],'patient_name'=>trim(($record['patient_first_name']??'').' '.($record['patient_last_name']??''))];
  $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,link VARCHAR(255) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_user_read(user_id,is_read)) ENGINE=InnoDB");
  $submission=fams_shared_idempotent_transaction($pdo,'maifip.ticket.delete',(string)($_POST['idempotency_key']??''),(int)current_user()['id'],function(PDO $db) use ($ticket,$id,$reason,$proposal,$record): void {
   $db->prepare('INSERT INTO revision_tickets(ticket_number,record_id,requested_by,reason,requested_fields,proposed_data) VALUES(?,?,?,?,?,?)')->execute([$ticket,$id,current_user()['id'],$reason,'delete',json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
   $admins=$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN); $n=$db->prepare('INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)'); foreach($admins as $aid)$n->execute([(int)$aid,'New delete entry request',current_user()['full_name'].' submitted '.$ticket.' for '.$record['control_number'].'.','tickets.php']);
   log_action($db,'CREATE_DELETE_TICKET',"Submitted delete-entry approval request {$ticket} for {$record['control_number']}.",$id);
  });
  flash('success',$submission['duplicate']?'This delete request was already submitted. No duplicate ticket was created.':"Delete request {$ticket} submitted. The entry remains unchanged until an administrator approves it."); redirect('tickets.php');
 }catch(Throwable $e){flash('error','The delete request could not be submitted. No record was changed.');redirect('delete_request.php?id='.$id.($popup?'&popup=1':''));}
}
if($popup) echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Request Delete Approval</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'" /></head><body class="fams-popup-page">'; else require __DIR__.'/partials/header.php';
?>
<section class="panel"><div class="panel-heading"><div><span class="section-kicker">ADMIN APPROVAL REQUIRED</span><h2>Request Delete Entry</h2></div></div><div class="notice">This request does not delete the entry. It will remain in the masterlist unless an administrator approves the request.</div><div class="form-grid"><div><label>Control No.</label><input value="<?=e($record['control_number'])?>" disabled></div><div><label>Patient</label><input value="<?=e(trim(($record['patient_first_name']??'').' '.($record['patient_last_name']??'')))?>" disabled></div></div><form method="post" class="record-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="idempotency_key" value="<?=e($formIdempotencyKey)?>"><input type="hidden" name="id" value="<?=$id?>"><?php if($popup):?><input type="hidden" name="popup_mode" value="1"><?php endif;?><label for="deleteReason">Reason for deletion</label><textarea id="deleteReason" name="reason" rows="4" required maxlength="1500" placeholder="Explain why this entry should be deleted..."></textarea><div class="form-actions"><a class="ghost-btn" href="records.php">Cancel</a><button class="primary-btn table-action-danger" type="submit" onclick="return confirm('Submit this request for administrator approval? The entry will not be deleted now.');">Submit Delete Request</button></div></form></section>
<?php if($popup):?><script>document.querySelector('form')?.addEventListener('submit',()=>parent.postMessage({type:'fams-popup-submitting'},'*'));</script></body></html><?php else:require __DIR__.'/partials/footer.php';endif;?>
