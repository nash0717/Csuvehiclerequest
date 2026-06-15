<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

/* ── Current user ── */
$cu = $pdo->prepare("SELECT u.*, o.office_id AS u_office_id, o.office_name
                    FROM users u
                    LEFT JOIN offices o ON u.office_id=o.office_id
                    WHERE u.user_id=?");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();
$oid = $me['u_office_id'] ?? null;
$officeName = $me['office_name'] ?? '';

/* ── AUTO-MIGRATE: ensure driver_vehicle_assignments table exists ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS driver_vehicle_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        assigned_by INT NULL,
        notes VARCHAR(500) NULL,
        UNIQUE KEY uniq_driver (driver_id),
        FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {}

/* ── POST HANDLERS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $driver_id  = (int)$_POST['driver_id'];
        $vehicle_id = (int)$_POST['vehicle_id'];
        $notes      = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        try {
            $pdo->prepare("INSERT INTO driver_vehicle_assignments (driver_id, vehicle_id, assigned_by, notes)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE vehicle_id=VALUES(vehicle_id), assigned_by=VALUES(assigned_by), notes=VALUES(notes), assigned_at=NOW()")
                ->execute([$driver_id, $vehicle_id, $_SESSION['user_id'], $notes]);
            $_SESSION['flash']['success'] = "Driver-vehicle assignment saved successfully.";
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }

    } elseif ($action === 'unassign') {
        $driver_id = (int)$_POST['driver_id'];
        try {
            $pdo->prepare("DELETE FROM driver_vehicle_assignments WHERE driver_id=?")->execute([$driver_id]);
            $_SESSION['flash']['success'] = "Assignment removed.";
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }
    }

    header("Location: drivervehicle.php"); exit;
}

/* ── Fetch all drivers for this office ── */
/* ── Fetch all drivers for this office ── */
$driversStmt = $pdo->prepare("
    SELECT d.*, 
           dva.vehicle_id AS assigned_vehicle_id,
           dva.notes AS assignment_notes,
           dva.assigned_at,
           v.brand, v.model, v.plate_number,
           ab.username AS assigned_by_name
    FROM drivers d
    LEFT JOIN driver_vehicle_assignments dva ON d.driver_id = dva.driver_id
    LEFT JOIN vehicles v ON dva.vehicle_id = v.vehicle_id
    LEFT JOIN users ab ON dva.assigned_by = ab.user_id
    WHERE d.driver_scope = 'Both' OR d.driver_scope = ? OR d.office_id = ?
    ORDER BY d.driver_name
");
$driversStmt->execute([$officeName, $oid]);
$drivers = $driversStmt->fetchAll();

/* ── Fetch all vehicles for this office ── */
$vehiclesStmt = $pdo->prepare("
    SELECT v.*,
           dva.driver_id AS assigned_driver_id,
           d.driver_name AS assigned_driver_name
    FROM vehicles v
    LEFT JOIN driver_vehicle_assignments dva ON v.vehicle_id = dva.vehicle_id
    LEFT JOIN drivers d ON dva.driver_id = d.driver_id
    WHERE v.vehicle_scope = 'Both' OR v.vehicle_scope = ? OR v.office_id = ?
    ORDER BY v.plate_number
");
$vehiclesStmt->execute([$officeName, $oid]);
$vehicles = $vehiclesStmt->fetchAll();

/* Notification count */
$_notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$_notifStmt->execute([$_SESSION['user_id'], $oid ?? 0]);
$_sidebarUnread = (int)$_notifStmt->fetchColumn();

$assignedCount   = count(array_filter($drivers, fn($d) => !empty($d['assigned_vehicle_id'])));
$unassignedCount = count($drivers) - $assignedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Driver-Vehicle Assignments – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
:root {
  --maroon: #800000;
  --maroon-dark: #6b0000;
  --maroon-light: #fdf5f5;
  --maroon-border: #f0e5e5;
  --sidebar-w: 240px;
}
body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; margin: 0; }

/* ── Sidebar ── */
.sidebar { min-height:100vh; background:linear-gradient(180deg,#800000,#6b0000); width:var(--sidebar-w); position:fixed; top:0; left:0; z-index:400; display:flex; flex-direction:column; }
.sidebar-brand { padding:1.25rem 1rem 1rem; border-bottom:1px solid rgba(255,255,255,.15); display:flex; align-items:center; gap:10px; }
.sidebar-logo { width:42px; height:42px; border-radius:50%; background:#fff; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.sidebar-logo img { width:38px; height:38px; object-fit:contain; }
.sidebar-brand-text { color:#fff; font-size:.82rem; font-weight:700; line-height:1.3; }
.sidebar-brand-text span { display:block; font-size:.72rem; font-weight:400; opacity:.7; }
.sidebar .nav-link { color:rgba(255,255,255,.8); padding:.6rem 1.25rem; font-size:.88rem; display:flex; align-items:center; gap:10px; border-left:3px solid transparent; transition:all .15s; }
.sidebar .nav-link:hover { color:#fff; background:rgba(255,255,255,.1); border-left-color:rgba(255,255,255,.4); }
.sidebar .nav-link.active { color:#fff; background:rgba(255,255,255,.15); border-left-color:#fff; font-weight:600; }
.sidebar .nav-link i { font-size:1rem; width:18px; }
.sidebar-divider { border-color:rgba(255,255,255,.15); margin:.5rem 1rem; }
.nav-section-label { padding:.75rem 1.25rem .25rem; font-size:.68rem; font-weight:700; color:rgba(255,255,255,.45); letter-spacing:.08em; text-transform:uppercase; }

/* ── Topbar ── */
.topbar { background:#fff; border-bottom:1px solid #e8dede; padding:.7rem 1.5rem; margin-left:var(--sidebar-w); position:sticky; top:0; z-index:99; display:flex; align-items:center; gap:10px; }
.topbar-title { flex:1; font-weight:700; font-size:1rem; color:#800000; }
.topbar-user { display:flex; align-items:center; gap:8px; font-size:.85rem; color:#666; }
.user-avatar { width:32px; height:32px; border-radius:50%; background:#800000; color:#fff; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; }

/* ── Main ── */
.main-content { margin-left:var(--sidebar-w); padding:1.5rem; }
.section-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(128,0,0,.07); overflow:hidden; margin-bottom:1.5rem; }
.section-header { padding:1rem 1.25rem; border-bottom:1px solid #f0e5e5; font-weight:700; font-size:.9rem; color:#800000; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem; }
.btn-maroon { background:#800000; color:#fff; border:none; }
.btn-maroon:hover { background:#6b0000; color:#fff; }

/* ── Stats strip ── */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.5rem; }
.stat-card { background:#fff; border-radius:12px; padding:1rem 1.25rem; box-shadow:0 2px 8px rgba(128,0,0,.06); border-left:4px solid #800000; }
.stat-card.assigned { border-left-color:#0f5132; }
.stat-card.unassigned { border-left-color:#856404; }
.stat-card.vehicles { border-left-color:#0a3678; }
.stat-lbl { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#aaa; margin-bottom:4px; }
.stat-val { font-size:1.8rem; font-weight:800; color:#1a1a1a; line-height:1; }
.stat-sub { font-size:.72rem; color:#888; margin-top:3px; }

/* ── Driver cards grid ── */
.driver-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px,1fr)); gap:1rem; padding:1.25rem; }
.driver-card {
    background:#fff;
    border:1.5px solid #f0e5e5;
    border-radius:14px;
    padding:1rem;
    transition:box-shadow .2s, border-color .2s;
    position:relative;
    overflow:hidden;
}
.driver-card:hover { box-shadow:0 4px 20px rgba(128,0,0,.1); border-color:#e0cece; }
.driver-card.has-vehicle { border-left:4px solid #0f5132; }
.driver-card.no-vehicle  { border-left:4px solid #e0cece; }
.dc-top { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.dc-avatar {
    width:44px; height:44px; border-radius:50%;
    background:linear-gradient(135deg,#800000,#6b0000);
    color:#fff; display:flex; align-items:center; justify-content:center;
    font-size:1.1rem; font-weight:700; flex-shrink:0;
}
.dc-name { font-weight:700; font-size:.95rem; color:#1a1a1a; }
.dc-license { font-size:.75rem; color:#888; margin-top:1px; }
.dc-status-pill { font-size:.7rem; font-weight:700; padding:2px 9px; border-radius:20px; }
.pill-assigned   { background:#d1e7dd; color:#0f5132; }
.pill-unassigned { background:#fff3cd; color:#856404; }
.pill-inactive   { background:#e2e3e5; color:#41464b; }
.dc-vehicle-box {
    background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;
    padding:9px 12px; margin-bottom:10px;
    display:flex; align-items:center; gap:10px;
}
.dc-vehicle-icon { font-size:1.2rem; color:#0f5132; }
.dc-vehicle-name { font-weight:700; font-size:.85rem; color:#0f5132; }
.dc-vehicle-plate { font-size:.72rem; color:#16a34a; }
.dc-no-vehicle {
    background:#fdf5f5; border:1px dashed #e0cece; border-radius:10px;
    padding:9px 12px; margin-bottom:10px; text-align:center;
    font-size:.8rem; color:#bbb; font-style:italic;
}
.dc-actions { display:flex; gap:6px; }
.btn-sm-assign { flex:1; padding:7px 10px; border-radius:9px; font-size:.78rem; font-weight:600; border:none; cursor:pointer; transition:all .15s; display:flex; align-items:center; justify-content:center; gap:5px; }
.btn-assign-primary { background:#800000; color:#fff; }
.btn-assign-primary:hover { background:#6b0000; }
.btn-assign-danger  { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.btn-assign-danger:hover  { background:#fee2e2; }
.dc-notes { font-size:.72rem; color:#888; margin-top:6px; font-style:italic; }

/* ── Custom Select ── */
.custom-select-wrap { position:relative; }
.custom-select-trigger {
    width:100%; padding:10px 40px 10px 14px;
    border:1.5px solid #e0d0d0; border-radius:10px;
    background:#fff; font-size:.88rem; color:#333;
    cursor:pointer; display:flex; align-items:center; gap:10px;
    transition:border-color .2s, box-shadow .2s;
    user-select:none; position:relative; min-height:46px;
}
.custom-select-trigger:focus,
.custom-select-trigger.open { border-color:#800000; box-shadow:0 0 0 3px rgba(128,0,0,.1); outline:none; }
.custom-select-trigger::after {
    content:''; position:absolute; right:14px; top:50%;
    transform:translateY(-50%) rotate(0deg);
    border:5px solid transparent; border-top:6px solid #888;
    transition:transform .2s; pointer-events:none;
    margin-top:3px;
}
.custom-select-trigger.open::after { transform:translateY(-50%) rotate(180deg); margin-top:-3px; }
.custom-select-icon { font-size:1rem; flex-shrink:0; }
.custom-select-text { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; line-height:1.3; }
.custom-select-text .cs-label { font-weight:600; font-size:.88rem; display:block; }
.custom-select-text .cs-sub   { font-size:.72rem; color:#888; display:block; }
.custom-select-placeholder { color:#bbb; font-size:.88rem; }
.custom-select-dropdown {
    position:absolute; top:calc(100% + 6px); left:0; right:0; z-index:500;
    background:#fff; border:1.5px solid #e0d0d0; border-radius:12px;
    box-shadow:0 8px 30px rgba(0,0,0,.12); max-height:260px; overflow-y:auto;
    display:none; padding:6px;
}
.custom-select-dropdown.open { display:block; }
.cs-search-wrap {
    display:flex; align-items:center; gap:6px;
    background:#f8f8f8; border-radius:8px; padding:6px 10px; margin-bottom:6px;
}
.cs-search-input {
    flex:1; border:none; background:transparent;
    font-size:.84rem; outline:none; color:#333;
}
.cs-option {
    display:flex; align-items:center; gap:10px;
    padding:9px 10px; border-radius:8px; cursor:pointer;
    transition:background .12s; position:relative;
}
.cs-option:hover { background:#fdf5f5; }
.cs-option.selected { background:#fdf5f5; }
.cs-option.selected::after { content:'\F272'; font-family:'bootstrap-icons'; position:absolute; right:10px; color:#800000; font-size:.9rem; }
.cs-option.disabled { opacity:.45; cursor:not-allowed; pointer-events:none; }
.cs-opt-icon { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; }
.cs-opt-icon.driver-ico  { background:#fdf5f5; color:#800000; }
.cs-opt-icon.vehicle-ico { background:#eff6ff; color:#1d4ed8; }
.cs-opt-label { font-weight:600; font-size:.84rem; color:#1a1a1a; }
.cs-opt-sub   { font-size:.7rem; color:#888; }
.cs-opt-badge { font-size:.66rem; font-weight:700; padding:1px 7px; border-radius:10px; margin-left:auto; flex-shrink:0; }
.badge-assigned   { background:#d1e7dd; color:#0f5132; }
.badge-unassigned { background:#fff3cd; color:#856404; }
.badge-busy       { background:#f8d7da; color:#842029; }
.cs-empty { text-align:center; padding:16px; color:#bbb; font-size:.82rem; }
.cs-section-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#aaa; padding:6px 10px 2px; }

/* ── Modal ── */
.mh-maroon { background:linear-gradient(135deg,#800000,#6b0000); color:#fff; }
.mh-maroon .btn-close { filter:invert(1); }
.assign-modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }

/* ── Table (vehicle list) ── */
.table thead th { background:#fdf5f5; color:#800000; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; border-bottom:2px solid #f0e5e5; padding:.75rem 1rem; white-space:nowrap; }
.table tbody td { padding:.7rem 1rem; font-size:.85rem; color:#444; vertical-align:middle; border-color:#fdf5f5; }
.table tbody tr:hover { background:#fdf8f8; }
.v-status-pill { font-size:.72rem; font-weight:700; padding:3px 10px; border-radius:20px; }
.v-available   { background:#d1e7dd; color:#0f5132; }
.v-busy        { background:#fff3cd; color:#856404; }
.v-inactive    { background:#e2e3e5; color:#41464b; }

/* ── Toast ── */
#toast-wrap { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; display:flex; flex-direction:column; gap:.5rem; }
.toast-item { padding:.75rem 1.25rem; border-radius:10px; font-size:.85rem; font-weight:600; box-shadow:0 4px 16px rgba(0,0,0,.15); animation:slideIn .3s ease; }
.toast-success { background:#d1e7dd; color:#0f5132; border-left:4px solid #0f5132; }
.toast-danger  { background:#f8d7da; color:#842029; border-left:4px solid #842029; }
@keyframes slideIn { from{transform:translateX(120%);opacity:0} to{transform:translateX(0);opacity:1} }

/* ── Tab nav ── */
.tab-nav { display:flex; gap:4px; padding:0 1.25rem; border-bottom:1px solid #f0e5e5; }
.tab-btn { padding:.65rem 1rem; font-size:.84rem; font-weight:600; color:#888; background:none; border:none; cursor:pointer; border-bottom:2.5px solid transparent; transition:all .15s; }
.tab-btn.active { color:#800000; border-bottom-color:#800000; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* Mobile */
.hamburger-btn { display:none; background:none; border:none; cursor:pointer; padding:4px 8px; color:#800000; font-size:1.4rem; align-items:center; line-height:1; }
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:399; }
.sidebar-overlay.show { display:block; }
@media (max-width:900px) {
    .sidebar { transform:translateX(-100%); transition:transform .25s ease; }
    .sidebar.open { transform:translateX(0); }
    .topbar, .main-content { margin-left:0 !important; }
    .hamburger-btn { display:flex !important; }
    .stats-row { grid-template-columns:1fr 1fr; }
    .driver-grid { grid-template-columns:1fr; }
    .assign-modal-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div id="toast-wrap"></div>

<div class="sidebar" id="mainSidebar">
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
        <a class="nav-link" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link active" href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="nav-link" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="nav-section-label">Scheduling</div>
        <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="nav-section-label">Settings</div>
        <a class="nav-link" href="notification.php" style="justify-content:space-between">
            <span style="display:flex;align-items:center;gap:10px"><i class="bi bi-bell"></i>Notifications</span>
            <?php if($_sidebarUnread > 0): ?>
            <span style="background:#e24b4a;color:#fff;font-size:.62rem;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;"><?= $_sidebarUnread > 99 ? '99+' : $_sidebarUnread ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-link" href="Signatories.php"><i class="bi bi-pen"></i>Signatories</a>
        <hr class="sidebar-divider">
        <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>
    </nav>
</div>

<div class="sidebar-overlay" id="dvSidebarOverlay" onclick="document.getElementById('mainSidebar').classList.remove('open');this.classList.remove('show')"></div>

<div class="topbar">
    <button class="hamburger-btn" onclick="document.getElementById('mainSidebar').classList.toggle('open');document.getElementById('dvSidebarOverlay').classList.toggle('show')" aria-label="Menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-link-45deg me-2"></i>Driver-Vehicle Assignments</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<div class="main-content">

<?php
$icons   = ['success'=>'check-circle','danger'=>'x-circle','warning'=>'exclamation-triangle'];
$borders = ['success'=>'#0f5132','danger'=>'#842029','warning'=>'#856404'];
foreach (['success','danger','warning'] as $type):
    if (!empty($_SESSION['flash'][$type])): ?>
<div class="alert alert-<?=$type?> alert-dismissible fade show mb-3" role="alert" style="font-size:.87rem;border-radius:10px;border-left:4px solid <?=$borders[$type]?>">
    <i class="bi bi-<?=$icons[$type]?> me-2"></i><?=htmlspecialchars($_SESSION['flash'][$type])?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash'][$type]); endif; endforeach; ?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-lbl">Total Drivers</div>
        <div class="stat-val"><?= count($drivers) ?></div>
        <div class="stat-sub">in your office</div>
    </div>
    <div class="stat-card assigned">
        <div class="stat-lbl">Assigned</div>
        <div class="stat-val"><?= $assignedCount ?></div>
        <div class="stat-sub">have a vehicle</div>
    </div>
    <div class="stat-card unassigned">
        <div class="stat-lbl">Unassigned</div>
        <div class="stat-val"><?= $unassignedCount ?></div>
        <div class="stat-sub">need assignment</div>
    </div>
    <div class="stat-card vehicles">
        <div class="stat-lbl">Total Vehicles</div>
        <div class="stat-val"><?= count($vehicles) ?></div>
        <div class="stat-sub">available fleet</div>
    </div>
</div>

<!-- Main card -->
<div class="section-card">
    <div class="section-header">
        <span><i class="bi bi-link-45deg me-2"></i>Manage Assignments
            <small class="ms-2" style="font-size:.75rem;color:#a05050;font-weight:400"><i class="bi bi-building me-1"></i><?=htmlspecialchars($officeName)?></small>
        </span>
        <button class="btn btn-maroon btn-sm" onclick="openAssignModal()">
            <i class="bi bi-plus-lg me-1"></i>New Assignment
        </button>
    </div>
    <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('drivers',this)"><i class="bi bi-person-badge me-1"></i>Drivers View</button>
        <button class="tab-btn" onclick="switchTab('vehicles',this)"><i class="bi bi-truck-front me-1"></i>Vehicles View</button>
    </div>

    <!-- Tab: Drivers -->
    <div class="tab-pane active" id="tab-drivers">
        <div class="driver-grid">
        <?php foreach($drivers as $d):
            $hasVeh = !empty($d['assigned_vehicle_id']);
            $statusCls = match(strtolower($d['status']??'')) {
                'available','active' => 'pill-assigned',
                default => 'pill-inactive'
            };
            $initials = strtoupper(implode('', array_map(fn($p) => $p[0], explode(' ', trim($d['driver_name']??'D')))));
            $initials  = substr($initials, 0, 2);
        ?>
        <div class="driver-card <?= $hasVeh ? 'has-vehicle' : 'no-vehicle' ?>">
            <div class="dc-top">
                <div class="dc-avatar"><?= htmlspecialchars($initials) ?></div>
                <div style="flex:1;min-width:0">
                    <div class="dc-name"><?= htmlspecialchars($d['driver_name']) ?></div>
                    <div class="dc-license"><i class="bi bi-card-text me-1"></i><?= htmlspecialchars($d['license_number']??'No license no.') ?></div>
                </div>
                <span class="dc-status-pill <?= $statusCls ?>"><?= htmlspecialchars(ucfirst($d['status']??'—')) ?></span>
            </div>

            <?php if($hasVeh): ?>
            <div class="dc-vehicle-box">
                <i class="bi bi-truck-front dc-vehicle-icon"></i>
                <div style="flex:1;min-width:0">
                    <div class="dc-vehicle-name"><?= htmlspecialchars($d['brand'].' '.$d['model']) ?></div>
                    <div class="dc-vehicle-plate"><?= htmlspecialchars($d['plate_number']) ?> · <?= htmlspecialchars($d['vehicle_type'] ?? $d['type'] ?? '—') ?></div>
                </div>
                <span class="dc-status-pill badge-assigned">Assigned</span>
            </div>
            <?php if(!empty($d['assignment_notes'])): ?>
            <div class="dc-notes"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($d['assignment_notes']) ?></div>
            <?php endif; ?>
            <?php else: ?>
            <div class="dc-no-vehicle"><i class="bi bi-dash-circle me-1"></i>No vehicle assigned</div>
            <?php endif; ?>

            <div class="dc-actions">
                <button class="btn-sm-assign btn-assign-primary" onclick="openAssignModalFor(<?= $d['driver_id'] ?>, '<?= htmlspecialchars($d['driver_name'], ENT_QUOTES) ?>', <?= (int)($d['assigned_vehicle_id']??0) ?>)">
                    <i class="bi bi-<?= $hasVeh ? 'arrow-repeat' : 'plus-lg' ?>"></i>
                    <?= $hasVeh ? 'Change Vehicle' : 'Assign Vehicle' ?>
                </button>
                <?php if($hasVeh): ?>
                <form method="POST" style="margin:0" onsubmit="return confirm('Remove this assignment?')">
                    <input type="hidden" name="action" value="unassign">
                    <input type="hidden" name="driver_id" value="<?= $d['driver_id'] ?>">
                    <button type="submit" class="btn-sm-assign btn-assign-danger" style="min-width:44px">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($drivers)): ?>
        <div style="text-align:center;padding:3rem;color:#bbb;grid-column:1/-1"><i class="bi bi-person-badge" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.3"></i>No drivers found.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Vehicles -->
    <div class="tab-pane" id="tab-vehicles">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr>
                    <th>Vehicle</th><th>Plate</th><th>Type</th><th>Status</th><th>Assigned Driver</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach($vehicles as $v):
                    $hasDrv = !empty($v['assigned_driver_id']);
                    $stCls  = match(strtolower($v['status']??'')) {
                        'available','active' => 'v-available',
                        'busy','on trip'     => 'v-busy',
                        default             => 'v-inactive'
                    };
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($v['brand'].' '.$v['model']) ?></strong></td>
                    <td><span style="font-size:.8rem;font-weight:700;background:#f5f5f5;padding:2px 8px;border-radius:6px"><?= htmlspecialchars($v['plate_number']) ?></span></td>
                    <td><?= htmlspecialchars($v['vehicle_type']??'—') ?></td>
                    <td><span class="v-status-pill <?= $stCls ?>"><?= htmlspecialchars(ucfirst($v['status']??'—')) ?></span></td>
                    <td>
                        <?php if($hasDrv): ?>
                        <div style="display:flex;align-items:center;gap:7px">
                            <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#800000,#6b0000);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700"><?= strtoupper(substr($v['assigned_driver_name'],0,1)) ?></div>
                            <span style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($v['assigned_driver_name']) ?></span>
                        </div>
                        <?php else: ?>
                        <span style="color:#bbb;font-size:.82rem;font-style:italic">No driver assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($hasDrv): ?>
                        <form method="POST" style="margin:0;display:inline" onsubmit="return confirm('Remove assignment for this vehicle?')">
                            <input type="hidden" name="action" value="unassign">
                            <input type="hidden" name="driver_id" value="<?= $v['assigned_driver_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-x-lg me-1"></i>Remove
                            </button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-success" onclick="openAssignModalForVehicle(<?= $v['vehicle_id'] ?>, '<?= htmlspecialchars($v['brand'].' '.$v['model'].' ('.$v['plate_number'].')', ENT_QUOTES) ?>')">
                            <i class="bi bi-plus-lg me-1"></i>Assign Driver
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($vehicles)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-truck fs-4 d-block mb-2 opacity-30"></i>No vehicles found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- end main-content -->

<!-- ═══ ASSIGN MODAL ═══ -->
<div class="modal fade" id="assignModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header mh-maroon">
        <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Assign Vehicle to Driver</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST" action="drivervehicle.php" id="assignForm">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="driver_id" id="modal_driver_id">
        <input type="hidden" name="vehicle_id" id="modal_vehicle_id">
        <div class="modal-body">
            <div class="assign-modal-grid">
                <!-- Driver picker -->
                <div>
                    <label style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#666;margin-bottom:8px;display:block">
                        <i class="bi bi-person-badge me-1 text-danger"></i>Driver <span class="text-danger">*</span>
                    </label>
                    <div class="custom-select-wrap" id="driverSelectWrap">
                        <div class="custom-select-trigger" id="driverSelectTrigger" tabindex="0" onclick="toggleCS('driver')">
                            <span class="custom-select-placeholder" id="driverSelectDisplay">— Select Driver —</span>
                        </div>
                        <div class="custom-select-dropdown" id="driverSelectDropdown">
                            <div class="cs-search-wrap">
                                <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                                <input class="cs-search-input" placeholder="Search driver…" oninput="filterCS('driver',this.value)" id="driverCSSearch">
                            </div>
                            <div id="driverCSOptions">
                            <?php foreach($drivers as $d):
                                $hasV = !empty($d['assigned_vehicle_id']);
                                $inactive = !in_array(strtolower($d['status']??''), ['available','active']);
                                $ini = strtoupper(implode('', array_map(fn($p) => $p[0], explode(' ', trim($d['driver_name']??'D')))));
                                $ini = substr($ini, 0, 2);
                            ?>
                            <div class="cs-option <?= $inactive ? 'disabled' : '' ?>"
                                 data-value="<?= $d['driver_id'] ?>"
                                 data-label="<?= htmlspecialchars($d['driver_name'], ENT_QUOTES) ?>"
                                 data-sub="<?= htmlspecialchars($d['license_number']??'', ENT_QUOTES) ?>"
                                 data-has-vehicle="<?= $hasV ? '1' : '0' ?>"
                                 data-vehicle-id="<?= (int)($d['assigned_vehicle_id']??0) ?>"
                                 onclick="selectCS('driver',this)">
                                <div class="cs-opt-icon driver-ico"><?= htmlspecialchars($ini) ?></div>
                                <div style="flex:1;min-width:0">
                                    <div class="cs-opt-label"><?= htmlspecialchars($d['driver_name']) ?></div>
                                    <div class="cs-opt-sub"><?= htmlspecialchars($d['license_number']??'No license') ?></div>
                                </div>
                                <span class="cs-opt-badge <?= $hasV ? 'badge-assigned' : 'badge-unassigned' ?>">
                                    <?= $hasV ? 'Has vehicle' : 'Unassigned' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Vehicle picker -->
                <div>
                    <label style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#666;margin-bottom:8px;display:block">
                        <i class="bi bi-truck-front me-1 text-primary"></i>Vehicle <span class="text-danger">*</span>
                    </label>
                    <div class="custom-select-wrap" id="vehicleSelectWrap">
                        <div class="custom-select-trigger" id="vehicleSelectTrigger" tabindex="0" onclick="toggleCS('vehicle')">
                            <span class="custom-select-placeholder" id="vehicleSelectDisplay">— Select Vehicle —</span>
                        </div>
                        <div class="custom-select-dropdown" id="vehicleSelectDropdown">
                            <div class="cs-search-wrap">
                                <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                                <input class="cs-search-input" placeholder="Search vehicle…" oninput="filterCS('vehicle',this.value)" id="vehicleCSSearch">
                            </div>
                            <div id="vehicleCSOptions">
                            <?php foreach($vehicles as $v):
                                $hasDrv = !empty($v['assigned_driver_id']);
                                $inactive = !in_array(strtolower($v['status']??''), ['available','active']);
                            ?>
                            <div class="cs-option <?= $inactive ? 'disabled' : '' ?>"
                                 data-value="<?= $v['vehicle_id'] ?>"
                                 data-label="<?= htmlspecialchars($v['brand'].' '.$v['model'], ENT_QUOTES) ?>"
                                 data-sub="<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>"
                                 data-has-driver="<?= $hasDrv ? '1' : '0' ?>"
                                 onclick="selectCS('vehicle',this)">
                                <div class="cs-opt-icon vehicle-ico"><i class="bi bi-truck-front"></i></div>
                                <div style="flex:1;min-width:0">
                                    <div class="cs-opt-label"><?= htmlspecialchars($v['brand'].' '.$v['model']) ?></div>
                                    <div class="cs-opt-sub"><?= htmlspecialchars($v['plate_number']) ?> · <?= htmlspecialchars($v['vehicle_type'] ?? $v['type'] ?? '—') ?></div>
                                </div>
                                <span class="cs-opt-badge <?= $hasDrv ? 'badge-assigned' : 'badge-unassigned' ?>">
                                    <?= $hasDrv ? 'Driver: '.$v['assigned_driver_name'] : 'Free' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Preview -->
            <div id="assignPreview" style="display:none;margin-top:1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px 16px">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#166534;margin-bottom:8px"><i class="bi bi-eye me-1"></i>Assignment Preview</div>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <div id="prev_driver" style="display:flex;align-items:center;gap:8px"></div>
                    <i class="bi bi-arrow-right" style="color:#aaa;font-size:1.1rem"></i>
                    <div id="prev_vehicle" style="display:flex;align-items:center;gap:8px"></div>
                </div>
            </div>
            <!-- Notes -->
            <div style="margin-top:1rem">
                <label style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#666;margin-bottom:6px;display:block">
                    <i class="bi bi-chat-left-text me-1"></i>Notes (optional)
                </label>
                <input type="text" name="notes" id="modal_notes" class="form-control" maxlength="500" placeholder="e.g. Primary vehicle for long trips">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" id="assignSaveBtn" class="btn btn-maroon" disabled>
                <i class="bi bi-link-45deg me-1"></i>Save Assignment
            </button>
        </div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Tab switcher ── */
function switchTab(name, btn){
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    btn.classList.add('active');
}

/* ── Custom Select state ── */
const csState = {
    driver:  { value: null, label: '', sub: '' },
    vehicle: { value: null, label: '', sub: '' }
};

function toggleCS(which){
    const drop = document.getElementById(which+'SelectDropdown');
    const trig = document.getElementById(which+'SelectTrigger');
    const isOpen = drop.classList.contains('open');
    closeAllCS();
    if(!isOpen){
        drop.classList.add('open');
        trig.classList.add('open');
        const si = document.getElementById(which+'CSSearch');
        if(si){ si.value=''; filterCS(which,''); si.focus(); }
    }
}
function closeAllCS(){
    ['driver','vehicle'].forEach(w => {
        document.getElementById(w+'SelectDropdown').classList.remove('open');
        document.getElementById(w+'SelectTrigger').classList.remove('open');
    });
}
document.addEventListener('click', e => {
    if(!e.target.closest('.custom-select-wrap')) closeAllCS();
});

function selectCS(which, el){
    const val   = el.dataset.value;
    const label = el.dataset.label;
    const sub   = el.dataset.sub;
    csState[which] = { value: val, label, sub };

    // Update hidden input
    document.getElementById('modal_'+which+'_id').value = val;

    // Update trigger display
    const disp = document.getElementById(which+'SelectDisplay');
    disp.outerHTML = `<div id="${which}SelectDisplay" style="display:flex;align-items:center;gap:10px;flex:1">
        <div style="width:32px;height:32px;border-radius:8px;background:${which==='driver'?'#fdf5f5':'#eff6ff'};display:flex;align-items:center;justify-content:center;font-size:.85rem;color:${which==='driver'?'#800000':'#1d4ed8'};flex-shrink:0">
            ${which==='driver' ? el.querySelector('.cs-opt-icon').textContent : '<i class="bi bi-truck-front"></i>'}
        </div>
        <div style="min-width:0">
            <div style="font-weight:600;font-size:.88rem;color:#1a1a1a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${label}</div>
            <div style="font-size:.72rem;color:#888">${sub}</div>
        </div>
    </div>`;

    // Mark selected
    document.querySelectorAll('#'+which+'CSOptions .cs-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    closeAllCS();
    updatePreview();
    checkSaveBtn();

    // Auto-select vehicle when driver chosen
    if(which === 'driver' && el.dataset.hasVehicle === '1'){
        const vid = el.dataset.vehicleId;
        if(vid && vid !== '0'){
            const vEl = document.querySelector(`#vehicleCSOptions .cs-option[data-value="${vid}"]`);
            if(vEl) selectCS('vehicle', vEl);
        }
    }
}

function filterCS(which, q){
    const opts = document.querySelectorAll('#'+which+'CSOptions .cs-option');
    let any = false;
    opts.forEach(o => {
        const txt = (o.dataset.label+' '+o.dataset.sub).toLowerCase();
        const show = !q || txt.includes(q.toLowerCase());
        o.style.display = show ? '' : 'none';
        if(show) any = true;
    });
    let empty = document.getElementById(which+'CSEmpty');
    if(!any){
        if(!empty){ empty = document.createElement('div'); empty.className='cs-empty'; empty.id=which+'CSEmpty'; empty.textContent='No results found.'; document.getElementById(which+'CSOptions').after(empty); }
        empty.style.display='';
    } else if(empty) empty.style.display='none';
}

function updatePreview(){
    const d = csState.driver, v = csState.vehicle;
    const prev = document.getElementById('assignPreview');
    if(!d.value || !v.value){ prev.style.display='none'; return; }
    prev.style.display='';
    document.getElementById('prev_driver').innerHTML = `
        <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#800000,#6b0000);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700">
            ${d.label.trim().split(' ').map(p=>p[0]).join('').substring(0,2).toUpperCase()}
        </div>
        <div><div style="font-weight:700;font-size:.88rem">${d.label}</div><div style="font-size:.72rem;color:#888">Driver</div></div>`;
    document.getElementById('prev_vehicle').innerHTML = `
        <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#0a3678);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.9rem">
            <i class="bi bi-truck-front"></i>
        </div>
        <div><div style="font-weight:700;font-size:.88rem">${v.label}</div><div style="font-size:.72rem;color:#888">${v.sub}</div></div>`;
}

function checkSaveBtn(){
    document.getElementById('assignSaveBtn').disabled = !(csState.driver.value && csState.vehicle.value);
}

/* ── Open modal helpers ── */
function openAssignModal(){
    resetModal();
    new bootstrap.Modal(document.getElementById('assignModal')).show();
}
function openAssignModalFor(driverId, driverName, vehicleId){
    resetModal();
    const dEl = document.querySelector(`#driverCSOptions .cs-option[data-value="${driverId}"]`);
    if(dEl) selectCS('driver', dEl);
    new bootstrap.Modal(document.getElementById('assignModal')).show();
}
function openAssignModalForVehicle(vehicleId, vehicleLabel){
    resetModal();
    const vEl = document.querySelector(`#vehicleCSOptions .cs-option[data-value="${vehicleId}"]`);
    if(vEl) selectCS('vehicle', vEl);
    new bootstrap.Modal(document.getElementById('assignModal')).show();
}

function resetModal(){
    csState.driver  = { value:null, label:'', sub:'' };
    csState.vehicle = { value:null, label:'', sub:'' };
    document.getElementById('modal_driver_id').value  = '';
    document.getElementById('modal_vehicle_id').value = '';
    document.getElementById('modal_notes').value = '';
    document.getElementById('assignSaveBtn').disabled = true;
    document.getElementById('assignPreview').style.display = 'none';
    ['driver','vehicle'].forEach(w => {
        const t = document.getElementById(w+'SelectTrigger');
        t.innerHTML = `<span class="custom-select-placeholder" id="${w}SelectDisplay">— Select ${w.charAt(0).toUpperCase()+w.slice(1)} —</span>`;
        document.querySelectorAll(`#${w}CSOptions .cs-option`).forEach(o => o.classList.remove('selected'));
    });
}

/* ── Toast ── */
function showToast(msg, type='success'){
    const c=document.getElementById('toast-wrap'), t=document.createElement('div');
    t.className='toast-item toast-'+type; t.textContent=msg; c.appendChild(t);
    setTimeout(()=>t.remove(), 3500);
}
</script>
</body>
</html>