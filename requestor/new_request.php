<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$errors  = [];
$success = false;

// ── Fetch current user's office & department ──
$user_stmt = $pdo->prepare("SELECT office_id, dept_id FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$current_user   = $user_stmt->fetch();
$user_office_id = $current_user['office_id'] ?? null;
$user_dept_id   = $current_user['dept_id']   ?? null;

// Unread notification count
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$user_id, $user_office_id]);
$unreadCount = (int)$unreadStmt->fetchColumn();

// ── Handle POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $destination   = trim($_POST['destination']   ?? '');
    $purpose       = trim($_POST['purpose']       ?? '');
    $passengers    = intval($_POST['passengers']  ?? 0);
    $date_start    = $_POST["date_start"]          ?? "";
    $date_end      = $_POST["date_end"]            ?? "";
    $time_start    = $_POST["time_start"]          ?? "";
    $time_end      = $_POST["time_end"]            ?? "";
    $office_id     = $user_office_id;
    $department_id = $user_dept_id;

    if (!$destination) $errors[] = "Destination is required.";
    if (!$purpose)     $errors[] = "Purpose is required.";
    if (!$date_start)  $errors[] = "Start date is required.";
    if (!$date_end)    $errors[] = "End date is required.";
    if (!$time_start)  $errors[] = "Start time is required.";
    if (!$time_end)    $errors[] = "End time is required.";
    if ($passengers < 1) $errors[] = "Number of passengers must be at least 1.";
    if ($date_start && $date_end && $date_end < $date_start)
        $errors[] = "End date cannot be before start date.";

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO schedules
                (user_id, office_id, department_id, destination, purpose, passengers,
                 date_start, date_end, time_start, time_end, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        $stmt->execute([
            $user_id, $office_id ?: null, $department_id ?: null,
            $destination, $purpose, $passengers,
            $date_start, $date_end, $time_start, $time_end,
        ]);

        $newScheduleId = (int)$pdo->lastInsertId();
        $requesterName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'A user';
        $notifMsg = "New trip request submitted by {$requesterName} — Destination: {$destination} on {$date_start}. [ref:{$newScheduleId}]";

        $recipients = $pdo->prepare("SELECT user_id FROM users WHERE role IN ('admin', 'staff') AND office_id = ? AND user_id != ?");
        $recipients->execute([$office_id, $user_id]);

        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, office_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
        foreach ($recipients->fetchAll() as $recipient) {
            $notifStmt->execute([$recipient['user_id'], $office_id, $notifMsg]);
        }

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Trip Request – CSU VSS</title>
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

/* ── Sidebar overlay ── */
.sidebar-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 99;
}
.sidebar-overlay.show { display: block; }

/* ── Hamburger ── */
.hamburger-btn {
    display: none;
    background: none; border: none; cursor: pointer;
    padding: 4px 6px; color: #800000; font-size: 1.3rem;
    align-items: center; justify-content: center;
    margin-right: 4px;
}

/* ── Topbar ── */
.topbar {
    background: #fff; border-bottom: 1px solid #e8dede;
    padding: 0.7rem 1.5rem; margin-left: 240px;
    position: sticky; top: 0; z-index: 99;
    display: flex; align-items: center; justify-content: space-between;
}
.topbar-title { font-weight: 700; font-size: 1rem; color: #800000; display: flex; align-items: center; gap: 6px; position: absolute; left: 50%; transform: translateX(-50%); }
.topbar-user { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #666; }
.user-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: #800000; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700; flex-shrink: 0;
}

/* ── Main ── */
.main-content { margin-left: 240px; padding: 1.5rem; }

/* ── Page Header Banner ── */
.page-header {
    background: linear-gradient(135deg, #800000 0%, #4a0000 60%, #2d0000 100%);
    color: #fff; border-radius: 16px;
    padding: 1.4rem 2rem; margin-bottom: 1.5rem;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 8px 32px rgba(128,0,0,0.3);
    position: relative; overflow: hidden;
}
.page-header::before {
    content: ''; position: absolute; top: -40px; right: -40px;
    width: 160px; height: 160px; background: rgba(255,255,255,0.04); border-radius: 50%;
}
.page-header h5 { font-weight: 800; margin: 0 0 4px; font-size: 1.1rem; z-index:1; position:relative; }
.page-header p  { margin: 0; opacity: 0.65; font-size: 0.82rem; z-index:1; position:relative; }
.page-header-icon {
    width: 52px; height: 52px; border-radius: 14px;
    background: rgba(255,255,255,0.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; color: #fff; z-index:1; flex-shrink:0;
}

/* ── Form Card ── */
.form-card {
    background: #fff; border-radius: 16px;
    box-shadow: 0 2px 16px rgba(128,0,0,0.08);
    overflow: hidden; margin-bottom: 1.25rem;
}
.form-card-header {
    background: #fdf5f5;
    padding: 0.85rem 1.5rem;
    border-bottom: 1px solid #f0e5e5;
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 0.88rem; color: #800000;
}
.form-card-header i { font-size: 1rem; }
.form-card-body { padding: 1.5rem; }

/* ── Form Controls ── */
.form-label {
    font-size: 0.8rem; font-weight: 700;
    color: #555; margin-bottom: 5px;
    display: flex; align-items: center; gap: 5px;
}
.form-label .req { color: #800000; }
.form-control, .form-select {
    border: 1.5px solid #e8dede;
    border-radius: 10px;
    font-size: 0.875rem;
    padding: 0.55rem 0.85rem;
    color: #333; background: #fff;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.form-control:focus, .form-select:focus {
    border-color: #800000;
    box-shadow: 0 0 0 3px rgba(128,0,0,0.1);
    outline: none;
}
.form-control::placeholder { color: #bbb; font-size: 0.82rem; }
textarea.form-control { resize: vertical; min-height: 90px; }

/* ── Buttons ── */
.btn-submit {
    background: linear-gradient(135deg, #800000, #5a0000);
    color: #fff; border: none;
    border-radius: 12px; font-weight: 700;
    font-size: 0.9rem; padding: 0.7rem 2rem;
    display: flex; align-items: center; gap: 8px;
    cursor: pointer; transition: all 0.2s;
    box-shadow: 0 4px 16px rgba(128,0,0,0.3);
}
.btn-submit:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(128,0,0,0.4); color: #fff; }
.btn-submit:active { transform: translateY(0); }
.btn-cancel {
    background: #f5f0f0; color: #666;
    border: 1.5px solid #e0d8d8;
    border-radius: 12px; font-weight: 600;
    font-size: 0.88rem; padding: 0.7rem 1.5rem;
    text-decoration: none; display: flex; align-items: center; gap: 6px;
    transition: all 0.15s;
}
.btn-cancel:hover { background: #ede5e5; color: #800000; border-color: #c0a0a0; }

/* ── Alert ── */
.alert-error {
    background: #fdf3f3; border: 1.5px solid #f5c6cb;
    border-radius: 12px; padding: 0.9rem 1.25rem;
    margin-bottom: 1.25rem;
    font-size: 0.84rem; color: #842029;
}
.alert-error ul { margin: 0.3rem 0 0 1rem; padding: 0; }
.alert-success {
    background: #f0fff4; border: 1.5px solid #b2dfdb;
    border-radius: 12px; padding: 1.25rem 1.5rem;
    text-align: center; color: #0f5132;
}
.alert-success i { font-size: 2.5rem; color: #198754; display: block; margin-bottom: 0.5rem; }
.alert-success h6 { font-weight: 800; font-size: 1rem; margin-bottom: 4px; }
.alert-success p  { font-size: 0.84rem; margin: 0; opacity: 0.8; }

/* ── Input icon wrapper ── */
.input-icon-wrap { position: relative; }
.input-icon-wrap .icon {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: #800000; font-size: 0.9rem; pointer-events: none;
}
.input-icon-wrap .form-control,
.input-icon-wrap .form-select { padding-left: 2.1rem; }
.input-icon-wrap.textarea-wrap .icon { top: 14px; transform: none; }
.input-icon-wrap.textarea-wrap .form-control { padding-left: 2.1rem; }

/* ── Step indicator ── */
.step-bar {
    display: flex; align-items: center;
    margin-bottom: 1.5rem;
    background: #fff; border-radius: 14px;
    box-shadow: 0 2px 12px rgba(128,0,0,0.07);
    padding: 0.75rem 1.5rem; overflow-x: auto;
}
.step-item {
    display: flex; align-items: center; gap: 8px;
    font-size: 0.78rem; font-weight: 600; color: #bbb;
    white-space: nowrap;
}
.step-item.active { color: #800000; }
.step-item.done   { color: #198754; }
.step-dot {
    width: 26px; height: 26px; border-radius: 50%;
    background: #f0e5e5; color: #ccc;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
}
.step-item.active .step-dot { background: #800000; color: #fff; }
.step-item.done   .step-dot { background: #198754; color: #fff; }
.step-line { flex: 1; height: 2px; background: #f0e5e5; margin: 0 8px; min-width: 16px; }

/* ── Character counter ── */
.char-counter { font-size: 0.72rem; color: #bbb; text-align: right; margin-top: 3px; }
.char-counter.warn { color: #856404; }
.char-counter.over { color: #842029; }

/* ── Passenger counter widget ── */
.passenger-counter {
    display: flex; align-items: center;
    border: 1.5px solid #e8dede; border-radius: 10px;
    overflow: hidden; background: #fff;
    transition: border-color 0.15s, box-shadow 0.15s;
    width: fit-content;
}
.passenger-counter:focus-within { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,0.1); }
.passenger-counter button {
    width: 40px; height: 42px;
    background: #fdf5f5; border: none; outline: none;
    color: #800000; font-size: 1.1rem; font-weight: 700;
    cursor: pointer; transition: background 0.15s;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.passenger-counter button:hover { background: #f5e5e5; }
.passenger-counter button:active { background: #edd5d5; }
.passenger-counter input[type="number"] {
    width: 64px; height: 42px;
    border: none; outline: none; text-align: center;
    font-size: 1rem; font-weight: 700; color: #333; background: #fff;
    -moz-appearance: textfield; appearance: textfield;
}
.passenger-counter input[type="number"]::-webkit-outer-spin-button,
.passenger-counter input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.passenger-hint { font-size: 0.75rem; color: #aaa; margin-top: 5px; }

/* ── Action row ── */
.action-row {
    display: flex; justify-content: flex-end; align-items: center;
    gap: 10px; flex-wrap: wrap;
}

/* ══════════════════════════════════
   MOBILE RESPONSIVE
══════════════════════════════════ */
@media (max-width: 768px) {
    /* Sidebar hidden by default */
    .sidebar { transform: translateX(-100%); z-index: 200; }
    .sidebar.open { transform: translateX(0); }

    /* Show hamburger */
    .hamburger-btn { display: flex; }

    /* Remove sidebar offset */
    .topbar, .main-content { margin-left: 0 !important; }
    .main-content { padding: 1rem; }
    .topbar { padding: 0.6rem 1rem; }

    /* Page header stacks */
    .page-header {
        flex-direction: column; align-items: flex-start;
        gap: 12px; padding: 1.1rem 1.25rem;
    }
    .page-header-icon { display: none; }

    /* Form card padding */
    .form-card-body { padding: 1rem; }

    /* Row grids go single column */
    .row.g-3 > [class*="col-md"] { flex: 0 0 100%; max-width: 100%; }

    /* Step bar shrinks text */
    .step-bar { padding: 0.6rem 1rem; }
    .step-item { font-size: 0.7rem; gap: 5px; }
    .step-dot  { width: 22px; height: 22px; font-size: 0.68rem; }
    .step-line { min-width: 8px; }

    /* Passenger counter full width */
    .passenger-counter { width: 100%; }
    .passenger-counter input[type="number"] { flex: 1; width: auto; }

    /* Action row */
    .action-row { justify-content: stretch; }
    .btn-submit, .btn-cancel { width: 100%; justify-content: center; }

    /* Success button row */
    .alert-success .d-flex { flex-direction: column; }
    .alert-success .d-flex a { width: 100%; text-align: center; justify-content: center; }

    /* Topbar user role hide */
    .topbar-user > div:last-child > div:last-child { display: none; }
}

@media (max-width: 400px) {
    .topbar-title { font-weight: 700; font-size: 1rem; color: #800000;
        display: flex; align-items: center; }
    .page-header h5 { font-size: 0.95rem; }
}
</style>
</head>
<body>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ── Sidebar ── -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="../image/Csu.png" alt="Logo"></div>
    <div class="sidebar-brand-text">CSU Vehicle System<span>Requestor Panel</span></div>
  </div>
  <nav class="nav flex-column mt-2">
    <div class="nav-section-label">Main</div>
    <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <div class="nav-section-label">Requests</div>
    <a class="nav-link active" href="new_request.php"><i class="bi bi-plus-circle"></i> New Trip Request</a>
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
    <div style="display:flex;align-items:center;gap:4px;">
        <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
            <i class="bi bi-list"></i>
        </button>
        <div class="topbar-title">
            <i class="bi bi-plus-circle"></i> New Trip Request
        </div>
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

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h5><i class="bi bi-plus-circle-fill me-2"></i>New Trip Request</h5>
            <p>Fill in the details below to submit a vehicle trip request.</p>
        </div>
        <div class="page-header-icon">
            <i class="bi bi-truck-front-fill"></i>
        </div>
    </div>

    <?php if ($success): ?>
    <!-- ── Success State ── -->
    <div class="form-card">
        <div class="form-card-body" style="padding:2.5rem 1.5rem;">
            <div class="alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <h6>Request Submitted Successfully!</h6>
                <p>Your trip request has been submitted and is now <strong>pending approval</strong>.<br>You will be notified once it has been reviewed.</p>
                <div class="d-flex justify-content-center gap-3 mt-3 flex-wrap">
                    <a href="new_request.php" class="btn-submit text-decoration-none" style="font-size:0.84rem;padding:0.55rem 1.4rem;">
                        <i class="bi bi-plus-circle"></i> New Request
                    </a>
                    <a href="my_trip.php" class="btn-cancel" style="font-size:0.84rem;padding:0.55rem 1.4rem;">
                        <i class="bi bi-map"></i> View My Trips
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>

    <!-- ── Step Indicator ── -->
    <div class="step-bar">
        <div class="step-item active">
            <div class="step-dot">1</div> Trip Details
        </div>
        <div class="step-line"></div>
        <div class="step-item">
            <div class="step-dot">2</div> Admin Review
        </div>
        <div class="step-line"></div>
        <div class="step-item">
            <div class="step-dot">3</div> Approved
        </div>
        <div class="step-line"></div>
        <div class="step-item">
            <div class="step-dot">4</div> Completed
        </div>
    </div>

    <!-- ── Errors ── -->
    <?php if (!empty($errors)): ?>
    <div class="alert-error">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following:</strong>
        <ul>
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="new_request.php" novalidate>

        <!-- ── Section 1: Trip Info ── -->
        <div class="form-card">
            <div class="form-card-header">
                <i class="bi bi-map-fill"></i> Trip Information
            </div>
            <div class="form-card-body">
                <div class="row g-3">

                    <!-- Destination -->
                    <div class="col-12">
                        <label class="form-label">
                            <i class="bi bi-geo-alt-fill" style="color:#800000"></i>
                            Destination <span class="req">*</span>
                        </label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-geo-alt icon"></i>
                            <input type="text" name="destination" class="form-control"
                                placeholder="e.g. Baguio City Hall, Session Road..."
                                value="<?= htmlspecialchars($_POST['destination'] ?? '') ?>"
                                maxlength="100" required>
                        </div>
                    </div>

                    <!-- Purpose -->
                    <div class="col-12">
                        <label class="form-label">
                            <i class="bi bi-journal-text" style="color:#800000"></i>
                            Purpose / Reason for Trip <span class="req">*</span>
                        </label>
                        <div class="input-icon-wrap textarea-wrap">
                            <i class="bi bi-journal-text icon"></i>
                            <textarea name="purpose" id="purposeField" class="form-control"
                                placeholder="Briefly describe the purpose of this trip..."
                                maxlength="255" required><?= htmlspecialchars($_POST['purpose'] ?? '') ?></textarea>
                        </div>
                        <div class="char-counter" id="purposeCounter">0 / 255</div>
                    </div>

                    <!-- Number of Passengers -->
                    <div class="col-12 col-md-6">
                        <label class="form-label">
                            <i class="bi bi-people-fill" style="color:#800000"></i>
                            Number of Passengers <span class="req">*</span>
                        </label>
                        <div class="passenger-counter">
                            <button type="button" id="passengerMinus" aria-label="Decrease">
                                <i class="bi bi-dash"></i>
                            </button>
                            <input type="number" name="passengers" id="passengersInput"
                                value="<?= htmlspecialchars($_POST['passengers'] ?? '1') ?>"
                                min="1" max="99" required>
                            <button type="button" id="passengerPlus" aria-label="Increase">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                        <div class="passenger-hint"><i class="bi bi-info-circle"></i> Include all passengers (excluding the driver)</div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Section 2: Schedule ── -->
        <div class="form-card">
            <div class="form-card-header">
                <i class="bi bi-calendar3"></i> Schedule
            </div>
            <div class="form-card-body">
                <div class="row g-3">

                    <div class="col-12 col-md-6">
                        <label class="form-label">
                            <i class="bi bi-calendar-event" style="color:#800000"></i>
                            Date Start <span class="req">*</span>
                        </label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-calendar3 icon"></i>
                            <input type="date" name="date_start" id="dateStart" class="form-control"
                                value="<?= htmlspecialchars($_POST['date_start'] ?? '') ?>"
                                min="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">
                            <i class="bi bi-calendar-range" style="color:#800000"></i>
                            Date End <span class="req">*</span>
                        </label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-calendar3-range icon"></i>
                            <input type="date" name="date_end" id="dateEnd" class="form-control"
                                value="<?= htmlspecialchars($_POST['date_end'] ?? '') ?>"
                                min="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">
                            <i class="bi bi-clock" style="color:#800000"></i>
                            Time Start <span class="req">*</span>
                        </label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-clock icon"></i>
                            <input type="time" name="time_start" class="form-control"
                                value="<?= htmlspecialchars($_POST['time_start'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label">
                            <i class="bi bi-clock-history" style="color:#800000"></i>
                            Time End <span class="req">*</span>
                        </label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-clock-history icon"></i>
                            <input type="time" name="time_end" class="form-control"
                                value="<?= htmlspecialchars($_POST['time_end'] ?? '') ?>" required>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Hidden fields -->
        <input type="hidden" name="office_id"     value="<?= htmlspecialchars($user_office_id ?? '') ?>">
        <input type="hidden" name="department_id" value="<?= htmlspecialchars($user_dept_id   ?? '') ?>">

        <!-- ── Action Buttons ── -->
        <div class="action-row">
            <button type="submit" class="btn-submit">
                <i class="bi bi-send-fill"></i> Submit Request
            </button>
        </div>

    </form>
    <?php endif; ?>

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Sidebar toggle ──
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
    document.body.style.overflow =
        document.getElementById('sidebar').classList.contains('open') ? 'hidden' : '';
}
document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) toggleSidebar();
    });
});

// ── Character counter ──
const purposeField   = document.getElementById('purposeField');
const purposeCounter = document.getElementById('purposeCounter');
function updateCounter() {
    const len = purposeField.value.length;
    purposeCounter.textContent = len + ' / 255';
    purposeCounter.className = 'char-counter' +
        (len > 230 ? ' over' : len > 180 ? ' warn' : '');
}
if (purposeField) { purposeField.addEventListener('input', updateCounter); updateCounter(); }

// ── Auto-set date_end min ──
const dateStartEl = document.getElementById("dateStart");
const dateEndEl   = document.getElementById("dateEnd");
if (dateStartEl && dateEndEl) {
    dateStartEl.addEventListener("change", function () {
        dateEndEl.min = this.value;
        if (dateEndEl.value && dateEndEl.value < this.value) {
            dateEndEl.value = this.value;
        }
    });
}

// ── Passenger +/- ──
const passengersInput = document.getElementById('passengersInput');
const minusBtn = document.getElementById('passengerMinus');
const plusBtn  = document.getElementById('passengerPlus');

function clampPassengers() {
    let v = parseInt(passengersInput.value) || 1;
    if (v < 1)  v = 1;
    if (v > 99) v = 99;
    passengersInput.value = v;
}
if (minusBtn && plusBtn && passengersInput) {
    minusBtn.addEventListener('click', () => {
        let v = parseInt(passengersInput.value) || 1;
        if (v > 1) passengersInput.value = v - 1;
    });
    plusBtn.addEventListener('click', () => {
        let v = parseInt(passengersInput.value) || 1;
        if (v < 99) passengersInput.value = v + 1;
    });
    passengersInput.addEventListener('change', clampPassengers);
    passengersInput.addEventListener('blur',   clampPassengers);
}
</script>

</body>
</html>