<?php
require_once __DIR__ . '/../includes/mailer.php';

/* ── Helper: get user email from DB ── */
function getUserEmail(PDO $pdo, int $userId): array {
    $s = $pdo->prepare("SELECT email, username FROM users WHERE user_id = ?");
    $s->execute([$userId]);
    return $s->fetch() ?: ['email' => '', 'username' => ''];
}

function fmtDate(string $d): string {
    return $d ? date('F d, Y', strtotime($d)) : '—';
}
function fmtTime12(string $t): string {
    if (!$t || $t === '--') return '--';
    return date('g:i A', strtotime("1970-01-01 $t"));
}

/* ══════════════════════════════════════════════════════════
   STATUS PALETTES  [bg, accent-border, text]
══════════════════════════════════════════════════════════ */
const STATUS_COLORS = [
    'success' => ['#F0FAF4', '#1A7A45', '#0D5C34'],
    'danger'  => ['#FDF1F1', '#C0392B', '#8C1B13'],
    'warning' => ['#FFFBF0', '#C98A0C', '#8A5E06'],
    'info'    => ['#EEF4FD', '#1B5FC1', '#103D85'],
    'neutral' => ['#F5F5F5', '#888888', '#444444'],
];

/* ══════════════════════════════════════════════════════════
   MASTER EMAIL SHELL
   Premium maroon + gold brand header. Clean white card body.
══════════════════════════════════════════════════════════ */
function emailTemplate(string $title, string $accentColor, string $body): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#EDE5E5;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">

<div style="display:none;max-height:0;overflow:hidden;">{$title} — CSU Vehicle Scheduling System</div>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation"
       style="background:#EDE5E5;padding:36px 16px;">
<tr><td align="center">

  <table width="600" cellpadding="0" cellspacing="0" role="presentation"
         style="max-width:600px;width:100%;border-radius:18px;overflow:hidden;
                border:1px solid #D9CCCC;background:#FFFFFF;">

    <!-- ① Top colour stripe -->
    <tr>
      <td style="background:{$accentColor};height:4px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>

    <!-- ② Brand header -->
    <tr>
      <td style="background:linear-gradient(160deg,#6B0000 0%,#3D0000 100%);
                 padding:32px 40px 28px;">
        <p style="margin:0 0 4px;font-size:10.5px;font-weight:700;
                  color:rgba(201,168,76,0.85);letter-spacing:0.16em;
                  text-transform:uppercase;">
          CSU Vehicle Scheduling System
        </p>
        <div style="width:32px;height:1.5px;background:#C9A84C;margin-bottom:8px;"></div>
        <h1 style="margin:0;font-size:20px;font-weight:700;
                   color:#FFFFFF;line-height:1.25;letter-spacing:0.01em;">
          {$title}
        </h1>
      </td>
    </tr>

    <!-- ③ Content area -->
    <tr>
      <td style="padding:32px 40px 24px;">
        {$body}
      </td>
    </tr>

    <!-- ④ Footer -->
    <tr>
      <td style="padding:18px 40px 28px;border-top:1px solid #EDE0E0;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td>
              <p style="margin:0 0 3px;font-size:11.5px;color:#AAAAAA;">
                Automated notification &mdash;
                <strong style="color:#800000;">Cagayan State University</strong>
              </p>
              <p style="margin:0;font-size:11px;color:#CCCCCC;">
                Please do not reply to this email. &copy; {$year} CSU. All rights reserved.
              </p>
            </td>
            <td align="right" style="vertical-align:middle;">
              <span style="display:inline-block;padding:4px 12px;border-radius:20px;
                           background:#F9F1F1;border:1px solid #E0CCCC;
                           font-size:10px;font-weight:700;color:#800000;
                           letter-spacing:0.06em;text-transform:uppercase;">
                CSU VSS
              </span>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- ⑤ Bottom gold-maroon stripe -->
    <tr>
      <td style="background:linear-gradient(90deg,#6B0000,#C9A84C,#6B0000);
                 height:3px;font-size:0;line-height:0;">&nbsp;</td>
    </tr>

  </table>

  <p style="margin:14px 0 0;font-size:10.5px;color:#B0A0A0;text-align:center;">
    Sent because you have an active account in the CSU Vehicle Scheduling System.
  </p>

</td></tr>
</table>
</body>
</html>
HTML;
}

/* ══════════════════════════════════════════════════════════
   HELPER PARTIALS
══════════════════════════════════════════════════════════ */

function greeting(string $username): string {
    $name = htmlspecialchars($username);
    return "<p style='margin:0 0 18px;font-size:15px;color:#2D2D2D;line-height:1.6;'>"
         . "Hello, <strong style='color:#6B0000;'>{$name}</strong>,</p>";
}

function bodyText(string $text): string {
    return "<p style='margin:0 0 20px;font-size:14px;color:#4A4A4A;line-height:1.8;'>{$text}</p>";
}

function infoTable(array $rows, string $accentColor): string {
    $out  = "<table width='100%' cellpadding='0' cellspacing='0' role='presentation' "
          . "style='margin:0 0 22px;border-radius:10px;overflow:hidden;"
          . "border:1px solid #E4DADA;font-size:0;'>";

    $out .= "<tr>"
          . "<td colspan='2' style='background:{$accentColor};padding:8px 16px;font-size:0;'>"
          . "<span style='font-size:10px;font-weight:700;color:rgba(255,255,255,0.88);"
          . "letter-spacing:0.12em;text-transform:uppercase;'>Trip Details</span>"
          . "</td></tr>";

    foreach ($rows as $i => [$label, $value]) {
        $bg   = $i % 2 === 0 ? '#F9F6F6' : '#FFFFFF';
        $last = $i === array_key_last($rows);
        $bb   = $last ? 'none' : '1px solid #EDE4E4';

        $out .= "<tr style='background:{$bg};'>"
              . "<td style='padding:11px 16px;width:34%;font-size:12px;font-weight:700;"
              . "color:{$accentColor};border-right:1px solid #EDE4E4;"
              . "border-bottom:{$bb};vertical-align:top;white-space:nowrap;'>"
              . $label
              . "</td>"
              . "<td style='padding:11px 16px;font-size:13px;color:#2D2D2D;"
              . "border-bottom:{$bb};vertical-align:top;line-height:1.55;'>"
              . $value
              . "</td>"
              . "</tr>";
    }

    $out .= "</table>";
    return $out;
}

function noticeBox(string $message, string $status = 'info'): string {
    [$bg, $border, $text] = STATUS_COLORS[$status] ?? STATUS_COLORS['info'];

    return "<table width='100%' cellpadding='0' cellspacing='0' role='presentation' "
         . "style='margin:0 0 20px;'><tr>"
         . "<td style='background:{$bg};"
         . "border-left:3px solid {$border};"
         . "border-radius:0 8px 8px 0;"
         . "padding:13px 17px;"
         . "font-size:13px;color:{$text};line-height:1.7;'>"
         . $message
         . "</td></tr></table>";
}

function divider(): string {
    return "<table width='100%' cellpadding='0' cellspacing='0' "
         . "style='margin:4px 0 22px;'>"
         . "<tr><td style='border-top:1px solid #EDE6E6;'></td></tr>"
         . "</table>";
}


/* ══════════════════════════════════
   EMAIL: Trip Approved
══════════════════════════════════ */
function emailRequestorApproved(
    PDO $pdo, int $userId, int $schedId,
    string $destination, string $dateStart, string $timeStart,
    string $dateEnd,   string $timeEnd,
    string $driverName, string $vehicleLabel
): void {
    $user = getUserEmail($pdo, $userId);
    if (!$user['email']) return;

    $dateRange   = fmtDate($dateStart) . ($dateStart !== $dateEnd ? ' &rarr; ' . fmtDate($dateEnd) : '');
    $timeRange   = fmtTime12($timeStart) . ' &ndash; ' . fmtTime12($timeEnd);
    $ref         = '#' . str_pad($schedId, 5, '0', STR_PAD_LEFT);
    $accentColor = '#1A7A45';

    $body = greeting($user['username'])
          . bodyText('Great news! Your vehicle scheduling request has been '
              . '<strong style="color:#1A7A45;">approved</strong>. '
              . 'Your confirmed trip details are shown below.')
          . infoTable([
                ['Destination', htmlspecialchars($destination)],
                ['Date',        $dateRange],
                ['Time',        $timeRange],
                ['Vehicle',     htmlspecialchars($vehicleLabel)],
                ['Driver',      htmlspecialchars($driverName)],
                ['Reference',   $ref],
            ], $accentColor)
          . noticeBox(
                '<strong>Reminder:</strong> Please be at your pickup point at least '
                . '<strong>10 minutes before</strong> your scheduled departure. '
                . 'Contact your office administrator for any concerns.',
                'success'
            );

    $html = emailTemplate('Trip Approved', $accentColor, $body);
    sendSystemEmail(
        $user['email'], $user['username'],
        "CSU VSS – Your Trip Has Been Approved [{$ref}]",
        $html
    );
}

/* ══════════════════════════════════
   EMAIL: Trip Rejected
══════════════════════════════════ */
function emailRequestorRejected(
    PDO $pdo, int $userId, int $schedId,
    string $destination, string $reason
): void {
    $user = getUserEmail($pdo, $userId);
    if (!$user['email']) return;

    $ref         = '#' . str_pad($schedId, 5, '0', STR_PAD_LEFT);
    $accentColor = '#C0392B';

    $body = greeting($user['username'])
          . bodyText('We regret to inform you that your vehicle scheduling request has been '
              . '<strong style="color:#C0392B;">rejected</strong>. '
              . 'Please see the details below.')
          . infoTable([
                ['Destination', htmlspecialchars($destination)],
                ['Reason',      htmlspecialchars($reason ?: 'No reason provided')],
                ['Reference',   $ref],
            ], $accentColor)
          . noticeBox(
                'You may submit a <strong>new request</strong> with updated details. '
                . 'Please reach out to your office administrator for further assistance.',
                'danger'
            );

    $html = emailTemplate('Trip Request Rejected', $accentColor, $body);
    sendSystemEmail(
        $user['email'], $user['username'],
        "CSU VSS – Trip Request Rejected [{$ref}]",
        $html
    );
}

/* ══════════════════════════════════
   EMAIL: Trip Cancelled
══════════════════════════════════ */
function emailRequestorCancelled(
    PDO $pdo, int $userId, int $schedId,
    string $destination, string $reason, string $cancelledBy
): void {
    $user = getUserEmail($pdo, $userId);
    if (!$user['email']) return;

    $ref         = '#' . str_pad($schedId, 5, '0', STR_PAD_LEFT);
    $accentColor = '#B94A00';

    $body = greeting($user['username'])
          . bodyText('Your scheduled vehicle trip has been '
              . '<strong style="color:#B94A00;">cancelled</strong>. '
              . 'Please review the details below.')
          . infoTable([
                ['Destination',  htmlspecialchars($destination)],
                ['Reason',       htmlspecialchars($reason ?: 'No reason provided')],
                ['Cancelled By', htmlspecialchars($cancelledBy)],
                ['Reference',    $ref],
            ], $accentColor)
          . noticeBox(
                'If you believe this cancellation was made in error, please contact your '
                . '<strong>office administrator</strong> immediately.',
                'warning'
            );

    $html = emailTemplate('Trip Cancelled', $accentColor, $body);
    sendSystemEmail(
        $user['email'], $user['username'],
        "CSU VSS – Trip Cancelled [{$ref}]",
        $html
    );
}

/* ══════════════════════════════════
   EMAIL: Assignment Changed
══════════════════════════════════ */
function emailRequestorAssignmentChanged(
    PDO $pdo, int $userId, int $schedId,
    string $destination, string $driverName, string $vehicleLabel
): void {
    $user = getUserEmail($pdo, $userId);
    if (!$user['email']) return;

    $ref         = '#' . str_pad($schedId, 5, '0', STR_PAD_LEFT);
    $accentColor = '#1B5FC1';

    $body = greeting($user['username'])
          . bodyText('Your trip assignment has been '
              . '<strong style="color:#1B5FC1;">updated</strong> by your administrator. '
              . 'Please take note of your new vehicle and driver.')
          . infoTable([
                ['Destination', htmlspecialchars($destination)],
                ['New Vehicle', htmlspecialchars($vehicleLabel)],
                ['New Driver',  htmlspecialchars($driverName)],
                ['Reference',   $ref],
            ], $accentColor)
          . noticeBox(
                'Please save this information. Contact your administrator if you have any questions about the change.',
                'info'
            );

    $html = emailTemplate('Assignment Updated', $accentColor, $body);
    sendSystemEmail(
        $user['email'], $user['username'],
        "CSU VSS – Trip Assignment Updated [{$ref}]",
        $html
    );
}

/* ══════════════════════════════════
   EMAIL: 24-Hour Reminder
══════════════════════════════════ */
function emailRequestorUpcoming24h(
    PDO $pdo, int $userId, int $schedId,
    string $destination, string $dateStart, string $timeStart,
    string $dateEnd,   string $timeEnd,
    string $driverName, string $vehicleLabel
): void {
    $user = getUserEmail($pdo, $userId);
    if (!$user['email']) return;

    $dateRange   = fmtDate($dateStart) . ($dateStart !== $dateEnd ? ' &rarr; ' . fmtDate($dateEnd) : '');
    $timeRange   = fmtTime12($timeStart) . ' &ndash; ' . fmtTime12($timeEnd);
    $ref         = '#' . str_pad($schedId, 5, '0', STR_PAD_LEFT);
    $accentColor = '#9E6C06';

    $body = greeting($user['username'])
          . bodyText('Friendly reminder — your scheduled trip is '
              . '<strong style="color:#9E6C06;">tomorrow</strong>. '
              . 'Make sure you are prepared!')
          . infoTable([
                ['Destination', htmlspecialchars($destination)],
                ['Date',        $dateRange],
                ['Time',        $timeRange],
                ['Vehicle',     htmlspecialchars($vehicleLabel)],
                ['Driver',      htmlspecialchars($driverName)],
                ['Reference',   $ref],
            ], $accentColor)
          . noticeBox(
                'Please be at your pickup point at least '
                . '<strong>10 minutes before</strong> your departure time. Have a safe trip!',
                'warning'
            );

    $html = emailTemplate('Trip Reminder – Tomorrow', $accentColor, $body);
    sendSystemEmail(
        $user['email'], $user['username'],
        "CSU VSS – Your Trip is Tomorrow! [{$ref}]",
        $html
    );
}

/* ══════════════════════════════════
   EMAIL: 1-Hour Reminder
══════════════════════════════════ */
function emailRequestorUpcoming1h(
    PDO $pdo, int $userId, int $schedId,
    string $destination, string $dateStart, string $timeStart,
    string $dateEnd,   string $timeEnd,
    string $driverName, string $vehicleLabel
): void {
    $user = getUserEmail($pdo, $userId);
    if (!$user['email']) return;

    $timeRange   = fmtTime12($timeStart) . ' &ndash; ' . fmtTime12($timeEnd);
    $ref         = '#' . str_pad($schedId, 5, '0', STR_PAD_LEFT);
    $accentColor = '#C0392B';

    $body = greeting($user['username'])
          . bodyText('Your trip departs in approximately '
              . '<strong style="color:#C0392B;">1 hour</strong>. '
              . 'Please head to your pickup point now!')
          . infoTable([
                ['Destination', htmlspecialchars($destination)],
                ['Departure',   fmtTime12($timeStart) . ' today'],
                ['Time Range',  $timeRange],
                ['Vehicle',     htmlspecialchars($vehicleLabel)],
                ['Driver',      htmlspecialchars($driverName)],
                ['Reference',   $ref],
            ], $accentColor)
          . noticeBox(
                'If you need to <strong>cancel</strong> or have an emergency, '
                . 'please contact your administrator <strong>immediately</strong>.',
                'danger'
            );

    $html = emailTemplate('Trip Starts in 1 Hour', $accentColor, $body);
    sendSystemEmail(
        $user['email'], $user['username'],
        "CSU VSS – Your Trip Starts in 1 Hour! [{$ref}]",
        $html
    );
}