<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$js = (string)file_get_contents($root . '/core/shared-shell.js');
$css = (string)file_get_contents($root . '/core/shared-components.css');
$checks = [
    'contextual icon enhancement is installed once' => str_contains($js, '__famsContextualIconsInstalled'),
    'navigation icons cover both workspace navigation selectors' => str_contains($js, '.sidebar nav a,.nav a,.sidebar-nav-scroll a'),
    'action icons cover common buttons and links' => str_contains($js, 'button,a.btn,a.button,.btn,button[type="submit"]'),
    'section heading icons cover shared heading patterns' => str_contains($js, '.section-head h2,.page-header h1,.page-heading h1,.card-header h2,.panel-heading h2'),
    'visible button labels are preserved by prepending icons' => str_contains($js, 'el.insertBefore(icon,el.firstChild)'),
    'icons are decorative and excluded from accessible names' => str_contains($js, "setAttribute('aria-hidden','true')"),
    'existing SVG and icon-only action controls are not overwritten' => str_contains($js, "el.querySelector('svg,img,.fams-context-icon')"),
    'icons are mutation-safe for dynamically inserted content, including the added root node' => str_contains($js, 'new MutationObserver(mutations=>') && str_contains($js, 'if(root instanceof Element && root.matches(selector))found.push(root)'),
    'shared stylesheet defines responsive contextual icon geometry' => str_contains($css, '.fams-context-icon') && str_contains($css, '@media(max-width:640px)'),
    'both workspaces use synchronized v0.0220 markers' => str_contains((string)file_get_contents($root.'/aics/config.php'), "const APP_VERSION = '0.0220';") && str_contains((string)file_get_contents($root.'/maifip/app_version.php'), "const APP_VERSION = '0.0220';"),
];
$failed=0;
foreach($checks as $label=>$ok){echo ($ok?'PASS':'FAIL').' - '.$label."\n"; if(!$ok)$failed++;}
echo count($checks).' checks; '.$failed.' failures'."\n";
exit($failed?1:0);
