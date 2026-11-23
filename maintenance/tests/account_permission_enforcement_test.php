<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$checks=[];
$assert=function(bool $ok,string $name) use (&$checks): void {$checks[]=['name'=>$name,'ok'=>$ok];};
$access=file_get_contents($root.'/core/access_control.php');
$aicsTypes=file_get_contents($root.'/aics/usertypes.php');
$maifipTypes=file_get_contents($root.'/maifip/usertypes.php');
$aicsSync=file_get_contents($root.'/aics/helpers.php');
$maifipSync=file_get_contents($root.'/maifip/helpers.php');
$aicsUsers=file_get_contents($root.'/aics/users.php');
$maifipUsers=file_get_contents($root.'/maifip/users.php');
$assert(str_contains($access, '$baseRole===\'admin\' && ((int)($d[\'is_system\']??0)!==1'),'Custom user types cannot inherit Administrator base role');
$assert(str_contains($access,'Administrator privileges are reserved for the protected built-in Administrator type.'),'Account assignment blocks custom Administrator types');
$assert(str_contains($access,'function fams_workspace_admin_allowed(string $targetSystem, string $permission): bool'),'Central workspace-admin policy exists');
$assert(str_contains($access,'if ($activeSystem !== $targetLower && !$centralLpgma) return false;') || str_contains($access,'$activeSystem !== $targetLower && !$centralLpgma'),'Cross-workspace admin requires central LPGMA identity');
$assert(str_contains($access,'return fams_current_type_allows($targetSystem, $permission);'),'Target workspace capability is checked independently');
$assert(str_contains($aicsTypes,'fams_workspace_admin_allowed($system,\'manage_usertypes\')') && str_contains($maifipTypes,'fams_workspace_admin_allowed($system,\'manage_usertypes\')'),'Both user-type pages authorize selected target workspace');
$assert(substr_count($aicsTypes,'fams_workspace_admin_allowed($system,\'manage_usertypes\')')>=2 && substr_count($maifipTypes,'fams_workspace_admin_allowed($system,\'manage_usertypes\')')>=2,'POST target workspace is reauthorized');
$assert(str_contains($aicsSync,'user_type_policy_hash') && str_contains($maifipSync,'user_type_policy_hash'),'Both workspace sessions retain a user-type policy fingerprint');
$assert(str_contains($aicsSync,'session_regenerate_id(true)') && str_contains($maifipSync,'session_regenerate_id(true)'),'Changed role or permission policy invalidates existing session');
$assert(str_contains($maifipUsers,"Your Site Administrator account must remain an Administrator."),'MAIFIP administrator cannot demote their own account');
$assert(str_contains($aicsUsers,'fams_shared_transaction($pdo') && str_contains($maifipUsers,'fams_shared_transaction($pdo'),'Account administration retains transactional writes');
$failed=0; foreach($checks as $c){echo ($c['ok']?'PASS':'FAIL').' '.$c['name'].PHP_EOL;if(!$c['ok'])$failed++;}
echo count($checks).' checks; '.$failed.' failures'.PHP_EOL; exit($failed?1:0);
