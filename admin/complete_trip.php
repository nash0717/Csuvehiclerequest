<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

header('Content-Type: application/json');

$scheduleId  = (int)($_POST['schedule_id']  ?? 0);
$arrivedDate = trim($_POST['arrived_date']  ?? '');
$arrivedTime = trim($_POST['arrived_time']  ?? '');

if (!$scheduleId || !$arrivedDate || !$arrivedTime) {
    echo json_encode(['ok' => false, 'msg' => 'Missing required fields.']);
    exit;
}

$arrivedAt = $arrivedDate . ' ' . $arrivedTime . ':00';

try {
    // Ensure arrived_at column exists
    $col = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'arrived_at'");
    if ($col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE schedules ADD COLUMN arrived_at DATETIME NULL");
    }

    // Check the row exists
    $check = $pdo->prepare("SELECT status FROM schedules WHERE schedule_id = ?");
    $check->execute([$scheduleId]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => "No schedule found with ID {$scheduleId}. Please refresh the page."]);
        exit;
    }

    $currentStatus = trim((string)($row['status'] ?? ''));

    if (strtolower($currentStatus) === 'completed') {
        $_SESSION['flash']['success'] = 'Trip already marked as completed.';
        echo json_encode(['ok' => true]);
        exit;
    }

    // Must be OnTrip to mark complete
    if (strtolower($currentStatus) !== 'ontrip') {
        echo json_encode(['ok' => false, 'msg' => "Cannot complete: status is '{$currentStatus}'. Trip must be marked as On Trip first."]);
        exit;
    }

    // Update status to Completed and record arrival
    $stmt = $pdo->prepare(
        "UPDATE schedules
         SET status = 'Completed', arrived_at = ?
         WHERE schedule_id = ? AND status = 'OnTrip'"
    );
    $stmt->execute([$arrivedAt, $scheduleId]);
    $affected = $stmt->rowCount();

    if ($affected > 0) {
        $_SESSION['flash']['success'] = 'Trip marked as completed successfully.';
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Update affected 0 rows. Please refresh and try again.']);
    }

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => 'DB error: ' . $e->getMessage()]);
}