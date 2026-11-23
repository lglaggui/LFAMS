<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents(__DIR__.'/../endpoint-registry.json'),true);
$routes=$manifest['routes']??[];$failed=0;
require_once $root.'/core/unified.php';
$check=static function(bool $ok,string $label)use(&$failed):void{echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$failed++;};
$check(($manifest['manifest_version']??0)>=3,'reconciled endpoint manifest version is current');
$check(count($routes)===81,'registry contains the 81 application entry points');
$seen=[];$allExist=true;$duplicates=false;
foreach($routes as $r){$path=(string)($r['path']??'');if($path===''||isset($seen[$path]))$duplicates=true;$seen[$path]=true;if(!is_file($root.'/'.$path))$allExist=false;}
$check(!$duplicates,'all registered endpoint paths are unique');
$check($allExist,'every registered endpoint maps to an existing PHP file');
$check(isset($seen['portal/select-system.php'])&&!isset($seen['portal/index.php']),'portal route points to the real selector endpoint');
$check(!isset($seen['maifip/media_upgrade.php']),'config-loaded media helper is not exposed as an endpoint');
$check(function_exists('fams_csv_safe_cell'),'shared CSV cell neutralizer loads');
$cases=[
 ['=1+1',"'=1+1"],['  =HYPERLINK("x")',"'  =HYPERLINK(\"x\")"],['+cmd',"'+cmd"],['-1',"'-1"],['@SUM(A1:A2)',"'@SUM(A1:A2)"],['normal text','normal text'],['12345','12345'],[12345,12345],[null,null]
];$safe=true;foreach($cases as [$in,$expected])if(fams_csv_safe_cell($in)!==$expected)$safe=false;
$check($safe,'CSV neutralizer protects formula prefixes and preserves numeric/null values');
foreach(['aics/export_csv.php','maifip/export_csv.php','aics/export_logs.php','maifip/export_logs.php','aics/masterlist_sheet.php','maifip/monthly_report_export.php'] as $rel){$src=(string)file_get_contents($root.'/'.$rel);if(!str_contains($src,'fams_csv_safe_cell'))$safe=false;}
$check($safe,'all registered CSV export handlers use the shared formula neutralizer');
$aics=(string)file_get_contents($root.'/aics/export_csv.php');$maifip=(string)file_get_contents($root.'/maifip/export_csv.php');
$check(str_contains($aics,"fams_current_type_allows('AICS','export_records')")&&str_contains($aics,'centralized masterlist'),'AICS export retains authorized Region II centralized visibility');
$check(str_contains($maifip,"fams_current_type_allows('MAIFIP','export_records')")&&str_contains($maifip,'WHERE facility=?'),'MAIFIP export remains facility-scoped');
$sync=(string)file_get_contents($root.'/maifip/offline_sync.php');
$check(str_contains($sync,'count($changes)>100')&&str_contains($sync,'client_change_id')&&str_contains($sync,'baseVersion'),'offline sync retains batch cap, replay key, and version checks');
$resolve=(string)file_get_contents($root.'/maifip/offline_resolve.php');
$check(str_contains($resolve,'WHERE id=? AND facility=? FOR UPDATE')&&str_contains($resolve,'record_version')&&str_contains($resolve,'verify_csrf_json'),'offline conflict resolution retains facility lock, version check, and CSRF');
$aicsSheet=(string)file_get_contents($root.'/aics/masterlist_sheet.php');$maifipSheet=(string)file_get_contents($root.'/maifip/fams_sheet_editor.php');
$check(str_contains($aicsSheet,'clone.querySelectorAll(\'tbody td\')')&&str_contains($aicsSheet,'cellIndex===11'),'AICS browser-generated Excel neutralizes text cells while preserving the numeric amount column');
$check(str_contains($maifipSheet,'const safeText=')&&str_contains($maifipSheet,'v=safeText(v)'),'MAIFIP browser-generated Excel neutralizes formula-like text cells');
foreach(['aics/export_event.php'=>'AICS','maifip/export_event.php'=>'MAIFIP'] as $rel=>$system){$src=(string)file_get_contents($root.'/'.$rel);$check(str_contains($src,'REQUEST_METHOD')&&str_contains($src,'POST required.'),'export event '.$system.' is POST-only');$check(str_contains($src,'fams_current_type_allows')&&str_contains($src,'export_records')&&str_contains($src,'verify_csrf()')&&str_contains($src,'log_action('),'export event '.$system.' requires capability + CSRF and records an audit event');}
exit($failed?1:0);
