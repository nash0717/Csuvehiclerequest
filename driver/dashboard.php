<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireDriver();

$driver_id = $_SESSION['user_id'];

// Fetch driver record
$stmt = $pdo->prepare("
    SELECT d.*, o.office_name
    FROM drivers d
    LEFT JOIN offices o ON d.office_id = o.office_id
    WHERE d.driver_id = ?
");
$stmt->execute([$driver_id]);
$driver = $stmt->fetch();

// Fetch upcoming/active trips
$trips = [];
if ($driver) {
    $tStmt = $pdo->prepare("
        SELECT s.*,
               u.username AS requestor_name,
               v.plate_number AS plate_no,
               CONCAT(v.brand, ' ', v.model) AS vehicle_name
        FROM schedules s
        LEFT JOIN users u    ON s.user_id    = u.user_id
        LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
        WHERE s.driver_id = ?
          AND s.status NOT IN ('Cancelled','Completed')
        ORDER BY s.date_start ASC
        LIMIT 20
    ");
    $tStmt->execute([$driver['driver_id']]);
    $trips = $tStmt->fetchAll();
}


// Assigned vehicle — via driver_vehicle_assignments table (most recent active assignment)
$vehicle = null;
if ($driver) {
    $vStmt = $pdo->prepare("
        SELECT v.*
        FROM driver_vehicle_assignments dva
        JOIN vehicles v ON dva.vehicle_id = v.vehicle_id
        WHERE dva.driver_id = ?
        ORDER BY dva.assigned_at DESC
        LIMIT 1
    ");
    $vStmt->execute([$driver['driver_id']]);
    $vehicle = $vStmt->fetch() ?: null;
}

// Display name & initials
$displayName = $driver['driver_name'] ?? $_SESSION['username'] ?? 'Driver';
$initials = implode('', array_map(
    fn($w) => strtoupper($w[0]),
    array_slice(explode(' ', $displayName), 0, 2)
));

// Stats
$cntApproved = 0; $cntTotal = 0; $cntToday = 0; $cntCompleted = 0;
if ($driver) {
    $did = $driver['driver_id'];
    $today = date('Y-m-d');

    $r = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE driver_id=? AND status IN ('Approved','OnTrip')");
    $r->execute([$did]); $cntApproved = (int)$r->fetchColumn();

    $r = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE driver_id=?");
    $r->execute([$did]); $cntTotal = (int)$r->fetchColumn();

    $r = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE driver_id=? AND DATE(date_start)=?");
    $r->execute([$did, $today]); $cntToday = (int)$r->fetchColumn();

    $r = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE driver_id=? AND status='Completed'");
    $r->execute([$did]); $cntCompleted = (int)$r->fetchColumn();
}

$st      = $driver['status'] ?? '';
$stColor = ($st === 'Available' || $st === 'Active') ? '#22c55e' : '#ef4444';

// Vehicle display helpers
$vehiclePlate  = $vehicle['plate_number'] ?? '—';
$vehicleName   = $vehicle ? trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '')) : '';
$vehicleStatus = $vehicle['status'] ?? 'Operational';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Driver Dashboard – CSU VSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; margin: 0; }

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
            width: 38px; height: 38px; border-radius: 50%; background: #fff;
            overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .sidebar-logo img { width: 34px; height: 34px; object-fit: contain; }
        .sidebar-brand-text { color: #fff; font-size: 0.8rem; font-weight: 700; line-height: 1.3; }
        .sidebar-brand-text span { display: block; font-size: 0.7rem; font-weight: 400; opacity: 0.6; }
        .nav-section-label {
            padding: 0.7rem 1.1rem 0.2rem; font-size: 0.65rem; font-weight: 700;
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
            width: 30px; height: 30px; border-radius: 50%; background: #7a0000; color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700;
        }

        /* ── MAIN CONTENT ── */
        .main-content { margin-left: 230px; padding: 1.25rem 1.4rem; }

        /* Profile banner */
        .profile-card {
            background: #7a0000; border-radius: 14px; padding: 1.25rem 1.5rem; color: #fff;
            display: flex; align-items: center; gap: 1.1rem; margin-bottom: 1.1rem;
        }
        .profile-avatar {
            width: 58px; height: 58px; border-radius: 50%; background: rgba(255,255,255,0.16);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; font-weight: 700; flex-shrink: 0;
            border: 1.5px solid rgba(255,255,255,0.28);
        }
        .profile-name { font-size: 1.1rem; font-weight: 700; margin-bottom: 4px; }
        .profile-meta { font-size: 0.78rem; opacity: 0.78; display: flex; flex-wrap: wrap; gap: 10px; }
        .profile-meta span { display: flex; align-items: center; gap: 4px; }

        /* 4-column stat grid */
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 1.1rem; }
        .stat-card { background: #fff; border-radius: 12px; padding: 0.9rem 1rem; border: 1px solid #f0e5e5; }
        .stat-icon {
            width: 30px; height: 30px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; margin-bottom: 7px;
        }
        .stat-icon i { font-size: 0.95rem; }
        .stat-icon.total    { background: #faeeda; color: #854f0b; }
        .stat-icon.today    { background: #e6f1fb; color: #185fa5; }
        .stat-icon.approved { background: #eaf3de; color: #3b6d11; }
        .stat-icon.done     { background: #eeedfe; color: #534ab7; }
        .stat-label { font-size: 0.68rem; color: #999; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-value { font-size: 1.55rem; font-weight: 800; color: #1a1a1a; line-height: 1.1; }
        .stat-sub   { font-size: 0.7rem; color: #aaa; margin-top: 1px; }

        /* Vehicle banner — full width */
        .vehicle-banner {
            background: #fff; border-radius: 12px; border: 1px solid #f0e5e5;
            padding: 0.9rem 1.2rem; display: flex; align-items: center; gap: 1.2rem;
            margin-bottom: 1.1rem; flex-wrap: wrap;
        }
        .vehicle-banner-icon {
            width: 46px; height: 46px; border-radius: 12px;
            background: #eeedfe; color: #534ab7;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; flex-shrink: 0;
        }
        .vehicle-banner-main { flex: 1; min-width: 160px; }
        .vehicle-banner-plate { font-size: 1.05rem; font-weight: 800; color: #1a1a1a; letter-spacing: 0.06em; }
        .vehicle-banner-name  { font-size: 0.75rem; color: #888; margin-top: 2px; }
        .vb-divider { width: 1px; height: 38px; background: #f0e5e5; flex-shrink: 0; }
        .vb-detail  { display: flex; flex-direction: column; gap: 2px; min-width: 90px; }
        .vb-detail .vbd-label { font-size: 0.63rem; color: #aaa; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
        .vb-detail .vbd-val   { font-size: 0.8rem; font-weight: 600; color: #333; }
        .op-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 600; color: #3b6d11; }
        .op-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #22c55e; }
        .na-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 600; color: #888; }
        .na-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #ccc; }

        /* Schedule table */
        .section-card { background: #fff; border-radius: 14px; border: 1px solid #f0e5e5; overflow: hidden; }
        .section-header {
            padding: 0.85rem 1.1rem; border-bottom: 1px solid #f0e5e5;
            font-weight: 700; font-size: 0.85rem; color: #7a0000;
            display: flex; align-items: center; justify-content: space-between;
        }
        .section-header a { font-size: 0.75rem; color: #7a0000; text-decoration: none; display: flex; align-items: center; gap: 3px; }
        .section-header a:hover { text-decoration: underline; }
        .table thead th {
            background: #fdf5f5; color: #7a0000; font-size: 0.72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.04em;
            border-bottom: 1px solid #f0e5e5; padding: 0.65rem 0.85rem;
        }
        .table tbody td {
            padding: 0.65rem 0.85rem; font-size: 0.82rem; color: #444;
            vertical-align: middle; border-color: #fdf5f5;
        }
        .table tbody tr:hover { background: #fdf8f8; }

        /* Badges */
        .badge-pending  { background: #faeeda; color: #854f0b; padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
        .badge-approved { background: #eaf3de; color: #3b6d11; padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
        .badge-ongoing  { background: #e6f1fb; color: #185fa5; padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
        .badge-other    { background: #f5f5f5; color: #555;    padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }

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
            .mob-topbar-title { font-size: 0.88rem; font-weight: 700; color: #fff; }
            .mob-topbar-right { display: flex; align-items: center; gap: 8px; }

            .mob-av {
                width: 32px; height: 32px; border-radius: 50%;
                background: rgba(255,255,255,0.22); color: #fff;
                display: flex; align-items: center; justify-content: center;
                font-size: 0.75rem; font-weight: 700; border: 1.5px solid rgba(255,255,255,0.38);
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
                display: flex; align-items: center; gap: 9px; padding: 0.6rem 1rem;
                color: rgba(255,255,255,0.78); font-size: 0.85rem; text-decoration: none;
                border-left: 3px solid transparent; transition: all 0.15s;
            }
            .mob-nav-link:hover  { color: #fff; background: rgba(255,255,255,0.09); }
            .mob-nav-link.active { color: #fff; background: rgba(255,255,255,0.14); border-left-color: #fff; font-weight: 600; }
            .mob-nav-link i { font-size: 0.95rem; width: 16px; }
            .mob-nav-sep { border-color: rgba(255,255,255,0.13); margin: 0.4rem 1rem; }

            .mob-scroll { padding: 12px 12px 24px; display: flex; flex-direction: column; gap: 10px; }

            .mob-profile {
                background: #7a0000; border-radius: 14px; padding: 1rem 1.1rem; color: #fff;
                display: flex; align-items: center; gap: 10px;
            }
            .mob-profile-av {
                width: 46px; height: 46px; border-radius: 50%; background: rgba(255,255,255,0.16);
                display: flex; align-items: center; justify-content: center;
                font-size: 1.1rem; font-weight: 700; flex-shrink: 0;
                border: 1.5px solid rgba(255,255,255,0.28);
            }
            .mob-profile-name { font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; }
            .mob-profile-meta { font-size: 0.72rem; opacity: 0.78; display: flex; flex-wrap: wrap; gap: 7px; }
            .mob-profile-meta span { display: flex; align-items: center; gap: 3px; }

            .mob-stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .mob-stat-card { background: #fff; border-radius: 12px; border: 1px solid #f0e5e5; padding: 0.8rem 0.9rem; }
            .mob-stat-icon { width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-bottom: 6px; }
            .mob-stat-icon i { font-size: 0.88rem; }
            .mob-stat-label { font-size: 0.65rem; color: #999; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
            .mob-stat-value { font-size: 1.45rem; font-weight: 800; color: #1a1a1a; line-height: 1.1; }
            .mob-stat-sub { font-size: 0.68rem; color: #aaa; margin-top: 1px; }

            /* Mobile vehicle card */
            .mob-vehicle-card {
                background: #fff; border-radius: 12px; border: 1px solid #f0e5e5; padding: 0.9rem 1rem;
            }
            .mob-veh-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 10px; gap: 8px; }
            .mob-veh-plate  { font-size: 1rem; font-weight: 800; color: #1a1a1a; letter-spacing: 0.06em; }
            .mob-veh-model  { font-size: 0.7rem; color: #888; margin-top: 2px; }
            .mob-veh-grid   { display: grid; grid-template-columns: 1fr 1fr; }
            .mob-veh-cell   { padding: 6px 0; border-bottom: 1px solid #fdf0f0; }
            .mob-veh-cell:nth-child(odd)        { padding-right: 8px; border-right: 1px solid #fdf0f0; }
            .mob-veh-cell:nth-child(even)       { padding-left: 8px; }
            .mob-veh-cell:nth-last-child(-n+2)  { border-bottom: none; }
            .mob-veh-key { font-size: 0.68rem; color: #999; margin-bottom: 2px; display: flex; align-items: center; gap: 3px; }
            .mob-veh-key i { font-size: 0.72rem; color: #7a0000; }
            .mob-veh-val { font-size: 0.78rem; font-weight: 600; color: #1a1a1a; }

            /* Trip cards */
            .mob-section { background: #fff; border-radius: 14px; border: 1px solid #f0e5e5; overflow: hidden; }
            .mob-section-header {
                padding: 0.8rem 1rem; border-bottom: 1px solid #f0e5e5;
                font-weight: 700; font-size: 0.83rem; color: #7a0000;
                display: flex; align-items: center; justify-content: space-between;
            }
            .mob-section-header a { font-size: 0.72rem; color: #7a0000; text-decoration: none; display: flex; align-items: center; gap: 3px; }
            .mob-trip-item {
                display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;
                padding: 0.75rem 1rem; border-bottom: 1px solid #fdf0f0;
            }
            .mob-trip-item:last-child { border-bottom: none; }
            .mob-trip-item:hover { background: #fdf8f8; }
            .mob-trip-dest { font-size: 0.82rem; font-weight: 600; color: #1a1a1a; margin-bottom: 3px; }
            .mob-trip-meta { font-size: 0.7rem; color: #888; display: flex; flex-wrap: wrap; gap: 6px; }
            .mob-trip-meta span { display: flex; align-items: center; gap: 3px; }
            .mob-trip-meta i { font-size: 0.75rem; }
        }
        @media (min-width: 901px) {
            .mob-topbar, .mob-scroll, .mob-sidebar, .mob-sidebar-overlay, .mob-section { display: none !important; }
        }
    </style>
</head>
<body>

<?php
function tripBadge(string $status): string {
    return match($status) {
        'Pending'           => '<span class="badge-pending">Pending</span>',
        'Approved',
        'Confirmed'         => '<span class="badge-approved">' . htmlspecialchars($status) . '</span>',
        'OnTrip', 'Ongoing' => '<span class="badge-ongoing">On Trip</span>',
        default             => '<span class="badge-other">' . htmlspecialchars($status) . '</span>',
    };
}
?>

<!-- MOBILE — Sidebar -->
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
        <a class="mob-nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a class="mob-nav-link" href="my_trips.php"><i class="bi bi-map"></i> My Trips</a>
        <hr class="mob-nav-sep">
        <a class="mob-nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </nav>
</div>

<!-- MOBILE — Topbar -->
<div class="mob-topbar">
    <div class="mob-topbar-left">
        <button class="mob-ham" onclick="toggleMobSidebar()" aria-label="Open menu"><i class="bi bi-list"></i></button>
        <div class="mob-topbar-title"><i class="bi bi-speedometer2" style="margin-right:5px"></i>Dashboard</div>
    </div>
    <div class="mob-topbar-right">
        <div class="mob-av"><?= htmlspecialchars($initials) ?></div>
    </div>
</div>

<!-- MOBILE — Scroll Content -->
<div class="mob-scroll">

    <div class="mob-profile">
        <div class="mob-profile-av"><?= htmlspecialchars($initials) ?></div>
        <div>
            <div class="mob-profile-name"><?= htmlspecialchars($displayName) ?></div>
            <div class="mob-profile-meta">
                <span><i class="bi bi-card-text"></i> <?= htmlspecialchars($driver['license_no'] ?? 'No license') ?></span>
                <span><i class="bi bi-telephone"></i> <?= htmlspecialchars($driver['phone_no'] ?? '—') ?></span>
                <span><i class="bi bi-building"></i> <?= htmlspecialchars($driver['office_name'] ?? '—') ?></span>
                <span><i class="bi bi-circle-fill" style="color:<?= $stColor ?>;font-size:0.48rem;"></i> <?= htmlspecialchars($st ?: 'Unknown') ?></span>
            </div>
        </div>
    </div>

    <!-- Stats 2x2 -->
    <div class="mob-stat-grid">
        <div class="mob-stat-card">
            <div class="mob-stat-icon stat-icon total"><i class="bi bi-signpost-2"></i></div>
            <div class="mob-stat-label">Total Trips</div>
            <div class="mob-stat-value"><?= $cntTotal ?></div>
            <div class="mob-stat-sub">All time</div>
        </div>
        <div class="mob-stat-card">
            <div class="mob-stat-icon stat-icon today"><i class="bi bi-calendar-event"></i></div>
            <div class="mob-stat-label">Today's Trips</div>
            <div class="mob-stat-value"><?= $cntToday ?></div>
            <div class="mob-stat-sub"><?= date('M d, Y') ?></div>
        </div>
        <div class="mob-stat-card">
            <div class="mob-stat-icon stat-icon approved"><i class="bi bi-check2-circle"></i></div>
            <div class="mob-stat-label">Approved / Active</div>
            <div class="mob-stat-value"><?= $cntApproved ?></div>
            <div class="mob-stat-sub">Confirmed trips</div>
        </div>
        <div class="mob-stat-card">
            <div class="mob-stat-icon stat-icon done"><i class="bi bi-trophy"></i></div>
            <div class="mob-stat-label">Completed</div>
            <div class="mob-stat-value"><?= $cntCompleted ?></div>
            <div class="mob-stat-sub">Finished trips</div>
        </div>
    </div>

    <!-- Vehicle card -->
    <div class="mob-vehicle-card">
        <div class="mob-veh-header">
            <div>
                <div class="mob-veh-plate"><?= htmlspecialchars($vehiclePlate) ?></div>
                <div class="mob-veh-model">
                    <?= $vehicle
                        ? htmlspecialchars(trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '')))
                        : 'No vehicle assigned' ?>
                </div>
            </div>
            <?php if ($vehicle): ?>
                <span class="op-badge"><?= htmlspecialchars($vehicleStatus) ?></span>
            <?php else: ?>
                <span class="na-badge">Not Assigned</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming trips -->
    <div class="mob-section">
        <div class="mob-section-header">
            <span><i class="bi bi-map me-1"></i>Upcoming &amp; Active Trips</span>
            <a href="my_trips.php">View all <i class="bi bi-chevron-right"></i></a>
        </div>
        <?php if (empty($trips)): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-map d-block mb-2 opacity-50" style="font-size:1.8rem;"></i>
            <small>No upcoming trips.</small>
        </div>
        <?php else: ?>
        <?php foreach ($trips as $t): ?>
        <div class="mob-trip-item">
            <div>
                <div class="mob-trip-dest"><?= htmlspecialchars($t['destination'] ?? '—') ?></div>
                <div class="mob-trip-meta">
                    <span><i class="bi bi-calendar3"></i> <?= htmlspecialchars($t['travel_date'] ?? ($t['date_start'] ?? '—')) ?></span>
                    <span><i class="bi bi-person"></i> <?= htmlspecialchars($t['requestor_name'] ?? '—') ?></span>
                    <span><i class="bi bi-truck"></i> <?= htmlspecialchars(trim(($t['vehicle_name'] ?? '') . ' ' . ($t['plate_no'] ?? ''))) ?></span>
                </div>
            </div>
            <?= tripBadge($t['status'] ?? '') ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div><!-- /mob-scroll -->


<!-- DESKTOP — Sidebar -->
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo"><img src="../image/Csu.png" alt="CSU"></div>
        <div class="sidebar-brand-text">CSU Vehicle System<span>Driver Portal</span></div>
    </div>
    <nav class="nav flex-column mt-2">
        <div class="nav-section-label">Menu</div>
        <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a class="nav-link" href="my_trips.php"><i class="bi bi-map"></i> My Trips</a>
        <hr class="sidebar-divider">
        <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
    </nav>
</div>

<!-- DESKTOP — Topbar -->
<div class="topbar">
    <div class="topbar-title"><i class="bi bi-speedometer2"></i> Driver Dashboard</div>
    <div class="topbar-user">
        <div>
            <div style="font-weight:600;color:#333;font-size:0.82rem"><?= htmlspecialchars($displayName) ?></div>
            <div style="font-size:0.68rem;color:#7a0000">Driver</div>
        </div>
        <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
    </div>
</div>

<!-- DESKTOP — Main Content -->
<div class="main-content">

    <!-- Profile banner -->
    <div class="profile-card">
        <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
        <div>
            <div class="profile-name"><?= htmlspecialchars($displayName) ?></div>
            <div class="profile-meta">
                <span><i class="bi bi-card-text"></i> <?= htmlspecialchars($driver['license_no'] ?? 'No license on file') ?></span>
                <span><i class="bi bi-telephone"></i> <?= htmlspecialchars($driver['phone_no'] ?? '—') ?></span>
                <span><i class="bi bi-building"></i> <?= htmlspecialchars($driver['office_name'] ?? '—') ?></span>
                <span>
                    <i class="bi bi-circle-fill" style="color:<?= $stColor ?>;font-size:0.48rem;"></i>
                    <?= htmlspecialchars($st ?: 'Unknown') ?>
                </span>
            </div>
        </div>
    </div>

    <!-- 4-column stat cards -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon total"><i class="bi bi-signpost-2"></i></div>
            <div class="stat-label">Total Trips</div>
            <div class="stat-value"><?= $cntTotal ?></div>
            <div class="stat-sub">All time</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon today"><i class="bi bi-calendar-event"></i></div>
            <div class="stat-label">Today's Trips</div>
            <div class="stat-value"><?= $cntToday ?></div>
            <div class="stat-sub"><?= date('M d, Y') ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon approved"><i class="bi bi-check2-circle"></i></div>
            <div class="stat-label">Approved / Active</div>
            <div class="stat-value"><?= $cntApproved ?></div>
            <div class="stat-sub">Confirmed trips</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon done"><i class="bi bi-trophy"></i></div>
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?= $cntCompleted ?></div>
            <div class="stat-sub">Finished trips</div>
        </div>
    </div>

    <!-- Vehicle banner — full width -->
    <div class="vehicle-banner">
        <div class="vehicle-banner-icon"><i class="bi bi-truck-front"></i></div>
        <div class="vehicle-banner-main">
            <div class="vehicle-banner-plate"><?= htmlspecialchars($vehiclePlate) ?></div>
            <div class="vehicle-banner-name">
                <?= $vehicle
                    ? htmlspecialchars(trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '')))
                    : 'No vehicle currently assigned' ?>
            </div>
        </div>
        <?php if (!$vehicle): ?>
        <span class="na-badge">Not Assigned</span>
        <?php endif; ?>
    </div>

    <!-- Upcoming schedule — full width -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-calendar3 me-2"></i>Upcoming Schedule</span>
            <a href="my_trips.php">View all <i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr><th>Date</th><th>Destination</th><th>Vehicle</th><th>Requestor</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php if (empty($trips)): ?>
                <tr><td colspan="5" class="text-center text-muted py-5">
                    <i class="bi bi-map d-block mb-2 opacity-50" style="font-size:2rem;"></i>No upcoming trips.
                </td></tr>
                <?php else: ?>
                <?php foreach ($trips as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['travel_date'] ?? ($t['date_start'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars($t['destination'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(trim(($t['vehicle_name'] ?? '') . ' ' . ($t['plate_no'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars($t['requestor_name'] ?? '—') ?></td>
                    <td><?= tripBadge($t['status'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleMobSidebar() {
    document.getElementById('mobSidebar').classList.toggle('open');
    document.getElementById('mobSidebarOverlay').classList.toggle('open');
}
</script>
</body>
</html>