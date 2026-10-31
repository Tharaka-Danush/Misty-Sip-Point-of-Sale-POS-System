<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$user = auth_user();

// A cashier with an active shift must close the shift before signing out.
if (($user['role'] ?? '') === 'cashier' && active_cashier_shift((int)$user['id'])) {
    header('Location: close_shift.php');
    exit;
}

log_activity('Logout','system','User signed out.');
$_SESSION=[];
session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: login.php');
exit;
