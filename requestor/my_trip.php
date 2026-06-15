<?php
session_start();
require_once '../includes/db.php';

$page_title = 'My Trips';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

// Filter parameters
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$sql = "
    SELECT s.*,
           s.schedule_id AS id,
           v.plate_number,
           CONCAT(v.brand, ' ', v.model) AS vehicle_name,
           d.driver_name,
           d.phone_no AS driver_phone,
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
";
$params = [$user_id];

if ($filter_status) {
    $sql .= " AND s.status = ?";
    $params[] = $filter_status;
}
if ($search) {
    $sql .= " AND (s.destination LIKE ? OR s.purpose LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY s.schedule_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$trips = $stmt->fetchAll();
$count = count($trips);

// Unread notification count
$userOfficeStmt = $pdo->prepare("SELECT office_id FROM users WHERE user_id = ?");
$userOfficeStmt->execute([$user_id]);
$userOfficeRow = $userOfficeStmt->fetch();
$user_office_id = $userOfficeRow['office_id'] ?? null;

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$user_id, $user_office_id]);
$unreadCount = (int)$unreadStmt->fetchColumn();

// Handle AJAX reschedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule_trip') {
    header('Content-Type: application/json');
    $id         = (int)$_POST['schedule_id'];
    $date_start = trim($_POST['date_start'] ?? '');
    $date_end   = trim($_POST['date_end'] ?? '');
    $time_start = trim($_POST['time_start'] ?? '');
    $time_end   = trim($_POST['time_end'] ?? '');

    $chk = $pdo->prepare("SELECT schedule_id FROM schedules WHERE schedule_id=? AND user_id=? AND status IN ('Pending','Approved')");
    $chk->execute([$id, $user_id]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Not allowed']);
        exit();
    }

    $oldStmt = $pdo->prepare("SELECT date_start, date_end, time_start, time_end FROM schedules WHERE schedule_id=?");
    $oldStmt->execute([$id]);
    $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);

    $oldDateStart = $oldRow['date_start'] ?? null;
    $oldDateEnd   = $oldRow['date_end']   ?? null;
    $oldTimeStart = $oldRow['time_start'] ?? '00:00:00';
    $oldTimeEnd   = $oldRow['time_end']   ?? '23:59:00';

    $upd = $pdo->prepare("UPDATE schedules SET date_start=?, date_end=?, time_start=?, time_end=? WHERE schedule_id=?");
    $upd->execute([$date_start, $date_end ?: $date_start, $time_start, $time_end, $id]);

    $requesterName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'A user';
    $tripNo = '#' . str_pad($id, 4, '0', STR_PAD_LEFT);

    if ($oldDateStart) {
        $oldStart = date('M d, Y g:i A', strtotime($oldDateStart . ' ' . $oldTimeStart));
        $oldEnd   = date('M d, Y g:i A', strtotime($oldDateEnd   . ' ' . $oldTimeEnd));
        $newStart = date('M d, Y g:i A', strtotime($date_start   . ' ' . $time_start));
        $newEnd   = date('M d, Y g:i A', strtotime(($date_end ?: $date_start) . ' ' . $time_end));
        $notifMsg = "Trip {$tripNo} has been rescheduled by {$requesterName} — Old: {$oldStart} to {$oldEnd} — New: {$newStart} to {$newEnd}. [ref:{$id}]";
    } else {
        $newStart = date('M d, Y g:i A', strtotime($date_start . ' ' . $time_start));
        $newEnd   = date('M d, Y g:i A', strtotime(($date_end ?: $date_start) . ' ' . $time_end));
        $notifMsg = "Trip {$tripNo} has been rescheduled by {$requesterName} — New: {$newStart} to {$newEnd}. [ref:{$id}]";
    }

    $officeStmt = $pdo->prepare("SELECT office_id FROM schedules WHERE schedule_id = ?");
    $officeStmt->execute([$id]);
    $schedOffice = (int)($officeStmt->fetchColumn() ?? 0);

    $admins = $pdo->prepare("SELECT user_id FROM users WHERE role = 'admin' AND office_id = ?");
    $admins->execute([$schedOffice]);
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, office_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
    foreach ($admins->fetchAll() as $admin) {
        $notifStmt->execute([$admin['user_id'], $schedOffice, $notifMsg]);
    }

    echo json_encode(['success' => true]);
    exit();
}

// Handle AJAX cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_trip') {
    header('Content-Type: application/json');
    $id     = (int)$_POST['schedule_id'];
    $reason = trim($_POST['cancel_reason'] ?? '');

    $chk = $pdo->prepare("SELECT schedule_id FROM schedules WHERE schedule_id=? AND user_id=? AND status IN ('Pending','Approved')");
    $chk->execute([$id, $user_id]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Not allowed']);
        exit();
    }

    $roleStmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
    $roleStmt->execute([$user_id]);
    $userRow  = $roleStmt->fetch();
    $userRole = $userRow ? ucfirst($userRow['role']) : 'User';
    $cancelledByLabel = ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') . ' (' . $userRole . ')';

    $upd = $pdo->prepare("UPDATE schedules SET status='Cancelled', cancel_reason=?, cancelled_by=? WHERE schedule_id=?");
    $upd->execute([$reason, $cancelledByLabel, $id]);

    $requesterName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'A user';
    $tripNo = '#' . str_pad($id, 4, '0', STR_PAD_LEFT);
    $notifMsg = "Trip {$tripNo} has been cancelled by {$requesterName}" . ($reason ? " — Reason: {$reason}." : ".") . " [ref:{$id}]";

    $officeStmt = $pdo->prepare("SELECT office_id FROM schedules WHERE schedule_id = ?");
    $officeStmt->execute([$id]);
    $schedOffice = (int)($officeStmt->fetchColumn() ?? 0);

    $admins = $pdo->prepare("SELECT user_id FROM users WHERE role = 'admin' AND office_id = ?");
    $admins->execute([$schedOffice]);
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, office_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
    foreach ($admins->fetchAll() as $admin) {
        $notifStmt->execute([$admin['user_id'], $schedOffice, $notifMsg]);
    }

    echo json_encode(['success' => true]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Trips – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
    --maroon:       #800000;
    --maroon-dark:  #5a0000;
    --maroon-light: #fdecea;
    --maroon-mid:   #f5e5e5;
    --surface:      #fff;
    --bg:           #f7f2f2;
    --border:       #eedede;
    --text:         #1a1a1a;
    --text-muted:   #888;
    --radius:       12px;
    --radius-lg:    18px;
    --shadow:       0 2px 16px rgba(128,0,0,0.08);
    --shadow-lg:    0 20px 60px rgba(0,0,0,0.18);
}
* { box-sizing: border-box; }
body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; margin: 0; }

/* ── Sidebar ── */
.sidebar {
    min-height: 100vh;
    background: linear-gradient(180deg, #800000 0%, #6b0000 100%);
    width: 240px; position: fixed; top: 0; left: 0;
    z-index: 200; display: flex; flex-direction: column;
    transition: transform 0.25s ease;
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
    border-left: 3px solid transparent; transition: all 0.15s; text-decoration: none;
}
.sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); border-left-color: rgba(255,255,255,0.4); }
.sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.15); border-left-color: #fff; font-weight: 600; }
.sidebar .nav-link i { font-size: 1rem; width: 18px; }
.sidebar-divider { border-color: rgba(255,255,255,0.15); margin: 0.5rem 1rem; }
.nav-section-label {
    padding: 0.75rem 1.25rem 0.25rem; font-size: 0.68rem; font-weight: 700;
    color: rgba(255,255,255,0.45); letter-spacing: 0.08em; text-transform: uppercase;
}
.notif-badge-pill {
    background: #e24b4a; color: #fff; font-size: .62rem;
    font-weight: 700; min-width: 17px; height: 17px;
    border-radius: 9px; display: inline-flex;
    align-items: center; justify-content: center;
    padding: 0 4px; margin-left: auto;
}

/* ── Sidebar overlay ── */
.sidebar-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 199;
}
.sidebar-overlay.show { display: block; }

/* ── Desktop Topbar ── */
.topbar {
    background: #fff; border-bottom: 1px solid #e8dede;
    padding: 0.7rem 1.5rem; margin-left: 240px;
    position: sticky; top: 0; z-index: 99;
    display: flex; align-items: center; justify-content: space-between;
}
.topbar-title { font-weight: 700; font-size: 1rem; color: #800000; display: flex; align-items: center; gap: 6px; }
.topbar-user { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #666; }
.user-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: #800000; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700; flex-shrink: 0;
}

/* ── Main ── */
.main-content { margin-left: 240px; padding: 1.5rem; }

/* ── Desktop UI ── */
.page-hdr { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 1.25rem; }
.page-hdr h4 { font-weight: 800; color: var(--text); margin: 0; font-size: 1.25rem; }
.page-hdr p  { color: var(--text-muted); font-size: 0.8rem; margin: 2px 0 0; }
.btn-new-req {
    background: var(--maroon); color: #fff; border: none; border-radius: 10px;
    padding: 9px 20px; font-size: 0.84rem; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; gap: 6px; text-decoration: none;
    transition: background 0.15s; white-space: nowrap;
}
.btn-new-req:hover { background: var(--maroon-dark); color: #fff; }
.status-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 1rem; }
.status-tab {
    padding: 5px 15px; border-radius: 20px; font-size: 0.77rem; font-weight: 600;
    text-decoration: none; background: var(--surface);
    border: 1.5px solid var(--border); color: var(--text-muted); transition: all .15s; white-space: nowrap;
}
.status-tab:hover { border-color: var(--maroon); color: var(--maroon); }
.status-tab.active { background: var(--maroon-light); border-color: var(--maroon); color: var(--maroon); }
.filter-bar {
    background: var(--surface); border-radius: var(--radius);
    box-shadow: var(--shadow); padding: 0.8rem 1rem; margin-bottom: 1.25rem;
    display: flex; gap: 10px; flex-wrap: wrap; align-items: center; border: 1px solid var(--border);
}
.filter-bar input {
    border: 1.5px solid var(--border); border-radius: 9px; padding: 7px 12px;
    font-size: 0.84rem; color: #444; transition: border-color .15s; background: var(--bg);
    flex: 1; min-width: 160px; font-family: inherit;
}
.filter-bar input:focus { outline: none; border-color: var(--maroon); background: #fff; }
.btn-filter {
    background: var(--maroon-light); color: var(--maroon); border: 1.5px solid var(--border);
    font-weight: 700; border-radius: 9px; padding: 7px 16px; font-size: 0.84rem;
    cursor: pointer; display: flex; align-items: center; gap: 5px; font-family: inherit;
    transition: background 0.15s; white-space: nowrap;
}
.btn-filter:hover { background: var(--maroon); color: #fff; }
.btn-reset {
    background: #e9ecef; color: #555; border: none; font-weight: 600; border-radius: 9px;
    padding: 7px 14px; font-size: 0.84rem; cursor: pointer; text-decoration: none;
    display: flex; align-items: center; gap: 5px; font-family: inherit; white-space: nowrap;
}
.section-card { background: var(--surface); border-radius: var(--radius-lg); box-shadow: var(--shadow); overflow: hidden; border: 1px solid var(--border); }
.section-header { padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--maroon-mid); font-weight: 700; font-size: 0.85rem; color: var(--maroon); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; }
.table thead th { background: #fdf5f5; color: var(--maroon); font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 2px solid var(--maroon-mid); padding: 0.7rem 1rem; white-space: nowrap; }
.table tbody td { padding: 0.7rem 1rem; font-size: 0.84rem; color: #3a3a3a; vertical-align: middle; border-color: #fdf5f5; }
.table tbody tr:hover { background: #fdf8f8; }
.badge-status { padding: 4px 11px; border-radius: 20px; font-size: 0.73rem; font-weight: 700; display: inline-block; white-space: nowrap; }
.badge-pending   { background:#fff3cd; color:#856404; }
.badge-approved  { background:#d1e7dd; color:#0f5132; }
.badge-completed { background:#cfe2ff; color:#0a3678; }
.badge-rejected  { background:#f8d7da; color:#842029; }
.badge-cancelled { background:#e2e3e5; color:#41464b; }
.badge-ongoing   { background:#fff0d6; color:#7a4f00; }
.pax-chip { display: inline-flex; align-items: center; gap: 5px; background: #f3e5ff; color: #6f42c1; padding: 3px 10px; border-radius: 20px; font-size: 0.76rem; font-weight: 700; }
.btn-act { border: none; border-radius: 7px; font-size: 0.74rem; font-weight: 700; cursor: pointer; padding: 5px 10px; transition: all 0.15s; display: inline-flex; align-items: center; gap: 4px; font-family: inherit; white-space: nowrap; }
.btn-view       { background: var(--maroon-light); color: var(--maroon); }
.btn-view:hover { background: var(--maroon); color: #fff; }
.btn-reschedule { background: #e8f4ff; color: #0a58ca; }
.btn-reschedule:hover { background: #0a58ca; color: #fff; }
.btn-cancel     { background: #f8d7da; color: #842029; }
.btn-cancel:hover { background: #842029; color: #fff; }
.empty-state { padding: 3rem 1rem; text-align: center; }
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: 0.75rem; color: #e0d0d0; }
.empty-state p { font-size: 0.85rem; margin: 0; color: #aaa; }
.empty-state a { color: var(--maroon); font-weight: 700; text-decoration: none; }

/* ══════════════════════════════════
   MODAL SYSTEM (unchanged)
══════════════════════════════════ */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 1000;
    align-items: center; justify-content: center; padding: 1rem;
    backdrop-filter: blur(2px);
}
.modal-overlay.show { display: flex; animation: fadein 0.18s ease; }
@keyframes fadein { from{opacity:0} to{opacity:1} }
.trip-modal { background: var(--surface); border-radius: var(--radius-lg); width: 100%; max-width: 680px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--shadow-lg); animation: slideup 0.22s cubic-bezier(.22,.68,0,1.2); }
@keyframes slideup { from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }
.trip-modal-hdr { background: linear-gradient(135deg, #800000 0%, #4a0000 100%); padding: 1.2rem 1.5rem; flex-shrink: 0; }
.trip-modal-body { padding: 1.4rem 1.5rem; overflow-y: auto; flex: 1; }
.trip-modal-ftr { padding: 0.9rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; flex-shrink: 0; background: #fdf8f8; }
.td-section-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: .08em; color: var(--maroon); font-weight: 800; margin: 1rem 0 0.55rem; padding-bottom: 5px; border-bottom: 2px solid var(--maroon-mid); display: flex; align-items: center; gap: 6px; }
.td-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem 1.5rem; }
.td-item-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: .06em; color: #bbb; font-weight: 700; margin-bottom: 3px; }
.td-item-value { font-size: 0.87rem; font-weight: 600; color: var(--text); }
.td-item-value.empty { color: #ccc; font-style: italic; font-weight: 400; }
.reschedule-modal { background: var(--surface); border-radius: var(--radius-lg); width: 100%; max-width: 480px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--shadow-lg); animation: slideup 0.22s cubic-bezier(.22,.68,0,1.2); }
.reschedule-modal-hdr { background: linear-gradient(135deg, #0a58ca 0%, #052e6e 100%); padding: 1.1rem 1.5rem; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; }
.reschedule-modal-body { padding: 1.4rem 1.5rem; overflow-y: auto; flex: 1; }
.reschedule-modal-ftr { padding: 0.9rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0; background: #f5f8ff; }
.form-label-custom { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--maroon); margin-bottom: 5px; display: block; }
.form-input-custom { width: 100%; border: 1.5px solid var(--border); border-radius: 9px; padding: 9px 12px; font-size: 0.87rem; font-family: inherit; color: var(--text); background: var(--bg); transition: border-color .15s, background .15s; }
.form-input-custom:focus { outline: none; border-color: var(--maroon); background: #fff; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.form-group { margin-bottom: 1rem; }
.cancel-modal { background: var(--surface); border-radius: var(--radius-lg); width: 100%; max-width: 400px; box-shadow: var(--shadow-lg); animation: slideup 0.22s cubic-bezier(.22,.68,0,1.2); overflow: hidden; }
.cancel-modal-hdr { background: linear-gradient(135deg, #842029 0%, #4a0010 100%); padding: 1.1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.cancel-modal-body { padding: 1.4rem 1.5rem; }
.cancel-modal-ftr { padding: 0.9rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; background: #fdf8f8; }
.modal-close-btn { background: rgba(255,255,255,0.15); border: none; color: #fff; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; transition: background .15s; flex-shrink: 0; }
.modal-close-btn:hover { background: rgba(255,255,255,0.3); }
.toast-wrap { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 2000; display: flex; flex-direction: column; gap: 8px; }
.toast-msg { background: #1a1a1a; color: #fff; padding: 10px 18px; border-radius: 10px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); animation: slidein-right 0.25s ease; }
.toast-msg.success { background: #0f5132; }
.toast-msg.error   { background: #842029; }
@keyframes slidein-right { from{transform:translateX(60px);opacity:0} to{transform:translateX(0);opacity:1} }
.spinner { display: none; }
.spinner.show { display: inline-block; }
.spin { animation: spin 0.7s linear infinite; display:inline-block; }
@keyframes spin { to{transform:rotate(360deg)} }

/* ══════════════════════════════════
   MOBILE STYLES
══════════════════════════════════ */
@media (max-width: 768px) {

    /* Hide desktop elements */
    .sidebar  { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .topbar   { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 0 !important; }

    /* Desktop-only elements */
    .desktop-only { display: none !important; }

    /* ── Mobile top bar ── */
    .mobile-topbar {
        background: #fff;
        border-bottom: 1px solid #e8dede;
        padding: .7rem 1.5rem;
        position: sticky; top: 0; z-index: 200;
        display: flex; align-items: center; justify-content: space-between;
    }
    .hamburger-btn {
        display: flex;
        background: none; border: none; cursor: pointer;
        padding: 4px 8px; color: #800000;
        font-size: 1.4rem; align-items: center;
        justify-content: center; line-height: 1;
    }
    .mobile-topbar .topbar-title {
        font-weight: 700; font-size: 1rem; color: #800000;
        display: flex; align-items: center;
    }
    .mobile-topbar .topbar-user {
        display: flex; align-items: center; gap: 8px;
    }
    .mobile-topbar .user-avatar {
        width: 32px; height: 32px; border-radius: 50%;
        background: #800000; color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: .8rem; font-weight: 700;
    }

    /* ── Scroll area ── */
    .mob-scroll { padding: 14px 14px 100px; }

    /* ── Summary strip ── */
    .mob-summary-strip {
        display: grid; grid-template-columns: repeat(3, 1fr);
        gap: 8px; margin-bottom: 14px;
    }
    .mob-sum-card {
        background: #fff; border-radius: 11px; padding: 10px 10px;
        box-shadow: 0 1px 5px rgba(128,0,0,.06); text-align: center;
    }
    .mob-sum-label { font-size: .6rem; color: #aaa; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
    .mob-sum-value { font-size: 1.35rem; font-weight: 800; color: #1a1a1a; line-height: 1; }
    .mob-sum-card.pending   { border-top: 3px solid #856404; }
    .mob-sum-card.active    { border-top: 3px solid #0f5132; }
    .mob-sum-card.completed { border-top: 3px solid #0a3678; }

    /* ── Filter pills ── */
    .mob-filter-row { display: flex; gap: 7px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 12px; scrollbar-width: none; }
    .mob-filter-row::-webkit-scrollbar { display: none; }
    .mob-filter-pill { flex-shrink: 0; padding: 7px 14px; border-radius: 20px; font-size: .76rem; font-weight: 600; border: 1.5px solid #e0d0d0; color: #800000; background: #fff; cursor: pointer; white-space: nowrap; text-decoration: none; transition: all .15s; }
    .mob-filter-pill.active { background: #800000; color: #fff; border-color: #800000; }

    /* ── Mobile search ── */
    .mob-search-wrap { display: flex; align-items: center; background: #fff; border: 1.5px solid #e0d0d0; border-radius: 11px; padding: 0 11px; margin-bottom: 14px; gap: 7px; }
    .mob-search-wrap:focus-within { border-color: #800000; }
    .mob-search-input { flex: 1; border: none; background: transparent; padding: .55rem 0; font-size: .86rem; outline: none; min-width: 0; }
    .mob-search-clear { flex-shrink: 0; display: none; align-items: center; background: none; border: none; color: #aaa; cursor: pointer; line-height: 1; padding: 0; }

    /* ── Trip cards ── */
    .trip-card {
        background: #fff; border-radius: 14px; padding: 13px 14px;
        margin-bottom: 10px; box-shadow: 0 1px 6px rgba(128,0,0,.07);
        border: 1px solid #f5eded;
    }
    .tc-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 9px; }
    .tc-id   { font-size: .67rem; color: #bbb; font-weight: 700; margin-bottom: 1px; }
    .tc-dest { font-weight: 700; font-size: .9rem; color: #1a1a1a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
    .tc-purp { font-size: .73rem; color: #888; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
    .tc-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 9px; }
    .tc-meta-item { background: #fdf8f8; border-radius: 8px; padding: 6px 9px; }
    .tc-meta-lbl { font-size: .6rem; color: #bbb; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 1px; }
    .tc-meta-val { font-size: .76rem; color: #444; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tc-actions { display: flex; gap: 7px; padding-top: 9px; border-top: 1px solid #fdf0f0; }
    .tc-btn { flex: 1; padding: 9px 8px; border-radius: 9px; font-size: .75rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 5px; cursor: pointer; border: none; transition: all .15s; }
    .tc-btn-view     { background: #fdf0f0; color: #800000; }
    .tc-btn-resched  { background: #e8f4ff; color: #0a58ca; }
    .tc-btn-cancel   { background: #fef2f2; color: #dc2626; }

    /* ── Sheet backdrop ── */
    .sheet-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 300; opacity: 0; pointer-events: none; transition: opacity .25s; }
    .sheet-backdrop.open { opacity: 1; pointer-events: all; }

    /* ── Bottom sheets ── */
    .mob-sheet {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 310;
        background: #fff; border-radius: 20px 20px 0 0;
        max-height: 92vh; overflow-y: auto;
        transform: translateY(105%);
        transition: transform .3s cubic-bezier(.4,0,.2,1);
        padding: 0 16px 48px;
    }
    .mob-sheet.open { transform: translateY(0); }
    .sheet-handle { width: 40px; height: 4px; background: #e0d0d0; border-radius: 2px; margin: 12px auto 16px; }
    .sheet-head { font-weight: 700; font-size: 1rem; color: #800000; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .sheet-lbl { font-size: .7rem; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; display: block; }
    .sheet-input {
        width: 100%; padding: 11px 13px; border-radius: 10px;
        border: 1.5px solid #e0d0d0; font-size: .9rem; color: #333;
        background: #fff; outline: none; -webkit-appearance: none; appearance: none;
        transition: border-color .15s;
    }
    .sheet-input:focus { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,.1); }
    .sheet-grp { margin-bottom: 13px; }
    .sheet-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .sheet-actions { display: flex; gap: 10px; margin-top: 18px; }
    .btn-sheet-cancel  { flex: 1; padding: 12px; border-radius: 11px; background: #f5f5f5; color: #555; font-weight: 600; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-primary { flex: 2; padding: 12px; border-radius: 11px; background: #800000; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-blue    { flex: 2; padding: 12px; border-radius: 11px; background: #0a58ca; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-danger  { flex: 2; padding: 12px; border-radius: 11px; background: #dc2626; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }

    /* Detail sheet sections */
    .det-section { background: #fdf8f8; border-radius: 10px; padding: 11px 13px; margin-bottom: 9px; }
    .det-section-hdr { font-size: .62rem; color: #800000; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 7px; display: flex; align-items: center; gap: 5px; }
    .det-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 5px; }
    .det-row:last-child { margin-bottom: 0; }
    .det-lbl { font-size: .68rem; color: #aaa; font-weight: 600; flex-shrink: 0; }
    .det-val { font-size: .78rem; color: #333; font-weight: 700; text-align: right; }

    /* Modals slide from bottom on mobile */
    .modal-overlay { padding: 0; align-items: flex-end; }
    .trip-modal, .reschedule-modal, .cancel-modal {
        max-width: 100%; max-height: 92vh;
        border-radius: 20px 20px 0 0;
    }
    .td-grid, .form-grid-2 { grid-template-columns: 1fr; }

    /* FAB */
    .mob-fab {
        position: fixed; bottom: 24px; right: 20px; z-index: 150;
        width: 58px; height: 58px; background: #800000; color: #fff;
        border: none; border-radius: 50%; font-size: 1.6rem;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 4px 16px rgba(128,0,0,.4); cursor: pointer;
        transition: transform .15s, background .15s;
    }
    .mob-fab:hover  { background: #6b0000; transform: scale(1.05); }
    .mob-fab:active { transform: scale(.95); }

    /* Empty */
    .mob-empty { text-align: center; padding: 36px 20px; color: #ccc; }
    .mob-empty i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: .35; }
    .mob-empty p { font-size: .84rem; margin: 0; }
}

/* ── Hide mobile on desktop ── */
@media (min-width: 769px) {
    .mobile-topbar { display: none !important; }
    .mob-scroll    { display: none !important; }
    .mob-fab       { display: none !important; }
    .sheet-backdrop { display: none !important; }
    .mob-sheet     { display: none !important; }
    .sidebar { transform: none !important; }
}
</style>
</head>
<body>

<!-- ══════════════════════════════
     MOBILE LAYOUT
══════════════════════════════ -->

<!-- Mobile topbar -->
<div class="mobile-topbar">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title">
        <i class="bi bi-map me-2"></i>My Trips
    </div>
    <div class="topbar-user">
        <?php if($unreadCount > 0): ?>
        <a href="notification_requestor.php" style="position:relative;color:#800000;font-size:1.1rem;line-height:1;display:flex;align-items:center">
            <i class="bi bi-bell-fill"></i>
            <span style="position:absolute;top:-4px;right:-4px;background:#e24b4a;color:#fff;font-size:.55rem;font-weight:700;min-width:14px;height:14px;border-radius:7px;display:flex;align-items:center;justify-content:center;padding:0 3px;"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
        </a>
        <?php endif; ?>
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></div>
            <div style="font-size:.72rem;color:#800000">Requestor</div>
        </div>
    </div>
</div>

<!-- Mobile scroll content -->
<div class="mob-scroll">

    <?php
    // Count statuses for summary
    $allTrips = $pdo->prepare("SELECT status FROM schedules WHERE user_id=?");
    $allTrips->execute([$user_id]);
    $allStatuses = $allTrips->fetchAll(PDO::FETCH_COLUMN);
    $cnt_pending   = count(array_filter($allStatuses, fn($s)=>$s==='Pending'));
    $cnt_active    = count(array_filter($allStatuses, fn($s)=>in_array($s,['Approved','OnTrip'])));
    $cnt_completed = count(array_filter($allStatuses, fn($s)=>$s==='Completed'));
    ?>

    <!-- Summary strip -->
    <div class="mob-summary-strip">
        <div class="mob-sum-card pending">
            <div class="mob-sum-label">Pending</div>
            <div class="mob-sum-value"><?= $cnt_pending ?></div>
        </div>
        <div class="mob-sum-card active">
            <div class="mob-sum-label">Active</div>
            <div class="mob-sum-value"><?= $cnt_active ?></div>
        </div>
        <div class="mob-sum-card completed">
            <div class="mob-sum-label">Done</div>
            <div class="mob-sum-value"><?= $cnt_completed ?></div>
        </div>
    </div>

    <!-- Filter pills -->
    <div class="mob-filter-row">
        <?php
        $statuses = ['', 'Pending', 'Approved', 'Ongoing', 'Completed', 'Rejected', 'Cancelled'];
        $labels   = ['All', 'Pending', 'Approved', 'Ongoing', 'Completed', 'Rejected', 'Cancelled'];
        foreach ($statuses as $i => $st):
            $active = ($filter_status === $st) ? 'active' : '';
            $url    = '?status=' . urlencode($st);
            if ($search) $url .= '&search=' . urlencode($search);
        ?>
        <a href="<?= $url ?>" class="mob-filter-pill <?= $active ?>"><?= $labels[$i] ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Mobile search -->
    <div class="mob-search-wrap">
        <i class="bi bi-search" style="color:#800000;font-size:.9rem;flex-shrink:0"></i>
        <input type="text" class="mob-search-input" id="mobTripSearch"
            placeholder="Search destination or purpose…"
            oninput="onMobSearch(this)" autocomplete="off"
            value="<?= htmlspecialchars($search) ?>">
        <button class="mob-search-clear" id="mobSearchClear" onclick="clearMobSearch()"
            style="<?= $search ? 'display:flex' : '' ?>">
            <i class="bi bi-x-lg" style="font-size:.75rem"></i>
        </button>
    </div>

    <!-- Trip cards -->
    <?php if (empty($trips)): ?>
    <div class="mob-empty">
        <i class="bi bi-map"></i>
        <p style="font-weight:700;color:#c0a0a0;margin-bottom:4px">No trips found</p>
        <p style="font-size:.78rem"><?= ($search || $filter_status) ? '<a href="my_trip.php" style="color:#800000;font-weight:700">Clear filters</a>' : '<a href="new_request.php" style="color:#800000;font-weight:700">Create your first request</a>' ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($trips as $row):
        $rowData = [
            "id"               => $row["id"],
            "destination"      => $row["destination"],
            "purpose"          => $row["purpose"],
            "passengers"       => (int)($row["passengers"] ?? 0),
            "date_start"       => $row["date_start"],
            "date_end"         => $row["date_end"],
            "time_start"       => $row["time_start"],
            "time_end"         => $row["time_end"],
            "status"           => $row["status"],
            "vehicle_name"     => $row["vehicle_name"],
            "plate_number"     => $row["plate_number"],
            "driver_name"      => $row["driver_name"],
            "driver_phone"     => $row["driver_phone"] ?? '',
            "office_name"      => $row["office_name"],
            "dept_name"        => $row["dept_name"],
            "rejection_reason" => $row["rejection_reason"] ?? '',
            "cancel_reason"    => $row["cancel_reason"] ?? '',
            "cancelled_by"     => $row["cancelled_by"] ?? '',
            "arrived_at"       => $row["arrived_at"] ?? '',
            "trip_ticket_no"   => $row["trip_ticket_no"] ?? '',
            "booked_by_staff"  => $row["booked_by_name"] ?? ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
            "created_at"       => $row["created_at"],
        ];
        $dataJson = htmlspecialchars(json_encode($rowData), ENT_QUOTES);
        $s = strtolower(str_replace(['-',' '], '', $row['status'] ?? ''));
        $map = ['pending'=>'badge-pending','approved'=>'badge-approved','completed'=>'badge-completed','rejected'=>'badge-rejected','cancelled'=>'badge-cancelled','ongoing'=>'badge-ongoing','ontrip'=>'badge-ongoing'];
        $cls = $map[$s] ?? 'badge-pending';
        $rowStatus = strtolower($row['status']);
        $canModify = ($rowStatus === 'pending' || $rowStatus === 'approved');
    ?>
    <div class="trip-card mob-trip-row"
        data-search="<?= htmlspecialchars(strtolower(($row['destination']??'').' '.($row['purpose']??'')), ENT_QUOTES) ?>">
        <div class="tc-top">
            <div style="flex:1;min-width:0">
                <div class="tc-id">#<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></div>
                <div class="tc-dest"><i class="bi bi-geo-alt-fill" style="color:#800000;font-size:.7rem"></i> <?= htmlspecialchars($row['destination']) ?></div>
                <div class="tc-purp"><?= htmlspecialchars($row['purpose']) ?></div>
            </div>
            <span class="badge-status <?= $cls ?>" style="flex-shrink:0;margin-left:8px"><?= htmlspecialchars($row['status']) ?></span>
        </div>
        <div class="tc-meta">
            <div class="tc-meta-item">
                <div class="tc-meta-lbl">Date</div>
                <div class="tc-meta-val">
                    <?= date('M d, Y', strtotime($row['date_start'])) ?>
                    <?php if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']): ?>
                    <span style="color:#bbb"> → <?= date('M d', strtotime($row['date_end'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tc-meta-item">
                <div class="tc-meta-lbl">Time</div>
                <div class="tc-meta-val">
                    <?= !empty($row['time_start']) ? date('h:i A', strtotime($row['time_start'])) : '—' ?>
                    <?php if (!empty($row['time_end'])): ?><span style="color:#bbb"> → <?= date('h:i A', strtotime($row['time_end'])) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="tc-meta-item">
                <div class="tc-meta-lbl">Vehicle</div>
                <div class="tc-meta-val"><?= !empty($row['vehicle_name']) ? htmlspecialchars($row['vehicle_name']) : '—' ?></div>
            </div>
            <div class="tc-meta-item">
                <div class="tc-meta-lbl">Driver</div>
                <div class="tc-meta-val"><?= !empty($row['driver_name']) ? htmlspecialchars($row['driver_name']) : '—' ?></div>
            </div>
        </div>
        <div class="tc-actions">
            <button class="tc-btn tc-btn-view" onclick='mobOpenView(<?= $dataJson ?>)'>
                <i class="bi bi-eye-fill" style="font-size:.75rem"></i> View
            </button>
            <?php if ($canModify): ?>
            <button class="tc-btn tc-btn-resched" onclick='mobOpenResched(<?= $dataJson ?>)'>
                <i class="bi bi-calendar2-week-fill" style="font-size:.75rem"></i> Resched
            </button>
            <button class="tc-btn tc-btn-cancel" onclick='mobOpenCancel(<?= (int)$row["id"] ?>, "<?= htmlspecialchars(str_pad($row["id"], 4, "0", STR_PAD_LEFT)) ?>")'>
                <i class="bi bi-x-lg" style="font-size:.75rem"></i> Cancel
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div><!-- /mob-scroll -->

<!-- Mobile FAB -->
<a href="new_request.php" class="mob-fab" title="New Request">
    <i class="bi bi-plus-lg"></i>
</a>

<!-- Sheet backdrop -->
<div class="sheet-backdrop" id="mobBackdrop" onclick="mobCloseAll()"></div>

<!-- ── View Details Sheet ── -->
<div class="mob-sheet" id="mobViewSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head" id="mobViewHead"><i class="bi bi-map"></i> Trip Details</div>
    <div id="mobViewBody"></div>
    <div class="sheet-actions">
        <button class="btn-sheet-cancel" onclick="mobCloseAll()">Close</button>
    </div>
</div>

<!-- ── Reschedule Sheet ── -->
<div class="mob-sheet" id="mobReschedSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-calendar2-week-fill" style="color:#0a58ca"></i> Reschedule Trip</div>
    <div id="mobReschedInfo" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:.78rem;color:#0a58ca"></div>
    <form id="mobReschedForm">
        <input type="hidden" name="action" value="reschedule_trip">
        <input type="hidden" name="schedule_id" id="mob_resched_id">
        <div class="sheet-grid-2">
            <div class="sheet-grp">
                <label class="sheet-lbl">New Start Date <span style="color:#dc2626">*</span></label>
                <input type="date" name="date_start" id="mob_resched_ds" class="sheet-input" required>
            </div>
            <div class="sheet-grp">
                <label class="sheet-lbl">New End Date</label>
                <input type="date" name="date_end" id="mob_resched_de" class="sheet-input">
                <div style="font-size:.63rem;color:#bbb;margin-top:3px">Leave blank if same day</div>
            </div>
            <div class="sheet-grp">
                <label class="sheet-lbl">New Start Time <span style="color:#dc2626">*</span></label>
                <input type="time" name="time_start" id="mob_resched_ts" class="sheet-input" required>
            </div>
            <div class="sheet-grp">
                <label class="sheet-lbl">New End Time</label>
                <input type="time" name="time_end" id="mob_resched_te" class="sheet-input">
            </div>
        </div>
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobCloseAll()">Discard</button>
            <button type="button" class="btn-sheet-blue" onclick="mobSubmitResched()">
                <i class="bi bi-calendar-check me-1"></i>
                <span id="mobReschedTxt">Confirm</span>
                <span id="mobReschedSpin" style="display:none"><i class="bi bi-arrow-repeat" style="animation:spin .7s linear infinite;display:inline-block"></i></span>
            </button>
        </div>
    </form>
</div>

<!-- ── Cancel Sheet ── -->
<div class="mob-sheet" id="mobCancelSheet">
    <div class="sheet-handle"></div>
    <div style="text-align:center;font-size:2.2rem;margin-bottom:6px">⚠️</div>
    <div style="text-align:center;color:#555;font-size:.88rem;margin-bottom:3px">Cancel request</div>
    <div style="text-align:center;font-weight:700;font-size:1rem;color:#dc2626;margin-bottom:4px" id="mobCancelReqNum">—</div>
    <div style="text-align:center;font-size:.74rem;color:#aaa;margin-bottom:16px">This cannot be undone.</div>
    <div class="sheet-grp">
        <label class="sheet-lbl">Reason <span style="color:#aaa;text-transform:none;font-weight:400;font-size:.7rem">(optional)</span></label>
        <textarea id="mob_cancel_reason" rows="3" class="sheet-input"
            placeholder="e.g. Trip no longer needed…" style="resize:none"></textarea>
    </div>
    <input type="hidden" id="mob_cancel_id">
    <div class="sheet-actions">
        <button class="btn-sheet-cancel" onclick="mobCloseAll()">No, keep it</button>
        <button class="btn-sheet-danger" onclick="mobSubmitCancel()">
            <i class="bi bi-x-circle me-1"></i>
            <span id="mobCancelTxt">Yes, Cancel</span>
            <span id="mobCancelSpin" style="display:none"><i class="bi bi-arrow-repeat" style="animation:spin .7s linear infinite;display:inline-block"></i></span>
        </button>
    </div>
</div>


<!-- ══════════════════════════════
     SHARED SIDEBAR
══════════════════════════════ -->
<div class="sidebar" id="mainSidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="../image/Csu.png" alt="Logo"></div>
    <div class="sidebar-brand-text">CSU Vehicle System<span>Requestor Panel</span></div>
  </div>
  <nav class="nav flex-column mt-2">
    <div class="nav-section-label">Main</div>
    <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <div class="nav-section-label">Requests</div>
    <a class="nav-link" href="new_request.php"><i class="bi bi-plus-circle"></i> New Trip Request</a>
    <a class="nav-link active" href="my_trip.php"><i class="bi bi-map"></i> My Trips</a>
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

<!-- Sidebar overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>


<!-- ══════════════════════════════
     DESKTOP LAYOUT
══════════════════════════════ -->

<!-- Desktop Topbar -->
<div class="topbar desktop-only">
    <div class="topbar-title"><i class="bi bi-map"></i> My Trips</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)) ?></div>
        <div>
            <div style="font-weight:700;color:#333;font-size:0.84rem"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></div>
            <div style="font-size:0.7rem;color:var(--maroon);font-weight:600;">Requestor</div>
        </div>
    </div>
</div>

<!-- Desktop main content -->
<div class="main-content desktop-only">

    <div class="page-hdr">
        <div>
            <h4>My Trips</h4>
            <p>All your vehicle request history</p>
        </div>
        <a href="new_request.php" class="btn-new-req">
            <i class="bi bi-plus-circle"></i> New Request
        </a>
    </div>

    <!-- Status Tabs -->
    <div class="status-tabs">
        <?php
        foreach ($statuses as $i => $st):
            $active = ($filter_status === $st) ? 'active' : '';
            $url    = '?status=' . urlencode($st);
            if ($search) $url .= '&search=' . urlencode($search);
        ?>
        <a href="<?= $url ?>" class="status-tab <?= $active ?>"><?= $labels[$i] ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Filter Bar -->
    <form method="GET" class="filter-bar">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
        <input type="text" name="search" placeholder="🔍  Search destination or purpose…" value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn-filter"><i class="bi bi-funnel"></i> Filter</button>
        <?php if ($search || $filter_status): ?>
        <a href="my_trip.php" class="btn-reset"><i class="bi bi-x"></i> Reset</a>
        <?php endif; ?>
    </form>

    <!-- Table -->
    <div class="section-card">
        <div class="section-header">
            <span>
                <i class="bi bi-table me-1"></i>
                Showing <strong><?= $count ?></strong> record<?= $count !== 1 ? 's' : '' ?>
                <?= $filter_status ? " &mdash; <span style='color:#856404'>" . htmlspecialchars($filter_status) . "</span>" : '' ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Req #</th><th>Date</th><th>Time</th><th>Destination</th>
                        <th>Purpose</th><th>Pax</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($trips)): ?>
                    <?php foreach ($trips as $row):
                        $rowData = [
                            "id"=>$row["id"],"destination"=>$row["destination"],"purpose"=>$row["purpose"],
                            "passengers"=>(int)($row["passengers"]??0),"date_start"=>$row["date_start"],
                            "date_end"=>$row["date_end"],"time_start"=>$row["time_start"],"time_end"=>$row["time_end"],
                            "status"=>$row["status"],"vehicle_name"=>$row["vehicle_name"],"plate_number"=>$row["plate_number"],
                            "driver_name"=>$row["driver_name"],"driver_phone"=>$row["driver_phone"]??'',
                            "office_name"=>$row["office_name"],"dept_name"=>$row["dept_name"],
                            "rejection_reason"=>$row["rejection_reason"]??'',
                            "cancel_reason"=>$row["cancel_reason"]??'',
                            "cancelled_by"=>$row["cancelled_by"]??'',
                            "arrived_at"=>$row["arrived_at"]??'',
                            "trip_ticket_no"=>$row["trip_ticket_no"]??'',
                            "booked_by_staff"=>$row["booked_by_name"]??($_SESSION['full_name']??$_SESSION['username']??''),
                            "created_at"=>$row["created_at"],
                        ];
                        $dataJson = htmlspecialchars(json_encode($rowData), ENT_QUOTES);
                        $s   = strtolower(str_replace(['-',' '], '', $row['status'] ?? ''));
                        $map = ['pending'=>'badge-pending','approved'=>'badge-approved','completed'=>'badge-completed','rejected'=>'badge-rejected','cancelled'=>'badge-cancelled','ongoing'=>'badge-ongoing','ontrip'=>'badge-ongoing'];
                        $cls = $map[$s] ?? 'badge-pending';
                    ?>
                    <tr>
                        <td style="font-weight:800;color:var(--maroon);">#<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td style="white-space:nowrap;font-size:0.81rem;">
                            <i class="bi bi-calendar3" style="color:var(--maroon);font-size:.73rem"></i>
                            <?= date('M d, Y', strtotime($row['date_start'])) ?>
                            <?php if (!empty($row['date_end']) && $row['date_end'] !== $row['date_start']): ?>
                            <div style="color:#bbb;font-size:0.74rem;margin-top:1px;">→ <?= date('M d, Y', strtotime($row['date_end'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.79rem;color:#666;white-space:nowrap;">
                            <?= !empty($row['time_start']) ? date('h:i A', strtotime($row['time_start'])) : '—' ?>
                            <?php if (!empty($row['time_end'])): ?><div style="color:#bbb;font-size:0.74rem;margin-top:1px;">→ <?= date('h:i A', strtotime($row['time_end'])) ?></div><?php endif; ?>
                        </td>
                        <td style="font-weight:600;min-width:120px;"><i class="bi bi-geo-alt-fill" style="color:var(--maroon);font-size:.73rem"></i> <?= htmlspecialchars($row['destination']) ?></td>
                        <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#888;font-size:0.81rem;" title="<?= htmlspecialchars($row['purpose']) ?>"><?= htmlspecialchars($row['purpose']) ?></td>
                        <td><span class="pax-chip"><i class="bi bi-people-fill"></i> <?= (int)($row['passengers'] ?? 0) ?></span></td>
                        <td><span class="badge-status <?= $cls ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <button onclick='openViewModal(<?= $dataJson ?>)' class="btn-act btn-view"><i class="bi bi-eye-fill"></i> View</button>
                                <?php $rowStatus = strtolower($row['status']); if ($rowStatus === 'pending' || $rowStatus === 'approved'): ?>
                                <button onclick='openRescheduleModal(<?= $dataJson ?>)' class="btn-act btn-reschedule" title="Reschedule"><i class="bi bi-calendar2-week-fill"></i></button>
                                <button onclick='openCancelModal(<?= (int)$row["id"] ?>, "<?= htmlspecialchars(str_pad($row["id"], 4, "0", STR_PAD_LEFT)) ?>")' class="btn-act btn-cancel" title="Cancel"><i class="bi bi-x-lg"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-map"></i><p style="font-weight:700;color:#c0a0a0;margin-bottom:.3rem">No trips found</p><p><?= ($search || $filter_status) ? '<a href="my_trip.php">Clear filters</a> to see all trips.' : '<a href="new_request.php">Create your first request</a>' ?></p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->


<!-- ════ DESKTOP MODALS (unchanged) ════ -->

<!-- Trip Detail Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="trip-modal">
        <div class="trip-modal-hdr" id="vm-header"></div>
        <div class="trip-modal-body" id="vm-body"></div>
        <div class="trip-modal-ftr">
            <button onclick="closeModal('viewModal')" class="btn-act" style="background:#e2e3e5;color:#555;padding:9px 22px;font-size:0.85rem;"><i class="bi bi-x me-1"></i>Close</button>
        </div>
    </div>
</div>

<!-- Reschedule Modal -->
<div class="modal-overlay" id="rescheduleModal">
    <div class="reschedule-modal">
        <div class="reschedule-modal-hdr">
            <div style="color:#fff;">
                <div style="font-size:0.95rem;font-weight:800;display:flex;align-items:center;gap:8px;"><i class="bi bi-calendar2-week-fill"></i><span>Reschedule Request <span id="reschedule-req-num"></span></span></div>
                <div style="font-size:0.72rem;opacity:0.55;margin-top:2px;">Update the date and time of your trip</div>
            </div>
            <button onclick="closeModal('rescheduleModal')" class="modal-close-btn"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="reschedule-modal-body">
            <form id="rescheduleForm">
                <input type="hidden" id="reschedule_schedule_id" name="schedule_id">
                <input type="hidden" name="action" value="reschedule_trip">
                <div id="reschedule-current-info" style="background:#f0f4ff;border:1.5px solid #c7d7f9;border-radius:10px;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.83rem;color:#0a58ca;"></div>
                <div class="form-grid-2">
                    <div class="form-group"><label class="form-label-custom" style="color:#0a58ca;"><i class="bi bi-calendar3 me-1"></i>New Start Date</label><input type="date" id="reschedule_date_start" name="date_start" class="form-input-custom" required></div>
                    <div class="form-group"><label class="form-label-custom" style="color:#0a58ca;"><i class="bi bi-calendar3-range me-1"></i>New End Date</label><input type="date" id="reschedule_date_end" name="date_end" class="form-input-custom"><div style="font-size:0.7rem;color:#aaa;margin-top:3px;">Leave blank if same as start</div></div>
                    <div class="form-group"><label class="form-label-custom" style="color:#0a58ca;"><i class="bi bi-clock me-1"></i>New Start Time</label><input type="time" id="reschedule_time_start" name="time_start" class="form-input-custom" required></div>
                    <div class="form-group"><label class="form-label-custom" style="color:#0a58ca;"><i class="bi bi-clock-history me-1"></i>New End Time</label><input type="time" id="reschedule_time_end" name="time_end" class="form-input-custom"></div>
                </div>
            </form>
        </div>
        <div class="reschedule-modal-ftr">
            <button onclick="closeModal('rescheduleModal')" class="btn-act" style="background:#e2e3e5;color:#555;padding:9px 20px;font-size:0.85rem;">Discard</button>
            <button onclick="submitReschedule()" id="rescheduleSaveBtn" class="btn-act" style="background:#0a58ca;color:#fff;padding:9px 20px;font-size:0.85rem;">
                <i class="bi bi-calendar-check me-1"></i>
                <span id="rescheduleSaveTxt">Confirm Reschedule</span>
                <span class="spinner" id="rescheduleSpinner"><i class="bi bi-arrow-repeat spin ms-1"></i></span>
            </button>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal-overlay" id="cancelModal">
    <div class="cancel-modal">
        <div class="cancel-modal-hdr">
            <div style="color:#fff;"><div style="font-size:0.95rem;font-weight:800;display:flex;align-items:center;gap:8px;"><i class="bi bi-x-circle-fill"></i>Cancel Request <span id="cancel-req-num"></span></div><div style="font-size:0.72rem;opacity:0.55;margin-top:2px;">This action cannot be undone</div></div>
            <button onclick="closeModal('cancelModal')" class="modal-close-btn"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="cancel-modal-body">
            <p style="font-size:0.85rem;color:#666;margin-bottom:1rem;">Are you sure you want to cancel this trip request? Please provide a reason below.</p>
            <div class="form-group">
                <label class="form-label-custom"><i class="bi bi-chat-text me-1"></i>Cancellation Reason <span style="color:#aaa;font-weight:400;text-transform:none;font-size:0.75rem;">(optional)</span></label>
                <textarea id="cancel_reason" rows="3" class="form-input-custom" placeholder="e.g. Trip was rescheduled, No longer needed…" style="resize:vertical;"></textarea>
            </div>
            <input type="hidden" id="cancel_schedule_id">
        </div>
        <div class="cancel-modal-ftr">
            <button onclick="closeModal('cancelModal')" class="btn-act" style="background:#e2e3e5;color:#555;padding:9px 18px;font-size:0.85rem;">No, keep it</button>
            <button onclick="submitCancel()" id="cancelConfirmBtn" class="btn-act" style="background:#842029;color:#fff;padding:9px 18px;font-size:0.85rem;">
                <i class="bi bi-x-circle me-1"></i>
                <span id="cancelConfirmTxt">Yes, Cancel</span>
                <span class="spinner" id="cancelSpinner"><i class="bi bi-arrow-repeat spin ms-1"></i></span>
            </button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ══ Sidebar toggle ══ */
function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
    document.body.style.overflow =
        document.getElementById('mainSidebar').classList.contains('open') ? 'hidden' : '';
}

const SESSION_NAME = <?= json_encode($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?>;

/* ══ Mobile sheet helpers ══ */
function mobOpenSheet(id) {
    document.getElementById('mobBackdrop').classList.add('open');
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function mobCloseAll() {
    document.getElementById('mobBackdrop').classList.remove('open');
    ['mobViewSheet','mobReschedSheet','mobCancelSheet'].forEach(id => {
        const el = document.getElementById(id); if(el) el.classList.remove('open');
    });
    document.body.style.overflow = '';
}

/* ══ Mobile search ══ */
function onMobSearch(inp) {
    const q = inp.value.toLowerCase().trim();
    document.getElementById('mobSearchClear').style.display = q ? 'flex' : 'none';
    document.querySelectorAll('.mob-trip-row').forEach(c => {
        c.style.display = (!q || c.dataset.search.includes(q)) ? '' : 'none';
    });
}
function clearMobSearch() {
    const inp = document.getElementById('mobTripSearch'); inp.value = ''; inp.focus(); onMobSearch(inp);
}

/* ══ Format helpers ══ */
const fmtDate = d => {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
};
const fmtTime = v => {
    if (!v) return '—';
    const p = v.split(':'); let h = parseInt(p[0]); const m = p[1] || '00';
    const ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
    return String(h).padStart(2,'0') + ':' + m + ' ' + ap;
};
const fmtDT = d => {
    if (!d) return null;
    const dt = new Date(d); if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'}) + ' · ' + dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
};
const val = v => (v && String(v).trim() !== '' && String(v).trim() !== 'null') ? v : null;

/* ══ Mobile View Sheet ══ */
function mobOpenView(t) {
    const sKey = (t.status||'').toLowerCase().replace(/[\s\-]/g,'');
    const badgeMap = {pending:'background:#fff3cd;color:#856404',approved:'background:#d1e7dd;color:#0f5132',completed:'background:#cfe2ff;color:#0a3678',rejected:'background:#f8d7da;color:#842029',cancelled:'background:#e2e3e5;color:#41464b',ongoing:'background:#fff0d6;color:#7a4f00',ontrip:'background:#fff0d6;color:#7a4f00'};
    const bStyle = badgeMap[sKey] || 'background:#fff3cd;color:#856404';
    const reqNum = '#' + String(t.id).padStart(4,'0');
    const pax = parseInt(t.passengers) || 0;
    const dateRange = (t.date_end && t.date_end !== t.date_start) ? fmtDate(t.date_start) + ' → ' + fmtDate(t.date_end) : fmtDate(t.date_start);
    const timeRange = fmtTime(t.time_start) + (t.time_end ? ' → ' + fmtTime(t.time_end) : '');

    document.getElementById('mobViewHead').innerHTML = `<i class="bi bi-receipt-cutoff"></i> ${reqNum}`;

    let html = `
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
            <span style="padding:4px 12px;border-radius:20px;font-size:.76rem;font-weight:700;${bStyle}">${t.status}</span>
            ${val(t.trip_ticket_no) ? `<span style="background:#d1e7dd;color:#0f5132;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:700"><i class="bi bi-ticket-perforated-fill me-1"></i>${t.trip_ticket_no}</span>` : ''}
            <span style="font-size:.7rem;color:#bbb;margin-left:auto">${fmtDT(t.created_at)||'—'}</span>
        </div>

        <div class="det-section" style="background:#fff7ed;border:1px solid #fed7aa">
            <div class="det-section-hdr" style="color:#c2410c"><i class="bi bi-geo-alt-fill"></i> Trip Info</div>
            <div class="det-row"><span class="det-lbl">Destination</span><span class="det-val">${val(t.destination)||'—'}</span></div>
            <div class="det-row"><span class="det-lbl">Purpose</span><span class="det-val">${val(t.purpose)||'—'}</span></div>
            <div class="det-row"><span class="det-lbl">Date</span><span class="det-val">${dateRange}</span></div>
            <div class="det-row"><span class="det-lbl">Time</span><span class="det-val">${timeRange}</span></div>
            <div class="det-row"><span class="det-lbl">Passengers</span><span class="det-val">${pax} passenger${pax!==1?'s':''}</span></div>
        </div>

        <div class="det-section" style="background:#eff6ff;border:1px solid #bfdbfe">
            <div class="det-section-hdr" style="color:#1d4ed8"><i class="bi bi-truck-front-fill"></i> Vehicle & Driver</div>
            <div class="det-row"><span class="det-lbl">Vehicle</span><span class="det-val">${val(t.vehicle_name)||'Not assigned yet'}</span></div>
            <div class="det-row"><span class="det-lbl">Plate</span><span class="det-val">${val(t.plate_number)||'Not assigned yet'}</span></div>
            <div class="det-row"><span class="det-lbl">Driver</span><span class="det-val">${val(t.driver_name)||'Not assigned yet'}</span></div>
            ${val(t.driver_phone) ? `<div class="det-row"><span class="det-lbl">Phone</span><span class="det-val">${t.driver_phone}</span></div>` : ''}
        </div>

        <div class="det-section">
            <div class="det-section-hdr"><i class="bi bi-building"></i> Office & Department</div>
            <div class="det-row"><span class="det-lbl">Office</span><span class="det-val">${val(t.office_name)||'—'}</span></div>
            <div class="det-row"><span class="det-lbl">Department</span><span class="det-val">${val(t.dept_name)||'—'}</span></div>
        </div>`;

    if (val(t.arrived_at)) {
        html += `<div class="det-section" style="background:#f0fdf4;border:1px solid #bbf7d0">
            <div class="det-section-hdr" style="color:#166534"><i class="bi bi-flag-fill"></i> Completion</div>
            <div class="det-row"><span class="det-lbl">Arrived</span><span class="det-val" style="color:#166534">${fmtDT(t.arrived_at)}</span></div>
        </div>`;
    }

    if (val(t.rejection_reason)) {
        html += `<div class="det-section" style="background:#fef2f2;border:1px solid #fecaca">
            <div class="det-section-hdr" style="color:#991b1b"><i class="bi bi-x-circle-fill"></i> Rejection Reason</div>
            <div style="font-size:.8rem;color:#991b1b;font-weight:600">${t.rejection_reason}</div>
        </div>`;
    }

    if (val(t.cancel_reason)) {
        html += `<div class="det-section" style="background:#fff3e0;border:1px solid #ffe0b2">
            <div class="det-section-hdr" style="color:#7a4f00"><i class="bi bi-slash-circle-fill"></i> Cancellation</div>
            <div style="font-size:.8rem;color:#7a4f00;font-weight:600;margin-bottom:5px">${t.cancel_reason}</div>
            ${val(t.cancelled_by) ? `<div style="font-size:.72rem;color:#7a4f00;background:#ffe8c0;border-radius:7px;padding:4px 9px;display:inline-block"><i class="bi bi-person-x-fill me-1"></i>Cancelled by: ${t.cancelled_by}</div>` : ''}
        </div>`;
    }

    document.getElementById('mobViewBody').innerHTML = html;
    mobOpenSheet('mobViewSheet');
}

/* ══ Mobile Reschedule ══ */
function mobOpenResched(t) {
    document.getElementById('mob_resched_id').value = t.id;
    document.getElementById('mob_resched_ds').value = t.date_start || '';
    document.getElementById('mob_resched_de').value = (t.date_end && t.date_end !== t.date_start) ? t.date_end : '';
    document.getElementById('mob_resched_ts').value = t.time_start || '';
    document.getElementById('mob_resched_te').value = t.time_end || '';
    const dateRange = (t.date_end && t.date_end !== t.date_start) ? fmtDate(t.date_start) + ' → ' + fmtDate(t.date_end) : fmtDate(t.date_start);
    document.getElementById('mobReschedInfo').innerHTML =
        `<div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;opacity:.7;margin-bottom:5px">Current Schedule</div>
         <div style="display:flex;gap:12px;flex-wrap:wrap">
             <span><i class="bi bi-calendar3 me-1"></i><strong>${dateRange}</strong></span>
             <span><i class="bi bi-clock me-1"></i><strong>${fmtTime(t.time_start)} → ${fmtTime(t.time_end)}</strong></span>
         </div>`;
    mobOpenSheet('mobReschedSheet');
}

function mobSubmitResched() {
    const form = document.getElementById('mobReschedForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const txt = document.getElementById('mobReschedTxt'), spin = document.getElementById('mobReschedSpin');
    txt.textContent = 'Saving…'; spin.style.display = 'inline-block';
    fetch(window.location.href, { method: 'POST', body: new FormData(form) })
        .then(r => r.json())
        .then(res => {
            if (res.success) { showToast('Trip rescheduled!', 'success'); mobCloseAll(); setTimeout(() => location.reload(), 800); }
            else showToast(res.message || 'Failed.', 'error');
        })
        .catch(() => showToast('Network error.', 'error'))
        .finally(() => { txt.textContent = 'Confirm'; spin.style.display = 'none'; });
}

/* ══ Mobile Cancel ══ */
function mobOpenCancel(id, reqNum) {
    document.getElementById('mob_cancel_id').value = id;
    document.getElementById('mobCancelReqNum').textContent = '#' + reqNum;
    document.getElementById('mob_cancel_reason').value = '';
    mobOpenSheet('mobCancelSheet');
}

function mobSubmitCancel() {
    const id = document.getElementById('mob_cancel_id').value;
    const reason = document.getElementById('mob_cancel_reason').value;
    const txt = document.getElementById('mobCancelTxt'), spin = document.getElementById('mobCancelSpin');
    txt.textContent = 'Cancelling…'; spin.style.display = 'inline-block';
    const data = new FormData();
    data.append('action', 'cancel_trip'); data.append('schedule_id', id); data.append('cancel_reason', reason);
    fetch(window.location.href, { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => {
            if (res.success) { showToast('Trip cancelled.', 'success'); mobCloseAll(); setTimeout(() => location.reload(), 800); }
            else showToast(res.message || 'Failed.', 'error');
        })
        .catch(() => showToast('Network error.', 'error'))
        .finally(() => { txt.textContent = 'Yes, Cancel'; spin.style.display = 'none'; });
}

/* ══ Toast ══ */
function showToast(msg, type = 'success') {
    const wrap = document.getElementById('toastWrap');
    const t = document.createElement('div'); t.className = 'toast-msg ' + type;
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
    t.innerHTML = `<i class="bi ${icon}"></i> ${msg}`;
    wrap.appendChild(t); setTimeout(() => t.remove(), 3500);
}

/* ══ Modal helpers (desktop) ══ */
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
function openModal(id)  { document.getElementById(id).classList.add('show'); }
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});

/* ══ Desktop view modal ══ */
function getStepperHTML(status) {
    const steps = ['Trip Details', 'Admin Review', 'Approved', 'Completed'];
    const statusMap = { 'pending':1, 'approved':2, 'ongoing':2, 'completed':3, 'rejected':1, 'cancelled':1 };
    const s = status.toLowerCase();
    const currentStep = statusMap[s] ?? 1;
    let html = '<div style="padding:0.75rem 1.5rem 1rem;background:linear-gradient(135deg,#800000 0%,#4a0000 100%)"><div style="display:flex;align-items:center;">';
    steps.forEach((label, i) => {
        const isDone=i<currentStep, isActive=i===currentStep;
        const nodeStyle=isDone?'background:#fff;color:#800000;border:2px solid #fff;':isActive?'background:#fff;color:#800000;border:2px solid #fff;box-shadow:0 0 0 4px rgba(255,255,255,0.25);':'background:rgba(255,255,255,0.18);color:rgba(255,255,255,0.5);border:2px solid rgba(255,255,255,0.35);';
        const labelColor=isDone?'rgba(255,255,255,0.9)':isActive?'#fff':'rgba(255,255,255,0.4)';
        html += `<div style="display:flex;align-items:center;flex:${i<steps.length-1?'1':'0'};">`;
        html += `<div style="width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;position:relative;z-index:1;${nodeStyle}">${isDone?'✓':(i+1)}</div>`;
        html += `<span style="font-size:10px;font-weight:700;margin-left:5px;white-space:nowrap;color:${labelColor};">${label}</span></div>`;
        if(i<steps.length-1){ const lineColor=isDone?'rgba(255,255,255,0.75)':'rgba(255,255,255,0.2)'; html+=`<div style="flex:1;height:2px;margin:0 6px;background:${lineColor};flex-shrink:0;min-width:8px;"></div>`; }
    });
    html += '</div></div>'; return html;
}

function openViewModal(t) {
    const sKey=(t.status||'').toLowerCase().replace(/[\s\-]/g,'');
    const badgeMap={pending:'background:#fff3cd;color:#856404',approved:'background:#d1e7dd;color:#0f5132',completed:'background:#cfe2ff;color:#0a3678',rejected:'background:#f8d7da;color:#842029',cancelled:'background:#e2e3e5;color:#41464b',ongoing:'background:#fff0d6;color:#7a4f00',ontrip:'background:#fff0d6;color:#7a4f00'};
    const badgeStyle=badgeMap[sKey]||'background:#fff3cd;color:#856404';
    const reqNum='#'+String(t.id).padStart(4,'0');
    const pax=parseInt(t.passengers)||0;
    const bookedBy=val(t.booked_by_staff)||SESSION_NAME;
    const avatarLetter=bookedBy.trim().charAt(0).toUpperCase();
    const dateRange=(t.date_end&&t.date_end!==t.date_start)?fmtDate(t.date_start)+' → '+fmtDate(t.date_end):fmtDate(t.date_start);
    const timeRange=fmtTime(t.time_start)+(t.time_end?' → '+fmtTime(t.time_end):'');
    document.getElementById('vm-header').innerHTML=`<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;"><div><div style="font-size:1rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px;"><i class="bi bi-receipt-cutoff"></i> Request ${reqNum}</div><div style="font-size:0.72rem;color:rgba(255,255,255,0.65);margin-top:3px;"><i class="bi bi-calendar3 me-1"></i>Submitted: ${fmtDT(t.created_at)||'—'}</div></div><div style="display:flex;align-items:center;gap:10px;"><span style="padding:4px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;${badgeStyle}">${t.status}</span><button onclick="closeModal('viewModal')" class="modal-close-btn"><i class="bi bi-x-lg"></i></button></div></div>${getStepperHTML(t.status)}`;
    document.getElementById('vm-body').innerHTML=`<div style="display:flex;align-items:center;gap:10px;background:#fdf5f5;border:1px solid #f0e5e5;border-radius:10px;padding:0.6rem 1rem;margin-bottom:1rem;flex-wrap:wrap;"><div style="width:36px;height:36px;border-radius:50%;background:var(--maroon);color:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:0.88rem;font-weight:800;">${avatarLetter}</div><div><div style="font-size:0.65rem;color:#bbb;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Requestor</div><div style="font-size:0.88rem;font-weight:700;color:var(--text);">${bookedBy}</div></div><div style="margin-left:auto;text-align:right;"><div style="font-size:0.65rem;color:#bbb;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Submitted</div><div style="font-size:0.78rem;font-weight:600;color:#666;">${fmtDT(t.created_at)||'—'}</div></div></div>
    <div class="td-section-label"><i class="bi bi-map-fill"></i> Trip Information</div>
    <div class="td-grid" style="margin-bottom:0.85rem;">
        <div><div class="td-item-label"><i class="bi bi-geo-alt-fill me-1" style="color:var(--maroon)"></i>Destination</div><div class="td-item-value ${!val(t.destination)?'empty':''}">${val(t.destination)||'N/A'}</div></div>
        <div><div class="td-item-label"><i class="bi bi-journal-text me-1" style="color:var(--maroon)"></i>Purpose</div><div class="td-item-value ${!val(t.purpose)?'empty':''}">${val(t.purpose)||'N/A'}</div></div>
        <div><div class="td-item-label"><i class="bi bi-calendar3 me-1" style="color:var(--maroon)"></i>Date</div><div class="td-item-value">${dateRange}</div></div>
        <div><div class="td-item-label"><i class="bi bi-clock me-1" style="color:var(--maroon)"></i>Time</div><div class="td-item-value">${timeRange}</div></div>
        <div><div class="td-item-label"><i class="bi bi-people-fill me-1" style="color:#6f42c1"></i>Passengers</div><div class="td-item-value"><span class="pax-chip"><i class="bi bi-people-fill"></i> ${pax} passenger${pax!==1?'s':''}</span></div></div>
    </div>
    <div class="td-section-label"><i class="bi bi-truck-front-fill"></i> Vehicle & Driver</div>
    <div class="td-grid" style="margin-bottom:0.85rem;">
        <div><div class="td-item-label">Vehicle</div><div class="td-item-value ${!val(t.vehicle_name)?'empty':''}">${val(t.vehicle_name)||'Not assigned yet'}</div></div>
        <div><div class="td-item-label">Plate</div><div class="td-item-value ${!val(t.plate_number)?'empty':''}">${val(t.plate_number)||'Not assigned yet'}</div></div>
        <div><div class="td-item-label">Driver</div><div class="td-item-value ${!val(t.driver_name)?'empty':''}">${val(t.driver_name)||'Not assigned yet'}</div></div>
        <div><div class="td-item-label">Driver Phone</div><div class="td-item-value ${!val(t.driver_phone)?'empty':''}">${val(t.driver_phone)||'—'}</div></div>
    </div>
    ${val(t.rejection_reason)?`<div style="background:#fdf3f3;border:1.5px solid #f5c6cb;border-radius:10px;padding:0.85rem 1rem;margin-top:0.5rem;"><div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:.07em;color:#842029;font-weight:800;margin-bottom:5px;"><i class="bi bi-x-circle-fill me-1"></i>Rejection Reason</div><div style="font-size:0.85rem;color:#842029;font-weight:500;">${t.rejection_reason}</div></div>`:''}
    ${val(t.cancel_reason)?`<div style="background:#fff3e0;border:1.5px solid #ffe0b2;border-radius:10px;padding:0.85rem 1rem;margin-top:0.5rem;"><div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:.07em;color:#7a4f00;font-weight:800;margin-bottom:5px;"><i class="bi bi-slash-circle-fill me-1"></i>Cancellation</div><div style="font-size:0.85rem;color:#7a4f00;font-weight:500;">${t.cancel_reason}</div>${val(t.cancelled_by)?`<div style="margin-top:8px;display:inline-flex;align-items:center;gap:6px;background:#ffe8c0;border-radius:8px;padding:4px 10px;font-size:0.78rem;font-weight:700;color:#7a4f00;"><i class="bi bi-person-x-fill"></i>Cancelled by <strong>${t.cancelled_by}</strong></div>`:''}</div>`:''}`;
    openModal('viewModal');
}

function openRescheduleModal(t) {
    document.getElementById('reschedule-req-num').textContent='#'+String(t.id).padStart(4,'0');
    document.getElementById('reschedule_schedule_id').value=t.id;
    document.getElementById('reschedule_date_start').value=t.date_start||'';
    document.getElementById('reschedule_date_end').value=(t.date_end&&t.date_end!==t.date_start)?t.date_end:'';
    document.getElementById('reschedule_time_start').value=t.time_start||'';
    document.getElementById('reschedule_time_end').value=t.time_end||'';
    const dateRange=(t.date_end&&t.date_end!==t.date_start)?fmtDate(t.date_start)+' → '+fmtDate(t.date_end):fmtDate(t.date_start);
    document.getElementById('reschedule-current-info').innerHTML=`<div style="font-size:0.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;margin-bottom:5px;opacity:0.7;">Current Schedule</div><div style="display:flex;gap:1.25rem;flex-wrap:wrap;"><span><i class="bi bi-calendar3 me-1"></i><strong>${dateRange}</strong></span><span><i class="bi bi-clock me-1"></i><strong>${fmtTime(t.time_start)} → ${fmtTime(t.time_end)}</strong></span></div>`;
    openModal('rescheduleModal');
}

function submitReschedule() {
    const form=document.getElementById('rescheduleForm');
    if(!form.checkValidity()){form.reportValidity();return;}
    const btn=document.getElementById('rescheduleSaveBtn'),txt=document.getElementById('rescheduleSaveTxt'),spin=document.getElementById('rescheduleSpinner');
    btn.disabled=true; txt.textContent='Saving…'; spin.classList.add('show');
    fetch(window.location.href,{method:'POST',body:new FormData(form)})
        .then(r=>r.json()).then(res=>{
            if(res.success){showToast('Trip rescheduled successfully!','success');closeModal('rescheduleModal');setTimeout(()=>location.reload(),900);}
            else showToast(res.message||'Reschedule failed.','error');
        }).catch(()=>showToast('Network error.','error'))
        .finally(()=>{btn.disabled=false;txt.textContent='Confirm Reschedule';spin.classList.remove('show');});
}

function openCancelModal(id,reqNum){
    document.getElementById('cancel-req-num').textContent='#'+reqNum;
    document.getElementById('cancel_schedule_id').value=id;
    document.getElementById('cancel_reason').value='';
    openModal('cancelModal');
}

function submitCancel(){
    const id=document.getElementById('cancel_schedule_id').value,reason=document.getElementById('cancel_reason').value;
    const btn=document.getElementById('cancelConfirmBtn'),txt=document.getElementById('cancelConfirmTxt'),spin=document.getElementById('cancelSpinner');
    btn.disabled=true; txt.textContent='Cancelling…'; spin.classList.add('show');
    const data=new FormData(); data.append('action','cancel_trip'); data.append('schedule_id',id); data.append('cancel_reason',reason);
    fetch(window.location.href,{method:'POST',body:data})
        .then(r=>r.json()).then(res=>{
            if(res.success){showToast('Trip request cancelled.','success');closeModal('cancelModal');setTimeout(()=>location.reload(),900);}
            else showToast(res.message||'Cancel failed.','error');
        }).catch(()=>showToast('Network error.','error'))
        .finally(()=>{btn.disabled=false;txt.textContent='Yes, Cancel';spin.classList.remove('show');});
}
</script>
</body>
</html>