<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Auth check - allow both admin and staff
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized.']);
    exit;
}

$scheduleId = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
if ($scheduleId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid schedule ID.']);
    exit;
}

if (empty($_FILES['signed_ticket']) || $_FILES['signed_ticket']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
    ];
    $code = $_FILES['signed_ticket']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['ok' => false, 'msg' => $uploadErrors[$code] ?? 'Upload error code: ' . $code]);
    exit;
}

$file     = $_FILES['signed_ticket'];
$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed  = ['pdf', 'jpg', 'jpeg', 'png'];
$maxSize  = 10 * 1024 * 1024; // 10MB

if (!in_array($ext, $allowed)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid file type. Only PDF, JPG, PNG allowed.']);
    exit;
}
if ($file['size'] > $maxSize) {
    echo json_encode(['ok' => false, 'msg' => 'File too large. Maximum is 10MB.']);
    exit;
}

// Validate it's actually an image/pdf (not just extension)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowedMimes)) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid file content detected.']);
    exit;
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/signed_tickets/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Could not create upload directory. Check server permissions.']);
        exit;
    }
}

// Check directory is writable
if (!is_writable($uploadDir)) {
    echo json_encode(['ok' => false, 'msg' => 'Upload directory is not writable. Check server permissions.']);
    exit;
}

// Delete old signed ticket if exists
$check = $pdo->prepare("SELECT signed_ticket_path FROM schedules WHERE schedule_id = ?");
$check->execute([$scheduleId]);
$existing = $check->fetchColumn();
if ($existing && file_exists(__DIR__ . '/../' . $existing)) {
    @unlink(__DIR__ . '/../' . $existing);
}

// Save new file
$filename = 'ticket_' . $scheduleId . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['ok' => false, 'msg' => 'Failed to save file. Check folder permissions.']);
    exit;
}

// Store relative path in DB
$relativePath = 'uploads/signed_tickets/' . $filename;
$update = $pdo->prepare("UPDATE schedules SET signed_ticket_path = ? WHERE schedule_id = ?");
$update->execute([$relativePath, $scheduleId]);

if ($update->rowCount() === 0) {
    echo json_encode(['ok' => false, 'msg' => 'File saved but DB update failed. Schedule ID may not exist.']);
    exit;
}

echo json_encode(['ok' => true, 'path' => $relativePath]);