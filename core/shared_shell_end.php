<?php
/** Shared LFAMS viewport closer. Workspace footer partials delegate here. */
require_once __DIR__ . '/unified_footer.php';
?>
</div><!-- /#pageView -->
<?php if (empty($famsShellIsPopup)): $famsFooterConfig = function_exists('fams_shell_config') ? fams_shell_config($famsActiveSystem ?? 'maifip') : ['organization'=>'LPGMA Isabela District'];
fams_render_unified_footer($famsActiveSystem ?? 'maifip', APP_VERSION, 'LPGMA Isabela District'); endif; ?>
</main>
</div><!-- /.app-layout -->
<?php if (empty($famsShellIsPopup)): ?><div class="fams-shared-dialog-backdrop" id="globalPopup" hidden><section class="fams-shared-dialog" role="dialog" aria-modal="true" aria-label="Popup editor"><div class="fams-shared-dialog-head"><strong class="fams-shared-dialog-title" id="globalPopupTitle">Financial Assistance</strong><button class="fams-shared-dialog-close" type="button" onclick="closeGlobalPopup()" aria-label="Close popup">×</button></div><iframe class="fams-shared-dialog-frame" id="globalPopupFrame" title="Popup editor"></iframe></section></div><?php endif; ?>
<script src="assets/app.js?v=<?=e(APP_VERSION)?>" defer></script>
<?php if (empty($famsShellIsPopup)): ?>
<script src="../core/session-activity.js?v=<?=e(APP_VERSION)?>" defer></script>
<script src="../core/shared-page-skeleton.js?v=<?=e(APP_VERSION)?>" defer></script>
<script src="../core/shared-notifications.js?v=<?=e(APP_VERSION)?>" defer></script>
<script src="../core/shared-shell.js?v=<?=e(APP_VERSION)?>" defer></script>
<script src="../core/shared-ux.js?v=<?=e(APP_VERSION)?>" defer></script>
<script src="../core/shared-page-assets.js?v=<?=e(APP_VERSION)?>" defer></script>
<?php endif; ?>
</body>
</html>
