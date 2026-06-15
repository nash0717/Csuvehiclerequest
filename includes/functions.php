<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function showFlash() {
    if (!empty($_SESSION['flash'])) {
        $type    = $_SESSION['flash']['type'] ?? 'info';
        $message = $_SESSION['flash']['message'] ?? '';
        $bs = $type === 'error' ? 'danger' : $type;
        echo "<div class='alert alert-{$bs} alert-dismissible fade show' role='alert'>
                {$message}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
        unset($_SESSION['flash']);
    }
}

function isVehicleAvailable($pdo, $vehicle_id, $date_start, $time_start, $time_end, $exclude_id = null) {
    $sql = "SELECT COUNT(*) FROM schedules 
            WHERE vehicle_id = ? AND date_start = ? AND status = 'Approved'
            AND (time_start < ? AND time_end > ?)";
    $params = [$vehicle_id, $date_start, $time_end, $time_start];
    if ($exclude_id) {
        $sql .= " AND schedule_id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() == 0;
}

function isDriverAvailable($pdo, $driver_id, $date_start, $time_start, $time_end, $exclude_id = null) {
    $sql = "SELECT COUNT(*) FROM schedules 
            WHERE driver_id = ? AND date_start = ? AND status = 'Approved'
            AND (time_start < ? AND time_end > ?)";
    $params = [$driver_id, $date_start, $time_end, $time_start];
    if ($exclude_id) {
        $sql .= " AND schedule_id != ?";
        $params[] = $exclude_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() == 0;
}

function officeScopeSQL($tableAlias = '', $existingWhere = '') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role   = $_SESSION['role']      ?? '';
    $office = $_SESSION['office_id'] ?? null;

    // Admins see everything — no filter
    if ($role === 'admin' || empty($office)) return $existingWhere;

    $col    = $tableAlias ? $tableAlias . '.office_id' : 'office_id';
    $clause = "{$col} = " . (int)$office;

    $existingWhere = trim($existingWhere);
    if ($existingWhere === '')                        return "WHERE {$clause}";
    if (stripos($existingWhere, 'where') === 0)       return $existingWhere . " AND {$clause}";
    return $existingWhere . " AND {$clause}";
}

/**
 * Insert a notification for every admin user.
 *
 * Usage examples:
 *   notify_admins($pdo, "New request submitted by John — Trip to Baguio (Request #42)");
 *   notify_admins($pdo, "Request #42 approved.");
 *   notify_admins($pdo, "Request #42 rejected.");
 *   notify_admins($pdo, "Vehicle overdue: Plate ABC-123 not yet returned.");
 *
 * The notification.php page already auto-detects type from these keywords:
 *   overdue / not yet returned → Overdue (red)
 *   rejected                  → Rejected (red)
 *   cancelled / canceled      → Cancelled (orange)
 *   approved                  → Approved (green)
 *   completed / complete      → Completed (green)
 *   reminder / departs in / 1 hour → Reminder (yellow)
 *   walk-in / walk in         → Walk-in (green)
 *   new request / submitted   → New Request (blue)
 *   (anything else)           → Notice (gray)
 *
 * @param PDO    $pdo
 * @param string $message   The notification message text.
 * @param int[]  $userIds   Optional — notify specific user IDs instead of all admins.
 */
function notify_admins(PDO $pdo, string $message, array $userIds = []): void {
    if (empty($userIds)) {
        // Default: notify all admins
        $rows = $pdo->query("SELECT user_id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $rows = $userIds;
    }

    if (empty($rows)) return;

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, message, is_read, created_at)
         VALUES (?, ?, 0, NOW())"
    );

    foreach ($rows as $uid) {
        $stmt->execute([(int)$uid, $message]);
    }
}

/**
 * Insert a notification for a single specific user (e.g. notify the requestor).
 *
 * Usage:
 *   notify_user($pdo, $requestorUserId, "Your request #42 has been approved.");
 *   notify_user($pdo, $requestorUserId, "Your request #42 was rejected.");
 */
function notify_user(PDO $pdo, int $userId, string $message): void {
    $pdo->prepare(
        "INSERT INTO notifications (user_id, message, is_read, created_at)
         VALUES (?, ?, 0, NOW())"
    )->execute([$userId, $message]);
}
