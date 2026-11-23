<?php
require_login();
$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$flash = get_flash();
$currentPage = basename($_SERVER['PHP_SELF']);
$isPopupPage = isset($_GET['popup']) && $_GET['popup'] === '1';
// The AICS header reads the normalized Region II fund ledger.
require_once dirname(__DIR__) . '/fund_ledger.php';
$headerAllotment = 0.0;
$headerDisbursed = 0.0;
try {
    $fundPosition = aics_fund_ledger_position($pdo, date('Y-m-d', strtotime('tomorrow')));
    $headerAllotment = (float)$fundPosition['available'];
    $headerDisbursed = (float)$fundPosition['disbursed'];
} catch (Throwable $e) {
    // Keep the workspace renderable if the fund tables are unavailable during migration.
}
$headerBalance = $headerAllotment - $headerDisbursed;
$headerUtilization = $headerAllotment > 0 ? max(0, min(100, ($headerDisbursed / $headerAllotment) * 100)) : 0;
$famsActiveSystem = 'aics';
require dirname(__DIR__,2) . '/core/shared_shell_start.php';
