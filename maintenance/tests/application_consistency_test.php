<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$failed=0;
$check=static function(bool $ok,string $label)use(&$failed):void{echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$failed++;};
$manifest=json_decode((string)file_get_contents(__DIR__.'/../endpoint-registry.json'),true);
$routes=$manifest['routes']??[];$allExist=true;$unique=[];
foreach($routes as $route){$path=(string)($route['path']??'');if($path===''||isset($unique[$path])||!is_file($root.'/'.$path))$allExist=false;$unique[$path]=true;}
$check($allExist&&count($routes)===81,'all 81 registered routes are unique and map to real PHP entry points');
$missingCsrf=[];$unauditedMutations=[];$cacheOnly=['aics/address_api.php','maifip/address_api.php'];
foreach($routes as $route){$path=(string)$route['path'];$source=(string)file_get_contents($root.'/'.$path);$mutates=(bool)preg_match('/\\b(?:INSERT\\s+INTO|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM)\\b/i',$source);if(!$mutates)continue;$hasCsrf=str_contains($source,'verify_csrf')||str_contains($source,'verify_csrf_json')||str_contains($source,'csrf_token')||str_contains($source,'chat_verify_csrf_json');if(!$hasCsrf)$missingCsrf[]=$path;$hasAudit=str_contains($source,'log_action(')||str_contains($source,'fams_audit_write')||str_contains($source,'user_message_trails');if(!$hasAudit&&!in_array($path,$cacheOnly,true))$unauditedMutations[]=$path;}
$check(!$missingCsrf,'all registered SQL-mutating routes contain a CSRF verification path');
$check(!$unauditedMutations,'all registered business mutations have an audit or dedicated message-trail path; address cache refreshes are non-business derived data');
$register=(string)file_get_contents($root.'/maifip/register.php');
$check(str_contains($register,'fams_shared_transaction_retry')&&strpos($register, "fams_audit_write_critical(\$db, 'maifip', 'REGISTER_ACCOUNT'")!==false,'public pending-account registration and its audit event are atomic');
$audit=(string)file_get_contents($root.'/core/audit_runtime.php');
$check((bool)preg_match('/REGISTER/',substr($audit,strpos($audit,'function fams_audit_action_is_critical')?:0,700)),'account-registration events are classified as critical audit events');
$settings=(string)file_get_contents($root.'/maifip/monthly_report_settings.php');
$check(str_contains($settings,'require_role([\'admin\',\'encoder\'])')&&str_contains($settings,'hash_equals')&&str_contains($settings,"header('Allow: POST')"),'monthly report settings require an authorized POST with CSRF and explicit method handling');
$check(str_contains($settings,'\\d{1,13}')&&str_contains($settings,'\\.\\d{1,2}')&&str_contains($settings,'9999999999999.99'),'monthly report amount validation matches DECIMAL(15,2) precision and scale');
$check(str_contains($settings,'fams_shared_transaction_retry')&&str_contains($settings,'fams_audit_write_critical'),'monthly report settings and audit entry commit atomically with transient-conflict retry');
$check(str_contains($settings,'fams_shared_user_error')&&str_contains($settings,'error_log('),'monthly report endpoint returns safe failure messages and logs internal details');
$shared=(string)file_get_contents($root.'/core/shared_runtime.php');
$check(str_contains($shared,'function fams_shared_transaction_retry')&&str_contains($shared,'$pdo->inTransaction()'),'retry wrapper avoids retrying nested transactions independently');
$check(str_contains($audit,"if(!\$pdo->inTransaction() && function_exists('fams_client_hostname')"),'transaction-bound audit writes avoid external reverse-DNS lookups inside retryable transactions');
exit($failed?1:0);
