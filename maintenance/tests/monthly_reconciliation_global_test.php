<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$helpers=(string)file_get_contents($root.'/maifip/helpers.php');
$worksheet=(string)file_get_contents($root.'/maifip/monthly_report.php');
$print=(string)file_get_contents($root.'/maifip/monthly_report_print.php');
$export=(string)file_get_contents($root.'/maifip/monthly_report_export.php');
$assertions=[];
$assertions['global anchor count has no selected-period upper bound']=preg_match("/SELECT COUNT\(\*\) FROM fund_downloads WHERE UPPER\(TRIM\(facility\)\)=UPPER\(TRIM\(\?\)\) AND date_downloaded>='2026-01-01' AND UPPER\(TRIM\(COALESCE\(batch_no,''\)\)\)='BEGINNING BALANCE'/",$helpers)===1;
$assertions['monthly downloaded-balance check rejects any later duplicate opening anchor']=str_contains($helpers,'$globalAnchorCount>1 || ($globalAnchorCount===1 && $janCount!==1)');
$assertions['ledger position uses the same global opening-anchor invariant']=substr_count($helpers,'$globalAnchorCount>1 ||')>=2;
$assertions['shared detail helper normalizes facility and status']=str_contains($helpers,"UPPER(TRIM(facility))=UPPER(TRIM(?)) AND UPPER(TRIM(status))='RELEASED'");
$assertions['shared detail helper uses a half-open month date range']=str_contains($helpers,'release_date>=? AND release_date<? ORDER BY release_date, id');
$assertions['worksheet uses canonical released detail helper']=str_contains($worksheet,'$rows=maifip_monthly_released_records($pdo,$year,$month);');
$assertions['print uses canonical released detail helper']=str_contains($print,'$rows=maifip_monthly_released_records($pdo,$year,$month);');
$assertions['CSV uses canonical released detail helper']=str_contains($export,'$rows=maifip_monthly_released_records($pdo,$year,$month);');
$assertions['no report detail query retains exact facility/status matching']=!preg_match('/SELECT \* FROM assistance_records WHERE facility=\? AND status=\'RELEASED\'/',$worksheet.$print.$export);
$assertions['selected live releases retain stored summary values for comparison']=str_contains($helpers,"'stored_summary_amount'=>\$saved['amount'] ?? null") && str_contains($helpers,"'summary_discrepancy'=>\$differs");
$assertions['worksheet surfaces a historical summary discrepancy']=str_contains($worksheet,'Historical summary difference: saved summary');
$assertions['print report no longer mislabels live detail as matching stored summary']=str_contains($print,'Saved monthly summary differs from live RELEASED records') && str_contains($print,'match the selected report total');
$assertions['CSV exports the stored summary comparison']=str_contains($export,'Stored Summary Comparison') && str_contains($export,'Stored Monthly Summary Amount');
$failed=[]; foreach($assertions as $label=>$ok){echo ($ok?'PASS':'FAIL')." - {$label}\n";if(!$ok)$failed[]=$label;}
echo count($assertions).' checks; '.count($failed).' failures'.PHP_EOL;
exit($failed?1:0);
