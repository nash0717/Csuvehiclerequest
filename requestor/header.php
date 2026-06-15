<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch unread notification count
$user_id = $_SESSION['user_id'];
$notif_count = 0;
if (isset($conn)) {
    $notif_q = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM notifications WHERE user_id='$user_id' AND is_read=0");
    if ($notif_q) {
        $notif_row = mysqli_fetch_assoc($notif_q);
        $notif_count = $notif_row['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' — FleetDesk' : 'FleetDesk Requestor'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg:        #0d0f14;
            --surface:   #151820;
            --card:      #1c2030;
            --border:    #262c3e;
            --accent:    #4f8ef7;
            --accent2:   #6ee7b7;
            --warn:      #f59e0b;
            --danger:    #f87171;
            --text:      #e8eaf0;
            --muted:     #7a8299;
            --nav-w:     240px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            display: flex;
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: var(--nav-w);
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            transition: transform .3s ease;
        }
        .sidebar-brand {
            padding: 28px 24px 20px;
            border-bottom: 1px solid var(--border);
        }
        .sidebar-brand .logo-mark {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .logo-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .logo-text {
            font-family: 'Syne', sans-serif;
            font-weight: 800;
            font-size: 18px;
            letter-spacing: -0.5px;
        }
        .logo-sub {
            font-size: 10px;
            color: var(--muted);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .nav-section {
            padding: 16px 12px 8px;
            font-size: 10px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
        }
        .nav-list { list-style: none; padding: 0 8px; }
        .nav-list li a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            color: var(--muted);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: all .2s;
            position: relative;
        }
        .nav-list li a:hover {
            background: var(--card);
            color: var(--text);
        }
        .nav-list li a.active {
            background: rgba(79,142,247,.12);
            color: var(--accent);
        }
        .nav-list li a .nav-icon {
            width: 20px; text-align: center; font-size: 14px;
        }
        .badge-dot {
            position: absolute;
            right: 14px;
            background: var(--danger);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .sidebar-user {
            margin-top: auto;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-avatar {
            width: 36px; height: 36px;
            background: linear-gradient(135deg,#667eea,#764ba2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 14px;
        }
        .user-info .user-name {
            font-weight: 600;
            font-size: 13px;
            line-height: 1.2;
        }
        .user-info .user-role {
            font-size: 11px;
            color: var(--muted);
        }
        .logout-btn {
            margin-left: auto;
            color: var(--muted);
            text-decoration: none;
            font-size: 15px;
            transition: color .2s;
        }
        .logout-btn:hover { color: var(--danger); }

        /* ── MAIN ── */
        .main-wrap {
            margin-left: var(--nav-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .topbar {
            height: 60px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 28px;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .topbar-title {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 17px;
        }
        .topbar-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .icon-btn {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: var(--card);
            border: 1px solid var(--border);
            color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            text-decoration: none;
            font-size: 15px;
            transition: all .2s;
            position: relative;
        }
        .icon-btn:hover { color: var(--text); border-color: var(--accent); }
        .icon-btn .notif-badge {
            position: absolute;
            top: 6px; right: 6px;
            width: 8px; height: 8px;
            background: var(--danger);
            border-radius: 50%;
            border: 2px solid var(--surface);
        }
        .page-content {
            padding: 28px;
            flex: 1;
        }

        /* ── ALERTS ── */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: rgba(110,231,183,.1); border: 1px solid rgba(110,231,183,.3); color: var(--accent2); }
        .alert-danger  { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.3); color: var(--danger); }
        .alert-warning { background: rgba(245,158,11,.1);  border: 1px solid rgba(245,158,11,.3);  color: var(--warn); }

        /* Mobile hamburger */
        .hamburger {
            display: none;
            background: none;
            border: none;
            color: var(--text);
            font-size: 20px;
            cursor: pointer;
            margin-right: 12px;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-wrap { margin-left: 0; }
            .hamburger { display: block; }
            .page-content { padding: 16px; }
        }
    </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="logo-mark">
            <div class="logo-icon">🚐</div>
            <div>
                <div class="logo-text">FleetDesk</div>
                <div class="logo-sub">Requestor Portal</div>
            </div>
        </div>
    </div>

    <div class="nav-section">Main Menu</div>
    <ul class="nav-list">
        <li>
            <a href="dashboard.php" class="<?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-th-large"></i></span> Dashboard
            </a>
        </li>
        <li>
            <a href="my_trips.php" class="<?= $current_page === 'my_trips.php' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-route"></i></span> My Trips
            </a>
        </li>
        <li>
            <a href="new_request.php" class="<?= $current_page === 'new_request.php' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-plus-circle"></i></span> New Request
            </a>
        </li>
        <li>
            <a href="notifications.php" class="<?= $current_page === 'notifications.php' ? 'active' : '' ?>">
                <span class="nav-icon"><i class="fas fa-bell"></i></span> Notifications
                <?php if ($notif_count > 0): ?>
                    <span class="badge-dot"><?= $notif_count ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <div class="sidebar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)) ?></div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User') ?></div>
            <div class="user-role">Requestor</div>
        </div>
        <a href="../logout.php" class="logout-btn" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</aside>

<div class="main-wrap">
    <div class="topbar">
        <button class="hamburger" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fas fa-bars"></i>
        </button>
        <span class="topbar-title"><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Dashboard'; ?></span>
        <div class="topbar-right">
            <a href="notifications.php" class="icon-btn" title="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($notif_count > 0): ?><span class="notif-badge"></span><?php endif; ?>
            </a>
        </div>
    </div>
    <div class="page-content">