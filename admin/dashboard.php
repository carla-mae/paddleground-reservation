<?php
require_once '../config/session_check.php';
require_role(['admin']);
include '../config/db.php';

$activePage = 'dashboard';

$unverifiedCount = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE verified = 0")->fetch_assoc()['c'];
$todayCount = $conn->query(
    "SELECT COUNT(*) AS c FROM bookings b
     JOIN schedules s ON b.schedule_id = s.schedule_id
     WHERE s.date = CURDATE() AND b.status = 'approved'"
)->fetch_assoc()['c'];
$totalBookings = $conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status != 'cancelled'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
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
    h1 { font-size: 30px; font-weight: 800; margin-bottom: 6px; }
    .subtitle { color: var(--muted); margin-bottom: 28px; }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 18px;
        max-width: 900px;
        margin-bottom: 32px;
    }
    .stat-card {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        padding: 22px;
        box-shadow: 0 12px 30px -18px rgba(23, 48, 31, 0.16);
    }
    .stat-card .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
    .stat-card .value { font-size: 30px; font-weight: 800; color: var(--brand-ink); }
    .stat-card.warn .value { color: #b45309; }
    .stat-card.info .value { color: var(--brand-green-dark); }
    .stat-card a {
        display: inline-block;
        margin-top: 10px;
        font-size: 12px;
        color: var(--brand-green);
        text-decoration: none;
    }
    .stat-card a:hover { text-decoration: underline; }

    .site-footer {
        text-align: center;
        color: var(--muted);
        font-size: 13px;
        margin-top: auto;
        padding-top: 20px;
    }

    /* ============ RESPONSIVE ============ */

    /* Tablet: tighten spacing, let the grid breathe at its own pace since
       auto-fit/minmax already reflows columns on its own. */
    @media (max-width: 900px) {
        .main { padding: 28px 20px; }
        h1 { font-size: 26px; }
        .stat-grid { max-width: 100%; }
    }

    /* Small tablet / large phone: stack sidebar above content since we
       don't control admin_sidebar.php's own responsive behavior here. */
    @media (max-width: 720px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 20px 16px; min-height: auto; height: auto; overflow-y: visible; }
        .subtitle { margin-bottom: 20px; }
    }

    /* Phone: single-column stat cards, sized UP (bigger text, bigger tap
       targets) so an admin can read and tap comfortably without
       pinch-zooming. */
    @media (max-width: 640px) {
        h1 { font-size: 23px; }
        .subtitle { font-size: 15px; }

        .stat-grid {
            grid-template-columns: 1fr;
            gap: 16px;
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

<?php include '../includes/admin_sidebar.php'; ?>

<div class="main">
    <div class="main-inner">
    <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
    <p class="subtitle">Here's what needs your attention today.</p>

    <div class="stat-grid">
        <div class="stat-card warn">
            <div class="label">Unverified Payments</div>
            <div class="value"><?= (int)$unverifiedCount ?></div>
            <a href="verify_payment.php">Review &rarr;</a>
        </div>
        <div class="stat-card info">
            <div class="label">Today's Approved Bookings</div>
            <div class="value"><?= (int)$todayCount ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Total Bookings</div>
            <div class="value"><?= (int)$totalBookings ?></div>
            <a href="view_bookings.php">View all &rarr;</a>
        </div>
    </div>

    <div class="site-footer">&copy; 2026 Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
    </div>
</div>

</body>
</html>