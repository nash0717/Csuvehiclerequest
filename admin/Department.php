<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

// Get current admin's office_id from session or users table
$current_user_office_id = $_SESSION['office_id'] ?? null;

if (!$current_user_office_id) {
    $stmt = $pdo->prepare("SELECT office_id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
    $current_user_office_id = $current_user['office_id'] ?? null;
    $_SESSION['office_id'] = $current_user_office_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

   if ($action === 'add') {
        $dept_name = sanitize($_POST['dept_name']);
        $office_id = $current_user_office_id;
        $dupCheck = $pdo->prepare("SELECT dept_id FROM departments WHERE dept_name = ? AND office_id = ?");
        $dupCheck->execute([$dept_name, $office_id]);
        if ($dupCheck->fetch()) {
            setFlash('danger', 'A department with that name already exists in your office.');
        } else {
            $pdo->prepare("INSERT INTO departments (dept_name, office_id) VALUES (?, ?)")
                ->execute([$dept_name, $office_id]);
            setFlash('success', 'Department added successfully.');
        }

    } elseif ($action === 'edit') {
        $dept_id   = (int)$_POST['dept_id'];
        $dept_name = sanitize($_POST['dept_name']);
        $check = $pdo->prepare("SELECT dept_id FROM departments WHERE dept_id = ? AND office_id = ?");
        $check->execute([$dept_id, $current_user_office_id]);
        if ($check->fetch()) {
            $dupCheck = $pdo->prepare("SELECT dept_id FROM departments WHERE dept_name = ? AND office_id = ? AND dept_id != ?");
            $dupCheck->execute([$dept_name, $current_user_office_id, $dept_id]);
            if ($dupCheck->fetch()) {
                setFlash('danger', 'A department with that name already exists in your office.');
            } else {
                $pdo->prepare("UPDATE departments SET dept_name=? WHERE dept_id=? AND office_id=?")
                    ->execute([$dept_name, $dept_id, $current_user_office_id]);
                setFlash('success', 'Department updated successfully.');
            }
        } else {
            setFlash('danger', 'You are not allowed to edit that department.');
        }

    } elseif ($action === 'delete') {
        $dept_id = (int)$_POST['dept_id'];
        $check = $pdo->prepare("SELECT dept_id FROM departments WHERE dept_id = ? AND office_id = ?");
        $check->execute([$dept_id, $current_user_office_id]);
        if ($check->fetch()) {

            $usedByUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE dept_id = ?");
            $usedByUsers->execute([$dept_id]);

            $usedBySchedules = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE department_id = ?");
            $usedBySchedules->execute([$dept_id]);

            if ((int)$usedByUsers->fetchColumn() > 0) {
                setFlash('danger', 'Cannot delete: this department has users assigned to it.');
            } elseif ((int)$usedBySchedules->fetchColumn() > 0) {
                setFlash('danger', 'Cannot delete: this department is used in existing schedules.');
            } else {
                $pdo->prepare("DELETE FROM departments WHERE dept_id = ? AND office_id = ?")
                    ->execute([$dept_id, $current_user_office_id]);
                setFlash('success', 'Department deleted.');
            }

        } else {
            setFlash('danger', 'You are not allowed to delete that department.');
        }
    }

    header("Location: Department.php");
    exit;
}

if ($current_user_office_id) {
    $stmt = $pdo->prepare("
        SELECT d.*, o.office_name
        FROM departments d
        LEFT JOIN offices o ON o.office_id = d.office_id
        WHERE d.office_id = ?
        ORDER BY d.dept_name
    ");
    $stmt->execute([$current_user_office_id]);
    $departments = $stmt->fetchAll();
} else {
    $departments = [];
}

$current_office = null;
if ($current_user_office_id) {
    $stmt = $pdo->prepare("SELECT office_id, office_name FROM offices WHERE office_id = ?");
    $stmt->execute([$current_user_office_id]);
    $current_office = $stmt->fetch();
}

$_notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$_notifStmt->execute([$_SESSION['user_id']]);
$_sidebarUnread = (int)$_notifStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments – CSU VSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; }

        /* ══ SIDEBAR ══ */
        .sidebar { min-height:100vh; background:linear-gradient(180deg,#800000 0%,#6b0000 100%); width:240px; position:fixed; top:0; left:0; z-index:200; display:flex; flex-direction:column; transition:transform 0.25s ease; }
        .sidebar-brand { padding:1.25rem 1rem 1rem; border-bottom:1px solid rgba(255,255,255,0.15); display:flex; align-items:center; gap:10px; }
        .sidebar-logo { width:42px; height:42px; border-radius:50%; background:#fff; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .sidebar-logo img { width:38px; height:38px; object-fit:contain; }
        .sidebar-brand-text { color:#fff; font-size:0.82rem; font-weight:700; line-height:1.3; }
        .sidebar-brand-text span { display:block; font-size:0.72rem; font-weight:400; opacity:0.7; }
        .sidebar .nav-link { color:rgba(255,255,255,0.8); padding:0.6rem 1.25rem; font-size:0.88rem; display:flex; align-items:center; gap:10px; border-left:3px solid transparent; transition:all 0.15s; }
        .sidebar .nav-link:hover { color:#fff; background:rgba(255,255,255,0.1); border-left-color:rgba(255,255,255,0.4); }
        .sidebar .nav-link.active { color:#fff; background:rgba(255,255,255,0.15); border-left-color:#fff; font-weight:600; }
        .sidebar .nav-link i { font-size:1rem; width:18px; }
        .sidebar-divider { border-color:rgba(255,255,255,0.15); margin:0.5rem 1rem; }
        .nav-section-label { padding:0.75rem 1.25rem 0.25rem; font-size:0.68rem; font-weight:700; color:rgba(255,255,255,0.45); letter-spacing:0.08em; text-transform:uppercase; }

        /* ══ SIDEBAR OVERLAY ══ */
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:199; }
        .sidebar-overlay.show { display:block; }

        /* ══ HAMBURGER ══ */
        .hamburger-btn { display:none; background:none; border:none; cursor:pointer; padding:4px 8px; color:#800000; font-size:1.4rem; line-height:1; }
        @media (max-width: 768px) {
            .hamburger-btn { display:flex !important; align-items:center; justify-content:center; }
        }

        /* ══ TOPBAR ══ */
        .topbar { background:#fff; border-bottom:1px solid #e8dede; padding:0.7rem 1.5rem; margin-left:240px; position:sticky; top:0; z-index:99; display:flex; align-items:center; justify-content:space-between; }
        .topbar-title { font-weight:700; font-size:1rem; color:#800000; }
        .topbar-user { display:flex; align-items:center; gap:8px; }
        .user-avatar { width:32px; height:32px; border-radius:50%; background:#800000; color:#fff; display:flex; align-items:center; justify-content:center; font-size:0.8rem; font-weight:700; }

        /* ══ MAIN ══ */
        .main-content { margin-left:240px; padding:1.5rem; }

        /* ══ Office context banner ══ */
        .office-context-banner { background:linear-gradient(135deg,#800000 0%,#6b0000 100%); color:#fff; border-radius:12px; padding:0.85rem 1.25rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:10px; font-size:0.85rem; flex-wrap:wrap; }
        .office-context-banner i { font-size:1.1rem; opacity:0.85; }
        .office-context-banner strong { font-size:0.92rem; }
        .office-context-banner .note { margin-left:auto; font-size:0.75rem; opacity:0.75; background:rgba(255,255,255,0.15); border-radius:20px; padding:3px 10px; white-space:nowrap; }

        /* ══ Card ══ */
        .section-card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(128,0,0,0.07); overflow:hidden; }
        .section-header { padding:1rem 1.25rem; border-bottom:1px solid #f0e5e5; font-weight:700; font-size:0.9rem; color:#800000; display:flex; align-items:center; justify-content:space-between; gap:.5rem; flex-wrap:wrap; }

        /* ══ Table ══ */
        .table thead th { background:#fdf5f5; color:#800000; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; border-bottom:2px solid #f0e5e5; padding:0.75rem 1rem; }
        .table tbody td { padding:0.7rem 1rem; font-size:0.85rem; color:#444; vertical-align:middle; border-color:#fdf5f5; }
        .table tbody tr:hover { background:#fdf8f8; }

        /* ══ Badges ══ */
        .dept-id-badge { color:#800000; font-weight:700; font-size:0.8rem; background:#fdecea; border-radius:6px; padding:2px 8px; display:inline-block; }
        .office-badge { background:#fdecea; color:#800000; border-radius:20px; padding:3px 10px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .dept-name-cell { color:#333; font-weight:600; font-size:0.9rem; background:linear-gradient(135deg,#fff 0%,#fdf8f8 100%); border-radius:8px; padding:0.5rem 0.75rem; border:1px solid #f0e5e5; transition:all 0.15s ease; }
        .dept-name-cell:hover { background:linear-gradient(135deg,#fdf8f8 0%,#fdecea 100%); border-color:#e8dede; }

        /* ══ Buttons & Forms ══ */
        .btn-maroon { background:#800000; color:#fff; border:none; }
        .btn-maroon:hover { background:#6b0000; color:#fff; }
        .form-control:focus, .form-select:focus { border-color:#800000; box-shadow:0 0 0 0.2rem rgba(128,0,0,0.12); }

        /* ══ Modals ══ */
        .modal-header { background:linear-gradient(135deg,#800000,#6b0000); color:#fff; }
        .modal-header .btn-close { filter:invert(1); }
        .modal-content { border-radius:14px; border:none; }
        .form-label { font-size:0.82rem; font-weight:600; color:#555; }
        .office-locked { background:#fdf5f5; border:1px solid #f0e5e5; border-radius:8px; padding:0.5rem 0.85rem; font-size:0.85rem; color:#800000; font-weight:600; display:flex; align-items:center; gap:6px; }
        .office-locked i { opacity:0.7; }
        .office-locked small { font-weight:400; color:#999; font-size:0.72rem; margin-left:auto; }

        /* ══ Mobile dept cards (hidden on desktop) ══ */
        .dept-card-list { display:none; }

        /* ══ MOBILE ══ */
        @media (max-width: 768px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); }
            .topbar, .main-content { margin-left:0 !important; }
            .desktop-dept-table { display:none !important; }
            .dept-card-list { display:flex; flex-direction:column; gap:10px; padding:12px; }
            .dept-card { background:#fff; border-radius:12px; padding:12px 14px; box-shadow:0 1px 6px rgba(128,0,0,0.07); display:flex; align-items:center; gap:12px; }
            .dept-card-icon { width:40px; height:40px; border-radius:10px; background:#fdecea; color:#800000; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
            .dept-card-body { flex:1; min-width:0; }
            .dept-card-name { font-weight:700; font-size:14px; color:#222; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .dept-card-id { font-size:11px; color:#800000; background:#fdecea; border-radius:6px; padding:1px 7px; font-weight:600; display:inline-block; margin-top:3px; }
            .dept-card-office { font-size:11px; color:#666; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .dept-card-actions { display:flex; gap:6px; flex-shrink:0; }
            .dept-card-actions .btn { padding:5px 9px; font-size:13px; }
            .office-context-banner .note { margin-left:0; }
        }

        @media (max-width: 480px) {
            .main-content { padding:1rem; }
            .topbar { padding:.6rem 1rem; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ════════ SIDEBAR ════════ -->
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
        <a class="nav-link" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i>Driver Trip Records</a>
        <a class="nav-link" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link" href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="nav-link active" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="nav-section-label">Scheduling</div>
        <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="nav-section-label">Settings</div>
        <a class="nav-link" href="notification.php" style="justify-content:space-between">
            <span style="display:flex;align-items:center;gap:10px">
                <i class="bi bi-bell"></i>Notifications
            </span>
            <?php if ($_sidebarUnread > 0): ?>
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

<!-- ════════ TOPBAR ════════ -->
<div class="topbar">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-diagram-3 me-2"></i>Departments</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:0.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:0.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<!-- ════════ MAIN ════════ -->
<div class="main-content">
    <?php showFlash(); ?>

    <?php if ($current_office): ?>
    <div class="office-context-banner">
        <i class="bi bi-building-check"></i>
        <div>Showing departments for: <strong><?= htmlspecialchars($current_office['office_name']) ?></strong></div>
        <span class="note"><i class="bi bi-lock-fill me-1"></i>Scoped to your office</span>
    </div>
    <?php elseif (!$current_user_office_id): ?>
    <div class="alert alert-warning rounded-3 d-flex align-items-center gap-2" style="font-size:0.85rem">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Your account is not assigned to any office. Please contact a super admin.
    </div>
    <?php endif; ?>

    <div class="section-card">
        <div class="section-header">
            <span><i class="bi bi-diagram-3 me-2"></i>All Departments (<?= count($departments) ?>)</span>
            <?php if ($current_user_office_id): ?>
            <button class="btn btn-maroon btn-sm rounded-3" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg me-1"></i>Add Department
            </button>
            <?php endif; ?>
        </div>

        <!-- Desktop table -->
        <div class="table-responsive desktop-dept-table">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th style="width:70px">#</th>
                        <th>Department Name</th>
                        <th style="width:200px">Office</th>
                        <th style="width:110px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $d): ?>
                <tr>
                    <td><span class="dept-id-badge">#<?= $d['dept_id'] ?></span></td>
                    <td><div class="dept-name-cell"><?= htmlspecialchars($d['dept_name']) ?></div></td>
                    <td>
                        <?php if ($d['office_name']): ?>
                            <span class="office-badge">
                                <i class="bi bi-building" style="font-size:0.7rem"></i>
                                <?= htmlspecialchars($d['office_name']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <button class="btn btn-sm btn-outline-secondary me-1"
                            onclick="openEdit(<?= $d['dept_id'] ?>, '<?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>')">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                            onclick="openDelete(<?= $d['dept_id'] ?>, '<?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($departments)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:1.8rem;opacity:0.3;display:block;margin-bottom:6px"></i>
                        No departments found for your office.
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="dept-card-list">
            <?php foreach ($departments as $d): ?>
            <div class="dept-card">
                <div class="dept-card-icon"><i class="bi bi-diagram-3"></i></div>
                <div class="dept-card-body">
                    <div class="dept-card-name"><?= htmlspecialchars($d['dept_name']) ?></div>
                    <span class="dept-card-id">#<?= $d['dept_id'] ?></span>
                    <?php if ($d['office_name']): ?>
                    <div class="dept-card-office"><i class="bi bi-building" style="font-size:10px"></i> <?= htmlspecialchars($d['office_name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="dept-card-actions">
                    <button class="btn btn-sm btn-outline-secondary"
                        onclick="openEdit(<?= $d['dept_id'] ?>, '<?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>')">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger"
                        onclick="openDelete(<?= $d['dept_id'] ?>, '<?= htmlspecialchars($d['dept_name'], ENT_QUOTES) ?>')">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($departments)): ?>
            <div style="text-align:center;padding:2rem;color:#bbb;font-size:.85rem">
                <i class="bi bi-diagram-3" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.3"></i>
                No departments found for your office.
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ════════ ADD MODAL ════════ -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i>Add Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" name="dept_name" class="form-control" required maxlength="120" placeholder="Enter department name">
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Office</label>
                        <div class="office-locked">
                            <i class="bi bi-building-fill"></i>
                            <?= htmlspecialchars($current_office['office_name'] ?? 'Not assigned') ?>
                            <small><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
                        </div>
                        <div class="form-text text-muted mt-1" style="font-size:0.75rem">
                            Departments are automatically assigned to your office.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-light btn-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon btn-sm rounded-3">
                        <i class="bi bi-check-lg me-1"></i>Add Department
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════ EDIT MODAL ════════ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="dept_id" id="edit_dept_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" name="dept_name" id="edit_dept_name" class="form-control" required maxlength="120">
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Office</label>
                        <div class="office-locked">
                            <i class="bi bi-building-fill"></i>
                            <?= htmlspecialchars($current_office['office_name'] ?? 'Not assigned') ?>
                            <small><i class="bi bi-lock-fill me-1"></i>Auto-assigned</small>
                        </div>
                        <div class="form-text text-muted mt-1" style="font-size:0.75rem">
                            Office cannot be changed — it is locked to your account.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                    <button type="button" class="btn btn-light btn-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon btn-sm rounded-3">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════ DELETE MODAL ════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content" style="border-radius:14px;border:none;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#c0392b,#a93226);padding:1rem 1.25rem">
                <h5 class="modal-title text-white fw-bold">
                    <i class="bi bi-trash me-2"></i>Delete Department
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
            </div>
            <div class="modal-body text-center py-4 px-4">
                <div style="font-size:2.8rem;margin-bottom:.75rem">⚠️</div>
                <p class="mb-1" style="color:#555;font-size:.95rem">Delete department</p>
                <p class="fw-bold fs-5 mb-2" style="color:#c0392b" id="deleteDeptName">—</p>
                <p class="text-muted" style="font-size:.85rem">This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4 gap-2">
                <button type="button" class="btn btn-secondary btn-sm px-4 rounded-3" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="dept_id" id="deleteDeptId">
                    <button type="submit" class="btn btn-danger btn-sm px-4 rounded-3">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}

function openEdit(id, name) {
    document.getElementById('edit_dept_id').value   = id;
    document.getElementById('edit_dept_name').value = name;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function openDelete(id, name) {
    document.getElementById('deleteDeptId').value        = id;
    document.getElementById('deleteDeptName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>