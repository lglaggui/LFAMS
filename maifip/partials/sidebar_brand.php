<?php
/** System-specific branding and facility context inside the shared sidebar frame. */
?>
<div class="sidebar-brand formal-brand" aria-label="LPGMA Financial Assistance Monitoring System — <?=e($currentFacilityInfo['name'])?>">
            <a class="sidebar-logo sidebar-logo-large sidebar-lpgma-logo sidebar-home-link" href="../index.php" title="Return to system selection" aria-label="Return to FAMS system selection"><img src="<?=e('../core/branding/lpgma-shared-logo.webp')?>" width="1070" height="740" decoding="sync" fetchpriority="high" alt="LPGMA Isabela District — return to selection"></a>
            <div class="sidebar-system-switch" aria-label="Current workspace: MAIFIP">
                <span class="sidebar-system-current maifip">MAIFIP</span><span class="maifip-doh-brand"><img src="../core/branding/doh-logo.jpg" alt="Department of Health, Republic of the Philippines" loading="eager"><span>DEPARTMENT OF HEALTH</span></span>
                <?php if(fams_is_lpgma_role((string)($user['role']??'')) && (fams_current_lpgma_user_id()>0 || in_array((string)($_SESSION['fams_identity']??''), ['LPGMA','LPGMA_BRIDGED'], true))): ?><a class="sidebar-system-switch-btn" href="../aics/switch.php?to=aics" title="Switch workspace to AICS" aria-label="Switch workspace to AICS">↔</a><?php endif; ?>
            </div>
            <div class="sidebar-fams-title"><span>FINANCIAL ASSISTANCE</span><span>MONITORING SYSTEM</span></div>
            <?php if (can_switch_facility()): ?>
            <form method="post" class="sidebar-selected-facility sidebar-facility-selector" aria-label="Select medical facility" style="--facility-selector-color: <?=e(facility_accent($currentFacility))?>;">
                <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
                <input type="hidden" name="fams_facility_switch" value="1">
                <input type="hidden" name="return_to" value="<?=e($currentPage)?>">
                <div class="sidebar-selected-facility-logo"><?=facility_logo_markup($currentFacility)?></div>
                <div class="sidebar-selected-facility-copy">
                    <strong><?=e($currentFacilityInfo['short'])?></strong>
                    <span class="sidebar-facility-full-name"><?=e($currentFacilityInfo['name'])?></span>
                </div>
                <button type="button" class="sidebar-facility-select-cue" aria-label="Switch facility" title="Switch facility"><svg class="facility-switch-chevron" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m5.5 7.5 4.5 4.5 4.5-4.5"></path></svg></button>
                <select id="sidebarFacilitySelect" name="fams_facility" aria-label="Choose facility" onchange="this.form.submit()">
                    <?php foreach(facility_options() as $fk=>$fi): ?>
                        <option value="<?=e($fk)?>" <?=$fk===$currentFacility?'selected':''?>><?=e($fi['short'])?> — <?=e($fi['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php else: ?>
            <div class="sidebar-selected-facility sidebar-facility-static" aria-label="Selected medical facility">
                <div class="sidebar-selected-facility-logo"><?=facility_logo_markup($currentFacility)?></div>
                <div class="sidebar-selected-facility-copy">
                    <strong><?=e($currentFacilityInfo['short'])?></strong>
                    <span class="sidebar-facility-full-name"><?=e($currentFacilityInfo['name'])?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
