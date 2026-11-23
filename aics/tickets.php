<?php
require_once __DIR__.'/config.php';
require_login();if(!fams_current_type_allows('AICS','submit_tickets')){http_response_code(403);exit('Your user type does not have permission for this action. Contact the Site Administrator if this access is required.');}
$pageTitle='Ticket Management';
$user=current_user();
$me=(int)($user['id']??0);
$isAdmin=user_is_admin();
$popup=isset($_GET['popup'])||isset($_POST['popup']);
$editId=(int)($_GET['edit']??0);
$formIdempotencyKey=fams_shared_idempotency_key();
// Keep existing installations compatible while adding explicit approval decisions.
// Apply compatibility DDL before any ticket workflow transaction. Fail visibly if a required
// migration cannot be completed; silently continuing can detach ticket decisions from records.
$ticketColumns=[];
foreach($pdo->query("SHOW COLUMNS FROM aics_tickets")->fetchAll(PDO::FETCH_ASSOC) as $columnRow) $ticketColumns[(string)$columnRow['Field']]=true;
$ticketStatus=$pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_tickets' AND COLUMN_NAME='status'");
$ticketStatus->execute(); $statusType=(string)$ticketStatus->fetchColumn();
if(!str_contains($statusType,"'APPROVED'") || !str_contains($statusType,"'REJECTED'")) $pdo->exec("ALTER TABLE aics_tickets MODIFY status ENUM('OPEN','IN PROGRESS','RESOLVED','CLOSED','APPROVED','REJECTED') NOT NULL DEFAULT 'OPEN'");
foreach(['resolution_notes'=>'TEXT NULL AFTER assigned_to','record_id'=>'INT UNSIGNED NULL AFTER id','record_control_number'=>'VARCHAR(40) NULL AFTER record_id','proposed_data'=>'LONGTEXT NULL AFTER record_control_number'] as $column=>$definition){
 if(!isset($ticketColumns[$column])) $pdo->exec("ALTER TABLE aics_tickets ADD COLUMN `{$column}` {$definition}");
}


// Preserve the human-readable record reference before approved deletion detaches the live record ID.
$pdo->exec("UPDATE aics_tickets t JOIN aics_assistance_records r ON r.id=t.record_id SET t.record_control_number=COALESCE(t.record_control_number,r.control_number) WHERE t.record_id IS NOT NULL AND t.record_control_number IS NULL");
$pdo->exec("UPDATE aics_tickets t JOIN fams_deleted_records d ON d.system_key='aics' AND d.original_id=t.record_id SET t.record_control_number=COALESCE(t.record_control_number,d.control_number) WHERE t.record_id IS NOT NULL AND t.record_control_number IS NULL");
$pdo->exec("UPDATE aics_tickets t JOIN fams_deleted_records d ON d.system_key='aics' AND d.original_id=t.record_id SET t.record_id=NULL WHERE t.category='DELETE REQUEST' AND t.status='APPROVED' AND t.record_id IS NOT NULL AND t.record_control_number IS NOT NULL");

function aics_ticket_finish(bool $popup): never { if($popup){echo '<script>window.parent.postMessage({recordSaved:true,type:"fams-ticket-saved"},"*");</script>';exit;} redirect('tickets.php'); }
function aics_ticket_fail(string $message,bool $popup): never { if($popup){echo '<!doctype html><meta charset="utf-8"><div style="font:14px Arial;padding:18px;color:#9f1239">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</div><script>window.parent.postMessage({type:"fams-popup-error",message:'.json_encode($message).'},"*");</script>';exit;} flash('error',$message);redirect('tickets.php'); }
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf(); $action=(string)($_POST['action']??'create'); $id=(int)($_POST['id']??0);
    try{
        if($action==='create'||$action==='edit'){
            $subject=trim((string)($_POST['subject']??''));$category=trim((string)($_POST['category']??'GENERAL'));$description=trim((string)($_POST['description']??''));
            if($subject===''||mb_strlen($subject)>180||$description==='')throw new RuntimeException('Subject (up to 180 characters) and details are required.');
            if($action==='create'){
                $ticketSequenceLock='fams:aics:ticket-sequence';$ticketLock=$pdo->prepare('SELECT GET_LOCK(?,8)');$ticketLock->execute([$ticketSequenceLock]);
                if((int)$ticketLock->fetchColumn()!==1)throw new RuntimeException('The AICS ticket-number sequence is busy. Please retry.');
                try{$created=fams_shared_idempotent_transaction($pdo,'aics.ticket.create',(string)($_POST['idempotency_key']??''),$me,function(PDO $db) use ($subject,$category,$description,$me): string {
                    $no=aics_next_ticket_number($db);
                    $db->prepare("INSERT INTO aics_tickets(ticket_no,subject,category,description,status,created_by) VALUES(?,?,?,?,'OPEN',?)")->execute([$no,$subject,$category?:'GENERAL',$description,$me]);
                    log_action($db,'CREATE_TICKET','Created ticket '.$no,null);
                    return $no;
                });}finally{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$ticketSequenceLock]);}
                if($created['duplicate']){aics_ticket_finish($popup);}
            }else{
                fams_shared_transaction($pdo,function(PDO $db) use($id,$me,$subject,$category,$description){
                    $q=$db->prepare('SELECT id,ticket_no,created_by,status FROM aics_tickets WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$id]);$t=$q->fetch(PDO::FETCH_ASSOC);
                    if(!$t||(int)$t['created_by']!==$me)throw new RuntimeException('You may edit only your own tickets.');
                    if(!in_array(strtoupper((string)$t['status']),['OPEN','IN PROGRESS'],true))throw new RuntimeException('Reviewed tickets can no longer be edited.');
                    $u=$db->prepare("UPDATE aics_tickets SET subject=?,category=?,description=? WHERE id=? AND created_by=? AND status IN ('OPEN','IN PROGRESS')");$u->execute([$subject,$category?:'GENERAL',$description,$id,$me]);
                    log_action($db,'EDIT_TICKET','Edited own ticket '.$t['ticket_no'],null);
                });
            }
            aics_ticket_finish($popup);
        }elseif($action==='decision'){
            if(!fams_current_type_allows('AICS','manage_tickets') || !$isAdmin)throw new RuntimeException('Only an administrator can approve or reject tickets.');
            $decision=strtoupper((string)($_POST['decision']??''));$note=trim((string)($_POST['resolution_notes']??''));
            if(!in_array($decision,['APPROVED','REJECTED'],true))throw new RuntimeException('Choose Approve or Reject.');
            if($decision==='REJECTED'&&$note==='')throw new RuntimeException('A rejection reason is required.');
            fams_shared_transaction($pdo,function(PDO $db) use($id,$decision,$note,$me){
                $check=$db->prepare("SELECT id,record_id,proposed_data,category,created_by,ticket_no,subject,description,status FROM aics_tickets WHERE id=? AND status IN ('OPEN','IN PROGRESS') LIMIT 1 FOR UPDATE");
                $check->execute([$id]);$ticketData=$check->fetch(PDO::FETCH_ASSOC);
                if(!$ticketData)throw new RuntimeException('Ticket was not found or has already been reviewed.');
                $category=strtoupper((string)$ticketData['category']);
                if($decision==='APPROVED' && $category==='DELETE REQUEST'){
                    $recordId=(int)($ticketData['record_id']??0);
                    if($recordId<1){
                        $candidate='';
                        if(preg_match('/Deletion request for\s+((?:19|20)\d{2}-\d{6})/i',(string)$ticketData['subject'],$m))$candidate=$m[1];
                        if($candidate==='' && preg_match('/(?:assistance )?record\s+((?:19|20)\d{2}-\d{6})/i',(string)$ticketData['description'],$m))$candidate=$m[1];
                        if($candidate!==''){$find=$db->prepare('SELECT id FROM aics_assistance_records WHERE control_number=? LIMIT 1');$find->execute([$candidate]);$recordId=(int)$find->fetchColumn();}
                    }
                    if($recordId<1)throw new RuntimeException('This deletion ticket is not linked to a masterlist entry. No record was deleted.');
                    $find=$db->prepare('SELECT * FROM aics_assistance_records WHERE id=? LIMIT 1 FOR UPDATE');$find->execute([$recordId]);$record=$find->fetch(PDO::FETCH_ASSOC);
                    if(!$record)throw new RuntimeException('The linked AICS masterlist entry no longer exists.');
                    $deleteProposal=json_decode((string)($ticketData['proposed_data']??''),true);$baseVersion=(int)(is_array($deleteProposal)?($deleteProposal['_base_record_version']??0):0);
                    if($baseVersion<1 || (int)($record['record_version']??1)!==$baseVersion)throw new RuntimeException('The record has changed since this delete request was submitted. Ask the requester to review the latest record and submit a new request.');
                    fams_archive_record_with_history($db,'aics','',$record,(int)$me);
                    $upd=$db->prepare("UPDATE aics_tickets SET status='APPROVED',resolution_notes=?,record_control_number=?,record_id=NULL WHERE id=? AND status IN ('OPEN','IN PROGRESS')");
                    $upd->execute([$note?:'Deletion request approved; linked masterlist entry archived and removed.',(string)$record['control_number'],$id]);
                    if($upd->rowCount()!==1)throw new RuntimeException('Ticket was already reviewed. No record was deleted.');
                    $del=$db->prepare('DELETE FROM aics_assistance_records WHERE id=?');$del->execute([$recordId]);
                    if($del->rowCount()!==1)throw new RuntimeException('The linked masterlist entry could not be deleted.');
                    log_action($db,'APPROVE_DELETE_TICKET','Approved deletion ticket '.$ticketData['ticket_no'].' and archived/removed AICS record '.$record['control_number'].'.',$recordId);
                    return;
                }
                if($decision==='APPROVED' && !empty($ticketData['record_id']) && $category==='RECORD REVISION'){
                    $proposal=json_decode((string)$ticketData['proposed_data'],true);if(!is_array($proposal))throw new RuntimeException('The proposed record changes could not be read.');
                    $allowed=['date_submission','client_first_name','client_middle_name','client_last_name','client_name_extension','barangay','municipality','province','contact_number','beneficiary_first_name','beneficiary_middle_name','beneficiary_last_name','beneficiary_name_extension','assistance_type','guarantee_amount','quotation','remarks','status','release_date'];
                    $sets=[];$vals=[];foreach($allowed as $field){if(array_key_exists($field,$proposal)){$sets[]='`'.$field.'`=?';$vals[]=$proposal[$field]===''?null:$proposal[$field];}}
                    if(array_key_exists('office_id',$proposal)){
                        $targetOffice=(int)$proposal['office_id'];
                        $officeCheck=$db->prepare("SELECT COUNT(*) FROM aics_offices WHERE id=? AND is_active=1 AND parent_id=2 AND UPPER(TRIM(name)) NOT IN ('LPGMA','LPGMA OFFICE')");$officeCheck->execute([$targetOffice]);
                        if($targetOffice<1 || (int)$officeCheck->fetchColumn()!==1)throw new RuntimeException('The requested destination is not a valid active SWAD office. The record was not changed.');
                        $sets[]='`office_id`=?';$vals[]=$targetOffice;
                    }
                    if(!$sets)throw new RuntimeException('The ticket contains no record changes.');
                    $proposalVersion=(int)($proposal['_base_record_version']??0);
                    if($proposalVersion<1)throw new RuntimeException('This older revision ticket has no recorded base version. Ask the requester to update and resubmit the ticket before approval.');
                    $recordLock=$db->prepare('SELECT id,record_version FROM aics_assistance_records WHERE id=? LIMIT 1 FOR UPDATE');$recordLock->execute([(int)$ticketData['record_id']]);$lockedRecord=$recordLock->fetch(PDO::FETCH_ASSOC);
                    if(!$lockedRecord)throw new RuntimeException('The linked AICS record no longer exists.');
                    if((int)$lockedRecord['record_version']!==$proposalVersion)throw new RuntimeException('The AICS record has changed since this ticket was submitted. Ask the requester to review the latest record and submit an updated ticket.');
                    $sets[]='record_version=record_version+1';$sets[]='updated_by=?';$vals[]=$me;$vals[]=(int)$ticketData['record_id'];$vals[]=$proposalVersion;
                    $oldStatusQuery=$db->prepare('SELECT status FROM aics_assistance_records WHERE id=? LIMIT 1 FOR UPDATE');$oldStatusQuery->execute([(int)$ticketData['record_id']]);$oldRecordStatus=strtoupper((string)$oldStatusQuery->fetchColumn());
                    $up=$db->prepare('UPDATE aics_assistance_records SET '.implode(',',$sets).' WHERE id=? AND record_version=?');$up->execute($vals);
                    if($up->rowCount()!==1)throw new RuntimeException('The AICS record changed while the ticket was being applied.');
                    if(array_key_exists('status',$proposal)){$newRecordStatus=strtoupper((string)$proposal['status']);if($newRecordStatus!==$oldRecordStatus){fams_status_history_write($db,'aics',(int)$ticketData['record_id'],$oldRecordStatus,$newRecordStatus,$me,'Applied revision ticket '.$ticketData['ticket_no']);}}
                }
                $q=$db->prepare("UPDATE aics_tickets SET status=?,resolution_notes=? WHERE id=? AND status IN ('OPEN','IN PROGRESS')");
                $q->execute([$decision,$note?:null,$id]);if($q->rowCount()!==1)throw new RuntimeException('Ticket was not found or has already been reviewed.');
                log_action($db,$decision==='APPROVED'?'APPROVE_TICKET':'REJECT_TICKET',strtolower($decision).' AICS ticket #'.$id,null);
            });
            // Keep reviewed tickets in the ticket history for auditability.
            aics_ticket_finish($popup);
        }elseif($action==='delete'){
            fams_shared_transaction($pdo,function(PDO $db) use($id,$isAdmin,$me){
                $q=$db->prepare('SELECT ticket_no,created_by,status,record_id,category FROM aics_tickets WHERE id=? LIMIT 1 FOR UPDATE');$q->execute([$id]);$t=$q->fetch(PDO::FETCH_ASSOC);
                if(!$t)throw new RuntimeException('Ticket not found.');
                if(!$isAdmin&&(int)$t['created_by']!==$me)throw new RuntimeException('You may delete only your own tickets.');
                if(!in_array(strtoupper((string)$t['status']),['OPEN','IN PROGRESS'],true))throw new RuntimeException('Reviewed tickets are retained for audit history and cannot be deleted.');
                if(!empty($t['record_id']) || in_array(strtoupper((string)$t['category']),['DELETE REQUEST','RECORD REVISION'],true))throw new RuntimeException('Record-linked workflow tickets cannot be deleted; they must remain available for audit and recovery.');
                $d=$db->prepare("DELETE FROM aics_tickets WHERE id=? AND status IN ('OPEN','IN PROGRESS')");$d->execute([$id]);if($d->rowCount()!==1)throw new RuntimeException('Ticket could not be deleted.');
                log_action($db,'DELETE_TICKET','Deleted ticket '.$t['ticket_no'],null);
            });aics_ticket_finish($popup);
        }else throw new RuntimeException('Unsupported ticket action.');
    }catch(Throwable $e){aics_ticket_fail($e->getMessage(),$popup);}
}
if($popup && isset($_GET['record'])){redirect('revision_ticket.php?popup=1&record='.(int)$_GET['record']);}
if($popup){
    $t=null;if($editId){$q=$pdo->prepare('SELECT * FROM aics_tickets WHERE id=?');$q->execute([$editId]);$t=$q->fetch(PDO::FETCH_ASSOC);if(!$t){http_response_code(404);exit('Ticket not found.');}if((int)$t['created_by']!==$me||!in_array(strtoupper((string)$t['status']),['OPEN','IN PROGRESS'],true)){http_response_code(403);exit('You may edit only your own pending tickets.');}}
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.($t?'Edit Ticket':'Submit Ticket').'</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'" /></head><body class="fams-popup-page popup-form-body"><section class="panel form-panel ticket-submit-form"><div class="panel-heading"><div><span class="section-kicker">SUBMIT TICKET</span><h3>'.($t?'Edit Ticket — '.$t['ticket_no']:'AICS Support Ticket').'</h3><span class="muted">Describe the request clearly. The AICS administrator will review it before a decision is recorded.</span></div></div><form method="post" class="record-form modern-editor-form"><input type="hidden" name="csrf_token" value="'.e(csrf_token()).'"><input type="hidden" name="idempotency_key" value="'.e($formIdempotencyKey).'"><input type="hidden" name="popup" value="1"><input type="hidden" name="action" value="'.($t?'edit':'create').'"><input type="hidden" name="id" value="'.(int)($t['id']??0).'"><div class="form-section-title">Ticket Details</div><div class="form-grid"><div class="span-4"><label>Subject *</label><input class="input" name="subject" maxlength="180" required value="'.e((string)($t['subject']??'')).'" placeholder="Briefly describe your request"></div><div><label>Request Type</label><select name="category">';
    foreach(['GENERAL','MASTERLIST','FUND','ACCOUNT','CHAT','SYSTEM','RECORD CORRECTION','OTHER'] as $cat)echo '<option value="'.e($cat).'" '.(($t['category']??'GENERAL')===$cat?'selected':'').'>'.e($cat).'</option>';
    echo '</select></div><div class="span-4"><label>Details / Reason *</label><textarea class="input" name="description" rows="7" required placeholder="Provide the details needed to review this request">'.e((string)($t['description']??'')).'</textarea></div></div><div class="form-actions"><button type="button" class="btn btn-secondary" onclick="parent.postMessage({type:&quot;fams-close-popup&quot;},&quot;*&quot;)">Cancel</button><button class="btn btn-primary">'.($t?'Save Changes':'Submit Ticket').'</button></div></form></section></body></html>';exit;
}
$rows=$pdo->query('SELECT t.*,u.full_name creator FROM aics_tickets t LEFT JOIN aics_users u ON u.id=t.created_by ORDER BY t.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
$pending=count(array_filter($rows,fn($r)=>in_array(strtoupper((string)$r['status']),['OPEN','IN PROGRESS'],true)));
require __DIR__.'/partials/header.php';
?>
<section class="panel ticket-management-panel"><div class="panel-heading"><div><span class="section-kicker">REQUEST REVIEW</span><h3>Ticket Management</h3><span class="muted">Administrators approve or reject submitted requests. Users can manage their own pending tickets.</span></div><div class="ticket-header-actions"><span class="muted"><?=$pending?> pending</span><a class="secondary-btn" href="tickets.php?popup=1" data-fams-popup data-popup-title="Submit AICS Ticket">＋ Submit Ticket</a></div></div>
<div class="table-wrap ticket-management-table <?= $isAdmin?'admin-ticket-table':'standard-ticket-table' ?>"><table class="fams-data-list-table" aria-label="AICS Ticket Management"><caption class="sr-only">AICS ticket submissions and administrator decisions</caption><colgroup><col class="ticket-col-ticket"><col class="ticket-col-subject"><col class="ticket-col-category"><col class="ticket-col-requester"><col class="ticket-col-reason"><col class="ticket-col-status"><col class="ticket-col-action"></colgroup><thead><tr><th>Ticket</th><th>Subject</th><th>Request Type</th><th>Requested By</th><th>Details / Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="7" class="empty-cell">No tickets submitted.</td></tr><?php endif; foreach($rows as $t): $owner=(int)$t['created_by']===$me; $status=strtoupper((string)$t['status']); $pendingTicket=in_array($status,['OPEN','IN PROGRESS'],true); ?>
<tr><td><strong><?=e($t['ticket_no'])?></strong><br><small><?=e(date('M d, Y H:i',strtotime($t['created_at'])))?></small></td><td><strong><?=e($t['subject'])?></strong><?php if(!empty($t['resolution_notes'])):?><div class="sub"><strong>Decision note:</strong> <?=e($t['resolution_notes'])?></div><?php endif;?></td><td><?=e($t['category'])?></td><td><?=e($t['creator']??'System')?></td><td class="ticket-reason-cell"><?=nl2br(e($t['description']))?></td><td><span class="status-badge <?=in_array($status,['OPEN','IN PROGRESS'],true)?'status-waiting':($status==='APPROVED'||$status==='RESOLVED'?'status-released':'status-cancelled')?>"><?=e($status)?></span></td><td class="admin-action-cell"><div class="admin-action-links ticket-row-actions">
<?php if($isAdmin && $pendingTicket): ?><form method="post" class="ticket-decision-form" onsubmit="return confirm('Approve this ticket?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="decision"><input type="hidden" name="decision" value="APPROVED"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="admin-action-link" type="submit">Approve</button></form><form method="post" class="ticket-decision-form" onsubmit="const r=this.querySelector('[name=resolution_notes]').value.trim();if(!r){alert('Enter a rejection reason.');return false;}return confirm('Reject this ticket?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="decision"><input type="hidden" name="decision" value="REJECTED"><input type="hidden" name="id" value="<?=$t['id']?>"><input class="input ticket-rejection-reason" name="resolution_notes" placeholder="Reason to reject" required><button class="admin-action-link danger-link" type="submit">Reject</button></form><?php endif; ?>
<?php if($owner && $pendingTicket): ?><a class="admin-action-link" data-fams-popup data-popup-title="Edit Ticket" href="tickets.php?popup=1&amp;edit=<?=$t['id']?>">Edit</a><?php endif; ?><?php if($owner||$isAdmin): ?><form method="post" class="ticket-delete-action" onsubmit="return confirm('Delete ticket <?=e($t['ticket_no'])?>?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="admin-action-link danger-link" type="submit">Delete</button></form><?php endif; ?><?php if(!$owner&&!$isAdmin): ?><span class="muted">View only</span><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php require __DIR__.'/partials/footer.php'; ?>
