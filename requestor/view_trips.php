<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: my_trips.php");
    exit();
}
$id = (int)$_GET['id'];

$q = $pdo->prepare("
    SELECT s.*,
           s.schedule_id AS id,
           v.plate_number, v.brand, v.model,
           CONCAT(v.brand, ' ', v.model) AS vehicle_name,
           d.driver_name, d.phone_no AS driver_phone
    FROM schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
    WHERE s.schedule_id = ? AND s.user_id = ?
    LIMIT 1
");
$q->execute([$id, $user_id]);
$trip = $q->fetch();

if (!$trip) {
    header("Location: my_trips.php?error=not_found");
    exit();
}

$page_title = 'Trip #' . str_pad($id, 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $page_title ?> – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; margin: 0; }

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
    border-left: 3px solid transparent; transition: all 0.15s; text-decoration: none;
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

/* ── Cards ── */
.detail-card {
    background: #fff; border-radius: 14px;
    box-shadow: 0 2px 12px rgba(128,0,0,0.07); overflow: hidden;
    margin-bottom: 1.25rem;
}
.detail-card-header {
    padding: 0.9rem 1.25rem; border-bottom: 1px solid #f0e5e5;
    font-weight: 700; font-size: 0.9rem; color: #800000;
    display: flex; align-items: center; gap: 10px;
}
.detail-card-header .hicon {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
}
.detail-card-body { padding: 1.25rem; }

/* ── Detail rows ── */
.detail-row {
    display: flex; gap: 12px; margin-bottom: 14px; align-items: flex-start;
}
.detail-row:last-child { margin-bottom: 0; }
.detail-label {
    flex-shrink: 0; width: 130px;
    font-size: 0.75rem; text-transform: uppercase;
    letter-spacing: 0.06em; color: #999; font-weight: 600; padding-top: 2px;
}
.detail-value { font-size: 0.9rem; font-weight: 500; flex: 1; color: #2d2d2d; }
.detail-value.empty { color: #bbb; font-style: italic; font-weight: 400; }

/* ── Badges ── */
.badge-pending   { background:#fff3cd; color:#856404; padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; display:inline-block; }
.badge-approved  { background:#d1e7dd; color:#0f5132; padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; display:inline-block; }
.badge-completed { background:#cfe2ff; color:#0a3678; padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; display:inline-block; }
.badge-rejected  { background:#f8d7da; color:#842029; padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; display:inline-block; }
.badge-cancelled { background:#e2e3e5; color:#41464b; padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; display:inline-block; }
.badge-ongoing   { background:#fff0d6; color:#7a4f00; padding:6px 14px; border-radius:20px; font-size:0.82rem; font-weight:700; display:inline-block; }

/* ── Timeline ── */
.timeline { position: relative; padding-left: 28px; }
.timeline::before {
    content: ''; position: absolute;
    left: 9px; top: 10px; bottom: 10px;
    width: 2px; background: #f0e5e5;
}
.tl-item { position: relative; margin-bottom: 20px; }
.tl-item:last-child { margin-bottom: 0; }
.tl-dot {
    position: absolute; left: -23px; top: 4px;
    width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid #e0d0d0; background: #fff;
}
.tl-dot.done    { background: #0f5132; border-color: #0f5132; }
.tl-dot.current { background: #800000; border-color: #800000; box-shadow: 0 0 0 4px rgba(128,0,0,0.15); }
.tl-dot.failed  { background: #842029; border-color: #842029; box-shadow: 0 0 0 4px rgba(132,32,41,0.15); }
.tl-label { font-size: 0.85rem; font-weight: 600; color: #999; }
.tl-label.done    { color: #0f5132; }
.tl-label.current { color: #800000; }
.tl-label.failed  { color: #842029; }
.tl-sub { font-size: 0.75rem; color: #bbb; margin-top: 2px; }

/* ── Assigned items ── */
.assigned-item {
    display: flex; align-items: center; gap: 14px;
    padding: 12px 0; border-bottom: 1px solid #fdf5f5;
}
.assigned-item:last-child { border-bottom: none; padding-bottom: 0; }
.assigned-item:first-child { padding-top: 0; }
.assigned-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; flex-shrink: 0;
}
.assigned-info .aname { font-weight: 600; font-size: 0.9rem; color: #2d2d2d; }
.assigned-info .asub  { font-size: 0.78rem; color: #999; margin-top: 2px; }

.assign-placeholder {
    text-align: center; padding: 2rem 1rem; color: #ccc;
}
.assign-placeholder i { font-size: 2rem; display: block; margin-bottom: 0.5rem; color: #e0d0d0; }
.assign-placeholder p { font-size: 0.83rem; margin: 0; }

/* ── Back link ── */
.back-link {
    display: inline-flex; align-items: center; gap: 7px;
    color: #800000; text-decoration: none; font-size: 0.85rem;
    font-weight: 600; margin-bottom: 1.25rem; transition: opacity .2s;
}
.back-link:hover { opacity: 0.7; color: #800000; }

/* ── Page header ── */
.page-trip-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 1.5rem;
}
.trip-req-num {
    font-size: 1.5rem; font-weight: 800; color: #2d2d2d; line-height: 1.1;
}
.trip-sub { font-size: 0.8rem; color: #999; margin-top: 4px; }
</style>
</head>
<body>

<!-- ── Sidebar ── -->
<div class="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="../image/Csu.png" alt="Logo"></div>
    <div class="sidebar-brand-text">CSU Vehicle System<span>Requestor Panel</span></div>
  </div>
  <nav class="nav flex-column mt-2">
    <div class="nav-section-label">Main</div>
    <a class="nav-link " href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <div class="nav-section-label">Requests</div>
    <a class="nav-link" href="new_request.php"><i class="bi bi-plus-circle"></i> New Trip Request</a>
    <a class="nav-link" href="my_trip.php"><i class="bi bi-map"></i> My Trips</a>
    <div class="nav-section-label">Notifications</div>
    <a class="nav-link" href="notification_requestor.php"><i class="bi bi-bell"></i> Notifications</a>
    <div class="nav-section-label">Account</div>
    <a class="nav-link" href="my_account.php"><i class="bi bi-person-circle"></i> My Account</a>
    <hr class="sidebar-divider">
    <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i> Logout</a>
  </nav>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
    <div class="topbar-title">
        <i class="bi bi-map me-2"></i>Trip Details
    </div>
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

    <a href="my_trips.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to My Trips
    </a>

    <!-- Page Header -->
    <div class="page-trip-header">
        <div>
            <div class="trip-req-num">
                <i class="bi bi-receipt" style="color:#800000;font-size:1.2rem;margin-right:6px"></i>
                Request #<?= str_pad($trip['id'], 4, '0', STR_PAD_LEFT) ?>
            </div>
            <div class="trip-sub">
                <i class="bi bi-calendar3 me-1"></i>
                Submitted: <?= date('F d, Y', strtotime($trip['created_at'])) ?>
                &nbsp;·&nbsp;
                <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($trip['destination']) ?>
            </div>
        </div>
        <?php
            $s   = strtolower(str_replace(['-',' '], '', $trip['status'] ?? ''));
            $map = [
                'pending'   => 'badge-pending',
                'approved'  => 'badge-approved',
                'completed' => 'badge-completed',
                'rejected'  => 'badge-rejected',
                'cancelled' => 'badge-cancelled',
                'ongoing'   => 'badge-ongoing',
                'ontrip'    => 'badge-ongoing',
            ];
            $cls = $map[$s] ?? 'badge-pending';
            echo "<span class='$cls'>" . htmlspecialchars($trip['status']) . "</span>";
        ?>
    </div>

    <div class="row g-4">

        <!-- LEFT COLUMN -->
        <div class="col-lg-7">

            <!-- Trip Details -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="hicon" style="background:#fdecea;color:#800000"><i class="bi bi-geo-alt-fill"></i></div>
                    Trip Details
                </div>
                <div class="detail-card-body">
                    <div class="detail-row">
                        <span class="detail-label">Date Start</span>
                        <span class="detail-value"><?= date('l, F d, Y', strtotime($trip['date_start'])) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date End</span>
                        <span class="detail-value"><?= date('l, F d, Y', strtotime($trip['date_end'])) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Time Start</span>
                        <span class="detail-value"><?= !empty($trip['time_start']) ? date('h:i A', strtotime($trip['time_start'])) : '—' ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Time End</span>
                        <span class="detail-value"><?= !empty($trip['time_end']) ? date('h:i A', strtotime($trip['time_end'])) : '—' ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Destination</span>
                        <span class="detail-value"><?= htmlspecialchars($trip['destination']) ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Purpose</span>
                        <span class="detail-value"><?= nl2br(htmlspecialchars($trip['purpose'])) ?></span>
                    </div>
                    <?php if (!empty($trip['trip_ticket_no'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Trip Ticket #</span>
                        <span class="detail-value" style="font-weight:700;color:#800000"><?= htmlspecialchars($trip['trip_ticket_no']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($trip['arrived_at'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Arrived At</span>
                        <span class="detail-value"><?= date('F d, Y h:i A', strtotime($trip['arrived_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rejection / Cancellation reason -->
            <?php if (!empty($trip['rejection_reason']) || !empty($trip['cancel_reason'])): ?>
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="hicon" style="background:#f8d7da;color:#842029"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <?= !empty($trip['rejection_reason']) ? 'Rejection Reason' : 'Cancellation Reason' ?>
                </div>
                <div class="detail-card-body">
                    <p style="font-size:0.88rem;color:#555;margin:0;line-height:1.6;">
                        <?= nl2br(htmlspecialchars($trip['rejection_reason'] ?? $trip['cancel_reason'])) ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Manage (only if Pending) -->
            <?php if (strtolower($trip['status']) === 'pending'): ?>
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="hicon" style="background:#fff3cd;color:#856404"><i class="bi bi-pencil-square"></i></div>
                    Manage Request
                </div>
                <div class="detail-card-body">
                    <p style="font-size:0.85rem;color:#999;margin-bottom:1rem;">
                        Your request is still <strong style="color:#856404">Pending</strong>. You may edit or cancel it.
                    </p>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="edit_request.php?id=<?= $trip['id'] ?>"
                           class="btn btn-sm"
                           style="background:#fff3cd;color:#856404;border:none;font-weight:600;border-radius:8px;">
                            <i class="bi bi-pencil me-1"></i>Edit Request
                        </a>
                        <a href="cancel_request.php?id=<?= $trip['id'] ?>"
                           class="btn btn-sm"
                           style="background:#f8d7da;color:#842029;border:none;font-weight:600;border-radius:8px;"
                           onclick="return confirm('Are you sure you want to cancel this request?')">
                            <i class="bi bi-x-circle me-1"></i>Cancel Request
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /left col -->

        <!-- RIGHT COLUMN -->
        <div class="col-lg-5">

            <!-- Assignment -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="hicon" style="background:#d1e7dd;color:#0f5132"><i class="bi bi-truck-front-fill"></i></div>
                    Assignment
                </div>
                <div class="detail-card-body">
                    <?php if (empty($trip['vehicle_id']) && empty($trip['driver_id'])): ?>
                        <div class="assign-placeholder">
                            <i class="bi bi-hourglass-split"></i>
                            <p>No vehicle or driver assigned yet.<br>Check back after approval.</p>
                        </div>
                    <?php else: ?>
                        <?php if (!empty($trip['vehicle_id'])): ?>
                        <div class="assigned-item">
                            <div class="assigned-icon" style="background:#fdecea;font-size:1.4rem">🚐</div>
                            <div class="assigned-info">
                                <div class="aname"><?= htmlspecialchars($trip['vehicle_name'] ?? 'Vehicle') ?></div>
                                <div class="asub"><?= htmlspecialchars($trip['plate_number'] ?? '') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($trip['driver_id'])): ?>
                        <div class="assigned-item">
                            <div class="assigned-icon" style="background:#d1e7dd;font-size:1.4rem">👤</div>
                            <div class="assigned-info">
                                <div class="aname"><?= htmlspecialchars($trip['driver_name'] ?? 'Driver') ?></div>
                                <?php if (!empty($trip['driver_phone'])): ?>
                                <div class="asub"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($trip['driver_phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Timeline -->
            <div class="detail-card">
                <div class="detail-card-header">
                    <div class="hicon" style="background:#cfe2ff;color:#0a3678"><i class="bi bi-list-check"></i></div>
                    Status Timeline
                </div>
                <div class="detail-card-body">
                    <?php
                    $steps = ['Pending', 'Approved', 'Ongoing', 'Completed'];
                    $rejects = ['Rejected', 'Cancelled'];
                    $cur = $trip['status'] ?? 'Pending';
                    $is_bad = in_array($cur, $rejects);
                    $step_idx = array_search($cur, $steps);
                    if ($step_idx === false) $step_idx = 0;
                    ?>
                    <div class="timeline">
                        <?php if (!$is_bad): ?>
                            <?php foreach ($steps as $i => $step): ?>
                            <div class="tl-item">
                                <div class="tl-dot <?= $i < $step_idx ? 'done' : ($i == $step_idx ? 'current' : '') ?>"></div>
                                <div class="tl-label <?= $i < $step_idx ? 'done' : ($i == $step_idx ? 'current' : '') ?>">
                                    <?= $step ?><?= $i == $step_idx ? ' <span style="font-size:.72rem;opacity:.6">← Current</span>' : '' ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="tl-item">
                                <div class="tl-dot done"></div>
                                <div class="tl-label done">Submitted</div>
                            </div>
                            <div class="tl-item">
                                <div class="tl-dot failed"></div>
                                <div class="tl-label failed"><?= htmlspecialchars($cur) ?> <span style="font-size:.72rem;opacity:.6">← Current</span></div>
                                <?php if (!empty($trip['rejection_reason'])): ?>
                                <div class="tl-sub"><?= htmlspecialchars(substr($trip['rejection_reason'], 0, 60)) ?>...</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /right col -->

    </div><!-- /row -->

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>