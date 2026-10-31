<?php
require_once __DIR__.'/includes/bootstrap.php';
if (auth_user()) { header('Location: index.php'); exit; }
$id=(int)($_SESSION['password_reset_user_id']??0);
if($id<1){ header('Location: login.php'); exit; }
$s=db()->prepare('SELECT id,username,role,password_reset_required,is_active FROM users WHERE id=? LIMIT 1');
$s->execute([$id]); $user=$s->fetch();
if(!$user || !(int)$user['is_active'] || !(int)$user['password_reset_required']){ unset($_SESSION['password_reset_user_id']); header('Location: login.php'); exit; }
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $password=(string)($_POST['new_password']??'');
    $confirm=(string)($_POST['confirm_password']??'');
    if(strlen($password)<8) $error='Password must contain at least 8 characters.';
    elseif($password!==$confirm) $error='Password confirmation does not match.';
    else{
        db()->prepare('UPDATE users SET password=?,password_reset_required=0,is_locked=0,failed_login_attempts=0 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        unset($_SESSION['password_reset_user_id']);
        header('Location: login.php?password=changed'); exit;
    }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set New Password | Misty Sip POS</title><link rel="stylesheet" href="assets/css/app.css"></head>
<body class="login-body"><form class="login-card" method="post" autocomplete="off"><img src="assets/logo.png" alt="Misty Sip"><h2>Set New Password</h2><p><?=e($user['username'])?>, create your new password to continue.</p><?php if($error):?><div class="alert danger"><?=e($error)?></div><?php endif;?><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>New Password<input type="password" name="new_password" minlength="8" autocomplete="new-password" required autofocus></label><label>Confirm Password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label><button class="btn primary full">Save New Password</button></form></body></html>
