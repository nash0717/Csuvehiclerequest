<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

date_default_timezone_set('Asia/Manila');

$userId = $_SESSION['user_id'];
$flash  = null;

/* ── Detect optional columns ── */
$deptColumnExists = false;
try {
    $dc = $pdo->query("SHOW COLUMNS FROM users LIKE 'dept_id'")->fetch(PDO::FETCH_ASSOC);
    if (!empty($dc)) $deptColumnExists = true;
} catch (Exception $e) {}

$emailColumnExists = false;
try {
    $ec = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch(PDO::FETCH_ASSOC);
    if (!empty($ec)) $emailColumnExists = true;
} catch (Exception $e) {}

$phoneColumnExists = false;
try {
    $pc = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch(PDO::FETCH_ASSOC);
    if (!empty($pc)) $phoneColumnExists = true;
} catch (Exception $e) {}

$createdAtExists = false;
try {
    if ($pdo->query("SHOW COLUMNS FROM users LIKE 'created_at'")->fetch()) $createdAtExists = true;
} catch (Exception $e) {}

$updatedAtExists = false;
try {
    if ($pdo->query("SHOW COLUMNS FROM users LIKE 'updated_at'")->fetch()) $updatedAtExists = true;
} catch (Exception $e) {}

$statusColumnExists = false;
try {
    if ($pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch()) $statusColumnExists = true;
} catch (Exception $e) {}

/* ── Build fetch query ── */
$selectCols = "u.user_id, u.username, u.role, u.office_id, u.password, o.office_name";
$deptJoin   = "";
if ($createdAtExists)    $selectCols .= ", u.created_at";
if ($updatedAtExists)    $selectCols .= ", u.updated_at";
if ($statusColumnExists) $selectCols .= ", u.status";
if ($emailColumnExists)  $selectCols .= ", u.email";
if ($phoneColumnExists)  $selectCols .= ", u.phone";
if ($deptColumnExists) {
    $selectCols .= ", u.dept_id, d.dept_name";
    $deptJoin    = "LEFT JOIN departments d ON u.dept_id = d.dept_id";
}

$fetchSql = "SELECT {$selectCols} FROM users u LEFT JOIN offices o ON u.office_id = o.office_id {$deptJoin} WHERE u.user_id = ?";
$stmt = $pdo->prepare($fetchSql);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$myOfficeId = (int)($user['office_id'] ?? 0);
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$userId, $myOfficeId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/* ── Helper: validate phone (must be 11 digits starting with 09) ── */
function validatePhone($phone) {
    return preg_match('/^09\d{9}$/', $phone);
}

/* ── POST handlers ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── Update Profile ── */
    if ($action === 'update_profile') {
        $newUsername = trim($_POST['username'] ?? '');
        $email       = trim($_POST['email']    ?? '');
        $phone       = trim($_POST['phone']    ?? '');
        $errors      = [];

        if (!$newUsername) {
            $errors[] = 'Full name is required.';
        } elseif ($newUsername !== $user['username']) {
            $chk = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
            $chk->execute([$newUsername, $userId]);
            if ($chk->fetch()) $errors[] = 'Full name is already taken.';
        }

        if ($emailColumnExists && empty($email)) {
            $errors[] = 'Email address is required.';
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        if ($phoneColumnExists && empty($phone)) {
            $errors[] = 'Phone number is required.';
        } elseif ($phoneColumnExists && !empty($phone) && !validatePhone($phone)) {
            $errors[] = 'Phone number must be 11 digits and start with 09 (e.g. 09XXXXXXXXX).';
        }

        if (empty($errors)) {
            $setCols = $updatedAtExists ? "username=?, updated_at=NOW()" : "username=?";
            $params  = [$newUsername];

            if ($emailColumnExists) { $setCols .= ", email=?"; $params[] = $email ?: null; }
            if ($phoneColumnExists) { $setCols .= ", phone=?"; $params[] = $phone ?: null; }
            $params[] = $userId;

            $pdo->prepare("UPDATE users SET {$setCols} WHERE user_id=?")->execute($params);
            $_SESSION['username'] = $newUsername;
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profile updated successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => implode(' ', $errors)];
        }
        header('Location: my_account.php'); exit;
    }

    /* ── Change Password ── */
    if ($action === 'change_password') {
        $current    = $_POST['current_password'] ?? '';
        $newPass    = $_POST['new_password']     ?? '';
        $confirmNew = $_POST['confirm_password'] ?? '';
        $errors     = [];

        if (!$current || !$newPass || !$confirmNew) $errors[] = 'All password fields are required.';
        elseif (!password_verify($current, $user['password'])) $errors[] = 'Current password is incorrect.';
        elseif (strlen($newPass) < 8) $errors[] = 'New password must be at least 8 characters.';
        elseif ($newPass !== $confirmNew) $errors[] = 'New passwords do not match.';
        elseif (password_verify($newPass, $user['password'])) $errors[] = 'New password must differ from the current one.';

        if (empty($errors)) {
            $pwSql = $updatedAtExists
                ? "UPDATE users SET password=?, updated_at=NOW() WHERE user_id=?"
                : "UPDATE users SET password=? WHERE user_id=?";
            $pdo->prepare($pwSql)->execute([password_hash($newPass, PASSWORD_DEFAULT), $userId]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Password changed successfully.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => implode(' ', $errors)];
        }
        header('Location: my_account.php'); exit;
    }
}

/* ── Flash message ── */
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ── Helpers ── */
function initials($u) {
    $f = $u['username'] ?? '?';
    return strtoupper(substr($f, 0, 1));
}
function fmt($d) { return $d ? date('M j, Y', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Account – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
:root{
    --maroon:#800000;--maroon-dark:#5c0000;--maroon-light:#fdf5f5;--maroon-mid:#f0e5e5;
    --gold:#c9a227;--text:#2a2a2a;--muted:#888;
    --radius:14px;--shadow:0 2px 16px rgba(128,0,0,.07);
}
body{background:#f5f0f0;font-family:'Segoe UI',system-ui,sans-serif;color:var(--text)}

/* ── Mobile hamburger + overlay ── */
.hamburger-btn{display:none;background:none;border:none;cursor:pointer;padding:4px;color:#800000;font-size:1.2rem;line-height:1;margin-right:.5rem}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:199}
.sidebar-overlay.open{display:block}

.notif-badge-pill {
    background: #e24b4a;
    color: #fff;
    font-size: .62rem;
    font-weight: 700;
    min-width: 17px;
    height: 17px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    margin-left: auto;
}

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
.topbar-title{font-weight:700;font-size:1rem;color:var(--maroon)}
.user-av-sm{width:32px;height:32px;border-radius:50%;background:var(--maroon);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0}

.main-content{margin-left:240px;padding:1.5rem}

.section-card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.25rem}
.section-header{padding:1rem 1.25rem;border-bottom:1px solid var(--maroon-mid);font-weight:700;font-size:.9rem;color:var(--maroon);display:flex;align-items:center;justify-content:space-between;background:var(--maroon-light)}

.table thead th{background:var(--maroon-light);color:var(--maroon);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid var(--maroon-mid);padding:.75rem 1rem;white-space:nowrap}
.table tbody td{padding:.75rem 1rem;font-size:.85rem;color:#444;vertical-align:middle;border-color:var(--maroon-light)}
.table tbody tr:hover{background:#fdf8f8}

.role-admin    {background:#fdecea;color:#800000;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px}
.role-staff    {background:#e8f0fe;color:#1a56db;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px}
.role-requestor{background:#e6f4ea;color:#137333;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px}
.dept-badge{background:#f3e8ff;color:#6b21a8;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px}
.you-badge{font-size:.72rem;background:#fff3cd;color:#856404;padding:2px 8px;border-radius:20px;font-weight:600}
.password-dots{color:#bbb;font-size:1.1rem;letter-spacing:3px}
.email-cell{display:flex;align-items:center;gap:7px}
.email-icon{width:26px;height:26px;border-radius:6px;background:#eef2ff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.email-icon i{font-size:.78rem;color:#4361ee}
.email-text{font-size:.83rem;color:#2c3e7a;font-weight:500}
.tbl-avatar{width:36px;height:36px;border-radius:50%;background:var(--maroon);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0;border:2px solid var(--maroon-mid)}

.btn-maroon{background:var(--maroon);color:#fff;border:none}
.btn-maroon:hover{background:var(--maroon-dark);color:#fff}

.modal-header{background:linear-gradient(135deg,#800000,#6b0000);color:#fff}
.modal-header .btn-close{filter:invert(1)}
.form-label-sm{font-size:.74rem;font-weight:700;color:#666;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.04em}
.form-control-styled{border:1.5px solid #e0d0d0;border-radius:8px;font-size:.875rem;padding:.5rem .85rem;transition:border-color .15s,box-shadow .15s}
.form-control-styled:focus{border-color:var(--maroon);box-shadow:0 0 0 3px rgba(128,0,0,.1);outline:none}
.form-control-styled[readonly]{background:var(--maroon-light);color:#999;cursor:not-allowed}
.input-group-text{background:var(--maroon-light);border:1.5px solid #e0d0d0;font-size:.85rem;color:var(--maroon)}

.pw-bar{height:4px;border-radius:2px;background:#eee;overflow:hidden;margin-top:5px}
.pw-fill{height:100%;border-radius:2px;transition:width .3s,background .3s;width:0}
.pw-txt{font-size:.69rem;margin-top:3px}

/* Phone validation feedback */
.phone-feedback{font-size:.75rem;margin-top:4px}
.phone-feedback.invalid{color:#dc3545}
.phone-feedback.valid{color:#198754}
input.phone-invalid{border-color:#dc3545 !important}
input.phone-valid{border-color:#198754 !important}

.toast-area{position:fixed;top:1rem;right:1rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem}
.toast-msg{display:flex;align-items:center;gap:.6rem;padding:.7rem 1.1rem;border-radius:10px;font-size:.83rem;font-weight:500;box-shadow:0 4px 18px rgba(0,0,0,.13);animation:slideIn .3s ease;min-width:260px}
.toast-msg.success{background:#d1e7dd;color:#0f5132;border-left:4px solid #2e7d52}
.toast-msg.error{background:#f8d7da;color:#842029;border-left:4px solid #b52a35}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}

/* ── Mobile responsive ── */
@media (max-width: 768px) {
    .hamburger-btn{display:flex;align-items:center}
    .sidebar{transform:translateX(-100%);transition:transform 0.25s ease;z-index:200;position:fixed !important;top:0;left:0;height:100vh;overflow-y:auto}
    .sidebar.open{transform:translateX(0) !important}
    .topbar{margin-left:0 !important;padding:.6rem 1rem}
    .main-content{margin-left:0 !important;padding:1rem}
    .topbar-title{font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .topbar .d-flex > div > div:last-child{display:none}
    .table-responsive{font-size:.78rem}
    .section-header{flex-wrap:wrap;gap:.5rem}
    .section-header .d-flex{flex-wrap:wrap;gap:.4rem}
    .modal-dialog{margin:auto 0 0;max-width:100%}
    .modal-content{border-radius:16px 16px 0 0 !important}
    .row.g-3 .col-md-4{margin-bottom:.5rem}
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ── Sidebar ── -->
<div class="sidebar" id="mainSidebar">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="../image/Csu.png" alt="Logo"></div>
    <div class="sidebar-brand-text">CSU Vehicle System<span>Staff Panel</span></div>
  </div>
  <nav class="nav flex-column mt-2">
    <div class="nav-section-label">Main</div>
    <a class="nav-link" href="dashboard.php">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a>
    <div class="nav-section-label">Manage</div>
    <a class="nav-link" href="Requestors.php">
      <i class="bi bi-people"></i> Requestors
    </a>
    <div class="nav-section-label">Scheduling</div>
    <a class="nav-link" href="WalkIn.php">
      <i class="bi bi-calendar-plus"></i> Walk-in Booking
    </a>
    <a class="nav-link" href="Schedules.php">
      <i class="bi bi-calendar-check"></i> View Schedules
    </a>
    <a class="nav-link" href="CheckAvailability.php">
      <i class="bi bi-search"></i> Check Availability
    </a>
    <a class="nav-link" href="staff_driverstripcomplete.php">
      <i class="bi bi-flag-fill"></i> Driver Trip Records
    </a>
    <div class="nav-section-label">Account</div>
    <a class="nav-link" href="notification.php">
        <i class="bi bi-bell"></i> Notifications
        <?php if($unreadCount > 0): ?>
        <span class="notif-badge-pill">
            <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
        </span>
        <?php endif; ?>
    </a>
    <a class="nav-link active" href="my_account.php">
        <i class="bi bi-person-circle"></i> My Account
    </a>
    <hr class="sidebar-divider">
    <a class="nav-link" href="../Logout.php">
      <i class="bi bi-box-arrow-left"></i> Logout
    </a>
  </nav>
</div>

<!-- ── Topbar ── -->
<div class="topbar">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-person-circle me-2"></i>My Account</div>
    <div class="d-flex align-items:center gap-2">
        <div class="user-av-sm"><?=initials($user)?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.85rem"><?=htmlspecialchars($user['username']??'')?></div>
            <div style="font-size:.72rem;color:var(--maroon)"><?=htmlspecialchars(ucfirst($user['role']??'Staff'))?></div>
        </div>
    </div>
</div>

<!-- ── Flash Toast ── -->
<?php if($flash): ?>
<div class="toast-area">
    <div class="toast-msg <?=htmlspecialchars($flash['type'])?>">
        <i class="bi bi-<?=$flash['type']==='success'?'check-circle-fill':'exclamation-circle-fill'?>"></i>
        <?=htmlspecialchars($flash['msg'])?>
    </div>
</div>
<?php endif; ?>

<div class="main-content">

    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h5 class="mb-0" style="color:var(--maroon);font-weight:700">My Account</h5>
            <div style="font-size:.78rem;color:var(--muted)">View and manage your personal information</div>
        </div>
    </div>

    <!-- ── Profile Table ── -->
    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-person-circle me-2"></i>My Profile <span class="you-badge ms-2">You</span></span>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pwModal">
                    <i class="bi bi-key me-1"></i>Change Password
                </button>
                <button class="btn btn-sm btn-maroon" data-bs-toggle="modal" data-bs-target="#editModal">
                    <i class="bi bi-pencil me-1"></i>Edit Info
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <?php if($emailColumnExists): ?><th>Email</th><?php endif; ?>
                        <th>Password</th>
                        <th>Role</th>
                        <th>Office</th>
                        <?php if($deptColumnExists): ?><th>Department</th><?php endif; ?>
                        <?php if($phoneColumnExists): ?><th>Phone</th><?php endif; ?>
                        <?php if($createdAtExists): ?><th>Member Since</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <tr>
                    <td><?=(int)$user['user_id']?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="tbl-avatar"><?=initials($user)?></div>
                            <div>
                                <div style="font-weight:600;color:#333"><?=htmlspecialchars($user['username']??'')?></div>
                                <div style="font-size:.72rem;color:var(--muted)"><span class="you-badge">You</span></div>
                            </div>
                        </div>
                    </td>
                    <?php if($emailColumnExists): ?>
                    <td>
                        <?php if(!empty($user['email'])): ?>
                            <div class="email-cell">
                                <div class="email-icon"><i class="bi bi-envelope-fill"></i></div>
                                <a href="mailto:<?=htmlspecialchars($user['email'])?>" class="email-text"><?=htmlspecialchars($user['email'])?></a>
                            </div>
                        <?php else: ?>
                            <span style="color:#bbb;font-style:italic;font-size:.82rem">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><span class="password-dots">••••••••</span></td>
                    <td>
                        <?php
                        $r = $user['role'] ?? '';
                        if ($r==='admin')         echo '<span class="role-admin"><i class="bi bi-shield-check"></i>Admin</span>';
                        elseif ($r==='staff')     echo '<span class="role-staff"><i class="bi bi-person-gear"></i>Staff</span>';
                        elseif ($r==='requestor') echo '<span class="role-requestor"><i class="bi bi-person"></i>Requestor</span>';
                        else echo '<span class="role-staff"><i class="bi bi-person"></i>'.htmlspecialchars(ucfirst($r)).'</span>';
                        ?>
                    </td>
                    <td><?=htmlspecialchars($user['office_name']??'—')?></td>
                    <?php if($deptColumnExists): ?>
                    <td>
                        <?php if(!empty($user['dept_name'])): ?>
                            <span class="dept-badge"><i class="bi bi-diagram-3"></i><?=htmlspecialchars($user['dept_name'])?></span>
                        <?php else: ?>
                            <span style="color:#bbb;font-style:italic;font-size:.82rem">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if($phoneColumnExists): ?>
                    <td style="font-size:.83rem"><?=htmlspecialchars(!empty($user['phone'])?$user['phone']:'—')?></td>
                    <?php endif; ?>
                    <?php if($createdAtExists): ?>
                    <td style="font-size:.8rem;color:var(--muted)"><?=fmt($user['created_at']??null)?></td>
                    <?php endif; ?>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal" title="Edit profile">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Info cards ── -->
    <div class="row g-3 mt-1">

        <!-- Account Details -->
        <div class="col-md-4">
            <div class="section-card">
                <div class="section-header" style="font-size:.82rem">
                    <span><i class="bi bi-info-circle me-2"></i>Account Details</span>
                </div>
                <div style="padding:.85rem 1.25rem">
                    <?php
                    $detailRows = [['User ID','#'.$userId,'bi-hash']];
                    if ($createdAtExists) $detailRows[] = ['Member Since', fmt($user['created_at']??null), 'bi-calendar2-plus'];
                    if ($updatedAtExists) $detailRows[] = ['Last Updated',  fmt($user['updated_at']??null), 'bi-pencil'];
                    $detailRows[] = ['Status', ucfirst($statusColumnExists ? ($user['status']??'Active') : 'Active'), 'bi-circle-fill'];
                    foreach($detailRows as [$lbl,$val,$icon]):
                    ?>
                    <div style="display:flex;align-items:center;gap:.6rem;padding:.45rem 0;border-bottom:1px solid var(--maroon-mid)">
                        <div style="width:26px;height:26px;border-radius:6px;background:var(--maroon-light);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="bi <?=$icon?>" style="font-size:.7rem;color:var(--maroon)"></i>
                        </div>
                        <div>
                            <div style="font-size:.66rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em"><?=$lbl?></div>
                            <div style="font-size:.8rem;font-weight:600;color:#333"><?=$val?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Profile Completeness -->
        <div class="col-md-4">
            <div class="section-card">
                <div class="section-header" style="font-size:.82rem">
                    <span><i class="bi bi-shield-check me-2"></i>Profile Completeness</span>
                </div>
                <div style="padding:.85rem 1.25rem">
                    <?php
                    $checks = [
                        [$emailColumnExists&&!empty($user['email']),  'Email added',    'bi-envelope'],
                        [$phoneColumnExists&&!empty($user['phone']),  'Phone added',    'bi-phone'],
                        [true,                                        'Password is set','bi-key'],
                    ];
                    $score = array_sum(array_column($checks,0));
                    $pct   = round(($score/count($checks))*100);
                    $barC  = $pct>=80?'#2e7d52':($pct>=50?'var(--gold)':'var(--maroon)');
                    ?>
                    <div style="margin-bottom:.75rem">
                        <div style="display:flex;justify-content:space-between;margin-bottom:.3rem">
                            <span style="font-size:.74rem;font-weight:700;color:var(--maroon)">Completeness</span>
                            <span style="font-size:.74rem;font-weight:700;color:var(--maroon)"><?=$pct?>%</span>
                        </div>
                        <div style="height:7px;background:#e0d0d0;border-radius:4px;overflow:hidden">
                            <div style="height:100%;width:<?=$pct?>%;background:<?=$barC?>;border-radius:4px;transition:width .4s"></div>
                        </div>
                        <div style="font-size:.68rem;color:var(--muted);margin-top:2px"><?=$score?>/<?=count($checks)?> items completed</div>
                    </div>
                    <?php foreach($checks as [$ok,$label,$icon]): ?>
                    <div style="display:flex;align-items:center;gap:.5rem;padding:.32rem 0;border-bottom:1px solid var(--maroon-mid);font-size:.79rem;color:#555">
                        <i class="bi <?=$icon?>" style="color:<?=$ok?'#2e7d52':'#ccc'?>;width:14px;flex-shrink:0"></i>
                        <span style="flex:1"><?=$label?></span>
                        <i class="bi bi-<?=$ok?'check-circle-fill':'circle'?>" style="color:<?=$ok?'#2e7d52':'#ddd'?>;font-size:.8rem"></i>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="col-md-4">
            <div class="section-card">
                <div class="section-header" style="font-size:.82rem">
                    <span><i class="bi bi-lightning me-2"></i>Quick Actions</span>
                </div>
                <div style="padding:.85rem 1.25rem;display:flex;flex-direction:column;gap:.6rem">
                    <button class="btn btn-maroon btn-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#editModal">
                        <i class="bi bi-pencil-square"></i>Edit My Profile
                    </button>
                    <button class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#pwModal">
                        <i class="bi bi-key"></i>Change Password
                    </button>
                    <a href="../Logout.php" class="btn btn-outline-danger btn-sm d-flex align-items-center gap-2" style="text-decoration:none"
                       onclick="return confirm('Sign out?')">
                        <i class="bi bi-box-arrow-left"></i>Sign Out
                    </a>
                </div>
            </div>
        </div>

    </div>

</div><!-- /.main-content -->

<!-- ══ EDIT PROFILE MODAL ══ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit My Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off" id="editProfileForm" novalidate>
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Username -->
                        <div class="col-12">
                            <label class="form-label-sm">Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" class="form-control form-control-styled"
                                       value="<?=htmlspecialchars($user['username']??'')?>"
                                       required placeholder="Enter full name">
                            </div>
                            <div class="invalid-feedback">Full name is required.</div>
                        </div>

                        <?php if($emailColumnExists): ?>
                        <!-- Email — required -->
                        <div class="col-12">
                            <label class="form-label-sm">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control form-control-styled"
                                       value="<?=htmlspecialchars($user['email']??'')?>"
                                       placeholder="your@email.com" required>
                            </div>
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        <?php endif; ?>

                        <?php if($phoneColumnExists): ?>
                        <!-- Phone — required, must start with 09 -->
                        <div class="col-12">
                            <label class="form-label-sm">Phone Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                <input type="text" name="phone" id="profile_phone"
                                       class="form-control form-control-styled"
                                       value="<?=htmlspecialchars(!empty($user['phone']) ? $user['phone'] : '')?>"
                                       placeholder="09XXXXXXXXX"
                                       pattern="09\d{9}"
                                       maxlength="11"
                                       inputmode="numeric"
                                       required>
                            </div>
                            <div style="font-size:.72rem;color:var(--muted);margin-top:3px">
                                <i class="bi bi-info-circle me-1"></i>Must start with <strong>09</strong> followed by 9 digits.
                            </div>
                            <div class="phone-feedback" id="profile_phone_feedback"></div>
                        </div>
                        <?php endif; ?>

                        <!-- Read-only fields -->
                        <div class="col-sm-6">
                            <label class="form-label-sm">Role</label>
                            <input type="text" class="form-control form-control-styled"
                                   value="<?=htmlspecialchars(ucfirst($user['role']??''))?>" readonly>
                            <div style="font-size:.68rem;color:var(--muted);margin-top:2px"><i class="bi bi-lock me-1"></i>Managed by admin</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label-sm">Office</label>
                            <input type="text" class="form-control form-control-styled"
                                   value="<?=htmlspecialchars($user['office_name']??'—')?>" readonly>
                            <div style="font-size:.68rem;color:var(--muted);margin-top:2px"><i class="bi bi-lock me-1"></i>Managed by admin</div>
                        </div>
                        <?php if($deptColumnExists): ?>
                        <div class="col-sm-6">
                            <label class="form-label-sm">Department</label>
                            <input type="text" class="form-control form-control-styled"
                                   value="<?=htmlspecialchars($user['dept_name']??'—')?>" readonly>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ CHANGE PASSWORD MODAL ══ -->
<div class="modal fade" id="pwModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="change_password">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-sm">Current Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="current_password" class="form-control form-control-styled"
                                   id="curPw" required placeholder="Enter current password">
                            <button type="button" class="input-group-text" onclick="togglePw('curPw',this)" style="cursor:pointer;border-left:none"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label-sm">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="new_password" class="form-control form-control-styled"
                                   id="newPw" required placeholder="Minimum 8 characters" oninput="checkStrength(this.value)">
                            <button type="button" class="input-group-text" onclick="togglePw('newPw',this)" style="cursor:pointer;border-left:none"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="pw-bar"><div class="pw-fill" id="strengthBar"></div></div>
                        <div class="pw-txt" id="strengthText" style="color:#aaa">Enter a password</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-sm">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="confirm_password" class="form-control form-control-styled"
                                   id="confPw" required placeholder="Repeat new password" oninput="checkMatch()">
                            <button type="button" class="input-group-text" onclick="togglePw('confPw',this)" style="cursor:pointer;border-left:none"><i class="bi bi-eye"></i></button>
                        </div>
                        <div class="pw-txt" id="matchText"></div>
                    </div>
                    <div style="padding:.6rem .8rem;background:#fff3cd;border-radius:8px;font-size:.76rem;color:#856404;margin-top:.75rem">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Never share your password</strong> with anyone.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon"><i class="bi bi-shield-lock me-1"></i>Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── Sidebar toggle (matches notification.php) ── */
function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const isOpen  = sidebar.classList.toggle('open');
    overlay.classList.toggle('open', isOpen);
    document.body.style.overflow = isOpen ? 'hidden' : '';
}
/* Close sidebar when a nav link is clicked on mobile */
document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) toggleSidebar();
    });
});

/* ── Password toggle ── */
function togglePw(id, btn) {
    const inp = document.getElementById(id), icon = btn.querySelector('i');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

/* ── Password strength ── */
function checkStrength(pw) {
    const bar = document.getElementById('strengthBar'), txt = document.getElementById('strengthText');
    let s = 0;
    if (pw.length >= 8) s++; if (/[A-Z]/.test(pw)) s++; if (/[0-9]/.test(pw)) s++; if (/[^A-Za-z0-9]/.test(pw)) s++;
    const lvls = [
        {w:'0%',  c:'#ccc',    t:'Enter a password',tc:'#aaa'},
        {w:'25%', c:'#e74c3c', t:'Weak',            tc:'#e74c3c'},
        {w:'50%', c:'#e6a817', t:'Fair',            tc:'#c87000'},
        {w:'75%', c:'#2ecc71', t:'Good',            tc:'#1a7a42'},
        {w:'100%',c:'#27ae60', t:'Strong 💪',       tc:'#1a7a42'},
    ];
    const l = pw.length === 0 ? 0 : Math.max(1, s);
    bar.style.width = lvls[l].w; bar.style.background = lvls[l].c;
    txt.textContent = lvls[l].t; txt.style.color = lvls[l].tc;
}

/* ── Password match ── */
function checkMatch() {
    const np = document.getElementById('newPw').value,
          cp = document.getElementById('confPw').value,
          el = document.getElementById('matchText');
    if (!cp) { el.textContent = ''; return; }
    el.textContent = np === cp ? '✓ Passwords match' : '✗ Passwords do not match';
    el.style.color = np === cp ? '#2e7d52' : '#842029';
}

/* ── Phone validation ── */
function setupPhoneField(inputId, feedbackId) {
    var input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('keypress', function (e) {
        var pos = this.selectionStart;
        if (pos === 0 && e.key !== '0') { e.preventDefault(); return; }
        if (pos === 1 && e.key !== '9') { e.preventDefault(); return; }
        if (!/^\d$/.test(e.key))         { e.preventDefault(); return; }
    });

    input.addEventListener('paste', function (e) {
        e.preventDefault();
        var digits = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').substring(0, 11);
        if (digits.length >= 2 && digits.substring(0, 2) !== '09') digits = '09' + digits.substring(2);
        this.value = digits;
        updatePhoneFeedback(input, feedbackId);
    });

    input.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').substring(0, 11);
        updatePhoneFeedback(input, feedbackId);
    });

    input.addEventListener('focus', function () {
        if (this.value === '') {
            this.value = '09';
            updatePhoneFeedback(input, feedbackId);
        }
    });

    input.addEventListener('blur', function () {
        if (this.value === '09') {
            this.value = '';
            updatePhoneFeedback(input, feedbackId);
        }
    });
}

function updatePhoneFeedback(input, feedbackId) {
    var fb  = document.getElementById(feedbackId);
    var val = input.value;
    if (!fb) return;

    if (val.length === 0) {
        fb.textContent = '';
        fb.className   = 'phone-feedback';
        input.classList.remove('phone-valid', 'phone-invalid');
    } else if (val.length < 2 || val.substring(0, 2) !== '09') {
        fb.textContent = '✗ Must start with 09.';
        fb.className   = 'phone-feedback invalid';
        input.classList.add('phone-invalid');
        input.classList.remove('phone-valid');
    } else if (val.length < 11) {
        fb.textContent = (11 - val.length) + ' more digit(s) needed.';
        fb.className   = 'phone-feedback invalid';
        input.classList.add('phone-invalid');
        input.classList.remove('phone-valid');
    } else {
        fb.textContent = '✓ Phone number looks good.';
        fb.className   = 'phone-feedback valid';
        input.classList.add('phone-valid');
        input.classList.remove('phone-invalid');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    setupPhoneField('profile_phone', 'profile_phone_feedback');

    var ph = document.getElementById('profile_phone');
    if (ph && ph.value) updatePhoneFeedback(ph, 'profile_phone_feedback');

    var form = document.getElementById('editProfileForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (ph) {
                ph.setCustomValidity(/^09\d{9}$/.test(ph.value) ? '' : 'Must be 11 digits starting with 09.');
            }
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    }

    var modal = document.getElementById('editModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function () {
            if (ph && ph.value) updatePhoneFeedback(ph, 'profile_phone_feedback');
        });
        modal.addEventListener('hidden.bs.modal', function () {
            if (form) form.classList.remove('was-validated');
            if (ph) {
                ph.setCustomValidity('');
                ph.classList.remove('phone-valid', 'phone-invalid');
            }
            var fb = document.getElementById('profile_phone_feedback');
            if (fb) { fb.textContent = ''; fb.className = 'phone-feedback'; }
        });
    }
});

/* ── Toast auto-dismiss ── */
setTimeout(() => document.querySelectorAll('.toast-msg').forEach(t => {
    t.style.transition = 'opacity .4s'; t.style.opacity = '0';
    setTimeout(() => t.remove(), 400);
}), 4000);
</script>
</body>
</html>