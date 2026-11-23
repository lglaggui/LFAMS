<?php
/** Single-source workspace branding block. Only facility data and switch destination vary. */
$__brandSystem = (string)($famsActiveSystem ?? 'maifip');
$__brandIsAics = $__brandSystem === 'aics';
$__brandCanSelectFacility = !$__brandIsAics && function_exists('can_switch_facility') && can_switch_facility();
$__brandCanSwitch = function_exists('fams_is_lpgma_role')
    && fams_is_lpgma_role((string)($user['role'] ?? ''))
    && (function_exists('fams_current_lpgma_user_id') && fams_current_lpgma_user_id() > 0
        || in_array((string)($_SESSION['fams_identity'] ?? ''), ['LPGMA','LPGMA_BRIDGED'], true));
$__brandFacilityShort = $__brandIsAics ? 'SWAD' : (string)($currentFacilityInfo['short'] ?? 'LPGMA');
$__brandFacilityName = $__brandIsAics ? 'DSWD Region II' : (string)($currentFacilityInfo['name'] ?? 'LPGMA Isabela District');
?>
<div class="fams-shared-brand" aria-label="LPGMA Financial Assistance Monitoring System">
  <a class="fams-brand-logo-link" href="../index.php" title="Return home" aria-label="Return to home or system selection">
    <img class="fams-brand-logo-image" src="../core/branding/lpgma-shared-logo.webp" width="1070" height="740" decoding="sync" fetchpriority="high" alt="LPGMA Isabela District">
  </a>
  <div class="fams-brand-system-switch" aria-label="Current workspace: <?= $__brandIsAics ? 'AICS' : 'MAIFIP' ?>">
    <span class="fams-brand-system-label <?= $__brandIsAics ? 'is-aics' : 'is-maifip' ?>"><?= $__brandIsAics ? 'AICS' : 'MAIFIP' ?></span>
    <?php if ($__brandCanSwitch): ?>
      <a class="fams-brand-switch-button" href="<?= $__brandIsAics ? 'switch.php?to=maifip' : '../aics/switch.php?to=aics' ?>" title="Switch workspace to <?= $__brandIsAics ? 'MAIFIP' : 'AICS' ?>" aria-label="Switch workspace to <?= $__brandIsAics ? 'MAIFIP' : 'AICS' ?>">↔</a>
    <?php endif; ?>
  </div>
  <div class="fams-brand-title"><span>FINANCIAL ASSISTANCE</span><span>MONITORING SYSTEM</span></div>
  <div class="fams-brand-facility<?= $__brandCanSelectFacility ? ' has-switcher' : '' ?>">
    <div class="fams-brand-facility-logo">
      <?php if ($__brandIsAics): ?><img src="assets/branding/dswd-region2-sticker.png" alt="DSWD Region II">
      <?php else: ?><?= function_exists('facility_logo_markup') ? facility_logo_markup($currentFacility ?? '') : '' ?><?php endif; ?>
    </div>
    <div class="fams-brand-facility-copy"><strong><?=e($__brandFacilityShort)?></strong><span><?=e($__brandFacilityName)?></span></div>
    <?php if ($__brandCanSelectFacility): ?>
      <span class="fams-brand-facility-cue" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M3 5h14M3 10h14M3 15h14" /></svg></span>
      <form class="fams-brand-facility-form" method="post" aria-label="Select medical facility">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="hidden" name="fams_facility_switch" value="1">
        <input type="hidden" name="return_to" value="<?=e($currentPage ?? 'dashboard.php')?>">
        <select name="fams_facility" aria-label="Choose facility" onchange="this.form.submit()">
          <?php foreach (facility_options() as $facilityKey => $facilityOption): ?>
            <option value="<?=e($facilityKey)?>" <?=$facilityKey === ($currentFacility ?? '') ? 'selected' : ''?>><?=e($facilityOption['short'].' — '.$facilityOption['name'])?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>
</div>
