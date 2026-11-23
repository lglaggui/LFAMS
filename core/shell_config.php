<?php
declare(strict_types=1);

/** Shared FAMS shell values. Page-specific databases supply the numbers; this
 * file only supplies the common visual identity and labels. */
function fams_shell_config(string $system): array {
    if ($system === 'aics') {
        return [
            'system'=>'aics',
            'code'=>'AICS',
            'eyebrow'=>'DSWD REGION II • AICS',
            'title'=>'DSWD SWAD R2 AICS',
            'subtitle'=>'Financial Assistance Monitoring',
            'program'=>'AICS Financial Assistance',
            'organization'=>'LPGMA Isabela District',
            'brand_logo'=>'../core/branding/lpgma-shared-logo.webp',
            'brand_alt'=>'LPGMA — system owner and administrator',
            'switch_to'=>'MAIFIP',
            'switch_href'=>'../aics/switch.php?to=maifip',
        ];
    }
    return [
        'system'=>'maifip',
        'code'=>'MAIFIP',
        'eyebrow'=>'DOH REGION II • MAIFIP',
        'title'=>'DOH R2 MAIFIP',
        'subtitle'=>'Medical Assistance Monitoring',
        'program'=>'MAIFIP Financial Assistance',
        'organization'=>'LPGMA Isabela District',
        'brand_logo'=>'../core/branding/lpgma-shared-logo.webp',
        'brand_alt'=>'LPGMA Isabela District',
        'switch_to'=>'AICS',
        'switch_href'=>'../aics/switch.php?to=aics',
    ];
}
