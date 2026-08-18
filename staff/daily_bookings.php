<?php
require_once '../config/session_check.php';
require_role(['staff']);
include '../config/db.php';

$activePage = 'daily';

// Joined to payments so refunded bookings (status = 'cancelled' due to rain
// refund) still show up here, but flagged as Refunded/Cancelled instead of
// being filtered out like a normal cancellation.
//
// Staff should only ever see bookings the admin has already approved (or
// refunded) — never ones still 'awaiting_confirmation'/pending admin review.
$sql = "SELECT b.booking_id, u.full_name, c.court_name, s.date, s.start_time, s.end_time,
               b.status, b.completed, p.refund_status
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN courts c ON s.court_id = c.court_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        WHERE s.date = CURDATE() AND (b.status = 'approved' OR p.refund_status = 1)
        ORDER BY s.date ASC, c.court_name ASC, s.start_time ASC";
$result = $conn->query($sql);

// Pull everything into an array first (instead of looping the mysqli result
// directly in the markup) so it can be grouped by date, and within each
// date, by court, before rendering. Order is preserved as-is from the SQL
// ORDER BY (date, court, start_time), so grouping below just "buckets"
// already-sorted rows rather than re-sorting anything.
$bookings = [];
while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}

$grouped = [];
foreach ($bookings as $row) {
    $grouped[$row['date']][$row['court_name']][] = $row;
}

$nowManila = new DateTime('now', new DateTimeZone('Asia/Manila'));

// Statuses that count as "confirmed" and therefore eligible to be ended by staff
$endableStatuses = ['approved', 'paid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Today's Bookings</title>
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
    h2 { font-size: 26px; font-weight: 800; margin-bottom: 20px; }
    .date-subtitle {
        color: var(--muted);
        font-size: 14px;
        margin-top: -14px;
        margin-bottom: 20px;
    }

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

    /* Group header row shown once per date */
    .date-group-row td {
        background: var(--page-bg);
        color: var(--brand-ink);
        font-size: 13px;
        font-weight: 800;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-soft);
    }
    /* Sub-divider row shown once per court within a date group */
    .court-divider-row td {
        background: #F4F7F5;
        color: var(--brand-green-dark);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 8px 14px;
        border-bottom: 1px solid var(--border-soft);
    }
    .no-bookings-row td {
        color: var(--muted);
        font-size: 13px;
        padding: 16px 14px;
    }

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
    .status-refunded { background: rgba(37,99,235,0.12); color: #2563eb; }

    .end-badge {
        display: inline-block;
        font-size: 12px;
        font-weight: 700;
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid transparent;
    }
    .end-open {
        background: rgba(22,163,74,0.12);
        color: var(--brand-green-dark);
        border-color: rgba(22,163,74,0.35);
        cursor: default;
    }
    .end-due {
        background: rgba(239,68,68,0.10);
        color: #b91c1c;
        border-color: rgba(239,68,68,0.35);
        cursor: pointer;
    }
    .end-due:hover { filter: brightness(1.15); }
    .end-done {
        background: rgba(107,114,128,0.12);
        color: var(--muted);
        border-color: rgba(107,114,128,0.3);
        cursor: default;
    }
    .end-cancelled {
        background: rgba(107,114,128,0.12);
        color: var(--muted);
        border-color: rgba(107,114,128,0.3);
        cursor: default;
    }
    .end-badge.loading { opacity: 0.6; pointer-events: none; }

    .site-footer {
        text-align: center;
        color: var(--muted);
        font-size: 13px;
        margin-top: auto;
        padding-top: 20px;
    }

    /* ============ RESPONSIVE ============ */

    /* Tablet: tighten spacing, let the table use full available width
       instead of being capped at 900px. */
    @media (max-width: 900px) {
        .main { padding: 76px 20px 28px; }
        h2 { font-size: 23px; }
        table { max-width: 100%; }
    }

    /* Small tablet / large phone: stack sidebar above content since we
       don't control staff_sidebar.php's own responsive behavior here. */
    @media (max-width: 720px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 76px 16px 20px; min-height: auto; height: auto; overflow-y: visible; }
        .date-subtitle { margin-bottom: 16px; }
    }

    /* Phone: convert each row into a stacked card. Headers are hidden and
       each cell gets its column name back via data-label + ::before.
       Everything is sized UP here (bigger text, bigger tap targets) so
       staff can read and tap End Reservation without pinch-zooming. */
    @media (max-width: 640px) {
        h2 { font-size: 21px; margin-bottom: 16px; }
        .date-subtitle { font-size: 14px; }

        table { border-radius: 12px; }
        table, thead, tbody, tr, td { display: block; width: 100%; }
        thead, th { display: none; }

        tr:not(.date-group-row):not(.court-divider-row):not(.no-bookings-row) {
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

        /* Section header rows (date / court) act as plain block labels,
           not data rows, and shouldn't get a data-label prefix. */
        .date-group-row td,
        .court-divider-row td,
        .no-bookings-row td {
            display: block;
            padding: 12px 16px;
        }
        .date-group-row td { font-size: 15px; }
        .court-divider-row td { font-size: 13px; }
        .date-group-row td::before,
        .court-divider-row td::before,
        .no-bookings-row td::before { content: none; }

        /* End Reservation badge: bigger tap target so staff's thumb can't
           miss it, especially since it's the one interactive control here. */
        td.end-cell { padding-top: 10px; padding-bottom: 10px; }
        .end-badge {
            font-size: 15px;
            padding: 12px 18px;
            border-radius: 10px;
            min-width: 110px;
            text-align: center;
        }
    }
</style>
</head>
<body>

<?php include '../includes/staff_sidebar.php'; ?>

<div class="main">
    <div class="main-inner">
    <h2>Today's Bookings</h2>
    <p class="date-subtitle"><?= htmlspecialchars($nowManila->format('l, F j, Y')) ?></p>
    <table>
        <tr>
            <th>Customer</th>
            <th>Court</th>
            <th>Time</th>
            <th>Status</th>
            <th>End Reservation</th>
        </tr>
        <?php if (empty($grouped)): ?>
        <tr class="no-bookings-row">
            <td colspan="5">No bookings for today.</td>
        </tr>
        <?php endif; ?>

        <?php foreach ($grouped as $date => $courts): ?>
        <tr class="date-group-row">
            <td colspan="5">
                <?= htmlspecialchars((DateTime::createFromFormat('Y-m-d', $date))->format('l, F j, Y')) ?>
            </td>
        </tr>
        <?php foreach ($courts as $courtName => $courtRows): ?>
        <tr class="court-divider-row">
            <td colspan="5"><?= htmlspecialchars($courtName) ?></td>
        </tr>
        <?php foreach ($courtRows as $row):
            $isRefunded = (bool)($row['refund_status'] ?? 0);
            $endDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $row['date'] . ' ' . $row['end_time'], new DateTimeZone('Asia/Manila'));
            $isPast = $nowManila >= $endDateTime;
            $isCompleted = (bool)$row['completed'];
            $isEndable = in_array($row['status'], $endableStatuses, true);
            $endLabel = date('g:i A', strtotime($row['end_time']));

            if ($isRefunded) {
                $badgeClass = 'end-cancelled';
                $badgeText = 'Cancelled';
                $statusLabel = 'refunded';
            } elseif ($isCompleted) {
                $badgeClass = 'end-done';
                $badgeText = 'Done';
                $statusLabel = $row['status'];
            } elseif ($isPast && $isEndable) {
                $badgeClass = 'end-due';
                $badgeText = $endLabel;
                $statusLabel = $row['status'];
            } else {
                $badgeClass = 'end-open';
                $badgeText = $endLabel;
                $statusLabel = $row['status'];
            }
        ?>
        <tr id="row-<?= (int)$row['booking_id'] ?>">
            <td data-label="Customer"><?= htmlspecialchars($row['full_name']) ?></td>
            <td data-label="Court"><?= htmlspecialchars($row['court_name']) ?></td>
            <td data-label="Time"><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= $endLabel ?></td>
            <td data-label="Status"><span class="status-pill status-<?= htmlspecialchars($statusLabel) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
            <td class="end-cell" data-label="End Reservation">
                <span class="end-badge <?= $badgeClass ?>"
                      data-booking-id="<?= (int)$row['booking_id'] ?>"
                      <?= $badgeClass === 'end-due' ? 'onclick="markDone(this)"' : '' ?>>
                    <?= htmlspecialchars($badgeText) ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </table>

    <div class="site-footer">&copy; 2026 Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
    </div>
</div>

<script>
function markDone(el) {
    if (!el.classList.contains('end-due')) return;
    const bookingId = el.dataset.bookingId;
    el.classList.add('loading');
    const originalText = el.textContent;
    el.textContent = 'Ending...';

    fetch('mark_done.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'booking_id=' + encodeURIComponent(bookingId)
    })
    .then(r => r.json())
    .then(data => {
        el.classList.remove('loading');
        if (data.success) {
            el.classList.remove('end-due');
            el.classList.add('end-done');
            el.removeAttribute('onclick');
            el.textContent = 'Done';
        } else {
            el.textContent = originalText;
            alert(data.error || 'Could not update this booking.');
        }
    })
    .catch(() => {
        el.classList.remove('loading');
        el.textContent = originalText;
        alert('Network error. Please try again.');
    });
}

// Auto-refresh every 60s so green badges flip to red without staff manually reloading
setTimeout(() => location.reload(), 60000);

// Auto-complete: the moment a badge is showing as "due" (red), fire the same
// completion call a click would have — no staff action required. This is a
// convenience layer only; cron/auto_complete_bookings.php is the real
// guarantee, since it runs on the server even if no one has this page open.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.end-badge.end-due').forEach(el => markDone(el));
});
</script>

</body>
</html>