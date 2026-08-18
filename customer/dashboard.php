<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

$user_id = $_SESSION['user_id'];

// --- Check for newly completed reservations (toast notifications, shown once) ---
// This is the ONLY trigger for a notification now: when staff marks a
// reservation as Done (mark_done.php sets completed = 1, completed_seen = 0),
// the customer sees a toast the next time they load the dashboard. Once shown,
// completed_seen flips to 1 so it never appears again.
$doneStmt = $conn->prepare(
    "SELECT b.booking_id, c.court_name, s.end_time
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.schedule_id
     JOIN courts c ON s.court_id = c.court_id
     WHERE b.user_id = ? AND b.completed = 1 AND b.completed_seen = 0
     ORDER BY s.end_time ASC"
);
$doneStmt->bind_param("i", $user_id);
$doneStmt->execute();
$justCompleted = $doneStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!empty($justCompleted)) {
    $ids = array_column($justCompleted, 'booking_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $markSeen = $conn->prepare("UPDATE bookings SET completed_seen = 1 WHERE booking_id IN ($placeholders)");
    $markSeen->bind_param($types, ...$ids);
    $markSeen->execute();
}

$activePage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Dashboard</title>
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
    html, body { height: 100%; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: var(--page-bg);
        color: var(--brand-ink);
        display: flex;
        min-height: 100vh;
    }
    .content-col {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        min-width: 0;
    }
    .main {
        flex-grow: 1;
        padding: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .hero {
        max-width: 560px;
        width: 100%;
        text-align: center;
        position: relative;
        padding: 48px 36px;
        border-radius: 20px;
        background: radial-gradient(circle at top, rgba(22,163,74,0.08), transparent 70%),
                    var(--card-bg);
        border: 1px solid var(--border-soft);
        box-shadow: 0 20px 50px -20px rgba(23, 48, 31, 0.18), 0 2px 8px rgba(23, 48, 31, 0.05);
        overflow: hidden;
    }
    .hero-trophy {
        position: absolute;
        top: -24px;
        right: -24px;
        width: 130px;
        height: 130px;
        opacity: 0.07;
        transform: rotate(12deg);
        pointer-events: none;
    }
    .hero-trophy svg { width: 100%; height: 100%; fill: var(--brand-green); }
    .hero-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(22,163,74,0.12);
        border: 1px solid rgba(22,163,74,0.35);
        font-size: 28px;
        position: relative;
        z-index: 1;
    }
    .main h1 {
        font-size: 32px;
        font-weight: 800;
        margin-bottom: 10px;
        background: linear-gradient(90deg, var(--brand-ink), var(--brand-green));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        position: relative;
        z-index: 1;
        word-break: break-word;
    }
    .divider {
        width: 60px;
        height: 3px;
        background: var(--brand-green);
        border-radius: 2px;
        margin: 14px auto 20px;
        position: relative;
        z-index: 1;
    }
    .main .subtitle {
        color: var(--muted);
        margin-bottom: 8px;
        font-size: 15px;
        position: relative;
        z-index: 1;
    }
    .main .subtitle.lead {
        color: var(--brand-green-dark);
        font-weight: 700;
        font-size: 17px;
        margin-bottom: 14px;
    }
    .info-block {
        margin-bottom: 18px;
        padding-bottom: 4px;
    }
    .quick-cta {
        display: inline-block;
        margin-top: 20px;
        background: var(--brand-green);
        color: #ffffff;
        font-weight: 700;
        text-decoration: none;
        padding: 12px 24px;
        border-radius: 8px;
        transition: filter 0.15s ease, transform 0.15s ease;
        position: relative;
        z-index: 1;
        min-height: 44px;
        line-height: 20px;
    }
    .quick-cta:hover { filter: brightness(1.08); transform: translateY(-1px); }

    .site-footer {
        text-align: center;
        padding: 18px;
        font-size: 12px;
        color: var(--muted);
        border-top: 1px solid var(--border-soft);
    }

    /* ---------- Toast notifications ---------- */
    /* One toast per completed court reservation, stacked in the top-right
       corner. Replaces the old inline "Reminder" boxes with something that
       reads as a real notification instead of clutter baked into the page. */
    .toast-stack {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 500;
        display: flex;
        flex-direction: column;
        gap: 12px;
        width: 340px;
        max-width: calc(100vw - 40px);
    }
    .toast {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-left: 4px solid var(--brand-green);
        border-radius: 12px;
        padding: 16px 18px;
        box-shadow: 0 10px 30px rgba(23, 48, 31, 0.16);
        display: flex;
        gap: 12px;
        align-items: flex-start;
        opacity: 0;
        transform: translateX(24px);
        animation: toastIn 0.35s ease forwards;
    }
    .toast.leaving {
        animation: toastOut 0.3s ease forwards;
    }
    @keyframes toastIn {
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes toastOut {
        to { opacity: 0; transform: translateX(24px); }
    }
    .toast-icon {
        flex-shrink: 0;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(22,163,74,0.14);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
    }
    .toast-body { flex-grow: 1; min-width: 0; }
    .toast-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--brand-ink);
        margin-bottom: 3px;
    }
    .toast-text {
        font-size: 12.5px;
        color: var(--muted);
        line-height: 1.45;
    }
    .toast-close {
        background: none;
        border: none;
        color: #9ca3af;
        font-size: 16px;
        cursor: pointer;
        line-height: 1;
        padding: 6px;
        margin: -6px;
        flex-shrink: 0;
    }
    .toast-close:hover { color: var(--brand-ink); }

    /* ---------- RESPONSIVE ---------- */

    /* Tablet / sidebar collapses above content */
    @media (max-width: 900px) {
        body { flex-direction: column; }
        .main { padding: 28px 20px; }
    }

    /* Phones */
    @media (max-width: 600px) {
        .main { padding: 20px 16px; }
        .hero { padding: 36px 22px; border-radius: 16px; }
        .main h1 { font-size: 24px; }
        .hero-icon { width: 54px; height: 54px; font-size: 24px; margin-bottom: 14px; }
        .main .subtitle { font-size: 13.5px; }
        .main .subtitle.lead { font-size: 15px; }
        .hero-trophy { width: 100px; height: 100px; top: -18px; right: -18px; }
        .quick-cta { width: 100%; padding: 13px 20px; }

        /* Toasts: full-width banners pinned to the top instead of a
           right-aligned card stack, so they don't crowd a narrow screen
           or get clipped by the sidebar toggle / notch area. */
        .toast-stack {
            top: 12px;
            right: 12px;
            left: 12px;
            width: auto;
            max-width: none;
        }
        .toast { padding: 14px 14px; }
    }

    /* Small phones */
    @media (max-width: 380px) {
        .hero { padding: 30px 16px; }
        .main h1 { font-size: 21px; }
        .main .subtitle.lead { font-size: 14px; }
    }

    /* Respect notches / home indicators on iOS */
    @supports (padding: max(0px)) {
        .main { padding-left: max(40px, env(safe-area-inset-left)); padding-right: max(40px, env(safe-area-inset-right)); }
        .toast-stack {
            top: max(20px, env(safe-area-inset-top));
            right: max(20px, env(safe-area-inset-right));
        }
    }
    @media (max-width: 900px) {
        @supports (padding: max(0px)) {
            .main { padding-left: max(28px, env(safe-area-inset-left)); padding-right: max(28px, env(safe-area-inset-right)); }
        }
    }
    @media (max-width: 600px) {
        @supports (padding: max(0px)) {
            .main { padding-left: max(16px, env(safe-area-inset-left)); padding-right: max(16px, env(safe-area-inset-right)); }
            .toast-stack {
                top: max(12px, env(safe-area-inset-top));
                right: max(12px, env(safe-area-inset-right));
                left: max(12px, env(safe-area-inset-left));
            }
        }
    }
</style>
</head>
<body>

<div class="toast-stack" id="toastStack"></div>

<?php include '../includes/customer_sidebar.php'; ?>

<div class="content-col">
    <div class="main">
        <div class="hero">
            <div class="hero-trophy">
                <svg viewBox="0 0 24 24"><path d="M18 2H6v2H2v4c0 2.21 1.79 4 4 4 .19 1.6.9 3.03 2 4.14V19H6v2h12v-2h-2v-2.86c1.1-1.11 1.81-2.54 2-4.14 2.21 0 4-1.79 4-4V4h-4V2zm-2 8c0 2.21-1.79 4-4 4s-4-1.79-4-4V4h8v6zM4 8V6h2v4c-1.1 0-2-.9-2-2zm16 0c0 1.1-.9 2-2 2V6h2v2z"/></svg>
            </div>

            <div class="hero-icon">🏓</div>
            <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
            <div class="divider"></div>

            <div class="info-block">
                <p class="subtitle lead">Book your PADDLE courts in seconds</p>
                <p class="subtitle">Barangay 3, San Francisco, Agusan del Sur</p>
                <p class="subtitle">Open: 5am - 12midnight</p>
            </div>

            <a class="quick-cta" href="schedule.php">Reserve Now &rarr;</a>
        </div>
    </div>

    <div class="site-footer">
        &copy; <?= date('Y') ?> Paddle Ground Reservation &middot; San Francisco, Agusan del Sur
    </div>
</div>

<script>
// Data handed off from PHP: one entry per booking the staff just marked Done.
const COMPLETED = <?= json_encode($justCompleted) ?>;

// A short, synthesized two-note "bell" — no audio file needed, so nothing
// to upload or host. Plays once total, no matter how many toasts appear.
function playBell() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const notes = [880, 1320]; // a clean two-tone chime
        notes.forEach((freq, i) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            const start = ctx.currentTime + i * 0.12;
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.18, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.5);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(start);
            osc.stop(start + 0.5);
        });
    } catch (e) {
        // Audio isn't critical — fail silently if the browser blocks it
        // (e.g. no user interaction yet on the page).
    }
}

function showToast(courtName, endTime) {
    const stack = document.getElementById('toastStack');

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `
        <div class="toast-icon">🏓</div>
        <div class="toast-body">
            <div class="toast-title">Reservation Ended</div>
            <div class="toast-text">Your session on <b>${courtName}</b> has ended. Thanks for playing — see you again soon!</div>
        </div>
        <button type="button" class="toast-close" aria-label="Dismiss">&times;</button>
    `;

    function dismiss() {
        toast.classList.add('leaving');
        setTimeout(() => toast.remove(), 280);
    }
    toast.querySelector('.toast-close').addEventListener('click', dismiss);

    stack.appendChild(toast);

    // Auto-dismiss after 8s if the customer doesn't close it manually.
    setTimeout(dismiss, 8000);
}

if (COMPLETED.length > 0) {
    playBell();
    // Stagger them slightly so multiple toasts don't all pop in at once.
    COMPLETED.forEach((item, i) => {
        setTimeout(() => showToast(item.court_name), i * 250);
    });
}
</script>

</body>
</html>