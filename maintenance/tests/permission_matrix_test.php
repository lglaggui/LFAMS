<?php
/**
 * FAMS permission-matrix regression tests.
 * Run from the htdocs root: php maintenance/tests/permission_matrix_test.php
 * These tests exercise the deterministic built-in policy and fail-closed fallback
 * without connecting to or modifying a production database.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/access_control.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS: {$message}\n"; }
    else { $failed++; fwrite(STDERR, "FAIL: {$message}\n"); }
};

$catalog = fams_permission_catalog();
$assert(count($catalog) === 15, 'permission catalog has the expected 15 capabilities');
foreach (['AICS','MAIFIP'] as $system) {
    $admin = fams_default_type_permissions($system, 'admin');
    $assert(count($admin) === count($catalog) && !in_array(false, $admin, true), "{$system} administrator baseline grants the complete catalog");
    $manager = fams_default_type_permissions($system, 'manager');
    $assert(!empty($manager['view_records']) && empty($manager['create_records']) && empty($manager['edit_records']) && empty($manager['manage_users']), "{$system} manager is read-only for records and account administration");
    $social = fams_default_type_permissions($system, 'social_worker');
    $assert(!empty($social['view_records']) && !empty($social['update_status']) && !empty($social['submit_tickets']) && empty($social['view_dashboard']) && empty($social['manage_tickets']), "{$system} social worker retains limited record/status/ticket access");
}
$aicsStaff = fams_default_type_permissions('AICS', 'staff');
$maifipEncoder = fams_default_type_permissions('MAIFIP', 'encoder');
$assert(!empty($aicsStaff['create_records']) && empty($aicsStaff['manage_users']), 'AICS staff can create records but cannot administer accounts');
$assert(!empty($maifipEncoder['create_records']) && empty($maifipEncoder['manage_users']), 'MAIFIP encoder can create records but cannot administer accounts');
$assert(!empty(fams_default_type_permissions('MAIFIP','staff')['create_records']), 'MAIFIP staff has its own create-record baseline');
$assert(!empty(fams_default_type_permissions('AICS','encoder')['view_records']) && empty(fams_default_type_permissions('AICS','encoder')['create_records']), 'AICS does not inherit MAIFIP encoder privileges');

// Exercise the no-database fallback explicitly. This is a safe CLI-only test context.
$GLOBALS['pdo'] = null;
$_SESSION = ['user' => ['id'=>1, 'role'=>'staff', 'user_type_key'=>'staff']];
$assert(fams_current_type_allows('AICS','create_records'), 'known AICS staff can create records in fallback mode');
$assert(!fams_current_type_allows('AICS','manage_users'), 'known AICS staff cannot manage accounts in fallback mode');
$_SESSION = ['user' => ['id'=>2, 'role'=>'manager', 'user_type_key'=>'manager']];
$assert(!fams_current_type_allows('MAIFIP','edit_records'), 'MAIFIP manager cannot edit records in fallback mode');
$_SESSION = ['user' => ['id'=>3, 'role'=>'staff', 'user_type_key'=>'custom_unknown']];
$assert(!fams_current_type_allows('AICS','view_records'), 'unknown custom user type fails closed without registry access');
$_SESSION = ['user' => ['id'=>4, 'role'=>'admin', 'user_type_key'=>'admin']];
$assert(!fams_current_type_allows('AICS','not_a_real_permission'), 'administrator cannot authorize an unknown permission key');
$assert(!fams_current_type_allows('UNKNOWN','view_records'), 'unknown workspace fails closed');
$_SESSION = ['user' => ['id'=>5, 'role'=>'admin', 'user_type_key'=>'custom_admin_like']];
$assert(!fams_current_type_allows('AICS','manage_users'), 'custom role cannot inherit administrator access from a session base-role label');
$_SESSION = ['user' => ['id'=>6, 'role'=>'admin', 'user_type_key'=>'admin']];
$assert(fams_current_type_allows('AICS','manage_users'), 'built-in AICS administrator retains fallback privileges');
$_SESSION = ['user' => ['id'=>7, 'role'=>'encoder', 'user_type_key'=>'encoder']];
$assert(!fams_current_type_allows('AICS','create_records'), 'MAIFIP encoder privileges do not transfer into AICS');

// Static security invariants for database-backed execution (PDO drivers are supplied by deployment).
$aclSource=file_get_contents(__DIR__.'/../../core/access_control.php');
$aicsHelper=file_get_contents(__DIR__.'/../../aics/helpers.php');
$maifipHelper=file_get_contents(__DIR__.'/../../maifip/helpers.php');
$maifipTickets=file_get_contents(__DIR__.'/../../maifip/tickets.php');
$aicsTickets=file_get_contents(__DIR__.'/../../aics/tickets.php');
$offlineSync=file_get_contents(__DIR__.'/../../maifip/offline_sync.php');
$offlineResolve=file_get_contents(__DIR__.'/../../maifip/offline_resolve.php');
$deleteRequest=file_get_contents(__DIR__.'/../../maifip/delete_request.php');
$aicsTrash=file_get_contents(__DIR__.'/../../aics/trash.php');
$maifipTrash=file_get_contents(__DIR__.'/../../maifip/trash.php');
$aicsSheetSave=file_get_contents(__DIR__.'/../../aics/sheet_save.php');
$maifipOfflineChanges=file_get_contents(__DIR__.'/../../maifip/offline_changes.php');
$maifipMedia=file_get_contents(__DIR__.'/../../maifip/media.php');
$maifipTicketReview=file_get_contents(__DIR__.'/../../maifip/ticket_review.php');
$aicsRevisionTicket=file_get_contents(__DIR__.'/../../aics/revision_ticket.php');
$aicsOfficeReassignment=file_get_contents(__DIR__.'/../../aics/office_reassignment_ticket.php');
$aicsDeleteRequest=file_get_contents(__DIR__.'/../../aics/delete_ticket_request.php');
$maifipDeleteRequest=file_get_contents(__DIR__.'/../../maifip/delete_request.php');
$maifipTicketCreate=file_get_contents(__DIR__.'/../../maifip/ticket_create.php');
$maifipTicketEdit=file_get_contents(__DIR__.'/../../maifip/ticket_edit.php');
$aicsRecordForm=file_get_contents(__DIR__.'/../../aics/record_form.php');
$maifipRecordForm=file_get_contents(__DIR__.'/../../maifip/record_form.php');
$aicsUsers=file_get_contents(__DIR__.'/../../aics/users.php');
$maifipUsers=file_get_contents(__DIR__.'/../../maifip/users.php');
$aicsImport=file_get_contents(__DIR__.'/../../aics/import_sheet.php');
$maifipImport=file_get_contents(__DIR__.'/../../maifip/import_sheet.php');
$assert(str_contains($aclSource, 'WHERE system_key=? AND role_key=? LIMIT 1'), 'database user-type lookup is strictly workspace-scoped');
$assert(str_contains($aclSource, '(int)($d[\'is_active\']??0)!==1'), 'inactive database user types are rejected');
$assert(str_contains($aclSource, 'if (!$d || (string)($d[\'system_key\']??\'\')!==$system'), 'missing and cross-workspace database types fail closed');
$assert(str_contains($aicsHelper, 'fams_shared_invalidate_session(\'AICS identity verification failed.'), 'AICS account-refresh failure invalidates stale session');
$assert(str_contains($maifipHelper, 'fams_shared_invalidate_session(\'MAIFIP identity verification failed.'), 'MAIFIP account-refresh failure invalidates stale session');
$assert(str_contains($maifipTickets, "fams_current_type_allows('MAIFIP','manage_tickets')"), 'MAIFIP ticket decisions require server-side manage_tickets capability');
$assert(str_contains($aicsTickets, "fams_current_type_allows('AICS','manage_tickets')"), 'AICS ticket decisions require server-side manage_tickets capability');
$assert(str_contains($offlineSync, "fams_current_type_allows('MAIFIP','create_records')") && str_contains($offlineSync, "fams_current_type_allows('MAIFIP','edit_records')"), 'offline sync separates create and edit capabilities');
$assert(str_contains($offlineResolve, "fams_current_type_allows('MAIFIP','edit_records')"), 'offline conflict application requires edit capability');
$assert(str_contains($deleteRequest, "fams_current_type_allows('MAIFIP','submit_tickets')"), 'MAIFIP deletion-ticket endpoint requires submit_tickets capability');
$assert(str_contains($aicsTrash, "fams_current_type_allows('AICS','edit_records')"), 'AICS restoration requires record-edit capability and administrator role');
$assert(str_contains($maifipTrash, "fams_current_type_allows('MAIFIP','edit_records')"), 'MAIFIP restoration requires record-edit capability and administrator role');
$assert(str_contains($aicsSheetSave, 'require_login();') && str_contains($aicsSheetSave, "fams_current_type_allows('AICS','edit_records')"), 'AICS editable-sheet API requires login and record-edit capability');
$assert(str_contains($maifipOfflineChanges, "fams_current_type_allows('MAIFIP','view_records')") && str_contains($maifipOfflineChanges, 'a.facility=?'), 'offline conflict list is permission- and facility-scoped');
$assert(str_contains($maifipMedia, 'facility_logo_[a-f0-9]{20}'), 'public media endpoint accepts only whitelisted public media keys');
$assert(str_contains($maifipTicketReview, 'status=\'OPEN\' FOR UPDATE') && str_contains($maifipTicketReview, 'record_version=record_version+1'), 'MAIFIP ticket review locks pending tickets and advances record versions');
$assert(str_contains($aicsRevisionTicket, '_base_record_version') && str_contains($aicsTickets, 'record_version=?'), 'AICS revision tickets are bound to the record version seen at submission');
$assert(str_contains($aicsOfficeReassignment, '_base_record_version'), 'AICS office-reassignment proposals include a base record version');
$assert(str_contains($aicsDeleteRequest, '_base_record_version') && str_contains($aicsTickets, 'record has changed since this delete request'), 'AICS delete requests are version-bound before approval');
$assert(str_contains($maifipDeleteRequest, '_base_record_version'), 'MAIFIP standalone delete requests include a base record version');
$assert(str_contains($maifipTicketCreate, '_base_record_version') && str_contains($maifipTicketReview, '$baseVersion<1'), 'MAIFIP revision and deletion approvals reject stale or legacy proposals without a base version');
$assert(str_contains($maifipTicketEdit, 'fams_shared_transaction($pdo') && str_contains($maifipTicketEdit, 'FOR UPDATE'), 'MAIFIP ticket edits re-lock ticket and record and commit with audit logging');
$assert(str_contains($aicsRecordForm, 'record_version=record_version+1') && str_contains($aicsRecordForm, 'record_version=?'), 'AICS record editing uses optimistic record-version checks');
$assert(str_contains($maifipRecordForm, 'record_version=record_version+1') && str_contains($maifipRecordForm, 'record_version=?'), 'MAIFIP record editing uses optimistic record-version checks');
$assert(str_contains($aicsRecordForm, "fams_current_type_allows('AICS', \$isEdit ? 'edit_records' : 'create_records')") && str_contains($aicsRecordForm, 'SELECT * FROM aics_assistance_records WHERE id=? LIMIT 1 FOR UPDATE') && str_contains($aicsRecordForm, 'can_edit_record($lockedRecord)'), 'AICS record mutations recheck capability, office scope, and workflow state under a row lock');
$assert(str_contains($maifipRecordForm, "fams_current_type_allows('MAIFIP', \$editing ? 'edit_records' : 'create_records')") && str_contains($maifipRecordForm, 'SELECT * FROM assistance_records WHERE id=? AND facility=? LIMIT 1 FOR UPDATE') && str_contains($maifipRecordForm, 'can_edit_existing_record($lockedRecord)'), 'MAIFIP record mutations recheck capability, facility scope, and workflow state under a row lock');
$assert(str_contains($aicsRecordForm, "header('Allow: GET, POST')") && str_contains($maifipRecordForm, "header('Allow: GET, POST')"), 'AICS and MAIFIP record forms reject unsupported HTTP methods');
$assert(str_contains($aicsRecordForm, 'SWAD office users cannot reassign a record outside their assigned office.'), 'AICS non-LPGMA users cannot move records outside their assigned SWAD office');
$assert(str_contains($maifipRecordForm, 'AND (status=? OR ?=1)'), 'MAIFIP edit SQL independently prevents non-admin updates to non-WAITING records');
$assert(str_contains($aicsUsers, 'fams_shared_transaction($pdo') && str_contains($maifipUsers, 'fams_shared_transaction($pdo'), 'AICS and MAIFIP account administration use the shared transaction service');
$assert(str_contains($aicsImport, 'can_edit_record($existing)') && str_contains($aicsImport, 'finalized entries cannot be updated'), 'AICS import enforces office ownership and WAITING-only edits for non-admins');
$assert(str_contains($maifipImport, 'can_edit_existing_record($existing)'), 'MAIFIP import enforces facility and record-status ownership before updates');

printf("\nPermission matrix tests: %d passed, %d failed.\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
