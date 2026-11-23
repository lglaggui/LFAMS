<?php
/** Single navigation source for both FAMS workspaces. Only route targets and location-management destination vary. */
$__navSystem = (string)($famsActiveSystem ?? (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/aics/') !== false ? 'aics' : 'maifip'));
$__navIsAics = $__navSystem === 'aics';
$__navRole = (string)($user['role'] ?? '');
$__navAdmin = $__navRole === 'admin';
$__navManager = in_array($__navRole, ['admin','manager'], true);
$__navCanDashboard = $__navRole !== 'social_worker';
$__navLocationPage = 'offices.php';
$__navAccountPage = $__navIsAics ? 'account.php' : 'users.php';
?>
<div class="sidebar-nav-scroll">
  <nav aria-label="FAMS navigation" class="sidebar-nav-categorized">
    <section class="nav-section" aria-labelledby="fams-nav-main">
      <div class="nav-section-label" id="fams-nav-main">Main</div>
      <?php if ($__navCanDashboard): ?>
      <a class="<?= $currentPage==='dashboard.php'?'active':'' ?>" <?= $currentPage==='dashboard.php'?'aria-current="page"':'' ?> href="dashboard.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="6" height="6" rx="1.2"></rect><rect x="14" y="4" width="6" height="6" rx="1.2"></rect><rect x="4" y="14" width="6" height="6" rx="1.2"></rect><rect x="14" y="14" width="6" height="6" rx="1.2"></rect></svg></span> Dashboard</a>
      <?php endif; ?>
      <a class="<?= in_array($currentPage,['records.php','record_form.php','print_record.php','masterlist_sheet.php'],true)?'active':'' ?>" <?= in_array($currentPage,['records.php','record_form.php','print_record.php','masterlist_sheet.php'],true)?'aria-current="page"':'' ?> href="records.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="3.5" width="14" height="17" rx="2"></rect><path d="M9 3.5h6v3H9z"></path><path d="m8.5 12 2 2 5-5M8.5 17h7"></path></svg></span> FAMS</a>
      <a class="<?= $currentPage==='chat.php'?'active':'' ?>" <?= $currentPage==='chat.php'?'aria-current="page"':'' ?> href="chat.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.7 8.7 0 0 1-4.2-1.1L4 19l1.2-3.1A7.2 7.2 0 0 1 4.5 12 7.5 7.5 0 0 1 12 4.5a7.5 7.5 0 0 1 8 7z"></path><path d="M8.5 12h.01M12 12h.01M15.5 12h.01"></path></svg></span> Chat <span class="nav-notification-badge" data-notif-category="chat" hidden>0</span></a>
    </section>
    <section class="nav-section" aria-labelledby="fams-nav-management">
      <div class="nav-section-label" id="fams-nav-management">Management</div>
      <a class="<?= $currentPage==='tickets.php'?'active':'' ?>" <?= $currentPage==='tickets.php'?'aria-current="page"':'' ?> href="tickets.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 7.5A2.5 2.5 0 0 0 7.5 10 2.5 2.5 0 0 0 10 7.5h4A2.5 2.5 0 0 0 16.5 10 2.5 2.5 0 0 0 19 7.5V6H5z"></path><path d="M5 6v12h14V6M9 14h6"></path></svg></span><span class="nav-label-stack"><strong>TICKET</strong><small>MANAGEMENT</small></span><span class="nav-notification-badge" data-notif-category="ticket" hidden>0</span></a>
      <?php if ($__navManager): ?>
      <a class="<?= $currentPage==='fund.php'?'active':'' ?>" <?= $currentPage==='fund.php'?'aria-current="page"':'' ?> href="fund.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h16v13H4z"></path><path d="M7 7V5.5A1.5 1.5 0 0 1 8.5 4h7A1.5 1.5 0 0 1 17 5.5V7"></path><path d="M4 11h16M14 11v2h3"></path></svg></span><span class="nav-label-stack"><strong>FUND</strong><small>MANAGEMENT</small></span></a>
      <?php endif; ?>
      <?php if ($__navAdmin): ?>
      <a class="<?= $currentPage===$__navLocationPage?'active':'' ?>" <?= $currentPage===$__navLocationPage?'aria-current="page"':'' ?> href="<?=e($__navLocationPage)?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20V9l8-5 8 5v11"></path><path d="M8 20v-6h8v6M6 9h12"></path></svg></span><span class="nav-label-stack"><strong>FACILITY / OFFICE</strong><small>MANAGEMENT</small></span></a>
      <a class="<?= $currentPage==='users.php'?'active':'' ?>" <?= $currentPage==='users.php'?'aria-current="page"':'' ?> href="users.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.7-3.6 2.5-5.5 5.5-5.5s4.8 1.9 5.5 5.5"></path><path d="M16 11a3 3 0 1 0-1.2-5.7M15.5 14c2.7.2 4.3 1.8 5 5"></path></svg></span><span class="nav-label-stack"><strong>ACCOUNT</strong><small>MANAGEMENT</small></span><span class="nav-notification-badge" data-notif-category="account" hidden>0</span></a>
      <?php endif; ?>
    </section>
    <section class="nav-section" aria-labelledby="fams-nav-support">
      <div class="nav-section-label" id="fams-nav-support"><?= $__navAdmin ? 'System &amp; Support' : 'Account &amp; Support' ?></div>
      <?php if (!$__navAdmin): ?>
      <a class="<?= in_array($currentPage,['account.php','users.php'],true)?'active':'' ?>" <?= in_array($currentPage,['account.php','users.php'],true)?'aria-current="page"':'' ?> href="<?=e($__navAccountPage)?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.7-3.6 2.5-5.5 5.5-5.5s4.8 1.9 5.5 5.5"></path><path d="M16 11a3 3 0 1 0-1.2-5.7M15.5 14c2.7.2 4.3 1.8 5 5"></path></svg></span> My Account</a>
      <?php endif; ?>
      <?php if ($__navManager): ?>
      <a class="<?= $currentPage==='logs.php'?'active':'' ?>" <?= $currentPage==='logs.php'?'aria-current="page"':'' ?> href="logs.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 7v5l3 2"></path><path d="M20 12a8 8 0 1 1-2.3-5.7"></path><path d="M20 5v5h-5"></path></svg></span> Action Logs</a>
      <?php endif; ?>
      <a class="<?= $currentPage==='help.php'?'active':'' ?> sidebar-help-link" <?= $currentPage==='help.php'?'aria-current="page"':'' ?> href="help.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M9.5 9a2.7 2.7 0 1 1 4.7 1.8c-1.4 1.2-2.2 1.6-2.2 3.2"></path><path d="M12 17h.01"></path></svg></span> Help &amp; Support</a>
    </section>
  </nav>
</div>
