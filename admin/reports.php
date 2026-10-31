<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_admin();

$today = date('Y-m-d');
$from = $_GET['from'] ?? $today;
$to   = $_GET['to'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $today;
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

$pdo = db();

$cashierId = isset($_GET['cashier_id']) && ctype_digit((string)$_GET['cashier_id'])
    ? (int)$_GET['cashier_id'] : 0;
$cashierOptions = $pdo->query("SELECT id,name FROM users WHERE role='cashier' ORDER BY name")->fetchAll();
$cashierName = 'All Cashiers';
if($cashierId>0){
    foreach($cashierOptions as $co){
        if((int)$co['id']===$cashierId){ $cashierName=(string)$co['name']; break; }
    }
}
$cashierSql = $cashierId>0 ? " AND s.user_id=?" : "";
$rangeParams = $cashierId>0 ? [$from,$to,$cashierId] : [$from,$to];

/* Sales + cashier */
$stmt = $pdo->prepare("SELECT s.*, u.name cashier
                       FROM sales s
                       JOIN users u ON u.id=s.user_id
                       WHERE DATE(s.created_at) BETWEEN ? AND ?".$cashierSql."
                       ORDER BY s.id DESC");
$stmt->execute($rangeParams);
$sales = $stmt->fetchAll();
$validSales = array_values(array_filter($sales, fn($s) => empty($s['is_void'])));
$voidSales  = array_values(array_filter($sales, fn($s) => !empty($s['is_void'])));

$grossSales = array_sum(array_map(fn($s)=>(float)$s['subtotal'], $validSales));
$normalDiscount = array_sum(array_map(fn($s)=>(float)$s['discount'], $validSales));
$loyaltyDiscount = array_sum(array_map(fn($s)=>(float)$s['loyalty_discount'], $validSales));
$totalDiscount = $normalDiscount + $loyaltyDiscount;
$netSales = array_sum(array_map(fn($s)=>(float)$s['total'], $validSales));
$totalBills = count($validSales);
$avgBill = $totalBills ? $netSales / $totalBills : 0;
$voidAmount = array_sum(array_map(fn($s)=>(float)$s['total'], $voidSales));

/* Average items per completed bill */
$stmt = $pdo->prepare("SELECT COALESCE(SUM(si.qty),0)
                       FROM sale_items si
                       JOIN sales s ON s.id=si.sale_id
                       WHERE COALESCE(s.is_void,0)=0
                         AND DATE(s.created_at) BETWEEN ? AND ?".$cashierSql);
$stmt->execute($rangeParams);
$totalItemsSold = (float)$stmt->fetchColumn();
$avgItemsPerBill = $totalBills ? $totalItemsSold / $totalBills : 0;

/* Payment method summary */
$stmt = $pdo->prepare("SELECT s.payment_method,
                              COUNT(*) bills,
                              COALESCE(SUM(s.total),0) total
                       FROM sales s
                       WHERE COALESCE(s.is_void,0)=0
                         AND DATE(s.created_at) BETWEEN ? AND ?".$cashierSql."
                       GROUP BY s.payment_method
                       ORDER BY total DESC");
$stmt->execute($rangeParams);
$paymentRows = $stmt->fetchAll();
$payments = ['cash'=>['bills'=>0,'total'=>0], 'card'=>['bills'=>0,'total'=>0], 'other'=>['bills'=>0,'total'=>0]];
foreach ($paymentRows as $r) {
    $key = strtolower((string)$r['payment_method']);
    if (!isset($payments[$key])) $payments[$key] = ['bills'=>0,'total'=>0];
    $payments[$key] = ['bills'=>(int)$r['bills'], 'total'=>(float)$r['total']];
}

/* Top 10 products by gross product revenue */
$stmt = $pdo->prepare("SELECT si.product_name, si.size,
                              SUM(si.qty) qty,
                              SUM(si.line_total) revenue
                       FROM sale_items si
                       JOIN sales s ON s.id=si.sale_id
                       WHERE COALESCE(s.is_void,0)=0
                         AND DATE(s.created_at) BETWEEN ? AND ?
                       GROUP BY si.product_name,si.size
                       ORDER BY revenue DESC
                       LIMIT 10");
$stmt->execute($rangeParams);
$top = $stmt->fetchAll();

/* Lowest 5 products that actually sold in selected period */
$stmt = $pdo->prepare("SELECT si.product_name, si.size,
                              SUM(si.qty) qty,
                              SUM(si.line_total) revenue
                       FROM sale_items si
                       JOIN sales s ON s.id=si.sale_id
                       WHERE COALESCE(s.is_void,0)=0
                         AND DATE(s.created_at) BETWEEN ? AND ?
                       GROUP BY si.product_name,si.size
                       HAVING SUM(si.qty) > 0
                       ORDER BY revenue ASC, qty ASC
                       LIMIT 5");
$stmt->execute($rangeParams);
$lowest = $stmt->fetchAll();

/* Category sales */
$stmt = $pdo->prepare("SELECT COALESCE(NULLIF(si.category_name,''),'Uncategorized') category,
                              SUM(si.qty) qty,
                              SUM(si.line_total) revenue
                       FROM sale_items si
                       JOIN sales s ON s.id=si.sale_id
                       WHERE COALESCE(s.is_void,0)=0
                         AND DATE(s.created_at) BETWEEN ? AND ?
                       GROUP BY COALESCE(NULLIF(si.category_name,''),'Uncategorized')
                       ORDER BY revenue DESC");
$stmt->execute($rangeParams);
$categories = $stmt->fetchAll();
$maxCategoryRevenue = 0;
foreach ($categories as $c) $maxCategoryRevenue = max($maxCategoryRevenue, (float)$c['revenue']);

/* Cashier performance */
$stmt = $pdo->prepare("SELECT u.name cashier,
                              SUM(CASE WHEN COALESCE(s.is_void,0)=0 THEN 1 ELSE 0 END) bills,
                              COALESCE(SUM(CASE WHEN COALESCE(s.is_void,0)=0 THEN s.total ELSE 0 END),0) sales,
                              COALESCE(SUM(CASE WHEN COALESCE(s.is_void,0)=0 THEN (s.discount+s.loyalty_discount) ELSE 0 END),0) discounts,
                              SUM(CASE WHEN COALESCE(s.is_void,0)=1 THEN 1 ELSE 0 END) voids
                       FROM sales s
                       JOIN users u ON u.id=s.user_id
                       WHERE DATE(s.created_at) BETWEEN ? AND ?".$cashierSql."
                       GROUP BY s.user_id,u.name
                       ORDER BY sales DESC");
$stmt->execute($rangeParams);
$cashiers = $stmt->fetchAll();

$pageTitle='Sales Reports';
include __DIR__.'/../includes/header.php';
?>

<div class="print-report-header" aria-hidden="true">
  <div class="print-brand">
    <img src="<?= e(base_url('assets/logo.png')) ?>" alt="Misty Sip">
    <div><strong>MISTY SIP</strong><span>TEA • JUICE • ICE CREAM</span><small>BANDARAWELA</small></div>
  </div>
  <div class="print-title">
    <h1>Sales Report</h1>
    <p>From <?=date('d M Y',strtotime($from))?> to <?=date('d M Y',strtotime($to))?></p>
    <p class="print-cashier">Cashier: <?=e($cashierName)?></p>
  </div>
  <div class="print-meta">
    <div>Printed On: <?=date('d M Y, h:i A')?></div>
    <div>User: <?=e($user['name'] ?? 'Administrator')?></div>
  </div>
</div>

<form class="filters panel">
  <label>From<input type="date" name="from" value="<?=e($from)?>"></label>
  <label>To<input type="date" name="to" value="<?=e($to)?>"></label>
  <label>Cashier
    <select name="cashier_id">
      <option value="0">All Cashiers</option>
      <?php foreach($cashierOptions as $co): ?>
        <option value="<?=e($co['id'])?>" <?=((int)$co['id']===$cashierId)?'selected':''?>><?=e($co['name'])?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn primary">Apply</button>
  <button type="button" class="btn" onclick="printReport('summary')">Print Summary</button>
  <button type="button" class="btn" onclick="printReport('full')">Print Full Report</button>
</form>

<section class="stats report-main-stats">
  <article><small>Gross Sales</small><strong>LKR <?=number_format($grossSales,2)?></strong></article>
  <article><small>Discounts</small><strong>LKR <?=number_format($totalDiscount,2)?></strong></article>
  <article><small>Net Sales</small><strong>LKR <?=number_format($netSales,2)?></strong></article>
  <article><small>Total Bills</small><strong><?=e($totalBills)?></strong></article>
</section>

<section class="stats report-extra-stats">
  <article><small>Average Bill</small><strong>LKR <?=number_format($avgBill,2)?></strong></article>
  <article><small>Avg. Items / Bill</small><strong><?=number_format($avgItemsPerBill,2)?></strong></article>
  <article><small>Void Bills</small><strong><?=count($voidSales)?></strong></article>
  <article><small>Void Amount</small><strong>LKR <?=number_format($voidAmount,2)?></strong></article>
</section>

<section class="report-grid two-col">
  <article class="panel report-card">
    <div class="report-card-head"><h2>Payment Method Summary</h2><span>Net Sales</span></div>
    <div class="payment-grid">
      <?php foreach(['cash'=>'Cash','card'=>'Card','other'=>'Other'] as $key=>$label):
        $amt=(float)($payments[$key]['total']??0); $bills=(int)($payments[$key]['bills']??0); $pct=$netSales>0?($amt/$netSales)*100:0; ?>
      <div class="payment-item">
        <div class="payment-title"><strong><?=$label?></strong><span><?=$bills?> bill<?=$bills===1?'':'s'?></span></div>
        <div class="payment-amount">LKR <?=number_format($amt,2)?></div>
        <div class="mini-track"><i style="width:<?=min(100,$pct)?>%"></i></div>
        <small><?=number_format($pct,1)?>% of net sales</small>
      </div>
      <?php endforeach; ?>
    </div>
  </article>

  <article class="panel report-card">
    <div class="report-card-head"><h2>Discount Breakdown</h2><span>Completed Bills</span></div>
    <div class="discount-breakdown">
      <div><small>Normal Discount</small><strong>LKR <?=number_format($normalDiscount,2)?></strong></div>
      <div><small>Loyalty Discount</small><strong>LKR <?=number_format($loyaltyDiscount,2)?></strong></div>
      <div class="discount-total"><small>Total Discount</small><strong>LKR <?=number_format($totalDiscount,2)?></strong></div>
    </div>
  </article>
</section>

<?php $topMaxRevenue=0; foreach($top as $p) $topMaxRevenue=max($topMaxRevenue,(float)$p['revenue']); ?>
<section class="panel top-products-panel">
  <div class="top-products-head"><h2>Top Products</h2><span>Top 10 by Revenue</span></div>
  <?php if($top): ?>
  <div class="top-products-chart-wrap">
    <div class="top-products-axis"><span>LKR <?=number_format($topMaxRevenue,0)?></span><span>LKR <?=number_format($topMaxRevenue*.75,0)?></span><span>LKR <?=number_format($topMaxRevenue*.5,0)?></span><span>LKR <?=number_format($topMaxRevenue*.25,0)?></span><span>LKR 0</span></div>
    <div class="top-products-chart">
      <?php foreach($top as $p): $rev=(float)$p['revenue']; $pct=$topMaxRevenue>0?max(4,($rev/$topMaxRevenue)*100):0; $label=trim($p['product_name'].' '.(!empty($p['size'])?'('.$p['size'].')':'')); ?>
      <div class="top-product-item" title="<?=e($label)?> — LKR <?=number_format($rev,2)?> | Qty <?=e($p['qty'])?>">
        <div class="top-product-value">LKR <?=number_format($rev,0)?></div>
        <div class="top-product-bar-area"><div class="top-product-bar" style="height:<?=$pct?>%"><span><?=e(rtrim(rtrim(number_format((float)$p['qty'],2,'.',''),'0'),'.'))?> sold</span></div></div>
        <div class="top-product-name"><?=e($label)?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?><div class="empty top-products-empty">No product sales in selected period.</div><?php endif; ?>
</section>

<section class="report-grid two-col">
  <article class="panel report-card category-panel">
    <div class="report-card-head"><h2>Category Sales</h2><span>Gross Product Revenue</span></div>
    <?php if($categories): ?>
      <div class="category-list">
      <?php foreach($categories as $c): $pct=$maxCategoryRevenue>0?((float)$c['revenue']/$maxCategoryRevenue)*100:0; ?>
        <div class="category-row">
          <div class="category-meta"><strong><?=e($c['category'])?></strong><span><?=e(rtrim(rtrim(number_format((float)$c['qty'],2,'.',''),'0'),'.'))?> sold · LKR <?=number_format((float)$c['revenue'],2)?></span></div>
          <div class="category-track"><i style="width:<?=min(100,$pct)?>%"></i></div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php else: ?><div class="empty">No category sales in selected period.</div><?php endif; ?>
  </article>

  <article class="panel report-card low-products-panel">
    <div class="report-card-head"><h2>Lowest Selling Products</h2><span>Bottom 5 Sold Items</span></div>
    <?php if($lowest): ?>
      <div class="low-list">
      <?php foreach($lowest as $i=>$p): $label=trim($p['product_name'].' '.(!empty($p['size'])?'('.$p['size'].')':'')); ?>
        <div class="low-row"><b><?=($i+1)?></b><div><strong><?=e($label)?></strong><small><?=e(rtrim(rtrim(number_format((float)$p['qty'],2,'.',''),'0'),'.'))?> sold</small></div><span>LKR <?=number_format((float)$p['revenue'],2)?></span></div>
      <?php endforeach; ?>
      </div>
    <?php else: ?><div class="empty">No product sales in selected period.</div><?php endif; ?>
  </article>
</section>

<section class="panel report-card">
  <div class="report-card-head"><h2>Cashier Performance</h2><span>Selected Period</span></div>
  <div class="table-wrap"><table><thead><tr><th>Cashier</th><th>Bills</th><th>Net Sales</th><th>Discounts</th><th>Void Bills</th></tr></thead><tbody>
  <?php foreach($cashiers as $c): ?><tr><td><?=e($c['cashier'])?></td><td><?=e($c['bills'])?></td><td>LKR <?=number_format((float)$c['sales'],2)?></td><td>LKR <?=number_format((float)$c['discounts'],2)?></td><td><?=e($c['voids'])?></td></tr><?php endforeach; ?>
  <?php if(!$cashiers): ?><tr><td colspan="5" class="empty">No cashier activity in selected period.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<section class="panel print-detail-section"><div class="report-card-head"><h2>Sales</h2><span><?=count($validSales)?> Completed</span></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Cashier</th><th>Method</th><th>Total</th><th>Discount</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($validSales as $s):?><tr><td><a href="../receipt.php?id=<?=e($s['id'])?>"><?=e($s['bill_no'])?></a></td><td><?=e($s['cashier'])?></td><td><?=e(ucfirst($s['payment_method']))?></td><td><?=number_format((float)$s['total'],2)?></td><td><?=number_format((float)$s['discount']+(float)$s['loyalty_discount'],2)?></td><td><?=date('d M, h:i A',strtotime($s['created_at']))?></td><td><span class="status-completed">Completed</span></td><td><button class="btn small void-open-btn" type="button" data-sale-id="<?=e($s['id'])?>" data-bill-no="<?=e($s['bill_no'])?>">Void</button></td></tr><?php endforeach;?><?php if(!$validSales):?><tr><td colspan="8" class="empty">No completed sales in selected period.</td></tr><?php endif;?></tbody></table></div></section>

<section class="panel print-detail-section"><div class="report-card-head"><h2>Void Bill Report</h2><span><?=count($voidSales)?> Void</span></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Cashier</th><th>Method</th><th>Total</th><th>Discount</th><th>Date</th><th>Reason</th></tr></thead><tbody><?php foreach($voidSales as $s):?><tr class="void-sale"><td><a href="../receipt.php?id=<?=e($s['id'])?>"><?=e($s['bill_no'])?></a></td><td><?=e($s['cashier'])?></td><td><?=e(ucfirst($s['payment_method']))?></td><td><?=number_format((float)$s['total'],2)?></td><td><?=number_format((float)$s['discount']+(float)$s['loyalty_discount'],2)?></td><td><?=date('d M, h:i A',strtotime($s['created_at']))?></td><td><?=e($s['void_reason']?:'—')?></td></tr><?php endforeach;?><?php if(!$voidSales):?><tr><td colspan="7" class="empty">No void bills in selected period.</td></tr><?php endif;?></tbody></table></div></section>

<style>
.report-main-stats,.report-extra-stats{margin-bottom:14px}.report-extra-stats article{min-height:92px}.report-grid{display:grid;gap:14px;margin-bottom:14px}.report-grid.two-col{grid-template-columns:repeat(2,minmax(0,1fr))}.report-card{overflow:hidden}.report-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.report-card-head h2{margin:0}.report-card-head>span{font-size:11px;font-weight:800;color:#61776d;background:#eef7f2;border:1px solid #dcebe3;padding:6px 9px;border-radius:999px;white-space:nowrap}
.payment-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.payment-item{border:1px solid #e0ebe5;border-radius:14px;padding:13px;background:#fbfefc}.payment-title{display:flex;justify-content:space-between;gap:8px;align-items:center}.payment-title strong{color:#173d30}.payment-title span,.payment-item small{font-size:10px;color:#71847b}.payment-amount{margin:8px 0 7px;font-size:17px;font-weight:900;color:#0a6f4b}.mini-track,.category-track{height:7px;background:#e8f0ec;border-radius:999px;overflow:hidden}.mini-track i,.category-track i{display:block;height:100%;background:linear-gradient(90deg,#12a66f,#08744c);border-radius:inherit}.payment-item small{display:block;margin-top:6px}
.discount-breakdown{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.discount-breakdown>div{padding:16px;border:1px solid #e1ebe6;border-radius:14px;background:#fbfefc}.discount-breakdown small{display:block;color:#70827a;font-size:11px;margin-bottom:6px}.discount-breakdown strong{font-size:16px;color:#173d30}.discount-breakdown .discount-total{background:#edf8f2;border-color:#cde7d9}.discount-breakdown .discount-total strong{color:#08744c}
.top-products-panel{overflow:hidden;margin-bottom:14px}.top-products-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.top-products-head h2{margin:0}.top-products-head span{font-size:12px;font-weight:800;color:#6d7f77;background:#eef7f2;border:1px solid #dcebe3;padding:7px 10px;border-radius:999px}.top-products-chart-wrap{display:grid;grid-template-columns:76px 1fr;gap:10px;height:320px;padding:6px 4px 0}.top-products-axis{display:flex;flex-direction:column;justify-content:space-between;height:245px;padding-top:2px;color:#7a8982;font-size:10px;text-align:right}.top-products-chart{position:relative;height:320px;display:grid;grid-template-columns:repeat(10,minmax(0,1fr));align-items:start;gap:10px;padding:0 2px;background:repeating-linear-gradient(to bottom,transparent 0,transparent 60px,#e7eee9 61px,#e7eee9 62px)}.top-product-item{min-width:0;height:320px;display:grid;grid-template-rows:28px 217px 75px;text-align:center}.top-product-value{align-self:end;font-size:10px;font-weight:900;color:#0b6b49;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.top-product-bar-area{height:217px;display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid #cfdcd5}.top-product-bar{position:relative;width:min(52px,78%);min-height:8px;border-radius:8px 8px 2px 2px;background:linear-gradient(180deg,#11a46e,#0a7a50);box-shadow:0 7px 18px rgba(9,122,79,.13);display:flex;align-items:flex-start;justify-content:center;transition:.2s ease}.top-product-bar:hover{filter:brightness(1.05);transform:translateY(-2px)}.top-product-bar span{position:absolute;top:7px;left:50%;transform:translateX(-50%);font-size:9px;font-weight:900;color:#fff;white-space:nowrap;writing-mode:vertical-rl;text-orientation:mixed}.top-product-name{padding-top:8px;font-size:10px;line-height:1.25;color:#2f463d;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.top-products-empty{padding:30px 0}
.category-list{display:grid;gap:12px;max-height:310px;overflow:auto;padding-right:4px}.category-row{display:grid;gap:7px}.category-meta{display:flex;align-items:center;justify-content:space-between;gap:10px}.category-meta strong{font-size:12px;color:#203f34}.category-meta span{font-size:10px;color:#71837b;text-align:right}.category-track{height:8px}.low-list{display:grid;gap:8px}.low-row{display:grid;grid-template-columns:30px 1fr auto;gap:10px;align-items:center;padding:10px 11px;border:1px solid #e3ece7;border-radius:12px;background:#fbfefc}.low-row>b{display:grid;place-items:center;width:28px;height:28px;border-radius:9px;background:#eef7f2;color:#08744c}.low-row div{min-width:0}.low-row strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#28463b;font-size:12px}.low-row small{display:block;margin-top:3px;color:#7b8b84;font-size:10px}.low-row>span{font-size:11px;font-weight:900;color:#0a6f4b;white-space:nowrap}
.status-completed{display:inline-block;padding:4px 8px;border-radius:999px;background:#eaf7f0;color:#08744c;font-size:10px;font-weight:800}
.void-modal[hidden]{display:none!important}.void-modal{position:fixed;inset:0;z-index:10050;display:grid;place-items:center;padding:20px}.void-modal-backdrop{position:absolute;inset:0;background:rgba(2,35,24,.67);backdrop-filter:blur(5px)}.void-modal-box{position:relative;width:min(460px,94vw);padding:28px 30px 26px;border:1px solid rgba(255,255,255,.75);border-radius:24px;background:linear-gradient(145deg,#fff,#f4fbf7);box-shadow:0 30px 90px rgba(0,35,23,.38);animation:voidModalIn .18s ease-out}.void-modal-icon{display:grid;place-items:center;width:62px;height:62px;margin:0 auto 10px;border-radius:18px;background:linear-gradient(135deg,#fff0eb,#ffe3dc);color:#c23b2d;font-size:29px;font-weight:900}.void-modal-label{text-align:center;color:#0a7a50;font-size:10px;font-weight:900;letter-spacing:1.8px}.void-modal-box h2{margin:6px 0 4px;text-align:center;color:#083f2d;font-size:25px}.void-modal-box>p{margin:0 0 20px;text-align:center;color:#6d7f77;font-size:12px;line-height:1.5}.void-bill-chip{display:inline-flex;align-items:center;justify-content:center;min-width:76px;margin-left:4px;padding:4px 9px;border-radius:999px;background:#e8f6ef;color:#086d49;font-weight:900}.void-modal-box label{margin-bottom:18px}.void-modal-box textarea{width:100%;min-height:94px;resize:vertical;padding:13px 14px;border:1px solid #cbdcd4;border-radius:12px;background:#fff;color:#173c2f;font:inherit;outline:none}.void-modal-box textarea:focus{border-color:#0f7a50;box-shadow:0 0 0 3px rgba(15,122,80,.12)}.void-modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:11px}.void-modal-actions button{min-height:48px;border-radius:12px;font-weight:900}.void-cancel{border:1px solid #d6e2dc;background:#fff;color:#405c51}.void-confirm{border:1px solid #a93226;background:linear-gradient(135deg,#c84738,#a93226);color:#fff;box-shadow:0 8px 20px rgba(169,50,38,.22)}.void-cancel:hover{background:#f2f7f4}.void-confirm:hover{filter:brightness(1.05)}.void-modal-close{position:absolute;right:16px;top:14px;width:34px;height:34px;border:0;border-radius:50%;background:#edf6f1;color:#0c6e4c;font-size:21px;cursor:pointer}.void-modal-open{overflow:hidden}@keyframes voidModalIn{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:none}}
@media(max-width:1100px){.report-grid.two-col{grid-template-columns:1fr}.payment-grid{grid-template-columns:repeat(3,1fr)}.top-products-chart-wrap{overflow-x:auto;grid-template-columns:68px minmax(900px,1fr)}.top-products-chart{min-width:900px}}@media(max-width:720px){.payment-grid,.discount-breakdown{grid-template-columns:1fr}.category-meta{align-items:flex-start;flex-direction:column}.category-meta span{text-align:left}}@media(max-width:520px){.void-modal-box{padding:24px 18px}.void-modal-actions{grid-template-columns:1fr}}
@page{size:A4 portrait;margin:8mm}
.print-report-header,.print-report-footer{display:none}
@media print{
  /* Override the global Cashier Shift print rule that hides body children. */
  body > *{visibility:visible!important}
  .main,.main *{visibility:visible!important}
  html,body{width:210mm!important;min-height:297mm!important;margin:0!important;padding:0!important;background:#fff!important;color:#173c2f!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;font-family:Arial,Helvetica,sans-serif!important}
  body{font-size:12px!important;overflow:visible!important}
  .sidebar,.topbar,.ms-logout-overlay,.filters,.void-modal,.void-open-btn{display:none!important}
  .main{margin:0!important;padding:0!important;width:auto!important;max-width:none!important;min-width:0!important;background:#fff!important}
  .print-report-header{display:grid!important;grid-template-columns:1fr 1.25fr 1fr;align-items:start;gap:8mm;margin:0 0 4mm;padding-bottom:3mm;border-bottom:1px solid #dce8e2}
  .print-brand{display:flex;align-items:center;gap:3mm}.print-brand img{width:18mm;height:18mm;object-fit:contain}.print-brand div{display:grid}.print-brand strong{font-size:19px;color:#075b3b;letter-spacing:.4px}.print-brand span{font-size:9.5px;font-weight:800;color:#075b3b}.print-brand small{font-size:9px;font-weight:800;color:#075b3b}
  .print-title{text-align:center}.print-title h1{margin:1mm 0 .8mm;font-size:31px;line-height:1;color:#0a5e43}.print-title p{margin:0;font-size:13px;color:#233c33}
  .print-meta{text-align:right;font-size:10.5px;line-height:1.7;color:#40554d;padding-top:1mm}
  .panel,.stats article{box-shadow:none!important;border:1px solid #d8e5df!important;background:#fff!important;border-radius:3mm!important}
  .report-main-stats{display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:2.4mm!important;margin:0 0 2.8mm!important}.report-main-stats article{min-height:18mm!important;padding:3mm!important;text-align:center!important}.report-main-stats small{font-size:10.5px!important;font-weight:800!important;color:#496159!important}.report-main-stats strong{display:block!important;margin-top:1.5mm!important;font-size:18px!important;line-height:1.05!important;color:#063d2a!important}
  .report-main-stats article:nth-child(1){background:#eef8ff!important;border-color:#c8dff4!important}.report-main-stats article:nth-child(2){background:#fff1f3!important;border-color:#f2cbd2!important}.report-main-stats article:nth-child(3){background:#fff8ea!important;border-color:#f1ddaf!important}.report-main-stats article:nth-child(4){background:#effaf4!important;border-color:#cde7d9!important}
  .report-extra-stats{display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:2.4mm!important;margin:0 0 2.8mm!important}.report-extra-stats article{min-height:14mm!important;padding:2.6mm!important;text-align:center!important}.report-extra-stats small{font-size:9.5px!important}.report-extra-stats strong{display:block!important;margin-top:1mm!important;font-size:15px!important}
  .report-grid{gap:2.4mm!important;margin-bottom:2.8mm!important}.report-grid.two-col{grid-template-columns:1.15fr .85fr!important}.report-card,.top-products-panel,.panel{padding:3mm!important;margin-bottom:2.8mm!important;break-inside:avoid;page-break-inside:avoid}
  .report-card-head,.top-products-head{margin-bottom:2.2mm!important}.report-card-head h2,.top-products-head h2,.panel>h2{font-size:16px!important;color:#075b3b!important}.report-card-head>span,.top-products-head span{font-size:9px!important;padding:1mm 2mm!important}
  .payment-grid{grid-template-columns:repeat(3,1fr)!important;gap:1.8mm!important}.payment-item{padding:2mm!important;border-radius:2mm!important}.payment-title strong{font-size:10px!important}.payment-title span,.payment-item small{font-size:8px!important}.payment-amount{font-size:12px!important;margin:1.3mm 0!important}.mini-track{height:1.4mm!important}
  .discount-breakdown{gap:1.8mm!important}.discount-breakdown>div{padding:2.2mm!important;border-radius:2mm!important}.discount-breakdown small{font-size:8.5px!important}.discount-breakdown strong{font-size:12px!important}
  .top-products-panel{height:61mm!important}.top-products-chart-wrap{grid-template-columns:13mm 1fr!important;gap:2mm!important;height:48mm!important;padding:0!important;overflow:visible!important}.top-products-axis{height:35mm!important;font-size:8px!important}.top-products-chart{height:48mm!important;min-width:0!important;gap:1.6mm!important;background:repeating-linear-gradient(to bottom,transparent 0,transparent 8.5mm,#e7eee9 8.7mm,#e7eee9 9mm)!important}.top-product-item{height:48mm!important;grid-template-rows:5mm 30mm 13mm!important}.top-product-value{font-size:8.5px!important}.top-product-bar-area{height:30mm!important}.top-product-bar{width:70%!important;border-radius:1.5mm 1.5mm .5mm .5mm!important;box-shadow:none!important}.top-product-bar span{font-size:7px!important;top:1.5mm!important}.top-product-name{font-size:7.5px!important;line-height:1.1!important;padding-top:1.3mm!important;-webkit-line-clamp:3!important}
  .category-list{max-height:none!important;overflow:visible!important;gap:1.6mm!important}.category-meta strong{font-size:9px!important}.category-meta span{font-size:8px!important}.category-track{height:1.4mm!important}.low-list{gap:1.2mm!important}.low-row{grid-template-columns:6mm 1fr auto!important;gap:1.5mm!important;padding:1.6mm!important;border-radius:1.5mm!important}.low-row>b{width:5mm!important;height:5mm!important;border-radius:1mm!important;font-size:8.5px!important}.low-row strong{font-size:9px!important}.low-row small,.low-row>span{font-size:8px!important}
  .table-wrap{overflow:visible!important}.table-wrap table{width:100%!important;border-collapse:collapse!important;table-layout:auto!important;font-size:9.2px!important}.table-wrap thead{display:table-header-group}.table-wrap tr{break-inside:avoid;page-break-inside:avoid}.table-wrap th{background:#087a58!important;color:#fff!important;font-size:8.8px!important;padding:1.55mm 1.25mm!important;border:1px solid #cbdcd4!important}.table-wrap td{padding:1.7mm 1.4mm!important;border:1px solid #dce6e1!important;color:#263d35!important;white-space:normal!important}.table-wrap a{color:#075b3b!important;text-decoration:none!important}.status-completed{padding:.8mm 1.5mm!important;font-size:8px!important}
  section.panel:has(.report-card-head h2:first-child){break-inside:auto}
  .void-sale td{background:#fff5f5!important}.void-sale td:first-child{border-left:2px solid #ef5b62!important}
  .print-report-footer{display:flex!important;align-items:center;gap:2mm;margin-top:3mm;padding-top:2mm;border-top:1px solid #cfdcd5;font-size:9px;color:#53665e}.print-report-footer strong{color:#173c2f}.print-report-footer em{margin-left:auto;font-style:normal}
}

</style>

<div class="print-report-footer" aria-hidden="true"><strong>MISTY SIP</strong><span>TEA • JUICE • ICE CREAM &nbsp; | &nbsp; BANDARAWELA</span><em>Sales Report</em></div>

<div class="void-modal" id="voidSaleModal" hidden>
  <div class="void-modal-backdrop" data-close-void></div>
  <form class="void-modal-box" method="post" action="void_sale.php" id="voidSaleForm">
    <button class="void-modal-close" type="button" aria-label="Close" data-close-void>&times;</button>
    <div class="void-modal-icon">!</div>
    <div class="void-modal-label">VOID BILL</div>
    <h2>Confirm Bill Void</h2>
    <p>You are about to void <span class="void-bill-chip" id="voidBillNo">—</span> and return its stock.</p>
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="sale_id" id="voidSaleId">
    <label>Reason for voiding this bill
      <textarea name="reason" id="voidReason" maxlength="255" placeholder="Enter the reason for voiding this bill..." required></textarea>
    </label>
    <div class="void-modal-actions">
      <button class="void-cancel" type="button" data-close-void>Cancel</button>
      <button class="void-confirm" type="submit">Confirm Void</button>
    </div>
  </form>
</div>

<script>
(function(){
  // Apply shows the selected range. A browser refresh returns this page to today's default date.
  if (window.location.search) window.history.replaceState({}, document.title, window.location.pathname);
})();

(function(){
  const modal=document.getElementById('voidSaleModal'),form=document.getElementById('voidSaleForm'),saleId=document.getElementById('voidSaleId'),billNo=document.getElementById('voidBillNo'),reason=document.getElementById('voidReason');
  if(!modal||!form)return;
  function openVoid(btn){saleId.value=btn.dataset.saleId||'';billNo.textContent=btn.dataset.billNo||'—';reason.value='';modal.hidden=false;document.body.classList.add('void-modal-open');setTimeout(()=>reason.focus(),30)}
  function closeVoid(){modal.hidden=true;document.body.classList.remove('void-modal-open');reason.value=''}
  document.querySelectorAll('.void-open-btn').forEach(btn=>btn.addEventListener('click',()=>openVoid(btn)));
  modal.querySelectorAll('[data-close-void]').forEach(el=>el.addEventListener('click',closeVoid));
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)closeVoid()});
  form.addEventListener('submit',e=>{if(!reason.value.trim()){e.preventDefault();reason.focus();return}reason.value=reason.value.trim()});
})();
</script>

<?php include __DIR__.'/../includes/footer.php'; ?>


<style>
.filters select{height:42px;border:1px solid #d5e3dd;border-radius:9px;padding:0 11px;background:#fff;min-width:160px}
@media print{
  /* Summary print removes row-heavy bill tables to keep page count low. */
  body.print-summary .print-detail-section{display:none!important}
  /* Full print keeps bill tables but packs more rows per A4 page. */
  body.print-full .print-detail-section table th,
  body.print-full .print-detail-section table td{
    font-size:9.5pt!important;
    line-height:1.15!important;
    padding:4px 5px!important;
  }
  body.print-full .print-detail-section table{page-break-inside:auto!important}
  body.print-full .print-detail-section tr{page-break-inside:avoid!important;page-break-after:auto!important}
  body.print-full .print-detail-section thead{display:table-header-group!important}
  .print-cashier{margin-top:2px!important}
}
</style>


<script>
function printReport(mode){
  document.body.classList.remove('print-summary','print-full');
  document.body.classList.add(mode==='full'?'print-full':'print-summary');
  window.print();
}
window.addEventListener('afterprint',function(){
  document.body.classList.remove('print-summary','print-full');
});
</script>
