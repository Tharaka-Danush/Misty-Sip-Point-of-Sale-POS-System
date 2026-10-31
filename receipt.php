<?php
require_once __DIR__.'/includes/bootstrap.php'; require_login();
$id=(int)($_GET['id']??0);
$autoPrint=(($_GET['auto']??'')==='1');
$embedded=(($_GET['embedded']??'')==='1');
$returnSource=(string)($_GET['return']??'');
$receiptShopName=app_setting('shop_name','MISTY SIP');
$receiptPhone=app_setting('shop_phone','071 787 8305');
$receiptFooter=app_setting('receipt_footer','Freshly Made • Naturally Refreshing');
$isSaleSummaryReprint=($returnSource==='sale_summary');
$viewer=auth_user();$sql='SELECT s.*,u.name cashier FROM sales s JOIN users u ON u.id=s.user_id WHERE s.id=?'.(($viewer['role']??'')==='cashier'?' AND s.user_id=?':'');$stmt=db()->prepare($sql);$stmt->execute((($viewer['role']??'')==='cashier'?[$id,(int)$viewer['id']]:[$id]));$sale=$stmt->fetch();
if(!$sale){http_response_code(404);exit('Receipt not found.');}
$orderStmt=db()->prepare('SELECT COUNT(*) FROM sales WHERE DATE(created_at)=DATE(?) AND id<=?');
$orderStmt->execute([$sale['created_at'],$sale['id']]);
$orderNumber=(int)$orderStmt->fetchColumn();
$stmt=db()->prepare("SELECT si.*,COALESCE(NULLIF(si.category_name,''),c.name,'') category FROM sale_items si LEFT JOIN products p ON p.id=si.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE si.sale_id=? ORDER BY si.id");$stmt->execute([$id]);$items=$stmt->fetchAll();
?>
<?php if($autoPrint): ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($sale['bill_no']) ?></title><link rel="stylesheet" href="assets/css/receipt.css?v=14"><link rel="stylesheet" href="assets/css/receipt-order-box.css?v=11"><style>.auto-print-receipt{background:#fff}.auto-print-receipt .receipt{position:fixed;left:-10000px;top:0}@media print{.auto-print-receipt .receipt{position:static;left:auto;top:auto}}</style></head><body class="auto-print-receipt">
<?php else: ?>
<?php
$pageTitle='Receipt Preview';
$pageSubtitle=$isSaleSummaryReprint?'Reprint a completed sale receipt.':'Review the completed sale or print a copy.';
$bodyClass='pos-page receipt-preview-screen';
$extraStyles=['assets/css/receipt.css?v=14','assets/css/receipt-order-box.css?v=11','assets/css/receipt-preview.css?v=4'];
require __DIR__.'/includes/header.php';
$methodLabel=ucwords(str_replace('_',' ',(string)$sale['payment_method']));
?>
<section class="receipt-preview-page">
  <div class="receipt-sale-summary" aria-label="Sale summary">
    <div><span class="receipt-summary-icon"><svg viewBox="0 0 24 24"><path d="M6 2h9l4 4v16H6z"/><path d="M15 2v5h4M9 12h7M9 16h7"/></svg></span><span><small>Bill</small><strong><?= e($sale['bill_no']) ?></strong></span></div>
    <div><span class="receipt-summary-icon"><svg viewBox="0 0 24 24"><path d="M10 3 8 21M17 3l-2 18M4 9h17M3 15h17"/></svg></span><span><small>Order</small><strong><?= e($orderNumber) ?></strong></span></div>
    <div><span class="receipt-summary-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.6 2.6L16.5 9"/></svg></span><span><small>Status</small><strong>Paid</strong></span></div>
    <div><span class="receipt-summary-icon"><svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M3 9h18M6 15h4"/></svg></span><span><small>Payment</small><strong><?= e($methodLabel) ?></strong></span></div>
  </div>
  <div class="receipt-preview-actions">
    <?php if($isSaleSummaryReprint): ?>
    <a class="btn receipt-back-btn" href="sale_summary.php" style="text-decoration:none;">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/><path d="M9 12h10"/></svg>
      <span>Back to Sale Summary</span>
    </a>
    <?php endif; ?>
    <button class="btn" id="printReceipt" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-3"/><path d="M7 14h10v7H7z"/></svg><span>Print Receipt</span></button>
    <button class="btn primary" id="printPreparation" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-3"/><path d="M7 14h10v7H7z"/></svg><span>Print Preparation Slip</span></button>
  </div>
  <div class="dual-preview-grid">
<?php endif; ?>
<article class="receipt" id="receiptPreview"><div class="receipt-brand"><img src="assets/logo.png" alt="<?= e($receiptShopName) ?>"></div><h1><?= e($receiptShopName) ?></h1><div class="receipt-info"><p>TEA • JUICE • ICE CREAM<br>BANDARAWELA<?php if($receiptPhone!==''): ?><br>Call / WhatsApp: <?= e($receiptPhone) ?><?php endif; ?></p><div class="order-badge"><span>ORDER NO</span><b><?= e($orderNumber) ?></b></div></div><hr><div class="meta"><span>Bill No</span><b><?= e($sale['bill_no']) ?></b><span>Date</span><b><?= date('d-m-Y h:i A',strtotime($sale['created_at'])) ?></b><span>Cashier</span><b><?= e($sale['cashier']) ?></b></div><hr><table><thead><tr><th>Item</th><th>Qty</th><th>Amount</th></tr></thead><tbody><?php foreach($items as $item): $details=[]; $showSize=!in_array($item['category'],['Fruit Tea','Ice Cream','Hot Drinks','Sundae','Fruit Salad'],true); if($item['boba_level']!=='No Boba')$details[]='Boba +'; if(!empty($item['topping_level'])&&$item['topping_level']!=='No Topping')$details[]='Topping: '.$item['topping_level']; if($item['category']==='Hot Drinks')$details[]=$item['sugar_level']==='Normal'?'Normal Sugar':'Less Sugar'; elseif(!in_array($item['category'],['Ice Cream','Sundae','Fruit Salad'],true)){$details[]=$item['sugar_level']==='Normal'?'Normal Sugar':'Less Sugar'; $details[]=$item['ice_level']==='Less Ice'?'Less Ice':'Normal Ice';} ?><tr><td><?= e($item['product_name']) ?><?= $showSize?' ('.e($item['size']).')':'' ?><?php if($details): ?><small><?= e(implode(' • ',$details)) ?></small><?php endif; ?></td><td><?= e($item['qty']) ?></td><td><?= number_format((float)$item['line_total'],2) ?></td></tr><?php endforeach; ?></tbody></table><hr><div class="totals"><span>SUB TOTAL</span><b><?= number_format((float)$sale['subtotal'],2) ?></b><span>DISCOUNT</span><b><?= number_format((float)$sale['discount'],2) ?></b><span class="grand">TOTAL</span><b class="grand">LKR <?= number_format((float)$sale['total'],2) ?></b><span><?= e(strtoupper($sale['payment_method'])) ?></span><b><?= number_format((float)$sale['paid_amount'],2) ?></b><span>BALANCE</span><b><?= number_format((float)$sale['balance_amount'],2) ?></b></div><hr><h2>THANK YOU!</h2><?php if($receiptFooter!==''): ?><p><?= e($receiptFooter) ?></p><?php endif; ?><p class="loyalty">Buy 5 Drinks • Get the 6th FREE</p></article>
<?php if(!$autoPrint): ?><div class="preparation-frame-wrap" id="preparationPreview"><iframe id="preparationFrame" title="Preparation Slip Preview" scrolling="no" src="prep_slip.php?id=<?= e($sale['id']) ?>&amp;embedded=1"></iframe></div></div></section><?php endif; ?>
<script>
<?php if((float)($sale['loyalty_discount']??0)>0): ?>document.addEventListener('DOMContentLoaded',function(){const totals=document.querySelector('.totals');if(!totals)return;const grand=totals.querySelector('.grand');const label=document.createElement('span');label.textContent='LOYALTY FREE DRINK';const value=document.createElement('b');value.textContent='-<?=number_format((float)$sale['loyalty_discount'],2)?>';totals.insertBefore(label,grand);totals.insertBefore(value,grand);});<?php endif; ?>
(function(){
  window.receiptHeaderReady=Promise.all([
    new Promise(function(resolve){
      const link=document.createElement('link');
      link.id='receipt-header-v2';
      link.rel='stylesheet';
      link.href='assets/css/receipt-order-box.css?v=10&t='+Date.now();
      link.onload=resolve;
      link.onerror=resolve;
      document.head.appendChild(link);
    }),
    new Promise(function(resolve){
      const link=document.createElement('link');
      link.id='receipt-print-layout-v13';
      link.rel='stylesheet';
      link.href='assets/css/receipt.css?v=13&t='+Date.now();
      link.onload=resolve;
      link.onerror=resolve;
      document.head.appendChild(link);
    })
  ]);
  function fitReceiptPaper(){
    const receipt=document.querySelector('.receipt');
    if(!receipt)return;
    receipt.style.zoom='1';
    receipt.style.minHeight='0';
    const targetHeightPx=165*96/25.4;
    const naturalHeight=Math.max(1,receipt.scrollHeight);
    const scale=Math.min(1,targetHeightPx/naturalHeight);
    receipt.style.zoom=String(scale);
  }
  window.fitReceiptPaper=fitReceiptPaper;
  window.addEventListener('load',fitReceiptPaper);
  window.addEventListener('beforeprint',fitReceiptPaper);
})();
</script>
<?php if(!$autoPrint): ?>
<script>
(function(){
  const printReceipt=document.getElementById('printReceipt');
  const printPreparation=document.getElementById('printPreparation');
  const frame=document.getElementById('preparationFrame');
  if(!printReceipt||!printPreparation||!frame)return;
  function resizePreparation(){
    try{
      const doc=frame.contentDocument;
      const height=Math.max(doc.body.scrollHeight,doc.documentElement.scrollHeight);
      frame.style.height=Math.max(430,height+2)+'px';
      frame.parentElement.style.height=frame.style.height;
    }catch(e){}
  }
  frame.addEventListener('load',resizePreparation);
  printReceipt.addEventListener('click',function(){
    <?php if($isSaleSummaryReprint): ?>
    try{
      const body=new URLSearchParams();
      body.set('csrf_token',<?= json_encode(csrf_token()) ?>);
      body.set('sale_id',<?= json_encode((string)$sale['id']) ?>);
      fetch('log_receipt_reprint.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString(),keepalive:true}).catch(function(){});
    }catch(e){}
    <?php endif; ?>
    window.print();
  });
  printPreparation.addEventListener('click',function(){frame.contentWindow.focus();frame.contentWindow.print();});
})();
</script>
<?php endif; ?>
<?php if($autoPrint): ?>
<script>
(function(){
  let moved=false;
  function nextSale(){
    if(moved) return; moved=true;
    <?php if($embedded): ?>
    if(window.parent && window.parent!==window){ window.parent.postMessage('misty-receipt-print-finished', location.origin); }
    <?php else: ?>
    location.replace('pos.php');
    <?php endif; ?>
  }
  window.addEventListener('afterprint', nextSale);
  if(window.matchMedia){
    const media=window.matchMedia('print');
    const done=e=>{ if(!e.matches) nextSale(); };
    if(media.addEventListener) media.addEventListener('change',done); else if(media.addListener) media.addListener(done);
  }
  window.addEventListener('load',function(){
    Promise.resolve(window.receiptHeaderReady).then(function(){
      if(window.fitReceiptPaper) window.fitReceiptPaper();
      setTimeout(function(){ window.print(); },150);
    });
  });
})();
</script>
<?php endif; ?>
<?php if($autoPrint): ?></body></html><?php else: require __DIR__.'/includes/footer.php'; endif; ?>
