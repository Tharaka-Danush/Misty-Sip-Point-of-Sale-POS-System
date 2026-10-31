<?php
require_once __DIR__ . '/includes/bootstrap.php';
if ($loggedInUser=auth_user()) {
    header('Location: '.(($loggedInUser['role']??'')==='cashier'?(active_cashier_shift((int)$loggedInUser['id'])?'pos.php':'open_shift.php'):'index.php'));
    exit;
}
$error = $_SESSION['login_error'] ?? '';
$loginUsername = $_SESSION['login_username'] ?? '';
$explicitLogout = false;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['password'])) { $error=''; $loginUsername=''; }
unset($_SESSION['login_error'], $_SESSION['login_username']);
$message='';
if (($_GET['password']??'')==='changed') $message='Password changed successfully. Please sign in.';
if (($_GET['timeout']??'')==='1') $message='Your session ended after 30 minutes of inactivity. Please sign in again.';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username=trim($_POST['login_user'] ?? $_POST['username'] ?? '');
    $stmt = db()->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && (int)($user['is_active']??0)!==1) {
        $error='Your account is inactive. Please contact the administrator.';
        $loginUsername=$username;
    } elseif ($user && (int)($user['password_reset_required']??0)===1) {
        $_SESSION['password_reset_user_id']=(int)$user['id'];
        header('Location: reset_password.php'); exit;
    }

    if ($user && ($user['role']??'')==='cashier' && (int)($user['is_locked']??0)===1) {
        $error='Account locked. Please contact the administrator.';
        $loginUsername=$username;
    } elseif ($user && password_verify($_POST['login_pass'] ?? $_POST['password'] ?? '', $user['password'])) {
        db()->prepare('UPDATE users SET failed_login_attempts=0 WHERE id=?')->execute([$user['id']]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>$user['id'],'name'=>$user['name'],'username'=>$user['username'],'role'=>$user['role']];
        $_SESSION['last_activity_at']=time();
        unset($_SESSION['pos_locked'],$_SESSION['pos_locked_at']);
        log_activity('Login','system','User signed in successfully.');
        header('Location: '.($user['role']==='cashier'?(active_cashier_shift((int)$user['id'])?'pos.php':'open_shift.php'):'index.php')); exit;
    } else {
        if ($user && ($user['role']??'')==='cashier') {
            $attempts=(int)($user['failed_login_attempts']??0)+1;
            if ($attempts>=3) {
                db()->prepare('UPDATE users SET failed_login_attempts=3,is_locked=1 WHERE id=?')->execute([$user['id']]);
                $error='Account locked after 3 incorrect password attempts. Please contact the administrator.';
                $loginUsername=$username;
            } else {
                db()->prepare('UPDATE users SET failed_login_attempts=? WHERE id=?')->execute([$attempts,$user['id']]);
                $error='Invalid username or password. '.(3-$attempts).' attempt(s) remaining.';
                $loginUsername=$username;
            }
        } else {
            $error='Invalid username or password.';
            $loginUsername=$username;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login | Misty Sip POS</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
*{box-sizing:border-box}
body.login-body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:Inter,Segoe UI,Arial,sans-serif;background:linear-gradient(135deg,#f3fff5 0%,#e8f8ed 45%,#dcf3e4 100%);overflow:hidden;position:relative}
body.login-body:before,body.login-body:after{content:"";position:fixed;border-radius:50%;filter:blur(4px);opacity:.55;pointer-events:none}
body.login-body:before{width:420px;height:420px;background:#c9f2d3;top:-170px;left:-120px}
body.login-body:after{width:480px;height:480px;background:#bde8c9;right:-180px;bottom:-210px}
.login-shell{width:min(940px,100%);min-height:560px;display:grid;grid-template-columns:1.05fr .95fr;background:rgba(255,255,255,.86);border:1px solid rgba(255,255,255,.9);border-radius:30px;box-shadow:0 28px 70px rgba(26,92,48,.16);overflow:hidden;position:relative;z-index:2;backdrop-filter:blur(16px)}
.login-brand{padding:54px;display:flex;flex-direction:column;justify-content:space-between;background:linear-gradient(145deg,rgba(28,126,62,.97),rgba(17,91,46,.96));color:#fff;position:relative;overflow:hidden}
.login-brand:after{content:"";position:absolute;width:320px;height:320px;border:60px solid rgba(255,255,255,.07);border-radius:50%;right:-150px;bottom:-130px}
.brand-logo{width:118px;height:118px;object-fit:contain;background:#fff;border-radius:26px;padding:10px;box-shadow:0 16px 35px rgba(0,0,0,.16);position:relative;z-index:1}
.brand-copy{position:relative;z-index:1}.brand-copy h1{font-size:44px;line-height:1.05;margin:0 0 14px;letter-spacing:-1.5px}.brand-copy p{font-size:17px;line-height:1.65;margin:0;color:rgba(255,255,255,.82);max-width:360px}.brand-foot{font-size:13px;color:rgba(255,255,255,.65);position:relative;z-index:1}
.login-panel{padding:48px;display:flex;align-items:center;background:rgba(255,255,255,.78)}
.login-form{width:100%;margin:0;background:rgba(255,255,255,.9);border:1px solid rgba(31,124,65,.12);border-radius:24px;padding:34px 32px;box-shadow:0 18px 42px rgba(28,92,48,.10)}.login-form .eyebrow{font-size:12px;font-weight:800;letter-spacing:1.7px;text-transform:uppercase;color:#238447;margin-bottom:10px}.login-form h2{font-size:32px;margin:0 0 8px;color:#252332}.login-form>p{margin:0 0 30px;color:#777383}
.login-form label{display:block;font-size:13px;font-weight:800;color:#3f4b43;margin:0 0 18px}.field-wrap{position:relative;margin-top:8px}.field-icon{position:absolute;left:15px;top:50%;transform:translateY(-50%);font-size:17px;opacity:.62;pointer-events:none}.login-form label .field-wrap input:not([type=hidden]){margin-top:0;padding-left:45px}.password-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);border:0;background:transparent;cursor:pointer;font-size:17px;padding:8px;opacity:.58}.password-toggle:hover{opacity:1}.login-form input:not([type=hidden]){width:100%;height:52px;margin-top:8px;border:1px solid #ded9e7;border-radius:14px;background:#fff;padding:0 16px;font-size:15px;outline:none;transition:.2s;box-shadow:0 4px 14px rgba(60,45,80,.04)}.login-form input:focus{border-color:#2e9852;box-shadow:0 0 0 4px rgba(46,152,82,.12)}
.login-form .btn.primary.full{width:100%;height:52px;border:0;border-radius:14px;background:linear-gradient(135deg,#2d9b52,#176f3a);color:#fff;font-size:15px;font-weight:800;cursor:pointer;box-shadow:0 12px 24px rgba(31,124,65,.22);transition:.2s}.login-form .btn.primary.full:hover{transform:translateY(-1px);box-shadow:0 15px 28px rgba(31,124,65,.28)}
.login-form small{display:block;text-align:center;margin-top:20px;color:#9a96a1;font-size:11px}.login-form .alert{border-radius:12px;padding:11px 13px;margin:0 0 18px;font-size:13px}.login-form .alert.danger{background:#fff0f1;color:#a73a46;border:1px solid #ffd7db}
#loginLoader{position:fixed;inset:0;z-index:9999;background:linear-gradient(135deg,#f4fff6,#e8f8ed,#dff4e6);display:flex;align-items:center;justify-content:center;transition:opacity .45s ease,visibility .45s ease}.loader-box{text-align:center}.loader-logo{width:120px;height:120px;object-fit:contain;background:#fff;border-radius:28px;padding:10px;box-shadow:0 18px 45px rgba(26,92,48,.16);animation:floatLogo 1.8s ease-in-out infinite}.loader-title{margin:22px 0 6px;font-size:25px;color:#332d3b;font-weight:800}.loader-sub{font-size:13px;color:#8c8592}.developer-credit{margin-top:14px;font-size:12px;color:#4d7358;font-weight:700;letter-spacing:.2px}.loader-track{width:220px;height:5px;background:rgba(31,124,65,.14);border-radius:99px;overflow:hidden;margin:20px auto 0}.loader-bar{height:100%;width:0;background:linear-gradient(90deg,#36ad5f,#176f3a);border-radius:99px;animation:loadFive 5s linear forwards}@keyframes loadFive{to{width:100%}}@keyframes floatLogo{50%{transform:translateY(-7px)}}#loginLoader.hide{opacity:0;visibility:hidden}
.fruit-layer{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:1}.fruit{position:absolute;font-size:58px;opacity:.72;filter:drop-shadow(0 10px 13px rgba(16,80,37,.25)) saturate(1.25);will-change:transform;user-select:none}.fruit.f1{top:7%;left:5%;animation:fruitMove1 13s ease-in-out infinite}.fruit.f2{top:18%;left:76%;font-size:70px;animation:fruitMove2 16s ease-in-out infinite}.fruit.f3{top:68%;left:8%;font-size:54px;animation:fruitMove3 14s ease-in-out infinite}.fruit.f4{top:72%;left:80%;font-size:66px;animation:fruitMove4 18s ease-in-out infinite}.fruit.f5{top:42%;left:3%;font-size:50px;animation:fruitMove5 15s ease-in-out infinite}.fruit.f6{top:38%;left:88%;font-size:62px;animation:fruitMove6 17s ease-in-out infinite}.fruit.f7{top:4%;left:48%;font-size:48px;animation:fruitMove7 19s ease-in-out infinite}.fruit.f8{top:86%;left:45%;font-size:56px;animation:fruitMove8 16s ease-in-out infinite}@keyframes fruitMove1{0%,100%{transform:translate(0,0) rotate(-12deg)}25%{transform:translate(24vw,12vh) rotate(60deg)}50%{transform:translate(8vw,58vh) rotate(145deg)}75%{transform:translate(55vw,30vh) rotate(235deg)}}@keyframes fruitMove2{0%,100%{transform:translate(0,0) rotate(10deg)}25%{transform:translate(-38vw,14vh) rotate(-70deg)}50%{transform:translate(-12vw,55vh) rotate(-155deg)}75%{transform:translate(-65vw,30vh) rotate(-245deg)}}@keyframes fruitMove3{0%,100%{transform:translate(0,0) rotate(0)}30%{transform:translate(58vw,-42vh) rotate(100deg)}60%{transform:translate(25vw,-10vh) rotate(210deg)}80%{transform:translate(70vw,-55vh) rotate(290deg)}}@keyframes fruitMove4{0%,100%{transform:translate(0,0) rotate(0)}30%{transform:translate(-55vw,-48vh) rotate(-110deg)}60%{transform:translate(-20vw,-12vh) rotate(-210deg)}82%{transform:translate(-68vw,-60vh) rotate(-300deg)}}@keyframes fruitMove5{0%,100%{transform:translate(0,0) rotate(-20deg)}25%{transform:translate(68vw,-28vh) rotate(90deg)}50%{transform:translate(38vw,34vh) rotate(180deg)}75%{transform:translate(72vw,12vh) rotate(280deg)}}@keyframes fruitMove6{0%,100%{transform:translate(0,0) rotate(20deg)}25%{transform:translate(-70vw,28vh) rotate(-90deg)}50%{transform:translate(-38vw,-30vh) rotate(-180deg)}75%{transform:translate(-75vw,-8vh) rotate(-280deg)}}@keyframes fruitMove7{0%,100%{transform:translate(0,0) rotate(0)}33%{transform:translate(35vw,66vh) rotate(130deg)}66%{transform:translate(-35vw,45vh) rotate(255deg)}}@keyframes fruitMove8{0%,100%{transform:translate(0,0) rotate(0)}33%{transform:translate(-35vw,-68vh) rotate(-130deg)}66%{transform:translate(38vw,-43vh) rotate(-255deg)}}@media(prefers-reduced-motion:reduce){.fruit{animation:none}}
@media(max-width:760px){body.login-body{padding:14px;overflow:auto}.login-shell{grid-template-columns:1fr;min-height:auto;border-radius:24px}.login-brand{padding:28px;min-height:210px}.brand-logo{width:78px;height:78px;border-radius:19px}.brand-copy h1{font-size:30px;margin-top:20px}.brand-copy p,.brand-foot{display:none}.login-panel{padding:34px 28px}.login-form h2{font-size:27px}}
</style>
</head>
<body class="login-body">
<div class="fruit-layer" aria-hidden="true"><span class="fruit f1">🍓</span><span class="fruit f2">🍊</span><span class="fruit f3">🍋</span><span class="fruit f4">🥭</span><span class="fruit f5">🍇</span><span class="fruit f6">🍍</span><span class="fruit f7">🥝</span><span class="fruit f8">🍉</span></div>
<div id="loginLoader" aria-label="Loading Misty Sip POS">
  <div class="loader-box"><img class="loader-logo" src="assets/logo.png" alt="Misty Sip"><div class="loader-title">Misty Sip POS</div><div class="loader-sub">Preparing your workspace...</div><div class="loader-track"><div class="loader-bar"></div></div><div class="developer-credit">Developed by Tharaka Danush</div></div>
</div>
<div class="login-shell">
  <section class="login-brand"><img class="brand-logo" src="assets/logo.png" alt="Misty Sip"><div class="brand-copy"><h1>Misty Sip<br>POS</h1><p>Fast, simple and reliable point-of-sale management for your daily operations.</p></div><div class="brand-foot">Misty Sip • Point of Sale System</div></section>
  <section class="login-panel">
    <form class="login-form" method="post" autocomplete="off">
      <input type="text" name="fake_username" autocomplete="username" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <input type="password" name="fake_password" autocomplete="current-password" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <div class="eyebrow">Secure Access</div><h2>Welcome back</h2><p>Sign in to continue to your POS.</p>
      <?php if($message): ?><div class="alert" id="loginMessage"><?= e($message) ?></div><?php endif; ?>
      <?php if($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
      <div class="alert danger" id="clientLoginError" style="display:none"></div>
      <div class="alert danger" id="accountStatusMessage" style="display:none"></div>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>Username<div class="field-wrap"><span class="field-icon">👤</span><input name="login_user" value="<?= e($loginUsername !== '' ? $loginUsername : ($_POST['login_user'] ?? $_POST['username'] ?? '')) ?>" required autofocus autocomplete="off" readonly autocapitalize="none" spellcheck="false" placeholder="Enter your username"></div></label>
      <div id="normalPasswordArea">
        <label>Password<div class="field-wrap"><span class="field-icon">🔒</span><input id="loginPassword" type="password" name="login_pass" value="" required autocomplete="off" readonly placeholder="Enter your password"><button class="password-toggle" type="button" id="passwordToggle" aria-label="Show password">◉</button></div></label>
        <button class="btn primary full" id="signInButton">Sign In</button>
      </div>
      <div id="newPasswordArea" style="display:none">
        <div class="alert" id="passwordSetupMessage" style="background:#eefaf1;color:#176f3a;border:1px solid #ccebd5">Create your password to continue.</div>
        <label>Create New Password<div class="field-wrap"><span class="field-icon">🔒</span><input id="newPassword" type="password" autocomplete="new-password" placeholder="Create new password"></div></label>
        <label>Confirm Password<div class="field-wrap"><span class="field-icon">🔒</span><input id="confirmPassword" type="password" autocomplete="new-password" placeholder="Confirm new password"></div></label>
        <button class="btn primary full" type="button" id="createPasswordButton">Create Password &amp; Continue</button>
        <div class="alert danger" id="newPasswordError" style="display:none;margin-top:14px"></div>
      </div>
      <small>Authorized users only</small>
    </form>
  </section>
</div>
<script>
const usernameInput=document.querySelector('input[name="login_user"]');
const passwordInput=document.querySelector('input[name="login_pass"]');
const passwordToggle=document.getElementById('passwordToggle');
[usernameInput,passwordInput].forEach(function(el){const unlock=function(){el.removeAttribute('readonly');};el.addEventListener('focus',unlock,{once:true});el.addEventListener('pointerdown',unlock,{once:true});});
passwordToggle.addEventListener('click',function(){const show=passwordInput.type==='password';passwordInput.type=show?'text':'password';this.textContent=show?'◌':'◉';this.setAttribute('aria-label',show?'Hide password':'Show password');});
const loginForm=document.querySelector('.login-form');
const signInButton=document.getElementById('signInButton');
const clientLoginError=document.getElementById('clientLoginError');
loginForm.addEventListener('submit',async function(event){
    event.preventDefault();
    if(normalPasswordArea.style.display==='none') return;
    clientLoginError.style.display='none';
    signInButton.disabled=true; signInButton.textContent='Signing In...';
    const body=new URLSearchParams({csrf_token:document.querySelector('input[name=csrf_token]').value,username:usernameInput.value.trim(),password:passwordInput.value});
    try{
        const response=await fetch('api/login_auth.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body});
        const data=await response.json();
        if(data.ok){ window.location.href=data.redirect; return; }
        if(data.password_setup){ passwordSetupMessage.textContent=data.setup_type==='reset'?'Password reset. Create a new password to continue.':'New user detected. Create your password to continue.'; showNewPassword(); return; }
        clientLoginError.textContent=data.error||'Invalid username or password.'; clientLoginError.style.display='block'; passwordInput.value=''; passwordInput.focus();
    }catch(e){clientLoginError.textContent='Unable to sign in. Please try again.';clientLoginError.style.display='block';passwordInput.value='';passwordInput.focus();}
    finally{signInButton.disabled=false;signInButton.textContent='Sign In';}
});
let lastUsername=usernameInput.value;
const normalPasswordArea=document.getElementById('normalPasswordArea');
const newPasswordArea=document.getElementById('newPasswordArea');
const newPassword=document.getElementById('newPassword');
const confirmPassword=document.getElementById('confirmPassword');
const createPasswordButton=document.getElementById('createPasswordButton');
const newPasswordError=document.getElementById('newPasswordError');
const passwordSetupMessage=document.getElementById('passwordSetupMessage');
const accountStatusMessage=document.getElementById('accountStatusMessage');
let lookupTimer=null;
let accountStatusClearTimer=null;
function showNormalPassword(){normalPasswordArea.style.display='block';newPasswordArea.style.display='none';accountStatusMessage.style.display='none';accountStatusMessage.textContent='';passwordInput.required=true;}
function showAccountStatus(message){
    clearTimeout(accountStatusClearTimer);
    normalPasswordArea.style.display='none';
    newPasswordArea.style.display='none';
    passwordInput.required=false;
    passwordInput.value='';
    accountStatusMessage.textContent=message;
    accountStatusMessage.style.display='block';
    accountStatusClearTimer=setTimeout(function(){
        accountStatusMessage.style.display='none';
        accountStatusMessage.textContent='';
        usernameInput.value='';
        passwordInput.value='';
        lastUsername='';
        showNormalPassword();
        usernameInput.focus();
    },3000);
}
function showNewPassword(){normalPasswordArea.style.display='none';newPasswordArea.style.display='block';passwordInput.required=false;passwordInput.value='';newPassword.focus();}
async function identifyUsername(){
    const username=usernameInput.value.trim();
    if(!username){showNormalPassword();return;}
    try{const r=await fetch('api/check_login_user.php?username='+encodeURIComponent(username),{cache:'no-store'});const data=await r.json();if(username!==usernameInput.value.trim())return;if(data.found&&data.inactive){showAccountStatus(data.message||'Your account is inactive. Please contact the administrator.');}else if(data.found&&data.locked){showAccountStatus(data.message||'Account locked. Please contact the administrator.');}else if(data.found&&data.new_password){passwordSetupMessage.textContent=data.setup_type==='reset'?'Password reset. Create a new password to continue.':'New user detected. Create your password to continue.';showNewPassword();}else showNormalPassword();}catch(e){showNormalPassword();}
}
usernameInput.addEventListener('input',function(){
    clearTimeout(accountStatusClearTimer);
    if(this.value!==lastUsername && passwordInput.value!=='') passwordInput.value='';
    lastUsername=this.value;
    clearTimeout(lookupTimer); lookupTimer=setTimeout(identifyUsername,350);
});
usernameInput.addEventListener('blur',identifyUsername);
createPasswordButton.addEventListener('click',async function(){
    newPasswordError.style.display='none';
    if(newPassword.value.length<6){newPasswordError.textContent='Password must be at least 6 characters.';newPasswordError.style.display='block';return;}
    if(newPassword.value!==confirmPassword.value){newPasswordError.textContent='Passwords do not match.';newPasswordError.style.display='block';return;}
    const body=new URLSearchParams({csrf_token:document.querySelector('input[name=csrf_token]').value,username:usernameInput.value.trim(),password:newPassword.value,confirm_password:confirmPassword.value});
    createPasswordButton.disabled=true;
    try{const r=await fetch('set_first_password.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const data=await r.json();if(data.ok){window.location.href=data.redirect;}else{newPasswordError.textContent=data.error||'Unable to create password.';newPasswordError.style.display='block';createPasswordButton.disabled=false;}}catch(e){newPasswordError.textContent='Unable to create password. Please try again.';newPasswordError.style.display='block';createPasswordButton.disabled=false;}
});
const explicitLogout=true;
function clearLoginFields(){
    if(!explicitLogout) return;
    loginForm.reset();
    usernameInput.value='';
    passwordInput.value='';
    newPassword.value='';
    confirmPassword.value='';
    lastUsername='';
    showNormalPassword();
    clientLoginError.style.display='none';
}
if(usernameInput.value && !explicitLogout) identifyUsername();
window.addEventListener('pageshow',function(){
    clearLoginFields();
    if(explicitLogout){ setTimeout(clearLoginFields,100); setTimeout(clearLoginFields,500); setTimeout(clearLoginFields,1200); }
});
window.addEventListener('load',function(){
    clearLoginFields();
    if(explicitLogout){ setTimeout(clearLoginFields,250); setTimeout(clearLoginFields,800); setTimeout(clearLoginFields,1600); }
    const loginLoader=document.getElementById('loginLoader');
    const hasLoginError=<?= $error !== '' ? 'true' : 'false' ?>;
    if(hasLoginError){
        // Invalid credentials stay on this exact login card with no reload/loader.
        loginLoader.classList.add('hide');
        if(window.history && window.history.replaceState){
            window.history.replaceState({}, document.title, 'login.php');
        }
        passwordInput.value='';
        passwordInput.focus();
    }else{
        setTimeout(function(){loginLoader.classList.add('hide');usernameInput.focus();},5000);
    }
    const loginMessage=document.getElementById('loginMessage');
    if(loginMessage){
        setTimeout(function(){
            loginMessage.style.transition='opacity .35s ease, transform .35s ease';
            loginMessage.style.opacity='0';
            loginMessage.style.transform='translateY(-6px)';
            setTimeout(function(){loginMessage.remove();},350);
        },3000);
    }
});
</script>
</body></html>