<?php
require_once __DIR__ . '/includes/bootstrap.php'; require_login(); $currentUser=auth_user();
$isAdmin=($currentUser['role']??'')==='admin';
$cashierHasOpenShift=($currentUser['role']??'')==='cashier' ? (bool)active_cashier_shift((int)$currentUser['id']) : true;
$pageTitle='Dashboard'; $pageSubtitle='Freshly made • Naturally refreshing'; $showPosHeaderLogo=true;
$posLowStockLevel=max(0,app_setting_int('low_stock_level',5));
$lowStmt=db()->prepare("SELECT name,stock_qty FROM products WHERE is_active=1 AND stock_qty<=? ORDER BY stock_qty ASC,name ASC");
$lowStmt->execute([$posLowStockLevel]);
$lowStockItems=$lowStmt->fetchAll();
$lowConsumables=$isAdmin?db()->query("SELECT name,stock_qty FROM consumables WHERE is_active=1 AND stock_qty<=low_stock_level ORDER BY stock_qty ASC,name ASC")->fetchAll():[];
$low=count($lowStockItems)+count($lowConsumables);
$recentSql="SELECT s.id,s.bill_no,s.total,s.payment_method,s.created_at,u.name cashier FROM sales s JOIN users u ON u.id=s.user_id WHERE s.is_void=0".($isAdmin?'':' AND s.user_id=?')." ORDER BY s.id DESC LIMIT 8";
$recentStmt=db()->prepare($recentSql); $recentStmt->execute($isAdmin?[]:[(int)$currentUser['id']]); $recent=$recentStmt->fetchAll();
$paymentSql="SELECT COUNT(*) today_bills,COALESCE(SUM(total),0) today_sales,COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) cash_total,COALESCE(SUM(CASE WHEN payment_method='card' THEN total ELSE 0 END),0) card_total,COALESCE(SUM(CASE WHEN payment_method='other' THEN total ELSE 0 END),0) other_total,COALESCE(SUM(CASE WHEN payment_method='cash' THEN 1 ELSE 0 END),0) cash_bills,COALESCE(SUM(CASE WHEN payment_method='card' THEN 1 ELSE 0 END),0) card_bills,COALESCE(SUM(CASE WHEN payment_method='other' THEN 1 ELSE 0 END),0) other_bills FROM sales WHERE is_void=0 AND DATE(created_at)=CURDATE()".($isAdmin?'':' AND user_id=?');
$paymentStmt=db()->prepare($paymentSql);
$paymentStmt->execute($isAdmin?[]:[(int)$currentUser['id']]); $payments=$paymentStmt->fetch();
$yesterdaySql="SELECT COALESCE(SUM(total),0) yesterday_sales FROM sales WHERE is_void=0 AND DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)".($isAdmin?'':' AND user_id=?');
$yesterdayStmt=db()->prepare($yesterdaySql);
$yesterdayStmt->execute($isAdmin?[]:[(int)$currentUser['id']]);
$yesterdaySales=(float)($yesterdayStmt->fetch()['yesterday_sales']??0);
$todaySales=(float)$payments['today_sales'];
if($yesterdaySales>0){
  $salesChangePercent=(($todaySales-$yesterdaySales)/$yesterdaySales)*100;
  $salesChangeType=$salesChangePercent>0?'up':($salesChangePercent<0?'down':'same');
}else{
  $salesChangePercent=$todaySales>0?100:0;
  $salesChangeType=$todaySales>0?'up':'same';
}
$todayPaymentTotal=(float)$payments['cash_total']+(float)$payments['card_total']+(float)$payments['other_total'];
$cashPercent=$todayPaymentTotal>0?((float)$payments['cash_total']/$todayPaymentTotal*100):0;
$cardPercent=$todayPaymentTotal>0?((float)$payments['card_total']/$todayPaymentTotal*100):0;
$otherPercent=$todayPaymentTotal>0?((float)$payments['other_total']/$todayPaymentTotal*100):0;
$cashDegrees=$cashPercent*3.6; $cashCardDegrees=($cashPercent+$cardPercent)*3.6;
$monthlySql="SELECT COUNT(*) total_bills,COALESCE(SUM(total),0) total_sales,COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) cash_total,COALESCE(SUM(CASE WHEN payment_method='card' THEN total ELSE 0 END),0) credit_total,COALESCE(SUM(CASE WHEN payment_method='other' THEN total ELSE 0 END),0) other_total FROM sales WHERE is_void=0 AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())".($isAdmin?'':' AND user_id=?');
$monthlyStmt=db()->prepare($monthlySql);
$monthlyStmt->execute($isAdmin?[]:[(int)$currentUser['id']]); $monthly=$monthlyStmt->fetch();
$monthlyPaymentTotal=(float)$monthly['cash_total']+(float)$monthly['credit_total']+(float)$monthly['other_total'];
$monthlyCashPercent=$monthlyPaymentTotal>0?((float)$monthly['cash_total']/$monthlyPaymentTotal*100):0;
$monthlyCardPercent=$monthlyPaymentTotal>0?((float)$monthly['credit_total']/$monthlyPaymentTotal*100):0;
$monthlyOtherPercent=$monthlyPaymentTotal>0?((float)$monthly['other_total']/$monthlyPaymentTotal*100):0;
$monthlyCashDegrees=$monthlyCashPercent*3.6; $monthlyCashCardDegrees=($monthlyCashPercent+$monthlyCardPercent)*3.6;

// Cashier-only operational snapshot.
$cashierShift=null;
$cashierShiftStats=['bills'=>0,'sales'=>0,'cash'=>0,'card'=>0,'other'=>0,'discounts'=>0,'voids'=>0];
if(!$isAdmin){
  $cashierShift=active_cashier_shift((int)$currentUser['id']);
  if($cashierShift){
    $shiftStatsStmt=db()->prepare("SELECT COUNT(*) bills,COALESCE(SUM(total),0) sales,COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) cash,COALESCE(SUM(CASE WHEN payment_method='card' THEN total ELSE 0 END),0) card,COALESCE(SUM(CASE WHEN payment_method='other' THEN total ELSE 0 END),0) other,COALESCE(SUM(discount+loyalty_discount),0) discounts FROM sales WHERE shift_id=? AND user_id=? AND is_void=0");
    $shiftStatsStmt->execute([(int)$cashierShift['id'],(int)$currentUser['id']]);
    $cashierShiftStats=array_merge($cashierShiftStats,$shiftStatsStmt->fetch()?:[]);
    $shiftVoidStmt=db()->prepare("SELECT COUNT(*) voids FROM sales WHERE shift_id=? AND user_id=? AND is_void=1");
    $shiftVoidStmt->execute([(int)$cashierShift['id'],(int)$currentUser['id']]);
    $cashierShiftStats['voids']=(int)($shiftVoidStmt->fetch()['voids']??0);
  }
  $todayDiscountStmt=db()->prepare("SELECT COALESCE(SUM(discount+loyalty_discount),0) discounts,COUNT(CASE WHEN is_void=1 THEN 1 END) voids FROM sales WHERE user_id=? AND DATE(created_at)=CURDATE()");
  $todayDiscountStmt->execute([(int)$currentUser['id']]);
  $todayOps=$todayDiscountStmt->fetch()?:[];
  $cashierTodayDiscount=(float)($todayOps['discounts']??0);
  $cashierTodayVoids=(int)($todayOps['voids']??0);
}

// Today's hourly sales chart (admin only; shop hours: 08:00 AM - 11:00 PM).
$hourlySales=[];
$hourlyMax=1;
if($isAdmin){
  $hourlySales=array_fill(8,16,0.0);
  $hourlySql="SELECT HOUR(created_at) sale_hour, COALESCE(SUM(total),0) hourly_total FROM sales WHERE is_void=0 AND DATE(created_at)=CURDATE() AND HOUR(created_at) BETWEEN 8 AND 23 GROUP BY HOUR(created_at)";
  $hourlyStmt=db()->query($hourlySql);
  foreach($hourlyStmt->fetchAll() as $hourRow){
    $hour=(int)$hourRow['sale_hour'];
    if(isset($hourlySales[$hour])) $hourlySales[$hour]=(float)$hourRow['hourly_total'];
  }
  $hourlyMax=max($hourlySales) ?: 1;
  $hourlyTotal=array_sum($hourlySales);
  $peakHour=array_search(max($hourlySales),$hourlySales,true);
  $peakAmount=$peakHour!==false?(float)$hourlySales[$peakHour]:0;
  $peakLabel=$peakAmount>0?date('g A',mktime((int)$peakHour,0,0)):'—';
}

// Admin-only management snapshots.
$openCashierShifts=[]; $topSellingItems=[]; $todayCashAudit=['closed_shifts'=>0,'net_difference'=>0,'short_total'=>0,'over_total'=>0];
$topPeriod=$_GET['top_period']??'today';
if(!in_array($topPeriod,['today','week','month'],true)) $topPeriod='today';
$topPeriodSql=$topPeriod==='week' ? 'YEARWEEK(s.created_at,1)=YEARWEEK(CURDATE(),1)' : ($topPeriod==='month' ? 'YEAR(s.created_at)=YEAR(CURDATE()) AND MONTH(s.created_at)=MONTH(CURDATE())' : 'DATE(s.created_at)=CURDATE()');
$topPeriodLabel=$topPeriod==='week'?'This Week':($topPeriod==='month'?'This Month':'Today');
if($isAdmin){
  $openCashierShifts=db()->query("SELECT cs.id,cs.shift_started_at,u.name cashier,COALESCE((SELECT SUM(s.total) FROM sales s WHERE s.is_void=0 AND s.user_id=cs.user_id AND s.created_at>=cs.shift_started_at),0) current_sales FROM cashier_shifts cs JOIN users u ON u.id=cs.user_id WHERE cs.closed_at IS NULL ORDER BY cs.shift_started_at ASC")->fetchAll();
  $topSellingItems=db()->query("SELECT si.product_id,si.product_name,COALESCE(p.image_path,'') image_path,SUM(si.qty) qty,SUM(si.line_total) sales FROM sale_items si JOIN sales s ON s.id=si.sale_id LEFT JOIN products p ON p.id=si.product_id WHERE s.is_void=0 AND $topPeriodSql GROUP BY si.product_id,si.product_name,p.image_path ORDER BY qty DESC,sales DESC LIMIT 5")->fetchAll();
  $todayCashAudit=db()->query("SELECT COUNT(*) closed_shifts,COALESCE(SUM(cash_difference),0) net_difference,COALESCE(SUM(CASE WHEN cash_difference<0 THEN ABS(cash_difference) ELSE 0 END),0) short_total,COALESCE(SUM(CASE WHEN cash_difference>0 THEN cash_difference ELSE 0 END),0) over_total FROM cashier_shifts WHERE closed_at IS NOT NULL AND DATE(closed_at)=CURDATE()")->fetch();
}
include __DIR__.'/includes/header.php';
?>
<script>document.body.classList.add('dashboard-page');</script>
<?php if(!$isAdmin): ?>
<section class="panel stock-details-panel <?= $low?'has-low-stock':'' ?>">
  <div class="stock-details-head"><h2>Inventory Status</h2><div class="stock-alert-wrap"><span class="stock-alert-label"><?= $low?'Low Stock Alert':'Stock OK' ?></span><strong class="stock-alert-count"><?= e($low) ?></strong></div></div>
  <div class="stock-details-body">
    <article class="stock-summary-card <?= $low?'warn':'' ?>"><small>Low Stock Items</small><strong><?= e($low) ?></strong><em>Products<?= $isAdmin?' and packaging':'' ?> at or below the alert level</em></article>
    <?php if($lowStockItems||$lowConsumables): ?><div class="low-stock-list">
      <?php foreach($lowStockItems as $item): ?>
        <article><div><strong><?= e($item['name']) ?></strong></div><span><?= e($item['stock_qty']) ?> left</span></article>
      <?php endforeach; ?>
      <?php foreach($lowConsumables as $item): ?>
        <article><div><strong><?= e($item['name']) ?></strong><small>Packaging</small></div><span><?= e($item['stock_qty']) ?> left</span></article>
      <?php endforeach; ?>
    </div><?php endif; ?>
  </div>
</section>

<?php endif; ?>
<?php if($isAdmin): ?>
<section class="panel stock-details-panel <?= $low?'has-low-stock':'' ?>">
  <div class="stock-details-head"><h2>Inventory Status</h2><div class="stock-alert-wrap"><span class="stock-alert-label"><?= $low?'Low Stock Alert':'Stock OK' ?></span><strong class="stock-alert-count"><?= e($low) ?></strong></div></div>
  <div class="stock-details-body">
    <article class="stock-summary-card <?= $low?'warn':'' ?>"><small>Low Stock Items</small><strong><?= e($low) ?></strong><em>Products<?= $isAdmin?' and packaging':'' ?> at or below the alert level</em></article>
    <?php if($lowStockItems||$lowConsumables): ?><div class="low-stock-list">
      <?php foreach($lowStockItems as $item): ?>
        <article><div><strong><?= e($item['name']) ?></strong></div><span><?= e($item['stock_qty']) ?> left</span></article>
      <?php endforeach; ?>
      <?php foreach($lowConsumables as $item): ?>
        <article><div><strong><?= e($item['name']) ?></strong><small>Packaging</small></div><span><?= e($item['stock_qty']) ?> left</span></article>
      <?php endforeach; ?>
    </div><?php endif; ?>
  </div>
</section>
<?php endif; ?>

<style>
/* Dashboard alerts and daily comparison */
.stock-details-panel.has-low-stock{border:1px solid #f3c7c7;box-shadow:0 10px 28px rgba(185,28,28,.08)}
.stock-alert-wrap{display:flex;align-items:center;gap:8px}
.stock-alert-label{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#ecfdf5;color:#167a52;font-size:11px;font-weight:800}
.has-low-stock .stock-alert-label{background:#fff1f2;color:#b42318}
.has-low-stock .stock-alert-count{background:#dc2626;color:#fff;box-shadow:0 0 0 4px rgba(220,38,38,.10)}
.stock-summary-card.warn{border-color:#f3c7c7;background:linear-gradient(135deg,#fff7f7,#fff)}
.sales-change{display:inline-flex;align-items:center;gap:5px;margin-top:7px;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800;line-height:1}
.sales-change.up{background:#ecfdf5;color:#167a52}
.sales-change.down{background:#fff1f2;color:#b42318}
.sales-change.same{background:#f3f4f6;color:#667085}
.sales-change small{font:inherit;color:inherit;opacity:.82}
/* Cashier shift data merged into Today's Payment Summary */
.cashier-payment-extra{border-top:1px solid #e2ece7;margin-top:18px;padding-top:16px}
.cashier-payment-status{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;color:#667d73;font-size:11px}
.cashier-payment-status strong{color:#294d40}
.cashier-payment-extra-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
.cashier-payment-extra-grid article{padding:12px 13px;border:1px solid #dce8e2;border-radius:12px;background:#f9fcfa}
.cashier-payment-extra-grid article:nth-child(4){background:#eef8f2;border-color:#bfe0cc}
.cashier-payment-extra-grid article.void-metric{background:#fffafa;border-color:#f2c6c6}
.cashier-payment-extra-grid small{display:block;color:#71857d;font-size:10px;font-weight:800;margin-bottom:5px}
.cashier-payment-extra-grid strong{display:block;color:#173c2f;font-size:14px;line-height:1.2}
.cashier-no-shift.merged{margin-top:2px}
@media(max-width:1100px){.cashier-payment-extra-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:680px){.cashier-payment-extra-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:460px){.cashier-payment-extra-grid{grid-template-columns:1fr}}

/* Dashboard Hourly Sales Chart — refined compact version */
.hourly-sales-panel{overflow:hidden;padding:20px 22px}
.hourly-sales-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:10px}
.hourly-sales-head h2{margin:0 0 4px;font-size:24px}
.hourly-sales-head p{margin:0;color:#71847c;font-size:12px}
.hourly-head-metrics{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.hourly-mini-stat{display:flex;flex-direction:column;gap:2px;min-width:105px;padding:8px 12px;border:1px solid #dce8e2;border-radius:12px;background:#f8fbf9;text-align:right}
.hourly-mini-stat small{font-size:9px;color:#71847c;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.hourly-mini-stat strong{font-size:12px;color:#173c2f}
.hourly-chart-wrap{width:100%;overflow-x:auto;padding:4px 0 0}
.hourly-chart{display:grid!important;grid-template-columns:repeat(16,minmax(56px,1fr))!important;gap:8px!important;align-items:end!important;min-width:940px!important;height:225px!important;padding:6px 6px 0!important;border-bottom:1px solid #dce8e2!important;background:repeating-linear-gradient(to top,transparent 0,transparent 43px,#eef4f1 44px)!important}
.hourly-column{display:grid!important;grid-template-rows:26px 155px 30px!important;align-items:end!important;min-width:0!important;text-align:center!important}
.hourly-value{display:block!important;align-self:center!important;color:#365a4b!important;font-size:10px!important;font-weight:900!important;white-space:nowrap!important;min-height:14px!important}
.hourly-value.zero{visibility:hidden!important}
.hourly-track{position:relative!important;display:flex!important;align-items:flex-end!important;justify-content:center!important;height:155px!important;width:100%!important}
.hourly-bar{display:block!important;width:42px!important;max-width:78%!important;min-height:0!important;border-radius:10px 10px 3px 3px!important;box-shadow:0 6px 14px rgba(23,60,47,.14)!important;transition:height .35s ease,transform .18s ease!important}
.hourly-column:hover .hourly-bar{transform:translateY(-2px)!important}
.hourly-label{display:block!important;align-self:center!important;color:#52685f!important;font-size:10px!important;font-weight:800!important;white-space:nowrap!important}
.hourly-color-1{background:linear-gradient(180deg,#16a36a,#0f7a50)!important}
.hourly-color-2{background:linear-gradient(180deg,#f59e0b,#d98200)!important}
.hourly-color-3{background:linear-gradient(180deg,#3b82f6,#2563eb)!important}
.hourly-color-4{background:linear-gradient(180deg,#8b5cf6,#7c3aed)!important}
.hourly-color-5{background:linear-gradient(180deg,#ec4899,#db2777)!important}
.hourly-color-6{background:linear-gradient(180deg,#14b8a6,#0f8f83)!important}
.hourly-color-7{background:linear-gradient(180deg,#f97316,#ea580c)!important}
.hourly-color-8{background:linear-gradient(180deg,#06b6d4,#0891b2)!important}
.hourly-color-9{background:linear-gradient(180deg,#84cc16,#65a30d)!important}
.hourly-color-10{background:linear-gradient(180deg,#6366f1,#4f46e5)!important}
.hourly-color-11{background:linear-gradient(180deg,#eab308,#ca8a04)!important}
.hourly-color-12{background:linear-gradient(180deg,#d946ef,#c026d3)!important}
.hourly-color-13{background:linear-gradient(180deg,#22c55e,#16a34a)!important}
.hourly-color-14{background:linear-gradient(180deg,#0ea5e9,#0284c7)!important}
.hourly-color-15{background:linear-gradient(180deg,#f43f5e,#e11d48)!important}
.hourly-color-16{background:linear-gradient(180deg,#a855f7,#9333ea)!important}
@media(max-width:700px){.hourly-sales-panel{padding:16px}.hourly-sales-head{flex-direction:column}.hourly-head-metrics{justify-content:flex-start}.hourly-chart{min-width:900px!important;height:215px!important}.hourly-column{grid-template-rows:24px 145px 28px!important}.hourly-track{height:145px!important}}
</style>
<?php if($isAdmin): ?>
<section class="panel hourly-sales-panel">
  <div class="payment-summary-head hourly-sales-head">
    <div><h2>Hourly Sales</h2><p>Today's sales from 8:00 AM to 11:00 PM</p></div>
    <div class="hourly-head-metrics">
      <span class="hourly-mini-stat"><small>Today Total</small><strong>LKR <?= number_format($hourlyTotal,0) ?></strong></span>
      <span class="hourly-mini-stat"><small>Peak Hour</small><strong><?= e($peakLabel) ?><?= $peakAmount>0?' · LKR '.number_format($peakAmount,0):'' ?></strong></span>
    </div>
  </div>
  <div class="hourly-chart-wrap">
    <div class="hourly-chart" role="img" aria-label="Hourly sales chart from 8 AM to 11 PM">
      <?php $hourIndex=0; foreach($hourlySales as $hour=>$amount): $height=$hourlyMax>0?max($amount>0?5:0,($amount/$hourlyMax)*100):0; $label=date('g A',mktime($hour,0,0)); ?>
        <div class="hourly-column">
          <div class="hourly-value<?= $amount<=0?' zero':'' ?>"><?= $amount>0?'LKR '.number_format($amount,0):'0' ?></div>
          <div class="hourly-track"><span class="hourly-bar hourly-color-<?= ($hourIndex%16)+1 ?>" style="height:<?= number_format($height,2,'.','') ?>%"></span></div>
          <div class="hourly-label"><?= e($label) ?></div>
        </div>
      <?php $hourIndex++; endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<section class="panel payment-summary">
  <div class="payment-summary-head"><h2>Today's Payment Summary</h2></div>
  <div class="today-payment-body">
    <div class="today-payment-overview">
      <article><small>Today's Bills</small><strong><?= e($payments['today_bills']) ?></strong></article>
      <article><small>Today's Sales</small><strong>LKR <?= number_format((float)$payments['today_sales'],2) ?></strong><span class="sales-change <?= e($salesChangeType) ?>"><?= $salesChangeType==='up'?'▲':($salesChangeType==='down'?'▼':'•') ?> <?= number_format(abs($salesChangePercent),1) ?>% <small>vs yesterday</small></span></article>
    </div>
    <div class="payment-cards">
      <article class="payment-card cash"><span class="payment-icon">Rs</span><div><small>Cash</small><strong>LKR <?= number_format((float)$payments['cash_total'],2) ?></strong><em><?= e($payments['cash_bills']) ?> bill<?= (int)$payments['cash_bills']===1?'':'s' ?></em></div></article>
      <article class="payment-card card"><span class="payment-icon">▰</span><div><small>Card</small><strong>LKR <?= number_format((float)$payments['card_total'],2) ?></strong><em><?= e($payments['card_bills']) ?> bill<?= (int)$payments['card_bills']===1?'':'s' ?></em></div></article>
      <article class="payment-card other"><span class="payment-icon">+</span><div><small>Other Payments</small><strong>LKR <?= number_format((float)$payments['other_total'],2) ?></strong><em><?= e($payments['other_bills']) ?> bill<?= (int)$payments['other_bills']===1?'':'s' ?></em></div></article>
    </div>
    <div class="payment-donut-card"><div class="payment-donut<?= $todayPaymentTotal>0?'':' empty' ?>" style="--cash-end:<?= number_format($cashDegrees,2,'.','') ?>deg;--card-end:<?= number_format($cashCardDegrees,2,'.','') ?>deg"><div><strong><?= number_format($todayPaymentTotal,0) ?></strong><small>LKR Total</small></div></div><div class="payment-donut-legend"><span><i class="cash-dot"></i>Cash <b><?= number_format($cashPercent,1) ?>%</b></span><span><i class="card-dot"></i>Card <b><?= number_format($cardPercent,1) ?>%</b></span><span><i class="other-dot"></i>Other <b><?= number_format($otherPercent,1) ?>%</b></span></div></div>
  </div>
  <?php if(!$isAdmin): ?>
  <div class="cashier-payment-extra">
    <?php if($cashierShift): ?>
      <div class="cashier-payment-status"><span class="shift-live-badge">● Live Shift #<?= e($cashierShift['id']) ?></span><span>Started <strong><?= date('h:i A',strtotime($cashierShift['shift_started_at'])) ?></strong></span></div>
      <div class="cashier-payment-extra-grid">
        <article><small>Opening Cash</small><strong>LKR <?= number_format((float)$cashierShift['opening_cash'],2) ?></strong></article>
        <article><small>Expected Cash</small><strong>LKR <?= number_format((float)$cashierShift['opening_cash']+(float)$cashierShiftStats['cash'],2) ?></strong></article>
        <article><small>Today's Discounts</small><strong>LKR <?= number_format($cashierTodayDiscount,2) ?></strong></article>
        <article class="<?= $cashierTodayVoids>0?'void-metric':'' ?>"><small>Today's Void Bills</small><strong><?= e($cashierTodayVoids) ?></strong></article>
      </div>
    <?php else: ?>
      <div class="cashier-no-shift merged"><p>No open cashier shift.</p><a class="btn primary" href="open_shift.php">Open Shift</a></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<section class="panel monthly-summary payment-summary">
  <div class="payment-summary-head"><h2>Monthly Summary</h2><span class="summary-period"><?= date('F Y') ?></span></div>
  <div class="today-payment-body monthly-payment-body">
    <div class="today-payment-overview">
      <article><small>Total Bills</small><strong><?= e($monthly['total_bills']) ?></strong></article>
      <article><small>Total Sales</small><strong>LKR <?= number_format((float)$monthly['total_sales'],2) ?></strong></article>
    </div>
    <div class="payment-cards">
      <article class="payment-card cash"><span class="payment-icon">Rs</span><div><small>Cash</small><strong>LKR <?= number_format((float)$monthly['cash_total'],2) ?></strong></div></article>
      <article class="payment-card card"><span class="payment-icon">▰</span><div><small>Card</small><strong>LKR <?= number_format((float)$monthly['credit_total'],2) ?></strong></div></article>
      <article class="payment-card other"><span class="payment-icon">+</span><div><small>Other Payments</small><strong>LKR <?= number_format((float)$monthly['other_total'],2) ?></strong></div></article>
    </div>
    <div class="payment-donut-card"><div class="payment-donut<?= $monthlyPaymentTotal>0?'':' empty' ?>" style="--cash-end:<?= number_format($monthlyCashDegrees,2,'.','') ?>deg;--card-end:<?= number_format($monthlyCashCardDegrees,2,'.','') ?>deg"><div><strong><?= number_format($monthlyPaymentTotal,0) ?></strong><small>LKR Total</small></div></div><div class="payment-donut-legend"><span><i class="cash-dot"></i>Cash <b><?= number_format($monthlyCashPercent,1) ?>%</b></span><span><i class="card-dot"></i>Card <b><?= number_format($monthlyCardPercent,1) ?>%</b></span><span><i class="other-dot"></i>Other <b><?= number_format($monthlyOtherPercent,1) ?>%</b></span></div></div>
  </div>
</section>
<?php if($isAdmin): ?>
<section class="admin-insights-grid">
  <article class="panel admin-insight-card">
    <div class="panel-head compact"><div><h2>Cashier Status</h2><small>Open shifts right now</small></div><span class="insight-count"><?= count($openCashierShifts) ?></span></div>
    <?php if($openCashierShifts): ?><div class="insight-list">
      <?php foreach($openCashierShifts as $os): ?>
        <div class="insight-row"><div><strong><?= e($os['cashier']) ?></strong><small>Opened <?= date('h:i A',strtotime($os['shift_started_at'])) ?></small></div><span class="status-open">Open</span><b>LKR <?= number_format((float)$os['current_sales'],2) ?></b></div>
      <?php endforeach; ?>
    </div><?php else: ?><div class="insight-empty">No cashier shifts are open.</div><?php endif; ?>
  </article>

  <article class="panel admin-insight-card">
    <div class="panel-head compact"><div><h2>Today's Cash Difference</h2><small>Closed cashier shifts</small></div><span class="insight-count"><?= e($todayCashAudit['closed_shifts']) ?></span></div>
    <div class="cash-audit-grid">
      <div><small>Short</small><strong class="audit-short">LKR <?= number_format((float)$todayCashAudit['short_total'],2) ?></strong></div>
      <div><small>Over</small><strong class="audit-over">LKR <?= number_format((float)$todayCashAudit['over_total'],2) ?></strong></div>
      <div><small>Net Difference</small><strong class="<?= (float)$todayCashAudit['net_difference']<0?'audit-short':((float)$todayCashAudit['net_difference']>0?'audit-over':'audit-ok') ?>">LKR <?= number_format((float)$todayCashAudit['net_difference'],2) ?></strong></div>
    </div>
  </article>

  <article class="panel admin-insight-card top-items-card" id="top-selling-items">
    <div class="panel-head compact top-selling-head"><div class="top-selling-title-wrap"><span class="top-selling-title-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20V11M12 20V4M19 20v-7"/></svg></span><div><h2>Top Selling Items</h2><small><?= e($topPeriodLabel) ?> top 5 by quantity</small></div></div><form class="top-period-form" method="get"><span class="top-period-calendar"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/></svg></span><select name="top_period" aria-label="Top selling period" onchange="sessionStorage.setItem('mistyDashboardScrollY',String(window.scrollY));this.form.submit()"><option value="today" <?= $topPeriod==='today'?'selected':'' ?>>Today</option><option value="week" <?= $topPeriod==='week'?'selected':'' ?>>This Week</option><option value="month" <?= $topPeriod==='month'?'selected':'' ?>>This Month</option></select></form></div>
    <?php if($topSellingItems): ?>
      <?php $topMaxQty=max(array_map(static fn($row)=>(float)$row['qty'],$topSellingItems)); ?>
      <div class="top-selling-chart">
        <div class="top-selling-columns"><span></span><span></span><b>Qty Sold</b><b>Sales Amount</b></div>
        <?php foreach($topSellingItems as $i=>$item): $barWidth=$topMaxQty>0?max(8,((float)$item['qty']/$topMaxQty)*100):0; ?>
          <div class="top-selling-bar-row">
            <div class="top-selling-label"><span class="item-rank"><?= $i+1 ?></span><img class="top-selling-photo" src="<?= e(base_url($item['image_path'] ?: 'assets/logo.png')) ?>" alt="<?= e($item['product_name']) ?>"><strong title="<?= e($item['product_name']) ?>"><?= e($item['product_name']) ?></strong></div>
            <div class="top-selling-track"><span class="top-selling-fill bar-color-<?= ($i%5)+1 ?>" style="width:<?= number_format($barWidth,2,'.','') ?>%"></span></div>
            <b class="top-selling-qty"><?= e($item['qty']) ?> sold</b>
            <em class="top-selling-sales">LKR <?= number_format((float)$item['sales'],2) ?></em>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?><div class="insight-empty">No sales recorded for this period.</div><?php endif; ?>
  </article>
</section>
<?php endif; ?>
<?php if($isAdmin && isset($_GET['top_period'])): ?><script>(function(){const y=sessionStorage.getItem('mistyDashboardScrollY');if(y!==null){sessionStorage.removeItem('mistyDashboardScrollY');window.addEventListener('load',function(){requestAnimationFrame(function(){window.scrollTo(0,parseInt(y,10)||0);});},{once:true});}})();</script><?php endif; ?>
<section class="panel"><div class="panel-head"><h2>Recent Sales</h2><?php if(!$isAdmin): ?><?php if($cashierHasOpenShift): ?><a class="btn primary" href="pos.php">+ New Sale</a><?php else: ?><a class="btn primary" href="open_shift.php">Open Shift</a><?php endif; ?><?php endif; ?></div><div class="table-wrap"><table><thead><tr><th>Bill</th><th>Cashier</th><th>Payment</th><th>Total</th><th>Date</th><th></th></tr></thead><tbody><?php foreach($recent as $sale): ?><tr><td><?= e($sale['bill_no']) ?></td><td><?= e($sale['cashier']) ?></td><td><span class="badge"><?= e(ucfirst($sale['payment_method'])) ?></span></td><td>LKR <?= number_format((float)$sale['total'],2) ?></td><td><?= date('d M Y, h:i A',strtotime($sale['created_at'])) ?></td><td><a href="receipt.php?id=<?= e($sale['id']) ?>">Receipt</a></td></tr><?php endforeach; ?><?php if(!$recent): ?><tr><td colspan="6" class="empty">No sales yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php include __DIR__.'/includes/footer.php'; ?>
