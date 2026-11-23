<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/unified.php';
if (!defined('FAMS_ALLOW_SWITCH') && !empty($_SESSION['fams_system']) && $_SESSION['fams_system'] !== 'aics') {
    $identity = (string)($_SESSION['fams_identity'] ?? '');
    $isLpgmaSession = (function_exists('fams_current_lpgma_user_id') && fams_current_lpgma_user_id() > 0)
        || in_array($identity, ['LPGMA','LPGMA_BRIDGED'], true);
    if ($isLpgmaSession) {
        $returnPage = basename((string)($_SERVER['PHP_SELF'] ?? 'dashboard.php'));
        if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $returnPage) || in_array($returnPage, ['switch.php','logout.php','login.php','index.php'], true)) $returnPage = 'dashboard.php';
        header('Location: switch.php?to=aics&return_page=' . rawurlencode($returnPage)); exit;
    }
    header('Location: ../index.php'); exit;
}
if(session_status()===PHP_SESSION_NONE){session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','path'=>'/']);session_start();}
const APP_NAME = 'SWAD Financial Assistance Monitoring System';
const APP_SHORT = 'SWAD FAMS';
const APP_VERSION = '0.0220';
const FAMS_VERSION = '0.0220';
require_once dirname(__DIR__) . '/core/database.php';
try {
    $pdo = lfams_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed. Check core/database.php and use the separately supplied consolidated database-backup archive if a fresh database import is required.');
}
require_once __DIR__.'/helpers.php';
require_once dirname(__DIR__).'/core/access_control.php';
require_once dirname(__DIR__).'/core/account_runtime.php';
fams_account_ensure_session_version_schema($pdo);
fams_ensure_usertype_schema($pdo);
// Keep the AICS user table compatible with the shared LPGMA identity bridge.
// Identity-bridge columns are required by account synchronization. Do not silently
// continue with a partially migrated identity table.
$identityColumns=[];
foreach($pdo->query("SHOW COLUMNS FROM aics_users")->fetchAll(PDO::FETCH_ASSOC) as $columnRow)$identityColumns[(string)$columnRow['Field']]=true;
if(!isset($identityColumns['lpgma_user_id']))$pdo->exec("ALTER TABLE aics_users ADD COLUMN lpgma_user_id INT UNSIGNED NULL AFTER office_id");
if(!isset($identityColumns['identity_source']))$pdo->exec("ALTER TABLE aics_users ADD COLUMN identity_source ENUM('LOCAL_AICS','LPGMA_BRIDGED') NOT NULL DEFAULT 'LOCAL_AICS' AFTER lpgma_user_id");
if(!isset($identityColumns['identity_synced_at']))$pdo->exec("ALTER TABLE aics_users ADD COLUMN identity_synced_at DATETIME NULL AFTER identity_source");

$pdo->exec("CREATE TABLE IF NOT EXISTS fams_deleted_records (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, system_key VARCHAR(16) NOT NULL, facility VARCHAR(32) NOT NULL DEFAULT '', original_id BIGINT UNSIGNED NOT NULL, control_number VARCHAR(40) NULL, record_payload LONGTEXT NOT NULL, deleted_by BIGINT UNSIGNED NULL, deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, restored_at DATETIME NULL, restored_by BIGINT UNSIGNED NULL, INDEX(system_key,facility,deleted_at), INDEX(original_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$aicsTicketTable=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_tickets'");$aicsTicketTable->execute();
if((int)$aicsTicketTable->fetchColumn()===1){
 $aicsTicketRecord=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_tickets' AND COLUMN_NAME='record_id'");$aicsTicketRecord->execute();
 if((int)$aicsTicketRecord->fetchColumn()===1){$aicsTicketSnapshot=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='aics_tickets' AND COLUMN_NAME='record_control_number'");$aicsTicketSnapshot->execute();if((int)$aicsTicketSnapshot->fetchColumn()===0)$pdo->exec("ALTER TABLE aics_tickets ADD COLUMN record_control_number VARCHAR(40) NULL AFTER record_id");}
}

$archiveHistoryColumn=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fams_deleted_records' AND COLUMN_NAME='history_payload'");
$archiveHistoryColumn->execute();
if((int)$archiveHistoryColumn->fetchColumn()===0)$pdo->exec("ALTER TABLE fams_deleted_records ADD COLUMN history_payload LONGTEXT NULL AFTER record_payload");
ensure_user_schema($pdo);
ensure_avatar_columns($pdo);
ensure_record_schema($pdo);
expire_waiting_records($pdo);
if (isset($_SESSION['user']['id']) && (($_SESSION['fams_system'] ?? '') === 'aics')) sync_aics_current_user_from_db($pdo);
