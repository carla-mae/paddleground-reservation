<?php
require_once '../config/session_check.php';
require_role(['staff']);
include '../config/db.php';

$activePage = 'report';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Staff should only ever see bookings the admin has already approved —
// never ones still 'awaiting_confirmation'/pending admin review.
$sql = "SELECT b.booking_id, u.full_name, c.court_name, s.date, s.start_time, s.end_time, b.status, p.amount, p.verified, p.method
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN courts c ON s.court_id = c.court_id
        LEFT JOIN payments p ON b.booking_id = p.booking_id
        WHERE s.date = ? AND b.status = 'approved'
        ORDER BY c.court_name ASC, s.start_time ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $date);
$stmt->execute();
$result = $stmt->get_result();

$rows = $result->fetch_all(MYSQLI_ASSOC);
$total = 0;
foreach ($rows as $r) {
    $total += (float)($r['amount'] ?? 0);
}

// Display labels for the raw `method` column values, same mapping used on
// the customer's My Bookings page and the admin's All Bookings page, so the
// "how did they pay" wording reads identically everywhere in the app.
$methodLabels = ['cash' => 'Cash', 'gcash' => 'GCash', 'maribank' => 'MariBank'];

$dateLabel = date('F j, Y', strtotime($date));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Report</title>
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
        max-width: 900px;
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
    }
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
        max-width: 900px;
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

    /* Court sub-grouping within the date */
    .court-divider td {
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
    .court-divider:first-child td { border-top: none; }

    .total-card {
        max-width: 900px;
        margin-top: 20px;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 12px;
        padding: 20px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 12px 30px -18px rgba(23, 48, 31, 0.16);
    }
    .total-card .label {
        font-size: 13px;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .total-card .value {
        font-size: 26px;
        font-weight: 800;
        color: var(--brand-green-dark);
    }

    .site-footer {
        text-align: center;
        color: var(--muted);
        font-size: 13px;
        margin-top: auto;
        padding-top: 20px;
    }

    /* ============ RESPONSIVE (screen only) ============ */

    /* Tablet: tighten spacing, let table/total-card use full available
       width instead of being capped at 900px. */
    @media screen and (max-width: 900px) {
        .main { padding: 28px 20px; }
        h2 { font-size: 23px; }
        table, .total-card { max-width: 100%; }
    }

    /* Small tablet / large phone: stack sidebar above content since we
       don't control staff_sidebar.php's own responsive behavior here. */
    @media screen and (max-width: 720px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 76px 16px 20px; min-height: auto; height: auto; overflow-y: visible; }
    }

    /* Phone: stack the toolbar, convert each row into a stacked card, and
       size everything UP (bigger text, bigger tap targets) so staff can
       read and use this without pinch-zooming. */
    @media screen and (max-width: 640px) {
        h2 { font-size: 21px; }
        .date-subtitle { font-size: 15px; margin-bottom: 20px; }

        .toolbar { flex-direction: column; align-items: stretch; gap: 10px; }
        .filter-form { flex-direction: column; align-items: stretch; gap: 10px; }
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

        table { border-radius: 12px; }
        table, thead, tbody, tr, td { display: block; width: 100%; }
        thead, th { display: none; }

        tr:not(.court-divider):not(.empty-row) {
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

        /* Court divider row acts as a plain block label, not a data row */
        tr.court-divider { padding: 0; border-bottom: none; }
        .court-divider td {
            display: block;
            text-align: left;
            font-size: 13px;
            padding: 12px 16px;
        }
        .court-divider td::before { content: none; }

        .empty-row td { display: block; text-align: center; padding: 24px 16px; }

        .total-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            padding: 18px 20px;
        }
        .total-card .label { font-size: 14px; }
        .total-card .value { font-size: 30px; }
    }

    /* ---------- PRINT / PDF EXPORT ---------- */
    /* This is what actually gets used when "Export as PDF" triggers window.print():
       the browser's print dialog lets the user pick "Save as PDF" as the destination.
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

        table {
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
        .court-divider td {
            background: #f1f1f1;
            color: #000;
        }

        .total-card {
            max-width: none;
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

<?php include '../includes/staff_sidebar.php'; ?>

<div class="main">
    <div class="main-inner">
    <!-- Shown only when printing/exporting, gives the PDF a proper letterhead -->
    <div class="print-header">
        <div class="brand">Paddle Ground Reservation</div>
        <div>Daily Rental Report &mdash; <?= htmlspecialchars($dateLabel) ?></div>
    </div>

    <h2>Daily Rental Report</h2>
    <p class="date-subtitle"><?= htmlspecialchars($dateLabel) ?></p>

    <div class="toolbar">
        <form method="GET" class="filter-form">
            <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
            <button type="submit">View</button>
        </form>

        <button type="button" class="export-btn" onclick="window.print()">
            &#128190; Export as PDF
        </button>
    </div>

    <table>
        <tr>
            <th>Customer</th>
            <th>Court</th>
            <th>Time</th>
            <th>Status</th>
            <th>Amount</th>
            <th>Verified/Paid</th>
        </tr>
        <?php if (empty($rows)): ?>
            <tr class="empty-row"><td colspan="6">No bookings found for this date.</td></tr>
        <?php else: ?>
            <?php $lastCourt = null; ?>
            <?php foreach ($rows as $row):
                $courtChanged = $row['court_name'] !== $lastCourt;
                $lastCourt = $row['court_name'];

                $isPaidVerified = (int)($row['verified'] ?? 0) === 1 && !empty($row['method']);
                $methodLabel = $isPaidVerified
                    ? ($methodLabels[$row['method']] ?? ucfirst($row['method']))
                    : null;
            ?>
            <?php if ($courtChanged): ?>
            <tr class="court-divider">
                <td colspan="6"><?= htmlspecialchars($row['court_name']) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td data-label="Customer"><?= htmlspecialchars($row['full_name']) ?></td>
                <td data-label="Court"><?= htmlspecialchars($row['court_name']) ?></td>
                <td data-label="Time"><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= date('g:i A', strtotime($row['end_time'])) ?></td>
                <td data-label="Status"><span class="status-pill status-<?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                <td data-label="Amount"><?= $row['amount'] !== null ? '&#8369;' . number_format((float)$row['amount'], 2) : '—' ?></td>
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

    <div class="total-card">
        <span class="label">Total Revenue</span>
        <span class="value">&#8369;<?= number_format($total, 2) ?></span>
    </div>

    <div class="site-footer">&copy; 2026 Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
    </div>
</div>

</body>
</html>