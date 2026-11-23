<?php
/** One shared LFAMS header. The active workspace supplies labels, facility and fund values. */
require_once __DIR__ . '/shell_config.php';
$__shellSystem = (string)($famsActiveSystem ?? 'maifip');
$__headerIdentity = function_exists('fams_shared_identity') ? fams_shared_identity($user, $__shellSystem) : $user;
$__headerDisplayRole = (($__headerIdentity['_fams_identity_source'] ?? '') === 'LPGMA' && function_exists('fams_shared_role_label')) ? fams_shared_role_label((string)($__headerIdentity['role'] ?? '')) : (!empty($user['user_type_label']) ? (string)$user['user_type_label'] : (function_exists('role_label') ? role_label((string)($user['role'] ?? '')) : (string)($user['role'] ?? ''))); 
$__shellConfig = function_exists('fams_shell_config') ? fams_shell_config($__shellSystem) : ['eyebrow'=>$__shellSystem==='aics'?'DSWD REGION II • AICS':'DOH REGION II • MAIFIP'];
$__shellAccent = $__shellSystem === 'maifip' && isset($currentFacility) && function_exists('facility_accent') ? facility_accent($currentFacility) : '#0b5cab';
$__shellFacilityCanSwitch = $__shellSystem === 'maifip' && function_exists('can_switch_facility') && can_switch_facility();
$__shellAllotment = (float)($famsHeaderAllotment ?? 0);
$__shellDisbursed = (float)($famsHeaderDisbursed ?? 0);
$__shellBalance = (float)($famsHeaderBalance ?? ($__shellAllotment - $__shellDisbursed));
$__shellUtilization = (float)($famsHeaderUtilization ?? ($__shellAllotment > 0 ? min(100, max(0, $__shellDisbursed / $__shellAllotment * 100)) : 0));
?>
<header class="topbar facility-theme-dynamic fams-branded-topbar<?= $currentPage === 'records.php' ? ' records-compact' : '' ?>" style="--facility-accent: <?=e($__shellAccent)?>;">
  <div class="topbar-left">
    <button class="sidebar-mobile-toggle mobile-menu-btn" id="sidebarMobileToggle" type="button" aria-controls="famsSidebar" aria-expanded="false" aria-label="Open navigation menu" title="Open menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"></path></svg></button>
    <div class="topbar-title fams-header-title"><span class="top-kicker"><strong class="top-office-identity"><span class="header-full-label"><?=e($__shellConfig['eyebrow'])?></span><span class="header-short-label"><?=e($__shellSystem==='aics'?'AICS':'MAIFIP')?></span></strong><?php if($__shellFacilityCanSwitch && isset($currentFacilityInfo)): ?> <span class="top-context-separator">•</span> <strong class="top-selected-facility"><span class="header-full-label"><?=e($currentFacilityInfo['short'].' — '.$currentFacilityInfo['name'])?></span><span class="header-short-label"><?=e($currentFacilityInfo['short'])?></span></strong><?php endif; ?></span></div>
  </div>
  <?php if (($user['role'] ?? '') !== 'social_worker'): ?>
  <section class="header-fund-summary" aria-label="Fund Summary"><div class="header-fund-summary-row header-fund-summary-heading"><span>Fund Summary</span></div><div class="header-fund-cards">
    <div class="header-fund-card"><span><span class="header-full-label">Total Allotment</span><span class="header-short-label">Allotment</span></span><strong data-amount="<?=e((string)$__shellAllotment)?>">₱<?=number_format($__shellAllotment,2)?></strong></div>
    <div class="header-fund-card"><span><span class="header-full-label">Disbursed Assistance</span><span class="header-short-label">Disbursed</span></span><strong data-amount="<?=e((string)$__shellDisbursed)?>">₱<?=number_format($__shellDisbursed,2)?></strong></div>
    <div class="header-fund-card header-fund-card-balance"><span><span class="header-full-label">Remaining Fund</span><span class="header-short-label">Remaining</span></span><strong data-amount="<?=e((string)$__shellBalance)?>">₱<?=number_format($__shellBalance,2)?></strong></div>
  </div><div class="header-fund-utilization"><span class="header-fund-utilization-label"><span class="header-full-label">Utilization Rate</span><span class="header-short-label">Utilization</span></span><div class="header-fund-utilization-track" aria-hidden="true"><span style="width:<?=number_format($__shellUtilization,2,'.','')?>%"></span></div><strong class="header-fund-utilization-percent"><?=number_format($__shellUtilization,1)?>%</strong></div></section>
  <?php endif; ?>
  <div class="topbar-right">
    <?php if($__shellSystem==='maifip' && function_exists('can_switch_facility') && !can_switch_facility() && isset($currentFacilityInfo)): ?><div class="facility-switcher" aria-label="Current facility"><div class="facility-current-badge"><span>Facility</span><strong><?=e($currentFacilityInfo['short'])?></strong></div></div><?php endif; ?>
    <button class="notification-bell" id="notificationBell" data-csrf="<?=e(csrf_token())?>" type="button" aria-label="Notifications" title="Notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg><span id="notificationBadge" class="notification-badge" hidden>0</span></button>
    <div class="notification-popover" id="notificationPopover" hidden><div class="notification-head"><strong>Notifications</strong><div class="notification-head-actions"><button type="button" id="notificationReadAll">Mark all read</button><button type="button" id="notificationDeleteAll" class="notification-delete-all">Remove all</button></div></div><div id="notificationList" class="notification-list"><div class="notification-empty">No new notifications.</div></div></div>
    <div class="fams-header-profile fams-header-profile-compactable is-online" data-presence="online" id="topProfile" data-account-href="<?=e($__shellSystem==='aics' ? ((($user['role']??'')==='admin') ? 'users.php' : 'account.php') : 'users.php')?>" aria-label="Open account management" role="link" tabindex="0" title="Open Account Management">
      <?php $headerFullName=trim((string)($__headerIdentity['full_name']??$user['full_name']??'')); $headerInitials=user_name_initials($headerFullName); ?>
      <span class="top-profile-user-wrap" aria-hidden="true"><?=function_exists('fams_shared_avatar_markup') ? fams_shared_avatar_markup($__headerIdentity, 'top-profile-user') : avatar_markup($user['role']??'','top-profile-user',$user['id']??null)?></span><span class="top-profile-collapsed-copy" aria-hidden="true"><strong><?=e($headerInitials)?></strong><i class="top-profile-presence-dot" aria-hidden="true"></i></span><span class="top-profile-expanded-copy"><strong class="top-profile-full-name"><?=e($headerFullName)?></strong><span><?=e($__headerDisplayRole)?></span><small class="top-profile-status-text" data-presence-label>Online</small></span>
      <a class="top-logout" href="logout.php" title="Log out" aria-label="Log out"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H10"></path><path d="M13 8l4 4-4 4"></path><path d="M9 12h8"></path></svg><span>Log out</span></a>
    </div>
  </div>
</header>
