<?php
require_once __DIR__.'/config.php'; require_login();if(!fams_current_type_allows('MAIFIP','manage_tickets')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
$activeFacility=active_data_facility($pdo);
require_role(['admin','manager']);
// Keep resolved deletion-ticket audit rows after their linked record is archived.
try {
    $hasColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='revision_tickets' AND COLUMN_NAME=?");
    foreach ([['record_control_number','VARCHAR(40) NULL AFTER record_id'],['record_facility','VARCHAR(100) NULL AFTER record_control_number']] as [$column,$definition]) {
        $hasColumn->execute([$column]);
        if ((int)$hasColumn->fetchColumn()===0) $pdo->exec("ALTER TABLE revision_tickets ADD COLUMN {$column} {$definition}");
    }
    $pdo->exec("UPDATE revision_tickets t JOIN assistance_records a ON a.id=t.record_id SET t.record_control_number=COALESCE(t.record_control_number,a.control_number),t.record_facility=COALESCE(t.record_facility,a.facility) WHERE t.record_control_number IS NULL OR t.record_facility IS NULL");
    $fk=$pdo->prepare("SELECT CONSTRAINT_NAME,DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='revision_tickets' AND CONSTRAINT_NAME='fk_ticket_record' LIMIT 1");
    $fk->execute(); $fkInfo=$fk->fetch(PDO::FETCH_ASSOC);
    if ($fkInfo && strtoupper((string)$fkInfo['DELETE_RULE'])!=='SET NULL') $pdo->exec('ALTER TABLE revision_tickets DROP FOREIGN KEY `'.str_replace('`','',$fkInfo['CONSTRAINT_NAME']).'`');
    $nullable=$pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='revision_tickets' AND COLUMN_NAME='record_id' LIMIT 1");
    $nullable->execute(); if (strtoupper((string)$nullable->fetchColumn())!=='YES') $pdo->exec('ALTER TABLE revision_tickets MODIFY record_id INT UNSIGNED NULL');
    $fk->execute(); $fkInfo=$fk->fetch(PDO::FETCH_ASSOC);
    if (!$fkInfo) $pdo->exec('ALTER TABLE revision_tickets ADD CONSTRAINT fk_ticket_record FOREIGN KEY(record_id) REFERENCES assistance_records(id) ON DELETE SET NULL');
} catch (Throwable $e) { error_log('MAIFIP ticket referential-integrity migration failed: '.$e->getMessage()); throw new RuntimeException('Ticket history schema could not be verified. No ticket action was processed; ask the system administrator to review the database migration permissions.',0,$e); }
$ticketId=(int)($_GET['id']??$_POST['ticket_id']??0);
$isManagerView=(current_user()['role']??'')==='manager';
$isPopup=isset($_GET['popup']) && $_GET['popup']==='1';
$q=$pdo->prepare('SELECT t.*, t.status AS ticket_status, a.id AS record_id, a.control_number, a.date_submission, a.patient_first_name, a.patient_middle_name, a.patient_last_name, a.patient_name_extension, a.barangay, a.municipality, a.province, a.guardian, a.contact_number, a.bill_amount, a.guarantee_amount, a.remarks, a.status AS record_status, a.release_date FROM revision_tickets t JOIN assistance_records a ON a.id=t.record_id WHERE t.id=? AND a.facility=? LIMIT 1');
$q->execute([$ticketId,$activeFacility]); $ticket=$q->fetch();
if(!$ticket){http_response_code(404);exit('Ticket not found.');}
if($ticket['ticket_status']!=='OPEN'){flash('error','This ticket is no longer open.');redirect('tickets.php');}
$proposal=json_decode((string)($ticket['proposed_data']??''),true); if(!is_array($proposal))$proposal=[];
$isDeleteRequest=strtoupper((string)($proposal['request_type']??''))==='DELETE' || strtolower((string)($ticket['requested_fields']??''))==='delete';
$requestedStatus=strtoupper((string)($proposal['requested_status']??$ticket['record_status']));
if(!in_array($requestedStatus,['WAITING','RELEASED','CANCELLED'],true))$requestedStatus='WAITING';
$releaseDate=(string)($proposal['release_date']??($ticket['release_date']??''));
$updates=is_array($proposal['record_updates']??null)?$proposal['record_updates']:[];
foreach(['date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'] as $f){if(!array_key_exists($f,$updates))$updates[$f]=(string)($ticket[$f]??'');}
$updates['bill_amount']=(float)($updates['bill_amount']??$ticket['bill_amount']??0);
$updates['guarantee_amount']=(float)($updates['guarantee_amount']??$ticket['guarantee_amount']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 if($isManagerView){http_response_code(403);exit('LPGMA Manager access is read-only.');}
 verify_csrf();
 $action=$_POST['action']??'';

 if($isDeleteRequest && $action==='approve_delete'){
  $notes=fams_uppercase_text($_POST['resolution_notes']??'');
  try{
   fams_shared_transaction($pdo,function(PDO $db) use($ticketId,$ticket,$activeFacility,$notes){
   $ticketLock=$db->prepare("SELECT id,record_id,proposed_data FROM revision_tickets WHERE id=? AND status='OPEN' FOR UPDATE");$ticketLock->execute([$ticketId]);$lockedTicket=$ticketLock->fetch(PDO::FETCH_ASSOC);if(!$lockedTicket)throw new RuntimeException('The delete request is no longer open.');
   // Archive the record for recovery, detach the ticket, then remove the masterlist row.
   $recordQuery=$db->prepare('SELECT * FROM assistance_records WHERE id=? AND facility=? LIMIT 1 FOR UPDATE');
   $recordQuery->execute([(int)$lockedTicket['record_id'],$activeFacility]);$recordRow=$recordQuery->fetch(PDO::FETCH_ASSOC);
   if(!$recordRow) throw new RuntimeException('The FAMS record could not be found.');
   $deleteProposal=json_decode((string)$lockedTicket['proposed_data'],true);$baseVersion=(int)(is_array($deleteProposal)?($deleteProposal['_base_record_version']??0):0);
   if($baseVersion<1 || (int)($recordRow['record_version']??1)!==$baseVersion)throw new RuntimeException('The record has changed since this delete request was submitted. Ask the requester to review the latest record and submit a new request.');
   fams_archive_record_with_history($db,'maifip',$activeFacility,$recordRow,(int)current_user()['id']);
   $u=$db->prepare("UPDATE revision_tickets SET status='RESOLVED',record_control_number=?,record_facility=?,record_id=NULL,resolution_notes=?,resolved_by=?,resolved_at=NOW() WHERE id=? AND status='OPEN'");
   $u->execute([$ticket['control_number'],$activeFacility,$notes!==''?$notes:'Delete request approved; record archived and removed from the masterlist.',current_user()['id'],$ticketId]);
   if($u->rowCount()!==1) throw new RuntimeException('The delete request is no longer open.');
   $del=$db->prepare('DELETE FROM assistance_records WHERE id=? AND facility=?');$del->execute([(int)$lockedTicket['record_id'],$activeFacility]);
   if($del->rowCount()!==1) throw new RuntimeException('The FAMS record could not be deleted.');
   log_action($db,'APPROVE_DELETE_TICKET',"Approved delete request {$ticket['ticket_number']}; archived and removed {$ticket['control_number']} from the active masterlist.",null);
   });
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','The delete request could not be completed. No changes were made.');redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  flash('success',"Delete request {$ticket['ticket_number']} was approved; {$ticket['control_number']} was archived and removed from the active masterlist.");redirect('tickets.php');
 }

 if($action==='reject'){
  $notes=fams_uppercase_text($_POST['resolution_notes']??'');
  if($notes===''){flash('error','Please enter a reason when rejecting a ticket.');redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  try{fams_shared_transaction($pdo,function(PDO $db) use($notes,$ticketId,$ticket,$isDeleteRequest){
   $u=$db->prepare("UPDATE revision_tickets SET status='REJECTED',resolution_notes=?,resolved_by=?,resolved_at=NOW() WHERE id=? AND status='OPEN'");
   $u->execute([$notes,(int)current_user()['id'],$ticketId]);if($u->rowCount()!==1)throw new RuntimeException('This ticket has already been processed.');
   log_action($db,$isDeleteRequest?'REJECT_DELETE_TICKET':'REJECT_TICKET',"Rejected revision ticket {$ticket['ticket_number']}.",(int)($ticket['record_id']??0));
  });}catch(Throwable $e){flash('error',$e->getMessage());redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  flash('success',$isDeleteRequest?'Delete entry request rejected.':'Revision ticket rejected.');redirect('tickets.php');
 }

 if(!$isDeleteRequest && $action==='apply'){
  $status=strtoupper(trim((string)($_POST['requested_status']??'')));$rd=trim((string)($_POST['release_date']??''));
  $data=[];foreach(['date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'] as $f)$data[$f]=trim((string)($_POST[$f]??''));
  $data['bill_amount']=(float)($_POST['bill_amount']??0);$data['guarantee_amount']=(float)($_POST['guarantee_amount']??0);
  $data=fams_uppercase_fields($data,['patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks']);
  if(!in_array($status,['WAITING','RELEASED','CANCELLED'],true)){flash('error','Invalid status selected.');redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  if($data['date_submission']===''||$data['date_submission']>date('Y-m-d')||$data['patient_first_name']===''||$data['patient_last_name']===''||$data['municipality']===''){flash('error','Please complete the required record details.');redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  if($status==='RELEASED'){
   $dt=DateTime::createFromFormat('Y-m-d',$rd);
   if(!$dt||$dt->format('Y-m-d')!==$rd||$rd<$data['date_submission']||$rd>date('Y-m-d')){flash('error','Release Date is invalid.');redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  }else $rd=null;
  if($status==='CANCELLED' && trim($data['remarks'])===''){flash('error','Remarks are required for CANCELLED status.');redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  $notes=fams_uppercase_text($_POST['resolution_notes']??'');if($notes==='')$notes="REVISION APPLIED: DETAILS/STATUS UPDATED TO {$status}.";
  try{fams_shared_transaction($pdo,function(PDO $db) use($ticketId,$ticket,$activeFacility,$data,$status,$rd,$notes){
   $ticketLock=$db->prepare("SELECT id,record_id,proposed_data FROM revision_tickets WHERE id=? AND status='OPEN' FOR UPDATE");$ticketLock->execute([$ticketId]);$lockedTicket=$ticketLock->fetch(PDO::FETCH_ASSOC);if(!$lockedTicket)throw new RuntimeException('This ticket has already been processed.');
   $rs=$db->prepare('SELECT status,record_version FROM assistance_records WHERE id=? AND facility=? FOR UPDATE');$rs->execute([(int)$lockedTicket['record_id'],$activeFacility]);$current=$rs->fetch(PDO::FETCH_ASSOC);if(!$current)throw new RuntimeException('The linked record no longer exists in this facility.');
   $ticketProposal=json_decode((string)$lockedTicket['proposed_data'],true);$baseVersion=(int)(is_array($ticketProposal)?($ticketProposal['_base_record_version']??0):0);
   if($baseVersion<1 || (int)($current['record_version']??1)!==$baseVersion)throw new RuntimeException('The record has changed since this ticket was submitted. Ask the requester to review the latest record and update the ticket before approval.');
   $oldRecordStatus=strtoupper((string)$current['status']);$version=(int)($current['record_version']??1);
   $u=$db->prepare('UPDATE assistance_records SET date_submission=?,patient_first_name=?,patient_middle_name=?,patient_last_name=?,patient_name_extension=?,barangay=?,municipality=?,province=?,guardian=?,contact_number=?,bill_amount=?,guarantee_amount=?,remarks=?,status=?,release_date=?,updated_by=?,record_version=record_version+1 WHERE id=? AND facility=? AND record_version=?');
   $u->execute([$data['date_submission'],$data['patient_first_name'],$data['patient_middle_name'],$data['patient_last_name'],$data['patient_name_extension'],$data['barangay'],$data['municipality'],$data['province'],$data['guardian'],$data['contact_number'],$data['bill_amount'],$data['guarantee_amount'],$data['remarks'],$status,$rd,(int)current_user()['id'],(int)$lockedTicket['record_id'],$activeFacility,$version]);
   if($u->rowCount()!==1)throw new RuntimeException('The record changed while this ticket was being applied.');
   if($oldRecordStatus!==$status){fams_status_history_write($db,'maifip',(int)$ticket['record_id'],$oldRecordStatus,$status,(int)current_user()['id'],'Applied revision ticket '.$ticket['ticket_number']);}
   $u=$db->prepare("UPDATE revision_tickets SET status='RESOLVED',resolution_notes=?,resolved_by=?,resolved_at=NOW() WHERE id=? AND status='OPEN'");$u->execute([$notes,(int)current_user()['id'],$ticketId]);if($u->rowCount()!==1)throw new RuntimeException('This ticket has already been processed.');
   log_action($db,'APPLY_REVISION_TICKET',"Applied revision ticket {$ticket['ticket_number']}: details/status updated.",(int)$lockedTicket['record_id']);
  });}catch(Throwable $e){flash('error',$e->getMessage());redirect($isPopup ? "ticket_review.php?id=$ticketId&popup=1" : "ticket_review.php?id=$ticketId");}
  // Keep the resolved ticket for audit history; approval must not auto-delete tickets.
  flash('success',"Ticket {$ticket['ticket_number']} resolved and the requested record update was applied.");redirect('tickets.php');
 }
}

$pageTitle=$isManagerView ? ($isDeleteRequest?'View Delete Entry Request':'View Record Update Ticket') : ($isDeleteRequest?'Review Delete Entry Request':'Review Record Update Ticket'); if($isPopup){ echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($pageTitle).'</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="fams-popup-page">'; } else { require __DIR__.'/partials/header.php'; }
?>
<section class="panel form-panel"><div class="panel-heading"><div><span class="section-kicker"><?= $isManagerView ? 'READ-ONLY TICKET VIEW' : 'TICKET MANAGER' ?></span><h3><?=e($ticket['ticket_number'])?> — <?=e($ticket['control_number'])?></h3></div><a class="ghost-btn" href="tickets.php">← Back</a></div>
<div class="notice"><strong>Requested by:</strong> <?=e($ticket['requested_by'])?> &nbsp; <strong>Reason:</strong> <?=e($ticket['reason'])?><br><small><?= $isManagerView ? ($isDeleteRequest ? 'This is a read-only delete-entry request. Only the LPGMA Admin / Site Admin can approve or reject it.' : 'This ticket is read-only for LPGMA Managers. Only the LPGMA Admin / Site Admin can apply or reject the requested changes.') : ($isDeleteRequest ? 'This is a delete-entry request. The record remains until the LPGMA Admin / Site Admin approves it.' : 'Review and, if appropriate, apply the requested record-detail and status updates.') ?></small></div>
<form method="post" class="record-form modern-editor-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($isPopup): ?><input type="hidden" name="popup_mode" value="1"><?php endif; ?><input type="hidden" name="ticket_id" value="<?=$ticketId?>"><?php if($isManagerView): ?><fieldset disabled class="manager-readonly-fields"><?php endif; ?>
<?php if($isDeleteRequest): ?>
<div class="form-section-title">Delete Request</div><div class="form-grid"><div><label>Control No.</label><input value="<?=e($ticket['control_number'])?>" disabled></div><div><label>Patient</label><input value="<?=e(trim(($ticket['patient_first_name']??'').' '.($ticket['patient_last_name']??'')))?>" disabled></div></div>
<label>Requester Reason</label><textarea rows="5" disabled><?=e($ticket['reason'])?></textarea>
<label>Admin Resolution Notes</label><textarea name="resolution_notes" rows="3" placeholder="Optional note explaining the decision."></textarea>
<div class="form-actions"><?php if(!$isManagerView): ?><button class="secondary-btn" name="action" value="reject" type="submit" onclick="return confirm('Reject this delete entry request?');">Reject Delete Request</button><button class="primary-btn table-action-danger" name="action" value="approve_delete" type="submit" onclick="return confirm('Approve this request? The record will be archived and removed from the active masterlist; this ticket will remain in history.');">Approve &amp; Delete Entry</button><?php endif; ?></div>
<?php else: ?>
<div class="form-section-title">Record Details</div><div class="form-grid">
<div><label>Date of Submission</label><input type="date" name="date_submission" max="<?=e(date('Y-m-d'))?>" required value="<?=e($updates['date_submission'])?>"></div>
<div><label>First Name</label><input name="patient_first_name" required value="<?=e($updates['patient_first_name'])?>"></div><div><label>Middle Name</label><input name="patient_middle_name" value="<?=e($updates['patient_middle_name'])?>"></div><div><label>Last Name</label><input name="patient_last_name" required value="<?=e($updates['patient_last_name'])?>"></div><div><label>Name Extension</label><input name="patient_name_extension" value="<?=e($updates['patient_name_extension'])?>"></div>
<div><label for="province">Province</label><select id="province" name="province" required><option value="">Select province</option></select></div><div><label for="municipality">Municipality / City</label><select id="municipality" name="municipality" required disabled><option value="">Select province first</option></select></div><div><label for="barangay">Barangay</label><select id="barangay" name="barangay" disabled><option value="">Select municipality / city first</option></select></div><div><label>Client</label><input name="guardian" value="<?=e($updates['guardian'])?>"></div><div><label>Contact Number</label><input name="contact_number" value="<?=e($updates['contact_number'])?>"></div><div><label>Bill Amount</label><input type="number" step="0.01" min="0" name="bill_amount" value="<?=e((string)$updates['bill_amount'])?>"></div><div><label>Guarantee Amount</label><input type="number" step="0.01" min="0" name="guarantee_amount" value="<?=e((string)$updates['guarantee_amount'])?>"></div><div class="span-2"><label>Remarks</label><textarea name="remarks" rows="4"><?=e($updates['remarks'])?></textarea></div>
</div>
<div class="form-section-title">Status Update</div><div class="form-grid"><div><label>Requested Status</label><select name="requested_status" id="reviewStatus" required><option value="WAITING" <?=$requestedStatus==='WAITING'?'selected':''?>>WAITING</option><option value="RELEASED" <?=$requestedStatus==='RELEASED'?'selected':''?>>RELEASED</option><option value="CANCELLED" <?=$requestedStatus==='CANCELLED'?'selected':''?>>CANCELLED</option></select></div><div><label>Release Date</label><input type="date" name="release_date" id="reviewReleaseDate" value="<?=e($releaseDate)?>" min="<?=e($updates['date_submission'])?>" max="<?=e(date('Y-m-d'))?>"></div></div>
<label>Admin Resolution Notes</label><textarea name="resolution_notes" rows="3" placeholder="Optional note explaining the decision."></textarea>
<div class="form-actions"><?php if(!$isManagerView): ?><button class="secondary-btn" name="action" value="reject" type="submit" onclick="return confirm('Reject this record update ticket?');">Reject Ticket</button><button class="primary-btn" name="action" value="apply" type="submit" onclick="return confirm('Apply these record detail/status updates and resolve the ticket?');">Apply Updates &amp; Resolve</button><?php endif; ?></div>
<?php endif; ?><?php if($isManagerView): ?></fieldset><div class="notice manager-readonly-note"><strong>View only:</strong> No ticket changes can be applied from a Manager account.</div><?php endif; ?></form></section>
<?php if(!$isDeleteRequest && !$isManagerView): ?><script src="../core/shared-address-cascade.js"></script><script>(function(){FAMSInitAddressCascade({provinceId:'province',municipalityId:'municipality',barangayId:'barangay',province:<?=json_encode($updates['province']??'')?>,municipality:<?=json_encode($updates['municipality']??'')?>,barangay:<?=json_encode($updates['barangay']??'')?>});const s=document.getElementById('reviewStatus'),d=document.getElementById('reviewReleaseDate');function sync(){const v=s.value;d.required=v==='RELEASED';d.disabled=v!=='RELEASED';if(v!=='RELEASED')d.value='';}s.addEventListener('change',sync);sync();})();</script><?php endif; ?>
<?php if($isPopup): ?><script>document.querySelector('.modern-editor-form')?.addEventListener('submit',()=>parent.postMessage({type:'fams-popup-submitting'},'*'));</script></body></html><?php else: require __DIR__.'/partials/footer.php'; endif; ?>
