<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$css=(string)file_get_contents($root.'/core/shared-dashboard.css');
$passed=0;$failed=0;
$check=static function(bool $ok,string $label)use(&$passed,&$failed):void{echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if($ok)$passed++;else $failed++;};
foreach(['aics','maifip'] as $workspace){
 $src=(string)file_get_contents($root.'/'.$workspace.'/dashboard.php');
 $check(str_contains($src,'class="dashboard-period-report-icon"'),$workspace.' dashboard uses compact report icon action');
 $check(str_contains($src,'aria-label="Display Selected Period Report"'),$workspace.' report icon has accessible name');
 $check(str_contains($src,'title="Display Selected Period Report"'),$workspace.' report icon has descriptive tooltip');
 $check(str_contains($src,'monthly_report.php?year=<?= (int)$selectedYear ?>&amp;month=<?= (int)$selectedMonth ?>'),$workspace.' report action preserves selected year and month');
 $check(str_contains($src,'<span class="sr-only">Display Selected Period Report</span>'),$workspace.' report action includes screen-reader text');
}
$check(str_contains($css,'.dashboard-period-report-icon:focus-visible'),'report icon has visible keyboard focus treatment');
$check(str_contains($css,'.dashboard-period-report-icon svg'),'report icon has standardized SVG sizing and stroke');
printf("\nDashboard reporting action tests: %d passed, %d failed.\n",$passed,$failed);
exit($failed?1:0);
