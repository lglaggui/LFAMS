<?php
declare(strict_types=1);

require_once __DIR__ . '/app_version.php';
require_once dirname(__DIR__) . '/core/unified.php';
if (!defined('FAMS_ALLOW_SWITCH') && basename((string)($_SERVER['PHP_SELF'] ?? '')) !== 'login.php' && !empty($_SESSION['fams_system']) && $_SESSION['fams_system'] !== 'maifip') {
    $identity = (string)($_SESSION['fams_identity'] ?? '');
    $isLpgmaSession = (function_exists('fams_current_lpgma_user_id') && fams_current_lpgma_user_id() > 0)
        || in_array($identity, ['LPGMA','LPGMA_BRIDGED'], true);
    if ($isLpgmaSession) {
        $returnPage = basename((string)($_SERVER['PHP_SELF'] ?? 'dashboard.php'));
        if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $returnPage) || in_array($returnPage, ['switch.php','logout.php','login.php','index.php'], true)) $returnPage = 'dashboard.php';
        header('Location: ../aics/switch.php?to=maifip&return_page=' . rawurlencode($returnPage)); exit;
    }
    header('Location: ../index.php'); exit;
}

// Both MAIFIP and AICS/SWAD use this one shared LFAMS database connection.
require_once dirname(__DIR__) . '/core/database.php';
try {
    $pdo = lfams_pdo();
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check core/database.php and your hosting database credentials.');
}

require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/core/access_control.php';
require_once dirname(__DIR__).'/core/account_runtime.php';
fams_account_ensure_session_version_schema($pdo);
fams_ensure_usertype_schema($pdo);
require_once __DIR__ . '/media_upgrade.php';
ensure_media_schema($pdo);
ensure_facility_schema($pdo);
ensure_monthly_report_settings($pdo);
ensure_facility_data_schema($pdo);
ensure_action_logs_schema($pdo);
ensure_user_avatar_schema($pdo);
// Keep the session identity synchronized with the authoritative users table on every request.
// This prevents partial navigation, stale session fields, or an account edit from making the
// active user appear under another name/role/facility.
if (isset($_SESSION['user']['id'])) {
    sync_current_user_from_db($pdo);
}
if(isset($_POST['fams_facility_switch'])){
    $token=(string)($_POST['csrf_token']??'');
    if(!hash_equals($_SESSION['csrf_token']??'', $token)){http_response_code(419);exit('Invalid or expired request token.');}
    $requested=trim((string)($_POST['fams_facility']??''));
    if(can_switch_facility() && array_key_exists($requested, facility_options())) $_SESSION['fams_facility']=$requested;
    $return=(string)($_POST['return_to']??'dashboard.php');
    if(!preg_match('/^[A-Za-z0-9_?=&.\-]+$/',$return)) $return='dashboard.php';
    header('Location: '.$return); exit;
}

// FAMS 5.9.8: ensure the read-only LPGMA Manager role exists.
$pdo->exec("CREATE TABLE IF NOT EXISTS fams_deleted_records (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, system_key VARCHAR(16) NOT NULL, facility VARCHAR(32) NOT NULL DEFAULT '', original_id BIGINT UNSIGNED NOT NULL, control_number VARCHAR(40) NULL, record_payload LONGTEXT NOT NULL, deleted_by BIGINT UNSIGNED NULL, deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, restored_at DATETIME NULL, restored_by BIGINT UNSIGNED NULL, INDEX(system_key,facility,deleted_at), INDEX(original_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$maifipTicketTable=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='revision_tickets'");$maifipTicketTable->execute();
if((int)$maifipTicketTable->fetchColumn()===1){
 foreach(['record_control_number'=>'VARCHAR(40) NULL AFTER record_id','record_facility'=>'VARCHAR(100) NULL AFTER record_control_number'] as $column=>$definition){$ticketCol=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='revision_tickets' AND COLUMN_NAME=?");$ticketCol->execute([$column]);if((int)$ticketCol->fetchColumn()===0)$pdo->exec("ALTER TABLE revision_tickets ADD COLUMN {$column} {$definition}");}
 $pdo->exec("UPDATE revision_tickets t JOIN assistance_records a ON a.id=t.record_id SET t.record_control_number=COALESCE(t.record_control_number,a.control_number),t.record_facility=COALESCE(t.record_facility,a.facility) WHERE t.record_id IS NOT NULL AND (t.record_control_number IS NULL OR t.record_facility IS NULL)");
}

$archiveHistoryColumn=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fams_deleted_records' AND COLUMN_NAME='history_payload'");
$archiveHistoryColumn->execute();
if((int)$archiveHistoryColumn->fetchColumn()===0)$pdo->exec("ALTER TABLE fams_deleted_records ADD COLUMN history_payload LONGTEXT NULL AFTER record_payload");
ensure_user_role_schema($pdo);

// FAMS 2.6: record revisioning and offline synchronization schema.
ensure_record_sync_schema($pdo);
