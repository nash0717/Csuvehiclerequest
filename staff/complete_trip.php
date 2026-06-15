<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    echo json_encode(['ok' => false, 'msg' => 'Not authorized']);
    exit;
}

$schedule_id  = (int)($_POST['schedule_id'] ?? 0);
$arrived_date = trim($_POST['arrived_date'] ?? '');
$arrived_time = trim($_POST['arrived_time'] ?? '');

if (!$schedule_id || !$arrived_date || !$arrived_time) {
    echo json_encode(['ok' => false, 'msg' => 'Missing required fields.']);
    exit;
}

$arrived_at = $arrived_date . ' ' . $arrived_time . ':00';

try {
    // Verify the schedule belongs to staff's office
    $cu = $pdo->prepare("SELECT office_id FROM users WHERE user_id = ?");
    $cu->execute([$_SESSION['user_id']]);
    $me = $cu->fetch();
    $myOfficeId = (int)($me['office_id'] ?? 0);

    $stmt = $pdo->prepare("UPDATE schedules 
                           SET arrived_at = ?, status = 'Completed' 
                           WHERE schedule_id = ? 
                           AND office_id = ?
                           AND status IN ('OnTrip', 'Approved')");
    $stmt->execute([$arrived_at, $schedule_id, $myOfficeId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Schedule not found or already completed.']);
    }
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => 'DB error: ' . $e->getMessage()]);
}