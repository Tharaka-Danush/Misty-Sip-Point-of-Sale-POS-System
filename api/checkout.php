<?php
require_once __DIR__.'/../includes/bootstrap.php'; require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed'],405);
verify_csrf();
$currentUser=auth_user();
if(($currentUser['role']??'')!=='cashier') json_response(['error'=>'Only a cashier can complete a sale.'],403);
$activeShift=active_cashier_shift((int)$currentUser['id']);
if(!$activeShift) json_response(['error'=>'Open a cashier shift before completing a sale.'],422);
$input=json_decode(file_get_contents('php://input'),true) ?: [];
$items=$input['items'] ?? []; $discount=max(0,(float)($input['discount']??0)); $approvalToken=trim((string)($input['approval_token']??'')); $checkoutToken=trim((string)($input['checkout_token']??''));
$sessionCheckoutToken=(string)($_SESSION['checkout_token']??'');
if($checkoutToken==='' || $sessionCheckoutToken==='' || !hash_equals($sessionCheckoutToken,$checkoutToken)) json_response(['error'=>'This sale was already submitted or the checkout session expired. Refresh New Sale and try again.'],409);
$loyaltyFreeIndex=(int)($input['loyalty_free_index']??-1);
$method=$input['payment_method']??'cash'; $paid=(float)($input['paid_amount']??0);
if (!$items || !in_array($method,['cash','card','other'],true)) json_response(['error'=>'Invalid sale details.'],422);
$pdo=db();
$highDiscountThreshold=max(0,min(100,app_setting_float('high_discount_threshold',10)));
try {
  $pdo->beginTransaction(); $subtotal=0; $clean=[]; $requested=[];
  $bobaCharges=['No Boba'=>0,'Regular Boba'=>100,'Extra Boba'=>150];
  $toppingCharges=['No Topping'=>0,'Honey'=>50,'Chocolate'=>75,'Pomegranate'=>100];
  $sugarLevels=['0%','20%','50%','75%','Normal']; $iceLevels=['Less Ice','Normal Ice'];
  $get=$pdo->prepare('SELECT id,name,size,price,stock_qty,is_active FROM products WHERE id=? FOR UPDATE');
  foreach($items as $row){
    $id=(int)($row['id']??0); $qty=(float)($row['qty']??0); $boba=(string)($row['boba_level']??'No Boba'); $topping=(string)($row['topping_level']??'No Topping'); $sugar=(string)($row['sugar_level']??'Normal'); $ice=(string)($row['ice_level']??'Normal Ice');
    if($id<1||$qty<=0) throw new RuntimeException('Invalid item quantity.');
    if(!array_key_exists($boba,$bobaCharges)||!array_key_exists($topping,$toppingCharges)||!in_array($sugar,$sugarLevels,true)||!in_array($ice,$iceLevels,true)) throw new RuntimeException('Invalid customization.');
    $get->execute([$id]); $p=$get->fetch(); if(!$p||!$p['is_active']) throw new RuntimeException('A product is unavailable.');
    $requested[$id]=($requested[$id]??0)+$qty; if((float)$p['stock_qty']<$requested[$id]) throw new RuntimeException($p['name'].' does not have enough stock.');
    $unitPrice=round((float)$p['price']+$bobaCharges[$boba]+$toppingCharges[$topping],2); $line=round($unitPrice*$qty,2); $subtotal+=$line;
    $clean[]=['product'=>$p,'qty'=>$qty,'unit_price'=>$unitPrice,'line'=>$line,'boba'=>$boba,'topping'=>$topping,'sugar'=>$sugar,'ice'=>$ice];
  }

  /* Lock and validate all packaging needed by this sale before creating it. */
  $usage=[];
  if($requested){
    $ids=implode(',',array_fill(0,count($requested),'?'));
    $usageStmt=$pdo->prepare("SELECT pc.product_id,pc.consumable_id,pc.qty_per_item,c.name,c.stock_qty,c.is_active FROM product_consumables pc JOIN consumables c ON c.id=pc.consumable_id WHERE pc.product_id IN ($ids) FOR UPDATE");
    $usageStmt->execute(array_keys($requested));
    foreach($usageStmt->fetchAll() as $u){$cid=(int)$u['consumable_id'];if(!$u['is_active'])throw new RuntimeException($u['name'].' is inactive.');if(!isset($usage[$cid]))$usage[$cid]=['name'=>$u['name'],'stock'=>(float)$u['stock_qty'],'qty'=>0];$usage[$cid]['qty']+=(float)$u['qty_per_item']*$requested[(int)$u['product_id']];}
    foreach($usage as $u)if($u['stock']+0.00001<$u['qty'])throw new RuntimeException('Not enough '.$u['name'].' stock. Required: '.rtrim(rtrim(number_format($u['qty'],2),'0'),'.').'.');
  }

  if($loyaltyFreeIndex < -1 || $loyaltyFreeIndex >= count($clean)) throw new RuntimeException('Invalid loyalty free drink selection.');
  $loyaltyDiscount=$loyaltyFreeIndex>=0?round((float)$clean[$loyaltyFreeIndex]['unit_price'],2):0;
  $discount=min($discount,max(0,$subtotal-$loyaltyDiscount));

  /* Manual discounts above 10% require one-time Admin approval. */
  $discountPct=$subtotal>0?($discount/$subtotal)*100:0;
  if($discountPct>$highDiscountThreshold+0.00001){
      $approvalReference=number_format($subtotal,2,'.','').'|'.number_format($discount,2,'.','');
      $approval=consume_admin_approval($approvalToken,'high_discount',$approvalReference);
      if(!$approval) throw new RuntimeException('Admin approval is required for discounts above '.rtrim(rtrim(number_format($highDiscountThreshold,2,'.',''),'0'),'.').'%.');
      log_activity('High Discount Approved','sales',
          'Admin '.$approval['admin_name'].' approved '.number_format($discountPct,1).'% discount (LKR '.number_format($discount,2).') for cashier '.$currentUser['name'].'.');
  }

  $total=round(max(0,$subtotal-$discount-$loyaltyDiscount),2); if($method!=='cash')$paid=$total; if($paid<$total)throw new RuntimeException('Paid amount is less than total.');
  $temporaryBillNo='TMP-'.strtoupper(bin2hex(random_bytes(8))); $stmt=$pdo->prepare('INSERT INTO sales(bill_no,user_id,shift_id,subtotal,discount,loyalty_discount,total,payment_method,paid_amount,balance_amount) VALUES(?,?,?,?,?,?,?,?,?,?)');
  $balance=round($paid-$total,2);$stmt->execute([$temporaryBillNo,$currentUser['id'],$activeShift['id'],$subtotal,$discount,$loyaltyDiscount,$total,$method,$paid,$balance]);$saleId=(int)$pdo->lastInsertId();
  $billNo='MS-'.str_pad((string)$saleId,3,'0',STR_PAD_LEFT);$pdo->prepare('UPDATE sales SET bill_no=? WHERE id=?')->execute([$billNo,$saleId]);
  $itemStmt=$pdo->prepare('INSERT INTO sale_items(sale_id,product_id,product_name,category_name,size,boba_level,topping_level,sugar_level,ice_level,qty,unit_price,line_total) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
  $stockStmt=$pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=?');$moveStmt=$pdo->prepare("INSERT INTO stock_movements(product_id,user_id,movement_type,qty,note,reference_no) VALUES(?,?,'sale',?,?,?)");
  $categoryStmt=$pdo->prepare('SELECT c.name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=?');
  foreach($clean as $row){$p=$row['product'];$categoryStmt->execute([$p['id']]);$categoryName=(string)$categoryStmt->fetchColumn();$itemStmt->execute([$saleId,$p['id'],$p['name'],$categoryName,$p['size'],$row['boba'],$row['topping'],$row['sugar'],$row['ice'],$row['qty'],$row['unit_price'],$row['line']]);$stockStmt->execute([$row['qty'],$p['id']]);$moveStmt->execute([$p['id'],$currentUser['id'],-$row['qty'],'POS sale',$billNo]);}

  if($usage){$deduct=$pdo->prepare('UPDATE consumables SET stock_qty=stock_qty-? WHERE id=?');$log=$pdo->prepare("INSERT INTO consumable_movements(consumable_id,user_id,sale_id,movement_type,qty,note,reference_no) VALUES(?,?,?,'sale',?,'Automatic sale deduction',?)");foreach($usage as $cid=>$u){$deduct->execute([$u['qty'],$cid]);$log->execute([$cid,$currentUser['id'],$saleId,-$u['qty'],$billNo]);}}
  $pdo->commit();
  unset($_SESSION['checkout_token']);
  json_response(['success'=>true,'sale_id'=>$saleId,'bill_no'=>$billNo,'consumables_deducted'=>count($usage)]);
} catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  error_log('Checkout failure: '.$e->getMessage());
  $message=$e instanceof RuntimeException?$e->getMessage():'Checkout could not be completed. Please try again.';
  json_response(['error'=>$message],422);
}
