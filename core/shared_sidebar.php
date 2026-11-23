<?php
/** Single LFAMS sidebar frame. Branding, navigation data and facility context vary by workspace. */
$__sidebarSystem = (string)($famsActiveSystem ?? (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/aics/') !== false ? 'aics' : 'maifip'));
if (!in_array($__sidebarSystem, ['maifip', 'aics'], true)) { $__sidebarSystem = 'maifip'; }
$__sidebarIdentity = function_exists('fams_shared_identity') ? fams_shared_identity($user, $__sidebarSystem) : $user;
$__sidebarName = trim((string)($__sidebarIdentity['full_name'] ?? $user['full_name'] ?? ''));
$__sidebarRole = (($__sidebarIdentity['_fams_identity_source'] ?? '') === 'LPGMA' && function_exists('fams_shared_role_label')) ? fams_shared_role_label((string)($__sidebarIdentity['role'] ?? '')) : (!empty($user['user_type_label']) ? (string)$user['user_type_label'] : (function_exists('role_label') ? role_label((string)($user['role'] ?? '')) : (string)($user['role'] ?? '')));
$__sidebarUsername = trim((string)($__sidebarIdentity['username'] ?? $user['username'] ?? '')); 
$__sidebarOffice = ($__sidebarIdentity['_fams_identity_source'] ?? '') === 'LPGMA'
    ? 'LPGMA Isabela District'
    : ($__sidebarSystem === 'aics'
        ? (trim((string)($user['office_name'] ?? '')) ?: (($user['role'] ?? '') === 'admin' ? 'LPGMA' : 'SWAD Region II'))
        : (function_exists('facility_info') ? (string)(facility_info(function_exists('user_facility') ? user_facility($user) : ($currentFacility ?? ''))['name'] ?? 'LPGMA Isabela District') : 'LPGMA Isabela District'));
?>
<aside class="sidebar" id="famsSidebar" aria-label="Primary navigation">
  <button class="sidebar-mobile-close" id="sidebarMobileClose" type="button" aria-label="Close navigation menu" title="Close menu">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>
  </button>
  <?php require __DIR__ . '/shared_sidebar_brand.php'; ?>
  <?php require __DIR__ . '/shared_navigation.php'; ?>
  <div class="fams-shared-profile-card" data-account-href="<?=e($__sidebarSystem==='aics' ? ((($user['role']??'')==='admin') ? 'users.php' : 'account.php') : 'users.php')?>" role="link" tabindex="0" title="Open Account Management">
    <div class="fams-profile-avatar"><?=function_exists('fams_shared_avatar_markup') ? fams_shared_avatar_markup($__sidebarIdentity, 'fams-profile-image') : avatar_markup($user['role'] ?? '', '', $user['id'] ?? null)?></div>
    <div class="fams-profile-copy"><strong><?=e($__sidebarName)?></strong><span><?=e($__sidebarRole)?></span><small><?=e($__sidebarOffice)?></small></div>
    <a class="fams-profile-logout" href="logout.php" title="Log out" aria-label="Log out"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H6.5A1.5 1.5 0 0 0 5 6.5v11A1.5 1.5 0 0 0 6.5 19H10"></path><path d="M13 8l4 4-4 4"></path><path d="M9 12h8"></path></svg><span>Log out</span></a>
  </div>
</aside>
