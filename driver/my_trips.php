<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireDriver();

$driver_id = $_SESSION['user_id'];

// ── Handle "Trip Done" AJAX ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'trip_done') {
    header('Content-Type: application/json');
    $schedule_id  = (int)($_POST['schedule_id']  ?? 0);
    $arrived_date = trim($_POST['arrived_date']   ?? '');
    $arrived_time = trim($_POST['arrived_time']   ?? '');

    if ($schedule_id > 0 && $arrived_date && $arrived_time) {
        $arrived_at = $arrived_date . ' ' . $arrived_time . ':00';
        $chk = $pdo->prepare("SELECT schedule_id FROM schedules WHERE schedule_id = ? AND driver_id = ? AND status = 'OnTrip'");
        $chk->execute([$schedule_id, $driver_id]);
        if ($chk->fetch()) {
            $upd = $pdo->prepare("UPDATE schedules SET status = 'Completed', arrived_at = ? WHERE schedule_id = ? AND driver_id = ?");
            $upd->execute([$arrived_at, $schedule_id, $driver_id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Not authorized or not on trip.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Please provide arrival date and time.']);
    }
    exit;
}

$stmt = $pdo->prepare("
    SELECT d.*, o.office_name
    FROM drivers d
    LEFT JOIN offices o ON d.office_id = o.office_id
    WHERE d.driver_id = ?
");
$stmt->execute([$driver_id]);
$driver = $stmt->fetch();

$displayName = $driver['driver_name'] ?? $_SESSION['username'] ?? 'Driver';
$initials = implode('', array_map(
    fn($w) => strtoupper($w[0]),
    array_slice(explode(' ', $displayName), 0, 2)
));

$filterStatus = $_GET['status'] ?? 'all';
$filterSearch = trim($_GET['search'] ?? '');
$filterFrom   = $_GET['from'] ?? '';
$filterTo     = $_GET['to'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;

$allowed = ['all','Approved','OnTrip','Completed','Cancelled'];
if (!in_array($filterStatus, $allowed)) $filterStatus = 'all';

$where  = ['s.driver_id = :driver_id'];
$params = [':driver_id' => $driver_id];

if ($filterStatus !== 'all') {
    $where[]           = 's.status = :status';
    $params[':status'] = $filterStatus;
}
if ($filterSearch !== '') {
    $where[]           = '(s.destination LIKE :search OR u.username LIKE :search OR v.plate_number LIKE :search)';
    $params[':search'] = '%' . $filterSearch . '%';
}
if ($filterFrom !== '') {
    $where[]         = 'DATE(s.date_start) >= :from';
    $params[':from'] = $filterFrom;
}
if ($filterTo !== '') {
    $where[]       = 'DATE(s.date_start) <= :to';
    $params[':to'] = $filterTo;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$cntStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM schedules s
    LEFT JOIN users u    ON s.user_id    = u.user_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    $whereSQL
");
$cntStmt->execute($params);
$totalRows  = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$tripsStmt = $pdo->prepare("
    SELECT s.*,
           s.signed_ticket_path,
           u.username                    AS requestor_name,
           v.plate_number                AS plate_no,
           CONCAT(v.brand, ' ', v.model) AS vehicle_name,
           o.office_name                 AS office_name
    FROM schedules s
    LEFT JOIN users u    ON s.user_id    = u.user_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    LEFT JOIN offices o  ON s.office_id  = o.office_id
    $whereSQL
    ORDER BY s.date_start DESC
    LIMIT :limit OFFSET :offset
");
$tripsStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$tripsStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $k => $v) {
    $tripsStmt->bindValue($k, $v);
}
$tripsStmt->execute();
$trips = $tripsStmt->fetchAll();

$counts = [];
foreach (['all','Approved','OnTrip','Completed','Cancelled'] as $s) {
    $cBase   = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE driver_id = ?" . ($s !== 'all' ? " AND status = ?" : ""));
    $cParams = $s !== 'all' ? [$driver_id, $s] : [$driver_id];
    $cBase->execute($cParams);
    $counts[$s] = (int)$cBase->fetchColumn();
}

function tripBadge(string $st): string {
    return match($st) {
        'Approved'   => '<span class="badge-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>',
        'OnTrip'     => '<span class="badge-ontrip"><i class="bi bi-geo-alt me-1"></i>On Trip</span>',
        'Completed'  => '<span class="badge-completed"><i class="bi bi-check2-all me-1"></i>Completed</span>',
        'Cancelled'  => '<span class="badge-cancelled"><i class="bi bi-x-circle me-1"></i>Cancelled</span>',
        default      => '<span class="badge-other">' . htmlspecialchars($st) . '</span>',
    };
}

function paginateUrl(int $p, array $get): string {
    $q = array_merge($get, ['page' => $p]);
    unset($q['page']);
    if ($p > 1) $q['page'] = $p;
    return '?' . http_build_query($q);
}

$tabDefs = [
    'all'       => ['label' => 'All',       'icon' => 'bi-list-ul'],
    'Approved'  => ['label' => 'Approved',  'icon' => 'bi-check-circle'],
    'OnTrip'    => ['label' => 'On Trip',   'icon' => 'bi-geo-alt'],
    'Completed' => ['label' => 'Completed', 'icon' => 'bi-check2-all'],
    'Cancelled' => ['label' => 'Cancelled', 'icon' => 'bi-x-circle'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Trips – CSU VSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }

        /* ── SIDEBAR ── */
        .sidebar {
            min-height: 100vh; background: #7a0000;
            width: 230px; position: fixed; top: 0; left: 0; z-index: 200;
            display: flex; flex-direction: column; overflow-y: auto;
        }
        .sidebar-brand {
            padding: 1.1rem 1rem 0.9rem;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            display: flex; align-items: center; gap: 10px;
        }
        .sidebar-logo {
            width: 38px; height: 38px; border-radius: 50%;
            background: #fff; overflow: hidden;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .sidebar-logo img { width: 34px; height: 34px; object-fit: contain; }
        .sidebar-brand-text { color: #fff; font-size: 0.8rem; font-weight: 700; line-height: 1.3; }
        .sidebar-brand-text span { display: block; font-size: 0.7rem; font-weight: 400; opacity: 0.6; }
        .nav-section-label {
            padding: 0.7rem 1.1rem 0.2rem;
            font-size: 0.65rem; font-weight: 700;
            color: rgba(255,255,255,0.38); letter-spacing: 0.08em; text-transform: uppercase;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.78); padding: 0.55rem 1.1rem;
            font-size: 0.85rem; display: flex; align-items: center; gap: 9px;
            border-left: 3px solid transparent; transition: all 0.15s;
        }
        .sidebar .nav-link:hover  { color: #fff; background: rgba(255,255,255,0.09); border-left-color: rgba(255,255,255,0.35); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.14); border-left-color: #fff; font-weight: 600; }
        .sidebar .nav-link i { font-size: 0.95rem; width: 17px; }
        .sidebar-divider { border-color: rgba(255,255,255,0.13); margin: 0.4rem 1rem; }

        /* ── TOPBAR ── */
        .topbar {
            background: #fff; border-bottom: 1px solid #e8dede;
            padding: 0.65rem 1.4rem; margin-left: 230px;
            position: sticky; top: 0; z-index: 99;
            display: flex; align-items: center; justify-content: space-between;
        }
        .topbar-title { font-weight: 700; font-size: 0.95rem; color: #7a0000; display: flex; align-items: center; gap: 7px; }
        .topbar-user  { display: flex; align-items: center; gap: 8px; }
        .user-avatar  {
            width: 30px; height: 30px; border-radius: 50%;
            background: #7a0000; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700;
        }

        /* ── MAIN CONTENT ── */
        .main-content { margin-left: 230px; padding: 1.25rem 1.4rem; }
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.1rem; }
        .page-header-title { font-size: 1.15rem; font-weight: 800; color: #7a0000; display: flex; align-items: center; gap: 8px; }
        .page-header-sub { font-size: 0.75rem; color: #999; margin-top: 1px; }
        .total-badge { background: #faeeda; color: #854f0b; font-size: 0.72rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; }

        /* ── FILTER BAR ── */
        .filter-bar {
            background: #fff; border-radius: 14px; border: 1px solid #f0e5e5;
            padding: 1rem 1.1rem; margin-bottom: 1rem;
            display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap;
        }
        .filter-bar .form-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-bar label { font-size: 0.68rem; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: 0.05em; }
        .filter-bar input, .filter-bar select {
            border: 1px solid #ede0e0; border-radius: 8px;
            padding: 0.42rem 0.7rem; font-size: 0.82rem; color: #333;
            background: #fdf9f9; height: 34px;
        }
        .filter-bar input:focus, .filter-bar select:focus { outline: none; border-color: #7a0000; background: #fff; }
        .filter-bar .search-wrap { position: relative; }
        .filter-bar .search-wrap i { position: absolute; left: 9px; top: 50%; transform: translateY(-50%); color: #bbb; font-size: 0.85rem; }
        .filter-bar .search-wrap input { padding-left: 28px; min-width: 200px; }
        .btn-filter {
            background: #7a0000; color: #fff; border: none; border-radius: 8px;
            padding: 0.42rem 1rem; font-size: 0.82rem; font-weight: 600; cursor: pointer;
            height: 34px; display: flex; align-items: center; gap: 6px;
        }
        .btn-filter:hover { background: #5e0000; }
        .btn-reset {
            background: #fff; color: #7a0000; border: 1px solid #ede0e0;
            border-radius: 8px; padding: 0.42rem 0.85rem;
            font-size: 0.82rem; font-weight: 600; cursor: pointer;
            height: 34px; display: flex; align-items: center; gap: 5px; text-decoration: none;
        }
        .btn-reset:hover { background: #fdf5f5; }

        /* ── STATUS TABS ── */
        .status-tabs { display: flex; gap: 4px; margin-bottom: 1rem; flex-wrap: wrap; }
        .status-tab {
            padding: 5px 13px; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
            border: 1.5px solid #ede0e0; background: #fff; color: #888;
            cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 5px;
            transition: all 0.15s;
        }
        .status-tab:hover { border-color: #c0a0a0; color: #555; }
        .status-tab.active { background: #7a0000; border-color: #7a0000; color: #fff; }
        .tab-count { background: rgba(0,0,0,0.12); border-radius: 10px; padding: 0 6px; font-size: 0.68rem; line-height: 1.6; }
        .status-tab.active .tab-count { background: rgba(255,255,255,0.25); }

        /* ── TABLE CARD ── */
        .section-card { background: #fff; border-radius: 14px; border: 1px solid #f0e5e5; overflow: hidden; }
        .section-header {
            padding: 0.85rem 1.1rem; border-bottom: 1px solid #f0e5e5;
            font-weight: 700; font-size: 0.85rem; color: #7a0000;
            display: flex; align-items: center; justify-content: space-between;
        }
        .table thead th {
            background: #fdf5f5; color: #7a0000; font-size: 0.72rem;
            font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
            border-bottom: 1px solid #f0e5e5; padding: 0.65rem 0.85rem;
        }
        .table tbody td {
            padding: 0.7rem 0.85rem; font-size: 0.82rem; color: #444;
            vertical-align: middle; border-color: #fdf5f5;
        }
        .table tbody tr:hover { background: #fdf8f8; }

        /* ── BADGES ── */
        .badge-approved  { background: #eaf3de; color: #3b6d11;  padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .badge-ontrip    { background: #e6f1fb; color: #185fa5;  padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .badge-completed { background: #eaf3de; color: #3b6d11;  padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .badge-cancelled { background: #fce8e8; color: #a32d2d;  padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .badge-other     { background: #f5f5f5; color: #555;     padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }

        /* ── TRIP DONE BUTTON ── */
        .btn-trip-done {
            display: inline-flex; align-items: center; gap: 5px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            border: none; border-radius: 8px; padding: 5px 11px;
            font-size: .75rem; font-weight: 700; color: #fff;
            cursor: pointer; white-space: nowrap;
            box-shadow: 0 2px 6px rgba(22,163,74,0.35);
            transition: all 0.15s;
        }
        .btn-trip-done:hover { background: linear-gradient(135deg, #15803d, #166534); box-shadow: 0 3px 10px rgba(22,163,74,0.45); transform: translateY(-1px); }
        .btn-trip-done:active { transform: translateY(0); }
        .btn-trip-done.loading { opacity: 0.7; pointer-events: none; }

        /* ── EMPTY STATE ── */
        .empty-state { text-align: center; padding: 3.5rem 1rem; }
        .empty-state i { font-size: 2.5rem; color: #ddd; display: block; margin-bottom: 0.75rem; }
        .empty-state p { color: #aaa; font-size: 0.85rem; margin: 0; }

        /* ── PAGINATION ── */
        .pagination-wrap {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.85rem 1.1rem; border-top: 1px solid #f0e5e5;
            font-size: 0.78rem; color: #888; flex-wrap: wrap; gap: 8px;
        }
        .pg-links { display: flex; gap: 4px; }
        .pg-btn {
            width: 30px; height: 30px; border-radius: 7px;
            border: 1px solid #ede0e0; background: #fff; color: #555;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.78rem; font-weight: 600; text-decoration: none; transition: all 0.12s;
        }
        .pg-btn:hover { border-color: #c0a0a0; color: #7a0000; }
        .pg-btn.active { background: #7a0000; border-color: #7a0000; color: #fff; }
        .pg-btn.disabled { opacity: 0.4; pointer-events: none; }

        /* ── MODALS ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            z-index: 1000; align-items: center; justify-content: center; padding: 1rem;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff; border-radius: 16px; width: 100%; max-width: 520px;
            max-height: 90vh; overflow-y: auto; animation: slideUp 0.22s ease;
        }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header {
            padding: 1rem 1.25rem; border-bottom: 1px solid #f0e5e5;
            display: flex; align-items: center; justify-content: space-between;
        }
        .modal-title { font-size: 0.92rem; font-weight: 700; color: #7a0000; display: flex; align-items: center; gap: 7px; }
        .modal-close {
            width: 28px; height: 28px; border-radius: 50%;
            background: #fdf5f5; border: 1px solid #f0e5e5;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #7a0000; font-size: 0.9rem;
        }
        .modal-close:hover { background: #f0e0e0; }
        .modal-body { padding: 1.1rem 1.25rem; }
        .modal-dest { font-size: 1.05rem; font-weight: 800; color: #1a1a1a; margin-bottom: 4px; }
        .modal-section-label {
            font-size: 0.65rem; font-weight: 700; color: #aaa;
            text-transform: uppercase; letter-spacing: 0.07em; margin: 1rem 0 6px;
        }
        .modal-row {
            display: flex; align-items: flex-start; justify-content: space-between;
            padding: 7px 0; border-bottom: 1px solid #fdf0f0;
        }
        .modal-row:last-child { border-bottom: none; }
        .modal-key { font-size: 0.78rem; color: #888; display: flex; align-items: center; gap: 6px; }
        .modal-key i { font-size: 0.85rem; color: #7a0000; }
        .modal-val { font-size: 0.78rem; font-weight: 600; color: #333; text-align: right; max-width: 60%; }
        .modal-purpose-box {
            background: #fdf5f5; border-radius: 10px;
            padding: 0.75rem 0.9rem; font-size: 0.8rem; color: #555; line-height: 1.55;
            border: 1px solid #f0e5e5;
        }
        .modal-footer { padding: 0.85rem 1.25rem; border-top: 1px solid #f0e5e5; display: flex; justify-content: flex-end; }
        .btn-close-modal {
            background: #7a0000; color: #fff; border: none;
            border-radius: 8px; padding: 0.45rem 1.2rem;
            font-size: 0.82rem; font-weight: 600; cursor: pointer;
        }
        .btn-close-modal:hover { background: #5e0000; }
        #viewSignedDrvModal { z-index: 1100 !important; }

        /* ── RECORD TRIP COMPLETION MODAL ── */
        .confirm-modal-box {
            background: #fff; border-radius: 18px; width: 100%; max-width: 480px;
            animation: slideUp 0.22s ease; overflow: hidden;
        }
        .confirm-modal-header {
            background: linear-gradient(135deg, #16a34a, #15803d);
            padding: 1.1rem 1.4rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .confirm-modal-header-left { display: flex; align-items: center; gap: 10px; }
        .confirm-modal-header-left i { font-size: 1.25rem; color: #fff; }
        .confirm-modal-header-text { color: #fff; }
        .confirm-modal-header-text strong { display: block; font-size: 0.95rem; font-weight: 700; }
        .confirm-modal-close {
            width: 28px; height: 28px; border-radius: 50%;
            background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.35);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #fff; font-size: 0.85rem; flex-shrink: 0;
        }
        .confirm-modal-close:hover { background: rgba(255,255,255,0.3); }
        .confirm-modal-body { padding: 1.25rem 1.4rem; }
        .confirm-trip-details {
            background: #f8f9fb; border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 0.9rem 1rem; margin-bottom: 1rem;
        }
        .confirm-trip-details-title {
            font-size: 0.65rem; font-weight: 700; color: #800000;
            text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 8px;
            display: flex; align-items: center; gap: 5px;
        }
        .confirm-trip-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 4px 0; font-size: 0.8rem;
        }
        .confirm-trip-row-label { color: #888; }
        .confirm-trip-row-value { font-weight: 700; color: #1a1a1a; }
        .confirm-arrival-note {
            background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px;
            padding: 0.7rem 0.9rem; font-size: 0.8rem; color: #166534;
            display: flex; align-items: flex-start; gap: 7px; margin-bottom: 1rem; line-height: 1.5;
        }
        .confirm-arrival-note i { flex-shrink: 0; margin-top: 1px; }
        .confirm-date-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 0.75rem;
        }
        .confirm-field-group label {
            display: block; font-size: 0.7rem; font-weight: 700; color: #555;
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 5px;
        }
        .confirm-field-group input {
            width: 100%; padding: 0.55rem 0.75rem; border-radius: 9px;
            border: 1.5px solid #e0d0d0; font-size: 0.88rem; color: #333;
            background: #fdf9f9; outline: none;
        }
        .confirm-field-group input:focus { border-color: #16a34a; background: #fff; box-shadow: 0 0 0 3px rgba(22,163,74,0.1); }
        .confirm-err-box {
            background: #fef2f2; border: 1px solid #fecaca; border-radius: 9px;
            padding: 0.6rem 0.85rem; font-size: 0.78rem; color: #dc2626;
            display: none; margin-top: 0.5rem;
        }
        .confirm-modal-footer {
            padding: 0.9rem 1.4rem; border-top: 1px solid #f0f0f0;
            display: flex; gap: 8px; justify-content: flex-end;
        }
        .btn-confirm-cancel {
            background: #fff; color: #555; border: 1px solid #ddd;
            border-radius: 8px; padding: 0.5rem 1.2rem;
            font-size: 0.82rem; font-weight: 600; cursor: pointer;
        }
        .btn-confirm-cancel:hover { background: #f5f5f5; }
        .btn-confirm-done {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff; border: none; border-radius: 8px;
            padding: 0.5rem 1.4rem; font-size: 0.82rem; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 6px;
            box-shadow: 0 2px 8px rgba(22,163,74,0.35);
        }
        .btn-confirm-done:hover { background: linear-gradient(135deg, #15803d, #166534); }
        .btn-confirm-done.loading { opacity: 0.7; pointer-events: none; }

        /* ── TOAST ── */
        .toast-wrap {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            display: flex; flex-direction: column; gap: 8px; pointer-events: none;
        }
        .toast-item {
            background: #1a1a1a; color: #fff; border-radius: 10px;
            padding: 12px 18px; font-size: 0.82rem; font-weight: 600;
            display: flex; align-items: center; gap: 9px;
            animation: toastIn 0.25s ease; box-shadow: 0 4px 16px rgba(0,0,0,0.25);
            pointer-events: all;
        }
        .toast-item.success { background: #15803d; }
        .toast-item.error   { background: #a32d2d; }
        @keyframes toastIn { from { transform: translateX(40px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* ── MOBILE ── */
        @media (max-width: 900px) {
            .sidebar, .topbar, .main-content { display: none !important; }

            .mob-topbar {
                background: #7a0000; padding: 0 14px; height: 52px;
                position: sticky; top: 0; z-index: 99;
                display: flex; align-items: center; justify-content: space-between;
            }
            .mob-topbar-left { display: flex; align-items: center; gap: 8px; }
            .mob-ham { background: none; border: none; cursor: pointer; color: #fff; font-size: 1.35rem; display: flex; align-items: center; padding: 0; }
            .mob-topbar-title { font-size: 0.88rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 6px; }
            .mob-topbar-right { display: flex; align-items: center; }
            .mob-av {
                width: 32px; height: 32px; border-radius: 50%;
                background: rgba(255,255,255,0.22); color: #fff;
                display: flex; align-items: center; justify-content: center;
                font-size: 0.75rem; font-weight: 700;
                border: 1.5px solid rgba(255,255,255,0.38);
            }
            .mob-sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 399; }
            .mob-sidebar-overlay.open { display: block; }
            .mob-sidebar {
                position: fixed; top: 0; left: 0; bottom: 0; width: 250px; z-index: 400;
                background: #7a0000; display: flex; flex-direction: column;
                transform: translateX(-100%); transition: transform 0.24s ease; overflow-y: auto;
            }
            .mob-sidebar.open { transform: translateX(0); }
            .mob-drawer-brand {
                padding: 1rem; border-bottom: 1px solid rgba(255,255,255,0.12);
                display: flex; align-items: center; gap: 9px;
            }
            .mob-drawer-logo {
                width: 36px; height: 36px; border-radius: 50%; background: #fff;
                display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            }
            .mob-drawer-logo img { width: 32px; height: 32px; object-fit: contain; }
            .mob-drawer-name { color: #fff; font-size: 0.8rem; font-weight: 700; }
            .mob-drawer-sub  { font-size: 0.68rem; color: rgba(255,255,255,0.55); }
            .mob-nav-section { padding: 0.6rem 1rem 0.2rem; font-size: 0.62rem; font-weight: 700; color: rgba(255,255,255,0.38); text-transform: uppercase; letter-spacing: 0.08em; }
            .mob-nav-link {
                display: flex; align-items: center; gap: 9px;
                padding: 0.6rem 1rem; color: rgba(255,255,255,0.78);
                font-size: 0.85rem; text-decoration: none;
                border-left: 3px solid transparent; transition: all 0.15s;
            }
            .mob-nav-link:hover  { color: #fff; background: rgba(255,255,255,0.09); }
            .mob-nav-link.active { color: #fff; background: rgba(255,255,255,0.14); border-left-color: #fff; font-weight: 600; }
            .mob-nav-link i { font-size: 0.95rem; width: 16px; }
            .mob-nav-sep { border-color: rgba(255,255,255,0.13); margin: 0.4rem 1rem; }
            .mob-scroll { padding: 12px 12px 80px; display: flex; flex-direction: column; gap: 10px; }
            .mob-page-header { display: flex; align-items: center; justify-content: space-between; }
            .mob-page-title { font-size: 1rem; font-weight: 800; color: #7a0000; display: flex; align-items: center; gap: 7px; }
            .mob-trips-count { font-size: 0.72rem; color: #999; }
            .mob-filter-bar {
                background: #fff; border-radius: 14px; border: 1px solid #f0e5e5;
                padding: 0.9rem; display: flex; flex-direction: column; gap: 10px;
            }
            .mob-filter-label { font-size: 0.65rem; font-weight: 700; color: #999; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
            .mob-filter-bar input, .mob-filter-bar select {
                width: 100%; border: 1px solid #ede0e0; border-radius: 8px;
                padding: 0.5rem 0.75rem; font-size: 0.82rem; color: #333;
                background: #fdf9f9; height: 38px;
                -webkit-appearance: none; appearance: none;
            }
            .mob-filter-bar input:focus, .mob-filter-bar select:focus { outline: none; border-color: #7a0000; background: #fff; }
            .mob-search-wrap { position: relative; }
            .mob-search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #bbb; font-size: 0.85rem; pointer-events: none; }
            .mob-search-wrap input { padding-left: 32px !important; }
            .mob-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .mob-filter-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
            .mob-filter-btns .btn-filter,
            .mob-filter-btns .btn-reset { height: 38px; justify-content: center; font-size: 0.82rem; border-radius: 9px; }
            .mob-tabs {
                display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px;
                scrollbar-width: none; -webkit-overflow-scrolling: touch;
            }
            .mob-tabs::-webkit-scrollbar { display: none; }
            .mob-tabs .status-tab { flex-shrink: 0; font-size: 0.76rem; padding: 5px 11px; }
            .mob-trip-card {
                background: #fff; border-radius: 14px; border: 1px solid #f0e5e5;
                padding: 1rem; transition: background 0.12s;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .mob-trip-card-header {
                display: flex; align-items: flex-start; justify-content: space-between;
                gap: 8px; margin-bottom: 10px;
            }
            .mob-trip-dest { font-size: 0.9rem; font-weight: 700; color: #1a1a1a; line-height: 1.3; }
            .mob-trip-id   { font-size: 0.68rem; color: #ccc; margin-top: 2px; }
            .mob-trip-meta {
                display: grid; grid-template-columns: 1fr 1fr; gap: 6px;
                padding-top: 8px; border-top: 1px solid #f5eeee; margin-top: 4px;
            }
            .mob-trip-detail { display: flex; align-items: center; gap: 6px; font-size: 0.74rem; color: #777; }
            .mob-trip-detail i { font-size: 0.78rem; color: #7a0000; flex-shrink: 0; }
            .mob-trip-detail span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .mob-card-actions {
                margin-top: 10px; padding-top: 10px; border-top: 1px solid #f0e5e5;
                display: flex; gap: 8px; flex-wrap: wrap;
            }
            .mob-card-actions .btn-trip-done { width: 100%; justify-content: center; font-size: 0.8rem; padding: 9px 13px; border-radius: 9px; }
            .mob-card-actions button.mob-signed {
                display: flex; align-items: center; justify-content: center; gap: 6px;
                flex: 1; background: #eff6ff; border: 1px solid #bfdbfe;
                border-radius: 9px; padding: 8px 13px; font-size: 0.78rem;
                font-weight: 600; color: #1d4ed8; cursor: pointer;
            }
            .mob-pagination { display: flex; align-items: center; justify-content: center; gap: 6px; }
            .mob-pagination .pg-btn { width: 34px; height: 34px; }

            /* mobile toast */
            .toast-wrap { bottom: 16px; right: 12px; left: 12px; }
            .toast-item { justify-content: center; }
        }

        @media (min-width: 901px) {
            .mob-topbar, .mob-scroll, .mob-sidebar, .mob-sidebar-overlay { display: none !important; }
        }
    </style>
</head>
<body>

<!-- ══ TOAST CONTAINER ══ -->
<div class="toast-wrap" id="toastWrap"></div>

<!-- ══ MOBILE — Sidebar Drawer ══ -->
<div class="mob-sidebar-overlay" id="mobSidebarOverlay" onclick="toggleMobSidebar()"></div>
<div class="mob-sidebar" id="mobSidebar">
    <div class="mob-drawer-brand">
        <div class="mob-drawer-logo"><img src="../image/Csu.png" alt="CSU"></div>
        <div>
            <div class="mob-drawer-name">CSU Vehicle System</div>
            <div class="mob-drawer-sub">Driver Portal</div>
        </div>
    </div>
    <nav style="padding:0.4rem 0;">
        <div class="mob-nav-section">Menu</div>
        <a class="mob-nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a class="mob-nav-link active" href="my_trips.php"><i class="bi bi-map"></i> My Trips</a>
        <hr class="mob-nav-sep">
        <a class="mob-nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </nav>
</div>

<!-- ══ MOBILE — Topbar ══ -->
<div class="mob-topbar">
    <div class="mob-topbar-left">
        <button class="mob-ham" onclick="toggleMobSidebar()"><i class="bi bi-list"></i></button>
        <div class="mob-topbar-title"><i class="bi bi-map"></i> My Trips</div>
    </div>
    <div class="mob-topbar-right">
        <div class="mob-av"><?= htmlspecialchars($initials) ?></div>
    </div>
</div>

<!-- ══ MOBILE — Scroll Content ══ -->
<div class="mob-scroll">

    <div class="mob-page-header">
        <div class="mob-page-title"><i class="bi bi-map"></i> My Trips</div>
        <div class="mob-trips-count"><?= $totalRows ?> trip<?= $totalRows !== 1 ? 's' : '' ?></div>
    </div>

    <form method="GET" action="my_trips.php">
        <div class="mob-filter-bar">
            <div>
                <div class="mob-filter-label">Search</div>
                <div class="mob-search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Destination, requestor, plate…" value="<?= htmlspecialchars($filterSearch) ?>">
                </div>
            </div>
            <div>
                <div class="mob-filter-label">Date Range</div>
                <div class="mob-date-row">
                    <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" placeholder="From">
                    <input type="date" name="to"   value="<?= htmlspecialchars($filterTo) ?>"   placeholder="To">
                </div>
            </div>
            <?php if ($filterStatus !== 'all'): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <?php endif; ?>
            <div class="mob-filter-btns">
                <button type="submit" class="btn-filter"><i class="bi bi-funnel"></i> Apply</button>
                <a href="my_trips.php" class="btn-reset"><i class="bi bi-x-circle"></i> Reset</a>
            </div>
        </div>
    </form>

    <div class="mob-tabs">
        <?php foreach ($tabDefs as $key => $def):
            $q = array_merge($_GET, ['status' => $key]);
            unset($q['page']);
            $isActive = ($filterStatus === $key);
        ?>
        <a href="?<?= http_build_query($q) ?>" class="status-tab <?= $isActive ? 'active' : '' ?>">
            <i class="bi <?= $def['icon'] ?>"></i>
            <?= $def['label'] ?>
            <span class="tab-count"><?= $counts[$key] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ══ MOBILE TRIP CARDS ══ -->
    <?php if (empty($trips)): ?>
    <div style="background:#fff;border-radius:14px;border:1px solid #f0e5e5;">
        <div class="empty-state"><i class="bi bi-map"></i><p>No trips found.</p></div>
    </div>
    <?php else: ?>

    <?php foreach ($trips as $t):
        $tSt          = $t['status'] ?? '';
        $tripDate     = $t['travel_date'] ?? ($t['date_start'] ?? '—');
        $vehicleLabel = trim(($t['vehicle_name'] ?? '') . ($t['plate_no'] ? ' · ' . $t['plate_no'] : ''));
        $sid          = (int)($t['schedule_id'] ?? $t['id'] ?? 0);
    ?>
    <div class="mob-trip-card" id="card-<?= $sid ?>">
        <div onclick="openModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)" style="cursor:pointer;">
            <div class="mob-trip-card-header">
                <div>
                    <div class="mob-trip-dest"><?= htmlspecialchars($t['destination'] ?? '—') ?></div>
                    <div class="mob-trip-id">#<?= $sid ?></div>
                </div>
                <span id="badge-mob-<?= $sid ?>"><?= tripBadge($tSt) ?></span>
            </div>
            <div class="mob-trip-meta">
                <div class="mob-trip-detail">
                    <i class="bi bi-calendar3"></i>
                    <span><?= htmlspecialchars($tripDate && $tripDate !== '—' ? date('M j, Y', strtotime($tripDate)) : '—') ?></span>
                </div>
                <div class="mob-trip-detail">
                    <i class="bi bi-person"></i>
                    <span><?= htmlspecialchars($t['requestor_name'] ?? '—') ?></span>
                </div>
                <div class="mob-trip-detail">
                    <i class="bi bi-truck"></i>
                    <span><?= htmlspecialchars($vehicleLabel ?: '—') ?></span>
                </div>
                <div class="mob-trip-detail">
                    <i class="bi bi-geo-alt"></i>
                    <span><?= htmlspecialchars($t['purpose'] ?? '—') ?></span>
                </div>
            </div>
        </div>

        <?php if ($tSt === 'OnTrip' || !empty($t['signed_ticket_path'])): ?>
        <div class="mob-card-actions">
            <?php if ($tSt === 'OnTrip'): ?>
            <button class="btn-trip-done"
              onclick="confirmTripDone(<?= $sid ?>, '<?= htmlspecialchars(addslashes($t['destination'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($t['requestor_name'] ?? ''), ENT_QUOTES) ?>')"
                <i class="bi bi-flag-fill"></i> Trip Done
            </button>
            <?php endif; ?>
            <?php if (!empty($t['signed_ticket_path'])): ?>
            <button type="button" class="mob-signed btn-view-signed-drv"
                data-path="../<?= htmlspecialchars($t['signed_ticket_path'], ENT_QUOTES) ?>"
                data-type="<?= strtolower(pathinfo($t['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
                <i class="bi bi-file-earmark-check-fill"></i> Signed Ticket
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Mobile pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="mob-pagination">
        <?php if ($page > 1): ?>
        <a href="<?= paginateUrl($page - 1, $_GET) ?>" class="pg-btn"><i class="bi bi-chevron-left"></i></a>
        <?php else: ?>
        <span class="pg-btn disabled"><i class="bi bi-chevron-left"></i></span>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 1);
        $end   = min($totalPages, $page + 1);
        for ($i = $start; $i <= $end; $i++):
        ?>
        <a href="<?= paginateUrl($i, $_GET) ?>" class="pg-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= paginateUrl($page + 1, $_GET) ?>" class="pg-btn"><i class="bi bi-chevron-right"></i></a>
        <?php else: ?>
        <span class="pg-btn disabled"><i class="bi bi-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /mob-scroll -->


<!-- ══ DESKTOP — Sidebar ══ -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo"><img src="../image/Csu.png" alt="CSU"></div>
        <div class="sidebar-brand-text">CSU Vehicle System<span>Driver Portal</span></div>
    </div>
    <nav class="nav flex-column mt-2">
        <div class="nav-section-label">Menu</div>
        <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a class="nav-link active" href="my_trips.php"><i class="bi bi-map"></i> My Trips</a>
        <hr class="sidebar-divider">
        <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </nav>
</div>

<!-- ══ DESKTOP — Topbar ══ -->
<div class="topbar">
    <div class="topbar-title"><i class="bi bi-map"></i> My Trips</div>
    <div class="topbar-user">
        <div>
            <div style="font-weight:600;color:#333;font-size:0.82rem"><?= htmlspecialchars($displayName) ?></div>
            <div style="font-size:0.68rem;color:#7a0000">Driver</div>
        </div>
        <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
    </div>
</div>

<!-- ══ DESKTOP — Main Content ══ -->
<div class="main-content">

    <div class="page-header">
        <div>
            <div class="page-header-title"><i class="bi bi-map"></i> My Trips</div>
            <div class="page-header-sub">All trips assigned to you</div>
        </div>
        <span class="total-badge"><?= $totalRows ?> trip<?= $totalRows !== 1 ? 's' : '' ?></span>
    </div>

    <form method="GET" action="my_trips.php">
        <div class="filter-bar">
            <div class="form-group">
                <label>Search</label>
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Destination, requestor, plate…" value="<?= htmlspecialchars($filterSearch) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
            </div>
            <div class="form-group">
                <label>To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>">
            </div>
            <?php if ($filterStatus !== 'all'): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <?php endif; ?>
            <button type="submit" class="btn-filter" style="align-self:flex-end;"><i class="bi bi-funnel"></i> Apply</button>
            <a href="my_trips.php" class="btn-reset" style="align-self:flex-end;"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
    </form>

    <div class="status-tabs">
        <?php foreach ($tabDefs as $key => $def):
            $q = array_merge($_GET, ['status' => $key]);
            unset($q['page']);
            $isActive = ($filterStatus === $key);
        ?>
        <a href="?<?= http_build_query($q) ?>" class="status-tab <?= $isActive ? 'active' : '' ?>">
            <i class="bi <?= $def['icon'] ?>"></i>
            <?= $def['label'] ?>
            <span class="tab-count"><?= $counts[$key] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ══ DESKTOP TABLE ══ -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-table me-2"></i>Trip List</span>
            <span style="font-size:0.72rem;color:#aaa;font-weight:400;">
                Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?>
            </span>
        </div>

        <?php if (empty($trips)): ?>
        <div class="empty-state">
            <i class="bi bi-map"></i>
            <p>No trips found<?= $filterStatus !== 'all' ? ' for the selected filter' : '' ?>.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Destination</th>
                        <th>Purpose</th>
                        <th>Vehicle</th>
                        <th>Requestor</th>
                        <th>Status</th>
                        <th>Signed Ticket</th>
                        <th>Details</th>
                        <th>Trip Done</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($trips as $i => $t):
                    $tSt      = $t['status'] ?? '';
                    $tripDate = $t['travel_date'] ?? ($t['date_start'] ?? '—');
                    $sid      = (int)($t['schedule_id'] ?? $t['id'] ?? 0);
                ?>
                <tr id="row-<?= $sid ?>">
                    <td style="color:#aaa;font-size:0.75rem;"><?= $offset + $i + 1 ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($tripDate && $tripDate !== '—' ? date('F j, Y', strtotime($tripDate)) : '—') ?></td>
                    <td style="font-weight:600;color:#1a1a1a;"><?= htmlspecialchars($t['destination'] ?? '—') ?></td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($t['purpose'] ?? '') ?>">
                        <?= htmlspecialchars($t['purpose'] ?? '—') ?>
                    </td>
                    <td><?= htmlspecialchars(trim(($t['vehicle_name'] ?? '') . ' · ' . ($t['plate_no'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars($t['requestor_name'] ?? '—') ?></td>
                    <td id="badge-desk-<?= $sid ?>"><?= tripBadge($tSt) ?></td>
                    <td>
                        <?php if (!empty($t['signed_ticket_path'])): ?>
                        <button type="button"
                            class="btn-view-signed-drv"
                            data-path="../<?= htmlspecialchars($t['signed_ticket_path'], ENT_QUOTES) ?>"
                            data-type="<?= strtolower(pathinfo($t['signed_ticket_path'], PATHINFO_EXTENSION)) ?>"
                            style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;
                                   border:1px solid #bfdbfe;border-radius:8px;padding:5px 11px;
                                   font-size:.75rem;font-weight:600;color:#1d4ed8;cursor:pointer;white-space:nowrap;">
                            <i class="bi bi-file-earmark-check-fill"></i> View
                        </button>
                        <?php else: ?>
                        <span style="font-size:.75rem;color:#ccc;font-style:italic;">None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button"
                            onclick="openModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)"
                            style="display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;
                                   border:1px solid #f0c0c0;border-radius:8px;padding:5px 11px;
                                   font-size:.75rem;font-weight:600;color:#7a0000;cursor:pointer;white-space:nowrap;">
                            <i class="bi bi-eye"></i> View Details
                        </button>
                    </td>
                    <td id="done-cell-<?= $sid ?>">
                        <?php if ($tSt === 'OnTrip'): ?>
                        <button class="btn-trip-done"
                          onclick="confirmTripDone(<?= $sid ?>, '<?= htmlspecialchars(addslashes($t['destination'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($t['requestor_name'] ?? ''), ENT_QUOTES) ?>')"
                            <i class="bi bi-flag-fill"></i> Trip Done
                        </button>
                        <?php else: ?>
                        <span style="font-size:.75rem;color:#ccc;font-style:italic;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <div class="pg-links">
                <a href="<?= paginateUrl(1, $_GET) ?>" class="pg-btn <?= $page === 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                <a href="<?= paginateUrl(max(1, $page - 1), $_GET) ?>" class="pg-btn <?= $page === 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                <a href="<?= paginateUrl($p, $_GET) ?>" class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="<?= paginateUrl(min($totalPages,$page+1), $_GET) ?>" class="pg-btn <?= $page === $totalPages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                <a href="<?= paginateUrl($totalPages, $_GET) ?>" class="pg-btn <?= $page === $totalPages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div><!-- /main-content -->


<!-- ══ TRIP DETAIL MODAL ══ -->
<div class="modal-overlay" id="tripModal" onclick="closeModalOnOverlay(event)">
    <div class="modal-box" id="tripModalBox">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-map"></i> <span id="mTitle">Trip Details</span></div>
            <div class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></div>
        </div>
        <div class="modal-body">
            <div id="mDest" class="modal-dest"></div>
            <div id="mBadge" style="margin-bottom:4px;"></div>

            <div class="modal-section-label">Trip Information</div>
            <div class="modal-row">
                <span class="modal-key"><i class="bi bi-calendar3"></i> Date</span>
                <span class="modal-val" id="mDate">—</span>
            </div>
            <div class="modal-row">
                <span class="modal-key"><i class="bi bi-calendar-range"></i> Date Range</span>
                <span class="modal-val" id="mRange">—</span>
            </div>
            <div class="modal-row">
                <span class="modal-key"><i class="bi bi-clock"></i> Time</span>
                <span class="modal-val" id="mTime">—</span>
            </div>

            <div class="modal-section-label">Vehicle & Requestor</div>
            <div class="modal-row">
                <span class="modal-key"><i class="bi bi-truck"></i> Vehicle</span>
                <span class="modal-val" id="mVehicle">—</span>
            </div>
            <div class="modal-row">
                <span class="modal-key"><i class="bi bi-credit-card-2-front"></i> Plate No.</span>
                <span class="modal-val" id="mPlate">—</span>
            </div>
            <div class="modal-row">
                <span class="modal-key"><i class="bi bi-person"></i> Requestor</span>
                <span class="modal-val" id="mRequestor">—</span>
            </div>
            <div class="modal-row">
                <span class="modal-key"><i class="bi bi-building"></i> Office</span>
                <span class="modal-val" id="mOffice">—</span>
            </div>

            <div class="modal-section-label">Purpose</div>
            <div class="modal-purpose-box" id="mPurpose">—</div>

            <div class="modal-section-label">Remarks</div>
            <div class="modal-purpose-box" id="mRemarks" style="background:#fafafa;border-color:#eee;">—</div>

            <div id="mCancelWrap" style="display:none;">
                <div class="modal-section-label" style="color:#a32d2d;">Cancellation Reason</div>
                <div class="modal-purpose-box" id="mCancelReason" style="background:#fce8e8;border-color:#f0c0c0;color:#a32d2d;">—</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-close-modal" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>


<!-- ══ RECORD TRIP COMPLETION MODAL ══ -->
<div class="modal-overlay" id="confirmDoneModal" onclick="closeConfirmOnOverlay(event)">
    <div class="confirm-modal-box">
        <div class="confirm-modal-header">
            <div class="confirm-modal-header-left">
                <i class="bi bi-flag-fill"></i>
                <div class="confirm-modal-header-text">
                    <strong>Record Trip Completion</strong>
                </div>
            </div>
            <div class="confirm-modal-close" onclick="closeConfirmModal()"><i class="bi bi-x-lg"></i></div>
        </div>
        <div class="confirm-modal-body">
            <div class="confirm-trip-details">
                <div class="confirm-trip-details-title"><i class="bi bi-info-circle"></i> Trip Details</div>
                <div class="confirm-trip-row">
                    <span class="confirm-trip-row-label">Requestor</span>
                    <span class="confirm-trip-row-value" id="confirmRequestorText">—</span>
                </div>
                <div class="confirm-trip-row">
                    <span class="confirm-trip-row-label">Destination</span>
                    <span class="confirm-trip-row-value" id="confirmDestText">—</span>
                </div>
            </div>
            <div class="confirm-arrival-note">
                <i class="bi bi-check-circle-fill"></i>
                Record the <strong>&nbsp;actual date and time&nbsp;</strong> the vehicle arrived back.
            </div>
            <div class="confirm-date-row">
                <div class="confirm-field-group">
                    <label>Arrival Date <span style="color:#dc2626">*</span></label>
                    <input type="date" id="confirmArrivalDate">
                </div>
                <div class="confirm-field-group">
                    <label>Arrival Time <span style="color:#dc2626">*</span></label>
                    <input type="time" id="confirmArrivalTime">
                </div>
            </div>
            <div class="confirm-err-box" id="confirmErrBox"></div>
        </div>
        <div class="confirm-modal-footer">
            <button class="btn-confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn-confirm-done" id="btnConfirmDone" onclick="submitTripDone()">
                <i class="bi bi-flag-fill"></i> Confirm Trip Complete
            </button>
        </div>
    </div>
</div>


<!-- ══ SIGNED TICKET VIEWER MODAL ══ -->
<div class="modal-overlay" id="viewSignedDrvModal" onclick="closeSignedDrvOnOverlay(event)">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:780px;max-height:90vh;
                display:flex;flex-direction:column;animation:slideUp .22s ease;">
        <div style="background:linear-gradient(135deg,#0550a0,#0a3678);color:#fff;padding:1rem 1.25rem;
                    display:flex;align-items:center;justify-content:space-between;
                    border-radius:16px 16px 0 0;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:9px;">
                <i class="bi bi-file-earmark-check-fill" style="font-size:1.1rem;"></i>
                <span style="font-weight:700;font-size:.95rem;">Signed Trip Ticket</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <a id="vsm_drv_download" href="#" download
                   style="display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.18);
                          border:1px solid rgba(255,255,255,.35);border-radius:8px;padding:5px 12px;
                          font-size:.78rem;font-weight:600;color:#fff;text-decoration:none;">
                    <i class="bi bi-download"></i> Download
                </a>
                <div onclick="closeSignedDrvModal()"
                     style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.18);
                            border:1px solid rgba(255,255,255,.3);display:flex;align-items:center;
                            justify-content:center;cursor:pointer;color:#fff;font-size:.85rem;">
                    <i class="bi bi-x-lg"></i>
                </div>
            </div>
        </div>
        <div style="flex:1;overflow:hidden;background:#f0f0f0;display:flex;align-items:center;
                    justify-content:center;min-height:400px;border-radius:0 0 16px 16px;">
            <div id="vsm_drv_loading" style="text-align:center;color:#888;padding:3rem;">
                <i class="bi bi-hourglass-split" style="font-size:2rem;display:block;margin-bottom:.75rem;color:#7a0000;"></i>
                <div style="font-size:.85rem;">Loading signed ticket…</div>
            </div>
            <iframe id="vsm_drv_iframe" src=""
                    style="display:none;width:100%;height:70vh;border:none;border-radius:0 0 16px 16px;"></iframe>
            <img id="vsm_drv_img" src="" alt="Signed Ticket"
                 style="display:none;max-width:100%;max-height:70vh;object-fit:contain;padding:1rem;">
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Mobile Sidebar ── */
function toggleMobSidebar() {
    document.getElementById('mobSidebar').classList.toggle('open');
    document.getElementById('mobSidebarOverlay').classList.toggle('open');
}

/* ── Badge HTML ── */
const badgeMap = {
    'Approved'  : '<span class="badge-approved"><i class="bi bi-check-circle" style="margin-right:4px"></i>Approved</span>',
    'OnTrip'    : '<span class="badge-ontrip"><i class="bi bi-geo-alt" style="margin-right:4px"></i>On Trip</span>',
    'Completed' : '<span class="badge-completed"><i class="bi bi-check2-all" style="margin-right:4px"></i>Completed</span>',
    'Cancelled' : '<span class="badge-cancelled"><i class="bi bi-x-circle" style="margin-right:4px"></i>Cancelled</span>',
};

/* ── Toast ── */
function showToast(msg, type = 'success') {
    const wrap = document.getElementById('toastWrap');
    const el   = document.createElement('div');
    el.className = `toast-item ${type}`;
    el.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'x-circle-fill'}"></i> ${msg}`;
    wrap.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.4s'; setTimeout(() => el.remove(), 400); }, 3000);
}

/* ── Record Trip Completion Modal ── */
let _pendingDoneSid = null;
let _pendingDoneRequestor = '—';

function confirmTripDone(sid, dest, requestor) {
    _pendingDoneSid       = sid;
    _pendingDoneRequestor = requestor || '—';

    document.getElementById('confirmDestText').textContent      = dest || '—';
    document.getElementById('confirmRequestorText').textContent = requestor || '—';
    document.getElementById('confirmErrBox').style.display      = 'none';
    document.getElementById('confirmErrBox').textContent        = '';

    // Pre-fill current date & time
    const now = new Date(), pad = n => String(n).padStart(2,'0');
    document.getElementById('confirmArrivalDate').value =
        now.getFullYear() + '-' + pad(now.getMonth()+1) + '-' + pad(now.getDate());
    document.getElementById('confirmArrivalTime').value =
        pad(now.getHours()) + ':' + pad(now.getMinutes());

    const btn = document.getElementById('btnConfirmDone');
    btn.classList.remove('loading');
    btn.innerHTML = '<i class="bi bi-flag-fill"></i> Confirm Trip Complete';
    btn.disabled  = false;

    document.getElementById('confirmDoneModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal() {
    document.getElementById('confirmDoneModal').classList.remove('open');
    document.body.style.overflow = '';
    _pendingDoneSid = null;
}

function closeConfirmOnOverlay(e) {
    if (e.target === document.getElementById('confirmDoneModal')) closeConfirmModal();
}

function submitTripDone() {
    if (!_pendingDoneSid) return;
    const sid          = _pendingDoneSid;
    const arrivedDate  = document.getElementById('confirmArrivalDate').value.trim();
    const arrivedTime  = document.getElementById('confirmArrivalTime').value.trim();
    const errBox       = document.getElementById('confirmErrBox');
    errBox.style.display = 'none';

    if (!arrivedDate || !arrivedTime) {
        errBox.textContent   = 'Please enter both arrival date and time.';
        errBox.style.display = 'block';
        return;
    }

    const btn = document.getElementById('btnConfirmDone');
    btn.classList.add('loading');
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving…';
    btn.disabled  = true;

    const fd = new FormData();
    fd.append('action',        'trip_done');
    fd.append('schedule_id',   sid);
    fd.append('arrived_date',  arrivedDate);
    fd.append('arrived_time',  arrivedTime);

    fetch('my_trips.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeConfirmModal();
                showToast('Trip marked as Completed!', 'success');

                // Update badge — desktop & mobile
                const deskBadge = document.getElementById('badge-desk-' + sid);
                const mobBadge  = document.getElementById('badge-mob-'  + sid);
                const newBadge  = badgeMap['Completed'];
                if (deskBadge) deskBadge.innerHTML = newBadge;
                if (mobBadge)  mobBadge.innerHTML  = newBadge;

                // Remove "Trip Done" button from desktop cell
                const doneCell = document.getElementById('done-cell-' + sid);
                if (doneCell) doneCell.innerHTML = '<span style="font-size:.75rem;color:#ccc;font-style:italic;">—</span>';

                // Remove "Trip Done" button from mobile card actions
                const mobCard = document.getElementById('card-' + sid);
                if (mobCard) {
                    const doneBtn = mobCard.querySelector('.btn-trip-done');
                    if (doneBtn) doneBtn.remove();
                }
            } else {
                errBox.textContent   = data.message || 'Something went wrong.';
                errBox.style.display = 'block';
                btn.classList.remove('loading');
                btn.innerHTML = '<i class="bi bi-flag-fill"></i> Confirm Trip Complete';
                btn.disabled  = false;
            }
        })
        .catch(() => {
            errBox.textContent   = 'Network error. Please try again.';
            errBox.style.display = 'block';
            btn.classList.remove('loading');
            btn.innerHTML = '<i class="bi bi-flag-fill"></i> Confirm Trip Complete';
            btn.disabled  = false;
        });
}

/* ── Trip Detail Modal ── */
function openModal(trip) {
    const e = v => (v ?? '—').toString().trim() || '—';
    function formatDate(val) {
        if (!val || val === '—') return '—';
        const d = new Date(val);
        return isNaN(d) ? val : d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    }
    function formatTime(val) {
        if (!val || val === '—') return '—';
        const [h, m] = val.toString().split(':');
        if (!h || !m) return val;
        const hour = parseInt(h);
        return `${hour % 12 || 12}:${m} ${hour >= 12 ? 'PM' : 'AM'}`;
    }
    const dateStart = trip.date_start ?? trip.travel_date ?? null;
    const dateEnd   = trip.date_end   ?? null;
    const timeStart = trip.time_start ?? trip.departure_time ?? trip.time_depart ?? null;
    const timeEnd   = trip.time_end   ?? trip.arrival_time   ?? null;

    document.getElementById('mDest').textContent      = e(trip.destination);
    document.getElementById('mTitle').textContent     = e(trip.destination);
    document.getElementById('mBadge').innerHTML       = badgeMap[trip.status] || '<span class="badge-other">' + e(trip.status) + '</span>';
    document.getElementById('mDate').textContent      = formatDate(dateStart);
    document.getElementById('mRange').textContent     = (dateStart && dateEnd) ? formatDate(dateStart) + ' → ' + formatDate(dateEnd) : formatDate(dateStart);
    document.getElementById('mTime').textContent      = timeStart ? formatTime(timeStart) + (timeEnd ? ' – ' + formatTime(timeEnd) : '') : '—';
    document.getElementById('mVehicle').textContent   = e(trip.vehicle_name);
    document.getElementById('mPlate').textContent     = e(trip.plate_no);
    document.getElementById('mRequestor').textContent = e(trip.requestor_name);
    document.getElementById('mOffice').textContent    = e(trip.office_name);
    document.getElementById('mPurpose').textContent   = e(trip.purpose);
    document.getElementById('mRemarks').textContent   = e(trip.remarks || trip.notes);

    const cancelReason = e(trip.cancel_reason);
    const cancelWrap   = document.getElementById('mCancelWrap');
    if (trip.status === 'Cancelled' && cancelReason !== '—') {
        document.getElementById('mCancelReason').textContent = cancelReason;
        cancelWrap.style.display = '';
    } else {
        cancelWrap.style.display = 'none';
    }
    document.getElementById('tripModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal() {
    document.getElementById('tripModal').classList.remove('open');
    document.body.style.overflow = '';
}
function closeModalOnOverlay(e) {
    if (e.target === document.getElementById('tripModal')) closeModal();
}

/* ── Signed Ticket ── */
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-view-signed-drv');
    if (!btn) return;
    e.stopPropagation();
    openSignedDrvModal(btn.dataset.path, btn.dataset.type);
});

function openSignedDrvModal(path, ext) {
    const overlay = document.getElementById('viewSignedDrvModal');
    const iframe  = document.getElementById('vsm_drv_iframe');
    const img     = document.getElementById('vsm_drv_img');
    const loader  = document.getElementById('vsm_drv_loading');
    const dl      = document.getElementById('vsm_drv_download');

    iframe.style.display = 'none';
    img.style.display    = 'none';
    loader.style.display = 'flex';
    loader.style.flexDirection = 'column';
    loader.style.alignItems = 'center';
    loader.innerHTML = '<i class="bi bi-hourglass-split" style="font-size:2rem;margin-bottom:.75rem;color:#7a0000;"></i><div style="font-size:.85rem;">Loading signed ticket…</div>';
    iframe.src = '';
    img.src    = '';
    dl.href    = path;

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (ext === 'pdf') {
        iframe.onload = () => { loader.style.display = 'none'; iframe.style.display = 'block'; };
        iframe.src = path;
    } else {
        img.onload  = () => { loader.style.display = 'none'; img.style.display = 'block'; };
        img.onerror = () => {
            loader.innerHTML = '<i class="bi bi-x-circle" style="font-size:2rem;color:#a32d2d;margin-bottom:.5rem;"></i><div style="font-size:.85rem;color:#a32d2d;">Could not load file.</div>';
        };
        img.src = path;
    }
}
function closeSignedDrvModal() {
    document.getElementById('viewSignedDrvModal').classList.remove('open');
    document.getElementById('vsm_drv_iframe').src = '';
    document.getElementById('vsm_drv_img').src    = '';
    document.body.style.overflow = '';
}
function closeSignedDrvOnOverlay(e) {
    if (e.target === document.getElementById('viewSignedDrvModal')) closeSignedDrvModal();
}

/* ── Escape key ── */
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('viewSignedDrvModal').classList.contains('open')) { closeSignedDrvModal(); return; }
    if (document.getElementById('confirmDoneModal').classList.contains('open'))   { closeConfirmModal();   return; }
    if (document.getElementById('tripModal').classList.contains('open'))           { closeModal();          return; }
});
</script>
</body>
</html>