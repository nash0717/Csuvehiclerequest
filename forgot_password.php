<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once 'includes/db.php';
require_once 'includes/functions.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Ensure password_resets table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(6) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(token)
    )");
} catch (Exception $e) { /* already exists */ }

$message = '';
$msgType = '';
$step    = $_SESSION['fp_step'] ?? 1;

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // STEP 1: Submit email
    if ($action === 'send_otp') {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $msgType = 'danger';
        } else {
            $stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE LOWER(Email) = LOWER(?)");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = "If that email is registered, a verification code has been sent.";
                $msgType = 'success';
            } else {
                $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                $pdo->prepare("UPDATE password_resets SET used=1 WHERE user_id=?")->execute([$user['user_id']]);

                $ins = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)");
                $ins->execute([$user['user_id'], $otp, $expires]);

                $sent = sendOTPEmail($email, $user['username'], $otp);

                if ($sent) {
                    $_SESSION['fp_step']    = 2;
                    $_SESSION['fp_user_id'] = $user['user_id'];
                    $_SESSION['fp_email']   = $email;
                    $step    = 2;
                    $message = "A 6-digit verification code has been sent to <strong>" . htmlspecialchars($email) . "</strong>. It expires in 15 minutes.";
                    $msgType = 'success';
                } else {
                    $message = "Failed to send email. Please check your mail configuration or try again.";
                    $msgType = 'danger';
                }
            }
        }
    }

    // STEP 2: Verify OTP
    elseif ($action === 'verify_otp') {
        $otp     = trim($_POST['otp'] ?? '');
        $user_id = (int)($_SESSION['fp_user_id'] ?? 0);

        if (empty($otp) || strlen($otp) !== 6 || !ctype_digit($otp)) {
            $message = "Please enter the 6-digit code.";
            $msgType = 'danger';
            $step    = 2;
        } elseif (!$user_id) {
            $message = "Session expired. Please start again.";
            $msgType = 'danger';
            unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_email']);
            $step = 1;
        } else {
            $stmt = $pdo->prepare("
                SELECT id FROM password_resets
                WHERE user_id=? AND token=? AND used=0 AND expires_at > NOW()
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$user_id, $otp]);
            $reset = $stmt->fetch();

            if (!$reset) {
                $message = "Invalid or expired code. Please try again.";
                $msgType = 'danger';
                $step    = 2;
            } else {
                $pdo->prepare("UPDATE password_resets SET used=1 WHERE id=?")->execute([$reset['id']]);
                $_SESSION['fp_verified'] = true;
                $_SESSION['fp_step']     = 3;
                header("Location: Reset_password.php");
                exit;
            }
        }
    }

    // Resend OTP
    elseif ($action === 'resend_otp') {
        unset($_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_email'], $_SESSION['fp_verified']);
        header("Location: Forgot_password.php");
        exit;
    }
}

// ── PHPMailer Email Sender ─────────────────────────────────────────────────────
function sendOTPEmail($to, $username, $otp) {
   require_once 'includes/PHPMailer/src/PHPMailer.php';
require_once 'includes/PHPMailer/src/SMTP.php';
require_once 'includes/PHPMailer/src/Exception.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'csuvehiclerequest@gmail.com';
        $mail->Password   = 'doex bzmx adna yjri'; // App Password (no spaces)
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender & recipient
        $mail->setFrom('csuvehiclerequest@gmail.com', 'CSU Vehicle Scheduling System');
        $mail->addAddress($to, $username);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'CSU VSS – Your Password Reset Code';
        $mail->Body    = "
        <div style='font-family:Segoe UI,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #f0e5e5;'>
            <div style='background:linear-gradient(135deg,#800000,#a00000);padding:28px 32px;text-align:center;'>
                <h2 style='color:#fff;margin:0;font-size:1.2rem;font-weight:700;'>CSU Vehicle Scheduling System</h2>
                <p style='color:rgba(255,255,255,0.75);margin:4px 0 0;font-size:0.82rem;'>Cagayan State University</p>
            </div>
            <div style='padding:32px;'>
                <p style='color:#444;font-size:0.95rem;margin-bottom:8px;'>Hello, <strong>" . htmlspecialchars($username) . "</strong></p>
                <p style='color:#666;font-size:0.88rem;margin-bottom:24px;'>We received a request to reset your password. Use the verification code below:</p>
                <div style='background:#fdf5f5;border:2px dashed #d9a0a0;border-radius:12px;text-align:center;padding:20px 32px;margin-bottom:24px;'>
                    <div style='font-size:2.2rem;font-weight:700;letter-spacing:10px;color:#800000;'>{$otp}</div>
                    <div style='font-size:0.78rem;color:#999;margin-top:6px;'>Expires in 15 minutes</div>
                </div>
                <p style='color:#888;font-size:0.8rem;'>If you did not request a password reset, you can safely ignore this email.</p>
            </div>
            <div style='background:#fdf8f8;border-top:1px solid #f0e5e5;text-align:center;padding:14px;font-size:0.75rem;color:#aaa;'>
                &copy; " . date('Y') . " Cagayan State University &middot; All rights reserved
            </div>
        </div>";

        $mail->send();
        return true;

  } catch (Exception $e) {
        echo "<pre>PHPMailer Error: " . $mail->ErrorInfo . "</pre>";
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password – CSU VSS</title>
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
        .fp-header .icon-wrap {
            width: 64px; height: 64px; border-radius: 50%;
            background: rgba(255,255,255,0.15);
            margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
        }
        .fp-header .icon-wrap i { font-size: 1.8rem; color: #fff; }
        .fp-header h4 { color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 4px; }
        .fp-header p  { color: rgba(255,255,255,0.75); font-size: 0.8rem; margin: 0; }

        .fp-body { padding: 1.75rem 2rem 2rem; }

        .step-indicator { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 0.5rem; }
        .step-dot {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700; transition: all 0.3s;
        }
        .step-dot.active   { background: #800000; color: #fff; box-shadow: 0 0 0 3px rgba(128,0,0,0.2); }
        .step-dot.done     { background: #e6f4ea; color: #137333; }
        .step-dot.inactive { background: #f0e5e5; color: #bbb; }
        .step-line { height: 2px; width: 36px; background: #f0e5e5; transition: background 0.3s; }
        .step-line.done { background: #800000; }

        .step-labels { display: flex; justify-content: space-between; font-size: 0.7rem; color: #aaa; margin-top: 6px; margin-bottom: 1.25rem; padding: 0 2px; }
        .step-labels span.active-label { color: #800000; font-weight: 600; }

        .form-label { font-size: 0.85rem; font-weight: 600; color: #4a4a4a; margin-bottom: 6px; }
        .input-group-text { background: #f9f3f3; border: 1.5px solid #e0d0d0; border-right: none; color: #800000; }
        .form-control { border: 1.5px solid #e0d0d0; border-left: none; font-size: 0.9rem; padding: 0.55rem 0.75rem; color: #333; }
        .form-control:focus { border-color: #800000; box-shadow: none; }
        .input-group:focus-within .input-group-text { border-color: #800000; }

        .otp-wrapper { display: flex; gap: 8px; justify-content: center; margin-bottom: 6px; }
        .otp-box {
            width: 44px; height: 52px;
            text-align: center; font-size: 1.4rem; font-weight: 700;
            color: #800000; border: 2px solid #e0d0d0;
            border-radius: 10px; outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .otp-box:focus { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,0.12); }
        .otp-box.filled { border-color: #800000; background: #fdf5f5; }

        .btn-primary-maroon {
            background: #800000; color: #fff; border: none;
            border-radius: 10px; padding: 0.65rem;
            font-size: 0.95rem; font-weight: 600; width: 100%;
            transition: background 0.2s, transform 0.1s; cursor: pointer;
        }
        .btn-primary-maroon:hover  { background: #6b0000; color: #fff; }
        .btn-primary-maroon:active { transform: scale(0.98); }

        .resend-btn {
            background: none; border: none; width: 100%;
            color: #800000; font-size: 0.82rem; padding: 0.4rem;
            cursor: pointer; transition: opacity 0.2s;
        }
        .resend-btn:hover { opacity: 0.75; }

        .back-link { display: block; text-align: center; margin-top: 1rem; font-size: 0.82rem; color: #800000; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        .fp-footer { background: #fdf8f8; border-top: 1px solid #f0e5e5; text-align: center; padding: 0.85rem; font-size: 0.78rem; color: #999; }
        .fp-footer span { color: #800000; font-weight: 600; }

        .alert { border-radius: 10px; font-size: 0.85rem; padding: 0.6rem 1rem; }
        .alert-success { background: #f0fdf4; border: 1px solid #b7ebc8; color: #186429; }
        .alert-danger  { background: #fff0f0; border: 1px solid #f5c2c2; color: #800000; }

        .otp-timer { font-size: 0.78rem; color: #999; text-align: center; margin-top: 4px; }
        .otp-timer span { color: #800000; font-weight: 600; }

        .email-badge {
            background: #fdf5f5; border: 1px solid #f0d0d0;
            border-radius: 8px; padding: 6px 12px;
            font-size: 0.82rem; color: #800000;
            text-align: center; margin-bottom: 1rem;
            word-break: break-all;
        }
    </style>
</head>
<body>
<div class="fp-wrapper">
    <div class="fp-card">

        <div class="fp-header">
            <div class="icon-wrap">
                <i class="bi bi-<?= $step === 2 ? 'shield-lock' : 'key' ?>"></i>
            </div>
            <h4><?= $step === 2 ? 'Enter Verification Code' : 'Forgot Password' ?></h4>
            <p><?= $step === 2 ? 'Check your email for the 6-digit code' : 'We\'ll send a reset code to your email' ?></p>
        </div>

        <div class="fp-body">

            <div class="step-indicator">
                <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : 'inactive' ?>">
                    <?= $step > 1 ? '<i class="bi bi-check"></i>' : '1' ?>
                </div>
                <div class="step-line <?= $step > 1 ? 'done' : '' ?>"></div>
                <div class="step-dot <?= $step >= 2 ? 'active' : 'inactive' ?>">2</div>
                <div class="step-line"></div>
                <div class="step-dot inactive">3</div>
            </div>
            <div class="step-labels">
                <span class="<?= $step === 1 ? 'active-label' : '' ?>">Email</span>
                <span class="<?= $step === 2 ? 'active-label' : '' ?>" style="margin-left:10px;">Verify</span>
                <span>Reset</span>
            </div>

            <?php if ($message): ?>
            <div class="alert alert-<?= $msgType ?> mb-3">
                <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-1"></i>
                <?= $message ?>
            </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
            <form method="POST">
                <input type="hidden" name="action" value="send_otp">
                <div class="mb-4">
                    <label class="form-label">Registered Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                        <input type="email" name="email" class="form-control"
                               placeholder="Enter your email address"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               required autofocus>
                    </div>
                    <div class="form-text mt-1">Enter the email address linked to your account.</div>
                </div>
                <button type="submit" class="btn-primary-maroon">
                    <i class="bi bi-send me-1"></i> Send Verification Code
                </button>
            </form>

            <?php elseif ($step === 2): ?>
            <div class="email-badge">
                <i class="bi bi-envelope me-1"></i>
                Code sent to: <strong><?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?></strong>
            </div>

            <form method="POST" id="otpForm">
                <input type="hidden" name="action" value="verify_otp">
                <input type="hidden" name="otp" id="otpHidden">
                <div class="mb-3">
                    <label class="form-label text-center d-block mb-3">Enter the 6-digit code</label>
                    <div class="otp-wrapper">
                        <input type="text" class="otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                        <input type="text" class="otp-box" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    </div>
                    <div class="otp-timer mt-2">Code expires in <span id="countdown">15:00</span></div>
                </div>
                <button type="submit" class="btn-primary-maroon mb-2" id="verifyBtn" disabled>
                    <i class="bi bi-shield-check me-1"></i> Verify Code
                </button>
            </form>

            <form method="POST" class="mt-1">
                <input type="hidden" name="action" value="resend_otp">
                <button type="submit" class="resend-btn">
                    <i class="bi bi-arrow-clockwise me-1"></i> Didn't get the code? Send again
                </button>
            </form>
            <?php endif; ?>

            <a href="login.php" class="back-link">
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
const boxes     = document.querySelectorAll('.otp-box');
const hidden    = document.getElementById('otpHidden');
const verifyBtn = document.getElementById('verifyBtn');

if (boxes.length) {
    boxes.forEach((box, i) => {
        box.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 1);
            if (this.value) {
                this.classList.add('filled');
                if (i < boxes.length - 1) boxes[i + 1].focus();
            } else {
                this.classList.remove('filled');
            }
            syncOTP();
        });

        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && i > 0) {
                boxes[i - 1].value = '';
                boxes[i - 1].classList.remove('filled');
                boxes[i - 1].focus();
                syncOTP();
            }
        });

        box.addEventListener('paste', function (e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
            pasted.split('').forEach((ch, idx) => {
                if (boxes[idx]) { boxes[idx].value = ch; boxes[idx].classList.add('filled'); }
            });
            if (boxes[Math.min(pasted.length, 5)]) boxes[Math.min(pasted.length, 5)].focus();
            syncOTP();
        });
    });
    boxes[0].focus();
}

function syncOTP() {
    const val = Array.from(boxes).map(b => b.value).join('');
    if (hidden) hidden.value = val;
    if (verifyBtn) verifyBtn.disabled = val.length !== 6;
}

const countdownEl = document.getElementById('countdown');
if (countdownEl) {
    let seconds = 15 * 60;
    const timer = setInterval(() => {
        seconds--;
        if (seconds <= 0) {
            clearInterval(timer);
            countdownEl.textContent = '00:00';
            countdownEl.style.color = '#e74c3c';
            if (verifyBtn) { verifyBtn.disabled = true; verifyBtn.textContent = 'Code Expired'; }
            return;
        }
        const m = String(Math.floor(seconds / 60)).padStart(2, '0');
        const s = String(seconds % 60).padStart(2, '0');
        countdownEl.textContent = `${m}:${s}`;
        if (seconds <= 60) countdownEl.style.color = '#e74c3c';
    }, 1000);
}
</script>
</body>
</html>