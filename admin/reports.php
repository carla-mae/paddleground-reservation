<?php
require_once '../config/session_check.php';
require_role(['admin']);
include '../config/db.php';

$activePage = 'reports';

// ---------- Period + date range ----------
$periodLabels = [
    'daily'   => 'Daily',
    'weekly'  => 'Weekly',
    'monthly' => 'Monthly',
    'yearly'  => 'Yearly',
];

$periodParam = $_GET['period'] ?? 'daily';

// The period <select> has its own flat "Court 1 / Court 2 / ..." entries
// alongside Daily/Weekly/Monthly/Yearly (Yearly itself carries no court
// filter — it always shows every court combined, same as before).
// Picking a court shows ALL bookings ever made for that one court.
$courtId = 'all';
if (preg_match('/^court-(\d+)$/', $periodParam, $m)) {
    $period  = 'court';
    $courtId = (int) $m[1];
} else {
    $period = array_key_exists($periodParam, $periodLabels) ? $periodParam : 'daily';
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$refTs = strtotime($date);

switch ($period) {
    case 'weekly':
        $dow       = (int) date('N', $refTs); // 1 (Mon) .. 7 (Sun)
        $startTs   = strtotime('-' . ($dow - 1) . ' days', $refTs);
        $endTs     = strtotime('+' . (7 - $dow) . ' days', $refTs);
        $startDate = date('Y-m-d', $startTs);
        $endDate   = date('Y-m-d', $endTs);
        $rangeLabel = date('F j', $startTs) . ' – ' . date('F j, Y', $endTs);
        break;

    case 'monthly':
        $startDate  = date('Y-m-01', $refTs);
        $endDate    = date('Y-m-t', $refTs);
        $rangeLabel = date('F Y', $refTs);
        break;

    case 'yearly':
        $startDate  = date('Y-01-01', $refTs);
        $endDate    = date('Y-12-31', $refTs);
        $rangeLabel = date('Y', $refTs);
        break;

    case 'court':
        // No date restriction — show every booking ever made for this court.
        $startDate  = '1970-01-01';
        $endDate    = '2099-12-31';
        $rangeLabel = 'All Time';
        break;

    case 'daily':
    default:
        $startDate  = $date;
        $endDate    = $date;
        $rangeLabel = date('l, F j, Y', $refTs);
        break;
}

// ---------- Court list (used for the flat Court dropdown entries) ----------
$courts = [];
$courtsResult = $conn->query("SELECT court_id, court_name FROM courts ORDER BY court_name ASC");
if ($courtsResult) {
    $courts = $courtsResult->fetch_all(MYSQLI_ASSOC);
}

$validCourtIds = array_map('intval', array_column($courts, 'court_id'));
if ($courtId !== 'all' && !in_array((int) $courtId, $validCourtIds, true)) {
    $courtId = 'all';
}

$selectedCourtName = null;
if ($courtId !== 'all') {
    foreach ($courts as $c) {
        if ((int) $c['court_id'] === (int) $courtId) {
            $selectedCourtName = $c['court_name'];
            break;
        }
    }
}

// ---------- Data ----------
//
// A booking the customer cancelled before ever paying (cancelled_by = 'customer')
// should stay invisible to admin — same rule used in payment.php. A booking
// cancelled by admin/staff themselves (e.g. no-show) still needs to show up
// here for the record, so only the customer-initiated ones are excluded.
$sql = "SELECT b.booking_id, u.full_name, c.court_name, s.date, s.start_time, s.end_time, b.status, p.amount, p.verified, p.method
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN courts c ON s.court_id = c.court_id
        LEFT JOIN payments p ON b.booking_id = p.booking_id
        WHERE s.date BETWEEN ? AND ?
          AND NOT (b.status = 'cancelled' AND b.cancelled_by = 'customer')";
$params = [$startDate, $endDate];
$types = "ss";

if ($period === 'court' && $courtId !== 'all') {
    $sql .= " AND c.court_id = ?";
    $params[] = (int) $courtId;
    $types .= "i";
}

$sql .= " ORDER BY s.date ASC, c.court_name ASC, s.start_time ASC";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = 0;
$totalBookings = count($rows);
foreach ($rows as $r) {
    $total += (float) ($r['amount'] ?? 0);
}

// ---------- Per-court breakdown (Yearly view only) ----------
// Yearly always shows every court combined here — this table is what lets
// the admin compare all courts side by side for the selected year.
$courtBreakdown = [];
if ($period === 'yearly') {
    $courtSql = "SELECT c.court_id, c.court_name,
                        COUNT(b.booking_id) AS bookings,
                        COALESCE(SUM(p.amount), 0) AS revenue
                 FROM bookings b
                 JOIN schedules s ON b.schedule_id = s.schedule_id
                 JOIN courts c ON s.court_id = c.court_id
                 LEFT JOIN payments p ON b.booking_id = p.booking_id
                 WHERE s.date BETWEEN ? AND ?
                   AND NOT (b.status = 'cancelled' AND b.cancelled_by = 'customer')
                 GROUP BY c.court_id, c.court_name
                 ORDER BY c.court_name ASC";
    $courtStmt = $conn->prepare($courtSql);
    $courtStmt->bind_param("ss", $startDate, $endDate);
    $courtStmt->execute();
    $courtBreakdown = $courtStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $courtStmt->close();
}

// Display labels for the raw `method` column values, same mapping used on
// the customer's My Bookings page and the admin's All Bookings page, so the
// "how did they pay" wording reads identically everywhere in the app.
$methodLabels = ['cash' => 'Cash', 'gcash' => 'GCash', 'maribank' => 'MariBank'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Generate Report</title>
<style>
    /* ---------- Brand palette (unified green, sage background) ---------- */
    :root {
        --brand-green: #16A34A;
        --brand-green-dark: #128A3E;
        --brand-ink: #17301F;
        --page-bg: #EAF1EC;
        --card-bg: #FFFFFF;
        --border-soft: #DDE6E0;
        --muted: #6B7A70;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: var(--page-bg);
        color: var(--brand-ink);
        display: flex;
        height: 100vh;
        overflow: hidden;
    }
    .main {
        flex-grow: 1;
        padding: 40px;
        display: flex;
        flex-direction: column;
        height: 100vh;
        overflow-y: auto;
        min-width: 0;
    }
    .main-inner {
        max-width: 1000px;
        width: 100%;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }
    h2 { font-size: 26px; font-weight: 800; margin-bottom: 6px; }
    .date-subtitle {
        color: var(--muted);
        font-size: 14px;
        margin-bottom: 24px;
    }

    .toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 24px;
    }
    .filter-form {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .filter-form select,
    .filter-form input[type="date"] {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        color: var(--brand-ink);
        border-radius: 8px;
        padding: 9px 12px;
        font-size: 14px;
        color-scheme: light;
    }
    .filter-form button {
        background: var(--brand-green);
        color: #ffffff;
        font-weight: 700;
        font-size: 13px;
        border: none;
        padding: 10px 18px;
        border-radius: 8px;
        cursor: pointer;
    }
    .filter-form button:hover { filter: brightness(1.08); }

    .export-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        color: var(--brand-ink);
        font-weight: 700;
        font-size: 13px;
        padding: 10px 18px;
        border-radius: 8px;
        cursor: pointer;
    }
    .export-btn:hover { border-color: var(--brand-green); color: var(--brand-green); }

    table {
        width: 100%;
        max-width: 1000px;
        border-collapse: collapse;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 12px;
        overflow: hidden;
    }
    th, td {
        text-align: left;
        padding: 12px 14px;
        font-size: 14px;
        border-bottom: 1px solid var(--border-soft);
    }
    th { background: var(--page-bg); color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
    tr:last-child td { border-bottom: none; }

    .status-pill {
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 999px;
        text-transform: capitalize;
    }
    .status-approved { background: rgba(22,163,74,0.14); color: var(--brand-green-dark); }
    .status-paid      { background: rgba(37,99,235,0.12); color: #2563eb; }
    .status-pending  { background: rgba(234,179,8,0.14); color: #b45309; }
    .status-cancelled{ background: rgba(107,114,128,0.12); color: var(--muted); }

    /* Verified/Paid column: plain colored text (not a pill), matching the
       admin's All Bookings page — "Paid thru Cash/GCash/MariBank" in green
       when paid & verified, muted grey "Not paid" otherwise. */
    .paid-thru {
        color: var(--brand-green-dark);
        font-weight: 700;
        font-size: 13px;
    }
    .not-paid {
        color: var(--muted);
        font-weight: 700;
        font-size: 13px;
    }

    .empty-row td {
        text-align: center;
        color: var(--muted);
        padding: 24px;
    }

    /* Date sub-grouping within the range */
    .date-divider td {
        padding: 8px 14px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        color: var(--muted);
        background: var(--page-bg);
        border-top: 2px solid var(--border-soft);
        border-bottom: 1px solid var(--border-soft);
    }
    .date-divider:first-child td { border-top: none; }

    .totals-row {
        max-width: 1000px;
        margin-top: 20px;
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }
    .total-card {
        flex: 1;
        min-width: 220px;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 12px;
        padding: 20px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 12px 30px -18px rgba(23, 48, 31, 0.16);
    }
    .total-card.highlight { border-color: var(--brand-green); }
    .total-card .label {
        font-size: 13px;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .total-card .value {
        font-size: 26px;
        font-weight: 800;
        color: var(--brand-ink);
    }
    .total-card.highlight .value { color: var(--brand-green-dark); }

    /* Per-court breakdown table */
    .section-heading {
        font-size: 16px;
        font-weight: 800;
        margin: 32px 0 12px;
        max-width: 1000px;
    }
    .court-table {
        width: 100%;
        max-width: 1000px;
        border-collapse: collapse;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 12px;
        overflow: hidden;
    }
    .court-table th, .court-table td {
        text-align: left;
        padding: 12px 14px;
        font-size: 14px;
        border-bottom: 1px solid var(--border-soft);
    }
    .court-table th { background: var(--page-bg); color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
    .court-table tr:last-child td { border-bottom: none; }
    .court-table .revenue-cell { color: var(--brand-green-dark); font-weight: 700; }

    .site-footer {
        text-align: center;
        color: var(--muted);
        font-size: 13px;
        margin-top: auto;
        padding-top: 20px;
    }

    /* ============ RESPONSIVE (screen only) ============ */

    /* Tablet: tighten spacing, let table/totals use full available
       width instead of being capped. */
    @media screen and (max-width: 900px) {
        .main { padding: 28px 20px; }
        h2 { font-size: 23px; }
        table, .totals-row, .court-table, .section-heading { max-width: 100%; }
    }

    /* Small tablet / large phone: stack sidebar above content since we
       don't control admin_sidebar.php's own responsive behavior here. */
    @media screen and (max-width: 720px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 20px 16px; min-height: auto; height: auto; overflow-y: visible; }
    }

    /* Phone: stack the toolbar, convert each row into a stacked card, and
       size everything UP (bigger text, bigger tap targets) so admins can
       read and use this without pinch-zooming. */
    @media screen and (max-width: 640px) {
        h2 { font-size: 21px; }
        .date-subtitle { font-size: 15px; margin-bottom: 20px; }

        .toolbar { flex-direction: column; align-items: stretch; gap: 10px; }
        .filter-form { flex-direction: column; align-items: stretch; gap: 10px; }
        .filter-form select,
        .filter-form input[type="date"] {
            font-size: 16px;
            padding: 12px 14px;
            width: 100%;
        }
        .filter-form button {
            font-size: 15px;
            padding: 13px 18px;
        }
        .export-btn {
            font-size: 15px;
            padding: 13px 18px;
            justify-content: center;
        }

        table, .court-table { border-radius: 12px; }
        table, .court-table, thead, tbody, tr, td { display: block; width: 100%; }
        thead, th { display: none; }

        tr:not(.date-divider):not(.empty-row) {
            padding: 12px 4px;
            border-bottom: 1px solid var(--border-soft);
        }
        tr:last-child { border-bottom: none; }

        td {
            border-bottom: none;
            padding: 8px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 16px;
            line-height: 1.4;
        }
        td::before {
            content: attr(data-label);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            flex-shrink: 0;
        }
        td[data-label]:empty::before { content: none; }

        .status-pill { font-size: 13px; padding: 5px 12px; }
        .paid-thru, .not-paid { font-size: 15px; }

        /* Date divider row acts as a plain block label, not a data row */
        tr.date-divider { padding: 0; border-bottom: none; }
        .date-divider td {
            display: block;
            text-align: left;
            font-size: 13px;
            padding: 12px 16px;
        }
        .date-divider td::before { content: none; }

        .empty-row td { display: block; text-align: center; padding: 24px 16px; }

        .totals-row { flex-direction: column; gap: 10px; }
        .total-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 18px 20px;
        }
        .total-card .label { font-size: 14px; }
        .total-card .value { font-size: 30px; }

        .section-heading { font-size: 15px; margin: 26px 0 10px; }
    }

    /* ---------- PRINT / PDF EXPORT ---------- */
    /* This is what actually gets used when "Export as PDF" triggers window.print():
       the browser's print dialog lets the admin pick "Save as PDF" as the destination.
       Everything not needed on paper (sidebar, filter form, export button) is hidden,
       and colors are flattened to plain black-on-white for reliable printing. */
    @media print {
        .sidebar, .sidebar-toggle, .filter-form, .export-btn, .site-footer {
            display: none !important;
        }
        body {
            display: block;
            background: #fff;
            color: #000;
            height: auto;
            overflow: visible;
        }
        .main {
            padding: 0;
            height: auto;
            overflow: visible;
        }
        .main-inner { max-width: none; }
        .date-subtitle { color: #444; }

        .print-header {
            display: block !important;
            margin-bottom: 16px;
        }
        .print-header .brand {
            font-size: 13px;
            font-weight: 800;
            color: #16a34a;
            margin-bottom: 4px;
        }

        table, .court-table {
            max-width: none;
            background: #fff;
            border: 1px solid #ccc;
        }
        th {
            background: #f1f1f1;
            color: #333;
        }
        td, th { border-bottom: 1px solid #ddd; color: #000; }

        .status-pill {
            background: none !important;
            border: 1px solid #999;
            color: #000 !important;
        }
        .paid-thru, .not-paid {
            color: #000 !important;
        }
        .date-divider td {
            background: #f1f1f1;
            color: #000;
        }
        .court-table .revenue-cell { color: #000; }
        .section-heading { color: #000; }

        .totals-row {
            max-width: none;
        }
        .total-card {
            background: #fff;
            border: 1px solid #ccc;
        }
        .total-card .value { color: #000; }
    }
    /* Hidden on screen; only shown inside @media print */
    .print-header { display: none; }
</style>
</head>
<body>

<?php include '../includes/admin_sidebar.php'; ?>

<?php
    // Title shown in the header — periodLabels doesn't have a 'court' entry
    // since that mode is really "Court X Report", not a time period.
    // Falls back to a generic label instead of an undefined-key warning if
    // $selectedCourtName somehow isn't set while period is 'court'.
    if ($period === 'court') {
        $reportTitle = htmlspecialchars($selectedCourtName ?? 'Court') . ' Report';
    } else {
        $reportTitle = htmlspecialchars($periodLabels[$period] ?? 'Daily') . ' Report';
    }
?>
<div class="main">
    <div class="main-inner">
    <!-- Shown only when printing/exporting, gives the PDF a proper letterhead -->
    <div class="print-header">
        <div class="brand">Paddle Ground Reservation</div>
        <div><?= $reportTitle ?> &mdash; <?= htmlspecialchars($rangeLabel) ?></div>
    </div>

    <h2><?= $reportTitle ?></h2>
    <p class="date-subtitle"><?= htmlspecialchars($rangeLabel) ?></p>

    <div class="toolbar">
        <form method="GET" class="filter-form">
            <select name="period">
                <option value="daily" <?= ($period === 'daily') ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= ($period === 'weekly') ? 'selected' : '' ?>>Weekly</option>
                <option value="monthly" <?= ($period === 'monthly') ? 'selected' : '' ?>>Monthly</option>
                <option value="yearly" <?= ($period === 'yearly') ? 'selected' : '' ?>>Yearly</option>
                <?php foreach ($courts as $c): ?>
                    <option value="court-<?= (int) $c['court_id'] ?>"
                        <?= ($period === 'court' && (string) $courtId === (string) $c['court_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['court_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
            <button type="submit">View</button>
        </form>

        <button type="button" class="export-btn" onclick="window.print()">
            &#128190; Export as PDF
        </button>
    </div>

    <table>
        <tr>
            <th>Date</th>
            <th>Customer</th>
            <th>Court</th>
            <th>Time</th>
            <th>Status</th>
            <th>Amount</th>
            <th>Verified/Paid</th>
        </tr>
        <?php if (empty($rows)): ?>
            <tr class="empty-row"><td colspan="7">No bookings found for this period.</td></tr>
        <?php else: ?>
            <?php $lastDate = null; ?>
            <?php foreach ($rows as $row):
                $dateChanged = $row['date'] !== $lastDate;
                $lastDate = $row['date'];

                $isPaidVerified = (int) ($row['verified'] ?? 0) === 1 && !empty($row['method']);
                $methodLabel = $isPaidVerified
                    ? ($methodLabels[$row['method']] ?? ucfirst($row['method']))
                    : null;
            ?>
            <?php if ($dateChanged && $period !== 'daily'): ?>
            <tr class="date-divider">
                <td colspan="7"><?= htmlspecialchars(date('F j, Y (D)', strtotime($row['date']))) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td data-label="Date"><?= date('M j, Y', strtotime($row['date'])) ?></td>
                <td data-label="Customer"><?= htmlspecialchars($row['full_name']) ?></td>
                <td data-label="Court"><?= htmlspecialchars($row['court_name']) ?></td>
                <td data-label="Time"><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= date('g:i A', strtotime($row['end_time'])) ?></td>
                <td data-label="Status"><span class="status-pill status-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                <td data-label="Amount"><?= $row['amount'] !== null ? '&#8369;' . number_format((float) $row['amount'], 2) : '—' ?></td>
                <td data-label="Verified/Paid">
                    <?php if ($methodLabel !== null): ?>
                        <span class="paid-thru">Paid thru <?= htmlspecialchars($methodLabel) ?></span>
                    <?php else: ?>
                        <span class="not-paid">Not paid</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>

    <div class="totals-row">
        <div class="total-card">
            <span class="label">Total Bookings</span>
            <span class="value"><?= (int) $totalBookings ?></span>
        </div>
        <div class="total-card highlight">
            <span class="label">Total Revenue</span>
            <span class="value">&#8369;<?= number_format($total, 2) ?></span>
        </div>
    </div>

    <?php if ($period === 'yearly'): ?>
    <!-- Per-court breakdown, shown only on the Yearly report — always
         compares every court together for the selected year. To see one
         court's own report (any date), pick that court from the dropdown
         above instead. -->
    <h3 class="section-heading">Revenue by Court &mdash; <?= htmlspecialchars($rangeLabel) ?></h3>
    <table class="court-table">
        <tr>
            <th>Court</th>
            <th>Bookings</th>
            <th>Revenue</th>
        </tr>
        <?php if (empty($courtBreakdown)): ?>
            <tr class="empty-row"><td colspan="3">No bookings found for this period.</td></tr>
        <?php else: ?>
            <?php foreach ($courtBreakdown as $cb): ?>
                <tr>
                    <td data-label="Court"><?= htmlspecialchars($cb['court_name']) ?></td>
                    <td data-label="Bookings"><?= (int) $cb['bookings'] ?></td>
                    <td data-label="Revenue" class="revenue-cell">&#8369;<?= number_format((float) $cb['revenue'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <div class="site-footer">&copy; 2026 Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
    </div>
</div>

</body>
</html>