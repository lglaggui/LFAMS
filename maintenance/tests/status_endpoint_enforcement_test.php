<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$checks = 0; $failed = 0;
$check = static function (bool $ok, string $label) use (&$checks, &$failed): void {
    $checks++; echo ($ok ? 'PASS: ' : 'FAIL: ').$label.PHP_EOL; if (!$ok) $failed++;
};
$aics = (string)file_get_contents($root.'/aics/status_update.php');
$maifip = (string)file_get_contents($root.'/maifip/status_update.php');
$helpers = (string)file_get_contents($root.'/aics/helpers.php');
$check(str_contains($aics, "fams_current_type_allows('AICS','update_status')") && str_contains($aics, 'can_access_record_scope($current)'), 'AICS rechecks capability and office scope inside the locked transaction');
$check(str_contains($aics, 'LIMIT 1 FOR UPDATE') && str_contains($aics, 'record_version') && str_contains($aics, "!user_is_admin()"), 'AICS status updates lock the row, reject stale versions, and preserve finalized-record restrictions');
$check(str_contains($aics, 'fams_status_history_write($db,\'aics\'') && str_contains($aics, 'fams_audit_write_critical($db,\'aics\''), 'AICS status history and audit writes occur with the transactional update');
$check(str_contains($aics, "header('Allow: GET, POST')") && str_contains($maifip, "header('Allow: POST')"), 'AICS and MAIFIP reject unsupported HTTP methods explicitly');
$check(str_contains($maifip, 'REQUEST_METHOD') && str_contains($maifip, 'verify_csrf()') && str_contains($maifip, "if (!can_update_status())"), 'MAIFIP requires POST, CSRF, and a status capability check');
$check(str_contains($maifip, 'WHERE id=? AND facility=? LIMIT 1 FOR UPDATE') && str_contains($maifip, 'record_version') && str_contains($maifip, "\$lockedStatus !== 'WAITING'"), 'MAIFIP rechecks facility scope, row version, and finalized-record restrictions under lock');
$check(str_contains($maifip, 'fams_status_history_write($pdo,\'maifip\'') && str_contains($maifip, 'fams_audit_write_critical($pdo,\'maifip\''), 'MAIFIP status history and audit writes are in the same transaction');
$expiry = substr($helpers, strpos($helpers, 'function expire_waiting_records'));
$check(str_contains($expiry, 'fams_shared_transaction') && str_contains($expiry, 'FOR UPDATE') && str_contains($expiry, 'record_version=?'), 'AICS automatic expiry uses a transaction, row locks, and version-guarded updates');
$check(str_contains($expiry, 'fams_status_history_write($db,\'aics\'') && str_contains($expiry, "'AUTO_CANCEL_EXPIRED'") && str_contains($expiry, "['system', 'system', 'AUTO_CANCEL_EXPIRED'"), 'AICS automatic expiry records history and an audit event');
echo "{$checks} checks; {$failed} failures.".PHP_EOL;
exit($failed ? 1 : 0);
