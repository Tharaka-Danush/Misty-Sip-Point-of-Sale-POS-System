<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$user=auth_user();
if(($user['role']??'')!=='cashier'){
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'Cashier access required.']);
    exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'Invalid request method.']);
    exit;
}

try{
    verify_csrf();
    $reason=trim((string)($_POST['reason']??''));
    $heldTime=trim((string)($_POST['held_time']??''));
    $itemSummary=trim((string)($_POST['item_summary']??''));
    $itemCount=max(0,(int)($_POST['item_count']??0));
    $totalQty=max(0,(float)($_POST['total_qty']??0));
    $totalAmount=max(0,(float)($_POST['total_amount']??0));

    if($reason==='') throw new RuntimeException('Removal reason is required.');
    if(mb_strlen($reason)>250) throw new RuntimeException('Removal reason is too long.');
    if(mb_strlen($heldTime)>100) $heldTime=mb_substr($heldTime,0,100);
    if(mb_strlen($itemSummary)>2000) $itemSummary=mb_substr($itemSummary,0,2000);

    $exists=db()->query("SHOW TABLES LIKE 'held_bill_removal_logs'")->fetchColumn();
    if(!$exists) throw new RuntimeException('Admin audit table is not installed. Run held_bill_removal_log.sql first.');

    $stmt=db()->prepare("INSERT INTO held_bill_removal_logs (user_id,held_time,item_summary,item_count,total_qty,total_amount,reason,removed_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $stmt->execute([(int)$user['id'],$heldTime!==''?$heldTime:null,$itemSummary!==''?$itemSummary:null,$itemCount,$totalQty,$totalAmount,$reason]);
    log_activity('Held Bill Removed','sales','Amount: LKR '.number_format($totalAmount,2).' • Items: '.$itemCount.' • Reason: '.$reason);

    echo json_encode(['ok'=>true]);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
