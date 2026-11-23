<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = fams_uppercase_text($_POST['full_name'] ?? '');
    $gmail = strtolower(trim($_POST['gmail'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $role = $_POST['requested_role'] ?? 'encoder';
    $facility = $_POST['facility'] ?? ($role === 'social_worker' ? 'SIMC' : 'LPGMA');

    if ($name === '' || $gmail === '' || $password === '' || $confirm === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($gmail, FILTER_VALIDATE_EMAIL) || !preg_match('/@gmail\.com$/i', $gmail)) {
        $error = 'Please use a valid Gmail address ending in @gmail.com.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($role, ['encoder', 'social_worker'], true) || !in_array($facility, array_merge(['LPGMA'], array_keys(facility_options())), true)) {
        $error = 'Please select a valid account type.';
    } elseif (($facility === 'LPGMA' && $role !== 'encoder') || ($facility !== 'LPGMA' && $role !== 'social_worker')) {
        $error = 'LPGMA Office uses Staff registration; medical facilities currently use Social Worker.';
    } else {
        $stmt = $pdo->prepare('SELECT id, approval_status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$gmail]);
        $existing = $stmt->fetch();
        if ($existing) {
            $error = $existing['approval_status'] === 'PENDING'
                ? 'A registration using this Gmail address is already pending Site Admin approval.'
                : 'This Gmail address is already registered in the system.';
        } else {
            $username = $gmail;
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $stmt->execute([$username]);
            if ((int)$stmt->fetchColumn() > 0) {
                $error = 'This Gmail address is already registered in the system.';
            } else {
                try {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    fams_shared_transaction_retry($pdo, function (PDO $db) use ($name, $username, $gmail, $passwordHash, $role, $facility): void {
                        $stmt = $db->prepare("INSERT INTO users(full_name, username, email, password_hash, role, facility, is_active, approval_status) VALUES(?,?,?,?,?,?,0,'PENDING')");
                        $stmt->execute([$name, $username, $gmail, $passwordHash, $role, $facility]);
                        $newUserId = (int)$db->lastInsertId();
                        fams_audit_write_critical($db, 'maifip', 'REGISTER_ACCOUNT', 'Submitted pending MAIFIP account registration for facility ' . $facility . ' and requested role ' . $role . '.', $newUserId > 0 ? $newUserId : null);
                    });
                    $success = 'Registration submitted. Your account will remain pending until the LPGMA Admin / Site Admin approves it.';
                } catch (PDOException $registrationError) {
                    if ((string)$registrationError->getCode() === '23000' || (int)($registrationError->errorInfo[1] ?? 0) === 1062) {
                        $error = 'This Gmail address is already registered in the system.';
                    } else {
                        error_log('MAIFIP account registration failed: ' . $registrationError->getMessage());
                        $error = 'Registration could not be completed. Please try again later.';
                    }
                } catch (Throwable $registrationError) {
                    error_log('MAIFIP account registration failed: ' . $registrationError->getMessage());
                    $error = 'Registration could not be completed. Please try again later.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | FAMS (Financial Assistance Monitoring System)</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-body">
<div class="login-shell">
    <section class="login-brand">
        <div class="brand-topline brand-logos"><div class="logo-card logo-card-lpgma"><img src="<?=e(system_media_url('lpgma_logo'))?>" alt="LPGMA Partylist logo"></div><div class="logo-divider"></div><div class="logo-card logo-card-simc"><img src="<?=e(system_media_url('simc_logo'))?>" alt="SIMC logo"></div><div class="brand-label"><strong>LPGMA</strong><span>Isabela District</span></div></div>
        <div class="brand-main"><span class="eyebrow">FINANCIAL ASSISTANCE</span><h1>Request a<br>System Account</h1><p>This is a system initiative of LPGMA Isabela District for participating medical facilities. Register using your Gmail address. Your account must be approved by the LPGMA Admin / Site Admin before you can sign in.</p></div>
        <div class="brand-stats"><div><strong><?=date('Y')?></strong><span>System Year</span></div><div><strong>Gmail</strong><span>Registration</span></div><div><strong>Admin</strong><span>Approval Required</span></div></div>
        <div class="login-orb orb-one"></div><div class="login-orb orb-two"></div>
    </section>
    <section class="login-form-side"><div class="login-card">
        <div class="mobile-logo mobile-branding"><div class="mobile-logo-card"><img src="<?=e(system_media_url('lpgma_logo'))?>" alt="LPGMA logo"></div><div class="mobile-logo-card simc"><img src="<?=e(system_media_url('simc_logo'))?>" alt="SIMC logo"></div><span><strong>LPGMA Isabela District</strong><small>LPGMA FAMS (Financial Assistance Monitoring System)</small></span></div>
        <div class="login-heading"><span class="eyebrow orange">ACCOUNT REGISTRATION</span><h2>Create an account</h2><p>Use your Gmail address and wait for Site Admin approval.</p></div>
        <?php if (!empty($error)): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
        <?php if (!empty($success)): ?><div class="alert alert-success"><?=e($success)?></div><?php endif; ?>
        <?php if (empty($success)): ?>
        <form method="post" class="login-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
            <label>Full Name</label><div class="input-control"><span class="input-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20 21a8 8 0 0 0-16 0M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/></svg></span><input type="text" name="full_name" placeholder="Enter your full name" value="<?=e($_POST['full_name'] ?? '')?>" required></div>
            <label>Gmail Address</label><div class="input-control"><span class="input-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M16 8.5v5a2.5 2.5 0 0 1-5 0v-2a2.5 2.5 0 0 1 5 0v2c0 1.7 1.3 3 3 3"/></svg></span><input type="email" name="gmail" placeholder="name@gmail.com" value="<?=e($_POST['gmail'] ?? '')?>" required></div>
            <label>Facility</label><div class="input-control"><span class="input-symbol" aria-hidden="true">⌂</span><select name="facility" id="registerFacility" style="width:100%;height:54px;border:1px solid var(--line);border-radius:11px;padding:0 15px 0 50px;background:#fbfcfe;outline:none" required><option value="LPGMA" <?=((($_POST['facility']??'')==='LPGMA') || !isset($_POST['facility']))?'selected':''?>>LPGMA Office</option><?php foreach(facility_options() as $fk=>$fi): ?><option value="<?=e($fk)?>" <?=((($_POST['facility']??'')===$fk))?'selected':''?>><?=e($fi['name'])?> (<?=e($fk)?>)</option><?php endforeach; ?></select></div>
            <label>Requested User Type</label><div class="input-control"><span class="input-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="1.5"/><circle cx="16" cy="12" r="1.5"/><circle cx="10" cy="18" r="1.5"/></svg></span><select name="requested_role" style="width:100%;height:54px;border:1px solid var(--line);border-radius:11px;padding:0 15px 0 50px;background:#fbfcfe;outline:none" required><option value="encoder">LPGMA Staff</option><option value="social_worker">Social Worker</option></select></div>
            <label>Password</label><div class="input-control"><span class="input-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><input id="password" type="password" name="password" placeholder="At least 6 characters" minlength="6" required></div>
            <label>Confirm Password</label><div class="input-control"><span class="input-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><input type="password" name="confirm_password" placeholder="Re-enter password" minlength="6" required></div>
            <button class="primary-btn login-submit" type="submit">SUBMIT REGISTRATION <span>→</span></button>
        </form>
        <?php endif; ?>
        <div style="text-align:center;margin-top:15px"><a class="text-link" href="login.php">← Back to Login</a></div>
        <div class="security-strip"><span class="shield">✓</span><p>Registration does not grant access immediately. A Site Admin must approve the account first.</p></div>
        <p class="copyright">© <?=date('Y')?> LPGMA Isabela District • LPGMA FAMS (Financial Assistance Monitoring System)</p>
    </div></section>
</div>
</body></html>
