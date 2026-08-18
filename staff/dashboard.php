<?php
require_once '../config/session_check.php';
require_role(['staff']);
include '../config/db.php';

$activePage = 'dashboard';

// Bookings ending within the next 30 minutes (today, approved only)
$endingSql = "SELECT u.full_name, c.court_name, s.end_time
              FROM bookings b
              JOIN users u ON b.user_id = u.user_id
              JOIN schedules s ON b.schedule_id = s.schedule_id
              JOIN courts c ON s.court_id = c.court_id
              WHERE s.date = CURDATE() AND b.status = 'approved'
              AND TIME_TO_SEC(TIMEDIFF(s.end_time, CURTIME())) BETWEEN 0 AND 1800";
$ending = $conn->query($endingSql);
$endingSoon = $ending ? $ending->fetch_all(MYSQLI_ASSOC) : [];

// Quick stats for today
$todayCount = $conn->query(
    "SELECT COUNT(*) AS c FROM bookings b
     JOIN schedules s ON b.schedule_id = s.schedule_id
     WHERE s.date = CURDATE() AND b.status = 'approved'"
)->fetch_assoc()['c'];

$unverifiedTodayCount = $conn->query(
    "SELECT COUNT(*) AS c FROM payments p
     JOIN bookings b ON p.booking_id = b.booking_id
     JOIN schedules s ON b.schedule_id = s.schedule_id
     WHERE s.date = CURDATE() AND p.verified = 0"
)->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: #EAF1EC;
        color: #17301F;
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
        max-width: 700px;
        width: 100%;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }
    h1 { font-size: 30px; font-weight: 800; margin-bottom: 6px; }
    .subtitle { color: #6B7A70; margin-bottom: 28px; }

    .alert {
        background: rgba(217, 119, 6, 0.10);
        border: 1px solid rgba(217, 119, 6, 0.35);
        color: #92400e;
        padding: 14px 18px;
        border-radius: 10px;
        margin-bottom: 12px;
        font-size: 14px;
        max-width: 600px;
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 18px;
        max-width: 700px;
        margin: 24px 0 32px;
    }
    .stat-card {
        background: #FFFFFF;
        border: 1px solid #DDE6E0;
        border-radius: 14px;
        padding: 22px;
        box-shadow: 0 12px 30px -18px rgba(23, 48, 31, 0.16);
    }
    .stat-card .label { font-size: 12px; color: #6B7A70; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
    .stat-card .value { font-size: 30px; font-weight: 800; color: #128A3E; }
    .stat-card a {
        display: inline-block;
        margin-top: 10px;
        font-size: 12px;
        color: #16A34A;
        text-decoration: none;
    }
    .stat-card a:hover { text-decoration: underline; }

    .site-footer {
        text-align: center;
        color: #6B7A70;
        font-size: 13px;
        margin-top: auto;
        padding-top: 20px;
    }

    /* ============ RESPONSIVE ============ */

    /* Tablet: tighten spacing, let the grid/alerts use full width instead
       of being capped. */
    @media (max-width: 900px) {
        .main { padding: 76px 20px 28px; }
        h1 { font-size: 26px; }
        .stat-grid, .alert { max-width: 100%; }
    }

    /* Small tablet / large phone: stack sidebar above content since we
       don't control staff_sidebar.php's own responsive behavior here. */
    @media (max-width: 720px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 76px 16px 20px; min-height: auto; height: auto; overflow-y: visible; }
        .subtitle { margin-bottom: 20px; }
    }

    /* Phone: single-column stat cards, sized UP (bigger text, bigger tap
       targets) so staff can read and tap comfortably without
       pinch-zooming. */
    @media (max-width: 640px) {
        h1 { font-size: 23px; }
        .subtitle { font-size: 15px; }

        .alert {
            font-size: 15px;
            padding: 16px 18px;
            border-radius: 12px;
        }

        .stat-grid {
            grid-template-columns: 1fr;
            gap: 16px;
            margin: 20px 0 28px;
        }
        .stat-card { padding: 22px 20px; border-radius: 14px; }
        .stat-card .label { font-size: 14px; margin-bottom: 10px; }
        .stat-card .value { font-size: 36px; }
        .stat-card a {
            margin-top: 14px;
            font-size: 15px;
            font-weight: 700;
        }
    }
</style>
</head>
<body>

<?php include '../includes/staff_sidebar.php'; ?>

<div class="main">
    <div class="main-inner">
    <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
    <p class="subtitle">Here's what's happening on the courts today.</p>

    <?php foreach ($endingSoon as $r): ?>
        <div class="alert">
            <?= htmlspecialchars($r['full_name']) ?>'s reservation on <?= htmlspecialchars($r['court_name']) ?>
            ends at <?= date('g:i A', strtotime($r['end_time'])) ?>
        </div>
    <?php endforeach; ?>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="label">Today's Approved Bookings</div>
            <div class="value"><?= (int)$todayCount ?></div>
            <a href="daily_bookings.php">View &rarr;</a>
        </div>
        <div class="stat-card">
            <div class="label">Unverified Payments Today</div>
            <div class="value"><?= (int)$unverifiedTodayCount ?></div>
            <a href="daily_report.php">View report &rarr;</a>
        </div>
    </div>

    <div class="site-footer">&copy; 2026 Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
    </div>
</div>

</body>
</html>