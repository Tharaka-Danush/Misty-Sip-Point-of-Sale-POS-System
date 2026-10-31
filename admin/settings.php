<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_admin();
ensure_app_settings_table();

$error='';
$success=(string)($_SESSION['settings_success']??'');
unset($_SESSION['settings_success']);

$defaults=[
  'shop_name'=>'MISTY SIP',
  'shop_phone'=>'071 787 8305',
  'receipt_footer'=>'Freshly Made • Naturally Refreshing',
  'high_discount_threshold'=>'10',
  'low_stock_level'=>'5',
];

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();

  if(($_POST['action']??'')==='change_admin_password'){
    try{
      $currentPassword=(string)($_POST['current_password']??'');
      $newPassword=(string)($_POST['new_password']??'');
      $confirmPassword=(string)($_POST['confirm_password']??'');
      $admin=auth_user();

      if($currentPassword==='' || $newPassword==='' || $confirmPassword===''){
        throw new RuntimeException('Complete all password fields.');
      }
      if(strlen($newPassword)<8){
        throw new RuntimeException('New password must be at least 8 characters.');
      }
      if($newPassword!==$confirmPassword){
        throw new RuntimeException('New password and confirmation do not match.');
      }

      $stmt=db()->prepare('SELECT password FROM users WHERE id=? AND role=\'admin\' LIMIT 1');
      $stmt->execute([(int)$admin['id']]);
      $hash=$stmt->fetchColumn();
      if(!$hash || !password_verify($currentPassword,(string)$hash)){
        throw new RuntimeException('Current password is incorrect.');
      }
      if(password_verify($newPassword,(string)$hash)){
        throw new RuntimeException('New password must be different from the current password.');
      }

      $newHash=password_hash($newPassword,PASSWORD_DEFAULT);
      $stmt=db()->prepare('UPDATE users SET password=?, password_reset_required=0, failed_login_attempts=0 WHERE id=? AND role=\'admin\'');
      $stmt->execute([$newHash,(int)$admin['id']]);

      log_activity('Admin Password Changed','system','Administrator changed their own password.');
      $_SESSION['settings_success']='Admin password changed successfully.';
      header('Location: settings.php');
      exit;
    }catch(Throwable $e){
      $error=$e->getMessage();
    }
  } elseif(($_POST['action']??'')==='save_shop_details'){
    try{
      $shopName=trim((string)($_POST['shop_name']??''));
      $shopPhone=trim((string)($_POST['shop_phone']??''));
      $receiptFooter=trim((string)($_POST['receipt_footer']??''));

      if($shopName==='' || mb_strlen($shopName)>80) throw new RuntimeException('Enter a valid shop name.');
      if(mb_strlen($shopPhone)>40) throw new RuntimeException('Phone number is too long.');
      if(mb_strlen($receiptFooter)>160) throw new RuntimeException('Receipt footer must be 160 characters or less.');

      set_app_setting('shop_name',$shopName);
      set_app_setting('shop_phone',$shopPhone);
      set_app_setting('receipt_footer',$receiptFooter);

      log_activity('Shop Settings Updated','system','Shop details and receipt settings updated.');
      $_SESSION['settings_success']='Shop details saved successfully.';
      header('Location: settings.php');
      exit;
    }catch(Throwable $e){
      $error=$e->getMessage();
    }
  } elseif(($_POST['action']??'')==='save_pos_controls'){
    try{
      $discountRaw=trim((string)($_POST['high_discount_threshold']??''));
      $lowStockRaw=trim((string)($_POST['low_stock_level']??''));

      if($discountRaw==='' || !is_numeric($discountRaw)) throw new RuntimeException('Enter a valid high-discount threshold.');
      $discount=(float)$discountRaw;
      if($discount<0 || $discount>100) throw new RuntimeException('High-discount threshold must be between 0 and 100.');
      if($lowStockRaw==='' || !ctype_digit($lowStockRaw)) throw new RuntimeException('Enter a valid low-stock level.');
      $lowStock=(int)$lowStockRaw;
      if($lowStock<0 || $lowStock>9999) throw new RuntimeException('Low-stock level must be between 0 and 9999.');

      set_app_setting('high_discount_threshold',rtrim(rtrim(number_format($discount,2,'.',''),'0'),'.'));
      set_app_setting('low_stock_level',(string)$lowStock);

      log_activity('POS Controls Updated','system',
        'High-discount approval threshold: '.number_format($discount,2).'% | Low-stock level: '.$lowStock.'.');
      $_SESSION['settings_success']='POS controls saved successfully.';
      header('Location: settings.php');
      exit;
    }catch(Throwable $e){
      $error=$e->getMessage();
    }
  }
}

$settings=[];
foreach($defaults as $key=>$default)$settings[$key]=app_setting($key,$default);

$pageTitle='Settings';
$pageSubtitle='Manage shop, receipt, discount approval and stock alert settings.';
include __DIR__.'/../includes/header.php';
?>
<style>
.settings-wrap{width:100%;max-width:none;margin:0 0 70px;padding:0;box-sizing:border-box}
.settings-alert{padding:14px 16px;border-radius:12px;margin-bottom:20px;font-weight:750}
.settings-alert.ok{background:#eaf8f1;border:1px solid #bde6d2;color:#08724e}
.settings-alert.err{background:#fff0f0;border:1px solid #f1c3c3;color:#ad3030}
.settings-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px}
.settings-card{background:#fff;border:1px solid #dce8e3;border-radius:18px;padding:22px 24px;box-shadow:0 8px 24px rgba(20,70,53,.05)}
.settings-card.wide{grid-column:1/-1}
.settings-card-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.settings-card-icon{width:48px;height:48px;border-radius:14px;display:grid;place-items:center;background:#e9f7f2;color:#087a56;flex:0 0 48px}
.settings-card-icon svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.settings-card h2{margin:0 0 5px;font-size:20px;color:#153c2e}
.settings-card p{margin:0;color:#708078;font-size:13px;line-height:1.55}
.setting-field{display:block;margin:14px 0;color:#3f554c;font-size:12px;font-weight:800}
.setting-field input,.setting-field textarea{display:block;width:100%;box-sizing:border-box;margin-top:7px;border:1px solid #cfddd7;border-radius:11px;background:#fff;padding:11px 12px;font:inherit;color:#1e3b30;outline:none}
.setting-field input{height:46px}.setting-field textarea{min-height:98px;resize:vertical}
.setting-field input:focus,.setting-field textarea:focus{border-color:#0c8b62;box-shadow:0 0 0 3px rgba(12,139,98,.09)}
.setting-help{display:block;margin-top:5px;color:#83928b;font-size:11px;font-weight:500}
.settings-inline{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px}
.settings-save{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:20px;padding:18px 22px;background:#fff;border:1px solid #e0e9e5;border-radius:16px;box-shadow:0 6px 18px rgba(20,70,53,.04)}
.settings-save p{margin:0;color:#5c7067;font-size:12px;line-height:1.5}
.settings-save button{border:0;border-radius:11px;background:#07865c;color:#fff;min-height:46px;padding:0 24px;font-weight:900;cursor:pointer;white-space:nowrap}
@media(max-width:850px){.settings-grid,.settings-inline{grid-template-columns:1fr}.settings-card.wide{grid-column:auto}.settings-save{align-items:stretch;flex-direction:column}}




.password-action-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:10px;padding-top:14px;border-top:1px solid #e4ece8;color:#6a7b73;font-size:12px}
.password-action-row button{border:0;border-radius:11px;background:#0b7a57;color:#fff;min-height:44px;padding:0 18px;font-weight:900;cursor:pointer;white-space:nowrap}
.password-action-row button:hover{filter:brightness(.96)}
@media(max-width:700px){.password-action-row{align-items:stretch;flex-direction:column}.password-action-row button{width:100%}}


.settings-grid-single{margin-top:20px}
.settings-section-save{display:flex;justify-content:flex-end;margin:12px 0 20px}
.settings-section-save button{border:0;border-radius:11px;background:#07865c;color:#fff;min-height:44px;padding:0 20px;font-weight:900;cursor:pointer;white-space:nowrap}
.settings-section-save button:hover{filter:brightness(.96)}

</style>

<section class="settings-wrap">
  <?php if($success):?><div class="settings-alert ok"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="settings-alert err"><?=e($error)?></div><?php endif;?>

  <form method="post" id="adminPasswordForm">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="change_admin_password">
  </form>

  <form method="post" id="shopDetailsForm">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="save_shop_details">
    <div class="settings-grid">
      <article class="settings-card">
        <div class="settings-card-head">
          <span class="settings-card-icon"><svg viewBox="0 0 24 24"><path d="M4 10h16v10H4z"/><path d="M3 10l2-6h14l2 6M8 14h8"/></svg></span>
          <div><h2>Shop Details</h2><p>Used on the customer receipt and system branding.</p></div>
        </div>
        <label class="setting-field">Shop Name
          <input type="text" name="shop_name" maxlength="80" value="<?=e($settings['shop_name'])?>" required>
        </label>
        <label class="setting-field">Phone Number
          <input type="text" name="shop_phone" maxlength="40" value="<?=e($settings['shop_phone'])?>" placeholder="Optional">
        </label>
      </article>

      <article class="settings-card">
        <div class="settings-card-head">
          <span class="settings-card-icon"><svg viewBox="0 0 24 24"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/></svg></span>
          <div><h2>Receipt</h2><p>Customize the short message printed on customer receipts.</p></div>
        </div>
        <label class="setting-field">Receipt Footer
          <textarea name="receipt_footer" maxlength="160" placeholder="Thank you for visiting!"><?=e($settings['receipt_footer'])?></textarea>
          <span class="setting-help">Maximum 160 characters.</span>
        </label>
      </article>

    </div>
    <div class="settings-section-save">
      <button type="submit">Save Shop Details</button>
    </div>
  </form>

  <form method="post" id="posControlsForm">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="action" value="save_pos_controls">
    <div class="settings-grid settings-grid-single">
      <article class="settings-card wide">
        <div class="settings-card-head">
          <span class="settings-card-icon"><svg viewBox="0 0 24 24"><path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6z"/><path d="M8 12h8"/></svg></span>
          <div><h2>POS Controls</h2><p>Control sensitive discounts and the general POS low-stock warning level.</p></div>
        </div>
        <div class="settings-inline">
          <label class="setting-field">High Discount Approval Threshold (%)
            <input type="number" name="high_discount_threshold" min="0" max="100" step="0.01" value="<?=e($settings['high_discount_threshold'])?>" required>
            <span class="setting-help">Example: 10 means a manual discount above 10% needs Admin approval.</span>
          </label>
          <label class="setting-field">POS Low Stock Level
            <input type="number" name="low_stock_level" min="0" max="9999" step="1" value="<?=e($settings['low_stock_level'])?>" required>
            <span class="setting-help">Used for product low-stock warning badges and dashboard product alerts.</span>
          </label>
        </div>
      </article>

    </div>
    <div class="settings-section-save">
      <button type="submit">Save POS Controls</button>
    </div>
  </form>

  <div class="settings-grid settings-grid-single">
      <article class="settings-card wide">
        <div class="settings-card-head">
          <span class="settings-card-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>
          <div><h2>Change Admin Password</h2><p>Update the password for your currently signed-in administrator account.</p></div>
        </div>
        <div class="settings-inline password-grid">
          <label class="setting-field">Current Password
            <input type="password" name="current_password" form="adminPasswordForm" autocomplete="current-password">
          </label>
          <label class="setting-field">New Password
            <input type="password" name="new_password" form="adminPasswordForm" minlength="8" autocomplete="new-password">
            <span class="setting-help">Minimum 8 characters.</span>
          </label>
        </div>
        <label class="setting-field">Confirm New Password
          <input type="password" name="confirm_password" form="adminPasswordForm" minlength="8" autocomplete="new-password">
        </label>
        <div class="password-action-row">
          <span>Changing the password does not close the current admin session.</span>
          <button type="submit" form="adminPasswordForm">Change Password</button>
        </div>
      </article>

    </div>

</section>
<?php require __DIR__.'/../includes/footer.php'; ?>
