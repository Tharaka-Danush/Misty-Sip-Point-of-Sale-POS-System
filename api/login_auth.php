<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'Invalid request.'],405);
verify_csrf();
$username=trim((string)($_POST['username']??''));
$password=(string)($_POST['password']??'');
if($username==='' || $password==='') json_response(['ok'=>false,'error'=>'Enter username and password.'],422);
$stmt=db()->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
$stmt->execute([$username]); $user=$stmt->fetch();
if($user && (int)($user['is_active']??0)!==1){
    json_response(['ok'=>false,'error'=>'Your account is inactive. Please contact the administrator.'],403);
}
if($user && (int)($user['password_reset_required']??0)===1){
    json_response(['ok'=>false,'password_setup'=>true,'setup_type'=>(!empty($user['password']) && password_verify('__MISTY_SIP_ADMIN_RESET_PENDING__',$user['password']))?'reset':'new']);
}
if($user && ($user['role']??'')==='cashier' && (int)($user['is_locked']??0)===1){
    json_response(['ok'=>false,'error'=>'Account locked. Please contact the administrator.'],423);
}
if($user && password_verify($password,(string)$user['password'])){
    db()->prepare('UPDATE users SET failed_login_attempts=0 WHERE id=?')->execute([$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user']=['id'=>$user['id'],'name'=>$user['name'],'username'=>$user['username'],'role'=>$user['role']];
    $_SESSION['last_activity_at']=time();
    log_activity('Login','system','User signed in.');
    $redirect=$user['role']==='cashier'?(active_cashier_shift((int)$user['id'])?'pos.php':'open_shift.php'):'index.php';
    json_response(['ok'=>true,'redirect'=>$redirect]);
}
$error='Invalid username or password.';
if($user && ($user['role']??'')==='cashier'){
    $attempts=(int)($user['failed_login_attempts']??0)+1;
    if($attempts>=3){db()->prepare('UPDATE users SET failed_login_attempts=3,is_locked=1 WHERE id=?')->execute([$user['id']]);$error='Account locked after 3 incorrect password attempts. Please contact the administrator.';}
    else{db()->prepare('UPDATE users SET failed_login_attempts=? WHERE id=?')->execute([$attempts,$user['id']]);$error='Invalid username or password. '.(3-$attempts).' attempt(s) remaining.';}
}
json_response(['ok'=>false,'error'=>$error],401);
