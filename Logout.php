<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /csuweb/login.php");
    exit;
}

// Store where user came from
$redirect_back = $_SERVER['HTTP_REFERER'] ?? '/csuweb/dashboard.php';

if (isset($_POST['confirm_logout'])) {
    session_destroy();
    header("Location: /csuweb/login.php?logged_out=1");
    exit;
}

$username = $_SESSION['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout — CSU Booking System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #1a0000;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .icon-wrap {
            width: 60px; height: 60px;
            background: #fff0f0;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
        }
        .icon-wrap svg { width: 28px; height: 28px; }
        h2 { font-size: 18px; font-weight: 600; color: #1a0a0a; margin-bottom: 6px; }
        p { font-size: 14px; color: #6b6b6b; line-height: 1.6; margin-bottom: 1.75rem; }
        p strong { color: #3d0000; font-weight: 500; }
        .btn-row { display: flex; gap: 10px; }
        .btn-cancel {
            flex: 1; padding: 10px;
            border: 1px solid #ddd; border-radius: 8px;
            background: #fff; color: #444;
            font-size: 14px; cursor: pointer;
            text-decoration: none; display: flex;
            align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .btn-cancel:hover { background: #f5f5f5; }
        .btn-logout {
            flex: 1; padding: 10px;
            border: none; border-radius: 8px;
            background: #6B0000; color: #fff;
            font-size: 14px; font-weight: 500;
            cursor: pointer; transition: background 0.15s;
        }
        .btn-logout:hover { background: #3d0000; }
    </style>
</head>
<body>
<div class="card">
    <div class="icon-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="#c0392b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
    </div>
    <h2>Logging out?</h2>
    <p>You're signed in as <strong><?= htmlspecialchars($username) ?></strong>.<br>Any unsaved changes will be lost.</p>
    <div class="btn-row">
        <a href="<?= htmlspecialchars($redirect_back) ?>" class="btn-cancel">Stay logged in</a>
        <form method="POST" style="flex:1;display:flex;">
            <button type="submit" name="confirm_logout" class="btn-logout" style="width:100%;">Yes, log out</button>
        </form>
    </div>
</div>
</body>
</html>