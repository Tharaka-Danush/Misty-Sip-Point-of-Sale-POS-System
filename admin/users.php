<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_admin();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=$_POST['action']??'create';
    try{
        if($action==='toggle'){
            $id=(int)($_POST['id']??0);
            if($id!==auth_user()['id']){
                $s=db()->prepare('UPDATE users SET is_active=1-is_active WHERE id=?');
                $s->execute([$id]);
                $q=db()->prepare('SELECT name,is_active FROM users WHERE id=?');$q->execute([$id]);$ru=$q->fetch();log_activity((int)$ru['is_active']?'User Activated':'User Deactivated','user','User: '.$ru['name'],$id);
            }
        }elseif($action==='reset_password'){
            $id=(int)($_POST['id']??0);
            if($id<1)throw new RuntimeException('Invalid user.');
            $resetMarker=password_hash('__MISTY_SIP_ADMIN_RESET_PENDING__',PASSWORD_DEFAULT);
            $s=db()->prepare('UPDATE users SET password=?,password_reset_required=1,is_locked=0,failed_login_attempts=0 WHERE id=? AND role="cashier"');
            $s->execute([$resetMarker,$id]);$q=db()->prepare('SELECT name FROM users WHERE id=?');$q->execute([$id]);log_activity('Password Reset','user','User: '.(string)$q->fetchColumn(),$id);
        }else{
            $name=trim($_POST['name']??'');
            $username=trim($_POST['username']??'');
            $role=$_POST['role']??'cashier';
            if(!$name||!$username)throw new RuntimeException('Name and username are required.');
            if(!in_array($role,['admin','cashier'],true))throw new RuntimeException('Invalid user role.');

            // Admin does not assign the user's password. A secure placeholder is stored
            // and the user must create their own password on first sign-in.
            $temporarySecret=bin2hex(random_bytes(32));
            $s=db()->prepare('INSERT INTO users(name,username,password,role,password_reset_required) VALUES(?,?,?,?,1)');
            $s->execute([$name,$username,password_hash($temporarySecret,PASSWORD_DEFAULT),$role]);

            // Employee ID is generated automatically from users.id and role.
            // Cashier examples: id 1 -> MS-C1001, id 2 -> MS-C1002, id 5 -> MS-C1005.
            // Admin examples:   id 1 -> MS-A2001, id 2 -> MS-A2002, id 5 -> MS-A2005.
            $newUserId=(int)db()->lastInsertId();
            $employeeId=$role==='admin'
                ? 'MS-A'.(2000+$newUserId)
                : 'MS-C'.(1000+$newUserId);
            $u=db()->prepare('UPDATE users SET employee_id=? WHERE id=?');
            $u->execute([$employeeId,$newUserId]);log_activity('User Created','user','New '.$role.': '.$name.' ('.$employeeId.')',$newUserId);
        }
    }catch(Throwable $e){ $_SESSION['flash']='Error: '.$e->getMessage(); }
    header('Location: users.php'); exit;
}

$rows=db()->query('SELECT id,name,username,employee_id,role,is_active,is_locked,password_reset_required,created_at FROM users ORDER BY id')->fetchAll();
$pageTitle='Users'; include __DIR__.'/../includes/header.php';
?>
<section class="split users-layout">
  <form class="panel form-card add-user-form" method="post" autocomplete="off">
    <h2>Add User</h2><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <label>Full Name<input name="name" autocomplete="off" required></label>
    <label>Username<input name="username" autocomplete="off" autocapitalize="none" spellcheck="false" readonly onfocus="this.removeAttribute('readonly')" required></label>
    <label>Role<select name="role" autocomplete="off" required><option value="" selected disabled>Select role</option><option value="cashier">Cashier</option><option value="admin">Admin</option></select></label>
    <button class="btn primary">Create User</button>
  </form>
  <section class="panel users-table-panel"><div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Employee ID</th><th>Role</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php foreach($rows as $r): $isCurrent=((int)$r['id']===(int)auth_user()['id']); ?>
    <tr><td><?=e($r['name'])?></td><td><?=e($r['username'])?></td><td><?=e($r['employee_id']?:'—')?></td><td><?=e(ucfirst($r['role']))?></td>
    <td>
      <?php if($isCurrent):?><span class="badge success">Current <span class="status-icon" aria-hidden="true">👤</span></span>
      <?php elseif(!(int)$r['is_active']):?><span class="badge inactive">Inactive <span class="status-icon" aria-hidden="true">⏸</span></span>
      <?php elseif($r['role']==='cashier' && (int)$r['is_locked']):?><span class="badge locked">Locked <span class="status-icon" aria-hidden="true">🔒</span></span>
      <?php elseif((int)$r['password_reset_required']):?><span class="badge pending">Password Setup <span class="status-icon" aria-hidden="true">🔑</span></span>
      <?php else:?><span class="badge success">Active <span class="status-icon" aria-hidden="true">✓</span></span><?php endif;?>
    </td>
    <td>
      <?php if(!$isCurrent):?>
        <button class="manage-user-btn" type="button"
          data-id="<?=e($r['id'])?>"
          data-name="<?=e($r['name'])?>"
          data-username="<?=e($r['username'])?>"
          data-role="<?=e(ucfirst($r['role']))?>"
          data-active="<?= (int)$r['is_active'] ?>"
          data-locked="<?= (int)$r['is_locked'] ?>"
          data-reset="<?= (int)$r['password_reset_required'] ?>"
          data-cashier="<?= $r['role']==='cashier' ? '1':'0' ?>">Manage</button>
      <?php else:?><span>—</span><?php endif;?>
    </td></tr>
  <?php endforeach;?>
  </tbody></table></div></section>
</section>

<style>
/* Locked cashier status fallback */
.users-table-panel .badge.locked{background:#dc2626!important;color:#fff!important;border:1px solid #dc2626!important;font-weight:800!important}

.status-icon{display:inline-block;margin-left:4px;font-size:.92em;line-height:1;vertical-align:-1px}
/* Inactive user status fallback */
.users-table-panel .badge.inactive{background:#f59e0b!important;color:#fff!important;border:1px solid #f59e0b!important;font-weight:800!important}
/* Modal fallback: keeps Manage User as a true popup even if an old CSS file is cached. */
.manage-user-btn{min-width:86px;background:#fff!important;color:#075f45!important;border:1px solid #b9d8cd!important;border-radius:10px!important;padding:9px 16px!important;font-weight:800!important;cursor:pointer}
.manage-user-btn:hover{background:#edf8f4!important;border-color:#82bca8!important}
.user-manage-modal[hidden]{display:none!important}
.user-manage-modal{position:fixed!important;inset:0!important;z-index:100000!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:24px!important}
.user-manage-backdrop{position:absolute!important;inset:0!important;background:rgba(7,37,29,.58)!important;backdrop-filter:blur(5px)}
.user-manage-card{position:relative!important;z-index:1!important;width:min(470px,calc(100vw - 32px))!important;max-height:calc(100vh - 48px)!important;overflow:auto!important;background:#fff!important;border-radius:22px!important;box-shadow:0 24px 70px rgba(0,0,0,.28)!important;padding:26px!important}
.user-manage-head{display:flex!important;justify-content:space-between!important;align-items:flex-start!important;gap:16px!important;margin-bottom:20px!important}
.user-manage-head small{display:block;color:#718078;font-weight:800;letter-spacing:.08em;font-size:11px;margin-bottom:5px}
.user-manage-head h2{margin:0;color:#102d24;font-size:25px}
.user-manage-x{width:36px;height:36px;border:1px solid #d9e5e0;background:#fff;border-radius:10px;font-size:22px;line-height:1;cursor:pointer}
.user-manage-info{display:grid!important;grid-template-columns:1fr 1fr!important;gap:10px!important;margin-bottom:20px!important}
.user-manage-info div{border:1px solid #e0e9e5;background:#f8fbfa;border-radius:12px;padding:12px}
.user-manage-info small{display:block;color:#718078;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;margin-bottom:4px}
.user-manage-info strong{color:#17362c;font-size:14px;word-break:break-word}
@media(max-width:600px){}
.user-manage-actions{display:flex!important;gap:10px!important;flex-wrap:wrap!important;padding-top:18px!important;border-top:1px solid #e5ece9!important}
.user-manage-actions form{margin:0!important;flex:1!important}.user-manage-actions .btn{width:100%;min-height:42px}.user-manage-note{margin:0;color:#718078;font-size:13px;line-height:1.5}
@media(max-width:600px){.user-manage-info{grid-template-columns:1fr!important}.user-manage-actions{flex-direction:column!important}}
</style>
<div class="user-manage-modal" id="userManageModal" hidden aria-hidden="true">
  <div class="user-manage-backdrop" data-close-user-modal></div>
  <section class="user-manage-card" role="dialog" aria-modal="true" aria-labelledby="manageUserTitle">
    <div class="user-manage-head"><div><small>USER MANAGEMENT</small><h2 id="manageUserTitle">Manage User</h2></div><button class="user-manage-x" type="button" data-close-user-modal aria-label="Close">×</button></div>
    <div class="user-manage-info">
      <div><small>Name</small><strong id="muName">—</strong></div><div><small>Username</small><strong id="muUsername">—</strong></div>
      <div><small>Role</small><strong id="muRole">—</strong></div><div><small>Status</small><strong id="muStatus">—</strong></div>
    </div>
    <div class="user-manage-actions" id="muActions">
      <form method="post" id="muToggleForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" id="muToggleId"><button class="btn" id="muToggleBtn" type="submit"></button></form>
      <form method="post" id="muResetForm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" id="muResetId"><button class="btn" type="submit">Reset Password</button></form>
      <p class="user-manage-note" id="muWaiting" hidden>Password setup is already pending. The user must create a password on the next login.</p>
    </div>
  </section>
</div>
<style>
.user-confirm-modal[hidden]{display:none!important}
.user-confirm-modal{position:fixed!important;inset:0!important;z-index:100100!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:24px!important}
.user-confirm-backdrop{position:absolute!important;inset:0!important;background:rgba(7,37,29,.48)!important;backdrop-filter:blur(3px)}
.user-confirm-card{position:relative!important;z-index:1!important;width:min(420px,calc(100vw - 32px))!important;background:#fff!important;border-radius:20px!important;box-shadow:0 24px 70px rgba(0,0,0,.30)!important;padding:26px!important;text-align:center!important}
.user-confirm-icon{width:58px;height:58px;margin:0 auto 14px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#fde8e8;color:#c62828;font-size:28px;font-weight:900}
.user-confirm-card h3{margin:0 0 8px;color:#102d24;font-size:21px}.user-confirm-card p{margin:0 auto 20px;color:#65766f;font-size:14px;line-height:1.55;max-width:330px}
.user-confirm-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}.user-confirm-actions button{min-height:44px;border-radius:11px;font-weight:800;cursor:pointer}
.user-confirm-cancel{background:#fff;border:1px solid #d7e3df;color:#17362c}.user-confirm-ok{background:#c62828;border:1px solid #c62828;color:#fff}.user-confirm-ok.green{background:#0b684b;border-color:#0b684b}
</style>
<div class="user-confirm-modal" id="userConfirmModal" hidden aria-hidden="true">
 <div class="user-confirm-backdrop" data-confirm-cancel></div>
 <section class="user-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="userConfirmTitle">
  <div class="user-confirm-icon" id="userConfirmIcon">!</div>
  <h3 id="userConfirmTitle">Confirm action</h3>
  <p id="userConfirmText"></p>
  <div class="user-confirm-actions"><button type="button" class="user-confirm-cancel" data-confirm-cancel>Cancel</button><button type="button" class="user-confirm-ok" id="userConfirmOk">Confirm</button></div>
 </section>
</div>
<script>
(function(){
 const modal=document.getElementById('userManageModal'), name=document.getElementById('muName'), username=document.getElementById('muUsername'), role=document.getElementById('muRole'), status=document.getElementById('muStatus'), toggleForm=document.getElementById('muToggleForm'), resetForm=document.getElementById('muResetForm'), waiting=document.getElementById('muWaiting'), toggleId=document.getElementById('muToggleId'), resetId=document.getElementById('muResetId'), toggleBtn=document.getElementById('muToggleBtn');
 const confirmModal=document.getElementById('userConfirmModal'), confirmTitle=document.getElementById('userConfirmTitle'), confirmText=document.getElementById('userConfirmText'), confirmOk=document.getElementById('userConfirmOk'), confirmIcon=document.getElementById('userConfirmIcon'); let pendingForm=null;
 function close(){modal.hidden=true;modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
 document.querySelectorAll('.manage-user-btn').forEach(btn=>btn.addEventListener('click',function(){
   const d=this.dataset, active=d.active==='1', locked=d.locked==='1', pending=d.reset==='1', cashier=d.cashier==='1';
   name.textContent=d.name; username.textContent=d.username; role.textContent=d.role;
   status.textContent=!active?'Inactive ⏸':(locked?'Locked 🔒':(pending?'Password Setup 🔑':'Active ✓'));
   toggleId.value=d.id; resetId.value=d.id; toggleBtn.textContent=active?'Set Inactive':'Set Active'; toggleBtn.classList.toggle('danger-soft',active);
   resetForm.hidden=!(cashier && active && !pending); waiting.hidden=!(cashier && pending);
   modal.hidden=false;modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';
 }));
 document.querySelectorAll('[data-close-user-modal]').forEach(el=>el.addEventListener('click',close));
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});
 function ask(form,title,text,buttonText,isGreen=false){pendingForm=form;confirmTitle.textContent=title;confirmText.textContent=text;confirmOk.textContent=buttonText;confirmOk.classList.toggle('green',isGreen);confirmIcon.textContent=isGreen?'✓':'!';confirmModal.hidden=false;confirmModal.setAttribute('aria-hidden','false');}
 function closeConfirm(){confirmModal.hidden=true;confirmModal.setAttribute('aria-hidden','true');pendingForm=null;}
 resetForm.addEventListener('submit',e=>{e.preventDefault();ask(resetForm,'Reset Cashier Password?','The cashier will create a new password on the next login.','Reset Password');});
 toggleForm.addEventListener('submit',e=>{e.preventDefault();const activating=toggleBtn.textContent==='Set Active';ask(toggleForm,activating?'Activate User?':'Set User Inactive?',activating?'This user will be allowed to sign in again.':'This user will not be able to sign in until activated again.',activating?'Activate User':'Set Inactive',activating);});
 document.querySelectorAll('[data-confirm-cancel]').forEach(el=>el.addEventListener('click',closeConfirm));
 confirmOk.addEventListener('click',()=>{if(!pendingForm)return;const f=pendingForm;pendingForm=null;confirmModal.hidden=true;f.submit();});
})();
</script>
<?php include __DIR__.'/../includes/footer.php'; ?>


<style>
/* Users page: keep Add User panel stationary; scroll only the users list panel */
.users-layout{
  align-items:flex-start!important;
  overflow:visible!important;
}
.users-layout .add-user-form{
  position:sticky!important;
  top:18px!important;
  align-self:flex-start!important;
  height:auto!important;
  max-height:none!important;
  overflow:visible!important;
}
.users-layout .users-table-panel{
  max-height:calc(100vh - 150px)!important;
  overflow:hidden!important;
  display:flex!important;
  flex-direction:column!important;
}
.users-layout .users-table-panel .table-wrap{
  overflow-y:auto!important;
  overflow-x:auto!important;
  max-height:calc(100vh - 170px)!important;
}
.users-layout .users-table-panel table thead th{
  position:sticky!important;
  top:0!important;
  z-index:2!important;
  background:#fff!important;
}
</style>
