<?php
/** Shared FAMS footer. Call after closing #pageView and before closing main. */
function fams_render_unified_footer(string $system, string $appVersion, string $organization='Region II'): void {
    $label = $system === 'aics' ? 'DSWD SWAD R2 AICS' : 'DOH R2 MAIFIP';
    ?>
    <footer class="fams-unified-footer" role="contentinfo">
        <span>FAMS — Financial Assistance Monitoring System • <?=e($label)?></span>
        <span>&copy; 2026 LPGMA Isabela District</span>
        <strong>Version <?=e($appVersion)?></strong>
    </footer>
    <?php
}
