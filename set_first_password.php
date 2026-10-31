<?php
require_once __DIR__.'/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Invalid request.']); exit; }
try { verify_csrf(); } catch(Throwable $e) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Session expired. Refresh and try again.']); exit; }
$username=trim((string)($_POST['username']??''));
$password=(string)($_POST['password']??'');
$confirm=(string)($_POST['confirm_password']??'');
if(strlen($password)<6){ echo json_encode(['ok'=>false,'error'=>'Password must be at least 6 characters.']); exit; }
if($password!==$confirm){ echo json_encode(['ok'=>false,'error'=>'Passwords do not match.']); exit; }
$stmt=db()->prepare('SELECT * FROM users WHERE username=? AND is_active=1 LIMIT 1');
$stmt->execute([$username]); $user=$stmt->fetch();
if(!$user || (int)($user['password_reset_required']??0)!==1){ echo json_encode(['ok'=>false,'error'=>'This account is not waiting for a new password.']); exit; }
db()->prepare('UPDATE users SET password=?,password_reset_required=0,is_locked=0,failed_login_attempts=0 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
session_regenerate_id(true);
$_SESSION['user']=['id'=>$user['id'],'name'=>$user['name'],'username'=>$user['username'],'role'=>$user['role']];
unset($_SESSION['password_reset_user_id']);
$redirect=($user['role']==='cashier')?(active_cashier_shift((int)$user['id'])?'pos.php':'open_shift.php'):'index.php';
echo json_encode(['ok'=>true,'redirect'=>$redirect]);
