<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();
date_default_timezone_set('Asia/Manila');

/* ── Current admin + office ── */
$cu = $pdo->prepare("SELECT u.*, o.office_id AS u_office_id, o.office_name FROM users u LEFT JOIN offices o ON u.office_id=o.office_id WHERE u.user_id=?");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();
$myOfficeId = (int)($me['u_office_id'] ?? 0);
$officeName = $me['office_name'] ?? '';

/* ── AJAX actions ── */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $uid = (int)$_SESSION['user_id'];
    switch ($_GET['action']) {
        case 'mark_read':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id, $uid]);
            echo json_encode(['success' => true]); break;
        case 'mark_all_read':
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
            echo json_encode(['success' => true]); break;
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$id, $uid]);
            echo json_encode(['success' => true]); break;
        case 'clear_all':
            $pdo->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$uid]);
            echo json_encode(['success' => true]); break;
    }
    exit;
}

/* ── Fetch notifications ── */
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

/* ── Unread count ── */
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$_SESSION['user_id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/* ── Detect type from message text ── */
function detect_type(string $msg): array {
    $m = strtolower($msg);
    if (str_contains($m,'schedule conflict') || str_contains($m,'conflict detected'))
        return ['icon'=>'bi-exclamation-diamond-fill','label'=>'Conflict','bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029'];
    if (str_contains($m,'pending trip reminder'))
    return ['icon'=>'bi-hourglass-split','label'=>'Pending Reminder','bg'=>'#fff3cd','color'=>'#856404','badgebg'=>'#fff3cd','badgecolor'=>'#856404'];
    if (str_contains($m,'rescheduled'))
    return ['icon'=>'bi-calendar2-event','label'=>'Rescheduled','bg'=>'#fff3cd','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#854f0b'];
    if (str_contains($m,'overdue') || str_contains($m,'not yet returned'))
        return ['icon'=>'bi-exclamation-triangle-fill','label'=>'Overdue',    'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029'];
    if (str_contains($m,'rejected'))
        return ['icon'=>'bi-x-circle-fill',            'label'=>'Rejected',   'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029'];
    if (str_contains($m,'cancelled') || str_contains($m,'canceled'))
        return ['icon'=>'bi-slash-circle-fill',        'label'=>'Cancelled',  'bg'=>'#fff0d6','color'=>'#7a4f00','badgebg'=>'#fff0d6','badgecolor'=>'#7a4f00'];
    if (str_contains($m,'approved'))
        return ['icon'=>'bi-check-circle-fill',        'label'=>'Approved',   'bg'=>'#d1f0e0','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132'];
    if (str_contains($m,'completed') || str_contains($m,'complete'))
        return ['icon'=>'bi-flag-fill',                'label'=>'Completed',  'bg'=>'#e1f5ee','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132'];
    if (str_contains($m,'reminder') || str_contains($m,'departs in') || str_contains($m,'1 hour'))
        return ['icon'=>'bi-alarm-fill',               'label'=>'Reminder',   'bg'=>'#faeeda','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404'];
    if (str_contains($m,'walk-in') || str_contains($m,'walk in'))
        return ['icon'=>'bi-pencil-square',            'label'=>'Walk-in',    'bg'=>'#eaf3de','color'=>'#3b6d11','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132'];
    if (str_contains($m,'new request') || str_contains($m,'submitted'))
        return ['icon'=>'bi-file-earmark-plus',        'label'=>'New Request','bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678'];
    return     ['icon'=>'bi-bell-fill',                'label'=>'Notice',     'bg'=>'#f5f0f0','color'=>'#666',  'badgebg'=>'#e2e3e5','badgecolor'=>'#41464b'];
}

/* ── Helpers ── */
function time_ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'Just now';
    if ($d < 3600)   return (int)($d/60)   . ' min'  . ((int)($d/60)   !== 1 ? 's' : '') . ' ago';
    if ($d < 86400)  return (int)($d/3600) . ' hr'   . ((int)($d/3600) !== 1 ? 's' : '') . ' ago';
    if ($d < 172800) return 'Yesterday · ' . date('g:i A', strtotime($dt));
    return date('M j, Y · g:i A', strtotime($dt));
}
function is_today(string $dt): bool    { return date('Y-m-d',strtotime($dt)) === date('Y-m-d'); }
function is_yesterday(string $dt): bool{ return date('Y-m-d',strtotime($dt)) === date('Y-m-d',strtotime('-1 day')); }

/* ── Group by date ── */
$grouped = ['Today'=>[],'Yesterday'=>[],'Earlier'=>[]];
foreach ($notifications as $n) {
    if      (is_today($n['created_at']))     $grouped['Today'][]     = $n;
    elseif  (is_yesterday($n['created_at'])) $grouped['Yesterday'][] = $n;
    else                                     $grouped['Earlier'][]   = $n;
}

$totalCount = count($notifications);
$unreadAll  = count(array_filter($notifications, fn($n) => !$n['is_read']));
$todayCount = count($grouped['Today']);
$alertCount = count(array_filter($notifications, fn($n) => preg_match('/overdue|rejected/i', $n['message'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Notifications – CSU VSS Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
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
.notif-badge-pill{background:#e24b4a;color:#fff;font-size:.62rem;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;margin-left:auto}
.topbar{background:#fff;border-bottom:1px solid #e8dede;padding:.7rem 1.5rem;margin-left:240px;position:sticky;top:0;z-index:99;display:flex;align-items:center;justify-content:space-between}
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}
.main-content{margin-left:240px;padding:1.5rem}
.section-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.06);overflow:hidden;margin-bottom:1.25rem}
.section-header{padding:.85rem 1.25rem;border-bottom:1px solid #f0e5e5;font-weight:700;font-size:.88rem;color:#800000;display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap}
.stat-pill{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #f0e5e5;border-radius:10px;padding:.6rem 1rem}
.stat-pill-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.stat-pill-val{font-size:1.1rem;font-weight:700;color:#2d2d2d;line-height:1}
.stat-pill-lbl{font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.04em}
.notif-card{display:flex;align-items:flex-start;gap:13px;padding:.85rem 1.25rem;border-bottom:1px solid #fdf5f5;cursor:pointer;transition:background .1s;position:relative;user-select:none}
.notif-card *{pointer-events:none}
.notif-card:last-child{border-bottom:none}
.notif-card:hover{background:#fdf8f8}
.notif-card.unread{border-left:3px solid #800000;padding-left:calc(1.25rem - 2px);background:#fffcfc}
.notif-icon{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.notif-body{flex:1;min-width:0}
.notif-message{font-size:.83rem;color:#333;line-height:1.55}
.notif-footer{display:flex;align-items:center;justify-content:space-between;margin-top:6px;flex-wrap:wrap;gap:4px}
.notif-time{font-size:.72rem;color:#bbb;display:flex;align-items:center;gap:4px}
.type-badge{font-size:.68rem;font-weight:600;padding:2px 9px;border-radius:10px}
.unread-dot{width:8px;height:8px;border-radius:50%;background:#800000;flex-shrink:0;margin-top:6px;pointer-events:none}
.notif-actions{display:none;gap:4px;position:absolute;top:.85rem;right:1rem}
.notif-card:hover .notif-actions{display:flex}
.notif-action-btn{width:26px;height:26px;border-radius:6px;border:1px solid #e8dede;background:#fff;color:#888;font-size:.72rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .12s}
.notif-action-btn:hover{background:#fdecea;border-color:#800000;color:#800000}
.date-label{font-size:.7rem;font-weight:700;color:#aaa;letter-spacing:.07em;text-transform:uppercase;padding:.75rem 1.25rem .4rem;background:#fdf8f8;border-bottom:1px solid #f5eeee}
.empty-state{text-align:center;padding:3.5rem 1rem;color:#ccc}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3}
.empty-state p{font-size:.85rem}
/* ══ MOBILE NOTIFICATIONS ══ */
.hamburger-btn {
    display: none; background: none; border: none;
    cursor: pointer; padding: 4px 8px; color: #800000;
    font-size: 1.4rem; align-items: center; line-height: 1;
}
.sidebar-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 399;
}
.sidebar-overlay.show { display: block; }

@media (max-width: 900px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform .25s ease;
        z-index: 400;
    }
    .sidebar.open { transform: translateX(0); }
    .topbar, .main-content { margin-left: 0 !important; }
    .hamburger-btn { display: flex !important; }
    .topbar {
        padding: .6rem 1rem; gap: 8px;
        justify-content: flex-start;
    }
    .topbar .topbar-title { flex: 1; }
    .main-content { padding: .85rem; }

    /* Stats grid 2-col on mobile */
    .row.g-3.mb-3 > .col-6 { width: 50%; }

    /* Stat pill compact */
    .stat-pill { padding: .5rem .75rem; gap: 6px; }
    .stat-pill-icon { width: 28px; height: 28px; font-size: .78rem; }
    .stat-pill-val { font-size: .95rem; }
    .stat-pill-lbl { font-size: .6rem; }

    /* Section header wraps nicely */
    .section-header { padding: .75rem 1rem; font-size: .82rem; }

    /* Notification cards mobile */
    .notif-card { padding: .75rem 1rem; gap: 10px; }
    .notif-card.unread { padding-left: calc(1rem - 2px); }
    .notif-icon { width: 36px; height: 36px; font-size: .95rem; flex-shrink: 0; }
    .notif-message { font-size: .8rem; }
    .notif-time { font-size: .68rem; }
    .type-badge { font-size: .64rem; padding: 2px 7px; }
    .unread-dot { width: 7px; height: 7px; margin-top: 5px; }
    .date-label { padding: .6rem 1rem .35rem; font-size: .66rem; }

    /* Always show action buttons on mobile (no hover) */
    .notif-actions {
        display: flex !important;
        position: static;
        flex-direction: column;
        gap: 4px;
        flex-shrink: 0;
        margin-left: auto;
        padding-left: 4px;
    }
    .notif-action-btn {
        width: 28px; height: 28px; font-size: .7rem;
    }

    /* Top bar heading area */
    .d-flex.align-items-center.justify-content-between.mb-4 {
        margin-bottom: 1rem !important;
    }
    h5.fw-bold { font-size: .95rem; }
    .text-muted.small { font-size: .72rem; }

    /* Modal adjustments */
    .modal-body.py-4 { padding: 1.25rem 1rem !important; }
    .modal-body .row.g-3 .col-6 { width: 50%; }
}

@media (max-width: 480px) {
    .main-content { padding: .65rem; }
    .notif-card { padding: .65rem .75rem; gap: 8px; }
    .notif-icon { width: 32px; height: 32px; font-size: .85rem; }
    .notif-message { font-size: .77rem; }
    .stat-pill { padding: .45rem .6rem; }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="notifSidebarOverlay" onclick="toggleNotifSidebar()"></div>
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
        <a class="nav-link " href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i> Driver Trip Records</a>
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

<div class="topbar">
    <button class="hamburger-btn" onclick="toggleNotifSidebar()" aria-label="Menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-bell me-2"></i>Notifications</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<div class="main-content">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h5 class="fw-bold mb-0" style="color:#800000">Notifications</h5>
            <div class="text-muted small"><?= date('l, F j, Y') ?> &nbsp;·&nbsp; <span id="live-time"><?= date('g:i A') ?></span> PHT</div>
        </div>
        <?php if($officeName): ?>
        <span style="display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;border:1px solid #e8cece;border-radius:20px;padding:3px 12px;font-size:.78rem;color:#800000;font-weight:600">
            <i class="bi bi-building" style="font-size:.8rem"></i><?= htmlspecialchars($officeName) ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Summary stats -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:#fdecea;color:#800000"><i class="bi bi-bell-fill"></i></div>
                <div><div class="stat-pill-val"><?= $totalCount ?></div><div class="stat-pill-lbl">Total</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:#fff3cd;color:#856404"><i class="bi bi-envelope-fill"></i></div>
                <div><div class="stat-pill-val"><?= $unreadAll ?></div><div class="stat-pill-lbl">Unread</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:#e8f4fd;color:#0c5480"><i class="bi bi-calendar-day"></i></div>
                <div><div class="stat-pill-val"><?= $todayCount ?></div><div class="stat-pill-lbl">Today</div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill">
                <div class="stat-pill-icon" style="background:#f8d7da;color:#842029"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div><div class="stat-pill-val"><?= $alertCount ?></div><div class="stat-pill-lbl">Alerts</div></div>
            </div>
        </div>
    </div>

    <!-- Notification list -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-bell me-2"></i>All Notifications</span>
            <div class="d-flex gap-2">
                <?php if($unreadAll > 0): ?>
                <button onclick="markAllRead()" class="btn btn-sm" style="font-size:.75rem;color:#800000;border:1px solid #e8cece;background:#fff;border-radius:8px;padding:4px 12px">
                    <i class="bi bi-check2-all me-1"></i>Mark all read
                </button>
                <?php endif; ?>
                
            </div>
        </div>

        <?php
        $hasAny = false;
        foreach ($grouped as $dateLabel => $items):
            if (empty($items)) continue;
            $hasAny = true;
        ?>
        <div class="date-label"><?= $dateLabel ?></div>
        <?php foreach ($items as $n):
            $cfg   = detect_type($n['message']);
            $unread = !$n['is_read'];
        ?>
        <div class="notif-card <?= $unread ? 'unread' : '' ?>"
     id="notif-<?= $n['id'] ?>"
     data-id="<?= $n['id'] ?>"
     data-unread="<?= $unread ? '1' : '0' ?>"
     data-ref="<?= htmlspecialchars(preg_match('/\[ref:(\d+)\]/', $n['message'], $_rm) ? $_rm[1] : '') ?>">

            <div class="notif-icon" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
                <i class="bi <?= $cfg['icon'] ?>"></i>
            </div>

            <div class="notif-body">
                <div class="notif-message"><?= htmlspecialchars(preg_replace('/\s*\[ref:\d+\]/', '', $n['message'])) ?></div>
                <div class="notif-footer">
                    <span class="notif-time">
                        <i class="bi bi-clock" style="font-size:.65rem"></i>
                        <?= time_ago($n['created_at']) ?>
                    </span>
                    <span class="type-badge" style="background:<?= $cfg['badgebg'] ?>;color:<?= $cfg['badgecolor'] ?>">
                        <?= $cfg['label'] ?>
                    </span>
                </div>
            </div>

            <?php if ($unread): ?>
            <div class="unread-dot"></div>
            <?php endif; ?>

            <div class="notif-actions" style="pointer-events:all">
                <button class="notif-action-btn" title="Delete"
                    onclick="event.stopPropagation();deleteNotif(<?= $n['id'] ?>)"
                    style="pointer-events:all">
                    <i class="bi bi-trash"></i>
                </button>
            </div>

        </div>
        <?php endforeach; endforeach; ?>

        <?php if (!$hasAny): ?>
        <div class="empty-state">
            <i class="bi bi-bell-slash"></i>
            <p class="fw-semibold" style="color:#aaa">No notifications yet.</p>
            <p style="font-size:.78rem;color:#ccc">You're all caught up!</p>
        </div>
        <?php endif; ?>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Live clock ── */
function updateClock(){
    const now=new Date(); let h=now.getHours(),m=String(now.getMinutes()).padStart(2,'0'),s=String(now.getSeconds()).padStart(2,'0');
    const ampm=h>=12?'PM':'AM'; h=h%12||12;
    const el=document.getElementById('live-time'); if(el) el.textContent=`${h}:${m}:${s} ${ampm}`;
}
updateClock(); setInterval(updateClock,1000);

/* ── Parsers ── */
function parseRequestorMsg(msg) {
    const staffMatch = msg.match(/created by staff ([^:]+):/i);
    const nameMatch  = msg.match(/:\s*"([^"]+)"/);
    const deptMatch  = msg.match(/—\s*([^\[]+)/);
    return {
        staff : staffMatch ? staffMatch[1].trim() : '—',
        name  : nameMatch  ? nameMatch[1].trim()  : '—',
        dept  : deptMatch  ? deptMatch[1].trim()  : '—',
    };
}

function parseRescheduleMsg(msg) {
    const tripMatch  = msg.match(/Trip\s*(#\d+)/i);
    const byMatch    = msg.match(/rescheduled by (.+?)\s*\u2014/i);
    const oldMatch   = msg.match(/Old:\s*(.+?)\s+to\s+(.+?)\s*\u2014/i);
    const newMatch   = msg.match(/New:\s*(.+?)\s+to\s+(.+?)(?:\.\s*\[|\.?\s*$)/i);
    return {
        trip     : tripMatch ? tripMatch[1].trim() : '—',
        by       : byMatch   ? byMatch[1].trim()   : '—',
        oldStart : oldMatch  ? oldMatch[1].trim()  : '—',
        oldEnd   : oldMatch  ? oldMatch[2].trim()  : '—',
        newStart : newMatch  ? newMatch[1].trim()  : '—',
        newEnd   : newMatch  ? newMatch[2].trim()  : '—',
    };
}

function parseCancelMsg(msg) {
    // Trip #0067 has been cancelled by john — Reason: sdasdasxas.
    const tripMatch   = msg.match(/Trip\s*(#\d+)/i);
    const byMatch     = msg.match(/cancelled by ([^—]+)—/i);
    const reasonMatch = msg.match(/Reason:\s*(.+?)\.?\s*$/i);
    return {
        trip   : tripMatch   ? tripMatch[1].trim()   : '—',
        by     : byMatch     ? byMatch[1].trim()     : '—',
        reason : reasonMatch ? reasonMatch[1].trim() : '—',
    };
}

/* ── Notification click ── */
document.addEventListener('click', function(e) {
    const card = e.target.closest('.notif-card');
    if (!card) return;

    const id       = card.dataset.id;
    const isUnread = card.dataset.unread === '1';
    const ref      = card.dataset.ref;
    const msgEl    = card.querySelector('.notif-message');
    const msg      = msgEl ? msgEl.textContent.trim() : '';
    const timeEl   = card.querySelector('.notif-time');
    const timeText = timeEl ? timeEl.textContent.trim() : '—';
    const msgLower = msg.toLowerCase();

    const isRequestorNotif  = msgLower.includes('requestor account created');
    const isRescheduleNotif = msgLower.includes('rescheduled');
    const isCancelNotif     = msgLower.includes('cancelled') || msgLower.includes('canceled');

    /* Mark as read */
    if (isUnread) {
        card.classList.remove('unread');
        card.dataset.unread = '0';
        card.style.background = '';
        card.querySelector('.unread-dot')?.remove();
        fetch('notification.php?action=mark_read', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + id
        });
    }

    if (isRequestorNotif) {
        const p = parseRequestorMsg(msg);
        document.getElementById('rd-name').textContent  = p.name;
        document.getElementById('rd-dept').textContent  = p.dept !== '—' ? p.dept : 'No department';
        document.getElementById('rd-staff').textContent = p.staff;
        document.getElementById('rd-time').textContent  = timeText;
        document.getElementById('rd-avatar').textContent = p.name.charAt(0).toUpperCase();
        new bootstrap.Modal(document.getElementById('requestorDetailModal')).show();

    } else if (isRescheduleNotif) {
    const p = parseRescheduleMsg(msg);
    document.getElementById('rs-trip').textContent     = p.trip;
    document.getElementById('rs-by').textContent       = p.by;
    document.getElementById('rs-oldstart').textContent = p.oldStart;
    document.getElementById('rs-oldend').textContent   = p.oldEnd;
    document.getElementById('rs-newstart').textContent = p.newStart;
    document.getElementById('rs-newend').textContent   = p.newEnd;
    document.getElementById('rs-time').textContent     = timeText;
    document.getElementById('rs-avatar').textContent   = p.by.charAt(0).toUpperCase();
    document.getElementById('rs-viewbtn').href         = 'Schedules.php' + (ref ? '?highlight=' + ref.replace('#','') : '');
    new bootstrap.Modal(document.getElementById('rescheduleDetailModal')).show();

    } else if (isCancelNotif) {
        const p = parseCancelMsg(msg);
        document.getElementById('cn-trip').textContent   = p.trip;
        document.getElementById('cn-by').textContent     = p.by;
        document.getElementById('cn-reason').textContent = p.reason;
        document.getElementById('cn-time').textContent   = timeText;
        document.getElementById('cn-avatar').textContent = p.by.charAt(0).toUpperCase();
        document.getElementById('cn-viewbtn').href       = 'Schedules.php' + (ref ? '?highlight=' + ref.replace('#','') : '');
        new bootstrap.Modal(document.getElementById('cancelDetailModal')).show();

    } else {
        window.location.href = 'Schedules.php' + (ref ? '?highlight=' + ref : '');
    }
});

/* ── Mark all read ── */
function markAllRead(){
    document.querySelectorAll('.notif-card.unread').forEach(el=>{
        el.classList.remove('unread');
        el.dataset.unread='0';
        el.style.background='';
        el.querySelector('.unread-dot')?.remove();
    });
    fetch('notification.php?action=mark_all_read',{method:'POST'});
    document.querySelector('[onclick="markAllRead()"]')?.remove();
}

function deleteNotif(id){
    const el=document.getElementById('notif-'+id); if(!el) return;
    el.style.transition='opacity .25s,transform .25s'; el.style.opacity='0'; el.style.transform='translateX(20px)';
    setTimeout(()=>{el.remove();checkEmpty();},260);
    fetch('notification.php?action=delete',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+id});
}

function checkEmpty(){
    document.querySelectorAll('.date-label').forEach(l=>{
        let n=l.nextElementSibling;
        if(!n||!n.classList.contains('notif-card')) l.remove();
    });
    if(!document.querySelectorAll('.notif-card').length){
        const card=document.querySelector('.section-card');
        if(card&&!card.querySelector('.empty-state'))
            card.insertAdjacentHTML('beforeend',`<div class="empty-state"><i class="bi bi-bell-slash"></i><p class="fw-semibold" style="color:#aaa">No notifications yet.</p><p style="font-size:.78rem;color:#ccc">You're all caught up!</p></div>`);
    }
}
</script>
<!-- RESCHEDULE DETAIL MODAL -->
<div class="modal fade" id="rescheduleDetailModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#854f0b,#7a4f00);color:#fff">
    <h5 class="modal-title"><i class="bi bi-calendar-event me-2"></i>Trip Rescheduled</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
  </div>
  <div class="modal-body py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
      <div id="rs-avatar" style="width:52px;height:52px;border-radius:50%;background:#854f0b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;flex-shrink:0"></div>
      <div>
        <div class="fw-bold fs-6" id="rs-trip"></div>
        <div class="text-muted" style="font-size:.78rem">Rescheduled Trip</div>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-6">
        <div style="background:#fff3cd;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Old Start</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rs-oldstart">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#fff3cd;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Old End</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rs-oldend">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#d1e7dd;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">New Start</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rs-newstart">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#d1e7dd;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">New End</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rs-newend">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#fdf5f5;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Rescheduled By</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rs-by">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#fdf5f5;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Notification Time</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rs-time">—</div>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer border-0 pt-0 gap-2">
    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
    <a id="rs-viewbtn" href="Schedules.php" class="btn btn-sm" style="background:#854f0b;color:#fff">
      <i class="bi bi-calendar-check me-1"></i>View Schedule
    </a>
  </div>
</div></div></div>

<!-- CANCEL DETAIL MODAL -->
<div class="modal fade" id="cancelDetailModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#a00000,#800000);color:#fff">
    <h5 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Trip Cancelled</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
  </div>
  <div class="modal-body py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
      <div id="cn-avatar" style="width:52px;height:52px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;flex-shrink:0"></div>
      <div>
        <div class="fw-bold fs-6" id="cn-trip"></div>
        <div class="text-muted" style="font-size:.78rem">Cancelled Trip</div>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-12">
        <div style="background:#fdecea;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Reason</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="cn-reason">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#fdf5f5;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Cancelled By</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="cn-by">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#fdf5f5;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Notification Time</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="cn-time">—</div>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer border-0 pt-0 gap-2">
    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
    <a id="cn-viewbtn" href="Schedules.php" class="btn btn-sm" style="background:#800000;color:#fff">
      <i class="bi bi-calendar-check me-1"></i>View Schedule
    </a>
  </div>
</div></div></div>
<!-- Requestor Detail Modal -->
<div class="modal fade" id="requestorDetailModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#800000,#6b0000);color:#fff">
    <h5 class="modal-title"><i class="bi bi-person-check me-2"></i>New Requestor Account</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
  </div>
  <div class="modal-body py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
      <div style="width:52px;height:52px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;flex-shrink:0" id="rd-avatar"></div>
      <div>
        <div class="fw-bold fs-6" id="rd-name"></div>
        <div class="text-muted" style="font-size:.78rem">Requestor Account</div>
      </div>
    </div>
    <div class="row g-3">
      <div class="col-6">
        <div style="background:#fdf5f5;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Department</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rd-dept">—</div>
        </div>
      </div>
      <div class="col-6">
        <div style="background:#fdf5f5;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Created By</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rd-staff">—</div>
        </div>
      </div>
      <div class="col-12">
        <div style="background:#fdf5f5;border-radius:10px;padding:.75rem 1rem">
          <div style="font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.06em;font-weight:700">Notification Time</div>
          <div class="fw-semibold mt-1" style="font-size:.85rem;color:#333" id="rd-time">—</div>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer border-0 pt-0 gap-2">
    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
    <a href="Users.php" class="btn btn-sm" style="background:#800000;color:#fff">
      <i class="bi bi-people me-1"></i>Go to Users
    </a>
  </div>
</div></div></div>
<script>
function toggleNotifSidebar(){
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('notifSidebarOverlay').classList.toggle('show');
}
/* Close sidebar when nav link tapped on mobile */
document.querySelectorAll('.sidebar .nav-link').forEach(function(link){
    link.addEventListener('click', function(){
        if(window.innerWidth <= 900){
            document.getElementById('mainSidebar').classList.remove('open');
            document.getElementById('notifSidebarOverlay').classList.remove('show');
        }
    });
});
</script>
</body>
</html>