<?php
require_once __DIR__.'/includes/bootstrap.php';
require_login();
$currentUser=auth_user();
if (($currentUser['role']??'')!=='cashier') { header('Location: index.php'); exit; }

$error='';
$success='';
$unlockError='';

// Read-only cashier profile data. No database column is added for an employee ID;
// a stable Cashier ID is displayed using the existing users.id value.
$profileStmt=db()->prepare('SELECT id,name,username,employee_id,email,mobile_number,role,created_at FROM users WHERE id=? AND is_active=1 LIMIT 1');
$profileStmt->execute([(int)$currentUser['id']]);
$profile=$profileStmt->fetch() ?: $currentUser;

// Cashier profile photo is stored as a file, so no database column is required.
$profilePhotoDir=__DIR__.'/assets/uploads/cashier_profiles';
$profilePhotoWeb='assets/uploads/cashier_profiles';
$profilePhotoPrefix='cashier_'.(int)$currentUser['id'];
$profilePhotoPath='';
$profilePhotoUrl='';
$photoError='';
$photoSuccess='';
$profileError='';
$profileSuccess='';

$findProfilePhoto=function() use ($profilePhotoDir,$profilePhotoPrefix,$profilePhotoWeb){
    foreach (['jpg','jpeg','png','webp'] as $ext) {
        $path=$profilePhotoDir.'/'.$profilePhotoPrefix.'.'.$ext;
        if (is_file($path)) {
            return [$path,base_url($profilePhotoWeb.'/'.$profilePhotoPrefix.'.'.$ext).'?v='.filemtime($path)];
        }
    }
    return ['',''];
};
[$profilePhotoPath,$profilePhotoUrl]=$findProfilePhoto();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'change_password');

    if ($action==='update_profile_details') {
        $email=trim((string)($_POST['email']??''));
        $mobile=trim((string)($_POST['mobile_number']??''));

        if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) {
            $profileError='Please enter a valid email address.';
        } elseif ($mobile!=='' && !preg_match('/^[0-9+()\-\s]{7,20}$/',$mobile)) {
            $profileError='Please enter a valid mobile number.';
        } else {
            try {
                if ($email!=='') {
                    $dup=db()->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
                    $dup->execute([$email,(int)$currentUser['id']]);
                    if ($dup->fetchColumn()) throw new RuntimeException('That email address is already in use.');
                }
                db()->prepare('UPDATE users SET email=?,mobile_number=? WHERE id=?')
                    ->execute([$email!==''?$email:null,$mobile!==''?$mobile:null,(int)$currentUser['id']]);
                header('Location: cashier_settings.php?profile=updated');
                exit;
            } catch (Throwable $e) {
                $profileError=$e->getMessage();
            }
        }
    }

    if ($action==='update_profile_photo') {
        if (!isset($_FILES['profile_photo']) || !is_array($_FILES['profile_photo'])) {
            $photoError='Please choose a profile picture.';
        } elseif ((int)($_FILES['profile_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
            $photoError='Unable to upload the selected image.';
        } elseif ((int)($_FILES['profile_photo']['size']??0)>2*1024*1024) {
            $photoError='Profile picture must be 2 MB or smaller.';
        } else {
            $tmp=(string)($_FILES['profile_photo']['tmp_name']??'');
            $finfo=new finfo(FILEINFO_MIME_TYPE);
            $mime=$tmp!==''?$finfo->file($tmp):'';
            $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) {
                $photoError='Please use a JPG, PNG, or WEBP image.';
            } else {
                if (!is_dir($profilePhotoDir) && !mkdir($profilePhotoDir,0775,true) && !is_dir($profilePhotoDir)) {
                    $photoError='Unable to create the profile picture folder.';
                } else {
                    foreach (['jpg','jpeg','png','webp'] as $oldExt) {
                        $old=$profilePhotoDir.'/'.$profilePhotoPrefix.'.'.$oldExt;
                        if (is_file($old)) @unlink($old);
                    }
                    $dest=$profilePhotoDir.'/'.$profilePhotoPrefix.'.'.$allowed[$mime];
                    if (!move_uploaded_file($tmp,$dest)) {
                        $photoError='Unable to save the profile picture.';
                    } else {
                        header('Location: cashier_settings.php?photo=updated');
                        exit;
                    }
                }
            }
        }
        [$profilePhotoPath,$profilePhotoUrl]=$findProfilePhoto();
    }

    if ($action==='remove_profile_photo') {
        foreach (['jpg','jpeg','png','webp'] as $oldExt) {
            $old=$profilePhotoDir.'/'.$profilePhotoPrefix.'.'.$oldExt;
            if (is_file($old)) @unlink($old);
        }
        header('Location: cashier_settings.php?photo=removed');
        exit;
    }

    if ($action==='lock_pos') {
        log_activity('POS Locked','system','Cashier manually locked the POS.');
        $_SESSION['pos_locked']=true;
        $_SESSION['pos_locked_at']=date('Y-m-d H:i:s');
        $_SESSION['last_activity_at']=time();
        session_regenerate_id(true);
        header('Location: cashier_settings.php?locked=1');
        exit;
    }

    if ($action==='unlock_pos') {
        $unlockPassword=(string)($_POST['unlock_password']??'');
        $stmt=db()->prepare('SELECT password FROM users WHERE id=? AND is_active=1 LIMIT 1');
        $stmt->execute([(int)$currentUser['id']]);
        $storedPassword=(string)($stmt->fetchColumn()?:'');
        if ($unlockPassword==='' || !password_verify($unlockPassword,$storedPassword)) {
            $unlockError='Incorrect password. Please try again.';
        } else {
            unset($_SESSION['pos_locked'],$_SESSION['pos_locked_at']);
            $_SESSION['last_activity_at']=time();
            log_activity('POS Unlocked','system','Cashier unlocked the POS with their password.');
            session_regenerate_id(true);
            header('Location: '.base_url(active_cashier_shift((int)$currentUser['id'])?'pos.php':'index.php'));
            exit;
        }
    }

    if ($action==='change_password') {
        $currentPassword=(string)($_POST['current_password']??'');
        $newPassword=(string)($_POST['new_password']??'');
        $confirmPassword=(string)($_POST['confirm_password']??'');
        $stmt=db()->prepare('SELECT password FROM users WHERE id=? AND is_active=1 LIMIT 1');
        $stmt->execute([(int)$currentUser['id']]);
        $storedPassword=(string)($stmt->fetchColumn()?:'');

        if (!password_verify($currentPassword,$storedPassword)) $error='Current password is incorrect.';
        elseif (strlen($newPassword)<8) $error='New password must contain at least 8 characters.';
        elseif ($newPassword!==$confirmPassword) $error='New password and confirmation do not match.';
        elseif (password_verify($newPassword,$storedPassword)) $error='New password must be different from the current password.';
        else {
            db()->prepare('UPDATE users SET password=?,password_reset_required=0,failed_login_attempts=0,is_locked=0 WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),(int)$currentUser['id']]);
            session_regenerate_id(true);
            $success='Your password was changed successfully.';
        }
    }
}

$isPosLocked=!empty($_SESSION['pos_locked']);
if (($_GET['photo']??'')==='updated') $photoSuccess='Profile picture updated successfully.';
if (($_GET['photo']??'')==='removed') $photoSuccess='Profile picture removed.';
if (($_GET['profile']??'')==='updated') $profileSuccess='Profile details updated successfully.';
$pageTitle='Settings';
$pageSubtitle='Secure your Misty Sip cashier account';
include __DIR__.'/includes/header.php';
?>
<style>
.cashier-settings-wrap{width:100%;max-width:none;margin:0;display:grid;gap:18px}.profile-photo-row{display:flex;align-items:center;gap:18px;padding:22px 24px 0}.profile-photo-avatar{width:92px;height:92px;flex:0 0 92px;border-radius:50%;overflow:hidden;border:4px solid #e4f3ec;background:linear-gradient(135deg,#e9f8f1,#d7efe4);display:grid;place-items:center;color:#0a8056;font-size:34px;font-weight:900}.profile-photo-avatar img{width:100%;height:100%;object-fit:cover}.profile-photo-controls{display:flex;flex-wrap:wrap;align-items:center;gap:10px}.profile-photo-controls form{margin:0}.profile-photo-upload{position:relative;overflow:hidden}.profile-photo-upload input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer}.profile-photo-help{width:100%;margin:2px 0 0;color:#71827a;font-size:11px}.profile-photo-message{margin:14px 24px 0;padding:11px 13px;border-radius:10px;font-size:12px}.profile-photo-message.success{background:#daf4e6;border:1px solid #9ed8b8;color:#086642}.profile-photo-message.error{background:#fff0f1;border:1px solid #ffd4d8;color:#a73440}.settings-card{overflow:hidden;padding:0}.settings-head{display:flex;align-items:center;gap:16px;padding:22px 24px;background:linear-gradient(125deg,#075b3b,#12a36b);color:#fff}.settings-icon{display:grid;place-items:center;width:54px;height:54px;flex:0 0 54px;border-radius:15px;background:rgba(255,255,255,.16);font-size:24px}.settings-head h2{margin:0 0 5px;color:#fff}.settings-head p{margin:0;color:#dcf8ea;font-size:12px}.profile-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;padding:22px 24px}.profile-item{border:1px solid #dfece6;background:#f8fbfa;border-radius:13px;padding:14px 15px}.profile-item small{display:block;color:#71827a;font-size:10px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;margin-bottom:5px}.profile-item strong{display:block;color:#17362c;font-size:15px;word-break:break-word}.profile-note{margin:0;padding:0 24px 20px;color:#7b8b84;font-size:11px}.password-settings-form{display:grid;gap:15px;padding:24px}.password-settings-form label{gap:7px}.password-settings-form input{min-height:45px}.password-hint{margin:-5px 0 0;color:#71827a;font-size:11px}.password-settings-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:3px}.password-settings-actions .btn{min-width:150px;text-align:center}.settings-success{background:#daf4e6;border:1px solid #9ed8b8;color:#086642;padding:12px 16px;border-radius:10px}.password-toggle-wrap{position:relative}.password-toggle-wrap input{padding-right:72px}.password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:#edf7f2;color:#08754c;padding:6px 9px;border-radius:7px;font-size:10px;font-weight:850;cursor:pointer}.pos-lock-overlay{position:fixed;inset:0;z-index:200000;display:flex;align-items:center;justify-content:center;padding:22px;background:linear-gradient(135deg,rgba(4,55,36,.96),rgba(7,91,59,.94));backdrop-filter:blur(10px)}.pos-lock-card{width:min(430px,100%);background:#fff;border-radius:24px;box-shadow:0 30px 90px rgba(0,0,0,.35);padding:30px;text-align:center}.pos-lock-logo{width:82px;height:82px;object-fit:contain;margin-bottom:10px}.pos-lock-badge{width:58px;height:58px;margin:2px auto 14px;border-radius:50%;display:grid;place-items:center;background:#e8f5ef;color:#075b3b;font-size:28px}.pos-lock-card h2{margin:0 0 6px;color:#132d24;font-size:27px}.pos-lock-card>p{margin:0 0 20px;color:#71827a;font-size:13px}.locked-user{background:#f6faf8;border:1px solid #e0ebe6;border-radius:13px;padding:11px 14px;margin-bottom:16px;color:#24483b;font-weight:800}.unlock-form{display:grid;gap:12px;text-align:left}.unlock-form label{font-size:12px;font-weight:800;color:#38564b}.unlock-form input{width:100%;min-height:48px;margin-top:7px;border:1px solid #d9e5e0;border-radius:12px;padding:0 14px;font-size:15px}.unlock-form button{min-height:48px;border:0;border-radius:12px;background:linear-gradient(135deg,#0a8056,#075b3b);color:#fff;font-weight:850;font-size:14px;cursor:pointer}.unlock-error{background:#fff0f1;border:1px solid #ffd4d8;color:#a73440;border-radius:10px;padding:10px 12px;font-size:12px}.lock-time{display:block;margin-top:14px;color:#8da097;font-size:10px}@media(max-width:700px){.profile-grid{grid-template-columns:1fr}}@media(max-width:600px){.settings-head{padding:18px}.password-settings-form{padding:18px}.password-settings-actions{flex-direction:column-reverse}.password-settings-actions .btn{width:100%}}

.profile-details-form{padding:22px 24px 24px}.profile-details-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.profile-field{display:grid;gap:7px;color:#365249;font-size:12px;font-weight:800}.profile-field input{width:100%;min-height:44px;border:1px solid #d9e7e1;border-radius:11px;background:#fff;padding:0 12px;font:inherit;font-weight:600;color:#17362c;box-sizing:border-box;outline:none}.profile-field input:focus{border-color:#0a8a5e;box-shadow:0 0 0 3px rgba(10,138,94,.10)}.profile-readonly{border:1px solid #dfece6;background:#f8fbfa;border-radius:13px;padding:14px 15px}.profile-readonly small{display:block;color:#71827a;font-size:10px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;margin-bottom:5px}.profile-readonly strong{display:block;color:#17362c;font-size:15px;word-break:break-word}.profile-save-row{display:flex;justify-content:flex-end;margin-top:16px}.profile-save-row .btn{min-width:150px}.profile-details-message{margin:14px 24px 0;padding:11px 13px;border-radius:10px;font-size:12px}.profile-details-message.success{background:#daf4e6;border:1px solid #9ed8b8;color:#086642}.profile-details-message.error{background:#fff0f1;border:1px solid #ffd4d8;color:#a73440}@media(max-width:850px){.profile-details-grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.profile-details-grid{grid-template-columns:1fr}.profile-save-row .btn{width:100%}}
</style>

<section class="cashier-settings-wrap">
  <article class="panel settings-card">
    <div class="settings-head"><span class="settings-icon" aria-hidden="true">👤</span><div><h2>Cashier Profile</h2><p>View your account and update your contact details. Employee ID is managed by Admin.</p></div></div>
    <?php if($photoSuccess): ?><div class="profile-photo-message success"><?=e($photoSuccess)?></div><?php endif; ?>
    <?php if($photoError): ?><div class="profile-photo-message error"><?=e($photoError)?></div><?php endif; ?>
    <div class="profile-photo-row">
      <div class="profile-photo-avatar">
        <?php if($profilePhotoUrl): ?>
          <img src="<?=e($profilePhotoUrl)?>" alt="<?=e($profile['name']??$currentUser['name'])?> profile picture">
        <?php else: ?>
          <?=e(strtoupper(substr((string)($profile['name']??$currentUser['name']??'C'),0,1)))?>
        <?php endif; ?>
      </div>
      <div class="profile-photo-controls">
        <form method="post" enctype="multipart/form-data" id="profilePhotoForm">
          <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="update_profile_photo">
          <label class="btn primary profile-photo-upload">Upload / Change
            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()">
          </label>
        </form>
        <?php if($profilePhotoUrl): ?>
        <form method="post" onsubmit="return confirm('Remove your profile picture?')">
          <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="remove_profile_photo">
          <button class="btn" type="submit">Remove Photo</button>
        </form>
        <?php endif; ?>
        <p class="profile-photo-help">JPG, PNG or WEBP · Maximum 2 MB</p>
      </div>
    </div>
    <?php if($profileSuccess): ?><div class="profile-details-message success"><?=e($profileSuccess)?></div><?php endif; ?>
    <?php if($profileError): ?><div class="profile-details-message error"><?=e($profileError)?></div><?php endif; ?>
    <form class="profile-details-form" method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="update_profile_details">
      <div class="profile-details-grid">
        <div class="profile-readonly"><small>Name</small><strong><?=e($profile['name']??$currentUser['name'])?></strong></div>
        <div class="profile-readonly"><small>Username</small><strong><?=e($profile['username']??$currentUser['username'])?></strong></div>
        <div class="profile-readonly"><small>Employee ID</small><strong><?=e(($profile['employee_id']??'')!=='' ? $profile['employee_id'] : 'Not assigned')?></strong></div>
        <label class="profile-field">Email
          <input type="email" name="email" maxlength="190" value="<?=e((($_POST['action']??'')==='update_profile_details') ? ($_POST['email']??'') : ($profile['email']??''))?>" placeholder="name@example.com">
        </label>
        <label class="profile-field">Mobile Number
          <input type="tel" name="mobile_number" maxlength="20" value="<?=e((($_POST['action']??'')==='update_profile_details') ? ($_POST['mobile_number']??'') : ($profile['mobile_number']??''))?>" placeholder="07X XXX XXXX">
        </label>
      </div>
      <div class="profile-save-row"><button class="btn primary" type="submit">Save Profile</button></div>
    </form>
  </article>



  <article class="panel settings-card">
    <div class="settings-head"><span class="settings-icon" aria-hidden="true">🔑</span><div><h2>Change Own Password</h2><p>Confirm your current password before creating a new one.</p></div></div>
    <form class="password-settings-form" method="post" autocomplete="off">
      <?php if($error): ?><div class="alert danger"><?=e($error)?></div><?php endif; ?>
      <?php if($success): ?><div class="settings-success"><?=e($success)?></div><?php endif; ?>
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="change_password">
      <label>Current Password<div class="password-toggle-wrap"><input type="password" name="current_password" autocomplete="current-password" required><button class="password-toggle" type="button">Show</button></div></label>
      <label>New Password<div class="password-toggle-wrap"><input type="password" name="new_password" minlength="8" autocomplete="new-password" required><button class="password-toggle" type="button">Show</button></div></label>
      <label>Confirm New Password<div class="password-toggle-wrap"><input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required><button class="password-toggle" type="button">Show</button></div></label>
      <p class="password-hint">Use at least 8 characters. Choose a password that is difficult for another person to guess.</p>
      <div class="password-settings-actions"><a class="btn" href="<?=e(base_url(active_cashier_shift((int)$currentUser['id'])?'pos.php':'index.php'))?>">Cancel</a><button class="btn primary" type="submit">Update Password</button></div>
    </form>
  </article>
</section>

<?php if($isPosLocked): ?>
<div class="pos-lock-overlay">
  <section class="pos-lock-card" role="dialog" aria-modal="true" aria-labelledby="posLockedTitle">
    <img class="pos-lock-logo" src="<?=e(base_url('assets/logo.png'))?>" alt="Misty Sip">
    <div class="pos-lock-badge" aria-hidden="true">🔒</div>
    <h2 id="posLockedTitle">POS Locked</h2>
    <p>Enter your cashier password to continue.</p>
    <div class="locked-user"><?=e($profile['name']??$currentUser['name'])?></div>
    <form class="unlock-form" method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="unlock_pos">
      <?php if($unlockError): ?><div class="unlock-error"><?=e($unlockError)?></div><?php endif; ?>
      <label>Password<input type="password" name="unlock_password" autocomplete="current-password" required autofocus></label>
      <button type="submit">Unlock POS</button>
    </form>
    <?php if(!empty($_SESSION['pos_locked_at'])): ?><small class="lock-time">Locked at <?=e(date('h:i A',strtotime((string)$_SESSION['pos_locked_at'])))?></small><?php endif; ?>
  </section>
</div>
<?php endif; ?>
<script>document.querySelectorAll('.password-toggle').forEach(function(button){button.addEventListener('click',function(){const input=button.parentElement.querySelector('input');const show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'Hide':'Show';});});</script>
<?php include __DIR__.'/includes/footer.php'; ?>
