<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!function_exists('setFlash')) {
    function setFlash($type, $message) {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}

requireAdmin();
$_mobFlash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$_flashHTML = '';
if (!empty($_mobFlash)) {
    $f = $_mobFlash;
    $isSuccess   = $f['type'] === 'success';
    $bgColor     = $isSuccess ? '#d1e7dd' : '#f8d7da';
    $textColor   = $isSuccess ? '#0a3622' : '#58151c';
    $borderColor = $isSuccess ? '#a3cfbb' : '#f1aeb5';
    $accentColor = $isSuccess ? '#22c55e' : '#ef4444';
    $_flashHTML  = '
    <div id="desktopFlash" style="
        background:'.$bgColor.';
        color:'.$textColor.';
        border:1px solid '.$borderColor.';
        border-left:4px solid '.$accentColor.';
        border-radius:8px;
        padding:12px 40px 12px 14px;
        font-size:.88rem;
        margin-bottom:16px;
        position:relative;
        line-height:1.5;
        animation: flashFadeOut 0.4s ease 4s forwards;
    ">
        '.htmlspecialchars($f['message']).'
        <button onclick="document.getElementById(\'desktopFlash\').remove()"
            style="position:absolute;top:50%;right:14px;transform:translateY(-50%);background:none;border:none;font-size:1.2rem;color:'.$textColor.';opacity:.6;cursor:pointer;line-height:1;padding:0;">&times;</button>
    </div>
    <style>@keyframes flashFadeOut { to { opacity:0; pointer-events:none; height:0; margin:0; padding:0; overflow:hidden; } }</style>';
}

// ── Notification count ──
$_notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$_notifStmt->execute([$_SESSION['user_id']]);
$_sidebarUnread = (int)$_notifStmt->fetchColumn();

// Ensure $oid is defined for use in POST handlers (defaults to session office)
$oid = $_SESSION['office_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

   if ($action === 'add') {
    $driver_name   = sanitize($_POST['driver_name']  ?? '');
    $license_no    = sanitize($_POST['license_no']   ?? '');
    $phone_no      = sanitize($_POST['phone_no']     ?? '');
    $status        = sanitize($_POST['status']       ?? 'Available');
    $driver_scope  = sanitize($_POST['driver_scope'] ?? 'Office');
    $office_id_drv = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : $oid;
    $drv_email     = trim($_POST['drv_email']    ?? '');
    $drv_password  = trim($_POST['drv_password'] ?? '');

    if (empty($driver_name)) {
        $_SESSION['flash'] = ['type'=>'danger','message'=>"Driver name is required."];
        header("Location: Drivers.php"); exit;
    }
    if (empty($drv_email) || !filter_var($drv_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['type'=>'danger','message'=>"Valid email is required."];
        header("Location: Drivers.php"); exit;
    }

    // Check email uniqueness in drivers table only
    $chk = $pdo->prepare("SELECT COUNT(*) FROM drivers WHERE email = ?");
    $chk->execute([$drv_email]);
    if ((int)$chk->fetchColumn() > 0) {
        $_SESSION['flash'] = ['type'=>'danger','message'=>"That email is already in use."];
        header("Location: Drivers.php"); exit;
    }
    if (empty($drv_password) || strlen($drv_password) < 6) {
        $_SESSION['flash'] = ['type'=>'danger','message'=>"Password must be at least 6 characters."];
        header("Location: Drivers.php"); exit;
    }

    try {
        $hashed = password_hash($drv_password, PASSWORD_DEFAULT);
        $dStmt  = $pdo->prepare("
            INSERT INTO drivers
                (driver_name, license_no, phone_no, status,
                 driver_scope, office_id, email, password)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $dStmt->execute([
            $driver_name, $license_no, $phone_no,
            $status, $driver_scope, $office_id_drv,
            $drv_email, $hashed
        ]);
        $_SESSION['flash'] = ['type'=>'success','message'=>"Driver added with login account."];
    } catch (PDOException $e) {
        $_SESSION['flash'] = ['type'=>'danger','message'=>"DB error: ".$e->getMessage()];
    }
    header("Location: Drivers.php"); exit;
} // end if action === 'add'
if ($action === 'edit') {
    $driver_id    = (int)($_POST['driver_id'] ?? 0);
    $driver_name  = sanitize($_POST['driver_name']   ?? '');
    $license_no   = sanitize($_POST['license_no']    ?? '');
    $phone_no     = sanitize($_POST['phone_no']      ?? '');
    $status       = sanitize($_POST['status']        ?? 'Active');
    $driver_scope = sanitize($_POST['driver_scope']  ?? 'Campus');
    $office_id    = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : $oid;
    $drv_email    = trim($_POST['drv_email']    ?? '');
    $drv_password = trim($_POST['drv_password'] ?? '');

    try {
        // Build update query — only update password if a new one is provided
        if ($drv_password !== '' && strlen($drv_password) >= 6) {
            $hashed = password_hash($drv_password, PASSWORD_DEFAULT);
            $pdo->prepare("
                UPDATE drivers
                SET driver_name=?, license_no=?, phone_no=?,
                    status=?, driver_scope=?, office_id=?, email=?, password=?
                WHERE driver_id=?
            ")->execute([
                $driver_name, $license_no, $phone_no,
                $status, $driver_scope, $office_id,
                $drv_email ?: null, $hashed, $driver_id
            ]);
        } else {
            $pdo->prepare("
                UPDATE drivers
                SET driver_name=?, license_no=?, phone_no=?,
                    status=?, driver_scope=?, office_id=?, email=?
                WHERE driver_id=?
            ")->execute([
                $driver_name, $license_no, $phone_no,
                $status, $driver_scope, $office_id,
                $drv_email ?: null, $driver_id
            ]);
        }
        $_SESSION['flash'] = ['type'=>'success','message'=>"Driver updated successfully."];
    } catch (PDOException $e) {
        $_SESSION['flash'] = ['type'=>'danger','message'=>"DB error: ".$e->getMessage()];
    }
    header("Location: Drivers.php"); exit;
}
}  // end if POST
$session_office_id = $_SESSION['office_id'] ?? null;
if (!$session_office_id && !empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT office_id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    $session_office_id = $u['office_id'] ?? null;
    $_SESSION['office_id'] = $session_office_id;
}

$is_superadmin = empty($session_office_id);
$session_office_name = '';

if (!$is_superadmin) {
    $stmt = $pdo->prepare("SELECT office_name FROM offices WHERE office_id = ?");
    $stmt->execute([$session_office_id]);
    $o = $stmt->fetch();
    $session_office_name = $o['office_name'] ?? '';
    $_SESSION['office_name'] = $session_office_name;
} else {
    $session_office_name = $_SESSION['office_name'] ?? '';
}

$user_scope = null;
if (!$is_superadmin) {
    if (stripos($session_office_name, 'central') !== false)    $user_scope = 'Central';
    elseif (stripos($session_office_name, 'campus') !== false) $user_scope = 'Campus';
    elseif (stripos($session_office_name, 'rde') !== false)    $user_scope = 'RDE';
}

$filter = $_GET['scope'] ?? 'all';

if ($is_superadmin) {
    if ($filter === 'central')    $where = "WHERE d.driver_scope = 'Central'";
    elseif ($filter === 'campus') $where = "WHERE d.driver_scope = 'Campus'";
    elseif ($filter === 'both')   $where = "WHERE d.driver_scope = 'Both'";
    else                          $where = '';
} else {
    $oid  = (int)$session_office_id;
    $base = "(d.office_id = $oid OR d.driver_scope = 'Both')";
    if ($filter === 'central')    $where = "WHERE $base AND d.driver_scope = 'Central'";
    elseif ($filter === 'campus') $where = "WHERE $base AND d.driver_scope = 'Campus'";
    elseif ($filter === 'both')   $where = "WHERE $base AND d.driver_scope = 'Both'";
    else                          $where = "WHERE $base";
}

$drivers = $pdo->query("
    SELECT d.*, o.office_name
    FROM drivers d
    LEFT JOIN offices o ON d.office_id = o.office_id
    $where
    ORDER BY d.driver_id DESC
")->fetchAll();

$offices = $pdo->query("SELECT * FROM offices ORDER BY office_name")->fetchAll();

if ($is_superadmin) {
    $cnt_central = $pdo->query("SELECT COUNT(*) FROM drivers WHERE driver_scope='Central'")->fetchColumn();
    $cnt_campus  = $pdo->query("SELECT COUNT(*) FROM drivers WHERE driver_scope='Campus'")->fetchColumn();
    $cnt_both    = $pdo->query("SELECT COUNT(*) FROM drivers WHERE driver_scope='Both'")->fetchColumn();
    $cnt_all     = $pdo->query("SELECT COUNT(*) FROM drivers")->fetchColumn();
} else {
    $oid = (int)$session_office_id;
    if ($user_scope === 'Central') {
        $cnt_central = $pdo->query("SELECT COUNT(*) FROM drivers WHERE driver_scope='Central' AND office_id=$oid")->fetchColumn();
        $cnt_campus  = 0;
    } else {
        $cnt_campus  = $pdo->query("SELECT COUNT(*) FROM drivers WHERE driver_scope='Campus' AND office_id=$oid")->fetchColumn();
        $cnt_central = 0;
    }
    $cnt_both = $pdo->query("SELECT COUNT(*) FROM drivers WHERE driver_scope='Both'")->fetchColumn();
    $cnt_all  = $pdo->query("SELECT COUNT(*) FROM drivers WHERE office_id=$oid OR driver_scope='Both'")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Drivers – CSU VSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; margin: 0; }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #800000 0%, #6b0000 100%);
            width: 240px;
            position: fixed; top: 0; left: 0;
            z-index: 200;
            display: flex; flex-direction: column;
            overflow-y: auto;
        }
        .sidebar-brand { padding: 1.25rem 1rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; gap: 10px; }
        .sidebar-logo { width: 42px; height: 42px; border-radius: 50%; background: #fff; overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sidebar-logo img { width: 38px; height: 38px; object-fit: contain; }
        .sidebar-brand-text { color: #fff; font-size: 0.82rem; font-weight: 700; line-height: 1.3; }
        .sidebar-brand-text span { display: block; font-size: 0.72rem; font-weight: 400; opacity: 0.7; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.6rem 1.25rem; font-size: 0.88rem; display: flex; align-items: center; gap: 10px; border-left: 3px solid transparent; transition: all 0.15s; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); border-left-color: rgba(255,255,255,0.4); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.15); border-left-color: #fff; font-weight: 600; }
        .sidebar .nav-link i { font-size: 1rem; width: 18px; }
        .sidebar-divider { border-color: rgba(255,255,255,0.15); margin: 0.5rem 1rem; }
        .nav-section-label { padding: 0.75rem 1.25rem 0.25rem; font-size: 0.68rem; font-weight: 700; color: rgba(255,255,255,0.45); letter-spacing: 0.08em; text-transform: uppercase; }

        .topbar { background: #fff; border-bottom: 1px solid #e8dede; padding: 0.7rem 1.5rem; margin-left: 240px; position: sticky; top: 0; z-index: 99; display: flex; align-items: center; justify-content: space-between; }
        .topbar .topbar-title { flex: 1; }
        .topbar-title { font-weight: 700; font-size: 1rem; color: #800000; }
        .topbar-user { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #666; }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: #800000; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; }
        .hamburger-btn { background: none; border: none; cursor: pointer; padding: 4px 8px; color: #800000; font-size: 1.3rem; display: flex; align-items: center; line-height: 1; margin-right: 0.5rem; }

        .main-content { margin-left: 240px; padding: 1.5rem; }

        .section-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(128,0,0,0.07); overflow: hidden; }
        .section-header { padding: 1rem 1.25rem; border-bottom: 1px solid #f0e5e5; font-weight: 700; font-size: 0.9rem; color: #800000; display: flex; align-items: center; justify-content: space-between; }
        .table { table-layout: fixed; width: 100%; }
        .table thead th { background: #fdf5f5; color: #800000; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #f0e5e5; padding: 0.75rem; white-space: nowrap; }
        .table tbody td { padding: 0.7rem 0.75rem; font-size: 0.85rem; color: #444; vertical-align: middle; border-color: #fdf5f5; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .table tbody tr:hover { background: #fdf8f8; }
        .col-id      { width: 50px; }
        .col-driver  { width: 22%; }
        .col-license { width: 15%; }
        .col-phone   { width: 13%; }
        .col-scope   { width: 14%; }
        .col-office  { width: 12%; }
        .col-status  { width: 10%; }
        .col-actions { width: 100px; }

        .badge-available   { background: #d1e7dd; color: #0f5132; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; }
        .badge-unavailable { background: #f8d7da; color: #842029; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; }
        .badge-maintenance { background: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; }
        .scope-central { background: #e8f0fe; color: #1a56db; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .scope-campus  { background: #fce8e6; color: #800000; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .scope-both    { background: #e6f4ea; color: #137333; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .btn-maroon { background: #800000; color: #fff; border: none; }
        .btn-maroon:hover { background: #6b0000; color: #fff; }
        .modal-header { background: linear-gradient(135deg,#800000,#6b0000); color: #fff; }
        .modal-header .btn-close { filter: invert(1); }
        .modal-content { border-radius: 14px; border: none; }
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1rem; }
        .filter-tab { padding: 6px 16px; border-radius: 20px; font-size: 0.82rem; font-weight: 600; cursor: pointer; text-decoration: none; border: 1.5px solid #e0d0d0; color: #800000; background: #fff; transition: all 0.15s; }
        .filter-tab:hover { background: #fdf0f0; color: #800000; }
        .filter-tab.active { background: #800000; color: #fff; border-color: #800000; }
        .filter-tab .count { opacity: 0.75; font-size: 0.75rem; margin-left: 4px; }
        .mini-stats { display: grid; gap: 12px; margin-bottom: 1.25rem; }
        .mini-stats-4 { grid-template-columns: repeat(4,1fr); }
        .mini-stats-3 { grid-template-columns: repeat(3,1fr); }
        .mini-card { background: #fff; border-radius: 12px; padding: 1rem 1.25rem; box-shadow: 0 2px 10px rgba(128,0,0,0.06); border-left: 4px solid #800000; }
        .mini-card.central { border-left-color: #1a56db; }
        .mini-card.campus  { border-left-color: #800000; }
        .mini-card.both    { border-left-color: #137333; }
        .mini-card.all     { border-left-color: #888; }
        .mini-label { font-size: 0.72rem; color: #999; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .mini-value { font-size: 1.5rem; font-weight: 700; color: #2d2d2d; line-height: 1.1; }
        .form-control:focus, .form-select:focus { border-color: #800000; box-shadow: 0 0 0 0.2rem rgba(128,0,0,0.12); }
        .scope-radio-label { display: flex; align-items: flex-start; gap: 10px; border: 1.5px solid #dee2e6; border-radius: 10px; padding: 12px; cursor: pointer; transition: all 0.15s; height: 100%; }
        .scope-radio-label:has(input:checked) { border-color: #800000; background: #fdf5f5; }

        @media (max-width: 900px) {
            .sidebar      { display: none !important; }
            .topbar       { display: none !important; }
            .main-content { display: none !important; }
            .desktop-only { display: none !important; }
            body { background: #f5f0f0; }
            #mobFlash { animation: flashFadeOut 0.4s ease 4s forwards; }
            @keyframes flashFadeOut {
                to { opacity: 0; pointer-events: none; height: 0; margin: 0; padding: 0; overflow: hidden; }
            }
            .mobile-topbar { background: #fff; border-bottom: 1px solid #e8dede; padding: .7rem 1rem; position: sticky; top: 0; z-index: 99; display: flex; align-items: center; justify-content: space-between; }
            .mobile-topbar .hamburger-btn { display: flex; background: none; border: none; cursor: pointer; padding: 4px 8px; color: #800000; font-size: 1.4rem; align-items: center; justify-content: center; line-height: 1; }
            .mobile-topbar .topbar-title { font-weight: 700; font-size: 1rem; color: #800000; flex: 1; }
            .mobile-topbar .topbar-user { display: flex; align-items: center; gap: 8px; }
            .mobile-topbar .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: #800000; color: #fff; display: flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; }
            .mob-sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 399; }
            .mob-sidebar-overlay.open { display: block; }
            .mob-sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: 260px; z-index: 400; background: linear-gradient(180deg, #800000, #6b0000); display: flex; flex-direction: column; transform: translateX(-100%); transition: transform .25s ease; overflow-y: auto; }
            .mob-sidebar.open { transform: translateX(0); }
            .mob-sidebar-brand { padding: 1.1rem 1rem; border-bottom: 1px solid rgba(255,255,255,.15); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
            .mob-logo-lg { width: 40px; height: 40px; border-radius: 50%; background: #fff; overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .mob-logo-lg img { width: 36px; height: 36px; object-fit: contain; }
            .mob-sidebar-nav { padding: .5rem 0; }
            .mob-nav-section { padding: .65rem 1.25rem .2rem; font-size: .65rem; font-weight: 700; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .08em; }
            .mob-nav-link { display: flex; align-items: center; gap: 10px; padding: .58rem 1.25rem; color: rgba(255,255,255,.8); font-size: .86rem; text-decoration: none; border-left: 3px solid transparent; transition: all .15s; }
            .mob-nav-link:hover { color: #fff; background: rgba(255,255,255,.1); border-left-color: rgba(255,255,255,.4); }
            .mob-nav-link.active { color: #fff; background: rgba(255,255,255,.15); border-left-color: #fff; font-weight: 600; }
            .mob-nav-link i { font-size: .95rem; width: 18px; }
            .mob-scroll { display: block; padding: 14px 14px 110px; }
            .office-banner { background: #fff8e1; border: 1px solid #ffe082; border-radius: 10px; padding: 9px 12px; font-size: .78rem; color: #6d4c00; display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
            .office-banner strong { color: #800000; }
            .mob-stat-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; margin-bottom: 14px; }
            .mob-stat-card { background: #fff; border-radius: 12px; padding: 12px 14px; border-left: 3px solid #800000; box-shadow: 0 1px 6px rgba(128,0,0,.06); }
            .mob-stat-card.central { border-left-color: #1a56db; }
            .mob-stat-card.campus  { border-left-color: #800000; }
            .mob-stat-card.both    { border-left-color: #137333; }
            .mob-stat-card.all     { border-left-color: #888; }
            .mob-stat-label { font-size: .63rem; color: #999; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px; }
            .mob-stat-value { font-size: 1.6rem; font-weight: 800; color: #1a1a1a; line-height: 1; }
            .mob-filter-row { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 14px; scrollbar-width: none; }
            .mob-filter-row::-webkit-scrollbar { display: none; }
            .mob-filter-pill { flex-shrink: 0; padding: 7px 15px; border-radius: 20px; font-size: .78rem; font-weight: 600; border: 1.5px solid #e0d0d0; color: #800000; background: #fff; cursor: pointer; white-space: nowrap; transition: all .15s; text-decoration: none; }
            .mob-filter-pill.active { background: #800000; color: #fff; border-color: #800000; }
            .mob-section-lbl { font-size: .72rem; font-weight: 700; color: #800000; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
            .driver-card { background: #fff; border-radius: 14px; padding: 14px; margin-bottom: 10px; box-shadow: 0 1px 6px rgba(128,0,0,.06); border: 1px solid #f5eded; }
            .dc-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; }
            .dc-avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg,#800000,#a00000); color: #fff; font-weight: 700; font-size: .95rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 10px; }
            .dc-info { flex: 1; min-width: 0; }
            .dc-name { font-weight: 700; font-size: .9rem; color: #1a1a1a; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .dc-license { font-size: .73rem; color: #888; font-family: monospace; }
            .dc-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 10px; }
            .dc-meta-item { background: #fdf8f8; border-radius: 8px; padding: 7px 9px; }
            .dc-meta-lbl { font-size: .62rem; color: #aaa; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
            .dc-meta-val { font-size: .78rem; color: #444; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .dc-meta-full { grid-column: 1/-1; }
            .dc-actions { display: flex; gap: 8px; padding-top: 10px; border-top: 1px solid #fdf0f0; }
            .dc-btn { flex: 1; padding: 9px 8px; border-radius: 9px; font-size: .78rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 5px; cursor: pointer; border: none; transition: all .15s; }
            .dc-btn-edit   { background: #f5f5f5; color: #444; }
            .dc-btn-edit:hover  { background: #ebebeb; }
            .dc-btn-delete { background: #fef2f2; color: #dc2626; }
            .dc-btn-delete:hover { background: #fee2e2; }
            .mob-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; margin-right: 4px; vertical-align: middle; }
            .mob-dot-green { background: #22c55e; }
            .mob-dot-red   { background: #ef4444; }
            .mob-dot-amber { background: #f59e0b; }
            .mob-fab { position: fixed; bottom: 24px; right: 20px; z-index: 150; width: 58px; height: 58px; background: #800000; color: #fff; border: none; border-radius: 50%; font-size: 1.6rem; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 16px rgba(128,0,0,.4); cursor: pointer; transition: transform .15s, background .15s; }
            .mob-fab:hover  { background: #6b0000; transform: scale(1.05); }
            .mob-fab:active { transform: scale(.95); }
            .mob-empty { text-align: center; padding: 40px 20px; color: #bbb; }
            .mob-empty i { font-size: 2.2rem; display: block; margin-bottom: 10px; opacity: .4; }
            .mob-empty p { font-size: .85rem; }
            .sheet-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 300; opacity: 0; pointer-events: none; transition: opacity .25s; }
            .sheet-backdrop.open { opacity: 1; pointer-events: all; }
            .mob-sheet { position: fixed; bottom: 0; left: 0; right: 0; z-index: 310; background: #fff; border-radius: 20px 20px 0 0; max-height: 92vh; overflow-y: auto; transform: translateY(105%); transition: transform .3s cubic-bezier(.4,0,.2,1); padding: 0 16px 48px; }
            .mob-sheet.open { transform: translateY(0); }
            .sheet-handle { width: 40px; height: 4px; background: #e0d0d0; border-radius: 2px; margin: 12px auto 16px; }
            .sheet-head { font-weight: 700; font-size: 1rem; color: #800000; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
            .sheet-form-group { margin-bottom: 13px; }
            .sheet-label { font-size: .72rem; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; display: block; }
            .sheet-input { width: 100%; padding: 11px 13px; border-radius: 10px; border: 1.5px solid #e0d0d0; font-size: .9rem; color: #333; background: #fff; outline: none; transition: border-color .15s, box-shadow .15s; -webkit-appearance: none; appearance: none; }
            .sheet-input:focus { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,.1); }
            .sheet-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
            .phone-row { display: flex; }
            .phone-prefix { background: #fdf5f5; border: 1.5px solid #e0d0d0; border-right: none; border-radius: 10px 0 0 10px; padding: 11px 12px; font-weight: 700; color: #800000; font-size: .85rem; white-space: nowrap; display: flex; align-items: center; }
            .phone-suffix { border-radius: 0 10px 10px 0 !important; border-left: none !important; }
            /* ── Scope option cards ── */
            .scope-opts { display: flex; flex-direction: column; gap: 8px; }
            .scope-opt {
                display: flex; align-items: center; gap: 10px;
                padding: 11px 12px; border: 1.5px solid #e0d0d0;
                border-radius: 10px; cursor: pointer; transition: all .15s;
            }
            /* Idle state for each variant */
            .scope-opt[data-scope="Central"] { border-color: #c3d0f5; background: #f7f9ff; }
            .scope-opt[data-scope="Campus"]  { border-color: #f0d0d0; background: #fff8f8; }
            .scope-opt[data-scope="RDE"]     { border-color: #d8c8f5; background: #faf7ff; }
            .scope-opt[data-scope="Both"]    { border-color: #b8dfc4; background: #f6fdf8; }
            /* Selected state */
            .scope-opt[data-scope="Central"].selected { border-color: #1a56db; background: #e8f0fe; }
            .scope-opt[data-scope="Campus"].selected  { border-color: #800000; background: #fdf5f5; }
            .scope-opt[data-scope="RDE"].selected     { border-color: #7c3aed; background: #f5f3ff; }
            .scope-opt[data-scope="Both"].selected    { border-color: #137333; background: #e6f4ea; }
            .scope-opt-radio { width: 16px; height: 16px; accent-color: #800000; flex-shrink: 0; }
            .scope-opt-lbl { font-size: .85rem; font-weight: 600; }
            .scope-opt-sub { font-size: .71rem; color: #aaa; }
            .sheet-actions { display: flex; gap: 10px; margin-top: 20px; }
            .btn-sheet-cancel { flex: 1; padding: 12px; border-radius: 11px; background: #f5f5f5; color: #555; font-weight: 600; font-size: .88rem; border: none; cursor: pointer; }
            .btn-sheet-save   { flex: 2; padding: 12px; border-radius: 11px; background: #800000; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
            .btn-sheet-save:active { background: #6b0000; }
            .confirm-sheet { padding-bottom: 48px; }
            .confirm-emoji { font-size: 2.6rem; text-align: center; margin-bottom: 8px; }
            .confirm-msg  { text-align: center; color: #555; font-size: .9rem; margin-bottom: 3px; }
            .confirm-name { text-align: center; font-size: 1.05rem; font-weight: 700; color: #c0392b; margin-bottom: 5px; }
            .confirm-sub  { text-align: center; font-size: .77rem; color: #aaa; margin-bottom: 18px; }
            .btn-sheet-del { flex: 2; padding: 12px; border-radius: 11px; background: #dc2626; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
        }

        @media (min-width: 901px) {
            .mobile-topbar      { display: none !important; }
            .mob-scroll         { display: none !important; }
            .mob-fab            { display: none !important; }
            .sheet-backdrop     { display: none !important; }
            .mob-sheet          { display: none !important; }
            .mob-sidebar        { display: none !important; }
            .mob-sidebar-overlay{ display: none !important; }
        }
    </style>
</head>
<body>

<div class="mob-sidebar-overlay" id="mobSidebarOverlay" onclick="toggleMobSidebar()"></div>
<div class="mob-sidebar" id="mobSidebar">
    <div class="mob-sidebar-brand">
        <div class="mob-logo-lg"><img src="../image/Csu.png" alt="CSU"></div>
        <div>
            <div style="font-weight:700;font-size:.88rem;color:#fff">CSU Vehicle System</div>
            <div style="font-size:.7rem;color:rgba(255,255,255,.6)">Admin Panel</div>
        </div>
    </div>
    <nav class="mob-sidebar-nav">
        <div class="mob-nav-section">Main</div>
        <a class="mob-nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <div class="mob-nav-section">Manage</div>
        <a class="mob-nav-link" href="Vehicles.php"><i class="bi bi-truck-front"></i>Vehicles</a>
        <a class="mob-nav-link" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i>Driver Trip Records</a>
        <a class="mob-nav-link active" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="mob-nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="mob-nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="mob-nav-link" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="mob-nav-section">Scheduling</div>
        <a class="mob-nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="mob-nav-section">Settings</div>
        <a class="mob-nav-link" href="notification.php" style="justify-content:space-between">
            <span style="display:flex;align-items:center;gap:10px"><i class="bi bi-bell"></i>Notifications</span>
            <?php if ($_sidebarUnread > 0): ?>
            <span style="background:#e24b4a;color:#fff;font-size:.62rem;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;">
                <?= $_sidebarUnread > 99 ? '99+' : $_sidebarUnread ?>
            </span>
            <?php endif; ?>
        </a>
        <a class="mob-nav-link" href="Signatories.php"><i class="bi bi-pen"></i>Signatories</a>
        <hr style="border-color:rgba(255,255,255,.15);margin:.5rem 1rem">
        <a class="mob-nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>
    </nav>
</div>

<div class="mobile-topbar">
    <button class="hamburger-btn" onclick="toggleMobSidebar()" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-person-badge me-2"></i>Drivers</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<div class="mob-scroll">
    <?php if (!empty($_mobFlash)):
        $f = $_mobFlash;
        $isSuccess   = $f['type'] === 'success';
        $bgColor     = $isSuccess ? '#d1e7dd' : '#f8d7da';
        $textColor   = $isSuccess ? '#0a3622' : '#58151c';
        $borderColor = $isSuccess ? '#a3cfbb' : '#f1aeb5';
        $accentColor = $isSuccess ? '#22c55e' : '#ef4444';
    ?>
    <div id="mobFlash" style="background:<?= $bgColor ?>;color:<?= $textColor ?>;border:1px solid <?= $borderColor ?>;border-left:4px solid <?= $accentColor ?>;border-radius:8px;padding:12px 40px 12px 14px;font-size:.84rem;margin-bottom:14px;position:relative;line-height:1.5;">
        <?= htmlspecialchars($f['message']) ?>
        <button onclick="document.getElementById('mobFlash').remove()" style="position:absolute;top:50%;right:12px;transform:translateY(-50%);background:none;border:none;font-size:1.1rem;color:<?= $textColor ?>;opacity:.6;cursor:pointer;line-height:1;padding:0;">&times;</button>
    </div>
    <?php endif; ?>

    <?php if (!$is_superadmin): ?>
    <div class="office-banner">
        <i class="bi bi-building" style="color:#b45309;font-size:1rem;"></i>
        Showing: <strong><?= htmlspecialchars($session_office_name ?: 'Your Office') ?></strong> + shared drivers
    </div>
    <?php endif; ?>

    <div class="mob-stat-grid">
        <div class="mob-stat-card all">
            <div class="mob-stat-label">Total</div>
            <div class="mob-stat-value"><?= $cnt_all ?></div>
        </div>
        <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
        <div class="mob-stat-card campus">
            <div class="mob-stat-label">Campus Only</div>
            <div class="mob-stat-value"><?= $cnt_campus ?></div>
        </div>
        <?php endif; ?>
        <?php if ($is_superadmin || $user_scope === 'Central'): ?>
        <div class="mob-stat-card central">
            <div class="mob-stat-label">Central Only</div>
            <div class="mob-stat-value"><?= $cnt_central ?></div>
        </div>
        <?php endif; ?>
        <div class="mob-stat-card both">
            <div class="mob-stat-label">Shared (Both)</div>
            <div class="mob-stat-value"><?= $cnt_both ?></div>
        </div>
    </div>

    <div class="mob-filter-row">
        <a href="?scope=all"     class="mob-filter-pill <?= $filter==='all'     ? 'active':'' ?>">All (<?= $cnt_all ?>)</a>
        <?php if ($is_superadmin || $user_scope === 'Central'): ?>
        <a href="?scope=central" class="mob-filter-pill <?= $filter==='central' ? 'active':'' ?>">Central (<?= $cnt_central ?>)</a>
        <?php endif; ?>
        <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
        <a href="?scope=campus"  class="mob-filter-pill <?= $filter==='campus'  ? 'active':'' ?>">Campus (<?= $cnt_campus ?>)</a>
        <?php endif; ?>
        <a href="?scope=both"    class="mob-filter-pill <?= $filter==='both'    ? 'active':'' ?>">Shared (<?= $cnt_both ?>)</a>
    </div>

    <div class="mob-section-lbl">
        <i class="bi bi-person-badge"></i>
        <?php
        if ($filter === 'central')    echo 'Central Office Drivers';
        elseif ($filter === 'campus') echo 'Campus Office Drivers';
        elseif ($filter === 'both')   echo 'Shared Drivers';
        else                          echo 'All Drivers';
        ?>
    </div>

    <?php if (empty($drivers)): ?>
    <div class="mob-empty">
        <i class="bi bi-person-x"></i>
        <p>No drivers found.</p>
    </div>
    <?php else: ?>
    <?php foreach ($drivers as $d):
        $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $d['driver_name']), 0, 2)));
        $sc = $d['driver_scope'];
        $st = $d['status'];
        $dot_class = ($st==='Available'||$st==='Active') ? 'mob-dot-green' : ($st==='Archived' ? 'mob-dot-amber' : 'mob-dot-red');
       if ($sc==='Central')       $scope_html = '<span class="scope-central"><i class="bi bi-building"></i>Central</span>';
elseif ($sc==='Campus')    $scope_html = '<span class="scope-campus"><i class="bi bi-building"></i>Campus</span>';
elseif ($sc==='RDE')       $scope_html = '<span style="background:#f5f3ff;color:#7c3aed;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;display:inline-flex;align-items:center;gap:3px;"><i class="bi bi-building"></i>RDE</span>';
elseif ($sc==='Both')      $scope_html = '<span class="scope-both"><i class="bi bi-buildings"></i>Both</span>';
else                       $scope_html = '<span style="background:#f5f5f5;color:#888;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;">'.htmlspecialchars($sc).'</span>';
    ?>
    <div class="driver-card">
        <div class="dc-header">
            <div style="display:flex;align-items:center;flex:1;min-width:0">
                <div class="dc-avatar"><?= htmlspecialchars($initials) ?></div>
                <div class="dc-info">
                    <div class="dc-name"><?= htmlspecialchars($d['driver_name']) ?></div>
                    <div class="dc-license"><?= htmlspecialchars($d['license_no'] ?? '—') ?></div>
                </div>
            </div>
            <div style="margin-left:8px;flex-shrink:0"><?= $scope_html ?></div>
        </div>
        <div class="dc-meta">
            <div class="dc-meta-item">
                <div class="dc-meta-lbl">Phone</div>
                <div class="dc-meta-val"><?= htmlspecialchars($d['phone_no'] ?? '—') ?></div>
            </div>
            <div class="dc-meta-item">
                <div class="dc-meta-lbl">Office</div>
                <div class="dc-meta-val"><?= htmlspecialchars($d['office_name'] ?? '—') ?></div>
            </div>
            <div class="dc-meta-item dc-meta-full">
                <div class="dc-meta-lbl">Email</div>
                <div class="dc-meta-val"><?= htmlspecialchars($d['email'] ?? '—') ?></div>
            </div>
            <div class="dc-meta-item dc-meta-full">
                <div class="dc-meta-lbl">Status</div>
                <div class="dc-meta-val">
                    <span class="mob-dot <?= $dot_class ?>"></span>
                    <?= htmlspecialchars($st ?? '—') ?>
                </div>
            </div>
        </div>
        <div class="dc-actions">
            <button class="dc-btn dc-btn-edit" onclick="mobOpenEdit(<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>)">
                <i class="bi bi-pencil" style="font-size:.8rem"></i> Edit
            </button>
            <button class="dc-btn dc-btn-delete" onclick="mobOpenDelete(<?= $d['driver_id'] ?>, '<?= htmlspecialchars($d['driver_name'], ENT_QUOTES) ?>')">
                <i class="bi bi-trash" style="font-size:.8rem"></i> Delete
            </button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<button class="mob-fab" onclick="mobOpenAdd()" title="Add Driver">
    <i class="bi bi-plus-lg"></i>
</button>

<div class="sheet-backdrop" id="mobBackdrop" onclick="mobCloseAll()"></div>

<!-- ════ MOBILE ADD/EDIT SHEET ════ -->
<div class="mob-sheet" id="mobFormSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head" id="mobSheetTitle"><i class="bi bi-person-plus"></i> Add Driver</div>
    <form method="POST" id="mobDriverForm">
        <input type="hidden" name="action" id="mob_action" value="add">
        <input type="hidden" name="driver_id" id="mob_driver_id">
        <div class="sheet-form-group">
            <label class="sheet-label">Full Name <span style="color:#dc2626">*</span></label>
            <input type="text" name="driver_name" id="mob_name" class="sheet-input" placeholder="Enter full name" required>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">License No. <span style="color:#dc2626">*</span></label>
            <input type="text" name="license_no" id="mob_license" class="sheet-input" placeholder="e.g. N01-12-345678" required
                oninput="this.value=this.value.replace(/[^A-Za-z0-9\-]/g,'').toUpperCase()" style="font-family:monospace;text-transform:uppercase">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Phone No.</label>
            <div class="phone-row">
                <span class="phone-prefix">+63</span>
                <input type="tel" name="phone_no" id="mob_phone" class="sheet-input phone-suffix" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)">
            </div>
            <div style="font-size:.65rem;color:#bbb;margin-top:4px">11 digits · starts with 09</div>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Office Assignment</label>
            <?php if ($is_superadmin): ?>
            <select name="office_id" id="mob_office" class="sheet-input sheet-select">
                <option value="">— None —</option>
                <?php foreach ($offices as $o): ?>
                <option value="<?= $o['office_id'] ?>"><?= htmlspecialchars($o['office_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="hidden" name="office_id" value="<?= $session_office_id ?>">
            <div style="background:#fdf5f5;border:1px solid #f0e5e5;border-radius:10px;padding:.6rem .9rem;font-size:.85rem;color:#800000;font-weight:600;display:flex;align-items:center;gap:6px;">
                <i class="bi bi-building-fill"></i>
                <?= htmlspecialchars($session_office_name ?: 'Your Office') ?>
                <small style="margin-left:auto;font-weight:400;color:#999;font-size:.7rem"><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Driver Scope: each option has data-scope for targeted CSS ── -->
        <div class="sheet-form-group">
            <label class="sheet-label">Driver Scope <span style="color:#dc2626">*</span></label>
            <div class="scope-opts">
                <?php if ($is_superadmin || $user_scope === 'Central'): ?>
                <label class="scope-opt" data-scope="Central">
                    <input type="radio" name="driver_scope" value="Central" class="scope-opt-radio" onchange="mobScopeStyle()">
                    <div>
                        <div class="scope-opt-lbl" style="color:#1a56db">Central Only</div>
                        <div class="scope-opt-sub">Exclusive to Central Office</div>
                    </div>
                </label>
                <?php endif; ?>

                <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
                <label class="scope-opt" data-scope="Campus">
                    <input type="radio" name="driver_scope" value="Campus" class="scope-opt-radio" onchange="mobScopeStyle()">
                    <div>
                        <div class="scope-opt-lbl" style="color:#800000">Campus Only</div>
                        <div class="scope-opt-sub">Exclusive to Campus Office</div>
                    </div>
                </label>
                <?php endif; ?>

                <?php if ($is_superadmin || $user_scope === 'RDE'): ?>
                <label class="scope-opt" data-scope="RDE">
                    <input type="radio" name="driver_scope" value="RDE" class="scope-opt-radio" onchange="mobScopeStyle()">
                    <div>
                        <div class="scope-opt-lbl" style="color:#7c3aed">RDE Only</div>
                        <div class="scope-opt-sub">Exclusive to RDE Office</div>
                    </div>
                </label>
                <?php endif; ?>

                <label class="scope-opt" data-scope="Both">
                    <input type="radio" name="driver_scope" value="Both" class="scope-opt-radio" onchange="mobScopeStyle()">
                    <div>
                        <div class="scope-opt-lbl" style="color:#137333">Both Offices</div>
                        <div class="scope-opt-sub">Shared between offices</div>
                    </div>
                </label>
            </div>
        </div>
<div style="border-top:1px solid #f0e5e5;margin:4px 0 14px"></div>
        <div style="font-size:.68rem;font-weight:700;color:#800000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">
           <i class="bi bi-person-lock me-1"></i>Driver Login Account
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Email Address</label>
          <input type="email" name="drv_email" id="mob_drv_email" class="sheet-input" placeholder="driver@email.com" autocomplete="off" required>
         <div style="font-size:.63rem;color:#bbb;margin-top:4px" id="mob_email_hint">Used to log in to the driver portal.</div>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Password <span style="color:#dc2626">*</span></label>
            <div style="display:flex;">
                <input type="password" name="drv_password" id="mob_drv_password" class="sheet-input" style="border-radius:10px 0 0 10px;border-right:none" placeholder="Set a password" autocomplete="new-password" required>
                <button type="button" onclick="togglePw('mob_drv_password','mob_pw_eye')" style="background:#fdf5f5;border:1.5px solid #e0d0d0;border-left:none;border-radius:0 10px 10px 0;padding:0 13px;cursor:pointer;color:#800000;font-size:1rem;display:flex;align-items:center;">
                    <i class="bi bi-eye" id="mob_pw_eye"></i>
                </button>
            </div>
            <div style="font-size:.63rem;color:#bbb;margin-top:4px">Must be at least 6 characters.</div>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Status</label>
            <select name="status" id="mob_status" class="sheet-input sheet-select">
                <option value="Active">Active</option>
                <option value="Available">Available</option>
                <option value="Unavailable">Unavailable</option>
                <option value="Archived">Archived</option>
            </select>
        </div>
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-save"><i class="bi bi-check-lg me-1"></i>Save Driver</button>
        </div>
    </form>
</div>

<!-- ════ MOBILE DELETE CONFIRM SHEET ════ -->
<div class="mob-sheet confirm-sheet" id="mobDeleteSheet">
    <div class="sheet-handle"></div>
    <div class="confirm-emoji">⚠️</div>
    <div class="confirm-msg">Delete driver</div>
    <div class="confirm-name" id="mobDeleteName">—</div>
    <div class="confirm-sub">This cannot be undone.</div>
    <form method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="driver_id" id="mobDeleteId">
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-del"><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
    </form>
</div>

<!-- ════════ DESKTOP SIDEBAR ════════ -->
<div class="sidebar desktop-only" id="mainSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo"><img src="../image/Csu.png" alt="CSU Logo"></div>
        <div class="sidebar-brand-text">CSU Vehicle System<span>Admin Panel</span></div>
    </div>
    <nav class="nav flex-column mt-2">
        <div class="nav-section-label">Main</div>
        <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <div class="nav-section-label">Manage</div>
        <a class="nav-link" href="Vehicles.php"><i class="bi bi-truck-front"></i>Vehicles</a>
        <a class="nav-link" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i>Driver Trip Records</a>
        <a class="nav-link active" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link" href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="nav-link" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="nav-section-label">Scheduling</div>
        <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="nav-section-label">Settings</div>
        <a class="nav-link" href="notification.php" style="justify-content:space-between">
            <span style="display:flex;align-items:center;gap:10px"><i class="bi bi-bell"></i>Notifications</span>
            <?php if ($_sidebarUnread > 0): ?>
            <span style="background:#e24b4a;color:#fff;font-size:.62rem;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;">
                <?= $_sidebarUnread > 99 ? '99+' : $_sidebarUnread ?>
            </span>
            <?php endif; ?>
        </a>
        <a class="nav-link" href="Signatories.php"><i class="bi bi-pen"></i>Signatories</a>
        <hr class="sidebar-divider">
        <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>
    </nav>
</div>

<!-- ════════ DESKTOP TOPBAR ════════ -->
<div class="topbar desktop-only">
    <div class="topbar-title"><i class="bi bi-person-badge me-2"></i>Drivers</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:0.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:0.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<!-- ════════ DESKTOP MAIN ════════ -->
<div class="main-content desktop-only">
    <?= $_flashHTML ?>

    <?php if (!$is_superadmin): ?>
    <div class="alert d-flex align-items-center gap-2 mb-3" style="background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:0.75rem 1.1rem;font-size:0.85rem;">
        <i class="bi bi-building me-1" style="color:#b45309;font-size:1rem;"></i>
        <span>Showing drivers for: <strong style="color:#800000"><?= htmlspecialchars($session_office_name ?: 'Your Office') ?></strong> + shared drivers (Both Offices)</span>
    </div>
    <?php endif; ?>

    <div class="mini-stats <?= $is_superadmin ? 'mini-stats-4' : 'mini-stats-3' ?>">
        <div class="mini-card all"><div class="mini-label">Total Drivers</div><div class="mini-value"><?= $cnt_all ?></div></div>
        <?php if ($is_superadmin || $user_scope === 'Central'): ?>
        <div class="mini-card central"><div class="mini-label">Central Only</div><div class="mini-value"><?= $cnt_central ?></div></div>
        <?php endif; ?>
        <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
        <div class="mini-card campus"><div class="mini-label">Campus Only</div><div class="mini-value"><?= $cnt_campus ?></div></div>
        <?php endif; ?>
        <div class="mini-card both"><div class="mini-label">Shared (Both)</div><div class="mini-value"><?= $cnt_both ?></div></div>
    </div>

    <div class="filter-tabs">
        <a href="?scope=all"     class="filter-tab <?= $filter==='all'     ? 'active':'' ?>">All <span class="count">(<?= $cnt_all ?>)</span></a>
        <?php if ($is_superadmin || $user_scope === 'Central'): ?>
        <a href="?scope=central" class="filter-tab <?= $filter==='central' ? 'active':'' ?>">Central Only <span class="count">(<?= $cnt_central ?>)</span></a>
        <?php endif; ?>
        <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
        <a href="?scope=campus"  class="filter-tab <?= $filter==='campus'  ? 'active':'' ?>">Campus Only <span class="count">(<?= $cnt_campus ?>)</span></a>
        <?php endif; ?>
        <a href="?scope=both"    class="filter-tab <?= $filter==='both'    ? 'active':'' ?>">Shared (Both) <span class="count">(<?= $cnt_both ?>)</span></a>
    </div>

    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-person-badge me-2"></i>
                <?php
                if ($filter === 'central')    echo 'Central Office Drivers';
                elseif ($filter === 'campus') echo 'Campus Office Drivers';
                elseif ($filter === 'both')   echo 'Shared Drivers (Both Offices)';
                else                          echo 'All Drivers';
                ?>
            </span>
            <button class="btn btn-maroon btn-sm rounded-3" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg me-1"></i>Add Driver
            </button>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th class="col-id">#</th>
                        <th class="col-driver">Driver</th>
                        <th class="col-license">License No.</th>
                        <th class="col-phone">Phone</th>
                        <th class="col-scope">Scope</th>
                        <th class="col-office">Office</th>
                       <th class="col-status">Status</th>
                        <th style="width:12%">Email</th>
                        <th style="width:100px;white-space:nowrap">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drivers as $d): ?>
                <tr>
                    <td><?= $d['driver_id'] ?></td>
                    <td><strong><?= htmlspecialchars($d['driver_name']) ?></strong></td>
                    <td><?= htmlspecialchars($d['license_no'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($d['phone_no'] ?? '—') ?></td>
                    <td>
                        <?php $sc = $d['driver_scope'];
                        if ($sc === 'Central')    echo '<span class="scope-central"><i class="bi bi-building"></i> Central Only</span>';
                        elseif ($sc === 'Campus') echo '<span class="scope-campus"><i class="bi bi-building"></i> Campus Only</span>';
                        elseif ($sc === 'RDE')    echo '<span style="background:#f5f3ff;color:#7c3aed;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px"><i class="bi bi-building"></i> RDE Only</span>';
                        elseif ($sc === 'Both')   echo '<span class="scope-both"><i class="bi bi-buildings"></i> Both Offices</span>';
                        else echo htmlspecialchars($sc ?? '—'); ?>
                    </td>
                    <td><?= htmlspecialchars($d['office_name'] ?? '—') ?></td>
                    <td style="font-size:0.78rem;color:#666;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($d['email'] ?? '—') ?></td>
                    <td>
                        <?php $st = $d['status'];
                        if ($st === 'Available' || $st === 'Active') echo '<span class="badge-available">'.htmlspecialchars($st).'</span>';
                        elseif ($st === 'Archived') echo '<span class="badge-maintenance">Archived</span>';
                        else echo '<span class="badge-unavailable">'.htmlspecialchars($st ?? '—').'</span>'; ?>
                    </td>
                    <td style="white-space:nowrap;min-width:90px">
    <button class="btn btn-sm btn-outline-secondary me-1" 
        title="Edit"
        onclick="openEdit(<?= htmlspecialchars(json_encode($d), ENT_QUOTES) ?>)">
        <i class="bi bi-pencil"></i>
    </button>
    <button class="btn btn-sm btn-outline-danger" 
        title="Delete"
        onclick="openDelete(<?= $d['driver_id'] ?>, '<?= htmlspecialchars($d['driver_name'], ENT_QUOTES) ?>')">
        <i class="bi bi-trash"></i>
    </button>
</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($drivers)): ?>
              <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-person-x fs-4 d-block mb-2 opacity-50"></i>No drivers found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ════════ DESKTOP ADD MODAL ════════ -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Driver</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="driver_name" class="form-control" required placeholder="Enter full name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">License No. <span class="text-danger">*</span></label>
                            <input type="text" name="license_no" class="form-control" placeholder="e.g. N01-12-345678" required oninput="this.value=this.value.replace(/[^A-Za-z0-9\-]/g,'').toUpperCase()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone No.</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#fff;border-color:#dee2e6;color:#666"><i class="bi bi-phone"></i></span>
                                <input type="text" name="phone_no" class="form-control" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Office Assignment</label>
                            <?php if ($is_superadmin): ?>
                            <select name="office_id" class="form-select">
                                <option value="">— None —</option>
                                <?php foreach ($offices as $o): ?>
                                <option value="<?= $o['office_id'] ?>"><?= htmlspecialchars($o['office_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="hidden" name="office_id" value="<?= $session_office_id ?>">
                            <div style="background:#fdf5f5;border:1px solid #f0e5e5;border-radius:8px;padding:0.5rem 0.85rem;font-size:0.85rem;color:#800000;font-weight:600;display:flex;align-items:center;gap:6px;">
                                <i class="bi bi-building-fill"></i><?= htmlspecialchars($session_office_name ?: 'Your Office') ?>
                                <small style="margin-left:auto;font-weight:400;color:#999;font-size:0.72rem"><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Driver Scope <span class="text-danger">*</span></label>
                            <div class="row g-2 mt-1">
                                <?php if ($is_superadmin || $user_scope === 'Central'): ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" value="Central" class="mt-1" required>
                                        <div><div class="fw-semibold" style="color:#1a56db">Central Only</div><div class="text-muted" style="font-size:0.78rem">Exclusive to Central Office</div></div>
                                    </label>
                                </div>
                                <?php endif; ?>
                                <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" value="Campus" class="mt-1">
                                        <div><div class="fw-semibold" style="color:#800000">Campus Only</div><div class="text-muted" style="font-size:0.78rem">Exclusive to Campus Office</div></div>
                                    </label>
                                </div>
                                <?php endif; ?>
                                <?php if ($is_superadmin || $user_scope === 'RDE'): ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" value="RDE" class="mt-1">
                                        <div><div class="fw-semibold" style="color:#7c3aed">RDE Only</div><div class="text-muted" style="font-size:0.78rem">Exclusive to RDE Office</div></div>
                                    </label>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" value="Both" class="mt-1">
                                        <div><div class="fw-semibold" style="color:#137333">Both Offices</div><div class="text-muted" style="font-size:0.78rem">Shared between offices</div></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12"><hr style="border-color:#f0e5e5;margin:.25rem 0 .5rem"></div>
                        <div class="col-12">
                            <div style="font-size:.78rem;font-weight:700;color:#800000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                               <i class="bi bi-person-lock me-1"></i>Driver Login Account
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#fff;border-color:#dee2e6;color:#666"><i class="bi bi-envelope"></i></span>
                              <input type="email" name="drv_email" class="form-control" placeholder="driver@email.com" required>
                            </div>
                          <div class="form-text text-muted" style="font-size:.72rem">Used to log in to the driver portal.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#fff;border-color:#dee2e6;color:#666"><i class="bi bi-lock"></i></span>
                               <input type="password" name="drv_password" id="desk_drv_password" class="form-control" placeholder="Set a password" required>
                                <button type="button" class="btn btn-outline-secondary" style="border-color:#dee2e6" onclick="togglePw('desk_drv_password','desk_pw_eye')"><i class="bi bi-eye" id="desk_pw_eye"></i></button>
                            </div>
                           <div class="form-text text-muted" style="font-size:.72rem">Must be at least 6 characters.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Available">Available</option>
                                <option value="Unavailable">Unavailable</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-light btn-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon btn-sm rounded-3"><i class="bi bi-check-lg me-1"></i>Add Driver</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════ DESKTOP EDIT MODAL ════════ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Driver</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="driver_id" id="edit_driver_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="driver_name" id="edit_driver_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">License No. <span class="text-danger">*</span></label>
                            <input type="text" name="license_no" id="edit_license_no" class="form-control" required oninput="this.value=this.value.replace(/[^A-Za-z0-9\-]/g,'').toUpperCase()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone No.</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#fff;border-color:#dee2e6;color:#666"><i class="bi bi-phone"></i></span>
                                <input type="text" name="phone_no" id="edit_phone_no" class="form-control" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Office Assignment</label>
                            <?php if ($is_superadmin): ?>
                            <select name="office_id" id="edit_office_id" class="form-select">
                                <option value="">— None —</option>
                                <?php foreach ($offices as $o): ?>
                                <option value="<?= $o['office_id'] ?>"><?= htmlspecialchars($o['office_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="hidden" name="office_id" value="<?= $session_office_id ?>">
                            <div style="background:#fdf5f5;border:1px solid #f0e5e5;border-radius:8px;padding:0.5rem 0.85rem;font-size:0.85rem;color:#800000;font-weight:600;display:flex;align-items:center;gap:6px;">
                                <i class="bi bi-building-fill"></i><?= htmlspecialchars($session_office_name ?: 'Your Office') ?>
                                <small style="margin-left:auto;font-weight:400;color:#999;font-size:0.72rem"><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Driver Scope <span class="text-danger">*</span></label>
                            <div class="row g-2 mt-1">
                                <?php if ($is_superadmin || $user_scope === 'Central'): ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" id="edit_scope_central" value="Central" class="mt-1">
                                        <div><div class="fw-semibold" style="color:#1a56db">Central Only</div><div class="text-muted" style="font-size:0.78rem">Exclusive to Central Office</div></div>
                                    </label>
                                </div>
                                <?php endif; ?>
                                <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" id="edit_scope_campus" value="Campus" class="mt-1">
                                        <div><div class="fw-semibold" style="color:#800000">Campus Only</div><div class="text-muted" style="font-size:0.78rem">Exclusive to Campus Office</div></div>
                                    </label>
                                </div>
                                <?php endif; ?>
                                <?php if ($is_superadmin || $user_scope === 'RDE'): ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" id="edit_scope_rde" value="RDE" class="mt-1">
                                        <div><div class="fw-semibold" style="color:#7c3aed">RDE Only</div><div class="text-muted" style="font-size:0.78rem">Exclusive to RDE Office</div></div>
                                    </label>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="driver_scope" id="edit_scope_both" value="Both" class="mt-1">
                                        <div><div class="fw-semibold" style="color:#137333">Both Offices</div><div class="text-muted" style="font-size:0.78rem">Shared between offices</div></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                       <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Available">Available</option>
                                <option value="Unavailable">Unavailable</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                        <div class="col-12"><hr style="border-color:#f0e5e5;margin:.25rem 0 .5rem"></div>
                        <div class="col-12">
                            <div style="font-size:.78rem;font-weight:700;color:#800000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                               <i class="bi bi-person-lock me-1"></i>Driver Login Account
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#fff;border-color:#dee2e6;color:#666"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="drv_email" id="edit_drv_email" class="form-control" placeholder="driver@email.com">
                            </div>
                            <div class="form-text text-muted" style="font-size:.72rem">Leave blank to keep existing email.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">New Password</label>
                            <div class="input-group">
                                <span class="input-group-text" style="background:#fff;border-color:#dee2e6;color:#666"><i class="bi bi-lock"></i></span>
                                <input type="password" name="drv_password" id="edit_drv_password" class="form-control" placeholder="Leave blank to keep current">
                                <button type="button" class="btn btn-outline-secondary" style="border-color:#dee2e6" onclick="togglePw('edit_drv_password','edit_pw_eye')"><i class="bi bi-eye" id="edit_pw_eye"></i></button>
                            </div>
                            <div class="form-text text-muted" style="font-size:.72rem">At least 6 characters if changing.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-light btn-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon btn-sm rounded-3"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════ DESKTOP DELETE MODAL ════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content" style="border-radius:14px;border:none;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#c0392b,#a93226);padding:1rem 1.25rem">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-trash me-2"></i>Delete Driver</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
            </div>
            <div class="modal-body text-center py-4 px-4">
                <div style="font-size:2.8rem;margin-bottom:.75rem">⚠️</div>
                <p class="mb-1" style="color:#555;font-size:.95rem">Delete driver</p>
                <p class="fw-bold fs-5 mb-2" style="color:#c0392b" id="deleteDriverName">—</p>
                <p class="text-muted" style="font-size:.85rem">This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4 gap-2">
                <button type="button" class="btn btn-secondary btn-sm px-4 rounded-3" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="driver_id" id="deleteDriverId">
                    <button type="submit" class="btn btn-danger btn-sm px-4 rounded-3"><i class="bi bi-trash me-1"></i>Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleMobSidebar() {
    document.getElementById('mobSidebar').classList.toggle('open');
    document.getElementById('mobSidebarOverlay').classList.toggle('open');
}

function openDelete(id, name) {
    document.getElementById('deleteDriverId').value = id;
    document.getElementById('deleteDriverName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function normalizePhone(raw) {
    if (!raw) return '';
    var digits = String(raw).replace(/\D/g, '');
    if (digits.startsWith('63') && digits.length === 12) digits = '0' + digits.slice(2);
    return digits;
}

function openEdit(d) {
    document.getElementById('edit_driver_id').value   = d.driver_id;
    document.getElementById('edit_driver_name').value = d.driver_name;
    document.getElementById('edit_license_no').value  = d.license_no ?? '';
    document.getElementById('edit_phone_no').value    = normalizePhone(d.phone_no);
    const officeEl = document.getElementById('edit_office_id');
    if (officeEl) officeEl.value = d.office_id ?? '';
    document.getElementById('edit_status').value = d.status ?? 'Active';
    var editEmail = document.getElementById('edit_drv_email');
    if (editEmail) editEmail.value = d.email ?? '';
    var editPw = document.getElementById('edit_drv_password');
    if (editPw) editPw.value = '';
    document.querySelectorAll('#editModal input[name=driver_scope]').forEach(r => r.checked = false);
    const match = document.querySelector('#editModal input[name=driver_scope][value="' + (d.driver_scope ?? '') + '"]');
    if (match) match.checked = true;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function mobOpenSheet(id) {
    document.getElementById('mobBackdrop').classList.add('open');
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function mobCloseAll() {
    document.getElementById('mobBackdrop').classList.remove('open');
    document.getElementById('mobFormSheet').classList.remove('open');
    document.getElementById('mobDeleteSheet').classList.remove('open');
    document.body.style.overflow = '';
}

/*
 * mobScopeStyle — uses data-scope attribute for precise per-option highlighting.
 * Adds/removes the "selected" class; each variant's selected style is in CSS.
 */
function mobScopeStyle() {
    document.querySelectorAll('#mobFormSheet .scope-opt').forEach(function(label) {
        var radio = label.querySelector('input[type=radio]');
        if (radio && radio.checked) {
            label.classList.add('selected');
        } else {
            label.classList.remove('selected');
        }
    });
}

function mobOpenAdd() {
    document.getElementById('mobSheetTitle').innerHTML = '<i class="bi bi-person-plus"></i> Add Driver';
    document.getElementById('mob_action').value    = 'add';
    document.getElementById('mob_driver_id').value = '';
    document.getElementById('mob_name').value      = '';
    document.getElementById('mob_license').value   = '';
    document.getElementById('mob_phone').value     = '';
    var offEl = document.getElementById('mob_office');
    if (offEl) offEl.value = '';
    document.getElementById('mob_status').value = 'Active';
    var emailEl = document.getElementById('mob_drv_email');
    var passEl  = document.getElementById('mob_drv_password');
    if (emailEl) emailEl.value = '';
    if (passEl)  passEl.value  = '';
    document.querySelectorAll('#mobFormSheet input[name=driver_scope]').forEach(r => r.checked = false);
    mobScopeStyle();
    mobOpenSheet('mobFormSheet');
}

function mobOpenEdit(d) {
    document.getElementById('mobSheetTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Driver';
    document.getElementById('mob_action').value    = 'edit';
    document.getElementById('mob_driver_id').value = d.driver_id;
    document.getElementById('mob_name').value      = d.driver_name;
    document.getElementById('mob_license').value   = d.license_no ?? '';
    document.getElementById('mob_phone').value     = normalizePhone(d.phone_no);
    var offEl = document.getElementById('mob_office');
    if (offEl) offEl.value = d.office_id ?? '';
    document.getElementById('mob_status').value = d.status ?? 'Active';
    var emailEl = document.getElementById('mob_drv_email');
    var passEl  = document.getElementById('mob_drv_password');
    if (emailEl) emailEl.value = d.email ?? '';
    if (passEl)  passEl.value  = '';  // always blank for security
    document.querySelectorAll('#mobFormSheet input[name=driver_scope]').forEach(function(r) {
        r.checked = r.value === (d.driver_scope ?? '');
    });
    mobScopeStyle();
    mobOpenSheet('mobFormSheet');
}

function mobOpenDelete(id, name) {
    document.getElementById('mobDeleteId').value = id;
    document.getElementById('mobDeleteName').textContent = name;
    mobOpenSheet('mobDeleteSheet');
}
function togglePw(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
    } else {
        inp.type = 'password';
        if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
    }
}
</script>
<?php include '../includes/mobile_js.php'; ?>
</body>
</html>