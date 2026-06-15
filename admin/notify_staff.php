<?php
/**
 * notify_staff.php
 * ─────────────────────────────────────────────────────────────────
 * Sends structured notifications to ALL staff members of an office.
 * Used by Schedules.php for every status-changing action.
 *
 * Structured format (matches detect_type() in staff/notification.php):
 *   Line 1  = TYPE KEYWORD (matched by LINE_KEY_MAP in JS/PHP)
 *   Line 2+ = Key: Value
 *   Last    = [ref:SCHEDULE_ID]
 * ─────────────────────────────────────────────────────────────────
 */

if (!function_exists('_notif_staff_insert')) {

    /**
     * Insert one notification row per staff member in the office.
     * Skips duplicates: same user_id + trip_id + type within the last hour.
     */
    function _notif_staff_insert(
        PDO    $pdo,
        int    $officeId,
        string $type,
        string $message,
        int    $scheduleId
    ): void {
        $staffStmt = $pdo->prepare("
            SELECT user_id FROM users
            WHERE office_id = ?
              AND role IN ('staff')
        ");
        $staffStmt->execute([$officeId]);
        $staffMembers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

        $dupCheck = $pdo->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE user_id   = ?
              AND trip_id   = ?
              AND type      = ?
              AND created_at >= NOW() - INTERVAL 1 HOUR
        ");

        $insert = $pdo->prepare("
            INSERT INTO notifications
                (user_id, office_id, trip_id, type, message, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");

        foreach ($staffMembers as $staff) {
            $dupCheck->execute([$staff['user_id'], $scheduleId, $type]);
            if ((int)$dupCheck->fetchColumn() > 0) continue;

            $insert->execute([
                $staff['user_id'],
                $officeId,
                $scheduleId,
                $type,
                $message,
            ]);
        }
    }
}

/* ══════════════════════════════════════════════════════════════════
   REJECTED
   Keyword: TRIP REJECTED  →  type: trip_rejected
══════════════════════════════════════════════════════════════════ */
function notifyStaffRejected(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $reason,
    string $rejectedBy
): void {
    $tripNo = _staff_trip_no($pdo, $scheduleId);
    $msg = implode("\n", [
        "TRIP REJECTED",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Rejected by: {$rejectedBy}",
        "Reason: " . ($reason ?: 'No reason provided'),
        "[ref:{$scheduleId}]",
    ]);
    _notif_staff_insert($pdo, $officeId, 'trip_rejected', $msg, $scheduleId);
}

/* ══════════════════════════════════════════════════════════════════
   CANCELLED
   Keyword: TRIP CANCELLED  →  type: trip_cancelled
══════════════════════════════════════════════════════════════════ */
function notifyStaffCancelled(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $reason,
    string $cancelledBy
): void {
    $tripNo = _staff_trip_no($pdo, $scheduleId);
    $msg = implode("\n", [
        "TRIP CANCELLED",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Cancelled by: {$cancelledBy}",
        "Reason: " . ($reason ?: 'No reason provided'),
        "[ref:{$scheduleId}]",
    ]);
    _notif_staff_insert($pdo, $officeId, 'trip_cancelled', $msg, $scheduleId);
}

/* ══════════════════════════════════════════════════════════════════
   RESCHEDULED
   Keyword: TRIP RESCHEDULED  →  type: trip_rescheduled
══════════════════════════════════════════════════════════════════ */
function notifyStaffRescheduled(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $oldDateStart,
    string $oldTimeStart,
    string $oldDateEnd,
    string $oldTimeEnd,
    string $newDateStart,
    string $newTimeStart,
    string $newDateEnd,
    string $newTimeEnd,
    string $rescheduledBy
): void {
    $tripNo   = _staff_trip_no($pdo, $scheduleId);
    $oldStart = date('M d, Y g:i A', strtotime($oldDateStart . ' ' . $oldTimeStart));
    $oldEnd   = date('M d, Y g:i A', strtotime($oldDateEnd   . ' ' . $oldTimeEnd));
    $newStart = date('M d, Y g:i A', strtotime($newDateStart . ' ' . $newTimeStart));
    $newEnd   = date('M d, Y g:i A', strtotime($newDateEnd   . ' ' . $newTimeEnd));

    $msg = implode("\n", [
        "TRIP RESCHEDULED",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Rescheduled by: {$rescheduledBy}",
        "Old date: {$oldStart} → {$oldEnd}",
        "Old departure time: {$oldStart}",
        "New date: {$newStart} → {$newEnd}",
        "New departure time: {$newStart}",
        "Note: Status reset to Pending — re-approval required.",
        "[ref:{$scheduleId}]",
    ]);
    _notif_staff_insert($pdo, $officeId, 'trip_rescheduled', $msg, $scheduleId);
}

/* ══════════════════════════════════════════════════════════════════
   ASSIGNMENT CHANGED  (change driver / vehicle)
   Keyword: ASSIGNMENT UPDATED  →  type: assignment_changed
══════════════════════════════════════════════════════════════════ */
function notifyStaffAssignmentChanged(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $newDriverName,
    string $newVehicleLabel,
    string $changedBy
): void {
    $tripNo = _staff_trip_no($pdo, $scheduleId);
    $msg = implode("\n", [
        "ASSIGNMENT UPDATED",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Changed by: {$changedBy}",
        "New driver: {$newDriverName}",
        "New vehicle: {$newVehicleLabel}",
        "[ref:{$scheduleId}]",
    ]);
    _notif_staff_insert($pdo, $officeId, 'assignment_changed', $msg, $scheduleId);
}

/* ══════════════════════════════════════════════════════════════════
   PENDING REMINDER  (admin page auto-fires this; staff mirror)
   Keyword: NEW TRIP REQUEST  →  type: new_trip_pending
══════════════════════════════════════════════════════════════════ */
function notifyStaffPendingReminder(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $dateEnd,
    string $timeEnd,
    int    $passengers = 1
): void {
    $tripNo    = _staff_trip_no($pdo, $scheduleId);
    $departure = date('M d, Y g:i A', strtotime($dateStart . ' ' . $timeStart));
    $return    = date('M d, Y g:i A', strtotime($dateEnd   . ' ' . $timeEnd));

    $msg = implode("\n", [
        "NEW TRIP REQUEST",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Start date: {$dateStart}",
        "End date: {$dateEnd}",
        "Departure time: {$departure}",
        "Return time: {$return}",
        "Passengers: {$passengers}",
        "[ref:{$scheduleId}]",
    ]);

    // Throttle: once per 5 hours per trip per staff member
    $staffStmt = $pdo->prepare("
        SELECT user_id FROM users
        WHERE office_id = ? AND role IN ('staff')
    ");
    $staffStmt->execute([$officeId]);
    $staffMembers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $dupCheck = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id   = ?
          AND trip_id   = ?
          AND type      = 'new_trip_pending'
          AND created_at >= NOW() - INTERVAL 5 HOUR
    ");
    $insert = $pdo->prepare("
        INSERT INTO notifications
            (user_id, office_id, trip_id, type, message, is_read, created_at)
        VALUES (?, ?, ?, 'new_trip_pending', ?, 0, NOW())
    ");
    foreach ($staffMembers as $staff) {
        $dupCheck->execute([$staff['user_id'], $scheduleId]);
        if ((int)$dupCheck->fetchColumn() > 0) continue;
        $insert->execute([$staff['user_id'], $officeId, $scheduleId, $msg]);
    }
}

/* ══════════════════════════════════════════════════════════════════
   OVERDUE REMINDER  (vehicle not yet returned)
   Keyword: TRIP RESCHEDULED is NOT used here — new keyword needed
   We reuse the legacy free-text approach so existing detect_type()
   picks it up as 'overdue'.
══════════════════════════════════════════════════════════════════ */
function notifyStaffOverdue(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $vehicleLabel,
    string $driverName,
    string $dueDateTime
): void {
    $tripNo = _staff_trip_no($pdo, $scheduleId);

    // Uses legacy free-text so detect_type() maps it to 'overdue'
    $msg = "⚠️ Vehicle Overdue: Trip {$tripNo} — {$vehicleLabel} driven by {$driverName} "
         . "was due back at {$dueDateTime} but has not yet returned. "
         . "Requestor: {$requestorName}. Destination: {$destination}. [ref:{$scheduleId}]";

    // Throttle: once per 3 hours per trip per staff
    $staffStmt = $pdo->prepare("
        SELECT user_id FROM users
        WHERE office_id = ? AND role IN ('staff')
    ");
    $staffStmt->execute([$officeId]);
    $staffMembers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $dupCheck = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id    = ?
          AND trip_id    = ?
          AND type       = 'vehicle_overdue'
          AND created_at >= NOW() - INTERVAL 3 HOUR
    ");
    $insert = $pdo->prepare("
        INSERT INTO notifications
            (user_id, office_id, trip_id, type, message, is_read, created_at)
        VALUES (?, ?, ?, 'vehicle_overdue', ?, 0, NOW())
    ");
    foreach ($staffMembers as $staff) {
        $dupCheck->execute([$staff['user_id'], $scheduleId]);
        if ((int)$dupCheck->fetchColumn() > 0) continue;
        $insert->execute([$staff['user_id'], $officeId, $scheduleId, $msg]);
    }
}

/* ══════════════════════════════════════════════════════════════════
   24-HOUR DEPARTURE REMINDER  (staff mirror of requestor reminder)
══════════════════════════════════════════════════════════════════ */
function notifyStaffUpcoming24h(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $dateEnd,
    string $timeEnd,
    string $driverName,
    string $vehicleLabel
): void {
    $tripNo    = _staff_trip_no($pdo, $scheduleId);
    $departure = date('M d, Y g:i A', strtotime($dateStart . ' ' . $timeStart));
    $return    = date('M d, Y g:i A', strtotime($dateEnd   . ' ' . $timeEnd));

    $msg = implode("\n", [
        "REMINDER",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Date: {$dateStart}",
        "Departure time: {$departure}",
        "Return time: {$return}",
        "Driver: {$driverName}",
        "Vehicle: {$vehicleLabel}",
        "Note: This trip departs in approximately 24 hours. Ensure vehicle and driver are ready.",
        "[ref:{$scheduleId}]",
    ]);

    $staffStmt = $pdo->prepare("
        SELECT user_id FROM users WHERE office_id = ? AND role IN ('staff')
    ");
    $staffStmt->execute([$officeId]);
    $staffMembers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $dupCheck = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id   = ?
          AND trip_id   = ?
          AND type      = 'reminder_24h'
          AND DATE(created_at) = DATE(NOW())
    ");
    $insert = $pdo->prepare("
        INSERT INTO notifications
            (user_id, office_id, trip_id, type, message, is_read, created_at)
        VALUES (?, ?, ?, 'reminder_24h', ?, 0, NOW())
    ");
    foreach ($staffMembers as $staff) {
        $dupCheck->execute([$staff['user_id'], $scheduleId]);
        if ((int)$dupCheck->fetchColumn() > 0) continue;
        $insert->execute([$staff['user_id'], $officeId, $scheduleId, $msg]);
    }
}

/* ══════════════════════════════════════════════════════════════════
   1-HOUR DEPARTURE REMINDER  (staff mirror)
══════════════════════════════════════════════════════════════════ */
function notifyStaffUpcoming1h(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $dateEnd,
    string $timeEnd,
    string $driverName,
    string $vehicleLabel
): void {
    $tripNo    = _staff_trip_no($pdo, $scheduleId);
    $departure = date('M d, Y g:i A', strtotime($dateStart . ' ' . $timeStart));
    $return    = date('M d, Y g:i A', strtotime($dateEnd   . ' ' . $timeEnd));

    $msg = implode("\n", [
        "REMINDER",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Date: {$dateStart}",
        "Departure time: {$departure}",
        "Return time: {$return}",
        "Driver: {$driverName}",
        "Vehicle: {$vehicleLabel}",
        "Note: This trip departs in approximately 1 hour. Final check — ensure driver and vehicle are ready.",
        "[ref:{$scheduleId}]",
    ]);

    $staffStmt = $pdo->prepare("
        SELECT user_id FROM users WHERE office_id = ? AND role IN ('staff')
    ");
    $staffStmt->execute([$officeId]);
    $staffMembers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $dupCheck = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id   = ?
          AND trip_id   = ?
          AND type      = 'reminder_1h'
          AND DATE(created_at) = DATE(NOW())
    ");
    $insert = $pdo->prepare("
        INSERT INTO notifications
            (user_id, office_id, trip_id, type, message, is_read, created_at)
        VALUES (?, ?, ?, 'reminder_1h', ?, 0, NOW())
    ");
    foreach ($staffMembers as $staff) {
        $dupCheck->execute([$staff['user_id'], $scheduleId]);
        if ((int)$dupCheck->fetchColumn() > 0) continue;
        $insert->execute([$staff['user_id'], $officeId, $scheduleId, $msg]);
    }
}

/* ══════════════════════════════════════════════════════════════════
   INTERNAL: generate trip ticket number
══════════════════════════════════════════════════════════════════ */
function _staff_trip_no(PDO $pdo, int $scheduleId): string {
    $st = $pdo->prepare("SELECT trip_ticket_no, date_start FROM schedules WHERE schedule_id = ?");
    $st->execute([$scheduleId]);
    $row = $st->fetch();
    if (!$row) return '#' . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);
    if (!empty($row['trip_ticket_no'])) return $row['trip_ticket_no'];
    if (!empty($row['date_start'])) {
        $m  = date('m', strtotime($row['date_start']));
        $y  = date('Y', strtotime($row['date_start']));
        $ct = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE status IN ('Approved','OnTrip','Completed') AND MONTH(date_start)=? AND YEAR(date_start)=? AND schedule_id<=?");
        $ct->execute([$m, $y, $scheduleId]);
        return "$m/$y/" . str_pad((int)$ct->fetchColumn(), 4, '0', STR_PAD_LEFT);
    }
    return '#' . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);
}
function notifyStaffApproved(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $requestorName,
    string $destination,
    string $dateStart,
    string $timeStart,
    string $dateEnd,
    string $timeEnd,
    string $driverName,
    string $vehicleLabel,
    string $approvedBy
): void {
    $tripNo    = _staff_trip_no($pdo, $scheduleId);
    $departure = date('M d, Y g:i A', strtotime($dateStart . ' ' . $timeStart));
    $return    = date('M d, Y g:i A', strtotime($dateEnd   . ' ' . $timeEnd));

    $msg = implode("\n", [
        "TRIP APPROVED",
        "Trip: {$tripNo}",
        "Destination: {$destination}",
        "Requested by: {$requestorName}",
        "Approved by: {$approvedBy}",
        "Start date: {$dateStart}",
        "End date: {$dateEnd}",
        "Departure time: {$departure}",
        "Return time: {$return}",
        "Driver: {$driverName}",
        "Vehicle: {$vehicleLabel}",
        "[ref:{$scheduleId}]",
    ]);
    _notif_staff_insert($pdo, $officeId, 'trip_approved', $msg, $scheduleId);
}
function notifyStaffCompleted(
    PDO $pdo,
    int $officeId,
    int $scheduleId,
    string $requestorName,
    string $destination,
    string $arrivedDatetime,
    string $completedBy = 'System'
): void {
    $tripNo = '#' . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);

    // Try to get the actual trip ticket number
    $tn = $pdo->prepare("SELECT trip_ticket_no FROM schedules WHERE schedule_id = ?");
    $tn->execute([$scheduleId]);
    $tnRow = $tn->fetch();
    if (!empty($tnRow['trip_ticket_no'])) {
        $tripNo = $tnRow['trip_ticket_no'];
    }

    $arrived = date('M d, Y g:i A', strtotime($arrivedDatetime));

    $msg = "✅ Trip {$tripNo} for {$requestorName} to {$destination} has been marked as Completed. "
         . "Vehicle arrived at: {$arrived}. [ref:{$scheduleId}]";

    $staffStmt = $pdo->prepare("
        SELECT user_id FROM users
        WHERE role IN ('admin', 'staff')
          AND office_id = ?
    ");
    $staffStmt->execute([$officeId]);

    $insert = $pdo->prepare("
        INSERT INTO notifications (user_id, office_id, message, is_read, trip_id, type, created_at)
        VALUES (?, ?, ?, 0, ?, 'trip_completed', NOW())
    ");

    foreach ($staffStmt->fetchAll() as $member) {
        $insert->execute([
            $member['user_id'],
            $officeId,
            $msg,
            $scheduleId
        ]);
    }
}