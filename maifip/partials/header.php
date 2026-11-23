<?php
$flash = get_flash();
$user = current_user();
$currentPage = basename($_SERVER['PHP_SELF']);
$currentFacility = selected_facility($pdo);
$currentFacilityInfo = facility_info($currentFacility);
$isPopupPage = isset($_GET['popup']) && $_GET['popup'] === '1';
// Keep the MAIFIP fund summary in the shared header, matching the SWAD AICS header.
// Figures are scoped to the selected facility and use the same reconciled reporting
// summary used by Fund Management.
$totalAllotment = 0.0;
$disbursedAssistance = 0.0;
try {
    $fundPosition = maifip_fund_ledger_position($pdo);
    $totalAllotment = (float)$fundPosition['available'];
    $disbursedAssistance = (float)$fundPosition['disbursed'];
} catch (Throwable $e) {
    error_log('MAIFIP shared header fund summary failed for facility ' . $currentFacility . ': ' . $e->getMessage());
}
$remainingBalance = $totalAllotment - $disbursedAssistance;
$utilizationRate = $totalAllotment > 0 ? max(0, min(100, ($disbursedAssistance / $totalAllotment) * 100)) : 0.0;
// Preserve existing notifications schema compatibility; notifications are used by the shared header.
$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,link VARCHAR(255) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_user_read(user_id,is_read),INDEX idx_notif_created(created_at)) ENGINE=InnoDB");
$famsActiveSystem = 'maifip';
require dirname(__DIR__,2) . '/core/shared_shell_start.php';
