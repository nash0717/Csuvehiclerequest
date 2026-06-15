<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('setFlash')) {
    function setFlash($type, $message) {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}

requireAdmin();

$currentUserStmt = $pdo->prepare("SELECT office_id FROM users WHERE user_id = ?");
$currentUserStmt->execute([$_SESSION['user_id']]);
$currentUserRow  = $currentUserStmt->fetch(PDO::FETCH_ASSOC);
$currentOfficeId = $currentUserRow['office_id'] ?? null;

$emailColumnExists = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch(PDO::FETCH_ASSOC);
    if (!empty($col)) {
        $emailColumnExists = true;
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username");
        $col2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch(PDO::FETCH_ASSOC);
        if (!empty($col2)) $emailColumnExists = true;
    }
} catch (Exception $e) {
    error_log("Email column check failed: " . $e->getMessage());
}

$phoneColumnExists = false;
try {
    $pc = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch(PDO::FETCH_ASSOC);
    if (!empty($pc)) {
        $phoneColumnExists = true;
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email");
        $pc2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetch(PDO::FETCH_ASSOC);
        if (!empty($pc2)) $phoneColumnExists = true;
    }
} catch (Exception $e) {
    error_log("phone column check failed: " . $e->getMessage());
}

$deptColumnExists = false;
try {
    $dc = $pdo->query("SHOW COLUMNS FROM users LIKE 'dept_id'")->fetch(PDO::FETCH_ASSOC);
    if (!empty($dc)) {
        $deptColumnExists = true;
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN dept_id INT DEFAULT NULL AFTER office_id");
        $dc2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'dept_id'")->fetch(PDO::FETCH_ASSOC);
        if (!empty($dc2)) $deptColumnExists = true;
    }
} catch (Exception $e) {
    error_log("dept_id column check failed: " . $e->getMessage());
}

function validatePhone($phone) {
    return preg_match('/^09\d{9}$/', $phone);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username  = sanitize($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = sanitize($_POST['role'] ?? '');
        $office_id = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : null;
        $dept_id   = !empty($_POST['dept_id'])   ? (int)$_POST['dept_id']   : null;

        if (empty($username)) {
            setFlash('error', "Full name is required.");
        } elseif (empty($password)) {
            setFlash('error', "Password is required.");
        } elseif (empty($role)) {
            setFlash('error', "Role is required.");
        } elseif ($emailColumnExists && empty($email)) {
            setFlash('error', "Email is required.");
        } elseif ($phoneColumnExists && empty($phone)) {
            setFlash('error', "Phone number is required.");
        } elseif ($phoneColumnExists && !validatePhone($phone)) {
            setFlash('error', "Phone number must be 11 digits and start with 09 (e.g. 09XXXXXXXXX).");
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', "Please enter a valid email address.");
        } elseif ($deptColumnExists && empty($dept_id)) {
            setFlash('error', "Department is required.");
        } else {
            $chkU = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $chkU->execute([$username]);
            if ((int)$chkU->fetchColumn() > 0) {
                setFlash('error', "Full name already exists.");
            } else {
                $emailTaken = false;
                if (!empty($email) && $emailColumnExists) {
                    $chkE = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    $chkE->execute([$email]);
                    $emailTaken = (int)$chkE->fetchColumn() > 0;
                }
                if ($emailTaken) {
                    setFlash('error', "That email is already in use.");
                } else {
                    $hashed    = password_hash($password, PASSWORD_DEFAULT);
                    $emailSave = ($email !== '') ? $email : null;
                    $phoneSave = ($phone !== '') ? $phone : null;
                    try {
                        if ($emailColumnExists && $phoneColumnExists && $deptColumnExists) {
                            $stmt = $pdo->prepare("INSERT INTO users (username, email, phone, password, role, office_id, dept_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$username, $emailSave, $phoneSave, $hashed, $role, $office_id, $dept_id]);
                        } elseif ($emailColumnExists && $phoneColumnExists) {
                            $stmt = $pdo->prepare("INSERT INTO users (username, email, phone, password, role, office_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$username, $emailSave, $phoneSave, $hashed, $role, $office_id]);
                        } elseif ($emailColumnExists && $deptColumnExists) {
                            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, office_id, dept_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$username, $emailSave, $hashed, $role, $office_id, $dept_id]);
                        } elseif ($emailColumnExists) {
                            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, office_id) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$username, $emailSave, $hashed, $role, $office_id]);
                        } elseif ($deptColumnExists) {
                            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, office_id, dept_id) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$username, $hashed, $role, $office_id, $dept_id]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO users (username, password, role, office_id) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$username, $hashed, $role, $office_id]);
                        }
                        setFlash('success', "User added successfully.");
                    } catch (Exception $e) {
                        setFlash('error', "Failed to add user: " . $e->getMessage());
                    }
                }
            }
        }

    } elseif ($action === 'edit') {
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $username  = sanitize($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $role      = sanitize($_POST['role'] ?? '');
        $office_id = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : null;
        $dept_id   = !empty($_POST['dept_id'])   ? (int)$_POST['dept_id']   : null;
        $password  = $_POST['password'] ?? '';
        $hasError  = false;

        if (empty($username)) {
            setFlash('error', "Full name is required."); $hasError = true;
        } elseif (empty($role)) {
            setFlash('error', "Role is required."); $hasError = true;
        } elseif ($emailColumnExists && empty($email)) {
            setFlash('error', "Email is required."); $hasError = true;
        } elseif ($phoneColumnExists && empty($phone)) {
            setFlash('error', "Phone number is required."); $hasError = true;
        } elseif ($phoneColumnExists && !validatePhone($phone)) {
            setFlash('error', "Phone number must be 11 digits and start with 09 (e.g. 09XXXXXXXXX)."); $hasError = true;
        } elseif ($deptColumnExists && empty($dept_id)) {
            setFlash('error', "Department is required."); $hasError = true;
        }

        if (!$hasError && !empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', "Please enter a valid email address."); $hasError = true;
        }
        if (!$hasError && !empty($email) && $emailColumnExists) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
            $chk->execute([$email, $user_id]);
            if ((int)$chk->fetchColumn() > 0) {
                setFlash('error', "That email is already in use by another account."); $hasError = true;
            }
        }

        if (!$hasError) {
            $emailSave = ($email !== '') ? $email : null;
            $phoneSave = ($phone !== '') ? $phone : null;
            try {
                if (!empty($password)) {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    if ($emailColumnExists && $phoneColumnExists && $deptColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, phone=?, password=?, role=?, office_id=?, dept_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $phoneSave, $hashed, $role, $office_id, $dept_id, $user_id]);
                    } elseif ($emailColumnExists && $phoneColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, phone=?, password=?, role=?, office_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $phoneSave, $hashed, $role, $office_id, $user_id]);
                    } elseif ($emailColumnExists && $deptColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, password=?, role=?, office_id=?, dept_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $hashed, $role, $office_id, $dept_id, $user_id]);
                    } elseif ($emailColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, password=?, role=?, office_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $hashed, $role, $office_id, $user_id]);
                    } elseif ($deptColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, role=?, office_id=?, dept_id=? WHERE user_id=?");
                        $stmt->execute([$username, $hashed, $role, $office_id, $dept_id, $user_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, role=?, office_id=? WHERE user_id=?");
                        $stmt->execute([$username, $hashed, $role, $office_id, $user_id]);
                    }
                } else {
                    if ($emailColumnExists && $phoneColumnExists && $deptColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, phone=?, role=?, office_id=?, dept_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $phoneSave, $role, $office_id, $dept_id, $user_id]);
                    } elseif ($emailColumnExists && $phoneColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, phone=?, role=?, office_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $phoneSave, $role, $office_id, $user_id]);
                    } elseif ($emailColumnExists && $deptColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=?, office_id=?, dept_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $role, $office_id, $dept_id, $user_id]);
                    } elseif ($emailColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=?, office_id=? WHERE user_id=?");
                        $stmt->execute([$username, $emailSave, $role, $office_id, $user_id]);
                    } elseif ($deptColumnExists) {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, role=?, office_id=?, dept_id=? WHERE user_id=?");
                        $stmt->execute([$username, $role, $office_id, $dept_id, $user_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET username=?, role=?, office_id=? WHERE user_id=?");
                        $stmt->execute([$username, $role, $office_id, $user_id]);
                    }
                }
                setFlash('success', "User updated successfully.");
            } catch (Exception $e) {
                setFlash('error', "Failed to update user: " . $e->getMessage());
            }
        }

    } elseif ($action === 'delete') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id == $_SESSION['user_id']) {
            setFlash('error', "You cannot delete your own account.");
        } else {
            $inUse = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE user_id = ?");
            $inUse->execute([$user_id]);
            if ($inUse->fetchColumn() > 0) {
                setFlash('danger', "Cannot delete this user because they have existing schedule records. Remove those schedules first.");
            } else {
                try {
                    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);
                    setFlash('success', "User deleted successfully.");
                } catch (Exception $e) {
                    setFlash('error', "Failed to delete user: " . $e->getMessage());
                }
            }
        }
    }

    header("Location: Users.php");
    exit;
}

if ($currentOfficeId) {
    $whereClause = " WHERE u.office_id = " . (int)$currentOfficeId;
} else {
    $whereClause = "";
}

$selectCols = "u.user_id, u.username, u.role, u.office_id, o.office_name";
if ($emailColumnExists) $selectCols .= ", u.email";
if ($phoneColumnExists) $selectCols .= ", u.phone";
if ($deptColumnExists)  $selectCols .= ", u.dept_id, d.dept_name";

$deptJoin = $deptColumnExists ? "LEFT JOIN departments d ON u.dept_id = d.dept_id" : "";

$users = $pdo->query("
    SELECT {$selectCols}
    FROM users u
    LEFT JOIN offices o ON u.office_id = o.office_id
    {$deptJoin}
    {$whereClause}
    ORDER BY u.user_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

if ($currentOfficeId) {
    $stmtA = $pdo->prepare("SELECT COUNT(*) FROM users WHERE office_id = ? AND role = 'admin'");
    $stmtA->execute([$currentOfficeId]);
    $cnt_admin = $stmtA->fetchColumn();
    $stmtS = $pdo->prepare("SELECT COUNT(*) FROM users WHERE office_id = ? AND role = 'staff'");
    $stmtS->execute([$currentOfficeId]);
    $cnt_staff = $stmtS->fetchColumn();
    $stmtR = $pdo->prepare("SELECT COUNT(*) FROM users WHERE office_id = ? AND role = 'requestor'");
    $stmtR->execute([$currentOfficeId]);
    $cnt_requestor = $stmtR->fetchColumn();
} else {
    $cnt_admin     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    $cnt_staff     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='staff'")->fetchColumn();
    $cnt_requestor = $pdo->query("SELECT COUNT(*) FROM users WHERE role='requestor'")->fetchColumn();
}

$currentOfficeName = '';
if ($currentOfficeId) {
    $offStmt = $pdo->prepare("SELECT office_name FROM offices WHERE office_id = ?");
    $offStmt->execute([$currentOfficeId]);
    $offRow = $offStmt->fetch(PDO::FETCH_ASSOC);
    $currentOfficeName = $offRow['office_name'] ?? '';
}

$offices = $pdo->query("SELECT * FROM offices ORDER BY office_name")->fetchAll(PDO::FETCH_ASSOC);

if ($currentOfficeId) {
    $deptStmt = $pdo->prepare("SELECT * FROM departments WHERE office_id = ? ORDER BY dept_name");
    $deptStmt->execute([$currentOfficeId]);
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name")->fetchAll(PDO::FETCH_ASSOC);
}

$totalCols = 6;
if ($emailColumnExists) $totalCols++;
if ($phoneColumnExists) $totalCols++;
if ($deptColumnExists)  $totalCols++;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Users – CSU VSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { background: #f5f0f0; font-family: 'Segoe UI', sans-serif; }

        /* ── Sidebar ── */
        .sidebar { min-height: 100vh; background: linear-gradient(180deg, #800000 0%, #6b0000 100%); width: 240px; position: fixed; top: 0; left: 0; z-index: 200; display: flex; flex-direction: column; transition: transform 0.25s ease; }
        .sidebar-brand { padding: 1.25rem 1rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; gap: 10px; }
        .sidebar-logo { width: 42px; height: 42px; border-radius: 50%; background: #fff; overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sidebar-logo img { width: 38px; height: 38px; object-fit: contain; }
        .sidebar-brand-text { color: #fff; font-size: 0.82rem; font-weight: 700; line-height: 1.3; }
        .sidebar-brand-text span { display: block; font-size: 0.72rem; font-weight: 400; opacity: 0.7; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 0.6rem 1.25rem; font-size: 0.88rem; display: flex; align-items: center; gap: 10px; border-left: 3px solid transparent; transition: all 0.15s; }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); border-left-color: rgba(255,255,255,0.4); }
        .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.15); border-left-color: #fff; font-weight: 600; }
        .sidebar .nav-link i { font-size: 1rem; width: 18px; }
        .sidebar-divider { border-color: rgba(255,255,255,0.15); margin: 0.5rem 1rem; }
        .nav-section-label { padding: 0.75rem 1.25rem 0.25rem; font-size: 0.68rem; font-weight: 700; color: rgba(255,255,255,0.45); letter-spacing: 0.08em; text-transform: uppercase; }

        /* ── Topbar ── */
        .topbar { background: #fff; border-bottom: 1px solid #e8dede; padding: 0.7rem 1.5rem; margin-left: 240px; position: sticky; top: 0; z-index: 99; display: flex; align-items: center; gap: 10px; }
        .topbar-title { font-weight: 700; font-size: 1rem; color: #800000; flex: 1; text-align: left; }
        .topbar-user { display: flex; align-items: center; gap: 8px; margin-left: auto; }
        .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: #800000; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; }

        .main-content { margin-left: 240px; padding: 1.5rem; }
        .section-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(128,0,0,0.07); overflow: hidden; }
        .section-header { padding: 1rem 1.25rem; border-bottom: 1px solid #f0e5e5; font-weight: 700; font-size: 0.9rem; color: #800000; display: flex; align-items: center; justify-content: space-between; }
        .table thead th { background: #fdf5f5; color: #800000; font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #f0e5e5; padding: 0.75rem 1rem; white-space: nowrap; }
        .table tbody td { padding: 0.7rem 1rem; font-size: 0.85rem; color: #444; vertical-align: middle; border-color: #fdf5f5; }
        .table tbody tr:hover { background: #fdf8f8; }

        .role-admin     { background:#fdecea; color:#800000; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .role-staff     { background:#e8f0fe; color:#1a56db; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .role-requestor { background:#e6f4ea; color:#137333; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .btn-maroon { background:#800000; color:#fff; border:none; }
        .btn-maroon:hover { background:#6b0000; color:#fff; }
        .modal-header { background: linear-gradient(135deg,#800000,#6b0000); color:#fff; }
        .modal-header .btn-close { filter: invert(1); }
        .mini-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:1.25rem; }
        .mini-card { background:#fff; border-radius:12px; padding:1rem 1.25rem; box-shadow:0 2px 10px rgba(128,0,0,0.06); }
        .mini-card.all   { border-left:4px solid #888; }
        .mini-card.admin { border-left:4px solid #800000; }
        .mini-card.staff { border-left:4px solid #1a56db; }
        .mini-card.req   { border-left:4px solid #137333; }
        .mini-label { font-size:0.72rem; color:#999; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
        .mini-value { font-size:1.5rem; font-weight:700; color:#2d2d2d; line-height:1.1; }
        .password-dots { color:#bbb; font-size:1.1rem; letter-spacing:3px; }
        .email-cell { display:flex; align-items:center; gap:7px; }
        .email-icon { width:26px; height:26px; border-radius:6px; background:#eef2ff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .email-icon i { font-size:0.78rem; color:#4361ee; }
        .phone-icon { width:26px; height:26px; border-radius:6px; background:#e6f4ea; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .phone-icon i { font-size:0.78rem; color:#137333; }
        .email-text { font-size:0.83rem; color:#2c3e7a; font-weight:500; text-decoration:none; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px; display:inline-block; vertical-align:middle; }
        .email-text:hover { color:#800000; text-decoration:underline; }
        .email-none { font-size:0.82rem; color:#bbb; font-style:italic; }
        .you-badge { font-size:0.72rem; background:#fff3cd; color:#856404; padding:2px 8px; border-radius:20px; }
        .office-badge { font-size:0.72rem; background:#e8f4fd; color:#0369a1; padding:2px 10px; border-radius:20px; font-weight:600; }
        .dept-badge { background:#f3e8ff; color:#6b21a8; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:600; display:inline-flex; align-items:center; gap:4px; }
        .dept-none { font-size:0.82rem; color:#bbb; font-style:italic; }
        .scope-bar { background: linear-gradient(135deg,#800000,#6b0000); color:#fff; padding:0.6rem 1.25rem; border-radius:10px; margin-bottom:1.25rem; display:flex; align-items:center; justify-content:space-between; font-size:0.83rem; }
        .scope-bar strong { font-size:0.9rem; }
        .scope-lock { font-size:0.75rem; opacity:0.75; display:flex; align-items:center; gap:5px; }
        input.phone-invalid { border-color: #dc3545 !important; }
        input.phone-valid   { border-color: #198754 !important; }
        .phone-feedback     { font-size: 0.78rem; margin-top: 4px; }
        .phone-feedback.invalid { color: #dc3545; }
        .phone-feedback.valid   { color: #198754; }

        /* ── Sidebar overlay (mobile) ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 199; }
        .sidebar-overlay.show { display: block; }

        /* ── Hamburger – hidden on desktop ── */
        .hamburger-btn { display: none; background: none; border: none; cursor: pointer; padding: 4px 8px; color: #800000; font-size: 1.4rem; align-items: center; justify-content: center; line-height: 1; }

        /* ══ MOBILE USER CARDS (hidden on desktop) ══ */
        .user-card-list { display: none; }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .topbar, .main-content { margin-left: 0 !important; }
            .hamburger-btn { display: flex !important; }
            .mini-stats { grid-template-columns: 1fr 1fr !important; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .scope-bar { flex-direction: column; align-items: flex-start; gap: 4px; }

            /* Hide desktop table, show cards */
            .desktop-users-table { display: none !important; }
            .user-card-list { display: flex; flex-direction: column; gap: 10px; padding: 12px; }

            .user-card { background: #fff; border-radius: 12px; padding: 12px 14px; box-shadow: 0 1px 6px rgba(128,0,0,0.07); }
            .user-card-top { display: flex; align-items: center; gap: 10px; margin-bottom: 9px; }

            /* ── Avatar: letter-based ── */
            .user-card-avatar {
                width: 42px; height: 42px; border-radius: 12px;
                display: flex; align-items: center; justify-content: center;
                font-size: 1.1rem; font-weight: 800; flex-shrink: 0; color: #fff;
                letter-spacing: 0;
            }
            .user-card-avatar.role-admin     { background: #800000; }
            .user-card-avatar.role-staff     { background: #1a56db; }
            .user-card-avatar.role-requestor { background: #137333; }
            .user-card-avatar.role-default   { background: #888; }

            .user-card-name { font-weight: 700; font-size: 14px; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .user-card-badges { display: flex; align-items: center; gap: 5px; margin-top: 2px; flex-wrap: wrap; }
            .user-card-actions { display: flex; gap: 6px; margin-left: auto; flex-shrink: 0; }
            .user-card-actions .btn { padding: 5px 9px; font-size: 13px; }
            .user-card-details { border-top: 1px solid #fdf5f5; padding-top: 9px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 8px; }
            .user-card-detail-label { font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 1px; }
            .user-card-detail-value { font-size: 12px; color: #444; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-bottom: 6px; }
            .user-card-detail-value.email { color: #2c3e7a; font-weight: 500; }
            .user-card-detail-value.phone { color: #137333; font-weight: 500; }
            .user-card-detail-value.dept  { color: #6b21a8; font-weight: 500; }
        }

        @media (max-width: 480px) {
            .mini-stats { grid-template-columns: 1fr 1fr !important; }
            .main-content { padding: 1rem; }
            .topbar { padding: .6rem 1rem; }
            .mini-value { font-size: 1.2rem; }
        }
        /* ══ MOBILE BOTTOM SHEET (matches Drivers.php) ══ */
@media (max-width: 768px) {
    /* FAB */
    .mob-fab {
        position: fixed; bottom: 24px; right: 20px; z-index: 150;
        width: 58px; height: 58px; background: #800000; color: #fff;
        border: none; border-radius: 50%; font-size: 1.6rem;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 4px 16px rgba(128,0,0,.4); cursor: pointer;
        transition: transform .15s, background .15s;
    }
    .mob-fab:hover  { background: #6b0000; transform: scale(1.05); }
    .mob-fab:active { transform: scale(.95); }

    /* Sheet backdrop */
    .sheet-backdrop {
        position: fixed; inset: 0; background: rgba(0,0,0,.45);
        z-index: 300; opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    .sheet-backdrop.open { opacity: 1; pointer-events: all; }

    /* Bottom sheet */
    .mob-sheet {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 310;
        background: #fff; border-radius: 20px 20px 0 0;
        max-height: 92vh; overflow-y: auto;
        transform: translateY(105%);
        transition: transform .3s cubic-bezier(.4,0,.2,1);
        padding: 0 16px 48px;
    }
    .mob-sheet.open { transform: translateY(0); }
    .sheet-handle {
        width: 40px; height: 4px; background: #e0d0d0;
        border-radius: 2px; margin: 12px auto 16px;
    }
    .sheet-head {
        font-weight: 700; font-size: 1rem; color: #800000;
        margin-bottom: 18px; display: flex; align-items: center; gap: 8px;
    }
    .sheet-form-group { margin-bottom: 13px; }
    .sheet-label {
        font-size: .72rem; font-weight: 700; color: #666;
        text-transform: uppercase; letter-spacing: .04em;
        margin-bottom: 5px; display: block;
    }
    .sheet-input {
        width: 100%; padding: 11px 13px; border-radius: 10px;
        border: 1.5px solid #e0d0d0; font-size: .9rem; color: #333;
        background: #fff; outline: none;
        transition: border-color .15s, box-shadow .15s;
        -webkit-appearance: none; appearance: none;
    }
    .sheet-input:focus { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,.1); }
    .sheet-select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 12px center;
    }
    .sheet-actions { display: flex; gap: 10px; margin-top: 20px; }
    .btn-sheet-cancel {
        flex: 1; padding: 12px; border-radius: 11px;
        background: #f5f5f5; color: #555; font-weight: 600;
        font-size: .88rem; border: none; cursor: pointer;
    }
    .btn-sheet-save {
        flex: 2; padding: 12px; border-radius: 11px;
        background: #800000; color: #fff; font-weight: 700;
        font-size: .88rem; border: none; cursor: pointer;
    }
    .btn-sheet-save:active { background: #6b0000; }

    /* Delete confirm sheet */
    .confirm-sheet { padding-bottom: 48px; }
    .confirm-emoji { font-size: 2.6rem; text-align: center; margin-bottom: 8px; }
    .confirm-msg   { text-align: center; color: #555; font-size: .9rem; margin-bottom: 3px; }
    .confirm-name  { text-align: center; font-size: 1.05rem; font-weight: 700; color: #c0392b; margin-bottom: 5px; }
    .confirm-sub   { text-align: center; font-size: .77rem; color: #aaa; margin-bottom: 18px; }
    .btn-sheet-del {
        flex: 2; padding: 12px; border-radius: 11px;
        background: #dc2626; color: #fff; font-weight: 700;
        font-size: .88rem; border: none; cursor: pointer;
    }

    /* Phone row inside sheet */
    .phone-row { display: flex; }
    .phone-prefix {
        background: #fdf5f5; border: 1.5px solid #e0d0d0;
        border-right: none; border-radius: 10px 0 0 10px;
        padding: 11px 12px; font-weight: 700; color: #800000;
        font-size: .85rem; white-space: nowrap;
        display: flex; align-items: center;
    }
    .phone-suffix { border-radius: 0 10px 10px 0 !important; border-left: none !important; }

    /* Hide desktop Add button on mobile (FAB replaces it) */
    .desktop-add-btn { display: none !important; }
}

@media (min-width: 769px) {
    .mob-fab         { display: none !important; }
    .sheet-backdrop  { display: none !important; }
    .mob-sheet       { display: none !important; }
}
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ── Sidebar ── -->
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
        <a class="nav-link" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i> Driver Trip Records</a>
        <a class="nav-link" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link " href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link active" href="Users.php"><i class="bi bi-people"></i>Users</a>
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

<!-- ── Topbar ── -->
<div class="topbar">
    <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="topbar-title"><i class="bi bi-people me-2"></i>Users</div>
    <div class="topbar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:600;color:#333;font-size:0.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div style="font-size:0.72rem;color:#800000">Administrator</div>
        </div>
    </div>
</div>

<div class="main-content">
    <?php showFlash(); ?>

    <?php if (!$emailColumnExists): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div><strong>Email column missing.</strong>
            <pre class="mb-0 mt-1 p-2 bg-light rounded" style="font-size:0.82rem">ALTER TABLE users ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username;</pre>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!$phoneColumnExists): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div><strong>Phone column missing on users.</strong>
            <pre class="mb-0 mt-1 p-2 bg-light rounded" style="font-size:0.82rem">ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email;</pre>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!$deptColumnExists): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div><strong>dept_id column missing on users.</strong>
            <pre class="mb-0 mt-1 p-2 bg-light rounded" style="font-size:0.82rem">ALTER TABLE users ADD COLUMN dept_id INT DEFAULT NULL AFTER office_id;</pre>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($currentOfficeName): ?>
    <div class="scope-bar">
        <span>Showing users for: <strong><?= htmlspecialchars($currentOfficeName) ?></strong></span>
        <span class="scope-lock"><i class="bi bi-lock-fill"></i>Scoped to your office</span>
    </div>
    <?php endif; ?>

    <!-- Mini Stats -->
    <div class="mini-stats">
        <div class="mini-card all">
            <div class="mini-label">Total Users</div>
            <div class="mini-value"><?= count($users) ?></div>
        </div>
        <div class="mini-card admin">
            <div class="mini-label">Admins</div>
            <div class="mini-value"><?= $cnt_admin ?></div>
        </div>
        <div class="mini-card staff">
            <div class="mini-label">Staff</div>
            <div class="mini-value"><?= $cnt_staff ?></div>
        </div>
        <div class="mini-card req">
            <div class="mini-label">Requestors</div>
            <div class="mini-value"><?= $cnt_requestor ?></div>
        </div>
    </div>

    <div class="section-card">
        <div class="section-header">
            <span>
                <i class="bi bi-people me-2"></i>All Users
                <?php if ($currentOfficeName): ?>
                    <span class="office-badge ms-2"><i class="bi bi-building me-1"></i><?= htmlspecialchars($currentOfficeName) ?></span>
                <?php endif; ?>
                <span class="text-muted fw-normal ms-2" style="font-size:0.8rem">(<?= count($users) ?>)</span>
            </span>
<button class="btn btn-maroon btn-sm px-3 desktop-add-btn" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg me-1"></i>Add User
            </button>
        </div>

        <!-- Desktop table -->
        <div class="table-responsive desktop-users-table">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <?php if ($emailColumnExists): ?><th>Email</th><?php endif; ?>
                        <?php if ($phoneColumnExists): ?><th>Phone</th><?php endif; ?>
                        <th>Password</th>
                        <th>Role</th>
                        <th>Office</th>
                        <?php if ($deptColumnExists): ?><th>Department</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u):
                    $rowEmail  = !empty($u['email'])     ? $u['email']     : '';
                    $rowPhone  = !empty($u['phone'])     ? $u['phone']     : '';
                    $rowDept   = !empty($u['dept_name']) ? $u['dept_name'] : '';
                    $rowDeptId = !empty($u['dept_id'])   ? $u['dept_id']   : '';
                ?>
                <tr>
                    <td><?= (int)$u['user_id'] ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <strong><?= htmlspecialchars($u['username']) ?></strong>
                            <?php if ((int)$u['user_id'] === (int)$_SESSION['user_id']): ?>
                                <span class="you-badge">You</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php if ($emailColumnExists): ?>
                    <td>
                        <?php if ($rowEmail !== ''): ?>
                            <div class="email-cell">
                                <div class="email-icon"><i class="bi bi-envelope-fill"></i></div>
                                <a href="mailto:<?= htmlspecialchars($rowEmail) ?>" class="email-text" title="<?= htmlspecialchars($rowEmail) ?>"><?= htmlspecialchars($rowEmail) ?></a>
                            </div>
                        <?php else: ?>
                            <span class="email-none">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($phoneColumnExists): ?>
                    <td>
                        <?php if ($rowPhone !== ''): ?>
                            <div class="email-cell">
                                <div class="phone-icon"><i class="bi bi-phone-fill"></i></div>
                                <a href="tel:<?= htmlspecialchars($rowPhone) ?>" class="email-text"><?= htmlspecialchars($rowPhone) ?></a>
                            </div>
                        <?php else: ?>
                            <span class="email-none">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><span class="password-dots">••••••••</span></td>
                    <td>
                        <?php
                        $r = $u['role'] ?? '';
                        if ($r === 'admin')         echo '<span class="role-admin"><i class="bi bi-shield-check"></i>Admin</span>';
                        elseif ($r === 'staff')     echo '<span class="role-staff"><i class="bi bi-person-gear"></i>Staff</span>';
                        elseif ($r === 'requestor') echo '<span class="role-requestor"><i class="bi bi-person"></i>Requestor</span>';
                        ?>
                    </td>
                    <td><?= htmlspecialchars($u['office_name'] ?? '—') ?></td>
                    <?php if ($deptColumnExists): ?>
                    <td>
                        <?php if ($rowDept !== ''): ?>
                            <span class="dept-badge"><i class="bi bi-diagram-3"></i><?= htmlspecialchars($rowDept) ?></span>
                        <?php else: ?>
                            <span class="dept-none">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary me-1 btn-edit-user"
                                data-id="<?= (int)$u['user_id'] ?>"
                                data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>"
                                data-email="<?= htmlspecialchars($rowEmail, ENT_QUOTES, 'UTF-8') ?>"
                                data-phone="<?= htmlspecialchars($rowPhone, ENT_QUOTES, 'UTF-8') ?>"
                                data-role="<?= htmlspecialchars($u['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-office="<?= htmlspecialchars((string)($u['office_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-dept="<?= htmlspecialchars((string)$rowDeptId, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                            onclick="openDelete(<?= (int)$u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="<?= $totalCols ?>" class="text-center text-muted py-4">
                        <i class="bi bi-people me-2"></i>No users found for this office.
                    </td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div><!-- end .desktop-users-table -->

        <!-- ── Mobile user cards ── -->
        <div class="user-card-list">
        <?php foreach ($users as $u):
            $rowEmail  = !empty($u['email'])     ? $u['email']     : '';
            $rowPhone  = !empty($u['phone'])     ? $u['phone']     : '';
            $rowDept   = !empty($u['dept_name']) ? $u['dept_name'] : '';
            $rowDeptId = !empty($u['dept_id'])   ? $u['dept_id']   : '';
            $role      = $u['role'] ?? '';
            /* First letter of the user's name */
            $initial   = strtoupper(substr($u['username'], 0, 1));
            $avatarClass = in_array($role, ['admin','staff','requestor']) ? 'role-'.$role : 'role-default';
        ?>
        <div class="user-card">
            <div class="user-card-top">
                <!-- Avatar shows first letter of name -->
                <div class="user-card-avatar <?= $avatarClass ?>">
                    <?= htmlspecialchars($initial) ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="user-card-name"><?= htmlspecialchars($u['username']) ?></div>
                    <div class="user-card-badges">
                        <?php
                        if ($role === 'admin')         echo '<span class="role-admin"><i class="bi bi-shield-check"></i>Admin</span>';
                        elseif ($role === 'staff')     echo '<span class="role-staff"><i class="bi bi-person-gear"></i>Staff</span>';
                        elseif ($role === 'requestor') echo '<span class="role-requestor"><i class="bi bi-person"></i>Requestor</span>';
                        ?>
                        <?php if ((int)$u['user_id'] === (int)$_SESSION['user_id']): ?>
                            <span class="you-badge">You</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="user-card-actions">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary btn-edit-user"
                            data-id="<?= (int)$u['user_id'] ?>"
                            data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>"
                            data-email="<?= htmlspecialchars($rowEmail, ENT_QUOTES, 'UTF-8') ?>"
                            data-phone="<?= htmlspecialchars($rowPhone, ENT_QUOTES, 'UTF-8') ?>"
                            data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
                            data-office="<?= htmlspecialchars((string)($u['office_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-dept="<?= htmlspecialchars((string)$rowDeptId, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                        onclick="openDelete(<?= (int)$u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                        <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-card-details">
                <?php if ($emailColumnExists): ?>
                <div>
                    <div class="user-card-detail-label">Email</div>
                    <div class="user-card-detail-value email">
                        <?= $rowEmail !== '' ? htmlspecialchars($rowEmail) : '<span style="color:#bbb;font-style:italic">—</span>' ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($phoneColumnExists): ?>
                <div>
                    <div class="user-card-detail-label">Phone</div>
                    <div class="user-card-detail-value phone">
                        <?= $rowPhone !== '' ? htmlspecialchars($rowPhone) : '<span style="color:#bbb;font-style:italic">—</span>' ?>
                    </div>
                </div>
                <?php endif; ?>
                <div>
                    <div class="user-card-detail-label">Office</div>
                    <div class="user-card-detail-value"><?= htmlspecialchars($u['office_name'] ?? '—') ?></div>
                </div>
                <?php if ($deptColumnExists): ?>
                <div>
                    <div class="user-card-detail-label">Department</div>
                    <div class="user-card-detail-value dept">
                        <?= $rowDept !== '' ? htmlspecialchars($rowDept) : '<span style="color:#bbb;font-style:italic">—</span>' ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
        <div style="text-align:center;padding:2rem;color:#bbb;font-size:.85rem">
            <i class="bi bi-people" style="font-size:1.8rem;display:block;margin-bottom:.4rem;opacity:.3"></i>
            No users found for this office.
        </div>
        <?php endif; ?>
        </div><!-- end .user-card-list -->

    </div><!-- end .section-card -->
</div><!-- end .main-content -->

<!-- ── Add User Modal ── -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off" id="addForm" novalidate>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" placeholder="Enter full name" required autocomplete="off">
                            <div class="invalid-feedback">Full name is required.</div>
                        </div>
                        <?php if ($emailColumnExists): ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" class="form-control" placeholder="user@example.com" autocomplete="off" required>
                            </div>
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($phoneColumnExists): ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                <input type="text" name="phone" id="add_phone" class="form-control"
                                       placeholder="09XXXXXXXXX" autocomplete="off"
                                       pattern="09\d{9}" maxlength="11" inputmode="numeric" required>
                            </div>
                            <div class="form-text text-muted"><i class="bi bi-info-circle me-1"></i>Must start with <strong>09</strong> followed by 9 digits.</div>
                            <div class="phone-feedback" id="add_phone_feedback"></div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="password" id="add_password" class="form-control" required autocomplete="new-password" placeholder="Enter password">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePw('add_password', this)"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="invalid-feedback">Password is required.</div>
                            <div class="form-text">Stored securely as a hash.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <option value="">— Select Role —</option>
                                <option value="admin">Admin</option>
                                <option value="staff">Staff</option>
                                <option value="requestor">Requestor</option>
                            </select>
                            <div class="invalid-feedback">Please select a role.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Office <span class="text-danger">*</span></label>
                            <?php if ($currentOfficeId): ?>
                                <input type="hidden" name="office_id" value="<?= (int)$currentOfficeId ?>">
                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentOfficeName) ?>" readonly style="background:#f5f5f5;cursor:not-allowed;color:#555;">
                                <div class="form-text"><i class="bi bi-lock-fill me-1"></i>Locked to your office.</div>
                            <?php else: ?>
                                <select name="office_id" class="form-select" required>
                                    <option value="">— Select Office —</option>
                                    <?php foreach ($offices as $o): ?>
                                    <option value="<?= (int)$o['office_id'] ?>"><?= htmlspecialchars($o['office_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select an office.</div>
                            <?php endif; ?>
                        </div>
                        <?php if ($deptColumnExists): ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                            <select name="dept_id" class="form-select" required>
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['dept_id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a department.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon"><i class="bi bi-person-plus me-1"></i>Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Edit User Modal ── -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off" id="editForm" novalidate>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="edit_username" class="form-control" placeholder="Enter full name" required autocomplete="off">
                            <div class="invalid-feedback">Full name is required.</div>
                        </div>
                        <?php if ($emailColumnExists): ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="edit_email" class="form-control" placeholder="user@example.com" autocomplete="off" required>
                            </div>
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($phoneColumnExists): ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                <input type="text" name="phone" id="edit_phone" class="form-control"
                                       placeholder="09XXXXXXXXX" pattern="09\d{9}" maxlength="11" inputmode="numeric" required>
                            </div>
                            <div class="form-text text-muted"><i class="bi bi-info-circle me-1"></i>Must start with <strong>09</strong> followed by 9 digits.</div>
                            <div class="phone-feedback" id="edit_phone_feedback"></div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">New Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="edit_password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep current password">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePw('edit_password', this)"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text">Leave blank to keep the current password.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" id="edit_role" class="form-select" required>
                                <option value="">— Select Role —</option>
                                <option value="admin">Admin</option>
                                <option value="staff">Staff</option>
                                <option value="requestor">Requestor</option>
                            </select>
                            <div class="invalid-feedback">Please select a role.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Office <span class="text-danger">*</span></label>
                            <?php if ($currentOfficeId): ?>
                                <input type="hidden" name="office_id" value="<?= (int)$currentOfficeId ?>">
                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentOfficeName) ?>" readonly style="background:#f5f5f5;cursor:not-allowed;color:#555;">
                                <div class="form-text"><i class="bi bi-lock-fill me-1"></i>Locked to your office.</div>
                            <?php else: ?>
                                <select name="office_id" id="edit_office_id" class="form-select" required>
                                    <option value="">— Select Office —</option>
                                    <?php foreach ($offices as $o): ?>
                                    <option value="<?= (int)$o['office_id'] ?>"><?= htmlspecialchars($o['office_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select an office.</div>
                            <?php endif; ?>
                        </div>
                        <?php if ($deptColumnExists): ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                            <select name="dept_id" id="edit_dept_id" class="form-select" required>
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['dept_id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a department.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon"><i class="bi bi-floppy me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Delete Confirmation Modal ── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content" style="border-radius:14px;border:none;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#c0392b,#a93226);padding:1rem 1.25rem">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-trash me-2"></i>Delete User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
            </div>
            <div class="modal-body text-center py-4 px-4">
                <div style="font-size:2.8rem;margin-bottom:.75rem">⚠️</div>
                <p class="mb-1" style="color:#555;font-size:.95rem">Delete user</p>
                <p class="fw-bold fs-5 mb-2" style="color:#c0392b" id="deleteUserName">—</p>
                <p class="text-muted" style="font-size:.85rem">This cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4 gap-2">
                <button type="button" class="btn btn-secondary btn-sm px-4 rounded-3" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <button type="submit" class="btn btn-danger btn-sm px-4 rounded-3"><i class="bi bi-trash me-1"></i>Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- ══ MOBILE FAB ══ -->
<button class="mob-fab" onclick="mobUserOpenAdd()" title="Add User">
    <i class="bi bi-plus-lg"></i>
</button>

<div class="sheet-backdrop" id="mobUserBackdrop" onclick="mobUserCloseAll()"></div>

<!-- ── Add/Edit Bottom Sheet ── -->
<div class="mob-sheet" id="mobUserFormSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head" id="mobUserSheetTitle">
        <i class="bi bi-person-plus"></i> Add User
    </div>
    <form method="POST" id="mobUserForm" novalidate>
        <input type="hidden" name="action" id="mob_user_action" value="add">
        <input type="hidden" name="user_id" id="mob_user_id">

        <div class="sheet-form-group">
            <label class="sheet-label">Full Name <span style="color:#dc2626">*</span></label>
            <input type="text" name="username" id="mob_username" class="sheet-input"
                   placeholder="Enter full name" required>
        </div>

        <?php if ($emailColumnExists): ?>
        <div class="sheet-form-group">
            <label class="sheet-label">Email <span style="color:#dc2626">*</span></label>
            <input type="email" name="email" id="mob_email" class="sheet-input"
                   placeholder="user@example.com" required>
        </div>
        <?php endif; ?>

        <?php if ($phoneColumnExists): ?>
        <div class="sheet-form-group">
            <label class="sheet-label">Phone <span style="color:#dc2626">*</span></label>
            <div class="phone-row">
                <span class="phone-prefix">+63</span>
                <input type="tel" name="phone" id="mob_phone_sheet" class="sheet-input phone-suffix"
                       placeholder="9XXXXXXXXX" maxlength="11" required
                       pattern="09\d{9}" inputmode="numeric"
                       oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)"
                       title="Enter 11-digit number starting with 09">
            </div>
            <div style="font-size:.65rem;color:#bbb;margin-top:4px">11 digits · starts with 09</div>
        </div>
        <?php endif; ?>

        <div class="sheet-form-group" id="mob_password_group">
            <label class="sheet-label">Password <span style="color:#dc2626" id="mob_pw_required">*</span></label>
            <input type="password" name="password" id="mob_password" class="sheet-input"
                   placeholder="Enter password">
            <div style="font-size:.65rem;color:#bbb;margin-top:4px" id="mob_pw_hint">Required for new users.</div>
        </div>

        <div class="sheet-form-group">
            <label class="sheet-label">Role <span style="color:#dc2626">*</span></label>
            <select name="role" id="mob_role" class="sheet-input sheet-select" required>
                <option value="">— Select Role —</option>
                <option value="admin">Admin</option>
                <option value="staff">Staff</option>
                <option value="requestor">Requestor</option>
            </select>
        </div>

        <div class="sheet-form-group">
            <label class="sheet-label">Office <span style="color:#dc2626">*</span></label>
            <?php if ($currentOfficeId): ?>
                <input type="hidden" name="office_id" value="<?= (int)$currentOfficeId ?>">
                <div style="background:#fdf5f5;border:1px solid #f0e5e5;border-radius:10px;padding:.6rem .9rem;font-size:.85rem;color:#800000;font-weight:600;display:flex;align-items:center;gap:6px;">
                    <i class="bi bi-building-fill"></i>
                    <?= htmlspecialchars($currentOfficeName ?: 'Your Office') ?>
                    <small style="margin-left:auto;font-weight:400;color:#999;font-size:.7rem">
                        <i class="bi bi-lock-fill me-1"></i>Auto-assigned
                    </small>
                </div>
            <?php else: ?>
                <select name="office_id" id="mob_office_id" class="sheet-input sheet-select" required>
                    <option value="">— Select Office —</option>
                    <?php foreach ($offices as $o): ?>
                    <option value="<?= (int)$o['office_id'] ?>"><?= htmlspecialchars($o['office_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <?php if ($deptColumnExists): ?>
        <div class="sheet-form-group">
            <label class="sheet-label">Department <span style="color:#dc2626">*</span></label>
            <select name="dept_id" id="mob_dept_id" class="sheet-input sheet-select" required>
                <option value="">— Select Department —</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['dept_id'] ?>"><?= htmlspecialchars($d['dept_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobUserCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-save">
                <i class="bi bi-check-lg me-1"></i>Save User
            </button>
        </div>
    </form>
</div>

<!-- ── Delete Confirm Bottom Sheet ── -->
<div class="mob-sheet confirm-sheet" id="mobUserDeleteSheet">
    <div class="sheet-handle"></div>
    <div class="confirm-emoji">⚠️</div>
    <div class="confirm-msg">Delete user</div>
    <div class="confirm-name" id="mobDeleteUserName">—</div>
    <div class="confirm-sub">This cannot be undone.</div>
    <form method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" id="mobDeleteUserId">
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobUserCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-del">
                <i class="bi bi-trash me-1"></i>Delete
            </button>
        </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('show');
}
/* Close sidebar on nav link click (mobile) */
document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) toggleSidebar();
    });
});

function togglePw(inputId, btn) {
    var input = document.getElementById(inputId);
    var icon  = btn.querySelector('i');
    if (input.type === 'password') { input.type = 'text'; icon.className = 'bi bi-eye-slash'; }
    else                           { input.type = 'password'; icon.className = 'bi bi-eye'; }
}

function openDelete(id, name) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteUserName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

/* ── Phone validation ── */
function setupPhoneValidation(inputId, feedbackId) {
    var input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('keypress', function(e) {
        var pos = this.selectionStart;
        if (pos === 0 && e.key !== '0') { e.preventDefault(); return; }
        if (pos === 1 && e.key !== '9') { e.preventDefault(); return; }
        if (!/^\d$/.test(e.key)) e.preventDefault();
    });
    input.addEventListener('paste', function(e) {
        e.preventDefault();
        var digits = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').substring(0, 11);
        if (digits.length >= 2 && digits.substring(0, 2) !== '09') digits = '09' + digits.substring(2);
        this.value = digits;
        updatePhoneFeedback(input, feedbackId);
    });
    input.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').substring(0, 11);
        updatePhoneFeedback(input, feedbackId);
    });
    input.addEventListener('focus', function() {
        if (this.value === '') { this.value = '09'; updatePhoneFeedback(input, feedbackId); }
    });
    input.addEventListener('blur', function() {
        if (this.value === '09') { this.value = ''; updatePhoneFeedback(input, feedbackId); }
    });
}

function updatePhoneFeedback(input, feedbackId) {
    var fb  = document.getElementById(feedbackId);
    var val = input.value;
    if (!fb) return;
    if (val.length === 0) {
        fb.textContent = ''; fb.className = 'phone-feedback';
        input.classList.remove('phone-valid', 'phone-invalid');
    } else if (val.length < 2 || val.substring(0, 2) !== '09') {
        fb.textContent = '✗ Must start with 09.'; fb.className = 'phone-feedback invalid';
        input.classList.add('phone-invalid'); input.classList.remove('phone-valid');
    } else if (val.length < 11) {
        fb.textContent = (11 - val.length) + ' more digit(s) needed.'; fb.className = 'phone-feedback invalid';
        input.classList.add('phone-invalid'); input.classList.remove('phone-valid');
    } else {
        fb.textContent = '✓ Phone number looks good.'; fb.className = 'phone-feedback valid';
        input.classList.add('phone-valid'); input.classList.remove('phone-invalid');
    }
}

function setupFormValidation(formId) {
    var form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function(e) {
        ['add_phone', 'edit_phone'].forEach(function(pid) {
            var ph = document.getElementById(pid);
            if (ph && ph.closest('form') === form) {
                ph.setCustomValidity(/^09\d{9}$/.test(ph.value) ? '' : 'Must be 11 digits starting with 09.');
            }
        });
        if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
        form.classList.add('was-validated');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    setupPhoneValidation('add_phone',  'add_phone_feedback');
    setupPhoneValidation('edit_phone', 'edit_phone_feedback');
    setupFormValidation('addForm');
    setupFormValidation('editForm');

    var editModalEl = document.getElementById('editModal');

    document.querySelectorAll('.btn-edit-user').forEach(function(btn) {
    btn.addEventListener('click', function() {
        /* On mobile → open bottom sheet instead of modal */
        if (window.innerWidth <= 768) {
            mobUserOpenEdit(this);
            return;
        }
            document.getElementById('editForm').classList.remove('was-validated');
            document.getElementById('edit_user_id').value  = this.dataset.id       || '';
            document.getElementById('edit_username').value = this.dataset.username || '';
            document.getElementById('edit_role').value     = this.dataset.role     || '';
            document.getElementById('edit_password').value = '';

            var officeEl = document.getElementById('edit_office_id');
            if (officeEl) officeEl.value = this.dataset.office || '';

            var emailEl = document.getElementById('edit_email');
            if (emailEl) emailEl.value = this.dataset.email || '';

            var phoneEl = document.getElementById('edit_phone');
            if (phoneEl) {
                phoneEl.value = this.dataset.phone || '';
                phoneEl.setCustomValidity('');
                phoneEl.classList.remove('phone-valid', 'phone-invalid');
                var fb = document.getElementById('edit_phone_feedback');
                if (fb) { fb.textContent = ''; fb.className = 'phone-feedback'; }
                if (phoneEl.value) updatePhoneFeedback(phoneEl, 'edit_phone_feedback');
            }

            var deptEl = document.getElementById('edit_dept_id');
            if (deptEl) deptEl.value = this.dataset.dept || '';

            bootstrap.Modal.getOrCreateInstance(editModalEl).show();
        });
    });

    document.getElementById('addModal').addEventListener('show.bs.modal', function() {
        var form = document.getElementById('addForm');
        form.reset();
        form.classList.remove('was-validated');
        var ph = document.getElementById('add_phone');
        if (ph) { ph.classList.remove('phone-valid','phone-invalid'); ph.setCustomValidity(''); }
        var fb = document.getElementById('add_phone_feedback');
        if (fb) { fb.textContent = ''; fb.className = 'phone-feedback'; }
    });
});
/* ══ MOBILE BOTTOM SHEET FUNCTIONS ══ */
function mobUserOpenSheet(id) {
    document.getElementById('mobUserBackdrop').classList.add('open');
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function mobUserCloseAll() {
    document.getElementById('mobUserBackdrop').classList.remove('open');
    document.getElementById('mobUserFormSheet').classList.remove('open');
    document.getElementById('mobUserDeleteSheet').classList.remove('open');
    document.body.style.overflow = '';
}
function mobUserOpenAdd() {
    document.getElementById('mobUserSheetTitle').innerHTML =
        '<i class="bi bi-person-plus"></i> Add User';
    document.getElementById('mob_user_action').value = 'add';
    document.getElementById('mob_user_id').value = '';
    document.getElementById('mob_username').value = '';
    document.getElementById('mob_role').value = '';
    document.getElementById('mob_password').value = '';
    document.getElementById('mob_pw_hint').textContent = 'Required for new users.';
    document.getElementById('mob_pw_required').style.display = 'inline';

    var emailEl = document.getElementById('mob_email');
    if (emailEl) emailEl.value = '';
    var phoneEl = document.getElementById('mob_phone_sheet');
    if (phoneEl) phoneEl.value = '';
    var officeEl = document.getElementById('mob_office_id');
    if (officeEl) officeEl.value = '';
    var deptEl = document.getElementById('mob_dept_id');
    if (deptEl) deptEl.value = '';

    mobUserOpenSheet('mobUserFormSheet');
}
function mobUserOpenEdit(btn) {
    document.getElementById('mobUserSheetTitle').innerHTML =
        '<i class="bi bi-pencil"></i> Edit User';
    document.getElementById('mob_user_action').value = 'edit';
    document.getElementById('mob_user_id').value     = btn.dataset.id       || '';
    document.getElementById('mob_username').value    = btn.dataset.username || '';
    document.getElementById('mob_role').value        = btn.dataset.role     || '';
    document.getElementById('mob_password').value    = '';
    document.getElementById('mob_pw_hint').textContent = 'Leave blank to keep current password.';
    document.getElementById('mob_pw_required').style.display = 'none';

    var emailEl = document.getElementById('mob_email');
    if (emailEl) emailEl.value = btn.dataset.email || '';
    var phoneEl = document.getElementById('mob_phone_sheet');
    if (phoneEl) phoneEl.value = btn.dataset.phone || '';
    var officeEl = document.getElementById('mob_office_id');
    if (officeEl) officeEl.value = btn.dataset.office || '';
    var deptEl = document.getElementById('mob_dept_id');
    if (deptEl) deptEl.value = btn.dataset.dept || '';

    mobUserOpenSheet('mobUserFormSheet');
}
function mobUserOpenDelete(id, name) {
    document.getElementById('mobDeleteUserId').value = id;
    document.getElementById('mobDeleteUserName').textContent = name;
    mobUserOpenSheet('mobUserDeleteSheet');
}
</script>
</body>
</html>