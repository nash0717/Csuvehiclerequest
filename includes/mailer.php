<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

/**
 * Send an HTML email via Gmail SMTP.
 * emailTemplate() has been moved to email_notifications.php
 * so it can own the full design system.
 */
function sendSystemEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'csuvehiclerequest@gmail.com';
        $mail->Password   = 'doex bzmx adna yjri';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('nashandreivergara17@gmail.com', 'CSU Vehicle Scheduling System');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer error: " . $mail->ErrorInfo);
        return false;
    }
}