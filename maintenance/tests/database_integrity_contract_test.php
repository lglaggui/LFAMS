<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$maifip=file_get_contents($root.'/maifip/helpers.php');
$allocator=substr($maifip,strpos($maifip,'function ensure_control_number_schema'));
$audit=file_get_contents($root.'/maintenance/database_integrity_audit.php');
$auditRuntime=file_get_contents($root.'/core/audit_runtime.php');
$aicsHelpers=file_get_contents($root.'/aics/helpers.php');
$maifipStatus=file_get_contents($root.'/maifip/status_update.php');
$checks=[
 'MAIFIP duplicate pairs checked before adding unique key'=>strpos($maifip,'SELECT COUNT(*) FROM (SELECT facility,control_number') < strpos($maifip,'ADD UNIQUE KEY uq_facility_control_number'),
 'MAIFIP replacement index created before legacy unique index removal'=>strpos($allocator,'ADD UNIQUE KEY uq_facility_control_number') < strpos($allocator,'DROP INDEX'),
 'MAIFIP migration does not mark completion after failure'=>strpos($maifip,'$done=false;') !== false && strpos($maifip,'$done=true;',strpos($maifip,'function ensure_control_number_schema')) !== false,
 'Integrity auditor is CLI-only'=>strpos($audit,"PHP_SAPI !== 'cli'") !== false,
 'Integrity auditor requires exact database confirmation'=>strpos($audit,'FAMS_AUDIT_CONFIRM_DB') !== false && strpos($audit,'hash_equals($confirm, $actual)') !== false,
 'Integrity auditor uses read-only transaction'=>strpos($audit,'SET TRANSACTION READ ONLY') !== false,
 'Integrity auditor reports aggregate counts only'=>strpos($audit,'SELECT COUNT(*)') !== false,
 'Status history adapter requires active transaction'=>strpos($auditRuntime,'Status history must be written in the same transaction') !== false,
 'Status history adapter preserves workspace-specific tables'=>strpos($auditRuntime,"'aics'=>'aics_status_history', 'maifip'=>'status_history'") !== false,
 'Workspace audit adapters use transaction-aware writer'=>strpos($aicsHelpers,'fams_audit_write_in_transaction') !== false && strpos(file_get_contents($root.'/maifip/helpers.php'),'fams_audit_write_in_transaction') !== false,
 'MAIFIP status mutation uses shared history writer'=>strpos($maifipStatus,"fams_status_history_write(\$pdo,'maifip'") !== false,
 'Archived deletions are excluded from unresolved orphan counts'=>strpos($audit,'fams_deleted_records') !== false && strpos($audit,'archived-as-deleted references are excluded') !== false,
 'Integrity auditor does not mutate schema or data'=>!preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE)\b/i',$audit),
];
$failed=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}echo 'Assertions: '.count($checks).'; Failed: '.$failed.PHP_EOL;exit($failed?1:0);
