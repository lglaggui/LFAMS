<?php
require_once __DIR__.'/config.php'; require_login();
$user=current_user(); $formIdempotencyKey=fams_shared_idempotency_key(); $popup=(($_GET['popup']??'')==='1') || (($_POST['popup']??'')==='1'); $recordId=(int)($_GET['record']??$_POST['record_id']??0);
$ticketColumnCheck=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_tickets' AND COLUMN_NAME=?");
foreach(['record_id'=>'INT UNSIGNED NULL AFTER id','record_control_number'=>'VARCHAR(40) NULL AFTER record_id','proposed_data'=>'LONGTEXT NULL AFTER record_control_number'] as $column=>$definition){
 $ticketColumnCheck->execute([$column]);
 if((int)$ticketColumnCheck->fetchColumn()===0)$pdo->exec("ALTER TABLE aics_tickets ADD COLUMN `{$column}` {$definition}");
}
if(user_is_admin()) { http_response_code(403); exit('Administrators should use the record management controls.'); }
// Ticket eligibility is based on access to this record, not LPGMA workspace identity.
// SWAD social workers/staff must be able to request deletion for records assigned to their office.
$st=$pdo->prepare('SELECT * FROM aics_assistance_records WHERE id=? LIMIT 1'); $st->execute([$recordId]); $record=$st->fetch(PDO::FETCH_ASSOC);
if(!$record){http_response_code(404);exit('Assistance record not found.');}
if(!fams_current_type_allows('AICS','submit_tickets')) { http_response_code(403); exit('Your user type does not have permission to submit deletion tickets. Contact the LPGMA Site Administrator if this access is required.'); }
if(!can_access_record_scope($record)) { http_response_code(403); exit('Access restricted: your account is assigned to a specific SWAD Office and may only view or request changes to entries assigned to that same office. This protects beneficiary privacy and preserves office-level accountability. If the entry belongs to your office but is assigned incorrectly, contact the LPGMA Site Administrator to review the office assignment; do not try to bypass the restriction.'); }
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf(); $reason=trim((string)($_POST['reason']??''));
  try {
    if($reason==='') throw new RuntimeException('Please provide a reason for requesting deletion.');
    if((function_exists('mb_strlen')?mb_strlen($reason):strlen($reason))>3000) throw new RuntimeException('The reason must be 3,000 characters or fewer.');
    $subject='Deletion request for '.$record['control_number'];
    $description='Request to delete assistance record '.$record['control_number'].".\nReason: ".$reason;
    $deleteProposal=['request_type'=>'DELETE','_base_record_version'=>(int)($record['record_version']??1),'control_number'=>(string)$record['control_number']];
    $ticketSequenceLock='fams:aics:ticket-sequence';$ticketLock=$pdo->prepare('SELECT GET_LOCK(?,8)');$ticketLock->execute([$ticketSequenceLock]);if((int)$ticketLock->fetchColumn()!==1)throw new RuntimeException('The AICS ticket-number sequence is busy. Please retry.');
    try{
    $created=fams_shared_idempotent_transaction($pdo,'aics.ticket.delete',(string)($_POST['idempotency_key']??''),(int)$user['id'],function(PDO $db) use ($recordId,$subject,$description,$user,$record,$deleteProposal): string {
      $lock=$db->prepare('SELECT * FROM aics_assistance_records WHERE id=? LIMIT 1 FOR UPDATE');$lock->execute([$recordId]);$current=$lock->fetch(PDO::FETCH_ASSOC);
      if(!$current || !can_access_record_scope($current))throw new RuntimeException('You are no longer authorized to submit a ticket for this record.');
      if((int)($current['record_version']??1)!==(int)($deleteProposal['_base_record_version']??0))throw new RuntimeException('The record changed while this request was being prepared. Reload it and submit a new ticket.');
      $ticketNo=aics_next_ticket_number($db);
      $db->prepare('INSERT INTO aics_tickets(ticket_no,record_id,proposed_data,subject,category,description,priority,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$ticketNo,$recordId,json_encode($deleteProposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$subject,'DELETE REQUEST',$description,'NORMAL',(int)$user['id']]);
      log_action($db,'CREATE_DELETE_REQUEST_TICKET','Requested deletion of assistance record '.$record['control_number'].' via ticket '.$ticketNo.'.',$recordId);
      return $ticketNo;
    });
    }finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$ticketSequenceLock]);}
    $ticketNo=(string)($created['result']??'');
    if($popup){echo '<script>parent.postMessage({recordSaved:true},"*");</script>';exit;}
    flash('success',$created['duplicate']?'This deletion request was already submitted. No duplicate ticket was created.':'Deletion request submitted as '.$ticketNo.'. The record has not been deleted.'); redirect('records.php');
  } catch(Throwable $e) {
    if($popup){echo '<script>parent.postMessage({type:"fams-popup-error",message:'.json_encode($e->getMessage()).'},"*");</script>';exit;}
    flash('error',$e->getMessage()); redirect('records.php');
  }
}
if($popup) echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="popup-form-body">'; else require __DIR__.'/partials/header.php';
?>
<section class="card card-pad">
 <div class="section-head"><div><span class="section-kicker">RECORD MANAGEMENT</span><h2>Request Deletion</h2><span class="muted">Record <?=e($record['control_number'])?></span></div></div>
 <p class="notice warning">This submits a deletion request for administrator review. The record will not be deleted immediately.</p>
 <form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="idempotency_key" value="<?=e($formIdempotencyKey)?>"><input type="hidden" name="popup" value="<?=$popup?1:0?>"><input type="hidden" name="record_id" value="<?=$recordId?>">
 <div class="form-grid"><div class="span-4"><label for="deleteReason">Reason for Deletion Request *</label><textarea class="input" id="deleteReason" name="reason" rows="6" maxlength="3000" required placeholder="Explain why this assistance record should be deleted."></textarea></div></div>
 <div class="form-actions"><button class="btn btn-secondary" type="button" onclick="<?= $popup ? 'parent.postMessage({type:\'fams-close-popup\'},\'*\')' : 'history.back()' ?>">Cancel</button><button class="btn btn-danger" type="submit">Send Deletion Ticket</button></div></form>
</section>
<?php if($popup) echo '</body></html>'; else require __DIR__.'/partials/footer.php'; ?>
