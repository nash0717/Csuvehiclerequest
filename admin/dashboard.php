<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();
$_SESSION['just_logged_in'] = true;
date_default_timezone_set('Asia/Manila');
$today     = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear  = date('Y');

/* ── Current admin + office ── */
$cu = $pdo->prepare("SELECT u.*, o.office_id AS u_office_id, o.office_name FROM users u LEFT JOIN offices o ON u.office_id=o.office_id WHERE u.user_id=?");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();
$myOfficeId = (int)($me['u_office_id'] ?? 0);
$officeName = $me['office_name'] ?? '';

/* ── Resource counts ── */
$tvStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles v WHERE v.office_id=? OR v.vehicle_scope='Both' OR v.vehicle_scope=?");
$tvStmt->execute([$myOfficeId,$officeName]);
$total_vehicles = (int)$tvStmt->fetchColumn();

$tdStmt = $pdo->prepare("SELECT COUNT(*) FROM drivers d WHERE d.office_id=? OR d.driver_scope='Both' OR d.driver_scope=?");
$tdStmt->execute([$myOfficeId,$officeName]);
$total_drivers = (int)$tdStmt->fetchColumn();

$tuStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.office_id=?");
$tuStmt->execute([$myOfficeId]);
$total_users = (int)$tuStmt->fetchColumn();

$tdepStmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE office_id=?");
$tdepStmt->execute([$myOfficeId]);
$total_depts = (int)$tdepStmt->fetchColumn();

$avStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles v WHERE v.status IN('Active','Available') AND (v.office_id=? OR v.vehicle_scope='Both' OR v.vehicle_scope=?)");
$avStmt->execute([$myOfficeId,$officeName]);
$activeVehicles = (int)$avStmt->fetchColumn();

$adStmt = $pdo->prepare("SELECT COUNT(*) FROM drivers d WHERE d.status IN('Active','Available') AND (d.office_id=? OR d.driver_scope='Both' OR d.driver_scope=?)");
$adStmt->execute([$myOfficeId,$officeName]);
$activeDrivers = (int)$adStmt->fetchColumn();

/* ── Schedule status counts ── */
$cbs = function($status) use ($pdo,$myOfficeId) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM schedules s WHERE s.office_id=? AND s.status=?");
    $st->execute([$myOfficeId,$status]); return (int)$st->fetchColumn();
};
$pending=$cbs('Pending'); $approved=$cbs('Approved'); $ontrip=$cbs('OnTrip');
$completed=$cbs('Completed'); $rejected=$cbs('Rejected'); $cancelled=$cbs('Cancelled');
$totalSchedules = $pending+$approved+$ontrip+$completed+$rejected+$cancelled;
$completionRate = $totalSchedules > 0 ? round(($completed/$totalSchedules)*100) : 0;

/* ── This month ── */
$monthStmt = $pdo->prepare("SELECT COUNT(*) FROM schedules s WHERE s.office_id=? AND DATE_FORMAT(s.date_start,'%Y-%m')=?");
$monthStmt->execute([$myOfficeId,$thisMonth]);
$schedulesThisMonth = (int)$monthStmt->fetchColumn();

$monthCmpStmt = $pdo->prepare("SELECT COUNT(*) FROM schedules s WHERE s.office_id=? AND s.status='Completed' AND DATE_FORMAT(s.date_start,'%Y-%m')=?");
$monthCmpStmt->execute([$myOfficeId,$thisMonth]);
$completedThisMonth = (int)$monthCmpStmt->fetchColumn();

/* ── Trips today ── */
$ttStmt = $pdo->prepare("
    SELECT s.*, u.username, o.office_name,
           COALESCE(v.plate_number,'—') AS plate_number,
           COALESCE(v.brand,'') AS brand, COALESCE(v.model,'') AS model,
           COALESCE(dr.driver_name,'—') AS driver_name
    FROM schedules s
    JOIN users u ON s.user_id=u.user_id JOIN offices o ON s.office_id=o.office_id
    LEFT JOIN vehicles v ON s.vehicle_id=v.vehicle_id
    LEFT JOIN drivers dr ON s.driver_id=dr.driver_id
    WHERE s.status IN('Approved','OnTrip') AND s.date_start<=:today AND s.date_end>=:today AND s.office_id=:oid
    ORDER BY s.time_start ASC
");
$ttStmt->execute([':today'=>$today,':oid'=>$myOfficeId]);
$tripsToday = $ttStmt->fetchAll();

/* ── Monthly trend (last 6 months) ── */
$monthLabels=[]; $monthCompleted=[]; $monthTotal=[];
for($i=5;$i>=0;$i--){
    $m=date('Y-m',strtotime("-$i months")); $lbl=date('M y',strtotime("-$i months"));
    $stT=$pdo->prepare("SELECT COUNT(*) FROM schedules s WHERE s.office_id=? AND DATE_FORMAT(s.date_start,'%Y-%m')=?");
    $stT->execute([$myOfficeId,$m]);
    $stC=$pdo->prepare("SELECT COUNT(*) FROM schedules s WHERE s.office_id=? AND s.status='Completed' AND DATE_FORMAT(s.date_start,'%Y-%m')=?");
    $stC->execute([$myOfficeId,$m]);
    $monthLabels[]=$lbl; $monthTotal[]=(int)$stT->fetchColumn(); $monthCompleted[]=(int)$stC->fetchColumn();
}

/* ── Day of week ── */
$dowStmt = $pdo->prepare("SELECT DAYOFWEEK(date_start) AS dow, COUNT(*) AS cnt FROM schedules WHERE office_id=? GROUP BY dow ORDER BY dow");
$dowStmt->execute([$myOfficeId]);
$dowRaw = $dowStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$dowLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$dowData = array_map(fn($i)=>(int)($dowRaw[$i]??0), range(1,7));

/* ── Top destinations ── */
$destStmt = $pdo->prepare("SELECT destination, COUNT(*) AS cnt FROM schedules WHERE office_id=? AND destination IS NOT NULL AND destination!='' GROUP BY destination ORDER BY cnt DESC LIMIT 5");
$destStmt->execute([$myOfficeId]);
$topDestinations = $destStmt->fetchAll();

/* ── Top requestors ── */
$reqStmt = $pdo->prepare("SELECT u.username, COUNT(*) AS cnt FROM schedules s JOIN users u ON s.user_id=u.user_id WHERE s.office_id=? GROUP BY s.user_id,u.username ORDER BY cnt DESC LIMIT 5");
$reqStmt->execute([$myOfficeId]);
$topRequestors = $reqStmt->fetchAll();

/* ── Most used vehicles ── */
$vehUseStmt = $pdo->prepare("SELECT CONCAT(v.brand,' ',v.model,' (',v.plate_number,')') AS vname, COUNT(*) AS cnt FROM schedules s JOIN vehicles v ON s.vehicle_id=v.vehicle_id WHERE s.office_id=? AND s.vehicle_id IS NOT NULL GROUP BY s.vehicle_id ORDER BY cnt DESC LIMIT 5");
$vehUseStmt->execute([$myOfficeId]);
$topVehicles = $vehUseStmt->fetchAll();

/* ── Most used drivers ── */
$drvUseStmt = $pdo->prepare("SELECT dr.driver_name, COUNT(*) AS cnt FROM schedules s JOIN drivers dr ON s.driver_id=dr.driver_id WHERE s.office_id=? AND s.driver_id IS NOT NULL GROUP BY s.driver_id ORDER BY cnt DESC LIMIT 5");
$drvUseStmt->execute([$myOfficeId]);
$topDrivers = $drvUseStmt->fetchAll();
/* ── Rankings date filter (AJAX endpoint guard) ── */
if (!empty($_GET['rankings_ajax'])) {
    $filter = $_GET['filter'] ?? 'all';
    $customStart = $_GET['date_start'] ?? null;
    $customEnd   = $_GET['date_end']   ?? null;

    $dateWhere = '';
    $dateParams = [$myOfficeId];

    switch ($filter) {
        case 'day':
            $dateWhere = "AND DATE(s.date_start) = CURDATE()"; break;
        case 'week':
            $dateWhere = "AND YEARWEEK(s.date_start,1) = YEARWEEK(CURDATE(),1)"; break;
        case 'month':
            $dateWhere = "AND DATE_FORMAT(s.date_start,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')"; break;
        case 'year':
            $dateWhere = "AND YEAR(s.date_start) = YEAR(CURDATE())"; break;
        case 'custom':
            if ($customStart && $customEnd) {
                $dateWhere = "AND s.date_start BETWEEN ? AND ?";
                $dateParams[] = $customStart;
                $dateParams[] = $customEnd;
            }
            break;
    }

    $qDest = $pdo->prepare("SELECT destination, COUNT(*) AS cnt FROM schedules s WHERE s.office_id=? $dateWhere AND destination IS NOT NULL AND destination!='' GROUP BY destination ORDER BY cnt DESC LIMIT 5");
    $qDest->execute($dateParams);

    $qReq = $pdo->prepare("SELECT u.username, COUNT(*) AS cnt FROM schedules s JOIN users u ON s.user_id=u.user_id WHERE s.office_id=? $dateWhere GROUP BY s.user_id,u.username ORDER BY cnt DESC LIMIT 5");
    $qReq->execute($dateParams);

    $qVeh = $pdo->prepare("SELECT CONCAT(v.brand,' ',v.model,' (',v.plate_number,')') AS vname, COUNT(*) AS cnt FROM schedules s JOIN vehicles v ON s.vehicle_id=v.vehicle_id WHERE s.office_id=? $dateWhere AND s.vehicle_id IS NOT NULL GROUP BY s.vehicle_id ORDER BY cnt DESC LIMIT 5");
    $qVeh->execute($dateParams);

    $qDrv = $pdo->prepare("SELECT dr.driver_name, COUNT(*) AS cnt FROM schedules s JOIN drivers dr ON s.driver_id=dr.driver_id WHERE s.office_id=? $dateWhere AND s.driver_id IS NOT NULL GROUP BY s.driver_id ORDER BY cnt DESC LIMIT 5");
    $qDrv->execute($dateParams);

    header('Content-Type: application/json');
    echo json_encode([
        'destinations' => $qDest->fetchAll(PDO::FETCH_ASSOC),
        'requestors'   => $qReq->fetchAll(PDO::FETCH_ASSOC),
        'vehicles'     => $qVeh->fetchAll(PDO::FETCH_ASSOC),
        'drivers'      => $qDrv->fetchAll(PDO::FETCH_ASSOC),
    ]);
    exit;
}
/* ── Upcoming (next 7 days) ── */
$next7 = date('Y-m-d',strtotime('+7 days'));
$upStmt = $pdo->prepare("
    SELECT s.*, u.username,
           COALESCE(v.plate_number,'—') AS plate_number,
           COALESCE(v.brand,'') AS brand, COALESCE(v.model,'') AS model,
           COALESCE(dr.driver_name,'—') AS driver_name
    FROM schedules s JOIN users u ON s.user_id=u.user_id
    LEFT JOIN vehicles v ON s.vehicle_id=v.vehicle_id
    LEFT JOIN drivers dr ON s.driver_id=dr.driver_id
    WHERE s.status='Approved' AND s.date_start>:today AND s.date_start<=:next7 AND s.office_id=:oid
    ORDER BY s.date_start ASC, s.time_start ASC LIMIT 8
");
$upStmt->execute([':today'=>$today,':next7'=>$next7,':oid'=>$myOfficeId]);
$upcomingTrips = $upStmt->fetchAll();

/* ── Oldest pending ── */
$oldPendStmt = $pdo->prepare("
    SELECT s.*, u.username, DATEDIFF(NOW(),s.created_at) AS days_waiting
    FROM schedules s JOIN users u ON s.user_id=u.user_id
    WHERE s.office_id=? AND s.status='Pending' ORDER BY s.schedule_id ASC LIMIT 5
");
$oldPendStmt->execute([$myOfficeId]);
$oldestPending = $oldPendStmt->fetchAll();

/* ── Recent schedules ── */
$recentStmt = $pdo->prepare("
    SELECT s.*, u.username,
           COALESCE(v.plate_number,'—') AS plate_number,
           COALESCE(v.brand,'') AS brand, COALESCE(v.model,'') AS model,
           COALESCE(dr.driver_name,'—') AS driver_name, o.office_name
    FROM schedules s JOIN users u ON s.user_id=u.user_id
    LEFT JOIN vehicles v ON s.vehicle_id=v.vehicle_id
    LEFT JOIN drivers dr ON s.driver_id=dr.driver_id
    JOIN offices o ON s.office_id=o.office_id
    WHERE s.office_id=? ORDER BY s.schedule_id DESC LIMIT 10
");
$recentStmt->execute([$myOfficeId]);
$recent = $recentStmt->fetchAll();

/* ── Notif count ── */
$_notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$_notifStmt->execute([$_SESSION['user_id']]);
$_sidebarUnread = (int)$_notifStmt->fetchColumn();

$statusBreakdown = compact('pending','approved','ontrip','completed','rejected','cancelled');

function fmt_date($d){ return $d ? date('M j, Y',strtotime($d)) : '—'; }
function pct($n,$total){ return $total>0 ? round(($n/$total)*100) : 0; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – CSU VSS Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:#f5f0f0;font-family:'Segoe UI',sans-serif}

/* ══ SIDEBAR ══ */
.sidebar{
    min-height:100vh;
    background:linear-gradient(180deg,#800000,#6b0000);
    width:240px;position:fixed;top:0;left:0;
    z-index:200;display:flex;flex-direction:column;
    transition:transform 0.25s ease;
}
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

/* ══ SIDEBAR OVERLAY (mobile) ══ */
.sidebar-overlay{
    display:none;
    position:fixed;inset:0;
    background:rgba(0,0,0,0.45);
    z-index:199;
}
.sidebar-overlay.show{display:block}

/* ══ TOPBAR ══ */
.topbar{
    background:#fff;border-bottom:1px solid #e8dede;
    padding:.7rem 1.5rem;margin-left:240px;
    position:sticky;top:0;z-index:99;
    display:flex;align-items:center;justify-content:space-between;
}
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}

/* Hamburger – hidden on desktop */
.hamburger-btn{
    display:none;
    background:none;border:none;cursor:pointer;
    padding:4px 8px;color:#800000;font-size:1.4rem;
    align-items:center;justify-content:center;
    line-height:1;
}

/* ══ MAIN ══ */
.main-content{margin-left:240px;padding:1.5rem}

/* ══ CARDS / TABLE ══ */
.stat-card{border:none;border-radius:14px;padding:1.1rem 1.25rem;background:#fff;box-shadow:0 2px 12px rgba(128,0,0,.06);transition:transform .15s,box-shadow .15s;height:100%}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(128,0,0,.11)}
.stat-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.stat-label{font-size:.72rem;color:#999;margin-bottom:2px;text-transform:uppercase;letter-spacing:.05em}
.stat-value{font-size:1.55rem;font-weight:700;color:#2d2d2d;line-height:1}
.stat-sub{font-size:.72rem;color:#aaa;margin-top:3px}
.section-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.06);overflow:hidden;margin-bottom:1.25rem}
.section-header{padding:.85rem 1.25rem;border-bottom:1px solid #f0e5e5;font-weight:700;font-size:.88rem;color:#800000;display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap}
.table thead th{background:#fdf5f5;color:#800000;font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid #f0e5e5;padding:.6rem 1rem;white-space:nowrap}
.table tbody td{padding:.58rem 1rem;font-size:.82rem;color:#444;vertical-align:middle;border-color:#fdf5f5}
.table tbody tr:hover{background:#fdf8f8}
.bp{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:600;white-space:nowrap}
.bp-pending{background:#fff3cd;color:#856404}
.bp-approved{background:#d1e7dd;color:#0f5132}
.bp-ontrip{background:#fff0d6;color:#7a4f00}
.bp-completed{background:#cfe2ff;color:#0a3678}
.bp-rejected{background:#f8d7da;color:#842029}
.bp-cancelled{background:#e2e3e5;color:#41464b}
.office-pill{display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;border:1px solid #e8cece;border-radius:20px;padding:3px 12px;font-size:.78rem;color:#800000;font-weight:600}
.trip-row{display:flex;align-items:flex-start;gap:.75rem;padding:.65rem 1.25rem;border-bottom:1px solid #fdf5f5}
.trip-row:last-child{border-bottom:none}
.trip-time{min-width:72px;font-size:.76rem;font-weight:700;color:#800000;padding-top:2px;flex-shrink:0}
.trip-info{flex:1;min-width:0}
.trip-dest{font-size:.83rem;font-weight:600;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.trip-meta{font-size:.73rem;color:#888;margin-top:2px}
.chart-legend{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;font-size:.73rem}
.chart-legend-item{display:flex;align-items:center;gap:4px}
.legend-dot{width:10px;height:10px;border-radius:2px;flex-shrink:0}
.rank-row{display:flex;align-items:center;gap:.6rem;padding:.5rem 1.25rem;border-bottom:1px solid #fdf5f5}
.rank-row:last-child{border-bottom:none}
.rank-num{width:22px;height:22px;border-radius:50%;background:#fdf5f5;color:#800000;font-size:.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.rank-bar-wrap{flex:1;background:#f5f0f0;border-radius:4px;height:6px;overflow:hidden}
.rank-bar{height:100%;border-radius:4px;background:#800000}
.rank-label{font-size:.79rem;font-weight:600;color:#333;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-count{font-size:.74rem;color:#888;flex-shrink:0;min-width:28px;text-align:right}
.kpi-ring{position:relative;display:inline-flex;align-items:center;justify-content:center}
.kpi-ring svg{transform:rotate(-90deg)}
.kpi-ring-label{position:absolute;text-align:center}
.alert-row{display:flex;align-items:center;gap:.75rem;padding:.6rem 1.25rem;border-bottom:1px solid #fdf5f5}
.alert-row:last-child{border-bottom:none}
.alert-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#856404}

/* ══ MOBILE RESPONSIVE ══ */
@media (max-width: 768px) {
    /* Sidebar hidden off-screen by default */
    .sidebar {
        transform: translateX(-100%);
    }
    .sidebar.open {
        transform: translateX(0);
    }

    /* Remove desktop left-margin */
    .topbar,
    .main-content {
        margin-left: 0 !important;
    }

    /* Show hamburger */
    .hamburger-btn {
        display: flex !important;
    }

    /* Welcome bar: stack vertically */
    .welcome-bar {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 8px !important;
    }

    /* Stat cards: 2-up on mobile */
    .col-6  { width: 50% !important; }
    .col-lg-3 { width: 50% !important; }
    .col-lg-2 { width: 33.333% !important; }
    .col-md-4 { width: 33.333% !important; }

    /* Charts: ensure they don't overflow */
    canvas { max-width: 100%; }

    /* Table: horizontal scroll */
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* Trip rows: tighter on small screens */
    .trip-row { padding: .5rem .9rem; gap: .5rem; }
    .trip-time { min-width: 60px; }

    /* Rank rows */
    .rank-row { padding: .45rem .9rem; }

    /* Section headers: allow wrap */
    .section-header { font-size: .82rem; padding: .7rem .9rem; }

    /* Completion ring: center properly */
    .kpi-ring { display: flex; justify-content: center; }

    /* Alert/toast on mobile */
    #welcomeToast {
        bottom: .75rem !important;
        right: .75rem !important;
        left: .75rem !important;
        min-width: unset !important;
        max-width: unset !important;
    }

    /* Notif badge stays in place */
    .stat-value { font-size: 1.3rem; }
}

@media (max-width: 480px) {
    .col-lg-2 { width: 50% !important; }
    .col-md-4 { width: 50% !important; }
    .main-content { padding: 1rem; }
    .topbar { padding: .6rem 1rem; }
}
</style>
</head>
<body>

<!-- ══ SIDEBAR OVERLAY (mobile backdrop) ══ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ══ SIDEBAR ══ -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo"><img src="../image/Csu.png" alt="CSU Logo"></div>
        <div class="sidebar-brand-text">CSU Vehicle System<span>Admin Panel</span></div>
    </div>
    <nav class="nav flex-column mt-2">
        <div class="nav-section-label">Main</div>
        <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <div class="nav-section-label">Manage</div>
        <a class="nav-link" href="Vehicles.php"><i class="bi bi-truck-front"></i>Vehicles</a>
        <a class="nav-link" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i>Driver Trip Records</a>
        <a class="nav-link" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link " href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="nav-link" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="nav-section-label">Scheduling</div>
        <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="nav-section-label">Settings</div>
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

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <!-- Hamburger: only visible on mobile via CSS -->
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-speedometer2 me-2"></i>Dashboard</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<!-- ══ MAIN CONTENT ══ -->
<div class="main-content">

    <!-- Welcome bar -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2 welcome-bar">
        <div>
            <h5 class="fw-bold mb-0" style="color:#800000">Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>!</h5>
            <div class="text-muted small" id="live-datetime"><?= date('l, F j, Y') ?> &nbsp;·&nbsp; <?= date('g:i A') ?> PHT</div>
        </div>
        <?php if($officeName): ?>
        <span class="office-pill"><i class="bi bi-building" style="font-size:.8rem"></i><?= htmlspecialchars($officeName) ?></span>
        <?php endif; ?>
    </div>

    <!-- ══ ROW 1: Resource KPIs ══ -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fdecea;color:#800000"><i class="bi bi-truck-front"></i></div>
                    <div><div class="stat-label">Vehicles</div><div class="stat-value"><?= $total_vehicles ?></div><div class="stat-sub"><?= $activeVehicles ?> active</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fdecea;color:#800000"><i class="bi bi-person-badge"></i></div>
                    <div><div class="stat-label">Drivers</div><div class="stat-value"><?= $total_drivers ?></div><div class="stat-sub"><?= $activeDrivers ?> active</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#e8f4fd;color:#0c5480"><i class="bi bi-people"></i></div>
                    <div><div class="stat-label">Users</div><div class="stat-value"><?= $total_users ?></div><div class="stat-sub">in this office</div></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#eaf4eb;color:#1a6b2a"><i class="bi bi-diagram-3"></i></div>
                    <div><div class="stat-label">Departments</div><div class="stat-value"><?= $total_depts ?></div><div class="stat-sub">in this office</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ROW 2: Schedule status ══ -->
    <div class="row g-3 mb-3">
        <?php
        $statuses=[
            ['Pending',$pending,'bi-hourglass-split','#fff3cd','#856404'],
            ['Approved',$approved,'bi-check-circle','#d1e7dd','#0f5132'],
            ['On Trip',$ontrip,'bi-truck','#fff0d6','#7a4f00'],
            ['Completed',$completed,'bi-check2-all','#cfe2ff','#0a3678'],
            ['Rejected',$rejected,'bi-x-circle','#f8d7da','#842029'],
            ['Cancelled',$cancelled,'bi-slash-circle','#e2e3e5','#41464b'],
        ];
        foreach($statuses as [$lbl,$val,$icon,$bg,$clr]):
        ?>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card text-center">
                <div class="stat-icon mx-auto mb-2" style="background:<?=$bg?>;color:<?=$clr?>"><i class="bi <?=$icon?>"></i></div>
                <div class="stat-label"><?=$lbl?></div>
                <div class="stat-value"><?=$val?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ══ ROW 3: Month summary + Completion ring + Busiest days ══ -->
    <div class="row g-3 mb-3">

        <div class="col-lg-4">
            <div class="section-card h-100" style="margin-bottom:0">
                <div class="section-header"><i class="bi bi-calendar-month me-2"></i><?= date('F Y') ?> Summary</div>
                <div style="padding:1rem 1.25rem">
                    <div class="row g-2">
                        <div class="col-6">
                            <div style="background:#fdf5f5;border-radius:10px;padding:.75rem;text-align:center">
                                <div style="font-size:1.4rem;font-weight:700;color:#800000"><?= $schedulesThisMonth ?></div>
                                <div style="font-size:.72rem;color:#999;text-transform:uppercase;letter-spacing:.04em">Total Trips</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div style="background:#f0fdf4;border-radius:10px;padding:.75rem;text-align:center">
                                <div style="font-size:1.4rem;font-weight:700;color:#1a6b2a"><?= $completedThisMonth ?></div>
                                <div style="font-size:.72rem;color:#999;text-transform:uppercase;letter-spacing:.04em">Completed</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div style="background:#fffbea;border-radius:10px;padding:.75rem;text-align:center">
                                <div style="font-size:1.4rem;font-weight:700;color:#856404"><?= $pending ?></div>
                                <div style="font-size:.72rem;color:#999;text-transform:uppercase;letter-spacing:.04em">Pending</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div style="background:#f0f4ff;border-radius:10px;padding:.75rem;text-align:center">
                                <div style="font-size:1.4rem;font-weight:700;color:#0a3678"><?= $ontrip ?></div>
                                <div style="font-size:.72rem;color:#999;text-transform:uppercase;letter-spacing:.04em">On Trip Now</div>
                            </div>
                        </div>
                    </div>
                    <?php if($pending>0): ?>
                    <div style="margin-top:.85rem;background:#fff3cd;border-radius:8px;padding:.55rem .85rem;font-size:.78rem;color:#856404;display:flex;align-items:center;gap:.5rem">
                        <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0"></i>
                        <span><strong><?=$pending?></strong> request<?=$pending!==1?'s':''?> awaiting approval</span>
                        <a href="Schedules.php?filter=Pending" style="margin-left:auto;color:#856404;font-weight:700;font-size:.73rem;white-space:nowrap;text-decoration:none">Review →</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="section-card h-100" style="margin-bottom:0">
                <div class="section-header"><i class="bi bi-award me-2"></i>Overall Completion Rate</div>
                <div style="padding:1.25rem;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75rem">
                    <?php $r=54; $circ=round(2*M_PI*$r,1); $dash=round($circ*$completionRate/100,1); ?>
                    <div class="kpi-ring">
                        <svg width="130" height="130" viewBox="0 0 130 130">
                            <circle cx="65" cy="65" r="<?=$r?>" fill="none" stroke="#f0e5e5" stroke-width="10"/>
                            <circle cx="65" cy="65" r="<?=$r?>" fill="none" stroke="#800000" stroke-width="10"
                                stroke-dasharray="<?=$dash?> <?=$circ?>" stroke-linecap="round"/>
                        </svg>
                        <div class="kpi-ring-label">
                            <div style="font-size:1.5rem;font-weight:700;color:#800000"><?=$completionRate?>%</div>
                            <div style="font-size:.68rem;color:#aaa">completed</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:1.5rem;font-size:.78rem;color:#666;text-align:center">
                        <div><div style="font-weight:700;color:#0a3678;font-size:1rem"><?=$completed?></div>Done</div>
                        <div><div style="font-weight:700;color:#842029;font-size:1rem"><?=$rejected?></div>Rejected</div>
                        <div><div style="font-weight:700;color:#41464b;font-size:1rem"><?=$cancelled?></div>Cancelled</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="section-card h-100" style="margin-bottom:0">
                <div class="section-header"><i class="bi bi-bar-chart me-2"></i>Busiest Days of Week</div>
                <div style="padding:.85rem 1.25rem">
                    <div style="position:relative;width:100%;height:170px">
                        <canvas id="dowChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ══ ROW 4: 6-month trend ══ -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="section-card" style="margin-bottom:0">
                <div class="section-header">
                    <span><i class="bi bi-graph-up me-2"></i>6-Month Schedule Trend</span>
                    <span style="font-size:.73rem;color:#aaa"><?= date('M Y',strtotime('-5 months')) ?> – <?= date('M Y') ?></span>
                </div>
                <div style="padding:.85rem 1.25rem">
                    <div class="chart-legend">
                        <span class="chart-legend-item"><span class="legend-dot" style="background:rgba(128,0,0,.25);border:1px solid #800000"></span><span style="color:#800000;font-weight:600">Total</span></span>
                        <span class="chart-legend-item"><span class="legend-dot" style="background:rgba(32,96,196,.2);border:1px solid #2060c4;border-style:dashed"></span><span style="color:#0a3678;font-weight:600">Completed</span></span>
                    </div>
                    <div style="position:relative;width:100%;height:200px">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ROW 5: Trips Today + Upcoming ══ -->
    <div class="row g-3 mb-3">

        <div class="col-lg-6">
            <div class="section-card" style="margin-bottom:0">
                <div class="section-header">
                    <span><i class="bi bi-calendar-day me-2"></i>Trips Today <span style="font-size:.72rem;color:#aaa;font-weight:400"><?= date('M j') ?></span></span>
                    <span class="bp bp-ontrip"><?= count($tripsToday) ?> trip<?= count($tripsToday)!==1?'s':'' ?></span>
                </div>
                <?php if(empty($tripsToday)): ?>
                <div style="text-align:center;padding:2rem;color:#ccc"><i class="bi bi-calendar-check" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.3"></i><div style="font-size:.83rem">No trips today.</div></div>
                <?php else: foreach($tripsToday as $t): ?>
                <div class="trip-row">
                    <div class="trip-time"><?= $t['time_start']?date('g:i A',strtotime($t['time_start'])):'—' ?><?php if($t['time_end']): ?><div style="font-size:.67rem;color:#bbb;font-weight:400">→<?= date('g:i A',strtotime($t['time_end'])) ?></div><?php endif; ?></div>
                    <div class="trip-info">
                        <div class="trip-dest"><i class="bi bi-geo-alt me-1" style="font-size:.7rem;color:#800000"></i><?= htmlspecialchars($t['destination']) ?></div>
                        <div class="trip-meta"><i class="bi bi-person me-1"></i><?= htmlspecialchars($t['username']) ?> &nbsp;·&nbsp; <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($t['driver_name']) ?></div>
                        <div class="trip-meta"><i class="bi bi-truck me-1"></i><?= htmlspecialchars(trim($t['brand'].' '.$t['model'])) ?: '—' ?></div>
                    </div>
                    <div><?php if($t['status']==='OnTrip'): ?><span class="bp bp-ontrip"><i class="bi bi-truck"></i> On Trip</span><?php else: ?><span class="bp bp-approved"><i class="bi bi-check-circle"></i> Approved</span><?php endif; ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="section-card" style="margin-bottom:0">
                <div class="section-header">
                    <span><i class="bi bi-calendar-week me-2"></i>Upcoming Trips</span>
                    <span style="font-size:.73rem;color:#aaa">Next 7 days</span>
                </div>
                <?php if(empty($upcomingTrips)): ?>
                <div style="text-align:center;padding:2rem;color:#ccc"><i class="bi bi-calendar3" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.3"></i><div style="font-size:.83rem">No upcoming trips in the next 7 days.</div></div>
                <?php else: foreach($upcomingTrips as $u): ?>
                <div class="trip-row">
                    <div class="trip-time" style="min-width:80px"><div style="font-size:.72rem;color:#800000;font-weight:700"><?= date('M j',strtotime($u['date_start'])) ?></div><div style="font-size:.67rem;color:#bbb"><?= $u['time_start']?date('g:i A',strtotime($u['time_start'])):'' ?></div></div>
                    <div class="trip-info">
                        <div class="trip-dest"><i class="bi bi-geo-alt me-1" style="font-size:.7rem;color:#800000"></i><?= htmlspecialchars($u['destination']) ?></div>
                        <div class="trip-meta"><i class="bi bi-person me-1"></i><?= htmlspecialchars($u['username']) ?> &nbsp;·&nbsp; <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($u['driver_name']) ?></div>
                        <div class="trip-meta"><i class="bi bi-truck me-1"></i><?= htmlspecialchars(trim($u['brand'].' '.$u['model'])) ?: '—' ?></div>
                    </div>
                    <span class="bp bp-approved"><i class="bi bi-calendar-event"></i> Upcoming</span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div>

    <!-- ══ ROW 6: Needs Attention + Status Doughnut ══ -->
    <div class="row g-3 mb-3">

        <div class="col-lg-6">
            <div class="section-card" style="margin-bottom:0">
                <div class="section-header">
                    <span><i class="bi bi-exclamation-triangle me-2" style="color:#856404"></i>Needs Attention — Oldest Pending</span>
                    <span class="bp bp-pending"><?=$pending?> pending</span>
                </div>
                <?php if(empty($oldestPending)): ?>
                <div style="text-align:center;padding:1.75rem;color:#888;font-size:.83rem"><i class="bi bi-check-circle" style="font-size:1.5rem;display:block;margin-bottom:.4rem;color:#1a6b2a;opacity:.6"></i>No pending requests — all clear!</div>
                <?php else: foreach($oldestPending as $p): ?>
                <div class="alert-row">
                    <div class="alert-dot"></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.82rem;font-weight:600;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['destination']??'—') ?></div>
                        <div style="font-size:.73rem;color:#888"><?= htmlspecialchars($p['username']) ?> &nbsp;·&nbsp; <?= !empty($p['date_start'])?date('M j, Y',strtotime($p['date_start'])):'—' ?></div>
                    </div>
                    <?php if(!empty($p['days_waiting'])&&$p['days_waiting']>0): ?>
                    <span style="font-size:.7rem;background:#fff3cd;color:#856404;padding:2px 8px;border-radius:10px;font-weight:600;white-space:nowrap"><?=$p['days_waiting']?>d waiting</span>
                    <?php endif; ?>
                    <a href="Schedules.php?filter=Pending" style="font-size:.72rem;color:#800000;text-decoration:none;font-weight:700;white-space:nowrap">Review →</a>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="section-card" style="margin-bottom:0">
                <div class="section-header"><i class="bi bi-pie-chart me-2"></i>Status Breakdown</div>
                <div style="padding:.85rem 1.25rem">
                    <div class="chart-legend" id="statusLegend"></div>
                    <div style="position:relative;width:100%;height:185px">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

   <!-- ══ ROW 7: Rankings ══ -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
            <span style="font-size:.78rem;font-weight:700;color:#800000;margin-right:.25rem"><i class="bi bi-funnel me-1"></i>Rankings Filter:</span>
            <?php foreach([
                ['all','All Time'],['day','Today'],['week','This Week'],
                ['month','This Month'],['year','This Year'],['custom','Custom']
            ] as [$v,$l]): ?>
            <button class="rank-filter-btn <?= $v==='all'?'active':'' ?>"
                    data-filter="<?=$v?>"
                    style="<?= $v==='all'?'background:#800000;color:#fff;border-color:#800000':'background:#fff;color:#800000;border-color:#e8cece' ?>">
                <?=$l?>
            </button>
            <?php endforeach; ?>
            <div id="customDateWrap" style="display:none;align-items:center;gap:6px;flex-wrap:wrap">
                <input type="date" id="customStart" style="border:1px solid #e8cece;border-radius:8px;padding:4px 10px;font-size:.78rem;color:#333;outline:none">
                <span style="font-size:.78rem;color:#999">to</span>
                <input type="date" id="customEnd"   style="border:1px solid #e8cece;border-radius:8px;padding:4px 10px;font-size:.78rem;color:#333;outline:none">
                <button id="customApplyBtn" style="background:#800000;color:#fff;border:none;border-radius:8px;padding:4px 12px;font-size:.78rem;font-weight:600;cursor:pointer">Apply</button>
            </div>
            <span id="rankFilterLabel" style="font-size:.73rem;color:#aaa;margin-left:.25rem"></span>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="section-card" style="margin-bottom:0">
            <div class="section-header" style="font-size:.82rem"><i class="bi bi-geo-alt me-2"></i>Top Destinations</div>
            <div id="rankDest">
                <?php if(empty($topDestinations)): ?>
                <div style="padding:1.5rem;text-align:center;color:#ccc;font-size:.8rem">No data yet.</div>
                <?php else: $maxD=max(array_column($topDestinations,'cnt'));
                foreach($topDestinations as $i=>$d): ?>
                <div class="rank-row">
                    <div class="rank-num"><?=$i+1?></div>
                    <div style="flex:1;min-width:0">
                        <div class="rank-label"><?= htmlspecialchars($d['destination']) ?></div>
                        <div class="rank-bar-wrap mt-1"><div class="rank-bar" style="width:<?= pct($d['cnt'],$maxD) ?>%"></div></div>
                    </div>
                    <div class="rank-count"><?=$d['cnt']?>x</div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="section-card" style="margin-bottom:0">
            <div class="section-header" style="font-size:.82rem"><i class="bi bi-person me-2"></i>Top Requestors</div>
            <div id="rankReq">
                <?php if(empty($topRequestors)): ?>
                <div style="padding:1.5rem;text-align:center;color:#ccc;font-size:.8rem">No data yet.</div>
                <?php else: $maxR=max(array_column($topRequestors,'cnt'));
                foreach($topRequestors as $i=>$r): ?>
                <div class="rank-row">
                    <div class="rank-num"><?=$i+1?></div>
                    <div style="flex:1;min-width:0">
                        <div class="rank-label"><?= htmlspecialchars($r['username']) ?></div>
                        <div class="rank-bar-wrap mt-1"><div class="rank-bar" style="width:<?= pct($r['cnt'],$maxR) ?>%"></div></div>
                    </div>
                    <div class="rank-count"><?=$r['cnt']?>x</div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="section-card" style="margin-bottom:0">
            <div class="section-header" style="font-size:.82rem"><i class="bi bi-truck-front me-2"></i>Most Used Vehicles</div>
            <div id="rankVeh">
                <?php if(empty($topVehicles)): ?>
                <div style="padding:1.5rem;text-align:center;color:#ccc;font-size:.8rem">No data yet.</div>
                <?php else: $maxV=max(array_column($topVehicles,'cnt'));
                foreach($topVehicles as $i=>$v): ?>
                <div class="rank-row">
                    <div class="rank-num"><?=$i+1?></div>
                    <div style="flex:1;min-width:0">
                        <div class="rank-label"><?= htmlspecialchars($v['vname']) ?></div>
                        <div class="rank-bar-wrap mt-1"><div class="rank-bar" style="width:<?= pct($v['cnt'],$maxV) ?>%"></div></div>
                    </div>
                    <div class="rank-count"><?=$v['cnt']?>x</div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3">
        <div class="section-card" style="margin-bottom:0">
            <div class="section-header" style="font-size:.82rem"><i class="bi bi-person-badge me-2"></i>Most Used Drivers</div>
            <div id="rankDrv">
                <?php if(empty($topDrivers)): ?>
                <div style="padding:1.5rem;text-align:center;color:#ccc;font-size:.8rem">No data yet.</div>
                <?php else: $maxDr=max(array_column($topDrivers,'cnt'));
                foreach($topDrivers as $i=>$dr): ?>
                <div class="rank-row">
                    <div class="rank-num"><?=$i+1?></div>
                    <div style="flex:1;min-width:0">
                        <div class="rank-label"><?= htmlspecialchars($dr['driver_name']) ?></div>
                        <div class="rank-bar-wrap mt-1"><div class="rank-bar" style="width:<?= pct($dr['cnt'],$maxDr) ?>%"></div></div>
                    </div>
                    <div class="rank-count"><?=$dr['cnt']?>x</div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

</div>
    <!-- ══ ROW 8: Recent Schedules ══ -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-clock-history me-2"></i>Recent Schedules</span>
            <a href="Schedules.php" style="font-size:.78rem;color:#800000;text-decoration:none;font-weight:400">View all →</a>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr>
                    <th>#</th><th>Requestor</th><th>Vehicle</th><th>Driver</th>
                    <th>Date</th><th>Destination</th><th>Purpose</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach($recent as $r):
                    $ds=$r['date_start']??''; $de=$r['date_end']??'';
                    $dateStr=($ds===$de)?fmt_date($ds):fmt_date($ds).' → '.fmt_date($de);
                    $st=$r['status']??'';
                    $cls=match($st){'Pending'=>'bp-pending','Approved'=>'bp-approved','OnTrip'=>'bp-ontrip','Completed'=>'bp-completed','Rejected'=>'bp-rejected','Cancelled'=>'bp-cancelled',default=>'bp-cancelled'};
                ?>
                <tr>
                    <td style="color:#aaa;font-size:.76rem">#<?= $r['schedule_id'] ?></td>
                    <td><?= htmlspecialchars($r['username']) ?></td>
                    <td><?= !empty($r['brand'])?htmlspecialchars($r['brand'].' '.$r['model'].' ('.$r['plate_number'].')'):'<span style="color:#ccc;font-style:italic">—</span>'?></td>
                    <td><?= htmlspecialchars($r['driver_name']) ?></td>
                    <td style="white-space:nowrap;font-size:.78rem"><?= $dateStr ?></td>
                    <td><?= htmlspecialchars($r['destination']??'—') ?></td>
                    <td style="max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['purpose']??'—') ?></td>
                    <td><span class="bp <?=$cls?>"><?= htmlspecialchars($st) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($recent)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-calendar-x" style="font-size:1.5rem;display:block;margin-bottom:.4rem;opacity:.3"></i>No schedules found.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
/* ══ Sidebar toggle (same pattern as requestor dashboard) ══ */
function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}

/* ══ Live clock ══ */
function updateClock() {
    const now = new Date();
    const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    let h = now.getHours();
    const m   = String(now.getMinutes()).padStart(2,'0');
    const s   = String(now.getSeconds()).padStart(2,'0');
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('live-datetime').innerHTML =
        `${days[now.getDay()]}, ${months[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()} &nbsp;·&nbsp; ${h}:${m}:${s} ${ampm} PHT`;
}
updateClock();
setInterval(updateClock, 1000);

/* ══ Charts ══ */
const monthLabels    = <?= json_encode($monthLabels) ?>;
const monthTotal     = <?= json_encode($monthTotal) ?>;
const monthCompleted = <?= json_encode($monthCompleted) ?>;
const statusLabels   = <?= json_encode(array_keys($statusBreakdown)) ?>;
const statusData     = <?= json_encode(array_values($statusBreakdown)) ?>;
const dowLabels      = <?= json_encode($dowLabels) ?>;
const dowData        = <?= json_encode($dowData) ?>;

const statusColors = {
    'pending'  :{bg:'#fff3cd',border:'#c9a227',text:'#856404'},
    'approved' :{bg:'#d1e7dd',border:'#2e7d52',text:'#0f5132'},
    'ontrip'   :{bg:'#fff0d6',border:'#c87000',text:'#7a4f00'},
    'completed':{bg:'#cfe2ff',border:'#2060c4',text:'#0a3678'},
    'rejected' :{bg:'#f8d7da',border:'#b52a35',text:'#842029'},
    'cancelled':{bg:'#e2e3e5',border:'#868e96',text:'#41464b'},
};

new Chart(document.getElementById('monthlyChart'),{
    type:'line',
    data:{labels:monthLabels,datasets:[
        {label:'Total',data:monthTotal,borderColor:'#800000',backgroundColor:'rgba(128,0,0,.08)',borderWidth:2,pointRadius:4,pointBackgroundColor:'#800000',fill:true,tension:.35},
        {label:'Completed',data:monthCompleted,borderColor:'#2060c4',backgroundColor:'rgba(32,96,196,.05)',borderWidth:2,pointRadius:4,pointBackgroundColor:'#2060c4',fill:true,tension:.35,borderDash:[5,4]}
    ]},
    options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false}},
        scales:{
            x:{grid:{display:false},ticks:{font:{size:11},autoSkip:false,maxRotation:0}},
            y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{size:11},stepSize:1,callback:v=>Number.isInteger(v)?v:''}}
        }
    }
});

new Chart(document.getElementById('dowChart'),{
    type:'bar',
    data:{labels:dowLabels,datasets:[{
        data:dowData,
        backgroundColor:dowData.map((_,i)=>i===0||i===6?'rgba(128,0,0,.1)':'rgba(128,0,0,.22)'),
        borderColor:dowData.map((_,i)=>i===0||i===6?'rgba(128,0,0,.3)':'#800000'),
        borderWidth:1.5,borderRadius:5
    }]},
    options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' '+ctx.parsed.y+' trip'+(ctx.parsed.y!==1?'s':'')}}},
        scales:{
            x:{grid:{display:false},ticks:{font:{size:11}}},
            y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{font:{size:11},stepSize:1,callback:v=>Number.isInteger(v)?v:''}}
        }
    }
});

const bgColors     = statusLabels.map(l=>statusColors[l.toLowerCase()]?.bg||'#eee');
const borderColors = statusLabels.map(l=>statusColors[l.toLowerCase()]?.border||'#aaa');
new Chart(document.getElementById('statusChart'),{
    type:'doughnut',
    data:{labels:statusLabels,datasets:[{data:statusData,backgroundColor:bgColors,borderColor:borderColors,borderWidth:1.5,hoverOffset:6}]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'65%',
        plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' '+ctx.label+': '+ctx.parsed}}}
    }
});

const legendEl = document.getElementById('statusLegend');
statusLabels.forEach((lbl,i)=>{
    if(!statusData[i]) return;
    const c = statusColors[lbl.toLowerCase()]||{bg:'#eee',border:'#aaa',text:'#555'};
    legendEl.innerHTML += `<span class="chart-legend-item"><span class="legend-dot" style="background:${c.bg};border:1px solid ${c.border}"></span><span style="color:${c.text};font-weight:600">${lbl}</span><span style="color:#aaa">${statusData[i]}</span></span>`;
});
</script>

<!-- ══ Welcome Toast ══ -->
<?php $showWelcome = !empty($_SESSION['just_logged_in']); unset($_SESSION['just_logged_in']); ?>
<?php if($showWelcome): ?>
<style>
@keyframes slideIn {
    from { opacity:0; transform:translateX(60px) scale(0.95); }
    to   { opacity:1; transform:translateX(0)    scale(1);    }
}
@keyframes slideOut {
    from { opacity:1; transform:translateX(0)    scale(1);    }
    to   { opacity:0; transform:translateX(60px) scale(0.95); }
}
.rank-filter-btn {
    border: 1px solid #e8cece;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: .75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    line-height: 1.5;
}
.rank-filter-btn:hover {
    background: #800000 !important;
    color: #fff !important;
    border-color: #800000 !important;
}
</style>
<div id="welcomeToast" style="
    position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;
    background:#fff;border-radius:14px;
    box-shadow:0 8px 32px rgba(128,0,0,0.18);
    border-left:5px solid #800000;
    padding:1rem 1.25rem 1rem 1rem;
    display:flex;align-items:flex-start;gap:12px;
    min-width:300px;max-width:360px;
    animation:slideIn 0.4s cubic-bezier(0.34,1.56,0.64,1) both;
">
    <div style="width:42px;height:42px;border-radius:50%;flex-shrink:0;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:700;">
        <?= strtoupper(substr($_SESSION['username'],0,1)) ?>
    </div>
    <div style="flex:1;">
        <div style="font-weight:700;font-size:.9rem;color:#2d2d2d;margin-bottom:2px;">
            Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>! 👋
        </div>
        <div style="font-size:.78rem;color:#800000;font-weight:600;margin-bottom:3px;">
            <?= htmlspecialchars($officeName ?: 'Administrator') ?> — Admin
        </div>
        <div style="font-size:.75rem;color:#999;"><?= date('l, F j, Y · g:i A') ?> PHT</div>
    </div>
    <button onclick="dismissToast()" style="background:none;border:none;cursor:pointer;color:#bbb;font-size:1rem;padding:0;line-height:1;align-self:flex-start;">&#x2715;</button>
</div>
<script>
function dismissToast(){
    const t=document.getElementById('welcomeToast');
    if(!t) return;
    t.style.animation='slideOut 0.3s ease forwards';
    setTimeout(()=>t.remove(),300);
}
setTimeout(dismissToast,5000);
/* ══ Rankings Filter ══ */
(function(){
    const buttons   = document.querySelectorAll('.rank-filter-btn');
    const customWrap = document.getElementById('customDateWrap');
    const labelEl   = document.getElementById('rankFilterLabel');
    let currentFilter = 'all';

    function renderRankBlock(containerId, items, nameKey) {
        const el = document.getElementById(containerId);
        if (!items || items.length === 0) {
            el.innerHTML = '<div style="padding:1.5rem;text-align:center;color:#ccc;font-size:.8rem">No data for this period.</div>';
            return;
        }
        const max = Math.max(...items.map(i => parseInt(i.cnt)));
        el.innerHTML = items.map((item, idx) => {
            const name = item[nameKey] || item.destination || item.username || item.vname || item.driver_name || '—';
            const w    = max > 0 ? Math.round((item.cnt / max) * 100) : 0;
            return `<div class="rank-row">
                <div class="rank-num">${idx + 1}</div>
                <div style="flex:1;min-width:0">
                    <div class="rank-label">${name}</div>
                    <div class="rank-bar-wrap mt-1"><div class="rank-bar" style="width:${w}%;transition:width .4s ease"></div></div>
                </div>
                <div class="rank-count">${item.cnt}x</div>
            </div>`;
        }).join('');
    }

    function loadRankings(filter, dateStart, dateEnd) {
        let url = `dashboard.php?rankings_ajax=1&filter=${filter}`;
        if (filter === 'custom' && dateStart && dateEnd) {
            url += `&date_start=${dateStart}&date_end=${dateEnd}`;
        }
        ['rankDest','rankReq','rankVeh','rankDrv'].forEach(id => {
            document.getElementById(id).style.opacity = '0.4';
        });
        fetch(url)
            .then(r => r.json())
            .then(data => {
                renderRankBlock('rankDest', data.destinations, 'destination');
                renderRankBlock('rankReq',  data.requestors,   'username');
                renderRankBlock('rankVeh',  data.vehicles,     'vname');
                renderRankBlock('rankDrv',  data.drivers,      'driver_name');
                ['rankDest','rankReq','rankVeh','rankDrv'].forEach(id => {
                    document.getElementById(id).style.opacity = '1';
                });
            });
    }

    buttons.forEach(btn => {
        btn.addEventListener('click', function() {
            buttons.forEach(b => {
                b.style.background = '#fff';
                b.style.color      = '#800000';
                b.style.borderColor= '#e8cece';
            });
            this.style.background  = '#800000';
            this.style.color       = '#fff';
            this.style.borderColor = '#800000';

            currentFilter = this.dataset.filter;

            if (currentFilter === 'custom') {
                customWrap.style.display = 'flex';
                labelEl.textContent = '';
            } else {
                customWrap.style.display = 'none';
                const labels = {all:'All Time',day:'Today',week:'This Week',month:'This Month',year:'This Year'};
                labelEl.textContent = '— ' + (labels[currentFilter] || '');
                if (currentFilter !== 'all') loadRankings(currentFilter);
                else location.reload(); // reload for "all time" to reset
            }
        });
    });

    document.getElementById('customApplyBtn').addEventListener('click', function() {
        const s = document.getElementById('customStart').value;
        const e = document.getElementById('customEnd').value;
        if (!s || !e) { alert('Please select both start and end dates.'); return; }
        if (s > e)    { alert('Start date must be before end date.'); return; }
        labelEl.textContent = `— ${s} to ${e}`;
        loadRankings('custom', s, e);
    });
})();
</script>
<?php endif; ?>

</body>
</html>