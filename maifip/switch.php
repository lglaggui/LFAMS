<?php
declare(strict_types=1);
define('FAMS_ALLOW_SWITCH', true);
require_once __DIR__ . '/config.php';

$role = (string)($_SESSION['user']['role'] ?? '');
$lpgmaId = function_exists('fams_current_lpgma_user_id') ? fams_current_lpgma_user_id() : 0;
$isLpgma = function_exists('fams_is_lpgma_role') && fams_is_lpgma_role($role);
if (!$isLpgma && $lpgmaId <= 0 && !in_array((string)($_SESSION['fams_identity'] ?? ''), ['LPGMA','LPGMA_BRIDGED'], true)) {
    header('Location: ../index.php'); exit;
}

$target = (string)($_GET['to'] ?? 'maifip');
if ($target === 'aics') {
    header('Location: ../aics/switch.php?to=aics' . (!empty($_GET['return_page']) ? '&return_page=' . rawurlencode(basename((string)$_GET['return_page'])) : '')); exit;
}
if ($target === 'maifip') {
    // The portal selector intentionally keeps the session in "portal" until a
    // workspace is selected. Delegate the actual identity restoration to the
    // shared switch handler, which validates the central LPGMA account and
    // initializes the MAIFIP workspace session before redirecting.
    header('Location: ../aics/switch.php?to=maifip' . (!empty($_GET['return_page']) ? '&return_page=' . rawurlencode(basename((string)$_GET['return_page'])) : '')); exit;
}
header('Location: ../index.php'); exit;
