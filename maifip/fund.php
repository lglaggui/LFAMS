<?php
require_once __DIR__ . '/config.php'; require_login(); if(!fams_current_type_allows('MAIFIP','manage_funds')){http_response_code(403);exit('Fund management is not permitted.');}
require_role(['admin','manager']);

$userRole=(string)(current_user()['role']??'');
$activeFacility=active_data_facility($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS fund_downloads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    downloaded_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    batch_no VARCHAR(80) NOT NULL,
    date_downloaded DATE NOT NULL,
    fund_source VARCHAR(160) NOT NULL DEFAULT 'Unspecified',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$sourceColumnExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fund_downloads' AND COLUMN_NAME='fund_source'")->fetchColumn();
if (!$sourceColumnExists) { $pdo->exec("ALTER TABLE fund_downloads ADD COLUMN fund_source VARCHAR(160) NOT NULL DEFAULT 'Unspecified' AFTER date_downloaded"); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($userRole ?? '') === 'manager') { http_response_code(403); exit('LPGMA Manager access is read-only.'); }
    verify_csrf();
    $action=$_POST['action']??'';
    if ($action==='save_fund') {
        $id=(int)($_POST['id']??0);
        $batch=fams_uppercase_text($_POST['batch_no']??'');
        $amountRaw=trim((string)($_POST['downloaded_amount']??''));
        $amount=is_numeric($amountRaw)?(float)$amountRaw:NAN;
        $date=trim((string)($_POST['date_downloaded']??''));
        $source=fams_uppercase_text($_POST['fund_source']??'');
        if ($batch==='' || !is_finite($amount) || abs($amount)>999999999999.99 || $date==='' || $source==='') {
            flash('error','Batch No., a valid signed fund amount, download date, and fund source are required.');
            redirect(!empty($_POST['popup_mode']) ? 'fund.php?popup=1&'.($id?'edit='.$id.'&':'') : 'fund.php'.($id?'?edit='.$id:''));
        }
        if (strtoupper(trim($batch))==='BEGINNING BALANCE') {
            flash('error','BEGINNING BALANCE is reserved for the verified January 1, 2026 opening balance. Enter ordinary fund additions using their actual batch number.');
            redirect(!empty($_POST['popup_mode']) ? 'fund.php?popup=1&'.($id?'edit='.$id.'&':'') : 'fund.php'.($id?'?edit='.$id:''));
        }
        if ($id>0) {
            $existing=$pdo->prepare("SELECT batch_no FROM fund_downloads WHERE id=? AND facility=?");
            $existing->execute([$id,$activeFacility]);
            if (strtoupper(trim((string)$existing->fetchColumn()))==='BEGINNING BALANCE') { flash('error','Opening-balance records cannot be edited through the ordinary fund-download screen.'); redirect(!empty($_POST['popup_mode']) ? 'fund.php?popup=1&edit='.$id : 'fund.php?edit='.$id); }
            $stmt=$pdo->prepare("UPDATE fund_downloads SET batch_no=?,downloaded_amount=?,date_downloaded=?,fund_source=? WHERE id=? AND facility=?");
            $stmt->execute([$batch,$amount,$date,$source,$id,$activeFacility]);
            log_action($pdo,'UPDATE_FUND_DOWNLOAD','Updated fund download '.$batch.'.');
            flash('success','Fund download updated successfully.');
        } else {
            $stmt=$pdo->prepare("INSERT INTO fund_downloads (facility,batch_no,downloaded_amount,date_downloaded,fund_source) VALUES (?,?,?,?,?)");
            $stmt->execute([$activeFacility,$batch,$amount,$date,$source]);
            log_action($pdo,'ADD_FUND_DOWNLOAD','Added fund download '.$batch.'.');
            flash('success','Fund download added successfully.');
        }
        redirect(!empty($_POST['popup_mode']) ? 'fund.php?popup=1&'.($id?'edit='.$id.'&':'').'saved=1' : 'fund.php');
    }
    if ($action==='delete_fund') {
        $id=(int)($_POST['id']??0);
        if ($id>0) { $stmt=$pdo->prepare("DELETE FROM fund_downloads WHERE id=? AND facility=?"); $stmt->execute([$id,$activeFacility]); log_action($pdo,'DELETE_FUND_DOWNLOAD','Deleted fund download #'.$id.'.'); }
        flash('success','Fund download deleted.');
        redirect('fund.php');
    }
}

$isPopup = isset($_GET['popup']) && $_GET['popup']==='1';
$editId=(int)($_GET['edit']??0);
if($userRole==='manager' && $isPopup && $editId<=0){ redirect('fund.php'); }
$editFund=null;
if($editId>0){ $stmt=$pdo->prepare("SELECT * FROM fund_downloads WHERE id=? AND facility=?"); $stmt->execute([$editId,$activeFacility]); $editFund=$stmt->fetch() ?: null; }
$stmt=$pdo->prepare("SELECT * FROM fund_downloads WHERE facility=? ORDER BY date_downloaded,id"); $stmt->execute([$activeFacility]); $fundDownloads=$stmt->fetchAll();
$fundPosition=maifip_fund_ledger_position($pdo); $totalAllotment=(float)$fundPosition['available'];
$disbursed=(float)$fundPosition['disbursed'];
$remaining=(float)$fundPosition['remaining'];

$pageTitle='Fund Management';
if($isPopup){ echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($userRole==='manager' ? 'View Fund Download' : ($editFund?'Edit Fund Download':'Add Fund Download')).'</title><link rel="stylesheet" href="assets/app.css?v='.e(APP_VERSION).'"></head><body class="fams-popup-page">'; } else { require __DIR__ . '/partials/header.php'; }
?>


<?php if($isPopup): ?>
<div class="fund-popup-editor-shell">
<section class="panel form-panel focused-popup-form">
  <div class="panel-heading">
    <div><span class="section-kicker">FUND MANAGEMENT</span><h3><?= $userRole==='manager' ? 'View Fund Download' : ($editFund ? 'Edit Fund Download' : 'Add Fund Download') ?></h3></div>
    <span class="editor-state-chip">Focused editor</span>
  </div>
  <?php if(function_exists('flash_messages')): ?><?php $popupFlashes=flash_messages(); foreach($popupFlashes as $fl): ?><div class="notice <?=e((string)($fl['type']??''))?>"><?=e((string)($fl['message']??''))?></div><?php endforeach; ?><?php endif; ?>
  <div class="editor-form-intro"><span class="editor-form-icon">₱</span><div><strong><?= $userRole==='manager' ? 'View fund entry' : ($editFund ? 'Update fund entry' : 'New fund entry') ?></strong><small>All values are recorded against the currently selected facility.</small></div></div>
  <form method="post" class="record-form modern-editor-form"><?php if($userRole==='manager'): ?><fieldset disabled class="manager-readonly-fields"><?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_fund"><input type="hidden" name="id" value="<?=e((string)($editFund['id']??0))?>"><input type="hidden" name="popup_mode" value="1">
    <div class="form-grid fund-entry-grid">
      <div><label>Batch No.</label><input type="text" name="batch_no" value="<?=e($editFund['batch_no']??'')?>" placeholder="e.g. BATCH NO. 109" required></div>
      <div><label>Fund Download / Reversal Amount</label><input type="number" name="downloaded_amount" step="0.01" value="<?=e(isset($editFund['downloaded_amount'])?number_format((float)$editFund['downloaded_amount'],2,'.',''):'')?>" placeholder="e.g. 1000000.00 or -50000.00" required></div>
      <div><label>Download Date</label><input type="date" name="date_downloaded" value="<?=e($editFund['date_downloaded']??date('Y-m-d'))?>" required></div>
      <div><label>Fund Source</label><input type="text" name="fund_source" value="<?=e($editFund['fund_source']??'')?>" placeholder="e.g. DSWD / DOH / Fund Transfer" required></div>
    </div>
    <div class="form-actions"><button class="secondary-btn" type="button" onclick="parent.postMessage({type:'fams-close-popup'},'*')">Close</button><?php if($userRole!=='manager'): ?><button class="primary-btn" type="submit"><?= $editFund ? 'Update Fund' : 'Add Fund' ?></button><?php endif; ?></div><?php if($userRole==='manager'): ?></fieldset><?php endif; ?>
  </form>
</section>
</div>
<script>document.querySelector('.modern-editor-form')?.addEventListener('submit',()=>parent.postMessage({type:'fams-popup-submitting'},'*'));<?php if(isset($_GET['saved']) && $_GET['saved']==='1'): ?>window.parent.postMessage({type:'fams-popup-success',message:'<?=e($editFund?'Fund entry updated successfully.':'Fund entry added successfully.')?>'},'*');<?php endif; ?></script>
<?php else: ?>
<div class="fund-management-page">
<section class="fund-dashboard-grid">
    <div class="panel summary-card"><span class="section-kicker">TOTAL ALLOTMENT</span><strong>₱<?=number_format($totalAllotment,2)?></strong><p>Opening balance plus net fund movements (including reversals).</p></div>
    <div class="panel summary-card"><span class="section-kicker">TOTAL UTILIZED</span><strong>₱<?=number_format($disbursed,2)?></strong><p>Reporting disbursements through <?=e(date('M d, Y'))?>.</p></div>
    <div class="panel summary-card fund-balance-emphasis"><span class="section-kicker">REMAINING FUND</span><strong>₱<?=number_format($remaining,2)?></strong><p>Available funds less reconciled disbursements.</p></div>
</section>
<section class="panel fund-page-section"><div class="panel-heading"><div><span class="section-kicker">FUND BREAKDOWN</span><h3>Fund Downloads and Reversals</h3></div><span class="muted"><?=number_format(count($fundDownloads))?> fund entr<?=count($fundDownloads)===1?'y':'ies'?></span></div><div class="table-wrap fund-ledger-table"><table class="fams-data-list-table"><thead><tr><th>Batch No.</th><th>Signed Fund Movement</th><th>Download Date</th><th>Fund Source</th><th>Actions</th></tr></thead><tbody>
    <?php if(!$fundDownloads): ?><tr><td colspan="5" class="empty-cell">No fund downloads have been encoded yet.</td></tr><?php endif; ?>
    <?php foreach($fundDownloads as $f): ?><tr><td><strong><?=e($f['batch_no'])?></strong></td><td class="fams-amount-cell"><strong>₱<?=number_format((float)$f['downloaded_amount'],2)?></strong></td><td><?=e(date('F d, Y',strtotime($f['date_downloaded'])))?></td><td><?=e($f['fund_source'])?></td><td><?php if($userRole==='manager'): ?><span class="muted">View only</span><?php else: ?><div class="action-links"><a class="text-link" href="fund.php?edit=<?=(int)$f['id']?>&popup=1" data-fams-popup data-popup-title="Edit Fund Download">Edit</a><form method="post" class="inline" onsubmit="return confirm('Delete this fund entry?');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_fund"><input type="hidden" name="id" value="<?=(int)$f['id']?>"><button class="button-link text-link danger-link" type="submit">Delete</button></form></div><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php if($userRole!=='manager'): ?><section class="panel form-panel fund-management-panel fund-add-full-width"><div class="panel-heading"><div><span class="section-kicker">FUND MANAGEMENT</span><h3>Fund Ledger Actions</h3></div><span class="editor-state-chip">Focused editor</span></div><div class="editor-launch-card"><div class="editor-launch-icon">₱</div><div><strong>Add or update a fund movement</strong><span>Enter positive amounts for additions and negative amounts for reversals or corrections.</span></div><a class="primary-btn" data-fams-popup data-popup-title="Add Fund Download" href="fund.php?popup=1">Add Fund Download</a></div></section><?php endif; ?>
</div>
<?php endif; ?>
<?php if(!$isPopup): require __DIR__.'/partials/footer.php'; else: echo '</body></html>'; endif; ?>
