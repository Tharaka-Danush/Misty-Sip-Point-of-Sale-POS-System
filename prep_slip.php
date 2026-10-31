<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
$id=(int)($_GET['id']??0);
$embedded=(($_GET['embedded']??'')==='1');
$viewer=auth_user();$sql='SELECT s.*,u.name cashier FROM sales s JOIN users u ON u.id=s.user_id WHERE s.id=?'.(($viewer['role']??'')==='cashier'?' AND s.user_id=?':'');
$stmt=db()->prepare($sql);
$stmt->execute((($viewer['role']??'')==='cashier'?[$id,(int)$viewer['id']]:[$id]));
$sale=$stmt->fetch();
if(!$sale){http_response_code(404);exit('Sale not found.');}
$orderStmt=db()->prepare('SELECT COUNT(*) FROM sales WHERE DATE(created_at)=DATE(?) AND id<=?');
$orderStmt->execute([$sale['created_at'],$sale['id']]);
$orderNumber=(int)$orderStmt->fetchColumn();
$stmt=db()->prepare("SELECT si.*,COALESCE(NULLIF(si.category_name,''),c.name,'') category FROM sale_items si LEFT JOIN products p ON p.id=si.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE si.sale_id=? ORDER BY si.id");
$stmt->execute([$id]);
$items=$stmt->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preparation <?= e($sale['bill_no']) ?></title>
<style>
@page{size:80mm auto;margin:0}*{box-sizing:border-box}html,body{width:80mm;min-height:0;margin:0;padding:0;overflow:visible}body{font:13px Arial,sans-serif;color:#000;background:#fff}.slip{width:80mm;max-width:100%;margin:0;padding:7mm 5mm 6mm}.brand{text-align:center;padding-bottom:5px}.brand img{display:block;width:23mm;height:20mm;margin:0 auto 2px;object-fit:contain}.brand h1{margin:0;font-size:25px;line-height:1;font-weight:900;letter-spacing:.3px}.brand p{margin:4px 0 0;font-size:8px;font-weight:800;letter-spacing:.4px}.head{text-align:center;border-top:1px dashed #000;border-bottom:1px dashed #000;padding:8px 0 9px;margin-top:7px}.head h2{margin:0 0 7px;font-size:22px;line-height:1;font-weight:900}.order-label{font-size:12px;font-weight:800}.order{font-size:38px;line-height:1;font-weight:900;margin-top:3px}.meta{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px dashed #000;font-size:12px;font-weight:800}.meta span:last-child{position:relative;right:1.5mm}.item{padding:10px 0;border-bottom:1px dashed #000}.item b{display:block;font-size:16px;line-height:1.2;overflow-wrap:anywhere}.item small{display:block;margin-top:4px;font-size:12px;overflow-wrap:anywhere}.footer{text-align:center;padding:12px 0 3px;font-size:14px;line-height:1.2;font-weight:900}.actions{text-align:center;margin:15px}.actions button{padding:9px 16px}@media screen{html,body{width:100%;overflow:hidden}.slip{width:100%;max-width:none}}@media print{html,body{width:80mm!important;margin:0!important;padding:0!important;overflow:visible!important}.actions{display:none}.slip{width:80mm!important;max-width:80mm!important;margin:0!important;padding:7mm 5mm 6mm!important}}
</style></head><body>
<?php if(!$embedded): ?><div class="actions"><button onclick="window.print()">Print Preparation Slip</button></div><?php endif; ?>
<main class="slip"><header class="brand"><img src="assets/logo.png" alt="Misty Sip"><h1>MISTY SIP</h1><p>• TEA • JUICE • ICE CREAM •</p></header><section class="head"><h2>PREPARATION</h2><div class="order-label">ORDER NO</div><div class="order"><?= e($orderNumber) ?></div></section><div class="meta"><span><?= e($sale['bill_no']) ?></span><span><?= date('h:i A',strtotime($sale['created_at'])) ?></span></div>
<?php foreach($items as $i): $opts=[]; if($i['boba_level']!=='No Boba')$opts[]=$i['boba_level']; if(!empty($i['topping_level'])&&$i['topping_level']!=='No Topping')$opts[]='Topping: '.$i['topping_level']; if($i['category']==='Hot Drinks')$opts[]=$i['sugar_level']==='Normal'?'Normal Sugar':'Less Sugar'; elseif(!in_array($i['category'],['Ice Cream','Sundae','Fruit Salad'],true)){$opts[]=$i['sugar_level']==='Normal'?'Normal Sugar':'Less Sugar';$opts[]=$i['ice_level'];} ?>
<section class="item"><b><?= e($i['qty']) ?> × <?= e($i['product_name']) ?> (<?= e($i['size']) ?>)</b><?php if($opts): ?><small><?= e(implode(' • ',$opts)) ?></small><?php endif; ?></section><?php endforeach; ?>
<footer class="footer">GOOD DRINKS<br>BRIGHTER DAYS</footer></main></body></html>
