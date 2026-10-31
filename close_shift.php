<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
$currentUser=auth_user();
if (($currentUser['role']??'')!=='cashier') { header('Location: index.php'); exit; }
$shift=active_cashier_shift((int)$currentUser['id']);
if (!$shift) { header('Location: open_shift.php'); exit; }
$shiftStartedAt=$shift['shift_started_at'];
function shift_totals(int $shiftId,int $userId,string $startedAt): array {
    $stmt=db()->prepare("SELECT COUNT(*) total_bills,COALESCE(SUM(total),0) total_sales,COALESCE(SUM(CASE WHEN payment_method='cash' THEN total ELSE 0 END),0) cash_total,COALESCE(SUM(CASE WHEN payment_method='card' THEN total ELSE 0 END),0) card_total,COALESCE(SUM(CASE WHEN payment_method='other' THEN total ELSE 0 END),0) other_total FROM sales WHERE is_void=0 AND (shift_id=? OR (shift_id IS NULL AND user_id=? AND created_at>=?))");
    $stmt->execute([$shiftId,$userId,$startedAt]); return $stmt->fetch();
}
$totals=shift_totals((int)$shift['id'],(int)$currentUser['id'],$shiftStartedAt); $openingCash=(float)$shift['opening_cash']; $expectedCash=$openingCash+(float)$totals['cash_total']; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf(); $actualCash=filter_input(INPUT_POST,'actual_cash',FILTER_VALIDATE_FLOAT); $note=trim((string)($_POST['note']??''));
    if ($actualCash===false || $actualCash===null || $actualCash<0) $error='Enter a valid actual cash amount.';
    else {
        $totals=shift_totals((int)$shift['id'],(int)$currentUser['id'],$shiftStartedAt); $expectedCash=$openingCash+(float)$totals['cash_total']; $difference=(float)$actualCash-$expectedCash;
        if (abs($difference) > 0.009 && $note === '') { $error='A note is required when there is a cash difference.'; }
        else {
        $stmt=db()->prepare('UPDATE cashier_shifts SET closed_at=NOW(),total_bills=?,total_sales=?,cash_total=?,card_total=?,other_total=?,expected_cash=?,actual_cash=?,cash_difference=?,note=? WHERE id=? AND closed_at IS NULL');
        $stmt->execute([(int)$totals['total_bills'],(float)$totals['total_sales'],(float)$totals['cash_total'],(float)$totals['card_total'],(float)$totals['other_total'],$expectedCash,(float)$actualCash,$difference,$note!==''?substr($note,0,500):null,(int)$shift['id']]);
        log_activity('Shift Closed','system','Bills: '.(int)$totals['total_bills'].' • Sales: LKR '.number_format((float)$totals['total_sales'],2).' • Difference: LKR '.number_format($difference,2),(int)$shift['id']);
        $_SESSION['closed_shift_id']=(int)$shift['id'];
        header('Location: shift_summary.php'); exit;
        }
    }
}
$pageTitle='Close Shift'; $pageSubtitle='Count the drawer, reconcile cash and finish the cashier shift'; include __DIR__.'/includes/header.php';
?>
<section class="panel close-shift-panel">
  <div class="close-shift-intro"><div><span>DAY-END PROCESS</span><h2>Cashier Shift Summary</h2><p><?= e($currentUser['name']) ?> • Started <?= date('d M Y, h:i A',strtotime($shiftStartedAt)) ?></p></div><img src="<?= e(base_url('assets/logo.png')) ?>" alt="Misty Sip"></div>
  <?php if($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
  <div class="shift-total-grid">
    <article><small>Total Bills</small><strong><?= e($totals['total_bills']) ?></strong></article><article><small>Total Sales</small><strong>LKR <?= number_format((float)$totals['total_sales'],2) ?></strong></article><article class="cash"><small>Cash Sales</small><strong>LKR <?= number_format((float)$totals['cash_total'],2) ?></strong></article><article class="card"><small>Card Sales</small><strong>LKR <?= number_format((float)$totals['card_total'],2) ?></strong></article><article class="other"><small>Other Payments</small><strong>LKR <?= number_format((float)$totals['other_total'],2) ?></strong></article>
  </div>
  <form class="close-shift-form" id="closeShiftForm" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="expected-cash"><small>Opening Cash</small><strong>LKR <?= number_format($openingCash,2) ?></strong></div>
    <div class="expected-cash"><small>Expected Cash</small><strong>LKR <?= number_format($expectedCash,2) ?></strong></div>
    <label>Actual Cash<input id="actualCash" class="no-number-spin" type="number" name="actual_cash" min="0" step="0.01" required placeholder="Enter counted cash"></label>
    <div class="cash-difference"><small>Cash Difference</small><strong id="cashDifference">LKR 0.00</strong></div>
    <label class="shift-note" id="shiftNote" style="display:none">Note <span style="color:#b42318">*</span><textarea id="shiftNoteText" name="note" maxlength="500" rows="3" placeholder="Reason for cash shortage / excess"></textarea></label>
    <div class="close-shift-actions"><a class="btn" href="pos.php">Back to New Sale</a><button class="btn close-shift-submit" type="submit">Confirm &amp; Close Shift</button></div>
  </form>
</section>
<div class="shift-confirm-modal" id="shiftConfirmModal" aria-hidden="true">
  <div class="shift-confirm-backdrop" data-close-modal></div>
  <div class="shift-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="shiftConfirmTitle">
    <div class="shift-confirm-icon">!</div>
    <h3 id="shiftConfirmTitle">Close Cashier Shift?</h3>
    <p>Once you close this shift, the shift totals will be finalized and cannot be modified.</p>
    <div class="shift-confirm-actions">
      <button class="btn shift-confirm-cancel" type="button" data-close-modal>Cancel</button>
      <button class="btn close-shift-submit" id="confirmCloseShift" type="button">Confirm &amp; Close Shift</button>
    </div>
  </div>
</div>
<style>.no-number-spin::-webkit-inner-spin-button,.no-number-spin::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}.no-number-spin{-moz-appearance:textfield;appearance:textfield}</style>
<script>(function(){const input=document.getElementById('actualCash'),output=document.getElementById('cashDifference'),noteWrap=document.getElementById('shiftNote'),note=document.getElementById('shiftNoteText'),form=document.getElementById('closeShiftForm'),modal=document.getElementById('shiftConfirmModal'),confirmBtn=document.getElementById('confirmCloseShift'),expected=<?= json_encode($expectedCash) ?>;function update(){const actual=parseFloat(input.value),difference=Number.isFinite(actual)?actual-expected:0,hasDifference=Number.isFinite(actual)&&Math.abs(difference)>0.009;output.textContent='LKR '+difference.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});output.classList.toggle('negative',difference<0);output.classList.toggle('positive',difference>0);noteWrap.style.display=hasDifference?'block':'none';note.required=hasDifference;if(!hasDifference)note.value=''}function openModal(){modal.classList.add('show');modal.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');confirmBtn.focus()}function closeModal(){modal.classList.remove('show');modal.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open')}input.addEventListener('input',update);form.addEventListener('submit',function(e){if(!form.checkValidity())return;e.preventDefault();openModal()});confirmBtn.addEventListener('click',function(){confirmBtn.disabled=true;confirmBtn.textContent='Closing Shift...';form.submit()});modal.querySelectorAll('[data-close-modal]').forEach(el=>el.addEventListener('click',closeModal));document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('show'))closeModal()});update()})();</script>
<?php include __DIR__.'/includes/footer.php'; ?>
