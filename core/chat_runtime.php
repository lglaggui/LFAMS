<?php
declare(strict_types=1);
require_once __DIR__.'/shared_runtime.php';
function fams_chat_verify_request_csrf(): void { fams_shared_verify_csrf((string)($_POST['csrf_token']??''),true); }
function fams_chat_json_response(array $payload, string $workspace): never {
    $u=fams_shared_current_user()??[];
    $scope=$workspace==='aics'?(string)($u['office_id']??''):(string)($u['facility']??'');
    $payload['identity']=[
        'id'=>(int)($u['id']??0),'username'=>(string)($u['username']??''),
        'full_name'=>(string)($u['full_name']??''),'role'=>(string)($u['role']??''),
        'facility'=>$scope
    ];
    fams_shared_json($payload);
}

/** Shared chat schema manager with fixed AICS/MAIFIP storage adapters. */
function fams_chat_table_map(string $workspace): array {
    if($workspace==='aics') return ['messages'=>'aics_user_messages','trails'=>'aics_user_message_trails','read'=>'aics_chat_read_state','notifications'=>'aics_notifications'];
    if($workspace==='maifip') return ['messages'=>'user_messages','trails'=>'user_message_trails','read'=>'chat_read_state','notifications'=>'notifications'];
    throw new InvalidArgumentException('Unknown chat workspace.');
}
function fams_chat_ensure_schema(PDO $pdo, string $workspace): void {
    $t=fams_chat_table_map($workspace); $messages=$t['messages']; $trails=$t['trails']; $read=$t['read']; $notifications=$t['notifications'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$messages}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sender_id INT UNSIGNED NULL,recipient_id INT UNSIGNED NULL,group_key VARCHAR(32) NULL,message TEXT NOT NULL,edited_at TIMESTAMP NULL DEFAULT NULL,deleted_at TIMESTAMP NULL DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(sender_id),INDEX(recipient_id),INDEX(group_key),INDEX(created_at)) ENGINE=InnoDB");
    $columnCheck=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    foreach(['group_key'=>'VARCHAR(32) NULL AFTER recipient_id','edited_at'=>'TIMESTAMP NULL DEFAULT NULL','deleted_at'=>'TIMESTAMP NULL DEFAULT NULL'] as $column=>$definition){
        $columnCheck->execute([$messages,$column]);
        if(!(int)$columnCheck->fetchColumn()){
            try{$pdo->exec("ALTER TABLE `{$messages}` ADD COLUMN `{$column}` {$definition}");}
            catch(PDOException $migrationError){$vendor=(int)($migrationError->errorInfo[1]??0);if((string)$migrationError->getCode()!=='42S21'&&$vendor!==1060)throw new RuntimeException('Chat schema migration failed for '.$workspace.'.',0,$migrationError);}
            $columnCheck->execute([$messages,$column]);
            if(!(int)$columnCheck->fetchColumn())throw new RuntimeException('Chat schema migration could not be verified for '.$workspace.'.');
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$trails}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,message_id BIGINT UNSIGNED NULL,actor_id INT UNSIGNED NULL,action ENUM('EDIT','DELETE','ADMIN_DELETE','CLEAR_ALL') NOT NULL,message_before TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(message_id),INDEX(actor_id),INDEX(created_at)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$read}` (user_id INT UNSIGNED NOT NULL,conversation_key VARCHAR(80) NOT NULL,last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(user_id,conversation_key),INDEX idx_chat_read_user(user_id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$notifications}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,link VARCHAR(255) NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_user_read(user_id,is_read),INDEX idx_notif_created(created_at)) ENGINE=InnoDB");
}
function fams_chat_mark_read_cursor(PDO $pdo, string $workspace, int $userId, string $conversationKey, int $maxId): void {
    $table=fams_chat_table_map($workspace)['read'];
    $st=$pdo->prepare("INSERT INTO `{$table}`(user_id,conversation_key,last_read_message_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id))");
    $st->execute([$userId,$conversationKey,max(0,$maxId)]);
}
function fams_chat_get_read_cursor(PDO $pdo, string $workspace, int $userId, string $conversationKey): int {
    $table=fams_chat_table_map($workspace)['read'];
    $st=$pdo->prepare("SELECT last_read_message_id FROM `{$table}` WHERE user_id=? AND conversation_key=?");
    $st->execute([$userId,$conversationKey]); return (int)($st->fetchColumn()?:0);
}
