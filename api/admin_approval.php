<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_login();
if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['ok'=>false,'error'=>'Method not allowed.'],405);
verify_csrf();

$requester=auth_user();
if(($requester['role']??'')!=='cashier') json_response(['ok'=>false,'error'=>'Cashier access required.'],403);

$input=json_decode(file_get_contents('php://input'),true) ?: [];
$action=(string)($input['action']??'');
$reference=trim((string)($input['reference']??''));
$username=trim((string)($input['username']??''));
$password=(string)($input['password']??'');

if(!in_array($action,['high_discount','void_sale'],true) || $reference===''){
    json_response(['ok'=>false,'error'=>'Invalid approval request.'],422);
}
if(strlen($reference)>120) json_response(['ok'=>false,'error'=>'Invalid approval reference.'],422);

$admin=verify_admin_credentials($username,$password);
if(!$admin){
    log_activity('Admin Approval Denied','system',
        'Cashier '.($requester['name']??'').' requested '.str_replace('_',' ',$action).' approval using admin username '.$username.'.');
    json_response(['ok'=>false,'error'=>'Invalid admin username or password.'],401);
}

$token=issue_admin_approval($action,$reference,$admin);
log_activity('Admin Approval Granted','system',
    'Approved by '.$admin['name'].' for cashier '.($requester['name']??'').': '.str_replace('_',' ',$action).'.');

json_response([
    'ok'=>true,
    'token'=>$token,
    'admin_name'=>$admin['name'],
    'expires_in'=>300
]);
