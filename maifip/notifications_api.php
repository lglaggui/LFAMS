<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once dirname(__DIR__).'/core/notifications_controller.php';
require_once __DIR__.'/chat_unread_helper.php';
ini_set('display_errors','0');
if (!is_logged_in()) fams_shared_json(['ok'=>false,'error'=>'Your session has expired. Please sign in again.'],401);
require_login();
try {
    fams_notifications_ensure_schema($pdo,'notifications');
    // Keep the MAIFIP chat read-counter adapter migration-safe when notifications poll first.
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_change_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,requested_email VARCHAR(150) NOT NULL,status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_by INT UNSIGNED NULL,reviewed_at TIMESTAMP NULL DEFAULT NULL,INDEX idx_email_change_user_status(user_id,status),INDEX idx_email_change_status(status),INDEX idx_email_change_email(requested_email)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sender_id INT UNSIGNED NULL,recipient_id INT UNSIGNED NULL,group_key VARCHAR(32) NULL,message TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(sender_id),INDEX(recipient_id),INDEX(group_key),INDEX(created_at)) ENGINE=InnoDB");
    $me=(int)current_user()['id']; $role=(string)(current_user()['role']??'');
    $chatState=fams_chat_unread_state($pdo,$me,$role);
    $chat=(int)($chatState['unread_messages']??0);
    if($role==='admin') {
        $pendingTickets=(int)$pdo->query("SELECT COUNT(*) FROM revision_tickets WHERE status='OPEN'")->fetchColumn();
        $pendingAccounts=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE approval_status='PENDING'")->fetchColumn();
        try { $pendingAccounts+=(int)$pdo->query("SELECT COUNT(*) FROM email_change_requests WHERE status='PENDING'")->fetchColumn(); } catch(Throwable $ignored) {}
    } else {
        $st=$pdo->prepare("SELECT COUNT(*) FROM revision_tickets WHERE status='OPEN' AND requested_by=?"); $st->execute([$me]);
        $pendingTickets=(int)$st->fetchColumn(); $pendingAccounts=0;
    }
    fams_notifications_dispatch($pdo,'notifications',$me,[
        'chat_unread'=>$chat,'pending_tickets'=>$pendingTickets,'pending_accounts'=>$pendingAccounts
    ]);
} catch(Throwable $e) {
    error_log('MAIFIP notifications: '.$e->getMessage());
    fams_shared_json(['ok'=>false,'error'=>'Notification service error. Please try again.'],500);
}
