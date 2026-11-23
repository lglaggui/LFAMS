<?php
require_once __DIR__ . '/config.php';
if (!is_logged_in()) redirect('login.php');
redirect(user_facility(current_user() ?: []) === 'LPGMA' && current_user()['role'] !== 'social_worker' ? 'dashboard.php' : 'records.php');
