<?php
require_once __DIR__.'/config.php';
if(!is_logged_in()) redirect('login.php');
$u=current_user() ?: [];
redirect(((int)($u['office_id']??0)===1 || ($u['identity_source']??'')==='LPGMA_BRIDGED') ? 'dashboard.php' : 'records.php');
