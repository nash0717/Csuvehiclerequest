<?php
/**
 * cron_trip_reminders.php
 *
 * FOLDER: Place this at your project ROOT (same level as index.php)
 *
 *   /your_project/
 *       index.php
 *       cron_trip_reminders.php   <-- HERE
 *       /admin/
 *       /includes/
 *           db.php
 *
 * CRON (cPanel Cron Jobs):
 *   Command:  php /home/yourusername/public_html/your_project/cron_trip_reminders.php
 *   Schedule: every 15 minutes
 *
 * TEST manually (SSH or browser via a test wrapper):
 *   php cron_trip_reminders.php
 */

require_once __DIR__ . '/includes/db.php';
date_default_timezone_set('Asia/Manila');

/* helpers */
function _fmtDate(string $d): string {
    $ts = strtotime($d);
    return $ts ? date('M d, Y', $ts) : $d;
}
function _fmtTime(string $t): string {
    if (!$t || trim($t) === '') return '';
    foreach (['H:i:s','H:i'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $t);
        if ($dt) return $dt->format('g:i A');
    }
    return $t;
}
function _cleanVehicle(string $v): string {
    $v = trim($v);
    if ($v === '' || preg_match('/^[\s()]+$/', $v)) return '';
    if (preg_match('/\(\s*\)\s*$/', $v)) {
        $base = trim(preg_replace('/\(\s*\)\s*$/', '', $v));
        return $base ?: '';
    }
    return $v;
}
function _insertReminder(PDO $pdo, int $uid, string $msg): void {
    $pdo->prepare(
        "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())"
    )->execute([$uid, $msg]);
}

/* duplicate guard — named params, DATE() comparison for MariaDB compatibility */
function _alreadySent(PDO $pdo, int $uid, int $sid, string $kw): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE user_id = :uid
           AND message LIKE :ref
           AND message LIKE :kw
           AND DATE(created_at) = DATE(NOW())"
    );
    $st->execute([
        ':uid' => $uid,
        ':ref' => '%[ref:' . $sid . ']%',
        ':kw'  => '%' . $kw . '%',
    ]);
    return (int)$st->fetchColumn() > 0;
}

function _buildMsg(int $sid, string $ticketNo, string $dest,
                   string $ds, string $ts, string $te,
                   string $drv, string $veh, string $note): string {
    $tripNo = trim($ticketNo) !== '' ? $ticketNo : '#'.str_pad($sid,4,'0',STR_PAD_LEFT);
    $v = _cleanVehicle($veh);
    $lines = [
        'REMINDER',
        "Trip: {$tripNo}",
        "Destination: {$dest}",
        "Date: "           . _fmtDate($ds),
        "Departure Time: " . _fmtTime($ts),
        "Return Time: "    . _fmtTime($te),
    ];
    if (trim($drv) !== '') $lines[] = "Driver: {$drv}";
    if ($v !== '')         $lines[] = "Vehicle: {$v}";
    $lines[] = "Note: {$note}";
    $lines[] = "[ref:{$sid}]";
    return implode("\n", $lines);
}

/* fetch approved upcoming trips */
$stmt = $pdo->prepare(
    "SELECT s.schedule_id, s.trip_ticket_no, s.user_id,
            s.destination, s.date_start, s.date_end, s.time_start, s.time_end,
            COALESCE(CONCAT(d.first_name,' ',d.last_name),'') AS driver_name,
            COALESCE(CONCAT(v.make,' ',v.model,' (',v.plate_no,')'),'') AS vehicle_label
     FROM schedules s
     LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
     LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
     WHERE s.status = 'Approved'
       AND CONCAT(s.date_start,' ',s.time_start) > NOW()"
);
$stmt->execute();
$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

$now = time();
$s24 = $s1 = 0;

foreach ($trips as $t) {
    $dep = strtotime($t['date_start'].' '.$t['time_start']);
    if (!$dep) continue;
    $diff = ($dep - $now) / 60;
    $sid  = (int)$t['schedule_id'];
    $uid  = (int)$t['user_id'];

    /* 24-hour window: 23h45m – 24h15m */
    if ($diff >= 1425 && $diff <= 1455 && !_alreadySent($pdo,$uid,$sid,'24 hours')) {
        _insertReminder($pdo, $uid, _buildMsg(
            $sid, $t['trip_ticket_no']??'', $t['destination'],
            $t['date_start'],$t['time_start'],$t['time_end'],
            $t['driver_name'],$t['vehicle_label'],
            'Your trip departs in approximately 24 hours. Please be ready.'
        ));
        $s24++;
        echo "[".date('Y-m-d H:i:s')."] 24h reminder sent → user {$uid}, schedule {$sid}\n";
    }

    /* 1-hour window: 45m – 1h15m */
    if ($diff >= 45 && $diff <= 75 && !_alreadySent($pdo,$uid,$sid,'1 hour')) {
        _insertReminder($pdo, $uid, _buildMsg(
            $sid, $t['trip_ticket_no']??'', $t['destination'],
            $t['date_start'],$t['time_start'],$t['time_end'],
            $t['driver_name'],$t['vehicle_label'],
            'Your trip departs in approximately 1 hour. Please be ready!'
        ));
        $s1++;
        echo "[".date('Y-m-d H:i:s')."] 1h  reminder sent → user {$uid}, schedule {$sid}\n";
    }
}

echo "[".date('Y-m-d H:i:s')."] Done. 24h={$s24}  1h={$s1}  checked=".count($trips)."\n";