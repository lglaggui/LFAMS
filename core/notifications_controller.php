<?php
declare(strict_types=1);
require_once __DIR__.'/shared_runtime.php';

function fams_notifications_ensure_schema(PDO $pdo, string $table): void {
    $allowed=['notifications','aics_notifications'];
    if (!in_array($table,$allowed,true)) throw new InvalidArgumentException('Unsupported notification store.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,link VARCHAR(255) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_user_read(user_id,is_read),INDEX idx_notif_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function fams_notifications_dispatch(PDO $pdo, string $table, int $userId, array $extra = []): never {
    if (!in_array($table,['notifications','aics_notifications'],true) || $userId<1) fams_shared_json(['ok'=>false,'error'=>'Notification service is unavailable.'],400);
    $action=(string)($_GET['action']??$_POST['action']??'list');
    if (in_array($action,['read','read_all','delete','delete_all'],true)) fams_shared_verify_csrf();
    if ($action==='read_all') { $pdo->prepare("UPDATE `{$table}` SET is_read=1 WHERE user_id=? AND is_read=0")->execute([$userId]); fams_shared_json(['ok'=>true]); }
    if ($action==='read') { $id=(int)($_POST['id']??0); if($id<1) fams_shared_json(['ok'=>false,'error'=>'Invalid notification.'],400); $pdo->prepare("UPDATE `{$table}` SET is_read=1 WHERE id=? AND user_id=?")->execute([$id,$userId]); fams_shared_json(['ok'=>true]); }
    if ($action==='delete') { $id=(int)($_POST['id']??0); if($id<1) fams_shared_json(['ok'=>false,'error'=>'Invalid notification.'],400); $st=$pdo->prepare("DELETE FROM `{$table}` WHERE id=? AND user_id=?"); $st->execute([$id,$userId]); fams_shared_json(['ok'=>true,'deleted'=>$st->rowCount()]); }
    if ($action==='delete_all') { $st=$pdo->prepare("DELETE FROM `{$table}` WHERE user_id=?"); $st->execute([$userId]); fams_shared_json(['ok'=>true,'deleted'=>$st->rowCount()]); }
    if ($action!=='list') fams_shared_json(['ok'=>false,'error'=>'Unsupported notification action.'],400);
    $st=$pdo->prepare("SELECT id,title,message,link,is_read,DATE_FORMAT(created_at,'%b %d, %Y %h:%i %p') created_at FROM `{$table}` WHERE user_id=? ORDER BY id DESC LIMIT 30"); $st->execute([$userId]); $items=$st->fetchAll(PDO::FETCH_ASSOC);
    $un=$pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE user_id=? AND is_read=0"); $un->execute([$userId]);
    fams_shared_json(array_merge(['ok'=>true,'unread'=>(int)$un->fetchColumn(),'items'=>$items],$extra));
}
