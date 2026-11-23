<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$css=(string)file_get_contents($root.'/core/shared-dashboard.css');
$failed=0;
$check=static function(bool $ok,string $label)use(&$failed):void{echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$failed++;};
foreach(['aics','maifip'] as $workspace){
  $html=(string)file_get_contents($root.'/'.$workspace.'/dashboard.php');
  $status=strpos($html,'data-editor-item="reporting-status"');
  $controls=strpos($html,'data-editor-item="reporting-controls"');
  $action=strpos($html,'class="dashboard-period-actions"');
  $formEnd=$status===false?false:strpos($html,'</form>',$controls);
  $check($status!==false && $action!==false && $action>$status, strtoupper($workspace).' report action is inside the selected-period card');
  $check($formEnd!==false && $action>$formEnd, strtoupper($workspace).' report action is outside the year/month form');
  $check(substr_count($html,'class="dashboard-period-report-icon"')===1, strtoupper($workspace).' has exactly one selected-period report action');
}
$check(str_contains($css,'.dashboard-period-actions { display:flex; justify-content:flex-end;'), 'selected-period action is anchored to the right');
$check(str_contains($css,'overflow-wrap:anywhere;'), 'action label can wrap without overflowing its card');
$check(str_contains($css,'box-sizing:border-box;'), 'action sizing includes padding and border');
exit($failed?1:0);
