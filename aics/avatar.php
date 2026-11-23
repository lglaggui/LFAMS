<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_login();
$id=(int)($_GET['user']??0); if($id<=0){http_response_code(404);exit;}
$st=$pdo->prepare('SELECT role,avatar_mime,avatar_data,identity_source,lpgma_user_id FROM aics_users WHERE id=? LIMIT 1'); $st->execute([$id]); $r=$st->fetch(); if(!$r){http_response_code(404);exit;}
if (($r['identity_source']??'')==='LPGMA_BRIDGED' && (int)($r['lpgma_user_id']??0)>0) {
    // LPGMA-linked users use the canonical MAIFIP avatar endpoint, not an AICS default.
    header('Cache-Control: private, max-age=300');
    header('Location: ../core/avatar.php?user='.(int)$r['lpgma_user_id'], true, 302); exit;
}
$mime=strtolower((string)($r['avatar_mime']??'')); $data=$r['avatar_data']??null;
if(is_string($data)&&$data!==''&&in_array($mime,['image/jpeg','image/png','image/webp'],true)){
 header('Cache-Control: private, no-cache, must-revalidate'); header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff'); echo $data; exit;
}
$file=((string)$r['role']==='admin') ? __DIR__.'/assets/avatars/default-administrator-avatar.webp' : __DIR__.'/assets/avatars/default-user-avatar.webp';
if(!is_file($file)){http_response_code(404);exit;} header('Cache-Control: public,max-age=86400'); header('Content-Type:image/webp'); readfile($file);
