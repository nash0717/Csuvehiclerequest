<?php
session_start();
require_once '../includes/db.php';

$page_title = 'Dashboard';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

// Summary counts
function countStatus($pdo, $user_id, $status = null) {
    $sql    = "SELECT COUNT(*) FROM schedules WHERE user_id = ?";
    $params = [$user_id];
    if ($status) {
        $sql    .= " AND status = ?";
        $params[] = $status;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$total     = countStatus($pdo, $user_id);
$pending   = countStatus($pdo, $user_id, 'Pending');
$approved  = countStatus($pdo, $user_id, 'Approved');
$completed = countStatus($pdo, $user_id, 'Completed');
// Unread notification count
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$user_id]);
$unreadCount = (int)$unreadStmt->fetchColumn();

// Recent 5 trips
$stmt = $pdo->prepare("
    SELECT s.*, s.schedule_id AS id,
           v.plate_number, CONCAT(v.brand, ' ', v.model) AS vehicle_name,
           d.driver_name,
           o.office_name,
           dep.dept_name,
           staff.username AS booked_by_name
    FROM schedules s
    LEFT JOIN vehicles v      ON s.vehicle_id      = v.vehicle_id
    LEFT JOIN drivers  d      ON s.driver_id       = d.driver_id
    LEFT JOIN offices  o      ON s.office_id       = o.office_id
    LEFT JOIN departments dep ON s.department_id   = dep.dept_id
    LEFT JOIN users staff     ON s.booked_by_staff = staff.user_id
    WHERE s.user_id = ?
    ORDER BY s.created_at DESC LIMIT 5
");
$stmt->execute([$user_id]);
$recent_trips = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; margin: 0; }

.notif-badge-pill {
    background: #e24b4a; color: #fff; font-size: .62rem;
    font-weight: 700; min-width: 17px; height: 17px;
    border-radius: 9px; display: inline-flex;
    align-items: center; justify-content: center;
    padding: 0 4px; margin-left: auto;
}
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
    text-decoration: none;
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

.topbar-title { font-weight: 700; font-size: 1rem; color: #800000; position: absolute; left: 45%; transform: translateX(-50%); }
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
    color: #fff; border-radius: 16px;
    padding: 1.6rem 2rem; margin-bottom: 1.5rem;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 8px 32px rgba(128,0,0,0.35);
    position: relative; overflow: hidden;
}
.welcome-banner::before {
    content: ''; position: absolute; top: -40px; right: -40px;
    width: 180px; height: 180px; background: rgba(255,255,255,0.04); border-radius: 50%;
}
.welcome-banner::after {
    content: ''; position: absolute; bottom: -60px; right: 120px;
    width: 220px; height: 220px; background: rgba(255,255,255,0.03); border-radius: 50%;
}
.welcome-banner h5 { font-weight: 800; margin: 0 0 6px; font-size: 1.15rem; letter-spacing: 0.01em; }
.welcome-banner p  { margin: 0; opacity: 0.75; font-size: 0.86rem; }
.banner-clock-block {
    display: flex; flex-direction: column; align-items: flex-end;
    gap: 2px; z-index: 1; flex-shrink: 0;
}
.banner-time {
    font-size: 1.9rem; font-weight: 800;
    letter-spacing: 0.03em; line-height: 1; color: #fff;
}
.banner-ampm { font-size: 0.8rem; font-weight: 600; opacity: 0.6; margin-left: 4px; vertical-align: super; }
.banner-date-label { font-size: 0.75rem; opacity: 0.55; font-weight: 500; text-align: right; }
.banner-left { z-index: 1; }

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
    flex-shrink: 0; line-height: 1;
}
.stat-icon i {
    font-size: 1.5rem !important;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
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
.badge-pending   { background:#fff3cd; color:#856404; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-block; }
.badge-approved  { background:#d1e7dd; color:#0f5132; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-block; }
.badge-completed { background:#cfe2ff; color:#0a3678; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-block; }
.badge-rejected  { background:#f8d7da; color:#842029; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-block; }
.badge-cancelled { background:#e2e3e5; color:#41464b; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-block; }
.badge-ongoing   { background:#fff0d6; color:#7a4f00; padding:4px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-block; }

/* ── Quick Actions ── */
.quick-actions-grid { display: flex; gap: 12px; margin-bottom: 1.5rem; flex-wrap: wrap; }
.action-card {
    flex: 1; min-width: 160px;
    background: #fff; border: 1px solid #f0e5e5;
    border-radius: 14px; padding: 1rem 1.25rem;
    text-decoration: none; color: #444;
    display: flex; align-items: center; gap: 12px;
    transition: all 0.2s; font-weight: 600; font-size: 0.88rem;
    box-shadow: 0 2px 12px rgba(128,0,0,0.06);
}
.action-card:hover {
    border-color: #800000; background: rgba(128,0,0,0.04);
    color: #800000; transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(128,0,0,0.12);
}
.action-card i { font-size: 1.2rem; color: #800000; }

/* ── Empty state ── */
.empty-state { padding: 3rem 1rem; text-align: center; color: #bbb; }
.empty-state i { font-size: 2.5rem; color: #e0c8c8; display: block; margin-bottom: 0.75rem; }
.empty-state p { font-size: 0.88rem; margin: 0; }
.empty-state a { color: #800000; font-weight: 600; text-decoration: none; }

/* ── Toast ── */
@keyframes slideIn {
    from { opacity: 0; transform: translateX(60px) scale(0.95); }
    to   { opacity: 1; transform: translateX(0) scale(1); }
}
@keyframes slideOut {
    from { opacity: 1; transform: translateX(0) scale(1); }
    to   { opacity: 0; transform: translateX(60px) scale(0.95); }
}

/* ── Transaction Details Modal ── */
.modal-header-custom {
    background: linear-gradient(135deg, #800000 0%, #4a0000 100%);
    color: #fff; border-radius: 16px 16px 0 0;
    padding: 1.25rem 1.5rem;
    display: flex; align-items: center; justify-content: space-between;
}
.modal-header-custom .modal-title {
    font-weight: 800; font-size: 1rem; color: #fff;
    display: flex; align-items: center; gap: 8px;
}
.modal-header-custom .btn-close-custom {
    background: rgba(255,255,255,0.15); border: none; color: #fff;
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; cursor: pointer; transition: background 0.15s;
}
.modal-header-custom .btn-close-custom:hover { background: rgba(255,255,255,0.3); }
.detail-section-title {
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em; color: #800000;
    margin: 1.25rem 0 0.6rem; padding-bottom: 5px;
    border-bottom: 2px solid #f0e5e5;
}
.detail-row { display: flex; gap: 8px; margin-bottom: 0.6rem; align-items: flex-start; }
.detail-label { font-size: 0.75rem; color: #999; font-weight: 600; min-width: 130px; flex-shrink: 0; }
.detail-value { font-size: 0.85rem; color: #333; font-weight: 500; flex: 1; }
.detail-value.empty { color: #ccc; font-style: italic; }
.req-id-badge {
    display: inline-flex; align-items: center;
    background: rgba(128,0,0,0.08); color: #800000;
    font-weight: 800; font-size: 1.1rem;
    padding: 6px 16px; border-radius: 10px;
    letter-spacing: 0.03em; margin-bottom: 4px;
}
.alert-reason {
    background: #fdf3f3; border: 1px solid #f5c6cb;
    border-radius: 10px; padding: 0.75rem 1rem;
    font-size: 0.83rem; color: #842029; margin-top: 0.5rem;
}
.alert-reason i { margin-right: 6px; }
#tripModal .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(128,0,0,0.2); }
#tripModal .modal-body { padding: 0.75rem 1.5rem 1.5rem; max-height: 70vh; overflow-y: auto; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1.5rem; }
@media (max-width: 576px) { .detail-grid { grid-template-columns: 1fr; } }
/* ── MOBILE RESPONSIVE ── */
@media (max-width: 768px) {

  /* Hide sidebar by default on mobile */
  .sidebar {
    transform: translateX(-100%);
    transition: transform 0.25s ease;
    z-index: 200;
  }

  /* Show sidebar when toggled */
  .sidebar.open {
    transform: translateX(0);
  }

  /* Overlay behind open sidebar */
  .sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 199;
  }

  .sidebar-overlay.show { display: block; }

  /* Remove margin-left from topbar and main */
  .topbar,
  .main-content {
    margin-left: 0 !important;
  }

  /* Hamburger button — shown only on mobile */
  .mobile-menu-btn {
    display: flex !important;
  }

  /* Shrink welcome banner padding */
  .welcome-banner {
    padding: 1.1rem 1.25rem;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }

  .banner-clock-block { align-items: flex-start; }

  /* Stack stat cards 2-up */
  .col-6 { width: 50% !important; }
  .col-lg-3 { width: 50% !important; }

  /* Quick actions wrap */
  .quick-actions-grid { flex-direction: column; }
  .action-card { min-width: 100%; }

  /* Table: allow horizontal scroll */
  .table-responsive { overflow-x: auto; }

  /* Modal: full width on mobile */
  .modal-dialog { margin: 0.5rem; }
  .trip-modal, .reschedule-modal, .cancel-modal {
    max-width: 100%;
    margin: 0;
    border-radius: 12px 12px 0 0;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
  }
  .modal-overlay {
    align-items: flex-end;
    padding: 0;
  }
}

/* Hide hamburger on desktop */
.mobile-menu-btn { display: none; }
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"
     onclick="toggleSidebar()"></div>
<!-- ── Sidebar ── -->
<div class="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="../image/Csu.png" alt="Logo"></div>
    <div class="sidebar-brand-text">CSU Vehicle System<span>Requestor Panel</span></div>
  </div>
  <nav class="nav flex-column mt-2">
    <div class="nav-section-label">Main</div>
    <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <div class="nav-section-label">Requests</div>
    <a class="nav-link" href="new_request.php"><i class="bi bi-plus-circle"></i> New Trip Request</a>
    <a class="nav-link" href="my_trip.php"><i class="bi bi-map"></i> My Trips</a>
    <div class="nav-section-label">Notifications</div>
    <a class="nav-link" href="notification_requestor.php">
    <i class="bi bi-bell"></i> Notifications
    <?php if($unreadCount > 0): ?>
    <span class="notif-badge-pill"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
    <?php endif; ?>
</a>
    <div class="nav-section-label">Account</div>
    <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
    <hr class="sidebar-divider">
    <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
  </nav>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
    <button class="mobile-menu-btn" onclick="toggleSidebar()"
  style="background:none;border:none;cursor:pointer;
         padding:4px 8px;color:#800000;font-size:1.3rem;
         align-items:center;justify-content:center;">
  <i class="bi bi-list"></i>
</button>
    <div class="topbar-title"><i class="bi bi-speedometer2 me-2"></i>Dashboard</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:0.85rem"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></div>
            <div style="font-size:0.72rem;color:#800000">Requestor</div>
        </div>
    </div>
</div>

<!-- ── Main Content ── -->
<div class="main-content">

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="banner-left">
            <h5>Welcome back, <?= htmlspecialchars(explode(' ', $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')[0]) ?>! 👋</h5>
            <p>Here's a summary of your vehicle trip requests.</p>
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

    <!-- ── Stat Cards ── -->
    <div class="row g-3 mb-4">

        <!-- Total Requests -->
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fdecea;">
                     <i class="bi bi-journal-text" style="color:#800000;font-size:1.5rem;"></i>
                    </div>
                    <div>
                        <div class="stat-label">Total Requests</div>
                        <div class="stat-value"><?= $total ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending -->
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fff3cd;">
                        <i class="bi bi-hourglass-split" style="color:#856404;font-size:1.5rem;"></i>
                    </div>
                    <div>
                        <div class="stat-label">Pending</div>
                        <div class="stat-value"><?= $pending ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approved -->
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#d1e7dd;">
                        <i class="bi bi-check-circle-fill" style="color:#0f5132;font-size:1.5rem;"></i>
                    </div>
                    <div>
                        <div class="stat-label">Approved</div>
                        <div class="stat-value"><?= $approved ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Completed -->
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#cfe2ff;">
                        <i class="bi bi-flag-fill" style="color:#0a3678;font-size:1.5rem;"></i>
                    </div>
                    <div>
                        <div class="stat-label">Completed</div>
                        <div class="stat-value"><?= $completed ?></div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /stat cards -->

    <!-- ── Quick Actions ── -->
    <div class="quick-actions-grid mb-4">
        <a href="new_request.php" class="action-card">
            <i class="bi bi-plus-circle-fill"></i> New Trip Request
        </a>
        <a href="my_trip.php" class="action-card">
            <i class="bi bi-map-fill"></i> View All My Trips
        </a>
        <a href="notifications.php" class="action-card">
            <i class="bi bi-bell-fill"></i> Notifications
        </a>
    </div>

    <!-- ── Transactions Table ── -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-receipt me-2"></i>Transactions</span>
            <a href="my_trip.php" class="btn btn-sm btn-outline-secondary" style="font-size:0.78rem">View All</a>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Req #</th>
                        <th>Request Date</th>
                        <th>Destination</th>
                        <th>Purpose</th>
                        <th>Passengers</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($recent_trips)): ?>
                    <?php foreach ($recent_trips as $row): ?>
                    <tr>
                        <td style="font-weight:700;color:#800000">#<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td>
                            <i class="bi bi-calendar3" style="color:#800000;font-size:.75rem"></i>
                            <?= date('M d, Y', strtotime($row['created_at'])) ?>
                            <div style="font-size:0.7rem;color:#aaa;margin-top:1px"><?= date('g:i A', strtotime($row['created_at'])) ?></div>
                        </td>
                        <td>
                            <i class="bi bi-geo-alt-fill" style="color:#800000;font-size:.75rem"></i>
                            <?= htmlspecialchars($row['destination']) ?>
                        </td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#666;">
                            <?= htmlspecialchars($row['purpose']) ?>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:5px;background:#f3e5ff;color:#6f42c1;padding:3px 10px;border-radius:20px;font-size:0.78rem;font-weight:700;">
                                <i class="bi bi-people-fill" style="font-size:0.78rem;"></i>
                                <?= (int)($row['passengers'] ?? 0) ?>
                            </span>
                        </td>
                        <td><?php
                            $s = strtolower($row['status']);
                            $map = [
                                'pending'   => 'badge-pending',
                                'approved'  => 'badge-approved',
                                'completed' => 'badge-completed',
                                'rejected'  => 'badge-rejected',
                                'cancelled' => 'badge-cancelled',
                                'ongoing'   => 'badge-ongoing',
                                'on-going'  => 'badge-ongoing',
                                'ontrip'    => 'badge-ongoing',
                            ];
                            $cls = $map[$s] ?? 'badge-pending';
                            echo "<span class='$cls'>{$row['status']}</span>";
                        ?></td>
                        <td>
                            <button onclick='showTrip(<?= htmlspecialchars(json_encode([
                                "id"               => $row["id"],
                                "destination"      => $row["destination"],
                                "purpose"          => $row["purpose"],
                                "passengers"       => $row["passengers"] ?? 0,
                                "date_start"       => $row["date_start"],
                                "date_end"         => $row["date_end"],
                                "time_start"       => $row["time_start"],
                                "time_end"         => $row["time_end"],
                                "status"           => $row["status"],
                                "vehicle_name"     => $row["vehicle_name"],
                                "plate_number"     => $row["plate_number"],
                                "driver_name"      => $row["driver_name"],
                                "office_name"      => $row["office_name"],
                                "dept_name"        => $row["dept_name"],
                                "rejection_reason" => $row["rejection_reason"],
                                "cancel_reason"    => $row["cancel_reason"],
                                "arrived_at"       => $row["arrived_at"],
                                "trip_ticket_no"   => $row["trip_ticket_no"],
                                "booked_by_staff"  => $row["booked_by_name"] ?? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'N/A'),
                                "created_at"       => $row["created_at"],
                            ]), ENT_QUOTES) ?>)'
                               class="btn btn-sm"
                               style="background:#800000;color:#fff;border:none;font-size:0.78rem;font-weight:600;border-radius:8px;padding:5px 12px;">
                                <i class="bi bi-card-list me-1"></i>Details
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="bi bi-truck-front"></i>
                                <p style="font-weight:600;color:#c0a0a0;margin-bottom:.2rem">No transactions yet</p>
                                <p><a href="new_request.php">Create your first request</a></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->

<!-- ── Transaction Details Modal ── -->
<div class="modal fade" id="tripModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header-custom">
        <div class="modal-title"><i class="bi bi-receipt-cutoff"></i> Transaction Details</div>
        <button class="btn-close-custom" onclick="closeTripModal()" aria-label="Close"><i class="bi bi-x"></i></button>
      </div>
      <div class="modal-body">

        <!-- Request ID + Status -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2">
            <div>
                <div style="font-size:0.72rem;color:#999;font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px">Request Number</div>
                <div class="req-id-badge" id="md_req_id">#0000</div>
            </div>
            <div style="text-align:right">
                <div style="font-size:0.72rem;color:#999;font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px">Status</div>
                <span id="md_status_badge"></span>
            </div>
        </div>

        <!-- Booked By -->
        <div style="display:flex;align-items:center;gap:10px;background:#fdf5f5;border:1px solid #f0e5e5;border-radius:10px;padding:0.6rem 1rem;margin-top:0.85rem;">
            <div style="width:34px;height:34px;border-radius:50%;background:#800000;color:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;" id="md_booked_avatar">—</div>
            <div>
                <div style="font-size:0.7rem;color:#999;font-weight:600;text-transform:uppercase;letter-spacing:.07em">Booked By</div>
                <div style="font-size:0.88rem;font-weight:700;color:#2d2d2d" id="md_booked_by">—</div>
            </div>
            <div style="margin-left:auto;text-align:right">
                <div style="font-size:0.7rem;color:#999;font-weight:600;text-transform:uppercase;letter-spacing:.07em">Submitted</div>
                <div style="font-size:0.8rem;font-weight:600;color:#555" id="md_created_at">—</div>
            </div>
        </div>

        <!-- Trip Information -->
        <div class="detail-section-title"><i class="bi bi-map me-1"></i>Trip Information</div>
        <div class="detail-grid">
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-geo-alt-fill me-1" style="color:#800000"></i>Destination</span>
                <span class="detail-value" id="md_destination">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-journal-text me-1" style="color:#800000"></i>Purpose</span>
                <span class="detail-value" id="md_purpose">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-calendar3 me-1" style="color:#800000"></i>Date Start</span>
                <span class="detail-value" id="md_date_start">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-calendar3-range me-1" style="color:#800000"></i>Date End</span>
                <span class="detail-value" id="md_date_end">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-clock me-1" style="color:#800000"></i>Time Start</span>
                <span class="detail-value" id="md_time_start">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-clock-history me-1" style="color:#800000"></i>Time End</span>
                <span class="detail-value" id="md_time_end">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-people-fill me-1" style="color:#6f42c1"></i>Passengers</span>
                <span class="detail-value" id="md_passengers">—</span>
            </div>
        </div>

        <!-- Vehicle & Driver -->
        <div class="detail-section-title"><i class="bi bi-truck me-1"></i>Vehicle & Driver</div>
        <div class="detail-grid">
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-car-front-fill me-1" style="color:#800000"></i>Vehicle</span>
                <span class="detail-value" id="md_vehicle">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-credit-card me-1" style="color:#800000"></i>Plate Number</span>
                <span class="detail-value" id="md_plate">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-person-fill me-1" style="color:#800000"></i>Driver</span>
                <span class="detail-value" id="md_driver">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-hash me-1" style="color:#800000"></i>Trip Ticket No.</span>
                <span class="detail-value" id="md_ticket">—</span>
            </div>
        </div>

        <!-- Office / Department -->
        <div class="detail-section-title"><i class="bi bi-building me-1"></i>Office & Department</div>
        <div class="detail-grid">
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-building me-1" style="color:#800000"></i>Office</span>
                <span class="detail-value" id="md_office">—</span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-diagram-3 me-1" style="color:#800000"></i>Department</span>
                <span class="detail-value" id="md_dept">—</span>
            </div>
        </div>

        <!-- Arrival -->
        <div id="md_arrived_block" style="display:none">
            <div class="detail-section-title"><i class="bi bi-flag-fill me-1"></i>Completion</div>
            <div class="detail-row">
                <span class="detail-label"><i class="bi bi-check2-circle me-1" style="color:#800000"></i>Arrived At</span>
                <span class="detail-value" id="md_arrived">—</span>
            </div>
        </div>

        <!-- Rejection Reason -->
        <div id="md_rejection_block" style="display:none">
            <div class="detail-section-title"><i class="bi bi-x-circle-fill me-1"></i>Rejection Details</div>
            <div class="alert-reason"><i class="bi bi-exclamation-triangle-fill"></i><strong>Reason:</strong> <span id="md_rejection_reason"></span></div>
        </div>

        <!-- Cancellation Reason -->
        <div id="md_cancel_block" style="display:none">
            <div class="detail-section-title"><i class="bi bi-slash-circle me-1"></i>Cancellation Details</div>
            <div class="alert-reason" style="background:#fff3e0;border-color:#ffe0b2;color:#7a4f00"><i class="bi bi-exclamation-circle-fill"></i><strong>Reason:</strong> <span id="md_cancel_reason"></span></div>
        </div>

      </div>
      <div class="modal-footer" style="border-top:1px solid #f0e5e5;padding:0.75rem 1.5rem;">
        <button type="button" class="btn btn-sm" onclick="closeTripModal()"
            style="background:#f5f0f0;color:#666;border:none;border-radius:8px;font-weight:600;padding:6px 18px;">
            <i class="bi bi-x me-1"></i>Close
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleSidebar() {
  document.querySelector('.sidebar')
    .classList.toggle('open');
  document.getElementById('sidebarOverlay')
    .classList.toggle('show');
}
// ── Live Clock ──
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

// ── Trip Details Modal ──
let tripModalInstance = null;

function fmt(val) { return val && val.trim && val.trim() !== '' ? val : null; }

function fmtDate(val) {
    if (!val) return null;
    const d = new Date(val);
    if (isNaN(d)) return val;
    return d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
}

function fmtTime(val) {
    if (!val) return null;
    const parts = val.split(':');
    let h = parseInt(parts[0]), m = parts[1] || '00';
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${String(h).padStart(2,'0')}:${m} ${ap}`;
}

function fmtDateTime(val) {
    if (!val) return null;
    const d = new Date(val);
    if (isNaN(d)) return val;
    return d.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' })
        + ' · ' + d.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
}

function setField(id, val, fallback) {
    const el = document.getElementById(id);
    if (!el) return;
    if (val) { el.textContent = val; el.classList.remove('empty'); }
    else     { el.textContent = fallback || 'N/A'; el.classList.add('empty'); }
}

const statusMap = {
    'pending':'badge-pending','approved':'badge-approved','completed':'badge-completed',
    'rejected':'badge-rejected','cancelled':'badge-cancelled','ongoing':'badge-ongoing',
    'on-going':'badge-ongoing','ontrip':'badge-ongoing',
};

function showTrip(data) {
    document.getElementById('md_req_id').textContent = '#' + String(data.id).padStart(4, '0');

    const statusEl = document.getElementById('md_status_badge');
    const cls = statusMap[data.status?.toLowerCase()] ?? 'badge-pending';
    statusEl.innerHTML = `<span class="${cls}" style="font-size:0.85rem;padding:5px 14px">${data.status}</span>`;

    setField('md_destination', fmt(data.destination));
    setField('md_purpose',     fmt(data.purpose));
    setField('md_date_start',  fmtDate(data.date_start));
    setField('md_date_end',    fmtDate(data.date_end));
    setField('md_time_start',  fmtTime(data.time_start));
    setField('md_time_end',    fmtTime(data.time_end));

    // Passengers
    const paxEl = document.getElementById('md_passengers');
    if (paxEl) {
        const pax = parseInt(data.passengers) || 0;
        paxEl.innerHTML = `<span style="display:inline-flex;align-items:center;gap:5px;background:#f3e5ff;color:#6f42c1;padding:3px 10px;border-radius:20px;font-size:0.82rem;font-weight:700;"><i class="bi bi-people-fill"></i>${pax} passenger${pax !== 1 ? 's' : ''}</span>`;
        paxEl.classList.remove('empty');
    }

    setField('md_vehicle', fmt(data.vehicle_name));
    setField('md_plate',   fmt(data.plate_number));
    setField('md_driver',  fmt(data.driver_name));
    setField('md_ticket',  fmt(data.trip_ticket_no));
    setField('md_office',  fmt(data.office_name));
    setField('md_dept',    fmt(data.dept_name));
    setField('md_booked_by',  fmt(data.booked_by_staff));
    setField('md_created_at', fmtDateTime(data.created_at));

    const avatarEl = document.getElementById('md_booked_avatar');
    if (avatarEl) {
        const name = fmt(data.booked_by_staff);
        avatarEl.textContent = name ? name.trim().charAt(0).toUpperCase() : '?';
    }

    const arrivedBlock = document.getElementById('md_arrived_block');
    if (data.arrived_at) { setField('md_arrived', fmtDateTime(data.arrived_at)); arrivedBlock.style.display = ''; }
    else arrivedBlock.style.display = 'none';

    const rejBlock = document.getElementById('md_rejection_block');
    if (data.rejection_reason?.trim()) { document.getElementById('md_rejection_reason').textContent = data.rejection_reason; rejBlock.style.display = ''; }
    else rejBlock.style.display = 'none';

    const cancelBlock = document.getElementById('md_cancel_block');
    if (data.cancel_reason?.trim()) { document.getElementById('md_cancel_reason').textContent = data.cancel_reason; cancelBlock.style.display = ''; }
    else cancelBlock.style.display = 'none';

    const modalEl = document.getElementById('tripModal');
    if (!tripModalInstance) tripModalInstance = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true });
    tripModalInstance.show();
}

function closeTripModal() { if (tripModalInstance) tripModalInstance.hide(); }
</script>

<!-- Welcome Toast -->
<div id="welcomeToast" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(128,0,0,0.18);border-left:5px solid #800000;padding:1rem 1.25rem 1rem 1rem;display:flex;align-items:flex-start;gap:12px;min-width:300px;max-width:360px;animation:slideIn 0.4s cubic-bezier(0.34,1.56,0.64,1) both;">
    <div style="width:42px;height:42px;border-radius:50%;flex-shrink:0;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;"><?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)) ?></div>
    <div style="flex:1;">
        <div style="font-weight:700;font-size:0.9rem;color:#2d2d2d;margin-bottom:2px;">Welcome back, <?= htmlspecialchars(explode(' ', $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User')[0]) ?>! 👋</div>
        <div style="font-size:0.78rem;color:#800000;font-weight:600;margin-bottom:3px;">Requestor</div>
        <div style="font-size:0.75rem;color:#999;"><?= date('l, F j, Y · g:i A') ?></div>
    </div>
    <button onclick="dismissToast()" style="background:none;border:none;cursor:pointer;color:#bbb;font-size:1rem;padding:0;line-height:1;align-self:flex-start;">&#x2715;</button>
</div>
<script>
function dismissToast() {
    const t = document.getElementById('welcomeToast');
    if (!t) return;
    t.style.animation = 'slideOut 0.3s ease forwards';
    setTimeout(() => t.remove(), 300);
}
setTimeout(dismissToast, 5000);
</script>
</body>
</html>