<?php
require_once __DIR__ . '/includes/bootstrap.php';
// Production installs must never expose a public account-creation endpoint or
// publish default credentials. Enable this explicitly only for a fresh install.
if (!defined('ALLOW_POS_SETUP') || ALLOW_POS_SETUP !== true) {
    http_response_code(404);
    exit('Not found.');
}
$count=(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if($count>0){http_response_code(409);exit('Setup has already been completed.');}
$message='Create the first administrator directly in the database, then disable setup again.';
?>
<!doctype html><html><head><meta charset="utf-8"><title>POS Setup</title><link rel="stylesheet" href="assets/css/app.css"></head><body class="login-body"><div class="login-card"><img src="assets/logo.png" alt="Logo"><h2>Misty Sip POS Setup</h2><p><?= e($message) ?></p><a class="btn primary" href="login.php">Go to Login</a></div></body></html>
