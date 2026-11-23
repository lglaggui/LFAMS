<?php
require_once __DIR__ . '/config.php';
if (is_logged_in()) { try { log_action($pdo, 'LOGOUT', 'User logged out.'); } catch (Throwable $e) {} }
header('Location: ../auth/logout.php'); exit;
