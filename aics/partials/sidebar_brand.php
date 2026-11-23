<?php
/** System-specific branding and facility context inside the shared sidebar frame. */
?>
<div class="sidebar-brand formal-brand" aria-label="DSWD SWAD Region II — Financial Assistance Monitoring System">
    <a class="sidebar-logo sidebar-logo-large sidebar-lpgma-logo sidebar-home-link" href="../index.php" title="Return to system selection" aria-label="Return to FAMS system selection"><img src="../core/branding/lpgma-shared-logo.webp" width="1070" height="740" decoding="sync" fetchpriority="high" alt="LPGMA — system owner and administrator; return to system selection"></a>
    <div class="sidebar-system-switch" aria-label="Current workspace: AICS">
      <span class="sidebar-system-current aics">AICS</span>
      <?php if(fams_is_lpgma_role((string)($user['role']??'')) && (fams_current_lpgma_user_id()>0 || in_array((string)($_SESSION['fams_identity']??''),['LPGMA','LPGMA_BRIDGED'],true))): ?>
      <a class="sidebar-system-switch-btn" href="switch.php?to=maifip" title="Switch workspace to MAIFIP" aria-label="Switch workspace to MAIFIP">↔</a>
      <?php endif; ?>
    </div>
    <div class="sidebar-fams-title"><span>FINANCIAL ASSISTANCE</span><span>MONITORING SYSTEM</span></div>
    <div class="sidebar-selected-facility sidebar-facility-static" aria-label="Selected SWAD facility">
      <div class="sidebar-selected-facility-logo"><img src="assets/branding/dswd-region2-sticker.png" alt="DSWD Region II logo"></div>
      <div class="sidebar-selected-facility-copy"><strong>SWAD</strong><span class="sidebar-facility-full-name">DSWD Region II</span></div>
    </div>
  </div>
