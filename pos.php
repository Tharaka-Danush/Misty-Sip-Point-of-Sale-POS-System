<?php
require_once __DIR__.'/includes/bootstrap.php'; require_login();
if ((auth_user()['role']??'')!=='cashier') { header('Location: index.php'); exit; }
require_open_cashier_shift();
if(empty($_SESSION['checkout_token'])) $_SESSION['checkout_token']=bin2hex(random_bytes(24));
$checkoutToken=(string)$_SESSION['checkout_token'];
$pageTitle='Fresh Order Station'; $pageSubtitle='Pick a favourite, customize your drink & serve the refreshment.'; $showPosHeaderLogo=true;
$posLowStockLevel=max(0,app_setting_int('low_stock_level',5));
$highDiscountThreshold=max(0,min(100,app_setting_float('high_discount_threshold',10)));
$categoryOrder="CASE name WHEN 'Bubble Tea' THEN 1 WHEN 'Fresh Juice' THEN 2 WHEN 'Fruit Tea' THEN 3 WHEN 'Milkshake' THEN 4 WHEN 'Hot Drinks' THEN 5 WHEN 'Sundae' THEN 6 WHEN 'Ice Cream' THEN 7 WHEN 'Fruit Salad' THEN 8 ELSE 99 END";
$categories=db()->query("SELECT c.* FROM categories c WHERE EXISTS (SELECT 1 FROM products p WHERE p.category_id=c.id AND p.is_active=1) ORDER BY $categoryOrder,c.name")->fetchAll();
$rows=db()->query("SELECT p.*,c.name category,c.color FROM products p JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 ORDER BY CASE c.name WHEN 'Bubble Tea' THEN 1 WHEN 'Fresh Juice' THEN 2 WHEN 'Fruit Tea' THEN 3 WHEN 'Milkshake' THEN 4 WHEN 'Hot Drinks' THEN 5 WHEN 'Sundae' THEN 6 WHEN 'Ice Cream' THEN 7 WHEN 'Fruit Salad' THEN 8 ELSE 99 END,p.name,FIELD(p.size,'S','M','L')")->fetchAll();
$products=[];
foreach($rows as $p){
  $key=$p['category_id'].'|'.$p['name'];
  if(!isset($products[$key]))$products[$key]=['name'=>$p['name'],'category_id'=>$p['category_id'],'category'=>$p['category'],'color'=>$p['color'],'image_path'=>$p['image_path'],'variants'=>[]];
  $products[$key]['variants'][]=['id'=>(int)$p['id'],'size'=>$p['size'],'price'=>(float)$p['price'],'stock'=>(float)$p['stock_qty']];
}
$grouped=[];
foreach($categories as $c){$grouped[$c['id']]=['name'=>$c['name']==='Hot Drinks'?'Coffee & Hot Drinks':$c['name'],'products'=>[]];}
foreach($products as $p){$grouped[$p['category_id']]['products'][]=$p;}
include __DIR__.'/includes/header.php';
?>
<script>document.body.classList.add('pos-page');</script>
<div class="pos-layout">
  <section class="catalog panel">
    <div class="catalog-tools"><input id="productSearch" type="hidden" value=""><div class="chips"><button class="chip active" data-category="all">All</button><?php foreach($categories as $c): ?><button class="chip" data-category="<?= e($c['id']) ?>"><?= e($c['name']) ?></button><?php endforeach; ?></div></div>
    <div class="product-sections" id="productGrid">
      <?php foreach($grouped as $categoryId=>$group): if(!$group['products'])continue; ?><section class="product-category-row" data-category-section="<?=e($categoryId)?>"><h3><?=e($group['name'])?></h3><div class="product-grid">
      <?php foreach($group['products'] as $p): $prices=array_column($p['variants'],'price'); $stocks=array_column($p['variants'],'stock'); $maxStock=max($stocks); $search=$p['name'].' '.$p['category']; $fixedSize=$p['category']==='Fruit Tea'?'L':(in_array($p['category'],['Ice Cream','Hot Drinks','Sundae','Fruit Salad'],true)?'M':null); $fixedIndex=$fixedSize===null?false:array_search($fixedSize,array_column($p['variants'],'size'),true); ?><button class="product-card<?= $maxStock<=0?' product-out-of-stock':($maxStock<=$posLowStockLevel?' product-low-stock':'') ?>" data-category="<?= e($p['category_id']) ?>" data-name="<?= e(strtolower($search)) ?>" data-product='<?= e(json_encode(['name'=>$p['name'],'category'=>$p['category'],'variants'=>$p['variants']],JSON_UNESCAPED_UNICODE)) ?>' <?= $maxStock<=0?'disabled':'' ?>>
        <?php if($maxStock<=0): ?><span class="stock-status out">OUT OF STOCK</span><?php elseif($maxStock<=$posLowStockLevel): ?><span class="stock-status low">LOW STOCK: <?= e($maxStock) ?></span><?php endif; ?>
        <?php if(!empty($p['image_path'])): ?><img class="product-photo" src="<?= e(base_url($p['image_path'])) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><span class="product-icon" style="--accent:<?= e($p['color']) ?>"><?= e(substr($p['category'],0,1)) ?></span><?php endif; ?><span class="product-name"><?= e($p['name']) ?></span><?php if($fixedSize===null): ?><span class="product-meta">Select S, M or L</span><?php else: ?><span class="product-meta">Stock: <?= e($fixedIndex!==false?$p['variants'][$fixedIndex]['stock']:0) ?></span><?php endif; ?><strong><?php if($fixedSize!==null): ?>LKR <?= number_format((float)($fixedIndex!==false?$p['variants'][$fixedIndex]['price']:0),2) ?><?php else: ?>LKR <?= number_format((float)min($prices),2) ?> – <?= number_format((float)max($prices),2) ?><?php endif; ?></strong>
      </button><?php endforeach; ?></div></section><?php endforeach; ?>
    </div>
  </section>
  <aside class="cart panel"><div class="panel-head bill-hero"><div class="bill-title-wrap"><h2>Current Bill</h2></div><button id="clearCart" class="btn ghost clear-bill-btn" type="button"><span aria-hidden="true">×</span> Clear Bill</button></div><div id="cartItems" class="cart-items"><div class="empty"><span class="empty-cart-icon" aria-hidden="true"><svg viewBox="0 0 64 64"><path d="M7 11h8l6 27h28l6-20H19"/><circle cx="27" cy="49" r="3"/><circle cx="47" cy="49" r="3"/><path d="M25 27h19"/></svg></span><span>Select a product to start.</span></div></div>
    <div class="cart-summary enhanced-summary">
      <div class="bill-quick-actions"><button id="holdOrderBtn" type="button">Hold Order</button><button id="resumeOrderBtn" type="button">Resume <span id="heldOrderCount">0</span></button><button id="loyaltyFreeBtn" type="button">Loyalty Free</button></div>
      <label class="summary-field"><span class="summary-label">Discount (LKR)</span><span class="field-with-icon"><span class="summary-icon" aria-hidden="true">◇</span><input id="discount" type="text" inputmode="decimal" value="0" autocomplete="off"></span></label>
      <div><span>Subtotal</span><b id="subtotal">0.00</b></div>
      <div class="grand total-highlight"><span>Total</span><b>LKR <span id="total">0.00</span></b></div>
      <label class="summary-field"><span class="summary-label">Payment Method</span><span class="field-with-icon"><span class="summary-icon card-icon" aria-hidden="true">▣</span><select id="paymentMethod"><option value="cash">Cash</option><option value="card">Card</option><option value="other">Other</option></select></span></label>
      <label class="summary-field"><span class="summary-label">Paid Amount</span><span class="field-with-icon"><span class="summary-icon" aria-hidden="true">▢</span><input id="paidAmount" type="text" inputmode="decimal" value="" placeholder="0.00" autocomplete="off"></span></label>
      <div class="balance-row"><span><span class="balance-icon" aria-hidden="true">↩</span> Balance</span><b id="balance">0.00</b></div>
      <p id="checkoutError" class="form-error"></p><button id="checkoutBtn" class="btn primary full print-btn"><span class="print-icon" aria-hidden="true">▣</span> Complete Sale & Print</button></div>
  </aside>
</div>

<div class="size-modal" id="sizeModal" hidden><div class="size-modal-backdrop" data-close-modal></div><section class="size-modal-box" role="dialog" aria-modal="true" aria-labelledby="sizeModalTitle"><button class="size-modal-close" type="button" data-close-modal aria-label="Close">×</button><div class="customize-head"><span class="customize-mark">MISTY SIP</span><p class="size-modal-label">Make it your way</p><h2 id="sizeModalTitle">Customize Drink</h2><p>Choose the cup size, boba, sweetness and ice level.</p></div><div class="customize-body"><div class="customize-columns"><div class="customize-column"><div class="customize-group"><div class="group-title"><span>1</span><h3>Cup Size</h3></div><div id="sizeOptions" class="size-options"></div></div><div class="customize-group"><div class="group-title"><span>2</span><h3>Boba Level</h3></div><div id="bobaOptions" class="choice-grid choice-grid-3"><button type="button" class="custom-choice selected"><b>No Boba</b><small>Free</small></button><button type="button" class="custom-choice"><b>Regular</b><small>+ LKR 100.00</small></button><button type="button" class="custom-choice"><b>Extra</b><small>+ LKR 150.00</small></button></div></div></div><div class="customize-column"><div class="customize-group"><div class="group-title"><span>3</span><h3>Sugar Level</h3><small>No extra charge</small></div><div id="sugarOptions" class="choice-grid choice-grid-5"><button type="button" class="custom-choice"><b>0%</b></button><button type="button" class="custom-choice"><b>20%</b></button><button type="button" class="custom-choice"><b>50%</b></button><button type="button" class="custom-choice"><b>75%</b></button><button type="button" class="custom-choice selected"><b>Normal</b></button></div></div><div class="customize-group"><div class="group-title"><span>4</span><h3>Ice Level</h3><small>No extra charge</small></div><div id="iceOptions" class="choice-grid choice-grid-2"><button type="button" class="custom-choice"><b>Less Ice</b></button><button type="button" class="custom-choice selected"><b>Normal Ice</b></button></div></div><div class="customize-group" id="toppingGroup" hidden><div class="group-title"><span>2</span><h3>Fruit Salad Topping</h3></div><div id="toppingOptions" class="choice-grid choice-grid-2"></div></div></div></div><div class="customize-footer"><div><small>Selected price</small><strong>LKR <span id="customPrice">0.00</span></strong></div><button id="addCustomizedDrink" class="btn primary" type="button" disabled>Add to Bill</button></div></div></section></div>
<div class="order-chooser-modal" id="orderChooserModal" hidden><div class="order-chooser-backdrop" data-chooser-close></div><section class="order-chooser-box" role="dialog" aria-modal="true" aria-labelledby="orderChooserTitle"><button class="order-chooser-close" type="button" data-chooser-close aria-label="Close">×</button><img src="<?=e(base_url('assets/logo.png'))?>" alt="Misty Sip"><span class="order-chooser-mark">MISTY SIP</span><h2 id="orderChooserTitle">Select an option</h2><p id="orderChooserHelp"></p><div id="orderChooserList" class="order-chooser-list"></div><button class="order-chooser-cancel" type="button" data-chooser-close>Cancel</button></section></div>
<div class="sale-confirm-modal" id="saleConfirmModal" hidden><div class="sale-confirm-backdrop" data-sale-confirm-close></div><section class="sale-confirm-box" role="alertdialog" aria-modal="true" aria-labelledby="saleConfirmTitle" aria-describedby="saleConfirmText"><img src="<?= e(base_url('assets/logo.png')) ?>" alt="Misty Sip"><span class="sale-confirm-mark">FINAL CHECK</span><h2 id="saleConfirmTitle">Finalize this bill?</h2><p id="saleConfirmText">Are you sure you want to complete the sale and send this bill to print?</p><div class="sale-confirm-actions"><button id="cancelSaleConfirm" class="btn sale-no" type="button">No, Continue Order</button><button id="confirmSaleBtn" class="btn sale-yes" type="button">Yes, Finalize &amp; Print</button></div></section></div>

<div class="admin-approval-overlay" id="adminApprovalOverlay" aria-hidden="true">
  <section class="admin-approval-modal" role="dialog" aria-modal="true" aria-labelledby="adminApprovalTitle">
    <div class="admin-approval-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
    </div>
    <span class="admin-approval-mark">ADMIN APPROVAL</span>
    <h2 id="adminApprovalTitle">Approval Required</h2>
    <p id="adminApprovalText">Enter an active Admin username and password to continue.</p>
    <label>Admin Username<input type="text" id="adminApprovalUsername" autocomplete="username"></label>
    <label>Admin Password<input type="password" id="adminApprovalPassword" autocomplete="current-password"></label>
    <p class="admin-approval-error" id="adminApprovalError"></p>
    <div class="admin-approval-actions">
      <button type="button" class="btn ghost" id="adminApprovalCancel">Cancel</button>
      <button type="button" class="btn primary" id="adminApprovalConfirm">Approve</button>
    </div>
  </section>
</div>
<style>
.admin-approval-overlay{position:fixed;inset:0;z-index:100200;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(10,31,24,.58);backdrop-filter:blur(4px)}
.admin-approval-overlay.open{display:flex}
.admin-approval-modal{width:min(430px,100%);background:#fff;border-radius:22px;padding:28px;box-shadow:0 28px 90px rgba(0,0,0,.3)}
.admin-approval-icon{width:58px;height:58px;border-radius:16px;display:grid;place-items:center;background:#eaf8f1;color:#087a55;margin-bottom:12px}
.admin-approval-icon svg{width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.admin-approval-mark{display:block;color:#0b8a61;font-size:11px;font-weight:900;letter-spacing:.12em;margin-bottom:5px}
.admin-approval-modal h2{margin:0 0 7px;color:#153c2e;font-size:23px}
.admin-approval-modal>p:not(.admin-approval-error){margin:0 0 17px;color:#687b73;font-size:14px;line-height:1.5}
.admin-approval-modal label{display:block;color:#40574e;font-size:12px;font-weight:800;margin:11px 0}
.admin-approval-modal input{width:100%;height:44px;box-sizing:border-box;margin-top:6px;border:1px solid #cfded7;border-radius:11px;padding:0 12px;font:inherit;outline:none}
.admin-approval-modal input:focus{border-color:#0b8a61;box-shadow:0 0 0 3px rgba(11,138,97,.10)}
.admin-approval-error{min-height:19px;margin:6px 0 0;color:#bf3232;font-size:12px;font-weight:800}
.admin-approval-actions{display:flex;gap:10px;margin-top:14px}
.admin-approval-actions .btn{flex:1}
</style>

<script>window.POS_USER_ID=<?=json_encode((int)auth_user()['id'])?>;window.POS_HIGH_DISCOUNT_THRESHOLD=<?=json_encode($highDiscountThreshold)?>;window.POS_CHECKOUT_TOKEN=<?=json_encode($checkoutToken)?>;</script>
<script src="<?= e(base_url('assets/js/pos.js')) ?>?v=77"></script>
<?php include __DIR__.'/includes/footer.php'; ?>
