<?php
declare(strict_types=1);
$source=(string)file_get_contents(dirname(__DIR__,2).'/core/workspace_repositories.php');
$checks=[
 'AICS office lookup is ID-and-office scoped'=>str_contains($source,'WHERE id=? AND office_id=?'),
 'AICS scoped updates include expected record version'=>str_contains($source,"'office_id=?'") && str_contains($source,"' WHERE id=? AND record_version=? AND '.\$scopeSql"),
 'MAIFIP facility lookup is ID-and-facility scoped'=>str_contains($source,'WHERE id=? AND UPPER(TRIM(facility))=?'),
 'MAIFIP updates include facility and expected version'=>str_contains($source,'WHERE id=? AND record_version=? AND UPPER(TRIM(facility))=?'),
 'AICS repository has a field allowlist'=>str_contains($source,"\$allowed=['status','validity_status'"),
 'MAIFIP repository has a separate field allowlist'=>str_contains($source,"\$allowed=['status','remarks'"),
];$failed=0;foreach($checks as $label=>$ok){echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$failed++;}exit($failed?1:0);
