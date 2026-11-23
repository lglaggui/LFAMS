<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/unified.php';
require_once dirname(__DIR__) . '/core/database.php';
require_once dirname(__DIR__) . '/core/access_control.php';
require_once dirname(__DIR__) . '/core/account_runtime.php';
require_once dirname(__DIR__) . '/maifip/app_version.php';

if (!function_exists('fams_login_escape')) {
    function fams_login_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
if (empty($_SESSION['auth_csrf'])) $_SESSION['auth_csrf'] = bin2hex(random_bytes(32));
function fams_login_redirect(string $url): never { header('Location: ' . $url); exit; }
function fams_login_destination(string $workspace, array $user): string {
    if ($workspace === 'portal') return '../portal/select-system.php';
    if ($workspace === 'maifip') return '../maifip/' . (($user['role'] ?? '') === 'social_worker' ? 'records.php' : 'dashboard.php');
    return '../aics/dashboard.php';
}

if (!empty($_SESSION['user']['id'])) {
    $workspace = (string)($_SESSION['fams_system'] ?? '');
    if ($workspace === 'portal' && fams_is_lpgma_role((string)($_SESSION['user']['role'] ?? ''))) fams_login_redirect('../portal/select-system.php');
    if ($workspace === 'maifip') fams_login_redirect(fams_login_destination('maifip', (array)$_SESSION['user']));
    if ($workspace === 'aics') fams_login_redirect('../aics/dashboard.php');
}
$error = '';
if (!empty($_SESSION['fams_session_expired'])) { $error = 'Your session expired due to inactivity or its maximum lifetime. Please sign in again.'; unset($_SESSION['fams_session_expired']); }
if (!empty($_SESSION['fams_session_invalidated'])) { $error = 'Your account or security settings changed. Please sign in again.'; unset($_SESSION['fams_session_invalidated'],$_SESSION['fams_session_invalid_reason']); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['auth_csrf'], $token)) {
        http_response_code(419); $error = 'Your login form expired. Refresh this page and try again.';
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($identifier === '' || $password === '') {
            $error = 'Enter your username/email and password.';
        } else {
            try {
                $pdo = lfams_pdo();
                fams_account_ensure_session_version_schema($pdo);
                fams_ensure_usertype_schema($pdo);
                $authenticated = false;
                // The central users table is authoritative for LPGMA and MAIFIP accounts.
                $st = $pdo->prepare('SELECT id,full_name,username,email,password_hash,role,facility,is_active,approval_status,auth_version FROM users WHERE username=? OR email=? OR full_name=? ORDER BY is_active DESC,id ASC');
                $st->execute([$identifier, $identifier, $identifier]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                    if ((int)$candidate['is_active'] !== 1 || !password_verify($password, (string)$candidate['password_hash'])) continue;
                    if (($candidate['approval_status'] ?? 'APPROVED') !== 'APPROVED') { $error = 'Your account is pending Site Admin approval.'; continue; }
                    $facility = trim((string)($candidate['facility'] ?? ''));
                    $storedRole = (string)($candidate['role'] ?? '');
                    $role = fams_base_role($pdo, 'MAIFIP', $storedRole);
                    if ($role === '__disabled__') { $error = 'This user type is disabled. Please contact the Site Administrator.'; continue; }
                    if (fams_is_lpgma_role($role) && ($facility === '' || strtoupper($facility) === 'LPGMA')) {
                        fams_begin_login('portal', [
                            'id'=>(int)$candidate['id'], 'auth_version'=>(int)($candidate['auth_version']??1), 'username'=>$candidate['username'], 'full_name'=>$candidate['full_name'],
                            'email'=>$candidate['email'] ?? '', 'role'=>$role, 'facility'=>'LPGMA'
                        ], ['identity_source'=>'LPGMA','lpgma_user_id'=>(int)$candidate['id']]);
                        $_SESSION['fams_user_type'] = 'LPGMA';
                        fams_login_redirect('../portal/select-system.php');
                    }
                    // Hospital/medical facility accounts always enter MAIFIP.
                    if ($role === 'social_worker' || ($facility !== '' && strtoupper($facility) !== 'LPGMA')) {
                        if ($facility === '') $facility = 'SIMC';
                        // Respect the MAIFIP facility activation flag before creating a session.
                        try {
                            $facilityCheck = $pdo->prepare('SELECT is_active FROM fams_facilities WHERE facility_key=? LIMIT 1');
                            $facilityCheck->execute([$facility]);
                            $facilityActive = $facilityCheck->fetchColumn();
                            if ($facilityActive !== false && (int)$facilityActive !== 1) {
                                $error = 'This facility is currently disabled. Please contact the Site Admin.';
                                continue;
                            }
                        } catch (Throwable $ignored) { /* older installations may not have the facility registry */ }
                        fams_begin_login('maifip', [
                            'id'=>(int)$candidate['id'], 'auth_version'=>(int)($candidate['auth_version']??1), 'username'=>$candidate['username'], 'full_name'=>$candidate['full_name'],
                            'email'=>$candidate['email'] ?? '', 'role'=>$role, 'facility'=>$facility
                        ], ['identity_source'=>'MAIFIP','lpgma_user_id'=>null]);
                        $_SESSION['fams_user_type'] = 'MAIFIP';
                        fams_login_redirect(fams_login_destination('maifip', array_merge($candidate, ['role'=>$role])));
                    }
                }
                // Local SWAD accounts live in aics_users. They must never gain MAIFIP access.
                $st = $pdo->prepare('SELECT u.*,o.name AS office_name FROM aics_users u LEFT JOIN aics_offices o ON o.id=u.office_id WHERE u.username=? OR u.email=? ORDER BY u.is_active DESC,u.id ASC');
                $st->execute([$identifier, $identifier]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                    if ((int)$candidate['is_active'] !== 1 || !password_verify($password, (string)$candidate['password_hash'])) continue;
                    $source = (string)($candidate['identity_source'] ?? 'LOCAL_AICS');
                    if ($source === 'LPGMA_BRIDGED' || !empty($candidate['lpgma_user_id'])) {
                        $lpgmaId = (int)($candidate['lpgma_user_id'] ?? 0);
                        $lpgma = $pdo->prepare('SELECT id,full_name,username,email,password_hash,role,facility,is_active,approval_status,auth_version FROM users WHERE id=? LIMIT 1');
                        $lpgma->execute([$lpgmaId]); $original = $lpgma->fetch(PDO::FETCH_ASSOC);
                        if ($original && (int)$original['is_active'] === 1 && fams_is_lpgma_role(fams_base_role($pdo, 'MAIFIP', (string)$original['role']))) {
                            fams_begin_login('portal', ['id'=>(int)$original['id'],'auth_version'=>(int)($original['auth_version']??1),'username'=>$original['username'],'full_name'=>$original['full_name'],'email'=>$original['email']??'','role'=>$original['role'],'facility'=>'LPGMA'], ['identity_source'=>'LPGMA','lpgma_user_id'=>$lpgmaId]);
                            $_SESSION['fams_user_type'] = 'LPGMA';
                            fams_login_redirect('../portal/select-system.php');
                        }
                        continue;
                    }
                    fams_begin_login('aics', [
                        'id'=>(int)$candidate['id'], 'auth_version'=>(int)($candidate['auth_version']??1), 'username'=>$candidate['username'], 'full_name'=>$candidate['full_name'],
                        'email'=>$candidate['email'] ?? '', 'role'=>$candidate['role'],
                        'office_id'=>!empty($candidate['office_id'])?(int)$candidate['office_id']:null,
                        'office_name'=>$candidate['office_name'] ?? null
                    ], ['identity_source'=>'LOCAL_AICS','lpgma_user_id'=>null]);
                    $_SESSION['fams_user_type'] = 'SWAD';
                    fams_login_redirect('../aics/dashboard.php');
                }
                if ($error === '') $error = 'Invalid username/email or password, or your account is inactive.';
            } catch (Throwable $e) {
                error_log('Unified FAMS login failed: ' . $e->getMessage());
                http_response_code(500);
                $error = 'The system could not verify your account. Please contact the system administrator.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123f67"><title>Sign In | FAMS Region II</title><link rel="stylesheet" href="../maifip/assets/app.css?v=<?=rawurlencode(APP_VERSION)?>"><link rel="stylesheet" href="../core/uniform.css?v=<?=rawurlencode(APP_VERSION)?>"><style>
.unified-login{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);background:#f3f6fa;color:#152b40}.unified-login .login-brand{position:relative;overflow:hidden}.unified-login .login-form-side{display:flex;align-items:center;justify-content:center;padding:34px 24px}.unified-login .login-card{width:min(100%,470px)}.unified-brand-tag{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,.24);padding:8px 12px;border-radius:999px;color:#e8f2fb;font-size:11px;letter-spacing:.08em}.unified-login .login-heading h2{font-size:30px}.unified-login .login-submit{width:100%;margin-top:18px}.unified-login .login-logo{width:56px;height:56px;object-fit:contain;background:#fff;border-radius:13px;padding:5px}.unified-login .brand-topline{display:flex;align-items:center;gap:13px}.unified-login .brand-label{display:flex;flex-direction:column}.unified-login .brand-label strong{font-size:13px}.unified-login .brand-label span{font-size:11px}.unified-login .unified-caption{font-size:12px;color:#5c7185;line-height:1.6;text-align:center;margin-top:20px}.unified-login .alert{margin:12px 0}.unified-login .copyright{text-align:center}@media(max-width:1100px){.unified-login{grid-template-columns:minmax(0,1fr)}}@media(max-width:760px){.unified-login{grid-template-columns:1fr}.unified-login .login-brand{min-height:230px;padding:25px}.unified-login .brand-main{margin-top:24px}.unified-login .brand-main h1{font-size:28px}.unified-login .brand-capabilities{display:none}.unified-login .login-form-side{padding:25px 18px}.unified-login .login-card{max-width:500px}}
</style></head><body class="login-body"><div class="login-shell unified-login"><section class="login-brand"><div class="brand-topline"><img class="login-logo" src="../core/branding/lpgma-shared-logo.webp" alt="LPGMA Isabela District"><div class="brand-label"><strong>LPGMA ISABELA DISTRICT</strong><span>REGION II • CAGAYAN VALLEY</span></div></div><div class="brand-main"><span class="unified-brand-tag">ONE APPLICATION · MULTIPLE WORKSPACES</span><h1>Financial Assistance<br>Monitoring System</h1><p>One secure sign-in for LPGMA, DOH Region II MAIFIP facilities, and DSWD SWAD AICS offices.</p></div><div class="brand-capabilities"><div><strong>Unified</strong><span>One login and session</span></div><div><strong>Secure</strong><span>Role and facility-based access</span></div><div><strong>Audited</strong><span>Existing activity controls retained</span></div></div></section><section class="login-form-side"><div class="login-card"><div class="login-heading"><span class="eyebrow orange">AUTHORIZED ACCESS</span><h2>Welcome back</h2><p>Sign in with your assigned FAMS account.</p></div><?php if($error!==''): ?><div class="alert alert-danger" role="alert"><?=fams_login_escape($error)?></div><?php endif; ?><form method="post" class="login-form" autocomplete="on"><input type="hidden" name="csrf_token" value="<?=fams_login_escape((string)$_SESSION['auth_csrf'])?>"><label for="identifier">Username, email, or full name</label><div class="input-control"><input id="identifier" type="text" name="identifier" placeholder="Enter your username or email" autocomplete="username" required autofocus></div><label for="password">Password</label><div class="input-control"><input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button type="button" class="password-toggle" onclick="const p=document.getElementById('password');const s=p.type==='password';p.type=s?'text':'password';this.textContent=s?'HIDE':'SHOW'">SHOW</button></div><button class="primary-btn login-submit" type="submit">SIGN IN TO FAMS <span>→</span></button></form><p class="unified-caption">Your workspace is selected automatically based on your authorized account. LPGMA users can choose an available workspace after signing in.</p><p class="copyright">© <?=date('Y')?> LPGMA Isabela District • FAMS Region II • Version <?=fams_login_escape(APP_VERSION)?></p></div></section></div></body></html>
