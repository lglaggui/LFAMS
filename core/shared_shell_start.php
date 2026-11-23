<?php
/** Shared LFAMS document and viewport. Workspace headers prepare data then include this renderer. */
require_once __DIR__ . '/shell_config.php';
$famsActiveSystem = in_array(($famsActiveSystem ?? ''), ['maifip','aics'], true) ? $famsActiveSystem : 'maifip';
$famsShell = fams_shell_config($famsActiveSystem);
$famsShellTitle = (string)($pageTitle ?? 'Financial Assistance Monitoring System');
$famsShellIsPopup = !empty($isPopupPage) || (isset($_GET['popup']) && (string)$_GET['popup'] === '1');
$famsShellBodyClass = 'app-body' . ($famsActiveSystem === 'aics' ? ' aics-app' : '') . ($famsShellIsPopup ? ' fams-popup-page' : '');
$famsShellAccent = $famsActiveSystem === 'maifip' && function_exists('facility_accent') ? facility_accent($currentFacility ?? '') : '#0b5cab';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($famsShellTitle)?> | <?=e($famsShell['title'])?></title>
<meta name="theme-color" content="#153f67">
<?php if ($famsActiveSystem === 'maifip'): ?><link rel="manifest" href="manifest.webmanifest"><?php endif; ?>
<link rel="stylesheet" href="assets/app.css?v=<?=e(APP_VERSION)?>">
<?php if ($famsActiveSystem === 'aics' && in_array(basename($_SERVER['PHP_SELF'] ?? ''), ['dashboard.php','chat.php'], true)): ?><link rel="stylesheet" href="../maifip/assets/app.css?v=<?=e(APP_VERSION)?>" data-shared-workspace-styles="1"><?php endif; ?>
<link rel="stylesheet" href="../core/uniform.css?v=<?=e(APP_VERSION)?>">
<link rel="stylesheet" href="../core/shared-components.css?v=<?=e(APP_VERSION)?>">
<link rel="stylesheet" href="../core/shared-shell.css?v=<?=e(APP_VERSION)?>">

<link rel="stylesheet" href="../core/shared-dashboard.css?v=<?=e(APP_VERSION)?>">
<link rel="stylesheet" href="../core/shared-ux.css?v=<?=e(APP_VERSION)?>">
<link rel="stylesheet" href="../core/shared-ui-consistency.css?v=<?=e(APP_VERSION)?>">
<?php if (!$famsShellIsPopup): ?><script src="../core/header-fit.js?v=<?=e(APP_VERSION)?>" defer></script><?php endif; ?>
</head>
<body class="<?=e($famsShellBodyClass)?>" style="--facility-accent: <?=e($famsShellAccent)?>;">
<?php
$famsShellIdentityForState = function_exists('fams_shared_identity') ? fams_shared_identity($user ?? [], $famsActiveSystem) : ($user ?? []);
$famsShellStateSeed = (string)($famsShellIdentityForState['id'] ?? '') . '|' . (string)($famsShellIdentityForState['username'] ?? '') . '|' . (string)($famsShellIdentityForState['_fams_identity_source'] ?? 'LOCAL');
$famsShellStateUserKey = hash('sha256', $famsShellStateSeed);
?>
<div class="app-layout fams-shared-layout<?= $famsActiveSystem === 'aics' ? ' fams-doh-mirror' : '' ?>" data-lfams-system="<?=e($famsActiveSystem)?>" data-fams-user-key="<?=e($famsShellStateUserKey)?>">
<?php if (!$famsShellIsPopup): require __DIR__ . '/shared_sidebar.php'; ?>
<button class="sidebar-mobile-backdrop" id="sidebarMobileBackdrop" type="button" aria-label="Close navigation menu" tabindex="-1"></button><?php endif; ?>
<main class="main-content" id="lfamsMainContent">
<?php if (!$famsShellIsPopup):
$famsHeaderAllotment = (float)($famsActiveSystem === 'aics' ? ($headerAllotment ?? 0) : ($totalAllotment ?? 0));
$famsHeaderDisbursed = (float)($famsActiveSystem === 'aics' ? ($headerDisbursed ?? 0) : ($disbursedAssistance ?? 0));
$famsHeaderBalance = (float)($famsActiveSystem === 'aics' ? ($headerBalance ?? ($famsHeaderAllotment-$famsHeaderDisbursed)) : ($remainingBalance ?? ($famsHeaderAllotment-$famsHeaderDisbursed)));
$famsHeaderUtilization = (float)($famsActiveSystem === 'aics' ? ($headerUtilization ?? 0) : ($utilizationRate ?? 0));
require __DIR__ . '/shared_topbar.php';
?>
<?php if (!empty($flash)): ?>
<div class="fams-flash-toast <?=($flash['type'] ?? '') === 'success' ? 'fams-flash-success' : 'fams-flash-error'?>" id="famsFlashToast" role="alert" aria-live="assertive">
<span class="fams-flash-icon" aria-hidden="true"><?=($flash['type'] ?? '') === 'success' ? '✓' : '!'?></span>
<span class="fams-flash-message"><?=e($flash['message'] ?? '')?></span>
<button type="button" class="fams-flash-close" aria-label="Close notification" onclick="this.closest('.fams-flash-toast').remove()">×</button>
</div>
<?php endif; endif; ?>
<div id="pageView" class="page-view<?= $famsActiveSystem === 'aics' ? ' content' : '' ?>" data-page-view>
