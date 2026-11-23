<?php
require_once __DIR__.'/config.php'; require_login();if(!fams_current_type_allows('MAIFIP','submit_tickets')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
require_role(['encoder','social_worker','manager','staff']);
$formIdempotencyKey = fams_shared_idempotency_key();

// Popup mode must be initialized before any POST validation/redirect path.
$isPopup = (($_GET['popup'] ?? '') === '1') || (($_POST['popup_mode'] ?? '') === '1');
$activeFacility=active_data_facility($pdo);
$id=(int)($_GET['id']??$_POST['id']??0);
$s=$pdo->prepare('SELECT * FROM assistance_records WHERE id=? AND facility=?'); $s->execute([$id,$activeFacility]); $record=$s->fetch();
if(!$record){http_response_code(404);exit('Record not found in your assigned facility. Facility-level access protects beneficiary privacy and accountability. If the assignment appears incorrect, contact the LPGMA Site Administrator for review.');}

$currentStatus=strtoupper((string)$record['status']);
$recordBaseVersion=(int)($record['record_version']??1);
if($recordBaseVersion<1){http_response_code(409);exit('The record version is unavailable. Reload the record before submitting a ticket.');}
$requestedStatusInitial=strtoupper(trim((string)($_GET['requested_status']??$currentStatus))); if(!in_array($requestedStatusInitial,['WAITING','RELEASED','CANCELLED'],true)) $requestedStatusInitial=$currentStatus;


if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $ticketAction=trim((string)($_POST['ticket_action']??'update'));
    $reason=fams_uppercase_text($_POST['reason']??'');
    if($ticketAction==='delete_request'){
        if($reason===''){flash('error','Please provide a reason for the delete entry request.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
        $ticket='REV-'.date('Y').'-'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT);
        $proposal=['request_type'=>'DELETE','current_status'=>$currentStatus,'_base_record_version'=>$recordBaseVersion,'control_number'=>$record['control_number'],'patient_name'=>trim(($record['patient_first_name']??'').' '.($record['patient_last_name']??''))];
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,link VARCHAR(255) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_user_read(user_id,is_read),INDEX idx_notif_created(created_at)) ENGINE=InnoDB");
        try{$submission=fams_shared_idempotent_transaction($pdo,'maifip.ticket.delete',(string)($_POST['idempotency_key']??''),(int)current_user()['id'],function(PDO $db) use ($ticket,$id,$reason,$proposal,$record,$recordBaseVersion,$activeFacility): void {
        $lock=$db->prepare('SELECT record_version FROM assistance_records WHERE id=? AND facility=? LIMIT 1 FOR UPDATE');$lock->execute([$id,$activeFacility]);$lockedVersion=$lock->fetchColumn();
        if($lockedVersion===false || (int)$lockedVersion!==$recordBaseVersion)throw new RuntimeException('The record changed while this ticket was being prepared. Reload it and submit a new ticket.');
        $q=$db->prepare('INSERT INTO revision_tickets(ticket_number,record_id,requested_by,reason,requested_fields,proposed_data) VALUES(?,?,?,?,?,?)');
        $q->execute([$ticket,$id,current_user()['id'],$reason,'delete',json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $admins=$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        $nn=$db->prepare('INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)');
        foreach($admins as $aid)$nn->execute([(int)$aid,'New delete entry request',current_user()['full_name'].' submitted '.$ticket.' to delete '.$record['control_number'].'.','tickets.php']);
        log_action($db,'CREATE_DELETE_TICKET',"Submitted delete entry request {$ticket} for {$record['control_number']}.",$id);
        });}catch(Throwable $e){flash('error',$e->getMessage());redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
        flash('success',$submission['duplicate']?'This delete request was already submitted. No duplicate ticket was created.':"Delete request {$ticket} submitted to the LPGMA Admin / Site Admin for review.");
        if($isPopup){echo '<script>parent.postMessage({recordSaved:true,type:"fams-ticket-submitted"},"*");</script>';exit;}
        redirect('tickets.php');
    }
    $data=[];
    foreach(['date_submission','patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks'] as $f){$data[$f]=trim((string)($_POST[$f]??''));}
    $data['bill_amount']=(float)($_POST['bill_amount']??0);
    $data['guarantee_amount']=(float)($_POST['guarantee_amount']??0);
    $data=fams_uppercase_fields($data,['patient_first_name','patient_middle_name','patient_last_name','patient_name_extension','barangay','municipality','province','guardian','contact_number','remarks']);
    $requestedStatus=strtoupper(trim((string)($_POST['requested_status']??$currentStatus)));
    $releaseDate=trim((string)($_POST['release_date']??($record['release_date']??'')));
    if($data['date_submission']==='' || $data['date_submission']>date('Y-m-d')){flash('error','Date of submission cannot be later than today.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
    if($data['patient_first_name']===''||$data['patient_last_name']===''||$data['municipality']===''){flash('error','First name, last name, and municipality are required.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
    if(!in_array($requestedStatus,['WAITING','RELEASED','CANCELLED'],true)){flash('error','Please select a valid requested status.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
    if($requestedStatus==='RELEASED'){
        if($releaseDate===''){flash('error','Release Date is required when requesting RELEASED status.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
        $dt=DateTime::createFromFormat('Y-m-d',$releaseDate);
        if(!$dt||$dt->format('Y-m-d')!==$releaseDate||$releaseDate<$data['date_submission']||$releaseDate>date('Y-m-d')){flash('error','Release Date must be on or after the submission date and not later than today.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
    }else{$releaseDate='';}
    if($requestedStatus==='CANCELLED'&&$reason===''){flash('error','Please provide a reason for the requested update.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
    if($reason===''){flash('error','Please provide a reason for the requested update.');redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
    $ticket='REV-'.date('Y').'-'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT);
    $proposal=['requested_status'=>$requestedStatus,'release_date'=>$releaseDate?:null,'remarks'=>$data['remarks'],'record_updates'=>$data,'current_status'=>$currentStatus,'_base_record_version'=>$recordBaseVersion];
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,link VARCHAR(255) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_user_read(user_id,is_read),INDEX idx_notif_created(created_at)) ENGINE=InnoDB");
    try{$submission=fams_shared_idempotent_transaction($pdo,'maifip.ticket.revision',(string)($_POST['idempotency_key']??''),(int)current_user()['id'],function(PDO $db) use ($ticket,$id,$reason,$proposal,$recordBaseVersion,$activeFacility): void {
    $lock=$db->prepare('SELECT record_version FROM assistance_records WHERE id=? AND facility=? LIMIT 1 FOR UPDATE');$lock->execute([$id,$activeFacility]);$lockedVersion=$lock->fetchColumn();
    if($lockedVersion===false || (int)$lockedVersion!==$recordBaseVersion)throw new RuntimeException('The record changed while this ticket was being prepared. Reload it and submit a new ticket.');
    $q=$db->prepare('INSERT INTO revision_tickets(ticket_number,record_id,requested_by,reason,requested_fields,proposed_data) VALUES(?,?,?,?,?,?)');
    $q->execute([$ticket,$id,current_user()['id'],$reason,'details,status',json_encode($proposal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $admins=$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
    $nn=$db->prepare('INSERT INTO notifications(user_id,title,message,link) VALUES(?,?,?,?)');
    foreach($admins as $aid)$nn->execute([(int)$aid,'New record revision ticket',current_user()['full_name'].' submitted '.$ticket.'.','tickets.php']);
    log_action($db,'CREATE_TICKET',"Submitted revision ticket {$ticket} for details/status update.",$id);
    });}catch(Throwable $e){flash('error',$e->getMessage());redirect($isPopup ? "ticket_create.php?id=$id&popup=1" : "ticket_create.php?id=$id");}
    flash('success',$submission['duplicate']?'This revision request was already submitted. No duplicate ticket was created.':"Ticket {$ticket} submitted to the LPGMA Admin / Site Admin for review.");
    if($isPopup){echo '<script>parent.postMessage({recordSaved:true,type:"fams-ticket-submitted"},"*");</script>';exit;}
    redirect('tickets.php');
}

$pageTitle='Submit Record Update Ticket'; if($isPopup){ echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Submit Record Update Ticket</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="fams-popup-page">'; } else { require __DIR__.'/partials/header.php'; }
?>
<section class="panel form-panel">
<div class="panel-heading"><div><span class="section-kicker">SUBMIT TICKET</span><h3>Record Update — <?=e($record['control_number'])?></h3></div><a class="ghost-btn" href="records.php">← Back</a></div>
<div class="notice"><strong>Current status:</strong> <span class="status-badge <?=e(status_class($currentStatus))?>"><?=e($currentStatus)?></span><br><small>Record revisions are submitted as tickets for LPGMA Admin / Site Admin review. Your changes will not affect the masterlist until approved.</small></div>
<form method="post" class="record-form modern-editor-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="idempotency_key" value="<?=e($formIdempotencyKey)?>"><?php if($isPopup): ?><input type="hidden" name="popup_mode" value="1"><?php endif; ?><input type="hidden" name="id" value="<?=$id?>">
<div class="form-section-title">Requested Record Details</div><div class="form-grid">
<div><label>Date of Submission</label><input type="date" name="date_submission" max="<?=e(date('Y-m-d'))?>" required value="<?=e($record['date_submission'])?>"></div>
<div><label>First Name</label><input name="patient_first_name" required value="<?=e($record['patient_first_name'])?>"></div>
<div><label>Middle Name</label><input name="patient_middle_name" value="<?=e($record['patient_middle_name'])?>"></div>
<div><label>Last Name</label><input name="patient_last_name" required value="<?=e($record['patient_last_name'])?>"></div>
<div><label>Name Extension</label><input name="patient_name_extension" value="<?=e($record['patient_name_extension']??'')?>"></div>
<div><label for="province">Province</label><select id="province" name="province" required><option value="">Select province</option></select></div>
<div><label for="municipality">Municipality / City</label><select id="municipality" name="municipality" required disabled><option value="">Select province first</option></select></div>
<div><label for="barangay">Barangay</label><select id="barangay" name="barangay" disabled><option value="">Select municipality / city first</option></select></div>
<div><label>Client</label><input name="guardian" value="<?=e($record['guardian'])?>"></div>
<div><label>Contact Number</label><input name="contact_number" value="<?=e($record['contact_number'])?>"></div>
<div><label>Bill Amount</label><input type="number" step="0.01" min="0" name="bill_amount" value="<?=e((string)$record['bill_amount'])?>"></div>
<div><label>Guarantee Amount</label><input type="number" step="0.01" min="0" name="guarantee_amount" value="<?=e((string)$record['guarantee_amount'])?>"></div>
<div class="span-2"><label>Remarks</label><textarea name="remarks" rows="4"><?=e($record['remarks'])?></textarea></div>
</div>
<div class="form-section-title">Requested Status</div><div class="form-grid">
<div><label>Requested Status</label><select name="requested_status" id="requestedStatus" required><option value="WAITING" <?=$requestedStatusInitial==='WAITING'?'selected':''?>>WAITING</option><option value="RELEASED" <?=$requestedStatusInitial==='RELEASED'?'selected':''?>>RELEASED</option><option value="CANCELLED" <?=$requestedStatusInitial==='CANCELLED'?'selected':''?>>CANCELLED</option></select></div>
<div><label>Release Date</label><input type="date" name="release_date" id="ticketReleaseDate" value="<?=e((string)$record['release_date'])?>" min="<?=e($record['date_submission'])?>" max="<?=e(date('Y-m-d'))?>"></div>
</div>
<div class="form-section-title">Reason for Update</div><textarea name="reason" rows="5" required placeholder="Explain the detail and/or status changes being requested."></textarea>
<div class="form-actions"><a class="ghost-btn" href="records.php">Cancel</a><button class="secondary-btn table-action-danger" type="submit" name="ticket_action" value="delete_request" onclick="return confirm('Submit a delete entry request for <?=e($record['control_number'])?>? The record will remain until the LPGMA Admin / Site Admin approves the request.');">Submit Delete Entry Request</button><button class="primary-btn" type="submit" name="ticket_action" value="update">Submit Record Update Ticket</button></div></form></section>
<script src="../core/shared-address-cascade.js"></script><script>(function(){FAMSInitAddressCascade({provinceId:'province',municipalityId:'municipality',barangayId:'barangay',province:<?=json_encode($record['province']??'')?>,municipality:<?=json_encode($record['municipality']??'')?>,barangay:<?=json_encode($record['barangay']??'')?>});const s=document.getElementById('requestedStatus'),d=document.getElementById('ticketReleaseDate');function sync(){const v=s.value;d.required=v==='RELEASED';d.disabled=v!=='RELEASED';if(v!=='RELEASED')d.value='';}s.addEventListener('change',sync);sync();})();</script>
<?php if($isPopup): ?><script>document.querySelector('.modern-editor-form')?.addEventListener('submit',()=>parent.postMessage({type:'fams-popup-submitting'},'*'));</script></body></html><?php else: require __DIR__.'/partials/footer.php'; endif; ?>