<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

$user_id = $_SESSION['user_id'];
$activePage = 'bookings';

// Group by date, then by court within each date, most recent date first.
$stmt = $conn->prepare(
    "SELECT b.booking_id, b.players, s.date, s.start_time, s.end_time, c.court_name, b.status, b.completed,
            p.payment_id, p.verified, p.method AS payment_method, p.refund_status
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.schedule_id
     JOIN courts c ON s.court_id = c.court_id
     LEFT JOIN payments p ON p.booking_id = b.booking_id
     WHERE b.user_id = ? AND b.status != 'awaiting_confirmation'
     ORDER BY s.date DESC, c.court_name ASC, s.start_time ASC"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Bookings</title>
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
        min-height: 100vh;
    }
    .main {
        flex-grow: 1;
        padding: 40px;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        min-width: 0;
        height: 100vh;
        overflow-y: auto;
    }
    .page-header {
        position: sticky;
        top: -40px;
        margin: -40px -40px 0;
        padding: 40px 40px 20px;
        background: var(--page-bg);
        z-index: 20;
    }
    .content { flex-grow: 1; min-width: 0; padding-top: 24px; }
    h2 { font-size: 28px; font-weight: 800; margin-bottom: 6px; }
    .subtitle { color: var(--muted); margin-bottom: 0; }

    /* Lets the table scroll sideways on narrow screens instead of squashing
       or overflowing the page. */
    .table-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    table {
        width: 100%;
        min-width: 720px;
        border-collapse: collapse;
    }
    th {
        text-align: left;
        font-size: 12px;
        letter-spacing: 0.5px;
        color: var(--muted);
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-soft);
        text-transform: uppercase;
        white-space: nowrap;
    }
    td {
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-soft);
        font-size: 14px;
        vertical-align: top;
    }
    tr:last-child td { border-bottom: none; }
    tr.clickable { cursor: pointer; }
    tr.clickable:hover { background: var(--page-bg); }

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
    .verified-refunded { color: #2563eb; font-weight: 700; }
    .verified-resend {
        color: #b45309;
        font-weight: 700;
        display: inline-block;
        max-width: 220px;
        line-height: 1.4;
    }
    .pay-hint { font-size: 11px; color: #9aa79f; margin-top: 4px; }
    .resend-hint { font-size: 11px; color: #b45309; margin-top: 4px; font-weight: 700; }

    /* Session status column: blank unless the staff has marked the
       reservation Done, in which case it reads clearly as finished. */
    .session-done {
        color: var(--brand-green-dark);
        font-weight: 700;
        font-size: 13px;
        line-height: 1.4;
        display: block;
        max-width: 200px;
    }
    /* Shown when the admin cancelled the booking because the customer took
       too long to pay in cash on-site — same red tone as .session-done so it
       reads consistently as "this is over / didn't happen", but the message
       is specific about *why* it was cancelled. */
    .session-cancelled {
        color: #b91c1c;
        font-weight: 700;
        font-size: 13px;
        line-height: 1.4;
        display: block;
        max-width: 200px;
    }
    /* Shown when the booking was refunded by the admin (e.g. rained out) —
       blue to match the "Refunded" tag, distinct from a late-cash cancel. */
    .session-refunded {
        color: #2563eb;
        font-weight: 700;
        font-size: 13px;
        line-height: 1.4;
        display: block;
        max-width: 200px;
    }
    .session-none { color: #9aa79f; font-size: 13px; }

    .method-tag {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        background: rgba(107,114,128,0.12);
        color: var(--muted);
        text-transform: uppercase;
    }

    /* Whole row tinted a subtle green once the session is done, so it
       visually separates itself from active/upcoming bookings at a glance. */
    tr.row-done td {
        background: rgba(22, 163, 74, 0.07);
    }
    tr.row-done.clickable:hover td {
        background: rgba(22, 163, 74, 0.12);
    }

    /* Whole row tinted light red once the booking is cancelled, so it reads
       clearly as "did not happen" and is never confused with the green
       row-done tint used for completed sessions. */
    tr.row-cancelled td {
        background: rgba(239, 68, 68, 0.06);
    }
    tr.row-cancelled.clickable:hover td {
        background: rgba(239, 68, 68, 0.1);
    }

    .empty-state { color: var(--muted); font-size: 14px; }

    /* Date grouping */
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
        gap: 10px;
        flex-wrap: wrap;
    }
    .date-header .done-tag {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        padding: 4px 10px;
        border-radius: 999px;
        display: none;
    }
    /* Past dates: light red tint on the header + a "Done" tag, since every
       booking for that day has already happened. */
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
        background: var(--card-bg);
    }
    .date-group table tr:last-child td { border-bottom: none; }

    /* Court sub-grouping within a date */
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
        white-space: nowrap;
    }
    .court-divider:first-child td { border-top: none; }

    /* Site footer: sits at the bottom of the page, consistent across
       customer-facing pages. */
    .site-footer {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 1px solid var(--border-soft);
        font-size: 13px;
        color: var(--muted);
        text-align: center;
    }

    /* ---------- RESPONSIVE ---------- */
    @media (max-width: 900px) {
        body { flex-direction: column; }
        .main { padding: 28px 20px; height: auto; overflow-y: visible; }
        .page-header {
            top: 0;
            margin: -28px -20px 0;
            padding: 76px 20px 16px;
        }
        .date-group { max-width: 100%; }
    }
    @media (max-width: 600px) {
        .main { padding: 20px 16px; }
        .page-header {
            margin: -20px -16px 0;
            padding: 72px 16px 14px;
        }
        h2 { font-size: 22px; }
        .subtitle { margin-bottom: 0; }
        .date-header { padding: 12px 14px; font-size: 14px; }
        table { min-width: 600px; }
        th, td { padding: 10px 12px; font-size: 13px; }
        .verified-resend { max-width: 160px; }
        .session-done, .session-cancelled, .session-refunded { max-width: 160px; }
        .site-footer { font-size: 12px; }
    }
</style>
</head>
<body>

<?php include '../includes/customer_sidebar.php'; ?>

<div class="main">
    <div class="page-header">
        <h2>My Bookings</h2>
        <p class="subtitle">All your court reservations, past and upcoming.</p>
    </div>
    <div class="content">

        <?php if (empty($bookings)): ?>
            <p class="empty-state">You haven't made any bookings yet.</p>
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
                    <div class="table-scroll">
                    <table>
                        <tr>
                            <th>Payment Method</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Status</th>
                            <th>Verified/Paid</th>
                            <th>Session Status</th>
                        </tr>
                        <?php $lastCourt = null; ?>
                        <?php foreach ($rows as $b):
                            $hasPayment  = $b['payment_id'] !== null;
                            $verifiedVal = $hasPayment ? (int)$b['verified'] : null;
                            $isRefunded  = $hasPayment && (int)$b['refund_status'] === 1;

                            // A refunded payment always means the booking is
                            // effectively cancelled, even if b.status hasn't
                            // been updated for some older records.
                            $effectiveStatus = $isRefunded ? 'cancelled' : $b['status'];
                            $isCancelled = $effectiveStatus === 'cancelled';
                            $isDone      = (bool)$b['completed'];

                            $statusClass = 'badge-pending';
                            if ($effectiveStatus === 'approved') $statusClass = 'badge-approved';
                            if ($isCancelled) $statusClass = 'badge-cancelled';

                            $clickable = false;

                            if ($isRefunded) {
                                $verifiedLabel = 'Refunded';
                                $verifiedClass = 'verified-refunded';
                                $hint = '';
                            } elseif ($isCancelled) {
                                $verifiedLabel = 'No';
                                $verifiedClass = 'verified-no';
                                $hint = '';
                            } elseif (!$hasPayment) {
                                $clickable = true;
                                $verifiedLabel = 'No';
                                $verifiedClass = 'verified-no';
                                $hint = '<div class="pay-hint">Click to pay &rarr;</div>';
                            } elseif ($verifiedVal === 1) {
                                $verifiedLabel = 'Yes';
                                $verifiedClass = 'verified-yes';
                                $hint = '';
                            } elseif ($verifiedVal === 2) {
                                $clickable = true;
                                $verifiedLabel = 'You need to send a valid receipt for you to confirm your schedule';
                                $verifiedClass = 'verified-resend';
                                $hint = '<div class="resend-hint">Click to resend your receipt &rarr;</div>';
                            } else {
                                $verifiedLabel = 'No';
                                $verifiedClass = 'verified-no';
                                $hint = '<div class="pay-hint">Awaiting staff verification</div>';
                            }

                            // A completed session is no longer clickable to pay/resend —
                            // it already happened, there's nothing left to do with it.
                            if ($isDone) { $clickable = false; }

                            // A cancelled booking that already had a CASH payment record
                            // attached to it can only have gotten there via the admin's
                            // "Cancel Booking" action on verify_payment.php (late cash
                            // pay-up) — a plain customer self-cancel happens earlier, while
                            // the booking is still 'awaiting_confirmation' and before any
                            // payment row exists. That distinction is what drives the
                            // message below. This never applies to refunded bookings —
                            // those get their own rain-cancellation message instead.
                            $cancelledForLateCash = !$isRefunded && $isCancelled && $hasPayment && $b['payment_method'] === 'cash';

                            $methodLabels = ['cash' => 'Cash', 'gcash' => 'GCash', 'maribank' => 'MariBank'];
                            $methodLabel = $hasPayment
                                ? ($methodLabels[$b['payment_method']] ?? ucfirst($b['payment_method']))
                                : null;

                            $courtChanged = $b['court_name'] !== $lastCourt;
                            $lastCourt = $b['court_name'];

                            // Row background tint: green once the session is done,
                            // light red once the booking is cancelled. Never both —
                            // a cancelled booking cannot also be marked completed.
                            $rowClasses = [];
                            if ($clickable) $rowClasses[] = 'clickable';
                            if ($isDone) $rowClasses[] = 'row-done';
                            if ($isCancelled) $rowClasses[] = 'row-cancelled';
                        ?>
                        <?php if ($courtChanged): ?>
                        <tr class="court-divider">
                            <td colspan="6"><?= htmlspecialchars($b['court_name']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="<?= htmlspecialchars(implode(' ', $rowClasses)) ?>"
                            <?= $clickable ? 'onclick="window.location.href=\'payment.php?booking_id=' . (int)$b['booking_id'] . '\'"' : '' ?>>
                            <td>
                                <?php if ($methodLabel !== null): ?>
                                    <span class="method-tag"><?= htmlspecialchars($methodLabel) ?></span>
                                <?php else: ?>
                                    <span class="session-none">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('g:i A', strtotime($b['start_time'])) ?></td>
                            <td><?= date('g:i A', strtotime($b['end_time'])) ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= htmlspecialchars(ucfirst($effectiveStatus)) ?></span></td>
                            <td>
                                <span class="<?= $verifiedClass ?>"><?= htmlspecialchars($verifiedLabel) ?></span>
                                <?= $hint ?>
                            </td>
                            <td>
                                <?php if ($isRefunded): ?>
                                    <span class="session-refunded">There's no playing right now because it's raining. Your payment has been refunded.</span>
                                <?php elseif ($cancelledForLateCash): ?>
                                    <span class="session-cancelled">Your booking was cancelled because you were too late to pay.</span>
                                <?php elseif ($isDone): ?>
                                    <span class="session-done">Your time is already done. This booking is done.</span>
                                <?php else: ?>
                                    <span class="session-none">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <footer class="site-footer">
        &copy; <?= date('Y') ?> Paddle Ground Reservation &middot; San Francisco, Agusan del Sur
    </footer>
</div>

</body>
</html>