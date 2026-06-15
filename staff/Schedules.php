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

/* ── Auto-status: Approved → OnTrip ── */
try {
    $pdo->exec("UPDATE schedules SET status='OnTrip'
                WHERE status='Approved'
                  AND office_id={$myOfficeId}
                  AND CONCAT(date_start,' ',COALESCE(time_start,'00:00:00')) <= NOW()");
} catch(PDOException $e){}

/* ── Fetch schedules ── */
$filter = $_GET['filter'] ?? 'All';
$whereStatus = $filter !== 'All' ? "AND s.status = " . $pdo->quote($filter) : '';

$st = $pdo->prepare("
    SELECT s.*, u.username,
        COALESCE(v.plate_number,'—') AS plate_number,
        COALESCE(v.brand,'') AS brand,
        COALESCE(v.model,'') AS model,
        COALESCE(dr.driver_name,'—') AS driver_name,
        o.office_name,
        COALESCE(dept.dept_name,'—') AS department_name,
        s.trip_ticket_no
    FROM schedules s
    JOIN users u ON s.user_id=u.user_id
    LEFT JOIN vehicles v ON s.vehicle_id=v.vehicle_id
    LEFT JOIN drivers dr ON s.driver_id=dr.driver_id
    JOIN offices o ON s.office_id=o.office_id
    LEFT JOIN departments dept ON s.department_id=dept.dept_id
    WHERE s.office_id=? {$whereStatus}
    ORDER BY s.schedule_id DESC
");
$st->execute([$myOfficeId]);
$schedules = $st->fetchAll();

/* ── Helpers ── */
$fmt = function($t){
    if(!$t||$t==='--') return '--';
    foreach(['H:i:s','H:i'] as $f){$dt=DateTime::createFromFormat($f,$t);if($dt)return $dt->format('g:i A');}
    return $t;
};

function getReqNo($id){ return 'REQ-'.str_pad($id,6,'0',STR_PAD_LEFT); }

function getTicketNo(PDO $pdo, int $id, string $ds, string $officeName): string {
    $stored = $pdo->prepare("SELECT trip_ticket_no, office_id FROM schedules WHERE schedule_id = ?");
    $stored->execute([$id]);
    $row = $stored->fetch();
    $storedTicket = $row['trip_ticket_no'] ?? '';

    if (!empty($storedTicket) && preg_match('/\/\d{4}$/', $storedTicket)) {
        return $storedTicket;
    }

    $officeId = (int)($row['office_id'] ?? 0);
    $on = strtolower(trim($officeName));
    if (str_contains($on, 'campus')) {
        $prefix = 'CAM-TRANSPO';
    } elseif (str_contains($on, 'rde')) {
        $prefix = 'RDE-TRANSPO';
    } else {
        $prefix = 'AUX-TRANSPO';
    }

    $m = date('m', strtotime($ds));
    $y = date('Y', strtotime($ds));

    $seqStmt = $pdo->prepare("
        SELECT seq FROM (
            SELECT schedule_id,
                   RANK() OVER (
                       PARTITION BY office_id, MONTH(date_start), YEAR(date_start)
                       ORDER BY schedule_id ASC
                   ) AS seq
            FROM schedules
            WHERE MONTH(date_start) = ?
              AND YEAR(date_start)  = ?
              AND office_id         = ?
        ) ranked
        WHERE schedule_id = ?
    ");
    $seqStmt->execute([$m, $y, $officeId, $id]);
    $seq = (int)$seqStmt->fetchColumn();

    $ticketNo = $prefix . '-' . $m . '/' . $y . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    $pdo->prepare("UPDATE schedules SET trip_ticket_no = ? WHERE schedule_id = ?")
        ->execute([$ticketNo, $id]);

    return $ticketNo;
}

/* ── Resolve trip ticket numbers for Approved/OnTrip/Completed ── */
foreach ($schedules as &$s) {
    if (in_array($s['status'], ['Approved', 'OnTrip', 'Completed'])) {
        $s['trip_ticket_no'] = getTicketNo($pdo, (int)$s['schedule_id'], $s['date_start'], $s['office_name'] ?? '');
    }
}
unset($s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Schedules – CSU VSS Staff</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ══ MOBILE SCHEDULES (Staff) ══ */
.hamburger-btn { display: none; background: none; border: none; cursor: pointer; padding: 4px 8px; color: #800000; font-size: 1.4rem; align-items: center; line-height: 1; }
.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 199; }
.sidebar-overlay.open { display: block; }

@media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); transition: transform .25s ease; }
    .sidebar.open { transform: translateX(0); }
    .topbar, .main-content { margin-left: 0 !important; }
    .hamburger-btn { display: flex !important; }
    .desktop-sched-table { display: none !important; }
    .topbar { padding: .6rem 1rem; gap: 8px; }
    .main-content { padding: .75rem; }

    .mob-stat-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px; }
    .mob-stat-card { background: #fff; border-radius: 10px; padding: 10px; border-left: 3px solid #800000; box-shadow: 0 1px 5px rgba(128,0,0,.06); }
    .mob-stat-card.approved { border-left-color: #0f5132; }
    .mob-stat-card.ontrip   { border-left-color: #7a4f00; }
    .mob-stat-card.pending  { border-left-color: #856404; }
    .mob-stat-lbl { font-size: .6rem; color: #aaa; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .mob-stat-val { font-size: 1.4rem; font-weight: 800; color: #1a1a1a; line-height: 1.1; }

    .mob-filter-row { display: flex; gap: 7px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 12px; scrollbar-width: none; }
    .mob-filter-row::-webkit-scrollbar { display: none; }
    .mob-filter-pill { flex-shrink: 0; padding: 6px 14px; border-radius: 20px; font-size: .76rem; font-weight: 600; border: 1.5px solid #e0d0d0; color: #800000; background: #fff; cursor: pointer; white-space: nowrap; text-decoration: none; transition: all .15s; }
    .mob-filter-pill.active { background: #800000; color: #fff; border-color: #800000; }

    .mob-search-wrap { display: flex; align-items: center; background: #fdf8f8; border: 1.5px solid #e0d0d0; border-radius: 10px; padding: 0 10px; margin-bottom: 12px; gap: 6px; }
    .mob-search-wrap:focus-within { border-color: #800000; background: #fff; }
    .mob-search-icon { color: #800000; font-size: .9rem; flex-shrink: 0; display: flex; align-items: center; }
    .mob-search-input { flex: 1; border: none; background: transparent; padding: .55rem 0; font-size: .86rem; outline: none; min-width: 0; }
    .mob-search-clear { flex-shrink: 0; display: none; align-items: center; background: none; border: none; color: #aaa; cursor: pointer; line-height: 1; padding: 0; }

    .sched-card { background: #fff; border-radius: 14px; padding: 13px 14px; margin-bottom: 9px; box-shadow: 0 1px 6px rgba(128,0,0,.07); border: 1px solid #f5eded; }
    .sc-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 9px; }
    .sc-id { font-size: .68rem; color: #bbb; font-weight: 700; margin-bottom: 2px; }
    .sc-name { font-weight: 700; font-size: .9rem; color: #1a1a1a; }
    .sc-dest { font-size: .78rem; color: #666; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
    .sc-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 9px; }
    .sc-meta-item { background: #fdf8f8; border-radius: 8px; padding: 6px 9px; }
    .sc-meta-lbl { font-size: .6rem; color: #bbb; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 1px; }
    .sc-meta-val { font-size: .76rem; color: #444; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sc-actions { display: flex; gap: 7px; padding-top: 9px; border-top: 1px solid #fdf0f0; flex-wrap: wrap; }
    .sc-btn { flex: 1; min-width: 70px; padding: 8px 6px; border-radius: 9px; font-size: .74rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 4px; cursor: pointer; border: none; transition: all .15s; }
    .sc-btn-done  { background: #d1e7dd; color: #0f5132; }
    .sc-btn-view  { background: #f0f4ff; color: #0550a0; }
    .sc-btn-print { background: #f5f5f5; color: #444; }

    .mob-empty { text-align: center; padding: 40px 20px; color: #bbb; }
    .mob-empty i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: .35; }

    .sheet-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 300; opacity: 0; pointer-events: none; transition: opacity .25s; }
    .sheet-backdrop.open { opacity: 1; pointer-events: all; }

    .mob-sheet { position: fixed; bottom: 0; left: 0; right: 0; z-index: 310; background: #fff; border-radius: 20px 20px 0 0; max-height: 92vh; overflow-y: auto; transform: translateY(105%); transition: transform .3s cubic-bezier(.4,0,.2,1); padding: 0 16px 48px; }
    .mob-sheet.open { transform: translateY(0); }
    .sheet-handle { width: 40px; height: 4px; background: #e0d0d0; border-radius: 2px; margin: 12px auto 16px; }
    .sheet-head { font-weight: 700; font-size: 1rem; color: #800000; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .sheet-actions { display: flex; gap: 10px; margin-top: 18px; }
    .btn-sheet-cancel { flex: 1; padding: 12px; border-radius: 11px; background: #f5f5f5; color: #555; font-weight: 600; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-success { flex: 2; padding: 12px; border-radius: 11px; background: #0f5132; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
    .sheet-form-group { margin-bottom: 13px; }
    .sheet-label { font-size: .72rem; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; display: block; }
    .sheet-input { width: 100%; padding: 11px 13px; border-radius: 10px; border: 1.5px solid #e0d0d0; font-size: .9rem; color: #333; background: #fff; outline: none; -webkit-appearance: none; appearance: none; }
    .sheet-input:focus { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,.1); }
    .sheet-info-box { background: #fdf8f8; border: 1px solid #f0e5e5; border-radius: 10px; padding: 10px 12px; margin-bottom: 13px; font-size: .8rem; color: #800000; }
    .sheet-info-row { display: flex; justify-content: space-between; margin-bottom: 3px; }
    .sheet-info-lbl { color: #aaa; font-size: .72rem; }
}

@media (min-width: 901px) {
    .sheet-backdrop  { display: none !important; }
    .mob-sheet       { display: none !important; }
    .mob-sched-list  { display: none !important; }
    .mob-stat-strip  { display: none !important; }
    .mob-filter-row  { display: none !important; }
    .mob-search-wrap { display: none !important; }
}

*{box-sizing:border-box}
body{background:#f5f0f0;font-family:'Segoe UI',sans-serif}
.hamburger-btn{display:none;background:none;border:none;cursor:pointer;padding:4px;color:#800000;font-size:1.2rem;line-height:1;margin-right:.5rem}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:199}
.sidebar-overlay.open{display:block}
.sidebar{min-height:100vh;background:linear-gradient(180deg,#800000,#6b0000);width:240px;position:fixed;top:0;left:0;z-index:200;display:flex;flex-direction:column}
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
.topbar{background:#fff;border-bottom:1px solid #e8dede;padding:.7rem 1.5rem;margin-left:240px;position:sticky;top:0;z-index:99;display:flex;align-items:center;justify-content:space-between}
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}
.main-content{margin-left:240px;padding:1.5rem}
.section-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.07);overflow:hidden}
.section-header{padding:1rem 1.25rem;border-bottom:1px solid #f0e5e5;font-weight:700;font-size:.9rem;color:#800000;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.table thead th{background:#fdf5f5;color:#800000;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid #f0e5e5;padding:.75rem 1rem;white-space:nowrap}
.table tbody td{padding:.7rem 1rem;font-size:.85rem;color:#444;vertical-align:middle;border-color:#fdf5f5}
.table tbody tr:hover{background:#fdf8f8}
.bp{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600}
.bp-pending{background:#fff3cd;color:#856404}
.bp-approved{background:#d1e7dd;color:#0f5132}
.bp-ontrip{background:#fff0d6;color:#7a4f00}
.bp-completed{background:#cfe2ff;color:#0a3678}
.bp-rejected{background:#f8d7da;color:#842029}
.bp-cancelled{background:#e2e3e5;color:#41464b}
.bp-upcoming{background:#e8d5ff;color:#5a00b4}
.req-no{font-size:.78rem;font-weight:700;color:#6b0000;background:#fdecea;padding:2px 9px;border-radius:10px;white-space:nowrap}
.ticket-no{display:inline-flex;align-items:center;gap:5px;font-size:.78rem;font-weight:700;color:#92600a;background:#fff8e1;border:1.5px solid #f6c94e;padding:2px 9px;border-radius:10px;white-space:nowrap;font-family:monospace;letter-spacing:.03em}
.ticket-none{display:inline-flex;align-items:center;gap:4px;font-size:.74rem;color:#bbb;background:#f8f9fa;border:1px solid #e9ecef;padding:2px 8px;border-radius:8px;white-space:nowrap;font-style:italic}
.not-assigned{font-size:.8rem;color:#aaa;font-style:italic}
.filter-btn{font-size:.8rem;padding:4px 14px;border-radius:20px;border:1.5px solid #e0d0d0;background:#fff;color:#666;cursor:pointer;transition:all .15s;text-decoration:none}
.filter-btn:hover,.filter-btn.active{background:#800000;color:#fff;border-color:#800000}
.readonly-badge{display:inline-flex;align-items:center;gap:5px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:4px 10px;font-size:.75rem;color:#666}
.mh-green{background:linear-gradient(135deg,#145a32,#1e8449);color:#fff}
.mh-green .btn-close{filter:invert(1)}
.mh-blue{background:linear-gradient(135deg,#0550a0,#0a3678);color:#fff}
.mh-blue .btn-close{filter:invert(1)}
.sbox{background:#f8f9fa;border-radius:10px;padding:1rem;border:1.5px solid #dee2e6}
.sbox-title{font-size:.8rem;font-weight:700;color:#800000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem}
.detail-label{font-size:.72rem;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px}
.detail-value{font-size:.9rem;font-weight:600;color:#333}
#toast-wrap{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem}
.toast-item{padding:.75rem 1.25rem;border-radius:10px;font-size:.85rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);animation:slideIn .3s ease}
.toast-success{background:#d1e7dd;color:#0f5132;border-left:4px solid #0f5132}
.toast-danger{background:#f8d7da;color:#842029;border-left:4px solid #842029}
@keyframes slideIn{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}

@media (max-width: 768px) {
    .hamburger-btn { display: flex; align-items: center; }
    .sidebar { transform: translateX(-100%); transition: transform 0.25s ease; position: fixed !important; top: 0; left: 0; height: 100vh; overflow-y: auto; z-index: 200; }
    .sidebar.open { transform: translateX(0) !important; }
    .topbar { margin-left: 0 !important; }
    .main-content { margin-left: 0 !important; padding: 1rem; }
    .topbar-title { font-size: .82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .topbar-title i { display: none; }
    .topbar-user > div > div:last-child { display: none; }
    .section-header { flex-wrap: wrap; gap: .5rem; }
    .filter-btn { font-size: .68rem; padding: 3px 10px; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table-responsive .table { min-width: 900px; }
    .modal-dialog { margin: auto 0 0; max-width: 100%; }
    .modal-content { border-radius: 16px 16px 0 0 !important; }
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<div id="toast-wrap"></div>

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
    <a class="nav-link active" href="Schedules.php"><i class="bi bi-calendar-check"></i> View Schedules</a>
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
  <div class="topbar-title"><i class="bi bi-calendar-check me-2"></i>Schedules</div>
  <div class="topbar-user">
    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
    <div>
      <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
      <div style="font-size:.72rem;color:#800000">Staff — <?= htmlspecialchars($me['office_name'] ?? '—') ?></div>
    </div>
  </div>
</div>

<div class="main-content">

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

<div class="section-card">
  <div class="section-header">
    <span>
      <i class="bi bi-calendar-check me-2"></i>Schedules
      <small class="ms-2" style="font-size:.75rem;color:#a05050;font-weight:400">
        <i class="bi bi-building me-1"></i><?= htmlspecialchars($me['office_name'] ?? '') ?>
      </small>
    </span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a href="?filter=All"       class="filter-btn <?=$filter==='All'?'active':''?>">All</a>
      <a href="?filter=Pending"   class="filter-btn <?=$filter==='Pending'?'active':''?>">Pending</a>
      <a href="?filter=Approved"  class="filter-btn <?=$filter==='Approved'?'active':''?>">Approved</a>
      <a href="?filter=OnTrip"    class="filter-btn <?=$filter==='OnTrip'?'active':''?>">On Trip</a>
      <a href="?filter=Completed" class="filter-btn <?=$filter==='Completed'?'active':''?>">Completed</a>
      <a href="?filter=Rejected"  class="filter-btn <?=$filter==='Rejected'?'active':''?>">Rejected</a>
      <a href="?filter=Cancelled" class="filter-btn <?=$filter==='Cancelled'?'active':''?>">Cancelled</a>
      <span class="readonly-badge ms-2"><i class="bi bi-eye me-1"></i>View Only</span>
    </div>
  </div>

  <!-- ══ DESKTOP TABLE ══ -->
  <div class="table-responsive desktop-sched-table">
    <table class="table mb-0">
      <thead><tr>
        <th>Request #</th><th>Trip Ticket #</th><th>Requestor</th><th>Dept</th>
        <th>Date</th><th>Time</th><th>Destination</th><th>Purpose</th>
        <th>Vehicle</th><th>Driver</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach($schedules as $s):
        $status    = $s['status'] ?? '';
        $isCmp     = ($status==='Completed');
        $isOnTrip  = ($status==='OnTrip');
        $isAppr    = ($status==='Approved');
        $tripStart = strtotime(($s['date_start']??'').' '.($s['time_start']??'00:00:00'));
        $isUpcoming= ($isAppr && $tripStart > time());
        $tickNo    = trim($s['trip_ticket_no'] ?? '');
        $vLbl      = !empty($s['vehicle_id'])?htmlspecialchars($s['brand'].' '.$s['model'].' ('.$s['plate_number'].')',ENT_QUOTES):'—';
        $dLbl      = !empty($s['driver_id'])?htmlspecialchars($s['driver_name'],ENT_QUOTES):'—';
        $sid_int   = (int)$s['schedule_id'];
        $arrv      = htmlspecialchars($s['arrived_at']??'',ENT_QUOTES);
        $reason    = htmlspecialchars($s['rejection_reason']??'',ENT_QUOTES);
        $cancelReason = htmlspecialchars($s['cancel_reason']??'',ENT_QUOTES);
      ?>
      <tr>
        <td><span class="req-no"><?= getReqNo($s['schedule_id']) ?></span></td>
        <td>
          <?php if ($tickNo !== ''): ?>
            <span class="ticket-no">
              <i class="bi bi-ticket-perforated-fill" style="font-size:.7rem"></i>
              <?= htmlspecialchars($tickNo) ?>
            </span>
          <?php else: ?>
            <span class="ticket-none">
              <i class="bi bi-dash" style="font-size:.7rem"></i> Not issued
            </span>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($s['username']) ?></td>
        <td><?= htmlspecialchars($s['department_name']) ?></td>
        <td><?php $ds=$s['date_start']??'';$de=$s['date_end']??''; echo $ds===$de?htmlspecialchars($ds):htmlspecialchars($ds).' → '.htmlspecialchars($de);?></td>
        <td><?= htmlspecialchars($fmt($s['time_start']).' – '.$fmt($s['time_end'])) ?></td>
        <td><?= htmlspecialchars($s['destination']) ?></td>
        <td><?= htmlspecialchars($s['purpose']) ?></td>
        <td><?= !empty($s['vehicle_id'])?htmlspecialchars($s['brand'].' '.$s['model'].' ('.$s['plate_number'].')') : '<span class="not-assigned">Not assigned</span>'?></td>
        <td><?= !empty($s['driver_id'])?htmlspecialchars($s['driver_name']):'<span class="not-assigned">Not assigned</span>'?></td>
        <td>
          <?php if($isCmp):?><span class="bp bp-completed"><i class="bi bi-check2-all me-1"></i>Completed</span>
          <?php elseif($isOnTrip):?><span class="bp bp-ontrip"><i class="bi bi-truck me-1"></i>On Trip</span>
          <?php elseif($isUpcoming):?><span class="bp bp-upcoming"><i class="bi bi-calendar-event me-1"></i>Upcoming</span>
          <?php elseif($isAppr):?><span class="bp bp-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>
          <?php elseif($status==='Pending'):?><span class="bp bp-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
          <?php elseif($status==='Cancelled'):?><span class="bp bp-cancelled"><i class="bi bi-slash-circle me-1"></i>Cancelled</span>
          <?php else:?><span class="bp bp-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span><?php endif;?>
        </td>
        <td>
          <div class="d-flex gap-1 flex-wrap align-items-center">
            <button type="button" class="btn btn-sm btn-outline-info btn-viewdetails" title="View Details"
              data-id="<?=$sid_int?>"
              data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>"
              data-dept="<?=htmlspecialchars($s['department_name'],ENT_QUOTES)?>"
              data-dest="<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>"
              data-purp="<?=htmlspecialchars($s['purpose'],ENT_QUOTES)?>"
              data-ds="<?=htmlspecialchars($s['date_start'],ENT_QUOTES)?>"
              data-de="<?=htmlspecialchars($s['date_end'],ENT_QUOTES)?>"
              data-ts="<?=htmlspecialchars($s['time_start']??'',ENT_QUOTES)?>"
              data-te="<?=htmlspecialchars($s['time_end']??'',ENT_QUOTES)?>"
              data-vehicle="<?=$vLbl?>" data-driver="<?=$dLbl?>"
              data-arrived="<?=$arrv?>" data-reason="<?=$reason?>"
              data-cancel-reason="<?=$cancelReason?>"
              data-ticket="<?=htmlspecialchars($tickNo,ENT_QUOTES)?>"
              data-status="<?=htmlspecialchars($status,ENT_QUOTES)?>">
              <i class="bi bi-info-circle"></i>
            </button>
            <?php if(in_array($status,['Approved','OnTrip','Completed'])):?>
<a href="../admin/print_trip_ticket.php?id=<?=$sid_int?>" target="_blank"
   class="btn btn-sm btn-outline-secondary" title="Print Trip Ticket">
  <i class="bi bi-printer"></i>
</a>
<button type="button"
    class="btn btn-sm btn-outline-success btn-attach-signed"
    title="<?= !empty($s['signed_ticket_path']) ? 'Replace Signed Ticket' : 'Attach Signed Ticket' ?>"
    data-id="<?= $sid_int ?>">
  <i class="bi bi-paperclip"></i>
</button>
<?php if(!empty($s['signed_ticket_path'])): ?>
<button type="button"
    class="btn btn-sm btn-outline-primary btn-view-signed"
    title="View Signed Ticket"
    data-path="../<?= htmlspecialchars($s['signed_ticket_path'], ENT_QUOTES) ?>"
    data-type="<?= strtolower(pathinfo($s['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
  <i class="bi bi-file-earmark-check-fill"></i>
</button>
<?php endif;?>
<?php endif;?>
            <?php if($isOnTrip):?>
            <button type="button" class="btn btn-sm btn-success btn-tripdone" title="Mark Trip Complete"
              data-schedule-id="<?=$sid_int?>"
              data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>"
              data-dest="<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>">
              <i class="bi bi-flag-fill me-1"></i>Trip Done
            </button>
            <?php endif;?>
          </div>
        </td>
      </tr>
      <?php endforeach;?>
      <?php if(empty($schedules)):?>
      <tr><td colspan="12" class="text-center text-muted py-4">
        <i class="bi bi-calendar-x fs-4 d-block mb-2 opacity-50"></i>No schedules found.
      </td></tr>
      <?php endif;?>
      </tbody>
    </table>
  </div>

  <!-- ══ MOBILE STATS ══ -->
<?php
$cnt_pending   = count(array_filter($schedules, fn($s) => $s['status']==='Pending'));
$cnt_approved  = count(array_filter($schedules, fn($s) => $s['status']==='Approved'));
$cnt_ontrip    = count(array_filter($schedules, fn($s) => $s['status']==='OnTrip'));
$cnt_completed = count(array_filter($schedules, fn($s) => $s['status']==='Completed'));
$cnt_rejected  = count(array_filter($schedules, fn($s) => $s['status']==='Rejected'));
$cnt_cancelled = count(array_filter($schedules, fn($s) => $s['status']==='Cancelled'));
?>
<div class="mob-stat-strip px-3 pt-3">
    <div class="mob-stat-card pending"><div class="mob-stat-lbl">Pending</div><div class="mob-stat-val"><?= $cnt_pending ?></div></div>
    <div class="mob-stat-card approved"><div class="mob-stat-lbl">Approved</div><div class="mob-stat-val"><?= $cnt_approved ?></div></div>
    <div class="mob-stat-card ontrip"><div class="mob-stat-lbl">On Trip</div><div class="mob-stat-val"><?= $cnt_ontrip ?></div></div>
</div>

<!-- ══ MOBILE FILTER PILLS ══ -->
<div class="mob-filter-row px-3 pt-2">
    <a href="?filter=All"       class="mob-filter-pill <?= $filter==='All'       ?'active':''?>">All (<?= count($schedules) ?>)</a>
    <a href="?filter=Pending"   class="mob-filter-pill <?= $filter==='Pending'   ?'active':''?>">Pending (<?= $cnt_pending ?>)</a>
    <a href="?filter=Approved"  class="mob-filter-pill <?= $filter==='Approved'  ?'active':''?>">Approved (<?= $cnt_approved ?>)</a>
    <a href="?filter=OnTrip"    class="mob-filter-pill <?= $filter==='OnTrip'    ?'active':''?>">On Trip (<?= $cnt_ontrip ?>)</a>
    <a href="?filter=Completed" class="mob-filter-pill <?= $filter==='Completed' ?'active':''?>">Completed (<?= $cnt_completed ?>)</a>
    <a href="?filter=Rejected"  class="mob-filter-pill <?= $filter==='Rejected'  ?'active':''?>">Rejected (<?= $cnt_rejected ?>)</a>
    <a href="?filter=Cancelled" class="mob-filter-pill <?= $filter==='Cancelled' ?'active':''?>">Cancelled (<?= $cnt_cancelled ?>)</a>
</div>

<!-- ══ MOBILE SEARCH ══ -->
<div class="mob-search-wrap mx-3">
    <i class="bi bi-search mob-search-icon"></i>
    <input type="text" class="mob-search-input" id="mobSchedSearch"
        placeholder="Search requestor, destination…"
        oninput="onMobSchedSearch(this)" autocomplete="off">
    <button class="mob-search-clear" id="mobSchedSearchClear" onclick="clearMobSchedSearch()">
        <i class="bi bi-x-lg" style="font-size:.75rem"></i>
    </button>
</div>

<!-- ══ MOBILE SCHEDULE CARDS ══ -->
<div class="mob-sched-list px-3 pb-3" id="mobSchedList">
<?php foreach($schedules as $s):
    $status    = $s['status'] ?? '';
    $isCmp     = ($status==='Completed');
    $isOnTrip  = ($status==='OnTrip');
    $isAppr    = ($status==='Approved');
    $tripStart = strtotime(($s['date_start']??'').' '.($s['time_start']??'00:00:00'));
    $isUpcoming= ($isAppr && $tripStart > time());
    $sid_int   = (int)$s['schedule_id'];
    $mobTicket = trim($s['trip_ticket_no'] ?? '');
    $vLbl      = !empty($s['vehicle_id']) ? htmlspecialchars($s['brand'].' '.$s['model'].' ('.$s['plate_number'].')',ENT_QUOTES) : '—';
    $dLbl      = !empty($s['driver_id'])  ? htmlspecialchars($s['driver_name'],ENT_QUOTES) : '—';
    $badgeCls  = match($status) {
        'Completed' => 'bp-completed', 'OnTrip' => 'bp-ontrip',
        'Approved'  => $isUpcoming ? 'bp-upcoming' : 'bp-approved',
        'Pending'   => 'bp-pending',  'Cancelled' => 'bp-cancelled',
        default     => 'bp-rejected'
    };
    $badgeTxt = match($status) {
        'OnTrip'   => 'On Trip',
        'Approved' => $isUpcoming ? 'Upcoming' : 'Approved',
        default    => $status
    };
?>
<div class="sched-card mob-sched-row"
    data-search="<?= htmlspecialchars(strtolower(($s['username']??'').' '.($s['destination']??'').' '.($s['purpose']??'').' '.($s['driver_name']??'').' '.($s['department_name']??'')), ENT_QUOTES) ?>">
    <div class="sc-top">
        <div style="flex:1;min-width:0">
            <div class="sc-id">
                <?= getReqNo($sid_int) ?>
                <?php if ($mobTicket !== '' && in_array($status, ['Approved','OnTrip','Completed'])): ?>
                &nbsp;·&nbsp;<span style="font-size:.66rem;font-weight:700;color:#92600a;background:#fff8e1;border:1px solid #f6c94e;border-radius:6px;padding:1px 6px;font-family:monospace;white-space:nowrap"><?= htmlspecialchars($mobTicket) ?></span>
                <?php endif; ?>
            </div>
            <div class="sc-name"><?= htmlspecialchars($s['username']) ?></div>
            <div class="sc-dest"><i class="bi bi-geo-alt-fill" style="color:#800000;font-size:.7rem"></i> <?= htmlspecialchars($s['destination']) ?></div>
        </div>
        <span class="bp <?= $badgeCls ?>" style="flex-shrink:0;margin-left:8px"><?= $badgeTxt ?></span>
    </div>
    <div class="sc-meta">
        <div class="sc-meta-item">
            <div class="sc-meta-lbl">Date</div>
            <div class="sc-meta-val"><?= $s['date_start']===$s['date_end'] ? htmlspecialchars($s['date_start']) : htmlspecialchars($s['date_start']).' → '.htmlspecialchars($s['date_end']) ?></div>
        </div>
        <div class="sc-meta-item">
            <div class="sc-meta-lbl">Time</div>
            <div class="sc-meta-val"><?= htmlspecialchars($fmt($s['time_start']).' – '.$fmt($s['time_end'])) ?></div>
        </div>
        <div class="sc-meta-item">
            <div class="sc-meta-lbl">Vehicle</div>
            <div class="sc-meta-val"><?= !empty($s['vehicle_id']) ? htmlspecialchars($s['brand'].' '.$s['model']) : '—' ?></div>
        </div>
        <div class="sc-meta-item">
            <div class="sc-meta-lbl">Driver</div>
            <div class="sc-meta-val"><?= !empty($s['driver_id']) ? htmlspecialchars($s['driver_name']) : '—' ?></div>
        </div>
    </div>
    <div class="sc-actions">
        <?php if($isCmp): ?>
    <a href="../admin/print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="sc-btn sc-btn-print"><i class="bi bi-printer"></i> Print</a>
    <button type="button"
        class="sc-btn sc-btn-view btn-attach-signed"
        style="background:#d1e7dd;color:#0f5132"
        title="Attach Signed Ticket"
        data-id="<?= $sid_int ?>">
        <i class="bi bi-paperclip"></i>
    </button>
    <?php if(!empty($s['signed_ticket_path'])): ?>
    <button type="button"
        class="sc-btn sc-btn-view btn-view-signed"
        style="background:#e8f0fe;color:#1d4ed8"
        title="View Signed Ticket"
        data-path="../<?= htmlspecialchars($s['signed_ticket_path'], ENT_QUOTES) ?>"
        data-type="<?= strtolower(pathinfo($s['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
        <i class="bi bi-file-earmark-check-fill"></i>
    </button>
    <?php endif;?>
    <button class="sc-btn sc-btn-view" onclick="mobViewDetails(...<?= htmlspecialchars(json_encode([
                'id'=>$sid_int,'username'=>$s['username'],'dept'=>$s['department_name']??'—',
                'dest'=>$s['destination'],'purp'=>$s['purpose'],'ds'=>$s['date_start'],'de'=>$s['date_end'],
                'ts'=>$s['time_start']??'','te'=>$s['time_end']??'','vehicle'=>$vLbl,'driver'=>$dLbl,
                'arrived'=>$s['arrived_at']??'','reason'=>$s['rejection_reason']??'',
                'cancelReason'=>$s['cancel_reason']??'','status'=>$status,
                'ticket'=>$mobTicket
            ]), ENT_QUOTES) ?>)"><i class="bi bi-info-circle"></i> Details</button>

        <?php elseif($isOnTrip): ?>
            <a href="../admin/print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="sc-btn sc-btn-print"><i class="bi bi-printer"></i></a>
            <button class="sc-btn sc-btn-done" onclick="mobTripDone(<?=$sid_int?>, '<?=htmlspecialchars($s['username'],ENT_QUOTES)?>', '<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>')">
                <i class="bi bi-flag-fill"></i> Trip Done
            </button>

        <?php elseif($isAppr): ?>
            <a href="../admin/print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="sc-btn sc-btn-print"><i class="bi bi-printer"></i></a>
            <button class="sc-btn sc-btn-view" onclick="mobViewDetails(<?= htmlspecialchars(json_encode([
                'id'=>$sid_int,'username'=>$s['username'],'dept'=>$s['department_name']??'—',
                'dest'=>$s['destination'],'purp'=>$s['purpose'],'ds'=>$s['date_start'],'de'=>$s['date_end'],
                'ts'=>$s['time_start']??'','te'=>$s['time_end']??'','vehicle'=>$vLbl,'driver'=>$dLbl,
                'arrived'=>'','reason'=>'','cancelReason'=>'','status'=>$status,
                'ticket'=>$mobTicket
            ]), ENT_QUOTES) ?>)"><i class="bi bi-info-circle"></i> Details</button>

        <?php else: ?>
            <button class="sc-btn sc-btn-view" onclick="mobViewDetails(<?= htmlspecialchars(json_encode([
                'id'=>$sid_int,'username'=>$s['username'],'dept'=>$s['department_name']??'—',
                'dest'=>$s['destination'],'purp'=>$s['purpose'],'ds'=>$s['date_start'],'de'=>$s['date_end'],
                'ts'=>$s['time_start']??'','te'=>$s['time_end']??'','vehicle'=>$vLbl,'driver'=>$dLbl,
                'arrived'=>$s['arrived_at']??'','reason'=>$s['rejection_reason']??'',
                'cancelReason'=>$s['cancel_reason']??'','status'=>$status,
                'ticket'=>$mobTicket
            ]), ENT_QUOTES) ?>)"><i class="bi bi-info-circle"></i> Details</button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php if(empty($schedules)): ?>
<div class="mob-empty"><i class="bi bi-calendar-x"></i><p>No schedules found.</p></div>
<?php endif; ?>
</div>

</div><!-- /section-card -->
</div><!-- /main-content -->

<!-- Sheet backdrop -->
<div class="sheet-backdrop" id="mobSchedBackdrop" onclick="mobSchedCloseAll()"></div>

<!-- Trip Done Sheet -->
<div class="mob-sheet" id="mobTripDoneSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-flag-fill" style="color:#0f5132"></i> Record Trip Completion</div>
    <div class="sheet-info-box" id="mob_td_info"></div>
    <div class="sheet-form-group">
        <label class="sheet-label">Arrival Date <span style="color:#dc2626">*</span></label>
        <input type="date" id="mob_td_date" class="sheet-input">
    </div>
    <div class="sheet-form-group">
        <label class="sheet-label">Arrival Time <span style="color:#dc2626">*</span></label>
        <input type="time" id="mob_td_time" class="sheet-input">
    </div>
    <div id="mob_td_err" style="background:#fef2f2;border-radius:9px;padding:8px 11px;font-size:.78rem;color:#dc2626;display:none;margin-top:8px"></div>
    <div class="sheet-actions">
        <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
        <button type="button" id="mob_td_confirm" class="btn-sheet-success" onclick="mobTripDoneConfirm()">
            <i class="bi bi-flag-fill me-1"></i>Confirm Complete
        </button>
    </div>
</div>

<!-- View Details Sheet -->
<div class="mob-sheet" id="mobDetailsSheet" style="padding-bottom:60px">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-clipboard2-check"></i> Trip Details</div>
    <div id="mob_det_body" style="font-size:.84rem"></div>
    <div class="sheet-actions" style="margin-top:12px">
        <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Close</button>
    </div>
</div>

<!-- TRIP DONE MODAL -->
<div class="modal fade" id="tripDoneModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header mh-green">
    <h5 class="modal-title"><i class="bi bi-flag-fill me-2"></i>Record Trip Completion</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body p-4">
    <div class="sbox mb-3">
      <div class="sbox-title"><i class="bi bi-info-circle me-1"></i>Trip Details</div>
      <div class="row g-2">
        <div class="col-md-6"><div style="font-size:.75rem;color:#888">Requestor</div><div class="fw-semibold" id="td_uname">—</div></div>
        <div class="col-md-6"><div style="font-size:.75rem;color:#888">Destination</div><div class="fw-semibold" id="td_dest">—</div></div>
      </div>
    </div>
    <div class="alert alert-success py-2 px-3 mb-3" style="font-size:.83rem">
      <i class="bi bi-check-circle me-1"></i>Record the actual arrival date and time.
    </div>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Arrival Date <span class="text-danger">*</span></label><input type="date" id="td_date" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Arrival Time <span class="text-danger">*</span></label><input type="time" id="td_time" class="form-control"></div>
    </div>
    <div id="td_err" class="alert alert-danger py-2 px-3 mt-3 d-none" style="font-size:.83rem"></div>
  </div>
  <div class="modal-footer border-0 pt-0 pb-4 px-4">
    <button type="button" class="btn btn-secondary btn-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
    <button type="button" id="td_confirm" class="btn btn-sm rounded-3 fw-semibold" style="background:#145a32;color:#fff;min-width:170px">
      <i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete
    </button>
  </div>
</div></div></div>

<!-- DETAILS MODAL -->
<div class="modal fade" id="detailsModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-header mh-blue">
    <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Trip Details</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body p-4">
    <div class="row g-3">
      <div class="col-md-4"><div class="detail-label">Requestor</div><div class="detail-value" id="det_uname">—</div></div>
      <div class="col-md-4"><div class="detail-label">Department</div><div class="detail-value" id="det_dept">—</div></div>
      <div class="col-md-4"><div class="detail-label">Status</div><div id="det_status">—</div></div>
      <div class="col-12"><div class="detail-label">Trip Ticket #</div><div id="det_ticket">—</div></div>
      <div class="col-md-6"><div class="detail-label">Destination</div><div class="detail-value" id="det_dest">—</div></div>
      <div class="col-md-6"><div class="detail-label">Purpose</div><div class="detail-value" id="det_purp">—</div></div>
      <div class="col-md-6"><div class="detail-label">Date Range</div><div class="detail-value" id="det_dates">—</div></div>
      <div class="col-md-6"><div class="detail-label">Time</div><div class="detail-value" id="det_time">—</div></div>
      <div class="col-md-6"><div class="detail-label">Vehicle</div><div class="detail-value" id="det_veh">—</div></div>
      <div class="col-md-6"><div class="detail-label">Driver</div><div class="detail-value" id="det_drv">—</div></div>
      <div class="col-12" id="det_arrived_wrap">
        <hr class="my-1">
        <div class="detail-label mt-2">Actual Arrival</div>
        <div class="detail-value" id="det_arrived" style="color:#145a32">—</div>
      </div>
      <div class="col-12" id="det_reason_wrap" style="display:none">
        <hr class="my-1">
        <div class="detail-label mt-2" style="color:#842029">Rejection Reason</div>
        <div class="detail-value" id="det_reason" style="color:#842029">—</div>
      </div>
      <div class="col-12" id="det_cancel_wrap" style="display:none">
        <hr class="my-1">
        <div class="detail-label mt-2" style="color:#41464b">Cancellation Reason</div>
        <div class="detail-value" id="det_cancel" style="color:#41464b">—</div>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>

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

const tripDoneModal = document.getElementById('tripDoneModal');

/* ── Trip Done (desktop) ── */
document.addEventListener('click', function(e){
    const btn = e.target.closest('.btn-tripdone'); if(!btn) return;
    tripDoneModal.dataset.currentScheduleId = btn.getAttribute('data-schedule-id');
    document.getElementById('td_uname').textContent = btn.getAttribute('data-username')||'—';
    document.getElementById('td_dest').textContent  = btn.getAttribute('data-dest')||'—';
    document.getElementById('td_err').classList.add('d-none');
    const now=new Date(), pad=n=>String(n).padStart(2,'0');
    document.getElementById('td_date').value = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
    document.getElementById('td_time').value = pad(now.getHours())+':'+pad(now.getMinutes());
    const cb=document.getElementById('td_confirm');
    cb.disabled=false; cb.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete';
    new bootstrap.Modal(tripDoneModal).show();
});

document.getElementById('td_confirm').addEventListener('click', function(){
    const scheduleId  = parseInt(tripDoneModal.dataset.currentScheduleId, 10);
    const arrivedDate = document.getElementById('td_date').value.trim();
    const arrivedTime = document.getElementById('td_time').value.trim();
    const errBox = document.getElementById('td_err');
    errBox.classList.add('d-none'); errBox.textContent = '';
    if (!scheduleId || scheduleId <= 0) { errBox.textContent='Error: Missing schedule ID.'; errBox.classList.remove('d-none'); return; }
    if (!arrivedDate) { errBox.textContent='Please enter arrival date.'; errBox.classList.remove('d-none'); return; }
    if (!arrivedTime) { errBox.textContent='Please enter arrival time.'; errBox.classList.remove('d-none'); return; }
    this.disabled=true; this.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    fetch('complete_trip.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({schedule_id:scheduleId,arrived_date:arrivedDate,arrived_time:arrivedTime}).toString()})
    .then(r=>r.text()).then(raw=>{
        let data; try{data=JSON.parse(raw);}catch(_){
            errBox.innerHTML='<strong>Server error:</strong><br><pre style="font-size:.75rem;white-space:pre-wrap">'+raw.substring(0,500)+'</pre>';
            errBox.classList.remove('d-none'); this.disabled=false; this.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete'; return;
        }
        if(data.ok){ bootstrap.Modal.getInstance(tripDoneModal).hide(); showToast('Trip marked complete!','success'); setTimeout(()=>location.reload(),800); }
        else{ errBox.textContent=data.msg||'Unknown error.'; errBox.classList.remove('d-none'); this.disabled=false; this.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete'; }
    }).catch(ex=>{ errBox.textContent='Network error: '+ex.message; errBox.classList.remove('d-none'); this.disabled=false; this.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete'; });
});

/* ── View Details (desktop) ── */
document.addEventListener('click', e=>{
    const b = e.target.closest('.btn-viewdetails'); if(!b) return;
    const d = b.dataset;
    document.getElementById('det_uname').textContent  = d.username||'—';
    document.getElementById('det_dept').textContent   = d.dept||'—';
    document.getElementById('det_dest').textContent   = d.dest||'—';
    document.getElementById('det_purp').textContent   = d.purp||'—';
    document.getElementById('det_dates').textContent  = d.ds===d.de ? d.ds : d.ds+' → '+d.de;
    document.getElementById('det_time').textContent   = (d.ts||'--')+' – '+(d.te||'--');
    document.getElementById('det_veh').textContent    = d.vehicle||'—';
    document.getElementById('det_drv').textContent    = d.driver||'—';
    document.getElementById('det_arrived').textContent= d.arrived||'Not recorded';
    document.getElementById('det_status').innerHTML   = getStatusBadge(d.status);
    const ticketEl = document.getElementById('det_ticket');
    if(d.ticket){
        ticketEl.innerHTML = `<span class="ticket-no"><i class="bi bi-ticket-perforated-fill" style="font-size:.7rem"></i>${d.ticket}</span>`;
    } else {
        ticketEl.innerHTML = '<span class="ticket-none"><i class="bi bi-dash" style="font-size:.7rem"></i> Not issued</span>';
    }
    const rw=document.getElementById('det_reason_wrap');
    if(d.reason&&d.status==='Rejected'){document.getElementById('det_reason').textContent=d.reason;rw.style.display='';}
    else rw.style.display='none';
    const cw=document.getElementById('det_cancel_wrap');
    if(d.cancelReason&&d.status==='Cancelled'){document.getElementById('det_cancel').textContent=d.cancelReason;cw.style.display='';}
    else cw.style.display='none';
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
});

function getStatusBadge(s){
    const map={
        'Completed':'<span class="bp bp-completed"><i class="bi bi-check2-all me-1"></i>Completed</span>',
        'OnTrip':'<span class="bp bp-ontrip"><i class="bi bi-truck me-1"></i>On Trip</span>',
        'Approved':'<span class="bp bp-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>',
        'Pending':'<span class="bp bp-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>',
        'Rejected':'<span class="bp bp-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span>',
        'Cancelled':'<span class="bp bp-cancelled"><i class="bi bi-slash-circle me-1"></i>Cancelled</span>',
    };
    return map[s]||s;
}

function showToast(msg,type='success'){
    const c=document.getElementById('toast-wrap'),t=document.createElement('div');
    t.className='toast-item toast-'+type; t.textContent=msg; c.appendChild(t);
    setTimeout(()=>t.remove(),3500);
}

/* ══ MOBILE ══ */
let _mobTdSid = 0;

function mobSchedOpenSheet(id){
    document.getElementById('mobSchedBackdrop').classList.add('open');
    document.getElementById(id).classList.add('open');
    document.body.style.overflow='hidden';
}
function mobSchedCloseAll(){
    document.getElementById('mobSchedBackdrop').classList.remove('open');
    ['mobTripDoneSheet','mobDetailsSheet'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.classList.remove('open');
    });
    document.body.style.overflow='';
}
function mobTripDone(sid,name,dest){
    _mobTdSid=sid;
    document.getElementById('mob_td_info').innerHTML=
        `<div style="display:flex;justify-content:space-between;margin-bottom:3px"><span style="color:#aaa;font-size:.72rem">Requestor</span><strong>${name}</strong></div>
         <div style="display:flex;justify-content:space-between"><span style="color:#aaa;font-size:.72rem">Destination</span><span>${dest}</span></div>`;
    document.getElementById('mob_td_err').style.display='none';
    const now=new Date(),pad=n=>String(n).padStart(2,'0');
    document.getElementById('mob_td_date').value=now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
    document.getElementById('mob_td_time').value=pad(now.getHours())+':'+pad(now.getMinutes());
    const btn=document.getElementById('mob_td_confirm');
    btn.disabled=false; btn.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Complete';
    mobSchedOpenSheet('mobTripDoneSheet');
}
function mobTripDoneConfirm(){
    const sid=_mobTdSid,date=document.getElementById('mob_td_date').value,
          time=document.getElementById('mob_td_time').value,
          err=document.getElementById('mob_td_err'),btn=document.getElementById('mob_td_confirm');
    err.style.display='none';
    if(!date||!time){err.textContent='Please fill in both date and time.';err.style.display='block';return;}
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    fetch('complete_trip.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({schedule_id:sid,arrived_date:date,arrived_time:time}).toString()})
    .then(r=>r.text()).then(raw=>{
        let data; try{data=JSON.parse(raw);}catch(_){
            err.textContent='Server error.';err.style.display='block';
            btn.disabled=false;btn.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Complete';return;
        }
        if(data.ok){mobSchedCloseAll();showToast('Trip completed!','success');setTimeout(()=>location.reload(),800);}
        else{err.textContent=data.msg||'Error.';err.style.display='block';btn.disabled=false;btn.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Complete';}
    });
}
function mobViewDetails(d){
    function fmtT(t){if(!t||t==='--')return '--';const p=t.split(':');let h=parseInt(p[0]),m=p[1]||'00';const ap=h>=12?'PM':'AM';h=h%12||12;return h+':'+m+' '+ap;}
    let html=`<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
        <span class="bp bp-${d.status.toLowerCase()}">${d.status}</span>
        ${d.ticket?`<span style="font-size:.72rem;font-weight:700;color:#92600a;background:#fff8e1;border:1px solid #f6c94e;border-radius:6px;padding:2px 8px;font-family:monospace;white-space:nowrap">${d.ticket}</span>`:''}
    </div>
    <div style="background:#f8f9fb;border-radius:10px;padding:10px 12px;margin-bottom:10px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
            <div><div style="font-size:.65rem;color:#999">Requestor</div><div style="font-weight:700;font-size:.82rem">${d.username}</div></div>
            <div><div style="font-size:.65rem;color:#999">Department</div><div style="font-weight:700;font-size:.82rem">${d.dept||'—'}</div></div>
        </div>
    </div>
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;margin-bottom:10px">
        <div style="font-size:.62rem;color:#c2410c;text-transform:uppercase;margin-bottom:3px">Destination & Purpose</div>
        <div style="font-weight:700;font-size:.88rem">${d.dest}</div>
        <div style="font-size:.78rem;color:#666;margin-top:3px">${d.purp||'—'}</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
        <div style="background:#f8f9fb;border-radius:10px;padding:9px 11px">
            <div style="font-size:.62rem;color:#aaa;text-transform:uppercase;margin-bottom:3px">Date</div>
            <div style="font-weight:700;font-size:.8rem">${d.ds===d.de?d.ds:d.ds+' → '+d.de}</div>
        </div>
        <div style="background:#f8f9fb;border-radius:10px;padding:9px 11px">
            <div style="font-size:.62rem;color:#aaa;text-transform:uppercase;margin-bottom:3px">Time</div>
            <div style="font-weight:700;font-size:.8rem">${fmtT(d.ts)} – ${fmtT(d.te)}</div>
        </div>
        <div style="background:#eff6ff;border-radius:10px;padding:9px 11px">
            <div style="font-size:.62rem;color:#1d4ed8;text-transform:uppercase;margin-bottom:3px">Vehicle</div>
            <div style="font-weight:700;font-size:.78rem">${d.vehicle||'—'}</div>
        </div>
        <div style="background:#eff6ff;border-radius:10px;padding:9px 11px">
            <div style="font-size:.62rem;color:#1d4ed8;text-transform:uppercase;margin-bottom:3px">Driver</div>
            <div style="font-weight:700;font-size:.78rem">${d.driver||'—'}</div>
        </div>
    </div>`;
    if(d.arrived){
        html+=`<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:9px 12px;margin-bottom:10px">
            <div style="font-size:.62rem;color:#166534;text-transform:uppercase;margin-bottom:3px"><i class="bi bi-flag-fill me-1"></i>Arrival</div>
            <div style="font-weight:700;color:#166534">${d.arrived}</div></div>`;
    }
    if(d.status==='Rejected'&&d.reason){
        html+=`<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:9px 12px;margin-bottom:10px">
            <div style="font-size:.62rem;color:#991b1b;text-transform:uppercase;margin-bottom:3px">Rejection Reason</div>
            <div style="font-weight:700;color:#991b1b">${d.reason}</div></div>`;
    }
    if(d.status==='Cancelled'&&d.cancelReason){
        html+=`<div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;margin-bottom:10px">
            <div style="font-size:.62rem;color:#41464b;text-transform:uppercase;margin-bottom:3px">Cancellation</div>
            <div style="font-weight:700;color:#41464b">${d.cancelReason}</div></div>`;
    }
    document.getElementById('mob_det_body').innerHTML=html;
    mobSchedOpenSheet('mobDetailsSheet');
}
function onMobSchedSearch(inp){
    const q=inp.value.toLowerCase().trim();
    document.getElementById('mobSchedSearchClear').style.display=q?'flex':'none';
    document.querySelectorAll('.mob-sched-row').forEach(c=>{
        c.style.display=(!q||c.dataset.search.includes(q))?'':'none';
    });
}
function clearMobSchedSearch(){
    const inp=document.getElementById('mobSchedSearch');inp.value='';inp.focus();onMobSchedSearch(inp);
}
</script>
<!-- ATTACH SIGNED TICKET MODAL -->
<div class="modal fade" id="attachSignedModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#145a32,#1e8449);color:#fff">
    <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i>Attach Signed Trip Ticket</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
  </div>
  <div class="modal-body">
    <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.83rem">
      <i class="bi bi-info-circle me-1"></i>
      Upload the signed trip ticket. Accepted: <strong>PDF, JPG, PNG</strong> (max 10MB).
    </div>
    <div id="attach_current_wrap" style="display:none;margin-bottom:12px">
      <div style="background:#f8f9fa;border-radius:10px;padding:1rem;border:1.5px solid #dee2e6">
        <div style="font-size:.8rem;font-weight:700;color:#0f5132;text-transform:uppercase;margin-bottom:.5rem">
          <i class="bi bi-file-earmark-check me-1"></i>Current Signed Ticket
        </div>
        <a id="attach_current_link" href="#"
           onclick="viewSignedTicket(this.href, this.dataset.ext); return false;"
           data-ext=""
           style="font-size:.85rem;color:#0f5132;font-weight:600">
          <i class="bi bi-eye me-1"></i>View existing signed ticket
        </a>
        <div style="font-size:.72rem;color:#888;margin-top:3px">Uploading a new file will replace this.</div>
      </div>
    </div>
    <div id="attach_drop_zone"
         style="border:2px dashed #b0c4b1;border-radius:12px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:all .2s;background:#f8fdf8"
         onclick="document.getElementById('attach_file_input').click()"
         ondragover="attachDragOver(event)" ondragleave="attachDragLeave(event)" ondrop="attachDrop(event)">
      <i class="bi bi-cloud-upload" style="font-size:2rem;color:#0f5132;display:block;margin-bottom:8px"></i>
      <div style="font-weight:600;color:#333;margin-bottom:4px">Click to browse or drag & drop</div>
      <div style="font-size:.78rem;color:#888">PDF, JPG, PNG — max 10MB</div>
    </div>
    <input type="file" id="attach_file_input" accept=".pdf,.jpg,.jpeg,.png"
           style="display:none" onchange="attachFileChosen(this)">
    <div id="attach_preview" style="display:none;margin-top:12px">
      <div style="background:#f8f9fa;border-radius:10px;padding:1rem;border:1.5px solid #dee2e6;display:flex;align-items:center;gap:10px">
        <i class="bi bi-file-earmark-fill" style="font-size:1.5rem;color:#0f5132"></i>
        <div style="flex:1;min-width:0">
          <div id="attach_filename" style="font-weight:600;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>
          <div id="attach_filesize" style="font-size:.72rem;color:#888"></div>
        </div>
        <button type="button" onclick="attachClearFile()"
                style="background:none;border:none;color:#aaa;cursor:pointer;font-size:1rem">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
    </div>
    <div id="attach_err" class="alert alert-danger py-2 px-3 mt-3 d-none" style="font-size:.83rem"></div>
    <div id="attach_progress_wrap" style="display:none;margin-top:12px">
      <div style="background:#e9ecef;border-radius:10px;height:8px;overflow:hidden">
        <div id="attach_progress_bar"
             style="height:100%;background:linear-gradient(90deg,#0f5132,#198754);width:0%;transition:width .3s;border-radius:10px"></div>
      </div>
      <div style="font-size:.72rem;color:#888;margin-top:4px;text-align:center" id="attach_progress_txt">Uploading…</div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="button" id="attach_upload_btn" class="btn btn-success fw-semibold" disabled
            onclick="attachDoUpload()">
      <i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket
    </button>
  </div>
</div></div></div>

<!-- VIEW SIGNED TICKET MODAL -->
<div class="modal fade" id="viewSignedModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#0550a0,#0a3678);color:#fff">
    <h5 class="modal-title"><i class="bi bi-file-earmark-check-fill me-2"></i>Signed Trip Ticket</h5>
    <div class="d-flex align-items-center gap-2 ms-auto">
      <a id="vsm_download" href="#" download class="btn btn-sm btn-light">
        <i class="bi bi-download me-1"></i>Download
      </a>
      <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
    </div>
  </div>
  <div class="modal-body p-0" style="min-height:500px;background:#f0f0f0;display:flex;align-items:center;justify-content:center">
    <div id="vsm_loading" style="text-align:center;color:#888;padding:3rem">
      <div class="spinner-border text-secondary mb-3"></div>
      <div>Loading…</div>
    </div>
    <iframe id="vsm_iframe" src="" style="display:none;width:100%;height:75vh;border:none"></iframe>
    <img id="vsm_img" src="" alt="Signed Ticket" style="display:none;max-width:100%;max-height:75vh;object-fit:contain;padding:1rem">
  </div>
</div></div></div>
</body>
<script>
/* ══ ATTACH & VIEW SIGNED TICKET ══ */
let _attachSid = 0, _attachFile = null;

document.addEventListener('click', e => {
    const b = e.target.closest('.btn-attach-signed');
    if (!b) return;
    _attachSid  = parseInt(b.dataset.id);
    _attachFile = null;

    document.getElementById('attach_err').classList.add('d-none');
    document.getElementById('attach_preview').style.display = 'none';
    document.getElementById('attach_progress_wrap').style.display = 'none';
    document.getElementById('attach_progress_bar').style.width = '0%';
    document.getElementById('attach_upload_btn').disabled = true;
    document.getElementById('attach_file_input').value = '';
    document.getElementById('attach_drop_zone').style.borderColor = '#b0c4b1';

    // Show current signed ticket link if button carries path
    const currentWrap = document.getElementById('attach_current_wrap');
    const viewBtn = b.closest('td, .sc-actions')?.querySelector('.btn-view-signed');
    if (viewBtn) {
        const link = document.getElementById('attach_current_link');
        link.href = viewBtn.dataset.path;
        link.dataset.ext = viewBtn.dataset.type;
        currentWrap.style.display = '';
    } else {
        currentWrap.style.display = 'none';
    }

    new bootstrap.Modal(document.getElementById('attachSignedModal')).show();
});

document.addEventListener('click', e => {
    const b = e.target.closest('.btn-view-signed');
    if (!b) return;
    viewSignedTicket(b.dataset.path, b.dataset.type);
});

function viewSignedTicket(path, ext) {
    const modal  = document.getElementById('viewSignedModal');
    const iframe = document.getElementById('vsm_iframe');
    const img    = document.getElementById('vsm_img');
    const loader = document.getElementById('vsm_loading');
    const dl     = document.getElementById('vsm_download');

    iframe.style.display = 'none';
    img.style.display    = 'none';
    loader.style.display = 'block';
    loader.innerHTML     = '<div class="spinner-border text-secondary mb-3"></div><div>Loading…</div>';
    iframe.src = '';
    img.src    = '';
    dl.href    = path;

    if (ext === 'pdf') {
        iframe.onload = () => { loader.style.display = 'none'; iframe.style.display = ''; };
        iframe.src = path;
    } else {
        img.onload  = () => { loader.style.display = 'none'; img.style.display = 'block'; };
        img.onerror = () => { loader.innerHTML = '<div class="text-danger"><i class="bi bi-x-circle fs-3 d-block mb-2"></i>Could not load image.</div>'; };
        img.src = path;
    }

    new bootstrap.Modal(modal).show();
}

function attachDragOver(e) {
    e.preventDefault();
    document.getElementById('attach_drop_zone').style.borderColor = '#0f5132';
    document.getElementById('attach_drop_zone').style.background  = '#f0fdf4';
}
function attachDragLeave() {
    document.getElementById('attach_drop_zone').style.borderColor = '#b0c4b1';
    document.getElementById('attach_drop_zone').style.background  = '#f8fdf8';
}
function attachDrop(e) {
    e.preventDefault();
    attachDragLeave();
    if (e.dataTransfer.files[0]) attachSetFile(e.dataTransfer.files[0]);
}
function attachFileChosen(inp) {
    if (inp.files[0]) attachSetFile(inp.files[0]);
}
function attachSetFile(file) {
    const allowed = ['application/pdf','image/jpeg','image/jpg','image/png'];
    const err = document.getElementById('attach_err');
    err.classList.add('d-none');
    if (!allowed.includes(file.type)) {
        err.textContent = 'Invalid file type. Only PDF, JPG, PNG allowed.';
        err.classList.remove('d-none'); return;
    }
    if (file.size > 10 * 1024 * 1024) {
        err.textContent = 'File is too large. Maximum size is 10MB.';
        err.classList.remove('d-none'); return;
    }
    _attachFile = file;
    document.getElementById('attach_filename').textContent = file.name;
    document.getElementById('attach_filesize').textContent = (file.size / 1024).toFixed(1) + ' KB';
    document.getElementById('attach_preview').style.display = '';
    document.getElementById('attach_upload_btn').disabled = false;
}
function attachClearFile() {
    _attachFile = null;
    document.getElementById('attach_file_input').value = '';
    document.getElementById('attach_preview').style.display = 'none';
    document.getElementById('attach_upload_btn').disabled = true;
}
function attachDoUpload() {
    if (!_attachFile || !_attachSid) return;
    const btn  = document.getElementById('attach_upload_btn');
    const err  = document.getElementById('attach_err');
    const prog = document.getElementById('attach_progress_wrap');
    const bar  = document.getElementById('attach_progress_bar');
    const txt  = document.getElementById('attach_progress_txt');
    err.classList.add('d-none');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
    prog.style.display = '';

    const fd = new FormData();
    fd.append('schedule_id', _attachSid);
    fd.append('signed_ticket', _attachFile);

    const xhr = new XMLHttpRequest();
    xhr.upload.onprogress = ev => {
        if (ev.lengthComputable) {
            const pct = Math.round(ev.loaded / ev.total * 100);
            bar.style.width = pct + '%';
            txt.textContent = 'Uploading… ' + pct + '%';
        }
    };
    xhr.onload = () => {
    let data;
    try { 
        data = JSON.parse(xhr.responseText); 
    } catch(_) {
        // Show the raw PHP output so you can see the actual error
        err.innerHTML = '<strong>Server error:</strong><br><pre style="font-size:.72rem;white-space:pre-wrap;max-height:120px;overflow:auto">' 
            + xhr.responseText.substring(0, 800) + '</pre>';
        err.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket';
        prog.style.display = 'none'; 
        return;
    }
    if (data.ok) {
        bootstrap.Modal.getInstance(document.getElementById('attachSignedModal')).hide();
        showToast('Signed ticket uploaded!', 'success');
        setTimeout(() => location.reload(), 800);
    } else {
        err.textContent = data.msg || 'Upload failed.';
        err.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket';
        prog.style.display = 'none';
    }
};
    xhr.onerror = () => {
        err.textContent = 'Network error. Please try again.';
        err.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket';
        prog.style.display = 'none';
    };
    xhr.open('POST', '../admin/upload_signed_ticket.php');
    xhr.send(fd);
}
</script>
</html>