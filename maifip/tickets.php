<?php
require_once __DIR__.'/config.php';
require_login();
if (!fams_current_type_allows('MAIFIP','submit_tickets') && !fams_current_type_allows('MAIFIP','manage_tickets')) { http_response_code(403); exit('Your user type cannot access ticket management.'); }
$me=(int)current_user()['id'];
$role=current_user()['role']??'';
$activeFacility=active_data_facility($pdo);
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

if($_SERVER['REQUEST_METHOD']==='POST'){
    // Facility-side users may edit/delete their own tickets; only admins can review decisions or bulk-delete.
    verify_csrf();
    $action=trim((string)($_POST['action']??''));
    $id=(int)($_POST['ticket_id']??0);

    if($action==='delete_ticket'){
        if (!fams_current_type_allows('MAIFIP','submit_tickets') && !fams_current_type_allows('MAIFIP','manage_tickets')) { http_response_code(403); exit('Ticket deletion is not permitted.'); }
        try{$ticketNumber=fams_shared_transaction($pdo,function(PDO $db) use($id,$activeFacility,$role,$me){
            $q=$db->prepare('SELECT t.ticket_number,t.record_id,t.record_facility,t.requested_by,t.status FROM revision_tickets t LEFT JOIN assistance_records a ON a.id=t.record_id WHERE t.id=? AND COALESCE(a.facility,t.record_facility)=? LIMIT 1 FOR UPDATE');
            $q->execute([$id,$activeFacility]);$t=$q->fetch(PDO::FETCH_ASSOC);
            if(!$t)throw new RuntimeException('Ticket not found.');
            if($role!=='admin'&&(int)$t['requested_by']!==$me)throw new RuntimeException('You may only delete your own ticket submissions.');
            $d=$db->prepare('DELETE FROM revision_tickets WHERE id=? AND (record_id IN (SELECT id FROM assistance_records WHERE facility=?) OR (record_id IS NULL AND record_facility=?))');
            $d->execute([$id,$activeFacility,$activeFacility]);if($d->rowCount()!==1)throw new RuntimeException('The ticket could not be deleted.');
            log_action($db,'DELETE_TICKET',$role==='admin'?"Admin deleted ticket {$t['ticket_number']}.":"Deleted own ticket {$t['ticket_number']}.",$t['record_id']!==null?(int)$t['record_id']:null);
            return (string)$t['ticket_number'];
        });flash('success',"Ticket {$ticketNumber} was deleted.");}catch(Throwable $e){flash('error',$e->getMessage());}
        redirect('tickets.php');
    }

    if($action==='delete_all_tickets'){
        if(!fams_current_type_allows('MAIFIP','manage_tickets') || $role!=='admin'){http_response_code(403);exit('Only the administrator can delete all tickets.');}
        try{$count=fams_shared_transaction($pdo,function(PDO $db) use($activeFacility){
            $countSt=$db->prepare('SELECT COUNT(*) FROM revision_tickets t LEFT JOIN assistance_records a ON a.id=t.record_id WHERE (a.facility=? OR (t.record_id IS NULL AND t.record_facility=?))');$countSt->execute([$activeFacility,$activeFacility]);$count=(int)$countSt->fetchColumn();
            $delAll=$db->prepare('DELETE t FROM revision_tickets t LEFT JOIN assistance_records a ON a.id=t.record_id WHERE (a.facility=? OR (t.record_id IS NULL AND t.record_facility=?))');$delAll->execute([$activeFacility,$activeFacility]);
            log_action($db,'DELETE_ALL_TICKETS',"Admin deleted all revision tickets ({$count} ticket(s)).",null);return $count;
        });flash('success',"Deleted {$count} ticket(s).");}catch(Throwable $e){flash('error','Tickets could not be deleted. No changes were made.');redirect('tickets.php');}
        redirect('tickets.php');
    }

    if($action==='reject'){
        if(!fams_current_type_allows('MAIFIP','manage_tickets') || $role!=='admin'){http_response_code(403);exit('Only the administrator can reject tickets.');}
        $notes=fams_uppercase_text($_POST['resolution_notes']??'');
        if($notes===''){flash('error','Please enter a reason when rejecting a ticket.');redirect('tickets.php');}
        try{fams_shared_transaction($pdo,function(PDO $db) use($id,$activeFacility,$notes){
            $q=$db->prepare('SELECT t.ticket_number,t.record_id,t.status FROM revision_tickets t JOIN assistance_records a ON a.id=t.record_id WHERE t.id=? AND a.facility=? LIMIT 1 FOR UPDATE');$q->execute([$id,$activeFacility]);$t=$q->fetch(PDO::FETCH_ASSOC);
            if(!$t)throw new RuntimeException('Ticket not found.');if($t['status']!=='OPEN')throw new RuntimeException('Ticket is already closed.');
            $u=$db->prepare("UPDATE revision_tickets SET status='REJECTED',resolution_notes=?,resolved_by=?,resolved_at=NOW() WHERE id=? AND status='OPEN'");$u->execute([$notes,(int)current_user()['id'],$id]);if($u->rowCount()!==1)throw new RuntimeException('Ticket was already processed.');
            log_action($db,'REJECT_TICKET',"Rejected status-change ticket {$t['ticket_number']}.",(int)$t['record_id']);
        });flash('success','Status-change ticket rejected.');}catch(Throwable $e){flash('error',$e->getMessage());}
        redirect('tickets.php');
    }
}

$st=$pdo->prepare("SELECT t.*,COALESCE(a.control_number,t.record_control_number,'Deleted record') AS control_number,a.patient_first_name,a.patient_last_name,u.full_name requester FROM revision_tickets t LEFT JOIN assistance_records a ON a.id=t.record_id LEFT JOIN users u ON u.id=t.requested_by WHERE (a.facility=? OR (t.record_id IS NULL AND t.record_facility=?)) ORDER BY t.created_at DESC"); $st->execute([$activeFacility,$activeFacility]); $rows=$st->fetchAll();
$openCount=count(array_filter($rows,fn($r)=>$r['status']==='OPEN'));
$pageTitle='Ticket Management';require __DIR__.'/partials/header.php';
?>
<section class="panel">
<div class="panel-heading">
  <div><span class="section-kicker">DATA CORRECTIONS</span><h3>Ticket Management</h3></div>
  <div class="ticket-header-actions">
    <span class="muted"><?=$openCount?> open</span>
    <?php if($role==='admin' && $rows): ?>
      <form method="post" class="inline-form" onsubmit="return confirm('Delete ALL ticket submissions? This removes the ticket records only; FAMS assistance records are not deleted. This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_all_tickets"><button type="submit" class="secondary-btn table-action-danger">Delete All Tickets</button></form>
    <?php endif; ?>
  </div>
</div>
<div class="table-wrap ticket-management-table <?= $role==='admin' ? 'admin-ticket-table' : 'standard-ticket-table' ?>"><table class="fams-data-list-table" aria-label="Ticket Management"><caption class="sr-only">Record revision tickets and their current review status</caption><colgroup><col class="ticket-col-ticket"><col class="ticket-col-control"><col class="ticket-col-beneficiary"><col class="ticket-col-requester"><col class="ticket-col-change"><col class="ticket-col-reason"><col class="ticket-col-status"><col class="ticket-col-action"></colgroup><thead><tr><th>Ticket</th><th>Control No.</th><th>Beneficiary</th><th>Requested By</th><th>Requested Change</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="8" class="empty-cell">No tickets submitted.</td></tr><?php endif; ?>
<?php foreach($rows as $r):
  $ticketProposal=json_decode((string)($r['proposed_data']??''),true); $requestedChange='—';
  if(is_array($ticketProposal)){
    $parts=[];
    if(strtoupper((string)($ticketProposal['request_type']??''))==='DELETE' || strtolower((string)($r['requested_fields']??''))==='delete') $parts[]='DELETE ENTRY';
    if(isset($ticketProposal['record_updates']) && is_array($ticketProposal['record_updates'])) $parts[]='DETAILS';
    if(isset($ticketProposal['requested_status'])) $parts[]='STATUS → '.strtoupper((string)$ticketProposal['requested_status']);
    $requestedChange=$parts?implode(' + ',$parts):($r['requested_fields']??'—');
  } else $requestedChange=$r['requested_fields']??'—';
  $isOwner=(int)$r['requested_by']===$me;
?>
<tr>
<td><strong><?=e($r['ticket_number'])?></strong><br><small><?=e(date('M d, Y H:i',strtotime($r['created_at'])))?></small></td>
<td><?=e($r['control_number'])?></td><td><?=e(trim(($r['patient_first_name']??'').' '.($r['patient_last_name']??'')) ?: '—')?></td><td><?=e($r['requester']??'—')?></td><td><?=e($requestedChange)?></td><td><?=e($r['reason'])?></td>
<td><span class="status-badge <?= $r['status']==='OPEN'?'status-waiting':($r['status']==='RESOLVED'?'status-released':'status-cancelled')?>"><?=e($r['status'])?></span></td>
<td class="admin-action-cell admin-action-links-cell">
  <div class="admin-action-links ticket-row-actions" aria-label="Actions for <?=e($r['ticket_number'])?>">
  <?php if($role==='admin'): ?>
    <?php if($r['status']==='OPEN'): ?><a class="admin-action-link" data-fams-popup data-popup-title="Review / Edit Ticket" href="ticket_review.php?id=<?=$r['id']?>">Review / Edit</a><?php endif; ?>
    <form method="post" class="ticket-delete-action" onsubmit="return confirm('Delete ticket <?=e($r['ticket_number'])?>? Only the ticket will be deleted; the FAMS record is not affected.');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="ticket_id" value="<?=$r['id']?>"><input type="hidden" name="action" value="delete_ticket"><button class="admin-action-link danger-link" type="submit">Delete Ticket</button></form>
    <?php if($r['status']==='OPEN'): ?><form method="post" class="ticket-reject-action"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="ticket_id" value="<?=$r['id']?>"><input type="hidden" name="action" value="reject"><input type="text" name="resolution_notes" placeholder="Rejection reason" required><button class="admin-action-link" type="submit">Reject Ticket</button></form><?php endif; ?>
  <?php elseif($isOwner): ?>
    <?php if($r['status']==='OPEN'): ?><a class="admin-action-link" data-fams-popup data-popup-title="Edit Ticket" href="ticket_edit.php?id=<?=$r['id']?>">Edit Ticket</a><?php endif; ?>
    <form method="post" class="ticket-delete-action" onsubmit="return confirm('Delete your ticket <?=e($r['ticket_number'])?>? Only the ticket will be deleted; the FAMS record is not affected.');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="ticket_id" value="<?=$r['id']?>"><input type="hidden" name="action" value="delete_ticket"><button class="admin-action-link danger-link" type="submit">Delete Ticket</button></form>
  <?php else: ?><span class="muted">No action</span><?php endif; ?>
  </div>
</td></tr>
<?php endforeach; ?>
</tbody></table></div></section>
<?php require __DIR__.'/partials/footer.php'; ?>
