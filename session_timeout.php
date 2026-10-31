<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=auth_user();
if(!$user){header('Location: '.base_url('login.php'));exit;}

if(($user['role']??'')==='cashier'){
    if(empty($_SESSION['pos_locked'])){
        log_activity('POS Auto Locked','system','Cashier POS locked automatically after 30 minutes of inactivity.');
    }
    $_SESSION['pos_locked']=true;
    $_SESSION['pos_locked_at']=date('Y-m-d H:i:s');
    $_SESSION['last_activity_at']=time();
    session_regenerate_id(true);
    header('Location: '.base_url('cashier_settings.php?locked=1&timeout=1'));
    exit;
}

log_activity('Auto Logout','system','Admin session ended automatically after 30 minutes of inactivity.');
$_SESSION=[];
if(ini_get('session.use_cookies')){ $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: '.base_url('login.php?timeout=1'));
exit;
