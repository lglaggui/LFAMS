<?php
require_once __DIR__.'/config.php';
require_login();
$user=current_user()??[];$formIdempotencyKey=fams_shared_idempotency_key();$me=(int)($user['id']??0);$popup=(($_GET['popup']??'')==='1'||($_POST['popup']??'')==='1');$recordId=(int)($_GET['record']??$_POST['record_id']??0);
if(!in_array(($user['role']??''),['social_worker','staff'],true)||(int)($user['office_id']??0)<=1){http_response_code(403);exit('Office reassignment requests are available to SWAD office staff accounts only.');}
if(!fams_current_type_allows('AICS','submit_tickets')){http_response_code(403);exit('Your user type does not have permission to submit tickets. Contact the LPGMA Site Administrator if this access is required.');}
$q=$pdo->prepare('SELECT r.*,o.name office_name FROM aics_assistance_records r LEFT JOIN aics_offices o ON o.id=r.office_id WHERE r.id=? LIMIT 1');$q->execute([$recordId]);$record=$q->fetch(PDO::FETCH_ASSOC);if(!$record){http_response_code(404);exit('AICS record not found.');}
$offices=$pdo->query("SELECT id,name FROM aics_offices WHERE is_active=1 AND parent_id=2 AND UPPER(TRIM(name)) NOT IN ('LPGMA','LPGMA OFFICE') ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$reason=trim((string)($_POST['reason']??''));$target=(int)($_POST['office_id']??0);
 try{
  if($reason==='')throw new RuntimeException('Please explain why the entry should be assigned to a different SWAD office.');
  if($target<1||(int)$record['office_id']===$target)throw new RuntimeException('Select a different destination SWAD office.');
  $valid=$pdo->prepare("SELECT name FROM aics_offices WHERE id=? AND is_active=1 AND parent_id=2 AND UPPER(TRIM(name)) NOT IN ('LPGMA','LPGMA OFFICE') LIMIT 1");$valid->execute([$target]);$targetName=$valid->fetchColumn();if(!$targetName)throw new RuntimeException('Select a valid active SWAD destination office.');
  $proposal=['office_id'=>$target,'_base_record_version'=>(int)($record['record_version']??1)];$description="Office reassignment request for {$record['control_number']}. Current office: ".((string)($record['office_name']??'Unassigned')).". Requested destination: {$targetName}.
Reason: {$reason}";
  $ticketSequenceLock='fams:aics:ticket-sequence';$ticketLock=$pdo->prepare('SELECT GET_LOCK(?,8)');$ticketLock->execute([$ticketSequenceLock]);if((int)$ticketLock->fetchColumn()!==1)throw new RuntimeException('The AICS ticket-number sequence is busy. Please retry.');
  try{
  $created=fams_shared_idempotent_transaction($pdo,'aics.ticket.office_reassignment',(string)($_POST['idempotency_key']??''),$me,function(PDO $db) use ($recordId,$record,$proposal,$description,$me,$targetName): string {
    $lock=$db->prepare('SELECT * FROM aics_assistance_records WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$recordId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
    if(!$current || !can_access_record_scope($current))throw new RuntimeException('You are no longer authorized to submit a reassignment ticket for this record.');
    if((int)($current['record_version']??1)!==(int)($proposal['_base_record_version']??0) || (int)$current['office_id']===(int)$proposal['office_id'])throw new RuntimeException('The record or its office assignment changed while this request was being prepared. Reload it and submit a new ticket.');
    $ticketNo=aics_next_ticket_number($db);
    $db->prepare("INSERT INTO aics_tickets(ticket_no,record_id,proposed_data,subject,category,description,status,created_by) VALUES(?,?,?,?,'RECORD REVISION',?,'OPEN',?)")->execute([$ticketNo,$recordId,json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'Office Reassignment — '.$record['control_number'],$description,$me]);
    if(function_exists('log_action'))log_action($db,'CREATE_OFFICE_REASSIGNMENT_TICKET','Requested reassignment of '.$record['control_number'].' from '.((string)($record['office_name']??'Unassigned')).' to '.$targetName.' via '.$ticketNo.'.',$recordId);
    return $ticketNo;
  });
  }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$ticketSequenceLock]);}
  $ticketNo=(string)($created['result']??'');
  if($popup){echo '<script>parent.postMessage({recordSaved:true,type:"fams-ticket-submitted"},"*");</script>';exit;}flash('success',$created['duplicate']?'This office reassignment request was already submitted. No duplicate ticket was created.':'Office reassignment ticket '.$ticketNo.' submitted for administrator review.');redirect('records.php');
 }catch(Throwable $e){if($popup){echo '<!doctype html><meta charset="utf-8"><div style="font:14px Arial;padding:18px;color:#9f1239">'.e($e->getMessage()).'</div><script>parent.postMessage({type:"fams-popup-error",message:'.json_encode($e->getMessage()).'},"*");</script>';exit;}flash('error',$e->getMessage());redirect('office_reassignment_ticket.php?record='.$recordId);}
}
if($popup)echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'" /></head><body class="fams-popup-page">';else require __DIR__.'/partials/header.php';
?>
<section class="panel form-panel"><div class="panel-heading"><div><span class="section-kicker">SWAD OFFICE TRANSFER</span><h3>Request Office Reassignment — <?=e((string)$record['control_number'])?></h3><span class="muted">This ticket requests only an office-assignment change. It does not authorize edits to another office's beneficiary or financial details.</span></div></div>
<div class="notice"><strong>Current assigned office:</strong> <?=e((string)($record['office_name']??'Unassigned'))?><br><small>The record remains assigned to its current office until an administrator approves this ticket.</small></div>
<form method="post" class="record-form modern-editor-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="idempotency_key" value="<?=e($formIdempotencyKey)?>"><input type="hidden" name="popup" value="<?=$popup?'1':'0'?>"><input type="hidden" name="record_id" value="<?=$recordId?>"><div class="form-grid"><div class="span-4"><label>Requested Destination SWAD Office *</label><select name="office_id" required><option value="">Select destination office</option><?php foreach($offices as $o):?><option value="<?=(int)$o['id']?>" <?=((int)$record['office_id']===(int)$o['id'])?'disabled':''?>><?=e($o['name'])?></option><?php endforeach;?></select></div><div class="span-4"><label>Reason for Reassignment *</label><textarea name="reason" rows="5" maxlength="3000" required placeholder="Explain why this entry should be transferred to the selected SWAD office."></textarea></div></div><div class="form-actions"><button type="button" class="btn btn-secondary" onclick="<?=$popup?"parent.postMessage({type:'fams-close-popup'},'*')":"history.back()"?>">Cancel</button><button type="submit" class="btn btn-primary">Submit Reassignment Ticket</button></div></form></section>
<?php if($popup)echo '</body></html>';else require __DIR__.'/partials/footer.php'; ?>
