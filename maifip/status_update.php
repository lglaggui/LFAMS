<?php
require_once __DIR__ . '/config.php';
require_role(['admin','manager','encoder','social_worker','staff']);
$activeFacility=active_data_facility($pdo);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') { header('Allow: POST'); http_response_code(405); exit('Method not allowed.'); }
verify_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid record.'); }

$stmt = $pdo->prepare('SELECT id, control_number, date_submission, status, release_date, remarks, record_version FROM assistance_records WHERE id = ? AND facility = ? LIMIT 1');
$stmt->execute([$id,$activeFacility]);
$record = $stmt->fetch();
if (!can_update_status()) { http_response_code(403); exit('Your account does not have permission to update assistance status.'); }
if (!$record) { http_response_code(404); exit('Record not found in your assigned facility. Facility-level access protects beneficiary privacy and accountability. If the assignment appears incorrect, contact the LPGMA Site Administrator for review.'); }

$userRole = current_user()['role'] ?? '';
$oldStatus = strtoupper((string)$record['status']);

// Once finalized, only the LPGMA Admin / Site Admin can make any further status change.
if ($userRole !== 'admin' && $oldStatus !== 'WAITING') {
    flash('error', 'Released and cancelled records are locked. Submit a ticket to the LPGMA Admin / Site Admin for any correction.');
    redirect('records.php');
}

$status = strtoupper(trim($_POST['status'] ?? ''));
$releaseDate = trim($_POST['release_date'] ?? '');
$remarks = fams_uppercase_text($_POST['remarks'] ?? '');

if (!in_array($status, ['WAITING','RELEASED','CANCELLED'], true)) {
    flash('error', 'Invalid status selected.');
    redirect('records.php');
}

$submissionDate = (string)$record['date_submission'];
$today = date('Y-m-d');

if ($status === 'RELEASED') {
    if ($releaseDate === '') {
        flash('error', 'Release date is required when status is RELEASED.');
        redirect('records.php');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $releaseDate);
    $validDate = $dt && $dt->format('Y-m-d') === $releaseDate;
    if (!$validDate || $releaseDate < $submissionDate) {
        flash('error', 'Release date must be on or after the submission date.');
        redirect('records.php');
    }
    if ($releaseDate > $today) {
        flash('error', 'Release date cannot be later than today.');
        redirect('records.php');
    }
}

if ($status === 'CANCELLED') {
    if ($remarks === '') {
        flash('error', 'Remarks are required when status is CANCELLED. Please state the reason for cancellation.');
        redirect('records.php');
    }
    $releaseDate = null;
}

if ($status === 'WAITING') {
    $releaseDate = null;
    // A blank remark means keep the existing remark.
    $remarks = $remarks !== '' ? $remarks : (string)($record['remarks'] ?? '');
}

// On a finalization, use the submitted remarks when supplied; for RELEASED remarks are optional.
$finalRemarks = $remarks !== '' ? $remarks : ($record['remarks'] ?? null);
if ($status === 'CANCELLED') $finalRemarks = $remarks;

// Complete compatibility schema checks before opening the business transaction; MySQL DDL may implicitly commit.
if (function_exists('ensure_action_logs_schema')) ensure_action_logs_schema($pdo);
try {
fams_shared_transaction_retry($pdo, static function (PDO $pdo) use ($id, $activeFacility, $status, $releaseDate, $finalRemarks, $remarks, $record): void {
    // Revalidate capability and facility scope inside the transaction, not only before it.
    if (!can_update_status()) {
        throw new RuntimeException('Your account does not have permission to update assistance status.');
    }
    $lock = $pdo->prepare('SELECT id, facility, control_number, date_submission, status, record_version FROM assistance_records WHERE id=? AND facility=? LIMIT 1 FOR UPDATE');
    $lock->execute([$id, $activeFacility]);
    $lockedRow = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$lockedRow) {
        throw new RuntimeException('Record not found in your assigned facility.');
    }
    $lockedStatus = strtoupper((string)$lockedRow['status']);
    $lockedVersion = (int)($lockedRow['record_version'] ?? 1);
    if ($lockedStatus !== $oldStatus || $lockedVersion !== (int)($record['record_version'] ?? 1)) {
        throw new RuntimeException('This record was changed by another user. Reload the masterlist and review the latest status before trying again.');
    }
    $user = current_user() ?? [];
    if (($user['role'] ?? '') !== 'admin' && $lockedStatus !== 'WAITING') {
        throw new RuntimeException('Released and cancelled records are locked. Submit a ticket to the LPGMA Admin / Site Admin for any correction.');
    }

    $repository = new FamsMaifipRecordRepository($pdo);
    $saved = $repository->updateInFacilityAtVersion($id, $activeFacility, $lockedVersion, [
        'status' => $status,
        'release_date' => $releaseDate ?: null,
        'remarks' => $finalRemarks,
        'updated_by' => (int)($user['id'] ?? 0),
    ]);
    if (!$saved) {
        throw new RuntimeException('The record status changed while the update was being saved. Please reload and try again.');
    }

    if ($lockedStatus !== $status) {
        fams_status_history_write($pdo,'maifip',$id,$lockedStatus ?: null,$status,(int)($user['id'] ?? 0),$remarks !== '' ? $remarks : null);
    }
    // Audit and history remain atomic with the record update.
    fams_audit_write_critical($pdo,'maifip','UPDATE_STATUS', "Changed {$lockedRow['control_number']} status from {$lockedStatus} to {$status}." . ($remarks !== '' ? ' Remarks updated.' : ''), $id);
});
} catch (Throwable $error) {
    flash('error', fams_shared_user_error($error,'Unable to update the record status. Please try again.'));
    redirect('records.php');
}
flash('success', 'Status updated successfully.');
redirect('records.php');
