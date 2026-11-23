<?php
declare(strict_types=1);
require_once __DIR__.'/unified.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['ok'=>false,'error'=>'Method not allowed.']); exit; }
if (empty($_SESSION['user']['id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Session expired.']); exit; }
$origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
if($origin!==''){ $originParts=parse_url($origin); $originHost=strtolower((string)($originParts['host']??'')); if(isset($originParts['port']))$originHost.=':'.(int)$originParts['port']; if($originHost!==strtolower((string)($_SERVER['HTTP_HOST']??''))){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Forbidden.']);exit;} }
$fetchSite=strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''));
if ($fetchSite!=='' && !in_array($fetchSite,['same-origin','same-site'],true)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden.']); exit; }
$_SESSION['fams_last_activity_at']=time();
echo json_encode(['ok'=>true]);
