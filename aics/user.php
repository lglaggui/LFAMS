<?php require_once __DIR__.'/config.php'; require_login(); if (!user_is_admin()) { header('Location: account.php'); exit; } require __DIR__.'/users.php';
