<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
$currentUser=auth_user();
if (($currentUser['role']??'')!=='cashier') { header('Location: index.php'); exit; }
$shiftId=(int)($_SESSION['closed_shift_id']??0);
if ($shiftId<1) { header('Location: '.(active_cashier_shift((int)$currentUser['id'])?'pos.php':'open_shift.php')); exit; }
$stmt=db()->prepare('SELECT * FROM cashier_shifts WHERE id=? AND user_id=? AND closed_at IS NOT NULL LIMIT 1');
$stmt->execute([$shiftId,(int)$currentUser['id']]);
$shift=$stmt->fetch();
if (!$shift) { unset($_SESSION['closed_shift_id']); header('Location: open_shift.php'); exit; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    unset($_SESSION['closed_shift_id']);
    $_SESSION=[];
    if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
    session_destroy();
    header('Location: login.php'); exit;
}
$pageTitle='Shift Summary'; $pageSubtitle='Shift closed successfully'; include __DIR__.'/includes/header.php';
$difference=(float)$shift['cash_difference'];
?>
<section class="panel shift-summary-panel">
  <div class="shift-success-head"><div class="shift-success-icon">✓</div><div><span>SHIFT CLOSED SUCCESSFULLY</span><h2>Closing Summary</h2><p><?= e($currentUser['name']) ?> • Shift #<?= e($shift['id']) ?></p></div><img src="<?= e(base_url('assets/logo.png')) ?>" alt="Misty Sip"></div>
  <div class="shift-time-row"><div><small>Opened</small><strong><?= date('d M Y, h:i A',strtotime($shift['shift_started_at'])) ?></strong></div><div><small>Closed</small><strong><?= date('d M Y, h:i A',strtotime($shift['closed_at'])) ?></strong></div><div><small>Total Bills</small><strong><?= e($shift['total_bills']) ?></strong></div></div>
  <div class="summary-money-grid">
    <div><small>Opening Cash</small><strong>LKR <?= number_format((float)$shift['opening_cash'],2) ?></strong></div>
    <div><small>Cash Sales</small><strong>LKR <?= number_format((float)$shift['cash_total'],2) ?></strong></div>
    <div><small>Card Sales</small><strong>LKR <?= number_format((float)$shift['card_total'],2) ?></strong></div>
    <div><small>Other Payments</small><strong>LKR <?= number_format((float)$shift['other_total'],2) ?></strong></div>
    <div class="summary-total"><small>Total Sales</small><strong>LKR <?= number_format((float)$shift['total_sales'],2) ?></strong></div>
    <div><small>Expected Cash</small><strong>LKR <?= number_format((float)$shift['expected_cash'],2) ?></strong></div>
    <div><small>Actual Cash</small><strong>LKR <?= number_format((float)$shift['actual_cash'],2) ?></strong></div>
    <div class="summary-difference <?= $difference<0?'negative':($difference>0?'positive':'') ?>"><small>Cash Difference</small><strong><?= $difference<0?'- ':($difference>0?'+ ':'') ?>LKR <?= number_format(abs($difference),2) ?></strong></div>
  </div>
  <?php if(abs($difference)>0.009 && trim((string)$shift['note'])!==''): ?><div class="summary-note"><small>Difference Note</small><p><?= nl2br(e($shift['note'])) ?></p></div><?php endif; ?>
  <div class="shift-summary-actions">
    <form id="finishShiftForm" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <button class="btn primary" id="donePrintLogout" type="button">Print &amp; Logout</button>
    </form>
  </div>
</section>
<style>
.shift-summary-panel{max-width:920px;margin:0 auto 24px;overflow:hidden;padding:0}.shift-success-head{display:flex;align-items:center;gap:16px;padding:24px 28px;background:linear-gradient(125deg,#063d2a,#0f7a50);color:#fff}.shift-success-head>div:nth-child(2){flex:1}.shift-success-icon{display:grid;place-items:center;width:52px;height:52px;border-radius:50%;background:#fff;color:#0f7a50;font-size:28px;font-weight:900}.shift-success-head span{font-size:10px;font-weight:900;letter-spacing:1.6px;color:#c8f5dc}.shift-success-head h2{margin:4px 0;color:#fff}.shift-success-head p{margin:0;color:#d9f3e6;font-size:12px}.shift-success-head img{width:68px;height:68px;object-fit:contain;background:#fff;border-radius:16px;padding:5px}.shift-time-row,.summary-money-grid{display:grid;gap:12px;padding:18px 22px}.shift-time-row{grid-template-columns:repeat(3,1fr);border-bottom:1px solid #e4eee9}.shift-time-row div,.summary-money-grid div{padding:14px;border:1px solid #dce8e2;border-radius:13px;background:#f9fcfa}.shift-time-row small,.summary-money-grid small,.summary-note small{display:block;color:#60736b;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px}.shift-time-row strong,.summary-money-grid strong{color:#173c2f}.summary-money-grid{grid-template-columns:repeat(4,1fr);padding-top:4px}.summary-money-grid .summary-total{background:#eef8f2;border-color:#bfe0cc}.summary-difference.negative strong{color:#b42318}.summary-difference.positive strong{color:#0f7a50}.summary-note{margin:0 22px 18px;padding:15px 16px;border-left:4px solid #e49a24;background:#fff9ed;border-radius:10px}.summary-note p{margin:4px 0 0;color:#59451f}.shift-summary-actions{display:flex;justify-content:flex-end;gap:10px;padding:0 22px 24px}.shift-summary-actions form{margin:0}@media(max-width:760px){.shift-time-row,.summary-money-grid{grid-template-columns:1fr 1fr}}@media(max-width:500px){.shift-time-row,.summary-money-grid{grid-template-columns:1fr}.shift-summary-actions{flex-direction:column}.shift-summary-actions .btn{width:100%}}
@media print{
  @page{size:80mm auto;margin:0}
  html,body{width:80mm!important;min-height:0!important;height:auto!important;margin:0!important;padding:0!important;background:#fff!important;color:#000!important;overflow:visible!important}
  body>*{visibility:visible!important}
  .sidebar,.topbar,header,.site-header,.app-header,footer,.site-footer,.shift-summary-actions{display:none!important}
  .main{display:block!important;visibility:visible!important;width:80mm!important;min-height:0!important;margin:0!important;padding:0!important}
  .main>*{display:none!important}
  .main>.shift-summary-panel{display:block!important;visibility:visible!important}
  .shift-summary-panel,.shift-summary-panel *{visibility:visible!important}
  .shift-summary-panel{position:relative!important;left:0!important;width:72mm!important;max-width:72mm!important;margin:0!important;padding:7mm 4mm 6mm!important;border:0!important;border-radius:0!important;box-shadow:none!important;overflow:visible!important;background:#fff!important;font:11px Arial,sans-serif!important;color:#000!important}
  .shift-success-head{display:flex!important;flex-direction:column!important;align-items:center!important;gap:1.5mm!important;padding:0 0 3mm!important;border-bottom:1px dashed #000!important;background:#fff!important;color:#000!important;text-align:center!important;-webkit-print-color-adjust:economy!important;print-color-adjust:economy!important}
  .shift-success-icon{display:none!important}
  .shift-success-head img{order:-1!important;width:24mm!important;height:21mm!important;margin:0 auto!important;padding:0!important;border-radius:0!important;background:transparent!important;object-fit:contain!important}
  .shift-success-head>div:nth-child(2){flex:none!important}
  .shift-success-head span,.shift-success-head h2,.shift-success-head p{color:#000!important}
  .shift-success-head span{font-size:8px!important;letter-spacing:1px!important}
  .shift-success-head h2{margin:1mm 0!important;font-size:21px!important;line-height:1!important}
  .shift-success-head p{margin:0!important;font-size:10px!important}
  .shift-time-row,.summary-money-grid{display:grid!important;grid-template-columns:1fr!important;gap:0!important;padding:2.5mm 0!important;border-bottom:1px dashed #000!important}
  .shift-time-row div,.summary-money-grid div{display:grid!important;grid-template-columns:1fr auto!important;align-items:baseline!important;gap:3mm!important;padding:1.2mm 0!important;border:0!important;border-radius:0!important;background:#fff!important}
  .shift-time-row small,.summary-money-grid small,.summary-note small{margin:0!important;color:#000!important;font-size:10px!important;font-weight:400!important;letter-spacing:0!important;text-transform:uppercase!important}
  .shift-time-row strong,.summary-money-grid strong{color:#000!important;font-size:11px!important;text-align:right!important;white-space:nowrap!important}
  .summary-money-grid .summary-total{margin-top:1mm!important;padding-top:2mm!important;border-top:1px solid #000!important;background:#fff!important}
  .summary-money-grid .summary-total small,.summary-money-grid .summary-total strong{font-size:14px!important;font-weight:800!important}
  .summary-difference.negative strong,.summary-difference.positive strong{color:#000!important}
  .summary-note{margin:2.5mm 0 0!important;padding:2mm 0!important;border:0!important;border-bottom:1px dashed #000!important;border-radius:0!important;background:#fff!important}
  .summary-note p{margin:1mm 0 0!important;color:#000!important;font-size:10px!important}
}
</style>
<script>
(function(){
  history.pushState(null,'',location.href);
  window.addEventListener('popstate',function(){history.pushState(null,'',location.href)});

  const finishForm=document.getElementById('finishShiftForm');
  const finishButton=document.getElementById('donePrintLogout');
  let waitingForPrint=false;
  let submitted=false;

  function logoutAfterPrint(){
    if(!waitingForPrint || submitted) return;
    submitted=true;
    finishButton.disabled=true;
    finishButton.textContent='Logging out...';
    finishForm.submit();
  }

  finishButton.addEventListener('click',function(){
    if(waitingForPrint) return;
    waitingForPrint=true;
    finishButton.disabled=true;
    finishButton.textContent='Printing...';
    window.print();
  });

  window.addEventListener('afterprint',logoutAfterPrint);

  // Fallback for browsers that expose print state through matchMedia.
  if(window.matchMedia){
    const media=window.matchMedia('print');
    const onPrintChange=function(e){ if(!e.matches) logoutAfterPrint(); };
    if(media.addEventListener) media.addEventListener('change',onPrintChange);
    else if(media.addListener) media.addListener(onPrintChange);
  }
})();
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
