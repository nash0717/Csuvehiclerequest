<?php
/**
 * check_availability.php
 * Returns JSON { conflict: bool, message: string }
 * Used by the Add Schedule modal for live availability checking.
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

header('Content-Type: application/json');

if (!isset($_GET['check'])) {
    echo json_encode(['conflict' => false, 'message' => '']);
    exit;
}

$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);
$driver_id  = (int)($_GET['driver_id']  ?? 0);
$date_start = $_GET['date_start'] ?? '';
$date_end   = $_GET['date_end']   ?? '';
$time_start = $_GET['time_start'] ?? '00:00';
$time_end   = $_GET['time_end']   ?? '23:59';

if (!$vehicle_id || !$driver_id || !$date_start || !$date_end) {
    echo json_encode(['conflict' => false, 'message' => '']);
    exit;
}

/* ── Vehicle conflict ────────────────────────────────────────── */
$vStmt = $pdo->prepare(
    "SELECT s.schedule_id, s.date_start, s.date_end, s.time_start, s.time_end,
            u.username
     FROM schedules s
     JOIN users u ON s.user_id = u.user_id
     WHERE s.vehicle_id = ?
       AND s.status = 'Approved'
       AND s.date_start <= ?
       AND s.date_end   >= ?
       AND s.time_start < ?
       AND s.time_end   > ?
     LIMIT 1"
);
$vStmt->execute([$vehicle_id, $date_end, $date_start, $time_end, $time_start]);
$vConflict = $vStmt->fetch();

if ($vConflict) {
    echo json_encode([
        'conflict' => true,
        'message'  => "Vehicle is already booked by \"{$vConflict['username']}\" "
                    . "from {$vConflict['date_start']} to {$vConflict['date_end']} "
                    . "({$vConflict['time_start']} – {$vConflict['time_end']}). "
                    . "Please choose a different vehicle or time slot."
    ]);
    exit;
}

/* ── Driver conflict ─────────────────────────────────────────── */
$dStmt = $pdo->prepare(
    "SELECT s.schedule_id, s.date_start, s.date_end, s.time_start, s.time_end,
            u.username
     FROM schedules s
     JOIN users u ON s.user_id = u.user_id
     WHERE s.driver_id = ?
       AND s.status = 'Approved'
       AND s.date_start <= ?
       AND s.date_end   >= ?
       AND s.time_start < ?
       AND s.time_end   > ?
     LIMIT 1"
);
$dStmt->execute([$driver_id, $date_end, $date_start, $time_end, $time_start]);
$dConflict = $dStmt->fetch();

if ($dConflict) {
    echo json_encode([
        'conflict' => true,
        'message'  => "Driver is already assigned to \"{$dConflict['username']}\" "
                    . "from {$dConflict['date_start']} to {$dConflict['date_end']} "
                    . "({$dConflict['time_start']} – {$dConflict['time_end']}). "
                    . "Please choose a different driver or time slot."
    ]);
    exit;
}

echo json_encode(['conflict' => false, 'message' => 'Available']);