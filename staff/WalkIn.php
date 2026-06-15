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

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$_SESSION['user_id'], $myOfficeId]);
$unreadCount = (int)$unreadStmt->fetchColumn();
/* ── Requestors in same office ── */
$reqStmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.dept_id, d.dept_name
    FROM users u
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE u.role='requestor' AND u.office_id=?
    ORDER BY u.username
");
$reqStmt->execute([$myOfficeId]);
$requestorsList = $reqStmt->fetchAll();

/* ── POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId  = (int)$_POST['user_id'];
    $deptId  = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $ds      = sanitize($_POST['date_start'] ?? '');
    $de      = sanitize($_POST['date_end']   ?? '');
    $ts      = sanitize($_POST['time_start'] ?? '');
    $te      = sanitize($_POST['time_end']   ?? '');
    $dest    = sanitize($_POST['destination'] ?? '');
    $purp    = sanitize($_POST['purpose'] ?? '');

    if (!$userId || !$ds || !$de || !$dest) {
        $_SESSION['flash']['danger'] = "Please fill all required fields.";
    } else {
        try {
            $insertCols = "user_id, office_id, department_id, date_start, date_end, time_start, time_end, destination, purpose, status";
            $insertVals = "?,?,?,?,?,?,?,?,?,'Pending'";
            $insertParams = [$userId, $myOfficeId, $deptId, $ds, $de, $ts, $te, $dest, $purp];
            $checkCols = [];
            foreach ($pdo->query("SHOW COLUMNS FROM schedules") as $c) { $checkCols[] = $c['Field']; }
            if (in_array('booked_by_staff', $checkCols)) {
                $insertCols .= ", booked_by_staff";
                $insertVals .= ",?";
                $insertParams[] = $_SESSION['user_id'];
            }
            $pdo->prepare("INSERT INTO schedules ($insertCols) VALUES ($insertVals)")
                ->execute($insertParams);

            $newScheduleId = $pdo->lastInsertId();

            $reqRow = $pdo->prepare("SELECT username FROM users WHERE user_id=?");
            $reqRow->execute([$userId]);
            $requestorName = $reqRow->fetchColumn() ?: 'Unknown';

            $dsFormatted = date('M d, Y', strtotime($ds));
            $deFormatted = date('M d, Y', strtotime($de));
            $tsFormatted = $ts ? date('g:i A', strtotime($ts)) : '—';
            $teFormatted = $te ? date('g:i A', strtotime($te)) : '—';
            $dateRange   = ($ds === $de) ? $dsFormatted : "$dsFormatted – $deFormatted";

            $notifMessage = "Walk-in booking submitted by staff {$_SESSION['username']} for requestor \"{$requestorName}\" — "
                          . "{$dest} · {$dateRange} · {$tsFormatted}–{$teFormatted} [ref:{$newScheduleId}]";

            $admins = $pdo->prepare("SELECT user_id FROM users WHERE role='admin' AND office_id=?");
            $admins->execute([$myOfficeId]);
            $notifStmt = $pdo->prepare(
                "INSERT INTO notifications (user_id, office_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())"
            );
            foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                $notifStmt->execute([$adminId, $myOfficeId, $notifMessage]);
            }

            $_SESSION['flash']['success'] = "Walk-in booking submitted successfully. Waiting for admin approval.";
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }
    }
    header("Location: WalkIn.php"); exit;
}

/* ── Walk-in Booking History ── */
$existingCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM schedules") as $col) {
    $existingCols[] = $col['Field'];
}
$hasCreatedAt       = in_array('created_at',       $existingCols);
$hasBookedByStaff   = in_array('booked_by_staff',   $existingCols);
$hasCancelReason    = in_array('cancel_reason',     $existingCols);
$hasRejectReason    = in_array('rejection_reason',  $existingCols);
$hasAdminNote       = in_array('admin_note',        $existingCols);
$hasApprovedAt      = in_array('approved_at',       $existingCols);
$hasRejectedAt      = in_array('rejected_at',       $existingCols);
$hasCancelledAt     = in_array('cancelled_at',      $existingCols);
$hasApprovedBy      = in_array('approved_by',       $existingCols);
$hasRejectedBy      = in_array('rejected_by',       $existingCols);
$hasCancelledBy     = in_array('cancelled_by',      $existingCols);

$selectCreatedAt  = $hasCreatedAt     ? 's.created_at,'       : 'NULL AS created_at,';
$selectCancelR    = $hasCancelReason  ? 's.cancel_reason,'    : 'NULL AS cancel_reason,';
$selectRejectR    = $hasRejectReason  ? 's.rejection_reason AS reject_reason,' : 'NULL AS reject_reason,';
$selectAdminNote  = $hasAdminNote     ? 's.admin_note,'       : 'NULL AS admin_note,';
$selectApprovedAt = $hasApprovedAt    ? 's.approved_at,'      : 'NULL AS approved_at,';
$selectRejectedAt = $hasRejectedAt    ? 's.rejected_at,'      : 'NULL AS rejected_at,';
$selectCancelledAt= $hasCancelledAt   ? 's.cancelled_at,'     : 'NULL AS cancelled_at,';
$selectApprovedBy = $hasApprovedBy    ? 's.approved_by,'      : 'NULL AS approved_by,';
$selectRejectedBy = $hasRejectedBy    ? 's.rejected_by,'      : 'NULL AS rejected_by,';
$selectCancelledBy= $hasCancelledBy   ? 's.cancelled_by,'     : 'NULL AS cancelled_by,';

$whereStaff = $hasBookedByStaff ? 'AND s.booked_by_staff = ?' : '';
$orderBy    = $hasCreatedAt     ? 'ORDER BY s.created_at DESC' : 'ORDER BY s.schedule_id DESC';

$driverCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM drivers") as $col) { $driverCols[] = $col['Field']; }
if (in_array('first_name', $driverCols) && in_array('last_name', $driverCols)) {
    $selectDriver = "CONCAT(dr.first_name,' ',dr.last_name) AS driver_name";
} elseif (in_array('name', $driverCols)) {
    $selectDriver = "dr.name AS driver_name";
} elseif (in_array('full_name', $driverCols)) {
    $selectDriver = "dr.full_name AS driver_name";
} elseif (in_array('driver_name', $driverCols)) {
    $selectDriver = "dr.driver_name AS driver_name";
} else {
    $selectDriver = "NULL AS driver_name";
}

$vehicleCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM vehicles") as $col) { $vehicleCols[] = $col['Field']; }
$vehicleModelCol = in_array('model', $vehicleCols) ? 'v.model' : (in_array('vehicle_model', $vehicleCols) ? 'v.vehicle_model' : 'NULL');

$histSql = "
    SELECT s.schedule_id, s.date_start, s.date_end, s.time_start, s.time_end,
           s.destination, s.purpose, s.status, $selectCreatedAt
           $selectCancelR $selectRejectR $selectAdminNote
           $selectApprovedAt $selectRejectedAt $selectCancelledAt
           $selectApprovedBy $selectRejectedBy $selectCancelledBy
           u.username AS requestor_name,
           d.dept_name,
           v.plate_number, $vehicleModelCol AS vehicle_model,
           $selectDriver
    FROM schedules s
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN departments d ON s.department_id = d.dept_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    LEFT JOIN drivers dr ON s.driver_id = dr.driver_id
    WHERE s.office_id = ? $whereStaff
    $orderBy
    LIMIT 50
";
$histStmt = $pdo->prepare($histSql);
$params = $hasBookedByStaff ? [$myOfficeId, $_SESSION['user_id']] : [$myOfficeId];
$histStmt->execute($params);
$history = $histStmt->fetchAll();

$requestorsJson = json_encode(array_column($requestorsList, null, 'user_id'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Walk-in Booking – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f0ebeb;font-family:'Segoe UI',sans-serif;min-height:100vh}

/* ── Hamburger + Overlay ── */
.hamburger-btn{display:none;background:none;border:none;cursor:pointer;padding:4px;color:#800000;font-size:1.2rem;line-height:1;margin-right:.5rem}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:199}
.sidebar-overlay.open{display:block}

/* ── Sidebar ── */
.sidebar{min-height:100vh;background:linear-gradient(180deg,#800000 0%,#6b0000 100%);width:240px;position:fixed;top:0;left:0;z-index:200;display:flex;flex-direction:column}
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
.topbar-user{display:flex;align-items:center;gap:8px;font-size:.85rem;color:#666}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}

/* ── Main layout ── */
.main-content{margin-left:240px;padding:1.4rem 1.5rem;min-height:calc(100vh - 53px);display:flex;flex-direction:column}
.flash-area{margin-bottom:1rem;flex-shrink:0}
.page-grid{display:grid;grid-template-columns:360px 1fr;gap:1.25rem;align-items:stretch;flex:1}

/* ── Form card ── */
.form-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(128,0,0,.08);overflow:hidden;display:flex;flex-direction:column}
.form-header{padding:1.1rem 1.4rem;background:linear-gradient(135deg,#800000,#6b0000);color:#fff}
.form-header h5{margin:0;font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.45rem}
.form-header p{margin:3px 0 0;font-size:.78rem;opacity:.75}
.form-body{padding:1.4rem;flex:1}
.section-divider{font-size:.68rem;font-weight:700;color:#800000;text-transform:uppercase;letter-spacing:.1em;border-bottom:2px solid #f0e5e5;padding-bottom:.45rem;margin-bottom:.9rem;margin-top:1.1rem;display:flex;align-items:center;gap:.35rem}
.requestor-card{background:#fdf5f5;border:1.5px solid #e8cece;border-radius:10px;padding:.7rem .9rem;font-size:.84rem;display:none;margin-bottom:.75rem}
.requestor-card.show{display:flex;align-items:center;gap:10px}
.req-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#800000,#b71c1c);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.88rem;flex-shrink:0;box-shadow:0 2px 6px rgba(128,0,0,.25)}
.form-control,.form-select{font-size:.85rem;border-color:#e0d0d0;border-radius:8px}
.form-control:focus,.form-select:focus{border-color:#800000;box-shadow:0 0 0 .2rem rgba(128,0,0,.12)}
.form-label{font-size:.82rem;font-weight:600;color:#444;margin-bottom:.3rem}
.btn-maroon{background:linear-gradient(135deg,#800000,#9b0000);color:#fff;border:none;border-radius:8px;font-size:.84rem;padding:.45rem 1.25rem;font-weight:600;transition:all .15s;box-shadow:0 2px 8px rgba(128,0,0,.25)}
.btn-maroon:hover{background:linear-gradient(135deg,#6b0000,#800000);color:#fff;box-shadow:0 3px 12px rgba(128,0,0,.35);transform:translateY(-1px)}
.btn-outline-secondary{border-radius:8px;font-size:.84rem;padding:.45rem 1rem}

/* ── History card ── */
.history-card{background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(128,0,0,.08);overflow:hidden;min-width:0;display:flex;flex-direction:column}
.history-header{padding:.9rem 1.25rem;border-bottom:2px solid #f5eded;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.history-header-left{display:flex;align-items:center;gap:.5rem}
.history-header-left h5{margin:0;font-weight:700;font-size:.9rem;color:#800000}
.badge-count{background:#800000;color:#fff;border-radius:20px;padding:.18rem .6rem;font-size:.72rem;font-weight:700}
.search-box{position:relative;min-width:180px}
.search-box input{padding:.35rem .75rem .35rem 2rem;font-size:.8rem;border-color:#e0d0d0;border-radius:8px;height:auto}
.search-box i{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:#bbb;font-size:.8rem;pointer-events:none}
.history-table-wrap{overflow-x:auto;overflow-y:auto;flex:1}
.history-table{width:100%;border-collapse:collapse;font-size:.81rem}
.history-table thead tr{background:#fdf5f5}
.history-table thead th{padding:.55rem .9rem;font-weight:700;color:#800000;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;white-space:nowrap;border-bottom:2px solid #f0e5e5;position:sticky;top:0;background:#fdf5f5;z-index:1}
.history-table tbody tr{border-bottom:1px solid #faf0f0;transition:background .12s}
.history-table tbody tr:hover{background:#fdf8f8}
.history-table tbody td{padding:.6rem .9rem;vertical-align:middle;color:#444}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap}
.status-pending{background:#fff8e1;color:#e65100;border:1px solid #ffe0b2}
.status-approved{background:#e8f5e9;color:#1b5e20;border:1px solid #c8e6c9}
.status-rejected{background:#fce4ec;color:#880e4f;border:1px solid #f8bbd0}
.status-completed{background:#e3f2fd;color:#0d47a1;border:1px solid #bbdefb}
.status-cancelled{background:#f5f5f5;color:#616161;border:1px solid #e0e0e0}
.req-chip{display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;border:1px solid #e8cece;border-radius:20px;padding:.12rem .5rem;font-size:.76rem;font-weight:600;color:#6b0000;white-space:nowrap}
.req-chip-avatar{width:17px;height:17px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;flex-shrink:0}
.vehicle-info{font-size:.75rem;color:#555;display:flex;align-items:center;gap:.3rem}
.vehicle-info i{color:#800000;font-size:.7rem}
.not-assigned{font-size:.74rem;color:#bbb;display:flex;align-items:center;gap:.3rem}
.dest-cell{max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.date-cell{white-space:nowrap;line-height:1.4}
.time-sub{font-size:.72rem;color:#999;display:flex;align-items:center;gap:.25rem;margin-top:2px}
.row-num{color:#ccc;font-size:.72rem;font-weight:600;width:28px}
.empty-history{padding:3rem 2rem;text-align:center;color:#bbb}
.empty-history i{font-size:2.8rem;color:#e8cece;display:block;margin-bottom:.75rem}
.empty-history p{font-size:.84rem;margin:0}

/* ── Flash alerts ── */
.alert{border-radius:10px;font-size:.85rem}

/* ── Stat pills ── */
.stat-pill{font-size:.7rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;white-space:nowrap}
.stat-pending{background:#fff8e1;color:#e65100;border:1px solid #ffe0b2}
.stat-approved{background:#e8f5e9;color:#1b5e20;border:1px solid #c8e6c9}
.stat-rejected{background:#fce4ec;color:#880e4f;border:1px solid #f8bbd0}
.stat-completed{background:#e3f2fd;color:#0d47a1;border:1px solid #bbdefb}

/* ── Details button ── */
.btn-details{background:transparent;border:1.5px solid #c9a5a5;color:#800000;border-radius:7px;font-size:.73rem;padding:.25rem .65rem;font-weight:600;transition:all .15s;display:inline-flex;align-items:center;gap:.3rem;cursor:pointer;white-space:nowrap}
.btn-details:hover{background:#800000;border-color:#800000;color:#fff;box-shadow:0 2px 8px rgba(128,0,0,.25)}
.btn-details i{font-size:.75rem}

/* ── Booking Detail Modal ── */
.modal-booking .modal-content{border:none;border-radius:16px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.modal-booking .modal-header{background:linear-gradient(135deg,#800000,#6b0000);color:#fff;padding:1rem 1.4rem;border:none}
.modal-booking .modal-header .modal-title{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem}
.modal-booking .modal-header .btn-close{filter:invert(1);opacity:.75}
.modal-booking .modal-body{padding:1.4rem;background:#fff}
.modal-booking .modal-footer{background:#fdf5f5;border-top:1px solid #f0e5e5;padding:.75rem 1.4rem}
.detail-section{margin-bottom:1.25rem}
.detail-section-title{font-size:.67rem;font-weight:700;color:#800000;text-transform:uppercase;letter-spacing:.1em;border-bottom:2px solid #f0e5e5;padding-bottom:.35rem;margin-bottom:.8rem;display:flex;align-items:center;gap:.35rem}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem .75rem}
.detail-item{font-size:.82rem}
.detail-label{font-size:.68rem;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.15rem}
.detail-value{font-weight:600;color:#222}
.detail-value.muted{color:#999;font-weight:400;font-style:italic}
.detail-value-full{font-size:.84rem;font-weight:600;color:#222}
.modal-status-banner{border-radius:10px;padding:.7rem 1rem;display:flex;align-items:center;gap:.6rem;font-size:.84rem;font-weight:700;margin-bottom:1.1rem}
.msb-pending  {background:#fff8e1;color:#e65100;border:1.5px solid #ffe0b2}
.msb-approved {background:#e8f5e9;color:#1b5e20;border:1.5px solid #c8e6c9}
.msb-rejected {background:#fce4ec;color:#880e4f;border:1.5px solid #f8bbd0}
.msb-completed{background:#e3f2fd;color:#0d47a1;border:1.5px solid #bbdefb}
.msb-cancelled{background:#f5f5f5;color:#616161;border:1.5px solid #e0e0e0}
.msb-icon{font-size:1.3rem;flex-shrink:0}
.msb-text{flex:1}
.msb-text small{display:block;font-size:.72rem;font-weight:400;opacity:.75;margin-top:2px}
.reason-box{border-radius:9px;padding:.75rem 1rem;font-size:.83rem;margin-top:.5rem;line-height:1.5}
.reason-box.cancelled{background:#f9f9f9;border:1.5px solid #e0e0e0;color:#555}
.reason-box.rejected {background:#fff0f4;border:1.5px solid #f8bbd0;color:#880e4f}
.reason-box.note     {background:#fffde7;border:1.5px solid #fff176;color:#5d4037}
.reason-box-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.35rem;display:flex;align-items:center;gap:.3rem;opacity:.7}
.no-reason{font-size:.8rem;color:#ccc;font-style:italic}
.booking-id-pill{display:inline-flex;align-items:center;gap:.35rem;background:#fdf5f5;border:1px solid #e8cece;border-radius:20px;padding:.2rem .65rem;font-size:.73rem;font-weight:700;color:#800000}

/* ── Mobile ── */
@media (max-width: 768px) {
    .hamburger-btn { display: flex; align-items: center; }
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        position: fixed !important;
        top: 0; left: 0;
        height: 100vh;
        overflow-y: auto;
        z-index: 200;
    }
    .sidebar.open { transform: translateX(0) !important; }
    .topbar { margin-left: 0 !important; }
    .main-content { margin-left: 0 !important; padding: 1rem; }
    .page-grid { grid-template-columns: 1fr !important; }
    .topbar-title { font-size: .82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .topbar-title i { display: none; }
    .topbar-user > div > div:last-child { display: none; }
    .section-header { flex-wrap: wrap; gap: .5rem; }
    .history-header { flex-direction: column; align-items: flex-start; }
    .search-box { min-width: 100%; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .modal-dialog { margin: auto 0 0; max-width: 100%; }
    .modal-content { border-radius: 16px 16px 0 0 !important; }
    .modal-booking .modal-content { border-radius: 16px 16px 0 0 !important; }
    .detail-grid { grid-template-columns: 1fr !important; }
    .history-table thead th:nth-child(5),
    .history-table tbody td:nth-child(5),
    .history-table thead th:nth-child(6),
    .history-table tbody td:nth-child(6),
    .history-table thead th:nth-child(8),
    .history-table tbody td:nth-child(8) { display: none; }
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
    <a class="nav-link active" href="WalkIn.php"><i class="bi bi-calendar-plus"></i> Walk-in Booking</a>
    <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i> View Schedules</a>
    <a class="nav-link" href="CheckAvailability.php"><i class="bi bi-search"></i> Check Availability</a>
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
  <div class="topbar-title"><i class="bi bi-calendar-plus me-2"></i>Walk-in Booking</div>
  <div class="topbar-user">
    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
    <div>
      <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
      <div style="font-size:.72rem;color:#800000">Staff — <?= htmlspecialchars($me['office_name'] ?? '—') ?></div>
    </div>
  </div>
</div>

<div class="main-content">
<div class="flash-area">
<?php
$icons=['success'=>'check-circle','danger'=>'x-circle','warning'=>'exclamation-triangle'];
$borders=['success'=>'#0f5132','danger'=>'#842029','warning'=>'#856404'];
foreach(['success','danger','warning'] as $t):
    if(!empty($_SESSION['flash'][$t])):
?>
<div class="alert alert-<?=$t?> alert-dismissible fade show mb-3" style="font-size:.87rem;border-radius:10px;border-left:4px solid <?=$borders[$t]?>">
    <i class="bi bi-<?=$icons[$t]?> me-2"></i><?=htmlspecialchars($_SESSION['flash'][$t])?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash'][$t]); endif; endforeach; ?>
</div>

<div class="page-grid">

<!-- ══ BOOKING FORM ══ -->
<div class="form-card">
  <div class="form-header">
    <h5><i class="bi bi-calendar-plus me-2"></i>New Walk-in Trip Request</h5>
    <p>Book a vehicle trip on behalf of a requestor. Admin will assign vehicle &amp; driver.</p>
  </div>
  <div class="form-body">
    <form method="POST" action="WalkIn.php" id="walkInForm" novalidate>

      <div class="section-divider"><i class="bi bi-person me-1"></i>Requestor Information</div>
      <div class="row g-2 mb-2">
        <div class="col-12">
          <label class="form-label">Select Requestor <span class="text-danger">*</span></label>
          <select name="user_id" id="sel_requestor" class="form-select" required>
            <option value="">— Select Requestor —</option>
            <?php foreach($requestorsList as $r):?>
            <option value="<?=$r['user_id']?>" data-dept-id="<?=(int)($r['dept_id']??0)?>" data-dept-name="<?=htmlspecialchars($r['dept_name']??'—',ENT_QUOTES)?>">
              <?=htmlspecialchars($r['username'])?>
            </option>
            <?php endforeach;?>
          </select>
          <div class="invalid-feedback">Please select a requestor.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Department</label>
          <input type="text" id="dept_display" class="form-control bg-light" readonly placeholder="Auto-filled from requestor">
          <input type="hidden" name="department_id" id="dept_id_hidden">
        </div>
      </div>

      <div class="requestor-card mb-3" id="req_card">
        <div class="req-avatar" id="req_avatar">?</div>
        <div>
          <div style="font-weight:700;font-size:.88rem" id="req_name_disp">—</div>
          <div style="font-size:.78rem;color:#888"><i class="bi bi-diagram-3 me-1"></i><span id="req_dept_disp">—</span> &nbsp;|&nbsp; <i class="bi bi-building me-1"></i><?= htmlspecialchars($me['office_name']??'') ?></div>
        </div>
      </div>

      <div class="section-divider"><i class="bi bi-map me-1"></i>Trip Details</div>
      <div class="row g-2">
        <div class="col-6">
          <label class="form-label">Date Start <span class="text-danger">*</span></label>
          <input type="date" name="date_start" id="wi_ds" class="form-control" required min="<?=date('Y-m-d')?>">
          <div class="invalid-feedback">Required.</div>
        </div>
        <div class="col-6">
          <label class="form-label">Date End <span class="text-danger">*</span></label>
          <input type="date" name="date_end" id="wi_de" class="form-control" required min="<?=date('Y-m-d')?>">
          <div class="invalid-feedback">On or after start.</div>
        </div>
        <div class="col-6">
          <label class="form-label">Time Start <span class="text-danger">*</span></label>
          <input type="time" name="time_start" id="wi_ts" class="form-control" required>
          <div class="invalid-feedback">Required.</div>
        </div>
        <div class="col-6">
          <label class="form-label">Time End <span class="text-danger">*</span></label>
          <input type="time" name="time_end" id="wi_te" class="form-control" required>
          <div class="invalid-feedback">After start time.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Destination <span class="text-danger">*</span></label>
          <input type="text" name="destination" class="form-control" required placeholder="e.g. City Hall, Tuguegarao">
          <div class="invalid-feedback">Required.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Purpose</label>
          <input type="text" name="purpose" class="form-control" placeholder="e.g. Official Business">
        </div>
      </div>

      <div id="time_alert" class="alert alert-danger py-2 px-3 mt-3 d-none" style="font-size:.83rem">
        <i class="bi bi-exclamation-triangle me-1"></i>End time must be after start time on the same day.
      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-maroon px-4">
          <i class="bi bi-send me-1"></i>Submit Walk-in Booking
        </button>
        <button type="reset" class="btn btn-outline-secondary" onclick="resetForm()">
          <i class="bi bi-x-circle me-1"></i>Clear
        </button>
      </div>

    </form>
  </div>
</div>

<!-- ══ BOOKING HISTORY ══ -->
<div class="history-card">
  <div class="history-header">
    <div class="history-header-left">
      <i class="bi bi-clock-history" style="color:#800000;font-size:1rem"></i>
      <h5>Walk-in Booking History</h5>
      <span class="badge-count"><?= count($history) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <?php
        $counts = ['Pending'=>0,'Approved'=>0,'Rejected'=>0,'Completed'=>0];
        foreach($history as $hx) {
          $s = ucfirst(strtolower($hx['status']));
          if(isset($counts[$s])) $counts[$s]++;
        }
      ?>
      <?php foreach($counts as $label=>$cnt): if($cnt>0): ?>
      <span class="stat-pill stat-<?=strtolower($label)?>"><?=$cnt?> <?=$label?></span>
      <?php endif; endforeach; ?>
      <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="histSearch" class="form-control" placeholder="Search…">
      </div>
    </div>
  </div>

  <?php if (empty($history)): ?>
  <div class="empty-history">
    <i class="bi bi-calendar-x"></i>
    <p style="font-weight:600;color:#c0a0a0;margin-bottom:.25rem">No bookings yet</p>
    <p style="font-size:.78rem">Walk-in trips you submit will appear here.</p>
  </div>
  <?php else: ?>
  <div class="history-table-wrap">
    <table class="history-table" id="historyTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Requestor</th>
          <th>Destination</th>
          <th>Date &amp; Time</th>
          <th>Purpose</th>
          <th>Vehicle / Driver</th>
          <th>Status</th>
          <th>Booked On</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($history as $i => $h):
          $statusClass = match(strtolower($h['status'])) {
            'approved'  => 'status-approved',
            'rejected'  => 'status-rejected',
            'completed' => 'status-completed',
            'cancelled' => 'status-cancelled',
            default     => 'status-pending',
          };
          $statusIcon = match(strtolower($h['status'])) {
            'approved'  => 'check-circle',
            'rejected'  => 'x-circle',
            'completed' => 'flag-fill',
            'cancelled' => 'slash-circle',
            default     => 'hourglass-split',
          };
          $ds = date('M d, Y', strtotime($h['date_start']));
          $de = date('M d, Y', strtotime($h['date_end']));
          $ts = $h['time_start'] ? date('h:i A', strtotime($h['time_start'])) : '—';
          $te = $h['time_end']   ? date('h:i A', strtotime($h['time_end']))   : '—';
          $sameDay = ($h['date_start'] === $h['date_end']);

          $modalData = [
            'id'           => $h['schedule_id'],
            'requestor'    => $h['requestor_name'],
            'dept'         => $h['dept_name'] ?? '',
            'dest'         => $h['destination'],
            'purpose'      => $h['purpose'] ?? '',
            'date_start'   => $ds,
            'date_end'     => $de,
            'time_start'   => $ts,
            'time_end'     => $te,
            'status'       => $h['status'],
            'vehicle'      => trim(($h['plate_number']??'').' '.($h['vehicle_model']??'')),
            'driver'       => $h['driver_name'] ?? '',
            'created_at'   => !empty($h['created_at']) ? date('M d, Y g:i A', strtotime($h['created_at'])) : '—',
            'booked_by'    => $_SESSION['username'],
            'cancel_reason'=> $h['cancel_reason'] ?? '',
            'reject_reason'=> $h['reject_reason'] ?? '',
            'admin_note'   => $h['admin_note'] ?? '',
            'approved_at'  => !empty($h['approved_at'])  ? date('M d, Y g:i A', strtotime($h['approved_at']))  : '',
            'rejected_at'  => !empty($h['rejected_at'])  ? date('M d, Y g:i A', strtotime($h['rejected_at']))  : '',
            'cancelled_at' => !empty($h['cancelled_at']) ? date('M d, Y g:i A', strtotime($h['cancelled_at'])) : '',
            'approved_by'  => $h['approved_by']  ?? '',
            'rejected_by'  => $h['rejected_by']  ?? '',
            'cancelled_by' => $h['cancelled_by'] ?? '',
          ];
        ?>
        <tr>
          <td class="row-num"><?= $i+1 ?></td>
          <td>
            <div class="req-chip">
              <div class="req-chip-avatar"><?= strtoupper(substr($h['requestor_name'],0,1)) ?></div>
              <?= htmlspecialchars($h['requestor_name']) ?>
            </div>
            <?php if($h['dept_name']): ?>
            <div style="font-size:.72rem;color:#aaa;margin-top:3px;padding-left:2px">
              <i class="bi bi-diagram-3" style="font-size:.68rem"></i> <?= htmlspecialchars($h['dept_name']) ?>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <div class="dest-cell" title="<?= htmlspecialchars($h['destination']) ?>">
              <i class="bi bi-geo-alt-fill" style="color:#800000;font-size:.75rem"></i>
              <?= htmlspecialchars($h['destination']) ?>
            </div>
          </td>
          <td>
            <div class="date-cell">
              <span><i class="bi bi-calendar3" style="color:#800000;font-size:.72rem"></i> <?= $ds ?></span>
              <?php if(!$sameDay): ?><div style="font-size:.72rem;color:#aaa;padding-left:1px">→ <?= $de ?></div><?php endif; ?>
              <div class="time-sub"><i class="bi bi-clock"></i><?= $ts ?> – <?= $te ?></div>
            </div>
          </td>
          <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#666;font-size:.8rem">
            <?= $h['purpose'] ? htmlspecialchars($h['purpose']) : '<span style="color:#ccc">—</span>' ?>
          </td>
          <td>
            <?php if($h['plate_number'] || $h['vehicle_model']): ?>
              <div class="vehicle-info"><i class="bi bi-truck-front-fill"></i><?= htmlspecialchars(trim(($h['plate_number']??'').' '.($h['vehicle_model']??''))) ?></div>
            <?php else: ?>
              <span class="not-assigned"><i class="bi bi-hourglass-split"></i>Not assigned</span>
            <?php endif; ?>
            <?php if(!empty($h['driver_name']) && trim($h['driver_name'])): ?>
              <div class="vehicle-info" style="margin-top:3px"><i class="bi bi-person-badge-fill"></i><?= htmlspecialchars($h['driver_name']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-badge <?= $statusClass ?>">
              <i class="bi bi-<?= $statusIcon ?>"></i>
              <?= htmlspecialchars($h['status']) ?>
            </span>
          </td>
          <td>
            <?php if(!empty($h['created_at'])): ?>
              <div style="font-size:.78rem;font-weight:600;color:#444;white-space:nowrap">
                <i class="bi bi-calendar-check" style="color:#800000;font-size:.72rem"></i>
                <?= date('M d, Y', strtotime($h['created_at'])) ?>
              </div>
              <div style="font-size:.72rem;color:#888;margin-top:2px;white-space:nowrap">
                <i class="bi bi-clock" style="font-size:.68rem"></i>
                <?= date('g:i A', strtotime($h['created_at'])) ?>
              </div>
            <?php else: ?>
              <div style="font-size:.75rem;color:#ccc;font-style:italic">Not recorded</div>
            <?php endif; ?>
          </td>
          <td>
            <button type="button" class="btn-details"
              onclick="showBookingDetails(<?= htmlspecialchars(json_encode($modalData), ENT_QUOTES) ?>)">
              <i class="bi bi-eye"></i> Details
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

</div><!-- /page-grid -->
</div><!-- /main-content -->

<!-- ══ BOOKING DETAIL MODAL ══ -->
<div class="modal fade modal-booking" id="bookingDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title">
          <i class="bi bi-calendar2-check me-2"></i>
          Booking Details
          <span class="booking-id-pill ms-2" id="mdl_id_pill">#—</span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="modal-status-banner" id="mdl_status_banner">
          <i class="msb-icon bi" id="mdl_status_icon"></i>
          <div class="msb-text">
            <span id="mdl_status_text"></span>
            <small id="mdl_status_sub"></small>
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-person-fill"></i>Requestor Information</div>
          <div class="detail-grid">
            <div class="detail-item"><div class="detail-label">Name</div><div class="detail-value" id="mdl_requestor">—</div></div>
            <div class="detail-item"><div class="detail-label">Department</div><div class="detail-value" id="mdl_dept">—</div></div>
            <div class="detail-item"><div class="detail-label">Booked by</div><div class="detail-value" id="mdl_booked_by">—</div></div>
            <div class="detail-item"><div class="detail-label">Submitted on</div><div class="detail-value" id="mdl_created_at">—</div></div>
          </div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-map-fill"></i>Trip Details</div>
          <div class="detail-grid">
            <div class="detail-item"><div class="detail-label">Date Start</div><div class="detail-value" id="mdl_date_start">—</div></div>
            <div class="detail-item"><div class="detail-label">Date End</div><div class="detail-value" id="mdl_date_end">—</div></div>
            <div class="detail-item"><div class="detail-label">Time Start</div><div class="detail-value" id="mdl_time_start">—</div></div>
            <div class="detail-item"><div class="detail-label">Time End</div><div class="detail-value" id="mdl_time_end">—</div></div>
          </div>
          <div class="mt-2"><div class="detail-label">Destination</div><div class="detail-value-full" id="mdl_dest">—</div></div>
          <div class="mt-2"><div class="detail-label">Purpose</div><div class="detail-value-full" id="mdl_purpose">—</div></div>
        </div>
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-truck-front-fill"></i>Vehicle &amp; Driver Assignment</div>
          <div class="detail-grid">
            <div class="detail-item"><div class="detail-label">Vehicle</div><div class="detail-value" id="mdl_vehicle">—</div></div>
            <div class="detail-item"><div class="detail-label">Driver</div><div class="detail-value" id="mdl_driver">—</div></div>
          </div>
        </div>
        <div class="detail-section" id="mdl_reason_section" style="display:none">
          <div class="detail-section-title"><i class="bi bi-chat-square-text-fill"></i>Remarks</div>
          <div id="mdl_reject_wrap" style="display:none">
            <div class="reason-box rejected">
              <div class="reason-box-label"><i class="bi bi-x-circle-fill"></i>Reason for Rejection</div>
              <div id="mdl_reject_reason"></div>
            </div>
            <div id="mdl_rejected_meta" style="font-size:.72rem;color:#aaa;margin-top:.4rem;padding-left:.25rem"></div>
          </div>
          <div id="mdl_cancel_wrap" style="display:none;margin-top:.5rem">
            <div class="reason-box cancelled">
              <div class="reason-box-label"><i class="bi bi-slash-circle-fill"></i>Reason for Cancellation</div>
              <div id="mdl_cancel_reason"></div>
            </div>
            <div id="mdl_cancelled_meta" style="font-size:.72rem;color:#aaa;margin-top:.4rem;padding-left:.25rem"></div>
          </div>
          <div id="mdl_note_wrap" style="display:none;margin-top:.5rem">
            <div class="reason-box note">
              <div class="reason-box-label"><i class="bi bi-sticky-fill"></i>Admin Note</div>
              <div id="mdl_admin_note"></div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
          <i class="bi bi-x me-1"></i>Close
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Sidebar toggle ── */
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

const requestorsData = <?= $requestorsJson ?>;

/* ── Requestor select auto-fill ── */
document.getElementById('sel_requestor').addEventListener('change', function(){
    const opt      = this.options[this.selectedIndex];
    const deptId   = opt.getAttribute('data-dept-id') || '';
    const deptName = opt.getAttribute('data-dept-name') || '—';
    const username = opt.text;
    document.getElementById('dept_id_hidden').value = deptId;
    document.getElementById('dept_display').value   = deptId ? deptName : '—';
    const card = document.getElementById('req_card');
    if (this.value) {
        document.getElementById('req_avatar').textContent    = username.charAt(0).toUpperCase();
        document.getElementById('req_name_disp').textContent = username;
        document.getElementById('req_dept_disp').textContent = deptId ? deptName : 'No Department';
        card.classList.add('show');
    } else {
        card.classList.remove('show');
    }
});

/* ── Date / Time logic ── */
document.getElementById('wi_ds').addEventListener('change', function(){
    if (!document.getElementById('wi_de').value || document.getElementById('wi_de').value < this.value) {
        document.getElementById('wi_de').value = this.value;
    }
    document.getElementById('wi_de').min = this.value;
    validateTimes();
});
document.getElementById('wi_de').addEventListener('change', validateTimes);
document.getElementById('wi_ts').addEventListener('change', function(){
    if (this.value && !document.getElementById('wi_te').value) {
        const [h,m] = this.value.split(':').map(Number);
        document.getElementById('wi_te').value = String((h+1)%24).padStart(2,'0')+':'+String(m).padStart(2,'0');
    }
    validateTimes();
});
document.getElementById('wi_te').addEventListener('change', validateTimes);

function validateTimes() {
    const ds=document.getElementById('wi_ds').value, de=document.getElementById('wi_de').value;
    const ts=document.getElementById('wi_ts').value, te=document.getElementById('wi_te').value;
    const al=document.getElementById('time_alert');
    if (ds===de && ts && te && te<=ts) { al.classList.remove('d-none'); return false; }
    al.classList.add('d-none'); return true;
}

document.getElementById('walkInForm').addEventListener('submit', function(e){
    if (!this.checkValidity() || !validateTimes()) {
        e.preventDefault(); this.classList.add('was-validated');
    }
});

function resetForm() {
    document.getElementById('walkInForm').classList.remove('was-validated');
    document.getElementById('dept_display').value = '';
    document.getElementById('dept_id_hidden').value = '';
    document.getElementById('req_card').classList.remove('show');
    document.getElementById('time_alert').classList.add('d-none');
}

/* ── History search ── */
document.getElementById('histSearch').addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#historyTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

/* ── Booking Detail Modal ── */
function showBookingDetails(d) {
    const status = (d.status || '').toLowerCase();

    document.getElementById('mdl_id_pill').textContent = '#' + d.id;

    const bannerEl = document.getElementById('mdl_status_banner');
    const iconEl   = document.getElementById('mdl_status_icon');
    const textEl   = document.getElementById('mdl_status_text');
    const subEl    = document.getElementById('mdl_status_sub');

    const statusMap = {
        pending:   { cls:'msb-pending',   icon:'bi-hourglass-split',   label:'Pending Approval',  sub:'Waiting for admin to review this booking.' },
        approved:  { cls:'msb-approved',  icon:'bi-check-circle-fill', label:'Approved',           sub: d.approved_at  ? 'Approved on '  + d.approved_at  + (d.approved_by  ? ' by ' + d.approved_by  : '') : '' },
        rejected:  { cls:'msb-rejected',  icon:'bi-x-circle-fill',     label:'Rejected',           sub: d.rejected_at  ? 'Rejected on '  + d.rejected_at  + (d.rejected_by  ? ' by ' + d.rejected_by  : '') : '' },
        completed: { cls:'msb-completed', icon:'bi-flag-fill',          label:'Completed',          sub:'This trip has been completed.' },
        cancelled: { cls:'msb-cancelled', icon:'bi-slash-circle-fill',  label:'Cancelled',          sub: d.cancelled_at ? 'Cancelled on ' + d.cancelled_at + (d.cancelled_by ? ' by ' + d.cancelled_by : '') : '' },
    };
    const sm = statusMap[status] || { cls:'msb-pending', icon:'bi-question-circle', label: d.status, sub:'' };

    bannerEl.className = 'modal-status-banner ' + sm.cls;
    iconEl.className   = 'msb-icon bi ' + sm.icon;
    textEl.textContent = sm.label;
    subEl.textContent  = sm.sub;

    document.getElementById('mdl_requestor').textContent  = d.requestor || '—';
    document.getElementById('mdl_dept').textContent       = d.dept      || '—';
    document.getElementById('mdl_booked_by').textContent  = d.booked_by || '—';
    document.getElementById('mdl_created_at').textContent = d.created_at || '—';
    document.getElementById('mdl_date_start').textContent = d.date_start || '—';
    document.getElementById('mdl_date_end').textContent   = d.date_end   || '—';
    document.getElementById('mdl_time_start').textContent = d.time_start || '—';
    document.getElementById('mdl_time_end').textContent   = d.time_end   || '—';

    document.getElementById('mdl_dest').textContent = d.dest || '—';

    const purpEl = document.getElementById('mdl_purpose');
    purpEl.textContent = d.purpose || 'Not specified';
    purpEl.className   = d.purpose ? 'detail-value-full' : 'detail-value-full muted';

    const vehEl = document.getElementById('mdl_vehicle');
    if (d.vehicle && d.vehicle.trim()) {
        vehEl.innerHTML = '<i class="bi bi-truck-front-fill" style="color:#800000;font-size:.8rem;margin-right:.3rem"></i>' + escHtml(d.vehicle.trim());
        vehEl.className = 'detail-value';
    } else {
        vehEl.textContent = 'Not yet assigned';
        vehEl.className = 'detail-value muted';
    }

    const drvEl = document.getElementById('mdl_driver');
    if (d.driver && d.driver.trim()) {
        drvEl.innerHTML = '<i class="bi bi-person-badge-fill" style="color:#800000;font-size:.8rem;margin-right:.3rem"></i>' + escHtml(d.driver.trim());
        drvEl.className = 'detail-value';
    } else {
        drvEl.textContent = 'Not yet assigned';
        drvEl.className = 'detail-value muted';
    }

    const reasonSection = document.getElementById('mdl_reason_section');
    const rejectWrap    = document.getElementById('mdl_reject_wrap');
    const cancelWrap    = document.getElementById('mdl_cancel_wrap');
    const noteWrap      = document.getElementById('mdl_note_wrap');

    rejectWrap.style.display = 'none';
    cancelWrap.style.display = 'none';
    noteWrap.style.display   = 'none';
    reasonSection.style.display = 'none';

    let hasReason = false;

    if (status === 'rejected') {
        rejectWrap.style.display = 'block';
        const rrEl = document.getElementById('mdl_reject_reason');
        rrEl.textContent = (d.reject_reason && d.reject_reason.trim()) ? d.reject_reason.trim() : 'No reason provided.';
        rrEl.className   = (d.reject_reason && d.reject_reason.trim()) ? '' : 'no-reason';
        const rmEl = document.getElementById('mdl_rejected_meta');
        rmEl.textContent = (d.rejected_at || d.rejected_by)
            ? '🕐 ' + [d.rejected_at, d.rejected_by ? 'by ' + d.rejected_by : ''].filter(Boolean).join(' ') : '';
        hasReason = true;
    }

    if (status === 'cancelled') {
        cancelWrap.style.display = 'block';
        const crEl = document.getElementById('mdl_cancel_reason');
        crEl.textContent = (d.cancel_reason && d.cancel_reason.trim()) ? d.cancel_reason.trim() : 'No reason provided.';
        crEl.className   = (d.cancel_reason && d.cancel_reason.trim()) ? '' : 'no-reason';
        const cmEl = document.getElementById('mdl_cancelled_meta');
        cmEl.textContent = (d.cancelled_at || d.cancelled_by)
            ? '🕐 ' + [d.cancelled_at, d.cancelled_by ? 'by ' + d.cancelled_by : ''].filter(Boolean).join(' ') : '';
        hasReason = true;
    }

    if (d.admin_note && d.admin_note.trim()) {
        noteWrap.style.display = 'block';
        document.getElementById('mdl_admin_note').textContent = d.admin_note.trim();
        hasReason = true;
    }

    if (hasReason) reasonSection.style.display = 'block';

    new bootstrap.Modal(document.getElementById('bookingDetailModal')).show();
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>