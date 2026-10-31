<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$username=trim((string)($_GET['username']??''));
if($username===''){ echo json_encode(['found'=>false]); exit; }
$stmt=db()->prepare('SELECT id,password,password_reset_required,is_active,is_locked,role FROM users WHERE username=? LIMIT 1');
$stmt->execute([$username]);
$user=$stmt->fetch();
if(!$user){ echo json_encode(['found'=>false]); exit; }

$isInactive=(int)($user['is_active']??0)!==1;
$isLocked=(($user['role']??'')==='cashier' && (int)($user['is_locked']??0)===1);
$needsPassword=(!$isInactive && !$isLocked && (int)($user['password_reset_required']??0)===1);
$setupType='new';
if($needsPassword && !empty($user['password']) && password_verify('__MISTY_SIP_ADMIN_RESET_PENDING__',$user['password'])) $setupType='reset';

echo json_encode([
  'found'=>true,
  'inactive'=>$isInactive,
  'locked'=>$isLocked,
  'new_password'=>$needsPassword,
  'setup_type'=>$needsPassword?$setupType:null,
  'message'=>$isInactive?'Your account is inactive. Please contact the administrator.':($isLocked?'Account locked. Please contact the administrator.':null)
]);
