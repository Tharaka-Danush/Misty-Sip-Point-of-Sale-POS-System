<?php require_once __DIR__ . '/bootstrap.php'; require_login(); $user = auth_user(); $isCashier=($user['role']??'')==='cashier'; $cashierShift=$isCashier?active_cashier_shift((int)$user['id']):null; $hasOpenShift=!empty($cashierShift); $currentPage=basename((string)($_SERVER['SCRIPT_NAME']??''));
$isSaleSummaryReceipt=($currentPage==='receipt.php' && (($_GET['return']??'')==='sale_summary'));
function nav_icon(string $name): string {
  $icons=[
    'dashboard'=>'<svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-5v-6h-5v6h-5A1.5 1.5 0 0 1 3 19.5z"/></svg>',
    'sale'=>'<svg viewBox="0 0 24 24"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"/><circle cx="10" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/></svg>',
    'products'=>'<svg viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4.5 7.7 7.5 4.2 7.5-4.2M12 12v9"/></svg>',
    'consumables'=>'<svg viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M4.5 7.7 12 12l7.5-4.3M12 12v9M8 5.2l8 4.5"/></svg>',
    'categories'=>'<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    'reports'=>'<svg viewBox="0 0 24 24"><path d="M5 20V10M12 20V4M19 20v-7"/></svg>',
    'summary'=>'<svg viewBox="0 0 24 24"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
    'shifts'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'users'=>'<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 20v-2.2A4.8 4.8 0 0 1 8.3 13h1.4a4.8 4.8 0 0 1 4.8 4.8V20M16 5.5a3 3 0 0 1 0 5.8M17 13.2a4.8 4.8 0 0 1 3.5 4.6V20"/></svg>',
    'activity'=>'<svg viewBox="0 0 24 24"><path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h5M8 16h7"/></svg>',
    'backup'=>'<svg viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/></svg>',
    'open'=>'<svg viewBox="0 0 24 24"><path d="M5 20V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v13M8 10h8M12 7v6"/></svg>',
    'close'=>'<svg viewBox="0 0 24 24"><path d="M5 20V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v13M8 10h8"/></svg>',
    'settings'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/></svg>',
    'lock'=>'<svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
    'logout'=>'<svg viewBox="0 0 24 24"><path d="M10 5H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h5M14 8l4 4-4 4M18 12H9"/></svg>'
  ];
  return '<span class="nav-icon" aria-hidden="true">'.($icons[$name]??'').'</span>';
}
function nav_current(string $currentPage, array $pages): string {
  return in_array($currentPage, $pages, true) ? ' aria-current="page"' : '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($pageTitle ?? 'Misty Sip POS') ?></title>
  <link rel="stylesheet" href="<?= e(base_url('assets/css/app.css')) ?>?v=20260922-packaging-teal-final">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/products.css')) ?>?v=83">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/close-shift.css')) ?>?v=1">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin-products.css')) ?>?v=38-compact-category-form">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/consumables.css')) ?>?v=2">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/consumables-toggle.css')) ?>?v=5">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/consumables-mapping.css')) ?>?v=3">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/admin-users.css')) ?>?v=4">
  <link rel="stylesheet" href="<?= e(base_url('assets/css/catalog-grid.css')) ?>?v=11">
  <?php foreach (($extraStyles ?? []) as $extraStyle): ?>
  <link rel="stylesheet" href="<?= e(base_url($extraStyle)) ?>">
  <?php endforeach; ?>
  <style>
    /* Cashier sidebar final contrast override */
    .sidebar.cashier-sidebar nav > a,
    .sidebar.cashier-sidebar nav > a span,
    .sidebar.cashier-sidebar nav > a .nav-icon,
    .sidebar.cashier-sidebar nav > a .nav-icon svg {
      color:#fff !important;
      opacity:1 !important;
      visibility:visible !important;
      filter:none !important;
    }
    .sidebar.cashier-sidebar nav > a {
      font-weight:600 !important;
      text-shadow:0 1px 1px rgba(0,0,0,.14) !important;
    }
    .sidebar.cashier-sidebar nav > a:hover {
      background:rgba(255,255,255,.18) !important;
      color:#fff !important;
    }
     .sidebar.cashier-sidebar .nav-lock-form{margin:0;padding:0;width:100%;}
    .sidebar.cashier-sidebar .nav-lock-button{
      width:100%;display:flex;align-items:center;gap:12px;min-height:48px;
      border:0;background:transparent;color:#fff;padding:8px 10px;border-radius:11px;
      font:inherit;font-weight:600;text-align:left;cursor:pointer;
      text-shadow:0 1px 1px rgba(0,0,0,.14);
      transition:background .18s,color .18s,transform .18s;
    }
    .sidebar.cashier-sidebar .nav-lock-button:hover{
      background:rgba(255,255,255,.18)!important;color:#fff!important;transform:translateX(2px);
    }
    /* Lock POS icon matches the other sidebar icon boxes */
    .sidebar.cashier-sidebar .nav-lock-button .nav-icon{
      width:38px!important;height:38px!important;min-width:38px!important;
      border-radius:10px!important;display:grid!important;place-items:center!important;
      background:linear-gradient(135deg,#f7b733 0%,#f59e0b 100%)!important;
      color:#fff!important;box-shadow:0 5px 12px rgba(245,158,11,.28)!important;
      transition:transform .18s,box-shadow .18s!important;
    }
    .sidebar.cashier-sidebar .nav-lock-button .nav-icon svg{
      width:21px!important;height:21px!important;stroke:#fff!important;color:#fff!important;
      fill:none!important;stroke-width:1.8!important;
    }
    .sidebar.cashier-sidebar .nav-lock-button:hover .nav-icon{
      transform:translateY(-1px) scale(1.04)!important;
      box-shadow:0 7px 15px rgba(245,158,11,.34)!important;
    }

    /* Lock POS confirmation modal */
    .ms-lock-overlay{position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.52);display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)}
    .ms-lock-overlay.is-open{display:flex}
    .ms-lock-modal{width:min(420px,calc(100vw - 40px));background:#fff;border-radius:22px;box-shadow:0 26px 80px rgba(15,23,42,.30);padding:30px 30px 26px;text-align:center;position:relative;animation:msLockIn .18s ease-out}
    @keyframes msLockIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
    .ms-lock-icon{width:64px;height:64px;border-radius:18px;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;background:#fff7dc;color:#d97706}
    .ms-lock-icon svg{width:32px;height:32px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    .ms-lock-modal h3{margin:0 0 8px;font-size:22px;line-height:1.25;color:#0f5132}
    .ms-lock-modal p{margin:0 auto;color:#64748b;font-size:14px;line-height:1.55;max-width:330px}
    .ms-lock-actions{display:flex;gap:12px;margin-top:24px}
    .ms-lock-actions button{flex:1;border:0;border-radius:12px;padding:12px 16px;font:inherit;font-weight:700;cursor:pointer}
    .ms-lock-cancel{background:#eef2f7;color:#334155}
    .ms-lock-cancel:hover{background:#e2e8f0}
    .ms-lock-confirm{background:#0f8a5f;color:#fff}
    .ms-lock-confirm:hover{background:#0b7350}

    .sidebar.cashier-sidebar nav > a[aria-current="page"],
    .sidebar.cashier-sidebar nav > a[aria-current="page"] > span:not(.nav-icon) {
      color:#075b3b !important;
      text-shadow:none !important;
    }

    /* Logout confirmation modal - isolated styles */
    .ms-logout-overlay{position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.48);display:none;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px)}
    .ms-logout-overlay.is-open{display:flex}
    .ms-logout-modal{width:min(404px,calc(100vw - 40px));background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(15,23,42,.28);padding:26px 28px 24px;text-align:center;position:relative;animation:msLogoutIn .18s ease-out}
    @keyframes msLogoutIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
    .ms-logout-close{position:absolute;right:18px;top:15px;border:0;background:transparent;color:#94a3b8;font-size:30px;line-height:1;cursor:pointer;padding:2px 6px}
    .ms-logout-icon{width:72px;height:72px;margin:2px auto 16px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(145deg,#fb7185,#ef4444);box-shadow:0 10px 24px rgba(239,68,68,.25)}
    .ms-logout-icon svg{width:34px;height:34px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
    .ms-logout-modal h2{margin:0 0 10px;color:#111827;font-size:28px;line-height:1.15;font-weight:800}
    .ms-logout-modal p{margin:0;color:#374151;font-size:16px;line-height:1.55}
    .ms-logout-modal p small{display:block;color:#64748b;font-size:14px;margin-top:2px}
    .ms-logout-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:24px}
    .ms-logout-actions button,.ms-logout-actions a{height:50px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;text-decoration:none;cursor:pointer;box-sizing:border-box}
    .ms-logout-cancel{border:0;background:#eef1f4;color:#1f2937}
    .ms-logout-confirm{border:0;background:linear-gradient(135deg,#fb3f4c,#ef2f3e);color:#fff;box-shadow:0 8px 18px rgba(239,47,62,.20)}
    .ms-logout-cancel:hover{background:#e2e8f0}.ms-logout-confirm:hover{filter:brightness(.97)}
  </style>
  <script>
    (function () {
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
      function isReload() {
        var entries = performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
        return (entries[0] && entries[0].type === 'reload') ||
          (performance.navigation && performance.navigation.type === 1);
      }
      function resetReloadScroll() {
        if (isReload()) window.scrollTo(0, 0);
      }
      window.addEventListener('pageshow', function () {
        if (isReload()) setTimeout(resetReloadScroll, 0);
      });
    }());
  </script>




<style>
/* Cashier sidebar - Sale Summary icon */
.cashier-sidebar a.nav-summary .nav-icon{
  width:38px!important;
  height:38px!important;
  min-width:38px!important;
  flex:0 0 38px!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  border-radius:10px!important;
  background:linear-gradient(135deg,#f472b6 0%,#ec4899 100%)!important;
  color:#fff!important;
  opacity:1!important;
  box-shadow:0 7px 16px rgba(236,72,153,.24)!important;
}
.cashier-sidebar a.nav-summary .nav-icon svg{
  width:20px!important;
  height:20px!important;
  stroke:#fff!important;
  color:#fff!important;
  opacity:1!important;
}
</style>


<style>
/* Activity Log menu - purple accent */
.sidebar .nav-activity .nav-icon,
.nav-activity .nav-icon{
  background:linear-gradient(135deg,#8b5cf6,#7c3aed)!important;
  color:#fff!important;
  box-shadow:0 8px 18px rgba(124,58,237,.24)!important;
}
.sidebar .nav-activity .nav-icon svg,
.nav-activity .nav-icon svg{
  color:#fff!important;
  stroke:#fff!important;
}
</style>


<style>
/* Backup & Restore admin menu accent */
.sidebar .nav-backup .nav-icon{
  background:linear-gradient(135deg,#38bdf8,#2563eb)!important;
  color:#fff!important;
  box-shadow:0 8px 18px rgba(37,99,235,.23)!important;
}
.sidebar .nav-backup .nav-icon svg{
  color:#fff!important;
  stroke:#fff!important;
}
</style>


<style>
/* Admin sidebar compact menu */
.sidebar:not(.cashier-sidebar) nav{
  gap:3px!important;
}
.sidebar:not(.cashier-sidebar) nav > a{
  min-height:44px!important;
  padding:6px 9px!important;
  gap:10px!important;
  border-radius:10px!important;
  font-size:14px!important;
  line-height:1.15!important;
}
.sidebar:not(.cashier-sidebar) nav > a .nav-icon{
  width:34px!important;
  height:34px!important;
  min-width:34px!important;
  flex:0 0 34px!important;
  border-radius:9px!important;
}
.sidebar:not(.cashier-sidebar) nav > a .nav-icon svg{
  width:18px!important;
  height:18px!important;
}
.sidebar:not(.cashier-sidebar) .sidebar-bottom-wrap{
  margin-top:auto!important;
  padding-top:18px!important;
  padding-bottom:6px!important;
}
</style>


<style>
/* Keep the admin slogan + user card at the very bottom */
.sidebar:not(.cashier-sidebar){
  display:flex!important;
  flex-direction:column!important;
}
.sidebar:not(.cashier-sidebar) nav{
  flex:0 0 auto!important;
}
.sidebar:not(.cashier-sidebar) .sidebar-bottom-wrap{
  margin-top:auto!important;
}
</style>


<style>
.sidebar:not(.cashier-sidebar) .nav-admin-settings .nav-icon{
  background:linear-gradient(135deg,#14b8a6,#0f766e)!important;
  color:#fff!important;
  box-shadow:0 7px 16px rgba(15,118,110,.22)!important;
}
.sidebar:not(.cashier-sidebar) .nav-admin-settings .nav-icon svg{stroke:#fff!important;color:#fff!important}
</style>

</head>
<body<?= !empty($bodyClass) ? ' class="'.e($bodyClass).'"' : '' ?>>
<div class="ms-logout-overlay" id="logoutConfirmModal" aria-hidden="true">
  <div class="ms-logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle">
    <button type="button" class="ms-logout-close" id="logoutModalClose" aria-label="Close">&times;</button>
    <div class="ms-logout-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 5H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h5M14 8l4 4-4 4M18 12H9"/></svg></div>
    <h2 id="logoutConfirmTitle">Logout</h2>
    <p>Are you sure you want to logout?<small>You will be returned to the login page.</small></p>
    <div class="ms-logout-actions">
      <button type="button" class="ms-logout-cancel" id="logoutModalCancel">Cancel</button>
      <a class="ms-logout-confirm" href="<?= e(base_url('logout.php')) ?>">Logout</a>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var link = document.getElementById('logoutMenuLink');
  var modal = document.getElementById('logoutConfirmModal');
  var cancel = document.getElementById('logoutModalCancel');
  var close = document.getElementById('logoutModalClose');
  if (!link || !modal) return;
  function openLogoutModal(e){ if(e) e.preventDefault(); modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); }
  function closeLogoutModal(){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); }
  link.addEventListener('click', openLogoutModal);
  if(cancel) cancel.addEventListener('click', closeLogoutModal);
  if(close) close.addEventListener('click', closeLogoutModal);
  modal.addEventListener('click', function(e){ if(e.target === modal) closeLogoutModal(); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && modal.classList.contains('is-open')) closeLogoutModal(); });
});
</script>
<aside class="sidebar<?= $isCashier ? ' cashier-sidebar' : '' ?>">
  <a class="brand" href="<?= e(base_url($isCashier?($hasOpenShift?'pos.php':'open_shift.php'):'index.php')) ?>">
    <img src="<?= e(base_url('assets/logo.png')) ?>" alt="Misty Sip"><span>MISTY SIP <small>POS SYSTEM</small></span>
  </a>
  <nav>
    <?php if ($isCashier): ?>
      <?php if ($hasOpenShift): ?>
        <a class="nav-sale" href="<?= e(base_url('pos.php')) ?>"<?= (!$isSaleSummaryReceipt ? nav_current($currentPage,['pos.php','receipt.php','prep_slip.php']) : '') ?>><?= nav_icon('sale') ?><span>New Sale</span></a>
        <a class="nav-dashboard" href="<?= e(base_url('index.php')) ?>"<?= nav_current($currentPage,['index.php']) ?>><?= nav_icon('dashboard') ?><span>Dashboard</span></a>
        <a class="nav-summary" href="<?= e(base_url('sale_summary.php')) ?>"<?= ($isSaleSummaryReceipt ? ' aria-current="page"' : nav_current($currentPage,['sale_summary.php'])) ?>><?= nav_icon('summary') ?><span>Sale Summary</span></a>
        <a class="nav-settings" href="<?= e(base_url('cashier_settings.php')) ?>"<?= $currentPage==='cashier_settings.php'?' aria-current="page"':'' ?>><?= nav_icon('settings') ?><span>Settings</span></a>
        <form class="nav-lock-form" method="post" action="<?= e(base_url('cashier_settings.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="lock_pos"><button type="submit" class="nav-lock-button"><?= nav_icon('lock') ?><span>Lock POS</span></button></form>
        <a class="nav-close" href="<?= e(base_url('close_shift.php')) ?>"<?= nav_current($currentPage,['close_shift.php','shift_summary.php']) ?>><?= nav_icon('close') ?><span>Close Shift</span></a>
      <?php else: ?>
        <a class="nav-open" href="<?= e(base_url('open_shift.php')) ?>"<?= nav_current($currentPage,['open_shift.php']) ?>><?= nav_icon('open') ?><span>Open Shift</span></a>
        <a class="nav-dashboard" href="<?= e(base_url('index.php')) ?>"<?= nav_current($currentPage,['index.php']) ?>><?= nav_icon('dashboard') ?><span>Dashboard</span></a>
        <a class="nav-summary" href="<?= e(base_url('sale_summary.php')) ?>"<?= ($isSaleSummaryReceipt ? ' aria-current="page"' : nav_current($currentPage,['sale_summary.php'])) ?>><?= nav_icon('summary') ?><span>Sale Summary</span></a>
        <a class="nav-settings" href="<?= e(base_url('cashier_settings.php')) ?>"<?= $currentPage==='cashier_settings.php'?' aria-current="page"':'' ?>><?= nav_icon('settings') ?><span>Settings</span></a>
        <form class="nav-lock-form" method="post" action="<?= e(base_url('cashier_settings.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="lock_pos"><button type="submit" class="nav-lock-button"><?= nav_icon('lock') ?><span>Lock POS</span></button></form>
      <?php endif; ?>
    <?php else: ?>
      <a class="nav-dashboard" href="<?= e(base_url('index.php')) ?>"<?= nav_current($currentPage,['index.php']) ?>><?= nav_icon('dashboard') ?><span>Dashboard</span></a>
    <?php endif; ?>
    <?php if ($user['role'] === 'admin'): ?>
      <a class="nav-products" href="<?= e(base_url('admin/products.php')) ?>"<?= nav_current($currentPage,['products.php']) ?>><?= nav_icon('products') ?><span>Products & Stock</span></a>
      <a class="nav-consumables" href="<?= e(base_url('admin/consumables.php')) ?>"<?= nav_current($currentPage,['consumables.php']) ?>><?= nav_icon('consumables') ?><span>Packaging & Consumables</span></a>
      <a class="nav-reports" href="<?= e(base_url('admin/reports.php')) ?>"<?= nav_current($currentPage,['reports.php']) ?>><?= nav_icon('reports') ?><span>Sales Reports</span></a>
      <a class="nav-shifts" href="<?= e(base_url('admin/shifts.php')) ?>"<?= nav_current($currentPage,['shifts.php']) ?>><?= nav_icon('shifts') ?><span>Cashier Shifts</span></a>
      <a class="nav-users" href="<?= e(base_url('admin/users.php')) ?>"<?= nav_current($currentPage,['users.php','user_form.php']) ?>><?= nav_icon('users') ?><span>Users</span></a>
      <a class="nav-activity" href="<?= e(base_url('admin/activity_log.php')) ?>"<?= nav_current($currentPage,['activity_log.php']) ?>><?= nav_icon('activity') ?><span>Activity Log</span></a>
      <a class="nav-admin-settings" href="<?= e(base_url('admin/settings.php')) ?>"<?= nav_current($currentPage,['settings.php']) ?>><?= nav_icon('settings') ?><span>Settings</span></a>
      <a class="nav-backup" href="<?= e(base_url('admin/backup_restore.php')) ?>"<?= nav_current($currentPage,['backup_restore.php']) ?>><?= nav_icon('backup') ?><span>Backup &amp; Restore</span></a>
      <a class="nav-logout" href="<?= e(base_url('logout.php')) ?>" id="logoutMenuLink"><?= nav_icon('logout') ?><span>Logout</span></a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-bottom-wrap">
  <div class="sidebar-slogan"><span>SIP</span><span>Happiness</span><span>Everyday</span><svg class="sidebar-slogan-leaf" viewBox="0 0 48 48" aria-hidden="true"><path d="M42 6C23 7 10 17 8 36c14 3 29-7 34-30Z"/><path d="M9 38c8-10 17-18 29-26"/></svg></div>
  <div class="sidebar-user modern-user-card"><span class="user-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.5-4 3-6 7-6s6.5 2 7 6"/></svg></span><div><strong><?= e($user['name']) ?></strong><small><?= e(ucfirst($user['role'])) ?></small></div></div>
</div>
</aside>

<div class="ms-lock-overlay" id="msLockOverlay" aria-hidden="true">
  <div class="ms-lock-modal" role="dialog" aria-modal="true" aria-labelledby="msLockTitle">
    <div class="ms-lock-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
    </div>
    <h3 id="msLockTitle">Lock POS now?</h3>
    <p>Your current shift will stay open. You will need your cashier password to unlock the POS.</p>
    <div class="ms-lock-actions">
      <button type="button" class="ms-lock-cancel" id="msLockCancel">Cancel</button>
      <button type="button" class="ms-lock-confirm" id="msLockConfirm">Lock POS</button>
    </div>
  </div>
</div>
<script>
(function(){
  const overlay = document.getElementById('msLockOverlay');
  const cancelBtn = document.getElementById('msLockCancel');
  const confirmBtn = document.getElementById('msLockConfirm');
  const forms = document.querySelectorAll('.nav-lock-form');
  let pendingForm = null;
  let allowSubmit = false;

  function openLockModal(form){
    pendingForm = form;
    overlay.classList.add('is-open');
    overlay.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
    setTimeout(() => cancelBtn.focus(), 0);
  }
  function closeLockModal(){
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
    pendingForm = null;
  }

  forms.forEach(function(form){
    form.addEventListener('submit', function(e){
      if (allowSubmit) return;
      e.preventDefault();
      openLockModal(form);
    });
  });

  cancelBtn.addEventListener('click', closeLockModal);
  confirmBtn.addEventListener('click', function(){
    if (!pendingForm) return;
    allowSubmit = true;
    pendingForm.submit();
  });
  overlay.addEventListener('click', function(e){
    if (e.target === overlay) closeLockModal();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeLockModal();
  });
})();
</script>

<script>
(function(){
  const IDLE_LIMIT_MS=30*60*1000;
  let idleTimer=null;
  function expireSession(){
    window.location.href=<?= json_encode(base_url('session_timeout.php')) ?>;
  }
  function resetIdleTimer(){
    clearTimeout(idleTimer);
    idleTimer=setTimeout(expireSession,IDLE_LIMIT_MS);
  }
  ['mousedown','mousemove','keydown','scroll','touchstart','click'].forEach(function(eventName){
    window.addEventListener(eventName,resetIdleTimer,{passive:true});
  });
  document.addEventListener('visibilitychange',function(){
    if(!document.hidden) resetIdleTimer();
  });
  resetIdleTimer();
})();
</script>
<main class="main"><header class="topbar"><img class="topbar-logo" src="<?= e(base_url('assets/logo.png')) ?>" alt="Misty Sip"><div class="topbar-copy"><h1><?= e($pageTitle ?? 'Misty Sip POS') ?></h1><p><?= e($pageSubtitle ?? 'Freshly made • Naturally refreshing') ?></p></div><div class="header-slogan"><span>Good Drinks</span><span>Brighter Days</span><svg class="header-slogan-leaf" viewBox="0 0 48 48" aria-hidden="true"><path d="M42 6C23 7 10 17 8 36c14 3 29-7 34-30Z"/><path d="M9 38c8-10 17-18 29-26"/></svg></div><span class="date"><svg class="header-date-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/><path d="M7 14h2v2H7zM11 14h2v2h-2zM15 14h2v2h-2z"/></svg><?= date('d M Y') ?></span></header>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert"><?= e($_SESSION['flash']); unset($_SESSION['flash']); ?></div><?php endif; ?>
