<?php
declare(strict_types=1);
require_once __DIR__ . '/core/unified.php';
if (empty($_SESSION['user']['id'])) { header('Location: auth/login.php'); exit; }
$workspace = (string)($_SESSION['fams_system'] ?? '');
$currentUser = (array)($_SESSION['user'] ?? []);
$currentRole = (string)($currentUser['role'] ?? '');
$currentLpgmaId = function_exists('fams_current_lpgma_user_id') ? fams_current_lpgma_user_id() : 0;
$isLpgmaIdentity = fams_is_lpgma_role($currentRole) || $currentLpgmaId > 0 || in_array((string)($_SESSION['fams_identity'] ?? ''), ['LPGMA','LPGMA_BRIDGED'], true);
// The shared sidebar logo is the workspace-selection shortcut for LPGMA users.
// Resolve bridged AICS shadow users back to the authoritative central account.
if ($isLpgmaIdentity && $workspace !== 'portal') {
    $centralId = $currentLpgmaId > 0 ? $currentLpgmaId : (int)($currentUser['id'] ?? 0);
    $centralDb = fams_lpgma_db();
    if ($centralDb && $centralId > 0) {
        $st = $centralDb->prepare('SELECT id,username,full_name,email,role,facility,auth_version FROM users WHERE id=? LIMIT 1');
        $st->execute([$centralId]);
        $centralUser = $st->fetch(PDO::FETCH_ASSOC);
        if ($centralUser && fams_is_lpgma_role((string)$centralUser['role'])) {
            fams_begin_login('portal', ['id'=>(int)$centralUser['id'],'auth_version'=>(int)($centralUser['auth_version']??1),'username'=>$centralUser['username'],'full_name'=>$centralUser['full_name'],'email'=>$centralUser['email']??'','role'=>$centralUser['role'],'facility'=>'LPGMA'], ['identity_source'=>'LPGMA','lpgma_user_id'=>(int)$centralUser['id']]);
            $_SESSION['fams_user_type'] = 'LPGMA';
            header('Location: portal/select-system.php'); exit;
        }
    }
}
if ($workspace === 'portal') { if(fams_validate_lpgma_session() && fams_is_lpgma_role((string)($_SESSION['user']['role'] ?? ''))){header('Location: portal/select-system.php');exit;} header('Location: auth/login.php');exit; }
if ($workspace === 'maifip') { header('Location: maifip/' . (($_SESSION['user']['role'] ?? '') === 'social_worker' ? 'records.php' : 'dashboard.php')); exit; }
if ($workspace === 'aics') { header('Location: aics/dashboard.php'); exit; }
header('Location: auth/login.php'); exit;
