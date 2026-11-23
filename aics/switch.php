<?php
define('FAMS_ALLOW_SWITCH', true);
require_once __DIR__ . '/config.php';
$target=(string)($_GET['to']??'');
$returnPage=(string)($_GET['return_page']??'');
function fams_switch_destination(string $directory, string $page, string $fallback): string {
    $page = basename(trim($page));
    // Equivalent destinations across workspaces. These aliases prevent a valid
    // page switch from falling back to the dashboard merely because the route
    // has a different name in the other system.
    $aliases = [
        'aics' => [
            'offices.php' => 'offices.php',
            'account.php' => 'users.php',
            'fund_settings.php' => 'fund.php',
            'fams_sheet_editor.php' => 'masterlist_sheet.php',
            'monthly_sheet_editor.php' => 'monthly_report.php',
            'monthly_report_print.php' => 'monthly_report.php',
            'monthly_report_export.php' => 'monthly_report.php',
            'ticket_create.php' => 'tickets.php',
            'ticket_edit.php' => 'tickets.php',
            'ticket_review.php' => 'tickets.php',
            'records_search.php' => 'records.php',
        ],
        'maifip' => [
            'offices.php' => 'offices.php',
            'account.php' => 'users.php',
            'fund_settings.php' => 'fund.php',
            'masterlist_sheet.php' => 'fams_sheet_editor.php',
            'monthly_report.php' => 'monthly_report.php',
            'print_record.php' => 'records.php',
        ],
    ];
    if (isset($aliases[$directory][$page])) $page = $aliases[$directory][$page];
    if ($page !== '' && preg_match('/^[a-zA-Z0-9_-]+\.php$/', $page)
        && !in_array($page, ['switch.php','login.php','logout.php','index.php','select-system.php'], true)
        && is_file(__DIR__ . '/../' . $directory . '/' . $page)) {
        return '../' . $directory . '/' . $page;
    }
    return $fallback;
}

$lpgmaId=fams_current_lpgma_user_id();
if($lpgmaId<=0 && !empty($_SESSION['user']['lpgma_user_id'])) $lpgmaId=(int)$_SESSION['user']['lpgma_user_id'];

if($lpgmaId<=0 && $target==='maifip') {
    $currentId=(int)($_SESSION['user']['id']??0);
    if($currentId>0){
        $q=$pdo->prepare("SELECT lpgma_user_id FROM aics_users WHERE id=? AND identity_source='LPGMA_BRIDGED' AND lpgma_user_id IS NOT NULL LIMIT 1");
        $q->execute([$currentId]);
        $lpgmaId=(int)($q->fetchColumn()?:0);
    }
    if($lpgmaId<=0 && !empty($_SESSION['user']['username'])) {
        $q=$pdo->prepare("SELECT lpgma_user_id FROM aics_users WHERE username=? AND identity_source='LPGMA_BRIDGED' AND lpgma_user_id IS NOT NULL LIMIT 1");
        $q->execute([(string)$_SESSION['user']['username']]);
        $lpgmaId=(int)($q->fetchColumn()?:0);
    }
}
if($target==='maifip' && $lpgmaId>0){
    $db=fams_lpgma_db();
    if($db){
        $st=$db->prepare("SELECT id,username,full_name,email,role,facility,is_active,approval_status,auth_version FROM users WHERE id=? LIMIT 1");
        $st->execute([$lpgmaId]); $u=$st->fetch();
        if($u && (int)$u['is_active']===1 && fams_is_lpgma_role((string)$u['role'])){
            fams_begin_login('maifip',['id'=>(int)$u['id'],'auth_version'=>(int)($u['auth_version']??1),'username'=>$u['username'],'full_name'=>$u['full_name'],'role'=>$u['role'],'facility'=>$u['facility']??'LPGMA'],['identity_source'=>'LPGMA','lpgma_user_id'=>(int)$u['id']]);
            header('Location: ' . fams_switch_destination('maifip', $returnPage, '../maifip/dashboard.php')); exit;
        }
    }
}
if($target==='aics' && $lpgmaId>0){
    $db=fams_lpgma_db();
    if($db){
        $st=$db->prepare("SELECT id,username,full_name,email,password_hash,role,facility,is_active,approval_status,auth_version FROM users WHERE id=? LIMIT 1");
        $st->execute([$lpgmaId]); $u=$st->fetch();
        if($u && (int)$u['is_active']===1 && fams_is_lpgma_role((string)$u['role']) && ($u['approval_status']??'APPROVED')==='APPROVED'){
            $shadow=fams_sync_aics_shadow($pdo,$u,1);
            if($shadow){
                fams_begin_login('aics',['id'=>(int)$shadow['id'],'auth_version'=>(int)($shadow['auth_version']??1),'username'=>$shadow['username'],'full_name'=>$shadow['full_name'],'email'=>$shadow['email'],'role'=>$shadow['role'],'office_id'=>$shadow['office_id'] ? (int)$shadow['office_id']:null,'office_name'=>$shadow['office_name']??'LPGMA'],['identity_source'=>'LPGMA_BRIDGED','lpgma_user_id'=>$lpgmaId]);
                // Both workspaces use users.php as the canonical account route.
                // The destination page selects admin management or self-service
                // based on the authenticated user's role and workspace identity.
                header('Location: ' . fams_switch_destination('aics', $returnPage, 'dashboard.php')); exit;
            }
        }
    }
}
header('Location: ../index.php');
