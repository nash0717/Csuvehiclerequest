<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once 'includes/db.php';
require_once 'includes/functions.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Must have completed OTP verification
if (empty($_SESSION['fp_verified']) || empty($_SESSION['fp_user_id'])) {
    header("Location: Forgot_password.php");
    exit;
}

$user_id = (int)$_SESSION['fp_user_id'];
$message = '';
$msgType = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    // Trim whitespace
    $password  = trim($password);
    $password2 = trim($password2);

    if (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
        $msgType = 'danger';
    } elseif ($password !== $password2) {
        $message = "Passwords do not match.";
        $msgType = 'danger';
    } else {
        try {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt   = $pdo->prepare("UPDATE users SET password=? WHERE user_id=?");
            $stmt->execute([$hashed, $user_id]);

            $rows = $stmt->rowCount();
            if ($rows === 0) {
                $message = "Error updating password. User not found.";
                $msgType = 'danger';
            } else {
                // Clear all session fp_ data
                unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_email'], $_SESSION['fp_verified']);

                $success = true;
                $message = "Your password has been reset successfully! You can now log in.";
                $msgType = 'success';
            }
        } catch (Exception $e) {
            $message = "An error occurred: " . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// Detect login file name
$loginFile = file_exists('login.php') ? 'login.php' : 'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password – CSU RESET PASSWORD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            background: #f5f0f0;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Segoe UI', sans-serif;
            background-image:
                radial-gradient(circle at 20% 20%, rgba(128,0,0,0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(128,0,0,0.06) 0%, transparent 50%);
        }
        .fp-wrapper { width: 100%; max-width: 440px; padding: 1rem; }
        .fp-card { background: #fff; border-radius: 20px; overflow: hidden; box-shadow: 0 8px 40px rgba(128,0,0,0.15); }

        .fp-header {
            background: linear-gradient(145deg, #800000, #a00000);
            padding: 2rem 2rem 1.75rem;
            text-align: center; position: relative;
        }
        .fp-header::after {
            content: ''; position: absolute;
            bottom: -1px; left: 0; right: 0;
            height: 28px; background: #fff;
            border-radius: 50% 50% 0 0 / 100% 100% 0 0;
        }
        .icon-wrap {
            width: 64px; height: 64px; border-radius: 50%;
            background: rgba(255,255,255,0.15);
            margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
        }
        .icon-wrap i { font-size: 1.8rem; color: #fff; }
        .fp-header h4 { color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 4px; }
        .fp-header p  { color: rgba(255,255,255,0.75); font-size: 0.8rem; margin: 0; }

        .fp-body { padding: 1.75rem 2rem 2rem; }

        /* Step Indicator */
        .step-indicator { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 0.5rem; }
        .step-dot {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700;
            transition: all 0.3s;
        }
        .step-dot.active   { background: #800000; color: #fff; box-shadow: 0 0 0 3px rgba(128,0,0,0.2); }
        .step-dot.done     { background: #e6f4ea; color: #137333; }
        .step-dot.inactive { background: #f0e5e5; color: #bbb; }
        .step-line { height: 2px; width: 36px; background: #f0e5e5; }
        .step-line.done { background: #800000; }

        .step-labels { display: flex; justify-content: space-between; font-size: 0.7rem; color: #aaa; margin-top: 6px; margin-bottom: 1.25rem; padding: 0 2px; }
        .step-labels span.active-label { color: #800000; font-weight: 600; }

        .form-label { font-size: 0.85rem; font-weight: 600; color: #4a4a4a; margin-bottom: 6px; }

        .input-group-text {
            background: #f9f3f3; border: 1.5px solid #e0d0d0;
            border-right: none; color: #800000;
            cursor: pointer;
        }
        .input-group-text.toggle { border-left: none; border-right: 1.5px solid #e0d0d0; }
        .form-control { border: 1.5px solid #e0d0d0; border-left: none; font-size: 0.9rem; padding: 0.55rem 0.75rem; color: #333; }
        .form-control:focus { border-color: #800000; box-shadow: none; }
        .input-group:focus-within .input-group-text { border-color: #800000; }

        .btn-primary-maroon {
            background: #800000; color: #fff; border: none;
            border-radius: 10px; padding: 0.65rem;
            font-size: 0.95rem; font-weight: 600; width: 100%;
            transition: background 0.2s, transform 0.1s;
            cursor: pointer;
        }
        .btn-primary-maroon:hover  { background: #6b0000; color: #fff; }
        .btn-primary-maroon:active { transform: scale(0.98); }

        .back-link { display: block; text-align: center; margin-top: 1rem; font-size: 0.82rem; color: #800000; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        .fp-footer { background: #fdf8f8; border-top: 1px solid #f0e5e5; text-align: center; padding: 0.85rem; font-size: 0.78rem; color: #999; }
        .fp-footer span { color: #800000; font-weight: 600; }

        .alert { border-radius: 10px; font-size: 0.85rem; padding: 0.65rem 1rem; }
        .alert-success { background: #f0fdf4; border: 1px solid #b7ebc8; color: #186429; }
        .alert-danger  { background: #fff0f0; border: 1px solid #f5c2c2; color: #800000; }

        /* Password strength */
        .strength-wrap { margin-top: 8px; }
        .strength-bar { height: 5px; border-radius: 4px; background: #eee; overflow: hidden; }
        .strength-fill { height: 100%; width: 0; border-radius: 4px; transition: width 0.3s, background 0.3s; }
        .strength-label { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; }
        .strength-text { font-size: 0.75rem; font-weight: 600; }
        .strength-hint { font-size: 0.72rem; color: #aaa; }

        /* Requirements checklist */
        .pw-requirements { margin-top: 8px; }
        .pw-req { font-size: 0.75rem; color: #bbb; display: flex; align-items: center; gap: 5px; margin-bottom: 2px; transition: color 0.2s; }
        .pw-req.met { color: #27ae60; }
        .pw-req i { font-size: 0.7rem; }

        /* Match indicator */
        .match-text { font-size: 0.78rem; margin-top: 5px; font-weight: 500; }

        /* Success */
        .success-wrap { text-align: center; padding: 1rem 0; }
        .success-wrap .big-icon { font-size: 3.5rem; color: #27ae60; margin-bottom: 0.75rem; }
        .success-wrap p { color: #666; font-size: 0.88rem; margin-bottom: 1.25rem; }

        /* Auto redirect bar */
        .redirect-bar { height: 4px; background: #e0d0d0; border-radius: 4px; overflow: hidden; margin-bottom: 1rem; }
        .redirect-fill { height: 100%; background: #800000; border-radius: 4px; width: 100%; transition: width linear; }
    </style>
</head>
<body>
<div class="fp-wrapper">
    <div class="fp-card">

        <div class="fp-header">
            <div class="icon-wrap">
                <i class="bi bi-<?= $success ? 'check-circle' : 'lock-fill' ?>"></i>
            </div>
            <h4><?= $success ? 'Password Reset!' : 'Set New Password' ?></h4>
            <p><?= $success ? 'Your account is secured' : 'Choose a strong new password' ?></p>
        </div>

        <div class="fp-body">

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step-dot done"><i class="bi bi-check"></i></div>
                <div class="step-line done"></div>
                <div class="step-dot done"><i class="bi bi-check"></i></div>
                <div class="step-line done"></div>
                <div class="step-dot <?= $success ? 'done' : 'active' ?>">
                    <?= $success ? '<i class="bi bi-check"></i>' : '3' ?>
                </div>
            </div>
            <div class="step-labels">
                <span>Email</span>
                <span style="margin-left:10px;">Verify</span>
                <span class="<?= !$success ? 'active-label' : '' ?>">Reset</span>
            </div>

            <?php if ($message && !$success): ?>
            <div class="alert alert-<?= $msgType ?> mb-3">
                <i class="bi bi-exclamation-circle me-1"></i>
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <!-- ── Success State ── -->
            <div class="success-wrap">
                <div class="big-icon"><i class="bi bi-patch-check-fill"></i></div>
                <p>Your password has been updated successfully. You can now sign in with your new password.</p>
                <div class="redirect-bar"><div class="redirect-fill" id="redirectFill"></div></div>
                <p style="font-size:0.75rem;color:#aaa;margin-bottom:1rem;">Redirecting to login in <span id="redirectCount">5</span>s…</p>
            </div>
            <a href="<?= $loginFile ?>" class="btn-primary-maroon text-center text-decoration-none d-block py-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login Now
            </a>

            <?php else: ?>
            <!-- ── Reset Form ── -->
            <form method="POST" id="resetForm">
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="password" id="newPassword"
                               class="form-control" placeholder="At least 8 characters"
                               required autofocus autocomplete="new-password"
                               oninput="checkStrength(this.value); checkMatch();">
                        <span class="input-group-text toggle" onclick="togglePw('newPassword', this)" style="cursor:pointer;">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                    <!-- Strength bar -->
                    <div class="strength-wrap">
                        <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                        <div class="strength-label">
                            <span class="strength-text" id="strengthText"></span>
                            <span class="strength-hint">Min. 8 characters</span>
                        </div>
                    </div>
                    <!-- Requirements -->
                    <div class="pw-requirements">
                        <div class="pw-req" id="req-len"><i class="bi bi-circle-fill"></i> At least 8 characters</div>
                        <div class="pw-req" id="req-upper"><i class="bi bi-circle-fill"></i> One uppercase letter</div>
                        <div class="pw-req" id="req-num"><i class="bi bi-circle-fill"></i> One number</div>
                        <div class="pw-req" id="req-special"><i class="bi bi-circle-fill"></i> One special character</div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="password2" id="confirmPassword"
                               class="form-control" placeholder="Re-enter your password"
                               required autocomplete="new-password"
                               oninput="checkMatch()">
                        <span class="input-group-text toggle" onclick="togglePw('confirmPassword', this)" style="cursor:pointer;">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                    <div class="match-text" id="matchText"></div>
                </div>

                <button type="submit" class="btn-primary-maroon" id="submitBtn">
                    <i class="bi bi-check-lg me-1"></i> Reset Password
                </button>
            </form>
            <?php endif; ?>

            <a href="<?= $loginFile ?>" class="back-link">
                <i class="bi bi-arrow-left me-1"></i> Back to Login
            </a>
        </div>

        <div class="fp-footer">
            &copy; <?= date('Y') ?> <span>Cagayan State University</span> · All rights reserved
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Toggle password visibility ────────────────────────────────────────────────
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// ── Password strength ─────────────────────────────────────────────────────────
function checkStrength(pw) {
    const fill  = document.getElementById('strengthFill');
    const text  = document.getElementById('strengthText');

    const reqs = {
        len:     pw.length >= 8,
        upper:   /[A-Z]/.test(pw),
        num:     /[0-9]/.test(pw),
        special: /[^A-Za-z0-9]/.test(pw),
    };

    // Update requirement indicators
    toggleReq('req-len',     reqs.len);
    toggleReq('req-upper',   reqs.upper);
    toggleReq('req-num',     reqs.num);
    toggleReq('req-special', reqs.special);

    const score = Object.values(reqs).filter(Boolean).length;
    const levels = [
        { pct: 0,   color: '#eee',    label: '' },
        { pct: 25,  color: '#e74c3c', label: 'Weak' },
        { pct: 50,  color: '#f39c12', label: 'Fair' },
        { pct: 75,  color: '#27ae60', label: 'Good' },
        { pct: 100, color: '#1a8a4a', label: 'Strong' },
    ];
    const lvl = levels[Math.min(score, 4)];
    fill.style.width      = lvl.pct + '%';
    fill.style.background = lvl.color;
    text.textContent      = pw.length ? lvl.label : '';
    text.style.color      = lvl.color;
}

function toggleReq(id, met) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('met', met);
    el.querySelector('i').className = met ? 'bi bi-check-circle-fill' : 'bi bi-circle-fill';
}

// ── Password match ────────────────────────────────────────────────────────────
function checkMatch() {
    const p1   = document.getElementById('newPassword').value;
    const p2   = document.getElementById('confirmPassword').value;
    const text = document.getElementById('matchText');
    const btn  = document.getElementById('submitBtn');
    if (!p2) { text.textContent = ''; return; }
    if (p1 === p2) {
        text.textContent = '✓ Passwords match';
        text.style.color = '#27ae60';
        if (btn) btn.disabled = false;
    } else {
        text.textContent = '✗ Passwords do not match';
        text.style.color = '#e74c3c';
        if (btn) btn.disabled = true;
    }
}

// ── Auto-redirect after success ───────────────────────────────────────────────
const redirectFill = document.getElementById('redirectFill');
const redirectCount = document.getElementById('redirectCount');
if (redirectFill && redirectCount) {
    let secs = 5;
    redirectFill.style.transition = 'width ' + secs + 's linear';
    setTimeout(() => { redirectFill.style.width = '0%'; }, 50);

    const tick = setInterval(() => {
        secs--;
        redirectCount.textContent = secs;
        if (secs <= 0) {
            clearInterval(tick);
            window.location.href = '<?= $loginFile ?>';
        }
    }, 1000);
}
</script>
</body>
</html>