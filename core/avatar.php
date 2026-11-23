<?php
declare(strict_types=1);
require_once __DIR__ . '/unified.php';
if (empty($_SESSION['user']) || empty($_SESSION['fams_system'])) { http_response_code(401); exit; }
$id=(int)($_GET['user']??0); if($id<=0){http_response_code(404);exit;}
try {
  $pdo=fams_lpgma_db(); if(!$pdo){http_response_code(404);exit;}
  $st=$pdo->prepare('SELECT username,role,avatar_mime,avatar_data,avatar_filename,updated_at FROM users WHERE id=? AND is_active=1 LIMIT 1');
  $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r){http_response_code(404);exit;}
  $mime=strtolower((string)($r['avatar_mime']??'')); $data=$r['avatar_data']??null;
  if(is_string($data)&&$data!==''&&in_array($mime,['image/jpeg','image/png','image/webp'],true)){
    header('Content-Type: '.$mime); header('Cache-Control: private, no-cache, must-revalidate'); header('X-Content-Type-Options: nosniff'); header('Content-Length: '.strlen($data)); echo $data; exit;
  }
  $fallback = ((string)($r['role'] ?? '') === 'admin') ? __DIR__.'/branding/default-admin-avatar.webp' : __DIR__.'/branding/default-regular-avatar.webp';
  if(is_file($fallback)){header('Content-Type: image/webp');header('Cache-Control: public,max-age=86400');header('X-Content-Type-Options: nosniff');readfile($fallback);exit;}
  http_response_code(404);
} catch(Throwable $e){http_response_code(404);}
