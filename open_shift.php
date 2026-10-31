<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
$currentUser=auth_user();
if (($currentUser['role']??'')!=='cashier') { header('Location: index.php'); exit; }
if (!empty($_SESSION['closed_shift_id'])) { header('Location: shift_summary.php'); exit; }
if (active_cashier_shift((int)$currentUser['id'])) { header('Location: pos.php'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $openingCash=filter_input(INPUT_POST,'opening_cash',FILTER_VALIDATE_FLOAT);
    if ($openingCash===false || $openingCash===null || $openingCash<0) $error='Enter a valid opening cash amount.';
    else {
        $stmt=db()->prepare('INSERT INTO cashier_shifts(user_id,shift_started_at,opening_cash) VALUES(?,NOW(),?)');
        $stmt->execute([(int)$currentUser['id'],(float)$openingCash]);
        header('Location: pos.php'); exit;
    }
}
$pageTitle='Open Shift'; $pageSubtitle='Count the starting drawer cash before the first sale';
include __DIR__.'/includes/header.php';
?>
<style>
.open-shift-form input[type="number"]::-webkit-outer-spin-button,
.open-shift-form input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.open-shift-form input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
</style>

<section class="panel close-shift-panel">
  <div class="close-shift-intro"><div><span>START OF SHIFT</span><h2>Open Cashier Shift</h2><p><?= e($currentUser['name']) ?> • <?= date('d M Y, h:i A') ?></p></div><img src="<?= e(base_url('assets/logo.png')) ?>" alt="Misty Sip"></div>
  <?php if($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
  <form class="close-shift-form open-shift-form" method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div class="open-shift-note"><small>OPENING DRAWER</small><h3>Count the cash currently in the drawer</h3><p>This amount will be added to cash sales when the expected closing cash is calculated.</p></div>
    <label>Opening Cash (LKR)
      <input type="number" name="opening_cash" min="0" step="0.01" required autofocus placeholder="Enter opening cash amount">
    </label>
    <div class="close-shift-actions"><a class="btn" href="<?= e(base_url('logout.php')) ?>">Sign Out</a><button class="btn primary" type="submit">Open Shift &amp; Start Sales</button></div>
  </form>
</section>
<?php include __DIR__.'/includes/footer.php'; ?>
