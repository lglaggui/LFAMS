<?php
require_once __DIR__ . '/config.php';
require_login();
if (!fams_current_type_allows('AICS','view_action_logs')) { http_response_code(403); exit('Your user type does not have permission to view action logs. Contact the Site Administrator if this access is required.'); }
require_once dirname(__DIR__) . '/core/audit_runtime.php';
$auditData = fams_audit_page($pdo, 'aics', $_GET, max(1,(int)($_GET['page'] ?? 1)), 50);
$workspaceLabel = 'AICS / SWAD';
$pageTitle = 'Action Logs';
require __DIR__ . '/partials/header.php';
require dirname(__DIR__) . '/core/audit_logs_view.php';
require __DIR__ . '/partials/footer.php';
