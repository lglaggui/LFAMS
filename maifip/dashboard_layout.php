<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
$layoutKey=preg_replace('/[^A-Za-z0-9_-]/','',(string)($_GET['layout_key']??'global')); $layoutKey=$layoutKey!==''?$layoutKey:'global';
$pdo->exec("CREATE TABLE IF NOT EXISTS dashboard_layouts (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, layout_key VARCHAR(80) NOT NULL, config_json LONGTEXT NOT NULL, updated_by INT UNSIGNED NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_dashboard_layout_key (layout_key)) ENGINE=InnoDB");
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt=$pdo->prepare('SELECT config_json,updated_at FROM dashboard_layouts WHERE layout_key=? LIMIT 1'); $stmt->execute([$layoutKey]); $row=$stmt->fetch();
    // Preserve a layout saved by the previous Version 100 build when moving
    // to Version 1; after the first save, Version 1 becomes authoritative.
    if(!$row && $layoutKey==='global_v1'){
        $stmt->execute(['global_v100']); $row=$stmt->fetch();
    }
    if($row){ $stored=json_decode($row['config_json'],true); if(is_array($stored) && isset($stored['layout']) && is_array($stored['layout'])) echo json_encode(['ok'=>true,'layout'=>$stored['layout'],'saved_at'=>(int)($stored['saved_at']??strtotime((string)$row['updated_at']))]); else echo json_encode(['ok'=>true,'layout'=>$stored,'saved_at'=>strtotime((string)$row['updated_at'])]); } else echo json_encode(['ok'=>true,'layout'=>null,'saved_at'=>0]); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['user']['role']??'') !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin access required.']); exit; }
$rawInput=file_get_contents('php://input');
if (!is_string($rawInput) || strlen($rawInput)>131072) { http_response_code(413); echo json_encode(['ok'=>false,'error'=>'Dashboard layout payload is too large.']); exit; }
$input=json_decode($rawInput,true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON payload.']); exit; }
fams_shared_verify_csrf((string)($input['csrf_token']??($_SERVER['HTTP_X_CSRF_TOKEN']??'')), true);
$layout=$input['layout']??null; $savedAt=(int)($input['saved_at']??round(microtime(true)*1000));
if (!is_array($layout) || count($layout)>100) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid dashboard layout.']); exit; }
$json=json_encode(['layout'=>$layout,'saved_at'=>$savedAt],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$stmt=$pdo->prepare('INSERT INTO dashboard_layouts (layout_key,config_json,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE config_json=VALUES(config_json),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP');
$stmt->execute([$layoutKey,$json,(int)($_SESSION['user']['id']??0)]);
log_action($pdo,'SAVE_DASHBOARD_LAYOUT','Saved the shared global dashboard layout.');
echo json_encode(['ok'=>true,'saved_at'=>$savedAt]);
