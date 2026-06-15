<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header("Location: /csuweb/login.php?error=unauthorized"); exit;
}

/* ── Current staff ── */
$cu = $pdo->prepare("SELECT u.*, o.office_id AS u_office_id, o.office_name FROM users u LEFT JOIN offices o ON u.office_id=o.office_id WHERE u.user_id=?");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();
$myOfficeId = (int)($me['u_office_id'] ?? 0);

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
$unreadStmt->execute([$_SESSION['user_id'], $myOfficeId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

/* ── Auto-migrate: ensure created_at exists ── */
try {
    $pdo->query("SHOW COLUMNS FROM users LIKE 'created_at'")->rowCount() === 0
        && $pdo->exec("ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
} catch(PDOException $e) {}

/* ── Departments for dropdown ── */
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE office_id = ? ORDER BY dept_name");
$deptStmt->execute([$myOfficeId]);
$depts = $deptStmt->fetchAll();

/* ── POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $uname  = trim(sanitize($_POST['username'] ?? ''));
        $email  = trim(sanitize($_POST['email'] ?? ''));
        $pass   = $_POST['password'] ?? '';
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

        if (!$uname || !$pass) {
            $_SESSION['flash']['danger'] = "Username and password are required.";
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?");
            $chk->execute([$uname]);
            if ($chk->fetchColumn() > 0) {
                $_SESSION['flash']['danger'] = "Username already exists.";
            } else {
                try {
                    $hashed = password_hash($pass, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (username, email, password, role, office_id, dept_id, created_at) VALUES (?,?,?,'requestor',?,?,NOW())")
                        ->execute([$uname, $email ?: null, $hashed, $myOfficeId, $deptId]);

                    $newUserId = (int)$pdo->lastInsertId();
                    $adminStmt = $pdo->prepare("SELECT user_id FROM users WHERE role='admin' AND office_id=?");
                    $adminStmt->execute([$myOfficeId]);
                    $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

                    $deptLabel = '';
                    if ($deptId) {
                        $deptRow = $pdo->prepare("SELECT dept_name FROM departments WHERE dept_id=?");
                        $deptRow->execute([$deptId]);
                        $deptLabel = $deptRow->fetchColumn();
                    }

                    $notifMsg = "New requestor account created by staff {$me['username']}: \"{$uname}\""
                              . ($deptLabel ? " — {$deptLabel}" : "")
                              . " [ref:{$newUserId}]";

                    $notifInsert = $pdo->prepare(
                        "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())"
                    );
                    foreach ($admins as $adminId) {
                        $notifInsert->execute([$adminId, $notifMsg]);
                    }

                    $_SESSION['flash']['success'] = "Requestor account created successfully.";
                } catch (PDOException $e) {
                    $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
                }
            }
        }

    } elseif ($action === 'edit') {
        $uid    = (int)$_POST['user_id'];
        $uname  = trim(sanitize($_POST['username'] ?? ''));
        $email  = trim(sanitize($_POST['email'] ?? ''));
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $pass   = $_POST['password'] ?? '';

        try {
            if ($pass) {
                $hashed = password_hash($pass, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET username=?, email=?, dept_id=?, password=? WHERE user_id=? AND office_id=? AND role='requestor'")
                    ->execute([$uname, $email ?: null, $deptId, $hashed, $uid, $myOfficeId]);
            } else {
                $pdo->prepare("UPDATE users SET username=?, email=?, dept_id=? WHERE user_id=? AND office_id=? AND role='requestor'")
                    ->execute([$uname, $email ?: null, $deptId, $uid, $myOfficeId]);
            }
            $_SESSION['flash']['success'] = "Requestor updated.";
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }

    } elseif ($action === 'delete') {
        try {
            $pdo->prepare("DELETE FROM users WHERE user_id=? AND office_id=? AND role='requestor'")
                ->execute([(int)$_POST['user_id'], $myOfficeId]);
            $_SESSION['flash']['warning'] = "Requestor removed.";
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $_SESSION['flash']['danger'] = "Cannot remove this requestor because they have existing schedules linked to their account. Delete their schedules first before removing.";
            } else {
                $_SESSION['flash']['danger'] = "An unexpected error occurred: " . $e->getMessage();
            }
        }
    }
    header("Location: Requestors.php"); exit;
}

/* ── Fetch requestors ── */
$requestors = $pdo->prepare("
    SELECT u.*, d.dept_name
    FROM users u
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE u.role='requestor' AND u.office_id=?
    ORDER BY u.username ASC
");
$requestors->execute([$myOfficeId]);
$requestors = $requestors->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Requestors – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:#f5f0f0;font-family:'Segoe UI',sans-serif}

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

/* ── Cards / Tables ── */
.section-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.07);overflow:hidden}
.section-header{padding:1rem 1.25rem;border-bottom:1px solid #f0e5e5;font-weight:700;font-size:.9rem;color:#800000;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.table thead th{background:#fdf5f5;color:#800000;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid #f0e5e5;padding:.75rem 1rem;white-space:nowrap}
.table tbody td{padding:.7rem 1rem;font-size:.85rem;color:#444;vertical-align:middle;border-color:#fdf5f5}
.table tbody tr:hover{background:#fdf8f8}
.btn-maroon{background:#800000;color:#fff;border:none}
.btn-maroon:hover{background:#6b0000;color:#fff}
.mh-maroon{background:linear-gradient(135deg,#800000,#6b0000);color:#fff}
.mh-maroon .btn-close{filter:invert(1)}
.mh-red{background:linear-gradient(135deg,#a00000,#800000);color:#fff}
.mh-red .btn-close{filter:invert(1)}
.pass-toggle{cursor:pointer;border-left:none}

/* ── Avatar initial bubble ── */
.user-initials{width:30px;height:30px;border-radius:8px;background:#fdecea;color:#800000;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0}

/* ── Dept badge ── */
.dept-badge{background:#fdf5f5;color:#800000;padding:2px 8px;border-radius:6px;font-size:.78rem;font-weight:600;display:inline-block}

/* ── Mobile Requestor Cards ── */
.requestor-card-list{display:none}

/* ── MOBILE BREAKPOINT ── */
@media (max-width: 768px) {
    .hamburger-btn{display:flex;align-items:center}
    .sidebar{transform:translateX(-100%);transition:transform 0.25s ease;z-index:200;position:fixed !important;top:0;left:0;height:100vh;overflow-y:auto}
    .sidebar.open{transform:translateX(0) !important}
    .topbar{margin-left:0 !important}
    .main-content{margin-left:0 !important;padding:1rem}
    .topbar-title{font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .topbar-title i{display:none}
    .topbar-user > div > div:last-child{display:none}
    .section-header{flex-wrap:wrap;gap:.5rem}
    .table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .modal-dialog{margin:auto 0 0;max-width:100%}
    .modal-content{border-radius:16px 16px 0 0 !important}

    /* Hide these columns on mobile only */
    .table th:nth-child(3),
    .table td:nth-child(3),
    .table th:nth-child(5),
    .table td:nth-child(5),
    .table th:nth-child(6),
    .table td:nth-child(6){display:none}

    /* Switch to card layout on mobile */
    .desktop-requestor-table{display:none !important}
    .requestor-card-list{display:flex;flex-direction:column;gap:10px;padding:12px}
    .requestor-card{background:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 1px 6px rgba(128,0,0,0.07);display:flex;align-items:center;gap:12px}
    .requestor-card-icon{width:40px;height:40px;border-radius:10px;background:#fdecea;color:#800000;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
    .requestor-card-body{flex:1;min-width:0}
    .requestor-card-name{font-weight:700;font-size:14px;color:#222;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .requestor-card-badge{font-size:11px;color:#800000;background:#fdecea;border-radius:6px;padding:1px 7px;font-weight:600;display:inline-block;margin-top:3px}
    .requestor-card-dept{font-size:11px;color:#666;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .requestor-card-actions{display:flex;gap:6px;flex-shrink:0}
    .requestor-card-actions .btn{padding:5px 9px;font-size:13px}
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
    <a class="nav-link active" href="Requestors.php"><i class="bi bi-people"></i> Requestors</a>
    <div class="nav-section-label">Scheduling</div>
    <a class="nav-link" href="WalkIn.php"><i class="bi bi-calendar-plus"></i> Walk-in Booking</a>
    <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i> View Schedules</a>
    <a class="nav-link" href="CheckAvailability.php"><i class="bi bi-search"></i> Check Availability</a>
    <a class="nav-link" href="staff_driverstripcomplete.php"><i class="bi bi-flag-fill"></i> Driver Trip Records</a>
    <div class="nav-section-label">Account</div>
    <a class="nav-link" href="notification.php">
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
  <div class="topbar-title"><i class="bi bi-people me-2"></i>Requestors</div>
  <div class="topbar-user">
    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
    <div>
      <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
      <div style="font-size:.72rem;color:#800000">Staff — <?= htmlspecialchars($me['office_name'] ?? '—') ?></div>
    </div>
  </div>
</div>

<!-- ── Main Content ── -->
<div class="main-content">

<?php
$icons=['success'=>'check-circle','danger'=>'x-circle','warning'=>'exclamation-triangle'];
$borders=['success'=>'#0f5132','danger'=>'#842029','warning'=>'#856404'];
foreach(['success','danger','warning'] as $t):
    if(!empty($_SESSION['flash'][$t])):
?>
<div class="alert alert-<?=$t?> alert-dismissible fade show mb-3" style="font-size:.87rem;border-radius:10px;border-left:4px solid <?=$borders[$t]?>">
    <i class="bi bi-<?=$icons[$t]?> me-2"></i><?=htmlspecialchars($_SESSION['flash'][$t])?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash'][$t]); endif; endforeach; ?>

<div class="section-card">
  <div class="section-header">
    <span>
      <i class="bi bi-people me-2"></i>Requestors
      <small class="ms-2" style="font-size:.75rem;color:#a05050;font-weight:400">
        <i class="bi bi-building me-1"></i><?= htmlspecialchars($me['office_name'] ?? '') ?>
      </small>
    </span>
    <button class="btn btn-maroon btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="bi bi-person-plus me-1"></i>Add Requestor
    </button>
  </div>

  <!-- ── Desktop Table ── -->
  <div class="table-responsive desktop-requestor-table">
    <table class="table mb-0" style="min-width:750px">
      <thead>
        <tr>
          <th style="width:44px">#</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Department</th>
          <th>Office</th>
          <th>Created</th>
          <th class="text-end pe-4" style="width:110px;white-space:nowrap">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($requestors as $i => $r): ?>
        <tr>
          <td style="color:#bbb;font-size:.78rem;font-weight:600"><?= $i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div class="user-initials"><?= strtoupper(substr($r['username'],0,1)) ?></div>
              <span class="fw-semibold" style="color:#222"><?= htmlspecialchars($r['username']) ?></span>
            </div>
          </td>
          <td style="font-size:.83rem;color:#666">
            <?php if (!empty($r['email'])): ?>
              <a href="mailto:<?= htmlspecialchars($r['email']) ?>" style="color:#800000;text-decoration:none"><?= htmlspecialchars($r['email']) ?></a>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <?php if(!empty($r['dept_name'])): ?>
              <span class="dept-badge"><?= htmlspecialchars($r['dept_name']) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td style="font-size:.83rem;color:#555"><?= htmlspecialchars($me['office_name'] ?? '—') ?></td>
          <td style="font-size:.78rem;color:#aaa"><?= !empty($r['created_at']) ? date('M j, Y', strtotime($r['created_at'])) : '—' ?></td>
          <td class="text-end pe-4">
            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px">
              <button class="btn btn-sm btn-outline-secondary btn-edit"
                data-id="<?= $r['user_id'] ?>"
                data-username="<?= htmlspecialchars($r['username'],ENT_QUOTES) ?>"
                data-email="<?= htmlspecialchars($r['email'] ?? '',ENT_QUOTES) ?>"
                data-dept="<?= (int)($r['dept_id']??0) ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger btn-delete"
                data-id="<?= $r['user_id'] ?>"
                data-username="<?= htmlspecialchars($r['username'],ENT_QUOTES) ?>">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($requestors)):?>
        <tr><td colspan="7" class="text-center text-muted py-4">
          <i class="bi bi-people fs-4 d-block mb-2 opacity-50"></i>No requestors yet. Add one to get started.
        </td></tr>
        <?php endif;?>
      </tbody>
    </table>
  </div>

  <!-- ── Mobile Cards ── -->
  <div class="requestor-card-list">
    <?php foreach($requestors as $i => $r): ?>
    <div class="requestor-card">
      <div class="requestor-card-icon"><i class="bi bi-person"></i></div>
      <div class="requestor-card-body">
        <div class="requestor-card-name"><?= htmlspecialchars($r['username']) ?></div>
        <span class="requestor-card-badge">#<?= $r['user_id'] ?></span>
        <?php if (!empty($r['dept_name'])): ?>
        <div class="requestor-card-dept">
          <i class="bi bi-diagram-3" style="font-size:10px"></i>
          <?= htmlspecialchars($r['dept_name']) ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="requestor-card-actions">
        <button class="btn btn-sm btn-outline-secondary btn-edit"
          data-id="<?= $r['user_id'] ?>"
          data-username="<?= htmlspecialchars($r['username'], ENT_QUOTES) ?>"
          data-email="<?= htmlspecialchars($r['email'] ?? '', ENT_QUOTES) ?>"
          data-dept="<?= (int)($r['dept_id'] ?? 0) ?>">
          <i class="bi bi-pencil"></i>
        </button>
        <button class="btn btn-sm btn-outline-danger btn-delete"
          data-id="<?= $r['user_id'] ?>"
          data-username="<?= htmlspecialchars($r['username'], ENT_QUOTES) ?>">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($requestors)): ?>
    <div style="text-align:center;padding:2rem;color:#bbb;font-size:.85rem">
      <i class="bi bi-people" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.3"></i>
      No requestors yet. Add one to get started.
    </div>
    <?php endif; ?>
  </div>

</div><!-- /section-card -->
</div><!-- /main-content -->

<!-- ── ADD MODAL ── -->
<div class="modal fade" id="addModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header mh-maroon">
    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Requestor</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <form method="POST" action="Requestors.php" id="addForm" novalidate>
    <input type="hidden" name="action" value="add">
    <div class="modal-body">
      <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.82rem">
        <i class="bi bi-building me-1"></i>Account will be created under <strong><?= htmlspecialchars($me['office_name'] ?? '') ?></strong>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="username" class="form-control" required placeholder="e.g. Juan Dela Cruz" maxlength="100">
        <div class="invalid-feedback">Please enter full name.</div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Email <span class="text-muted fw-normal" style="font-size:.78rem">(optional)</span></label>
        <div class="input-group">
          <span class="input-group-text" style="background:#fdf5f5;color:#800000"><i class="bi bi-envelope"></i></span>
          <input type="email" name="email" class="form-control" placeholder="e.g. juan@csu.edu.ph" maxlength="150">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="password" name="password" id="add_pass" class="form-control" required placeholder="Enter password">
          <span class="input-group-text pass-toggle" onclick="togglePass('add_pass',this)"><i class="bi bi-eye"></i></span>
        </div>
        <div class="invalid-feedback">Please enter a password.</div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Department</label>
        <select name="department_id" class="form-select">
          <option value="">— Select Department —</option>
          <?php foreach($depts as $d):?>
          <option value="<?=$d['dept_id']?>"><?=htmlspecialchars($d['dept_name'])?></option>
          <?php endforeach;?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-maroon"><i class="bi bi-person-check me-1"></i>Create Account</button>
    </div>
  </form>
</div></div></div>

<!-- ── EDIT MODAL ── -->
<div class="modal fade" id="editModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header mh-maroon">
    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Requestor</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <form method="POST" action="Requestors.php">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="user_id" id="edit_uid">
    <div class="modal-body">
      <div class="mb-3">
        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
        <input type="text" name="username" id="edit_uname" class="form-control" required>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Email <span class="text-muted fw-normal" style="font-size:.78rem">(optional)</span></label>
        <div class="input-group">
          <span class="input-group-text" style="background:#fdf5f5;color:#800000"><i class="bi bi-envelope"></i></span>
          <input type="email" name="email" id="edit_email" class="form-control" placeholder="e.g. juan@csu.edu.ph" maxlength="150">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">New Password <small class="text-muted fw-normal">(leave blank to keep)</small></label>
        <div class="input-group">
          <input type="password" name="password" id="edit_pass" class="form-control" placeholder="Leave blank to keep current">
          <span class="input-group-text pass-toggle" onclick="togglePass('edit_pass',this)"><i class="bi bi-eye"></i></span>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Department</label>
        <select name="department_id" id="edit_dept" class="form-select">
          <option value="">— Select Department —</option>
          <?php foreach($depts as $d):?>
          <option value="<?=$d['dept_id']?>"><?=htmlspecialchars($d['dept_name'])?></option>
          <?php endforeach;?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-maroon"><i class="bi bi-save me-1"></i>Save Changes</button>
    </div>
  </form>
</div></div></div>

<!-- ── DELETE MODAL ── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content">
  <div class="modal-header mh-red">
    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Remove Requestor</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <form method="POST" action="Requestors.php">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="del_uid">
    <div class="modal-body text-center py-3">
      <i class="bi bi-exclamation-triangle-fill text-warning fs-2 mb-2 d-block"></i>
      <p class="mb-1" style="font-size:.88rem">Remove requestor account</p>
      <div class="fw-bold" id="del_uname" style="color:#800000"></div>
      <p class="text-muted mt-2 mb-0" style="font-size:.8rem">This cannot be undone.</p>
    </div>
    <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Remove</button>
    </div>
  </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

document.addEventListener('click', e => {
    const editBtn = e.target.closest('.btn-edit');
    if (editBtn) {
        document.getElementById('edit_uid').value   = editBtn.dataset.id;
        document.getElementById('edit_uname').value = editBtn.dataset.username;
        document.getElementById('edit_email').value = editBtn.dataset.email;
        document.getElementById('edit_dept').value  = editBtn.dataset.dept;
        document.getElementById('edit_pass').value  = '';
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }
    const delBtn = e.target.closest('.btn-delete');
    if (delBtn) {
        document.getElementById('del_uid').value         = delBtn.dataset.id;
        document.getElementById('del_uname').textContent = delBtn.dataset.username;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
});

function togglePass(id, el) {
    const inp = document.getElementById(id);
    const icon = el.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text'; icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password'; icon.className = 'bi bi-eye';
    }
}

document.getElementById('addForm').addEventListener('submit', function(e){
    if(!this.checkValidity()){e.preventDefault();this.classList.add('was-validated');}
});
</script>
</body>
</html>