<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

$activePage = 'book';

// Which month is being viewed (defaults to current month)
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$firstOfMonth = $month . '-01';
$monthTs = strtotime($firstOfMonth);
$daysInMonth = (int) date('t', $monthTs);
$startWeekday = (int) date('w', $monthTs); // 0 = Sunday
$monthLabel = date('F Y', $monthTs);

$today = date('Y-m-d');

$prevMonthTs = strtotime('-1 month', $monthTs);
$nextMonthTs = strtotime('+1 month', $monthTs);
$prevMonth = date('Y-m', $prevMonthTs);
$nextMonth = date('Y-m', $nextMonthTs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Book a Court</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { -webkit-text-size-adjust: 100%; }
    html, body { height: 100%; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: #eef3ef;
        color: #1f2937;
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
    .content {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
    }
    h2 { font-size: 28px; font-weight: 800; margin-bottom: 6px; color: #1f2937; }
    .subtitle { color: #6b7280; margin-bottom: 28px; }

    .calendar-wrap {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 26px;
        max-width: 520px;
        width: 100%;
        box-shadow: 0 4px 16px rgba(0,0,0,0.05);
    }
    .cal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
    }
    .cal-header h3 { font-size: 19px; font-weight: 700; color: #1f2937; }
    .cal-nav {
        display: flex;
        gap: 8px;
    }
    .cal-nav a {
        color: #6b7280;
        text-decoration: none;
        border: 1px solid #e5e7eb;
        border-radius: 7px;
        padding: 6px 12px;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 38px;
        min-height: 38px;
    }
    .cal-nav a:hover { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }

    .cal-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 7px;
    }
    .cal-grid .dow {
        text-align: center;
        font-size: 11px;
        color: #9ca3af;
        text-transform: uppercase;
        padding-bottom: 8px;
        font-weight: 700;
    }
    .cal-day {
        aspect-ratio: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        font-size: 15px;
        text-decoration: none;
        color: #1f2937;
        background: #f3f4f6;
        border: 1px solid transparent;
    }
    .cal-day.empty { background: transparent; }
    .cal-day.past {
        color: #d1d5db;
        background: transparent;
        pointer-events: none;
    }
    .cal-day.available:hover {
        border-color: #16a34a;
        background: rgba(22,163,74,0.10);
        color: #16a34a;
    }
    .cal-day.today {
        border-color: #16a34a;
        color: #16a34a;
        background: rgba(22,163,74,0.08);
        font-weight: 800;
    }

    /* Site footer: sits at the bottom of the page, consistent across
       customer-facing pages. */
    .site-footer {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
        font-size: 13px;
        color: #9ca3af;
        text-align: center;
    }

    /* ---------- RESPONSIVE ---------- */

    /* Tablet / sidebar collapses above content */
    @media (max-width: 900px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 28px 20px; height: auto; overflow: visible; }
    }

    /* Phones */
    @media (max-width: 600px) {
        .main { padding: 76px 16px 20px; } /* top padding clears the fixed hamburger button */
        h2 { font-size: 22px; }
        .subtitle { font-size: 13.5px; margin-bottom: 20px; }

        .calendar-wrap {
            max-width: none;
            width: 100%;
            padding: 16px;
            border-radius: 12px;
        }
        .cal-header h3 { font-size: 16px; }
        .cal-nav a {
            padding: 5px 10px;
            font-size: 14px;
            min-width: 40px;
            min-height: 40px;
        }
        .cal-grid { gap: 5px; }
        .cal-grid .dow { font-size: 10px; }
        .cal-day { font-size: 13px; border-radius: 7px; }
    }

    /* Small phones */
    @media (max-width: 380px) {
        .calendar-wrap { padding: 12px; }
        .cal-grid { gap: 4px; }
        .cal-day { font-size: 12px; }
    }

    /* Respect notches / home indicators on iOS */
    @supports (padding: max(0px)) {
        .main { padding-left: max(40px, env(safe-area-inset-left)); padding-right: max(40px, env(safe-area-inset-right)); }
    }
    @media (max-width: 900px) {
        @supports (padding: max(0px)) {
            .main { padding-left: max(28px, env(safe-area-inset-left)); padding-right: max(28px, env(safe-area-inset-right)); }
        }
    }
    @media (max-width: 600px) {
        @supports (padding: max(0px)) {
            .main {
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
                padding-top: max(76px, calc(env(safe-area-inset-top) + 60px));
            }
        }
    }
</style>
</head>
<body>

<?php include '../includes/customer_sidebar.php'; ?>

<div class="main">
    <div class="content">
        <h2>Book a Court</h2>
        <p class="subtitle">Pick a date to see available courts and time slots.</p>

        <div class="calendar-wrap">
            <div class="cal-header">
                <div class="cal-nav">
                    <a href="schedule.php?month=<?= urlencode($prevMonth) ?>">&larr;</a>
                </div>
                <h3><?= htmlspecialchars($monthLabel) ?></h3>
                <div class="cal-nav">
                    <a href="schedule.php?month=<?= urlencode($nextMonth) ?>">&rarr;</a>
                </div>
            </div>

            <div class="cal-grid">
                <div class="dow">Sun</div>
                <div class="dow">Mon</div>
                <div class="dow">Tue</div>
                <div class="dow">Wed</div>
                <div class="dow">Thu</div>
                <div class="dow">Fri</div>
                <div class="dow">Sat</div>

                <?php for ($i = 0; $i < $startWeekday; $i++): ?>
                    <div class="cal-day empty"></div>
                <?php endfor; ?>

                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $dateStr = sprintf('%s-%02d', $month, $d);
                    $isPast = $dateStr < $today;
                    $isToday = $dateStr === $today;
                    $classes = 'cal-day';
                    if ($isPast) {
                        $classes .= ' past';
                    } else {
                        $classes .= ' available';
                    }
                    if ($isToday) { $classes .= ' today'; }
                ?>
                    <?php if ($isPast): ?>
                        <div class="<?= $classes ?>"><?= $d ?></div>
                    <?php else: ?>
                        <a class="<?= $classes ?>" href="select_court.php?date=<?= urlencode($dateStr) ?>"><?= $d ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        &copy; <?= date('Y') ?> Paddle Ground Reservation &middot; San Francisco, Agusan del Sur
    </footer>
</div>

</body>
</html>