<?php
require_once '../config/session_check.php';
require_role(['admin']);
include '../config/db.php';

$activePage = 'bookings';

// Excludes customer self-cancels (cancelled_by = 'customer') — those should
// be invisible to admin/staff. Admin-initiated cancellations (cancelled_by
// = 'admin', e.g. disapprove, late-cash cancel, or refund) still show up
// normally.
$sql = "SELECT b.booking_id, u.full_name, c.court_name, s.date, s.start_time, s.end_time,
               b.status, b.total_price, p.payment_id, p.verified, p.method, p.refund_status
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN courts c ON s.court_id = c.court_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        WHERE NOT (b.status = 'cancelled' AND b.cancelled_by = 'customer')
        ORDER BY s.date DESC, c.court_name ASC, s.start_time ASC";
$result = $conn->query($sql);
$bookings = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Map raw method values (however they're stored) to display labels
function formatPaymentMethod($method) {
    $method = strtolower(trim((string)$method));
    $labels = [
        'gcash'    => 'GCash',
        'maribank' => 'Maribank',
        'cash'     => 'Cash',
    ];
    return $labels[$method] ?? ucfirst($method);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>All Bookings</title>
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
    html { -webkit-text-size-adjust: 100%; }
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
        min-width: 0;
        height: 100vh;
        overflow-y: auto;
    }
    .main-inner {
        max-width: 1100px;
        width: 100%;
        margin: 0 auto;
    }
    h2 { font-size: 28px; font-weight: 800; margin-bottom: 6px; }
    .subtitle { color: var(--muted); margin-bottom: 28px; }

    table {
        width: 100%;
        max-width: 1100px;
        border-collapse: collapse;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        overflow: hidden;
    }
    th {
        text-align: left;
        font-size: 12px;
        letter-spacing: 0.5px;
        color: var(--muted);
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-soft);
        text-transform: uppercase;
    }
    td {
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-soft);
        font-size: 14px;
    }
    tr:last-child td { border-bottom: none; }

    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
    }
    .badge-pending { background: rgba(234,179,8,0.14); color: #b45309; }
    .badge-approved { background: rgba(22,163,74,0.14); color: var(--brand-green-dark); }
    .badge-cancelled { background: rgba(239,68,68,0.12); color: #b91c1c; }

    .verified-yes { color: var(--brand-green-dark); font-weight: 700; }
    .verified-no { color: var(--muted); font-weight: 700; }
    .refunded-yes { color: #2563eb; font-weight: 700; }
    .method-tag {
        display: block;
        font-size: 11px;
        color: #9aa79f;
        font-weight: 500;
        margin-top: 2px;
    }

    tr.row-approved td {
        background: rgba(22, 163, 74, 0.06);
    }
    tr.row-cancelled td {
        background: rgba(239, 68, 68, 0.06);
    }

    .empty-state { color: var(--muted); font-size: 14px; }

    .date-group {
        max-width: 1100px;
        margin-bottom: 24px;
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 12px 30px -18px rgba(23, 48, 31, 0.16);
    }
    .date-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 20px;
        background: var(--page-bg);
        border-bottom: 1px solid var(--border-soft);
        font-size: 15px;
        font-weight: 800;
    }
    .date-header .done-tag {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        padding: 4px 10px;
        border-radius: 999px;
        display: none;
    }
    .date-group.past .date-header {
        background: rgba(239, 68, 68, 0.08);
        border-bottom-color: rgba(239, 68, 68, 0.25);
    }
    .date-group.past .date-header .done-tag {
        display: inline-block;
        background: rgba(239, 68, 68, 0.14);
        color: #b91c1c;
    }
    .date-group table {
        border: none;
        border-radius: 0;
        max-width: none;
    }
    .date-group table tr:last-child td { border-bottom: none; }

    .court-divider td {
        padding: 8px 18px;
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

    .site-footer {
        text-align: center;
        color: var(--muted);
        font-size: 13px;
        margin-top: 40px;
        padding-top: 20px;
    }

    @media (max-width: 900px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 28px 20px; height: auto; overflow-y: visible; }
    }

    @media (max-width: 700px) {
        .main { padding: 76px 14px 20px; }
        h2 { font-size: 22px; }
        .subtitle { font-size: 13.5px; margin-bottom: 20px; }

        .date-group, table { max-width: none; }
        .date-header { padding: 12px 16px; font-size: 14px; }

        table, thead, tbody, tr, th, td { display: block; }
        thead { display: none; }

        .court-divider { padding: 0; }
        .court-divider td {
            display: block;
            border-top: none;
        }

        tr:not(.court-divider) {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-soft);
        }
        tr:not(.court-divider) td {
            padding: 4px 0;
            border-bottom: none;
            font-size: 13.5px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            text-align: right;
        }
        tr:not(.court-divider) td::before {
            content: attr(data-label);
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            text-align: left;
            flex-shrink: 0;
        }
        .badge { font-size: 11px; }
        .method-tag { text-align: right; }
    }

    @supports (padding: max(0px)) {
        .main { padding-left: max(40px, env(safe-area-inset-left)); padding-right: max(40px, env(safe-area-inset-right)); }
    }
    @media (max-width: 900px) {
        @supports (padding: max(0px)) {
            .main { padding-left: max(28px, env(safe-area-inset-left)); padding-right: max(28px, env(safe-area-inset-right)); }
        }
    }
    @media (max-width: 700px) {
        @supports (padding: max(0px)) {
            .main {
                padding-left: max(14px, env(safe-area-inset-left));
                padding-right: max(14px, env(safe-area-inset-right));
                padding-top: max(76px, calc(env(safe-area-inset-top) + 60px));
            }
        }
    }
</style>
</head>
<body>

<?php include '../includes/admin_sidebar.php'; ?>

<div class="main">
    <div class="main-inner">
    <h2>All Bookings</h2>
    <p class="subtitle">Every reservation across all customers.</p>

    <?php if (empty($bookings)): ?>
        <p class="empty-state">No bookings have been made yet.</p>
    <?php else: ?>
        <?php
            $groups = [];
            foreach ($bookings as $row) {
                $groups[$row['date']][] = $row;
            }
            $today = date('Y-m-d');
        ?>
        <?php foreach ($groups as $groupDate => $rows): ?>
            <?php $isPast = $groupDate < $today; ?>
            <div class="date-group<?= $isPast ? ' past' : '' ?>">
                <div class="date-header">
                    <span><?= date('F j, Y', strtotime($groupDate)) ?></span>
                    <span class="done-tag">Done</span>
                </div>
                <table>
                    <tr>
                        <th>Customer</th>
                        <th>Court</th>
                        <th>Time</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Paid / Verified</th>
                    </tr>
                    <?php $lastCourt = null; ?>
                    <?php foreach ($rows as $row):
                        $hasPayment = $row['payment_id'] !== null;
                        $isVerified = $hasPayment && (int)$row['verified'] === 1;
                        $isRefunded = $hasPayment && (int)$row['refund_status'] === 1;
                        $methodLabel = $hasPayment ? formatPaymentMethod($row['method']) : '';

                        // A refunded payment always means the booking is
                        // effectively cancelled, even if b.status hasn't
                        // been updated for some older records.
                        $effectiveStatus = $isRefunded ? 'cancelled' : $row['status'];

                        $statusClass = 'badge-pending';
                        if ($effectiveStatus === 'approved') $statusClass = 'badge-approved';
                        if ($effectiveStatus === 'cancelled') $statusClass = 'badge-cancelled';

                        $rowClass = '';
                        if ($effectiveStatus === 'approved') $rowClass = 'row-approved';
                        if ($effectiveStatus === 'cancelled') $rowClass = 'row-cancelled';

                        $courtChanged = $row['court_name'] !== $lastCourt;
                        $lastCourt = $row['court_name'];
                    ?>
                    <?php if ($courtChanged): ?>
                    <tr class="court-divider">
                        <td colspan="6"><?= htmlspecialchars($row['court_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="<?= htmlspecialchars($rowClass) ?>">
                        <td data-label="Customer"><?= htmlspecialchars($row['full_name']) ?></td>
                        <td data-label="Court"><?= htmlspecialchars($row['court_name']) ?></td>
                        <td data-label="Time"><?= date('g:i A', strtotime($row['start_time'])) ?> – <?= date('g:i A', strtotime($row['end_time'])) ?></td>
                        <td data-label="Total">&#8369;<?= number_format((float)$row['total_price'], 2) ?></td>
                        <td data-label="Status"><span class="badge <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($effectiveStatus)) ?></span></td>
                        <td data-label="Paid / Verified">
                            <?php if (!$hasPayment): ?>
                                <span class="verified-no">Not paid</span>
                            <?php elseif ($isRefunded): ?>
                                <span class="refunded-yes">Refunded</span>
                                <span class="method-tag">via <?= htmlspecialchars($methodLabel) ?></span>
                            <?php elseif ($isVerified): ?>
                                <span class="verified-yes">Paid thru <?= htmlspecialchars($methodLabel) ?></span>
                            <?php else: ?>
                                <span class="verified-no">Awaiting verification</span>
                                <span class="method-tag">via <?= htmlspecialchars($methodLabel) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="site-footer">&copy; 2026 Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
    </div>
</div>

</body>
</html>