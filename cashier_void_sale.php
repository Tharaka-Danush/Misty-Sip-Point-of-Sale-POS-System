<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
$user=auth_user();
if(($user['role']??'')!=='cashier'){http_response_code(403);exit('Cashier access required.');}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: sale_summary.php');exit;}
verify_csrf();
$saleId=(int)($_POST['sale_id']??0);$reason=trim((string)($_POST['reason']??''));$approvalToken=trim((string)($_POST['approval_token']??''));
if($saleId<1||$reason===''){$_SESSION['flash']='Please enter a void reason.';header('Location: sale_summary.php');exit;}
$pdo=db();
try{
  $approval=consume_admin_approval($approvalToken,'void_sale',(string)$saleId);
  if(!$approval) throw new RuntimeException('Admin approval is required to void this bill.');
  $pdo->beginTransaction();
  $get=$pdo->prepare('SELECT * FROM sales WHERE id=? AND user_id=? FOR UPDATE');
  $get->execute([$saleId,(int)$user['id']]);
  $sale=$get->fetch();
  if(!$sale||!empty($sale['is_void'])) throw new RuntimeException('Sale is unavailable.');
  $items=$pdo->prepare('SELECT product_id,qty FROM sale_items WHERE sale_id=?');$items->execute([$saleId]);
  $stock=$pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=?');
  $move=$pdo->prepare("INSERT INTO stock_movements(product_id,user_id,movement_type,qty,note,reference_no) VALUES(?,?,'adjustment',?,?,?)");
  foreach($items as $item){if($item['product_id']!==null){$stock->execute([$item['qty'],$item['product_id']]);$move->execute([$item['product_id'],(int)$user['id'],$item['qty'],'Cashier void sale: '.$reason,$sale['bill_no']]);}}
  $used=$pdo->prepare("SELECT consumable_id,-SUM(qty) restore_qty FROM consumable_movements WHERE sale_id=? AND movement_type='sale' GROUP BY consumable_id");$used->execute([$saleId]);
  $restore=$pdo->prepare('UPDATE consumables SET stock_qty=stock_qty+? WHERE id=?');
  $log=$pdo->prepare("INSERT INTO consumable_movements(consumable_id,user_id,sale_id,movement_type,qty,note,reference_no) VALUES(?,?,?,'reversal',?,'Cashier void sale consumable reversal',?)");
  foreach($used as $c){$qty=(float)$c['restore_qty'];if($qty>0){$restore->execute([$qty,$c['consumable_id']]);$log->execute([$c['consumable_id'],(int)$user['id'],$saleId,$qty,$sale['bill_no']]);}}
  $stmt=$pdo->prepare('UPDATE sales SET is_void=1,void_reason=?,voided_by=?,voided_at=NOW() WHERE id=?');$stmt->execute([$reason,(int)$user['id'],$saleId]);
  $pdo->commit();
  log_activity('Cashier Bill Voided','sales',
      'Bill '.$sale['bill_no'].' voided by '.$user['name'].'; approved by '.$approval['admin_name'].'. Reason: '.$reason,$saleId);
  $_SESSION['flash']='Bill voided and stock restored.';
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Cashier void sale failure: '.$e->getMessage());$_SESSION['flash']='Could not void the bill: '.$e->getMessage();}
header('Location: sale_summary.php');exit;
