<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    redirectByRole();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = sanitize($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($login) || empty($password)) {
        $error = "Please enter your username or email and password.";
    } else {
        try {
            // In login.php, replace the login query section with this:
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

// If not found in users, check drivers table
if (!$user) {
    $dStmt = $pdo->prepare("SELECT * FROM drivers WHERE email = ?");
    $dStmt->execute([$login]);
    $driver = $dStmt->fetch();
    
    if ($driver && !empty($driver['password']) && password_verify($password, $driver['password'])) {
        $_SESSION['user_id']   = $driver['driver_id'];  // use driver_id as user_id
        $_SESSION['username']  = $driver['driver_name'];
        $_SESSION['role']      = 'driver';
        $_SESSION['office_id'] = $driver['office_id'] ?? null;
        $_SESSION['driver_id'] = $driver['driver_id'];
        redirectByRole();
    } else {
        $error = "Invalid username or password.";
    }
} else {
    // existing users table login logic stays here
    $hash    = $user['password'];
    $hashLen = strlen($hash);
    if ($hashLen < 50) {
        $error = "Invalid password hash in database. Please use forgot password to reset.";
    } elseif (password_verify($password, $hash)) {
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['office_id'] = $user['office_id'] ?? null;

        if (!empty($user['office_id'])) {
            $officeStmt = $pdo->prepare("SELECT office_name FROM offices WHERE office_id = ?");
            $officeStmt->execute([$user['office_id']]);
            $office = $officeStmt->fetch();
            $_SESSION['office_name'] = $office['office_name'] ?? '';
        } else {
            $_SESSION['office_name'] = '';
        }
        redirectByRole();
    } else {
        $error = "Invalid username or password.";
    }
}
        } catch (Exception $e) {
            $error = "An error occurred during login. Please try again later.";
        }
    }
}

$url_error = $_GET['error'] ?? '';
if ($url_error === 'unauthorized') {
    $error = "You are not authorized to access that page.";
}

$forgotFile = 'Forgot_password.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – CSU Vehicle Scheduling System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --maroon:       #7a0000;
            --maroon-deep:  #4d0000;
            --maroon-glow:  #b30000;
            --gold:         #c9a84c;
            --gold-light:   #f0d080;
            --cream:        #fdf8f3;
            --text:         #1c1010;
            --muted:        #7a6060;
            --border:       #e8d8d8;
        }

        body {
            min-height: 100vh;
            background: var(--cream);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Sans', sans-serif;
            position: relative;
            padding: 1rem;
        }

        /* ── Ambient background ── */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
            background:
                radial-gradient(ellipse 80% 60% at 15% 10%, rgba(122,0,0,0.12) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 85% 90%, rgba(201,168,76,0.10) 0%, transparent 55%),
                radial-gradient(ellipse 100% 80% at 50% 50%, #fdf6ee 0%, #f5ebe0 100%);
        }

        /* Decorative geometric lines */
        .bg-lines {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
        }
        .bg-lines::before {
            content: '';
            position: absolute;
            top: -20%; left: -10%;
            width: 60%; height: 120%;
            border: 1px solid rgba(122,0,0,0.06);
            border-radius: 50%;
            transform: rotate(-15deg);
        }
        .bg-lines::after {
            content: '';
            position: absolute;
            bottom: -30%; right: -15%;
            width: 70%; height: 130%;
            border: 1px solid rgba(201,168,76,0.08);
            border-radius: 50%;
            transform: rotate(20deg);
        }

        /* ── Main card layout ── */
        .login-outer {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 960px;
            min-height: 560px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-radius: 28px;
            overflow: hidden;
            box-shadow:
                0 2px 0 rgba(201,168,76,0.6),
                0 32px 80px rgba(77,0,0,0.22),
                0 8px 24px rgba(0,0,0,0.08);
            animation: cardIn 0.7s cubic-bezier(0.22,1,0.36,1) both;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(28px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Left panel ── */
        .panel-left {
            background: linear-gradient(160deg, var(--maroon) 0%, var(--maroon-deep) 60%, #2a0000 100%);
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .panel-left::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                repeating-linear-gradient(
                    45deg, transparent, transparent 40px,
                    rgba(255,255,255,0.015) 40px, rgba(255,255,255,0.015) 41px
                );
        }

        .panel-left::after {
            content: '';
            position: absolute;
            bottom: -60px; right: -60px;
            width: 200px; height: 200px;
            border: 2px solid rgba(201,168,76,0.2);
            border-radius: 50%;
        }

        .gold-ring {
            position: absolute;
            top: -40px; left: -40px;
            width: 160px; height: 160px;
            border: 1.5px solid rgba(201,168,76,0.15);
            border-radius: 50%;
        }

        .logo-wrap {
            position: relative;
            width: 130px; height: 130px;
            margin-bottom: 1.75rem;
            animation: logoFloat 0.9s cubic-bezier(0.22,1,0.36,1) 0.15s both;
        }

        @keyframes logoFloat {
            from { opacity: 0; transform: translateY(-16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .logo-ring {
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 1.5px solid rgba(201,168,76,0.35);
            animation: spin 18s linear infinite;
        }

        .logo-ring::before {
            content: '';
            position: absolute;
            top: 50%; left: -4px;
            width: 7px; height: 7px;
            background: var(--gold);
            border-radius: 50%;
            transform: translateY(-50%);
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }

        .logo-bg {
            width: 130px; height: 130px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.12);
        }

        .logo-bg img {
            width: 108px; height: 108px;
            object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.3));
        }

        .left-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.55rem; font-weight: 800;
            color: #fff; text-align: center; line-height: 1.25;
            margin-bottom: 0.6rem;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.25s both;
        }

        .left-sub {
            font-size: 0.78rem;
            color: rgba(255,255,255,0.55);
            text-align: center;
            letter-spacing: 0.12em; text-transform: uppercase; font-weight: 500;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.32s both;
        }

        .gold-divider {
            width: 48px; height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            margin: 1.25rem auto;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.38s both;
        }

        .left-tagline {
            font-size: 0.76rem;
            color: rgba(255,255,255,0.4);
            text-align: center; line-height: 1.6; max-width: 220px;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.44s both;
        }

        /* ── Right panel ── */
        .panel-right {
            background: #fff;
            padding: 3rem 2.75rem;
            display: flex; flex-direction: column; justify-content: center;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.1s both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .form-eyebrow {
            font-size: 0.7rem; font-weight: 600;
            letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--gold); margin-bottom: 0.4rem;
        }

        .form-headline {
            font-family: 'Playfair Display', serif;
            font-size: 1.9rem; font-weight: 800;
            color: var(--text); line-height: 1.15; margin-bottom: 0.35rem;
        }

        .form-headline span { color: var(--maroon); }

        .form-desc {
            font-size: 0.8rem; color: var(--muted);
            margin-bottom: 2rem; font-weight: 400;
        }

        /* ── Error alert ── */
        .alert-error {
            background: #fff5f5;
            border-left: 3px solid var(--maroon);
            border-radius: 8px;
            padding: 0.65rem 1rem;
            font-size: 0.82rem; color: var(--maroon);
            margin-bottom: 1.5rem;
            display: flex; align-items: center; gap: 8px;
            animation: fadeUp 0.3s ease both;
        }

        /* ── Form fields ── */
        .field-group { margin-bottom: 1.1rem; }

        .field-label {
            font-size: 0.76rem; font-weight: 600;
            color: #3a2a2a; letter-spacing: 0.04em; text-transform: uppercase;
            margin-bottom: 6px; display: block;
        }

        .field-wrap {
            position: relative; display: flex; align-items: center;
        }

        .field-icon {
            position: absolute; left: 14px;
            color: #bba8a8; font-size: 0.95rem;
            pointer-events: none; transition: color 0.2s;
        }

        .field-input {
            width: 100%;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 0.7rem 1rem 0.7rem 2.6rem;
            font-size: 0.88rem;
            font-family: 'DM Sans', sans-serif;
            color: var(--text); background: #fdfafa;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            outline: none;
        }

        .field-input::placeholder { color: #c0aaaa; }

        .field-input:focus {
            border-color: var(--maroon); background: #fff;
            box-shadow: 0 0 0 3px rgba(122,0,0,0.07);
        }

        .field-wrap:focus-within .field-icon { color: var(--maroon); }

        .eye-btn {
            position: absolute; right: 12px;
            background: none; border: none; cursor: pointer;
            color: #bba8a8; font-size: 0.95rem; padding: 4px;
            transition: color 0.2s; line-height: 1;
        }
        .eye-btn:hover { color: var(--maroon); }

        .forgot-row {
            display: flex; justify-content: flex-end;
            margin-bottom: 1.5rem; margin-top: -0.4rem;
        }

        .forgot-link {
            font-size: 0.78rem; color: var(--maroon);
            text-decoration: none; font-weight: 500;
            display: flex; align-items: center; gap: 4px;
            opacity: 0.75; transition: opacity 0.2s;
        }
        .forgot-link:hover { opacity: 1; text-decoration: underline; }

        /* ── Sign in button ── */
        .btn-signin {
            width: 100%;
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-deep) 100%);
            color: #fff; border: none; border-radius: 10px;
            padding: 0.8rem 1rem; font-size: 0.92rem; font-weight: 600;
            font-family: 'DM Sans', sans-serif; letter-spacing: 0.04em;
            cursor: pointer; position: relative; overflow: hidden;
            transition: transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 18px rgba(122,0,0,0.28);
        }

        .btn-signin::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, transparent 60%);
        }

        .btn-signin:hover { transform: translateY(-1px); box-shadow: 0 7px 24px rgba(122,0,0,0.38); }
        .btn-signin:active { transform: translateY(0); box-shadow: 0 3px 10px rgba(122,0,0,0.25); }
        .btn-signin i { margin-right: 6px; }

        /* ── Register link ── */
        .register-row {
            margin-top: 1.25rem; text-align: center;
            font-size: 0.8rem; color: var(--muted);
        }

        .register-link {
            color: var(--maroon); font-weight: 600; text-decoration: none;
            display: inline-flex; align-items: center; gap: 4px;
            margin-left: 4px; transition: opacity 0.2s;
        }
        .register-link:hover { opacity: 0.75; text-decoration: underline; }

        /* ── Footer ── */
        .right-footer {
            margin-top: 2rem; padding-top: 1.25rem;
            border-top: 1px solid #f0e8e8; text-align: center;
        }
        .right-footer p { font-size: 0.72rem; color: #c0b0b0; line-height: 1.7; }
        .right-footer .dev-credit { color: var(--maroon); font-weight: 600; }

        /* ── Responsive ── */
        @media (max-width: 700px) {
            body { align-items: flex-start; padding: 1rem; }

            .login-outer {
                grid-template-columns: 1fr;
                max-width: 100%;
                border-radius: 20px;
                min-height: auto;
            }

            .panel-left {
                padding: 2rem 1.75rem 1.5rem;
                min-height: auto;
            }

            .left-title { font-size: 1.2rem; }
            .gold-divider, .left-tagline { display: none; }

            .logo-wrap { width: 88px; height: 88px; margin-bottom: 1rem; }
            .logo-bg   { width: 88px; height: 88px; }
            .logo-bg img { width: 72px; height: 72px; }

            .panel-right { padding: 1.75rem 1.5rem; }
            .form-headline { font-size: 1.5rem; }
            .form-desc { margin-bottom: 1.25rem; }
            .right-footer { margin-top: 1.25rem; }
        }
    </style>
</head>
<body>

<div class="bg-layer"></div>
<div class="bg-lines"></div>

<div class="login-outer">

    <!-- ── Left panel ── -->
    <div class="panel-left">
        <div class="gold-ring"></div>

        <div class="logo-wrap">
            <div class="logo-ring"></div>
            <div class="logo-bg">
                <img src="image/Csu.png" alt="CSU Logo">
            </div>
        </div>

        <div class="left-title">CSU Vehicle<br>Scheduling System</div>
        <div class="gold-divider"></div>
        <div class="left-sub">Cagayan State University</div>
        <div class="left-tagline" style="margin-top:0.9rem;">
            Streamlining university vehicle management and trip scheduling.
        </div>
    </div>

    <!-- ── Right panel ── -->
    <div class="panel-right">

        <div class="form-eyebrow">Welcome back</div>
        <div class="form-headline">Sign in to<br><span>your account</span></div>
        <div class="form-desc">Enter your full name or email to continue</div>

        <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="field-group">
                <label class="field-label">Full Name or Email</label>
                <div class="field-wrap">
                    <i class="bi bi-person-fill field-icon"></i>
                    <input type="text" name="username" class="field-input"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="Enter your full name or email" required autofocus>
                </div>
            </div>

            <div class="field-group">
                <label class="field-label">Password</label>
                <div class="field-wrap">
                    <i class="bi bi-lock-fill field-icon"></i>
                    <input type="password" name="password" id="password"
                           class="field-input" placeholder="Enter your password" required>
                    <button type="button" class="eye-btn" onclick="togglePassword()">
                        <i class="bi bi-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>

            <div class="forgot-row">
                <a href="<?= $forgotFile ?>" class="forgot-link">
                    <i class="bi bi-question-circle"></i> Forgot password?
                </a>
            </div>

            <button type="submit" class="btn-signin">
                <i class="bi bi-box-arrow-in-right"></i> Sign In
            </button>

        </form>

        <div class="register-row">
            Don't have a Requestor account?
            <a href="register.php" class="register-link">
                <i class="bi bi-person-plus-fill"></i> Create one here
            </a>
        </div>

        <div class="right-footer">
            <p>
                &copy; <?= date('Y') ?> <strong style="color:#800000;">Cagayan State University</strong> &middot; All rights reserved<br>
                System Developed by <span class="dev-credit">Nash Andrei Tumaliuan Vergara</span>
            </p>
        </div>

    </div>
</div>

<script>
function togglePassword() {
    const pw  = document.getElementById('password');
    const eye = document.getElementById('eye-icon');
    if (pw.type === 'password') {
        pw.type = 'text';
        eye.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        pw.type = 'password';
        eye.classList.replace('bi-eye-slash', 'bi-eye');
    }
}
</script>
</body>
</html>