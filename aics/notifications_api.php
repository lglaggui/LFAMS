<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once dirname(__DIR__).'/core/notifications_controller.php';
ini_set('display_errors','0');
if (!is_logged_in()) fams_shared_json(['ok'=>false,'error'=>'Your session has expired. Please sign in again.'],401);
require_login();
try {
    fams_notifications_ensure_schema($pdo,'aics_notifications');
    $pendingTickets=0; $pendingAccounts=0;
    try { $pendingTickets=(int)$pdo->query("SELECT COUNT(*) FROM aics_tickets WHERE status='OPEN'")->fetchColumn(); } catch(Throwable $ignored) {}
    try { $pendingAccounts=(int)$pdo->query("SELECT COUNT(*) FROM aics_users WHERE approval_status='PENDING'")->fetchColumn(); } catch(Throwable $ignored) {}
    fams_notifications_dispatch($pdo,'aics_notifications',(int)current_user()['id'],[
        'chat_unread'=>0,'pending_tickets'=>$pendingTickets,'pending_accounts'=>$pendingAccounts
    ]);
} catch(Throwable $e) {
    error_log('AICS notifications: '.$e->getMessage());
    fams_shared_json(['ok'=>false,'error'=>'Notification service error. Please try again.'],500);
}
