<?php
require_once __DIR__ . '/config.php';
require_login();
if(!fams_current_type_allows('MAIFIP','view_records')){http_response_code(403);exit('Your user type does not have permission to view assistance records. Contact the Site Administrator if this access is required.');}

$pageTitle = 'LPGMA FAMS';
$famsPageTitle = 'Financial Assistance Monitoring System (FAMS)';
$user = current_user();
$activeFacility=active_data_facility($pdo);
$isFragment = isset($_GET['fragment']) && $_GET['fragment'] === '1';
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$q = fams_shared_normalize_search((string)($_GET['q'] ?? ''));
$status = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM assistance_records WHERE facility=?"; $params = [$activeFacility];

if ($q !== '') {
    $sql .= " AND (
        control_number LIKE ? OR
        patient_first_name LIKE ? OR
        patient_middle_name LIKE ? OR
        patient_last_name LIKE ? OR
        barangay LIKE ? OR
        municipality LIKE ? OR
        guardian LIKE ? OR
        contact_number LIKE ?
    )";
    $needle = "%{$q}%";
    array_push($params, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
}

if ($status !== '') {
    $sql .= " AND status = ?";
    $params[] = $status;
}

// Count the complete filtered result set, then fetch only the requested page.
$countSql = preg_replace('/^SELECT \* FROM assistance_records/', 'SELECT COUNT(*) FROM assistance_records', $sql);
$countSql = preg_replace('/ ORDER BY date_submission DESC, id DESC LIMIT 500$/', '', $countSql);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$pageWindow = fams_shared_page_window($totalRecords, $page, $perPage);
$totalPages = $pageWindow['pages'];
$page = $pageWindow['page'];
$offset = $pageWindow['offset'];
$sql .= ' ORDER BY date_submission DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

if (!$isFragment) require __DIR__ . '/partials/header.php';
?>
<?php if ($isFragment): ?><div id="pageView"><?php endif; ?>
<div class="records-page" data-shell-title="<?=e($pageTitle)?>">
<style>
.records-top-row{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(0,1fr);gap:12px;align-items:stretch;margin:0 0 12px}
.records-top-row>.masterlist-hero,.records-top-row>.masterlist-control-panel,.records-top-row>.maifip-access-card{margin:0;min-width:0;height:100%;box-sizing:border-box}
.records-top-row .masterlist-hero{padding:14px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;min-height:106px;background:linear-gradient(135deg,#fff 0%,#f3f8fc 100%);border:1px solid #dce7ef;border-radius:14px}
.records-top-row .masterlist-hero h2{margin:3px 0 4px;color:#0a376c;font-size:22px}
.records-top-row .masterlist-hero p{margin:0;color:#536d83;font-size:11px;line-height:1.5}
.records-top-row .maifip-access-inline{margin-top:12px;padding:10px 12px;border-left:3px solid #2f6f9f;background:#f2f7fb;border-radius:0 8px 8px 0;color:#29465e;font-size:10px;line-height:1.5}.records-top-row .masterlist-hero-meta{min-width:76px;text-align:center;border-left:1px solid #dfe8ef;padding-left:16px}
.records-top-row .masterlist-hero-meta strong{display:block;font-size:24px;color:#0b4d92}
.records-top-row .masterlist-hero-meta span{font-size:9px;text-transform:uppercase;letter-spacing:.12em;color:#657d91}
.records-top-row .maifip-access-card{padding:14px 18px;border:1px solid #d6e5f0;border-radius:14px;background:linear-gradient(135deg,#f7fbff,#edf5fb);color:#29465e;box-shadow:0 4px 16px rgba(20,55,85,.045)}
.records-top-row .maifip-access-card h3{font-size:15px;margin:5px 0 8px;color:#173e61}
.records-top-row .maifip-access-card p{font-size:11px;line-height:1.6;margin:0;color:#536d83}
.records-top-row .masterlist-actions-panel{padding:12px 16px;background:linear-gradient(180deg,#fff 0%,#f9fbfd 100%);border:1px solid #dbe5ee;border-radius:14px;box-shadow:0 4px 16px rgba(20,55,85,.045)}
.records-top-row .masterlist-toolbar-top{height:100%;margin:0;display:flex;flex-direction:column;align-items:stretch;justify-content:center;gap:8px}
.records-top-row .toolbar-title h3{margin:2px 0 0;font-size:15px;color:#173e61}
.records-top-row .masterlist-panel-hint{margin:5px 0 0;color:#657d91;font-size:10px;line-height:1.45}
.records-top-row .toolbar-actions{display:flex;justify-content:flex-start;align-items:center;gap:9px;flex-wrap:wrap}
.records-top-row .toolbar-actions .btn{min-height:0;width:40px;height:40px;padding:0;border-radius:11px;white-space:nowrap;font-size:0;gap:0;box-shadow:0 2px 7px rgba(15,45,75,.08)}.records-top-row .toolbar-actions .action-btn-icon{width:19px;height:19px;margin:0}.records-top-row .toolbar-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.records-top-row .toolbar-title h3{margin:0}.records-top-row .toolbar-title .masterlist-panel-hint{flex-basis:100%;margin:0}
@media(max-width:900px){.records-top-row{grid-template-columns:minmax(0,1fr)}.records-top-row .masterlist-hero{min-height:120px}.records-top-row .masterlist-toolbar-top{gap:10px}}
@media(max-width:520px){.records-top-row .masterlist-hero{padding:13px}.records-top-row .masterlist-hero h2{font-size:19px}.records-top-row .masterlist-hero-meta{min-width:56px;padding-left:10px}.records-top-row .toolbar-actions .btn{flex:0 0 38px;width:38px;height:38px;white-space:normal;text-align:center}}

.action-btn-icon{width:16px;height:16px;display:inline-block;vertical-align:-3px;margin-right:7px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:none}.toolbar-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:2px}

.records-top-row .toolbar-actions .btn{display:inline-flex;align-items:center;justify-content:center;color:#174c78;transition:transform .16s ease,box-shadow .16s ease,background-color .16s ease}
.records-top-row .toolbar-actions .btn:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(15,45,75,.14);filter:brightness(.98)}
.records-top-row .toolbar-actions .btn:focus-visible{outline:3px solid #78bdf2;outline-offset:3px}
</style>
<div class="records-top-row">
<section class="masterlist-hero" aria-labelledby="famsPageHeading"><div class="masterlist-hero-copy"><span class="section-kicker">LPGMA ISABELA DISTRICT • <?=e(facility_label(selected_facility($pdo)))?></span><h2 id="famsPageHeading">Masterlist</h2><p>Financial assistance records monitoring, tracking, and management.</p></div><div class="masterlist-hero-tools"><div class="masterlist-hero-meta"><strong><?=number_format($totalRecords)?></strong><span>records</span></div><?php if(user_facility($user)==='LPGMA' && in_array(($user['role']??''),['admin','encoder'],true) && fams_current_type_allows('MAIFIP','create_records')): ?><a class="masterlist-add-entry-icon" href="record_form.php" data-fams-popup data-popup-title="Add Financial Assistance Record" aria-label="Add financial assistance entry" title="Add Entry"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span class="sr-only">Add Entry</span></a><?php endif; ?></div></section>
<?php if(user_facility($user)!=='LPGMA'): ?>
<section class="card maifip-access-card" aria-labelledby="maifipAccessHeading"><span class="section-kicker">ACCESS REMINDER</span><h3 id="maifipAccessHeading">Facility-based access — <?=e($activeFacility)?></h3><p>To protect beneficiary privacy and maintain facility-level accountability, you can work only with records available to your assigned facility. If a record appears to belong to your facility but is missing, contact the LPGMA Site Administrator to review the facility assignment; do not create a duplicate record or try to bypass the restriction.</p></section>
<?php else: ?>
<section class="card masterlist-control-panel masterlist-actions-panel" aria-labelledby="masterlistActionsHeading"><div class="masterlist-toolbar-top"><div class="toolbar-title"><span class="section-kicker">ACTIONS</span><h3 id="masterlistActionsHeading">Record Actions</h3><p class="masterlist-panel-hint">Hover or focus an icon to see its action.</p></div><div class="toolbar-actions">
<?php if (in_array($user['role'], ['admin','encoder'], true)): ?><a class="btn btn-secondary" href="import_sheet.php" aria-label="Import or update spreadsheet" title="Import / Update Sheet"><svg class="action-btn-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V3m0 0L7 8m5-5 5 5M4 14v6h16v-6"/></svg></a><?php endif; ?>
<?php if (in_array($user['role'], ['admin','encoder','manager'], true)): ?><a class="btn btn-secondary" href="fams_sheet_editor.php?facility=<?=e(rawurlencode($activeFacility))?>" id="displayFamsSheet" target="_blank" rel="noopener" aria-label="<?= $user['role']==='manager' ? 'View sheet' : 'Display sheet' ?>" title="<?= $user['role']==='manager' ? 'View Sheet' : 'Display Sheet' ?>"><svg class="action-btn-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11m6-11v11"/></svg></a><?php endif; ?>
<?php if (in_array($user['role'], ['admin','encoder'], true)): ?><a class="btn btn-primary" href="record_form.php" data-fams-popup data-popup-title="Add Financial Assistance Record" aria-label="Add financial assistance record" title="Add Financial Assistance Record"><svg class="action-btn-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></a><?php endif; ?>
<?php if (($user['role']??'')==='admin'): ?><a class="btn btn-secondary" href="trash.php" aria-label="View deleted entries" title="Deleted Entries"><svg class="action-btn-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg></a><?php endif; ?>
<?php if (fams_current_type_allows('MAIFIP','export_records')): ?><a class="btn btn-ghost" href="export_csv.php<?=!empty($_SERVER['QUERY_STRING'])?'?'.e($_SERVER['QUERY_STRING']):''?>" aria-label="Export records to CSV" title="Export CSV"><svg class="action-btn-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v13m0 0 5-5m-5 5-5-5M4 20h16"/></svg></a><?php endif; ?>
</div></div></section>
<?php endif; ?>
</div>
<section class="fams-masterlist-toolbar" aria-label="FAMS masterlist controls">
    <div class="fams-toolbar-line fams-toolbar-search-line" aria-label="Masterlist search and filters">
        <form class="search-form" method="get" id="famsSearchForm">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search patient, guardian, address, contact..." autocomplete="off" aria-label="Search masterlist">
            <select name="status">
                <option value="">All Status</option>
                <?php foreach (['WAITING','RELEASED','CANCELLED'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="secondary-btn" type="submit">Search</button>
            <a class="ghost-btn" href="records.php">Reset</a>
        </form>
    </div>
</section>

<section class="panel table-panel">
    <div class="panel-heading compact">
        <div>
            <span class="section-kicker">MASTER LIST</span>
            <h3 id="famsRecordCount" aria-live="polite" aria-atomic="true"><?= number_format($totalRecords) ?> record(s) found</h3>
        </div>
    </div>

    <div class="table-wrap masterlist-table-wrap" role="region" aria-label="Scrollable masterlist table" tabindex="0">
        <table class="fams-data-list-table swad-masterlist-table" aria-label="MAIFIP FAMS masterlist">
            <thead><tr>
                <th>Submission</th><th>Patient</th><th>Guardian</th><th>Address</th><th>Bill Amount</th><th>GL Amount</th><th>Remarks</th><th>Status</th><th class="no-print">Action</th>
            </tr></thead>
            <tbody id="famsRecordBody">
            <?php if (!$records): ?>
                <tr><td colspan="9" class="empty-cell">No records found.</td></tr>
            <?php endif; ?>

            <?php foreach ($records as $i => $row): ?>
            <?php $beneficiaryName = trim(($row['patient_first_name'] ?? '').' '.($row['patient_middle_name'] ?? '').' '.($row['patient_last_name'] ?? '').' '.($row['patient_name_extension'] ?? '')); ?>
            <tr>
                <td><strong><?= e(date('M d, Y', strtotime($row['date_submission']))) ?></strong><br><small><?= e($row['control_number']) ?></small></td>
                <td><strong><?= e($beneficiaryName !== '' ? $beneficiaryName : '—') ?></strong></td>
                <td><strong><?= e($row['guardian'] ?: '—') ?></strong><?php if (!empty($row['contact_number'])): ?><br><small><?= e($row['contact_number']) ?></small><?php endif; ?></td>
                <td><strong><?= e($row['barangay'] ?: '—') ?></strong><br><small><?= e(trim(($row['municipality'] ?? '').($row['province'] ? ', '.$row['province'] : '')) ?: '—') ?></small></td>
                <td class="fams-amount-cell"><strong>₱<?= number_format((float)($row['bill_amount'] ?? 0), 2) ?></strong></td>
                <td class="fams-amount-cell"><strong>₱<?= number_format((float)$row['guarantee_amount'], 2) ?></strong></td>
                <td><strong><?= e($row['remarks'] ?: '—') ?></strong></td>
                <td>
                    <?php $isFinal = in_array(strtoupper((string)$row['status']), ['RELEASED','CANCELLED'], true); $canChangeThisStatus = can_update_status(); ?>
                    <?php if ($canChangeThisStatus): ?>
                        <form method="post" action="status_update.php" class="inline-status-form" data-status-form data-record-status="<?= e(strtoupper((string)$row['status'])) ?>" data-record-id="<?= (int)$row['id'] ?>" data-user-role="<?= e($user['role'] ?? '') ?>" data-submission-date="<?= e($row['date_submission']) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <select name="status" class="status-select <?= e(status_class($row['status'])) ?>" data-status-select aria-label="Change status for <?= e($row['control_number']) ?>">
                                <?php foreach (['WAITING','RELEASED','CANCELLED'] as $st): ?>
                                    <option value="<?= e($st) ?>" <?= $row['status'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="status-popover" data-status-popover hidden>
                                <div class="status-popover-title">Update <?= e($row['control_number']) ?></div>
                                <label>Release Date <span class="field-required" data-release-required>*</span></label>
                                <input type="date" name="release_date" value="<?= e((string)$row['release_date']) ?>" max="<?= e(date('Y-m-d')) ?>" data-release-date>
                                <small class="date-rule">Must be later than <?= e(date('M d, Y', strtotime($row['date_submission']))) ?> and not later than today.</small>
                                <label>Remarks <span class="field-required" data-remarks-required hidden>*</span></label>
                                <textarea name="remarks" rows="3" data-status-remarks placeholder="Add status remarks..."><?= e((string)$row['remarks']) ?></textarea>
                                <div class="status-popover-help" data-status-help></div>
                                <div class="status-popover-actions"><button type="button" class="ghost-btn status-cancel" data-status-cancel>Cancel</button><button type="submit" class="primary-btn">Save</button></div>
                            </div>
                        </form>
                    <?php else: ?>
                        <span class="status-badge <?= e(status_class($row['status'])) ?>"><?= e($row['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="no-print masterlist-action-cell"><div class="masterlist-action-links">
                    <?php if (can_edit_record() && (($user['role'] ?? '') === 'admin' || strtoupper((string)$row['status']) === 'WAITING')): ?><a class="table-action fams-icon-action fams-icon-action-edit" data-fams-popup data-popup-title="Edit MAIFIP FAMS Record" href="record_form.php?id=<?= (int)$row['id'] ?>" aria-label="Edit record" title="Edit record"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 16.5V20h3.5L19 8.5 15.5 5 4 16.5Z"/><path d="m13.8 6.7 3.5 3.5"/></svg></a><?php endif; ?>
                    <?php if (($user['role'] ?? '') !== 'admin' && can_raise_ticket() && !can_edit_existing_record($row)): ?><a class="table-action fams-icon-action fams-icon-action-ticket" data-fams-popup data-popup-title="Submit Revision Ticket" href="ticket_create.php?id=<?= (int)$row['id'] ?>&popup=1" aria-label="Create approval ticket" title="Create approval ticket"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7.5A2.5 2.5 0 0 0 6.5 10v4A2.5 2.5 0 0 0 4 16.5V19h16v-2.5a2.5 2.5 0 0 1 0-5v-4A2.5 2.5 0 0 0 17.5 5H4Z"/><path d="M12 7.5v2m0 3v2m0 3v.5"/></svg></a><?php endif; ?>
                    <?php if (($user['role'] ?? '') !== 'admin' && can_raise_ticket() && !can_edit_existing_record($row)): ?><a class="table-action table-action-danger fams-icon-action fams-icon-action-delete" data-fams-popup data-popup-title="Request Delete Entry Approval" href="delete_request.php?id=<?= (int)$row['id'] ?>&popup=1" aria-label="Request delete entry approval" title="Request delete entry approval"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg></a><?php endif; ?>
                    <?php if (($user['role'] ?? '') === 'admin' || can_edit_existing_record($row)): ?>
                        <form method="post" action="delete_record.php" class="masterlist-delete-form" onsubmit="return confirm('Delete <?= e($row['control_number']) ?> — <?= e(trim(($row['patient_first_name'] ?? '').' '.($row['patient_last_name'] ?? ''))) ?>? This moves the FAMS record to Trash. Linked workflow history is retained for audit purposes.');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button type="submit" class="table-action table-action-danger fams-icon-action fams-icon-action-delete" aria-label="Delete record" title="Delete record"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg></button>
                        </form>
                    <?php endif; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<div id="famsMasterlistPagination" class="fams-masterlist-pagination" aria-label="Masterlist pages">
<?php $paginationParams=['q'=>$q,'status'=>$status]; $paginationParams=array_filter($paginationParams,static fn($v)=>$v!==''); ?>
<div class="fams-pagination-summary">Showing <?=number_format($totalRecords ? $offset+1 : 0)?>–<?=number_format(min($offset+$perPage,$totalRecords))?> of <?=number_format($totalRecords)?> records</div><div class="fams-pagination-controls"><?php if($page>1): ?><a href="<?=e('records.php?'.http_build_query($paginationParams+['page'=>1]))?>" data-masterlist-page="1">« First</a><a href="<?=e('records.php?'.http_build_query($paginationParams+['page'=>$page-1]))?>" data-masterlist-page="<?=$page-1?>">‹ Previous</a><?php endif; ?><span>Page <?=$page?> of <?=$totalPages?></span><?php if($page<$totalPages): ?><a href="<?=e('records.php?'.http_build_query($paginationParams+['page'=>$page+1]))?>" data-masterlist-page="<?=$page+1?>">Next ›</a><a href="<?=e('records.php?'.http_build_query($paginationParams+['page'=>$totalPages]))?>" data-masterlist-page="<?=$totalPages?>">Last »</a><?php endif; ?></div></div>
</div>
<?php if ($isFragment): ?></div><?php else: require __DIR__ . '/partials/footer.php'; endif; ?>
