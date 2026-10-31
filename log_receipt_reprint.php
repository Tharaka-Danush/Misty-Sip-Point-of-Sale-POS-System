<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
verify_csrf();
$saleId=(int)($_POST['sale_id']??0);
if($saleId<1){http_response_code(422);exit;}
$user=auth_user();
$sql='SELECT id,bill_no,user_id,total FROM sales WHERE id=?'.(($user['role']??'')==='cashier'?' AND user_id=?':'').' LIMIT 1';
$stmt=db()->prepare($sql);
$stmt->execute((($user['role']??'')==='cashier'?[$saleId,(int)$user['id']]:[$saleId]));
$sale=$stmt->fetch();
if(!$sale){http_response_code(404);exit;}
log_activity('Receipt Reprinted','sales','Bill '.$sale['bill_no'].' reprinted. Total: LKR '.number_format((float)$sale['total'],2),$saleId);
http_response_code(204);
exit;
