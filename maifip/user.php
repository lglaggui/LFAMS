<?php
require_once __DIR__.'/config.php'; require_login(); if ((current_user()['role'] ?? '') !== 'admin') { header('Location: account.php'); exit; } require __DIR__.'/users.php';
