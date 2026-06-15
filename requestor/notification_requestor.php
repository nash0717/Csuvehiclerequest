<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
date_default_timezone_set('Asia/Manila');

$userId = $_SESSION['user_id'];

/* ── AJAX actions ── */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    switch ($_GET['action']) {
        case 'mark_read':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id, $userId]);
            echo json_encode(['success' => true]); break;
        case 'mark_all_read':
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);
            echo json_encode(['success' => true]); break;
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$id, $userId]);
            echo json_encode(['success' => true]); break;
        case 'clear_all':
            $pdo->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$userId]);
            echo json_encode(['success' => true]); break;
    }
    exit;
}

/* ── Fetch notifications ── */
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

/* ── Unread count ── */
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$unreadStmt->execute([$userId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/* ── Detect notification type ── */
function detect_type(string $msg): array {
    $m = strtolower($msg);
    $first = strtoupper(trim(explode("\n", $msg)[0]));

    $newMap = [
        'SUBMITTED'          => ['icon'=>'bi-file-earmark-plus-fill','label'=>'Submitted',  'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678'],
        'APPROVED'           => ['icon'=>'bi-check-circle-fill',     'label'=>'Approved',   'bg'=>'#d1f0e0','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132'],
        'REJECTED'           => ['icon'=>'bi-x-circle-fill',         'label'=>'Rejected',   'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029'],
        'CANCELLED'          => ['icon'=>'bi-slash-circle-fill',     'label'=>'Cancelled',  'bg'=>'#fff0d6','color'=>'#7a4f00','badgebg'=>'#fff0d6','badgecolor'=>'#7a4f00'],
        'RESCHEDULED'        => ['icon'=>'bi-calendar2-event-fill',  'label'=>'Rescheduled','bg'=>'#fff3cd','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404'],
        'ASSIGNMENT UPDATED' => ['icon'=>'bi-arrow-repeat',          'label'=>'Assignment', 'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678'],
        'COMPLETED'          => ['icon'=>'bi-flag-fill',             'label'=>'Completed',  'bg'=>'#e1f5ee','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132'],
        'REMINDER' => ['icon'=>'bi-alarm-fill','label'=>'Reminder','bg'=>'#faeeda','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404'],
    ];
    if (isset($newMap[$first])) return $newMap[$first];

    if (str_contains($m,'submitted')||str_contains($m,'waiting for admin'))
        return ['icon'=>'bi-file-earmark-plus-fill','label'=>'Submitted',  'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678'];
    if (str_contains($m,'approved'))
        return ['icon'=>'bi-check-circle-fill',     'label'=>'Approved',   'bg'=>'#d1f0e0','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132'];
    if (str_contains($m,'rejected'))
        return ['icon'=>'bi-x-circle-fill',         'label'=>'Rejected',   'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029'];
    if (str_contains($m,'cancelled'))
        return ['icon'=>'bi-slash-circle-fill',     'label'=>'Cancelled',  'bg'=>'#fff0d6','color'=>'#7a4f00','badgebg'=>'#fff0d6','badgecolor'=>'#7a4f00'];
    if (str_contains($m,'rescheduled'))
        return ['icon'=>'bi-calendar2-event-fill',  'label'=>'Rescheduled','bg'=>'#fff3cd','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404'];
    if (str_contains($m,'has been updated') || (str_contains($m,'driver') && str_contains($m,'vehicle') && str_contains($m,'updated')))
        return ['icon'=>'bi-arrow-repeat',          'label'=>'Assignment', 'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678'];
    if (str_contains($m,'completed')||str_contains($m,'returned'))
        return ['icon'=>'bi-flag-fill',             'label'=>'Completed',  'bg'=>'#e1f5ee','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132'];
    if (str_contains($m,'reminder')||str_contains($m,'upcoming'))
        return ['icon'=>'bi-alarm-fill',            'label'=>'Reminder',   'bg'=>'#faeeda','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404'];
    if (str_contains($m,'overdue'))
        return ['icon'=>'bi-exclamation-triangle-fill','label'=>'Overdue', 'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029'];

    return ['icon'=>'bi-bell-fill','label'=>'Notice','bg'=>'#f5f0f0','color'=>'#666','badgebg'=>'#e2e3e5','badgecolor'=>'#41464b'];
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
function is_today(string $dt): bool     { return date('Y-m-d', strtotime($dt)) === date('Y-m-d'); }
function is_yesterday(string $dt): bool { return date('Y-m-d', strtotime($dt)) === date('Y-m-d', strtotime('-1 day')); }

/* ── Group by date ── */
$grouped = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
foreach ($notifications as $n) {
    if      (is_today($n['created_at']))     $grouped['Today'][]     = $n;
    elseif  (is_yesterday($n['created_at'])) $grouped['Yesterday'][] = $n;
    else                                     $grouped['Earlier'][]   = $n;
}

$totalCount = count($notifications);
$unreadAll  = count(array_filter($notifications, fn($n) => !$n['is_read']));
$todayCount = count($grouped['Today']);
$alertCount = count(array_filter($notifications, fn($n) => preg_match('/rejected|cancelled/i', $n['message'])));

/* ── Current user info ── */
$cuStmt = $pdo->prepare("SELECT username FROM users WHERE user_id=?");
$cuStmt->execute([$userId]);
$me = $cuStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifications – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
:root{
    --maroon:#800000;--maroon-dark:#5c0000;--maroon-light:#fdf5f5;--maroon-mid:#f0e5e5;
    --radius:14px;--shadow:0 2px 16px rgba(128,0,0,.07);
}
body{background:#f5f0f0;font-family:'Segoe UI',system-ui,sans-serif;color:#2a2a2a}

/* ── Sidebar ── */
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

/* ── Topbar ── */
.topbar{background:#fff;border-bottom:1px solid #e8dede;padding:.7rem 1.5rem;margin-left:240px;position:sticky;top:0;z-index:99;display:flex;align-items:center;justify-content:space-between}
.topbar-title{font-weight:700;font-size:1rem;color:var(--maroon)}
.user-av-sm{width:32px;height:32px;border-radius:50%;background:var(--maroon);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0}

/* ── Main ── */
.main-content{margin-left:240px;padding:1.5rem}

/* ── Stat Pills ── */
.stat-pill{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #f0e5e5;border-radius:10px;padding:.6rem 1rem;box-shadow:0 1px 4px rgba(128,0,0,.05)}
.stat-pill-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.stat-pill-val{font-size:1.1rem;font-weight:700;color:#2d2d2d;line-height:1}
.stat-pill-lbl{font-size:.68rem;color:#aaa;text-transform:uppercase;letter-spacing:.04em}

/* ── Section card ── */
.section-card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.25rem}
.section-header{padding:.85rem 1.25rem;border-bottom:1px solid var(--maroon-mid);font-weight:700;font-size:.88rem;color:var(--maroon);display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap}

/* ── Notification cards ── */
.date-label{font-size:.7rem;font-weight:700;color:#aaa;letter-spacing:.07em;text-transform:uppercase;padding:.75rem 1.25rem .4rem;background:#fdf8f8;border-bottom:1px solid #f5eeee}
.notif-card{display:flex;align-items:flex-start;gap:13px;padding:.9rem 1.25rem;border-bottom:1px solid #fdf5f5;cursor:pointer;transition:background .1s;position:relative}
.notif-card:last-child{border-bottom:none}
.notif-card:hover{background:#fdf8f8}
.notif-card.unread{border-left:3px solid var(--maroon);padding-left:calc(1.25rem - 2px);background:#fffcfc}
.notif-icon{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.notif-body{flex:1;min-width:0}
.notif-message{font-size:.84rem;color:#333;line-height:1.6;white-space:pre-line;
    display:-webkit-box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.notif-footer{display:flex;align-items:center;justify-content:space-between;margin-top:6px;flex-wrap:wrap;gap:4px}
.notif-time{font-size:.72rem;color:#bbb;display:flex;align-items:center;gap:4px}
.type-badge{font-size:.68rem;font-weight:600;padding:2px 9px;border-radius:10px}
.unread-dot{width:8px;height:8px;border-radius:50%;background:var(--maroon);flex-shrink:0;margin-top:7px}
.notif-actions{display:none;gap:4px;position:absolute;top:.9rem;right:1rem;pointer-events:none}
.notif-card:hover .notif-actions{display:flex;pointer-events:all}
.notif-action-btn{width:26px;height:26px;border-radius:6px;border:1px solid #e8dede;background:#fff;color:#888;font-size:.72rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .12s}
.notif-action-btn:hover{background:var(--maroon-light);border-color:var(--maroon);color:var(--maroon)}
.notif-action-btn.del:hover{background:#fdecea;border-color:#e24b4a;color:#e24b4a}

/* ── Empty state ── */
.empty-state{text-align:center;padding:3.5rem 1rem;color:#ccc}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.3}
.empty-state p{font-size:.85rem}

/* ── Modal ── */
.modal-header-maroon{background:linear-gradient(135deg,#800000,#6b0000);color:#fff}
.modal-header-maroon .btn-close{filter:invert(1)}

/* ── Detail row cards ── */
.detail-row{display:flex;align-items:flex-start;gap:10px;border-radius:12px;padding:.65rem .9rem;border:1px solid transparent}
.detail-row-icon{width:30px;height:30px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.88rem;margin-top:1px}
.detail-row-label{font-size:.61rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-bottom:2px}
.detail-row-value{font-size:.87rem;font-weight:600;color:#1e293b;line-height:1.5}

/* ── Mobile Responsive ── */
.hamburger-btn {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    color: #800000;
    font-size: 1.2rem;
    line-height: 1;
    margin-right: .5rem;
}
.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 99;
}
.sidebar-overlay.open { display: block; }

@media (max-width: 768px) {
    .hamburger-btn { display: flex; align-items: center; }
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        z-index: 200;
    }
    .sidebar.open { transform: translateX(0); }
    .topbar { margin-left: 0; }
    .main-content { margin-left: 0; padding: 1rem; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .section-header { flex-wrap: wrap; gap: .5rem; }
    .modal-dialog { margin: auto 0 0; max-width: 100%; }
    .modal-content { border-radius: 16px 16px 0 0 !important; }
    .topbar-user > div > div:last-child { display: none; }
    .row.g-3 > .col-6 { flex: 0 0 50%; max-width: 50%; }
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ── Sidebar ── -->
<div class="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="../image/Csu.png" alt="Logo"></div>
    <div class="sidebar-brand-text">CSU Vehicle System<span>Requestor Panel</span></div>
  </div>
  <nav class="nav flex-column mt-2">
    <div class="nav-section-label">Main</div>
    <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <div class="nav-section-label">Requests</div>
    <a class="nav-link" href="new_request.php"><i class="bi bi-plus-circle"></i> New Trip Request</a>
    <a class="nav-link" href="my_trip.php"><i class="bi bi-map"></i> My Trips</a>
    <div class="nav-section-label">Notifications</div>
    <a class="nav-link active" href="notification_requestor.php"><i class="bi bi-bell"></i> Notifications
      <?php if($unreadAll > 0): ?>
      <span class="notif-badge-pill"><?= $unreadAll > 99 ? '99+' : $unreadAll ?></span>
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
  <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Menu">
    <i class="bi bi-list"></i>
  </button>
  <div class="topbar-title"><i class="bi bi-bell me-2"></i>Notifications</div>
  <div class="d-flex align-items-center gap-2">
    <div class="user-av-sm"><?= strtoupper(substr($me['username'] ?? '?', 0, 1)) ?></div>
    <div>
      <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($me['username'] ?? '') ?></div>
      <div style="font-size:.72rem;color:var(--maroon)">Requestor</div>
    </div>
  </div>
</div>

<div class="main-content">

  <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
      <h5 class="fw-bold mb-0" style="color:var(--maroon)">Notifications</h5>
      <div class="text-muted small">
        <?= date('l, F j, Y') ?> &nbsp;·&nbsp;
        <span id="live-time"><?= date('g:i A') ?></span> PHT
      </div>
    </div>
  </div>

  <!-- ── Stats ── -->
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

  <!-- ── List ── -->
  <div class="section-card">
    <div class="section-header">
      <span><i class="bi bi-bell me-2"></i>All Notifications</span>
      <div class="d-flex gap-2 flex-wrap">
        <?php if($unreadAll > 0): ?>
        <button onclick="markAllRead()" class="btn btn-sm"
          style="font-size:.75rem;color:var(--maroon);border:1px solid #e8cece;background:#fff;border-radius:8px;padding:4px 12px">
          <i class="bi bi-check2-all me-1"></i>Mark all read
        </button>
        <?php endif; ?>
        <?php if($totalCount > 0): ?>
       
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
        $cfg      = detect_type($n['message']);
        $unread   = !$n['is_read'];
        preg_match('/\[ref:(\d+)\]/', $n['message'], $rm);
        $refId    = $rm[1] ?? '';
        $cleanMsg = trim(preg_replace('/\s*\[ref:\d+\]/', '', $n['message']));
        $lines    = array_filter(array_map('trim', explode("\n", $cleanMsg)), fn($l) => $l !== '');
        $lines    = array_values($lines);
      $newKeywords = ['SUBMITTED','APPROVED','REJECTED','CANCELLED','RESCHEDULED','ASSIGNMENT UPDATED','COMPLETED','REMINDER'];
$previewLines = in_array(strtoupper($lines[0] ?? ''), $newKeywords)
    ? array_slice($lines, 1, 2)
    : array_slice($lines, 0, 2);
        $preview  = implode(' · ', $previewLines);
    ?>
    <div class="notif-card <?= $unread ? 'unread' : '' ?>"
         id="notif-<?= $n['id'] ?>"
         data-id="<?= $n['id'] ?>"
         data-unread="<?= $unread ? '1' : '0' ?>"
         data-ref="<?= htmlspecialchars($refId) ?>"
         data-msg="<?= htmlspecialchars(str_replace(["\r\n", "\r", "\n"], "\\n", $cleanMsg), ENT_QUOTES) ?>"
         data-label="<?= htmlspecialchars($cfg['label'], ENT_QUOTES) ?>"
         data-fulltime="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at']))) ?>">

      <div class="notif-icon" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
        <i class="bi <?= $cfg['icon'] ?>"></i>
      </div>

      <div class="notif-body">
        <div style="font-size:.8rem;font-weight:700;color:<?= $cfg['color'] ?>;margin-bottom:2px">
          <?= htmlspecialchars($cfg['label']) ?>
        </div>
        <div class="notif-message"><?= htmlspecialchars($preview) ?></div>
        <div class="notif-footer">
          <span class="notif-time">
            <i class="bi bi-clock" style="font-size:.65rem"></i>
            <?= time_ago($n['created_at']) ?>
          </span>
          <span class="type-badge" style="background:<?= $cfg['badgebg'] ?>;color:<?= $cfg['badgecolor'] ?>">
            <?= htmlspecialchars($cfg['label']) ?>
          </span>
        </div>
      </div>

      <?php if ($unread): ?>
      <div class="unread-dot"></div>
      <?php endif; ?>

      <div class="notif-actions">
        <?php if($unread): ?>
        <button class="notif-action-btn" onclick="markRead(<?= $n['id'] ?>, this)" title="Mark as read">
          <i class="bi bi-check2"></i>
        </button>
        <?php endif; ?>
        
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

</div><!-- /.main-content -->


<!-- ══════════════════════════════════════
     NOTIFICATION DETAIL MODAL
══════════════════════════════════════ -->
<div class="modal fade" id="notifDetailModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:480px">
<div class="modal-content" style="border-radius:20px;overflow:hidden;border:none;box-shadow:0 20px 60px rgba(0,0,0,.22)">

  <!-- Dynamic coloured header -->
  <div id="modal-hdr" style="padding:1.2rem 1.4rem 1rem;color:#fff;position:relative;overflow:hidden">
    <div style="position:absolute;right:-24px;top:-24px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.08);pointer-events:none"></div>
    <div style="position:absolute;right:30px;bottom:-40px;width:70px;height:70px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none"></div>
    <div style="display:flex;align-items:center;gap:13px;position:relative">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.15)">
        <i class="bi" id="modal-icon" style="font-size:1.2rem"></i>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:1rem;line-height:1.25" id="modal-label">Notification</div>
        <div style="font-size:.72rem;opacity:.72;margin-top:3px;display:flex;align-items:center;gap:5px">
          <i class="bi bi-clock" style="font-size:.65rem"></i>
          <span id="modal-time">—</span>
        </div>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1);opacity:.75;position:relative;z-index:1"></button>
    </div>
  </div>

  <!-- Body -->
  <div class="modal-body" style="padding:1.1rem 1.3rem .9rem;background:#fff">

    <!-- Status badge -->
    <div style="margin-bottom:1rem">
      <span id="modal-badge"
            style="display:inline-flex;align-items:center;gap:6px;font-size:.74rem;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:.02em">
        <i class="bi" id="modal-badge-icon" style="font-size:.78rem"></i>
        <span id="modal-badge-text"></span>
      </span>
    </div>

    <!-- Detail rows rendered by JS -->
    <div id="modal-rows" style="display:flex;flex-direction:column;gap:.5rem"></div>

    <!-- CTA -->
    <div id="modal-cta" style="display:none;margin-top:1rem">
      <a href="#" id="modal-cta-link"
         style="display:flex;align-items:center;justify-content:center;gap:8px;
                background:linear-gradient(135deg,#800000,#5c0000);color:#fff;
                border-radius:12px;padding:.7rem 1rem;font-size:.84rem;font-weight:700;
                text-decoration:none;box-shadow:0 4px 14px rgba(128,0,0,.28);
                transition:transform .15s,box-shadow .15s"
         onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 6px 18px rgba(128,0,0,.35)'"
         onmouseout="this.style.transform='';this.style.boxShadow='0 4px 14px rgba(128,0,0,.28)'">
        <i class="bi bi-map-fill" style="font-size:.9rem"></i> View My Trip Details
      </a>
    </div>
  </div>

  <!-- Footer -->
  <div style="background:#f8f9fb;border-top:1px solid #eef0f3;padding:.85rem 1.3rem;display:flex;justify-content:flex-end">
    <button type="button" class="btn btn-secondary btn-sm px-4"
            style="border-radius:20px;font-size:.83rem" data-bs-dismiss="modal">Close</button>
  </div>

</div>
</div>
</div>

<!-- ══ CLEAR ALL MODAL ══ -->
<div class="modal fade" id="clearAllModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content" style="border-radius:16px;overflow:hidden">
  <div class="modal-header modal-header-maroon">
    <h5 class="modal-title" style="font-size:.9rem"><i class="bi bi-trash3 me-2"></i>Clear All Notifications</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body" style="font-size:.85rem;color:#555">
    Delete <strong>all notifications</strong>? This cannot be undone.
  </div>
  <div class="modal-footer border-0 pt-0 gap-2">
    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
    <button type="button" class="btn btn-danger btn-sm fw-semibold" onclick="confirmClearAll()">
      <i class="bi bi-trash3 me-1"></i>Yes, Clear All
    </button>
  </div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
    document.body.style.overflow =
        document.querySelector('.sidebar').classList.contains('open') ? 'hidden' : '';
}
document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) toggleSidebar();
    });
});
/* ══════════════════════════════════
   TYPE CONFIG (header colour + badge colours)
══════════════════════════════════ */
const TC = {
  'Submitted'  :{ icon:'bi-file-earmark-plus-fill', hdr:'#1565c0', badge:{bg:'#dbeafe',c:'#1e40af'} },
  'Approved'   :{ icon:'bi-check-circle-fill',      hdr:'#145a32', badge:{bg:'#dcfce7',c:'#166534'} },
  'Rejected'   :{ icon:'bi-x-circle-fill',          hdr:'#800000', badge:{bg:'#fee2e2',c:'#991b1b'} },
  'Cancelled'  :{ icon:'bi-slash-circle-fill',      hdr:'#7a4f00', badge:{bg:'#fef3c7',c:'#92400e'} },
  'Rescheduled':{ icon:'bi-calendar2-event-fill',   hdr:'#854f0b', badge:{bg:'#fef9c3',c:'#713f12'} },
  'Assignment' :{ icon:'bi-arrow-repeat',           hdr:'#1e3a5f', badge:{bg:'#dbeafe',c:'#1e40af'} },
  'Completed'  :{ icon:'bi-flag-fill',              hdr:'#0f6e56', badge:{bg:'#dcfce7',c:'#166534'} },
  'Reminder'   :{ icon:'bi-alarm-fill',             hdr:'#854f0b', badge:{bg:'#fef3c7',c:'#92400e'} },
  'Overdue'    :{ icon:'bi-exclamation-triangle-fill',hdr:'#800000',badge:{bg:'#fee2e2',c:'#991b1b'} },
  'Notice'     :{ icon:'bi-bell-fill',              hdr:'#374151', badge:{bg:'#f3f4f6',c:'#4b5563'} }
};

const FRIENDLY = {
  'Submitted'  :'Trip Request Submitted',
  'Approved'   :'Trip Approved! 🎉',
  'Rejected'   :'Trip Rejected',
  'Cancelled'  :'Trip Cancelled',
  'Rescheduled':'Trip Rescheduled',
  'Assignment' :'Assignment Updated',
  'Completed'  :'Trip Completed ✓',
  'Reminder'   :'Upcoming Trip Reminder',
  'Overdue'    :'Trip Overdue',
  'Notice'     :'Notification'
};

/* ══════════════════════════════════
   ROW STYLE PALETTE
   key = canonical field label (lowercase for matching)
══════════════════════════════════ */
const RS = {
  'trip'           :{ icon:'bi-ticket-perforated-fill', bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'trip #'         :{ icon:'bi-ticket-perforated-fill', bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'destination'    :{ icon:'bi-geo-alt-fill',           bg:'#fff7ed', bdr:'#fed7aa', ic:'#c2410c' },
  'date'           :{ icon:'bi-calendar-event-fill',    bg:'#eff6ff', bdr:'#bfdbfe', ic:'#1d4ed8' },
  'start date'     :{ icon:'bi-calendar-event-fill',    bg:'#eff6ff', bdr:'#bfdbfe', ic:'#1d4ed8' },
  'end date'       :{ icon:'bi-calendar-check-fill',    bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'departure time' :{ icon:'bi-clock-fill',             bg:'#eff6ff', bdr:'#bfdbfe', ic:'#1d4ed8' },
  'return time'    :{ icon:'bi-clock-history',          bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'time'           :{ icon:'bi-clock-fill',             bg:'#eff6ff', bdr:'#bfdbfe', ic:'#1d4ed8' },
  'driver'         :{ icon:'bi-person-badge-fill',      bg:'#faf5ff', bdr:'#e9d5ff', ic:'#7c3aed' },
  'new driver'     :{ icon:'bi-person-badge-fill',      bg:'#faf5ff', bdr:'#e9d5ff', ic:'#7c3aed' },
  'previous driver':{ icon:'bi-person-dash-fill',       bg:'#fef2f2', bdr:'#fecaca', ic:'#dc2626' },
  'vehicle'        :{ icon:'bi-truck-front-fill',       bg:'#eff6ff', bdr:'#bfdbfe', ic:'#1d4ed8' },
  'new vehicle'    :{ icon:'bi-truck-front-fill',       bg:'#eff6ff', bdr:'#bfdbfe', ic:'#1d4ed8' },
  'previous vehicle':{ icon:'bi-truck-front-fill',      bg:'#fef2f2', bdr:'#fecaca', ic:'#dc2626' },
  'plate no'       :{ icon:'bi-card-text',              bg:'#eff6ff', bdr:'#bfdbfe', ic:'#1d4ed8' },
  'status'         :{ icon:'bi-info-circle-fill',       bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'reason'         :{ icon:'bi-chat-left-text-fill',    bg:'#fef2f2', bdr:'#fecaca', ic:'#dc2626' },
  'rejected by'    :{ icon:'bi-person-x-fill',          bg:'#fef2f2', bdr:'#fecaca', ic:'#dc2626' },
  'cancelled by'   :{ icon:'bi-person-x-fill',          bg:'#fef2f2', bdr:'#fecaca', ic:'#dc2626' },
  'old date'            :{ icon:'bi-calendar-minus-fill',    bg:'#fefce8', bdr:'#fde68a', ic:'#b45309' },
  'new date'            :{ icon:'bi-calendar-check-fill',    bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'old departure time'  :{ icon:'bi-clock-history',          bg:'#fefce8', bdr:'#fde68a', ic:'#b45309' },
  'old return time'     :{ icon:'bi-clock-history',          bg:'#fefce8', bdr:'#fde68a', ic:'#b45309' },
  'new departure time'  :{ icon:'bi-clock-fill',             bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'new return time'     :{ icon:'bi-clock-fill',             bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'returned at'    :{ icon:'bi-flag-fill',              bg:'#f0fdf4', bdr:'#bbf7d0', ic:'#15803d' },
  'note'           :{ icon:'bi-sticky-fill',            bg:'#fefce8', bdr:'#fde68a', ic:'#b45309' },
  'default'        :{ icon:'bi-dot',                   bg:'#f8fafc', bdr:'#e2e8f0', ic:'#64748b' }
};

/* ══════════════════════════════════
   LIVE CLOCK
══════════════════════════════════ */
(function tick(){
  const now=new Date(),h=now.getHours(),
        m=String(now.getMinutes()).padStart(2,'0'),
        s=String(now.getSeconds()).padStart(2,'0'),
        ap=h>=12?'PM':'AM',hh=h%12||12;
  const el=document.getElementById('live-time');
  if(el) el.textContent=`${hh}:${m}:${s} ${ap}`;
  setTimeout(tick,1000);
})();

/* ══════════════════════════════════
   HELPERS
══════════════════════════════════ */
function getRowStyle(key) {
  const k = key.trim().toLowerCase();
  return RS[k] || RS['default'];
}

function makeRow(key, value) {
  if (!value || value.toString().trim() === '' || value.toString().trim() === '—') return '';
  const s = getRowStyle(key);
  return `
  <div style="display:flex;align-items:flex-start;gap:10px;background:${s.bg};
              border:1px solid ${s.bdr};border-radius:12px;padding:.65rem .9rem">
    <div style="width:30px;height:30px;border-radius:9px;background:${s.bdr};flex-shrink:0;
                display:flex;align-items:center;justify-content:center;
                color:${s.ic};font-size:.88rem;margin-top:1px">
      <i class="bi ${s.icon}"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:.61rem;color:#94a3b8;text-transform:uppercase;
                  letter-spacing:.08em;font-weight:700;margin-bottom:2px">${escHtml(key)}</div>
      <div style="font-size:.87rem;font-weight:600;color:#1e293b;line-height:1.5">${escHtml(value)}</div>
    </div>
  </div>`;
}

function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(s) {
  if (!s || s.trim() === '') return s;
  // already formatted like "Jan 01, 2025" — just return
  if (/[a-zA-Z]/.test(s)) return s;
  const d = new Date(s + 'T00:00:00');
  return isNaN(d) ? s : d.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'});
}

function fmtTime(t) {
  if (!t || t.trim() === '') return t;
  // already has AM/PM
  if (/[APap][Mm]/.test(t)) return t;
  const p = t.split(':');
  let h = parseInt(p[0]), m = (p[1]||'00').substring(0,2);
  const ap = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return h + ':' + m + ' ' + ap;
}

/* ══════════════════════════════════
   DETECT LABEL
══════════════════════════════════ */
const NEW_KEYWORDS = new Set(['SUBMITTED','APPROVED','REJECTED','CANCELLED','RESCHEDULED','ASSIGNMENT UPDATED','COMPLETED','REMINDER']);

function detectLabel(msg) {
  const first = (msg.split('\n')[0] || '').trim().toUpperCase();
  const labelMap = {
    'SUBMITTED':'Submitted','APPROVED':'Approved','REJECTED':'Rejected',
    'CANCELLED':'Cancelled','RESCHEDULED':'Rescheduled',
    'ASSIGNMENT UPDATED':'Assignment','COMPLETED':'Completed',
    'REMINDER':'Reminder'
};
  if (labelMap[first]) return labelMap[first];

  const m = msg.toLowerCase();
  // Assignment / driver / vehicle update — check BEFORE generic terms
  if (m.includes('has been updated') || m.includes('driver') && m.includes('vehicle') && m.includes('updated'))
    return 'Assignment';
  if (m.includes('submitted')||m.includes('waiting for admin')) return 'Submitted';
  if (m.includes('approved'))    return 'Approved';
  if (m.includes('rejected'))    return 'Rejected';
  if (m.includes('cancelled'))   return 'Cancelled';
  if (m.includes('rescheduled')) return 'Rescheduled';
  if (m.includes('completed')||m.includes('returned')) return 'Completed';
  if (m.includes('reminder')||m.includes('upcoming'))  return 'Reminder';
  if (m.includes('overdue'))     return 'Overdue';
  return 'Notice';
}

/* ══════════════════════════════════
   PARSE NEW FORMAT
   First line = TYPE keyword
   Subsequent lines = "Key: Value"
══════════════════════════════════ */
function parseNewFormat(lines) {
  const pairs = [];
  for (let i = 1; i < lines.length; i++) {
    const ci = lines[i].indexOf(':');
    if (ci > 0) {
      const k = lines[i].slice(0, ci).trim();
      const v = lines[i].slice(ci + 1).trim();
      pairs.push({ k, v });
    } else if (pairs.length && lines[i].trim()) {
      // continuation of previous value
      pairs[pairs.length - 1].v += ' ' + lines[i].trim();
    }
  }
  return pairs;
}

/* ══════════════════════════════════
   SMART FIELD BUILDER PER TYPE
   Ensures the right fields show for each notification type
══════════════════════════════════ */
function buildRows(label, rawPairs) {
  // Build a quick lookup map (case-insensitive key)
  const map = {};
  rawPairs.forEach(p => { map[p.k.toLowerCase()] = p.v; });

  const get = (...keys) => {
    for (const k of keys) {
      const v = map[k.toLowerCase()];
      if (v && v.trim() !== '') return v;
    }
    return '';
  };

  const rows = [];

  // Helper to push a row only if value exists
  const add = (key, value) => {
    const v = value || get(key);
    if (v && v.trim() !== '') rows.push({ k: key, v });
  };

  // Trip # always show if available
  const tripNum = get('trip', 'trip #', 'trip id', 'request');
  if (tripNum) rows.push({ k: 'Trip #', v: tripNum });

  switch (label) {

    /* ── APPROVED ──────────────────────────────────────────────
         PHP keys: start date, end date, departure time,
                   return time, driver, vehicle
    ── */
    case 'Approved': {
      add('Destination', get('destination'));
      const appSD = get('start date', 'date');
      const appED = get('end date');
      const appDT = get('departure time', 'time');
      const appRT = get('return time');
      if (appSD) rows.push({ k:'Start Date',      v: fmtDate(appSD) });
      if (appED) rows.push({ k:'End Date',         v: fmtDate(appED) });
      if (appDT) rows.push({ k:'Departure Time',   v: fmtTime(appDT) });
      if (appRT) rows.push({ k:'Return Time',      v: fmtTime(appRT) });
      const appDrv = get('driver');
      const appVeh = get('vehicle');
      if (appDrv) rows.push({ k:'Driver',  v: appDrv });
      if (appVeh) rows.push({ k:'Vehicle', v: appVeh });
      break;
    }

    /* ── REJECTED ──────────────────────────────────────────────
         PHP keys: destination, rejected by, reason
    ── */
    case 'Rejected': {
      add('Destination', get('destination'));
      const rejBy  = get('rejected by');
      const rejRea = get('reason');
      if (rejBy)  rows.push({ k:'Rejected By', v: rejBy });
      if (rejRea) rows.push({ k:'Reason',      v: rejRea });
      break;
    }

    /* ── CANCELLED ─────────────────────────────────────────────
         PHP keys: destination, cancelled by, reason
    ── */
    case 'Cancelled': {
      add('Destination', get('destination'));
      const canBy  = get('cancelled by');
      const canRea = get('reason');
      if (canBy)  rows.push({ k:'Cancelled By', v: canBy });
      if (canRea) rows.push({ k:'Reason',       v: canRea });
      break;
    }

    /* ── RESCHEDULED ───────────────────────────────────────────
         PHP keys: old date, old departure time, old return time,
                   new date, new departure time, new return time, status
    ── */
    case 'Rescheduled': {
      add('Destination', get('destination'));
      const rOldDate = get('old date');
      const rOldDep  = get('old departure time');
      const rOldRet  = get('old return time');
      const rNewDate = get('new date');
      const rNewDep  = get('new departure time');
      const rNewRet  = get('new return time');
      const rStatus  = get('status');
      if (rOldDate) rows.push({ k:'Old Date',           v: fmtDate(rOldDate) });
      if (rOldDep)  rows.push({ k:'Old Departure Time', v: fmtTime(rOldDep)  });
      if (rOldRet)  rows.push({ k:'Old Return Time',    v: fmtTime(rOldRet)  });
      if (rNewDate) rows.push({ k:'New Date',           v: fmtDate(rNewDate) });
      if (rNewDep)  rows.push({ k:'New Departure Time', v: fmtTime(rNewDep)  });
      if (rNewRet)  rows.push({ k:'New Return Time',    v: fmtTime(rNewRet)  });
      if (rStatus)  rows.push({ k:'Status',             v: rStatus           });
      break;
    }

    /* ── ASSIGNMENT UPDATED ────────────────────────────────────
         PHP keys: destination, new driver, new vehicle
    ── */
    case 'Assignment': {
      add('Destination', get('destination'));
      const aNDrv = get('new driver');
      const aNVeh = get('new vehicle');
      if (aNDrv) rows.push({ k:'New Driver',  v: aNDrv });
      if (aNVeh) rows.push({ k:'New Vehicle', v: aNVeh });
      break;
    }

    /* ── SUBMITTED ─────────────────────────────────────────────
         PHP keys: destination, date, departure time, return time, status
    ── */
    case 'Submitted': {
      add('Destination', get('destination'));
      const subD  = get('date');
      const subDT = get('departure time', 'time');
      const subRT = get('return time');
      const subSt = get('status');
      if (subD)  rows.push({ k:'Date',           v: fmtDate(subD)  });
      if (subDT) rows.push({ k:'Departure Time', v: fmtTime(subDT) });
      if (subRT) rows.push({ k:'Return Time',    v: fmtTime(subRT) });
      if (subSt) rows.push({ k:'Status',         v: subSt          });
      break;
    }

    /* ── COMPLETED ─────────────────────────────────────────────
         PHP keys: destination, returned at
    ── */
    case 'Completed': {
      add('Destination', get('destination'));
      const compRet = get('returned at');
      if (compRet) rows.push({ k:'Returned At', v: compRet });
      break;
    }
/* ── REMINDER ──────────────────────────────────────────────
     PHP keys: trip #, destination, date, departure time,
               return time, driver, vehicle, note
── */
case 'Reminder': {
  add('Destination', get('destination'));
  const remDate = get('date', 'start date');
  const remDT   = get('departure time', 'time');
  const remRT   = get('return time');
  const remDrv  = get('driver');
  const remVeh  = get('vehicle');
  const remNote = get('note');
  if (remDate) rows.push({ k:'Date',           v: fmtDate(remDate) });
  if (remDT)   rows.push({ k:'Departure Time', v: fmtTime(remDT)   });
  if (remRT)   rows.push({ k:'Return Time',    v: fmtTime(remRT)   });
  if (remDrv)  rows.push({ k:'Driver',         v: remDrv           });
  if (remVeh)  rows.push({ k:'Vehicle',        v: remVeh           });
  if (remNote) rows.push({ k:'Note',           v: remNote          });
  break;
}
    /* ── DEFAULT: show all parsed fields ───────────── */
    default:
      rawPairs.forEach(p => {
        // skip the type keyword line and trip# already added
        if (NEW_KEYWORDS.has(p.k.toUpperCase())) return;
        if (['trip','trip #','trip id'].includes(p.k.toLowerCase()) && tripNum) return;
        rows.push({ k: p.k, v: p.v });
      });
  }

  return rows;
}

/* ══════════════════════════════════
   OLD FORMAT PARSER
   Handles sentence-style messages from the DB.
   Examples:
   "Your trip request #0064 to SDFSDF on 2026-05-09 (11:22) ... has been approved. Driver: John. Vehicle: Toyota (ABC 123)."
   "Your trip #0064 has been rescheduled from 2026-05-01 (08:00) to 2026-05-09 (11:22)."
   "The assignment for your trip #0063 to cv has been updated. Driver: sdf Vehicle: Toyota (ABC 123)"
   "Your trip #0071 has been rejected. Reason: asdasd. Rejected by: admin."
   "Your trip #0064 has been cancelled. Reason: test. Cancelled by: admin."
══════════════════════════════════ */
function parseOldFormat(msg, label) {
  const rows = [];

  /* ── Trip # ── */
  const tripM = msg.match(/#(\d+)/);
  if (tripM) rows.push({ k:'Trip #', v:'#' + tripM[1] });

  /* ── Destination ── multiple patterns ── */
  const destM = msg.match(/\bto\s+([A-Za-z0-9 ,\-]+?)\s+(?:on|from|has been)/i);
  if (destM) {
    const d = destM[1].trim();
    if (d && d.length > 0) rows.push({ k:'Destination', v: d });
  }

  /* ── Extract ALL dates (YYYY-MM-DD) and times (HH:MM inside parens) ── */
  const allDates = msg.match(/\d{4}-\d{2}-\d{2}/g) || [];
  // Times: either (HH:MM) pattern or "HH:MM" standalone
  const allTimesRaw = [];
  const timeParenRe = /\((\d{2}:\d{2}(?::\d{2})?)\)/g;
  let tm;
  while ((tm = timeParenRe.exec(msg)) !== null) allTimesRaw.push(tm[1]);

  /* ── Per-type field extraction ── */
  switch (label) {

    case 'Approved':
    case 'Submitted': {
      if (allDates[0]) rows.push({ k:'Start Date',      v: fmtDate(allDates[0]) });
      if (allDates[1]) rows.push({ k:'End Date',         v: fmtDate(allDates[1]) });
      if (allTimesRaw[0]) rows.push({ k:'Departure Time', v: fmtTime(allTimesRaw[0]) });
      if (allTimesRaw[1]) rows.push({ k:'Return Time',    v: fmtTime(allTimesRaw[1]) });
      // Driver — stop at Vehicle or end-of-sentence
      const drvA = msg.match(/[Dd]river[:\s]+([^.V\n]+?)(?:\s*[Vv]ehicle|[Pp]late|\.|$)/);
      if (drvA) { const d=drvA[1].trim(); if(d&&d!=='—'&&d!=='') rows.push({k:'Driver',v:d}); }
      // Vehicle — grab plate in parens too
      const vehA = msg.match(/[Vv]ehicle[:\s]+([^.\n]+?)(?:\.|$)/);
      if (vehA) { const v=vehA[1].trim(); if(v&&v!=='()'&&!/^\s*\(\s*\)\s*$/.test(v)) rows.push({k:'Vehicle',v:v}); }
      break;
    }

    case 'Rejected': {
      if (allDates[0]) rows.push({ k:'Date', v: fmtDate(allDates[0]) });
      // "Rejected by: admin" or "by admin"
      const rejBy = msg.match(/[Rr]ejected\s+by[:\s]+([^.\n]+)/i);
      if (rejBy) rows.push({ k:'Rejected By', v: rejBy[1].trim() });
      // Reason
      const rejRea = msg.match(/[Rr]eason[:\s]+([^.\n]+)/i);
      if (rejRea) rows.push({ k:'Reason', v: rejRea[1].trim() });
      break;
    }

    case 'Cancelled': {
      if (allDates[0]) rows.push({ k:'Date', v: fmtDate(allDates[0]) });
      const canBy = msg.match(/[Cc]ancell?ed\s+by[:\s]+([^.\n]+)/i);
      if (canBy) rows.push({ k:'Cancelled By', v: canBy[1].trim() });
      const canRea = msg.match(/[Rr]eason[:\s]+([^.\n]+)/i);
      if (canRea) rows.push({ k:'Reason', v: canRea[1].trim() });
      break;
    }

    case 'Rescheduled': {
      // "from 2026-05-01 (08:00) to 2026-05-09 (11:22)"
      if (allDates[0]) rows.push({ k:'Old Date', v: fmtDate(allDates[0]) });
      if (allDates[1]) rows.push({ k:'New Date', v: fmtDate(allDates[1]) });
      if (allTimesRaw[0]) rows.push({ k:'Old Time', v: fmtTime(allTimesRaw[0]) });
      if (allTimesRaw[1]) rows.push({ k:'New Time', v: fmtTime(allTimesRaw[1]) });
      const resNote = msg.match(/[Rr]eason[:\s]+([^.\n]+)/i);
      if (resNote) rows.push({ k:'Note', v: resNote[1].trim() });
      break;
    }
case 'Reminder': {
  if (allDates[0]) rows.push({ k:'Date',           v: fmtDate(allDates[0]) });
  if (allTimesRaw[0]) rows.push({ k:'Departure Time', v: fmtTime(allTimesRaw[0]) });
  if (allTimesRaw[1]) rows.push({ k:'Return Time',    v: fmtTime(allTimesRaw[1]) });
  const remDrv = msg.match(/[Dd]river[:\s]+([^.\nVv]+?)(?:\s*[Vv]ehicle|\.|$)/);
  if (remDrv) { const d=remDrv[1].trim(); if(d&&d!=='—') rows.push({k:'Driver',v:d}); }
  const remVeh = msg.match(/[Vv]ehicle[:\s]+([^.\n]+?)(?:\.|$)/);
  if (remVeh) { const v=remVeh[1].trim(); if(v&&v!=='()') rows.push({k:'Vehicle',v:v}); }
  const remNote = msg.match(/[Nn]ote[:\s]+([^.\n]+)/i);
  if (remNote) rows.push({ k:'Note', v: remNote[1].trim() });
  break;
}
    case 'Assignment': {
      // "The assignment for your trip #0063 to cv has been updated. Driver: sdf Vehicle: Toyota (ABC 123)"
      // Previous driver/vehicle — look for "from X to Y" or "Previous: X"
      const prevDrv = msg.match(/[Pp]revious\s+[Dd]river[:\s]+([^.\n,]+)/i)
                   || msg.match(/[Oo]ld\s+[Dd]river[:\s]+([^.\n,]+)/i);
      if (prevDrv) rows.push({ k:'Previous Driver', v: prevDrv[1].trim() });

      // New driver — "Driver: X" (stop before Vehicle/Plate/period)
      const newDrv = msg.match(/[Dd]river[:\s]+([^.\nVv]+?)(?:\s*[Vv]ehicle|\s*[Pp]late|\.|$)/);
      if (newDrv) {
        const d = newDrv[1].trim();
        if (d && d !== '—' && d !== '') rows.push({ k:'New Driver', v: d });
      }

      const prevVeh = msg.match(/[Pp]revious\s+[Vv]ehicle[:\s]+([^.\n,]+)/i)
                   || msg.match(/[Oo]ld\s+[Vv]ehicle[:\s]+([^.\n,]+)/i);
      if (prevVeh) rows.push({ k:'Previous Vehicle', v: prevVeh[1].trim() });

      // New vehicle — "Vehicle: Toyota (ABC 123)" — stop at period or end
      const newVeh = msg.match(/[Vv]ehicle[:\s]+([^.\n]+?)(?:\.|$)/);
      if (newVeh) {
        const v = newVeh[1].trim();
        if (v && v !== '()' && !/^\s*\(\s*\)\s*$/.test(v)) rows.push({ k:'New Vehicle', v: v });
      }
      break;
    }

    case 'Completed': {
      if (allDates[0]) rows.push({ k:'Date', v: fmtDate(allDates[0]) });
      const retAt = msg.match(/[Rr]eturned?\s+at[:\s]+([^.\n]+)/i);
      if (retAt) rows.push({ k:'Returned At', v: retAt[1].trim() });
      const cDrv = msg.match(/[Dd]river[:\s]+([^.\nVv]+?)(?:\s*[Vv]ehicle|\.|$)/);
      if (cDrv) { const d=cDrv[1].trim(); if(d) rows.push({k:'Driver',v:d}); }
      const cVeh = msg.match(/[Vv]ehicle[:\s]+([^.\n]+?)(?:\.|$)/);
      if (cVeh) { const v=cVeh[1].trim(); if(v&&v!=='()') rows.push({k:'Vehicle',v:v}); }
      break;
    }

    default: {
      // Generic: show dates and any key: value pairs found
      if (allDates[0]) rows.push({ k:'Date', v: fmtDate(allDates[0]) });
      if (allTimesRaw[0]) rows.push({ k:'Time', v: fmtTime(allTimesRaw[0]) });
      const genericKV = msg.matchAll(/([A-Za-z ]{2,20})[:\s]{1,2}([^\n.]{1,80})/g);
      const skipKeys = new Set(['trip','your trip','the','a','an','for','and','that','this']);
      for (const m of genericKV) {
        const k = m[1].trim();
        const v = m[2].trim();
        if (!skipKeys.has(k.toLowerCase()) && v && v.length > 0)
          rows.push({ k, v });
      }
    }
  }

  return rows;
}

/* ══════════════════════════════════
   OPEN MODAL ON CARD CLICK
══════════════════════════════════ */
document.addEventListener('click', function(e) {
  if (e.target.closest('.notif-action-btn')) return;
  const card = e.target.closest('.notif-card');
  if (!card) return;

  const id     = card.dataset.id;
  const unread = card.dataset.unread === '1';
  const ref    = card.dataset.ref;
  // Restore \n from encoded data attribute
  const msg    = (card.dataset.msg || '').replace(/\\n/g, '\n');
  const time   = card.dataset.fulltime;

  // Mark as read
  if (unread) {
    card.classList.remove('unread');
    card.dataset.unread = '0';
    card.querySelector('.unread-dot')?.remove();
    card.querySelector('.notif-actions .notif-action-btn:not(.del)')?.remove();
    fetch('notification_requestor.php?action=mark_read', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'id='+id
    });
    updateUnreadBadge(-1);
  }

  // Detect label & config
  const label = detectLabel(msg);
  const cfg   = TC[label] || TC['Notice'];

  // ── Header ──
  document.getElementById('modal-hdr').style.background =
    `linear-gradient(135deg, ${cfg.hdr} 0%, ${cfg.hdr}bb 100%)`;
  document.getElementById('modal-icon').className  = 'bi ' + cfg.icon;
  document.getElementById('modal-label').textContent = FRIENDLY[label] || label;
  document.getElementById('modal-time').textContent  = time;

  // ── Badge ──
  const badge = document.getElementById('modal-badge');
  badge.style.background = cfg.badge.bg;
  badge.style.color      = cfg.badge.c;
  document.getElementById('modal-badge-icon').className = 'bi ' + cfg.icon;
  document.getElementById('modal-badge-text').textContent = label;

  // ── Parse message ──
  const lines   = msg.split('\n').map(l => l.trim()).filter(Boolean);
  const isNew   = NEW_KEYWORDS.has((lines[0] ?? '').toUpperCase().trim());
  let rawPairs  = isNew ? parseNewFormat(lines) : [];
  let finalRows = isNew ? buildRows(label, rawPairs) : parseOldFormat(msg, label);

  // Fallback: if nothing parsed, show all lines
  if (finalRows.length === 0) {
    finalRows = lines.map((l, i) => ({ k: i === 0 ? 'Status' : 'Detail', v: l }));
  }

  document.getElementById('modal-rows').innerHTML =
    finalRows.map(r => makeRow(r.k, r.v)).join('');

  // ── CTA button ──
  const cta = document.getElementById('modal-cta');
  if (ref) {
    cta.style.display = '';
    document.getElementById('modal-cta-link').href = 'my_trip.php?highlight=' + ref;
  } else {
    cta.style.display = 'none';
  }

  new bootstrap.Modal(document.getElementById('notifDetailModal')).show();
});

/* ══════════════════════════════════
   ACTIONS
══════════════════════════════════ */
function markRead(id, btn) {
  const card = document.getElementById('notif-'+id); if(!card) return;
  card.classList.remove('unread'); card.dataset.unread='0';
  card.querySelector('.unread-dot')?.remove();
  btn.closest('.notif-actions').querySelector('.notif-action-btn:not(.del)')?.remove();
  fetch('notification_requestor.php?action=mark_read',{method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+id});
  updateUnreadBadge(-1);
}
function markAllRead() {
  document.querySelectorAll('.notif-card.unread').forEach(c=>{
    c.classList.remove('unread'); c.dataset.unread='0'; c.querySelector('.unread-dot')?.remove();
  });
  fetch('notification_requestor.php?action=mark_all_read',{method:'POST'});
  document.querySelector('[onclick="markAllRead()"]')?.remove();
  updateUnreadBadge(0,true);
}
function deleteNotif(id) {
  const el = document.getElementById('notif-'+id); if(!el) return;
  const wasUnread = el.dataset.unread==='1';
  el.style.transition='opacity .25s,transform .25s';
  el.style.opacity='0'; el.style.transform='translateX(20px)';
  setTimeout(()=>{el.remove();cleanDateLabels();checkEmpty();},260);
  fetch('notification_requestor.php?action=delete',{method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+id});
  if(wasUnread) updateUnreadBadge(-1);
}
function clearAll(){ new bootstrap.Modal(document.getElementById('clearAllModal')).show(); }
function confirmClearAll(){
  bootstrap.Modal.getInstance(document.getElementById('clearAllModal')).hide();
  document.querySelectorAll('.notif-card').forEach(c=>c.remove());
  document.querySelectorAll('.date-label').forEach(l=>l.remove());
  checkEmpty();
  fetch('notification_requestor.php?action=clear_all',{method:'POST'});
  updateUnreadBadge(0,true);
  document.querySelector('[onclick="markAllRead()"]')?.remove();
  document.querySelector('[onclick="clearAll()"]')?.remove();
}
function cleanDateLabels(){
  document.querySelectorAll('.date-label').forEach(l=>{
    if(!l.nextElementSibling?.classList.contains('notif-card')) l.remove();
  });
}
function checkEmpty(){
  if(!document.querySelectorAll('.notif-card').length){
    const c=document.querySelector('.section-card');
    if(c&&!c.querySelector('.empty-state'))
      c.insertAdjacentHTML('beforeend',`
        <div class="empty-state">
          <i class="bi bi-bell-slash"></i>
          <p class="fw-semibold" style="color:#aaa">No notifications yet.</p>
          <p style="font-size:.78rem;color:#ccc">You're all caught up!</p>
        </div>`);
  }
}
function updateUnreadBadge(delta,reset=false){
  const pill=document.querySelector('.notif-badge-pill');
  if(reset){if(pill)pill.remove();return;}
  if(!pill)return;
  let cur=Math.max(0,(parseInt(pill.textContent)||0)+delta);
  if(cur===0)pill.remove(); else pill.textContent=cur>99?'99+':cur;
}

</script>
</body>
</html>