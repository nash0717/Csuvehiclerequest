<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit;
}

$error   = '';
$success = '';
$formData = [];

/* ── Fetch offices ── */
$officesStmt = $pdo->query("SELECT office_id, office_name FROM offices ORDER BY office_name ASC");
$offices = $officesStmt->fetchAll();

/* ── Fetch ALL departments with their office_id ── */
$deptsStmt = $pdo->query("SELECT dept_id, dept_name, office_id FROM departments ORDER BY dept_name ASC");
$allDepartments = $deptsStmt->fetchAll();

/* ── Group departments by office_id for JS ── */
$deptsByOffice = [];
foreach ($allDepartments as $d) {
    $oid = $d['office_id'] ?? 0;
    $deptsByOffice[$oid][] = ['id' => $d['dept_id'], 'name' => $d['dept_name']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'username'   => trim($_POST['username']   ?? ''),
        'email'      => trim($_POST['email']      ?? ''),
        'office_id'  => (int)($_POST['office_id'] ?? 0),
        'dept_id'    => (int)($_POST['dept_id']   ?? 0),
        'contact_no' => trim($_POST['contact_no'] ?? ''),
    ];
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (empty($formData['username']) || strlen($formData['username']) < 4) {
        $error = "Username must be at least 4 characters.";
    } elseif (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($formData['office_id'] === 0) {
        $error = "Please select your office.";
    } elseif (empty($password) || strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $password2) {
        $error = "Passwords do not match.";
    } else {
        try {
            $chkUser = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
            $chkUser->execute([$formData['username']]);
            if ($chkUser->fetch()) {
                $error = "That Fullname is already taken. Please choose another.";
            } else {
                $chkEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
                $chkEmail->execute([$formData['email']]);
                if ($chkEmail->fetch()) {
                    $error = "An account with that email already exists.";
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("
                        INSERT INTO users (username, email, password, role, office_id, dept_id, phone, created_at)
                        VALUES (?, ?, ?, 'requestor', ?, ?, ?, NOW())
                    ");
                    $ins->execute([
                        $formData['username'],
                        $formData['email'],
                        $hash,
                        $formData['office_id'] ?: null,
                        $formData['dept_id']   ?: null,
                        $formData['contact_no'] ?: null,
                    ]);
                    $success  = "Account created successfully! You can now log in.";
                    $formData = [];
                }
            }
        } catch (PDOException $e) {
            $error = "A database error occurred. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Requestor Account – CSU Vehicle Scheduling System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --maroon:      #7a0000;
            --maroon-deep: #4d0000;
            --gold:        #c9a84c;
            --cream:       #fdf8f3;
            --text:        #1c1010;
            --muted:       #7a6060;
            --border:      #e8d8d8;
            --bg-field:    #fdfafa;
        }

        body {
            min-height: 100vh;
            background: var(--cream);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            font-family: 'DM Sans', sans-serif;
            padding: 2rem 1rem;
        }

        body::before {
            content: '';
            position: fixed; inset: 0; z-index: 0;
            background:
                radial-gradient(ellipse 80% 60% at 15% 10%, rgba(122,0,0,0.10) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 85% 90%, rgba(201,168,76,0.09) 0%, transparent 55%),
                radial-gradient(ellipse 100% 80% at 50% 50%, #fdf6ee 0%, #f5ebe0 100%);
        }

        body::after {
            content: '';
            position: fixed; top: -80px; left: -80px;
            width: 340px; height: 340px;
            border: 1px solid rgba(122,0,0,0.06);
            border-radius: 50%; z-index: 0;
        }

        .register-outer {
            position: relative; z-index: 10;
            width: 100%; max-width: 980px;
            display: grid;
            grid-template-columns: 280px 1fr;
            border-radius: 28px; overflow: hidden;
            box-shadow:
                0 2px 0 rgba(201,168,76,0.5),
                0 32px 80px rgba(77,0,0,0.20),
                0 8px 24px rgba(0,0,0,0.07);
            animation: cardIn 0.7s cubic-bezier(0.22,1,0.36,1) both;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(24px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ══ Left Panel ══ */
        .panel-left {
            background: linear-gradient(160deg, var(--maroon) 0%, var(--maroon-deep) 60%, #2a0000 100%);
            padding: 3rem 1.75rem;
            display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
            position: relative; overflow: hidden;
        }

        .panel-left::before {
            content: ''; position: absolute; inset: 0;
            background-image: repeating-linear-gradient(
                45deg, transparent, transparent 40px,
                rgba(255,255,255,0.015) 40px, rgba(255,255,255,0.015) 41px);
        }

        .panel-left::after {
            content: ''; position: absolute;
            bottom: -60px; right: -60px;
            width: 200px; height: 200px;
            border: 2px solid rgba(201,168,76,0.15); border-radius: 50%;
        }

        .gold-ring {
            position: absolute; top: -40px; left: -40px;
            width: 160px; height: 160px;
            border: 1.5px solid rgba(201,168,76,0.12); border-radius: 50%;
        }

        .logo-wrap {
            position: relative; width: 110px; height: 110px;
            margin-bottom: 1.5rem;
            animation: logoFloat 0.9s cubic-bezier(0.22,1,0.36,1) 0.15s both;
        }

        @keyframes logoFloat {
            from { opacity: 0; transform: translateY(-14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .logo-ring {
            position: absolute; inset: -8px; border-radius: 50%;
            border: 1.5px solid rgba(201,168,76,0.30);
            animation: spin 18s linear infinite;
        }

        .logo-ring::before {
            content: ''; position: absolute;
            top: 50%; left: -4px; width: 7px; height: 7px;
            background: var(--gold); border-radius: 50%;
            transform: translateY(-50%);
        }

        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        .logo-bg {
            width: 110px; height: 110px;
            background: rgba(255,255,255,0.06); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .logo-bg img { width: 90px; height: 90px; object-fit: contain; filter: drop-shadow(0 4px 12px rgba(0,0,0,0.3)); }

        .left-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.25rem; font-weight: 800; color: #fff;
            text-align: center; line-height: 1.3; margin-bottom: 0.5rem;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.25s both;
        }

        .gold-divider {
            width: 40px; height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            margin: 1rem auto;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.32s both;
        }

        .left-sub {
            font-size: 0.7rem; color: rgba(255,255,255,0.5);
            text-align: center; letter-spacing: 0.12em;
            text-transform: uppercase; font-weight: 500;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.38s both;
        }

        .role-badge {
            margin-top: 1.25rem;
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(201,168,76,0.15);
            border: 1px solid rgba(201,168,76,0.3);
            border-radius: 20px; padding: 5px 14px;
            font-size: 0.72rem; font-weight: 600; color: var(--gold);
            letter-spacing: 0.06em; text-transform: uppercase;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.42s both;
        }

        .steps-wrap {
            margin-top: 2rem; width: 100%;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.48s both;
        }

        .step-item { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 1.1rem; }

        .step-dot {
            width: 26px; height: 26px; border-radius: 50%;
            background: rgba(201,168,76,0.18);
            border: 1.5px solid rgba(201,168,76,0.35);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.68rem; font-weight: 700; color: var(--gold);
            flex-shrink: 0; margin-top: 1px;
        }

        .step-text { font-size: 0.74rem; color: rgba(255,255,255,0.55); line-height: 1.5; }
        .step-label { font-weight: 600; display: block; font-size: 0.77rem; color: rgba(255,255,255,0.85); }

        .left-back {
            margin-top: auto; padding-top: 2rem; width: 100%;
            animation: fadeUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.54s both;
        }

        .back-link {
            display: flex; align-items: center; gap: 7px;
            font-size: 0.76rem; color: rgba(255,255,255,0.4);
            text-decoration: none; transition: color 0.2s;
        }

        .back-link:hover { color: rgba(255,255,255,0.85); }

        /* ══ Right Panel ══ */
        .panel-right {
            background: #fff; padding: 2.5rem 2.75rem;
            display: flex; flex-direction: column;
            animation: fadeUp 0.7s cubic-bezier(0.22,1,0.36,1) 0.1s both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .form-eyebrow {
            font-size: 0.68rem; font-weight: 600;
            letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--gold); margin-bottom: 0.3rem;
            display: flex; align-items: center; gap: 7px;
        }

        .form-headline {
            font-family: 'Playfair Display', serif;
            font-size: 1.75rem; font-weight: 800; color: var(--text);
            line-height: 1.15; margin-bottom: 0.3rem;
        }

        .form-headline span { color: var(--maroon); }

        .form-desc { font-size: 0.78rem; color: var(--muted); margin-bottom: 1rem; }

        /* Requestor-only notice */
        .requestor-notice {
            display: flex; align-items: flex-start; gap: 8px;
            background: #fff8e6;
            border: 1.5px solid #f0d070;
            border-radius: 9px;
            padding: 8px 12px;
            font-size: 0.76rem; color: #7a5a00; font-weight: 500;
            margin-bottom: 1.1rem; line-height: 1.5;
        }

        .requestor-notice i { color: #c9a84c; font-size: 0.9rem; flex-shrink: 0; margin-top: 1px; }

        .section-label {
            font-size: 0.67rem; font-weight: 700;
            letter-spacing: 0.14em; text-transform: uppercase;
            color: var(--maroon);
            border-bottom: 1.5px solid #f5eaea;
            padding-bottom: 6px; margin-bottom: 1rem; margin-top: 1.25rem;
            display: flex; align-items: center; gap: 7px;
        }

        .section-label:first-of-type { margin-top: 0; }

        .field-grid { display: grid; gap: 0.85rem; }
        .grid-2 { grid-template-columns: 1fr 1fr; }

        .field-group { display: flex; flex-direction: column; gap: 5px; }

        .field-label {
            font-size: 0.72rem; font-weight: 600;
            color: #3a2a2a; letter-spacing: 0.04em; text-transform: uppercase;
        }

        .field-wrap { position: relative; display: flex; align-items: center; }

        .field-icon {
            position: absolute; left: 13px; color: #c0aaaa;
            font-size: 0.88rem; pointer-events: none;
            transition: color 0.2s; z-index: 1;
        }

        .field-wrap:focus-within .field-icon { color: var(--maroon); }

        .field-input, .field-select {
            width: 100%;
            border: 1.5px solid var(--border); border-radius: 9px;
            padding: 0.62rem 0.9rem 0.62rem 2.4rem;
            font-size: 0.85rem; font-family: 'DM Sans', sans-serif;
            color: var(--text); background: var(--bg-field);
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            outline: none; appearance: none; -webkit-appearance: none;
        }

        .field-input::placeholder { color: #c8b4b4; }

        .field-input:focus, .field-select:focus {
            border-color: var(--maroon); background: #fff;
            box-shadow: 0 0 0 3px rgba(122,0,0,0.06);
        }

        .field-select:disabled {
            opacity: 0.5; cursor: not-allowed; background: #f5f0f0;
        }

        /* Custom select arrow */
        .select-wrap::after {
            content: '\F282'; font-family: 'Bootstrap Icons';
            position: absolute; right: 12px;
            color: #bba8a8; font-size: 0.8rem; pointer-events: none;
        }

        /* Department hint */
        .dept-hint {
            font-size: 0.69rem; color: var(--muted);
            margin-top: 3px; display: flex; align-items: center; gap: 4px;
            min-height: 16px; transition: color 0.2s;
        }

        .dept-hint.has-depts { color: #1a7a4a; }
        .dept-hint.no-depts  { color: #c09060; }

        .eye-btn {
            position: absolute; right: 11px;
            background: none; border: none; cursor: pointer;
            color: #bba8a8; font-size: 0.9rem; padding: 4px;
            transition: color 0.2s; line-height: 1; z-index: 1;
        }

        .eye-btn:hover { color: var(--maroon); }

        .strength-bar {
            height: 3px; border-radius: 3px;
            background: #f0e8e8; margin-top: 5px; overflow: hidden;
        }

        .strength-fill {
            height: 100%; border-radius: 3px;
            width: 0%; transition: width 0.35s ease, background 0.35s ease;
        }

        .strength-hint { font-size: 0.68rem; color: var(--muted); margin-top: 3px; }

        .alert-error, .alert-success {
            border-radius: 9px; padding: 0.65rem 1rem;
            font-size: 0.82rem; margin-bottom: 1.25rem;
            display: flex; align-items: flex-start; gap: 9px;
            animation: fadeUp 0.3s ease both;
        }

        .alert-error   { background: #fff5f5; border-left: 3px solid var(--maroon); color: var(--maroon); }
        .alert-success { background: #f0faf5; border-left: 3px solid #1a7a4a; color: #1a5c3a; }

        .btn-register {
            width: 100%;
            background: linear-gradient(135deg, var(--maroon) 0%, var(--maroon-deep) 100%);
            color: #fff; border: none; border-radius: 10px;
            padding: 0.82rem 1rem; font-size: 0.9rem; font-weight: 600;
            font-family: 'DM Sans', sans-serif; letter-spacing: 0.04em;
            cursor: pointer; position: relative; overflow: hidden;
            transition: transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 18px rgba(122,0,0,0.26);
            margin-top: 1.5rem;
        }

        .btn-register::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, transparent 60%);
        }

        .btn-register:hover { transform: translateY(-1px); box-shadow: 0 7px 24px rgba(122,0,0,0.34); }
        .btn-register:active { transform: translateY(0); }
        .btn-register i { margin-right: 6px; }

        .login-row { margin-top: 1.1rem; text-align: center; font-size: 0.79rem; color: var(--muted); }
        .login-link { color: var(--maroon); font-weight: 600; text-decoration: none; margin-left: 4px; }
        .login-link:hover { text-decoration: underline; }

        .right-footer { margin-top: 1.75rem; padding-top: 1.1rem; border-top: 1px solid #f0e8e8; text-align: center; }
        .right-footer p { font-size: 0.69rem; color: #c0b0b0; line-height: 1.7; }
        .right-footer .dev-credit { color: var(--maroon); font-weight: 600; }

        .req { color: var(--maroon); margin-left: 2px; }

        @media (max-width: 750px) {
            .register-outer { grid-template-columns: 1fr; }
            .panel-left { padding: 2rem 1.5rem 1.5rem; }
            .steps-wrap, .left-back { display: none; }
            .left-title { font-size: 1.1rem; }
            .logo-wrap, .logo-bg { width: 90px; height: 90px; }
            .logo-bg img { width: 74px; height: 74px; }
            .panel-right { padding: 2rem 1.5rem; }
            .grid-2 { grid-template-columns: 1fr; }
            .form-headline { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="register-outer">

    <!-- ══ Left Panel ══ -->
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

        <div class="role-badge">
            <i class="bi bi-person-fill"></i> Requestor Registration
        </div>

        <div class="steps-wrap">
            <div class="step-item">
                <div class="step-dot">1</div>
                <div class="step-text">
                    <span class="step-label">Personal Info</span>
                    Your Fullname, Email and contact details
                </div>
            </div>
            <div class="step-item">
                <div class="step-dot">2</div>
                <div class="step-text">
                    <span class="step-label">Office & Department</span>
                    Select your office — departments filter automatically
                </div>
            </div>
            <div class="step-item">
                <div class="step-dot">3</div>
                <div class="step-text">
                    <span class="step-label">Account Credentials</span>
                    Choose a secure Fullname and password
                </div>
            </div>
        </div>

        <div class="left-back">
            <a href="login.php" class="back-link">
                <i class="bi bi-arrow-left"></i> Back to Sign In
            </a>
        </div>
    </div>

    <!-- ══ Right Panel ══ -->
    <div class="panel-right">

        <div class="form-eyebrow">
            <i class="bi bi-person-plus-fill"></i> New Account
        </div>
        <div class="form-headline">Create your<br><span>Requestor account</span></div>
        <div class="form-desc">Complete the form below to register for vehicle scheduling access.</div>

        <!-- Requestor-only notice -->
        <div class="requestor-notice">
            <i class="bi bi-shield-exclamation"></i>
            <span>
                This registration is for <strong>Requestors only.</strong>
                Staff and Admin accounts are created exclusively by system administrators.
            </span>
        </div>

        <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-circle-fill" style="flex-shrink:0;margin-top:1px"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert-success">
            <i class="bi bi-check-circle-fill" style="flex-shrink:0;margin-top:1px"></i>
            <span>
                <?= htmlspecialchars($success) ?>
                <a href="login.php" style="color:#1a5c3a;font-weight:700;margin-left:4px;">Go to login &rarr;</a>
            </span>
        </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">

            <!-- 1. Personal Info -->
            <div class="section-label">
                <i class="bi bi-person-fill"></i> Personal Information
            </div>

            

            <div class="field-grid grid-2" style="margin-top:0.85rem">
                <div class="field-group">
                    <label class="field-label">Email Address <span class="req">*</span></label>
                    <div class="field-wrap">
                        <i class="bi bi-envelope field-icon"></i>
                        <input type="email" name="email" class="field-input"
                               value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                               placeholder="you@csu.edu.ph" required>
                    </div>
                </div>
                <div class="field-group">
                    <label class="field-label">Contact No.</label>
                    <div class="field-wrap">
                        <i class="bi bi-telephone field-icon"></i>
                        <input type="text" name="contact_no" class="field-input"
                               value="<?= htmlspecialchars($formData['contact_no'] ?? '') ?>"
                               placeholder="e.g. 09XX-XXX-XXXX">
                    </div>
                </div>
            </div>

            <!-- 2. Office & Department -->
            <div class="section-label">
                <i class="bi bi-building"></i> Office & Department
            </div>

            <div class="field-grid grid-2">
                <!-- Office -->
                <div class="field-group">
                    <label class="field-label">Office <span class="req">*</span></label>
                    <div class="field-wrap select-wrap">
                        <i class="bi bi-building field-icon"></i>
                        <select name="office_id" id="office_select" class="field-input field-select"
                                required onchange="filterDepartments(this.value)">
                            <option value="">— Select Office —</option>
                            <?php foreach ($offices as $o): ?>
                            <option value="<?= $o['office_id'] ?>"
                                <?= ($formData['office_id'] ?? 0) == $o['office_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($o['office_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Department — filtered dynamically -->
                <div class="field-group">
                    <label class="field-label">Department</label>
                    <div class="field-wrap select-wrap">
                        <i class="bi bi-diagram-3 field-icon"></i>
                        <select name="dept_id" id="dept_select" class="field-input field-select" disabled>
                            <option value="">— Select Office first —</option>
                        </select>
                    </div>
                    <div class="dept-hint" id="dept_hint">
                        <i class="bi bi-arrow-up-left"></i> Choose an office to see its departments
                    </div>
                </div>
            </div>

            <!-- 3. Credentials -->
            <div class="section-label">
                <i class="bi bi-shield-lock"></i> Account Credentials
            </div>

            <div class="field-grid" style="margin-bottom:0.85rem">
                <div class="field-group">
                    <label class="field-label">Username <span class="req">*</span></label>
                    <div class="field-wrap">
                        <i class="bi bi-at field-icon"></i>
                        <input type="text" name="username" class="field-input"
                               value="<?= htmlspecialchars($formData['username'] ?? '') ?>"
                               placeholder="Fullname" required minlength="4"
                               autocomplete="new-password">
                    </div>
                </div>
            </div>

            <div class="field-grid grid-2">
                <div class="field-group">
                    <label class="field-label">Password <span class="req">*</span></label>
                    <div class="field-wrap">
                        <i class="bi bi-lock field-icon"></i>
                        <input type="password" name="password" id="pw1" class="field-input"
                               placeholder="Min. 8 characters" required minlength="8"
                               autocomplete="new-password" oninput="checkStrength(this.value)">
                        <button type="button" class="eye-btn" onclick="togglePw('pw1','eye1')">
                            <i class="bi bi-eye" id="eye1"></i>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                    <div class="strength-hint" id="strength-hint">Enter a password</div>
                </div>
                <div class="field-group">
                    <label class="field-label">Confirm Password <span class="req">*</span></label>
                    <div class="field-wrap">
                        <i class="bi bi-lock-fill field-icon"></i>
                        <input type="password" name="password2" id="pw2" class="field-input"
                               placeholder="Re-enter password" required autocomplete="new-password"
                               oninput="checkMatch()">
                        <button type="button" class="eye-btn" onclick="togglePw('pw2','eye2')">
                            <i class="bi bi-eye" id="eye2"></i>
                        </button>
                    </div>
                    <div class="strength-hint" id="match-hint" style="margin-top:3px"></div>
                </div>
            </div>

            <button type="submit" class="btn-register">
                <i class="bi bi-person-check-fill"></i> Create Requestor Account
            </button>

        </form>

        <div class="login-row">
            Already have an account?
            <a href="login.php" class="login-link"><i class="bi bi-box-arrow-in-right"></i> Sign In</a>
        </div>

        <div class="right-footer">
            <p>
                &copy; <?= date('Y') ?> <strong style="color:#800000;">Cagayan State University</strong> &middot; All rights reserved<br>
                System Developed by <span class="dev-credit">Nash Andrei Tumaliuan Vergara</span>
            </p>
        </div>

    </div><!-- /.panel-right -->
</div><!-- /.register-outer -->

<script>
/* ── Department data from PHP ── */
const deptsByOffice = <?= json_encode($deptsByOffice, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const savedDeptId   = <?= (int)($formData['dept_id']  ?? 0) ?>;
const savedOfficeId = <?= (int)($formData['office_id'] ?? 0) ?>;

function filterDepartments(officeId) {
    const deptSelect = document.getElementById('dept_select');
    const deptHint   = document.getElementById('dept_hint');

    officeId = parseInt(officeId) || 0;

    // Reset state
    deptSelect.innerHTML = '';
    deptSelect.disabled  = true;

    if (!officeId) {
        deptSelect.innerHTML = '<option value="">— Select Office first —</option>';
        deptHint.innerHTML   = '<i class="bi bi-arrow-up-left"></i> Choose an office to see its departments';
        deptHint.className   = 'dept-hint';
        return;
    }

    const depts = deptsByOffice[officeId] || [];

    if (depts.length === 0) {
        deptSelect.innerHTML = '<option value="">No departments under this office</option>';
        deptHint.innerHTML   = '<i class="bi bi-dash-circle"></i> No departments found for this office';
        deptHint.className   = 'dept-hint no-depts';
        return;
    }

    // Build options
    deptSelect.disabled = false;
    let html = '<option value="">— Select Department —</option>';
    depts.forEach(d => {
        const sel = (savedDeptId && savedDeptId === d.id) ? ' selected' : '';
        html += `<option value="${d.id}"${sel}>${d.name}</option>`;
    });
    deptSelect.innerHTML = html;

    deptHint.innerHTML = `<i class="bi bi-check-circle"></i> ${depts.length} department${depts.length !== 1 ? 's' : ''} available`;
    deptHint.className = 'dept-hint has-depts';
}

/* Restore state on validation error (page reloaded with POST data) */
window.addEventListener('DOMContentLoaded', () => {
    if (savedOfficeId) {
        document.getElementById('office_select').value = savedOfficeId;
        filterDepartments(savedOfficeId);
    }
});

/* ── Eye toggle ── */
function togglePw(id, iconId) {
    const pw  = document.getElementById(id);
    const eye = document.getElementById(iconId);
    if (pw.type === 'password') {
        pw.type = 'text';
        eye.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        pw.type = 'password';
        eye.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

/* ── Password strength ── */
function checkStrength(val) {
    const fill = document.getElementById('strength-fill');
    const hint = document.getElementById('strength-hint');
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: '0%',   color: '#e8d8d8', text: 'Enter a password' },
        { pct: '25%',  color: '#e03a3a', text: 'Weak' },
        { pct: '50%',  color: '#e07a2a', text: 'Fair' },
        { pct: '75%',  color: '#d4a017', text: 'Good' },
        { pct: '100%', color: '#1a7a4a', text: 'Strong ✓' },
    ];

    const lvl = val.length === 0 ? levels[0] : (levels[score] || levels[1]);
    fill.style.width      = lvl.pct;
    fill.style.background = lvl.color;
    hint.textContent      = lvl.text;
    hint.style.color      = lvl.color === '#e8d8d8' ? '#7a6060' : lvl.color;
    checkMatch();
}

/* ── Password match ── */
function checkMatch() {
    const pw1  = document.getElementById('pw1').value;
    const pw2  = document.getElementById('pw2').value;
    const hint = document.getElementById('match-hint');
    if (!pw2) { hint.textContent = ''; return; }
    if (pw1 === pw2) {
        hint.textContent = 'Passwords match ✓';
        hint.style.color = '#1a7a4a';
    } else {
        hint.textContent = 'Passwords do not match';
        hint.style.color = '#e03a3a';
    }
}
</script>
</body>
</html>