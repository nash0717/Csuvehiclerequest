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
// Capture flash once for reuse in both mobile and desktop
ob_start();
showFlash();
$_flashHTML = ob_get_clean();

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $plate_number  = sanitize($_POST['plate_number']);
        $brand         = sanitize($_POST['brand']);
        $model         = sanitize($_POST['model']);
        $capacity      = (int)$_POST['capacity'];
        $office_id     = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : null;
        $vehicle_scope = sanitize($_POST['vehicle_scope']);
        $status        = sanitize($_POST['status']);
        $stmt = $pdo->prepare("INSERT INTO vehicles (plate_number, brand, model, capacity, office_id, vehicle_scope, status) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$plate_number, $brand, $model, $capacity, $office_id, $vehicle_scope, $status]);
        setFlash('success', 'Vehicle added successfully.');

    } elseif ($action === 'edit') {
        $vehicle_id    = (int)$_POST['vehicle_id'];
        $plate_number  = sanitize($_POST['plate_number']);
        $brand         = sanitize($_POST['brand']);
        $model         = sanitize($_POST['model']);
        $capacity      = (int)$_POST['capacity'];
        $office_id     = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : null;
        $vehicle_scope = sanitize($_POST['vehicle_scope']);
        $status        = sanitize($_POST['status']);
        $stmt = $pdo->prepare("UPDATE vehicles SET plate_number=?, brand=?, model=?, capacity=?, office_id=?, vehicle_scope=?, status=? WHERE vehicle_id=?");
        $stmt->execute([$plate_number, $brand, $model, $capacity, $office_id, $vehicle_scope, $status, $vehicle_id]);
        setFlash('success', 'Vehicle updated successfully.');

   } elseif ($action === 'delete') {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $inUse = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE vehicle_id = ?");
    $inUse->execute([$vehicle_id]);
    if ($inUse->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete this vehicle because it is assigned to existing schedules.');
    } else {
        try {
            $pdo->prepare("DELETE FROM vehicles WHERE vehicle_id = ?")->execute([$vehicle_id]);
            setFlash('success', 'Vehicle deleted successfully.');
        } catch (\PDOException $e) {
            setFlash('danger', 'An unexpected error occurred: ' . $e->getMessage());
        }
    }
}

    header("Location: Vehicles.php");
    exit;
}

// ── Determine logged-in user's office ──────────────────────────────────────
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

// All non-superadmins see all scope options — scope = office's vehicle scope, not user detection
$user_scope = 'All'; // always show all choices
// ── Filters ────────────────────────────────────────────────────────────────
$filter = $_GET['scope'] ?? 'all';

if ($is_superadmin) {
    if ($filter === 'mine')  $where = "WHERE v.vehicle_scope = 'Both'";
    else                     $where = '';
} else {
    $oid  = (int)$session_office_id;
    $base = "(v.vehicle_scope = 'office_$oid' OR v.vehicle_scope = 'Both')";
    if ($filter === 'mine')  $where = "WHERE v.vehicle_scope = 'office_$oid'";
    elseif ($filter === 'both') $where = "WHERE v.vehicle_scope = 'Both'";
    else                        $where = "WHERE $base";
}

$vehicles = $pdo->query("
    SELECT v.*, o.office_name
    FROM vehicles v
    LEFT JOIN offices o ON v.office_id = o.office_id
    $where
    ORDER BY v.vehicle_id DESC
")->fetchAll();

$offices = $pdo->query("SELECT * FROM offices ORDER BY office_name")->fetchAll();

// ── Counts ─────────────────────────────────────────────────────────────────
if ($is_superadmin) {
    $cnt_central = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE vehicle_scope='Central'")->fetchColumn();
    $cnt_campus  = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE vehicle_scope='Campus'")->fetchColumn();
    $cnt_both    = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE vehicle_scope='Both'")->fetchColumn();
    $cnt_all     = $pdo->query("SELECT COUNT(*) FROM vehicles")->fetchColumn();
} else {
    $oid = (int)$session_office_id;
    if ($user_scope === 'Central') {
        $cnt_central = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE vehicle_scope='Central' AND office_id=$oid")->fetchColumn();
        $cnt_campus  = 0;
    } else {
        $cnt_campus  = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE vehicle_scope='Campus' AND office_id=$oid")->fetchColumn();
        $cnt_central = 0;
    }
    $cnt_both = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE vehicle_scope='Both'")->fetchColumn();
    $cnt_all  = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE office_id=$oid OR vehicle_scope='Both'")->fetchColumn();
}

// ── Available count ────────────────────────────────────────────────────────
if ($is_superadmin) {
    $cnt_available = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='Available'")->fetchColumn();
} else {
    $oid = (int)$session_office_id;
    $cnt_available = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='Available' AND (office_id=$oid OR vehicle_scope='Both')")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicles – CSU VSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; }

        /* ══ SIDEBAR ══ */
        .sidebar { min-height:100vh; background:linear-gradient(180deg,#800000 0%,#6b0000 100%); width:240px; position:fixed; top:0; left:0; z-index:200; display:flex; flex-direction:column; transition:transform 0.25s ease; }
        .sidebar-brand { padding:1.25rem 1rem 1rem; border-bottom:1px solid rgba(255,255,255,0.15); display:flex; align-items:center; gap:10px; }
        .sidebar-logo { width:42px; height:42px; border-radius:50%; background:#fff; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .sidebar-logo img { width:38px; height:38px; object-fit:contain; }
        .sidebar-brand-text { color:#fff; font-size:0.82rem; font-weight:700; line-height:1.3; }
        .sidebar-brand-text span { display:block; font-size:0.72rem; font-weight:400; opacity:0.7; }
        .sidebar .nav-link { color:rgba(255,255,255,0.8); padding:0.6rem 1.25rem; font-size:0.88rem; display:flex; align-items:center; gap:10px; border-left:3px solid transparent; transition:all 0.15s; }
        .sidebar .nav-link:hover { color:#fff; background:rgba(255,255,255,0.1); border-left-color:rgba(255,255,255,0.4); }
        .sidebar .nav-link.active { color:#fff; background:rgba(255,255,255,0.15); border-left-color:#fff; font-weight:600; }
        .sidebar .nav-link i { font-size:1rem; width:18px; }
        .sidebar-divider { border-color:rgba(255,255,255,0.15); margin:0.5rem 1rem; }
        .nav-section-label { padding:0.75rem 1.25rem 0.25rem; font-size:0.68rem; font-weight:700; color:rgba(255,255,255,0.45); letter-spacing:0.08em; text-transform:uppercase; }
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:199; }
        .sidebar-overlay.show { display:block; }

        /* ══ TOPBAR ══ */
        .topbar { background:#fff; border-bottom:1px solid #e8dede; padding:0.7rem 1.5rem; margin-left:240px; position:sticky; top:0; z-index:99; display:flex; align-items:center; justify-content:space-between; }
        .topbar-title { font-weight:700; font-size:1rem; color:#800000; }
        .topbar-user { display:flex; align-items:center; gap:8px; font-size:0.85rem; color:#666; }
        .user-avatar { width:32px; height:32px; border-radius:50%; background:#800000; color:#fff; display:flex; align-items:center; justify-content:center; font-size:0.8rem; font-weight:700; }
        .hamburger-btn { display:none; background:none; border:none; cursor:pointer; padding:4px 8px; color:#800000; font-size:1.4rem; align-items:center; justify-content:center; line-height:1; }

        /* ══ DESKTOP MAIN ══ */
        .main-content { margin-left:240px; padding:1.5rem; }
        .section-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(128,0,0,0.07); overflow:hidden; }
        .section-header { padding:1rem 1.25rem; border-bottom:1px solid #f0e5e5; font-weight:700; font-size:0.9rem; color:#800000; display:flex; align-items:center; justify-content:space-between; }
        .table thead th { background:#fdf5f5; color:#800000; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; border-bottom:2px solid #f0e5e5; padding:0.75rem 1rem; }
        .table tbody td { padding:0.7rem 1rem; font-size:0.85rem; color:#444; vertical-align:middle; border-color:#fdf5f5; }
        .table tbody tr:hover { background:#fdf8f8; }
        .badge-available   { background:#d1e7dd; color:#0f5132; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .badge-unavailable { background:#f8d7da; color:#842029; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .badge-maintenance { background:#fff3cd; color:#856404; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
        .scope-central { background:#e8f0fe; color:#1a56db; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .scope-campus  { background:#fce8e6; color:#800000; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .scope-both    { background:#e6f4ea; color:#137333; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .btn-maroon { background:#800000; color:#fff; border:none; }
        .btn-maroon:hover { background:#6b0000; color:#fff; }
        .modal-header { background:linear-gradient(135deg,#800000,#6b0000); color:#fff; }
        .modal-header .btn-close { filter:invert(1); }
        .modal-content { border-radius:14px; border:none; }
        .filter-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1rem; }
        .filter-tab { padding:6px 16px; border-radius:20px; font-size:0.82rem; font-weight:600; cursor:pointer; text-decoration:none; border:1.5px solid #e0d0d0; color:#800000; background:#fff; transition:all 0.15s; }
        .filter-tab:hover { background:#fdf0f0; color:#800000; }
        .filter-tab.active { background:#800000; color:#fff; border-color:#800000; }
        .filter-tab .count { opacity:0.75; font-size:0.75rem; margin-left:4px; }
        .mini-stats { display:grid; gap:12px; margin-bottom:1.25rem; }
        .mini-stats-4 { grid-template-columns: repeat(4,1fr); }
        .mini-stats-3 { grid-template-columns: repeat(3,1fr); }
        .mini-card { background:#fff; border-radius:12px; padding:1rem 1.25rem; box-shadow:0 2px 10px rgba(128,0,0,0.06); border-left:4px solid #800000; }
        .mini-card.central { border-left-color:#1a56db; }
        .mini-card.campus  { border-left-color:#800000; }
        .mini-card.both    { border-left-color:#137333; }
        .mini-card.all     { border-left-color:#888; }
        .mini-label { font-size:0.72rem; color:#999; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
        .mini-value { font-size:1.5rem; font-weight:700; color:#2d2d2d; line-height:1.1; }
        .form-control:focus, .form-select:focus { border-color:#800000; box-shadow:0 0 0 0.2rem rgba(128,0,0,0.12); }
        .scope-radio-label { display:flex; align-items:flex-start; gap:10px; border:1.5px solid #dee2e6; border-radius:10px; padding:12px; cursor:pointer; transition:all 0.15s; }
        .scope-radio-label:has(input:checked) { border-color:#800000; background:#fdf5f5; }

        /* ══════════════════════════════════════════════
           MOBILE STYLES — dashboard-parity redesign
        ══════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); }
            .topbar { display:none !important; }
            .main-content { display:none !important; }
            .hamburger-btn { display:flex !important; }

            /* ── Mobile topbar ── */
            .mob-topbar {
                background:#fff;
                border-bottom:1px solid #e8dede;
                padding:.7rem 1rem;
                position:sticky; top:0; z-index:99;
                display:flex; align-items:center; justify-content:space-between;
                gap:10px;
            }
            .mob-topbar-title { font-weight:700; font-size:1rem; color:#800000; flex:1; }
            .mob-topbar-user  { display:flex; align-items:center; gap:8px; }
            .mob-topbar-user .user-avatar { width:32px; height:32px; border-radius:50%; background:#800000; color:#fff; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; }

            /* ── Scroll area ── */
            .mob-scroll { padding:14px 14px 110px; }

            /* ── Office banner ── */
            .office-banner {
                background:#fff8e1; border:1px solid #ffe082;
                border-radius:10px; padding:9px 12px;
                font-size:.78rem; color:#6d4c00;
                display:flex; align-items:center; gap:8px;
                margin-bottom:14px;
            }
            .office-banner strong { color:#800000; }

            /* ── Resource stat cards (like dashboard row-1) ── */
            .mob-stat-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:16px; }
            .mob-stat-card {
                background:#fff; border-radius:14px;
                padding:14px 14px;
                box-shadow:0 2px 10px rgba(128,0,0,.06);
                display:flex; align-items:center; gap:12px;
            }
            .mob-stat-icon {
                width:40px; height:40px; border-radius:11px;
                display:flex; align-items:center; justify-content:center;
                font-size:1.1rem; flex-shrink:0;
            }
            .mob-stat-icon.icon-all      { background:#fdecea; color:#800000; }
            .mob-stat-icon.icon-central  { background:#e8f0fe; color:#1a56db; }
            .mob-stat-icon.icon-campus   { background:#fce8e6; color:#800000; }
            .mob-stat-icon.icon-both     { background:#e6f4ea; color:#137333; }
            .mob-stat-icon.icon-avail    { background:#d1e7dd; color:#0f5132; }
            .mob-stat-meta { flex:1; min-width:0; }
            .mob-stat-label { font-size:.63rem; color:#999; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin-bottom:3px; }
            .mob-stat-value { font-size:1.4rem; font-weight:800; color:#1a1a1a; line-height:1; }
            .mob-stat-sub   { font-size:.68rem; color:#aaa; margin-top:2px; }

            /* ── Filter pills ── */
            .mob-filter-row { display:flex; gap:8px; overflow-x:auto; padding-bottom:4px; margin-bottom:16px; scrollbar-width:none; }
            .mob-filter-row::-webkit-scrollbar { display:none; }
            .mob-filter-pill {
                flex-shrink:0; padding:7px 15px; border-radius:20px;
                font-size:.78rem; font-weight:600;
                border:1.5px solid #e0d0d0; color:#800000;
                background:#fff; cursor:pointer; white-space:nowrap;
                transition:all .15s; text-decoration:none;
            }
            .mob-filter-pill.active { background:#800000; color:#fff; border-color:#800000; }

            /* ── Section label ── */
            .mob-section-lbl {
                font-size:.72rem; font-weight:700; color:#800000;
                text-transform:uppercase; letter-spacing:.06em;
                margin-bottom:10px; display:flex; align-items:center; gap:6px;
            }

            /* ── Vehicle cards ── */
            .vehicle-card {
                background:#fff; border-radius:14px;
                padding:14px; margin-bottom:10px;
                box-shadow:0 2px 10px rgba(128,0,0,.06);
                border:1px solid #f5eded;
                transition:box-shadow .15s;
            }
            .vc-top { display:flex; align-items:flex-start; gap:10px; margin-bottom:12px; }
            .vc-icon {
                width:44px; height:44px; border-radius:11px; flex-shrink:0;
                background:linear-gradient(135deg,#800000,#a00000);
                color:#fff; font-size:1.2rem;
                display:flex; align-items:center; justify-content:center;
            }
            .vc-title { flex:1; min-width:0; }
            .vc-plate { font-weight:700; font-size:.98rem; color:#1a1a1a; font-family:monospace; margin-bottom:2px; }
            .vc-brand { font-size:.78rem; color:#888; }
            .vc-scope-pill { flex-shrink:0; }

            /* Scope pill variants */
            .pill-central { background:#e8f0fe; color:#1a56db; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; }
            .pill-campus  { background:#fce8e6; color:#800000; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; }
            .pill-both    { background:#e6f4ea; color:#137333; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; display:inline-flex; align-items:center; gap:3px; }

            /* Meta grid */
            .vc-meta { display:grid; grid-template-columns:1fr 1fr; gap:6px; margin-bottom:12px; }
            .vc-meta-item { background:#fdf8f8; border-radius:9px; padding:8px 10px; }
            .vc-meta-lbl { font-size:.62rem; color:#aaa; font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
            .vc-meta-val { font-size:.8rem; color:#333; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:5px; }
            .vc-meta-full { grid-column:1/-1; }

            /* Status dot */
            .status-dot { width:7px; height:7px; border-radius:50%; display:inline-block; flex-shrink:0; }
            .dot-green  { background:#22c55e; }
            .dot-red    { background:#ef4444; }
            .dot-amber  { background:#f59e0b; }

            /* Action buttons */
            .vc-actions { display:flex; gap:8px; padding-top:12px; border-top:1px solid #fdf0f0; }
            .vc-btn {
                flex:1; padding:9px 8px; border-radius:10px;
                font-size:.78rem; font-weight:600;
                display:flex; align-items:center; justify-content:center; gap:5px;
                cursor:pointer; border:none; transition:all .15s;
            }
            .vc-btn-edit   { background:#f5f5f5; color:#555; }
            .vc-btn-edit:hover   { background:#ebebeb; }
            .vc-btn-delete { background:#fef2f2; color:#dc2626; }
            .vc-btn-delete:hover { background:#fee2e2; }

            /* ── Empty state ── */
            .mob-empty { text-align:center; padding:44px 20px; color:#bbb; }
            .mob-empty i { font-size:2.4rem; display:block; margin-bottom:10px; opacity:.35; }
            .mob-empty p { font-size:.85rem; margin:0; }

            /* ── FAB ── */
            .mob-fab {
                position:fixed; bottom:24px; right:20px; z-index:150;
                width:58px; height:58px;
                background:#800000; color:#fff; border:none; border-radius:50%;
                font-size:1.6rem; display:flex; align-items:center; justify-content:center;
                box-shadow:0 4px 20px rgba(128,0,0,.40);
                cursor:pointer; transition:transform .15s, background .15s;
            }
            .mob-fab:hover  { background:#6b0000; transform:scale(1.05); }
            .mob-fab:active { transform:scale(.95); }

            /* ── Sheet backdrop ── */
            .sheet-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:300; opacity:0; pointer-events:none; transition:opacity .25s; }
            .sheet-backdrop.open { opacity:1; pointer-events:all; }

            /* ── Bottom sheet ── */
            .mob-sheet {
                position:fixed; bottom:0; left:0; right:0; z-index:310;
                background:#fff; border-radius:20px 20px 0 0;
                max-height:92vh; overflow-y:auto;
                transform:translateY(105%);
                transition:transform .3s cubic-bezier(.4,0,.2,1);
                padding:0 16px 48px;
            }
            .mob-sheet.open { transform:translateY(0); }
            .sheet-handle { width:40px; height:4px; background:#e0d0d0; border-radius:2px; margin:12px auto 16px; }
            .sheet-head { font-weight:700; font-size:1rem; color:#800000; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
            .sheet-form-group { margin-bottom:14px; }
            .sheet-label { font-size:.72rem; font-weight:700; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; display:block; }
            .sheet-input {
                width:100%; padding:11px 13px; border-radius:10px;
                border:1.5px solid #e0d0d0; font-size:.9rem; color:#333;
                background:#fff; outline:none;
                transition:border-color .15s, box-shadow .15s;
                -webkit-appearance:none; appearance:none;
            }
            .sheet-input:focus { border-color:#800000; box-shadow:0 0 0 3px rgba(128,0,0,.1); }
            .sheet-select {
                background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
                background-repeat:no-repeat; background-position:right 12px center;
            }
            .scope-opts { display:flex; flex-direction:column; gap:8px; }
            .scope-opt {
                display:flex; align-items:center; gap:10px;
                padding:11px 12px; border:1.5px solid #e0d0d0;
                border-radius:10px; cursor:pointer; transition:all .15s;
            }
            .scope-opt.sel-central { border-color:#1a56db; background:#f0f4ff; }
            .scope-opt.sel-campus  { border-color:#800000; background:#fdf5f5; }
            .scope-opt.sel-both    { border-color:#137333; background:#f0faf2; }
            .scope-opt-radio { width:16px; height:16px; accent-color:#800000; flex-shrink:0; }
            .scope-opt-lbl { font-size:.85rem; font-weight:600; }
            .scope-opt-sub { font-size:.71rem; color:#aaa; }
            .sheet-actions { display:flex; gap:10px; margin-top:20px; }
            .btn-sheet-cancel { flex:1; padding:13px; border-radius:11px; background:#f5f5f5; color:#555; font-weight:600; font-size:.88rem; border:none; cursor:pointer; }
            .btn-sheet-save   { flex:2; padding:13px; border-radius:11px; background:#800000; color:#fff; font-weight:700; font-size:.88rem; border:none; cursor:pointer; }
            .btn-sheet-save:active { background:#6b0000; }

            /* ── Delete confirm sheet ── */
            .confirm-sheet { padding-bottom:48px; }
            .confirm-emoji { font-size:2.6rem; text-align:center; margin-bottom:6px; }
            .confirm-msg   { text-align:center; color:#555; font-size:.9rem; margin-bottom:3px; }
            .confirm-name  { text-align:center; font-size:1.05rem; font-weight:700; color:#c0392b; margin-bottom:4px; }
            .confirm-sub   { text-align:center; font-size:.77rem; color:#aaa; margin-bottom:20px; }
            .btn-sheet-del { flex:2; padding:13px; border-radius:11px; background:#dc2626; color:#fff; font-weight:700; font-size:.88rem; border:none; cursor:pointer; }
        }

        @media (min-width: 769px) {
            .mob-topbar    { display:none; }
            .mob-scroll    { display:none; }
            .mob-fab       { display:none; }
            .sheet-backdrop { display:none; }
            .mob-sheet     { display:none; }
        }
    </style>
</head>
<body>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ════════ SIDEBAR ════════ -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo"><img src="../image/Csu.png" alt="CSU Logo"></div>
        <div class="sidebar-brand-text">CSU Vehicle System<span>Admin Panel</span></div>
    </div>
    <nav class="nav flex-column mt-2">
        <div class="nav-section-label">Main</div>
        <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <div class="nav-section-label">Manage</div>
        <a class="nav-link active" href="Vehicles.php"><i class="bi bi-truck-front"></i>Vehicles</a>
        <a class="nav-link" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i>Driver Trip Records</a>
        <a class="nav-link" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link " href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="nav-link" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="nav-section-label">Scheduling</div>
        <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="nav-section-label">Settings</div>
        <?php
        $_notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $_notifStmt->execute([$_SESSION['user_id']]);
        $_sidebarUnread = (int)$_notifStmt->fetchColumn();
        ?>
        <a class="nav-link" href="notification.php" style="justify-content:space-between">
            <span style="display:flex;align-items:center;gap:10px">
                <i class="bi bi-bell"></i>Notifications
            </span>
            <?php if($_sidebarUnread > 0): ?>
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

<!-- ════════ MOBILE TOPBAR ════════ -->
<div class="mob-topbar">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="mob-topbar-title"><i class="bi bi-truck-front me-2"></i>Vehicles</div>
    <div class="mob-topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.82rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:.7rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<!-- ════════ MOBILE SCROLL CONTENT ════════ -->
<div class="mob-scroll">
    <?= $_flashHTML ?>

    <?php if (!$is_superadmin): ?>
    <div class="office-banner">
        <i class="bi bi-building" style="color:#b45309;font-size:1rem"></i>
        Showing: <strong><?= htmlspecialchars($session_office_name ?: 'Your Office') ?></strong> + shared vehicles
    </div>
    <?php endif; ?>

    <!-- ── Resource stat cards (dashboard-style) ── -->
    <div class="mob-stat-grid">
    <div class="mob-stat-card">
        <div class="mob-stat-icon icon-all"><i class="bi bi-truck-front"></i></div>
        <div class="mob-stat-meta">
            <div class="mob-stat-label">Total</div>
            <div class="mob-stat-value"><?= $cnt_all ?></div>
            <div class="mob-stat-sub"><?= $cnt_available ?> available</div>
        </div>
    </div>

    <div class="mob-stat-card">
        <div class="mob-stat-icon icon-central"><i class="bi bi-building"></i></div>
        <div class="mob-stat-meta">
            <div class="mob-stat-label">Central</div>
            <div class="mob-stat-value"><?= $cnt_central ?></div>
            <div class="mob-stat-sub">office only</div>
        </div>
    </div>

    <div class="mob-stat-card">
        <div class="mob-stat-icon icon-campus"><i class="bi bi-building"></i></div>
        <div class="mob-stat-meta">
            <div class="mob-stat-label">Campus</div>
            <div class="mob-stat-value"><?= $cnt_campus ?></div>
            <div class="mob-stat-sub">office only</div>
        </div>
    </div>

    <div class="mob-stat-card">
        <div class="mob-stat-icon icon-both"><i class="bi bi-buildings"></i></div>
        <div class="mob-stat-meta">
            <div class="mob-stat-label">Shared</div>
            <div class="mob-stat-value"><?= $cnt_both ?></div>
            <div class="mob-stat-sub">both offices</div>
        </div>
    </div>
</div>

    <!-- ── Filter pills ── -->
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

    <!-- ── Section label ── -->
    <div class="mob-section-lbl">
        <i class="bi bi-truck-front"></i>
        <?php
        if ($filter === 'central')    echo 'Central Office Vehicles';
        elseif ($filter === 'campus') echo 'Campus Office Vehicles';
        elseif ($filter === 'both')   echo 'Shared Vehicles';
        else                          echo 'All Vehicles';
        ?> <span style="font-weight:400;color:#bbb;font-size:.68rem;margin-left:4px">(<?= count($vehicles) ?>)</span>
    </div>

    <!-- ── Vehicle cards ── -->
    <?php if (empty($vehicles)): ?>
    <div class="mob-empty">
        <i class="bi bi-truck"></i>
        <p>No vehicles found.</p>
    </div>
    <?php else: foreach ($vehicles as $v):
        $sc = $v['vehicle_scope'];
        $st = $v['status'];

        // Dot color
        $dot = ($st === 'Available') ? 'dot-green' : (($st === 'Maintenance') ? 'dot-amber' : 'dot-red');

        // Scope pill
if ($sc === 'Both') {
    $scope_html = '<span class="pill-both"><i class="bi bi-buildings" style="font-size:.65rem"></i>Both</span>';
} elseif (str_starts_with($sc, 'office_')) {
    $sc_office_id = str_replace('office_', '', $sc);
    $sc_name = '';
    foreach ($offices as $o) {
        if ($o['office_id'] == $sc_office_id) { $sc_name = $o['office_name']; break; }
    }
    $scope_html = '<span class="pill-campus"><i class="bi bi-building" style="font-size:.65rem"></i>' . htmlspecialchars($sc_name) . ' Only</span>';
} else {
    $scope_html = '<span class="pill-both"><i class="bi bi-buildings" style="font-size:.65rem"></i>' . htmlspecialchars($sc) . '</span>';
}
    ?>
    <div class="vehicle-card">
        <!-- Header row -->
        <div class="vc-top">
            <div class="vc-icon"><i class="bi bi-truck-front"></i></div>
            <div class="vc-title">
                <div class="vc-plate"><?= htmlspecialchars($v['plate_number']) ?></div>
                <div class="vc-brand"><?= htmlspecialchars(trim($v['brand'] . ' ' . $v['model'])) ?: '—' ?></div>
            </div>
            <div class="vc-scope-pill"><?= $scope_html ?></div>
        </div>

        <!-- Meta grid -->
        <div class="vc-meta">
            <div class="vc-meta-item">
                <div class="vc-meta-lbl">Capacity</div>
                <div class="vc-meta-val"><i class="bi bi-people" style="font-size:.72rem;color:#800000"></i><?= htmlspecialchars($v['capacity'] ?? '—') ?> seats</div>
            </div>
            <div class="vc-meta-item">
                <div class="vc-meta-lbl">Status</div>
                <div class="vc-meta-val"><span class="status-dot <?= $dot ?>"></span><?= htmlspecialchars($st ?? '—') ?></div>
            </div>
            <div class="vc-meta-item vc-meta-full">
                <div class="vc-meta-lbl">Office</div>
                <div class="vc-meta-val"><i class="bi bi-building" style="font-size:.72rem;color:#800000"></i><?= htmlspecialchars($v['office_name'] ?? '—') ?></div>
            </div>
        </div>

        <!-- Actions -->
        <div class="vc-actions">
            <button class="vc-btn vc-btn-edit"
                onclick="mobOpenEdit(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)">
                <i class="bi bi-pencil" style="font-size:.78rem"></i> Edit
            </button>
            <button class="vc-btn vc-btn-delete"
                onclick="mobOpenDelete(<?= $v['vehicle_id'] ?>, '<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>')">
                <i class="bi bi-trash" style="font-size:.78rem"></i> Delete
            </button>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div><!-- /mob-scroll -->

<!-- FAB -->
<button class="mob-fab" onclick="mobOpenAdd()" title="Add Vehicle">
    <i class="bi bi-plus-lg"></i>
</button>

<!-- Sheet backdrop -->
<div class="sheet-backdrop" id="mobBackdrop" onclick="mobCloseAll()"></div>

<!-- ── Add/Edit Bottom Sheet ── -->
<div class="mob-sheet" id="mobFormSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head" id="mobSheetTitle"><i class="bi bi-truck-front"></i> Add Vehicle</div>
    <form method="POST" id="mobVehicleForm">
        <input type="hidden" name="action" id="mob_action" value="add">
        <input type="hidden" name="vehicle_id" id="mob_vehicle_id">
        <div class="sheet-form-group">
            <label class="sheet-label">Plate Number <span style="color:#dc2626">*</span></label>
            <input type="text" name="plate_number" id="mob_plate" class="sheet-input" placeholder="e.g. ABC 1234" required>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Brand <span style="color:#dc2626">*</span></label>
            <input type="text" name="brand" id="mob_brand" class="sheet-input" placeholder="e.g. Toyota" required>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Model</label>
            <input type="text" name="model" id="mob_model" class="sheet-input" placeholder="e.g. Hiace">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Capacity (seats)</label>
            <input type="number" name="capacity" id="mob_capacity" class="sheet-input" min="1" placeholder="e.g. 12" inputmode="numeric">
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
            <div style="background:#fdf5f5;border:1px solid #f0e5e5;border-radius:10px;padding:.65rem .9rem;font-size:.85rem;color:#800000;font-weight:600;display:flex;align-items:center;gap:6px;">
                <i class="bi bi-building-fill"></i>
                <?= htmlspecialchars($session_office_name ?: 'Your Office') ?>
                <small style="margin-left:auto;font-weight:400;color:#999;font-size:.7rem"><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
            </div>
            <?php endif; ?>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Status</label>
            <select name="status" id="mob_status" class="sheet-input sheet-select">
                <option value="Available">Available</option>
                <option value="Unavailable">Unavailable</option>
                <option value="Maintenance">Maintenance</option>
            </select>
        </div>
       <div class="sheet-form-group">
    <label class="sheet-label">Vehicle Scope <span style="color:#dc2626">*</span></label>
    <div class="scope-opts">
        <?php if ($is_superadmin): ?>
            <?php foreach ($offices as $o): ?>
            <label class="scope-opt" id="mob_opt_office_<?= $o['office_id'] ?>">
                <input type="radio" name="vehicle_scope"
                       value="office_<?= $o['office_id'] ?>"
                       class="scope-opt-radio" onchange="mobScopeStyle()">
                <div>
                    <div class="scope-opt-lbl" style="color:#800000"><?= htmlspecialchars($o['office_name']) ?> Only</div>
                    <div class="scope-opt-sub">Exclusive to <?= htmlspecialchars($o['office_name']) ?></div>
                </div>
            </label>
            <?php endforeach; ?>
        <?php else: ?>
            <label class="scope-opt" id="mob_opt_office_<?= $session_office_id ?>">
                <input type="radio" name="vehicle_scope"
                       value="office_<?= $session_office_id ?>"
                       class="scope-opt-radio" onchange="mobScopeStyle()">
                <div>
                    <div class="scope-opt-lbl" style="color:#800000"><?= htmlspecialchars($session_office_name) ?> Only</div>
                    <div class="scope-opt-sub">Exclusive to <?= htmlspecialchars($session_office_name) ?></div>
                </div>
            </label>
        <?php endif; ?>
        <label class="scope-opt" id="mob_opt_both">
            <input type="radio" name="vehicle_scope" value="Both"
                   class="scope-opt-radio" onchange="mobScopeStyle()">
            <div>
                <div class="scope-opt-lbl" style="color:#137333">Both Offices</div>
                <div class="scope-opt-sub">Shared between offices</div>
            </div>
        </label>
    </div>
</div>
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-save"><i class="bi bi-check-lg me-1"></i>Save Vehicle</button>
        </div>
    </form>
</div>

<!-- ── Delete Confirm Bottom Sheet ── -->
<div class="mob-sheet confirm-sheet" id="mobDeleteSheet">
    <div class="sheet-handle"></div>
    <div class="confirm-emoji">⚠️</div>
    <div class="confirm-msg">Delete vehicle</div>
    <div class="confirm-name" id="mobDeleteName">—</div>
    <div class="confirm-sub">This cannot be undone.</div>
    <form method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="vehicle_id" id="mobDeleteId">
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-del"><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
    </form>
</div>

<!-- ════════ DESKTOP TOPBAR ════════ -->
<div class="topbar">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-truck-front me-2"></i>Vehicles</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:0.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:0.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<!-- ════════ DESKTOP MAIN ════════ -->
<div class="main-content">
    <?= $_flashHTML ?>

    <?php if (!$is_superadmin): ?>
    <div class="alert d-flex align-items-center gap-2 mb-3"
         style="background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:0.75rem 1.1rem;font-size:0.85rem;">
        <i class="bi bi-building me-1" style="color:#b45309;font-size:1rem;"></i>
        <span>Showing vehicles for: <strong style="color:#800000"><?= htmlspecialchars($session_office_name ?: 'Your Office') ?></strong> + shared vehicles (Both Offices)</span>
    </div>
    <?php endif; ?>

    <!-- Mini stat cards -->
    <div class="mini-stats <?= $is_superadmin ? 'mini-stats-4' : 'mini-stats-3' ?>">
        <div class="mini-card all">
            <div class="mini-label">Total Vehicles</div>
            <div class="mini-value"><?= $cnt_all ?></div>
        </div>
        <?php if ($is_superadmin || $user_scope === 'Central'): ?>
        <div class="mini-card central">
            <div class="mini-label">Central Only</div>
            <div class="mini-value"><?= $cnt_central ?></div>
        </div>
        <?php endif; ?>
        <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
        <div class="mini-card campus">
            <div class="mini-label">Campus Only</div>
            <div class="mini-value"><?= $cnt_campus ?></div>
        </div>
        <?php endif; ?>
        <div class="mini-card both">
            <div class="mini-label">Shared (Both)</div>
            <div class="mini-value"><?= $cnt_both ?></div>
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="filter-tabs">
        <a href="?scope=all" class="filter-tab <?= $filter==='all' ? 'active':'' ?>">
            All <span class="count">(<?= $cnt_all ?>)</span>
        </a>
        <?php if ($is_superadmin || $user_scope === 'Central'): ?>
        <a href="?scope=central" class="filter-tab <?= $filter==='central' ? 'active':'' ?>">
            Central Only <span class="count">(<?= $cnt_central ?>)</span>
        </a>
        <?php endif; ?>
        <?php if ($is_superadmin || $user_scope === 'Campus'): ?>
        <a href="?scope=campus" class="filter-tab <?= $filter==='campus' ? 'active':'' ?>">
            Campus Only <span class="count">(<?= $cnt_campus ?>)</span>
        </a>
        <?php endif; ?>
        <a href="?scope=both" class="filter-tab <?= $filter==='both' ? 'active':'' ?>">
            Shared (Both) <span class="count">(<?= $cnt_both ?>)</span>
        </a>
    </div>

    <!-- Table card -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-truck-front me-2"></i>
                <?php
                if ($filter === 'central')    echo 'Central Office Vehicles';
                elseif ($filter === 'campus') echo 'Campus Office Vehicles';
                elseif ($filter === 'both')   echo 'Shared Vehicles (Both Offices)';
                else                          echo 'All Vehicles';
                ?>
            </span>
            <button class="btn btn-maroon btn-sm rounded-3" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg me-1"></i>Add Vehicle
            </button>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Plate No.</th>
                        <th>Brand</th>
                        <th>Model</th>
                        <th>Capacity</th>
                        <th>Scope</th>
                        <th>Office</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vehicles as $v): ?>
                <tr>
                    <td><?= $v['vehicle_id'] ?></td>
                    <td><strong><?= htmlspecialchars($v['plate_number']) ?></strong></td>
                    <td><?= htmlspecialchars($v['brand']) ?></td>
                    <td><?= htmlspecialchars($v['model']) ?></td>
                    <td><?= $v['capacity'] ?> seats</td>
                    <td>
                        <?php
                        $sc = $v['vehicle_scope'];
                        if ($sc === 'Both') {
                            echo '<span class="scope-both"><i class="bi bi-buildings"></i> Both Offices</span>';
                        } elseif (str_starts_with($sc, 'office_')) {
                            $sc_office_id = str_replace('office_', '', $sc);
                            $sc_name = '';
                            foreach ($offices as $o) {
                                if ($o['office_id'] == $sc_office_id) { $sc_name = $o['office_name']; break; }
                            }
                            echo '<span class="scope-campus"><i class="bi bi-building"></i> ' . htmlspecialchars($sc_name) . ' Only</span>';
                        } ?>
                    </td>
                    <td><?= htmlspecialchars($v['office_name'] ?? '—') ?></td>
                    <td>
                        <?php $st = $v['status'];
                        if ($st === 'Available')       echo '<span class="badge-available">Available</span>';
                        elseif ($st === 'Maintenance') echo '<span class="badge-maintenance">Maintenance</span>';
                        else                           echo '<span class="badge-unavailable">'.htmlspecialchars($st ?? '—').'</span>'; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <button class="btn btn-sm btn-outline-secondary me-1"
                            onclick="openEdit(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                            onclick="openDelete(<?= $v['vehicle_id'] ?>, '<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vehicles)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-truck fs-4 d-block mb-2 opacity-50"></i>No vehicles found.
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ════════ ADD MODAL ════════ -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-truck-front me-2"></i>Add Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Plate Number <span class="text-danger">*</span></label>
                            <input type="text" name="plate_number" class="form-control" required placeholder="e.g. ABC 1234">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
                            <input type="text" name="brand" class="form-control" required placeholder="e.g. Toyota">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Model</label>
                            <input type="text" name="model" class="form-control" placeholder="e.g. Hiace">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Capacity (seats)</label>
                            <input type="number" name="capacity" class="form-control" min="1" placeholder="e.g. 12">
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
                                <i class="bi bi-building-fill"></i>
                                <?= htmlspecialchars($session_office_name ?: 'Your Office') ?>
                                <small style="margin-left:auto;font-weight:400;color:#999;font-size:0.72rem"><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="Available">Available</option>
                                <option value="Unavailable">Unavailable</option>
                                <option value="Maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Vehicle Scope <span class="text-danger">*</span></label>
                            <div class="row g-2 mt-1">
                                <?php if ($is_superadmin): ?>
                                    <?php foreach ($offices as $o): ?>
                                    <div class="col-md-4">
                                        <label class="scope-radio-label">
                                            <input type="radio" name="vehicle_scope"
                                                   value="office_<?= $o['office_id'] ?>" class="mt-1">
                                            <div>
                                                <div class="fw-semibold" style="color:#800000"><?= htmlspecialchars($o['office_name']) ?> Only</div>
                                                <div class="text-muted" style="font-size:0.78rem">Exclusive to <?= htmlspecialchars($o['office_name']) ?></div>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="col-md-4">
                                        <label class="scope-radio-label">
                                            <input type="radio" name="vehicle_scope"
                                                   value="office_<?= $session_office_id ?>" class="mt-1">
                                            <div>
                                                <div class="fw-semibold" style="color:#800000"><?= htmlspecialchars($session_office_name) ?> Only</div>
                                                <div class="text-muted" style="font-size:0.78rem">Exclusive to <?= htmlspecialchars($session_office_name) ?></div>
                                            </div>
                                        </label>
                                    </div>
                                <?php endif; ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="vehicle_scope" value="Both" class="mt-1">
                                        <div>
                                            <div class="fw-semibold" style="color:#137333">Both Offices</div>
                                            <div class="text-muted" style="font-size:0.78rem">Shared between offices</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-light btn-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon btn-sm rounded-3"><i class="bi bi-check-lg me-1"></i>Add Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════ EDIT MODAL ════════ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Plate Number <span class="text-danger">*</span></label>
                            <input type="text" name="plate_number" id="edit_plate_number" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
                            <input type="text" name="brand" id="edit_brand" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Model</label>
                            <input type="text" name="model" id="edit_model" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Capacity (seats)</label>
                            <input type="number" name="capacity" id="edit_capacity" class="form-control" min="1">
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
                                <i class="bi bi-building-fill"></i>
                                <?= htmlspecialchars($session_office_name ?: 'Your Office') ?>
                                <small style="margin-left:auto;font-weight:400;color:#999;font-size:0.72rem"><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="Available">Available</option>
                                <option value="Unavailable">Unavailable</option>
                                <option value="Maintenance">Maintenance</option>
                            </select>
                        </div>
                        <!-- ════════ EDIT MODAL scope section ════════ -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Vehicle Scope <span class="text-danger">*</span></label>
                            <div class="row g-2 mt-1">
                                <?php if ($is_superadmin): ?>
                                    <?php foreach ($offices as $o): ?>
                                    <div class="col-md-4">
                                        <label class="scope-radio-label">
                                            <input type="radio" name="vehicle_scope"
                                                   id="edit_scope_office_<?= $o['office_id'] ?>"
                                                   value="office_<?= $o['office_id'] ?>" class="mt-1">
                                            <div>
                                                <div class="fw-semibold" style="color:#800000"><?= htmlspecialchars($o['office_name']) ?> Only</div>
                                                <div class="text-muted" style="font-size:0.78rem">Exclusive to <?= htmlspecialchars($o['office_name']) ?></div>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="col-md-4">
                                        <label class="scope-radio-label">
                                            <input type="radio" name="vehicle_scope"
                                                   id="edit_scope_office_<?= $session_office_id ?>"
                                                   value="office_<?= $session_office_id ?>" class="mt-1">
                                            <div>
                                                <div class="fw-semibold" style="color:#800000"><?= htmlspecialchars($session_office_name) ?> Only</div>
                                                <div class="text-muted" style="font-size:0.78rem">Exclusive to <?= htmlspecialchars($session_office_name) ?></div>
                                            </div>
                                        </label>
                                    </div>
                                <?php endif; ?>
                                <div class="col-md-4">
                                    <label class="scope-radio-label">
                                        <input type="radio" name="vehicle_scope" id="edit_scope_both" value="Both" class="mt-1">
                                        <div>
                                            <div class="fw-semibold" style="color:#137333">Both Offices</div>
                                            <div class="text-muted" style="font-size:0.78rem">Shared between offices</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
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

<!-- ════════ DELETE MODAL ════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#a00000,#800000);color:#fff;">
                <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Vehicle</h5>
                <button type="button" class="btn-close" style="filter:invert(1)" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="vehicle_id" id="del_vehicle_id">
                <div class="modal-body text-center py-3">
                    <i class="bi bi-exclamation-triangle-fill text-warning fs-2 mb-2 d-block"></i>
                    <p class="mb-1" style="font-size:.88rem">Delete vehicle</p>
                    <div class="fw-bold" id="del_plate" style="color:#800000;font-size:1rem"></div>
                    <p class="text-muted mt-2 mb-0" style="font-size:.8rem">This cannot be undone.</p>
                </div>
                <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}

/* ── Desktop modals ── */
function openDelete(id, plate) {
    document.getElementById('del_vehicle_id').value = id;
    document.getElementById('del_plate').textContent = plate;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
function openEdit(v) {
    document.getElementById('edit_vehicle_id').value   = v.vehicle_id;
    document.getElementById('edit_plate_number').value = v.plate_number;
    document.getElementById('edit_brand').value        = v.brand;
    document.getElementById('edit_model').value        = v.model    ?? '';
    document.getElementById('edit_capacity').value     = v.capacity ?? '';
    const officeEl = document.getElementById('edit_office_id');
    if (officeEl) officeEl.value = v.office_id ?? '';
    document.getElementById('edit_status').value = v.status ?? 'Available';

    // Uncheck all scope radios first
    document.querySelectorAll('#editModal input[name=vehicle_scope]').forEach(r => r.checked = false);
    // Match by value — e.g. "office_1", "Both"
    const matchEdit = document.querySelector(
        '#editModal input[name=vehicle_scope][value="' + (v.vehicle_scope ?? '') + '"]'
    );
    if (matchEdit) matchEdit.checked = true;

    new bootstrap.Modal(document.getElementById('editModal')).show();
}

/* ── Mobile sheet helpers ── */
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
function mobScopeStyle() {
    document.querySelectorAll('#mobFormSheet .scope-opt').forEach(function(label) {
        const radio = label.querySelector('input[type=radio]');
        label.className = 'scope-opt';
        if (radio && radio.checked) {
            const val = radio.value;
            if (val === 'Both') {
                label.classList.add('sel-both');
            } else {
                label.classList.add('sel-campus');
            }
        }
    });
}
function mobOpenAdd() {
    document.getElementById('mobSheetTitle').innerHTML = '<i class="bi bi-truck-front"></i> Add Vehicle';
    document.getElementById('mob_action').value = 'add';
    document.getElementById('mob_vehicle_id').value = '';
    document.getElementById('mob_plate').value = '';
    document.getElementById('mob_brand').value = '';
    document.getElementById('mob_model').value = '';
    document.getElementById('mob_capacity').value = '';
    var offEl = document.getElementById('mob_office');
    if (offEl) offEl.value = '';
    document.getElementById('mob_status').value = 'Available';
    document.querySelectorAll('#mobFormSheet input[name=vehicle_scope]').forEach(function(r){ r.checked = false; });
    mobScopeStyle();
    mobOpenSheet('mobFormSheet');
}
function mobOpenEdit(v) {
    document.getElementById('mobSheetTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Vehicle';
    document.getElementById('mob_action').value    = 'edit';
    document.getElementById('mob_vehicle_id').value = v.vehicle_id;
    document.getElementById('mob_plate').value      = v.plate_number;
    document.getElementById('mob_brand').value      = v.brand;
    document.getElementById('mob_model').value      = v.model    ?? '';
    document.getElementById('mob_capacity').value   = v.capacity ?? '';
    const offEl = document.getElementById('mob_office');
    if (offEl) offEl.value = v.office_id ?? '';
    document.getElementById('mob_status').value = v.status ?? 'Available';

    document.querySelectorAll('#mobFormSheet input[name=vehicle_scope]').forEach(function(r) {
        r.checked = r.value === (v.vehicle_scope ?? '');
    });
    mobScopeStyle();
    mobOpenSheet('mobFormSheet');
}
function mobOpenDelete(id, plate) {
    document.getElementById('mobDeleteId').value = id;
    document.getElementById('mobDeleteName').textContent = plate;
    mobOpenSheet('mobDeleteSheet');
}
</script>
</body>
</html>