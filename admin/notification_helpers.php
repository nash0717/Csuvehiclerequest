<?php
/**
 * notification_helpers.php
 * Place in: admin/notification_helpers.php
 *
 * Admin-facing notification helpers for the CSU Vehicle Scheduling System.
 * Fans notifications out to ALL admins in the relevant office.
 *
 * Include in any admin controller:
 *   require_once __DIR__ . '/notification_helpers.php';
 *
 * Functions:
 *   create_notification()           – core insert (single user)
 *   notif_new_request()             – new trip submitted by staff
 *   notif_walkin_booking()          – walk-in booking logged by admin
 *   notif_request_approved()        – trip approved
 *   notif_request_cancelled()       – trip cancelled
 *   notif_request_rejected()        – trip rejected
 *   notif_trip_completed()          – trip marked complete
 *   notif_departure_reminder()      – 1-hour departure reminder (cron)
 *   notif_vehicle_overdue()         – vehicle overdue / not returned (cron)
 *   notif_pending_reminder()        – pending trip needs approval (throttled)
 *   notif_conflict()                – booking conflict detected
 *   notif_rescheduled()             – trip rescheduled by admin
 *   notif_assignment_changed()      – driver/vehicle reassigned
 *
 * CLI cron usage:
 *   php /path/to/admin/notification_helpers.php cron
 */

/* ══════════════════════════════════════════════════════════
   CORE INSERT — single recipient
══════════════════════════════════════════════════════════ */

/**
 * Insert one notification for a specific user.
 * Auto-migrates trip_id, type, title, triggered_by columns if missing.
 */
function create_notification(
    PDO     $pdo,
    int     $office_id,
    int     $user_id,
    string  $type,
    string  $title,
    string  $message,
    ?int    $trip_id      = null,
    ?int    $triggered_by = null
): void {
    try {
        static $colsChecked = false;
        if (!$colsChecked) {
            $colsChecked = true;
            $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('trip_id',      $cols, true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN trip_id INT NULL DEFAULT NULL");
            if (!in_array('type',         $cols, true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(60) NULL DEFAULT NULL");
            if (!in_array('title',        $cols, true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN title VARCHAR(160) NULL DEFAULT NULL");
            if (!in_array('triggered_by', $cols, true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN triggered_by INT NULL DEFAULT NULL");
        }

        $pdo->prepare("
            INSERT INTO notifications
                (office_id, user_id, triggered_by, trip_id, type, title, message, is_read, created_at)
            VALUES
                (:oid, :uid, :tby, :tid, :type, :title, :msg, 0, NOW())
        ")->execute([
            'oid'   => $office_id,
            'uid'   => $user_id,
            'tby'   => $triggered_by,
            'tid'   => $trip_id,
            'type'  => $type,
            'title' => $title,
            'msg'   => $message,
        ]);
    } catch (PDOException $e) {
        error_log("[notification_helpers] create_notification error ({$type}): " . $e->getMessage());
    }
}

/* ══════════════════════════════════════════════════════════
   FAN-OUT HELPER — sends to every admin in an office
══════════════════════════════════════════════════════════ */

/**
 * Insert a notification for every admin in $officeId.
 * Optionally exclude one user (the admin who triggered the action).
 */
function _notif_fanout(
    PDO    $pdo,
    int    $officeId,
    string $type,
    string $title,
    string $message,
    int    $tripId     = 0,
    int    $excludeUid = 0
): void {
    try {
        $q = $pdo->prepare("SELECT user_id FROM users WHERE role = 'admin' AND office_id = ?");
        $q->execute([$officeId]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if ($excludeUid && (int)$uid === $excludeUid) continue;
            create_notification($pdo, $officeId, (int)$uid, $type, $title, $message, $tripId ?: null);
        }
    } catch (PDOException $e) {
        error_log("[notification_helpers] fanout error ({$type}): " . $e->getMessage());
    }
}

/* ══════════════════════════════════════════════════════════
   SHARED FORMATTERS
══════════════════════════════════════════════════════════ */

function _adm_fdate(string $d): string
{
    if (!$d || $d === '0000-00-00') return '—';
    $ts = strtotime($d);
    return $ts ? date('M d, Y', $ts) : $d;
}

function _adm_ftime(string $t): string
{
    if (!$t || trim($t) === '') return '—';
    foreach (['H:i:s', 'H:i'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $t);
        if ($dt) return $dt->format('g:i A');
    }
    return $t;
}

function _adm_ticket(PDO $pdo, int $scheduleId): string
{
    try {
        $r = $pdo->prepare("SELECT trip_ticket_no, date_start FROM schedules WHERE schedule_id = ?");
        $r->execute([$scheduleId]);
        $row = $r->fetch();
        if (!$row) return '#' . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);
        if (!empty($row['trip_ticket_no'])) return $row['trip_ticket_no'];
        $ds = $row['date_start'] ?? '';
        $m  = $ds ? date('m', strtotime($ds)) : '??';
        $y  = $ds ? date('Y', strtotime($ds)) : '????';
        return "{$m}/{$y}/" . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);
    } catch (PDOException $e) {
        return '#' . str_pad($scheduleId, 4, '0', STR_PAD_LEFT);
    }
}

/* ══════════════════════════════════════════════════════════
   1. NEW REQUEST  — notify admins when staff submits a trip
   Call from: add action in Schedules.php
══════════════════════════════════════════════════════════ */
function notif_new_request(
    PDO    $pdo,
    int    $office_id,
    int    $admin_id,        // single target admin (or use fanout below)
    array  $schedule,
    ?int   $requester_id = null
): void {
    $sid  = (int)($schedule['schedule_id'] ?? 0);
    $date = _adm_fdate($schedule['date_start'] ?? '');

    create_notification(
        $pdo, $office_id, $admin_id,
        'new_request',
        'New trip request submitted',
        "{$schedule['username']} submitted a new vehicle request to {$schedule['destination']} on {$date}. [ref:{$sid}]",
        $sid ?: null,
        $requester_id
    );
}

/**
 * Fan-out variant: notify ALL admins of a new request.
 * Call this instead of notif_new_request() for full coverage.
 */
function notif_new_request_all(
    PDO   $pdo,
    int   $officeId,
    array $schedule,
    int   $excludeUid = 0
): void {
    $sid  = (int)($schedule['schedule_id'] ?? 0);
    $date = _adm_fdate($schedule['date_start'] ?? '');

    _notif_fanout(
        $pdo, $officeId,
        'new_request',
        'New trip request submitted',
        "{$schedule['username']} submitted a new vehicle request to {$schedule['destination']} on {$date}. [ref:{$sid}]",
        $sid,
        $excludeUid
    );
}

/* ══════════════════════════════════════════════════════════
   2. WALK-IN BOOKING  — admin logs an in-person booking
   Call from: walk-in booking form handler
══════════════════════════════════════════════════════════ */
function notif_walkin_booking(
    PDO   $pdo,
    int   $office_id,
    int   $admin_id,
    array $schedule
): void {
    $sid = (int)($schedule['schedule_id'] ?? 0);

    create_notification(
        $pdo, $office_id, $admin_id,
        'walkin_booking',
        'Walk-in booking logged',
        "Admin logged a walk-in booking for {$schedule['department']} — {$schedule['vehicle_name']}. [ref:{$sid}]",
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   3. REQUEST APPROVED  — notify admin(s) of approval
   Call from: approve action in Schedules.php
══════════════════════════════════════════════════════════ */
function notif_request_approved(
    PDO   $pdo,
    int   $office_id,
    int   $admin_id,
    array $schedule
): void {
    $sid  = (int)($schedule['schedule_id'] ?? 0);
    $time = !empty($schedule['time_start']) ? _adm_ftime($schedule['time_start']) : '';

    create_notification(
        $pdo, $office_id, $admin_id,
        'request_approved',
        'Trip request approved',
        "Trip request #{$sid} by {$schedule['username']} has been approved"
            . ($time ? " — departs {$time}" : '') . ". [ref:{$sid}]",
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   4. REQUEST CANCELLED
   Call from: cancel action in Schedules.php
══════════════════════════════════════════════════════════ */
function notif_request_cancelled(
    PDO    $pdo,
    int    $office_id,
    int    $admin_id,
    array  $schedule,
    string $cancelled_by = 'requestor'
): void {
    $sid = (int)($schedule['schedule_id'] ?? 0);

    create_notification(
        $pdo, $office_id, $admin_id,
        'request_cancelled',
        'Trip request cancelled',
        "Trip #{$sid} to {$schedule['destination']} was cancelled by the {$cancelled_by}. [ref:{$sid}]",
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   5. REQUEST REJECTED
   Call from: reject action in Schedules.php
══════════════════════════════════════════════════════════ */
function notif_request_rejected(
    PDO    $pdo,
    int    $office_id,
    int    $admin_id,
    array  $schedule,
    string $reason = ''
): void {
    $sid = (int)($schedule['schedule_id'] ?? 0);
    $msg = "Admin rejected request #{$sid} by {$schedule['username']}";
    if ($reason) $msg .= " — reason: {$reason}";
    $msg .= ". [ref:{$sid}]";

    create_notification(
        $pdo, $office_id, $admin_id,
        'request_rejected',
        'Trip request rejected',
        $msg,
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   6. TRIP COMPLETED
   Call from: complete_trip.php
══════════════════════════════════════════════════════════ */
function notif_trip_completed(
    PDO   $pdo,
    int   $office_id,
    int   $admin_id,
    array $schedule
): void {
    $sid = (int)($schedule['schedule_id'] ?? 0);

    create_notification(
        $pdo, $office_id, $admin_id,
        'trip_completed',
        'Trip marked as completed',
        "Trip #{$sid} to {$schedule['destination']} has been marked complete. [ref:{$sid}]",
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   7. DEPARTURE REMINDER  (1 hour)
   Call from: cron job or auto-reminder loop
══════════════════════════════════════════════════════════ */
function notif_departure_reminder(
    PDO   $pdo,
    int   $office_id,
    int   $admin_id,
    array $schedule
): void {
    $sid  = (int)($schedule['schedule_id'] ?? 0);
    $time = !empty($schedule['time_start']) ? _adm_ftime($schedule['time_start']) : '—';

    create_notification(
        $pdo, $office_id, $admin_id,
        'departure_reminder',
        'Trip departing in 1 hour',
        "Trip #{$sid} departs at {$time} — driver: {$schedule['driver_name']}, vehicle: {$schedule['vehicle_name']}. [ref:{$sid}]",
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   8. VEHICLE OVERDUE
   Call from: cron job
══════════════════════════════════════════════════════════ */
function notif_vehicle_overdue(
    PDO   $pdo,
    int   $office_id,
    int   $admin_id,
    array $schedule
): void {
    $sid        = (int)($schedule['schedule_id'] ?? 0);
    $returnTime = !empty($schedule['time_end']) ? _adm_ftime($schedule['time_end']) : 'scheduled time';

    create_notification(
        $pdo, $office_id, $admin_id,
        'vehicle_overdue',
        'Vehicle overdue — not yet returned',
        "Trip #{$sid} — {$schedule['vehicle_name']} was due back at {$returnTime} but has not been logged as returned. [ref:{$sid}]",
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   9. PENDING REMINDER  (throttled — called from Schedules.php loop)
   Throttle / dedup is handled by the caller; this just inserts.
══════════════════════════════════════════════════════════ */
function notif_pending_reminder(
    PDO   $pdo,
    int   $office_id,
    int   $admin_id,
    array $schedule
): void {
    $sid    = (int)($schedule['schedule_id'] ?? 0);
    $ticket = _adm_ticket($pdo, $sid);
    $date   = _adm_fdate($schedule['date_start'] ?? '');
    $ts     = _adm_ftime($schedule['time_start'] ?? '');
    $te     = _adm_ftime($schedule['time_end']   ?? '');
    $who    = $schedule['username'] ?? 'Unknown';

    create_notification(
        $pdo, $office_id, $admin_id,
        'pending_reminder',
        'Pending trip awaiting approval',
        "Pending trip {$ticket} from {$who} to \"{$schedule['destination']}\" on {$date} ({$ts} – {$te}) is still awaiting approval. [ref:{$sid}]",
        $sid ?: null
    );
}

/* ══════════════════════════════════════════════════════════
   10. BOOKING CONFLICT
   Call from: approve / change_assignment actions in Schedules.php
   (This replaces the inline notifyAdminsOfConflict() defined in Schedules.php)
══════════════════════════════════════════════════════════ */
function notif_conflict(
    PDO    $pdo,
    int    $officeId,
    string $adminUsername,
    array  $conflictingRow,
    string $newBookingInfo,
    int    $excludeUid = 0
): void {
    $sid    = (int)($conflictingRow['schedule_id'] ?? 0);
    $ticket = _adm_ticket($pdo, $sid);

    $msg = "Schedule conflict detected by {$adminUsername}: "
         . "new booking ({$newBookingInfo}) overlaps with existing trip {$ticket} "
         . "(Requestor: " . ($conflictingRow['username'] ?? '—') . ", "
         . _adm_fdate($conflictingRow['date_start'] ?? '') . " → "
         . _adm_fdate($conflictingRow['date_end']   ?? '') . ", "
         . "Status: " . ($conflictingRow['status'] ?? '—') . "). "
         . "Please resolve before approving. [ref:{$sid}]";

    _notif_fanout($pdo, $officeId, 'conflict', 'Schedule conflict detected', $msg, $sid, $excludeUid);
}

/* ══════════════════════════════════════════════════════════
   11. RESCHEDULED  (admin-side — notify other admins)
   Call from: reschedule action in Schedules.php
══════════════════════════════════════════════════════════ */
function notif_rescheduled(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $rescheduledBy,
    string $oldDateStart,
    string $oldTimeStart,
    string $oldDateEnd,
    string $oldTimeEnd,
    string $newDateStart,
    string $newTimeStart,
    string $newDateEnd,
    string $newTimeEnd,
    int    $excludeUid = 0
): void {
    $ticket = _adm_ticket($pdo, $scheduleId);

    $oldStr = _adm_fdate($oldDateStart) . ' ' . _adm_ftime($oldTimeStart)
            . ' – ' . _adm_fdate($oldDateEnd) . ' ' . _adm_ftime($oldTimeEnd);
    $newStr = _adm_fdate($newDateStart) . ' ' . _adm_ftime($newTimeStart)
            . ' – ' . _adm_fdate($newDateEnd) . ' ' . _adm_ftime($newTimeEnd);

    $msg = "Trip {$ticket} was rescheduled by {$rescheduledBy}. "
         . "Old: {$oldStr}. New: {$newStr}. "
         . "Status reset to Pending — re-approval needed. [ref:{$scheduleId}]";

    _notif_fanout($pdo, $officeId, 'rescheduled', 'Trip rescheduled', $msg, $scheduleId, $excludeUid);
}

/* ══════════════════════════════════════════════════════════
   12. ASSIGNMENT CHANGED  (admin-side — notify other admins)
   Call from: change_assignment action in Schedules.php
══════════════════════════════════════════════════════════ */
function notif_assignment_changed(
    PDO    $pdo,
    int    $officeId,
    int    $scheduleId,
    string $changedBy,
    string $newDriverName,
    string $newVehicleLabel,
    int    $excludeUid = 0
): void {
    $ticket = _adm_ticket($pdo, $scheduleId);

    $msg = "Trip {$ticket} was reassigned by {$changedBy}. "
         . "New Driver: " . (trim($newDriverName)    ?: '—') . " | "
         . "New Vehicle: " . (trim($newVehicleLabel) ?: '—') . ". [ref:{$scheduleId}]";

    _notif_fanout($pdo, $officeId, 'assignment_changed', 'Trip assignment changed', $msg, $scheduleId, $excludeUid);
}

/* ══════════════════════════════════════════════════════════
   CRON JOB
   Run every 5 minutes via crontab:
     * /5 * * * * php /var/www/html/admin/notification_helpers.php cron
   Or from the terminal for testing:
     php admin/notification_helpers.php cron
══════════════════════════════════════════════════════════ */
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'cron') {
    require_once __DIR__ . '/../includes/db.php';
    date_default_timezone_set('Asia/Manila');

    /* ── Departure reminders: trips departing in 55–65 min, not yet reminded ── */
    $stmt = $pdo->query("
        SELECT s.schedule_id, s.time_start, s.destination, s.office_id,
               COALESCE(dr.driver_name, '—') AS driver_name,
               COALESCE(CONCAT(v.brand,' ',v.model), '—') AS vehicle_name,
               u.user_id AS admin_user_id
        FROM schedules s
        JOIN users u ON u.office_id = s.office_id AND u.role = 'admin'
        LEFT JOIN drivers  dr ON s.driver_id  = dr.driver_id
        LEFT JOIN vehicles v  ON s.vehicle_id = v.vehicle_id
        WHERE s.status = 'Approved'
          AND s.reminder_sent = 0
          AND CONCAT(s.date_start, ' ', s.time_start)
              BETWEEN DATE_ADD(NOW(), INTERVAL 55 MINUTE)
                  AND DATE_ADD(NOW(), INTERVAL 65 MINUTE)
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        notif_departure_reminder($pdo, $row['office_id'], $row['admin_user_id'], $row);
        $pdo->prepare("UPDATE schedules SET reminder_sent = 1 WHERE schedule_id = ?")->execute([$row['schedule_id']]);
        echo "[REMINDER] Schedule #{$row['schedule_id']}\n";
    }

    /* ── Overdue vehicles: OnTrip past date_end + time_end, not yet notified ── */
    $stmt2 = $pdo->query("
        SELECT s.schedule_id, s.time_end, s.destination, s.office_id,
               COALESCE(CONCAT(v.brand,' ',v.model), '—') AS vehicle_name,
               u.user_id AS admin_user_id
        FROM schedules s
        JOIN users u ON u.office_id = s.office_id AND u.role = 'admin'
        LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
        WHERE s.status = 'OnTrip'
          AND s.overdue_notified = 0
          AND CONCAT(s.date_end, ' ', COALESCE(s.time_end, '23:59:00')) < NOW()
    ");
    foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        notif_vehicle_overdue($pdo, $row['office_id'], $row['admin_user_id'], $row);
        $pdo->prepare("UPDATE schedules SET overdue_notified = 1 WHERE schedule_id = ?")->execute([$row['schedule_id']]);
        echo "[OVERDUE] Schedule #{$row['schedule_id']}\n";
    }

    echo "Cron done: " . date('Y-m-d H:i:s') . "\n";
    exit;
}