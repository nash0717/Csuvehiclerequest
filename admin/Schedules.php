<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once 'notify_requestor.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/notify_staff.php';
require_once __DIR__ . '/email_notifications.php';
require_once '../includes/mailer.php';
requireAdmin();
try {
    $stp = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'signed_ticket_path'");
    if ($stp->rowCount() === 0)
        $pdo->exec("ALTER TABLE schedules ADD COLUMN signed_ticket_path VARCHAR(300) NULL DEFAULT NULL");
} catch (PDOException $e) {}
/* ── AUTO-MIGRATE ── */
try {
    $tid = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'trip_id'");
    if ($tid->rowCount() === 0)
        $pdo->exec("ALTER TABLE notifications ADD COLUMN trip_id INT NULL DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $ttype = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'type'");
    if ($ttype->rowCount() === 0)
        $pdo->exec("ALTER TABLE notifications ADD COLUMN type VARCHAR(60) NULL DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $rr = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'rejection_reason'");
    if ($rr->rowCount() === 0)
        $pdo->exec("ALTER TABLE schedules ADD COLUMN rejection_reason VARCHAR(500) NULL DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $aa = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'arrived_at'");
    if ($aa->rowCount() === 0)
        $pdo->exec("ALTER TABLE schedules ADD COLUMN arrived_at DATETIME NULL");
} catch (PDOException $e) {}

try {
    $cr = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'cancel_reason'");
    if ($cr->rowCount() === 0)
        $pdo->exec("ALTER TABLE schedules ADD COLUMN cancel_reason VARCHAR(500) NULL DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $cb = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'cancelled_by'");
    if ($cb->rowCount() === 0)
        $pdo->exec("ALTER TABLE schedules ADD COLUMN cancelled_by VARCHAR(100) NULL DEFAULT NULL");
} catch (PDOException $e) {}

try {
    $cat = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'created_at'");
    if ($cat->rowCount() === 0)
        $pdo->exec("ALTER TABLE schedules ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
} catch (PDOException $e) {}

try {
    $bbs = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'booked_by_staff'");
    if ($bbs->rowCount() === 0)
        $pdo->exec("ALTER TABLE schedules ADD COLUMN booked_by_staff INT NULL");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE schedules MODIFY COLUMN status
        ENUM('Pending','Approved','OnTrip','Rejected','Completed','Cancelled','Reassigned')
        DEFAULT 'Pending'");
} catch (PDOException $e) {}

try {
    $pdo->exec("UPDATE schedules
                SET status='Completed'
                WHERE arrived_at IS NOT NULL
                  AND arrived_at != ''
                  AND status NOT IN ('Completed','Cancelled')");
} catch (PDOException $e) {}

try {
    $pdo->exec("UPDATE schedules
                SET status='OnTrip'
                WHERE status='Approved'
                  AND CONCAT(date_start, ' ', COALESCE(time_start,'00:00:00')) <= NOW()");
} catch (PDOException $e) {}

/* ── AUTO-REMINDER ── */
try {
    require_once __DIR__ . '/notification_helpers.php';
    $pendingStmt = $pdo->prepare("
        SELECT s.schedule_id, s.destination, s.date_start, s.time_start, s.time_end,
               s.office_id, u.username
        FROM schedules s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.status = 'Pending'
          AND s.created_at <= NOW() - INTERVAL 1 HOUR
          AND CONCAT(s.date_start, ' ', COALESCE(s.time_start, '23:59:00')) >= NOW()
    ");
    $pendingStmt->execute();
    $pendingRows = $pendingStmt->fetchAll();
    foreach ($pendingRows as $pr) {
        $adminStmt = $pdo->prepare("SELECT user_id FROM users WHERE role = 'admin' AND office_id = ?");
        $adminStmt->execute([$pr['office_id']]);
        $admins = $adminStmt->fetchAll();
        foreach ($admins as $admin) {
            $dupCheck = $pdo->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE user_id   = ?
                  AND trip_id   = ?
                  AND type      = 'pending_reminder'
                  AND created_at >= NOW() - INTERVAL 5 HOUR
            ");
            $dupCheck->execute([$admin['user_id'], $pr['schedule_id']]);
            if ((int)$dupCheck->fetchColumn() > 0) continue;
            notif_pending_reminder($pdo, (int)$pr['office_id'], (int)$admin['user_id'], $pr);
        }
    }
} catch (PDOException $e) {}

/* ── 24-hour reminder ── */
$rem24 = $pdo->prepare("
    SELECT s.schedule_id, s.user_id, s.destination,
           s.date_start, s.time_start, s.date_end, s.time_end,
           COALESCE(d.driver_name, '—') AS driver_name,
           COALESCE(CONCAT(v.brand,' ',v.model,' (',v.plate_number,')'), '—') AS vehicle_label
    FROM schedules s
    LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    WHERE s.status = 'Approved'
      AND CONCAT(s.date_start,' ',COALESCE(s.time_start,'00:00:00'))
          BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR)
              AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
");
$rem24->execute();
foreach ($rem24->fetchAll() as $row) {
    $dup = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id   = ?
          AND message   LIKE ?
          AND message   LIKE ?
          AND DATE(created_at) = DATE(NOW())
    ");
    $dup->execute([
        $row['user_id'],
        '%[ref:' . $row['schedule_id'] . ']%',
        '%24 hours%'
    ]);
    if ((int)$dup->fetchColumn() > 0) continue;
    notifyRequestorUpcoming24h(
        $pdo, (int)$row['user_id'], (int)$row['schedule_id'],
        $row['destination'], $row['date_start'], $row['time_start'],
        $row['date_end'], $row['time_end'], $row['driver_name'], $row['vehicle_label']
    );
    emailRequestorUpcoming24h($pdo, (int)$row['user_id'], (int)$row['schedule_id'],
        $row['destination'], $row['date_start'], $row['time_start'],
        $row['date_end'], $row['time_end'], $row['driver_name'], $row['vehicle_label']);
}

/* ── 1-hour reminder ── */
$rem1h = $pdo->prepare("
    SELECT s.schedule_id, s.user_id, s.destination,
           s.date_start, s.time_start, s.date_end, s.time_end,
           COALESCE(d.driver_name, '—') AS driver_name,
           COALESCE(CONCAT(v.brand,' ',v.model,' (',v.plate_number,')'), '—') AS vehicle_label
    FROM schedules s
    LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    WHERE s.status = 'Approved'
      AND CONCAT(s.date_start,' ',COALESCE(s.time_start,'00:00:00'))
          BETWEEN DATE_ADD(NOW(), INTERVAL 55 MINUTE)
              AND DATE_ADD(NOW(), INTERVAL 65 MINUTE)
");
$rem1h->execute();
foreach ($rem1h->fetchAll() as $row) {
    $dup = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id   = ?
          AND message   LIKE ?
          AND message   LIKE ?
          AND DATE(created_at) = DATE(NOW())
    ");
    $dup->execute([
        $row['user_id'],
        '%[ref:' . $row['schedule_id'] . ']%',
        '%1 hour%'
    ]);
    if ((int)$dup->fetchColumn() > 0) continue;
    notifyRequestorUpcoming1h(
        $pdo, (int)$row['user_id'], (int)$row['schedule_id'],
        $row['destination'], $row['date_start'], $row['time_start'],
        $row['date_end'], $row['time_end'], $row['driver_name'], $row['vehicle_label']
    );
    emailRequestorUpcoming1h($pdo, (int)$row['user_id'], (int)$row['schedule_id'],
        $row['destination'], $row['date_start'], $row['time_start'],
        $row['date_end'], $row['time_end'], $row['driver_name'], $row['vehicle_label']);
}

/* ── Current user ── */
$cu = $pdo->prepare("SELECT u.*, o.office_id AS u_office_id, o.office_name,
                            d.dept_id AS department_id, d.dept_name AS department_name
                    FROM users u
                    LEFT JOIN offices o ON u.office_id=o.office_id
                    LEFT JOIN departments d ON u.dept_id=d.dept_id
                    WHERE u.user_id=?");
$cu->execute([$_SESSION['user_id']]);
$me = $cu->fetch();

/* ── GRACE PERIOD HELPER ── */
function graceExpired(array $row): bool {
    if (($row['status'] ?? '') !== 'OnTrip') return false;
    $endDt = ($row['date_end'] ?? '') . ' ' . ($row['time_end'] ?? '23:59:00');
    $graceUntil = strtotime($endDt) + 3600;
    return time() > $graceUntil;
}

/* ── Helper: fetch schedule row ── */
function fetchSchedule(PDO $pdo, int $sid): ?array {
    $s = $pdo->prepare("
        SELECT s.*, u.user_id AS req_user_id, u.username,
               COALESCE(v.brand,'') AS v_brand, COALESCE(v.model,'') AS v_model,
               COALESCE(v.plate_number,'') AS plate_number,
               COALESCE(d.driver_name,'') AS driver_name
        FROM schedules s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
        LEFT JOIN drivers d ON s.driver_id = d.driver_id
        WHERE s.schedule_id = ?");
    $s->execute([$sid]);
    return $s->fetch() ?: null;
}

/* ── AUTO-CANCEL ── */
try {
    $overdueStmt = $pdo->prepare("
        SELECT s.schedule_id, s.user_id, s.destination, s.office_id
        FROM schedules s
        WHERE s.status = 'Pending'
          AND CONCAT(s.date_start, ' ', COALESCE(s.time_start, '23:59:00')) < NOW()
    ");
    $overdueStmt->execute();
    $overdueRows = $overdueStmt->fetchAll();
    foreach ($overdueRows as $or) {
        $cancelStmt = $pdo->prepare("
            UPDATE schedules
            SET status = 'Cancelled',
                cancel_reason = 'Overdue and not approved.',
                cancelled_by = 'System (Auto-Cancelled)'
            WHERE schedule_id = ? AND status = 'Pending'
        ");
        $cancelStmt->execute([$or['schedule_id']]);
        if ($cancelStmt->rowCount() > 0) {
            require_once 'notify_requestor.php';
            notifyRequestorCancelled(
                $pdo, (int)$or['user_id'], (int)$or['schedule_id'],
                $or['destination'], 'Overdue and not approved.', 'System (Auto-Cancelled)'
            );
        }
    }
} catch (PDOException $e) {}

/* ════════════════════════════════════════════════
   POST HANDLERS
   ════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $oid    = $me['u_office_id'] ?? null;

    /* ── ADD ── */
   if ($action === 'add') {
    try {
        $checkUserId = isset($_POST['requested_user_id']) && (int)$_POST['requested_user_id'] > 0
            ? (int)$_POST['requested_user_id']
            : (int)$_POST['user_id'];

        // ✅ FIX: Fetch the REQUESTOR's actual office and department
        $reqStmt = $pdo->prepare("
            SELECT u.office_id, u.dept_id, o.office_name
            FROM users u
            LEFT JOIN offices o ON u.office_id = o.office_id
            WHERE u.user_id = ?
        ");
        $reqStmt->execute([$checkUserId]);
        $reqInfo = $reqStmt->fetch();

        $useOfficeId = !empty($reqInfo['office_id']) ? (int)$reqInfo['office_id'] : (int)($_POST['office_id'] ?? 0);
        $useDeptId   = !empty($reqInfo['dept_id'])   ? (int)$reqInfo['dept_id']   : (!empty($_POST['department_id']) ? (int)$_POST['department_id'] : null);

        $dupCheck = $pdo->prepare("
            SELECT COUNT(*) FROM schedules
            WHERE user_id = ?
              AND status IN ('Pending', 'Approved', 'OnTrip')
              AND date_start <= ?
              AND date_end >= ?
        ");
        $dupCheck->execute([
            $checkUserId,
            sanitize($_POST['date_end']),
            sanitize($_POST['date_start'])
        ]);
        if ((int)$dupCheck->fetchColumn() > 0) {
            $_SESSION['flash']['danger'] = "This requestor already has an active booking on the selected date(s). Please choose a different date.";
            header("Location: Schedules.php");
            exit;
        }

        $pdo->prepare("INSERT INTO schedules
            (user_id, office_id, department_id, date_start, date_end,
            time_start, time_end, destination, purpose, passengers,
            status, booked_by_staff)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)")
            ->execute([
                $checkUserId,
                $useOfficeId,
                $useDeptId,
                sanitize($_POST['date_start']), sanitize($_POST['date_end']),
                sanitize($_POST['time_start']), sanitize($_POST['time_end']),
                sanitize($_POST['destination']), sanitize($_POST['purpose']),
                max(1, (int)($_POST['passengers'] ?? 1)),
                // ✅ FIX: Only set booked_by_staff if booking FOR someone else
                ($checkUserId !== (int)$me['user_id']) ? (int)$me['user_id'] : null
            ]);

        $newId = (int)$pdo->lastInsertId();

        notifyRequestorSubmitted(
            $pdo, $checkUserId, $newId,
            sanitize($_POST['destination']),
            sanitize($_POST['date_start']),
            sanitize($_POST['time_start']),
            sanitize($_POST['time_end'])
        );

        notifyStaffPendingReminder(
            $pdo, $useOfficeId, $newId,
            $me['username'],
            sanitize($_POST['destination']),
            sanitize($_POST['date_start']),
            sanitize($_POST['time_start']),
            sanitize($_POST['date_end']),
            sanitize($_POST['time_end']),
            max(1, (int)($_POST['passengers'] ?? 1))
        );

        $_SESSION['flash']['success'] = "Schedule request added successfully.";
    } catch (PDOException $e) {
        $_SESSION['flash']['danger'] = "DB error (add): " . $e->getMessage();
    }

    /* ── APPROVE ── */
    } elseif ($action === 'approve') {
        $sid = (int)$_POST['schedule_id'];
        $vid = (int)$_POST['vehicle_id'];
        $did = (int)$_POST['driver_id'];
        $row = fetchSchedule($pdo, $sid);

        $userDupCheck = $pdo->prepare("
            SELECT COUNT(*) FROM schedules
            WHERE user_id = ?
              AND schedule_id != ?
              AND status IN ('Approved', 'OnTrip')
              AND date_start <= ?
              AND date_end >= ?
        ");
        $userDupCheck->execute([
            (int)$row['user_id'], $sid,
            $row['date_end'], $row['date_start']
        ]);
        if ((int)$userDupCheck->fetchColumn() > 0) {
            $_SESSION['flash']['danger'] = "Cannot approve: this requestor already has an approved or active trip on the same date(s).";
            header("Location: Schedules.php"); exit;
        }

        $vConflictRows = [];
        $dConflictRows = [];

        $vConflicts = $pdo->prepare("
            SELECT * FROM schedules
            WHERE vehicle_id = ?
              AND schedule_id != ?
              AND arrived_at IS NULL
              AND status IN ('OnTrip','Approved')
              AND date_start <= ?
              AND date_end >= ?
        ");
        $vConflicts->execute([$vid, $sid, $row['date_end'], $row['date_start']]);
        $vConflictRows = array_values(array_filter(
            $vConflicts->fetchAll(),
            fn($r) => !graceExpired($r)
        ));

        $dConflicts = $pdo->prepare("
            SELECT * FROM schedules
            WHERE driver_id = ?
              AND schedule_id != ?
              AND arrived_at IS NULL
              AND status IN ('OnTrip','Approved')
              AND date_start <= ?
              AND date_end >= ?
        ");
        $dConflicts->execute([$did, $sid, $row['date_end'], $row['date_start']]);
        $dConflictRows = array_values(array_filter(
            $dConflicts->fetchAll(),
            fn($r) => !graceExpired($r)
        ));

        if (count($vConflictRows) > 0) {
            $conflicting = array_values($vConflictRows)[0];
            $newInfo = "{$row['date_start']} to {$row['date_end']}, {$row['time_start']}–{$row['time_end']}";
            notifyAdminsOfConflict($pdo, $oid, $_SESSION['username'], $conflicting, $newInfo);
            $_SESSION['flash']['danger'] = "Cannot approve: vehicle is currently on trip or already booked on the same date. All admins have been notified.";
        } elseif (count($dConflictRows) > 0) {
            $conflicting = array_values($dConflictRows)[0];
            $newInfo = "{$row['date_start']} to {$row['date_end']}, {$row['time_start']}–{$row['time_end']}";
            notifyAdminsOfConflict($pdo, $oid, $_SESSION['username'], $conflicting, $newInfo);
            $_SESSION['flash']['danger'] = "Cannot approve: driver is currently on trip or already booked on the same date. All admins have been notified.";
        } else {
            $pdo->prepare("UPDATE schedules SET status='Approved',vehicle_id=?,driver_id=? WHERE schedule_id=?")
                ->execute([$vid, $did, $sid]);

            $row = fetchSchedule($pdo, $sid);
            $vLabel = trim("{$row['v_brand']} {$row['v_model']} ({$row['plate_number']})");

            notifyRequestorApproved($pdo, (int)$row['req_user_id'], $sid,
                $row['destination'], $row['date_start'], $row['time_start'],
                $row['date_end'], $row['time_end'], $row['driver_name'], $vLabel);

            notifyStaffApproved($pdo, (int)$row['office_id'], $sid,
                $row['username'], $row['destination'], $row['date_start'],
                $row['time_start'], $row['date_end'], $row['time_end'],
                $row['driver_name'], $vLabel, $_SESSION['username'] ?? 'Admin');

            emailRequestorApproved($pdo, (int)$row['req_user_id'], $sid,
                $row['destination'], $row['date_start'], $row['time_start'],
                $row['date_end'], $row['time_end'], $row['driver_name'], $vLabel);

            $_SESSION['flash']['success'] = "Schedule approved.";
        }

    /* ── REJECT ── */
    } elseif ($action === 'reject') {
        try {
            $reason = sanitize($_POST['rejection_reason'] ?? '');
            $sid    = (int)$_POST['schedule_id'];
            $row    = fetchSchedule($pdo, $sid);

            $stmt = $pdo->prepare("UPDATE schedules SET status='Rejected',rejection_reason=? WHERE schedule_id=? AND status NOT IN ('Completed','OnTrip')");
            $stmt->execute([$reason, $sid]);

            if ($stmt->rowCount() > 0 && $row) {
                notifyRequestorRejected($pdo, (int)$row['req_user_id'], $sid, $row['destination'], $reason);
                notifyStaffRejected($pdo, (int)$row['office_id'], $sid, $row['username'], $row['destination'], $reason, $_SESSION['username'] ?? 'Admin');
                emailRequestorRejected($pdo, (int)$row['req_user_id'], $sid, $row['destination'], $reason);
                $_SESSION['flash']['warning'] = "Schedule rejected.";
            } else {
                $_SESSION['flash']['danger'] = "Could not reject.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }

    /* ── CANCEL ── */
    } elseif ($action === 'cancel') {
        try {
            $reason = sanitize($_POST['cancel_reason'] ?? '');
            $sid    = (int)$_POST['schedule_id'];
            $row    = fetchSchedule($pdo, $sid);

            $roleStmt = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
            $roleStmt->execute([$_SESSION['user_id']]);
            $roleRow  = $roleStmt->fetch();
            $userRole = $roleRow ? ucfirst($roleRow['role']) : 'Admin';
            $cancelledByLabel = ($_SESSION['username'] ?? 'Unknown') . ' (' . $userRole . ')';

            $stmt = $pdo->prepare("UPDATE schedules SET status='Cancelled', cancel_reason=?, cancelled_by=? WHERE schedule_id=? AND status IN ('Pending','Approved')");
            $stmt->execute([$reason, $cancelledByLabel, $sid]);

            if ($stmt->rowCount() > 0 && $row) {
                notifyRequestorCancelled($pdo, (int)$row['req_user_id'], $sid, $row['destination'], $reason, $cancelledByLabel);
                notifyStaffCancelled($pdo, (int)$row['office_id'], $sid, $row['username'], $row['destination'], $reason, $cancelledByLabel);
                emailRequestorCancelled($pdo, (int)$row['req_user_id'], $sid, $row['destination'], $reason, $cancelledByLabel);
                $_SESSION['flash']['warning'] = "Schedule cancelled.";
            } else {
                $_SESSION['flash']['danger'] = "Could not cancel.";
            }
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }

    /* ── RESCHEDULE ── */
    } elseif ($action === 'reschedule') {
        try {
            $sid = (int)$_POST['schedule_id'];
            $row = fetchSchedule($pdo, $sid);

            $oldDateStart = $row['date_start'] ?? '';
            $oldDateEnd   = $row['date_end']   ?? '';
            $oldTimeStart = $row['time_start'] ?? '';
            $oldTimeEnd   = $row['time_end']   ?? '';

            $newDateStart = sanitize($_POST['date_start']);
            $newDateEnd   = sanitize($_POST['date_end']);
            $newTimeStart = sanitize($_POST['time_start']);
            $newTimeEnd   = sanitize($_POST['time_end']);
            $newDest      = sanitize($_POST['destination']);
            $newPurp      = sanitize($_POST['purpose']);

            $pdo->prepare("UPDATE schedules
                SET date_start=?,date_end=?,time_start=?,time_end=?,
                    destination=?,purpose=?,status='Pending',vehicle_id=NULL,driver_id=NULL
                WHERE schedule_id=? AND status NOT IN ('Completed','OnTrip')")
                ->execute([$newDateStart,$newDateEnd,$newTimeStart,$newTimeEnd,$newDest,$newPurp,$sid]);

            if ($row) {
                $oldStart = date('M d, Y g:i A', strtotime($oldDateStart . ' ' . $oldTimeStart));
                $oldEnd   = date('M d, Y g:i A', strtotime($oldDateEnd   . ' ' . $oldTimeEnd));
                $newStart = date('M d, Y g:i A', strtotime($newDateStart . ' ' . $newTimeStart));
                $newEnd   = date('M d, Y g:i A', strtotime($newDateEnd   . ' ' . $newTimeEnd));

                $tripStmt = $pdo->prepare("SELECT trip_ticket_no, office_id FROM schedules WHERE schedule_id=?");
                $tripStmt->execute([$sid]);
                $tripRow = $tripStmt->fetch();
                $tripNo  = !empty($tripRow['trip_ticket_no'])
                    ? $tripRow['trip_ticket_no']
                    : '#' . str_pad($sid, 4, '0', STR_PAD_LEFT);

                $adminNotifMsg = "Trip {$tripNo} has been rescheduled by {$_SESSION['username']} — "
                    . "Old: {$oldStart} to {$oldEnd} — "
                    . "New: {$newStart} to {$newEnd}. [ref:{$sid}]";

                $admins = $pdo->prepare("SELECT user_id FROM users WHERE role='admin' AND office_id=? AND user_id != ?");
                $admins->execute([$tripRow['office_id'], $_SESSION['user_id']]);
                $notifInsert = $pdo->prepare("INSERT INTO notifications (user_id, office_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
                foreach ($admins->fetchAll() as $admin) {
                    $notifInsert->execute([$admin['user_id'], $tripRow['office_id'], $adminNotifMsg]);
                }

                notifyStaffRescheduled(
                    $pdo, (int)$tripRow['office_id'], $sid,
                    $row['username'], $newDest,
                    $oldDateStart, $oldTimeStart, $oldDateEnd, $oldTimeEnd,
                    $newDateStart, $newTimeStart, $newDateEnd, $newTimeEnd,
                    $_SESSION['username'] ?? 'Admin'
                );

                notifyRequestorRescheduled(
                    $pdo, (int)$row['req_user_id'], $sid, $newDest,
                    $oldDateStart, $oldTimeStart, $oldDateEnd, $oldTimeEnd,
                    $newDateStart, $newTimeStart, $newDateEnd, $newTimeEnd
                );
            }

            $_SESSION['flash']['success'] = "Schedule rescheduled.";
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }

    /* ── CHANGE ASSIGNMENT ── */
    } elseif ($action === 'change_assignment') {
        try {
            $sid = (int)$_POST['schedule_id'];
            $vid = (int)$_POST['vehicle_id'];
            $did = (int)$_POST['driver_id'];
            $row = fetchSchedule($pdo, $sid);

            $vConflictRows = [];
            $dConflictRows = [];

            $vConflicts = $pdo->prepare("
                SELECT * FROM schedules
                WHERE vehicle_id=? AND schedule_id!=? AND arrived_at IS NULL
                  AND status IN ('OnTrip','Approved')
                  AND date_start <= ? AND date_end >= ?
                  AND time_start < ? AND time_end > ?
            ");
            $vConflicts->execute([$vid, $sid, $row['date_end'], $row['date_start'], $row['time_end'], $row['time_start']]);
            $vConflictRows = array_filter($vConflicts->fetchAll(), fn($r) => !graceExpired($r));

            $dConflicts = $pdo->prepare("
                SELECT * FROM schedules
                WHERE driver_id=? AND schedule_id!=? AND arrived_at IS NULL
                  AND status IN ('OnTrip','Approved')
                  AND date_start <= ? AND date_end >= ?
                  AND time_start < ? AND time_end > ?
            ");
            $dConflicts->execute([$did, $sid, $row['date_end'], $row['date_start'], $row['time_end'], $row['time_start']]);
            $dConflictRows = array_filter($dConflicts->fetchAll(), fn($r) => !graceExpired($r));

            if (count($vConflictRows) > 0) {
                $conflicting = array_values($vConflictRows)[0];
                $newInfo = "{$row['date_start']} to {$row['date_end']}, {$row['time_start']}–{$row['time_end']}";
                notifyAdminsOfConflict($pdo, $oid, $_SESSION['username'], $conflicting, $newInfo);
                $_SESSION['flash']['danger'] = "Cannot change: vehicle is already booked on the same date. All admins have been notified.";
            } elseif (count($dConflictRows) > 0) {
                $conflicting = array_values($dConflictRows)[0];
                $newInfo = "{$row['date_start']} to {$row['date_end']}, {$row['time_start']}–{$row['time_end']}";
                notifyAdminsOfConflict($pdo, $oid, $_SESSION['username'], $conflicting, $newInfo);
                $_SESSION['flash']['danger'] = "Cannot change: driver is already booked on the same date. All admins have been notified.";
              } else {
    $pdo->prepare("UPDATE schedules SET vehicle_id=?, driver_id=? WHERE schedule_id=?")
        ->execute([$vid, $did, $sid]);

    $updatedRow = fetchSchedule($pdo, $sid);
    if ($updatedRow) {
        $newVehicleLabel = trim("{$updatedRow['v_brand']} {$updatedRow['v_model']} ({$updatedRow['plate_number']})");
        notifyRequestorAssignmentChanged($pdo, (int)$row['req_user_id'], $sid, $row['destination'], $updatedRow['driver_name'], $newVehicleLabel);
        notifyStaffAssignmentChanged($pdo, (int)$row['office_id'], $sid, $row['username'], $row['destination'], $updatedRow['driver_name'], $newVehicleLabel, $_SESSION['username'] ?? 'Admin');
        emailRequestorAssignmentChanged($pdo, (int)$row['req_user_id'], $sid, $row['destination'], $updatedRow['driver_name'], $newVehicleLabel);
    }

    $_SESSION['flash']['success'] = "Assignment updated.";
}
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }

    /* ── DELETE ── */
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare("DELETE FROM schedules WHERE schedule_id=?")->execute([(int)$_POST['schedule_id']]);
            $_SESSION['flash']['success'] = "Schedule deleted.";
        } catch (PDOException $e) {
            $_SESSION['flash']['danger'] = "DB error: " . $e->getMessage();
        }
    }

    header("Location: Schedules.php"); exit;
}

/* ── Fetch schedules ── */
$oid = $me['u_office_id'] ?? null;
$officeName = $me['office_name'] ?? '';
$schedules = [];
if ($oid) {
    $st = $pdo->prepare("SELECT s.*,
        u.username,
        COALESCE(v.plate_number,'—') AS plate_number, COALESCE(v.brand,'') AS brand, COALESCE(v.model,'') AS model,
        COALESCE(dr.driver_name,'—') AS driver_name, o.office_name, dept.dept_name AS department_name,
        s.trip_ticket_no, s.created_at, s.passengers,
        sb.username AS booked_by_name
        FROM schedules s
        JOIN users u ON s.user_id=u.user_id
        LEFT JOIN vehicles v ON s.vehicle_id=v.vehicle_id
        LEFT JOIN drivers dr ON s.driver_id=dr.driver_id
        JOIN offices o ON s.office_id=o.office_id
        LEFT JOIN departments dept ON s.department_id=dept.dept_id
        LEFT JOIN users sb ON s.booked_by_staff=sb.user_id
        WHERE s.office_id=? ORDER BY s.schedule_id DESC");
    $st->execute([$oid]);
    $schedules = $st->fetchAll();
}

/* ── notifyAdminsOfConflict ── */
function notifyAdminsOfConflict(PDO $pdo, int $officeId, string $adminUsername, array $conflictingRow, string $newBookingInfo): void {
    $tripNo = !empty($conflictingRow['trip_ticket_no'])
        ? $conflictingRow['trip_ticket_no']
        : '#' . str_pad($conflictingRow['schedule_id'], 4, '0', STR_PAD_LEFT);

    $msg = "⚠️ Schedule Conflict Detected by {$adminUsername}: "
         . "The newly requested booking ({$newBookingInfo}) overlaps with existing Trip {$tripNo} "
         . "(Requestor: {$conflictingRow['username']}, "
         . "{$conflictingRow['date_start']} to {$conflictingRow['date_end']}, "
         . "Status: {$conflictingRow['status']}). "
         . "[ref:{$conflictingRow['schedule_id']}]";

    $admins = $pdo->prepare("SELECT user_id FROM users WHERE role='admin' AND office_id=?");
    $admins->execute([$officeId]);
    $insert = $pdo->prepare("INSERT INTO notifications (user_id, office_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
    foreach ($admins->fetchAll() as $admin) {
        $insert->execute([$admin['user_id'], $officeId, $msg]);
    }
}

function getTicketNo(PDO $pdo, int $id, ?string $ds): string {
    if (!$ds) return '-';

    // 1. Return already-stored complete ticket immediately
    $stored = $pdo->prepare("SELECT trip_ticket_no, office_id FROM schedules WHERE schedule_id = ?");
    $stored->execute([$id]);
    $row = $stored->fetch();

    if (!empty($row['trip_ticket_no']) && preg_match('/\/\d{4}$/', $row['trip_ticket_no'])) {
        return $row['trip_ticket_no'];
    }

    $officeId = (int)($row['office_id'] ?? 0);

    // 2. Determine prefix from office name
    $offStmt = $pdo->prepare("SELECT office_name FROM offices WHERE office_id = ?");
    $offStmt->execute([$officeId]);
    $officeName = strtolower($offStmt->fetchColumn() ?? '');

    if (str_contains($officeName, 'campus')) {
        $prefix = 'CAM-TRANSPO';
    } elseif (str_contains($officeName, 'rde')) {
        $prefix = 'RDE-TRANSPO';
    } else {
        $prefix = 'AUX-TRANSPO';
    }

    $m = date('m', strtotime($ds));
    $y = date('Y', strtotime($ds));

    // 3. Rank within SAME OFFICE + SAME MONTH/YEAR only
    $seqStmt = $pdo->prepare("
        SELECT seq FROM (
            SELECT schedule_id,
                   RANK() OVER (
                       PARTITION BY office_id, MONTH(date_start), YEAR(date_start)
                       ORDER BY schedule_id ASC
                   ) AS seq
            FROM schedules
            WHERE MONTH(date_start) = ?
              AND YEAR(date_start)  = ?
              AND office_id         = ?
        ) ranked
        WHERE schedule_id = ?
    ");
    $seqStmt->execute([$m, $y, $officeId, $id]);
    $seq = (int)$seqStmt->fetchColumn();

    $ticketNo = $prefix . '-' . $m . '/' . $y . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // 4. Persist to DB so it never changes and stays in sync with print
    $pdo->prepare("
        UPDATE schedules
        SET trip_ticket_no = ?
        WHERE schedule_id = ?
          AND (trip_ticket_no IS NULL OR trip_ticket_no NOT REGEXP '/[0-9]{4}$')
    ")->execute([$ticketNo, $id]);

    return $ticketNo;
}
/* ── Vehicle / Driver lists ── */
$vStmt = $pdo->prepare("SELECT * FROM vehicles WHERE status IN ('Available','Active') AND (vehicle_scope='Both' OR vehicle_scope=? OR office_id=?) ORDER BY plate_number");
$vStmt->execute([$officeName, $oid]); $vehicles = $vStmt->fetchAll();
$dStmt = $pdo->prepare("SELECT * FROM drivers WHERE status IN ('Available','Active') AND (driver_scope='Both' OR driver_scope=? OR office_id=?) ORDER BY driver_name");
$dStmt->execute([$officeName, $oid]); $drivers = $dStmt->fetchAll();
$avStmt = $pdo->prepare("SELECT * FROM vehicles WHERE status IN ('Available','Active') AND (vehicle_scope='Both' OR vehicle_scope=? OR office_id=?) ORDER BY plate_number");
$avStmt->execute([$officeName, $oid]); $allVehicles = $avStmt->fetchAll();
$adStmt = $pdo->prepare("SELECT * FROM drivers WHERE status IN ('Available','Active') AND (driver_scope='Both' OR driver_scope=? OR office_id=?) ORDER BY driver_name");
$adStmt->execute([$officeName, $oid]); $allDrivers = $adStmt->fetchAll();

/* ── Driver-Vehicle default assignments ── */
$dvaStmt = $pdo->query("SELECT driver_id, vehicle_id FROM driver_vehicle_assignments");
$driverVehicleMap = [];
foreach ($dvaStmt->fetchAll() as $row) {
    $driverVehicleMap[(int)$row['driver_id']] = (int)$row['vehicle_id'];
}
$driverVehicleMapJson = json_encode($driverVehicleMap);

/* ── Approved/OnTrip schedules for JS conflict detection ── */
$approvedScheds = $pdo->query("
    SELECT schedule_id, vehicle_id, driver_id,
          date_start, date_end, time_start, time_end,
          status, arrived_at,
          TIMESTAMPADD(HOUR, 1,
              CONCAT(date_end,' ',COALESCE(time_end,'23:59:00'))
          ) AS grace_until
    FROM schedules
    WHERE status IN ('Approved','OnTrip')
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Office users for add form ── */
$userStmt = $pdo->prepare("SELECT user_id, username FROM users WHERE office_id = ? ORDER BY username");
$userStmt->execute([$me['u_office_id']]);
$officeUsers = $userStmt->fetchAll();
$filter = $_GET['filter'] ?? 'All';
$filtered = $filter === 'All' ? $schedules : array_filter($schedules, fn($s) => $s['status'] === $filter);

$fmt = function($t) {
    if (!$t || $t === '--') return '--';
    foreach (['H:i:s','H:i'] as $f) { $dt = DateTime::createFromFormat($f, $t); if ($dt) return $dt->format('g:i A'); }
    return $t;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta charset="UTF-8">
  <title>Schedules – CSU VSS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* ── Custom Select ── */
.custom-select-wrap { position: relative; }
.custom-select-trigger {
    width: 100%; padding: 9px 40px 9px 13px;
    border: 1.5px solid #e0d0d0; border-radius: 10px;
    background: #fff; font-size: .88rem; color: #333;
    cursor: pointer; display: flex; align-items: center; gap: 10px;
    transition: border-color .2s, box-shadow .2s; user-select: none;
    min-height: 44px; position: relative;
}
.custom-select-trigger:focus,
.custom-select-trigger.open { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,.1); outline: none; }
.custom-select-trigger::after {
    content: ''; position: absolute; right: 13px; top: 50%;
    transform: translateY(-50%); border: 5px solid transparent;
    border-top: 6px solid #888; margin-top: 3px; transition: transform .2s;
    pointer-events: none;
}
.custom-select-trigger.open::after { transform: translateY(-50%) rotate(180deg); margin-top: -3px; }
.custom-select-placeholder { color: #bbb; font-size: .88rem; }
.custom-select-dropdown {
    position: absolute; top: calc(100% + 5px); left: 0; right: 0; z-index: 600;
    background: #fff; border: 1.5px solid #e0d0d0; border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,.13); max-height: 260px; overflow-y: auto;
    display: none; padding: 6px;
}
.custom-select-dropdown.open { display: block; }
.cs-search-wrap {
    display: flex; align-items: center; gap: 6px;
    background: #f8f8f8; border-radius: 8px; padding: 6px 10px; margin-bottom: 5px;
}
.cs-search-input { flex: 1; border: none; background: transparent; font-size: .83rem; outline: none; color: #333; }
.cs-option {
    display: flex; align-items: center; gap: 9px;
    padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: background .12s;
}
.cs-option:hover { background: #fdf5f5; }
.cs-option.selected { background: #fdf5f5; }
.cs-opt-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .85rem; flex-shrink: 0; }
.cs-opt-icon.driver-ico  { background: #fdf5f5; color: #800000; }
.cs-opt-icon.vehicle-ico { background: #eff6ff; color: #1d4ed8; }
.cs-opt-label { font-weight: 600; font-size: .83rem; color: #1a1a1a; }
.cs-opt-sub   { font-size: .7rem; color: #888; }
.cs-opt-badge { font-size: .66rem; font-weight: 700; padding: 1px 7px; border-radius: 10px; margin-left: auto; flex-shrink: 0; white-space: nowrap; }
.badge-assigned   { background: #d1e7dd; color: #0f5132; }
.badge-unassigned { background: #fff3cd; color: #856404; }
.badge-busy       { background: #f8d7da; color: #842029; }
@keyframes highlightRow {
    0%   { background: #fff3cd; }
    60%  { background: #fff3cd; }
    100% { background: transparent; }
}
.row-highlight { animation: highlightRow 3s ease forwards; }
*{box-sizing:border-box}
body{background:#f5f0f0;font-family:'Segoe UI',sans-serif}
.sidebar{min-height:100vh;background:linear-gradient(180deg,#800000,#6b0000);width:240px;position:fixed;top:0;left:0;z-index:400;display:flex;flex-direction:column}
.sidebar-brand{padding:1.25rem 1rem 1rem;border-bottom:1px solid rgba(255,255,255,.15);display:flex;align-items:center;gap:10px}
.sidebar-logo{width:42px;height:42px;border-radius:50%;background:#fff;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sidebar-logo img{width:38px;height:38px;object-fit:contain}
.sidebar-brand-text{color:#fff;font-size:.82rem;font-weight:700;line-height:1.3}
.sidebar-brand-text span{display:block;font-size:.72rem;font-weight:400;opacity:.7}
.sidebar .nav-link{color:rgba(255,255,255,.8);padding:.6rem 1.25rem;font-size:.88rem;display:flex;align-items:center;gap:10px;border-left:3px solid transparent;transition:all .15s}
.sidebar .nav-link:hover{color:#fff;background:rgba(255,255,255,.1);border-left-color:rgba(255,255,255,.4)}
.sidebar .nav-link.active{color:#fff;background:rgba(255,255,255,.15);border-left-color:#fff;font-weight:600}
.sidebar .nav-link i{font-size:1rem;width:18px}
.sidebar-divider{border-color:rgba(255,255,255,.15);margin:.5rem 1rem}
.nav-section-label{padding:.75rem 1.25rem .25rem;font-size:.68rem;font-weight:700;color:rgba(255,255,255,.45);letter-spacing:.08em;text-transform:uppercase}
.topbar{background:#fff;border-bottom:1px solid #e8dede;padding:.7rem 1.5rem;margin-left:240px;position:sticky;top:0;z-index:99;display:flex;align-items:center;gap:10px;}
.topbar .topbar-title{flex:1;}
.topbar-title{font-weight:700;font-size:1rem;color:#800000}
.topbar-user{display:flex;align-items:center;gap:8px;font-size:.85rem;color:#666}
.user-avatar{width:32px;height:32px;border-radius:50%;background:#800000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}
.main-content{margin-left:240px;padding:1.5rem}
.section-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(128,0,0,.07);overflow:hidden}
.section-header{padding:1rem 1.25rem;border-bottom:1px solid #f0e5e5;font-weight:700;font-size:.9rem;color:#800000;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.table thead th{background:#fdf5f5;color:#800000;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid #f0e5e5;padding:.75rem 1rem;white-space:nowrap}
.table tbody td{padding:.7rem 1rem;font-size:.85rem;color:#444;vertical-align:middle;border-color:#fdf5f5}
.table tbody tr:hover{background:#fdf8f8}
.bp{display:inline-block;padding:4px 10px;border-radius:20px;font-size:.75rem;font-weight:600}
.bp-pending{background:#fff3cd;color:#856404}
.bp-approved{background:#d1e7dd;color:#0f5132}
.bp-ontrip{background:#fff0d6;color:#7a4f00}
.bp-completed{background:#cfe2ff;color:#0a3678}
.bp-rejected{background:#f8d7da;color:#842029}
.bp-cancelled{background:#e2e3e5;color:#41464b}
.bp-upcoming{background:#e8d5ff;color:#5a00b4}
.ticket-no{font-size:.78rem;font-weight:700;color:#0f5132;background:#d1e7dd;padding:2px 8px;border-radius:10px;white-space:nowrap}
.ticket-na{font-size:.78rem;color:#aaa;font-style:italic}
.not-assigned{font-size:.8rem;color:#aaa;font-style:italic}
.action-group{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.btn-maroon{background:#800000;color:#fff;border:none}
.btn-maroon:hover{background:#6b0000;color:#fff}
.filter-btn{font-size:.8rem;padding:4px 14px;border-radius:20px;border:1.5px solid #e0d0d0;background:#fff;color:#666;cursor:pointer;transition:all .15s;text-decoration:none}
.filter-btn:hover,.filter-btn.active{background:#800000;color:#fff;border-color:#800000}
.mh-maroon{background:linear-gradient(135deg,#800000,#6b0000);color:#fff}
.mh-maroon .btn-close{filter:invert(1)}
.mh-green{background:linear-gradient(135deg,#145a32,#1e8449);color:#fff}
.mh-green .btn-close{filter:invert(1)}
.mh-blue{background:linear-gradient(135deg,#0550a0,#0a3678);color:#fff}
.mh-blue .btn-close{filter:invert(1)}
.mh-orange{background:linear-gradient(135deg,#b84a00,#e67e00);color:#fff}
.mh-orange .btn-close{filter:invert(1)}
.info-badge{display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;border:1px solid #e8cece;border-radius:8px;padding:6px 12px;font-size:.82rem;color:#800000;font-weight:600}
.sbox{background:#f8f9fa;border-radius:10px;padding:1rem;border:1.5px solid #dee2e6}
.sbox-title{font-size:.8rem;font-weight:700;color:#800000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem}
.conflict-alert{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px 14px;font-size:.82rem;color:#856404;display:none}
.conflict-alert.show{display:flex;align-items:center;gap:8px}
.avail-badge{font-size:.72rem;padding:2px 8px;border-radius:10px;font-weight:600}
.avail-ok{background:#d1e7dd;color:#0f5132}
.avail-no{background:#f8d7da;color:#842029}
.avail-grace{background:#fff3cd;color:#856404}
.detail-label{font-size:.72rem;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px}
.detail-value{font-size:.9rem;font-weight:600;color:#333}
.pax-badge{display:inline-flex;align-items:center;gap:4px;background:#e8f4ff;border:1px solid #b8d8f8;border-radius:10px;padding:2px 9px;font-size:.78rem;font-weight:700;color:#0a3678}
#toast-wrap{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem}
.toast-item{padding:.75rem 1.25rem;border-radius:10px;font-size:.85rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);animation:slideIn .3s ease}
.toast-success{background:#d1e7dd;color:#0f5132;border-left:4px solid #0f5132}
.toast-danger{background:#f8d7da;color:#842029;border-left:4px solid #842029}
.toast-warning{background:#fff3cd;color:#856404;border-left:4px solid #ffc107}
@keyframes slideIn{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
.walkin-tag{display:inline-flex;align-items:center;gap:3px;background:#fdf5f5;border:1px solid #e8cece;border-radius:10px;padding:1px 7px;font-size:.68rem;font-weight:700;color:#800000;margin-bottom:2px}
.cancelled-by-tag{display:inline-flex;align-items:center;gap:5px;background:#f1f3f5;border:1px solid #ced4da;border-radius:8px;padding:4px 10px;font-size:.78rem;color:#41464b;margin-top:5px}
.cancelled-by-tag i{color:#800000}
.global-search-wrap{padding:.85rem 1.25rem;background:#fff;border-bottom:1px solid #f0e5e5;display:flex;align-items:center;gap:.75rem}
.global-search-outer{position:relative;flex:1;max-width:440px}
.global-search-input{width:100%;padding:.48rem 2.4rem .48rem 2.5rem;border-radius:10px;border:2px solid #e0d0d0;font-size:.87rem;color:#333;background:#fdf8f8;transition:border-color .2s,box-shadow .2s;outline:none}
.global-search-input:focus{border-color:#800000;background:#fff;box-shadow:0 0 0 3px rgba(128,0,0,.1)}
.global-search-input::placeholder{color:#bbb}
.global-search-icon{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#800000;font-size:.9rem;pointer-events:none}
.global-search-clear{position:absolute;right:9px;top:50%;transform:translateY(-50%);background:none;border:none;color:#aaa;font-size:.85rem;cursor:pointer;padding:2px;line-height:1;display:none}
.global-search-clear:hover{color:#800000}
.global-search-count{font-size:.78rem;color:#888;white-space:nowrap}
.search-no-results{text-align:center;color:#aaa;padding:2.5rem 1rem;font-size:.88rem}

/* ══ MOBILE ══ */
.hamburger-btn {
    display: none; background: none; border: none;
    cursor: pointer; padding: 4px 8px; color: #800000;
    font-size: 1.4rem; align-items: center; line-height: 1;
}
.sidebar-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 399;
}
.sidebar-overlay.show { display: block; }

@media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); transition: transform .25s ease; }
    .sidebar.open { transform: translateX(0); }
    .topbar, .main-content { margin-left: 0 !important; }
    .hamburger-btn { display: flex !important; }
    .desktop-sched-table { display: none !important; }
    .global-search-wrap  { display: none !important; }
    .topbar { padding: .6rem 1rem; gap: 8px; }
    .main-content { padding: .75rem; }
    .desktop-add-btn { display: none !important; }

    .mob-stat-strip {
        display: grid; grid-template-columns: repeat(3, 1fr);
        gap: 8px; margin-bottom: 12px;
    }
    .mob-stat-card {
        background: #fff; border-radius: 10px;
        padding: 10px 10px; border-left: 3px solid #800000;
        box-shadow: 0 1px 5px rgba(128,0,0,.06);
    }
    .mob-stat-card.approved { border-left-color: #0f5132; }
    .mob-stat-card.ontrip   { border-left-color: #7a4f00; }
    .mob-stat-card.pending  { border-left-color: #856404; }
    .mob-stat-card.completed{ border-left-color: #0a3678; }
    .mob-stat-card.rejected { border-left-color: #842029; }
    .mob-stat-card.cancelled{ border-left-color: #41464b; }
    .mob-stat-lbl { font-size: .6rem; color: #aaa; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
    .mob-stat-val { font-size: 1.4rem; font-weight: 800; color: #1a1a1a; line-height: 1.1; }

    .mob-filter-row {
        display: flex; gap: 7px; overflow-x: auto;
        padding-bottom: 4px; margin-bottom: 12px; scrollbar-width: none;
    }
    .mob-filter-row::-webkit-scrollbar { display: none; }
    .mob-filter-pill {
        flex-shrink: 0; padding: 6px 14px; border-radius: 20px;
        font-size: .76rem; font-weight: 600; border: 1.5px solid #e0d0d0;
        color: #800000; background: #fff; cursor: pointer;
        white-space: nowrap; text-decoration: none; transition: all .15s;
    }
    .mob-filter-pill.active { background: #800000; color: #fff; border-color: #800000; }

    .mob-search-wrap {
        display: flex; align-items: center;
        background: #fdf8f8; border: 1.5px solid #e0d0d0;
        border-radius: 10px; padding: 0 10px;
        margin-bottom: 12px; gap: 6px;
    }
    .mob-search-wrap:focus-within { border-color: #800000; background: #fff; }
    .mob-search-icon { color: #800000; font-size: .9rem; flex-shrink: 0; line-height: 1; display: flex; align-items: center; }
    .mob-search-input { flex: 1; border: none; background: transparent; padding: .55rem 0; font-size: .86rem; outline: none; min-width: 0; line-height: 1.4; }
    .mob-search-clear { flex-shrink: 0; display: none; align-items: center; background: none; border: none; color: #aaa; cursor: pointer; line-height: 1; padding: 0; }

    .sched-card {
        background: #fff; border-radius: 14px; padding: 13px 14px;
        margin-bottom: 9px; box-shadow: 0 1px 6px rgba(128,0,0,.07);
        border: 1px solid #f5eded;
    }
    .sc-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 9px; }
    .sc-id { font-size: .68rem; color: #bbb; font-weight: 700; margin-bottom: 2px; }
   .sc-name { font-weight: 800; font-size: 1rem; color: #1a1a1a; margin-bottom: 2px; }
.sc-dest { font-size: .8rem; color: #555; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; font-weight: 500; }
.sc-id   { font-size: .68rem; color: #bbb; font-weight: 700; margin-bottom: 3px; }
.sc-meta-val { font-size: .78rem; color: #333; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sc-meta-lbl { font-size: .62rem; color: #999; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
    .sc-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 9px; }
    .sc-meta-item { background: #fdf8f8; border-radius: 8px; padding: 6px 9px; }
    .sc-meta-lbl { font-size: .6rem; color: #bbb; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 1px; }
    .sc-meta-val { font-size: .76rem; color: #444; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sc-actions { display: flex; gap: 7px; padding-top: 9px; border-top: 1px solid #fdf0f0; flex-wrap: wrap; }
    .sc-btn {
        flex: 1; min-width: 70px; padding: 8px 6px; border-radius: 9px;
        font-size: .74rem; font-weight: 600; display: flex; align-items: center;
        justify-content: center; gap: 4px; cursor: pointer; border: none; transition: all .15s;
    }
    .sc-btn-approve  { background: #d1e7dd; color: #0f5132; }
    .sc-btn-reject   { background: #fff3cd; color: #856404; }
    .sc-btn-cancel   { background: #fef2f2; color: #dc2626; }
    .sc-btn-done     { background: #d1e7dd; color: #0f5132; }
    .sc-btn-change   { background: #e8f0fe; color: #1a56db; }
    .sc-btn-resched  { background: #f5f0f0; color: #800000; }
    .sc-btn-view     { background: #f0f4ff; color: #0550a0; }
    .sc-btn-print    { background: #f5f5f5; color: #444; }

    .mob-empty { text-align: center; padding: 40px 20px; color: #bbb; }
    .mob-empty i { font-size: 2rem; display: block; margin-bottom: 8px; opacity: .35; }

    .mob-fab {
        position: fixed; bottom: 24px; right: 20px; z-index: 150;
        width: 58px; height: 58px; background: #800000; color: #fff;
        border: none; border-radius: 50%; font-size: 1.6rem;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 4px 16px rgba(128,0,0,.4); cursor: pointer;
        transition: transform .15s, background .15s;
    }
    .mob-fab:hover  { background: #6b0000; transform: scale(1.05); }
    .mob-fab:active { transform: scale(.95); }

    .sheet-backdrop {
        position: fixed; inset: 0; background: rgba(0,0,0,.45);
        z-index: 300; opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    .sheet-backdrop.open { opacity: 1; pointer-events: all; }

    .mob-sheet {
        position: fixed; bottom: 0; left: 0; right: 0; z-index: 310;
        background: #fff; border-radius: 20px 20px 0 0;
        max-height: 92vh; overflow-y: auto;
        transform: translateY(105%);
        transition: transform .3s cubic-bezier(.4,0,.2,1);
        padding: 0 16px 48px;
    }
    .mob-sheet.open { transform: translateY(0); }
    .sheet-handle { width: 40px; height: 4px; background: #e0d0d0; border-radius: 2px; margin: 12px auto 16px; }
    .sheet-head { font-weight: 700; font-size: 1rem; color: #800000; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .sheet-form-group { margin-bottom: 13px; }
    .sheet-label { font-size: .72rem; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px; display: block; }
    .sheet-input {
        width: 100%; padding: 11px 13px; border-radius: 10px;
        border: 1.5px solid #e0d0d0; font-size: .9rem; color: #333;
        background: #fff; outline: none; -webkit-appearance: none; appearance: none;
    }
    .sheet-input:focus { border-color: #800000; box-shadow: 0 0 0 3px rgba(128,0,0,.1); }
    .sheet-select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 12px center;
    }
    .sheet-actions { display: flex; gap: 10px; margin-top: 18px; }
    .btn-sheet-cancel { flex: 1; padding: 12px; border-radius: 11px; background: #f5f5f5; color: #555; font-weight: 600; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-primary { flex: 2; padding: 12px; border-radius: 11px; background: #800000; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-primary:active { background: #6b0000; }
    .btn-sheet-success { flex: 2; padding: 12px; border-radius: 11px; background: #0f5132; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-warning { flex: 2; padding: 12px; border-radius: 11px; background: #856404; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }
    .btn-sheet-danger  { flex: 2; padding: 12px; border-radius: 11px; background: #dc2626; color: #fff; font-weight: 700; font-size: .88rem; border: none; cursor: pointer; }

    .confirm-emoji { font-size: 2.4rem; text-align: center; margin-bottom: 8px; }
    .confirm-msg   { text-align: center; color: #555; font-size: .9rem; margin-bottom: 3px; }
    .confirm-name  { text-align: center; font-size: 1rem; font-weight: 700; margin-bottom: 5px; }
    .confirm-sub   { text-align: center; font-size: .77rem; color: #aaa; margin-bottom: 16px; }
    .sheet-info-box { background: #fdf8f8; border: 1px solid #f0e5e5; border-radius: 10px; padding: 10px 12px; margin-bottom: 13px; font-size: .8rem; color: #800000; }
    .sheet-info-row { display: flex; justify-content: space-between; margin-bottom: 3px; }
    .sheet-info-lbl { color: #aaa; font-size: .72rem; }
    .conflict-mob { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 8px 11px; font-size: .78rem; color: #856404; display: none; margin-top: 8px; }
    .conflict-mob.show { display: flex; align-items: center; gap: 7px; }
}

@media (min-width: 901px) {
    .mob-fab         { display: none !important; }
    .sheet-backdrop  { display: none !important; }
    .mob-sheet       { display: none !important; }
    .mob-sched-list  { display: none !important; }
    .mob-stat-strip  { display: none !important; }
    .mob-filter-row  { display: none !important; }
    .mob-search-wrap { display: none !important; }
}
</style>
</head>
<body>
<div id="toast-wrap"></div>

<div class="sidebar" id="mainSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo"><img src="../image/Csu.png" alt="CSU Logo"></div>
        <div class="sidebar-brand-text">CSU Vehicle System<span>Admin Panel</span></div>
    </div>
    <nav class="nav flex-column mt-2">
        <div class="nav-section-label">Main</div>
        <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <div class="nav-section-label">Manage</div>
        <a class="nav-link" href="Vehicles.php"><i class="bi bi-truck-front"></i>Vehicles</a>
        <a class="nav-link" href="driverstripcomplete.php"><i class="bi bi-flag-fill"></i>Driver Trip Records</a>
        <a class="nav-link" href="Drivers.php"><i class="bi bi-person-badge"></i>Drivers</a>
        <a class="nav-link" href="drivervehicle.php"><i class="bi bi-link-45deg"></i>Driver-Vehicle</a>
        <a class="nav-link" href="Users.php"><i class="bi bi-people"></i>Users</a>
        <a class="nav-link" href="Offices.php"><i class="bi bi-building"></i>Offices</a>
        <a class="nav-link" href="Department.php"><i class="bi bi-diagram-3"></i>Departments</a>
        <div class="nav-section-label">Scheduling</div>
        <a class="nav-link active" href="Schedules.php"><i class="bi bi-calendar-check"></i>Schedules</a>
        <div class="nav-section-label">Settings</div>
        <?php
        $_notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND (office_id IS NULL OR office_id=?)");
        $_notifStmt->execute([$_SESSION['user_id'], $me['u_office_id'] ?? 0]);
        $_sidebarUnread = (int)$_notifStmt->fetchColumn();
        ?>
        <a class="nav-link" href="notification.php" style="justify-content:space-between">
            <span style="display:flex;align-items:center;gap:10px">
                <i class="bi bi-bell"></i>Notifications
            </span>
            <?php if($_sidebarUnread > 0): ?>
            <span style="background:#e24b4a;color:#fff;font-size:.62rem;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;">
                <?= $_sidebarUnread > 99 ? '99+' : $_sidebarUnread ?>
            </span>
            <?php endif; ?>
        </a>
        <a class="nav-link" href="Signatories.php"><i class="bi bi-pen"></i>Signatories</a>
        <hr class="sidebar-divider">
        <a class="nav-link" href="../Logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>
    </nav>
</div>

<div class="sidebar-overlay" id="schedSidebarOverlay" onclick="toggleSchedSidebar()"></div>
<div class="topbar">
  <button class="hamburger-btn" onclick="toggleSchedSidebar()" aria-label="Menu">
    <i class="bi bi-list"></i>
  </button>
  <div class="topbar-title"><i class="bi bi-calendar-check me-2"></i>Schedules</div>
  <div class="topbar-user">
    <div class="user-avatar"><?= strtoupper(substr($_SESSION['username'],0,1)) ?></div>
    <div>
      <div style="font-weight:600;color:#333;font-size:.85rem"><?= htmlspecialchars($_SESSION['username']) ?></div>
      <div style="font-size:.72rem;color:#800000">Administrator</div>
    </div>
  </div>
</div>

<div class="main-content">
<?php
$icons  = ['success'=>'check-circle','danger'=>'x-circle','warning'=>'exclamation-triangle','info'=>'info-circle'];
$borders= ['success'=>'#0f5132','danger'=>'#842029','warning'=>'#856404','info'=>'#055160'];
foreach (['success','danger','warning','info'] as $type):
    if (!empty($_SESSION['flash'][$type])): ?>
<div class="alert alert-<?=$type?> alert-dismissible fade show mx-0 mb-3" role="alert"
    style="font-size:.87rem;border-radius:10px;border-left:4px solid <?=$borders[$type]?>">
    <i class="bi bi-<?=$icons[$type]?> me-2"></i><?=htmlspecialchars($_SESSION['flash'][$type])?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash'][$type]); endif; endforeach; ?>

<div class="section-card">
  <div class="section-header">
    <span><i class="bi bi-calendar-check me-2"></i>All Schedules
      <?php if($oid): ?>
      <small class="ms-2" style="font-size:.75rem;color:#a05050;font-weight:400"><i class="bi bi-building me-1"></i><?=htmlspecialchars($me['office_name']??'')?></small>
      <?php endif; ?>
    </span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a href="?filter=All"       class="filter-btn <?=$filter==='All'      ?'active':''?>">All</a>
      <a href="?filter=Pending"   class="filter-btn <?=$filter==='Pending'  ?'active':''?>">Pending</a>
      <a href="?filter=Approved"  class="filter-btn <?=$filter==='Approved' ?'active':''?>">Approved</a>
      <a href="?filter=OnTrip"    class="filter-btn <?=$filter==='OnTrip'   ?'active':''?>">On Trip</a>
      <a href="?filter=Completed" class="filter-btn <?=$filter==='Completed'?'active':''?>">Completed</a>
      <a href="?filter=Rejected"  class="filter-btn <?=$filter==='Rejected' ?'active':''?>">Rejected</a>
         <a href="?filter=Reassigned" class="filter-btn <?=$filter==='Reassigned'?'active':''?>">Reassigned</a>
      <button class="btn btn-maroon btn-sm ms-2 desktop-add-btn" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-lg me-1"></i>Add Schedule
      </button>
    </div>
  </div>

<!-- ══ MOBILE STATS ══ -->
<?php
$cnt_pending   = count(array_filter($schedules, fn($s) => $s['status']==='Pending'));
$cnt_approved  = count(array_filter($schedules, fn($s) => $s['status']==='Approved'));
$cnt_ontrip    = count(array_filter($schedules, fn($s) => $s['status']==='OnTrip'));
$cnt_completed = count(array_filter($schedules, fn($s) => $s['status']==='Completed'));
$cnt_rejected  = count(array_filter($schedules, fn($s) => $s['status']==='Rejected'));
$cnt_cancelled = count(array_filter($schedules, fn($s) => $s['status']==='Cancelled'));
?>
<div class="mob-stat-strip px-3 pt-3">
    <div class="mob-stat-card pending">
        <div class="mob-stat-lbl">Pending</div>
        <div class="mob-stat-val"><?= $cnt_pending ?></div>
    </div>
    <div class="mob-stat-card approved">
        <div class="mob-stat-lbl">Approved</div>
        <div class="mob-stat-val"><?= $cnt_approved ?></div>
    </div>
    <div class="mob-stat-card ontrip">
        <div class="mob-stat-lbl">On Trip</div>
        <div class="mob-stat-val"><?= $cnt_ontrip ?></div>
    </div>
</div>

<!-- ══ MOBILE FILTER PILLS ══ -->
<div class="mob-filter-row px-3 pt-2">
    <a href="?filter=All"       class="mob-filter-pill <?= $filter==='All'       ?'active':''?>">All (<?= count($schedules) ?>)</a>
    <a href="?filter=Pending"   class="mob-filter-pill <?= $filter==='Pending'   ?'active':''?>">Pending (<?= $cnt_pending ?>)</a>
    <a href="?filter=Approved"  class="mob-filter-pill <?= $filter==='Approved'  ?'active':''?>">Approved (<?= $cnt_approved ?>)</a>
    <a href="?filter=OnTrip"    class="mob-filter-pill <?= $filter==='OnTrip'    ?'active':''?>">On Trip (<?= $cnt_ontrip ?>)</a>
    <a href="?filter=Completed" class="mob-filter-pill <?= $filter==='Completed' ?'active':''?>">Completed (<?= $cnt_completed ?>)</a>
    <a href="?filter=Rejected"  class="mob-filter-pill <?= $filter==='Rejected'  ?'active':''?>">Rejected (<?= $cnt_rejected ?>)</a>
    <?php $cnt_reassigned = count(array_filter($schedules, fn($s) => $s['status']==='Reassigned')); ?>
    <a href="?filter=Reassigned" class="mob-filter-pill <?= $filter==='Reassigned' ?'active':''?>">Reassigned (<?= $cnt_reassigned ?>)</a>
</div>

<!-- ══ MOBILE SEARCH ══ -->
<div class="mob-search-wrap mx-3">
    <i class="bi bi-search mob-search-icon"></i>
    <input type="text" class="mob-search-input" id="mobSchedSearch"
        placeholder="Search requestor, destination…"
        oninput="onMobSchedSearch(this)" autocomplete="off">
    <button class="mob-search-clear" id="mobSchedSearchClear"
        onclick="clearMobSchedSearch()">
        <i class="bi bi-x-lg" style="font-size:.75rem"></i>
    </button>
</div>

<!-- ══ MOBILE SCHEDULE CARDS ══ -->
<div class="mob-sched-list px-3 pb-3" id="mobSchedList">
<?php foreach($filtered as $s):
    $status   = $s['status'] ?? '';
    $isCmp    = ($status==='Completed');
    $isOnTrip = ($status==='OnTrip');
    $isAppr   = ($status==='Approved');
    $isPend   = ($status==='Pending');
    $isCan    = ($status==='Cancelled');
    $isRej    = ($status==='Rejected');
    $tripStart= strtotime(($s['date_start']??'').' '.($s['time_start']??'00:00:00'));
    $isUpcoming = ($isAppr && $tripStart > time());
    $sid_int  = (int)$s['schedule_id'];
    $paxCount = (int)($s['passengers'] ?? 1);
    $isWalkIn = !empty($s['booked_by_staff']);
    $bookedByName = !empty($s['booked_by_name']) ? $s['booked_by_name'] : $s['username'];
    $tickNo   = in_array($status,['Approved','OnTrip','Completed']) ? getTicketNo($pdo,$sid_int,$s['date_start']) : null;
    $vLbl     = !empty($s['vehicle_id']) ? htmlspecialchars($s['brand'].' '.$s['model'].' ('.$s['plate_number'].')',ENT_QUOTES) : '—';
    $dLbl     = !empty($s['driver_id'])  ? htmlspecialchars($s['driver_name'],ENT_QUOTES) : '—';

    $badgeCls = match($status) {
        'Completed' => 'bp-completed',
        'OnTrip'    => 'bp-ontrip',
        'Approved'  => $isUpcoming ? 'bp-upcoming' : 'bp-approved',
        'Pending'   => 'bp-pending',
        'Cancelled' => 'bp-cancelled',
        default     => 'bp-rejected'
    };
    $badgeTxt = match($status) {
        'OnTrip'  => 'On Trip',
        'Approved'=> $isUpcoming ? 'Upcoming' : 'Approved',
        default   => $status
    };
?>
<div class="sched-card mob-sched-row"
    data-search="<?= htmlspecialchars(strtolower(
        ($s['username']??'').' '.($s['destination']??'').' '.($s['purpose']??'').' '.
        ($s['driver_name']??'').' '.($s['brand']??'').' '.($s['model']??'').' '.
        ($s['plate_number']??'').' '.($s['department_name']??'')), ENT_QUOTES) ?>">
    <div class="sc-top">
        <div style="flex:1;min-width:0">
            <div class="sc-id">#<?= $sid_int ?> <?= $tickNo ? '· '.$tickNo : '' ?></div>
            <div class="sc-name"><?= htmlspecialchars($s['username']) ?></div>
            <div class="sc-dest"><i class="bi bi-geo-alt-fill" style="color:#800000;font-size:.7rem"></i> <?= htmlspecialchars($s['destination']) ?></div>
        </div>
        <span class="bp <?= $badgeCls ?>" style="flex-shrink:0;margin-left:8px"><?= $badgeTxt ?></span>
    </div>
  <div class="sc-meta">
        <div class="sc-meta-item" style="grid-column:1/-1">
            <div class="sc-meta-lbl"><i class="bi bi-calendar3" style="color:#800000"></i> Date</div>
            <div class="sc-meta-val"><?php
$fDs2=$s['date_start']?date('M j, Y',strtotime($s['date_start'])):'—';
$fDe2=$s['date_end']  ?date('M j, Y',strtotime($s['date_end']))  :'—';
echo $s['date_start']===$s['date_end']?htmlspecialchars($fDs2):htmlspecialchars($fDs2).' → '.htmlspecialchars($fDe2);
?></div>
        </div>
        <div class="sc-meta-item">
            <div class="sc-meta-lbl"><i class="bi bi-clock" style="color:#800000"></i> Time</div>
            <div class="sc-meta-val"><?= htmlspecialchars($fmt($s['time_start']).' – '.$fmt($s['time_end'])) ?></div>
        </div>
        <div class="sc-meta-item">
            <div class="sc-meta-lbl"><i class="bi bi-people-fill" style="color:#800000"></i> Passengers</div>
            <div class="sc-meta-val"><?= $paxCount ?></div>
        </div>
        <div class="sc-meta-item">
            <div class="sc-meta-lbl"><i class="bi bi-truck-front" style="color:#800000"></i> Vehicle</div>
            <div class="sc-meta-val"><?= !empty($s['vehicle_id']) ? htmlspecialchars($s['brand'].' '.$s['model']) : '<span style="color:#ccc;font-style:italic">Not assigned</span>' ?></div>
        </div>
        <div class="sc-meta-item">
            <div class="sc-meta-lbl"><i class="bi bi-person-badge" style="color:#800000"></i> Driver</div>
            <div class="sc-meta-val"><?= !empty($s['driver_id']) ? htmlspecialchars($s['driver_name']) : '<span style="color:#ccc;font-style:italic">Not assigned</span>' ?></div>
        </div>
    </div>
    <div class="sc-actions">
    <?php if($isCmp): ?>
    <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" 
       class="btn btn-sm btn-outline-secondary" title="Print">
        <i class="bi bi-printer"></i>
    </a>
    <button type="button" 
        class="btn btn-sm btn-outline-success btn-attach-signed"
        title="<?= !empty($s['signed_ticket_path']) ? 'Replace Signed Ticket' : 'Attach Signed Ticket' ?>"
        data-id="<?= $sid_int ?>">
        <i class="bi bi-paperclip"></i>
    </button>
    <?php if(!empty($s['signed_ticket_path'])): ?>
   <button type="button" 
    class="btn btn-sm btn-outline-primary btn-view-signed"
    title="View Signed Ticket"
    data-path="../<?= htmlspecialchars($s['signed_ticket_path'], ENT_QUOTES) ?>"
    data-type="<?= strtolower(pathinfo($s['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
    <i class="bi bi-file-earmark-check-fill"></i>
</button>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-outline-info btn-viewdetails" title="View Details"
        data-ticket="<?= $tickNo ? htmlspecialchars($tickNo, ENT_QUOTES) : '' ?>"
        data-id="<?= (int)$sid_int ?>"
        data-username="<?= htmlspecialchars($s['username'] ?? '', ENT_QUOTES) ?>"
        data-office="<?= htmlspecialchars($s['office_name'] ?? '', ENT_QUOTES) ?>"
        data-dept="<?= htmlspecialchars($s['department_name'] ?? '—', ENT_QUOTES) ?>"
        data-dest="<?= htmlspecialchars($s['destination'] ?? '', ENT_QUOTES) ?>"
        data-purp="<?= htmlspecialchars($s['purpose'] ?? '', ENT_QUOTES) ?>"
        data-ds="<?= htmlspecialchars($s['date_start'] ?? '', ENT_QUOTES) ?>"
        data-de="<?= htmlspecialchars($s['date_end'] ?? '', ENT_QUOTES) ?>"
        data-ts="<?= htmlspecialchars($s['time_start'] ?? '', ENT_QUOTES) ?>"
        data-te="<?= htmlspecialchars($s['time_end'] ?? '', ENT_QUOTES) ?>"
        data-vehicle="<?= htmlspecialchars($vLbl ?? '', ENT_QUOTES) ?>"
        data-driver="<?= htmlspecialchars($dLbl ?? '', ENT_QUOTES) ?>"
      data-arrived="<?= htmlspecialchars($s['arrived_at'] ?? '', ENT_QUOTES) ?>"
data-reason="<?= htmlspecialchars($s['rejection_reason'] ?? '', ENT_QUOTES) ?>"
data-cancel-reason="<?= htmlspecialchars($s['cancel_reason'] ?? '', ENT_QUOTES) ?>"
data-cancelled-by="<?= htmlspecialchars($s['cancelled_by'] ?? '', ENT_QUOTES) ?>"
data-booked-at="<?= htmlspecialchars($s['created_at'] ?? '', ENT_QUOTES) ?>"
        data-booked-by="<?= htmlspecialchars($bookedByName ?? '', ENT_QUOTES) ?>"
        data-is-walkin="<?= $isWalkIn ? '1' : '0' ?>"
        data-pax="<?= (int)($paxCount ?? 0) ?>">
        <i class="bi bi-info-circle"></i>
    </button>

    <?php elseif($isOnTrip): ?>
        <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="sc-btn sc-btn-print"><i class="bi bi-printer"></i></a>
        <button class="sc-btn sc-btn-done" onclick="mobTripDone(<?=$sid_int?>, '<?=htmlspecialchars($s['username'],ENT_QUOTES)?>', '<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>')">
            <i class="bi bi-flag-fill"></i> Trip Done
        </button>

    <?php elseif($isUpcoming): ?>
    <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="sc-btn sc-btn-print"><i class="bi bi-printer"></i></a>
    
    <!-- ADD THESE -->
    <button type="button" 
        class="sc-btn btn btn-sm btn-outline-success btn-attach-signed"
        title="Attach Signed Ticket"
        data-id="<?= $sid_int ?>">
        <i class="bi bi-paperclip"></i>
    </button>
    <?php if(!empty($s['signed_ticket_path'])): ?>
    <button type="button" 
        class="sc-btn btn btn-sm btn-outline-primary btn-view-signed"
        title="View Signed Ticket"
        data-path="../<?= htmlspecialchars($s['signed_ticket_path'], ENT_QUOTES) ?>"
        data-type="<?= strtolower(pathinfo($s['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
        <i class="bi bi-file-earmark-check-fill"></i>
    </button>
    <?php endif; ?>
        <button class="sc-btn sc-btn-change" onclick="mobChangeAssign(<?= htmlspecialchars(json_encode([
            'id'=>$sid_int,'username'=>$s['username'],'ds'=>$s['date_start'],'de'=>$s['date_end'],
            'ts'=>$s['time_start']??'','te'=>$s['time_end']??'',
            'vid'=>(int)($s['vehicle_id']??0),'did'=>(int)($s['driver_id']??0)
        ]), ENT_QUOTES) ?>)"><i class="bi bi-arrow-repeat"></i></button>
        <button class="sc-btn sc-btn-resched" onclick="mobReschedule(<?= htmlspecialchars(json_encode([
            'id'=>$sid_int,'ds'=>$s['date_start'],'de'=>$s['date_end'],
            'ts'=>$s['time_start']??'','te'=>$s['time_end']??'',
            'dest'=>$s['destination'],'purp'=>$s['purpose']
        ]), ENT_QUOTES) ?>)"><i class="bi bi-calendar2-event"></i></button>
        <button class="sc-btn sc-btn-cancel" onclick="mobCancel(<?=$sid_int?>, '<?=htmlspecialchars($s['username'],ENT_QUOTES)?>', 'Approved')"><i class="bi bi-slash-circle"></i></button>

    <?php elseif($isAppr): ?>
        <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="sc-btn sc-btn-print"><i class="bi bi-printer"></i></a>
        <button class="sc-btn sc-btn-done" onclick="mobTripDone(<?=$sid_int?>, '<?=htmlspecialchars($s['username'],ENT_QUOTES)?>', '<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>')">
            <i class="bi bi-flag-fill"></i> Trip Done
        </button>

    <?php elseif($isPend): ?>
        <!-- ✅ FIX: All buttons properly opened and closed -->
        <button class="sc-btn sc-btn-approve" onclick="mobApprove(<?= htmlspecialchars(json_encode([
            'id'      => $sid_int,
            'username'=> $s['username'],
            'ds'      => $s['date_start'],
            'de'      => $s['date_end'],
            'ts'      => $s['time_start'] ?? '',
            'te'      => $s['time_end']   ?? '',
            'pax'     => $paxCount,
            'uid'     => (int)$s['user_id']
        ]), ENT_QUOTES) ?>)">
            <i class="bi bi-check-lg"></i> Approve
        </button>
        <button class="sc-btn sc-btn-reject" onclick="mobReject(<?=$sid_int?>, '<?=htmlspecialchars($s['username'],ENT_QUOTES)?>')">
            <i class="bi bi-x-lg"></i> Reject
        </button>
        <button class="sc-btn sc-btn-resched" onclick="mobReschedule(<?= htmlspecialchars(json_encode([
            'id'=>$sid_int,'ds'=>$s['date_start'],'de'=>$s['date_end'],
            'ts'=>$s['time_start']??'','te'=>$s['time_end']??'',
            'dest'=>$s['destination'],'purp'=>$s['purpose']
        ]), ENT_QUOTES) ?>)">
            <i class="bi bi-calendar2-event"></i>
        </button>
        <button class="sc-btn sc-btn-cancel" onclick="mobCancel(<?=$sid_int?>, '<?=htmlspecialchars($s['username'],ENT_QUOTES)?>', 'Pending')">
            <i class="bi bi-slash-circle"></i>
        </button>

    <?php else: ?>
        <button class="sc-btn sc-btn-view" onclick="mobViewDetails(<?= htmlspecialchars(json_encode([
            'id'=>$sid_int,'username'=>$s['username'],'office'=>$s['office_name'],'dept'=>$s['department_name']??'—',
            'dest'=>$s['destination'],'purp'=>$s['purpose'],'ds'=>$s['date_start'],'de'=>$s['date_end'],
            'ts'=>$s['time_start']??'','te'=>$s['time_end']??'','vehicle'=>$vLbl,'driver'=>$dLbl,
            'arrived'=>$s['arrived_at']??'','reason'=>$s['rejection_reason']??'',
            'cancelReason'=>$s['cancel_reason']??'','cancelledBy'=>$s['cancelled_by']??'',
            'status'=>$status,'bookedAt'=>$s['created_at']??'','bookedBy'=>$bookedByName,
            'isWalkin'=>$isWalkIn?'1':'0','pax'=>$paxCount,'ticket'=>$tickNo??''
        ]), ENT_QUOTES) ?>)"><i class="bi bi-info-circle"></i> Details</button>
        <button class="sc-btn sc-btn-resched" onclick="mobReschedule(<?= htmlspecialchars(json_encode([
            'id'=>$sid_int,'ds'=>$s['date_start'],'de'=>$s['date_end'],
            'ts'=>$s['time_start']??'','te'=>$s['time_end']??'',
            'dest'=>$s['destination'],'purp'=>$s['purpose']
        ]), ENT_QUOTES) ?>)"><i class="bi bi-calendar2-event"></i></button>
    <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php if(empty($filtered)): ?>
<div class="mob-empty"><i class="bi bi-calendar-x"></i><p>No schedules found.</p></div>
<?php endif; ?>
</div>

<!-- ══ DESKTOP TABLE ══ -->
<div class="desktop-sched-table">
  <div class="global-search-wrap">
    <div class="global-search-outer">
      <i class="bi bi-search global-search-icon"></i>
      <input type="text" class="global-search-input" id="globalSearch"
        placeholder="Search by requestor, destination, purpose, vehicle, driver, department…"
        oninput="onGlobalSearch(this)" autocomplete="off">
      <button class="global-search-clear" id="globalSearchClear" onclick="clearGlobalSearch()" title="Clear search">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <span class="global-search-count" id="globalSearchCount"></span>
  </div>

  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr>
        <th>#</th><th>Requestor</th>
        <th>Department</th><th>Date</th><th>Time</th><th>Destination</th>
        <th>Purpose</th><th>Passengers</th><th>Vehicle</th><th>Driver</th><th>Status</th>
        <th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach($filtered as $s):
        $status      = $s['status'] ?? '';
        $isCmp       = ($status==='Completed');
        $isOnTrip    = ($status==='OnTrip');
        $isAppr      = ($status==='Approved');
        $isPend      = ($status==='Pending');
        $isCancelled = ($status==='Cancelled');
        $tripStart   = strtotime(($s['date_start']??'').' '.($s['time_start']??'00:00:00'));
        $isUpcoming  = ($isAppr && $tripStart > time());
        $tickNo      = in_array($status,['Approved','OnTrip','Completed']) ? getTicketNo($pdo,$s['schedule_id'],$s['date_start']) : null;
        $vLbl        = !empty($s['vehicle_id']) ? htmlspecialchars($s['brand'].' '.$s['model'].' ('.$s['plate_number'].')',ENT_QUOTES) : '—';
        $dLbl        = !empty($s['driver_id'])  ? htmlspecialchars($s['driver_name'],ENT_QUOTES) : '—';
        $arrv        = htmlspecialchars($s['arrived_at']??'',ENT_QUOTES);
        $reason      = htmlspecialchars($s['rejection_reason']??'',ENT_QUOTES);
        $cancelReason= htmlspecialchars($s['cancel_reason']??'',ENT_QUOTES);
        $cancelledBy = htmlspecialchars($s['cancelled_by']??'',ENT_QUOTES);
        $sid_int     = (int)$s['schedule_id'];
        $bookedAt    = !empty($s['created_at']) ? $s['created_at'] : '';
        $bookedByName= !empty($s['booked_by_name']) ? $s['booked_by_name'] : $s['username'];
        $isWalkIn    = !empty($s['booked_by_staff']);
        $paxCount    = (int)($s['passengers'] ?? 1);
        
      ?>
      <tr class="sched-row"
        data-schedule-id="<?= $sid_int ?>"
        data-driver="<?=htmlspecialchars(strtolower($s['driver_name']??''),ENT_QUOTES)?>"
        data-vehicle="<?=htmlspecialchars(strtolower(($s['brand']??'').' '.($s['model']??'').' '.($s['plate_number']??'')),ENT_QUOTES)?>"
        data-dept="<?=htmlspecialchars(strtolower($s['department_name']??''),ENT_QUOTES)?>"
        data-dest="<?=htmlspecialchars(strtolower($s['destination']??''),ENT_QUOTES)?>">
        <td><span style="font-size:.78rem;font-weight:700;color:#555">#<?= $sid_int ?></span></td>
        <td><?=htmlspecialchars($s['username'])?></td>
        <td><?=htmlspecialchars($s['department_name']??'—')?></td>
        <td><?php
$ds=$s['date_start']??'';$de=$s['date_end']??'';
$fDs=$ds?date('M j, Y',strtotime($ds)):'—';
$fDe=$de?date('M j, Y',strtotime($de)):'—';
echo $ds===$de?htmlspecialchars($fDs):htmlspecialchars($fDs).' → '.htmlspecialchars($fDe);
?></td>
        <td><?=htmlspecialchars($fmt($s['time_start']).' – '.$fmt($s['time_end']))?></td>
        <td><?=htmlspecialchars($s['destination'])?></td>
        <td><?=htmlspecialchars($s['purpose'])?></td>
        <td><span class="pax-badge"><i class="bi bi-people-fill"></i><?=$paxCount?></span></td>
        <td><?=!empty($s['vehicle_id'])?htmlspecialchars($s['brand'].' '.$s['model'].' ('.$s['plate_number'].')'):'<span class="not-assigned">Not assigned</span>'?></td>
        <td><?=!empty($s['driver_id'])?htmlspecialchars($s['driver_name']):'<span class="not-assigned">Not assigned</span>'?></td>
        <td>
          <?php if($isCmp):?><span class="bp bp-completed"><i class="bi bi-check2-all me-1"></i>Completed</span>
          <?php elseif($isOnTrip):?><span class="bp bp-ontrip"><i class="bi bi-truck me-1"></i>On Trip</span>
          <?php elseif($isUpcoming):?><span class="bp bp-upcoming"><i class="bi bi-calendar-event me-1"></i>Upcoming</span>
          <?php elseif($isAppr):?><span class="bp bp-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>
          <?php elseif($isPend):?><span class="bp bp-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
          <?php elseif($isCancelled):?><span class="bp bp-cancelled"><i class="bi bi-slash-circle me-1"></i>Cancelled</span>
          <?php else:?><span class="bp bp-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span><?php endif;?>
        </td>
        <td><div class="action-group">

          <?php if($isCmp): ?>
    <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" 
       class="btn btn-sm btn-outline-secondary" title="Print">
        <i class="bi bi-printer"></i>
    </a>
    <button type="button" 
        class="btn btn-sm btn-outline-success btn-attach-signed"
        title="<?= !empty($s['signed_ticket_path']) ? 'Replace Signed Ticket' : 'Attach Signed Ticket' ?>"
        data-id="<?= $sid_int ?>">
        <i class="bi bi-paperclip"></i>
    </button>
    <?php if(!empty($s['signed_ticket_path'])): ?>
    <button type="button" 
   class="btn btn-sm btn-outline-primary btn-view-signed" 
   title="View Signed Ticket"
   data-path="../<?= htmlspecialchars($s['signed_ticket_path'], ENT_QUOTES) ?>"
   data-type="<?= strtolower(pathinfo($s['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
    <i class="bi bi-file-earmark-check-fill"></i>
</button>
    <?php endif; ?>
    <button type="button" 
        class="btn btn-sm btn-outline-info btn-viewdetails" 
        title="View Details"
        data-ticket="<?= $tickNo ? htmlspecialchars($tickNo, ENT_QUOTES) : '' ?>"
        data-id="<?= $sid_int ?>"
        data-username="<?= htmlspecialchars($s['username'], ENT_QUOTES) ?>"
        data-office="<?= htmlspecialchars($s['office_name'], ENT_QUOTES) ?>"
        data-dept="<?= htmlspecialchars($s['department_name'] ?? '—', ENT_QUOTES) ?>"
        data-dest="<?= htmlspecialchars($s['destination'], ENT_QUOTES) ?>"
        data-purp="<?= htmlspecialchars($s['purpose'], ENT_QUOTES) ?>"
        data-ds="<?= htmlspecialchars($s['date_start'], ENT_QUOTES) ?>"
        data-de="<?= htmlspecialchars($s['date_end'], ENT_QUOTES) ?>"
        data-ts="<?= htmlspecialchars($s['time_start'] ?? '', ENT_QUOTES) ?>"
        data-te="<?= htmlspecialchars($s['time_end'] ?? '', ENT_QUOTES) ?>"
        data-vehicle="<?= $vLbl ?>"
        data-driver="<?= $dLbl ?>"
        data-arrived="<?= $arrv ?>"
        data-reason="<?= $reason ?>"
        data-cancel-reason="<?= $cancelReason ?>"
        data-cancelled-by="<?= $cancelledBy ?>"
        data-status="Completed"
        data-booked-at="<?= htmlspecialchars($bookedAt, ENT_QUOTES) ?>"
        data-booked-by="<?= htmlspecialchars($bookedByName, ENT_QUOTES) ?>"
        data-is-walkin="<?= $isWalkIn ? '1' : '0' ?>"
        data-pax="<?= $paxCount ?>">
        <i class="bi bi-info-circle"></i>
    </button>

<?php elseif($isOnTrip): ?>
    <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" 
       class="btn btn-sm btn-outline-secondary" title="Print">
        <i class="bi bi-printer"></i>
    </a>
    <button type="button" 
        class="btn btn-sm btn-outline-success btn-attach-signed"
        title="<?= !empty($s['signed_ticket_path']) ? 'Replace Signed Ticket' : 'Attach Signed Ticket' ?>"
        data-id="<?= $sid_int ?>">
        <i class="bi bi-paperclip"></i>
    </button>
    <?php if(!empty($s['signed_ticket_path'])): ?>
    <button type="button" 
    class="btn btn-sm btn-outline-primary btn-view-signed"
    title="View Signed Ticket"
    data-path="../<?= htmlspecialchars($s['signed_ticket_path'], ENT_QUOTES) ?>"
    data-type="<?= strtolower(pathinfo($s['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
    <i class="bi bi-file-earmark-check-fill"></i>
</button>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-success btn-tripdone" 
        title="Mark as Trip Complete"
        data-schedule-id="<?= $sid_int ?>"
        data-username="<?= htmlspecialchars($s['username'], ENT_QUOTES) ?>"
        data-dest="<?= htmlspecialchars($s['destination'], ENT_QUOTES) ?>">
        <i class="bi bi-flag-fill me-1"></i>Trip Done
    </button>

<?php elseif($isUpcoming): ?>
    <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="bi bi-printer"></i></a>
    
    <!-- ADD THESE TWO BUTTONS -->
    <button type="button" 
        class="btn btn-sm btn-outline-success btn-attach-signed"
        title="<?= !empty($s['signed_ticket_path']) ? 'Replace Signed Ticket' : 'Attach Signed Ticket' ?>"
        data-id="<?= $sid_int ?>">
        <i class="bi bi-paperclip"></i>
    </button>
    <?php if(!empty($s['signed_ticket_path'])): ?>
    <button type="button" 
        class="btn btn-sm btn-outline-primary btn-view-signed" 
        title="View Signed Ticket"
        data-path="../<?= htmlspecialchars($s['signed_ticket_path'], ENT_QUOTES) ?>"
        data-type="<?= strtolower(pathinfo($s['signed_ticket_path'], PATHINFO_EXTENSION)) ?>">
        <i class="bi bi-file-earmark-check-fill"></i>
    </button>
    <?php endif; ?>
    <!-- END ADDED BUTTONS -->
    
    <button type="button" class="btn btn-sm btn-outline-primary btn-change" title="Change Driver/Vehicle"
      data-id="<?=$sid_int?>" data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>"
      data-vid="<?=(int)($s['vehicle_id']??0)?>" data-did="<?=(int)($s['driver_id']??0)?>"
      data-ds="<?=$s['date_start']?>" data-de="<?=$s['date_end']?>"
      data-ts="<?=$s['time_start']??''?>" data-te="<?=$s['time_end']??''?>">
      <i class="bi bi-arrow-repeat"></i>
    </button>
            <button type="button" class="btn btn-sm btn-outline-warning btn-reschedule" title="Reschedule"
              data-id="<?=$sid_int?>" data-ds="<?=$s['date_start']?>" data-de="<?=$s['date_end']?>"
              data-ts="<?=$s['time_start']??''?>" data-te="<?=$s['time_end']??''?>"
              data-dest="<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>" data-purp="<?=htmlspecialchars($s['purpose'],ENT_QUOTES)?>">
              <i class="bi bi-calendar2-event"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-cancel" title="Cancel"
              data-id="<?=$sid_int?>" data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>" data-status="Approved">
              <i class="bi bi-slash-circle"></i>
            </button>

          <?php elseif($isAppr): ?>
            <a href="print_trip_ticket.php?id=<?=$sid_int?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="bi bi-printer"></i></a>
            <button type="button" class="btn btn-sm btn-success btn-tripdone" title="Mark as Trip Complete"
              data-schedule-id="<?=$sid_int?>"
              data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>"
              data-dest="<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>">
              <i class="bi bi-flag-fill me-1"></i>Trip Done
            </button>

          <?php elseif($isPend): ?>
            <button type="button" class="btn btn-sm btn-success btn-approve" title="Approve"
              data-id="<?=$sid_int?>" data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>"
              data-uid="<?=(int)$s['user_id']?>"
              data-ds="<?=$s['date_start']?>" data-de="<?=$s['date_end']?>"
              data-ts="<?=$s['time_start']??''?>" data-te="<?=$s['time_end']??''?>" data-pax="<?=$paxCount?>">
              <i class="bi bi-check-lg"></i>
            </button>
            <button type="button" class="btn btn-sm btn-warning btn-reject" title="Reject"
              data-id="<?=$sid_int?>" data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>">
              <i class="bi bi-x-lg"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary btn-reschedule" title="Reschedule"
              data-id="<?=$sid_int?>" data-ds="<?=$s['date_start']?>" data-de="<?=$s['date_end']?>"
              data-ts="<?=$s['time_start']??''?>" data-te="<?=$s['time_end']??''?>"
              data-dest="<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>" data-purp="<?=htmlspecialchars($s['purpose'],ENT_QUOTES)?>">
              <i class="bi bi-calendar2-event"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-cancel" title="Cancel"
              data-id="<?=$sid_int?>" data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>" data-status="Pending">
              <i class="bi bi-slash-circle"></i>
            </button>

          <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-info btn-viewdetails" title="View Details"
              data-ticket="<?= $tickNo ? htmlspecialchars($tickNo,ENT_QUOTES) : '' ?>"
              data-id="<?=$sid_int?>" data-username="<?=htmlspecialchars($s['username'],ENT_QUOTES)?>"
              data-office="<?=htmlspecialchars($s['office_name'],ENT_QUOTES)?>" data-dept="<?=htmlspecialchars($s['department_name']??'—',ENT_QUOTES)?>"
              data-dest="<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>" data-purp="<?=htmlspecialchars($s['purpose'],ENT_QUOTES)?>"
              data-ds="<?=htmlspecialchars($s['date_start'],ENT_QUOTES)?>" data-de="<?=htmlspecialchars($s['date_end'],ENT_QUOTES)?>"
              data-ts="<?=htmlspecialchars($s['time_start']??'',ENT_QUOTES)?>" data-te="<?=htmlspecialchars($s['time_end']??'',ENT_QUOTES)?>"
              data-vehicle="<?=$vLbl?>" data-driver="<?=$dLbl?>" data-arrived="<?=$arrv?>"
              data-reason="<?=$reason?>" data-cancel-reason="<?=$cancelReason?>"
              data-cancelled-by="<?=$cancelledBy?>"
              data-status="<?=htmlspecialchars($status,ENT_QUOTES)?>"
              data-booked-at="<?=htmlspecialchars($bookedAt,ENT_QUOTES)?>"
              data-booked-by="<?=htmlspecialchars($bookedByName,ENT_QUOTES)?>"
              data-is-walkin="<?=$isWalkIn?'1':'0'?>"
              data-pax="<?=$paxCount?>"
              data-ticket="<?= $tickNo ? htmlspecialchars($tickNo,ENT_QUOTES) : '' ?>">
              <i class="bi bi-info-circle"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary btn-reschedule"
              data-id="<?=$sid_int?>" data-ds="<?=$s['date_start']?>" data-de="<?=$s['date_end']?>"
              data-ts="<?=$s['time_start']??''?>" data-te="<?=$s['time_end']??''?>"
              data-dest="<?=htmlspecialchars($s['destination'],ENT_QUOTES)?>" data-purp="<?=htmlspecialchars($s['purpose'],ENT_QUOTES)?>">
              <i class="bi bi-calendar2-event"></i>
            </button>
          <?php endif; ?>

        </div></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($filtered)):?>
      <tr><td colspan="12" class="text-center text-muted py-4"><i class="bi bi-calendar-x fs-4 d-block mb-2 opacity-50"></i>No schedules found.</td></tr>
      <?php endif;?>
      </tbody>
    </table>
  </div>
</div><!-- end desktop-sched-table -->
</div>

<!-- ═══════ MODALS ═══════ -->
<!-- ADD -->
<div class="modal fade" id="addModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header mh-maroon"><h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Add Schedule Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" action="Schedules.php" id="scheduleForm" novalidate>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="user_id" id="add_user_id" value="<?=$me['user_id']?>">
    <input type="hidden" name="office_id" value="<?=$me['u_office_id']??0?>">
    <input type="hidden" name="department_id" value="<?=!empty($me['department_id'])?(int)$me['department_id']:''?>">
    <div class="modal-body">
     <div class="d-flex gap-2 flex-wrap mb-3" id="add_info_badges">
    <span class="info-badge" id="add_badge_name">
        <i class="bi bi-person-circle"></i>
        <span id="add_badge_name_text"><?= htmlspecialchars($me['username'] ?? '—') ?></span>
    </span>
    <span class="info-badge" id="add_badge_office">
        <i class="bi bi-building"></i>
        <span id="add_badge_office_text"><?= htmlspecialchars($me['office_name'] ?? '—') ?></span>
    </span>
    <span class="info-badge" id="add_badge_dept">
        <i class="bi bi-diagram-3"></i>
        <span id="add_badge_dept_text"><?= htmlspecialchars($me['department_name'] ?? '—') ?></span>
    </span>
</div>
      <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.83rem"><i class="bi bi-info-circle me-1"></i>Vehicle and driver will be assigned upon approval.</div>
      <div class="row g-3">
        <div class="col-12">
  <label class="form-label fw-semibold">
    <i class="bi bi-person-circle me-1 text-danger"></i>Booking For <span class="text-danger">*</span>
  </label>
  <select name="requested_user_id" id="add_requested_user" class="form-select" required
    onchange="updateBookingInfo(this)">
    <option value="<?= $me['user_id'] ?>"
        data-name="<?= htmlspecialchars($me['username'], ENT_QUOTES) ?>"
        data-office="<?= htmlspecialchars($me['office_name'] ?? '—', ENT_QUOTES) ?>"
        data-dept="<?= htmlspecialchars($me['department_name'] ?? '—', ENT_QUOTES) ?>"
        style="font-weight:700">
        👤 <?= htmlspecialchars($me['username']) ?> (Me)
    </option>
    <?php foreach($officeUsers as $u):
        if($u['user_id'] == $me['user_id']) continue;
        // Fetch each user's office and department
        $uInfoStmt = $pdo->prepare("SELECT o.office_name, d.dept_name FROM users u LEFT JOIN offices o ON u.office_id=o.office_id LEFT JOIN departments d ON u.dept_id=d.dept_id WHERE u.user_id=?");
        $uInfoStmt->execute([$u['user_id']]);
        $uInfo = $uInfoStmt->fetch();
    ?>
    <option value="<?= $u['user_id'] ?>"
        data-name="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
        data-office="<?= htmlspecialchars($uInfo['office_name'] ?? '—', ENT_QUOTES) ?>"
        data-dept="<?= htmlspecialchars($uInfo['dept_name'] ?? '—', ENT_QUOTES) ?>">
        <?= htmlspecialchars($u['username']) ?>
    </option>
    <?php endforeach; ?>
</select>
  <div class="form-text text-muted">Select the person this schedule is for.</div>
</div>
        <div class="col-md-6"><label class="form-label fw-semibold">Date Start <span class="text-danger">*</span></label><input type="date" name="date_start" id="add_ds" class="form-control" required min="<?=date('Y-m-d')?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Date End <span class="text-danger">*</span></label><input type="date" name="date_end" id="add_de" class="form-control" required min="<?=date('Y-m-d')?>"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Time Start <span class="text-danger">*</span></label><input type="time" name="time_start" id="add_ts" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Time End <span class="text-danger">*</span></label><input type="time" name="time_end" id="add_te" class="form-control" required><div class="invalid-feedback" id="add_te_err">Please enter an end time after the start time.</div></div>
        <div class="col-12"><div id="add_time_alert" class="alert alert-danger py-2 px-3 d-none" style="font-size:.83rem"><i class="bi bi-exclamation-triangle me-1"></i><span id="add_time_alert_msg">End time must be after start time when on the same day.</span></div></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Destination <span class="text-danger">*</span></label><input type="text" name="destination" class="form-control" required placeholder="e.g. City Hall, Baguio City"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Purpose</label><input type="text" name="purpose" class="form-control" placeholder="e.g. Official Business"></div>
        <div class="col-md-6">
          <label class="form-label fw-semibold"><i class="bi bi-people-fill me-1 text-primary"></i>No. of Passengers <span class="text-danger">*</span></label>
          <input type="number" name="passengers" class="form-control" required min="1" max="50" value="1">
          <div class="invalid-feedback">Please enter the number of passengers (minimum 1).</div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="button" id="add_submit_btn" class="btn btn-maroon"><i class="bi bi-send me-1"></i>Submit Request</button>
    </div>
  </form>
</div></div></div>

<!-- APPROVE -->
<div class="modal fade" id="approveModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header mh-maroon"><h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Approve & Assign</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" action="Schedules.php" id="approveForm">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="schedule_id" id="apr_sid">
    <input type="hidden" name="vehicle_id" id="apr_vehicle_hidden">
    <input type="hidden" name="driver_id"  id="apr_driver_hidden">
    <div class="modal-body">
      <div class="sbox mb-3">
        <div class="sbox-title"><i class="bi bi-info-circle me-1"></i>Request Details</div>
        <div class="row g-2">
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Requestor</div><div class="fw-semibold" id="apr_uname">—</div></div>
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Date Range</div><div class="fw-semibold" id="apr_dates">—</div></div>
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Time</div><div class="fw-semibold" id="apr_time">—</div></div>
          <div class="col-md-3"><div style="font-size:.75rem;color:#888">Passengers</div><div class="fw-semibold" id="apr_pax">—</div></div>
        </div>
      </div>
      <div class="sbox">
        <div class="sbox-title"><i class="bi bi-truck-front me-1"></i>Assign Driver & Vehicle</div>
        <p class="text-muted mb-3" style="font-size:.82rem"><i class="bi bi-stars me-1 text-warning"></i>Selecting a driver <strong>auto-fills their assigned vehicle</strong>. Options marked <strong>[BOOKED]</strong> or <strong>[ON TRIP]</strong> are unavailable.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Driver <span class="text-danger">*</span></label>
            <div class="custom-select-wrap">
              <div class="custom-select-trigger" id="apr_drv_trigger" tabindex="0" onclick="toggleAprDrop('drv')">
                <span id="apr_drv_display" class="custom-select-placeholder">— Select Driver —</span>
              </div>
              <div class="custom-select-dropdown" id="apr_drv_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" id="apr_drv_search" placeholder="Search driver…" oninput="filterAprDrop('drv',this.value)">
                </div>
                <div id="apr_drv_opts">
                  <?php foreach($drivers as $d):
                    $dVid = $driverVehicleMap[$d['driver_id']] ?? 0;
                    $ini  = strtoupper(substr(implode('',array_map(fn($p)=>$p[0],explode(' ',trim($d['driver_name']??'D')))),0,2));
                  ?>
                  <div class="cs-option"
                    data-value="<?=$d['driver_id']?>"
                    data-label="<?=htmlspecialchars($d['driver_name'],ENT_QUOTES)?>"
                    data-sub="<?=htmlspecialchars($d['license_number']??'',ENT_QUOTES)?>"
                    data-ini="<?=htmlspecialchars($ini,ENT_QUOTES)?>"
                    data-default-vehicle="<?=$dVid?>"
                    onclick="aprSelectDriver(this)">
                    <div class="cs-opt-icon driver-ico"><?=htmlspecialchars($ini)?></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?=htmlspecialchars($d['driver_name'])?></div>
                      <div class="cs-opt-sub"><?=htmlspecialchars($d['license_number']??'No license')?></div>
                    </div>
                    <?php if($dVid>0):?><span class="cs-opt-badge badge-assigned"><i class="bi bi-truck-front me-1"></i>Has vehicle</span><?php else:?><span class="cs-opt-badge badge-unassigned">No vehicle</span><?php endif;?>
                  </div>
                  <?php endforeach;?>
                </div>
              </div>
            </div>
            <div id="apr_drv_msg" class="mt-1"></div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
            <div class="custom-select-wrap">
              <div class="custom-select-trigger" id="apr_veh_trigger" tabindex="0" onclick="toggleAprDrop('veh')">
                <span id="apr_veh_display" class="custom-select-placeholder">— Select Vehicle —</span>
              </div>
              <div class="custom-select-dropdown" id="apr_veh_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" id="apr_veh_search" placeholder="Search vehicle…" oninput="filterAprDrop('veh',this.value)">
                </div>
                <div id="apr_veh_opts">
                  <?php foreach($vehicles as $v):?>
                  <div class="cs-option"
                    data-value="<?=$v['vehicle_id']?>"
                    data-label="<?=htmlspecialchars($v['brand'].' '.$v['model'],ENT_QUOTES)?>"
                    data-sub="<?=htmlspecialchars($v['plate_number'],ENT_QUOTES)?>"
                    onclick="aprSelectVehicle(this)">
                    <div class="cs-opt-icon vehicle-ico"><i class="bi bi-truck-front"></i></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?=htmlspecialchars($v['brand'].' '.$v['model'])?></div>
                      <div class="cs-opt-sub"><?=htmlspecialchars($v['plate_number'])?></div>
                    </div>
                  </div>
                  <?php endforeach;?>
                </div>
              </div>
            </div>
            <div id="apr_veh_msg" class="mt-1"></div>
          </div>
        </div>
        <div id="apr_autofill_note" style="display:none;margin-top:10px;background:#e8f4ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:.8rem;color:#1d4ed8">
          <i class="bi bi-stars me-1"></i>Vehicle auto-filled from driver's default assignment.
        </div>
        <div class="conflict-alert mt-3" id="apr_conflict"><i class="bi bi-exclamation-triangle-fill"></i><span id="apr_conflict_msg"></span></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" id="apr_save_btn" class="btn btn-success" disabled><i class="bi bi-check-lg me-1"></i>Approve & Assign</button>
    </div>
  </form>
</div></div></div>

<!-- REJECT -->
<div class="modal fade" id="rejectModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header mh-maroon"><h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" action="Schedules.php">
    <input type="hidden" name="action" value="reject">
    <input type="hidden" name="schedule_id" id="rej_sid">
    <div class="modal-body">
      <div class="sbox mb-3"><div class="sbox-title"><i class="bi bi-person me-1"></i>Requestor</div><div class="fw-semibold" id="rej_uname">—</div></div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
        <textarea name="rejection_reason" id="rej_reason" class="form-control" rows="3" required maxlength="500" placeholder="e.g. Vehicle unavailable on requested date."></textarea>
        <div class="form-text text-muted"><span id="rej_char">0</span>/500 characters</div>
      </div>
      <div class="alert alert-warning py-2 px-3" style="font-size:.82rem"><i class="bi bi-exclamation-triangle me-1"></i>The requestor will be notified of this rejection and reason.</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-x-circle me-1"></i>Confirm Reject</button>
    </div>
  </form>
</div></div></div>

<!-- CANCEL -->
<div class="modal fade" id="cancelModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header mh-orange"><h5 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Cancel Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" action="Schedules.php">
    <input type="hidden" name="action" value="cancel">
    <input type="hidden" name="schedule_id" id="cancel_sid">
    <div class="modal-body">
      <div class="sbox mb-3">
        <div class="sbox-title"><i class="bi bi-person me-1"></i>Requestor</div>
        <div class="fw-semibold" id="cancel_uname">—</div>
        <div class="mt-2" id="cancel_status_badge"></div>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Reason for Cancellation <span class="text-danger">*</span></label>
        <textarea name="cancel_reason" id="cancel_reason_input" class="form-control" rows="3" required maxlength="500" placeholder="e.g. Trip no longer needed…"></textarea>
        <div class="form-text text-muted"><span id="cancel_char">0</span>/500 characters</div>
      </div>
      <div class="alert alert-warning py-2 px-3" style="font-size:.82rem"><i class="bi bi-exclamation-triangle me-1"></i>This action cannot be undone. The requestor will be notified of this cancellation.</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
      <button type="submit" class="btn btn-danger fw-semibold"><i class="bi bi-slash-circle me-1"></i>Confirm Cancellation</button>
    </div>
  </form>
</div></div></div>

<!-- RESCHEDULE -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header mh-maroon"><h5 class="modal-title"><i class="bi bi-calendar2-event me-2"></i>Reschedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" action="Schedules.php">
    <input type="hidden" name="action" value="reschedule">
    <input type="hidden" name="schedule_id" id="res_sid">
    <div class="modal-body">
      <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.83rem"><i class="bi bi-exclamation-triangle me-1"></i>Rescheduling resets status to <strong>Pending</strong> and clears vehicle/driver. The requestor will be notified.</div>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-semibold">Date Start</label><input type="date" name="date_start" id="res_ds" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Date End</label><input type="date" name="date_end" id="res_de" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Time Start</label><input type="time" name="time_start" id="res_ts" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Time End</label><input type="time" name="time_end" id="res_te" class="form-control"></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Destination</label><input type="text" name="destination" id="res_dest" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label fw-semibold">Purpose</label><input type="text" name="purpose" id="res_purp" class="form-control"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-calendar2-check me-1"></i>Save Reschedule</button>
    </div>
  </form>
</div></div></div>

<!-- CHANGE ASSIGNMENT -->
<div class="modal fade" id="changeModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header mh-maroon"><h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Change Driver / Vehicle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST" action="Schedules.php" id="changeForm">
    <input type="hidden" name="action" value="change_assignment">
    <input type="hidden" name="schedule_id" id="chg_sid">
    <input type="hidden" name="vehicle_id" id="chg_vehicle_hidden">
    <input type="hidden" name="driver_id"  id="chg_driver_hidden">
    <div class="modal-body">
      <div class="sbox mb-3">
        <div class="sbox-title"><i class="bi bi-info-circle me-1"></i>Schedule Details</div>
        <div class="row g-2">
          <div class="col-md-4"><div style="font-size:.75rem;color:#888">Requestor</div><div class="fw-semibold" id="chg_uname">—</div></div>
          <div class="col-md-4"><div style="font-size:.75rem;color:#888">Date Range</div><div class="fw-semibold" id="chg_dates">—</div></div>
          <div class="col-md-4"><div style="font-size:.75rem;color:#888">Time</div><div class="fw-semibold" id="chg_time">—</div></div>
        </div>
      </div>
      <div class="sbox">
        <div class="sbox-title"><i class="bi bi-truck-front me-1"></i>New Driver & Vehicle</div>
        <p class="text-muted mb-3" style="font-size:.82rem"><i class="bi bi-stars me-1 text-warning"></i>Selecting a driver <strong>auto-fills their assigned vehicle</strong>. Options marked <strong>[BOOKED]</strong> are reserved.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Driver <span class="text-danger">*</span></label>
            <div class="custom-select-wrap">
              <div class="custom-select-trigger" id="chg_drv_trigger" tabindex="0" onclick="toggleChgDrop('drv')">
                <span id="chg_drv_display" class="custom-select-placeholder">— Select Driver —</span>
              </div>
              <div class="custom-select-dropdown" id="chg_drv_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" placeholder="Search driver…" oninput="filterChgDrop('drv',this.value)">
                </div>
                <div id="chg_drv_opts">
                  <?php foreach($allDrivers as $d):
                    $dVid = $driverVehicleMap[$d['driver_id']] ?? 0;
                    $ini  = strtoupper(substr(implode('',array_map(fn($p)=>$p[0],explode(' ',trim($d['driver_name']??'D')))),0,2));
                  ?>
                  <div class="cs-option"
                    data-value="<?=$d['driver_id']?>"
                    data-label="<?=htmlspecialchars($d['driver_name'],ENT_QUOTES)?>"
                    data-sub="<?=htmlspecialchars($d['license_number']??'',ENT_QUOTES)?>"
                    data-ini="<?=htmlspecialchars($ini,ENT_QUOTES)?>"
                    data-default-vehicle="<?=$dVid?>"
                    onclick="chgSelectDriver(this)">
                    <div class="cs-opt-icon driver-ico"><?=htmlspecialchars($ini)?></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?=htmlspecialchars($d['driver_name'])?></div>
                      <div class="cs-opt-sub"><?=htmlspecialchars($d['license_number']??'No license')?></div>
                    </div>
                    <?php if($dVid>0):?><span class="cs-opt-badge badge-assigned"><i class="bi bi-truck-front me-1"></i>Has vehicle</span><?php endif;?>
                  </div>
                  <?php endforeach;?>
                </div>
              </div>
            </div>
            <div id="chg_drv_msg" class="mt-1"></div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
            <div class="custom-select-wrap">
              <div class="custom-select-trigger" id="chg_veh_trigger" tabindex="0" onclick="toggleChgDrop('veh')">
                <span id="chg_veh_display" class="custom-select-placeholder">— Select Vehicle —</span>
              </div>
              <div class="custom-select-dropdown" id="chg_veh_dropdown">
                <div class="cs-search-wrap">
                  <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                  <input class="cs-search-input" placeholder="Search vehicle…" oninput="filterChgDrop('veh',this.value)">
                </div>
                <div id="chg_veh_opts">
                  <?php foreach($allVehicles as $v):?>
                  <div class="cs-option"
                    data-value="<?=$v['vehicle_id']?>"
                    data-label="<?=htmlspecialchars($v['brand'].' '.$v['model'],ENT_QUOTES)?>"
                    data-sub="<?=htmlspecialchars($v['plate_number'],ENT_QUOTES)?>"
                    onclick="chgSelectVehicle(this)">
                    <div class="cs-opt-icon vehicle-ico"><i class="bi bi-truck-front"></i></div>
                    <div style="flex:1;min-width:0">
                      <div class="cs-opt-label"><?=htmlspecialchars($v['brand'].' '.$v['model'])?></div>
                      <div class="cs-opt-sub"><?=htmlspecialchars($v['plate_number'])?></div>
                    </div>
                  </div>
                  <?php endforeach;?>
                </div>
              </div>
            </div>
            <div id="chg_veh_msg" class="mt-1"></div>
          </div>
        </div>
        <div id="chg_autofill_note" style="display:none;margin-top:10px;background:#e8f4ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:.8rem;color:#1d4ed8"></div>
        <div class="conflict-alert mt-3" id="chg_conflict"><i class="bi bi-exclamation-triangle-fill"></i><span id="chg_conflict_msg"></span></div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" id="chg_save_btn" class="btn btn-primary" disabled><i class="bi bi-save me-1"></i>Save Changes</button>
    </div>
  </form>
</div></div></div>

<!-- TRIP DONE -->
<div class="modal fade" id="tripDoneModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header mh-green"><h5 class="modal-title"><i class="bi bi-flag-fill me-2"></i>Record Trip Completion</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body p-4">
    <div class="sbox mb-3">
      <div class="sbox-title"><i class="bi bi-info-circle me-1"></i>Trip Details</div>
      <div class="row g-2">
        <div class="col-md-6"><div style="font-size:.75rem;color:#888">Requestor</div><div class="fw-semibold" id="td_uname">—</div></div>
        <div class="col-md-6"><div style="font-size:.75rem;color:#888">Destination</div><div class="fw-semibold" id="td_dest">—</div></div>
      </div>
    </div>
    <div class="alert alert-success py-2 px-3 mb-3" style="font-size:.83rem"><i class="bi bi-check-circle me-1"></i>Record the <strong>actual date and time</strong> the vehicle arrived back. The requestor will be notified.</div>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Arrival Date <span class="text-danger">*</span></label><input type="date" id="td_date" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Arrival Time <span class="text-danger">*</span></label><input type="time" id="td_time" class="form-control"></div>
    </div>
    <div id="td_err" class="alert alert-danger py-2 px-3 mt-3 d-none" style="font-size:.83rem"></div>
  </div>
  <div class="modal-footer border-0 pt-0 pb-4 px-4">
    <button type="button" class="btn btn-secondary btn-sm rounded-3" data-bs-dismiss="modal">Cancel</button>
    <button type="button" id="td_confirm" class="btn btn-sm rounded-3 fw-semibold" style="background:#145a32;color:#fff;min-width:170px">
      <i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete
    </button>
  </div>
</div></div></div>

<!-- DETAILS -->
<div class="modal fade" id="detailsModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content" style="border-radius:16px;overflow:hidden;border:none">
  <div class="modal-header" style="background:linear-gradient(135deg,#0550a0,#0a3678);color:#fff;padding:1.1rem 1.5rem">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:36px;height:36px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem"><i class="bi bi-clipboard2-check-fill"></i></div>
      <div><div style="font-weight:700;font-size:1rem">Trip Details</div><div style="font-size:.72rem;opacity:.8" id="det_status_line">—</div></div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
  </div>
  <div class="modal-body p-0">
    <div style="background:#f8f9fb;border-bottom:1px solid #eee;padding:.75rem 1.5rem;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:7px">
        <div style="width:30px;height:30px;background:#e8f0fe;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#0550a0;font-size:.85rem"><i class="bi bi-person-fill"></i></div>
        <div><div style="font-size:.65rem;color:#999;text-transform:uppercase;letter-spacing:.05em">Requestor</div><div style="font-weight:700;font-size:.88rem;color:#222" id="det_uname">—</div></div>
      </div>
      <div style="display:flex;align-items:center;gap:7px">
        <div style="width:30px;height:30px;background:#fdecea;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#800000;font-size:.85rem"><i class="bi bi-building-fill"></i></div>
        <div><div style="font-size:.65rem;color:#999;text-transform:uppercase;letter-spacing:.05em">Office</div><div style="font-weight:700;font-size:.88rem;color:#222" id="det_office">—</div></div>
      </div>
      <div style="display:flex;align-items:center;gap:7px">
        <div style="width:30px;height:30px;background:#e8f5e9;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#1b5e20;font-size:.85rem"><i class="bi bi-diagram-3-fill"></i></div>
        <div><div style="font-size:.65rem;color:#999;text-transform:uppercase;letter-spacing:.05em">Department</div><div style="font-weight:700;font-size:.88rem;color:#222" id="det_dept">—</div></div>
      </div>
      <div id="det_ticket_pill" style="margin-left:auto"></div>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:.9rem 1rem">
          <div style="font-size:.65rem;color:#c2410c;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-geo-alt-fill me-1"></i>Destination</div>
          <div style="font-weight:700;font-size:.95rem;color:#1a1a1a" id="det_dest">—</div>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:.9rem 1rem">
          <div style="font-size:.65rem;color:#166534;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-clipboard-fill me-1"></i>Purpose</div>
          <div style="font-weight:700;font-size:.95rem;color:#1a1a1a" id="det_purp">—</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:.75rem">
        <div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:12px;padding:.9rem 1rem">
          <div style="font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-calendar-range me-1"></i>Date Range</div>
          <div style="font-weight:700;font-size:.88rem;color:#1a1a1a" id="det_dates">—</div>
        </div>
        <div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:12px;padding:.9rem 1rem">
          <div style="font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-clock me-1"></i>Time</div>
          <div style="font-weight:700;font-size:.88rem;color:#1a1a1a" id="det_time">—</div>
        </div>
        <div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:12px;padding:.9rem 1rem">
          <div style="font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-people-fill me-1"></i>Passengers</div>
          <div style="font-weight:700;font-size:.88rem;color:#1a1a1a" id="det_pax">—</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:.9rem 1rem">
          <div style="font-size:.65rem;color:#1d4ed8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-truck-front-fill me-1"></i>Vehicle</div>
          <div style="font-weight:700;font-size:.88rem;color:#1a1a1a" id="det_veh">—</div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:.9rem 1rem">
          <div style="font-size:.65rem;color:#1d4ed8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-person-badge-fill me-1"></i>Driver</div>
          <div style="font-weight:700;font-size:.88rem;color:#1a1a1a" id="det_drv">—</div>
        </div>
      </div>
      <div id="det_arrival_wrap" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:.9rem 1rem;margin-bottom:.75rem">
        <div style="font-size:.65rem;color:#166534;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-flag-fill me-1"></i>Actual Arrival</div>
        <div style="font-weight:700;font-size:.95rem;color:#166534" id="det_arrived">—</div>
      </div>
      <div id="det_booked_wrap" style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:12px;padding:.9rem 1rem;margin-bottom:.75rem">
        <div style="font-size:.65rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-calendar-check me-1"></i>Booked On</div>
        <div style="font-weight:700;font-size:.88rem;color:#1a1a1a" id="det_booked_on">—</div>
        <div style="font-size:.78rem;color:#888;margin-top:2px" id="det_booked_by"></div>
      </div>
      <div id="det_reason_wrap" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:.9rem 1rem;margin-bottom:.75rem">
        <div style="font-size:.65rem;color:#991b1b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-x-circle-fill me-1"></i>Rejection Reason</div>
        <div style="font-weight:700;font-size:.88rem;color:#991b1b" id="det_reason">—</div>
      </div>
      <div id="det_cancel_wrap" style="display:none;background:#f8f9fb;border:1px solid #e2e8f0;border-radius:12px;padding:.9rem 1rem;margin-bottom:.75rem">
        <div style="font-size:.65rem;color:#41464b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px"><i class="bi bi-slash-circle-fill me-1"></i>Cancellation Reason</div>
        <div style="font-weight:700;font-size:.88rem;color:#41464b" id="det_cancel">—</div>
        <div id="det_cancelled_by" class="mt-2"></div>
      </div>
    </div>
  </div>
  <div class="modal-footer" style="background:#f8f9fb;border-top:1px solid #eee">
    <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
  </div>
</div></div></div>

<!-- ══ MOBILE FAB ══ -->
<button class="mob-fab" onclick="mobSchedOpenAdd()" title="Add Schedule">
    <i class="bi bi-plus-lg"></i>
</button>

<div class="sheet-backdrop" id="mobSchedBackdrop" onclick="mobSchedCloseAll()"></div>

<!-- ── Add Schedule Sheet ── -->
<div class="mob-sheet" id="mobAddSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-calendar-plus"></i> Add Schedule</div>
    <form method="POST" action="Schedules.php" id="mobAddForm" novalidate>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="user_id" value="<?=$me['user_id']?>">
        <input type="hidden" name="office_id" value="<?=$me['u_office_id']??0?>">
        <input type="hidden" name="department_id" value="<?=!empty($me['department_id'])?(int)$me['department_id']:''?>">
        <div id="mob_add_info_badges" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
    <span style="display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;border:1px solid #e8cece;border-radius:8px;padding:6px 12px;font-size:.78rem;color:#800000;font-weight:600">
        <i class="bi bi-person-circle"></i>
        <span id="mob_add_badge_name"><?= htmlspecialchars($me['username']??'—') ?></span>
    </span>
    <span style="display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;border:1px solid #e8cece;border-radius:8px;padding:6px 12px;font-size:.78rem;color:#800000;font-weight:600">
        <i class="bi bi-building"></i>
        <span id="mob_add_badge_office"><?= htmlspecialchars($me['office_name']??'—') ?></span>
    </span>
    <span style="display:inline-flex;align-items:center;gap:5px;background:#fdf5f5;border:1px solid #e8cece;border-radius:8px;padding:6px 12px;font-size:.78rem;color:#800000;font-weight:600">
        <i class="bi bi-diagram-3"></i>
        <span id="mob_add_badge_dept"><?= htmlspecialchars($me['department_name']??'—') ?></span>
    </span>
</div>
        <!-- ✅ FIX: Added "Booking For" selector matching desktop -->
        <div class="sheet-form-group">
    <label class="sheet-label">Booking For <span style="color:#dc2626">*</span></label>
    <select name="requested_user_id" id="mob_add_user" class="sheet-input sheet-select" required
    onchange="mobUpdateBookingInfo(this)">
    <option value="<?= $me['user_id'] ?>"
        data-name="<?= htmlspecialchars($me['username'], ENT_QUOTES) ?>"
        data-office="<?= htmlspecialchars($me['office_name'] ?? '—', ENT_QUOTES) ?>"
        data-dept="<?= htmlspecialchars($me['department_name'] ?? '—', ENT_QUOTES) ?>"
        style="font-weight:700">
        👤 <?= htmlspecialchars($me['username']) ?> (Me)
    </option>
    <?php foreach($officeUsers as $u):
        if($u['user_id'] == $me['user_id']) continue;
        $uInfoStmt2 = $pdo->prepare("SELECT o.office_name, d.dept_name FROM users u LEFT JOIN offices o ON u.office_id=o.office_id LEFT JOIN departments d ON u.dept_id=d.dept_id WHERE u.user_id=?");
        $uInfoStmt2->execute([$u['user_id']]);
        $uInfo2 = $uInfoStmt2->fetch();
    ?>
    <option value="<?= $u['user_id'] ?>"
        data-name="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
        data-office="<?= htmlspecialchars($uInfo2['office_name'] ?? '—', ENT_QUOTES) ?>"
        data-dept="<?= htmlspecialchars($uInfo2['dept_name'] ?? '—', ENT_QUOTES) ?>">
        <?= htmlspecialchars($u['username']) ?>
    </option>
    <?php endforeach; ?>
</select>
</div>
        <div class="sheet-form-group">
            <label class="sheet-label">Date Start <span style="color:#dc2626">*</span></label>
            <input type="date" name="date_start" id="mob_add_ds" class="sheet-input" required min="<?=date('Y-m-d')?>">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Date End <span style="color:#dc2626">*</span></label>
            <input type="date" name="date_end" id="mob_add_de" class="sheet-input" required min="<?=date('Y-m-d')?>">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Time Start <span style="color:#dc2626">*</span></label>
            <input type="time" name="time_start" id="mob_add_ts" class="sheet-input" required>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Time End <span style="color:#dc2626">*</span></label>
            <input type="time" name="time_end" id="mob_add_te" class="sheet-input" required>
            <div id="mob_add_te_err" style="font-size:.72rem;color:#dc2626;margin-top:4px;display:none">End time must be after start time.</div>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Destination <span style="color:#dc2626">*</span></label>
            <input type="text" name="destination" class="sheet-input" required placeholder="e.g. City Hall, Baguio">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Purpose</label>
            <input type="text" name="purpose" class="sheet-input" placeholder="e.g. Official Business">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Passengers <span style="color:#dc2626">*</span></label>
            <input type="number" name="passengers" class="sheet-input" required min="1" max="50" value="1">
        </div>
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-primary"><i class="bi bi-send me-1"></i>Submit</button>
        </div>
        
    </form>
</div>

<!-- ── Approve Sheet ── -->
<div class="mob-sheet" id="mobApproveSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-check-circle"></i> Approve & Assign</div>
    <form method="POST" action="Schedules.php" id="mobApproveForm">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="schedule_id" id="mob_apr_sid">
        <input type="hidden" name="vehicle_id" id="mob_apr_vehicle_hidden">
        <input type="hidden" name="driver_id"  id="mob_apr_driver_hidden">
        <div class="sheet-info-box" id="mob_apr_info"></div>

        <!-- Driver Custom Select -->
        <div class="sheet-form-group">
            <label class="sheet-label">Driver <span style="color:#dc2626">*</span></label>
            <div class="custom-select-wrap">
                <div class="custom-select-trigger" id="mob_apr_drv_trigger" tabindex="0"
                    onclick="toggleMobAprDrop('drv')">
                    <span id="mob_apr_drv_display" class="custom-select-placeholder">— Select Driver —</span>
                </div>
                <div class="custom-select-dropdown" id="mob_apr_drv_dropdown">
                    <div class="cs-search-wrap">
                        <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                        <input class="cs-search-input" id="mob_apr_drv_search"
                            placeholder="Search driver…"
                            oninput="filterMobAprDrop('drv',this.value)">
                    </div>
                    <div id="mob_apr_drv_opts">
                        <?php foreach($drivers as $d):
                            $dVid = $driverVehicleMap[$d['driver_id']] ?? 0;
                            $ini  = strtoupper(substr(implode('',array_map(fn($p)=>$p[0],explode(' ',trim($d['driver_name']??'D')))),0,2));
                        ?>
                        <div class="cs-option"
                            data-value="<?=$d['driver_id']?>"
                            data-label="<?=htmlspecialchars($d['driver_name'],ENT_QUOTES)?>"
                            data-sub="<?=htmlspecialchars($d['license_number']??'',ENT_QUOTES)?>"
                            data-ini="<?=htmlspecialchars($ini,ENT_QUOTES)?>"
                            data-default-vehicle="<?=$dVid?>"
                            onclick="mobAprSelectDriver(this)">
                            <div class="cs-opt-icon driver-ico"><?=htmlspecialchars($ini)?></div>
                            <div style="flex:1;min-width:0">
                                <div class="cs-opt-label"><?=htmlspecialchars($d['driver_name'])?></div>
                                <div class="cs-opt-sub"><?=htmlspecialchars($d['license_number']??'No license')?></div>
                            </div>
                            <?php if($dVid>0):?><span class="cs-opt-badge badge-assigned"><i class="bi bi-truck-front me-1"></i>Has vehicle</span><?php else:?><span class="cs-opt-badge badge-unassigned">No vehicle</span><?php endif;?>
                        </div>
                        <?php endforeach;?>
                    </div>
                </div>
            </div>
            <div id="mob_apr_drv_msg" class="mt-1"></div>
        </div>

        <!-- Vehicle Custom Select -->
        <div class="sheet-form-group">
            <label class="sheet-label">Vehicle <span style="color:#dc2626">*</span></label>
            <div class="custom-select-wrap">
                <div class="custom-select-trigger" id="mob_apr_veh_trigger" tabindex="0"
                    onclick="toggleMobAprDrop('veh')">
                    <span id="mob_apr_veh_display" class="custom-select-placeholder">— Select Vehicle —</span>
                </div>
                <div class="custom-select-dropdown" id="mob_apr_veh_dropdown">
                    <div class="cs-search-wrap">
                        <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                        <input class="cs-search-input" id="mob_apr_veh_search"
                            placeholder="Search vehicle…"
                            oninput="filterMobAprDrop('veh',this.value)">
                    </div>
                    <div id="mob_apr_veh_opts">
                        <?php foreach($vehicles as $v):?>
                        <div class="cs-option"
                            data-value="<?=$v['vehicle_id']?>"
                            data-label="<?=htmlspecialchars($v['brand'].' '.$v['model'],ENT_QUOTES)?>"
                            data-sub="<?=htmlspecialchars($v['plate_number'],ENT_QUOTES)?>"
                            onclick="mobAprSelectVehicle(this)">
                            <div class="cs-opt-icon vehicle-ico"><i class="bi bi-truck-front"></i></div>
                            <div style="flex:1;min-width:0">
                                <div class="cs-opt-label"><?=htmlspecialchars($v['brand'].' '.$v['model'])?></div>
                                <div class="cs-opt-sub"><?=htmlspecialchars($v['plate_number'])?></div>
                            </div>
                        </div>
                        <?php endforeach;?>
                    </div>
                </div>
            </div>
            <div id="mob_apr_veh_msg" class="mt-1"></div>
        </div>

        <div id="mob_apr_autofill_note" style="display:none;background:#e8f4ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:.78rem;color:#1d4ed8;margin-bottom:10px">
            <i class="bi bi-stars me-1"></i>Vehicle auto-filled from driver's default assignment.
            <a href="#" style="color:#1d4ed8;font-weight:700;text-decoration:underline;display:block;margin-top:4px"
                onclick="mobAprClearVehicle(event)">Assign a different vehicle</a>
        </div>

        <div class="conflict-mob" id="mob_apr_conflict">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span id="mob_apr_conflict_msg"></span>
        </div>

        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
            <button type="submit" id="mob_apr_btn" class="btn-sheet-success" disabled>
                <i class="bi bi-check-lg me-1"></i>Approve
            </button>
        </div>
    </form>
</div>

<!-- ── Reject Sheet ── -->
<div class="mob-sheet" id="mobRejectSheet">
    <div class="sheet-handle"></div>
    <div class="confirm-emoji">⚠️</div>
    <div class="confirm-msg">Reject schedule for</div>
    <div class="confirm-name" id="mob_rej_name" style="color:#856404">—</div>
    <form method="POST" action="Schedules.php">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="schedule_id" id="mob_rej_sid">
        <div class="sheet-form-group">
            <label class="sheet-label">Reason <span style="color:#dc2626">*</span></label>
            <textarea name="rejection_reason" class="sheet-input" rows="3" required maxlength="500" placeholder="e.g. Vehicle unavailable…" style="resize:none"></textarea>
        </div>
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-warning"><i class="bi bi-x-circle me-1"></i>Reject</button>
        </div>
    </form>
</div>

<!-- ── Cancel Sheet ── -->
<div class="mob-sheet" id="mobCancelSheet">
    <div class="sheet-handle"></div>
    <div class="confirm-emoji">🚫</div>
    <div class="confirm-msg">Cancel schedule for</div>
    <div class="confirm-name" id="mob_cancel_name" style="color:#dc2626">—</div>
    <form method="POST" action="Schedules.php">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="schedule_id" id="mob_cancel_sid">
        <div class="sheet-form-group">
            <label class="sheet-label">Reason <span style="color:#dc2626">*</span></label>
            <textarea name="cancel_reason" class="sheet-input" rows="3" required maxlength="500" placeholder="e.g. Trip no longer needed…" style="resize:none"></textarea>
        </div>
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-danger"><i class="bi bi-slash-circle me-1"></i>Confirm Cancel</button>
        </div>
    </form>
</div>

<!-- ── Reschedule Sheet ── -->
<div class="mob-sheet" id="mobReschedSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-calendar2-event"></i> Reschedule</div>
    <div style="background:#fff3cd;border-radius:9px;padding:8px 11px;font-size:.76rem;color:#856404;margin-bottom:14px">
        <i class="bi bi-exclamation-triangle me-1"></i>Resets to Pending and clears vehicle/driver.
    </div>
    <form method="POST" action="Schedules.php">
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="schedule_id" id="mob_res_sid">
        <div class="sheet-form-group">
            <label class="sheet-label">Date Start</label>
            <input type="date" name="date_start" id="mob_res_ds" class="sheet-input" required>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Date End</label>
            <input type="date" name="date_end" id="mob_res_de" class="sheet-input" required>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Time Start</label>
            <input type="time" name="time_start" id="mob_res_ts" class="sheet-input">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Time End</label>
            <input type="time" name="time_end" id="mob_res_te" class="sheet-input">
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Destination</label>
            <input type="text" name="destination" id="mob_res_dest" class="sheet-input" required>
        </div>
        <div class="sheet-form-group">
            <label class="sheet-label">Purpose</label>
            <input type="text" name="purpose" id="mob_res_purp" class="sheet-input">
        </div>
        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
            <button type="submit" class="btn-sheet-primary"><i class="bi bi-calendar2-check me-1"></i>Save</button>
        </div>
    </form>
</div>

<!-- ── Change Assignment Sheet ── -->
<div class="mob-sheet" id="mobChangeSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-arrow-repeat"></i> Change Vehicle / Driver</div>
    <form method="POST" action="Schedules.php" id="mobChangeForm">
        <input type="hidden" name="action" value="change_assignment">
        <input type="hidden" name="schedule_id" id="mob_chg_sid">
        <input type="hidden" name="vehicle_id" id="mob_chg_vehicle_hidden">
        <input type="hidden" name="driver_id"  id="mob_chg_driver_hidden">
        <div class="sheet-info-box" id="mob_chg_info"></div>

        <!-- Driver Custom Select -->
        <div class="sheet-form-group">
            <label class="sheet-label">Driver <span style="color:#dc2626">*</span></label>
            <div class="custom-select-wrap">
                <div class="custom-select-trigger" id="mob_chg_drv_trigger" tabindex="0"
                    onclick="toggleMobChgDrop('drv')">
                    <span id="mob_chg_drv_display" class="custom-select-placeholder">— Select Driver —</span>
                </div>
                <div class="custom-select-dropdown" id="mob_chg_drv_dropdown">
                    <div class="cs-search-wrap">
                        <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                        <input class="cs-search-input" id="mob_chg_drv_search"
                            placeholder="Search driver…"
                            oninput="filterMobChgDrop('drv',this.value)">
                    </div>
                    <div id="mob_chg_drv_opts">
                        <?php foreach($allDrivers as $d):
                            $dVid = $driverVehicleMap[$d['driver_id']] ?? 0;
                            $ini  = strtoupper(substr(implode('',array_map(fn($p)=>$p[0],explode(' ',trim($d['driver_name']??'D')))),0,2));
                        ?>
                        <div class="cs-option"
                            data-value="<?=$d['driver_id']?>"
                            data-label="<?=htmlspecialchars($d['driver_name'],ENT_QUOTES)?>"
                            data-sub="<?=htmlspecialchars($d['license_number']??'',ENT_QUOTES)?>"
                            data-ini="<?=htmlspecialchars($ini,ENT_QUOTES)?>"
                            data-default-vehicle="<?=$dVid?>"
                            onclick="mobChgSelectDriver(this)">
                            <div class="cs-opt-icon driver-ico"><?=htmlspecialchars($ini)?></div>
                            <div style="flex:1;min-width:0">
                                <div class="cs-opt-label"><?=htmlspecialchars($d['driver_name'])?></div>
                                <div class="cs-opt-sub"><?=htmlspecialchars($d['license_number']??'No license')?></div>
                            </div>
                            <?php if($dVid>0):?><span class="cs-opt-badge badge-assigned"><i class="bi bi-truck-front me-1"></i>Has vehicle</span><?php else:?><span class="cs-opt-badge badge-unassigned">No vehicle</span><?php endif;?>
                        </div>
                        <?php endforeach;?>
                    </div>
                </div>
            </div>
            <div id="mob_chg_drv_msg" class="mt-1"></div>
        </div>

        <!-- Vehicle Custom Select -->
        <div class="sheet-form-group">
            <label class="sheet-label">Vehicle <span style="color:#dc2626">*</span></label>
            <div class="custom-select-wrap">
                <div class="custom-select-trigger" id="mob_chg_veh_trigger" tabindex="0"
                    onclick="toggleMobChgDrop('veh')">
                    <span id="mob_chg_veh_display" class="custom-select-placeholder">— Select Vehicle —</span>
                </div>
                <div class="custom-select-dropdown" id="mob_chg_veh_dropdown">
                    <div class="cs-search-wrap">
                        <i class="bi bi-search" style="color:#aaa;font-size:.8rem"></i>
                        <input class="cs-search-input" id="mob_chg_veh_search"
                            placeholder="Search vehicle…"
                            oninput="filterMobChgDrop('veh',this.value)">
                    </div>
                    <div id="mob_chg_veh_opts">
                        <?php foreach($allVehicles as $v):?>
                        <div class="cs-option"
                            data-value="<?=$v['vehicle_id']?>"
                            data-label="<?=htmlspecialchars($v['brand'].' '.$v['model'],ENT_QUOTES)?>"
                            data-sub="<?=htmlspecialchars($v['plate_number'],ENT_QUOTES)?>"
                            onclick="mobChgSelectVehicle(this)">
                            <div class="cs-opt-icon vehicle-ico"><i class="bi bi-truck-front"></i></div>
                            <div style="flex:1;min-width:0">
                                <div class="cs-opt-label"><?=htmlspecialchars($v['brand'].' '.$v['model'])?></div>
                                <div class="cs-opt-sub"><?=htmlspecialchars($v['plate_number'])?></div>
                            </div>
                        </div>
                        <?php endforeach;?>
                    </div>
                </div>
            </div>
            <div id="mob_chg_veh_msg" class="mt-1"></div>
        </div>

        <div id="mob_chg_autofill_note" style="display:none;background:#e8f4ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;font-size:.78rem;color:#1d4ed8;margin-bottom:10px">
            <i class="bi bi-stars me-1"></i>Vehicle auto-filled from driver's default assignment.
            <a href="#" style="color:#1d4ed8;font-weight:700;text-decoration:underline;display:block;margin-top:4px"
                onclick="mobChgClearVehicle(event)">Assign a different vehicle</a>
        </div>

        <div class="conflict-mob" id="mob_chg_conflict">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span id="mob_chg_conflict_msg"></span>
        </div>

        <div class="sheet-actions">
            <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
            <button type="submit" id="mob_chg_btn" class="btn-sheet-primary" disabled>
                <i class="bi bi-save me-1"></i>Save
            </button>
        </div>
    </form>
</div>
<!-- ── Trip Done Sheet ── -->
<div class="mob-sheet" id="mobTripDoneSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-head"><i class="bi bi-flag-fill" style="color:#0f5132"></i> Record Trip Completion</div>
    <div class="sheet-info-box" id="mob_td_info"></div>
    <div class="sheet-form-group">
        <label class="sheet-label">Arrival Date <span style="color:#dc2626">*</span></label>
        <input type="date" id="mob_td_date" class="sheet-input">
    </div>
    <div class="sheet-form-group">
        <label class="sheet-label">Arrival Time <span style="color:#dc2626">*</span></label>
        <input type="time" id="mob_td_time" class="sheet-input">
    </div>
    <div id="mob_td_err" style="background:#fef2f2;border-radius:9px;padding:8px 11px;font-size:.78rem;color:#dc2626;display:none;margin-top:8px"></div>
    <div class="sheet-actions">
        <button type="button" class="btn-sheet-cancel" onclick="mobSchedCloseAll()">Cancel</button>
        <button type="button" id="mob_td_confirm" class="btn-sheet-success" onclick="mobTripDoneConfirm()">
            <i class="bi bi-flag-fill me-1"></i>Confirm Complete
        </button>
    </div>
</div>

<!-- ── View Details Sheet ── -->
<div class="mob-sheet" id="mobDetailsSheet" style="padding-bottom:0;border-radius:20px 20px 0 0;overflow:hidden;display:flex;flex-direction:column">
    <!-- Blue header matching desktop -->
    <div style="background:linear-gradient(135deg,#0550a0,#0a3678);color:#fff;padding:1rem 1.1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
        <div style="display:flex;align-items:center;gap:9px">
            <div style="width:32px;height:32px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem"><i class="bi bi-clipboard2-check-fill"></i></div>
            <div>
                <div style="font-weight:700;font-size:.92rem">Trip Details</div>
                <div style="font-size:.68rem;opacity:.8" id="mob_det_status_line">—</div>
            </div>
        </div>
        <div onclick="mobSchedCloseAll()" style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:.85rem">
            <i class="bi bi-x-lg"></i>
        </div>
    </div>
  <div style="overflow-y:auto;flex:1;padding:14px 14px 48px">
        <div id="mob_det_body" style="font-size:.84rem"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ══════════════════════════════════════════
   MOBILE SCHEDULES
══════════════════════════════════════════ */
function updateBookingInfo(sel) {
    const opt = sel.options[sel.selectedIndex];
    const name   = opt.dataset.name   || '—';
    const office = opt.dataset.office || '—';
    const dept   = opt.dataset.dept   || '—';

    document.getElementById('add_badge_name_text').textContent   = name;
    document.getElementById('add_badge_office_text').textContent = office;
    document.getElementById('add_badge_dept_text').textContent   = dept;

    // Also sync hidden user_id
    document.getElementById('add_user_id').value = opt.value;
}

// Reset badges when modal opens
document.getElementById('addModal').addEventListener('show.bs.modal', function() {
    const sel = document.getElementById('add_requested_user');
    if (sel) {
        sel.selectedIndex = 0;
        document.getElementById('add_user_id').value = sel.value;
        updateBookingInfo(sel);
    }
});
function toggleSchedSidebar(){
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('schedSidebarOverlay').classList.toggle('show');
}

let _mobAprDS='',_mobAprDE='',_mobAprTS='',_mobAprTE='',_mobAprSid=0,_mobAprUid=0;
let _mobChgDS='',_mobChgDE='',_mobChgTS='',_mobChgTE='',_mobChgSid=0;
let _mobTdSid = 0;

function mobSchedOpenSheet(id){
    document.getElementById('mobSchedBackdrop').classList.add('open');
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function mobSchedCloseAll(){
    document.getElementById('mobSchedBackdrop').classList.remove('open');
    ['mobAddSheet','mobApproveSheet','mobRejectSheet','mobCancelSheet',
     'mobReschedSheet','mobChangeSheet','mobTripDoneSheet','mobDetailsSheet']
    .forEach(id => { const el = document.getElementById(id); if(el) el.classList.remove('open'); });
    document.body.style.overflow = '';
}
function mobSchedOpenAdd(){
    document.getElementById('mobAddForm').reset();
    
    // Reset badges to "Me" defaults
    const sel = document.getElementById('mob_add_user');
    if(sel){
        sel.selectedIndex = 0;
        mobUpdateBookingInfo(sel);
    }
    
    mobSchedOpenSheet('mobAddSheet');
}
// REPLACE the existing mobUpdateBookingInfo function:
function mobUpdateBookingInfo(sel) {
    const opt = sel.options[sel.selectedIndex];
    const name   = opt.dataset.name   || '—';
    const office = opt.dataset.office || '—';
    const dept   = opt.dataset.dept   || '—';

    document.getElementById('mob_add_badge_name').textContent   = name;
    document.getElementById('mob_add_badge_office').textContent = office;
    document.getElementById('mob_add_badge_dept').textContent   = dept;

    // Sync hidden user_id
    document.querySelector('#mobAddForm input[name=user_id]').value = opt.value;
}
/* ── ✅ FIX: mobApprove now stores uid and checks for user duplicate bookings ── */
function mobApprove(d){
    _mobAprSid = d.id;
    _mobAprDS  = d.ds;
    _mobAprDE  = d.de;
    _mobAprTS  = d.ts;
    _mobAprTE  = d.te;
    _mobAprUid = parseInt(d.uid || 0);

    document.getElementById('mob_apr_sid').value = d.id;
    document.getElementById('mob_apr_info').innerHTML =
        `<div class="sheet-info-row"><span class="sheet-info-lbl">Requestor</span><strong>${d.username}</strong></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Date</span><span>${d.ds===d.de?d.ds:d.ds+' → '+d.de}</span></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Time</span><span>${d.ts||'--'} – ${d.te||'--'}</span></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Passengers</span><span>${d.pax}</span></div>`;

    document.getElementById('mob_apr_veh').value = '';
    document.getElementById('mob_apr_drv').value = '';
    document.getElementById('mob_apr_conflict').classList.remove('show');
    document.getElementById('mob_apr_btn').disabled = false;

    /* ✅ Same user duplicate check as desktop */
    const hasDup = allSchedules.some(s =>
        parseInt(s.user_id) === _mobAprUid &&
        String(s.schedule_id) !== String(d.id) &&
        ['Approved','OnTrip'].includes(s.status) &&
        s.date_start <= d.de && s.date_end >= d.ds
    );
    if(hasDup){
        document.getElementById('mob_apr_conflict_msg').textContent =
            'Warning: This requestor already has an approved trip on the same date(s).';
        document.getElementById('mob_apr_conflict').classList.add('show');
        document.getElementById('mob_apr_btn').disabled = true;
    }

    /* Mark vehicle/driver options with availability */
    markConflicts('mob_apr_veh','mob_apr_drv', d.ds, d.de, d.ts, d.te, d.id);
    mobSchedOpenSheet('mobApproveSheet');
}

function mobReject(sid, name){
    document.getElementById('mob_rej_sid').value = sid;
    document.getElementById('mob_rej_name').textContent = name;
    mobSchedOpenSheet('mobRejectSheet');
}
function mobCancel(sid, name, status){
    document.getElementById('mob_cancel_sid').value = sid;
    document.getElementById('mob_cancel_name').textContent = name;
    mobSchedOpenSheet('mobCancelSheet');
}
function mobReschedule(d){
    document.getElementById('mob_res_sid').value  = d.id;
    document.getElementById('mob_res_ds').value   = d.ds;
    document.getElementById('mob_res_de').value   = d.de;
    document.getElementById('mob_res_ts').value   = d.ts;
    document.getElementById('mob_res_te').value   = d.te;
    document.getElementById('mob_res_dest').value = d.dest;
    document.getElementById('mob_res_purp').value = d.purp;
    mobSchedOpenSheet('mobReschedSheet');
}
function mobChangeAssign(d){
    _mobChgSid=d.id; _mobChgDS=d.ds; _mobChgDE=d.de; _mobChgTS=d.ts; _mobChgTE=d.te;
    document.getElementById('mob_chg_sid').value = d.id;
    document.getElementById('mob_chg_info').innerHTML =
        `<div class="sheet-info-row"><span class="sheet-info-lbl">Requestor</span><strong>${d.username}</strong></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Date</span><span>${d.ds===d.de?d.ds:d.ds+' → '+d.de}</span></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Time</span><span>${d.ts||'--'} – ${d.te||'--'}</span></div>`;
    document.getElementById('mob_chg_veh').value = d.vid||'';
    document.getElementById('mob_chg_drv').value = d.did||'';
    document.getElementById('mob_chg_conflict').classList.remove('show');
    document.getElementById('mob_chg_btn').disabled = false;
    markConflicts('mob_chg_veh','mob_chg_drv',d.ds,d.de,d.ts,d.te,d.id);
    mobSchedOpenSheet('mobChangeSheet');
}
function mobTripDone(sid, name, dest){
    _mobTdSid = sid;
    document.getElementById('mob_td_info').innerHTML =
        `<div class="sheet-info-row"><span class="sheet-info-lbl">Requestor</span><strong>${name}</strong></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Destination</span><span>${dest}</span></div>`;
    document.getElementById('mob_td_err').style.display='none';
    const now=new Date(), pad=n=>String(n).padStart(2,'0');
    document.getElementById('mob_td_date').value = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
    document.getElementById('mob_td_time').value = pad(now.getHours())+':'+pad(now.getMinutes());
    const btn=document.getElementById('mob_td_confirm');
    btn.disabled=false; btn.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Complete';
    mobSchedOpenSheet('mobTripDoneSheet');
}
function mobTripDoneConfirm(){
    const sid=_mobTdSid, date=document.getElementById('mob_td_date').value,
          time=document.getElementById('mob_td_time').value,
          err=document.getElementById('mob_td_err'), btn=document.getElementById('mob_td_confirm');
    err.style.display='none';
    if(!date||!time){ err.textContent='Please fill in both date and time.'; err.style.display='block'; return; }
    btn.disabled=true; btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    fetch('complete_trip.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({schedule_id:sid,arrived_date:date,arrived_time:time}).toString()})
    .then(r=>r.text()).then(raw=>{
        let data; try{data=JSON.parse(raw);}catch(_){
            err.textContent='Server error.'; err.style.display='block';
            btn.disabled=false; btn.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Complete'; return;
        }
        if(data.ok){ mobSchedCloseAll(); showToast('Trip completed!','success'); setTimeout(()=>location.reload(),800); }
        else{ err.textContent=data.msg||'Error.'; err.style.display='block'; btn.disabled=false; btn.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Complete'; }
    });
}

function mobViewDetails(d){
    const statusBadge = {
        Completed:'<span class="bp bp-completed"><i class="bi bi-check2-all me-1"></i>Completed</span>',
        OnTrip:'<span class="bp bp-ontrip"><i class="bi bi-truck me-1"></i>On Trip</span>',
        Approved:'<span class="bp bp-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>',
        Pending:'<span class="bp bp-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>',
        Cancelled:'<span class="bp bp-cancelled"><i class="bi bi-slash-circle me-1"></i>Cancelled</span>',
        Rejected:'<span class="bp bp-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span>',
    };

    function fmtArrival(val){
        if(!val) return null;
        const dt = new Date(val.replace(' ','T'));
        return isNaN(dt) ? val : dt.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' '+dt.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
    }

    let html = `
    <!-- Status + Ticket -->
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        ${statusBadge[d.status]||'<span class="bp">'+d.status+'</span>'}
        ${d.ticket?`<span style="display:inline-flex;align-items:center;gap:5px;background:#d1e7dd;border:1px solid #0f5132;border-radius:20px;padding:3px 11px;font-size:.72rem;font-weight:700;color:#0f5132"><i class="bi bi-ticket-perforated-fill"></i>${d.ticket}</span>`:''}
    </div>

    <!-- Requestor / Office / Dept -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
        <div style="background:#e8f0fe;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#0550a0;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-person-fill me-1"></i>Requestor</div>
            <div style="font-weight:700;font-size:.82rem;color:#1a1a1a">${d.username||'—'}</div>
        </div>
        <div style="background:#fdecea;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#800000;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-building-fill me-1"></i>Office</div>
            <div style="font-weight:700;font-size:.82rem;color:#1a1a1a">${d.office||'—'}</div>
        </div>
        <div style="background:#e8f5e9;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#1b5e20;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-diagram-3-fill me-1"></i>Department</div>
            <div style="font-weight:700;font-size:.82rem;color:#1a1a1a">${d.dept||'—'}</div>
        </div>
        <div style="background:#f8f9fb;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-people-fill me-1"></i>Passengers</div>
            <div style="font-weight:700;font-size:.82rem;color:#1a1a1a">${d.pax||1} passenger${parseInt(d.pax||1)!==1?'s':''}</div>
        </div>
    </div>

    <!-- Destination + Purpose -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#c2410c;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-geo-alt-fill me-1"></i>Destination</div>
            <div style="font-weight:700;font-size:.85rem;color:#1a1a1a">${d.dest||'—'}</div>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#166534;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-clipboard-fill me-1"></i>Purpose</div>
            <div style="font-weight:700;font-size:.85rem;color:#1a1a1a">${d.purp||'—'}</div>
        </div>
    </div>

    <!-- Date / Time -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
        <div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-calendar-range me-1"></i>Date Range</div>
            <div style="font-weight:700;font-size:.8rem;color:#1a1a1a">${d.ds===d.de?fmtDate(d.ds):fmtDate(d.ds)+' → '+fmtDate(d.de)}</div>
        </div>
        <div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-clock me-1"></i>Time</div>
            <div style="font-weight:700;font-size:.8rem;color:#1a1a1a">${fmtTime(d.ts)} – ${fmtTime(d.te)}</div>
        </div>
    </div>

    <!-- Vehicle + Driver -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-truck-front-fill me-1"></i>Vehicle</div>
            <div style="font-weight:700;font-size:.78rem;color:#1a1a1a">${d.vehicle||'—'}</div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:9px 11px">
            <div style="font-size:.6rem;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-person-badge-fill me-1"></i>Driver</div>
            <div style="font-weight:700;font-size:.78rem;color:#1a1a1a">${d.driver||'—'}</div>
        </div>
    </div>`;

    // Actual Arrival
    if(d.arrived){
        html+=`<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:9px 12px;margin-bottom:10px">
            <div style="font-size:.6rem;color:#166534;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-flag-fill me-1"></i>Actual Arrival</div>
            <div style="font-weight:700;font-size:.88rem;color:#166534">${fmtArrival(d.arrived)||d.arrived}</div>
        </div>`;
    }

    // Booked On
    if(d.bookedAt){
        const bdt = new Date(d.bookedAt);
        const bdStr = isNaN(bdt) ? d.bookedAt : bdt.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'})+' '+bdt.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
        html+=`<div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;margin-bottom:10px">
            <div style="font-size:.6rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-calendar-check me-1"></i>Booked On</div>
            <div style="font-weight:700;font-size:.82rem;color:#1a1a1a">${bdStr}</div>
            <div style="font-size:.75rem;color:#888;margin-top:2px">${d.isWalkin==='1'?'<span style="background:#fdf5f5;border:1px solid #e8cece;border-radius:8px;padding:1px 7px;font-size:.68rem;font-weight:700;color:#800000;margin-right:4px">Walk-in</span>':''} by ${d.bookedBy||'—'}</div>
        </div>`;
    }

    // Rejection Reason
    if(d.status==='Rejected' && d.reason){
        html+=`<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:9px 12px;margin-bottom:10px">
            <div style="font-size:.6rem;color:#991b1b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-x-circle-fill me-1"></i>Rejection Reason</div>
            <div style="font-weight:700;font-size:.82rem;color:#991b1b">${d.reason}</div>
        </div>`;
    }

    // Cancellation
    if(d.status==='Cancelled'){
        let iconClass='bi-person-x-fill',badgeColor='#41464b',badgeBg='#f1f3f5';
        if((d.cancelledBy||'').includes('(Admin)')){iconClass='bi-shield-x';badgeColor='#842029';badgeBg='#f8d7da';}
        else if((d.cancelledBy||'').includes('(User)')){iconClass='bi-person-x-fill';badgeColor='#0a3678';badgeBg='#cfe2ff';}
        html+=`<div style="background:#f8f9fb;border:1px solid #e2e8f0;border-radius:10px;padding:9px 12px;margin-bottom:10px">
            <div style="font-size:.6rem;color:#41464b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px"><i class="bi bi-slash-circle-fill me-1"></i>Cancellation Reason</div>
            <div style="font-weight:700;font-size:.82rem;color:#41464b">${d.cancelReason||'No reason provided'}</div>
            ${d.cancelledBy?`<div style="margin-top:6px"><span style="display:inline-flex;align-items:center;gap:5px;background:${badgeBg};border:1px solid ${badgeColor};border-radius:8px;padding:3px 9px;font-size:.72rem;color:${badgeColor}"><i class="bi ${iconClass}"></i>Cancelled by <strong>${d.cancelledBy}</strong></span></div>`:''}
        </div>`;
    }

    document.getElementById('mob_det_body').innerHTML = html;
    mobSchedOpenSheet('mobDetailsSheet');
}

/* ✅ Mobile conflict check — also disables btn when conflict detected */
function mobChkConf(prefix){
    const vEl = document.getElementById('mob_'+prefix+'_veh');
    const dEl = document.getElementById('mob_'+prefix+'_drv');
    const conf = document.getElementById('mob_'+prefix+'_conflict');
    const msg  = document.getElementById('mob_'+prefix+'_conflict_msg');
    const btn  = document.getElementById('mob_'+prefix+'_btn');
    const sid  = prefix==='apr' ? _mobAprSid : _mobChgSid;
    const ds   = prefix==='apr' ? _mobAprDS  : _mobChgDS;
    const de   = prefix==='apr' ? _mobAprDE  : _mobChgDE;
    const ts   = prefix==='apr' ? _mobAprTS  : _mobChgTS;
    const te   = prefix==='apr' ? _mobAprTE  : _mobChgTE;
    let conflictMsg = null;
    if(vEl.value){ const lvl=worstVehicleConflict(vEl.value,ds,de,ts,te,sid); if(lvl) conflictMsg=lvl==='ontrip'?'Vehicle is on trip.':'Vehicle already booked.'; }
    if(!conflictMsg&&dEl.value){ const lvl=worstDriverConflict(dEl.value,ds,de,ts,te,sid); if(lvl) conflictMsg=lvl==='ontrip'?'Driver is on trip.':'Driver already assigned.'; }
    if(conflictMsg){ msg.textContent=conflictMsg; conf.classList.add('show'); if(btn) btn.disabled=true; }
    else { conf.classList.remove('show'); if(btn) btn.disabled=false; }
}

/* Mobile search */
function onMobSchedSearch(inp){
    const q = inp.value.toLowerCase().trim();
    document.getElementById('mobSchedSearchClear').style.display = q ? 'flex' : 'none';
    document.querySelectorAll('.mob-sched-row').forEach(c => {
        c.style.display = (!q || c.dataset.search.includes(q)) ? '' : 'none';
    });
}
function clearMobSchedSearch(){
    const inp=document.getElementById('mobSchedSearch'); inp.value=''; inp.focus(); onMobSchedSearch(inp);
}

/* Mobile add form: date/time sync */
document.getElementById('mob_add_ds').addEventListener('change', function(){
    const de=document.getElementById('mob_add_de');
    if(!de.value||de.value<this.value){ de.value=this.value; de.min=this.value; }
});

/* ══ allSchedules for JS dup check ══ */
const allSchedules = <?= json_encode(array_map(fn($s) => [
    'schedule_id' => $s['schedule_id'],
    'user_id'     => $s['user_id'],
    'date_start'  => $s['date_start'],
    'date_end'    => $s['date_end'],
    'status'      => $s['status'],
], $schedules)) ?>;

const approvedSchedules = <?= json_encode($approvedScheds) ?>;
const tripDoneModal = document.getElementById('tripDoneModal');

/* ── Trip Done (desktop modal) ── */
document.addEventListener('click', function(e){
    const btn = e.target.closest('.btn-tripdone'); if(!btn) return;
    const scheduleId = parseInt(btn.getAttribute('data-schedule-id'), 10);
    if(!scheduleId||scheduleId<=0){ alert('Error reading schedule ID. Please refresh.'); return; }
    tripDoneModal.dataset.currentScheduleId = scheduleId;
    document.getElementById('td_uname').textContent = btn.getAttribute('data-username')||'—';
    document.getElementById('td_dest').textContent  = btn.getAttribute('data-dest')||'—';
    document.getElementById('td_err').classList.add('d-none');
    const now=new Date(), pad=n=>String(n).padStart(2,'0');
    document.getElementById('td_date').value = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
    document.getElementById('td_time').value = pad(now.getHours())+':'+pad(now.getMinutes());
    const cb = document.getElementById('td_confirm');
    cb.disabled=false; cb.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete';
    new bootstrap.Modal(tripDoneModal).show();
});

document.getElementById('td_confirm').addEventListener('click', function(){
    const scheduleId = parseInt(tripDoneModal.dataset.currentScheduleId, 10);
    const arrivedDate = document.getElementById('td_date').value.trim();
    const arrivedTime = document.getElementById('td_time').value.trim();
    const errBox = document.getElementById('td_err');
    errBox.classList.add('d-none');
    if(!scheduleId){ errBox.textContent='Missing schedule ID.'; errBox.classList.remove('d-none'); return; }
    if(!arrivedDate){ errBox.textContent='Please enter arrival date.'; errBox.classList.remove('d-none'); return; }
    if(!arrivedTime){ errBox.textContent='Please enter arrival time.'; errBox.classList.remove('d-none'); return; }
    this.disabled=true; this.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    fetch('complete_trip.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({schedule_id:scheduleId,arrived_date:arrivedDate,arrived_time:arrivedTime}).toString()})
    .then(r=>r.text()).then(raw=>{
        let data; try{data=JSON.parse(raw);}catch(_){
            errBox.innerHTML='<strong>Server error:</strong><br><pre style="font-size:.75rem;white-space:pre-wrap">'+raw.substring(0,500)+'</pre>';
            errBox.classList.remove('d-none'); this.disabled=false; this.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete'; return;
        }
        if(data.ok){ bootstrap.Modal.getInstance(tripDoneModal).hide(); showToast('Trip marked as completed!','success'); setTimeout(()=>location.reload(),800); }
        else{ errBox.textContent=data.msg||'Unknown error.'; errBox.classList.remove('d-none'); this.disabled=false; this.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete'; }
    }).catch(ex=>{ errBox.textContent='Network error: '+ex.message; errBox.classList.remove('d-none'); this.disabled=false; this.innerHTML='<i class="bi bi-flag-fill me-1"></i>Confirm Trip Complete'; });
});

/* ── Reject ── */
document.addEventListener('click', e=>{
    const b = e.target.closest('.btn-reject'); if(!b) return;
    document.getElementById('rej_sid').value  = b.dataset.id;
    document.getElementById('rej_uname').textContent = b.dataset.username;
    document.getElementById('rej_reason').value = '';
    document.getElementById('rej_char').textContent  = '0';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
});
document.getElementById('rej_reason').addEventListener('input', function(){ document.getElementById('rej_char').textContent = this.value.length; });

/* ── Cancel ── */
document.addEventListener('click', e=>{
    const b = e.target.closest('.btn-cancel'); if(!b) return;
    document.getElementById('cancel_sid').value  = b.dataset.id;
    document.getElementById('cancel_uname').textContent  = b.dataset.username||'—';
    document.getElementById('cancel_status_badge').innerHTML =
        b.dataset.status==='Approved'
        ? '<span class="bp bp-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>'
        : '<span class="bp bp-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>';
    document.getElementById('cancel_reason_input').value = '';
    document.getElementById('cancel_char').textContent = '0';
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
});
document.getElementById('cancel_reason_input').addEventListener('input', function(){ document.getElementById('cancel_char').textContent = this.value.length; });

/* ── Reschedule ── */
document.addEventListener('click', e=>{
    const b = e.target.closest('.btn-reschedule'); if(!b) return;
    const d = b.dataset;
    document.getElementById('res_sid').value  = d.id;
    document.getElementById('res_ds').value   = d.ds;
    document.getElementById('res_de').value   = d.de;
    document.getElementById('res_ts').value   = d.ts;
    document.getElementById('res_te').value   = d.te;
    document.getElementById('res_dest').value = d.dest;
    document.getElementById('res_purp').value = d.purp;
    new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
});
function fmtDate(d) {
    if (!d || d === '—') return '—';
    const dt = new Date(d + 'T00:00:00');
    return isNaN(dt) ? d : dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
/* ── View Details ── */
function fmtTime(t){
    if(!t||t==='--') return '--';
    const parts=t.split(':'); let h=parseInt(parts[0]),m=parts[1]||'00';
    const ap=h>=12?'PM':'AM'; h=h%12||12;
    return h+':'+m+' '+ap;
}
document.addEventListener('click', e=>{
    const b = e.target.closest('.btn-viewdetails'); if(!b) return;
    const d = b.dataset;
    const statusIcons={Completed:'bi-check2-all',Rejected:'bi-x-circle-fill',Cancelled:'bi-slash-circle-fill',Approved:'bi-check-circle-fill',OnTrip:'bi-truck',Pending:'bi-hourglass-split'};
    const st = d.status||'';
    document.getElementById('det_status_line').innerHTML =
        `<span style="background:rgba(255,255,255,.2);padding:1px 8px;border-radius:10px;font-size:.72rem"><i class="bi ${statusIcons[st]||'bi-bell'} me-1"></i>${st}</span>`;
    document.getElementById('det_uname').textContent  = d.username||'—';
    document.getElementById('det_office').textContent = d.office||'—';
    document.getElementById('det_dept').textContent   = d.dept||'—';
    document.getElementById('det_dest').textContent   = d.dest||'—';
    document.getElementById('det_purp').textContent   = d.purp||'—';
  document.getElementById('det_dates').textContent  = (d.ds===d.de) ? fmtDate(d.ds) : fmtDate(d.ds)+' → '+fmtDate(d.de);
    document.getElementById('det_time').textContent   = fmtTime(d.ts)+' – '+fmtTime(d.te);
    document.getElementById('det_veh').textContent    = d.vehicle||'—';
    document.getElementById('det_drv').textContent    = d.driver||'—';
    const pax = parseInt(d.pax||'1',10);
    document.getElementById('det_pax').textContent = pax + ' passenger' + (pax!==1?'s':'');
    const arrWrap = document.getElementById('det_arrival_wrap');
   if(d.arrived){
    const arrDt = new Date(d.arrived.replace(' ','T'));
    document.getElementById('det_arrived').textContent = isNaN(arrDt)
        ? d.arrived
        : arrDt.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
          + ' ' + arrDt.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
    arrWrap.style.display='';
}
    else { arrWrap.style.display='none'; }
    const ticketPill = document.getElementById('det_ticket_pill');
    if(d.ticket){
        ticketPill.innerHTML=`<span style="display:inline-flex;align-items:center;gap:6px;background:#d1e7dd;border:1px solid #0f5132;border-radius:20px;padding:4px 14px;font-size:.8rem;font-weight:700;color:#0f5132"><i class="bi bi-ticket-perforated-fill"></i>Trip Ticket: ${d.ticket}</span>`;
    } else {
        ticketPill.innerHTML = (st==='Approved'||st==='OnTrip'||st==='Completed')
            ? `<span style="display:inline-flex;align-items:center;gap:6px;background:#f8f9fb;border:1px dashed #ccc;border-radius:20px;padding:4px 14px;font-size:.8rem;color:#999"><i class="bi bi-ticket-perforated"></i>No ticket yet</span>`
            : '';
    }
    const bookedAt=d.bookedAt||'', bookedBy=d.bookedBy||'', isWalkIn=d.isWalkin==='1';
    const bookedOnEl=document.getElementById('det_booked_on'), bookedByEl=document.getElementById('det_booked_by');
    if(bookedAt){
        const dt=new Date(bookedAt);
        bookedOnEl.textContent=dt.toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'})+' '+dt.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
        bookedByEl.innerHTML=(isWalkIn?'<span class="walkin-tag"><i class="bi bi-person-walking"></i>Walk-in</span> ':'')+' by '+bookedBy;
    } else { bookedOnEl.textContent='Not recorded'; bookedByEl.textContent=''; }
    const rw=document.getElementById('det_reason_wrap');
    if(d.reason&&st==='Rejected'){ document.getElementById('det_reason').textContent=d.reason; rw.style.display=''; } else rw.style.display='none';
    const cw = document.getElementById('det_cancel_wrap');
    if(st==='Cancelled'){
        cw.style.display='';
        document.getElementById('det_cancel').textContent = d.cancelReason||'No reason provided';
        const cancelledBy=(d.cancelledBy||'').trim();
        let iconClass='bi-person-x-fill',badgeColor='#41464b',badgeBg='#f1f3f5';
        if(cancelledBy.includes('(Admin)')){iconClass='bi-shield-x';badgeColor='#842029';badgeBg='#f8d7da';}
        else if(cancelledBy.includes('(User)')){iconClass='bi-person-x-fill';badgeColor='#0a3678';badgeBg='#cfe2ff';}
        document.getElementById('det_cancelled_by').innerHTML = cancelledBy
            ? `<span class="cancelled-by-tag" style="background:${badgeBg};border-color:${badgeColor};color:${badgeColor}"><i class="bi ${iconClass}"></i>Cancelled by <strong>${cancelledBy}</strong></span>`
            : `<span class="cancelled-by-tag" style="color:#999;font-style:italic"><i class="bi bi-question-circle"></i>Cancelled by not recorded</span>`;
    } else { cw.style.display='none'; }
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
    // On mobile, use the sheet instead
    if(window.innerWidth <= 900){
        const d2 = {
            status: d.status, ticket: d.ticket, username: d.username,
            office: d.office, dept: d.dept, dest: d.dest, purp: d.purp,
            ds: d.ds, de: d.de, ts: d.ts, te: d.te,
            vehicle: d.vehicle, driver: d.driver,
            arrived: d.arrived, bookedAt: d.bookedAt, bookedBy: d.bookedBy,
            isWalkin: d.isWalkin, pax: d.pax,
            reason: d.reason, cancelReason: d.cancelReason, cancelledBy: d.cancelledBy
        };
        const statusIcons2={Completed:'bi-check2-all',Rejected:'bi-x-circle-fill',Cancelled:'bi-slash-circle-fill',Approved:'bi-check-circle-fill',OnTrip:'bi-truck',Pending:'bi-hourglass-split'};
        document.getElementById('mob_det_status_line').innerHTML =
            `<i class="bi ${statusIcons2[d.status]||'bi-bell'} me-1"></i>${d.status}`;
        mobViewDetails(d2);
        return;
    }
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
});
/* ══ MOBILE APPROVE CUSTOM SELECT ══ */
function toggleMobAprDrop(which){
    const drop = document.getElementById('mob_apr_'+which+'_dropdown');
    const trig = document.getElementById('mob_apr_'+which+'_trigger');
    const isOpen = drop.classList.contains('open');
    ['drv','veh'].forEach(w=>{
        document.getElementById('mob_apr_'+w+'_dropdown').classList.remove('open');
        document.getElementById('mob_apr_'+w+'_trigger').classList.remove('open');
    });
    if(!isOpen){ drop.classList.add('open'); trig.classList.add('open'); }
}
function filterMobAprDrop(which, q){
    document.querySelectorAll('#mob_apr_'+which+'_opts .cs-option').forEach(o=>{
        o.style.display = (!q||(o.dataset.label+' '+o.dataset.sub).toLowerCase().includes(q.toLowerCase())) ? '' : 'none';
    });
}
function mobAprRenderTrigger(which, label, sub, icon, type){
    document.getElementById('mob_apr_'+which+'_trigger').innerHTML =
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:${type==='drv'?'#fdf5f5':'#eff6ff'};color:${type==='drv'?'#800000':'#1d4ed8'};display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0">${icon}</div>
           <div style="min-width:0"><div style="font-weight:600;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${label}</div><div style="font-size:.72rem;color:#888">${sub}</div></div>
         </div>`;
    document.getElementById('mob_apr_'+which+'_trigger').classList.remove('open');
    document.getElementById('mob_apr_'+which+'_dropdown').classList.remove('open');
}
function mobAprSelectDriver(el){
    document.querySelectorAll('#mob_apr_drv_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('mob_apr_driver_hidden').value = el.dataset.value;
    mobAprRenderTrigger('drv', el.dataset.label, el.dataset.sub||'Driver', el.dataset.ini||el.dataset.label[0], 'drv');
    const lvl = worstDriverConflict(el.dataset.value, _mobAprDS, _mobAprDE, _mobAprTS, _mobAprTE, _mobAprSid);
    document.getElementById('mob_apr_drv_msg').innerHTML = lvl
        ? driverAvailBadge(el.dataset.value, _mobAprDS, _mobAprDE, _mobAprTS, _mobAprTE, _mobAprSid)
        : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    const dVid = parseInt(el.dataset.defaultVehicle||0);
    const note = document.getElementById('mob_apr_autofill_note');
    if(dVid > 0){
        const vEl = document.querySelector(`#mob_apr_veh_opts .cs-option[data-value="${dVid}"]`);
        if(vEl){ mobAprSelectVehicle(vEl); note.style.display=''; }
    } else { note.style.display='none'; }
    mobAprCheckConflict(); mobAprUpdateBtn();
}
function mobAprClearVehicle(e){
    e.preventDefault();
    document.getElementById('mob_apr_vehicle_hidden').value = '';
    document.getElementById('mob_apr_veh_trigger').innerHTML = '<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('mob_apr_veh_msg').innerHTML = '';
    document.querySelectorAll('#mob_apr_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    document.getElementById('mob_apr_autofill_note').innerHTML =
        '<i class="bi bi-info-circle me-1"></i>Select a vehicle manually below.';
    mobAprCheckConflict(); mobAprUpdateBtn();
    toggleMobAprDrop('veh');
}
function mobAprSelectVehicle(el){
    document.querySelectorAll('#mob_apr_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('mob_apr_vehicle_hidden').value = el.dataset.value;
    mobAprRenderTrigger('veh', el.dataset.label, el.dataset.sub||'', '<i class="bi bi-truck-front"></i>', 'veh');
    const lvl = worstVehicleConflict(el.dataset.value, _mobAprDS, _mobAprDE, _mobAprTS, _mobAprTE, _mobAprSid);
    document.getElementById('mob_apr_veh_msg').innerHTML = lvl
        ? vehicleAvailBadge(el.dataset.value, _mobAprDS, _mobAprDE, _mobAprTS, _mobAprTE, _mobAprSid)
        : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    mobAprCheckConflict(); mobAprUpdateBtn();
}
function mobAprCheckConflict(){
    const vid = document.getElementById('mob_apr_vehicle_hidden').value;
    const did = document.getElementById('mob_apr_driver_hidden').value;
    const conf = document.getElementById('mob_apr_conflict');
    const msg  = document.getElementById('mob_apr_conflict_msg');
    let conflictMsg = null;
    if(vid){ const lvl=worstVehicleConflict(vid,_mobAprDS,_mobAprDE,_mobAprTS,_mobAprTE,_mobAprSid); if(lvl) conflictMsg=lvl==='ontrip'?'Vehicle is currently on trip.':'Vehicle already booked on these dates.'; }
    if(!conflictMsg&&did){ const lvl=worstDriverConflict(did,_mobAprDS,_mobAprDE,_mobAprTS,_mobAprTE,_mobAprSid); if(lvl) conflictMsg=lvl==='ontrip'?'Driver is currently on trip.':'Driver already booked on these dates.'; }
    if(conflictMsg){ msg.textContent=conflictMsg; conf.classList.add('show'); }
    else { conf.classList.remove('show'); }
}
function mobAprUpdateBtn(){
    const vid = document.getElementById('mob_apr_vehicle_hidden').value;
    const did = document.getElementById('mob_apr_driver_hidden').value;
    const conf = document.getElementById('mob_apr_conflict').classList.contains('show');
    document.getElementById('mob_apr_btn').disabled = !(vid && did && !conf);
}
function mobAprMarkOptions(){
    document.querySelectorAll('#mob_apr_drv_opts .cs-option').forEach(o=>{
        const lvl = worstDriverConflict(o.dataset.value,_mobAprDS,_mobAprDE,_mobAprTS,_mobAprTE,_mobAprSid);
        const badge = o.querySelector('.cs-opt-badge');
        if(lvl){
            o.style.opacity='.5';
            if(badge){ badge.textContent=lvl==='ontrip'?'ON TRIP':'BOOKED'; badge.className='cs-opt-badge badge-busy'; }
        } else {
            o.style.opacity='';
            if(badge&&badge.classList.contains('badge-busy')){
                badge.textContent = parseInt(o.dataset.defaultVehicle||0)>0?'Has vehicle':'No vehicle';
                badge.className = 'cs-opt-badge '+(parseInt(o.dataset.defaultVehicle||0)>0?'badge-assigned':'badge-unassigned');
            }
        }
    });
    document.querySelectorAll('#mob_apr_veh_opts .cs-option').forEach(o=>{
        const lvl = worstVehicleConflict(o.dataset.value,_mobAprDS,_mobAprDE,_mobAprTS,_mobAprTE,_mobAprSid);
        const badge = o.querySelector('.cs-opt-badge');
        if(lvl){ o.style.opacity='.5'; if(badge){badge.textContent=lvl==='ontrip'?'ON TRIP':'BOOKED';badge.className='cs-opt-badge badge-busy';} }
        else { o.style.opacity=''; }
    });
}

/* ══ FIXED mobApprove() ══ */
function mobApprove(d){
    _mobAprSid = d.id;
    _mobAprDS  = d.ds;
    _mobAprDE  = d.de;
    _mobAprTS  = d.ts;
    _mobAprTE  = d.te;
    _mobAprUid = parseInt(d.uid || 0);

    document.getElementById('mob_apr_sid').value = d.id;
    document.getElementById('mob_apr_vehicle_hidden').value = '';
    document.getElementById('mob_apr_driver_hidden').value  = '';

    document.getElementById('mob_apr_info').innerHTML =
        `<div class="sheet-info-row"><span class="sheet-info-lbl">Requestor</span><strong>${d.username}</strong></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Date</span><span>${d.ds===d.de?fmtDate(d.ds):fmtDate(d.ds)+' → '+fmtDate(d.de)}</span></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Time</span><span>${d.ts||'--'} – ${d.te||'--'}</span></div>
         <div class="sheet-info-row"><span class="sheet-info-lbl">Passengers</span><span>${d.pax}</span></div>`;

    document.getElementById('mob_apr_drv_trigger').innerHTML = '<span class="custom-select-placeholder">— Select Driver —</span>';
    document.getElementById('mob_apr_veh_trigger').innerHTML = '<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('mob_apr_drv_msg').innerHTML = '';
    document.getElementById('mob_apr_veh_msg').innerHTML = '';
    document.getElementById('mob_apr_conflict').classList.remove('show');
    document.getElementById('mob_apr_autofill_note').style.display = 'none';
    document.getElementById('mob_apr_btn').disabled = true;

    document.querySelectorAll('#mob_apr_drv_opts .cs-option, #mob_apr_veh_opts .cs-option')
        .forEach(o=>{ o.classList.remove('selected'); o.style.opacity=''; });

    /* Duplicate booking check */
    const hasDup = allSchedules.some(s =>
        parseInt(s.user_id) === _mobAprUid &&
        String(s.schedule_id) !== String(d.id) &&
        ['Approved','OnTrip'].includes(s.status) &&
        s.date_start <= d.de && s.date_end >= d.ds
    );
    if(hasDup){
        document.getElementById('mob_apr_conflict_msg').textContent =
            'Warning: This requestor already has an approved trip on the same date(s).';
        document.getElementById('mob_apr_conflict').classList.add('show');
    }

    mobAprMarkOptions();
    mobSchedOpenSheet('mobApproveSheet');
}

/* ══ MOBILE CHANGE ASSIGNMENT CUSTOM SELECT ══ */
function toggleMobChgDrop(which){
    const drop = document.getElementById('mob_chg_'+which+'_dropdown');
    const trig = document.getElementById('mob_chg_'+which+'_trigger');
    const isOpen = drop.classList.contains('open');
    ['drv','veh'].forEach(w=>{
        document.getElementById('mob_chg_'+w+'_dropdown').classList.remove('open');
        document.getElementById('mob_chg_'+w+'_trigger').classList.remove('open');
    });
    if(!isOpen){ drop.classList.add('open'); trig.classList.add('open'); }
}
function filterMobChgDrop(which, q){
    document.querySelectorAll('#mob_chg_'+which+'_opts .cs-option').forEach(o=>{
        o.style.display = (!q||(o.dataset.label+' '+o.dataset.sub).toLowerCase().includes(q.toLowerCase())) ? '' : 'none';
    });
}
function mobChgRenderTrigger(which, label, sub, icon, type){
    document.getElementById('mob_chg_'+which+'_trigger').innerHTML =
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:${type==='drv'?'#fdf5f5':'#eff6ff'};color:${type==='drv'?'#800000':'#1d4ed8'};display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0">${icon}</div>
           <div style="min-width:0"><div style="font-weight:600;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${label}</div><div style="font-size:.72rem;color:#888">${sub}</div></div>
         </div>`;
    document.getElementById('mob_chg_'+which+'_trigger').classList.remove('open');
    document.getElementById('mob_chg_'+which+'_dropdown').classList.remove('open');
}
function mobChgSelectDriver(el){
    document.querySelectorAll('#mob_chg_drv_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('mob_chg_driver_hidden').value = el.dataset.value;
    mobChgRenderTrigger('drv', el.dataset.label, el.dataset.sub||'Driver', el.dataset.ini||el.dataset.label[0], 'drv');
    const lvl = worstDriverConflict(el.dataset.value, _mobChgDS, _mobChgDE, _mobChgTS, _mobChgTE, _mobChgSid);
    document.getElementById('mob_chg_drv_msg').innerHTML = lvl
        ? driverAvailBadge(el.dataset.value, _mobChgDS, _mobChgDE, _mobChgTS, _mobChgTE, _mobChgSid)
        : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    const dVid = parseInt(el.dataset.defaultVehicle||0);
    const note = document.getElementById('mob_chg_autofill_note');
    if(dVid > 0){
        const vEl = document.querySelector(`#mob_chg_veh_opts .cs-option[data-value="${dVid}"]`);
        if(vEl){ mobChgSelectVehicle(vEl); note.style.display=''; }
    } else { if(note) note.style.display='none'; }
    mobChgCheckConflict(); mobChgUpdateBtn();
}
function mobChgClearVehicle(e){
    e.preventDefault();
    document.getElementById('mob_chg_vehicle_hidden').value = '';
    document.getElementById('mob_chg_veh_trigger').innerHTML = '<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('mob_chg_veh_msg').innerHTML = '';
    document.querySelectorAll('#mob_chg_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    const note = document.getElementById('mob_chg_autofill_note');
    if(note) note.innerHTML = '<i class="bi bi-info-circle me-1"></i>Select a vehicle manually below.';
    mobChgCheckConflict(); mobChgUpdateBtn();
    toggleMobChgDrop('veh');
}
function mobChgSelectVehicle(el){
    document.querySelectorAll('#mob_chg_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('mob_chg_vehicle_hidden').value = el.dataset.value;
    mobChgRenderTrigger('veh', el.dataset.label, el.dataset.sub||'', '<i class="bi bi-truck-front"></i>', 'veh');
    const lvl = worstVehicleConflict(el.dataset.value, _mobChgDS, _mobChgDE, _mobChgTS, _mobChgTE, _mobChgSid);
    document.getElementById('mob_chg_veh_msg').innerHTML = lvl
        ? vehicleAvailBadge(el.dataset.value, _mobChgDS, _mobChgDE, _mobChgTS, _mobChgTE, _mobChgSid)
        : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    mobChgCheckConflict(); mobChgUpdateBtn();
}
function mobChgCheckConflict(){
    const vid = document.getElementById('mob_chg_vehicle_hidden').value;
    const did = document.getElementById('mob_chg_driver_hidden').value;
    const conf = document.getElementById('mob_chg_conflict');
    const msg  = document.getElementById('mob_chg_conflict_msg');
    let conflictMsg = null;
    if(vid){ const lvl=worstVehicleConflict(vid,_mobChgDS,_mobChgDE,_mobChgTS,_mobChgTE,_mobChgSid); if(lvl) conflictMsg=lvl==='ontrip'?'Vehicle is on trip.':'Vehicle already booked.'; }
    if(!conflictMsg&&did){ const lvl=worstDriverConflict(did,_mobChgDS,_mobChgDE,_mobChgTS,_mobChgTE,_mobChgSid); if(lvl) conflictMsg=lvl==='ontrip'?'Driver is on trip.':'Driver already booked.'; }
    if(conflictMsg){ msg.textContent=conflictMsg; conf.classList.add('show'); }
    else { conf.classList.remove('show'); }
}
function mobChgUpdateBtn(){
    const vid = document.getElementById('mob_chg_vehicle_hidden').value;
    const did = document.getElementById('mob_chg_driver_hidden').value;
    const conf = document.getElementById('mob_chg_conflict').classList.contains('show');
    document.getElementById('mob_chg_btn').disabled = !(vid && did && !conf);
}

/* ══ Close mobile dropdowns on outside tap ══ */
document.getElementById('mobSchedBackdrop').addEventListener('click', mobSchedCloseAll);
document.addEventListener('click', e=>{
    if(!e.target.closest('.custom-select-wrap')){
        ['mob_apr','mob_chg'].forEach(p=>{
            ['drv','veh'].forEach(w=>{
                document.getElementById(p+'_'+w+'_dropdown')?.classList.remove('open');
                document.getElementById(p+'_'+w+'_trigger')?.classList.remove('open');
            });
        });
    }
});
/* ══ CONFLICT DETECTION ══ */
function timeToMins(t){ if(!t) return 0; const[h,m]=t.split(':').map(Number); return h*60+m; }
function rangesOverlap(ds1,de1,ts1,te1,ds2,de2,ts2,te2){
    if(ds1>de2||ds2>de1) return false;
    if(ds1===ds2 && de1===de2 && ds1===de1){
        const s1=timeToMins(ts1||'00:00'), e1=timeToMins(te1||'23:59'),
              s2=timeToMins(ts2||'00:00'), e2=timeToMins(te2||'23:59');
        if(e1<s2||e2<s1) return false;
    }
    return true;
}
function isGraceExpired(s){
    if(s.status!=='OnTrip') return false;
    if(!s.grace_until) return false;
    return new Date(s.grace_until.replace(' ','T')) <= new Date();
}
function conflictLevel(s, idField, checkId, ds, de, ts, te, ex){
    if(String(s[idField])!==String(checkId)) return null;
    if(String(s.schedule_id)===String(ex))   return null;
    if(isGraceExpired(s))                    return null;
    if(rangesOverlap(ds,de,ts,te,s.date_start,s.date_end,s.time_start,s.time_end)){
        if(s.status==='OnTrip') return 'ontrip';
        return 'booked';
    }
    return null;
}
function worstVehicleConflict(vid, ds, de, ts, te, ex){
    let worst = null;
    for(const s of approvedSchedules){
        const lvl = conflictLevel(s,'vehicle_id',vid,ds,de,ts,te,ex);
        if(lvl==='ontrip') return 'ontrip';
        if(lvl==='booked') worst = 'booked';
    }
    return worst;
}
function worstDriverConflict(did, ds, de, ts, te, ex){
    let worst = null;
    for(const s of approvedSchedules){
        const lvl = conflictLevel(s,'driver_id',did,ds,de,ts,te,ex);
        if(lvl==='ontrip') return 'ontrip';
        if(lvl==='booked') worst = 'booked';
    }
    return worst;
}
function vehicleAvailBadge(vid, ds, de, ts, te, ex){
    const lvl = worstVehicleConflict(vid,ds,de,ts,te,ex);
    if(!lvl) return '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    if(lvl==='ontrip') return '<span class="avail-badge avail-no"><i class="bi bi-truck me-1"></i>Currently on trip</span>';
    return '<span class="avail-badge avail-no"><i class="bi bi-x-circle me-1"></i>Already booked</span>';
}
function driverAvailBadge(did, ds, de, ts, te, ex){
    const lvl = worstDriverConflict(did,ds,de,ts,te,ex);
    if(!lvl) return '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    if(lvl==='ontrip') return '<span class="avail-badge avail-no"><i class="bi bi-truck me-1"></i>Currently on trip</span>';
    return '<span class="avail-badge avail-no"><i class="bi bi-x-circle me-1"></i>Already assigned</span>';
}
function markConflicts(vS, dS, ds, de, ts, te, ex){
    [...document.getElementById(vS).options].forEach(o=>{
        if(!o.value) return;
        o.text = o.text.replace(/\s*(✓ Available|\[BOOKED\]|\[ON TRIP\])/g, '').trim();
        const lvl = worstVehicleConflict(o.value,ds,de,ts,te,ex);
        const bad = !!lvl;
        o.disabled    = bad;
        o.style.color = bad ? '#842029' : '#0f5132';
        o.text += bad ? (lvl==='ontrip' ? ' [ON TRIP]' : ' [BOOKED]') : ' ✓ Available';
    });
    [...document.getElementById(dS).options].forEach(o=>{
        if(!o.value) return;
        o.text = o.text.replace(/\s*(✓ Available|\[BOOKED\]|\[ON TRIP\])/g, '').trim();
        const lvl = worstDriverConflict(o.value,ds,de,ts,te,ex);
        const bad = !!lvl;
        o.disabled    = bad;
        o.style.color = bad ? '#842029' : '#0f5132';
        o.text += bad ? (lvl==='ontrip' ? ' [ON TRIP]' : ' [BOOKED]') : ' ✓ Available';
    });
}

/* ── Driver-Vehicle Map ── */
const driverVehicleMap = <?= $driverVehicleMapJson ?>;

/* ══ APPROVE Custom Select Helpers ══ */
let aprDS='',aprDE='',aprTS='',aprTE='';

function toggleAprDrop(which){
    const drop=document.getElementById('apr_'+which+'_dropdown');
    const trig=document.getElementById('apr_'+which+'_trigger');
    const isOpen=drop.classList.contains('open');
    ['drv','veh'].forEach(w=>{ document.getElementById('apr_'+w+'_dropdown').classList.remove('open'); document.getElementById('apr_'+w+'_trigger').classList.remove('open'); });
    if(!isOpen){ drop.classList.add('open'); trig.classList.add('open'); }
}
function filterAprDrop(which,q){
    document.querySelectorAll('#apr_'+which+'_opts .cs-option').forEach(o=>{
        o.style.display=(!q||(o.dataset.label+' '+o.dataset.sub).toLowerCase().includes(q.toLowerCase()))?'':'none';
    });
}
function aprRenderTrigger(which, label, sub, icon, color){
    document.getElementById('apr_'+which+'_trigger').innerHTML=
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:${color==='drv'?'#fdf5f5':'#eff6ff'};color:${color==='drv'?'#800000':'#1d4ed8'};display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0">${icon}</div>
           <div style="min-width:0"><div style="font-weight:600;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${label}</div><div style="font-size:.72rem;color:#888">${sub}</div></div>
         </div>`;
    document.getElementById('apr_'+which+'_trigger').classList.remove('open');
    document.getElementById('apr_'+which+'_dropdown').classList.remove('open');
}
function aprSelectDriver(el){
    document.querySelectorAll('#apr_drv_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('apr_driver_hidden').value = el.dataset.value;
    aprRenderTrigger('drv', el.dataset.label, el.dataset.sub||'Driver', el.dataset.ini||el.dataset.label[0], 'drv');
    const lvl=worstDriverConflict(el.dataset.value,aprDS,aprDE,aprTS,aprTE,document.getElementById('apr_sid').value);
    document.getElementById('apr_drv_msg').innerHTML = lvl ? driverAvailBadge(el.dataset.value,aprDS,aprDE,aprTS,aprTE,document.getElementById('apr_sid').value) : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    const dVid = parseInt(el.dataset.defaultVehicle||0);
    const autofillNote = document.getElementById('apr_autofill_note');
    if(dVid>0){
        const vEl=document.querySelector(`#apr_veh_opts .cs-option[data-value="${dVid}"]`);
        if(vEl){
            aprSelectVehicle(vEl);
            autofillNote.style.display='';
            autofillNote.innerHTML=`<i class="bi bi-stars me-1"></i>Vehicle auto-filled from driver's default assignment. <a href="#" style="color:#1d4ed8;font-weight:700;text-decoration:underline" onclick="aprClearVehicle(event)">Assign a different vehicle</a>`;
        }
    } else {
        autofillNote.style.display='none';
    }
    aprCheckConflict(); aprUpdateSaveBtn();
}
function aprClearVehicle(e){
    e.preventDefault();
    document.getElementById('apr_vehicle_hidden').value='';
    document.getElementById('apr_veh_trigger').innerHTML='<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('apr_veh_msg').innerHTML='';
    document.querySelectorAll('#apr_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    document.getElementById('apr_autofill_note').innerHTML=`<i class="bi bi-info-circle me-1"></i>Select a vehicle manually below.`;
    aprCheckConflict(); aprUpdateSaveBtn();
    toggleAprDrop('veh');
}
function aprSelectVehicle(el){
    document.querySelectorAll('#apr_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('apr_vehicle_hidden').value = el.dataset.value;
    aprRenderTrigger('veh', el.dataset.label, el.dataset.sub||'', '<i class="bi bi-truck-front"></i>', 'veh');
    const lvl=worstVehicleConflict(el.dataset.value,aprDS,aprDE,aprTS,aprTE,document.getElementById('apr_sid').value);
    document.getElementById('apr_veh_msg').innerHTML = lvl ? vehicleAvailBadge(el.dataset.value,aprDS,aprDE,aprTS,aprTE,document.getElementById('apr_sid').value) : '<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    aprCheckConflict(); aprUpdateSaveBtn();
}
function aprCheckConflict(){
    const sid=document.getElementById('apr_sid').value;
    const vid=document.getElementById('apr_vehicle_hidden').value;
    const did=document.getElementById('apr_driver_hidden').value;
    const al=document.getElementById('apr_conflict'), ml=document.getElementById('apr_conflict_msg');
    let msg=null;
    if(vid){const lvl=worstVehicleConflict(vid,aprDS,aprDE,aprTS,aprTE,sid);if(lvl)msg=lvl==='ontrip'?'Vehicle is currently on trip.':'Vehicle is already booked on these dates.';}
    if(!msg&&did){const lvl=worstDriverConflict(did,aprDS,aprDE,aprTS,aprTE,sid);if(lvl)msg=lvl==='ontrip'?'Driver is currently on trip.':'Driver is already booked on these dates.';}
    if(msg){ml.textContent=msg;al.classList.add('show');document.getElementById('apr_save_btn').disabled=true;}
    else{al.classList.remove('show');}
}
function aprUpdateSaveBtn(){
    const vid=document.getElementById('apr_vehicle_hidden').value;
    const did=document.getElementById('apr_driver_hidden').value;
    const conf=document.getElementById('apr_conflict').classList.contains('show');
    document.getElementById('apr_save_btn').disabled=!(vid&&did&&!conf);
}
function aprMarkOptions(){
    const sid=document.getElementById('apr_sid').value;
    document.querySelectorAll('#apr_drv_opts .cs-option').forEach(o=>{
        const lvl=worstDriverConflict(o.dataset.value,aprDS,aprDE,aprTS,aprTE,sid);
        const badge=o.querySelector('.cs-opt-badge');
        if(lvl){o.style.opacity='.5';if(badge){badge.textContent=lvl==='ontrip'?'ON TRIP':'BOOKED';badge.className='cs-opt-badge badge-busy';}}
        else{o.style.opacity='';if(badge&&badge.classList.contains('badge-busy')){badge.textContent=o.dataset.defaultVehicle>0?'Has vehicle':'No vehicle';badge.className='cs-opt-badge '+(o.dataset.defaultVehicle>0?'badge-assigned':'badge-unassigned');}}
    });
    document.querySelectorAll('#apr_veh_opts .cs-option').forEach(o=>{
        const lvl=worstVehicleConflict(o.dataset.value,aprDS,aprDE,aprTS,aprTE,sid);
        const badge=o.querySelector('.cs-opt-badge');
        if(lvl){o.style.opacity='.5';if(badge){badge.textContent=lvl==='ontrip'?'ON TRIP':'BOOKED';badge.className='cs-opt-badge badge-busy';}}
        else{o.style.opacity='';}
    });
}

/* ══ APPROVE button click (desktop) ══ */
document.addEventListener('click', e=>{
    const b=e.target.closest('.btn-approve'); if(!b) return;
    const d=b.dataset; aprDS=d.ds; aprDE=d.de; aprTS=d.ts; aprTE=d.te;
    document.getElementById('apr_sid').value=d.id;
    document.getElementById('apr_vehicle_hidden').value='';
    document.getElementById('apr_driver_hidden').value='';
    document.getElementById('apr_uname').textContent=d.username;
    document.getElementById('apr_dates').textContent=d.ds===d.de?d.ds:d.ds+' → '+d.de;
    document.getElementById('apr_time').textContent=(d.ts||'--')+' – '+(d.te||'--');
    document.getElementById('apr_pax').textContent=d.pax?d.pax+' passenger'+(parseInt(d.pax)>1?'s':''):'—';
    document.getElementById('apr_drv_trigger').innerHTML='<span class="custom-select-placeholder">— Select Driver —</span>';
    document.getElementById('apr_veh_trigger').innerHTML='<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('apr_drv_msg').innerHTML='';
    document.getElementById('apr_veh_msg').innerHTML='';
    document.getElementById('apr_conflict').classList.remove('show');
    document.getElementById('apr_autofill_note').style.display='none';
    document.getElementById('apr_save_btn').disabled=true;
    document.querySelectorAll('#apr_drv_opts .cs-option,#apr_veh_opts .cs-option').forEach(o=>{o.classList.remove('selected');o.style.opacity='';});
    const userId=parseInt(d.uid||0);
    const hasDup=allSchedules.some(s=>parseInt(s.user_id)===userId&&String(s.schedule_id)!==String(d.id)&&['Approved','OnTrip'].includes(s.status)&&s.date_start<=d.de&&s.date_end>=d.ds);
    if(hasDup){document.getElementById('apr_conflict_msg').textContent='Warning: This requestor already has an approved trip on the same date(s).';document.getElementById('apr_conflict').classList.add('show');document.getElementById('apr_save_btn').disabled=true;}
    aprMarkOptions();
    new bootstrap.Modal(document.getElementById('approveModal')).show();
});

/* ══ CHANGE ASSIGNMENT Custom Select Helpers ══ */
let chgDS='',chgDE='',chgTS='',chgTE='',chgId=0;

function toggleChgDrop(which){
    const drop=document.getElementById('chg_'+which+'_dropdown');
    const trig=document.getElementById('chg_'+which+'_trigger');
    const isOpen=drop.classList.contains('open');
    ['drv','veh'].forEach(w=>{ document.getElementById('chg_'+w+'_dropdown').classList.remove('open'); document.getElementById('chg_'+w+'_trigger').classList.remove('open'); });
    if(!isOpen){drop.classList.add('open');trig.classList.add('open');}
}
function filterChgDrop(which,q){
    document.querySelectorAll('#chg_'+which+'_opts .cs-option').forEach(o=>{
        o.style.display=(!q||(o.dataset.label+' '+o.dataset.sub).toLowerCase().includes(q.toLowerCase()))?'':'none';
    });
}
function chgRenderTrigger(which,label,sub,icon,type){
    document.getElementById('chg_'+which+'_trigger').innerHTML=
        `<div style="display:flex;align-items:center;gap:9px;flex:1">
           <div style="width:30px;height:30px;border-radius:8px;background:${type==='drv'?'#fdf5f5':'#eff6ff'};color:${type==='drv'?'#800000':'#1d4ed8'};display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0">${icon}</div>
           <div style="min-width:0"><div style="font-weight:600;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${label}</div><div style="font-size:.72rem;color:#888">${sub}</div></div>
         </div>`;
    document.getElementById('chg_'+which+'_trigger').classList.remove('open');
    document.getElementById('chg_'+which+'_dropdown').classList.remove('open');
}
function chgSelectDriver(el){
    document.querySelectorAll('#chg_drv_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('chg_driver_hidden').value=el.dataset.value;
    chgRenderTrigger('drv',el.dataset.label,el.dataset.sub||'Driver',el.dataset.ini||el.dataset.label[0],'drv');
    const lvl=worstDriverConflict(el.dataset.value,chgDS,chgDE,chgTS,chgTE,chgId);
    document.getElementById('chg_drv_msg').innerHTML=lvl?driverAvailBadge(el.dataset.value,chgDS,chgDE,chgTS,chgTE,chgId):'<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    const dVid=parseInt(el.dataset.defaultVehicle||0);
    const chgNote = document.getElementById('chg_autofill_note');
    if(dVid>0){
        const vEl=document.querySelector(`#chg_veh_opts .cs-option[data-value="${dVid}"]`);
        if(vEl){
            chgSelectVehicle(vEl);
            if(chgNote){
                chgNote.style.display='';
                chgNote.innerHTML=`<i class="bi bi-stars me-1"></i>Vehicle auto-filled from driver's default assignment. <a href="#" style="color:#1d4ed8;font-weight:700;text-decoration:underline" onclick="chgClearVehicle(event)">Assign a different vehicle</a>`;
            }
        }
    } else {
        if(chgNote) chgNote.style.display='none';
    }
    chgCheckConflict(); chgUpdateSaveBtn();
}
function chgClearVehicle(e){
    e.preventDefault();
    document.getElementById('chg_vehicle_hidden').value='';
    document.getElementById('chg_veh_trigger').innerHTML='<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('chg_veh_msg').innerHTML='';
    document.querySelectorAll('#chg_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    const chgNote=document.getElementById('chg_autofill_note');
    if(chgNote) chgNote.innerHTML=`<i class="bi bi-info-circle me-1"></i>Select a vehicle manually below.`;
    chgCheckConflict(); chgUpdateSaveBtn();
    toggleChgDrop('veh');
}
function chgSelectVehicle(el){
    document.querySelectorAll('#chg_veh_opts .cs-option').forEach(o=>o.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('chg_vehicle_hidden').value=el.dataset.value;
    chgRenderTrigger('veh',el.dataset.label,el.dataset.sub||'','<i class="bi bi-truck-front"></i>','veh');
    const lvl=worstVehicleConflict(el.dataset.value,chgDS,chgDE,chgTS,chgTE,chgId);
    document.getElementById('chg_veh_msg').innerHTML=lvl?vehicleAvailBadge(el.dataset.value,chgDS,chgDE,chgTS,chgTE,chgId):'<span class="avail-badge avail-ok"><i class="bi bi-check-circle me-1"></i>Available</span>';
    chgCheckConflict(); chgUpdateSaveBtn();
}
function chgCheckConflict(){
    const vid=document.getElementById('chg_vehicle_hidden').value;
    const did=document.getElementById('chg_driver_hidden').value;
    const al=document.getElementById('chg_conflict'),ml=document.getElementById('chg_conflict_msg');
    let msg=null;
    if(vid){const lvl=worstVehicleConflict(vid,chgDS,chgDE,chgTS,chgTE,chgId);if(lvl)msg=lvl==='ontrip'?'Vehicle is on trip.':'Vehicle already booked.';}
    if(!msg&&did){const lvl=worstDriverConflict(did,chgDS,chgDE,chgTS,chgTE,chgId);if(lvl)msg=lvl==='ontrip'?'Driver is on trip.':'Driver already booked.';}
    if(msg){ml.textContent=msg;al.classList.add('show');document.getElementById('chg_save_btn').disabled=true;}
    else{al.classList.remove('show');}
}
function chgUpdateSaveBtn(){
    const vid=document.getElementById('chg_vehicle_hidden').value;
    const did=document.getElementById('chg_driver_hidden').value;
    const conf=document.getElementById('chg_conflict').classList.contains('show');
    document.getElementById('chg_save_btn').disabled=!(vid&&did&&!conf);
}

/* ══ CHANGE ASSIGNMENT button click (desktop) ══ */
document.addEventListener('click', e=>{
    const b=e.target.closest('.btn-change'); if(!b) return;
    const d=b.dataset; chgId=d.id; chgDS=d.ds; chgDE=d.de; chgTS=d.ts; chgTE=d.te;
    document.getElementById('chg_sid').value=d.id;
    document.getElementById('chg_vehicle_hidden').value='';
    document.getElementById('chg_driver_hidden').value='';
    document.getElementById('chg_uname').textContent=d.username;
    document.getElementById('chg_dates').textContent=d.ds===d.de?d.ds:d.ds+' → '+d.de;
    document.getElementById('chg_time').textContent=(d.ts||'--')+' – '+(d.te||'--');
    document.getElementById('chg_drv_trigger').innerHTML='<span class="custom-select-placeholder">— Select Driver —</span>';
    document.getElementById('chg_veh_trigger').innerHTML='<span class="custom-select-placeholder">— Select Vehicle —</span>';
    document.getElementById('chg_drv_msg').innerHTML='';
    document.getElementById('chg_veh_msg').innerHTML='';
    document.getElementById('chg_conflict').classList.remove('show');
    document.getElementById('chg_save_btn').disabled=true;
    document.querySelectorAll('#chg_drv_opts .cs-option,#chg_veh_opts .cs-option').forEach(o=>{o.classList.remove('selected');o.style.opacity='';});
    if(d.did&&d.did!=='0'){const dEl=document.querySelector(`#chg_drv_opts .cs-option[data-value="${d.did}"]`);if(dEl)chgSelectDriver(dEl);}
    if(d.vid&&d.vid!=='0'){const vEl=document.querySelector(`#chg_veh_opts .cs-option[data-value="${d.vid}"]`);if(vEl)chgSelectVehicle(vEl);}
    new bootstrap.Modal(document.getElementById('changeModal')).show();
});

/* Close dropdowns on outside click */
document.addEventListener('click', e=>{
    if(!e.target.closest('.custom-select-wrap')){
        ['apr','chg'].forEach(p=>{
            ['drv','veh'].forEach(w=>{
                document.getElementById(p+'_'+w+'_dropdown')?.classList.remove('open');
                document.getElementById(p+'_'+w+'_trigger')?.classList.remove('open');
            });
        });
    }
});

/* ── Add form validation (desktop) ── */
const addDs=document.getElementById('add_ds'), addDe=document.getElementById('add_de');
const addTs=document.getElementById('add_ts'), addTe=document.getElementById('add_te');
const addTimeAlert=document.getElementById('add_time_alert'), addTimeAlertMsg=document.getElementById('add_time_alert_msg');
function validateAddTimes(){
    const ds=addDs.value,de=addDe.value,ts=addTs.value,te=addTe.value;
    addTimeAlert.classList.add('d-none'); addTe.classList.remove('is-invalid');
    if(!ts||!te) return true;
    if(ds===de&&te<=ts){ addTimeAlertMsg.textContent='End time must be after start time on the same day.'; addTimeAlert.classList.remove('d-none'); addTe.classList.add('is-invalid'); return false; }
    return true;
}
addDs.addEventListener('change', function(){ if(!addDe.value||addDe.value<this.value) addDe.value=this.value; addDe.min=this.value; validateAddTimes(); });
addDe.addEventListener('change', validateAddTimes);
addTs.addEventListener('change', function(){ if(this.value&&!addTe.value){ const[h,m]=this.value.split(':').map(Number); addTe.value=String((h+1)%24).padStart(2,'0')+':'+String(m).padStart(2,'0'); } validateAddTimes(); });
addTe.addEventListener('change', validateAddTimes);
document.getElementById('add_submit_btn').addEventListener('click', function(){
    const form=document.getElementById('scheduleForm'), timeOk=validateAddTimes();
    if(!form.checkValidity()||!timeOk){ form.classList.add('was-validated'); if(!timeOk) addTimeAlert.classList.remove('d-none'); return; }
    form.classList.remove('was-validated'); form.submit();
});
document.getElementById('addModal').addEventListener('hidden.bs.modal', ()=>{
    const form=document.getElementById('scheduleForm'); form.reset(); form.classList.remove('was-validated');
    addTimeAlert.classList.add('d-none'); addTe.classList.remove('is-invalid');
});
document.getElementById('addModal').addEventListener('show.bs.modal', function() {
    // Reset selector to "Me" and sync hidden field
    const sel = document.getElementById('add_requested_user');
    if (sel) {
        sel.selectedIndex = 0;
        document.getElementById('add_user_id').value = sel.value;
    }
});
function showToast(msg, type='success'){
    const c=document.getElementById('toast-wrap'), t=document.createElement('div');
    t.className='toast-item toast-'+type; t.textContent=msg; c.appendChild(t);
    setTimeout(()=>t.remove(), 3500);
}

/* ── Global Search ── */
function onGlobalSearch(inp) {
    const q = inp.value.toLowerCase().trim();
    document.getElementById('globalSearchClear').style.display = q ? 'block' : 'none';
    const rows = [...document.querySelectorAll('.sched-row')];
    let visible = 0;
    rows.forEach(r => {
        if (q === '') { r.style.display = ''; visible++; return; }
        const fields = [...r.querySelectorAll('td')].map(t => t.textContent.toLowerCase()).join(' ');
        const match = fields.includes(q);
        r.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    let noRes = document.getElementById('globalNoResults');
    if (!noRes) {
        noRes = document.createElement('tr');
        noRes.id = 'globalNoResults';
        noRes.innerHTML = `<td colspan="12" class="search-no-results"><i class="bi bi-search fs-4 d-block mb-2 opacity-30"></i>No results match <strong id="globalNoResultsTerm"></strong></td>`;
        document.querySelector('.table tbody').appendChild(noRes);
    }
    if (q) {
        document.getElementById('globalNoResultsTerm').textContent = '"' + inp.value + '"';
        noRes.style.display = visible === 0 ? '' : 'none';
        document.getElementById('globalSearchCount').textContent = `${visible} result${visible !== 1 ? 's' : ''} of ${rows.length}`;
    } else { noRes.style.display = 'none'; document.getElementById('globalSearchCount').textContent = ''; }
}
function clearGlobalSearch() {
    const inp = document.getElementById('globalSearch'); inp.value = ''; inp.focus(); onGlobalSearch(inp);
}
</script>
<script>
const highlightId = <?= isset($_GET['highlight']) ? (int)$_GET['highlight'] : 'null' ?>;
if(highlightId){
    const row = document.querySelector(`tr[data-schedule-id="${highlightId}"]`);
    if(row){ row.classList.add('row-highlight'); setTimeout(() => row.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100); }
}
/* ══ ATTACH SIGNED TICKET ══ */
let _attachSid = 0, _attachFile = null;

document.addEventListener('click', e => {
    const b = e.target.closest('.btn-attach-signed');
    if (!b) return;
    _attachSid  = parseInt(b.dataset.id);
    _attachFile = null;

    // Reset UI
    document.getElementById('attach_err').classList.add('d-none');
    document.getElementById('attach_preview').style.display = 'none';
    document.getElementById('attach_progress_wrap').style.display = 'none';
    document.getElementById('attach_progress_bar').style.width = '0%';
    document.getElementById('attach_upload_btn').disabled = true;
    document.getElementById('attach_file_input').value = '';
    document.getElementById('attach_drop_zone').style.borderColor = '#b0c4b1';

    // Check if signed ticket already exists
    const viewBtn = b.closest('td, .sc-actions')?.querySelector('a[href*="signed_tickets"]');
    const currentWrap = document.getElementById('attach_current_wrap');
    if (viewBtn) {
        document.getElementById('attach_current_link').href = viewBtn.href;
        currentWrap.style.display = '';
    } else {
        currentWrap.style.display = 'none';
    }

    new bootstrap.Modal(document.getElementById('attachSignedModal')).show();
});

function attachDragOver(e) {
    e.preventDefault();
    document.getElementById('attach_drop_zone').style.borderColor = '#0f5132';
    document.getElementById('attach_drop_zone').style.background  = '#f0fdf4';
}
function attachDragLeave(e) {
    document.getElementById('attach_drop_zone').style.borderColor = '#b0c4b1';
    document.getElementById('attach_drop_zone').style.background  = '#f8fdf8';
}
function attachDrop(e) {
    e.preventDefault();
    attachDragLeave(e);
    const file = e.dataTransfer.files[0];
    if (file) attachSetFile(file);
}
function attachFileChosen(inp) {
    if (inp.files[0]) attachSetFile(inp.files[0]);
}
function attachSetFile(file) {
    const allowed = ['application/pdf','image/jpeg','image/jpg','image/png'];
    const err = document.getElementById('attach_err');
    err.classList.add('d-none');

    if (!allowed.includes(file.type)) {
        err.textContent = 'Invalid file type. Only PDF, JPG, PNG allowed.';
        err.classList.remove('d-none'); return;
    }
    if (file.size > 10 * 1024 * 1024) {
        err.textContent = 'File is too large. Maximum size is 10MB.';
        err.classList.remove('d-none'); return;
    }
    _attachFile = file;
    document.getElementById('attach_filename').textContent = file.name;
    document.getElementById('attach_filesize').textContent = (file.size / 1024).toFixed(1) + ' KB';
    document.getElementById('attach_preview').style.display = '';
    document.getElementById('attach_upload_btn').disabled = false;
}
function attachClearFile() {
    _attachFile = null;
    document.getElementById('attach_file_input').value = '';
    document.getElementById('attach_preview').style.display = 'none';
    document.getElementById('attach_upload_btn').disabled = true;
}
function attachDoUpload() {
    if (!_attachFile || !_attachSid) return;
    const btn  = document.getElementById('attach_upload_btn');
    const err  = document.getElementById('attach_err');
    const prog = document.getElementById('attach_progress_wrap');
    const bar  = document.getElementById('attach_progress_bar');
    const txt  = document.getElementById('attach_progress_txt');
    err.classList.add('d-none');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
    prog.style.display = '';

    const fd = new FormData();
    fd.append('schedule_id', _attachSid);
    fd.append('signed_ticket', _attachFile);

    const xhr = new XMLHttpRequest();
    xhr.upload.onprogress = ev => {
        if (ev.lengthComputable) {
            const pct = Math.round(ev.loaded / ev.total * 100);
            bar.style.width = pct + '%';
            txt.textContent = 'Uploading… ' + pct + '%';
        }
    };
    xhr.onload = () => {
        let data;
        try { data = JSON.parse(xhr.responseText); } catch(_) {
            err.textContent = 'Server error. Please try again.';
            err.classList.remove('d-none');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket';
            prog.style.display = 'none'; return;
        }
        if (data.ok) {
            bootstrap.Modal.getInstance(document.getElementById('attachSignedModal')).hide();
            showToast('Signed ticket uploaded successfully!', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            err.textContent = data.msg || 'Upload failed.';
            err.classList.remove('d-none');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket';
            prog.style.display = 'none';
        }
    };
    xhr.onerror = () => {
        err.textContent = 'Network error. Please try again.';
        err.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket';
        prog.style.display = 'none';
    };
    xhr.open('POST', 'upload_signed_ticket.php');
    xhr.send(fd);
}
</script>
<!-- ATTACH SIGNED TICKET MODAL -->
<div class="modal fade" id="attachSignedModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header mh-green">
    <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i>Attach Signed Trip Ticket</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.83rem">
      <i class="bi bi-info-circle me-1"></i>
      Upload the signed trip ticket. Accepted: <strong>PDF, JPG, PNG</strong> (max 10MB).
    </div>
    <div id="attach_current_wrap" style="display:none;margin-bottom:12px">
      <div class="sbox">
        <div class="sbox-title"><i class="bi bi-file-earmark-check me-1"></i>Current Signed Ticket</div>
       <a id="attach_current_link" href="#" 
   style="font-size:.85rem;color:#0f5132;font-weight:600"
   onclick="viewSignedTicket(this.href, this.dataset.ext); return false;"
   data-ext="">
  <i class="bi bi-eye me-1"></i>View existing signed ticket
</a>
        <div style="font-size:.72rem;color:#888;margin-top:3px">
          Uploading a new file will replace this.
        </div>
      </div>
    </div>
    <div id="attach_drop_zone" 
         style="border:2px dashed #b0c4b1;border-radius:12px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:all .2s;background:#f8fdf8"
         onclick="document.getElementById('attach_file_input').click()"
         ondragover="attachDragOver(event)" ondragleave="attachDragLeave(event)" ondrop="attachDrop(event)">
      <i class="bi bi-cloud-upload" style="font-size:2rem;color:#0f5132;display:block;margin-bottom:8px"></i>
      <div style="font-weight:600;color:#333;margin-bottom:4px">Click to browse or drag & drop</div>
      <div style="font-size:.78rem;color:#888">PDF, JPG, PNG — max 10MB</div>
    </div>
    <input type="file" id="attach_file_input" accept=".pdf,.jpg,.jpeg,.png" 
           style="display:none" onchange="attachFileChosen(this)">
    <div id="attach_preview" style="display:none;margin-top:12px">
      <div class="sbox" style="display:flex;align-items:center;gap:10px">
        <i class="bi bi-file-earmark-fill" style="font-size:1.5rem;color:#0f5132"></i>
        <div style="flex:1;min-width:0">
          <div id="attach_filename" style="font-weight:600;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>
          <div id="attach_filesize" style="font-size:.72rem;color:#888"></div>
        </div>
        <button type="button" onclick="attachClearFile()" 
                style="background:none;border:none;color:#aaa;cursor:pointer;font-size:1rem">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
    </div>
    <div id="attach_err" class="alert alert-danger py-2 px-3 mt-3 d-none" style="font-size:.83rem"></div>
    <div id="attach_progress_wrap" style="display:none;margin-top:12px">
      <div style="background:#e9ecef;border-radius:10px;height:8px;overflow:hidden">
        <div id="attach_progress_bar" 
             style="height:100%;background:linear-gradient(90deg,#0f5132,#198754);width:0%;transition:width .3s;border-radius:10px"></div>
      </div>
      <div style="font-size:.72rem;color:#888;margin-top:4px;text-align:center" id="attach_progress_txt">Uploading…</div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="button" id="attach_upload_btn" class="btn btn-success fw-semibold" disabled
            onclick="attachDoUpload()">
      <i class="bi bi-cloud-upload me-1"></i>Upload Signed Ticket
    </button>
  </div>
</div></div></div>
<!-- VIEW SIGNED TICKET MODAL -->
<div class="modal fade" id="viewSignedModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
  <div class="modal-header" style="background:linear-gradient(135deg,#0550a0,#0a3678);color:#fff">
    <h5 class="modal-title"><i class="bi bi-file-earmark-check-fill me-2"></i>Signed Trip Ticket</h5>
    <div class="d-flex align-items-center gap-2 ms-auto">
      <a id="vsm_download" href="#" download class="btn btn-sm btn-light">
        <i class="bi bi-download me-1"></i>Download
      </a>
      <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1)"></button>
    </div>
  </div>
  <div class="modal-body p-0" style="min-height:500px;background:#f0f0f0;display:flex;align-items:center;justify-content:center">
    <div id="vsm_loading" style="text-align:center;color:#888;padding:3rem">
      <div class="spinner-border text-secondary mb-3"></div>
      <div>Loading…</div>
    </div>
    <iframe id="vsm_iframe" src="" style="display:none;width:100%;height:75vh;border:none"></iframe>
    <img id="vsm_img" src="" alt="Signed Ticket" style="display:none;max-width:100%;max-height:75vh;object-fit:contain;padding:1rem">
  </div>
</div></div></div>

<script>
document.addEventListener('click', e => {
    const b = e.target.closest('.btn-view-signed');
    if (!b) return;
    viewSignedTicket(b.dataset.path, b.dataset.type);
});

function viewSignedTicket(path, ext) {
    const modal = document.getElementById('viewSignedModal');
    const iframe = document.getElementById('vsm_iframe');
    const img    = document.getElementById('vsm_img');
    const loader = document.getElementById('vsm_loading');
    const dl     = document.getElementById('vsm_download');

    iframe.style.display = 'none';
    img.style.display    = 'none';
    loader.style.display = 'block';
    iframe.src = '';
    img.src    = '';
    dl.href    = path;

    if (ext === 'pdf') {
        iframe.onload = () => { loader.style.display = 'none'; iframe.style.display = ''; };
        iframe.src = path;
    } else {
        img.onload  = () => { loader.style.display = 'none'; img.style.display = 'block'; };
        img.onerror = () => { loader.innerHTML = '<div class="text-danger"><i class="bi bi-x-circle fs-3 d-block mb-2"></i>Could not load image.</div>'; };
        img.src = path;
    }

    new bootstrap.Modal(modal).show();
}
</script>
</body>
</html>