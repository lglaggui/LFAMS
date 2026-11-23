<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$css = file_get_contents($root . '/core/shared-ui-consistency.css');
$shell = file_get_contents($root . '/core/shared_shell_start.php');
$aicsCss = file_get_contents($root . '/aics/assets/app.css');
$maifipCss = file_get_contents($root . '/maifip/assets/app.css');
$aics = file_get_contents($root . '/aics/config.php');
$maifip = file_get_contents($root . '/maifip/app_version.php');
$checks = [
    'shared stylesheet exists and is non-empty' => is_string($css) && strlen($css) > 3000,
    'shared stylesheet loaded last in full shell and imported for standalone popups' => str_contains($shell, 'shared-ui-consistency.css') && str_starts_with($aicsCss, '@import url("../../core/shared-ui-consistency.css");') && str_starts_with($maifipCss, '@import url("../../core/shared-ui-consistency.css");'),
    'both workspaces use v0.0220 cache marker' => str_contains($aics, "const APP_VERSION = '0.0220'") && str_contains($maifip, "const APP_VERSION = '0.0220'"),
    'dashboard card contract included' => str_contains($css, '.dashboard-card') && str_contains($css, '.fams-kpi-card'),
    'table consistency contract included' => str_contains($css, 'table.fams-standard-table') && str_contains($css, 'thead th'),
    'form controls and validation states included' => str_contains($css, 'aria-invalid=true') && str_contains($css, 'textarea'),
    'filter and responsive layouts included' => str_contains($css, '.logs-filter-bar') && str_contains($css, '@media (max-width: 760px)'),
    'popup and empty-state contracts included' => str_contains($css, 'body.fams-popup-page') && str_contains($css, '.notification-empty'),
    'reduced-motion preference respected' => str_contains($css, 'prefers-reduced-motion: reduce'),
];
$failed = 0;
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS' : 'FAIL') . ' ' . $name . PHP_EOL; if (!$ok) $failed++; }
echo 'RESULT ' . (count($checks)-$failed) . '/' . count($checks) . PHP_EOL;
exit($failed ? 1 : 0);
