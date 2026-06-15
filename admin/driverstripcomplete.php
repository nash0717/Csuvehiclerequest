<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Admin auth check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /csuweb/login.php?error=unauthorized"); exit;
}

date_default_timezone_set('Asia/Manila');

/* ─── Format helpers — defined FIRST ─── */
function fd($d) { return $d ? date('M j, Y', strtotime($d)) : '—'; }
function ft($t) { return ($t && $t !== '--') ? date('g:i A', strtotime($t)) : '—'; }
function fh($h) {
    $h = max(0, (int)$h);
    if ($h < 1)  return '<1h';
    if ($h < 24) return $h . 'h';
    return floor($h/24) . 'd ' . ($h%24) . 'h';
}

/* ─── Trip ticket number helper ─── */
function getTicketNo(PDO $pdo, int $id, string $ds, string $officeName): string {
    // First check if already stored and valid
    $stored = $pdo->prepare("SELECT trip_ticket_no FROM schedules WHERE schedule_id = ?");
    $stored->execute([$id]);
    $row = $stored->fetch();
    $storedTicket = $row['trip_ticket_no'] ?? '';

    if (!empty($storedTicket) && preg_match('/\/\d{4}$/', $storedTicket)) {
        return $storedTicket;
    }

    // Determine prefix from office name
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

    // Generate sequence using RANK()
    $seqStmt = $pdo->prepare("
        SELECT seq FROM (
            SELECT schedule_id,
                   RANK() OVER (
                       PARTITION BY MONTH(date_start), YEAR(date_start)
                       ORDER BY schedule_id ASC
                   ) AS seq
            FROM schedules
            WHERE MONTH(date_start) = ?
              AND YEAR(date_start)  = ?
        ) ranked
        WHERE schedule_id = ?
    ");
    $seqStmt->execute([$m, $y, $id]);
    $seq = (int)$seqStmt->fetchColumn();

    $ticketNo = $prefix . '-' . $m . '/' . $y . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // Save it permanently
    $pdo->prepare("UPDATE schedules SET trip_ticket_no = ? WHERE schedule_id = ?")
        ->execute([$ticketNo, $id]);

    return $ticketNo;
}

/* ── Current admin + office ── */
$cu = $pdo->prepare("SELECT u.*, o.office_id AS u_office_id, o.office_name FROM users u LEFT JOIN offices o ON u.office_id=o.office_id WHERE u.user_id=?");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();
$myOfficeId = (int)($me['u_office_id'] ?? 0);

/* ── Office signatory (Approved by) ── */
$sigStmt = $pdo->prepare("SELECT signatory_name, signatory_title FROM office_signatories WHERE office_id = ? LIMIT 1");
$sigStmt->execute([$myOfficeId]);
$officeSig = $sigStmt->fetch();

/* ── Unread notification count ── */
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$_SESSION['user_id'], $myOfficeId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/* ── Filter parameters ── */
$filter     = $_GET['filter']       ?? 'month';
$driverId   = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$customDate = $_GET['custom_date']  ?? date('Y-m-d');
$customYear = $_GET['custom_year']  ?? date('Y');

/* ── Date range based on filter ── */
switch ($filter) {
    case 'day':
        $dateFrom = $customDate;
        $dateTo   = $customDate;
        $label    = date('F j, Y', strtotime($customDate));
        break;
    case 'week':
        $monday   = date('Y-m-d', strtotime('monday this week', strtotime($customDate)));
        $sunday   = date('Y-m-d', strtotime('sunday this week', strtotime($customDate)));
        $dateFrom = $monday;
        $dateTo   = $sunday;
        $label    = date('M j', strtotime($monday)) . ' – ' . date('M j, Y', strtotime($sunday));
        break;
    case 'year':
        $dateFrom = $customYear . '-01-01';
        $dateTo   = $customYear . '-12-31';
        $label    = $customYear;
        break;
    case 'month':
    default:
        $filter      = 'month';
        $month       = $_GET['custom_month'] ?? date('Y-m');
        $dateFrom    = $month . '-01';
        $dateTo      = date('Y-m-t', strtotime($dateFrom));
        $label       = date('F Y', strtotime($dateFrom));
        $customMonth = $month;
        break;
}
$customMonth = $customMonth ?? date('Y-m');

/* ── Fetch all drivers for this office (for dropdown) ── */
$driversStmt = $pdo->prepare("
    SELECT d.driver_id, d.driver_name, d.license_no,
           COUNT(s.schedule_id) AS total_completed
    FROM drivers d
    LEFT JOIN schedules s ON s.driver_id = d.driver_id
        AND s.status    = 'Completed'
        AND s.office_id = ?
        AND s.date_end BETWEEN ? AND ?
    WHERE (d.office_id = ? OR d.driver_scope = 'Both')
    GROUP BY d.driver_id
    ORDER BY d.driver_name ASC
");
$driversStmt->execute([$myOfficeId, $dateFrom, $dateTo, $myOfficeId]);
$allDrivers = $driversStmt->fetchAll();

/* ── Fetch completed trips — SCOPED to current office only ── */
$params = [$dateFrom, $dateTo, $myOfficeId];
$driverFilter = '';
if ($driverId > 0) {
    $driverFilter = ' AND s.driver_id = ?';
    $params[] = $driverId;
}

$tripsStmt = $pdo->prepare("
    SELECT s.*,
           s.trip_ticket_no,
           d.driver_name, d.license_no,
           v.brand, v.model, v.plate_number,
           u.username AS requestor_name,
           o.office_name,
           dep.dept_name,
           TIMESTAMPDIFF(HOUR,
               CONCAT(s.date_start,' ',COALESCE(s.time_start,'00:00:00')),
               CONCAT(s.date_end,' ',COALESCE(s.time_end,'23:59:00'))
           ) AS duration_hours
    FROM schedules s
    JOIN drivers d ON s.driver_id = d.driver_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN offices o ON s.office_id = o.office_id
    LEFT JOIN departments dep ON s.department_id = dep.dept_id
    WHERE s.status = 'Completed'
      AND s.date_end BETWEEN ? AND ?
      AND s.office_id = ?
      {$driverFilter}
    ORDER BY s.date_end DESC, s.schedule_id DESC
");
$tripsStmt->execute($params);
$trips = $tripsStmt->fetchAll();

/* ── Resolve trip ticket numbers for all trips ── */
foreach ($trips as &$t) {
    $t['trip_ticket_no'] = getTicketNo($pdo, (int)$t['schedule_id'], $t['date_start'], $t['office_name'] ?? '');
}
unset($t);

/* ── Summary stats ── */
$totalTrips         = count($trips);
$totalDrivers       = count(array_unique(array_column($trips, 'driver_id')));
$totalHours         = array_sum(array_column($trips, 'duration_hours'));
$uniqueDestinations = count(array_unique(array_column($trips, 'destination')));

/* ── Per-driver summary ── */
$driverSummary = [];
foreach ($trips as $t) {
    $did = $t['driver_id'];
    if (!isset($driverSummary[$did])) {
        $driverSummary[$did] = ['name' => $t['driver_name'], 'count' => 0, 'hours' => 0];
    }
    $driverSummary[$did]['count']++;
    $driverSummary[$did]['hours'] += max(0, (int)$t['duration_hours']);
}
uasort($driverSummary, fn($a, $b) => $b['count'] - $a['count']);

/* ── Selected driver name (for signatory) ── */
$selectedDriverName = '';
$selectedDriverLicense = '';
if ($driverId > 0) {
    foreach ($allDrivers as $d) {
        if ((int)$d['driver_id'] === $driverId) {
            $selectedDriverName    = $d['driver_name'];
            $selectedDriverLicense = $d['license_no'] ?? '';
            break;
        }
    }
    if (!$selectedDriverName && !empty($trips)) {
        $selectedDriverName = $trips[0]['driver_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Driver Completed Trips – CSU VSS Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --maroon:       #800000;
    --maroon-dark:  #5a0000;
    --maroon-light: #fdecea;
    --maroon-mid:   #f0e5e5;
    --surface:      #fff;
    --bg:           #f5f0f0;
    --border:       #eedede;
    --text:         #1a1a1a;
    --muted:        #888;
    --radius:       12px;
    --radius-lg:    18px;
    --shadow:       0 2px 16px rgba(128,0,0,0.08);
    --shadow-lg:    0 8px 32px rgba(128,0,0,0.13);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body{background:#f5f0f0;font-family:'Segoe UI',sans-serif}
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
.topbar{background:#fff;border-bottom:1px solid #e8dede;padding:.7rem 1.5rem;margin-left:240px;position:sticky;top:0;z-index:99;display:flex;align-items:center;justify-content:space-between}
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}
.main-content{margin-left:240px;padding:1.5rem}
.page-hdr { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 1.4rem; }
.page-hdr h4 { font-weight: 800; font-size: 1.3rem; color: var(--text); margin: 0; }
.page-hdr p { color: var(--muted); font-size: .8rem; margin: 3px 0 0; }
.filter-card { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow); border: 1px solid var(--border); padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
.filter-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.filter-period-tabs { display: flex; gap: 4px; background: var(--bg); border-radius: 10px; padding: 3px; }
.fptab { padding: 6px 16px; border-radius: 8px; font-size: .78rem; font-weight: 700; text-decoration: none; color: var(--muted); border: none; background: transparent; cursor: pointer; transition: all .15s; font-family: inherit; }
.fptab.active,.fptab:hover { background: var(--maroon); color: #fff; }
.filter-input { border: 1.5px solid var(--border); border-radius: 9px; padding: 7px 12px; font-size: .84rem; color: #444; background: var(--bg); font-family: inherit; transition: border-color .15s; }
.filter-input:focus { outline: none; border-color: var(--maroon); background: #fff; }
.btn-apply { background: var(--maroon); color: #fff; border: none; border-radius: 9px; padding: 7px 18px; font-size: .84rem; font-weight: 700; cursor: pointer; font-family: inherit; display: flex; align-items: center; gap: 6px; transition: background .15s; }
.btn-apply:hover { background: var(--maroon-dark); }
.driver-select { border: 1.5px solid var(--border); border-radius: 9px; padding: 7px 12px; font-size: .84rem; color: #444; background: var(--bg); font-family: inherit; min-width: 200px; }
.stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 1.25rem; }
.stat-card { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow); padding: 1rem 1.2rem; display: flex; align-items: center; gap: 14px; transition: transform .15s,box-shadow .15s; }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
.stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
.stat-val { font-size: 1.6rem; font-weight: 800; color: var(--text); line-height: 1; }
.stat-lbl { font-size: .68rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; font-weight: 700; margin-top: 3px; }
.section-card { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow); border: 1px solid var(--border); overflow: hidden; margin-bottom: 1.25rem; }
.section-hdr { padding: .9rem 1.3rem; border-bottom: 1px solid var(--maroon-mid); font-weight: 800; font-size: .88rem; color: var(--maroon); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; background: var(--maroon-light); }
.driver-lb { display: grid; grid-template-columns: repeat(auto-fill,minmax(200px,1fr)); gap: 10px; padding: 1rem 1.2rem; }
.driver-lb-card { border-radius: 12px; border: 1.5px solid var(--border); padding: .85rem 1rem; display: flex; align-items: center; gap: 12px; transition: all .15s; cursor: pointer; text-decoration: none; }
.driver-lb-card:hover { border-color: var(--maroon); background: var(--maroon-light); transform: translateY(-1px); }
.driver-lb-card.top1 { border-color: #f59e0b; background: #fffbeb; }
.driver-lb-card.top2 { border-color: #94a3b8; background: #f8fafc; }
.driver-lb-card.top3 { border-color: #b45309; background: #fff7ed; }
.lb-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--maroon); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .88rem; font-weight: 800; flex-shrink: 0; }
.lb-name { font-weight: 700; font-size: .85rem; color: var(--text); }
.lb-count { font-size: .72rem; color: var(--muted); margin-top: 1px; }
.lb-rank { font-size: 1.1rem; margin-left: auto; }
.table { margin: 0; }
.table thead th { background: var(--maroon-light); color: var(--maroon); font-size: .71rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; border-bottom: 2px solid var(--maroon-mid); padding: .7rem 1rem; white-space: nowrap; }
.table tbody td { padding: .72rem 1rem; font-size: .84rem; color: #3a3a3a; vertical-align: middle; border-color: #fdf5f5; }
.table tbody tr { transition: background .1s; }
.table tbody tr:hover { background: #fdf8f8; }
.driver-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--maroon-light); color: var(--maroon); padding: 4px 10px; border-radius: 20px; font-size: .76rem; font-weight: 700; }
.driver-chip .dc-av { width: 20px; height: 20px; border-radius: 50%; background: var(--maroon); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .6rem; font-weight: 800; flex-shrink: 0; }
.duration-chip { background: #e8f4fd; color: #0c5480; padding: 3px 9px; border-radius: 20px; font-size: .72rem; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.dest-cell { font-weight: 600; }
.dest-cell i { color: var(--maroon); font-size: .73rem; }
.plate-chip { background: #f3f4f6; color: #374151; padding: 2px 8px; border-radius: 6px; font-size: .72rem; font-weight: 700; font-family: monospace; letter-spacing: .04em; }
.period-pill { display: inline-flex; align-items: center; gap: 6px; background: var(--maroon); color: #fff; padding: 5px 15px; border-radius: 20px; font-size: .78rem; font-weight: 700; }
.empty-state { text-align: center; padding: 3.5rem 1rem; }
.empty-state i { font-size: 3rem; display: block; margin-bottom: .75rem; color: #e0d0d0; }
.empty-state p { font-size: .85rem; color: #aaa; margin: 0; }
.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 199; }
.sidebar-overlay.show { display: block; }
.hamburger-btn { display: none; background: none; border: none; cursor: pointer; padding: 4px 8px; color: var(--maroon); font-size: 1.4rem; align-items: center; justify-content: center; line-height: 1; }
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); transition: transform 0.25s ease; z-index: 200; }
    .sidebar.open { transform: translateX(0); }
    .topbar, .main-content { margin-left: 0 !important; }
    .hamburger-btn { display: flex !important; }
    .stats-row { grid-template-columns: 1fr 1fr !important; }
    .filter-row { flex-direction: column; align-items: stretch !important; }
    .filter-period-tabs { justify-content: center; }
    .driver-select, .filter-input, .btn-apply { width: 100%; justify-content: center; }
    .driver-lb { grid-template-columns: 1fr !important; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .page-hdr { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .stats-row { grid-template-columns: 1fr !important; }
    .main-content { padding: 1rem; }
    .topbar { padding: .6rem 1rem; }
    .stat-val { font-size: 1.3rem; }
}
@media print {
    @page { size: legal landscape; margin: 0; }
    .sidebar, .topbar, .filter-card, .no-print, .stats-row, .driver-lb, .page-hdr, .period-pill { display: none !important; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    body { background: #fff !important; font-family: 'Plus Jakarta Sans','Segoe UI',sans-serif; font-size: 9pt; color: #000; padding: 12mm 14mm 14mm 14mm !important; }
    .main-content { margin-left: 0 !important; padding: 0 !important; }
    .print-header { position: relative; display: flex !important; align-items: center; justify-content: center; border-bottom: 3px solid #800000; padding-bottom: 9px; margin-bottom: 10px; min-height: 70px; }
    .print-header-center { display: flex; align-items: center; justify-content: center; gap: 12px; }
    .print-header-center img { width: 52px; height: 52px; object-fit: contain; flex-shrink: 0; }
    .print-header-center-text { text-align: center; }
    .print-header-center-text .ph-title { font-size: 13pt; font-weight: 800; color: #800000; letter-spacing: .02em; line-height: 1.2; margin: 0; }
    .print-header-center-text .ph-sub { font-size: 8.5pt; color: #555; margin: 2px 0 0; font-weight: 500; }
    .print-header-center-text .ph-period { display: inline-block; margin-top: 5px; background: #800000 !important; color: #fff !important; font-size: 7.5pt; font-weight: 700; padding: 2px 12px; border-radius: 20px; letter-spacing: .04em; }
    .print-header-right { position: absolute; right: 0; top: 0; text-align: right; font-size: 7.5pt; color: #555; line-height: 1.75; }
    .print-header-right strong { color: #800000; }
    .print-summary { display: flex !important; gap: 10px; margin-bottom: 12px; }
    .print-summary-item { flex: 1; border: 1.5px solid #800000; border-radius: 6px; padding: 6px 10px; text-align: center; }
    .print-summary-item .ps-val { font-size: 15pt; font-weight: 800; color: #800000; line-height: 1; }
    .print-summary-item .ps-lbl { font-size: 6.5pt; color: #666; text-transform: uppercase; letter-spacing: .07em; font-weight: 700; margin-top: 3px; }
    .section-card { box-shadow: none !important; border: 1.5px solid #c0a0a0 !important; border-radius: 6px !important; overflow: visible !important; page-break-inside: avoid; margin-bottom: 0 !important; }
    .section-hdr { background: #800000 !important; color: #fff !important; padding: 7px 10px !important; font-size: 9pt !important; border-radius: 4px 4px 0 0 !important; display: flex !important; align-items: center !important; justify-content: space-between !important; }
    .section-hdr * { color: #fff !important; }
    .table-responsive { overflow: visible !important; }
    .table { width: 100% !important; border-collapse: collapse !important; font-size: 8pt !important; }
    .table thead th { background: #fdecea !important; color: #800000 !important; border: 1px solid #e0c0c0 !important; padding: 5px 7px !important; font-size: 7pt !important; font-weight: 800 !important; white-space: nowrap; text-transform: uppercase; letter-spacing: .05em; }
    .table tbody td { border: 1px solid #eedede !important; padding: 5px 7px !important; font-size: 8pt !important; color: #222 !important; vertical-align: middle !important; }
    .table tbody tr:nth-child(even) td { background: #fdf8f8 !important; }
    .table tbody tr { page-break-inside: avoid; }
    .driver-chip { background: #fdecea !important; color: #800000 !important; font-size: 7pt !important; padding: 2px 6px !important; border-radius: 10px !important; display: inline-flex; align-items: center; gap: 4px; }
    .dc-av { background: #800000 !important; color: #fff !important; width: 14px !important; height: 14px !important; font-size: 6pt !important; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .duration-chip { background: #e8f4fd !important; color: #0c5480 !important; font-size: 7pt !important; padding: 1px 5px !important; border-radius: 8px !important; }
    .plate-chip { background: #f3f4f6 !important; color: #374151 !important; font-size: 6.5pt !important; padding: 1px 4px !important; font-family: monospace; border-radius: 4px !important; }
    .ticket-chip { background: #fff8e1 !important; color: #92600a !important; border: 1pt solid #f6c94e !important; font-size: 6.5pt !important; padding: 1px 5px !important; border-radius: 4px !important; font-family: monospace; font-weight: 700 !important; }
    .ticket-chip i { display: none; }
    .dest-cell i { display: none; }
    .bi-calendar3 { display: none; }
    .print-footer { display: flex !important; justify-content: space-between; align-items: flex-end; margin-top: 24px; padding-top: 8px; border-top: 1.5px solid #800000; font-size: 7pt; color: #555; }
    .print-footer .footer-meta { line-height: 1.7; }
    .print-footer .footer-meta .meta-sys  { font-weight: 700; color: #800000; font-size: 7.5pt; }
    .print-footer .footer-meta .meta-gen  { font-size: 7pt; color: #444; }
    .print-footer .footer-meta .meta-note { font-size: 6pt; color: #aaa; margin-top: 2px; }
    .sig-row { display: flex; gap: 52px; align-items: flex-end; }
    .sig-block { text-align: center; min-width: 160px; }
    .sig-name { font-weight: 800; font-size: 8.5pt; color: #1a1a1a; white-space: nowrap; padding-bottom: 4px; border-bottom: 1.5px solid #333; display: block; margin-top: 32px; }
    .sig-blank { display: block; border-bottom: 1.5px solid #333; margin-top: 32px; padding-bottom: 4px; min-height: 14pt; }
    .sig-title { font-size: 7pt; color: #555; margin-top: 3px; display: block; }
}
@media screen { .print-header, .print-summary, .print-footer { display: none; } }
@media (max-width: 992px) { .stats-row { grid-template-columns: 1fr 1fr; } }
.notif-badge-pill { background: #e24b4a; color: #fff; font-size: .62rem; font-weight: 700; min-width: 17px; height: 17px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; margin-left: auto; }
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

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
        <a class="nav-link active" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i>Driver Trip Records</a>
        <a class="nav-link" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link" href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
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
            <span style="display:flex;align-items:center;gap:10px"><i class="bi bi-bell"></i>Notifications</span>
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

<div class="topbar">
  <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
    <i class="bi bi-list"></i>
  </button>
  <div class="topbar-title">
    <i class="bi bi-flag-fill" style="color:var(--maroon)"></i>
    Driver Completed Trips
  </div>
  <div style="display:flex;align-items:center;gap:10px;">
    <button onclick="triggerPrint()" class="no-print"
      style="background:var(--maroon-light);color:var(--maroon);border:1.5px solid var(--border);border-radius:9px;padding:6px 14px;font-size:.8rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;font-family:inherit;">
      <i class="bi bi-printer"></i> Print
    </button>
    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    <div>
      <div style="font-weight:700;color:#333;font-size:.84rem"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></div>
      <div style="font-size:.7rem;color:var(--maroon);font-weight:700">Admin</div>
    </div>
  </div>
</div>

<div class="main-content">

  <div class="page-hdr no-print">
    <div>
      <h4><i class="bi bi-flag-fill me-2" style="color:var(--maroon);font-size:1.1rem"></i>Driver Trip Records</h4>
      <p>Completed trips per driver — filtered by period &nbsp;|&nbsp; Office: <strong><?= htmlspecialchars($me['office_name'] ?? '—') ?></strong></p>
    </div>
    <span class="period-pill"><i class="bi bi-calendar3"></i> <?= htmlspecialchars($label) ?></span>
  </div>

  <div class="filter-card no-print">
    <form method="GET" action="">
      <div class="filter-row">
        <div class="filter-period-tabs">
          <?php foreach (['day' => 'Day','week' => 'Week','month' => 'Month','year' => 'Year'] as $k => $v): ?>
          <button type="submit" name="filter" value="<?= $k ?>"
                  class="fptab <?= $filter === $k ? 'active' : '' ?>"
                  formaction="?filter=<?= $k ?>&driver_id=<?= $driverId ?>&custom_date=<?= $customDate ?>&custom_month=<?= $customMonth ?>&custom_year=<?= $customYear ?>">
            <?= $v ?>
          </button>
          <?php endforeach; ?>
        </div>
        <?php if ($filter === 'day' || $filter === 'week'): ?>
        <input type="date" name="custom_date" class="filter-input" value="<?= htmlspecialchars($customDate) ?>">
        <?php elseif ($filter === 'month'): ?>
        <input type="month" name="custom_month" class="filter-input" value="<?= htmlspecialchars($customMonth) ?>">
        <?php elseif ($filter === 'year'): ?>
        <select name="custom_year" class="filter-input">
          <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
          <option value="<?= $y ?>" <?= $customYear == $y ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
        <?php endif; ?>
        <select name="driver_id" class="driver-select">
          <option value="0">All Drivers</option>
          <?php foreach ($allDrivers as $d): ?>
          <option value="<?= $d['driver_id'] ?>" <?= $driverId === (int)$d['driver_id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($d['driver_name']) ?> (<?= $d['total_completed'] ?> trips)
          </option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <button type="submit" class="btn-apply"><i class="bi bi-funnel-fill"></i> Apply</button>
        <?php if ($driverId || $filter !== 'month'): ?>
        <a href="driverstripcomplete.php"
           style="background:#e9ecef;color:#555;border:none;border-radius:9px;padding:7px 14px;font-size:.84rem;font-weight:600;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:5px;">
          <i class="bi bi-x"></i> Reset
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="stats-row no-print">
    <div class="stat-card">
      <div class="stat-icon" style="background:#d1e7dd;color:#0f5132"><i class="bi bi-flag-fill"></i></div>
      <div><div class="stat-val"><?= $totalTrips ?></div><div class="stat-lbl">Completed Trips</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:var(--maroon-light);color:var(--maroon)"><i class="bi bi-person-badge-fill"></i></div>
      <div><div class="stat-val"><?= $totalDrivers ?></div><div class="stat-lbl">Active Drivers</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#e8f4fd;color:#0c5480"><i class="bi bi-clock-history"></i></div>
      <div><div class="stat-val"><?= fh($totalHours) ?></div><div class="stat-lbl">Total Duration</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#f3e8ff;color:#6b21a8"><i class="bi bi-geo-alt-fill"></i></div>
      <div><div class="stat-val"><?= $uniqueDestinations ?></div><div class="stat-lbl">Destinations</div></div>
    </div>
  </div>

  <?php if ($driverId === 0 && !empty($driverSummary)): ?>
  <div class="section-card no-print">
    <div class="section-hdr">
      <span><i class="bi bi-trophy-fill me-2" style="color:#f59e0b"></i>Driver Leaderboard — <?= htmlspecialchars($label) ?></span>
      <span style="font-size:.72rem;color:var(--muted);font-weight:600">Ranked by trips completed</span>
    </div>
    <div class="driver-lb">
      <?php $rank = 0; foreach ($driverSummary as $did => $ds): $rank++;
        $rankClass = $rank === 1 ? 'top1' : ($rank === 2 ? 'top2' : ($rank === 3 ? 'top3' : ''));
        $rankEmoji = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : '#'.$rank));
        $url = '?filter='.urlencode($filter).'&driver_id='.$did.'&custom_date='.urlencode($customDate).'&custom_month='.urlencode($customMonth).'&custom_year='.urlencode($customYear);
      ?>
      <a href="<?= $url ?>" class="driver-lb-card <?= $rankClass ?>">
        <div class="lb-avatar"><?= strtoupper(substr($ds['name'], 0, 1)) ?></div>
        <div style="flex:1;min-width:0">
          <div class="lb-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($ds['name']) ?></div>
          <div class="lb-count"><?= $ds['count'] ?> trip<?= $ds['count'] !== 1 ? 's' : '' ?> · <?= fh($ds['hours']) ?></div>
        </div>
        <div class="lb-rank"><?= $rankEmoji ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- PRINT HEADER -->
  <div class="print-header">
    <div class="print-header-center">
      <img src="../image/Csu.png" alt="CSU Logo">
      <div class="print-header-center-text">
        <p class="ph-title">Cagayan State University</p>
        <p class="ph-sub">Vehicle Scheduling System &mdash; Driver Completed Trip Records</p>
        <span class="ph-period"><?= htmlspecialchars($label) ?></span>
      </div>
      <img src="../image/Auxi.png" alt="Auxi Logo">
    </div>
    <div class="print-header-right">
      <strong>Office:</strong> <?= htmlspecialchars($me['office_name'] ?? '—') ?><br>
      <strong>Printed by:</strong> <?= htmlspecialchars($_SESSION['username'] ?? '—') ?><br>
      <strong>Date printed:</strong> <?= date('F j, Y g:i A') ?><br>
      <strong>Period:</strong> <?= ucfirst($filter) ?>
      <?php if ($driverId > 0 && $selectedDriverName): ?>
      <br><strong>Driver:</strong> <?= htmlspecialchars($selectedDriverName) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- PRINT SUMMARY -->
  <div class="print-summary">
    <div class="print-summary-item">
      <div class="ps-val"><?= $totalTrips ?></div>
      <div class="ps-lbl">Completed Trips</div>
    </div>
    <div class="print-summary-item">
      <div class="ps-val"><?= fh($totalHours) ?></div>
      <div class="ps-lbl">Total Duration</div>
    </div>
    <div class="print-summary-item">
      <div class="ps-val"><?= $uniqueDestinations ?></div>
      <div class="ps-lbl">Destinations</div>
    </div>
  </div>

  <!-- TRIPS TABLE -->
  <div class="section-card">
    <div class="section-hdr">
      <span>
        <i class="bi bi-table me-2"></i>
        <?php if ($driverId > 0 && $selectedDriverName): ?>
          Trips by <strong><?= htmlspecialchars($selectedDriverName) ?></strong>
        <?php else: ?>
          All Completed Trips — <?= htmlspecialchars($me['office_name'] ?? '') ?>
        <?php endif; ?>
        &mdash; <?= htmlspecialchars($label) ?>
      </span>
      <span style="font-size:.78rem;font-weight:600;opacity:.85">
        <?= $totalTrips ?> record<?= $totalTrips !== 1 ? 's' : '' ?>
      </span>
    </div>

    <?php if (empty($trips)): ?>
    <div class="empty-state">
      <i class="bi bi-flag"></i>
      <p style="font-weight:700;color:#c0a0a0;margin-bottom:.3rem">No completed trips found</p>
      <p>No trips were completed in the selected period<?= $driverId ? ' for this driver' : '' ?>.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>Request #</th>
            <th>Trip Ticket #</th>
            <th>Driver</th>
            <th>Vehicle</th>
            <th>Destination</th>
            <th>Purpose</th>
            <th>Date</th>
            <th>Time</th>
            <th>Duration</th>
            <th>Requestor</th>
            <th>Office</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($trips as $t):
            $hours = max(0, (int)$t['duration_hours']);
          ?>
          <tr>
            <td style="font-weight:800;color:var(--maroon);white-space:nowrap;">
              REQ-<?= str_pad($t['schedule_id'], 4, '0', STR_PAD_LEFT) ?>
            </td>
            <td style="white-space:nowrap;">
              <?php if (!empty($t['trip_ticket_no'])): ?>
              <?php
$hasSignedTicket = !empty($t['signed_ticket_path']);
$signedExt = $hasSignedTicket ? strtolower(pathinfo($t['signed_ticket_path'], PATHINFO_EXTENSION)) : '';
?>
<button type="button"
    class="ticket-chip <?= $hasSignedTicket ? 'btn-view-signed-trip' : '' ?>"
    style="display:inline-flex;align-items:center;gap:5px;background:#fff8e1;color:#92600a;border:1.5px solid #f6c94e;border-radius:7px;padding:3px 9px;font-size:.76rem;font-weight:700;font-family:monospace;letter-spacing:.04em;cursor:<?= $hasSignedTicket ? 'pointer' : 'default' ?>;transition:all .15s;"
    <?php if ($hasSignedTicket): ?>
        data-path="../<?= htmlspecialchars($t['signed_ticket_path'], ENT_QUOTES) ?>"
        data-ext="<?= $signedExt ?>"
        data-ticket="<?= htmlspecialchars($t['trip_ticket_no'], ENT_QUOTES) ?>"
        title="View signed ticket"
    <?php else: ?>
        title="No signed ticket attached"
    <?php endif; ?>>
    <i class="bi bi-ticket-perforated-fill" style="font-size:.72rem"></i>
    <?= htmlspecialchars($t['trip_ticket_no']) ?>
    <?php if ($hasSignedTicket): ?>
        <i class="bi bi-paperclip" style="font-size:.68rem;opacity:.7"></i>
    <?php endif; ?>
</button>
              <?php else: ?>
              <span style="color:#ccc;font-style:italic;font-size:.78rem">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="driver-chip">
                <div class="dc-av"><?= strtoupper(substr($t['driver_name'], 0, 1)) ?></div>
                <?= htmlspecialchars($t['driver_name']) ?>
              </div>
            </td>
            <td>
              <?php if ($t['brand']): ?>
              <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($t['brand'].' '.$t['model']) ?></div>
              <div class="plate-chip mt-1"><?= htmlspecialchars($t['plate_number'] ?? '—') ?></div>
              <?php else: ?>
              <span style="color:#ccc;font-style:italic">N/A</span>
              <?php endif; ?>
            </td>
            <td class="dest-cell">
              <i class="bi bi-geo-alt-fill"></i>
              <?= htmlspecialchars($t['destination']) ?>
            </td>
            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:.8rem"
                title="<?= htmlspecialchars($t['purpose']) ?>">
              <?= htmlspecialchars($t['purpose']) ?>
            </td>
            <td style="white-space:nowrap;font-size:.81rem">
              <i class="bi bi-calendar3" style="color:var(--maroon);font-size:.72rem"></i>
              <?= fd($t['date_start']) ?>
              <?php if ($t['date_end'] && $t['date_end'] !== $t['date_start']): ?>
              <div style="color:#bbb;font-size:.74rem;margin-top:1px">→ <?= fd($t['date_end']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:.79rem;color:#666;white-space:nowrap">
              <?= ft($t['time_start']) ?>
              <?php if ($t['time_end']): ?>
              <div style="color:#bbb;font-size:.74rem;margin-top:1px">→ <?= ft($t['time_end']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="duration-chip">
                <i class="bi bi-clock" style="font-size:.65rem"></i>
                <?= fh($hours) ?>
              </span>
            </td>
            <td style="font-size:.82rem"><?= htmlspecialchars($t['requestor_name'] ?? '—') ?></td>
            <td style="font-size:.79rem;color:var(--muted)">
              <?= htmlspecialchars($t['office_name'] ?? '—') ?>
              <?php if (!empty($t['dept_name'])): ?>
              <div style="font-size:.72rem;margin-top:1px"><?= htmlspecialchars($t['dept_name']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- PRINT FOOTER -->
  <div class="print-footer">
    <div class="footer-meta">
      <div class="meta-sys">CSU Vehicle Scheduling System &mdash; <?= htmlspecialchars($me['office_name'] ?? '') ?></div>
      <div class="meta-gen">Generated: <?= date('F j, Y \a\t g:i A') ?></div>
      <div class="meta-note">This document is system-generated and valid without a handwritten signature unless otherwise required.</div>
    </div>
    <div class="sig-row">
      <?php if ($driverId > 0 && $selectedDriverName): ?>
      <div class="sig-block">
        <span class="sig-name"><?= htmlspecialchars($selectedDriverName) ?></span>
        <span class="sig-title">Driver<?= $selectedDriverLicense ? ' | Lic. No. '.htmlspecialchars($selectedDriverLicense) : '' ?></span>
      </div>
      <?php endif; ?>
      <div class="sig-block">
        <?php if (!empty($officeSig['signatory_name'])): ?>
        <span class="sig-name"><?= htmlspecialchars($officeSig['signatory_name']) ?></span>
        <span class="sig-title"><?= htmlspecialchars($officeSig['signatory_title'] ?? 'Approved by') ?></span>
        <?php else: ?>
        <span class="sig-blank"></span>
        <span class="sig-title">Approved by</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.fptab').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelector('input[name="filter"]').value = this.value || '';
    });
});
function triggerPrint() {
    const orig = document.title;
    document.title = ' ';
    setTimeout(() => { window.print(); setTimeout(() => { document.title = orig; }, 600); }, 60);
}
function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
</script>
<!-- VIEW SIGNED TICKET MODAL -->
<div class="modal fade" id="viewSignedModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#800000,#6b0000);color:#fff;padding:.85rem 1.25rem">
    <div style="display:flex;align-items:center;gap:10px">
      <i class="bi bi-file-earmark-check-fill" style="font-size:1.1rem"></i>
      <div>
        <div style="font-weight:700;font-size:.95rem">Signed Trip Ticket</div>
        <div style="font-size:.72rem;opacity:.75" id="vsm_ticket_label">—</div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-left:auto">
      <a id="vsm_download" href="#" download
         style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:5px 13px;font-size:.78rem;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:5px">
        <i class="bi bi-download"></i> Download
      </a>
      <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
    </div>
  </div>
  <div class="modal-body p-0"
       style="min-height:520px;background:#e8e8e8;display:flex;align-items:center;justify-content:center;position:relative">
    <div id="vsm_loading" style="text-align:center;color:#888;padding:3rem">
      <div class="spinner-border text-secondary mb-3" style="width:2.5rem;height:2.5rem"></div>
      <div style="font-size:.85rem">Loading signed ticket…</div>
    </div>
    <iframe id="vsm_iframe" src=""
            style="display:none;width:100%;height:78vh;border:none"></iframe>
    <img id="vsm_img" src="" alt="Signed Ticket"
         style="display:none;max-width:100%;max-height:78vh;object-fit:contain;padding:1rem">
    <div id="vsm_error"
         style="display:none;text-align:center;color:#842029;padding:2rem">
      <i class="bi bi-x-circle-fill" style="font-size:2rem;display:block;margin-bottom:.5rem"></i>
      <div style="font-size:.88rem;font-weight:600">Could not load the signed ticket.</div>
      <div style="font-size:.78rem;color:#888;margin-top:.3rem">Try downloading it instead.</div>
    </div>
  </div>
</div></div></div>

<script>
document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-view-signed-trip');
    if (!btn) return;

    const path   = btn.dataset.path;
    const ext    = btn.dataset.ext;
    const ticket = btn.dataset.ticket || 'Signed Ticket';

    const iframe  = document.getElementById('vsm_iframe');
    const img     = document.getElementById('vsm_img');
    const loader  = document.getElementById('vsm_loading');
    const errBox  = document.getElementById('vsm_error');
    const dlBtn   = document.getElementById('vsm_download');
    const label   = document.getElementById('vsm_ticket_label');

    // Reset state
    iframe.style.display = 'none';
    img.style.display    = 'none';
    errBox.style.display = 'none';
    loader.style.display = 'block';
    iframe.src = '';
    img.src    = '';
    dlBtn.href = path;
    label.textContent = ticket;

    if (ext === 'pdf') {
        iframe.onload = () => {
            loader.style.display = 'none';
            iframe.style.display = 'block';
        };
        iframe.src = path;
    } else {
        img.onload = () => {
            loader.style.display = 'none';
            img.style.display = 'block';
        };
        img.onerror = () => {
            loader.style.display = 'none';
            errBox.style.display = 'block';
        };
        img.src = path;
    }

    new bootstrap.Modal(document.getElementById('viewSignedModal')).show();
});

// Cleanup on modal close so iframe/img don't keep loading in background
document.getElementById('viewSignedModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('vsm_iframe').src = '';
    document.getElementById('vsm_img').src    = '';
});
</script>
</body>
</html>