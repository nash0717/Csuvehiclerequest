<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireAdmin();

$schedule_id = (int)($_GET['id'] ?? 0);
if (!$schedule_id) { echo "Invalid schedule."; exit; }

/* ─── Fetch schedule ─── */
$stmt = $pdo->prepare("
    SELECT s.*,
           u.username,
           v.plate_number, v.brand, v.model,
           dr.driver_name,
           o.office_name,
           dept.dept_name AS department_name
    FROM schedules s
    JOIN  users u        ON s.user_id    = u.user_id
    LEFT JOIN vehicles v   ON s.vehicle_id = v.vehicle_id
    LEFT JOIN drivers  dr  ON s.driver_id  = dr.driver_id
    JOIN  offices o      ON s.office_id  = o.office_id
    LEFT JOIN departments dept ON s.department_id = dept.dept_id
    WHERE s.schedule_id = ?
");
$stmt->execute([$schedule_id]);
$s = $stmt->fetch();
if (!$s) { echo "Schedule not found."; exit; }

/* ─── Trip Ticket # ─── */
$ticketMonth = date('m', strtotime($s['date_start']));
$ticketYear  = date('Y', strtotime($s['date_start']));
$reqNo       = 'REQ-' . str_pad($schedule_id, 6, '0', STR_PAD_LEFT);

// Use stored trip_ticket_no if already assigned
if (!empty($s['trip_ticket_no'])) {
    $tripTicketNo = $s['trip_ticket_no'];
} else {
    // Count only Approved/OnTrip/Completed schedules in same month/year
    // ordered by schedule_id to get a stable sequence
    $seqStmt = $pdo->prepare("
        SELECT COUNT(*) FROM schedules
        WHERE status IN ('Approved', 'OnTrip', 'Completed')
          AND MONTH(date_start) = ?
          AND YEAR(date_start)  = ?
          AND schedule_id <= ?
    ");
    $seqStmt->execute([$ticketMonth, $ticketYear, $schedule_id]);
    $seq = (int)$seqStmt->fetchColumn();

    $tripTicketNo = $ticketMonth . '/' . $ticketYear . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);

    // Store it so the number never changes on reprint
    $pdo->prepare("UPDATE schedules SET trip_ticket_no = ? WHERE schedule_id = ? AND trip_ticket_no IS NULL")
        ->execute([$tripTicketNo, $schedule_id]);
}

/* ─── Format helpers ─── */

/* ─── Format helpers ─── */
function fd($d) { return $d ? date('F j, Y', strtotime($d)) : '—'; }
function ft($t) { return $t ? date('g:i A', strtotime($t)) : '—'; }

/* ─── Fetch office signatory ─── */
$sigStmt = $pdo->prepare("
    SELECT signatory_name, signatory_title
    FROM office_signatories
    WHERE office_id = ?
");
$sigStmt->execute([$s['office_id']]);
$sig = $sigStmt->fetch();

$signatoryName  = $sig['signatory_name']  ?? 'TERENCE TEJADA';
$signatoryTitle = $sig['signatory_title'] ?? '';

$vehicleName = trim(($s['brand'] ?? '') . ' ' . ($s['model'] ?? ''));
$plateNo     = $s['plate_number'] ?? '—';
$driver      = $s['driver_name']  ?? '—';
$requestor   = $s['username']     ?? '—';
$office      = $s['office_name']  ?? '—';
$destination = $s['destination']  ?? '—';
$purpose     = $s['purpose']      ?? '—';
$fromDT      = fd($s['date_start']) . '  ' . ft($s['time_start']);
$toDT        = fd($s['date_end'])   . '  ' . ft($s['time_end']);
$generated   = date('F j, Y  g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Trip Ticket – <?= htmlspecialchars($reqNo) ?></title>
<style>
/* ── Reset ── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11pt;
    background: #ccc;
    color: #000;
}

/* ── Print toolbar ── */
.toolbar {
    background: #2c2c2c;
    padding: 10px 20px;
    display: flex;
    gap: 10px;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 100;
}
.toolbar button {
    padding: 7px 22px;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
    font-weight: 600;
}
.btn-print { background: #800000; color: #fff; }
.btn-print:hover { background: #600000; }
.btn-close { background: #555; color: #fff; }
.btn-close:hover { background: #333; }

/* ── A4 page ── */
.page {
    width: 210mm;
    min-height: 297mm;
    background: #fff;
    margin: 12mm auto;
    padding: 10mm 12mm 10mm;
}

/* ════ HEADER ════ */
.hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 3px;
}
.hdr-logo-wrap { width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.hdr-logo { width: 68px; height: 68px; object-fit: contain; }
.hdr-mid  { flex: 1; text-align: center; padding: 0 10px; line-height: 1.35; }
.hdr-republic { font-size: 9pt; }
.hdr-uni  { font-size: 14pt; font-weight: 900; letter-spacing: .5px; text-decoration: underline; text-transform: uppercase; }
.hdr-dept { font-size: 10.5pt; font-weight: 700; letter-spacing: 3.5px; text-transform: uppercase; }
.hdr-addr { font-size: 8.5pt; color: #444; margin-top: 1px; }

.rule-red  { border: none; border-top: 3.5px solid #c00000; margin: 4px 0 1.5px; }
.rule-navy { border: none; border-top: 2px solid #00008b;   margin: 0 0 6px; }

/* ════ TITLE BAR ════ */
.title-bar {
    display: flex;
    border: 2px solid #000;
}
.title-left {
    flex: 1;
    background: #00008b;
    color: #fff;
    font-size: 24pt;
    font-weight: 900;
    letter-spacing: 1px;
    padding: 8px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: Arial Black, Arial, sans-serif;
    text-align: center;
}
.title-right {
    min-width: 140px;
    background: #e8a000;
    padding: 6px 12px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    text-align: center;
    border-left: 2px solid #000;
}
.tn-label { font-size: 8.5pt; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
.tn-value { font-size: 13pt; font-weight: 900; margin-top: 3px; }

/* ════ INFO TABLE ════ */
.info-table {
    width: 100%;
    border-collapse: collapse;
    border: 2px solid #000;
    border-top: none;
}
.info-table td {
    border: 1px solid #000;
    padding: 4px 8px;
    vertical-align: middle;
    font-size: 10.5pt;
}
.lbl {
    background: #c8c8c8;
    font-weight: 700;
    font-size: 9.5pt;
    white-space: nowrap;
    width: 118px;
}
/* Duration row */
.dur-lbl { background: #c8c8c8; font-weight: 700; font-size: 9.5pt; width: 118px; }
.dur-hd  { font-weight: 700; font-size: 10.5pt; }
.dur-sub { font-size: 8pt; color: #555; margin-top: 1px; }

/* Passengers */
.pass-lbl {
    background: #c8c8c8;
    font-weight: 700;
    font-size: 9.5pt;
    vertical-align: top;
    padding-top: 6px;
    width: 118px;
}
.pass-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
}
.pass-item {
    padding: 3px 6px;
    font-size: 9.5pt;
    min-height: 19px;
    border-bottom: 1px solid #bbb;
}
.pass-item:nth-child(odd) { border-right: 1px solid #bbb; }

/* Remarks */
.remarks-td { min-height: 50px; vertical-align: top; font-size: 10.5pt; }

/* ════ APPROVED BY ════ */
.appr-bar {
    background: #00008b;
    color: #fff;
    text-align: center;
    font-weight: 700;
    font-size: 10pt;
    letter-spacing: 2.5px;
    padding: 5px;
    border: 2px solid #000;
    border-top: none;
    text-transform: uppercase;
}
.appr-name-wrap {
    text-align: center;
    padding: 10px 0 8px;
    border: 2px solid #000;
    border-top: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.appr-name { font-weight: 900; font-size: 11pt; letter-spacing: .5px; text-transform: uppercase; }
.appr-line { display: inline-block; border-top: 1.5px solid #000; min-width: 240px; margin-top: 4px; padding-top: 2px; }
.appr-sub  { font-size: 8pt; color: #333; }

/* ════ BOTTOM TABLE ════ */
.bot-table {
    width: 100%;
    border-collapse: collapse;
    border: 2px solid #000;
    border-top: none;
}
.bot-table td {
    border: 1px solid #000;
    padding: 3.5px 7px;
    font-size: 9.5pt;
    vertical-align: middle;
}
.blbl { font-weight: 700; font-size: 9pt; }
.bval { min-width: 60px; }
.gas-hdr {
    background: #c8c8c8;
    font-weight: 700;
    font-size: 9pt;
    text-align: center;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* ════ SIGNATURE ROW ════ */
.sig-wrap {
    display: flex;
    align-items: stretch;
    border: 2px solid #000;
    border-top: none;
}
.sig-left, .sig-right {
    flex: 1;
    padding: 6px 10px 5px;
    font-size: 8.5pt;
    color: #333;
    display: grid;
    grid-template-rows: 1fr auto auto;
    height: 90px;
}
.sig-cert { font-size: 8.5pt; color: #333; align-self: start; }
.sig-left { border-right: 2px solid #000; }
.sig-dname {
    font-weight: 900;
    font-size: 10pt;
    color: #000;
    margin: 0 0 2px;
    text-transform: uppercase;
    text-align: center;
}
.sig-role-bar, .sig-right-role-bar {
    font-weight: 700;
    font-size: 9pt;
    color: #000;
    background: #c8c8c8;
    text-align: center;
    padding: 3px;
    border-top: 1.5px solid #000;
    margin-top: 2px;
}

/* ════ FOOTER ════ */
.page-footer {
    font-size: 7.5pt;
    color: #666;
    text-align: center;
    margin-top: 6px;
    font-style: italic;
}

/* ════ PRINT ════ */
@media print {
    body    { background: #fff; }
    .toolbar { display: none !important; }
    .page   { margin: 0; padding: 8mm 10mm; width: 100%; box-shadow: none; }
    @page   { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ &nbsp;Print Trip Ticket</button>
    <button class="btn-close" onclick="window.close()">✕ &nbsp;Close</button>
</div>

<div class="page">

    <!-- ══ HEADER ══ -->
    <div class="hdr">
        <div class="hdr-logo-wrap"><img src="../image/Csu.png"  alt="CSU Logo"  class="hdr-logo"></div>
        <div class="hdr-mid">
            <div class="hdr-republic">Republic of the Philippines</div>
            <div class="hdr-uni">Cagayan State University</div>
            <div class="hdr-dept">Auxiliary Services Office</div>
            <div class="hdr-addr">Caritan Sur, Tuguegarao City, Cagayan</div>
        </div>
        <div class="hdr-logo-wrap"><img src="../image/auxi.png" alt="Auxi Logo" class="hdr-logo"></div>
    </div>
    <hr class="rule-red">
    <hr class="rule-navy">

    <!-- ══ TITLE BAR ══ -->
    <div class="title-bar">
        <div class="title-left">TRIP TICKET</div>
        <div class="title-right">
            <div class="tn-label">Trip Ticket #</div>
            <div class="tn-value"><?= htmlspecialchars($tripTicketNo) ?></div>
        </div>
    </div>

    <!-- ══ INFO TABLE ══ -->
    <table class="info-table">

        <!-- Row 1: Request No + Office -->
        <tr>
            <td class="lbl">REQUEST NO.:</td>
            <td style="width:36%"><?= htmlspecialchars($reqNo) ?></td>
            <td class="lbl" style="width:80px">OFFICE:</td>
            <td><?= htmlspecialchars($office) ?></td>
        </tr>

        <!-- Row 2: Requestor -->
        <tr>
            <td class="lbl">REQUESTOR:</td>
            <td colspan="3"><?= htmlspecialchars($requestor) ?></td>
        </tr>

        <!-- Row 3: Driver + Vehicle -->
        <tr>
            <td class="lbl">DRIVER:</td>
            <td><?= htmlspecialchars($driver) ?></td>
            <td class="lbl">VEHICLE:</td>
            <td><?= htmlspecialchars($vehicleName ?: '—') ?></td>
        </tr>

        <!-- Row 4: Plate -->
        <tr>
            <td class="lbl">PLATE NO.:</td>
            <td colspan="3"><?= htmlspecialchars($plateNo) ?></td>
        </tr>

        <!-- Row 5: Destination -->
        <tr>
            <td class="lbl">DESTINATION:</td>
            <td colspan="3"><?= htmlspecialchars($destination) ?></td>
        </tr>

        <!-- Row 6: Purpose (taller) -->
        <tr>
            <td class="lbl" style="vertical-align:top;padding-top:5px">PURPOSE<br>OF TRAVEL:</td>
            <td colspan="3" style="height:58px; vertical-align:top; padding-top:5px">
                <?= htmlspecialchars($purpose) ?>
            </td>
        </tr>

        <!-- Row 7: Duration -->
        <tr>
            <td class="dur-lbl">DURATION:</td>
            <td>
                <div class="dur-hd">FROM: &nbsp;<?= htmlspecialchars($fromDT) ?></div>
                <div class="dur-sub">Date &amp; Time of Departure</div>
            </td>
            <td colspan="2">
                <div class="dur-hd">TO: &nbsp;<?= htmlspecialchars($toDT) ?></div>
                <div class="dur-sub">Date &amp; Time of Return</div>
            </td>
        </tr>

        <!-- Row 8: Passengers -->
        <tr>
            <td class="pass-lbl">NAME OF<br>PASSENGERS:</td>
            <td colspan="3" style="padding:0">
                <div class="pass-grid">
                    <?php for ($i = 1; $i <= 20; $i++): ?>
                    <div class="pass-item"><?= $i ?>.</div>
                    <?php endfor; ?>
                </div>
            </td>
        </tr>

        <!-- Row 9: Remarks -->
        <tr>
            <td class="lbl" style="vertical-align:top;padding-top:5px">REMARKS /<br>NOTES:</td>
            <td colspan="3" class="remarks-td" style="height:52px">&nbsp;</td>
        </tr>

    </table>

    
   <!-- ══ APPROVED BY ══ -->
    <div class="appr-bar">A P P R O V E D &nbsp; B Y :</div>
    <div class="appr-name-wrap">
        <div class="appr-name"><?= htmlspecialchars(strtoupper($signatoryName)) ?></div>
        <div class="appr-line">
            <?php if ($signatoryTitle): ?>
            <div style="font-size:9.5pt; font-weight:700; color:#222; margin-bottom:2px;">
                <?= htmlspecialchars($signatoryTitle) ?>
            </div>
            <?php endif; ?>
            <div class="appr-sub">Name / Signature over Printed Name</div>
        </div>
    </div>
    <!-- ══ BOTTOM DETAILS ══ -->
    <table class="bot-table">
        <tr>
            <td class="blbl" style="width:28%">Approximate Distance</td>
            <td class="bval" style="width:14%"></td>
            <td class="gas-hdr" colspan="2">Gasoline (Approximation)</td>
        </tr>
        <tr>
            <td class="blbl">Gear Oil Used / ATF</td>
            <td class="bval"></td>
            <td class="blbl" style="width:28%">Balance in Tank</td>
            <td class="bval" style="width:14%"></td>
        </tr>
        <tr>
            <td class="blbl">Lubricant Oil Used</td>
            <td class="bval"></td>
            <td class="blbl">Purchased During Trip</td>
            <td class="bval"></td>
        </tr>
        <tr>
            <td class="blbl">Grase Used</td>
            <td class="bval"></td>
            <td class="blbl">Issued by Office from Stock</td>
            <td class="bval"></td>
        </tr>
        <tr>
            <td class="blbl">Odometer</td>
            <td class="bval"></td>
            <td class="blbl">Total Before Travel</td>
            <td class="bval"></td>
        </tr>
        <tr>
            <td class="blbl">Before Travel (A)</td>
            <td class="bval"></td>
            <td class="blbl">Consumed During Trip</td>
            <td class="bval"></td>
        </tr>
        <tr>
            <td class="blbl">After Travel (B)</td>
            <td class="bval"></td>
            <td class="blbl">Balance After Travel</td>
            <td class="bval"></td>
        </tr>
        <tr>
            <td class="blbl">Distance (B – A)</td>
            <td class="bval"></td>
            <td class="blbl">Condition of<br>Vehicle After Travel</td>
            <td class="bval"></td>
        </tr>
    </table>

    <!-- ══ SIGNATURES ══ -->
    <div class="sig-wrap">
        <div class="sig-left">
            <div class="sig-cert">I hereby certify to the correctness of the above details of travel</div>
            <div class="sig-dname"><?= htmlspecialchars($driver) ?></div>
            <div class="sig-role-bar">Driver</div>
        </div>
        <div class="sig-right">
            <div class="sig-cert">I hereby certify to that I/we used this vehicle on OFFICIAL BUSINESS ONLY as stated above.</div>
            <div class="sig-dname"><?= htmlspecialchars($requestor) ?></div>
            <div class="sig-right-role-bar">Name and Signature of passenger(s)</div>
        </div>
    </div>

    <!-- ══ FOOTER ══ -->
    <div class="page-footer">
        Generated: <?= $generated ?> &nbsp;|&nbsp;
        Request #<?= htmlspecialchars($reqNo) ?> &nbsp;|&nbsp;
        Trip Ticket #: <?= htmlspecialchars($tripTicketNo) ?> &nbsp;|&nbsp;
        Not valid without an authorized signature.
    </div>

</div><!-- /.page -->
</body>
</html>