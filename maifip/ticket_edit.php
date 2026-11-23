<?php
require_once __DIR__.'/config.php'; require_login();if(!fams_current_type_allows('MAIFIP','submit_tickets')){http_response_code(403);exit('Your user type does not have permission to edit submitted tickets. Contact the Site Administrator if this access is required.');}
$activeFacility=active_data_facility($pdo);
require_role(['admin','manager','encoder','social_worker','staff']);
if (!can_raise_ticket() && (current_user()['role'] ?? '') !== 'admin') { http_response_code(403); exit('Your account cannot manage ticket submissions.'); }

// Detect popup mode before any validation redirect or form rendering.
$isPopup = (($_GET['popup'] ?? '') === '1') || (($_POST['popup_mode'] ?? '') === '1') || (($_POST['popup'] ?? '') === '1');
$me=(int)current_user()['id'];
$ticketId=(int)($_GET['id']??$_POST['id']??0);
$q=$pdo->prepare('SELECT t.*, COALESCE(a.control_number,t.record_control_number) AS control_number, a.date_submission, a.patient_first_name, a.patient_middle_name, a.patient_last_name, a.patient_name_extension, a.barangay AS record_barangay, a.municipality AS record_municipality, a.province AS record_province, a.guardian AS record_guardian, a.contact_number AS record_contact_number, a.bill_amount AS record_bill_amount, a.guarantee_amount AS record_guarantee_amount, a.remarks AS record_remarks, a.status AS record_status, a.release_date AS record_release_date, a.record_version AS record_version FROM revision_tickets t LEFT JOIN assistance_records a ON a.id=t.record_id WHERE t.id=? AND t.requested_by=? AND COALESCE(a.facility,t.record_facility)=? LIMIT 1');
$q->execute([$ticketId,$me,$activeFacility]);
$ticket=$q->fetch();
if(!$ticket){http_response_code(403);exit('Ticket not found in your facility, or you are not the ticket requester. You may edit only your own tickets.');}
if($ticket['status']!=='OPEN'){
    flash('error','Only OPEN tickets can be edited.');
    redirect('tickets.php');
}
$proposal=json_decode((string)($ticket['proposed_data']??''),true);
if(!is_array($proposal))$proposal=[];
$isDelete=strtoupper((string)($proposal['request_type']??''))==='DELETE' || strtolower((string)($ticket['requested_fields']??''))==='delete';
$ticketBaseVersion=(int)($proposal['_base_record_version']??0);
if($ticketBaseVersion<1 || (int)($ticket['record_version']??0)!==$ticketBaseVersion){http_response_code(409);exit('The linked record has changed since this ticket was submitted. This ticket can no longer be edited safely; review the latest record and submit a new ticket.');}
$updates=is_array($proposal['record_updates']??null)?$proposal['record_updates']:[];
$fields=['date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'];
foreach($fields as $f){
    $fallbackMap=['barangay'=>'record_barangay','municipality'=>'record_municipality','province'=>'record_province','guardian'=>'record_guardian','contact_number'=>'record_contact_number','remarks'=>'record_remarks'];
    $key=$f;
    if(!array_key_exists($key,$updates))$updates[$key]=(string)($ticket[$fallbackMap[$f]??$f]??'');
}
$updates['bill_amount']=(float)($updates['bill_amount']??$ticket['record_bill_amount']??0);
$updates['guarantee_amount']=(float)($updates['guarantee_amount']??$ticket['record_guarantee_amount']??0);
$requestedStatus=strtoupper((string)($proposal['requested_status']??$ticket['record_status']??'WAITING'));
if(!in_array($requestedStatus,['WAITING','RELEASED','CANCELLED'],true))$requestedStatus='WAITING';
$releaseDate=(string)($proposal['release_date']??$ticket['record_release_date']??'');
$reason=(string)$ticket['reason'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $reason=fams_uppercase_text($_POST['reason']??'');
    if($reason===''){flash('error','Please provide a reason for the requested update.');redirect($isPopup ? "ticket_edit.php?id=$ticketId&popup=1" : "ticket_edit.php?id=$ticketId");}

    if($isDelete){
        $proposal=['request_type'=>'DELETE','current_status'=>strtoupper((string)$ticket['record_status']),'_base_record_version'=>(int)($ticket['record_version']??1),'control_number'=>$ticket['control_number'],'patient_name'=>trim(($ticket['patient_first_name']??'').' '.($ticket['patient_last_name']??''))];
        fams_shared_transaction($pdo,function(PDO $db) use($reason,$proposal,$ticketId,$me,$ticket,$activeFacility,$ticketBaseVersion){
            $lock=$db->prepare("SELECT id,record_id FROM revision_tickets WHERE id=? AND requested_by=? AND status='OPEN' FOR UPDATE");$lock->execute([$ticketId,$me]);$locked=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$locked)throw new RuntimeException('This ticket is no longer open or is not yours to edit.');
            $record=$db->prepare('SELECT record_version FROM assistance_records WHERE id=? AND facility=? FOR UPDATE');$record->execute([(int)$locked['record_id'],$activeFacility]);$version=$record->fetchColumn();
            if($version===false || (int)$version!==$ticketBaseVersion)throw new RuntimeException('The linked record has changed since this ticket was submitted. Create a new ticket using the latest record values.');
            $proposal['_base_record_version']=$ticketBaseVersion;
            $u=$db->prepare("UPDATE revision_tickets SET reason=?,requested_fields='delete',proposed_data=? WHERE id=? AND requested_by=? AND status='OPEN'");
            $u->execute([$reason,json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$ticketId,$me]);
            if($u->rowCount()!==1)throw new RuntimeException('The ticket could not be updated.');
            log_action($db,'EDIT_DELETE_TICKET',"Edited delete-entry request {$ticket['ticket_number']}.",(int)$ticket['record_id']);
        });
        flash('success',"Ticket {$ticket['ticket_number']} was updated.");
        if($isPopup){echo '<script>parent.postMessage({recordSaved:true,type:"fams-ticket-updated"},"*");</script>';exit;}
        redirect('tickets.php');
    }

    $data=[];
    foreach($fields as $f)$data[$f]=trim((string)($_POST[$f]??''));
    $data['bill_amount']=(float)($_POST['bill_amount']??0);
    $data['guarantee_amount']=(float)($_POST['guarantee_amount']??0);
    $data=fams_uppercase_fields($data,['patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks']);
    $requestedStatus=strtoupper(trim((string)($_POST['requested_status']??$requestedStatus)));
    $releaseDate=trim((string)($_POST['release_date']??''));
    if($data['date_submission']==='' || $data['date_submission']>date('Y-m-d')){flash('error','Date of submission cannot be later than today.');redirect($isPopup ? "ticket_edit.php?id=$ticketId&popup=1" : "ticket_edit.php?id=$ticketId");}
    if($data['patient_first_name']===''||$data['patient_last_name']===''||$data['municipality']===''){flash('error','First name, last name, and municipality are required.');redirect($isPopup ? "ticket_edit.php?id=$ticketId&popup=1" : "ticket_edit.php?id=$ticketId");}
    if(!in_array($requestedStatus,['WAITING','RELEASED','CANCELLED'],true)){flash('error','Please select a valid requested status.');redirect($isPopup ? "ticket_edit.php?id=$ticketId&popup=1" : "ticket_edit.php?id=$ticketId");}
    if($requestedStatus==='RELEASED'){
        $dt=DateTime::createFromFormat('Y-m-d',$releaseDate);
        if(!$dt||$dt->format('Y-m-d')!==$releaseDate||$releaseDate<$data['date_submission']||$releaseDate>date('Y-m-d')){flash('error','Release Date must be on or after the submission date and not later than today.');redirect($isPopup ? "ticket_edit.php?id=$ticketId&popup=1" : "ticket_edit.php?id=$ticketId");}
    }else{$releaseDate='';}
    if($requestedStatus==='CANCELLED' && trim($data['remarks'])===''){flash('error','Remarks are required when requesting CANCELLED status.');redirect($isPopup ? "ticket_edit.php?id=$ticketId&popup=1" : "ticket_edit.php?id=$ticketId");}
    $proposal=['requested_status'=>$requestedStatus,'release_date'=>$releaseDate?:null,'remarks'=>$data['remarks'],'record_updates'=>$data,'current_status'=>strtoupper((string)$ticket['record_status']),'_base_record_version'=>(int)($ticket['record_version']??1)];
    fams_shared_transaction($pdo,function(PDO $db) use($reason,$proposal,$ticketId,$me,$ticket,$activeFacility,$ticketBaseVersion){
        $lock=$db->prepare("SELECT id,record_id FROM revision_tickets WHERE id=? AND requested_by=? AND status='OPEN' FOR UPDATE");$lock->execute([$ticketId,$me]);$locked=$lock->fetch(PDO::FETCH_ASSOC);
        if(!$locked)throw new RuntimeException('This ticket is no longer open or is not yours to edit.');
        $record=$db->prepare('SELECT record_version FROM assistance_records WHERE id=? AND facility=? FOR UPDATE');$record->execute([(int)$locked['record_id'],$activeFacility]);
        $version=$record->fetchColumn();
        if($version===false || (int)$version!==$ticketBaseVersion)throw new RuntimeException('The linked record has changed since this ticket was submitted. Create a new ticket using the latest record values.');
        $proposal['_base_record_version']=$ticketBaseVersion;
        $u=$db->prepare("UPDATE revision_tickets SET reason=?,requested_fields='details,status',proposed_data=? WHERE id=? AND requested_by=? AND status='OPEN'");
        $u->execute([$reason,json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$ticketId,$me]);
        if($u->rowCount()!==1)throw new RuntimeException('The ticket could not be updated.');
        log_action($db,'EDIT_TICKET',"Edited revision ticket {$ticket['ticket_number']}.",(int)$ticket['record_id']);
    });
    flash('success',"Ticket {$ticket['ticket_number']} was updated.");
    if($isPopup){echo '<script>parent.postMessage({recordSaved:true,type:"fams-ticket-updated"},"*");</script>';exit;}
    redirect('tickets.php');
}

$pageTitle='Edit Ticket'; if($isPopup){ echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Edit Ticket</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="fams-popup-page">'; } else { require __DIR__.'/partials/header.php'; }
?>
<section class="panel form-panel">
<div class="panel-heading"><div><span class="section-kicker">EDIT TICKET</span><h3><?=e($ticket['ticket_number'])?> — <?=e($ticket['control_number'])?></h3></div><a class="ghost-btn" href="<?= $isPopup ? 'tickets.php?popup=1' : 'tickets.php' ?>">← Back</a></div>
<div class="notice"><strong>Ticket status:</strong> <span class="status-badge status-waiting">OPEN</span><br><small>You may edit only your own ticket while it is OPEN. Editing changes the request submitted for Admin review; it does not directly change the FAMS record.</small></div>
<form method="post" class="record-form modern-editor-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($isPopup): ?><input type="hidden" name="popup_mode" value="1"><?php endif; ?><input type="hidden" name="id" value="<?=$ticketId?>">
<?php if($isDelete): ?>
<div class="form-section-title">Delete Entry Request</div>
<div class="form-grid"><div><label>Control No.</label><input value="<?=e($ticket['control_number'])?>" disabled></div><div><label>Patient</label><input value="<?=e(trim(($ticket['patient_first_name']??'').' '.($ticket['patient_last_name']??'')))?>" disabled></div></div>
<label>Reason for Delete Entry Request</label><textarea name="reason" rows="6" required><?=e($reason)?></textarea>
<div class="form-actions"><a class="ghost-btn" href="<?= $isPopup ? 'tickets.php?popup=1' : 'tickets.php' ?>">Cancel</a><button class="primary-btn" type="submit">Save Ticket Changes</button></div>
<?php else: ?>
<div class="form-section-title">Requested Record Details</div><div class="form-grid">
<div><label>Date of Submission</label><input type="date" name="date_submission" max="<?=e(date('Y-m-d'))?>" required value="<?=e($updates['date_submission'])?>"></div>
<div><label>First Name</label><input name="patient_first_name" required value="<?=e($updates['patient_first_name'])?>"></div><div><label>Middle Name</label><input name="patient_middle_name" value="<?=e($updates['patient_middle_name'])?>"></div><div><label>Last Name</label><input name="patient_last_name" required value="<?=e($updates['patient_last_name'])?>"></div><div><label>Name Extension</label><input name="patient_name_extension" value="<?=e($updates['patient_name_extension'])?>"></div>
<div><label for="province">Province</label><select id="province" name="province" required><option value="">Select province</option></select></div><div><label for="municipality">Municipality / City</label><select id="municipality" name="municipality" required disabled><option value="">Select province first</option></select></div><div><label for="barangay">Barangay</label><select id="barangay" name="barangay" disabled><option value="">Select municipality / city first</option></select></div>
<div><label>Client</label><input name="guardian" value="<?=e($updates['guardian'])?>"></div><div><label>Contact Number</label><input name="contact_number" value="<?=e($updates['contact_number'])?>"></div><div><label>Bill Amount</label><input type="number" step="0.01" min="0" name="bill_amount" value="<?=e((string)$updates['bill_amount'])?>"></div><div><label>Guarantee Amount</label><input type="number" step="0.01" min="0" name="guarantee_amount" value="<?=e((string)$updates['guarantee_amount'])?>"></div><div class="span-2"><label>Remarks</label><textarea name="remarks" rows="4"><?=e($updates['remarks'])?></textarea></div>
</div>
<div class="form-section-title">Requested Status</div><div class="form-grid"><div><label>Requested Status</label><select name="requested_status" id="requestedStatus" required><option value="WAITING" <?=$requestedStatus==='WAITING'?'selected':''?>>WAITING</option><option value="RELEASED" <?=$requestedStatus==='RELEASED'?'selected':''?>>RELEASED</option><option value="CANCELLED" <?=$requestedStatus==='CANCELLED'?'selected':''?>>CANCELLED</option></select></div><div><label>Release Date</label><input type="date" name="release_date" id="ticketReleaseDate" value="<?=e($releaseDate)?>" min="<?=e($updates['date_submission'])?>" max="<?=e(date('Y-m-d'))?>"></div></div>
<div class="form-section-title">Reason for Update</div><textarea name="reason" rows="5" required><?=e($reason)?></textarea>
<div class="form-actions"><a class="ghost-btn" href="<?= $isPopup ? 'tickets.php?popup=1' : 'tickets.php' ?>">Cancel</a><button class="primary-btn" type="submit">Save Ticket Changes</button></div>
<?php endif; ?></form></section>
<?php if(!$isDelete): ?><script src="../core/shared-address-cascade.js"></script><script>(function(){FAMSInitAddressCascade({provinceId:'province',municipalityId:'municipality',barangayId:'barangay',province:<?=json_encode($updates['province']??'')?>,municipality:<?=json_encode($updates['municipality']??'')?>,barangay:<?=json_encode($updates['barangay']??'')?>});const s=document.getElementById('requestedStatus'),d=document.getElementById('ticketReleaseDate');function sync(){const v=s.value;d.required=v==='RELEASED';d.disabled=v!=='RELEASED';if(v!=='RELEASED')d.value='';}s.addEventListener('change',sync);sync();})();</script><?php endif; ?>
<?php if($isPopup): ?><script>document.querySelector('.modern-editor-form')?.addEventListener('submit',()=>parent.postMessage({type:'fams-popup-submitting'},'*'));</script></body></html><?php else: require __DIR__.'/partials/footer.php'; endif; ?>
