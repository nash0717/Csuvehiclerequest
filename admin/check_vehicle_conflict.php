<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
header('Content-Type: application/json');

$vehicleId = (int)($_POST['vehicle_id'] ?? 0);
$driverId  = (int)($_POST['driver_id']  ?? 0);
$dateStart = $_POST['date_start'] ?? '';
$dateEnd   = $_POST['date_end']   ?? '';
$timeStart = $_POST['time_start'] ?? '';
$timeEnd   = $_POST['time_end']   ?? '';
$excludeId = (int)($_POST['exclude_id'] ?? 0);

if (!$vehicleId && !$driverId) {
    echo json_encode(['conflict' => false]);
    exit;
}

$conflicts = [];

// Vehicle conflict check
if ($vehicleId) {
    $stmt = $pdo->prepare("
        SELECT s.*, u.username, v.plate_number, v.brand, v.model
        FROM schedules s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
        WHERE s.vehicle_id = ?
          AND s.schedule_id != ?
          AND s.arrived_at IS NULL
          AND s.status IN ('Approved','OnTrip')
          AND s.date_start <= ? AND s.date_end >= ?
          AND s.time_start < ? AND s.time_end > ?
    ");
    $stmt->execute([$vehicleId, $excludeId, $dateEnd, $dateStart, $timeEnd, $timeStart]);
    foreach ($stmt->fetchAll() as $row) {
        $conflicts[] = [
            'type'     => 'vehicle',
            'schedule_id' => $row['schedule_id'],
            'username' => $row['username'],
            'date_start' => $row['date_start'],
            'date_end'   => $row['date_end'],
            'time_start' => $row['time_start'],
            'time_end'   => $row['time_end'],
            'status'     => $row['status'],
            'label'      => ($row['brand'].' '.$row['model'].' ('.$row['plate_number'].')'),
        ];
    }
}

// Driver conflict check
if ($driverId) {
    $stmt = $pdo->prepare("
        SELECT s.*, u.username, d.driver_name
        FROM schedules s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN drivers d ON s.driver_id = d.driver_id
        WHERE s.driver_id = ?
          AND s.schedule_id != ?
          AND s.arrived_at IS NULL
          AND s.status IN ('Approved','OnTrip')
          AND s.date_start <= ? AND s.date_end >= ?
          AND s.time_start < ? AND s.time_end > ?
    ");
    $stmt->execute([$driverId, $excludeId, $dateEnd, $dateStart, $timeEnd, $timeStart]);
    foreach ($stmt->fetchAll() as $row) {
        $conflicts[] = [
            'type'     => 'driver',
            'schedule_id' => $row['schedule_id'],
            'username' => $row['username'],
            'date_start' => $row['date_start'],
            'date_end'   => $row['date_end'],
            'time_start' => $row['time_start'],
            'time_end'   => $row['time_end'],
            'status'     => $row['status'],
            'label'      => $row['driver_name'],
        ];
    }
}

echo json_encode(['conflict' => count($conflicts) > 0, 'details' => $conflicts]);