<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header("Location: /csuweb/login.php?error=unauthorized"); exit;
}

/* ── Current staff ── */
$cu = $pdo->prepare("SELECT u.*, o.office_id AS u_office_id, o.office_name FROM users u LEFT JOIN offices o ON u.office_id=o.office_id WHERE u.user_id=?");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();
$myOfficeId = (int)($me['u_office_id'] ?? 0);
$officeName = $me['office_name'] ?? ''; 
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$_SESSION['user_id'], $myOfficeId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/* ── Date inputs ── */
$checkDate  = $_GET['date']       ?? date('Y-m-d');
$checkDateE = $_GET['date_end']   ?? $checkDate;
$timeStart  = $_GET['time_start'] ?? '';
$timeEnd    = $_GET['time_end']   ?? '';
$searched   = isset($_GET['date']);

/* ── Available Vehicles ── */
$availVehicles = [];
$busyVehicles  = [];
if ($searched) {
    $vStmt = $pdo->prepare("
    SELECT v.*
    FROM vehicles v
    WHERE v.status IN ('Active', 'Available')
      AND (v.vehicle_scope = 'Both' OR v.vehicle_scope = :oname OR v.office_id = :oid)
      AND v.vehicle_id NOT IN (
          SELECT s.vehicle_id FROM schedules s
          WHERE s.vehicle_id IS NOT NULL
            AND s.status IN ('Approved','OnTrip')
            AND s.date_start <= :de
            AND s.date_end   >= :ds
            AND NOT (
                s.status = 'OnTrip'
                AND TIMESTAMPADD(HOUR, 1,
                    CONCAT(s.date_end,' ',COALESCE(s.time_end,'23:59:00'))
                ) <= NOW()
            )
      )
    ORDER BY v.brand, v.model
");
    $vStmt->execute([':ds' => $checkDate, ':de' => $checkDateE, ':oname' => $officeName, ':oid' => $myOfficeId]);
    $availVehicles = $vStmt->fetchAll();

    $bvStmt = $pdo->prepare("
    SELECT v.*, s.date_start, s.date_end, s.time_start, s.time_end,
           s.destination, s.status, u.username AS requestor,
           o.office_name,
           TIMESTAMPADD(HOUR,1,
               CONCAT(s.date_end,' ',COALESCE(s.time_end,'23:59:00'))
           ) AS grace_until
    FROM vehicles v
    JOIN schedules s ON s.vehicle_id = v.vehicle_id
    JOIN users u ON s.user_id = u.user_id
    JOIN offices o ON s.office_id = o.office_id
    WHERE v.status IN ('Active', 'Available')
      AND (v.vehicle_scope = 'Both' OR v.vehicle_scope = :oname OR v.office_id = :oid)
      AND s.status IN ('Approved','OnTrip')
      AND s.date_start <= :de
      AND s.date_end   >= :ds
      AND NOT (
          s.status = 'OnTrip'
          AND TIMESTAMPADD(HOUR, 1,
              CONCAT(s.date_end,' ',COALESCE(s.time_end,'23:59:00'))
          ) <= NOW()
      )
    ORDER BY v.brand, v.model
");
    $bvStmt->execute([':ds' => $checkDate, ':de' => $checkDateE, ':oname' => $officeName, ':oid' => $myOfficeId]);
    $busyVehicles = $bvStmt->fetchAll();
}

/* ── Available Drivers ── */
$availDrivers = [];
$busyDrivers  = [];
if ($searched) {
    $dStmt = $pdo->prepare("
    SELECT d.*
    FROM drivers d
    WHERE d.status IN ('Active', 'Available')
      AND (d.driver_scope = 'Both' OR d.driver_scope = :oname OR d.office_id = :oid)
      AND d.driver_id NOT IN (
          SELECT s.driver_id FROM schedules s
          WHERE s.driver_id IS NOT NULL
            AND s.status IN ('Approved','OnTrip')
            AND s.date_start <= :de
            AND s.date_end   >= :ds
            AND NOT (
                s.status = 'OnTrip'
                AND TIMESTAMPADD(HOUR, 1,
                    CONCAT(s.date_end,' ',COALESCE(s.time_end,'23:59:00'))
                ) <= NOW()
            )
      )
    ORDER BY d.driver_name
");
    $dStmt->execute([':ds' => $checkDate, ':de' => $checkDateE, ':oname' => $officeName, ':oid' => $myOfficeId]);
    $availDrivers = $dStmt->fetchAll();

    $bdStmt = $pdo->prepare("
    SELECT d.*, s.date_start, s.date_end, s.time_start, s.time_end,
           s.destination, s.status, u.username AS requestor,
           o.office_name,
           TIMESTAMPADD(HOUR,1,
               CONCAT(s.date_end,' ',COALESCE(s.time_end,'23:59:00'))
           ) AS grace_until
    FROM drivers d
    JOIN schedules s ON s.driver_id = d.driver_id
    JOIN users u ON s.user_id = u.user_id
    JOIN offices o ON s.office_id = o.office_id
    WHERE d.status IN ('Active', 'Available')
      AND (d.driver_scope = 'Both' OR d.driver_scope = :oname OR d.office_id = :oid)
      AND s.status IN ('Approved','OnTrip')
      AND s.date_start <= :de
      AND s.date_end   >= :ds
      AND NOT (
          s.status = 'OnTrip'
          AND TIMESTAMPADD(HOUR, 1,
              CONCAT(s.date_end,' ',COALESCE(s.time_end,'23:59:00'))
          ) <= NOW()
      )
    ORDER BY d.driver_name
");
    $bdStmt->execute([':ds' => $checkDate, ':de' => $checkDateE, ':oname' => $officeName, ':oid' => $myOfficeId]);
    $busyDrivers = $bdStmt->fetchAll();
}

function ft($t){ return ($t && $t!='--') ? date('g:i A', strtotime($t)) : '—'; }
function fd($d){ return $d ? date('M j, Y', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check Availability – CSU VSS Staff</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:#f5f0f0;font-family:'Segoe UI',sans-serif}

/* ── Mobile hamburger + overlay ── */
.hamburger-btn{display:none;background:none;border:none;cursor:pointer;padding:4px;color:#800000;font-size:1.2rem;line-height:1;margin-right:.5rem}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:199}
.sidebar-overlay.open{display:block}

/* ── Sidebar ── */
.sidebar{min-height:100vh;background:linear-gradient(180deg,#800000,#6b0000);width:240px;position:fixed;top:0;left:0;z-index:100;display:flex;flex-direction:column}
.sidebar-brand{padding:1.25rem 1rem 1rem;border-bottom:1px solid rgba(255,255,255,.15);display:flex;align-items:center;gap:10px}
.sidebar-logo{width:42px;height:42px;border-radius:50%;background:#fff;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sidebar-logo img{width:38px;height:38px;object-fit:contain}
.sidebar-brand-text{color:#fff;font-size:.82rem;font-weight:700;line-height:1.3}
.sidebar-brand-text span{display:block;font-size:.72rem;font-weight:400;opacity:.7}
.sidebar .nav-link{color:rgba(255,255,255,.8);padding:.6rem 1.25rem;font-size:.88rem;display:flex;align-items:center;gap:10px;border-left:3px solid transparent;transition:all .15s}
.sidebar .nav-link:hover{color:#fff;background:rgba(255,255,255,.1);border-left-color:rgba(255,255,255,.4)}
.sidebar .nav-link.active{color:#fff;background:rgba(255,255,255,.15);border-left-color:#fff;font-weight:600}
.sidebar .nav-link i{font-size:1rem;width:18px}
.sidebar-divider{border-color:rgba(255,255,255,.15);margin:.5rem 1rem}
.nav-section-label{padding:.75rem 1.25rem .25rem;font-size:.68rem;font-weight:700;color:rgba(255,255,255,.45);letter-spacing:.08em;text-transform:uppercase}
.notif-badge-pill{background:#e24b4a;color:#fff;font-size:.62rem;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;margin-left:auto}

/* ── Topbar ── */
.topbar{background:#fff;border-bottom:1px solid #e8dede;padding:.7rem 1.5rem;margin-left:240px;position:sticky;top:0;z-index:99;display:flex;align-items:center;justify-content:space-between}
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}

/* ── Main ── */
.main-content{margin-left:240px;padding:1.5rem}

/* ── Search Card ── */
.search-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.07);padding:1.25rem 1.5rem;margin-bottom:1.25rem}
.search-card h6{font-weight:700;color:#800000;font-size:.9rem;margin-bottom:1rem}

/* ── Result Section ── */
.section-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.07);overflow:hidden;margin-bottom:1.25rem}
.section-header{padding:.85rem 1.25rem;border-bottom:1px solid #f0e5e5;font-weight:700;font-size:.88rem;color:#fff;display:flex;align-items:center;gap:8px}
.sh-green{background:linear-gradient(135deg,#145a32,#1e8449)}
.sh-red{background:linear-gradient(135deg,#800000,#6b0000)}
.sh-blue{background:linear-gradient(135deg,#0550a0,#0a3678)}
.sh-orange{background:linear-gradient(135deg,#7a4f00,#c87000)}

/* ── Grid Cards ── */
.avail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.9rem;padding:1.1rem}
.avail-card{border-radius:10px;padding:.85rem 1rem;border:1.5px solid #d1e7dd;background:#f0fdf4;position:relative;transition:box-shadow .15s}
.avail-card:hover{box-shadow:0 4px 14px rgba(20,90,50,.12)}
.avail-card-busy{border-color:#f5c6cb;background:#fff5f5}
.avail-card-busy:hover{box-shadow:0 4px 14px rgba(128,0,0,.10)}
.avail-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;margin-bottom:.5rem;flex-shrink:0}
.icon-green{background:#d1e7dd;color:#145a32}
.icon-red{background:#f8d7da;color:#800000}
.card-name{font-weight:700;font-size:.88rem;color:#222;margin-bottom:2px}
.card-sub{font-size:.75rem;color:#666}
.card-meta{font-size:.75rem;color:#555;margin-top:.4rem;line-height:1.5}
.card-meta i{width:14px;color:#888}
.badge-avail{display:inline-flex;align-items:center;gap:4px;background:#d1e7dd;color:#0f5132;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:10px;margin-top:.4rem}
.badge-busy{display:inline-flex;align-items:center;gap:4px;background:#f8d7da;color:#842029;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:10px;margin-top:.4rem}

/* ── Summary pills ── */
.summary-bar{display:flex;gap:.6rem;flex-wrap:wrap;padding:.75rem 1.25rem;background:#fdf8f8;border-bottom:1px solid #f0e5e5}
.spill{display:inline-flex;align-items:center;gap:6px;padding:4px 13px;border-radius:20px;font-size:.78rem;font-weight:600}
.spill-green{background:#d1e7dd;color:#0f5132}
.spill-red{background:#f8d7da;color:#842029}
.spill-blue{background:#cfe2ff;color:#0a3678}
.spill-orange{background:#fff3cd;color:#856404}

/* ── Empty state ── */
.empty-state{text-align:center;padding:2.5rem 1rem;color:#aaa}
.empty-state i{font-size:2.2rem;display:block;margin-bottom:.5rem;opacity:.4}
.empty-state p{font-size:.85rem;margin:0}

/* ── Date range display ── */
.range-display{background:#f8f4f4;border:1.5px solid #e8d5d5;border-radius:10px;padding:.6rem 1rem;font-size:.82rem;color:#800000;font-weight:600;display:inline-flex;align-items:center;gap:6px}

/* ── Mobile ── */
@media (max-width: 768px) {
    .hamburger-btn{display:flex;align-items:center}
    .sidebar{transform:translateX(-100%);transition:transform 0.25s ease;z-index:200;position:fixed !important;top:0;left:0;height:100vh;overflow-y:auto}
    .sidebar.open{transform:translateX(0) !important}
    .topbar{margin-left:0 !important}
    .main-content{margin-left:0 !important;padding:1rem}
    .topbar-title{font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .topbar-title i{display:none}
    .topbar-user > div > div:last-child{display:none}
    .section-header{flex-wrap:wrap;gap:.5rem}
    .section-header .ms-auto{font-size:.7rem !important;white-space:nowrap}
    .avail-grid{grid-template-columns:1fr !important}
    .avail-card{padding:.7rem .85rem}
    .spill{font-size:.7rem;padding:3px 9px}
    .search-card{padding:.85rem}
    .search-card .col-md-3,
    .search-card .col-md-2{flex:0 0 50%;max-width:50%}
    .search-card .col-md-2:last-child{flex:0 0 100%;max-width:100%}
    .range-display{display:flex;flex-wrap:wrap;width:100%;font-size:.76rem}
    .modal-dialog{margin:auto 0 0;max-width:100%}
    .modal-content{border-radius:16px 16px 0 0 !important}
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .table th:nth-child(3),
    .table td:nth-child(3),
    .table th:nth-child(6),
    .table td:nth-child(6){display:none}
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
    <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <div class="nav-section-label">Manage</div>
    <a class="nav-link" href="Requestors.php"><i class="bi bi-people"></i> Requestors</a>
    <div class="nav-section-label">Scheduling</div>
    <a class="nav-link" href="WalkIn.php"><i class="bi bi-calendar-plus"></i> Walk-in Booking</a>
    <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i> View Schedules</a>
    <a class="nav-link active" href="CheckAvailability.php"><i class="bi bi-search"></i> Check Availability</a>
    <a class="nav-link" href="staff_driverstripcomplete.php"><i class="bi bi-flag-fill"></i> Driver Trip Records</a>
    <div class="nav-section-label">Account</div>
    <a class="nav-link" href="notification.php">
      <i class="bi bi-bell"></i> Notifications
      <?php if($unreadCount > 0): ?>
      <span class="notif-badge-pill"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
      <?php endif; ?>
    </a>
    <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
    <hr class="sidebar-divider">
    <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
  </nav>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
  <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Menu">
    <i class="bi bi-list"></i>
  </button>
  <div class="topbar-title"><i class="bi bi-search me-2"></i>Check Availability</div>
  <div class="topbar-user">
    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
    <div>
      <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
      <div style="font-size:.72rem;color:#800000">Staff — <?= htmlspecialchars($me['office_name'] ?? '—') ?></div>
    </div>
  </div>
</div>

<!-- ── Main ── -->
<div class="main-content">

  <div class="search-card">
    <h6><i class="bi bi-calendar-range me-2"></i>Check Vehicle &amp; Driver Availability</h6>
    <form method="GET" action="">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label fw-semibold" style="font-size:.82rem">Date From <span class="text-danger">*</span></label>
          <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($checkDate) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold" style="font-size:.82rem">Date To <span class="text-danger">*</span></label>
          <input type="date" name="date_end" class="form-control form-control-sm" value="<?= htmlspecialchars($checkDateE) ?>" required>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold" style="font-size:.82rem">Time Start <span class="text-muted">(optional)</span></label>
          <input type="time" name="time_start" class="form-control form-control-sm" value="<?= htmlspecialchars($timeStart) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold" style="font-size:.82rem">Time End <span class="text-muted">(optional)</span></label>
          <input type="time" name="time_end" class="form-control form-control-sm" value="<?= htmlspecialchars($timeEnd) ?>">
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-sm w-100 fw-semibold" style="background:#800000;color:#fff;border-radius:8px;padding:.45rem">
            <i class="bi bi-search me-1"></i>Check Now
          </button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($searched): ?>

  <div class="mb-3">
    <span class="range-display">
      <i class="bi bi-calendar-range"></i>
      Showing availability for:
      <?= fd($checkDate) ?>
      <?php if($checkDate !== $checkDateE): ?> → <?= fd($checkDateE) ?><?php endif; ?>
      <?php if($timeStart): ?> &nbsp;·&nbsp; <?= ft($timeStart) ?><?php if($timeEnd): ?> – <?= ft($timeEnd) ?><?php endif; ?><?php endif; ?>
    </span>
  </div>

  <div class="row g-3">

    <!-- Vehicles -->
    <div class="col-12">
      <div class="section-card mb-3">
        <div class="section-header sh-green">
          <i class="bi bi-truck"></i>
          Available Vehicles
          <span class="ms-auto badge bg-white text-success fw-bold" style="font-size:.78rem"><?= count($availVehicles) ?> available</span>
        </div>
        <div class="summary-bar">
          <span class="spill spill-green"><i class="bi bi-check-circle-fill"></i><?= count($availVehicles) ?> Free</span>
          <span class="spill spill-red"><i class="bi bi-x-circle-fill"></i><?= count($busyVehicles) ?> On Schedule</span>
        </div>
        <?php if(empty($availVehicles)): ?>
        <div class="empty-state"><i class="bi bi-truck"></i><p>No vehicles available on the selected date range.</p></div>
        <?php else: ?>
        <div class="avail-grid">
          <?php foreach($availVehicles as $v): ?>
          <div class="avail-card">
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="avail-icon icon-green"><i class="bi bi-truck"></i></div>
              <div>
                <div class="card-name"><?= htmlspecialchars($v['brand'].' '.$v['model']) ?></div>
                <div class="card-sub"><?= htmlspecialchars($v['plate_number'] ?? '—') ?></div>
              </div>
            </div>
            <?php if(!empty($v['type'])): ?><div class="card-meta"><i class="bi bi-tag"></i><?= htmlspecialchars($v['type']) ?></div><?php endif; ?>
            <?php if(!empty($v['capacity'])): ?><div class="card-meta"><i class="bi bi-people"></i>Capacity: <?= htmlspecialchars($v['capacity']) ?></div><?php endif; ?>
            <div><span class="badge-avail"><i class="bi bi-check-circle-fill"></i>Available</span></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if(!empty($busyVehicles)): ?>
      <div class="section-card">
        <div class="section-header sh-red">
          <i class="bi bi-truck"></i>
          Vehicles on Schedule
          <span class="ms-auto badge bg-white text-danger fw-bold" style="font-size:.78rem"><?= count($busyVehicles) ?> busy</span>
        </div>
        <div class="avail-grid">
          <?php foreach($busyVehicles as $v): ?>
          <div class="avail-card avail-card-busy">
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="avail-icon icon-red"><i class="bi bi-truck"></i></div>
              <div>
                <div class="card-name"><?= htmlspecialchars($v['brand'].' '.$v['model']) ?></div>
                <div class="card-sub"><?= htmlspecialchars($v['plate_number'] ?? '—') ?></div>
              </div>
            </div>
            <div class="card-meta"><i class="bi bi-building"></i><?= htmlspecialchars($v['office_name']) ?></div>
            <div class="card-meta"><i class="bi bi-person"></i><?= htmlspecialchars($v['requestor']) ?></div>
            <div class="card-meta"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($v['destination']) ?></div>
            <div class="card-meta">
              <i class="bi bi-calendar"></i>
              <?= fd($v['date_start']) ?>
              <?php if($v['date_start']!==$v['date_end']): ?> → <?= fd($v['date_end']) ?><?php endif; ?>
            </div>
            <div class="card-meta"><i class="bi bi-clock"></i><?= ft($v['time_start']).' – '.ft($v['time_end']) ?></div>
            <div>
              <?php if($v['status']==='OnTrip'): ?>
                <span class="badge-busy"><i class="bi bi-truck"></i>On Trip</span>
                <div class="card-meta mt-1" style="color:#842029;font-size:.72rem">
                  <i class="bi bi-hourglass-split"></i>
                  Free after: <?= date('M j, g:i A', strtotime($v['grace_until'])) ?>
                  <span style="opacity:.7">(+1hr grace)</span>
                </div>
              <?php else: ?>
                <span class="badge-busy"><i class="bi bi-calendar-check"></i>Approved</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Drivers -->
    <div class="col-12">
      <div class="section-card mb-3">
        <div class="section-header sh-blue">
          <i class="bi bi-person-badge"></i>
          Available Drivers
          <span class="ms-auto badge bg-white text-primary fw-bold" style="font-size:.78rem"><?= count($availDrivers) ?> available</span>
        </div>
        <div class="summary-bar">
          <span class="spill spill-blue"><i class="bi bi-check-circle-fill"></i><?= count($availDrivers) ?> Free</span>
          <span class="spill spill-orange"><i class="bi bi-x-circle-fill"></i><?= count($busyDrivers) ?> On Schedule</span>
        </div>
        <?php if(empty($availDrivers)): ?>
        <div class="empty-state"><i class="bi bi-person-badge"></i><p>No drivers available on the selected date range.</p></div>
        <?php else: ?>
        <div class="avail-grid">
          <?php foreach($availDrivers as $d): ?>
          <div class="avail-card">
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="avail-icon icon-green"><i class="bi bi-person-badge"></i></div>
              <div>
                <div class="card-name"><?= htmlspecialchars($d['driver_name']) ?></div>
                <?php if(!empty($d['license_no'])): ?><div class="card-sub">License: <?= htmlspecialchars($d['license_no']) ?></div><?php endif; ?>
              </div>
            </div>
            <?php if(!empty($d['contact_no'])): ?><div class="card-meta"><i class="bi bi-telephone"></i><?= htmlspecialchars($d['contact_no']) ?></div><?php endif; ?>
            <?php if(!empty($d['license_expiry'])): ?><div class="card-meta"><i class="bi bi-card-text"></i>Exp: <?= fd($d['license_expiry']) ?></div><?php endif; ?>
            <div><span class="badge-avail"><i class="bi bi-check-circle-fill"></i>Available</span></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if(!empty($busyDrivers)): ?>
      <div class="section-card">
        <div class="section-header sh-orange">
          <i class="bi bi-person-badge"></i>
          Drivers on Schedule
          <span class="ms-auto badge bg-white fw-bold" style="font-size:.78rem;color:#7a4f00"><?= count($busyDrivers) ?> busy</span>
        </div>
        <div class="avail-grid">
          <?php foreach($busyDrivers as $d): ?>
          <div class="avail-card avail-card-busy">
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="avail-icon icon-red"><i class="bi bi-person-badge"></i></div>
              <div>
                <div class="card-name"><?= htmlspecialchars($d['driver_name']) ?></div>
                <?php if(!empty($d['license_no'])): ?><div class="card-sub">License: <?= htmlspecialchars($d['license_no']) ?></div><?php endif; ?>
              </div>
            </div>
            <div class="card-meta"><i class="bi bi-building"></i><?= htmlspecialchars($d['office_name']) ?></div>
            <div class="card-meta"><i class="bi bi-person"></i><?= htmlspecialchars($d['requestor']) ?></div>
            <div class="card-meta"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($d['destination']) ?></div>
            <div class="card-meta">
              <i class="bi bi-calendar"></i>
              <?= fd($d['date_start']) ?>
              <?php if($d['date_start']!==$d['date_end']): ?> → <?= fd($d['date_end']) ?><?php endif; ?>
            </div>
            <div class="card-meta"><i class="bi bi-clock"></i><?= ft($d['time_start']).' – '.ft($d['time_end']) ?></div>
            <div>
              <?php if($d['status']==='OnTrip'): ?>
                <span class="badge-busy"><i class="bi bi-truck"></i>On Trip</span>
                <div class="card-meta mt-1" style="color:#842029;font-size:.72rem">
                  <i class="bi bi-hourglass-split"></i>
                  Free after: <?= date('M j, g:i A', strtotime($d['grace_until'])) ?>
                  <span style="opacity:.7">(+1hr grace)</span>
                </div>
              <?php else: ?>
                <span class="badge-busy"><i class="bi bi-calendar-check"></i>Approved</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <?php else: ?>
  <div class="section-card">
    <div class="empty-state" style="padding:3.5rem 1rem">
      <i class="bi bi-search" style="font-size:2.8rem;display:block;margin-bottom:.75rem;opacity:.25;color:#800000"></i>
      <p style="font-size:.92rem;color:#999">Select a date range above and click <strong>Check Now</strong> to see available vehicles and drivers.</p>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const isOpen  = sidebar.classList.toggle('open');
    overlay.classList.toggle('open', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
}
document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) toggleSidebar();
    });
});
document.querySelector('[name="date"]').addEventListener('change', function(){
    const de = document.querySelector('[name="date_end"]');
    if(de.value && de.value < this.value) de.value = this.value;
    de.min = this.value;
});
document.addEventListener('DOMContentLoaded', function(){
    const ds = document.querySelector('[name="date"]');
    const de = document.querySelector('[name="date_end"]');
    if(ds.value) de.min = ds.value;
});
</script>
</body>
</html>