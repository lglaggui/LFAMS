<?php
declare(strict_types=1);

/**
 * Single source of truth for chat unread badges.
 * Historical messages never become unread merely because the badge system was introduced.
 * Only incoming, non-deleted messages newer than the user's cursor are counted.
 */
function fams_chat_unread_state(PDO $pdo, int $me, string $role): array {
    // The notification endpoint can be the first chat endpoint called after an upgrade.
    // Make sure the fields used by unread counting exist before querying them.
    $columns = [
        'group_key' => 'VARCHAR(32) NULL AFTER recipient_id',
        'edited_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'deleted_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ];
    foreach ($columns as $column => $definition) {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_messages' AND COLUMN_NAME=".$pdo->quote($column))->fetchColumn();
        if (!$exists) {
            try { $pdo->exec("ALTER TABLE user_messages ADD COLUMN {$column} {$definition}"); }
            catch (Throwable $migrationError) {
                $verify=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_messages' AND COLUMN_NAME=?");
                $verify->execute([$column]);
                if((int)$verify->fetchColumn()===0)throw new RuntimeException('MAIFIP chat schema migration failed for '.$column.'.',0,$migrationError);
            }
        }
        $verify=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_messages' AND COLUMN_NAME=?");
        $verify->execute([$column]);
        if((int)$verify->fetchColumn()!==1)throw new RuntimeException('MAIFIP chat schema verification failed for '.$column.'.');
    }
    $meUserSt = $pdo->prepare('SELECT id, role, facility FROM users WHERE id=? LIMIT 1');
    $meUserSt->execute([$me]);
    $meUser = $meUserSt->fetch() ?: ['role'=>$role, 'facility'=>null];
    $office = user_facility($meUser);
    // Every authenticated user can access every office group and the all-users group.
    $groups = array_merge(['LPGMA'], array_keys(facility_options()),['ALL']);
    $counts = [];
    $totalMessages = 0;
    $totalConversations = 0;

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_read_state (
        user_id INT UNSIGNED NOT NULL,
        conversation_key VARCHAR(64) NOT NULL,
        last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id,conversation_key),
        INDEX idx_chat_read_user(user_id)
    ) ENGINE=InnoDB");

    $state = $pdo->prepare('SELECT last_read_message_id FROM chat_read_state WHERE user_id=? AND conversation_key=?');
    $seed = $pdo->prepare('INSERT IGNORE INTO chat_read_state(user_id,conversation_key,last_read_message_id) VALUES(?,?,?)');
    $normalizeZero = $pdo->prepare('UPDATE chat_read_state SET last_read_message_id=? WHERE user_id=? AND conversation_key=? AND last_read_message_id=0');

    $add = static function(string $key, int $count) use (&$counts,&$totalMessages,&$totalConversations): void {
        if ($count > 0) {
            $counts[$key] = $count;
            $totalMessages += $count;
            $totalConversations++;
        }
    };

    $activeChatFacilities=array_keys(facility_options());
if($activeChatFacilities){
    $ph=implode(',',array_fill(0,count($activeChatFacilities),'?'));
    $stActiveUsers=$pdo->prepare("SELECT id FROM users WHERE is_active=1 AND id<>? AND (facility='LPGMA' OR facility IN ($ph))");
    $stActiveUsers->execute(array_merge([(int)$me],$activeChatFacilities)); $users=$stActiveUsers->fetchAll(PDO::FETCH_COLUMN);
}else{
    $stActiveUsers=$pdo->prepare("SELECT id FROM users WHERE is_active=1 AND id<>? AND facility='LPGMA'"); $stActiveUsers->execute([(int)$me]); $users=$stActiveUsers->fetchAll(PDO::FETCH_COLUMN);
}
    $maxPersonal = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)) AND group_key IS NULL');
    $countPersonal = $pdo->prepare('SELECT COUNT(*) FROM user_messages WHERE recipient_id=? AND sender_id=? AND group_key IS NULL AND deleted_at IS NULL AND id>?');

    foreach ($users as $uid) {
        $uid = (int)$uid;
        $key = 'u:'.$uid;
        $maxPersonal->execute([$me,$uid,$uid,$me]);
        $max = (int)$maxPersonal->fetchColumn();
        $state->execute([$me,$key]);
        $cursorRow = $state->fetchColumn();
        if ($cursorRow === false) {
            // First time seeing this conversation: start at its current end.
            $seed->execute([$me,$key,$max]);
            $cursor = $max;
        } else {
            $cursor = (int)$cursorRow;
            if ($cursor === 0) {
                // Legacy cursor: never turn historical messages into unread messages.
                $normalizeZero->execute([$max,$me,$key]);
                $cursor = $max;
            }
        }
        $countPersonal->execute([$me,$uid,$cursor]);
        $add($key,(int)$countPersonal->fetchColumn());
    }

    foreach ($groups as $g) {

        $key = 'g:'.$g;
        if ($g === 'ALL') {
            $mx = $pdo->query("SELECT COALESCE(MAX(id),0) FROM user_messages WHERE (group_key='ALL' OR (group_key IS NULL AND recipient_id IS NULL))");
        } else {
            $mx = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM user_messages WHERE group_key=?');
            $mx->execute([$g]);
        }
        $max = (int)$mx->fetchColumn();
        $state->execute([$me,$key]);
        $cursorRow = $state->fetchColumn();
        if ($cursorRow === false) {
            $seed->execute([$me,$key,$max]);
            $cursor = $max;
        } else {
            $cursor = (int)$cursorRow;
            if ($cursor === 0) {
                $normalizeZero->execute([$max,$me,$key]);
                $cursor = $max;
            }
        }

        if ($g === 'ALL') {
            $q = $pdo->prepare("SELECT COUNT(*) FROM user_messages WHERE (group_key='ALL' OR (group_key IS NULL AND recipient_id IS NULL)) AND sender_id<>? AND deleted_at IS NULL AND id>?");
            $q->execute([$me,$cursor]);
        } else {
            $q = $pdo->prepare('SELECT COUNT(*) FROM user_messages WHERE group_key=? AND sender_id<>? AND deleted_at IS NULL AND id>?');
            $q->execute([$g,$me,$cursor]);
        }
        $add($key,(int)$q->fetchColumn());
    }

    return [
        'counts' => $counts,
        'unread_messages' => $totalMessages,
        'unread_conversations' => $totalConversations,
    ];
}
