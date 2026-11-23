<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$aics=(string)file_get_contents($root.'/aics/import_sheet.php');
$maifip=(string)file_get_contents($root.'/maifip/import_sheet.php');
$checks=[];
$checks['AICS preview captures matched record ID and version']=str_contains($aics,'SELECT id,record_version FROM aics_assistance_records WHERE control_number=?') && str_contains($aics,"'existing_record_id'=>\$existingRecordId,'existing_record_version'=>\$existingRecordVersion");
$checks['AICS apply rejects a new or changed record match after preview']=str_contains($aics,'$previewExistingId<1') && str_contains($aics,'$previewExistingVersion)') && str_contains($aics,'is no longer available after the import preview');
$checks['AICS update uses version compare-and-swap and checks affected rows']=str_contains($aics,'WHERE id=? AND record_version=?') && str_contains($aics,'$update->rowCount()!==1');
$checks['MAIFIP preview captures matched record ID and version']=str_contains($maifip,'$n[\'existing_record_id\']=(int)($c[\'existing\'][\'id\']??0)') && str_contains($maifip,'$n[\'existing_record_version\']=(int)($c[\'existing\'][\'record_version\']??0)');
$checks['MAIFIP recheck refreshes match/version metadata']=str_contains($maifip,'$checkedRows[$i][\'existing_record_id\']=$result[\'existing_record_id\']') && str_contains($maifip,'$checkedRows[$i][\'existing_record_version\']=$result[\'existing_record_version\']');
$checks['MAIFIP apply rejects stale or changed record matches']=str_contains($maifip,'$previewExistingId!==$currentExistingId') && str_contains($maifip,'$previewExistingVersion!==$currentExistingVersion');
$checks['MAIFIP browser recheck carries refreshed match/version metadata into apply payload']=str_contains($maifip,'rows[i].existing_record_id=Number(x.existing_record_id||0)') && str_contains($maifip,'rows[i].existing_record_version=Number(x.existing_record_version||0)') && str_contains($maifip,'applyPayload.value=j');
$checks['MAIFIP update is facility- and version-scoped']=str_contains($maifip,'WHERE id=? AND facility=? AND record_version=?') && str_contains($maifip,'$update->rowCount()!==1');
$failed=0;
foreach($checks as $label=>$ok){echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$failed++;}
echo 'Checks: '.count($checks).'; Failed: '.$failed.PHP_EOL;
exit($failed?1:0);
