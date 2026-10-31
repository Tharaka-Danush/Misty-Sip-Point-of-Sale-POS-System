<?php
declare(strict_types=1);

// Misty Sip operates in Sri Lanka; keep all PHP-rendered shift times in local time.
date_default_timezone_set('Asia/Colombo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

require_once __DIR__ . '/../config/database.php';

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid CSRF token. Refresh and try again.');
    }
}

function auth_user(): ?array { return $_SESSION['user'] ?? null; }

function require_login(): void
{
    $sessionUser=auth_user();
    if (!$sessionUser) {
        header('Location: ' . base_url('login.php'));
        exit;
    }

    // 30-minute inactivity policy.
    $idleLimit=30*60;
    $lastActivity=(int)($_SESSION['last_activity_at']??time());
    $idleExpired=(time()-$lastActivity)>$idleLimit;
    if($idleExpired){
        if(($sessionUser['role']??'')==='cashier'){
            if(empty($_SESSION['pos_locked'])){
                log_activity('POS Auto Locked','system','Cashier POS locked automatically after 30 minutes of inactivity.');
            }
            $_SESSION['pos_locked']=true;
            $_SESSION['pos_locked_at']=date('Y-m-d H:i:s');
            $_SESSION['last_activity_at']=time();
            $currentScript=basename((string)($_SERVER['SCRIPT_NAME']??''));
            if($currentScript!=='cashier_settings.php'){
                header('Location: '.base_url('cashier_settings.php?locked=1&timeout=1'));
                exit;
            }
        }else{
            log_activity('Auto Logout','system','Admin session ended automatically after 30 minutes of inactivity.');
            $_SESSION=[];
            if(ini_get('session.use_cookies')){ $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
            session_destroy();
            header('Location: '.base_url('login.php?timeout=1'));
            exit;
        }
    }

    $_SESSION['last_activity_at']=time();

    $stmt=db()->prepare('SELECT id,name,username,role,is_active,is_locked FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$sessionUser['id']]);
    $freshUser=$stmt->fetch();
    if(!$freshUser || !(int)$freshUser['is_active'] || (int)$freshUser['is_locked']){
        $_SESSION=[];
        if(ini_get('session.use_cookies')){ $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
        session_destroy();
        header('Location: '.base_url('login.php?account=disabled'));
        exit;
    }
    $_SESSION['user']=array_merge($sessionUser,['name'=>$freshUser['name'],'username'=>$freshUser['username'],'role'=>$freshUser['role']]);

    // Keep a cashier signed in while locked, but block every other POS page until unlock.
    if (($freshUser['role'] ?? '') === 'cashier' && !empty($_SESSION['pos_locked'])) {
        $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($currentScript !== 'cashier_settings.php') {
            header('Location: ' . base_url('cashier_settings.php?locked=1'));
            exit;
        }
    }
}

function require_admin(): void
{
    require_login();
    if ((auth_user()['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin access required.');
    }
}

function base_url(string $path = ''): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $root = preg_replace('#/(admin|api)/.*$#', '', $script);
    $root = preg_replace('#/[^/]+\.php$#', '', $root);
    return rtrim($root, '/') . '/' . ltrim($path, '/');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function default_product_image(int $categoryId): string
{
    return [1=>'assets/products/bubble-tea.png',2=>'assets/products/fresh-juice.png',3=>'assets/products/ice-cream.png',4=>'assets/products/hot-drink.png'][$categoryId] ?? 'assets/logo.png';
}

function save_product_image(array $file, ?string $current = null): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $current ?? '';
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed.');
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) throw new RuntimeException('Image must be smaller than 3 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG or WEBP images are allowed.');
    $directory=__DIR__.'/../assets/uploads';
    if(!is_dir($directory) && !mkdir($directory,0755,true) && !is_dir($directory)) throw new RuntimeException('Could not create upload folder.');
    $name='product-'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
    if(!move_uploaded_file($file['tmp_name'],$directory.'/'.$name)) throw new RuntimeException('Could not save image.');
    return 'assets/uploads/'.$name;
}

function log_activity(string $action,string $category,string $details='',?int $targetId=null): void
{
    try {
        $u=auth_user(); if(!$u) return;
        $stmt=db()->prepare('INSERT INTO activity_logs(user_id,user_name,user_role,action,category,details,target_id,ip_address,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())');
        $stmt->execute([(int)$u['id'],(string)$u['name'],(string)$u['role'],$action,$category,$details,$targetId,$_SERVER['REMOTE_ADDR']??null]);
    } catch(Throwable $e) { error_log('Activity log failed: '.$e->getMessage()); }
}



function ensure_app_settings_table(): void
{
    static $done=false;
    if($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done=true;
}

function app_setting(string $key,string $default=''): string
{
    ensure_app_settings_table();
    static $cache=[];
    if(array_key_exists($key,$cache)) return $cache[$key];
    $stmt=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $value=$stmt->fetchColumn();
    return $cache[$key]=$value===false?$default:(string)$value;
}

function set_app_setting(string $key,string $value): void
{
    ensure_app_settings_table();
    $user=auth_user();
    $stmt=db()->prepare("INSERT INTO app_settings(setting_key,setting_value,updated_by,updated_at)
        VALUES(?,?,?,NOW())
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=NOW()");
    $stmt->execute([$key,$value,$user['id']??null]);
}

function app_setting_float(string $key,float $default): float
{
    $value=app_setting($key,(string)$default);
    return is_numeric($value)?(float)$value:$default;
}

function app_setting_int(string $key,int $default): int
{
    $value=app_setting($key,(string)$default);
    return is_numeric($value)?(int)$value:$default;
}

function verify_admin_credentials(string $username,string $password): ?array
{
    $username=trim($username);
    if($username==='' || $password==='') return null;
    $stmt=db()->prepare("SELECT id,name,username,password,role,is_active FROM users WHERE username=? AND role='admin' LIMIT 1");
    $stmt->execute([$username]);
    $admin=$stmt->fetch();
    if(!$admin || !(int)$admin['is_active']) return null;
    return password_verify($password,(string)$admin['password']) ? $admin : null;
}

function issue_admin_approval(string $action,string $reference,array $admin): string
{
    if(!isset($_SESSION['admin_approvals']) || !is_array($_SESSION['admin_approvals'])) $_SESSION['admin_approvals']=[];
    foreach($_SESSION['admin_approvals'] as $token=>$approval){
        if((int)($approval['expires']??0)<time()) unset($_SESSION['admin_approvals'][$token]);
    }
    $token=bin2hex(random_bytes(24));
    $_SESSION['admin_approvals'][$token]=[
        'action'=>$action,
        'reference'=>$reference,
        'admin_id'=>(int)$admin['id'],
        'admin_name'=>(string)$admin['name'],
        'expires'=>time()+300
    ];
    return $token;
}

function consume_admin_approval(string $token,string $action,string $reference): ?array
{
    if($token==='' || empty($_SESSION['admin_approvals'][$token])) return null;
    $approval=$_SESSION['admin_approvals'][$token];
    unset($_SESSION['admin_approvals'][$token]);
    if((int)($approval['expires']??0)<time()) return null;
    if(!hash_equals((string)($approval['action']??''),$action)) return null;
    if(!hash_equals((string)($approval['reference']??''),$reference)) return null;
    return $approval;
}

function active_cashier_shift(?int $userId = null): ?array
{
    $userId = $userId ?? (int)(auth_user()['id'] ?? 0);
    if ($userId < 1) return null;
    $stmt = db()->prepare('SELECT * FROM cashier_shifts WHERE user_id=? AND closed_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function require_open_cashier_shift(): array
{
    require_login();
    $user = auth_user();
    if (($user['role'] ?? '') !== 'cashier') return [];
    if (!empty($_SESSION['closed_shift_id'])) {
        header('Location: ' . base_url('shift_summary.php'));
        exit;
    }
    $shift = active_cashier_shift((int)$user['id']);
    if (!$shift) {
        header('Location: ' . base_url('open_shift.php'));
        exit;
    }
    return $shift;
}
