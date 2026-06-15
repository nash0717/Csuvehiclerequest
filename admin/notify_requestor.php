<?php
/**
 * notify_requestor.php
 * Place in: admin/notify_requestor.php
 *
 * Sends structured notifications TO the trip requestor (staff member).
 *
 * Message format (newline-delimited, parsed by notification.php JS):
 *   Line 1  = TYPE KEYWORD
 *   Line 2+ = Key: Value
 *
 * Functions:
 *   notifyRequestorSubmitted()
 *   notifyRequestorApproved()
 *   notifyRequestorRejected()
 *   notifyRequestorCancelled()
 *   notifyRequestorRescheduled()
 *   notifyRequestorAssignmentChanged()
 *   notifyRequestorCompleted()
 *   notifyRequestorUpcoming24h()
 *   notifyRequestorUpcoming1h()
 */

/* ══════════════════════════════════════════════════════════
   INTERNAL HELPERS
══════════════════════════════════════════════════════════ */

/**
 * Core insert — writes one notification row for the requestor.
 * Auto-adds trip_id and type columns if they don't exist yet.
 */
function _insertNotif(PDO $pdo, int $userId, string $message, ?int $officeId = null, ?int $tripId = null, string $type = ''): void
{
    try {
        static $colsChecked = false;
        if (!$colsChecked) {
            $colsChecked = true;
            $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('trip_id', $cols, true))
                $pdo->exec("ALTER TABLE notifications ADD COLUMN trip_id INT NULL DEFAULT NULL");
            if (!in_array('type', $cols, true))
                $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(60) NULL DEFAULT NULL");
        }

        $pdo->prepare(
            "INSERT INTO notifications (user_id, office_id, trip_id, type, message, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())"
        )->execute([$userId, $officeId, $tripId, $type ?: null, $message]);

    } catch (PDOException $e) {
        error_log("[notify_requestor] Insert error: " . $e->getMessage());
    }
}

/**
 * Look up office_id for a schedule.
 */
function _getOfficeId(PDO $pdo, int $scheduleId): ?int
{
    try {
        $st = $pdo->prepare("SELECT office_id FROM schedules WHERE schedule_id = ?");
        $st->execute([$scheduleId]);
        $row = $st->fetch();
        return $row ? (int)$row['office_id'] : null;
    } catch (PDOException $e) { return null; }
}

/**
 * Returns stored trip_ticket_no or a zero-padded fallback like #0064.
 */
function _tripNo(PDO $pdo, int $scheduleId): string
{
    try {
        $st = $pdo->prepare("SELECT trip_ticket_no FROM schedules WHERE schedule_id = ?");
        $st->execute([$scheduleId]);
        $row = $st->fetch();
        return !empty($row['trip_ticket_no'])
            ? $row['trip_ticket_no']
            : '#' . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        return '#' . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);
    }
}

/**
 * Format YYYY-MM-DD → "Jan 09, 2026"
 */
function _fmtDate(string $date): string
{
    if (!$date || $date === '0000-00-00') return '—';
    $ts = strtotime($date);
    return $ts ? date('M d, Y', $ts) : $date;
}

/**
 * Format H:i:s or H:i → "11:22 AM"
 */
function _fmtTime(string $time): string
{
    if (!$time || trim($time) === '') return '—';
    foreach (['H:i:s', 'H:i'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $time);
        if ($dt) return $dt->format('g:i A');
    }
    return $time;
}

/**
 * Clean a vehicle label — strips empty parens, returns '' if nothing real.
 * Handles: "", "()", "  (  )", "Toyota Hilux (—)", "Toyota Hilux ()"
 */
function _cleanVehicle(string $v): string
{
    $v = trim($v);
    if ($v === '' || preg_match('/^[\s()—\-]+$/', $v)) return '';
    $stripped = trim(preg_replace('/\(\s*[—\-]?\s*\)\s*$/', '', $v));
    return $stripped ?: '';
}

/* ══════════════════════════════════════════════════════════
   1. SUBMITTED
   Triggered: add action in Schedules.php
══════════════════════════════════════════════════════════ */
function notifyRequestorSubmitted(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $timeEnd
): void {
    $tripNo = _tripNo($pdo, $scheduleId);

    $msg = "SUBMITTED\n"
         . "Trip: {$tripNo}\n"
         . "Destination: {$destination}\n"
         . "Date: "           . _fmtDate($dateStart) . "\n"
         . "Departure time: " . _fmtTime($timeStart) . "\n"
         . "Return time: "    . _fmtTime($timeEnd)   . "\n"
         . "Status: Waiting for admin approval\n"
         . "[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, $msg, _getOfficeId($pdo, $scheduleId), $scheduleId, 'submitted');
}

/* ══════════════════════════════════════════════════════════
   2. APPROVED
   Triggered: approve action in Schedules.php
══════════════════════════════════════════════════════════ */
function notifyRequestorApproved(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $dateEnd,
    string $timeEnd,
    string $driverName,
    string $vehicleLabel
): void {
    $tripNo  = _tripNo($pdo, $scheduleId);
    $driver  = trim($driverName);
    $vehicle = _cleanVehicle($vehicleLabel);

    $msg = "APPROVED\n"
         . "Trip: {$tripNo}\n"
         . "Destination: {$destination}\n"
         . "Start date: "     . _fmtDate($dateStart) . "\n"
         . "End date: "       . _fmtDate($dateEnd)   . "\n"
         . "Departure time: " . _fmtTime($timeStart) . "\n"
         . "Return time: "    . _fmtTime($timeEnd);

    if ($driver  !== '') $msg .= "\nDriver: {$driver}";
    if ($vehicle !== '') $msg .= "\nVehicle: {$vehicle}";
    $msg .= "\n[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, $msg, _getOfficeId($pdo, $scheduleId), $scheduleId, 'approved');
}

/* ══════════════════════════════════════════════════════════
   3. REJECTED
   Triggered: reject action in Schedules.php
══════════════════════════════════════════════════════════ */
function notifyRequestorRejected(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $reason,
    string $rejectedBy = ''
): void {
    $tripNo = _tripNo($pdo, $scheduleId);

    $msg = "REJECTED\n"
         . "Trip: {$tripNo}\n"
         . "Destination: {$destination}";

    if (trim($rejectedBy) !== '') $msg .= "\nRejected by: {$rejectedBy}";
    $msg .= "\nReason: " . (trim($reason) ?: 'No reason provided');
    $msg .= "\n[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, $msg, _getOfficeId($pdo, $scheduleId), $scheduleId, 'rejected');
}

/* ══════════════════════════════════════════════════════════
   4. CANCELLED
   Triggered: cancel action in Schedules.php (admin or system)
══════════════════════════════════════════════════════════ */
function notifyRequestorCancelled(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $reason,
    string $cancelledBy
): void {
    $tripNo = _tripNo($pdo, $scheduleId);

    $msg = "CANCELLED\n"
         . "Trip: {$tripNo}\n"
         . "Destination: {$destination}\n"
         . "Cancelled by: " . (trim($cancelledBy) ?: 'Admin') . "\n"
         . "Reason: "       . (trim($reason)      ?: 'No reason provided') . "\n"
         . "[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, $msg, _getOfficeId($pdo, $scheduleId), $scheduleId, 'cancelled');
}

/* ══════════════════════════════════════════════════════════
   5. RESCHEDULED
   Triggered: reschedule action in Schedules.php
══════════════════════════════════════════════════════════ */
function notifyRequestorRescheduled(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $oldDateStart,
    string $oldTimeStart,
    string $oldDateEnd,
    string $oldTimeEnd,
    string $newDateStart,
    string $newTimeStart,
    string $newDateEnd,
    string $newTimeEnd
): void {
    $tripNo = _tripNo($pdo, $scheduleId);

    $msg = "RESCHEDULED\n"
         . "Trip: {$tripNo}\n"
         . "Destination: {$destination}\n"
         . "Old date: "           . _fmtDate($oldDateStart) . "\n"
         . "Old departure time: " . _fmtTime($oldTimeStart) . "\n"
         . "Old return time: "    . _fmtTime($oldTimeEnd)   . "\n"
         . "New date: "           . _fmtDate($newDateStart) . "\n"
         . "New departure time: " . _fmtTime($newTimeStart) . "\n"
         . "New return time: "    . _fmtTime($newTimeEnd)   . "\n"
         . "Status: Reset to Pending – awaiting re-approval\n"
         . "[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, $msg, _getOfficeId($pdo, $scheduleId), $scheduleId, 'rescheduled');
}

/* ══════════════════════════════════════════════════════════
   6. ASSIGNMENT CHANGED (driver / vehicle swap)
   Triggered: change_assignment action in Schedules.php
══════════════════════════════════════════════════════════ */
function notifyRequestorAssignmentChanged(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $newDriverName,
    string $newVehicleLabel
): void {
    $tripNo  = _tripNo($pdo, $scheduleId);
    $driver  = trim($newDriverName);
    $vehicle = _cleanVehicle($newVehicleLabel);

    $msg = "ASSIGNMENT UPDATED\n"
         . "Trip: {$tripNo}\n"
         . "Destination: {$destination}";

    if ($driver  !== '') $msg .= "\nNew driver: {$driver}";
    if ($vehicle !== '') $msg .= "\nNew vehicle: {$vehicle}";
    $msg .= "\n[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, $msg, _getOfficeId($pdo, $scheduleId), $scheduleId, 'assignment_changed');
}

/* ══════════════════════════════════════════════════════════
   7. COMPLETED
   Triggered: complete_trip.php after arrived_at is recorded.
   Add this to complete_trip.php:
     require_once 'notify_requestor.php';
     notifyRequestorCompleted($pdo, $row['user_id'], $sid, $row['destination'], $arrivedDatetime);
══════════════════════════════════════════════════════════ */
function notifyRequestorCompleted(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $arrivedDatetime
): void {
    $tripNo = _tripNo($pdo, $scheduleId);

    $arrivedFmt = '—';
    if ($arrivedDatetime) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $arrivedDatetime)
           ?: DateTime::createFromFormat('Y-m-d H:i',   $arrivedDatetime);
        if ($dt) $arrivedFmt = $dt->format('M d, Y g:i A');
    }

    $msg = "COMPLETED\n"
         . "Trip: {$tripNo}\n"
         . "Destination: {$destination}\n"
         . "Returned at: {$arrivedFmt}\n"
         . "[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, $msg, _getOfficeId($pdo, $scheduleId), $scheduleId, 'completed');
}

/* ══════════════════════════════════════════════════════════
   8. 24-HOUR UPCOMING REMINDER
   Triggered: auto-reminder loop in Schedules.php
══════════════════════════════════════════════════════════ */
function notifyRequestorUpcoming24h(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $dateEnd,
    string $timeEnd,
    string $driverName,
    string $vehicleLabel
): void {
    $tripNo  = _tripNo($pdo, $scheduleId);
    $driver  = trim($driverName);
    $vehicle = _cleanVehicle($vehicleLabel);

    $lines = [
        'REMINDER',
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Date: "           . _fmtDate($dateStart),
        "Departure time: " . _fmtTime($timeStart),
        "Return time: "    . _fmtTime($timeEnd),
    ];

    if ($driver  !== '') $lines[] = "Driver: {$driver}";
    if ($vehicle !== '') $lines[] = "Vehicle: {$vehicle}";
    $lines[] = 'Note: Your trip departs in approximately 24 hours. Please be ready.';
    $lines[] = "[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, implode("\n", $lines), _getOfficeId($pdo, $scheduleId), $scheduleId, 'reminder_24h');
}

/* ══════════════════════════════════════════════════════════
   9. 1-HOUR UPCOMING REMINDER
   Triggered: auto-reminder loop in Schedules.php
══════════════════════════════════════════════════════════ */
function notifyRequestorUpcoming1h(
    PDO    $pdo,
    int    $userId,
    int    $scheduleId,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $dateEnd,
    string $timeEnd,
    string $driverName,
    string $vehicleLabel
): void {
    $tripNo  = _tripNo($pdo, $scheduleId);
    $driver  = trim($driverName);
    $vehicle = _cleanVehicle($vehicleLabel);

    $lines = [
        'REMINDER',
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Date: "           . _fmtDate($dateStart),
        "Departure time: " . _fmtTime($timeStart),
        "Return time: "    . _fmtTime($timeEnd),
    ];

    if ($driver  !== '') $lines[] = "Driver: {$driver}";
    if ($vehicle !== '') $lines[] = "Vehicle: {$vehicle}";
    $lines[] = 'Note: Your trip departs in approximately 1 hour. Please be ready!';
    $lines[] = "[ref:{$scheduleId}]";

    _insertNotif($pdo, $userId, implode("\n", $lines), _getOfficeId($pdo, $scheduleId), $scheduleId, 'reminder_1h');
}