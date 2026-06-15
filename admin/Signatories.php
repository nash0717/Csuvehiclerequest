<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
if (!isLoggedIn()) {
    header("Location: /csuweb/login.php?error=unauthorized");
    exit;
}

/* ── Auto-migrate ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS office_signatories (
        signatory_id   INT AUTO_INCREMENT PRIMARY KEY,
        office_id      INT NOT NULL,
        signatory_name VARCHAR(200) NOT NULL,
        signatory_title VARCHAR(200) NOT NULL DEFAULT '',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_office (office_id),
        FOREIGN KEY (office_id) REFERENCES offices(office_id) ON DELETE CASCADE
    )");
} catch (PDOException $e) {}

/* ── Current user + their office ── */
$cu = $pdo->prepare("
    SELECT u.*, o.office_id AS u_office_id, o.office_name
    FROM users u
    LEFT JOIN offices o ON u.office_id = o.office_id
    WHERE u.user_id = ?
");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();

$myOfficeId   = (int)($me['u_office_id'] ?? 0);
$myOfficeName = $me['office_name'] ?? '—';
$isSuperAdmin = ($myOfficeId === 0);

/* ── Fetch signatories ── */
if ($isSuperAdmin) {
    $signatories = $pdo->query("
        SELECT s.*, o.office_name
        FROM office_signatories s
        JOIN offices o ON s.office_id = o.office_id
        ORDER BY o.office_name
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT s.*, o.office_name
        FROM office_signatories s
        JOIN offices o ON s.office_id = o.office_id
        WHERE s.office_id = ?
        ORDER BY o.office_name
    ");
    $stmt->execute([$myOfficeId]);
    $signatories = $stmt->fetchAll();
}

/* ── Fetch offices for dropdown ── */
if ($isSuperAdmin) {
    $offices = $pdo->query("SELECT * FROM offices ORDER BY office_name")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM offices WHERE office_id = ? ORDER BY office_name");
    $stmt->execute([$myOfficeId]);
    $offices = $stmt->fetchAll();
}

$assignedOfficeIds = array_column($signatories, 'office_id');

if ($isSuperAdmin) {
    $unassigned = array_filter($offices, fn($o) => !in_array($o['office_id'], $assignedOfficeIds));
} else {
    $unassigned = array_filter($offices, fn($o) =>
        $o['office_id'] === $myOfficeId && !in_array($o['office_id'], $assignedOfficeIds)
    );
}

if (!$isSuperAdmin && !$myOfficeId) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <title>Signatories – CSU VSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh">
      <div class="text-center p-4">
        <i class="bi bi-building-slash fs-1 text-danger opacity-50 d-block mb-3"></i>
        <h5 class="fw-bold text-danger">No Office Assigned</h5>
        <p class="text-muted small">Your account is not linked to any office.<br>Please contact the administrator.</p>
        <a href="../index.php" class="btn btn-sm btn-secondary mt-2">← Go Back</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* ── POST handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $oid   = $isSuperAdmin ? (int)$_POST['office_id'] : $myOfficeId;
        $name  = trim(sanitize($_POST['signatory_name'] ?? ''));
        $title = trim(sanitize($_POST['signatory_title'] ?? ''));

        if ($name === '') {
            $_SESSION['flash']['danger'] = "Signatory name is required.";
        } elseif (!$oid) {
            $_SESSION['flash']['danger'] = "Please select a valid office.";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO office_signatories (office_id, signatory_name, signatory_title)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        signatory_name  = VALUES(signatory_name),
                        signatory_title = VALUES(signatory_title),
                        updated_at      = NOW()
                ")->execute([$oid, $name, $title]);
                $_SESSION['flash']['success'] = "Signatory saved successfully.";
            } catch (PDOException $e) {
                $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
            }
        }

    } elseif ($action === 'delete') {
        try {
            if ($isSuperAdmin) {
                $pdo->prepare("DELETE FROM office_signatories WHERE signatory_id = ?")
                    ->execute([(int)$_POST['signatory_id']]);
            } else {
                $pdo->prepare("DELETE FROM office_signatories WHERE signatory_id = ? AND office_id = ?")
                    ->execute([(int)$_POST['signatory_id'], $myOfficeId]);
            }
            $_SESSION['flash']['warning'] = "Signatory removed.";
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }
    }

    header("Location: Signatories.php"); exit;
}

/* ── Single signatory for scoped admin ── */
$signatory = null;
if (!$isSuperAdmin) {
    $sig = $pdo->prepare("SELECT * FROM office_signatories WHERE office_id = ?");
    $sig->execute([$myOfficeId]);
    $signatory = $sig->fetch();
}

/* ── Notif count for sidebar badge ── */
$_notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$_notifStmt->execute([$_SESSION['user_id']]);
$_sidebarUnread = (int)$_notifStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Signatory – CSU VSS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:#f5f0f0;font-family:'Segoe UI',sans-serif}

/* ══ SIDEBAR ══ */
.sidebar{
    min-height:100vh;
    background:linear-gradient(180deg,#800000,#6b0000);
    width:240px;position:fixed;top:0;left:0;
    z-index:400;display:flex;flex-direction:column;
    transition:transform .25s ease;
}
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

/* ══ SIDEBAR OVERLAY ══ */
.sidebar-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,.45);z-index:399;
}
.sidebar-overlay.show{display:block}

/* ══ TOPBAR ══ */
.topbar{
    background:#fff;border-bottom:1px solid #e8dede;
    padding:.7rem 1.5rem;margin-left:240px;
    position:sticky;top:0;z-index:99;
    display:flex;align-items:center;justify-content:space-between;
}
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px;font-size:.85rem;color:#666}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}

/* Hamburger – hidden on desktop */
.hamburger-btn{
    display:none;background:none;border:none;cursor:pointer;
    padding:4px 8px;color:#800000;font-size:1.4rem;
    align-items:center;line-height:1;
}

/* ══ MAIN ══ */
.main-content{margin-left:240px;padding:1.5rem}
.section-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.07);overflow:hidden;margin-bottom:1.5rem}
.section-header{padding:1rem 1.25rem;border-bottom:1px solid #f0e5e5;font-weight:700;font-size:.9rem;color:#800000;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}

/* ══ SIGNATORY DISPLAY ══ */
.sig-display{padding:2rem 1.5rem;display:flex;flex-direction:column;align-items:center;justify-content:center;}
.sig-office-badge{display:inline-flex;align-items:center;gap:6px;background:#800000;color:#fff;border-radius:20px;padding:5px 16px;font-size:.78rem;font-weight:700;letter-spacing:.04em;margin-bottom:1.25rem;box-shadow:0 2px 8px rgba(128,0,0,.18)}
.sig-office-badge i{font-size:.9rem}
.sig-preview-block{display:flex;flex-direction:column;align-items:center;min-width:300px;max-width:380px;width:100%;padding:1.75rem 2rem 1.5rem;border:none;border-radius:16px;background:#fff;box-shadow:0 4px 24px rgba(128,0,0,.10);position:relative;}
.sig-preview-name{font-size:1.08rem;font-weight:800;color:#111;letter-spacing:.03em;text-align:center;margin-bottom:.75rem}
.sig-preview-line{width:80%;border:none;border-top:1.75px solid #333;margin:0 auto .5rem}
.sig-preview-title{font-size:.85rem;color:#555;font-style:italic;text-align:center;margin-bottom:.25rem}
.sig-preview-role{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#800000;text-align:center;margin-top:.25rem}
.sig-updated{font-size:.75rem;color:#aaa;margin-top:1rem;display:flex;align-items:center;gap:4px}
.no-sig-state{padding:2.5rem 1rem;text-align:center;color:#bbb}
.no-sig-state i{font-size:2.5rem;display:block;margin-bottom:.75rem;opacity:.4}
.no-sig-state p{font-size:.85rem;margin:0}

/* ══ TABLE ══ */
.table-sig th{font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#800000;background:#fdf5f5;border-bottom:2px solid #e8dede}
.table-sig td{font-size:.86rem;vertical-align:middle}

/* ══ BUTTONS ══ */
.btn-maroon{background:#800000;color:#fff;border:none}
.btn-maroon:hover{background:#6b0000;color:#fff}
.mh-maroon{background:linear-gradient(135deg,#800000,#6b0000);color:#fff}
.mh-maroon .btn-close{filter:invert(1)}
.mh-red{background:linear-gradient(135deg,#a00000,#800000);color:#fff}
.mh-red .btn-close{filter:invert(1)}

/* ══ MOBILE RESPONSIVE ══ */
@media (max-width: 900px) {
    .sidebar {
        transform: translateX(-100%);
    }
    .sidebar.open {
        transform: translateX(0);
    }
    .topbar,
    .main-content {
        margin-left: 0 !important;
    }
    .hamburger-btn {
        display: flex !important;
    }
    .topbar {
        padding: .6rem 1rem;
        gap: 8px;
    }
    .topbar .topbar-title { flex: 1; }
    .main-content { padding: .85rem; }

    /* Section header */
    .section-header { padding: .75rem 1rem; font-size: .82rem; }

    /* Signature preview block: full width on mobile */
    .sig-preview-block {
        min-width: unset;
        max-width: 100%;
        padding: 1.25rem 1rem;
    }
    .sig-preview-name { font-size: .95rem; }
    .sig-display { padding: 1.25rem 1rem; }
    .sig-office-badge { font-size: .72rem; padding: 4px 12px; }

    /* Table: horizontal scroll */
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .table-sig th, .table-sig td { font-size: .77rem; padding: .5rem .65rem; }

    /* Action buttons in table: always visible */
    .table-sig td .btn { padding: 3px 7px; font-size: .72rem; }

    /* How it works steps */
    .d-flex.gap-3.mb-3 { gap: .75rem !important; }

    /* Alert info banner */
    .alert { font-size: .8rem; }

    /* Form modal: full width */
    .modal-dialog { margin: .5rem; }

    /* Flash messages */
    .alert-dismissible { font-size: .82rem; }

    /* Topbar user: hide text on very small */
    .topbar-user > div:last-child { display: none; }
}

@media (max-width: 480px) {
    .main-content { padding: .65rem; }
    .section-header { font-size: .78rem; }
    .sig-preview-name { font-size: .88rem; }
    .sig-preview-title { font-size: .78rem; }
    .table-sig th, .table-sig td { font-size: .72rem; padding: .4rem .5rem; }
}
</style>
</head>
<body>

<!-- ══ SIDEBAR OVERLAY ══ -->
<div class="sidebar-overlay" id="sigSidebarOverlay" onclick="toggleSigSidebar()"></div>

<!-- ══ SIDEBAR ══ -->
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
        <a class="nav-link " href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="nav-link" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="nav-section-label">Scheduling</div>
        <a class="nav-link" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="nav-section-label">Settings</div>
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
        <a class="nav-link active" href="Signatories.php"><i class="bi bi-pen"></i>Signatories</a>
        <hr class="sidebar-divider">
        <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>
    </nav>
</div>

<!-- ══ TOPBAR ══ -->
<div class="topbar">
    <button class="hamburger-btn" onclick="toggleSigSidebar()" aria-label="Menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-pen me-2"></i>Office Signatory</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:.72rem;color:#800000">
                <?= $isSuperAdmin ? 'Super Admin — All Offices' : htmlspecialchars($myOfficeName) ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ MAIN CONTENT ══ -->
<div class="main-content">

<!-- Flash messages -->
<?php
$icons  = ['success'=>'check-circle','danger'=>'x-circle','warning'=>'exclamation-triangle','info'=>'info-circle'];
$borders= ['success'=>'#0f5132','danger'=>'#842029','warning'=>'#856404','info'=>'#055160'];
foreach(['success','danger','warning','info'] as $type):
    if(!empty($_SESSION['flash'][$type])):
?>
<div class="alert alert-<?=$type?> alert-dismissible fade show mb-3" role="alert"
     style="font-size:.87rem;border-radius:10px;border-left:4px solid <?=$borders[$type]?>">
    <i class="bi bi-<?=$icons[$type]?> me-2"></i><?=htmlspecialchars($_SESSION['flash'][$type])?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash'][$type]); endif; endforeach; ?>

<!-- Info banner -->
<div class="alert alert-info mb-3 py-2 px-3" style="font-size:.83rem;border-radius:10px">
    <i class="bi bi-info-circle me-1"></i>
    <?php if ($isSuperAdmin): ?>
        You are managing signatories for <strong>all offices</strong>. Each office may have one Approving Authority signatory.
    <?php else: ?>
        This is the <strong>Approving Authority</strong> signatory for <strong><?= htmlspecialchars($myOfficeName) ?></strong>.
        It will appear at the bottom of every trip ticket generated by your office.
    <?php endif; ?>
</div>

<?php if ($isSuperAdmin): ?>
<!-- ════ SUPER ADMIN VIEW ════ -->
<div class="section-card">
    <div class="section-header">
        <span><i class="bi bi-pen me-2"></i>All Office Signatories</span>
        <?php if (!empty($unassigned)): ?>
        <button class="btn btn-maroon btn-sm" data-bs-toggle="modal" data-bs-target="#editModal" data-mode="add">
            <i class="bi bi-plus-lg me-1"></i>Add Signatory
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($signatories)): ?>
        <div class="no-sig-state py-4">
            <i class="bi bi-pen d-block mb-2" style="font-size:2rem;opacity:.3"></i>
            <p class="text-muted small">No signatories configured yet.</p>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0 table-sig">
            <thead>
                <tr>
                    <th class="ps-4">Office</th>
                    <th>Approving Authority</th>
                    <th>Title / Designation</th>
                    <th>Last Updated</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($signatories as $s): ?>
                <tr>
                    <td class="ps-4 fw-semibold" style="color:#800000"><?= htmlspecialchars($s['office_name']) ?></td>
                    <td><?= htmlspecialchars($s['signatory_name']) ?></td>
                    <td class="text-muted fst-italic"><?= $s['signatory_title'] ? htmlspecialchars($s['signatory_title']) : '<span class="text-muted" style="opacity:.5">—</span>' ?></td>
                    <td style="font-size:.78rem;color:#aaa"><?= date('M j, Y', strtotime($s['updated_at'])) ?></td>
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-secondary me-1"
                            data-bs-toggle="modal" data-bs-target="#editModal"
                            data-mode="edit"
                            data-id="<?= $s['signatory_id'] ?>"
                            data-office-id="<?= $s['office_id'] ?>"
                            data-office-name="<?= htmlspecialchars($s['office_name']) ?>"
                            data-name="<?= htmlspecialchars($s['signatory_name']) ?>"
                            data-title="<?= htmlspecialchars($s['signatory_title']) ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#deleteModal"
                            data-id="<?= $s['signatory_id'] ?>"
                            data-office="<?= htmlspecialchars($s['office_name']) ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ════ SCOPED ADMIN VIEW ════ -->
<div class="section-card">
    <div class="section-header">
        <span><i class="bi bi-pen me-2"></i>Current Signatory — <?= htmlspecialchars($myOfficeName) ?></span>
        <button class="btn btn-maroon btn-sm" data-bs-toggle="modal" data-bs-target="#editModal"
            data-mode="<?= $signatory ? 'edit' : 'add' ?>"
            <?php if ($signatory): ?>
                data-id="<?= $signatory['signatory_id'] ?>"
                data-office-id="<?= $signatory['office_id'] ?>"
                data-office-name="<?= htmlspecialchars($myOfficeName) ?>"
                data-name="<?= htmlspecialchars($signatory['signatory_name']) ?>"
                data-title="<?= htmlspecialchars($signatory['signatory_title']) ?>"
            <?php endif; ?>>
            <i class="bi bi-<?= $signatory ? 'pencil' : 'plus-lg' ?> me-1"></i>
            <?= $signatory ? 'Update Signatory' : 'Set Signatory' ?>
        </button>
    </div>

    <div class="sig-display">
        <div class="sig-office-badge">
            <i class="bi bi-building"></i><?= htmlspecialchars($myOfficeName) ?>
        </div>

        <?php if ($signatory): ?>
            <div class="sig-preview-block">
                <div class="sig-preview-name"><?= htmlspecialchars($signatory['signatory_name']) ?></div>
                <div class="sig-preview-line"></div>
                <?php if ($signatory['signatory_title']): ?>
                    <div class="sig-preview-title"><?= htmlspecialchars($signatory['signatory_title']) ?></div>
                <?php else: ?>
                    <div class="sig-preview-title" style="color:#ccc">No title set</div>
                <?php endif; ?>
                <div class="sig-preview-role">Approving Authority</div>
            </div>
            <div class="sig-updated">
                <i class="bi bi-clock me-1"></i>Last updated: <?= date('F j, Y g:i A', strtotime($signatory['updated_at'])) ?>
            </div>
            <div class="mt-3">
                <button type="button" class="btn btn-sm btn-outline-danger"
                    data-bs-toggle="modal" data-bs-target="#deleteModal"
                    data-id="<?= $signatory['signatory_id'] ?>"
                    data-office="<?= htmlspecialchars($myOfficeName) ?>">
                    <i class="bi bi-trash me-1"></i>Remove Signatory
                </button>
            </div>
        <?php else: ?>
            <div class="no-sig-state">
                <i class="bi bi-pen"></i>
                <p>No signatory configured for your office yet.<br>
                <small>Trip tickets will show a blank signature block until one is set.</small></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- How It Works -->
<div class="section-card">
    <div class="section-header"><i class="bi bi-question-circle me-2"></i>How It Works</div>
    <div class="p-4" style="font-size:.86rem;color:#555;line-height:1.8">
        <div class="d-flex gap-3 mb-3">
            <div style="flex-shrink:0;width:28px;height:28px;background:#fdf5f5;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#800000;font-weight:700;font-size:.8rem">1</div>
            <div>Set the <strong>name</strong> and <strong>title</strong> of the person who approves vehicle requests for your office (e.g. the Director or Department Head).</div>
        </div>
        <div class="d-flex gap-3 mb-3">
            <div style="flex-shrink:0;width:28px;height:28px;background:#fdf5f5;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#800000;font-weight:700;font-size:.8rem">2</div>
            <div>When a trip ticket is printed for any schedule from <strong><?= htmlspecialchars($myOfficeName) ?></strong>, this name and title will appear in the <em>Approving Authority</em> signature block.</div>
        </div>
        <div class="d-flex gap-3">
            <div style="flex-shrink:0;width:28px;height:28px;background:#fdf5f5;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#800000;font-weight:700;font-size:.8rem">3</div>
            <div>You can update this at any time. Only one signatory per office is allowed.</div>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /main-content -->

<!-- ══ ADD / EDIT MODAL ══ -->
<div class="modal fade" id="editModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header mh-maroon">
        <h5 class="modal-title" id="editModalTitle">
            <i class="bi bi-pen me-2"></i>Set Signatory
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST" action="Signatories.php" id="sigForm" novalidate>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="signatory_id" id="edit_sid" value="">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label fw-semibold">Office</label>
                <?php if ($isSuperAdmin): ?>
                    <select name="office_id" id="edit_office" class="form-select" required>
                        <option value="">— Select Office —</option>
                        <?php foreach ($offices as $o): ?>
                            <option value="<?= $o['office_id'] ?>"><?= htmlspecialchars($o['office_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please select an office.</div>
                <?php else: ?>
                    <div class="form-control bg-light text-muted" style="cursor:not-allowed">
                        <i class="bi bi-building me-1 text-danger"></i><?= htmlspecialchars($myOfficeName) ?>
                    </div>
                    <div class="form-text">Locked to your assigned office.</div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Approving Authority Name <span class="text-danger">*</span></label>
                <input type="text" name="signatory_name" id="sig_name" class="form-control" required
                    placeholder="e.g. Juan dela Cruz" maxlength="200" value="">
                <div class="invalid-feedback">Please enter the signatory's name.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Title / Designation</label>
                <input type="text" name="signatory_title" id="sig_title" class="form-control"
                    placeholder="e.g. Director, Office of the President" maxlength="200" value="">
                <div class="form-text">This appears below the name on the trip ticket.</div>
            </div>

            <!-- Live preview -->
            <div class="mt-3 p-3 border rounded" style="background:#fafafa;text-align:center">
                <div style="font-size:.72rem;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Signature Block Preview</div>
                <div id="prev_name" style="font-weight:700;font-size:.9rem;color:#222;margin-bottom:6px">Name will appear here</div>
                <div style="border-top:1.5px solid #333;width:220px;margin:0 auto 4px"></div>
                <div id="prev_title" style="font-size:.8rem;color:#666;font-style:italic">Title / Designation</div>
                <div style="font-size:.72rem;color:#888;margin-top:4px">Approving Authority</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-maroon" id="editSubmitBtn">
                <i class="bi bi-save me-1"></i>Save Signatory
            </button>
        </div>
    </form>
</div></div></div>

<!-- ══ DELETE CONFIRM MODAL ══ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content">
    <div class="modal-header mh-red">
        <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Remove Signatory</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST" action="Signatories.php">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="signatory_id" id="del_sid" value="">
        <div class="modal-body text-center py-3">
            <i class="bi bi-exclamation-triangle-fill text-warning fs-2 mb-2 d-block"></i>
            <p class="mb-1" style="font-size:.88rem">Remove the signatory for</p>
            <div class="fw-bold" id="del_office_name" style="color:#800000"></div>
            <p class="text-muted mt-2 mb-0" style="font-size:.8rem">Trip tickets will show a blank signature block.</p>
        </div>
        <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Remove</button>
        </div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const isSuperAdmin = <?= $isSuperAdmin ? 'true' : 'false' ?>;

/* ══ Sidebar toggle ══ */
function toggleSigSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sigSidebarOverlay').classList.toggle('show');
}

/* Close sidebar when nav link tapped on mobile */
document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 900) {
            document.getElementById('mainSidebar').classList.remove('open');
            document.getElementById('sigSidebarOverlay').classList.remove('show');
        }
    });
});

/* ══ Edit modal population ══ */
document.addEventListener('click', e => {
    const editBtn = e.target.closest('[data-bs-target="#editModal"]');
    if (editBtn) {
        const mode       = editBtn.dataset.mode || 'add';
        const sid        = editBtn.dataset.id || '';
        const name       = editBtn.dataset.name || '';
        const title      = editBtn.dataset.title || '';
        const officeId   = editBtn.dataset.officeId || '';

        document.getElementById('editModalTitle').innerHTML =
            `<i class="bi bi-pen me-2"></i>${mode === 'edit' ? 'Update Signatory' : 'Set Signatory'}`;
        document.getElementById('editSubmitBtn').innerHTML =
            `<i class="bi bi-save me-1"></i>${mode === 'edit' ? 'Update' : 'Save'} Signatory`;
        document.getElementById('edit_sid').value  = sid;
        document.getElementById('sig_name').value  = name;
        document.getElementById('sig_title').value = title;
        document.getElementById('prev_name').textContent  = name  || 'Name will appear here';
        document.getElementById('prev_title').textContent = title || 'Title / Designation';

        if (isSuperAdmin) {
            const sel = document.getElementById('edit_office');
            if (sel) sel.value = officeId;
        }

        document.getElementById('sigForm').classList.remove('was-validated');
        return;
    }

    const delBtn = e.target.closest('[data-bs-target="#deleteModal"]');
    if (delBtn) {
        document.getElementById('del_sid').value              = delBtn.dataset.id || '';
        document.getElementById('del_office_name').textContent = delBtn.dataset.office || '';
    }
});

/* ══ Live preview ══ */
document.getElementById('sig_name').addEventListener('input', function() {
    document.getElementById('prev_name').textContent = this.value.trim() || 'Name will appear here';
});
document.getElementById('sig_title').addEventListener('input', function() {
    document.getElementById('prev_title').textContent = this.value.trim() || 'Title / Designation';
});

/* ══ Form validation ══ */
document.getElementById('sigForm').addEventListener('submit', function(e) {
    if (!this.checkValidity()) { e.preventDefault(); this.classList.add('was-validated'); }
});
</script>
</body>
</html>