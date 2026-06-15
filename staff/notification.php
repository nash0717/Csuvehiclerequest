
<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireStaff();
date_default_timezone_set('Asia/Manila');

/* ── Current staff + office ── */
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
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND (office_id IS NULL OR office_id=?)")->execute([$uid, $myOfficeId]);
            echo json_encode(['success' => true]); break;
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$id, $uid]);
            echo json_encode(['success' => true]); break;
        case 'clear_all':
            $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND (office_id IS NULL OR office_id=?)")->execute([$uid, $myOfficeId]);
            echo json_encode(['success' => true]); break;
        case 'poll':
            $lastId   = (int)($_GET['last_id'] ?? 0);
            $pollStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND (office_id IS NULL OR office_id=?) AND id>? ORDER BY created_at DESC LIMIT 20");
            $pollStmt->execute([$uid, $myOfficeId, $lastId]);
            $newRows  = $pollStmt->fetchAll(PDO::FETCH_ASSOC);
            $unreadQ  = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
            $unreadQ->execute([$uid, $myOfficeId]);
            echo json_encode(['notifications' => $newRows, 'unread_count' => (int)$unreadQ->fetchColumn()]);
            break;
        case 'unread_count':
            $uq = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
            $uq->execute([$uid, $myOfficeId]);
            echo json_encode(['count' => (int)$uq->fetchColumn()]);
            break;
    }
    exit;
}

/* ── Fetch notifications ── */
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND (office_id IS NULL OR office_id=?) ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$_SESSION['user_id'], $myOfficeId]);
$notifications = $stmt->fetchAll();

/* ── Unread count ── */
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$_SESSION['user_id'], $myOfficeId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

function detect_type(string $msg, string $dbType = ''): array {
    $typeMap = [
        'submitted'          => ['icon'=>'bi-file-earmark-plus-fill',   'label'=>'Submitted',      'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678','type'=>'submitted'],
        'approved'           => ['icon'=>'bi-check-circle-fill',         'label'=>'Approved',       'bg'=>'#d1f0e0','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132','type'=>'approved'],
        'rejected'           => ['icon'=>'bi-x-circle-fill',             'label'=>'Rejected',       'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029','type'=>'rejected'],
        'cancelled'          => ['icon'=>'bi-slash-circle-fill',         'label'=>'Cancelled',      'bg'=>'#fff0d6','color'=>'#7a4f00','badgebg'=>'#fff0d6','badgecolor'=>'#7a4f00','type'=>'cancel'],
        'rescheduled'        => ['icon'=>'bi-calendar2-event-fill',      'label'=>'Rescheduled',    'bg'=>'#fff3cd','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404','type'=>'reschedule'],
        'assignment_changed' => ['icon'=>'bi-arrow-left-right',          'label'=>'Reassigned',     'bg'=>'#e8eaff','color'=>'#3730a3','badgebg'=>'#e0e7ff','badgecolor'=>'#3730a3','type'=>'assignment_change'],
        'completed'          => ['icon'=>'bi-flag-fill',                 'label'=>'Completed',      'bg'=>'#e1f5ee','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132','type'=>'completed'],
        'reminder_24h'       => ['icon'=>'bi-alarm-fill',                'label'=>'Reminder 24h',   'bg'=>'#faeeda','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404','type'=>'reminder'],
        'reminder_1h'        => ['icon'=>'bi-alarm-fill',                'label'=>'Reminder 1h',    'bg'=>'#faeeda','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404','type'=>'reminder'],
        'trip_assigned'      => ['icon'=>'bi-person-check-fill',         'label'=>'Trip Assigned',  'bg'=>'#e8eaff','color'=>'#3730a3','badgebg'=>'#e0e7ff','badgecolor'=>'#3730a3','type'=>'trip_assigned'],
        'new_trip_pending'   => ['icon'=>'bi-file-earmark-plus-fill',    'label'=>'New Request',    'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678','type'=>'new_trip_pending'],
        'assignment_removed' => ['icon'=>'bi-person-dash-fill',          'label'=>'Unassigned',     'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029','type'=>'assignment_removed'],
        'trip_approved'      => ['icon'=>'bi-check-circle-fill',         'label'=>'Approved',       'bg'=>'#d1f0e0','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132','type'=>'approved'],
        'trip_rejected'      => ['icon'=>'bi-x-circle-fill',             'label'=>'Rejected',       'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029','type'=>'rejected'],
        'trip_cancelled'     => ['icon'=>'bi-slash-circle-fill',         'label'=>'Cancelled',      'bg'=>'#fff0d6','color'=>'#7a4f00','badgebg'=>'#fff0d6','badgecolor'=>'#7a4f00','type'=>'cancel'],
        'trip_rescheduled'   => ['icon'=>'bi-calendar2-event-fill',      'label'=>'Rescheduled',    'bg'=>'#fff3cd','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404','type'=>'reschedule'],
        'trip_completed'     => ['icon'=>'bi-flag-fill',                 'label'=>'Completed',      'bg'=>'#e1f5ee','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132','type'=>'completed'],
        'new_request'        => ['icon'=>'bi-file-earmark-plus-fill',    'label'=>'New Request',    'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678','type'=>'new_request'],
        'walkin_booking'     => ['icon'=>'bi-calendar-plus-fill',        'label'=>'Walk-in',        'bg'=>'#e8f4fd','color'=>'#0c5480','badgebg'=>'#cfe2ff','badgecolor'=>'#0a3678','type'=>'new_request'],
        'request_approved'   => ['icon'=>'bi-check-circle-fill',         'label'=>'Approved',       'bg'=>'#d1f0e0','color'=>'#0f6e56','badgebg'=>'#d1e7dd','badgecolor'=>'#0f5132','type'=>'approved'],
        'request_rejected'   => ['icon'=>'bi-x-circle-fill',             'label'=>'Rejected',       'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029','type'=>'rejected'],
        'request_cancelled'  => ['icon'=>'bi-slash-circle-fill',         'label'=>'Cancelled',      'bg'=>'#fff0d6','color'=>'#7a4f00','badgebg'=>'#fff0d6','badgecolor'=>'#7a4f00','type'=>'cancel'],
        'departure_reminder' => ['icon'=>'bi-alarm-fill',                'label'=>'Reminder',       'bg'=>'#faeeda','color'=>'#854f0b','badgebg'=>'#fff3cd','badgecolor'=>'#856404','type'=>'reminder'],
        'vehicle_overdue'    => ['icon'=>'bi-exclamation-triangle-fill', 'label'=>'Overdue',        'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029','type'=>'overdue'],
        'pending_reminder'   => ['icon'=>'bi-hourglass-split',           'label'=>'Pending',        'bg'=>'#fff3cd','color'=>'#856404','badgebg'=>'#fff3cd','badgecolor'=>'#856404','type'=>'pending'],
        'conflict'           => ['icon'=>'bi-exclamation-diamond-fill',  'label'=>'Conflict',       'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029','type'=>'conflict'],
        'overdue_cancelled'  => ['icon'=>'bi-clock-history',             'label'=>'Auto-Cancelled', 'bg'=>'#fdecea','color'=>'#800000','badgebg'=>'#f8d7da','badgecolor'=>'#842029','type'=>'cancel'],
    ];
    if ($dbType && isset($typeMap[$dbType])) return $typeMap[$dbType];
    $firstLine = trim(strtok($msg, "\n"));
    $lineKeyMap = [
        'TRIP ASSIGNED'=>$typeMap['trip_assigned'],'NEW TRIP REQUEST'=>$typeMap['new_trip_pending'],
        'ASSIGNMENT REMOVED'=>$typeMap['assignment_removed'],'ASSIGNMENT UPDATED'=>$typeMap['assignment_changed'],
        'TRIP APPROVED'=>$typeMap['trip_approved'],'TRIP REJECTED'=>$typeMap['trip_rejected'],
        'TRIP CANCELLED'=>$typeMap['trip_cancelled'],'TRIP RESCHEDULED'=>$typeMap['trip_rescheduled'],
        'TRIP COMPLETED'=>$typeMap['trip_completed'],'SUBMITTED'=>$typeMap['submitted'],
        'APPROVED'=>$typeMap['approved'],'REJECTED'=>$typeMap['rejected'],
        'CANCELLED'=>$typeMap['cancelled'],'RESCHEDULED'=>$typeMap['rescheduled'],
        'COMPLETED'=>$typeMap['completed'],'REMINDER'=>$typeMap['reminder_24h'],
    ];
    if (isset($lineKeyMap[$firstLine])) return $lineKeyMap[$firstLine];
    $m = strtolower($msg);
    if (str_contains($m,'conflict detected')||str_contains($m,'schedule conflict')||str_contains($m,'overlaps with')) return $typeMap['conflict'];
    if (str_contains($m,'overdue')||str_contains($m,'not yet returned')||str_contains($m,'was due back')) return $typeMap['vehicle_overdue'];
    if (str_contains($m,'pending trip')&&str_contains($m,'awaiting approval')) return $typeMap['pending_reminder'];
    if (str_contains($m,'rescheduled')) return $typeMap['rescheduled'];
    if (str_contains($m,'reassigned')||str_contains($m,'assignment changed')||(str_contains($m,'new driver')&&str_contains($m,'new vehicle')&&str_contains($m,'updated'))) return $typeMap['assignment_changed'];
    if (str_contains($m,'rejected')) return $typeMap['rejected'];
    if (str_contains($m,'cancelled')||str_contains($m,'canceled')||str_contains($m,'auto-cancelled')) return $typeMap['cancelled'];
    if (str_contains($m,'approved')) return $typeMap['approved'];
    if (str_contains($m,'completed')||str_contains($m,'marked complete')||str_contains($m,'returned')) return $typeMap['completed'];
    if (str_contains($m,'reminder')||str_contains($m,'departs at')||str_contains($m,'departing in')||str_contains($m,'1 hour')) return $typeMap['departure_reminder'];
    if (str_contains($m,'new vehicle request')||str_contains($m,'submitted a new')||str_contains($m,'submitted')) return $typeMap['new_request'];
    if (str_contains($m,'walk-in booking')||str_contains($m,'walk-in')) return $typeMap['walkin_booking'];
    return ['icon'=>'bi-bell-fill','label'=>'Notice','bg'=>'#f5f0f0','color'=>'#666','badgebg'=>'#e2e3e5','badgecolor'=>'#41464b','type'=>'general'];
}

function time_ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'Just now';
    if ($d < 3600)   return (int)($d/60).'m ago';
    if ($d < 86400)  return (int)($d/3600).'h ago';
    if ($d < 172800) return 'Yesterday · '.date('g:i A', strtotime($dt));
    return date('M j, Y · g:i A', strtotime($dt));
}
function is_today(string $dt): bool     { return date('Y-m-d', strtotime($dt)) === date('Y-m-d'); }
function is_yesterday(string $dt): bool { return date('Y-m-d', strtotime($dt)) === date('Y-m-d', strtotime('-1 day')); }

$grouped = ['Today'=>[], 'Yesterday'=>[], 'Earlier'=>[]];
foreach ($notifications as $n) {
    if      (is_today($n['created_at']))     $grouped['Today'][]     = $n;
    elseif  (is_yesterday($n['created_at'])) $grouped['Yesterday'][] = $n;
    else                                     $grouped['Earlier'][]   = $n;
}

$totalCount = count($notifications);
$unreadAll  = count(array_filter($notifications, fn($n) => !$n['is_read']));
$todayCount = count($grouped['Today']);
$alertCount = count(array_filter($notifications, fn($n) => preg_match('/overdue|rejected|conflict/i', $n['message'])));
$maxId      = $notifications ? max(array_column($notifications, 'id')) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifications – CSU VSS Staff</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#f0ebeb;font-family:'Segoe UI',sans-serif;min-height:100vh}
:root{
    --maroon:#800000;--maroon-dark:#5a0000;--maroon-light:#fdecea;
    --maroon-mid:#f0e5e5;--bg:#f5f0f0;--border:#eedede;
    --text:#1a1a1a;--muted:#888;--radius:12px;--radius-lg:16px;
    --shadow:0 2px 16px rgba(128,0,0,.07);--shadow-lg:0 20px 60px rgba(0,0,0,.15);
}

/* ── Mobile hamburger + overlay ── */
.hamburger-btn{display:none;background:none;border:none;cursor:pointer;padding:4px;color:#800000;font-size:1.2rem;line-height:1;margin-right:.5rem}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:199}
.sidebar-overlay.open{display:block}

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
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}

/* ── Main ── */
.main-content{margin-left:240px;padding:1.5rem}

/* ── Page header ── */
.page-hdr{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:1.4rem}
.page-hdr h4{font-size:1.3rem;font-weight:800;color:var(--text)}
.page-hdr p{font-size:.78rem;color:var(--muted);margin-top:2px}
.live-badge{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1.5px solid #e0f0e8;color:#0f6e56;border-radius:20px;padding:4px 12px;font-size:.72rem;font-weight:700}
.live-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;animation:pulse-green 1.6s ease-in-out infinite}
@keyframes pulse-green{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.5)}50%{box-shadow:0 0 0 5px rgba(34,197,94,0)}}

/* ── Stats ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1.4rem}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:.9rem 1.1rem;display:flex;align-items:center;gap:12px;box-shadow:var(--shadow);transition:transform .15s}
.stat-card:hover{transform:translateY(-2px)}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.stat-val{font-size:1.4rem;font-weight:800;color:var(--text);line-height:1}
.stat-lbl{font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-top:2px}

/* ── Section card ── */
.section-card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}
.section-header{padding:.9rem 1.3rem;border-bottom:1px solid var(--maroon-mid);font-weight:800;font-size:.88rem;color:var(--maroon);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}

/* ── Filter tabs ── */
.filter-tabs{display:flex;gap:5px;flex-wrap:wrap;padding:.75rem 1.2rem;border-bottom:1px solid var(--maroon-mid);background:#fdf9f9}
.filter-tab{font-size:.72rem;font-weight:700;padding:4px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;color:var(--muted);cursor:pointer;transition:all .15s}
.filter-tab:hover{border-color:var(--maroon);color:var(--maroon)}
.filter-tab.active{background:var(--maroon);color:#fff;border-color:var(--maroon)}

/* ── Date label ── */
.date-label{font-size:.7rem;font-weight:800;color:#bbb;letter-spacing:.08em;text-transform:uppercase;padding:.7rem 1.3rem .35rem;background:#fdf9f9;border-bottom:1px solid #faf0f0;display:flex;align-items:center;gap:8px}
.date-label::after{content:'';flex:1;height:1px;background:#f0e8e8}

/* ── Notification cards ── */
.notif-card{display:flex;align-items:flex-start;gap:12px;padding:.9rem 1.3rem;border-bottom:1px solid #fdf5f5;cursor:pointer;transition:background .12s;position:relative}
.notif-card:last-child{border-bottom:none}
.notif-card:hover{background:#fdf8f8}
.notif-card.unread{border-left:3px solid var(--maroon);padding-left:calc(1.3rem - 1px);background:#fffcfc}
.notif-card.new-arrival{animation:flash-in .55s ease}
@keyframes flash-in{0%{background:#fff3cd;transform:translateX(-6px);opacity:0}60%{background:#fffbee}100%{background:transparent;transform:translateX(0);opacity:1}}
.notif-icon{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.notif-body{flex:1;min-width:0}
.notif-label{font-size:.75rem;font-weight:800;margin-bottom:2px}
.notif-message{font-size:.83rem;color:#555;line-height:1.55;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:480px}
.notif-footer{display:flex;align-items:center;justify-content:space-between;margin-top:5px;flex-wrap:wrap;gap:4px}
.notif-time{font-size:.7rem;color:#c0a8a8;display:flex;align-items:center;gap:4px}
.type-badge{font-size:.67rem;font-weight:700;padding:2px 9px;border-radius:10px}
.unread-dot{width:8px;height:8px;border-radius:50%;background:var(--maroon);flex-shrink:0;margin-top:7px}
.notif-actions{display:none;gap:4px;position:absolute;top:.9rem;right:1rem}
.notif-card:hover .notif-actions{display:flex}
.notif-action-btn{width:27px;height:27px;border-radius:7px;border:1.5px solid var(--border);background:#fff;color:#999;font-size:.72rem;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .12s}
.notif-action-btn:hover{background:var(--maroon-light);border-color:var(--maroon);color:var(--maroon)}
.notif-action-btn.del:hover{background:#fdecea;border-color:#e24b4a;color:#e24b4a}

/* ── Empty state ── */
.empty-state{text-align:center;padding:4rem 1rem}
.empty-state i{font-size:3rem;display:block;margin-bottom:.75rem;opacity:.2;color:var(--maroon)}
.empty-state p{font-size:.85rem;color:#c0b0b0}

/* ── New alert banner ── */
.new-alert-banner{display:none;position:sticky;top:60px;z-index:90;margin:-1.5rem -1.75rem 1.5rem;padding:.7rem 1.75rem;background:linear-gradient(90deg,#2d6a4f,#1b4332);color:#fff;font-size:.82rem;font-weight:700;align-items:center;gap:10px;cursor:pointer;animation:slide-down .3s ease}
.new-alert-banner.show{display:flex}
@keyframes slide-down{from{transform:translateY(-100%)}to{transform:translateY(0)}}

/* ── Toast ── */
.toast-wrap{position:fixed;bottom:1.5rem;right:1.5rem;z-index:2000;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast-item{background:#1a1a1a;color:#fff;padding:10px 16px;border-radius:12px;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:8px;box-shadow:0 4px 20px rgba(0,0,0,.25);animation:toast-in .25s ease;pointer-events:all}
.toast-item.success{background:#0f5132}.toast-item.error{background:#842029}.toast-item.info{background:#0c5480}
@keyframes toast-in{from{transform:translateX(60px);opacity:0}to{transform:translateX(0);opacity:1}}

/* ── Sound toggle ── */
.sound-toggle{display:inline-flex;align-items:center;gap:6px;font-size:.72rem;font-weight:700;color:var(--muted);cursor:pointer;padding:5px 10px;border-radius:8px;border:1.5px solid var(--border);background:#fff;transition:all .15s}
.sound-toggle.on{color:#0f6e56;border-color:#b7dfc8;background:#f0fdf4}
.btn-act-sm{font-size:.73rem;font-weight:700;padding:5px 13px;border-radius:8px;border:1.5px solid var(--border);background:#fff;color:var(--muted);cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:inherit;transition:all .15s}
.btn-act-sm:hover{border-color:var(--maroon);color:var(--maroon)}

/* ── Modal ── */
.modal-content{border:none;border-radius:var(--radius-lg);overflow:hidden;box-shadow:var(--shadow-lg)}
.modal-body{padding:1.2rem 1.4rem}
.modal-footer{padding:.85rem 1.4rem;border-top:1px solid var(--border);background:#fdf9f9;gap:8px;display:flex;justify-content:flex-end}
.dg{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-top:.9rem}
.dg.single{grid-template-columns:1fr}
.db{border-radius:10px;padding:.75rem 1rem}
.db-lbl{font-size:.63rem;color:#aaa;text-transform:uppercase;letter-spacing:.07em;font-weight:700;margin-bottom:4px}
.db-val{font-size:.86rem;color:#333;font-weight:600;line-height:1.4;word-break:break-word}
.db.red{background:#fdecea}.db.green{background:#f0fdf4}.db.yellow{background:#fffbeb}
.db.blue{background:#eff6ff}.db.purple{background:#f5f3ff}.db.grey{background:#f5f5f5}
.db.orange{background:#fff7ed}.db.teal{background:#f0fdfa}
.db.full{grid-column:1/-1}
.btn-modal-primary{color:#fff;border:none;border-radius:8px;padding:7px 18px;font-size:.82rem;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.btn-ghost{background:#e9ecef;color:#555;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;font-family:inherit;cursor:pointer}

/* ── Mobile ── */
@media (max-width: 768px) {
    .hamburger-btn{display:flex;align-items:center}
    .sidebar{transform:translateX(-100%);transition:transform 0.25s ease;z-index:200;position:fixed !important;top:0;left:0;height:100vh;overflow-y:auto}
    .sidebar.open{transform:translateX(0) !important}
    .topbar{margin-left:0 !important}
    .main-content{margin-left:0 !important;padding:1rem}
    .topbar-title{font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .topbar-user > div > div:last-child{display:none}
    .stats-row{grid-template-columns:1fr 1fr}
    .dg{grid-template-columns:1fr}
    .filter-tabs{gap:4px}
    .filter-tab{font-size:.68rem;padding:3px 10px}
    .section-header{flex-wrap:wrap;gap:.5rem}
    .modal-dialog{margin:auto 0 0;max-width:100%}
    .modal-content{border-radius:16px 16px 0 0 !important}
    .notif-message{max-width:200px}
    .sound-toggle span{display:none}
    .new-alert-banner{margin:-1rem -1rem 1rem;padding:.6rem 1rem}
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
    <a class="nav-link" href="WalkIn.php"><i class="bi bi-calendar-plus"></i> Walk-in Booking</a>
    <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i> View Schedules</a>
    <a class="nav-link" href="CheckAvailability.php"><i class="bi bi-search"></i> Check Availability</a>
    <a class="nav-link" href="staff_driverstripcomplete.php"><i class="bi bi-flag-fill"></i> Driver Trip Records</a>
    <div class="nav-section-label">Account</div>
    <a class="nav-link active" href="notification.php">
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
  <div class="topbar-title">
    <i class="bi bi-bell-fill"></i> Notifications
    <?php if($unreadCount>0): ?>
    <span style="background:var(--maroon);color:#fff;border-radius:20px;padding:2px 9px;font-size:.7rem;font-weight:700"><?=$unreadCount?></span>
    <?php endif; ?>
  </div>
  <div class="topbar-user">
    <button class="sound-toggle on" id="soundToggle">
      <i class="bi bi-volume-up-fill" id="soundIcon"></i>
      <span id="soundLabel">Sound On</span>
    </button>
    <div class="user-avatar ms-2"><?=strtoupper(substr($_SESSION['username'],0,1))?></div>
    <div>
      <div style="font-weight:700;color:#333;font-size:.84rem"><?=htmlspecialchars($_SESSION['username'])?></div>
      <div style="font-size:.7rem;color:var(--maroon);font-weight:700">Staff</div>
    </div>
  </div>
</div>

<div class="new-alert-banner" id="newAlertBanner" onclick="loadNewNotifs()">
  <div class="live-dot" style="background:#fff"></div>
  <span id="newAlertText">New notifications received</span>
  <span style="margin-left:auto;font-size:.75rem;opacity:.8">Click to load ↑</span>
</div>

<div class="main-content">
  <div class="page-hdr">
    <div>
      <h4>Notifications</h4>
      <p><?=date('l, F j, Y')?> &nbsp;·&nbsp; <span id="live-time"></span> PHT
        <?php if($officeName): ?>&nbsp;·&nbsp; <strong style="color:var(--maroon)"><?=htmlspecialchars($officeName)?></strong><?php endif; ?>
      </p>
    </div>
    <div class="live-badge"><div class="live-dot"></div> Live Updates</div>
  </div>

  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon" style="background:#fdecea;color:var(--maroon)"><i class="bi bi-bell-fill"></i></div>
      <div><div class="stat-val" id="stat-total"><?=$totalCount?></div><div class="stat-lbl">Total</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#fff3cd;color:#856404"><i class="bi bi-envelope-fill"></i></div>
      <div><div class="stat-val" id="stat-unread"><?=$unreadAll?></div><div class="stat-lbl">Unread</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#e8f4fd;color:#0c5480"><i class="bi bi-calendar-day"></i></div>
      <div><div class="stat-val"><?=$todayCount?></div><div class="stat-lbl">Today</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon" style="background:#f8d7da;color:#842029"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <div><div class="stat-val"><?=$alertCount?></div><div class="stat-lbl">Alerts</div></div>
    </div>
  </div>

  <div class="section-card">
    <div class="section-header">
      <span><i class="bi bi-bell me-2"></i>All Notifications</span>
      <div class="d-flex gap-2 flex-wrap">
        <?php if($unreadAll>0): ?>
        <button class="btn-act-sm" onclick="markAllRead()"><i class="bi bi-check2-all"></i> Mark all read</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="filter-tabs">
      <button class="filter-tab active" data-filter="all">All</button>
      <button class="filter-tab" data-filter="new_request">New Requests</button>
      <button class="filter-tab" data-filter="approved">Approved</button>
      <button class="filter-tab" data-filter="rejected">Rejected</button>
      <button class="filter-tab" data-filter="cancel">Cancelled</button>
      <button class="filter-tab" data-filter="reschedule">Rescheduled</button>
      <button class="filter-tab" data-filter="reminder">Reminders</button>
      <button class="filter-tab" data-filter="assignment_change">Reassigned</button>
      <button class="filter-tab" data-filter="completed">Completed</button>
    </div>

    <div id="notifList">
    <?php
    $hasAny = false;
    foreach ($grouped as $dateLabel => $items):
        if (empty($items)) continue; $hasAny = true;
    ?>
    <div class="date-label" data-grouplabel><?= $dateLabel ?></div>
    <?php foreach ($items as $n):
        $dbType  = $n['type'] ?? '';
        $cfg     = detect_type($n['message'], $dbType);
        $unread  = !$n['is_read'];
        preg_match('/\[ref:(\d+)\]/', $n['message'], $_rm);
        $refId   = $_rm[1] ?? '';
        $cleanMsg = preg_replace('/\s*\[ref:\d+\]/', '', $n['message']);
        $allLines = array_values(array_filter(array_map('trim', explode("\n", $cleanMsg)), fn($l) => $l !== ''));
        $lineKeyMap = ['TRIP ASSIGNED','NEW TRIP REQUEST','ASSIGNMENT REMOVED','ASSIGNMENT UPDATED','TRIP APPROVED','TRIP REJECTED','TRIP CANCELLED','TRIP RESCHEDULED','TRIP COMPLETED','SUBMITTED','APPROVED','REJECTED','CANCELLED','RESCHEDULED','COMPLETED','REMINDER'];
        $isStructured = count($allLines) > 1 && in_array($allLines[0] ?? '', $lineKeyMap);
        $previewLines = $isStructured ? array_slice($allLines, 1) : $allLines;
        $preview = implode(' · ', array_slice($previewLines, 0, 2));
        $safeMsg = htmlspecialchars($n['message'], ENT_QUOTES);
        $safeType= htmlspecialchars($cfg['type'], ENT_QUOTES);
        $ago     = time_ago($n['created_at']);
        $fullTime= date('M j, Y g:i A', strtotime($n['created_at']));
    ?>
    <div class="notif-card <?= $unread ? 'unread' : '' ?>"
         id="notif-<?= $n['id'] ?>"
         data-id="<?= $n['id'] ?>"
         data-unread="<?= $unread ? '1':'0' ?>"
         data-ref="<?= htmlspecialchars($refId) ?>"
         data-type="<?= $safeType ?>"
         data-msg="<?= $safeMsg ?>"
         data-created="<?= htmlspecialchars($n['created_at']) ?>"
         data-fulltime="<?= htmlspecialchars($fullTime) ?>"
         onclick="handleCardClick(this)">
      <div class="notif-icon" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
        <i class="bi <?= $cfg['icon'] ?>"></i>
      </div>
      <div class="notif-body">
        <div class="notif-label" style="color:<?= $cfg['color'] ?>"><?= htmlspecialchars($cfg['label']) ?></div>
        <div class="notif-message"><?= htmlspecialchars($preview) ?></div>
        <div class="notif-footer">
          <span class="notif-time"><i class="bi bi-clock" style="font-size:.6rem"></i><?= $ago ?></span>
          <span class="type-badge" style="background:<?= $cfg['badgebg'] ?>;color:<?= $cfg['badgecolor'] ?>"><?= htmlspecialchars($cfg['label']) ?></span>
        </div>
      </div>
      <?php if($unread): ?><div class="unread-dot"></div><?php endif; ?>
      <div class="notif-actions">
        <?php if($unread): ?>
        <button class="notif-action-btn" title="Mark read" onclick="event.stopPropagation();markOneRead(<?=$n['id']?>,this)"><i class="bi bi-check2"></i></button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; endforeach; ?>
    <?php if(!$hasAny): ?>
    <div class="empty-state"><i class="bi bi-bell-slash"></i><p class="fw-bold mt-2">No notifications yet</p><p>You're all caught up!</p></div>
    <?php endif; ?>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:500px">
<div class="modal-content">
  <div id="dm-header" style="padding:1.1rem 1.4rem 1rem;color:#fff;position:relative">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.1rem"><i class="bi" id="dm-icon"></i></div>
      <div style="flex:1">
        <div style="font-weight:800;font-size:1rem" id="dm-title">Notification</div>
        <div style="font-size:.72rem;opacity:.72;margin-top:2px"><i class="bi bi-clock" style="font-size:.65rem"></i> <span id="dm-time">—</span></div>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
    </div>
  </div>
  <div class="modal-body" style="background:#fff">
    <div id="dm-badge" style="margin-bottom:1rem"></div>
    <div id="dm-rows" class="dg"></div>
    <div id="dm-rawbox" style="display:none;margin-top:.9rem;background:#f8f9fb;border-radius:10px;padding:.85rem;font-size:.82rem;color:#444;line-height:1.65;white-space:pre-wrap;word-break:break-word"></div>
  </div>
  <div class="modal-footer">
    <button class="btn-ghost" data-bs-dismiss="modal">Close</button>
    <a id="dm-cta" href="Schedules.php" class="btn-modal-primary" style="background:var(--maroon)"><i class="bi bi-calendar-check"></i> View Schedule</a>
  </div>
</div></div></div>

<div class="modal fade" id="clearModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#800000,#5a0000);border-bottom:none">
    <h6 class="modal-title" style="color:#fff;font-weight:800"><i class="bi bi-trash3 me-2"></i>Clear All</h6>
    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
  </div>
  <div class="modal-body" style="font-size:.85rem;color:#555">Delete <strong>all notifications</strong>? This cannot be undone.</div>
  <div class="modal-footer border-0 pt-0">
    <button class="btn-ghost" data-bs-dismiss="modal">Cancel</button>
    <button class="btn-modal-primary" style="background:#842029" onclick="confirmClearAll()"><i class="bi bi-trash3"></i> Yes, Clear All</button>
  </div>
</div></div></div>

<div class="toast-wrap" id="toastWrap"></div>

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

let lastMaxId        = <?= (int)$maxId ?>;
let soundEnabled     = true;
let pendingNewNotifs = [];
let activeFilter     = 'all';
const POLL_INTERVAL  = 8000;

const TC = {
  submitted        :{ hdr:'#0c5480',  icon:'bi-file-earmark-plus-fill',  label:'Submitted',     cta:'Schedules.php?filter=Pending' },
  approved         :{ hdr:'#0f6e56',  icon:'bi-check-circle-fill',       label:'Approved',      cta:'Schedules.php?filter=Approved' },
  rejected         :{ hdr:'#800000',  icon:'bi-x-circle-fill',           label:'Rejected',      cta:'Schedules.php?filter=Rejected' },
  cancel           :{ hdr:'#7a4f00',  icon:'bi-slash-circle-fill',       label:'Cancelled',     cta:'Schedules.php?filter=Cancelled' },
  reschedule       :{ hdr:'#854f0b',  icon:'bi-calendar2-event-fill',    label:'Rescheduled',   cta:'Schedules.php?filter=Pending' },
  reminder         :{ hdr:'#854f0b',  icon:'bi-alarm-fill',              label:'Reminder',      cta:'Schedules.php?filter=Approved' },
  completed        :{ hdr:'#0f6e56',  icon:'bi-flag-fill',               label:'Completed',     cta:'Schedules.php?filter=Completed' },
  assignment_change:{ hdr:'#3730a3',  icon:'bi-arrow-left-right',        label:'Reassigned',    cta:'Schedules.php' },
  trip_assigned    :{ hdr:'#3730a3',  icon:'bi-person-check-fill',       label:'Trip Assigned', cta:'Schedules.php?filter=Approved' },
  new_trip_pending :{ hdr:'#0c5480',  icon:'bi-file-earmark-plus-fill',  label:'New Request',   cta:'Schedules.php?filter=Pending' },
  assignment_removed:{ hdr:'#800000', icon:'bi-person-dash-fill',        label:'Unassigned',    cta:'Schedules.php' },
  new_request      :{ hdr:'#0c5480',  icon:'bi-file-earmark-plus-fill',  label:'New Request',   cta:'Schedules.php?filter=Pending' },
  pending          :{ hdr:'#856404',  icon:'bi-hourglass-split',         label:'Pending',       cta:'Schedules.php?filter=Pending' },
  conflict         :{ hdr:'#800000',  icon:'bi-exclamation-diamond-fill',label:'Conflict',      cta:'Schedules.php' },
  overdue          :{ hdr:'#991b1b',  icon:'bi-exclamation-triangle-fill',label:'Overdue',      cta:'Schedules.php?filter=OnTrip' },
  general          :{ hdr:'#374151',  icon:'bi-bell-fill',               label:'Notice',        cta:'Schedules.php' },
};

const LINE_KEY_MAP = {
  'TRIP ASSIGNED':'trip_assigned','NEW TRIP REQUEST':'new_trip_pending',
  'ASSIGNMENT REMOVED':'assignment_removed','ASSIGNMENT UPDATED':'assignment_change',
  'TRIP APPROVED':'approved','TRIP REJECTED':'rejected','TRIP CANCELLED':'cancel',
  'TRIP RESCHEDULED':'reschedule','TRIP COMPLETED':'completed',
  'SUBMITTED':'submitted','APPROVED':'approved','REJECTED':'rejected',
  'CANCELLED':'cancel','RESCHEDULED':'reschedule','COMPLETED':'completed','REMINDER':'reminder',
};

function detectType(msg, storedType) {
  if (storedType && TC[storedType]) return storedType;
  const firstLine = (msg || '').split('\n')[0].trim();
  if (LINE_KEY_MAP[firstLine]) return LINE_KEY_MAP[firstLine];
  const m = msg.toLowerCase();
  if (m.includes('conflict detected')||m.includes('schedule conflict')||m.includes('overlaps with')) return 'conflict';
  if (m.includes('overdue')||m.includes('not yet returned')||m.includes('was due back'))              return 'overdue';
  if (m.includes('pending trip')&&m.includes('awaiting approval'))                                    return 'pending';
  if (m.includes('rescheduled'))                                                                       return 'reschedule';
  if (m.includes('reassigned')||m.includes('assignment changed'))                                     return 'assignment_change';
  if (m.includes('rejected'))                                                                          return 'rejected';
  if (m.includes('cancelled')||m.includes('canceled')||m.includes('auto-cancelled'))                 return 'cancel';
  if (m.includes('approved'))                                                                          return 'approved';
  if (m.includes('completed')||m.includes('marked complete')||m.includes('returned'))                return 'completed';
  if (m.includes('departs at')||m.includes('departing in')||m.includes('1 hour')||m.includes('reminder')) return 'reminder';
  if (m.includes('submitted a new')||m.includes('new vehicle request')||m.includes('submitted'))     return 'new_request';
  return 'general';
}

function parseStructured(msg) {
  const result = {};
  const clean = (msg || '').replace(/\s*\[ref:\d+\]/g, '');
  clean.split('\n').forEach((line, i) => {
    if (i === 0) return;
    const colon = line.indexOf(':');
    if (colon > 0) { result[line.slice(0, colon).trim()] = line.slice(colon + 1).trim(); }
  });
  return result;
}

function isStructured(msg) {
  return !!LINE_KEY_MAP[(msg || '').split('\n')[0].trim()];
}

(function tick(){
  const n=new Date(),h=n.getHours(),m=String(n.getMinutes()).padStart(2,'0'),s=String(n.getSeconds()).padStart(2,'0');
  const ap=h>=12?'PM':'AM',hh=h%12||12;
  const el=document.getElementById('live-time'); if(el) el.textContent=`${hh}:${m}:${s} ${ap}`;
  setTimeout(tick,1000);
})();

function playNotifSound(){
  if(!soundEnabled) return;
  try{
    const ctx=new(window.AudioContext||window.webkitAudioContext)();
    const o1=ctx.createOscillator(),o2=ctx.createOscillator(),g=ctx.createGain();
    o1.connect(g);o2.connect(g);g.connect(ctx.destination);
    o1.frequency.value=880;o2.frequency.value=1100;
    g.gain.setValueAtTime(0,ctx.currentTime);
    g.gain.linearRampToValueAtTime(0.18,ctx.currentTime+0.05);
    g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.4);
    o1.start(ctx.currentTime);o1.stop(ctx.currentTime+0.18);
    o2.start(ctx.currentTime+0.18);o2.stop(ctx.currentTime+0.4);
  }catch(e){}
}

document.getElementById('soundToggle').addEventListener('click',function(){
  soundEnabled=!soundEnabled;
  this.classList.toggle('on',soundEnabled);
  document.getElementById('soundIcon').className=soundEnabled?'bi bi-volume-up-fill':'bi bi-volume-mute-fill';
  document.getElementById('soundLabel').textContent=soundEnabled?'Sound On':'Sound Off';
  showToast(soundEnabled?'Sound enabled':'Sound muted','info');
});

function showToast(msg,type='success'){
  const wrap=document.getElementById('toastWrap');
  const t=document.createElement('div');
  const icon=type==='success'?'bi-check-circle-fill':type==='error'?'bi-x-circle-fill':'bi-info-circle-fill';
  t.className=`toast-item ${type}`;t.innerHTML=`<i class="bi ${icon}"></i> ${msg}`;
  wrap.appendChild(t);
  setTimeout(()=>{t.style.transition='opacity .3s';t.style.opacity='0';setTimeout(()=>t.remove(),300);},3000);
}

function updateSidebarBadge(count){
  const topBadge=document.querySelector('.topbar-title span');
  const unreadEl=document.getElementById('stat-unread');
  if(topBadge){if(count>0){topBadge.textContent=count;topBadge.style.display='';}else topBadge.style.display='none';}
  if(unreadEl) unreadEl.textContent=count;
}

if('Notification'in window&&Notification.permission==='default') Notification.requestPermission();
function sendBrowserNotif(title,body){
  if('Notification'in window&&Notification.permission==='granted')
    new Notification(title,{body,icon:'../image/Csu.png'});
}

function getTypeColors(type){
  const map={
    submitted:{bg:'#e8f4fd',color:'#0c5480',badgebg:'#cfe2ff',badgecolor:'#0a3678'},
    new_request:{bg:'#e8f4fd',color:'#0c5480',badgebg:'#cfe2ff',badgecolor:'#0a3678'},
    new_trip_pending:{bg:'#e8f4fd',color:'#0c5480',badgebg:'#cfe2ff',badgecolor:'#0a3678'},
    trip_assigned:{bg:'#e8eaff',color:'#3730a3',badgebg:'#e0e7ff',badgecolor:'#3730a3'},
    assignment_change:{bg:'#e8eaff',color:'#3730a3',badgebg:'#e0e7ff',badgecolor:'#3730a3'},
    assignment_removed:{bg:'#fdecea',color:'#800000',badgebg:'#f8d7da',badgecolor:'#842029'},
    approved:{bg:'#d1f0e0',color:'#0f6e56',badgebg:'#d1e7dd',badgecolor:'#0f5132'},
    rejected:{bg:'#fdecea',color:'#800000',badgebg:'#f8d7da',badgecolor:'#842029'},
    cancel:{bg:'#fff0d6',color:'#7a4f00',badgebg:'#fff0d6',badgecolor:'#7a4f00'},
    reschedule:{bg:'#fff3cd',color:'#854f0b',badgebg:'#fff3cd',badgecolor:'#856404'},
    reminder:{bg:'#faeeda',color:'#854f0b',badgebg:'#fff3cd',badgecolor:'#856404'},
    pending:{bg:'#fff3cd',color:'#856404',badgebg:'#fff3cd',badgecolor:'#856404'},
    conflict:{bg:'#fdecea',color:'#800000',badgebg:'#f8d7da',badgecolor:'#842029'},
    overdue:{bg:'#fdecea',color:'#800000',badgebg:'#f8d7da',badgecolor:'#842029'},
    completed:{bg:'#e1f5ee',color:'#0f6e56',badgebg:'#d1e7dd',badgecolor:'#0f5132'},
  };
  return map[type]||{bg:'#f5f0f0',color:'#666',badgebg:'#e2e3e5',badgecolor:'#41464b'};
}

setInterval(async()=>{
  try{
    const res=await fetch(`notification.php?action=poll&last_id=${lastMaxId}`);
    const data=await res.json();
    updateSidebarBadge(data.unread_count);
    if(data.notifications&&data.notifications.length>0){
      pendingNewNotifs=data.notifications;
      const newMax=Math.max(...data.notifications.map(n=>parseInt(n.id)));
      if(newMax>lastMaxId) lastMaxId=newMax;
      const count=data.notifications.length;
      document.getElementById('newAlertText').textContent=`${count} new notification${count>1?'s':''} — click to load`;
      document.getElementById('newAlertBanner').classList.add('show');
      playNotifSound();
      sendBrowserNotif('CSU VSS — New Notification',data.notifications[0].message.substring(0,80));
    }
  }catch(e){}
},POLL_INTERVAL);

function loadNewNotifs(){
  document.getElementById('newAlertBanner').classList.remove('show');
  if(!pendingNewNotifs.length) return;
  const list=document.getElementById('notifList');
  list.querySelector('.empty-state')?.remove();
  let todayLbl=list.querySelector('[data-grouplabel]');
  if(!todayLbl){
    todayLbl=document.createElement('div');
    todayLbl.className='date-label';todayLbl.dataset.grouplabel='';todayLbl.textContent='Today';
    list.insertBefore(todayLbl,list.firstChild);
  }
  const insertBefore=todayLbl.nextSibling;
  pendingNewNotifs.forEach(n=>{
    if(document.getElementById('notif-'+n.id)) return;
    const storedType=n.type||'';
    const type=detectType(n.message,storedType);
    const tCfg=TC[type]||TC.general;
    const cleanMsg=(n.message||'').replace(/\s*\[ref:\d+\]/,'');
    const allLines=cleanMsg.split('\n').map(l=>l.trim()).filter(Boolean);
    const structured=isStructured(n.message);
    const previewLines=structured?allLines.slice(1):allLines;
    const preview=previewLines.slice(0,2).join(' · ');
    const refM=(n.message||'').match(/\[ref:(\d+)\]/);
    const refId=refM?refM[1]:'';
    const div=document.createElement('div');
    div.className='notif-card unread new-arrival';
    div.id='notif-'+n.id;
    div.dataset.id=n.id;div.dataset.unread='1';div.dataset.ref=refId;
    div.dataset.type=type;div.dataset.msg=n.message||'';div.dataset.created=n.created_at||'';
    div.dataset.fulltime=new Date((n.created_at||'').replace(' ','T')).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
    div.setAttribute('onclick','handleCardClick(this)');
    const colors=getTypeColors(type);
    div.innerHTML=`
      <div class="notif-icon" style="background:${colors.bg};color:${colors.color}"><i class="bi ${tCfg.icon}"></i></div>
      <div class="notif-body">
        <div class="notif-label" style="color:${colors.color}">${tCfg.label}</div>
        <div class="notif-message">${escHtml(preview)}</div>
        <div class="notif-footer">
          <span class="notif-time"><i class="bi bi-clock" style="font-size:.6rem"></i>Just now</span>
          <span class="type-badge" style="background:${colors.badgebg};color:${colors.badgecolor}">${tCfg.label}</span>
        </div>
      </div>
      <div class="unread-dot"></div>
      <div class="notif-actions">
        <button class="notif-action-btn" title="Mark read" onclick="event.stopPropagation();markOneRead(${n.id},this)"><i class="bi bi-check2"></i></button>
      </div>`;
    if(activeFilter!=='all'&&type!==activeFilter) div.style.display='none';
    list.insertBefore(div,insertBefore);
  });
  const totalEl=document.getElementById('stat-total');
  if(totalEl) totalEl.textContent=parseInt(totalEl.textContent||0)+pendingNewNotifs.length;
  pendingNewNotifs=[];
  showToast('New notifications loaded','info');
}

document.querySelectorAll('.filter-tab').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.filter-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    activeFilter=btn.dataset.filter;
    document.querySelectorAll('.notif-card').forEach(card=>{
      const t=card.dataset.type;
      const match=activeFilter==='all'
        ||t===activeFilter
        ||(activeFilter==='new_request'&&(t==='new_trip_pending'||t==='new_request'||t==='walkin_booking'))
        ||(activeFilter==='completed'&&(t==='completed'||t==='trip_completed'))
        ||(activeFilter==='approved'&&(t==='approved'||t==='trip_approved'))
        ||(activeFilter==='rejected'&&(t==='rejected'||t==='trip_rejected'))
        ||(activeFilter==='cancel'&&(t==='cancel'||t==='trip_cancelled'))
        ||(activeFilter==='reschedule'&&(t==='reschedule'||t==='trip_rescheduled'))
        ||(activeFilter==='reminder'&&(t==='reminder'||t==='departure_reminder'||t==='reminder_24h'||t==='reminder_1h'));
      card.style.display=match?'':'none';
    });
    document.querySelectorAll('[data-grouplabel]').forEach(lbl=>{
      let sib=lbl.nextElementSibling,visible=false;
      while(sib&&sib.classList.contains('notif-card')){if(sib.style.display!=='none')visible=true;sib=sib.nextElementSibling;}
      lbl.style.display=visible?'':'none';
    });
  });
});

function esc(str){ return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escHtml(s){ return esc(s); }
function db(color,label,value){ if(!value||String(value).trim()===''||value==='—') return ''; return `<div class="db ${color}"><div class="db-lbl">${esc(label)}</div><div class="db-val">${esc(value)}</div></div>`; }
function dbFull(color,label,value){ if(!value||String(value).trim()==='') return ''; return `<div class="db ${color} full"><div class="db-lbl">${esc(label)}</div><div class="db-val">${esc(value)}</div></div>`; }
function ex(msg,regex,group=1){ const m=msg.match(regex); return m?(m[group]||'').trim():''; }

function handleCardClick(card) {
  const id=card.dataset.id, isUnread=card.dataset.unread==='1', ref=card.dataset.ref;
  const msg=card.dataset.msg||'', storedTy=card.dataset.type||'', fulltime=card.dataset.fulltime||'';
  const type=detectType(msg,storedTy), tCfg=TC[type]||TC.general;

  if(isUnread){
    card.classList.remove('unread');card.dataset.unread='0';
    card.querySelector('.unread-dot')?.remove();
    fetch('notification.php?action=mark_read',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+id});
    fetch('notification.php?action=unread_count').then(r=>r.json()).then(d=>updateSidebarBadge(d.count));
  }

  const hdr=document.getElementById('dm-header');
  hdr.style.background=`linear-gradient(135deg,${tCfg.hdr},${tCfg.hdr}bb)`;
  document.getElementById('dm-icon').className='bi '+tCfg.icon;
  document.getElementById('dm-title').textContent=tCfg.label;
  document.getElementById('dm-time').textContent=fulltime;

  const colors=getTypeColors(type);
  document.getElementById('dm-badge').innerHTML=`<span style="display:inline-flex;align-items:center;gap:6px;font-size:.74rem;font-weight:700;padding:5px 14px;border-radius:20px;background:${colors.badgebg};color:${colors.badgecolor}"><i class="bi ${tCfg.icon}" style="font-size:.78rem"></i>${tCfg.label}</span>`;

  const ctaUrl=ref?tCfg.cta+(tCfg.cta.includes('?')?'&':'?')+'highlight='+ref:tCfg.cta;
  document.getElementById('dm-cta').href=ctaUrl;

  const rows=document.getElementById('dm-rows'), rawBx=document.getElementById('dm-rawbox');
  rows.innerHTML='';rows.className='dg';rawBx.style.display='none';
  let html='';
  const structured=isStructured(msg), L=structured?parseStructured(msg):{};

  if(structured){
    html+=db('blue','Trip #',L['Trip']||L['Trip #']);
    html+=db('blue','Destination',L['Destination']);
    html+=db('blue','Requested by',L['Requested by']);
    const keys=['Start date','End date','Departure time','Return time','Driver','Vehicle','Approved by','Rejected by','Cancelled by','Rescheduled by','Reason','New driver','New vehicle','Returned at','Note','Date','Status'];
    keys.forEach(k=>{ if(L[k]) html+=db('blue',k,L[k]); });
  } else {
    rawBx.textContent=msg.replace(/\s*\[ref:\d+\]/,'');
    rawBx.style.display='block';
    rows.className='dg single';
  }
  rows.innerHTML=html||'';
  new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function markOneRead(id,btn){
  const card=document.getElementById('notif-'+id); if(!card) return;
  card.classList.remove('unread');card.dataset.unread='0';
  card.querySelector('.unread-dot')?.remove();btn.remove();
  fetch('notification.php?action=mark_read',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+id})
  .then(()=>fetch('notification.php?action=unread_count').then(r=>r.json()).then(d=>updateSidebarBadge(d.count)));
  showToast('Marked as read');
}

function markAllRead(){
  document.querySelectorAll('.notif-card.unread').forEach(el=>{
    el.classList.remove('unread');el.dataset.unread='0';
    el.querySelector('.unread-dot')?.remove();
    el.querySelectorAll('[title="Mark read"]').forEach(b=>b.remove());
  });
  fetch('notification.php?action=mark_all_read',{method:'POST'});
  updateSidebarBadge(0);
  showToast('All notifications marked as read');
}

function deleteNotif(id){
  const el=document.getElementById('notif-'+id); if(!el) return;
  const wasUnread=el.dataset.unread==='1';
  el.style.transition='opacity .25s,transform .25s';
  el.style.opacity='0';el.style.transform='translateX(20px)';
  setTimeout(()=>{el.remove();cleanDateLabels();checkEmpty();},260);
  fetch('notification.php?action=delete',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'id='+id});
  if(wasUnread) fetch('notification.php?action=unread_count').then(r=>r.json()).then(d=>updateSidebarBadge(d.count));
}

function clearAll(){ new bootstrap.Modal(document.getElementById('clearModal')).show(); }

function confirmClearAll(){
  bootstrap.Modal.getInstance(document.getElementById('clearModal')).hide();
  document.querySelectorAll('.notif-card').forEach(c=>c.remove());
  document.querySelectorAll('.date-label').forEach(l=>l.remove());
  checkEmpty();
  fetch('notification.php?action=clear_all',{method:'POST'});
  updateSidebarBadge(0);
  const totalEl=document.getElementById('stat-total');
  if(totalEl) totalEl.textContent='0';
  showToast('All notifications cleared','info');
}

function cleanDateLabels(){
  document.querySelectorAll('[data-grouplabel]').forEach(lbl=>{
    let sib=lbl.nextElementSibling,has=false;
    while(sib&&sib.classList.contains('notif-card')){has=true;break;}
    if(!has) lbl.remove();
  });
}

function checkEmpty(){
  if(!document.querySelectorAll('.notif-card').length){
    const c=document.querySelector('#notifList');
    if(c&&!c.querySelector('.empty-state'))
      c.insertAdjacentHTML('beforeend',`<div class="empty-state"><i class="bi bi-bell-slash"></i><p class="fw-bold mt-2">No notifications yet</p><p>You're all caught up!</p></div>`);
  }
}

updateSidebarBadge(<?= $unreadCount ?>);
</script>
</body>
</html>