<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
$_SESSION['just_logged_in'] = true;
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header("Location: /csuweb/login.php?error=unauthorized"); exit;
}

/* ── Current staff user + office ── */
$cu = $pdo->prepare("
    SELECT u.*, o.office_id AS u_office_id, o.office_name
    FROM users u
    LEFT JOIN offices o ON u.office_id = o.office_id
    WHERE u.user_id = ?
");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();
$myOfficeId = (int)($me['u_office_id'] ?? 0);

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$_SESSION['user_id'], $myOfficeId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/* ── Stats ── */
$stmtTR = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='requestor' AND office_id=?");
$stmtTR->execute([$myOfficeId]);
$totalRequestors = (int)$stmtTR->fetchColumn();

try {
    $stmtNR = $pdo->prepare("
        SELECT COUNT(*) FROM users
        WHERE role='requestor' AND office_id=?
        AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmtNR->execute([$myOfficeId]);
    $newRequestors = (int)$stmtNR->fetchColumn();
} catch (\PDOException $e) {
    $newRequestors = 0;
}

$stmtTS = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE office_id=?");
$stmtTS->execute([$myOfficeId]);
$totalSchedules = (int)$stmtTS->fetchColumn();

$stmtPS = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE office_id=? AND status='Pending'");
$stmtPS->execute([$myOfficeId]);
$pendingSchedules = (int)$stmtPS->fetchColumn();

/* ── Today's trips ── */
$stmtTD = $pdo->prepare("
    SELECT s.*, u.username, COALESCE(d.dept_name,'—') AS dept_name,
           v.plate_number,
           v.model AS vehicle_model
    FROM schedules s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN departments d ON s.department_id = d.dept_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    WHERE s.office_id = ?
      AND CURDATE() BETWEEN s.date_start AND s.date_end
    ORDER BY s.time_start ASC
");
$stmtTD->execute([$myOfficeId]);
$todaysTrips = $stmtTD->fetchAll();
$todayCount  = count($todaysTrips);

/* ── Recent schedules ── */
$stmtRS = $pdo->prepare("
    SELECT s.*, u.username, COALESCE(d.dept_name,'—') AS dept_name
    FROM schedules s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN departments d ON s.department_id = d.dept_id
    WHERE s.office_id = ?
    ORDER BY s.schedule_id DESC LIMIT 10
");
$stmtRS->execute([$myOfficeId]);
$recentSchedules = $stmtRS->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>Staff Dashboard – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; }
.notif-bell-btn {
    position: relative;
    background: #fdecea;
    border: none;
    border-radius: 50%;
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; color: #800000;
    cursor: pointer;
    transition: background .15s, transform .15s;
    text-decoration: none;
}
.notif-bell-btn:hover { background: #800000; color: #fff; transform: scale(1.08); }
.notif-badge {
    position: absolute;
    top: -3px; right: -3px;
    background: #dc3545; color: #fff;
    border-radius: 10px; padding: 1px 5px;
    font-size: .6rem; font-weight: 800;
    line-height: 1.4; min-width: 16px; text-align: center;
    border: 2px solid #fff;
    display: none;  /* shown by JS when count > 0 */
}
 
/* Notification Dropdown */
.notif-dropdown {
    position: absolute;
    top: calc(100% + 10px); right: 0;
    width: 360px; max-height: 480px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(128,0,0,0.18);
    border: 1px solid #f0e5e5;
    z-index: 1000;
    overflow: hidden;
    display: none;
    flex-direction: column;
}
.notif-dropdown.open { display: flex; }
.notif-drop-header {
    padding: .85rem 1.1rem .6rem;
    border-bottom: 1px solid #f0e5e5;
    display: flex; align-items: center; justify-content: space-between;
}
.notif-drop-header h6 {
    font-weight: 800; font-size: .85rem; color: #800000; margin: 0;
}
.notif-drop-header a {
    font-size: .72rem; color: #800000; text-decoration: none; font-weight: 600;
}
.notif-drop-header a:hover { text-decoration: underline; }
.notif-drop-body {
    overflow-y: auto; flex: 1;
}
.notif-drop-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: .7rem 1.1rem;
    border-bottom: 1px solid #fdf5f5;
    cursor: pointer; transition: background .12s;
    text-decoration: none;
}
.notif-drop-item:hover { background: #fdf8f8; }
.notif-drop-item.unread { background: #fffaf9; }
.notif-drop-icon {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
}
.notif-drop-title {
    font-size: .8rem; font-weight: 700; color: #2d2d2d;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 2px;
}
.notif-drop-msg  { font-size: .73rem; color: #888; line-height: 1.4; }
.notif-drop-time { font-size: .68rem; color: #bbb; margin-top: 3px; }
.notif-drop-unread-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #800000; flex-shrink: 0; margin-top: 6px;
}
.notif-drop-empty {
    text-align: center; padding: 2rem 1rem; color: #ccc;
}
.notif-drop-empty i { font-size: 2rem; display: block; margin-bottom: .5rem; }
.notif-drop-footer {
    padding: .65rem 1.1rem;
    border-top: 1px solid #f0e5e5;
    text-align: center;
}
.notif-drop-footer a {
    font-size: .78rem; color: #800000; font-weight: 700;
    text-decoration: none;
}
.notif-drop-footer a:hover { text-decoration: underline; }
/* ── Sidebar ── */
.sidebar {
    min-height: 100vh;
    background: linear-gradient(180deg, #800000 0%, #6b0000 100%);
    width: 240px; position: fixed; top: 0; left: 0;
    z-index: 100; display: flex; flex-direction: column;
}
.sidebar-brand {
    padding: 1.25rem 1rem 1rem;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    display: flex; align-items: center; gap: 10px;
}
.sidebar-logo {
    width: 42px; height: 42px; border-radius: 50%;
    background: #fff; overflow: hidden;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sidebar-logo img { width: 38px; height: 38px; object-fit: contain; }
.sidebar-brand-text { color: #fff; font-size: 0.82rem; font-weight: 700; line-height: 1.3; }
.sidebar-brand-text span { display: block; font-size: 0.72rem; font-weight: 400; opacity: 0.7; }
.sidebar .nav-link {
    color: rgba(255,255,255,0.8); padding: 0.6rem 1.25rem;
    font-size: 0.88rem; display: flex; align-items: center; gap: 10px;
    border-left: 3px solid transparent; transition: all 0.15s;
}
.sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); border-left-color: rgba(255,255,255,0.4); }
.sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.15); border-left-color: #fff; font-weight: 600; }
.sidebar .nav-link i { font-size: 1rem; width: 18px; }
.sidebar-divider { border-color: rgba(255,255,255,0.15); margin: 0.5rem 1rem; }
.nav-section-label {
    padding: 0.75rem 1.25rem 0.25rem;
    font-size: 0.68rem; font-weight: 700;
    color: rgba(255,255,255,0.45);
    letter-spacing: 0.08em; text-transform: uppercase;
}

/* ── Topbar ── */
.topbar {
    background: #fff; border-bottom: 1px solid #e8dede;
    padding: 0.7rem 1.5rem; margin-left: 240px;
    position: sticky; top: 0; z-index: 99;
    display: flex; align-items: center; justify-content: space-between;
}
.topbar-title { font-weight: 700; font-size: 1rem; color: #800000; }
.topbar-user { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #666; }
.user-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: #800000; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700;
}

/* ── Main ── */
.main-content { margin-left: 240px; padding: 1.5rem; }

/* ── Welcome Banner ── */
.welcome-banner {
    background: linear-gradient(135deg, #800000 0%, #4a0000 60%, #2d0000 100%);
    color: #fff;
    border-radius: 16px;
    padding: 1.6rem 2rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 8px 32px rgba(128,0,0,0.35);
    position: relative;
    overflow: hidden;
}
.welcome-banner::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    background: rgba(255,255,255,0.04);
    border-radius: 50%;
}
.welcome-banner::after {
    content: '';
    position: absolute;
    bottom: -60px; right: 120px;
    width: 220px; height: 220px;
    background: rgba(255,255,255,0.03);
    border-radius: 50%;
}
.welcome-banner h5 {
    font-weight: 800;
    margin: 0 0 6px;
    font-size: 1.15rem;
    letter-spacing: 0.01em;
}
.welcome-banner p {
    margin: 0;
    opacity: 0.75;
    font-size: 0.86rem;
}
.banner-clock-block {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    z-index: 1;
    flex-shrink: 0;
}
.banner-time {
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: 0.03em;
    line-height: 1;
    color: #fff;
}
.banner-ampm {
    font-size: 0.8rem;
    font-weight: 600;
    opacity: 0.6;
    margin-left: 4px;
    vertical-align: super;
}
.banner-date-label {
    font-size: 0.75rem;
    opacity: 0.55;
    font-weight: 500;
    text-align: right;
}
.banner-left { z-index: 1; }
.banner-greeting-icon {
    font-size: 1.1rem;
    margin-left: 6px;
}
.banner-office-tag {
    display: inline-block;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 20px;
    padding: 1px 10px;
    font-size: 0.78rem;
    font-weight: 700;
    color: #fff;
    margin: 0 4px;
    letter-spacing: 0.03em;
}

/* ── Stat Cards ── */
.stat-card {
    border: none; border-radius: 14px;
    padding: 1.25rem 1.5rem; background: #fff;
    box-shadow: 0 2px 12px rgba(128,0,0,0.07);
    transition: transform 0.15s, box-shadow 0.15s;
    height: 100%;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(128,0,0,0.12); }
.stat-icon {
    width: 50px; height: 50px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; flex-shrink: 0;
}
.stat-label { font-size: 0.78rem; color: #999; margin-bottom: 2px; }
.stat-value { font-size: 1.6rem; font-weight: 700; color: #2d2d2d; line-height: 1; }

/* ── Section Card ── */
.section-card {
    background: #fff; border-radius: 14px;
    box-shadow: 0 2px 12px rgba(128,0,0,0.07); overflow: hidden;
}
.section-header {
    padding: 1rem 1.25rem; border-bottom: 1px solid #f0e5e5;
    font-weight: 700; font-size: 0.9rem; color: #800000;
    display: flex; align-items: center; justify-content: space-between;
}
.table thead th {
    background: #fdf5f5; color: #800000;
    font-size: 0.78rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    border-bottom: 2px solid #f0e5e5; padding: 0.75rem 1rem;
}
.table tbody td {
    padding: 0.7rem 1rem; font-size: 0.85rem;
    color: #444; vertical-align: middle; border-color: #fdf5f5;
}
.table tbody tr:hover { background: #fdf8f8; }

/* ── Badges ── */
.badge-pending  { background:#fff3cd; color:#856404; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
.badge-approved { background:#d1e7dd; color:#0f5132; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
.badge-ontrip   { background:#fff0d6; color:#7a4f00; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
.badge-completed{ background:#cfe2ff; color:#0a3678; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
.badge-rejected { background:#f8d7da; color:#842029; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }
.badge-cancelled{ background:#e2e3e5; color:#41464b; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; }

/* ── Today's Trips card highlight ── */
.today-section-header {
    background: linear-gradient(135deg, #f3e5f5, #ede0f5);
    border-bottom: 2px solid #ce93d8;
}
.today-section-header span { color: #6a1b9a; }
.today-count-pill {
    background: #6a1b9a; color: #fff;
    border-radius: 20px; padding: 2px 10px;
    font-size: .72rem; font-weight: 700; margin-left: .5rem;
}
.today-empty {
    padding: 2.5rem 1rem; text-align: center; color: #bbb;
}
.today-empty i { font-size: 2.2rem; color: #ce93d8; display: block; margin-bottom: .5rem; }
.today-empty p { font-size: .84rem; margin: 0; }
.notif-badge-pill {
    background: #e24b4a;
    color: #fff;
    font-size: .62rem;
    font-weight: 700;
    min-width: 17px;
    height: 17px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    margin-left: auto;
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<!-- ── Sidebar ── -->
<div class="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="../image/Csu.png" alt="Logo"></div>
    <div class="sidebar-brand-text">CSU Vehicle System<span>Staff Panel</span></div>
  </div>
  <nav class="nav flex-column mt-2">
    <div class="nav-section-label">Main</div>
    <a class="nav-link active" href="dashboard.php">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a>
    <div class="nav-section-label">Manage</div>
    <a class="nav-link" href="Requestors.php">
      <i class="bi bi-people"></i> Requestors
    </a>
    <div class="nav-section-label">Scheduling</div>
    <a class="nav-link" href="WalkIn.php">
      <i class="bi bi-calendar-plus"></i> Walk-in Booking
    </a>
    <a class="nav-link" href="Schedules.php">
      <i class="bi bi-calendar-check"></i> View Schedules
    </a>
    <a class="nav-link" href="CheckAvailability.php">
      <i class="bi bi-search"></i> Check Availability
    </a>
    <a class="nav-link" href="staff_driverstripcomplete.php">
      <i class="bi bi-flag-fill"></i> Driver Trip Records
    </a>
    <div class="nav-section-label">Account</div>
<a class="nav-link" href="notification.php">
    <i class="bi bi-bell"></i> Notifications
    <?php if($unreadCount > 0): ?>
    <span class="notif-badge-pill">
        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
    </span>
    <?php endif; ?>
</a>
<a class="nav-link" href="my_account.php">
    <i class="bi bi-person-circle"></i> My Account
</a>
    <hr class="sidebar-divider">
    <a class="nav-link" href="../Logout.php">
      <i class="bi bi-box-arrow-left"></i> Logout
    </a>
  </nav>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
     <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Menu">
    <i class="bi bi-list"></i>
  </button>
    <div class="topbar-title">
        <i class="bi bi-speedometer2 me-2"></i>Dashboard
    </div>
    <div class="topbar-user" style="position:relative">
 
        <!-- Notification Bell -->
        <div style="position:relative">
            <button class="notif-bell-btn" id="notifBellBtn" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <span class="notif-badge" id="notifBadge"></span>
            </button>
 
            <!-- Dropdown -->
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-drop-header">
                    <h6><i class="bi bi-bell-fill me-1"></i>Notifications</h6>
                    <a href="notification.php">View all →</a>
                </div>
                <div class="notif-drop-body" id="notifDropBody">
                    <div class="notif-drop-empty">
                        <i class="bi bi-bell-slash"></i>
                        <p style="font-size:.78rem;margin:0">Loading…</p>
                    </div>
                </div>
                <div class="notif-drop-footer">
                    <a href="notification.php">Open Notification Center</a>
                </div>
            </div>
        </div>
 
        <!-- Avatar / username -->
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:0.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:0.72rem;color:#800000">Staff — <?= htmlspecialchars($me['office_name'] ?? '—') ?></div>
        </div>
    </div>
</div>
<!-- ── Main Content ── -->
<div class="main-content">

    <?php if (function_exists('showFlash')) showFlash(); ?>

    <!-- Welcome Banner -->
    <div class="welcome-banner">
    <div class="banner-left">
        <h5>Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>! <span class="banner-greeting-icon">👋</span></h5>
        <p>
            Managing requestors &amp; walk-in bookings for
            <span class="banner-office-tag"><?= htmlspecialchars($me['office_name'] ?? '—') ?></span>
        </p>
        <p style="margin-top:6px;font-size:0.78rem;opacity:0.5">
            <i class="bi bi-calendar3 me-1"></i><?= date('l, F j, Y') ?>
        </p>
    </div>
    <div class="banner-clock-block">
        <div class="banner-time">
            <span id="liveH">--</span>:<span id="liveM">--</span>:<span id="liveS">--</span><span class="banner-ampm" id="liveAMPM"></span>
        </div>
        <div class="banner-date-label"><i class="bi bi-clock me-1"></i>Local Time</div>
    </div>
</div>

    <!-- ── Stat Cards (5 cards) ── -->
    <div class="row g-3 mb-4">

        <!-- Total Requestors -->
        <div class="col-6 col-lg">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fdecea;color:#800000">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Requestors</div>
                        <div class="stat-value"><?= $totalRequestors ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New This Week -->
        <div class="col-6 col-lg">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#d1e7dd;color:#0f5132">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>
                    <div>
                        <div class="stat-label">New This Week</div>
                        <div class="stat-value"><?= $newRequestors ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Schedules -->
        <div class="col-6 col-lg">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#cfe2ff;color:#0a3678">
                        <i class="bi bi-calendar-check-fill"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Schedules</div>
                        <div class="stat-value"><?= $totalSchedules ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Approval -->
        <div class="col-6 col-lg">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fff3cd;color:#856404">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div>
                        <div class="stat-label">Pending Approval</div>
                        <div class="stat-value"><?= $pendingSchedules ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Trips -->
        <div class="col-6 col-lg">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#f3e5f5;color:#6a1b9a">
                        <i class="bi bi-truck-front-fill"></i>
                    </div>
                    <div>
                        <div class="stat-label">Today's Trips</div>
                        <div class="stat-value"><?= $todayCount ?></div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row stat cards -->

    <!-- ── Today's Trips Table ── -->
    <div class="section-card mb-4">
        <div class="section-header today-section-header">
            <span>
                <i class="bi bi-truck-front-fill me-2"></i>
                Today's Trips
                <span class="today-count-pill"><?= $todayCount ?></span>
            </span>
            <span style="font-size:.78rem;color:#9c4dcc;font-weight:500">
                <i class="bi bi-calendar3 me-1"></i><?= date('l, F j, Y') ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Requestor</th>
                        <th>Department</th>
                        <th>Destination</th>
                        <th>Time</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($todaysTrips)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="today-empty">
                                <i class="bi bi-truck-front"></i>
                                <p style="font-weight:600;color:#c0a0d0;margin-bottom:.2rem">No trips today</p>
                                <p style="font-size:.78rem">Trips scheduled for today will appear here.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($todaysTrips as $i => $t):
                        $ts = $t['time_start'] ? date('h:i A', strtotime($t['time_start'])) : '—';
                        $te = $t['time_end']   ? date('h:i A', strtotime($t['time_end']))   : '—';
                        $vehicle = trim(($t['plate_number'] ?? '') . ' ' . ($t['vehicle_model'] ?? ''));
                        $statusClass = match(strtolower($t['status'])) {
                            'approved'  => 'badge-approved',
                            'rejected'  => 'badge-rejected',
                            'completed' => 'badge-completed',
                            'cancelled' => 'badge-cancelled',
                            'ontrip'    => 'badge-ontrip',
                            default     => 'badge-pending',
                        };
                    ?>
                    <tr>
                        <td class="text-muted" style="font-size:.78rem"><?= $i + 1 ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($t['username']) ?></td>
                        <td style="font-size:.82rem;color:#666"><?= htmlspecialchars($t['dept_name']) ?></td>
                        <td>
                            <i class="bi bi-geo-alt-fill" style="color:#800000;font-size:.75rem"></i>
                            <?= htmlspecialchars($t['destination']) ?>
                        </td>
                        <td style="white-space:nowrap;font-size:.82rem">
                            <i class="bi bi-clock" style="color:#800000;font-size:.72rem"></i>
                            <?= $ts ?> – <?= $te ?>
                        </td>
                        <td style="font-size:.82rem">
                            <?php if ($vehicle && trim($vehicle)): ?>
                                <i class="bi bi-truck-front-fill" style="color:#800000;font-size:.75rem"></i>
                                <?= htmlspecialchars(trim($vehicle)) ?>
                            <?php else: ?>
                                <span style="color:#ccc;font-size:.78rem;font-style:italic">Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="<?= $statusClass ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Recent Schedules Table ── -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-clock-history me-2"></i>Recent Schedules</span>
            <a href="Schedules.php" class="btn btn-sm btn-outline-secondary" style="font-size:0.78rem">View All</a>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Requestor</th>
                        <th>Department</th>
                        <th>Destination</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentSchedules as $r): ?>
                    <tr>
                        <td><?= $r['schedule_id'] ?></td>
                        <td><?= htmlspecialchars($r['username']) ?></td>
                        <td><?= htmlspecialchars($r['dept_name']) ?></td>
                        <td><?= htmlspecialchars($r['destination']) ?></td>
                        <td><?= date('M j, Y', strtotime($r['date_start'])) ?></td>
                        <td>
                            <?php $st = $r['status']; ?>
                            <?php if ($st === 'Pending'): ?>
                                <span class="badge-pending">Pending</span>
                            <?php elseif ($st === 'Approved'): ?>
                                <span class="badge-approved">Approved</span>
                            <?php elseif ($st === 'OnTrip'): ?>
                                <span class="badge-ontrip">On Trip</span>
                            <?php elseif ($st === 'Completed'): ?>
                                <span class="badge-completed">Completed</span>
                            <?php elseif ($st === 'Rejected'): ?>
                                <span class="badge-rejected">Rejected</span>
                            <?php else: ?>
                                <span class="badge-cancelled">Cancelled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentSchedules)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-calendar-x fs-4 d-block mb-2 opacity-50"></i>
                            No schedules yet.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateClock() {
    const now = new Date();
    let h = now.getHours();
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('liveH').textContent = String(h).padStart(2, '0');
    document.getElementById('liveM').textContent = m;
    document.getElementById('liveS').textContent = s;
    document.getElementById('liveAMPM').textContent = ampm;
}
updateClock();
setInterval(updateClock, 1000);
</script>
<?php $showWelcome = !empty($_SESSION['just_logged_in']); unset($_SESSION['just_logged_in']); ?>

<?php if ($showWelcome): ?>
<div id="welcomeToast" style="
    position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
    background: #fff; border-radius: 14px;
    box-shadow: 0 8px 32px rgba(128,0,0,0.18);
    border-left: 5px solid #800000;
    padding: 1rem 1.25rem 1rem 1rem;
    display: flex; align-items: flex-start; gap: 12px;
    min-width: 300px; max-width: 360px;
    animation: slideIn 0.4s cubic-bezier(0.34,1.56,0.64,1) both;
">
    <div style="
        width: 42px; height: 42px; border-radius: 50%; flex-shrink: 0;
        background: #800000; color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem; font-weight: 700;
    "><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
    <div style="flex: 1;">
        <div style="font-weight: 700; font-size: 0.9rem; color: #2d2d2d; margin-bottom: 2px;">
            Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>! 👋
        </div>
        <div style="font-size: 0.78rem; color: #800000; font-weight: 600; margin-bottom: 3px;">
            <?= htmlspecialchars($me['office_name'] ?? 'Staff') ?> — Staff
        </div>
        <div style="font-size: 0.75rem; color: #999;">
            <?= date('l, F j, Y · g:i A') ?>
        </div>
    </div>
    <button onclick="dismissToast()" style="
        background: none; border: none; cursor: pointer;
        color: #bbb; font-size: 1rem; padding: 0; line-height: 1;
        align-self: flex-start;
    ">&#x2715;</button>
</div>

<style>
    /* ── Mobile Responsive ── */
.hamburger-btn {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    color: #800000;
    font-size: 1.2rem;
    line-height: 1;
    margin-right: .5rem;
}
.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 99;
}
.sidebar-overlay.open { display: block; }

@media (max-width: 768px) {
    .hamburger-btn { display: flex; align-items: center; }

    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        z-index: 200;
    }
    .sidebar.open { transform: translateX(0); }

    .topbar { margin-left: 0; }
    .main-content { margin-left: 0; padding: 1rem; }

    /* Stack stat cards vertically */
    .row.g-3 > .col-6.col-lg { flex: 0 0 50%; max-width: 50%; }

    /* Make tables scroll horizontally */
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* Welcome banner stacks */
    .welcome-banner {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    .banner-clock-block { align-items: flex-start; }

    /* Walk-in page grid → single column */
    .page-grid {
        grid-template-columns: 1fr !important;
    }

    /* Availability grid → 1 col */
    .avail-grid {
        grid-template-columns: 1fr !important;
    }

    /* Stats row → 2 cols */
    .stats-row {
        grid-template-columns: 1fr 1fr !important;
    }

    /* Modals slide up from bottom */
    .modal-dialog {
        margin: auto 0 0;
        max-width: 100%;
    }
    .modal-content {
        border-radius: 16px 16px 0 0 !important;
    }

    /* Filter tabs wrap */
    .filter-tabs { gap: 4px; }
    .filter-tab  { font-size: .68rem; padding: 3px 10px; }
    .filter-btn  { font-size: .74rem; padding: 3px 10px; }

    /* Section header wraps */
    .section-header { flex-wrap: wrap; gap: .5rem; }

    /* Topbar user info — hide role subtitle on very small screens */
    .topbar-user > div > div:last-child { display: none; }

    /* History card search full-width */
    .search-box { min-width: 120px; }

    /* Today trips table — hide less-important cols */
    .table th:nth-child(3),
    .table td:nth-child(3),
    .table th:nth-child(6),
    .table td:nth-child(6) { display: none; }
}
@keyframes slideIn {
    from { opacity: 0; transform: translateX(60px) scale(0.95); }
    to   { opacity: 1; transform: translateX(0) scale(1); }
}
@keyframes slideOut {
    from { opacity: 1; transform: translateX(0) scale(1); }
    to   { opacity: 0; transform: translateX(60px) scale(0.95); }
}
</style>
<script>
function dismissToast() {
    const t = document.getElementById('welcomeToast');
    t.style.animation = 'slideOut 0.3s ease forwards';
    setTimeout(() => t.remove(), 300);
}
setTimeout(dismissToast, 5000);
</script>
<?php endif; ?>
<script>
    function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
    document.body.style.overflow =
        document.querySelector('.sidebar').classList.contains('open')
        ? 'hidden' : '';
}
// Close sidebar on nav link click (mobile UX)
document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) toggleSidebar();
    });
});
/* ── Notification Bell Logic ── */
const bell    = document.getElementById('notifBellBtn');
const dropdown= document.getElementById('notifDropdown');
const badge   = document.getElementById('notifBadge');
const body_   = document.getElementById('notifDropBody');
const sideBadge = document.getElementById('sidebarNotifBadge');
 
const TYPE_COLOR = {
    pending:   { bg:'#fff8e1', color:'#856404', icon:'bi-hourglass-split' },
    ontrip:    { bg:'#fff3e0', color:'#7a4f00', icon:'bi-truck-front-fill' },
    completed: { bg:'#e8f5e9', color:'#0f5132', icon:'bi-check-circle-fill' },
    newuser:   { bg:'#e3f2fd', color:'#0a3678', icon:'bi-person-plus-fill' },
    rejected:  { bg:'#fce4ec', color:'#842029', icon:'bi-x-circle-fill' },
    info:      { bg:'#fdecea', color:'#800000', icon:'bi-bell-fill' },
};
 
function timeAgo(ts) {
    const diff = Math.floor((Date.now() - new Date(ts)) / 1000);
    if (diff < 60)     return 'Just now';
    if (diff < 3600)   return Math.floor(diff/60)+'m ago';
    if (diff < 86400)  return Math.floor(diff/3600)+'h ago';
    return Math.floor(diff/86400)+'d ago';
}
function cleanTitle(raw) {
    return raw.replace(/^\[[\w\-]+\]\s*/, '');
}
 
async function loadNotifications() {
    try {
        const r = await fetch('notification.php?json=1');
        const data = await r.json();
 
        /* Update badges */
        if (data.unread > 0) {
            badge.textContent = data.unread > 99 ? '99+' : data.unread;
            badge.style.display = 'block';
            sideBadge.textContent = data.unread > 99 ? '99+' : data.unread;
            sideBadge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
            sideBadge.style.display = 'none';
        }
 
        /* Render dropdown items */
        if (!data.notifications || data.notifications.length === 0) {
            body_.innerHTML = `<div class="notif-drop-empty">
                <i class="bi bi-bell-slash"></i>
                <p style="font-size:.78rem;margin:0">You're all caught up!</p>
            </div>`;
            return;
        }
 
        body_.innerHTML = data.notifications.map(n => {
            const tc    = TYPE_COLOR[n.type] || TYPE_COLOR.info;
            const title = cleanTitle(n.title);
            const unread= n.is_read == 0;
            return `<a href="${n.link || 'notification.php'}"
                       class="notif-drop-item ${unread ? 'unread' : ''}">
                <div class="notif-drop-icon" style="background:${tc.bg};color:${tc.color}">
                    <i class="bi ${tc.icon}"></i>
                </div>
                <div style="flex:1;min-width:0">
                    <div class="notif-drop-title">${title}</div>
                    <div class="notif-drop-msg">${n.message || ''}</div>
                    <div class="notif-drop-time">${timeAgo(n.created_at)}</div>
                </div>
                ${unread ? '<div class="notif-drop-unread-dot"></div>' : ''}
            </a>`;
        }).join('');
 
    } catch(e) {
        body_.innerHTML = `<div class="notif-drop-empty" style="color:#dc3545">
            <i class="bi bi-exclamation-circle"></i>
            <p style="font-size:.78rem;margin:0">Could not load notifications</p>
        </div>`;
    }
}
 
/* Toggle dropdown */
bell.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = dropdown.classList.toggle('open');
    if (isOpen) loadNotifications();
});
 
/* Close on outside click */
document.addEventListener('click', (e) => {
    if (!dropdown.contains(e.target) && e.target !== bell) {
        dropdown.classList.remove('open');
    }
});
 
/* Initial load (for badge count only) */
loadNotifications();
/* Refresh every 60 seconds */
setInterval(loadNotifications, 60000);
</script>
</body>
</html>