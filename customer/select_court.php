<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

// Force Asia/Manila everywhere in this file so "today" here always matches
// the timezone used by get_day_bookings.php and the JS Manila-time checks.
// Without this, PHP's default timezone (often UTC on shared hosts) can
// disagree with the DB/JS about what "today" is, which is what causes a
// valid date like today to intermittently fail to load availability.
date_default_timezone_set('Asia/Manila');

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
    header("Location: schedule.php");
    exit();
}

$BRACKETS = [
    ['label' => 'Morning – Afternoon', 'start' => '05:00:00', 'end' => '18:00:00'],
    ['label' => 'Evening',             'start' => '18:00:00', 'end' => '24:00:00'],
];

$courts = [];
// FIX: only show courts the admin hasn't removed. Without "WHERE is_active = 1"
// here, a soft-deleted court (is_active = 0) still shows up to customers even
// though it no longer appears in the admin Settings list.
$res = $conn->query("SELECT court_id, court_name, day_rate, night_rate, description, open_time, close_time FROM courts WHERE is_active = 1 ORDER BY court_name ASC");
while ($row = $res->fetch_assoc()) {
    $courts[] = $row;
}

$dateLabel = date('F j, Y', strtotime($date));
$activePage = 'book';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Select a Court</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { -webkit-text-size-adjust: 100%; }
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
        padding: 40px 40px 40px 72px;
        min-width: 0;
        display: flex;
        flex-direction: column;
        height: 100vh;
        overflow-y: auto;
        width: 100%;
    }
    .content {
        flex-grow: 1;
        max-width: 1040px;
        width: 100%;
        margin: 0 auto;
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
    .back-link {
        color: #6b7280;
        text-decoration: none;
        font-size: 13px;
        display: inline-block;
        margin-bottom: 14px;
    }
    .back-link:hover { color: #16a34a; }
    h2 { font-size: 28px; font-weight: 800; margin-bottom: 6px; color: #1f2937; }
    .subtitle { color: #6b7280; margin-bottom: 28px; }

    .court-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        width: 100%;
    }
    .court-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        min-width: 0;
        box-shadow: 0 4px 16px rgba(0,0,0,0.05);
    }
    .court-card-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .court-card h3 {
        font-size: 20px;
        line-height: 1.25;
        min-width: 0;
        word-break: break-word;
        color: #1f2937;
    }
    .rate-badge {
        flex-shrink: 0;
        background: rgba(22, 163, 74, 0.12);
        color: #16a34a;
        font-weight: 700;
        font-size: 12px;
        padding: 6px 12px;
        border-radius: 999px;
        white-space: nowrap;
    }
    .rate-breakdown {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 12px;
        line-height: 1.6;
    }
    .rate-breakdown b { color: #1f2937; }
    .hours-row {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #6b7280;
        font-size: 13px;
        margin-bottom: 18px;
        flex-wrap: wrap;
    }
    .book-now-btn {
        background: #16a34a;
        color: #ffffff;
        font-weight: 700;
        font-size: 13px;
        border: none;
        padding: 12px;
        border-radius: 8px;
        cursor: pointer;
        width: 100%;
        min-height: 44px;
    }
    .book-now-btn:hover { filter: brightness(1.08); }

    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 32, 0.55);
        align-items: center;
        justify-content: center;
        z-index: 100;
        padding: 16px;
    }
    .modal-overlay.open { display: flex; }
    .modal-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 28px;
        width: 460px;
        max-width: 100%;
        max-height: 86vh;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        box-shadow: 0 20px 50px rgba(0,0,0,0.18);
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 18px;
        gap: 10px;
        position: sticky;
        top: 0;
        background: #ffffff;
    }
    .modal-header h3 { font-size: 19px; word-break: break-word; color: #1f2937; }
    .modal-header h3 span { color: #16a34a; }
    .modal-close {
        background: none;
        border: none;
        color: #6b7280;
        font-size: 24px;
        cursor: pointer;
        line-height: 1;
        flex-shrink: 0;
        padding: 6px;
        margin: -6px;
    }
    .modal-close:hover { color: #1f2937; }
    .bracket {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 14px;
    }
    .bracket-title { font-size: 14px; font-weight: 700; margin-bottom: 4px; color: #1f2937; }
    .bracket-range { font-size: 12px; color: #6b7280; margin-bottom: 10px; }
    .busy-list { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .busy-tag {
        font-size: 11px;
        background: rgba(107,114,128,0.14);
        color: #6b7280;
        padding: 4px 8px;
        border-radius: 12px;
    }
    /* A busy slot whose end time has already passed today is "Completed"
       rather than merely "Booked" — give it the same green-success color
       used elsewhere (rate-badge) so it visually reads as finished, not
       upcoming/ongoing. */
    .busy-tag.completed {
        background: rgba(22, 163, 74, 0.12);
        color: #16a34a;
    }
    .no-bookings { font-size: 12px; color: #9ca3af; margin-bottom: 12px; }
    .fully-booked-tag {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        color: #dc2626;
        background: rgba(220, 38, 38, 0.10);
        border: 1px solid rgba(220, 38, 38, 0.30);
        padding: 3px 10px;
        border-radius: 999px;
        margin-bottom: 10px;
    }
    .time-passed-tag {
        display: inline-block;
        font-size: 11px;
        font-weight: 700;
        color: #6b7280;
        background: rgba(107, 114, 128, 0.12);
        border: 1px solid rgba(107, 114, 128, 0.30);
        padding: 3px 10px;
        border-radius: 999px;
        margin-bottom: 10px;
    }
    .time-row select:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .book-slot-btn:disabled {
        background: #e5e7eb;
        color: #9ca3af;
        cursor: not-allowed;
    }
    .book-slot-btn:disabled:hover { filter: none; }
    /* Fully booked takes priority over the generic disabled grey — red signals
       "no availability" specifically, distinct from "time already passed". */
    .book-slot-btn.fully-booked:disabled {
        background: rgba(220, 38, 38, 0.10);
        color: #dc2626;
        border: 1px solid rgba(220, 38, 38, 0.35);
    }
    .time-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .time-row > div { min-width: 120px; flex: 1; }
    .time-row label { font-size: 11px; color: #6b7280; display: block; margin-bottom: 4px; }
    .time-row select {
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #1f2937;
        border-radius: 7px;
        padding: 10px 8px;
        font-size: 13px;
        width: 100%;
        min-height: 40px;
    }

    .book-slot-btn {
        margin-top: 12px;
        width: 100%;
        background: #16a34a;
        color: #ffffff;
        font-weight: 700;
        font-size: 13px;
        border: none;
        padding: 12px;
        border-radius: 7px;
        cursor: pointer;
        min-height: 44px;
    }
    .book-slot-btn:hover { filter: brightness(1.08); }
    .loading-msg { color: #9ca3af; font-size: 13px; padding: 10px 0; }

    /* ---------- RESPONSIVE ---------- */

    /* Tablet / sidebar collapses above content */
    @media (max-width: 900px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 76px 20px 28px; height: auto; overflow: visible; }
        .court-grid {
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        }
    }

    /* Phones */
    @media (max-width: 600px) {
        .main { padding: 76px 16px 20px; }
        h2 { font-size: 22px; }
        .subtitle { margin-bottom: 20px; }
        .court-grid { grid-template-columns: 1fr; gap: 14px; }
        .court-card { padding: 18px; }
        .court-card-top { gap: 8px; }
        .court-card h3 { font-size: 18px; }

        .modal-overlay { padding: 0; align-items: flex-end; }
        .modal-card {
            padding: 18px 16px calc(18px + env(safe-area-inset-bottom, 0px));
            width: 100%;
            max-width: 100%;
            max-height: 92vh;
            border-radius: 16px 16px 0 0;
        }
        .modal-header { margin-bottom: 14px; }
        .modal-header h3 { font-size: 17px; }

        .time-row { flex-direction: column; align-items: stretch; gap: 8px; }
        .time-row > div { min-width: 0; }

        /* Prevent iOS Safari from auto-zooming the page when a select/input
           is focused — it zooms whenever the tapped control's font-size is
           under 16px. Keeping the visual size via the wrapper while bumping
           font-size to 16px on the control itself avoids that jump. */
        .time-row select { font-size: 16px; }

        .book-now-btn,
        .book-slot-btn { padding: 13px; }
    }

    /* Small phones */
    @media (max-width: 380px) {
        .main { padding: 76px 12px 16px; }
        h2 { font-size: 20px; }
        .rate-badge { font-size: 11px; padding: 5px 10px; }
        .modal-card { padding: 16px 14px; }
        .bracket { padding: 12px; }
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
            .main { padding-left: max(16px, env(safe-area-inset-left)); padding-right: max(16px, env(safe-area-inset-right)); }
        }
    }
</style>
</head>
<body>

<?php include '../includes/customer_sidebar.php'; ?>

<div class="main">
    <div class="content">
        <a class="back-link" href="schedule.php">&larr; Back to calendar</a>
        <h2>Select a Court</h2>
        <p class="subtitle">Find the perfect space for your next match — <?= htmlspecialchars($dateLabel) ?>.</p>

        <div class="court-grid">
            <?php foreach ($courts as $c): ?>
                <div class="court-card">
                    <div class="court-card-top">
                        <h3><?= htmlspecialchars($c['court_name']) ?></h3>
                        <span class="rate-badge">
                            &#8369;<?= number_format((float)$c['day_rate'], 0) ?>–<?= number_format((float)$c['night_rate'], 0) ?>/hr
                        </span>
                    </div>
                    <div class="rate-breakdown">
                        <b>&#8369;<?= number_format((float)$c['day_rate'], 2) ?>/hr</b> · 5:00 AM – 6:00 PM<br>
                        <b>&#8369;<?= number_format((float)$c['night_rate'], 2) ?>/hr</b> · 6:00 PM – 12:00 AM
                    </div>
                    <div class="hours-row">
                        &#128337; Available: <?= date('g:i A', strtotime($c['open_time'])) ?>
                        – <?= date('g:i A', strtotime($c['close_time'])) ?>
                    </div>
                    <button type="button" class="book-now-btn"
                            onclick="openModal(<?= (int)$c['court_id'] ?>, '<?= htmlspecialchars($c['court_name'], ENT_QUOTES) ?>')">
                        Book Now
                    </button>
                </div>
            <?php endforeach; ?>

            <?php if (empty($courts)): ?>
                <p class="subtitle">No courts have been set up yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <footer class="site-footer">
        &copy; <?= date('Y') ?> Paddle Ground Reservation &middot; San Francisco, Agusan del Sur
    </footer>
</div>

<!-- MODAL -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Book <span id="modalCourtLabel"></span></h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div id="modalBody">
            <p class="loading-msg">Loading availability…</p>
        </div>
    </div>
</div>

<form id="bookForm" method="POST" action="book.php" style="display:none;">
    <input type="hidden" name="date" id="fDate" value="<?= htmlspecialchars($date) ?>">
    <input type="hidden" name="court_id" id="fCourtId">
    <input type="hidden" name="start" id="fStart">
    <input type="hidden" name="end" id="fEnd">
</form>

<script>
const BRACKETS = <?= json_encode($BRACKETS) ?>;
const CURRENT_DATE = <?= json_encode($date) ?>;
let currentCourtId = null;

function timeToMinutes(t) {
    const [h, m] = t.split(':').map(Number);
    return h * 60 + m;
}
// A booking's end_time of "00:00:00" means midnight = end of day (1440 minutes),
// not the very start of the day (0 minutes). Without this, any booking ending
// exactly at midnight (e.g. 7:30 PM - 12:00 AM) gets silently dropped from
// fully-booked / overlap calculations, since its interval becomes [start, 0]
// which looks invalid (end before start) and gets filtered out.
function endTimeToMinutes(t) {
    const mins = timeToMinutes(t);
    return mins === 0 ? 1440 : mins;
}
function minutesToLabel(mins) {
    let h = Math.floor(mins / 60) % 24;
    const m = mins % 60;
    const ampm = h >= 12 ? 'PM' : 'AM';
    let h12 = h % 12; if (h12 === 0) h12 = 12;
    return h12 + ':' + String(m).padStart(2, '0') + ' ' + ampm;
}
function minutesToTimeString(mins) {
    const h = Math.floor(mins / 60) % 24;
    const m = mins % 60;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':00';
}
// Is this exact minute-mark already covered by an existing booking?
// Used so a start (or end) option that falls inside a booked range gets
// disabled outright, not just ones that are earlier than "now".
function isMinuteBusy(minute, busyList) {
    return busyList.some(b => {
        const bs = timeToMinutes(b.start_time);
        const be = endTimeToMinutes(b.end_time);
        return minute >= bs && minute < be;
    });
}
// isDisabled (optional): function(minutesValue) => boolean, decides whether
// that particular option should be greyed out / unselectable. Options are
// still shown (not removed) so the customer can see the full range of times.
function buildTimeOptions(selectEl, startMin, endMin, step = 30, isDisabled = null) {
    selectEl.innerHTML = '';
    let firstEnabledValue = null;
    for (let m = startMin; m <= endMin; m += step) {
        const opt = document.createElement('option');
        opt.value = String(m);
        opt.textContent = minutesToLabel(m === 1440 ? 0 : m) + (m === 1440 ? ' (midnight)' : '');
        opt.dataset.minutes = m;
        const disabled = isDisabled ? isDisabled(m) : false;
        if (disabled) {
            opt.disabled = true;
        } else if (firstEnabledValue === null) {
            firstEnabledValue = opt.value;
        }
        selectEl.appendChild(opt);
    }
    if (firstEnabledValue !== null) {
        selectEl.value = firstEnabledValue;
    }
}

// ---- Philippine time (Asia/Manila) helpers ----
// Always resolve "now" against Manila time, regardless of the visitor's device timezone.
function getManilaNow() {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false
    }).formatToParts(new Date());

    const get = (type) => parts.find(p => p.type === type).value;
    const dateStr = `${get('year')}-${get('month')}-${get('day')}`;
    let hour = parseInt(get('hour'), 10);
    if (hour === 24) hour = 0; // some environments render midnight as 24
    const minute = parseInt(get('minute'), 10);
    return { dateStr, nowMinutes: hour * 60 + minute };
}

function openModal(courtId, courtName) {
    currentCourtId = courtId;
    document.getElementById('modalOverlay').classList.add('open');
    document.getElementById('modalCourtLabel').textContent = courtName;

    const body = document.getElementById('modalBody');
    body.innerHTML = '<p class="loading-msg">Loading availability…</p>';

    fetch('get_day_bookings.php?date=' + encodeURIComponent(CURRENT_DATE) + '&court_id=' + encodeURIComponent(courtId))
        .then(async r => {
            const raw = await r.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (parseErr) {
                // The server did not return valid JSON (PHP fatal error, wrong path, HTML error page, etc).
                // Show the raw response so the real cause is visible instead of a generic message.
                throw new Error('Server returned non-JSON response (HTTP ' + r.status + '): ' + raw.slice(0, 300));
            }

            // FIX: special-case an expired session (401) so the user gets a
            // clear "please log in again" prompt instead of a generic error
            // that looks like a bug. This is the main cause of the modal
            // failing to load only after the tab has been open a while
            // (idle session garbage-collected server-side).
            if (r.status === 401) {
                throw new Error('SESSION_EXPIRED');
            }
            if (!r.ok || data.error) {
                throw new Error(data.error || ('HTTP ' + r.status));
            }
            return data;
        })
        .then(data => renderBrackets(data.bookings || []))
        .catch(err => {
            if (err.message === 'SESSION_EXPIRED') {
                body.innerHTML =
                    '<p class="loading-msg">Your session has expired. ' +
                    '<a href="../auth/login.php" style="color:#16a34a;">Please log in again</a>.</p>';
                return;
            }
            body.innerHTML = '<p class="loading-msg">Could not load availability: ' + err.message.replace(/</g, '&lt;') + '</p>';
        });
}

function isBracketFullyBooked(busyList, startMin, endMin) {
    if (startMin >= endMin) return true;

    const intervals = busyList
        .map(b => [Math.max(startMin, timeToMinutes(b.start_time)), Math.min(endMin, endTimeToMinutes(b.end_time))])
        .filter(([s, e]) => e > s)
        .sort((a, b) => a[0] - b[0]);

    let covered = startMin;
    for (const [s, e] of intervals) {
        if (s > covered) return false; // gap found — some time is still open
        covered = Math.max(covered, e);
    }
    return covered >= endMin;
}

function renderBrackets(bookings) {
    const body = document.getElementById('modalBody');
    body.innerHTML = '';

    const { dateStr: manilaToday, nowMinutes } = getManilaNow();
    const isToday = CURRENT_DATE === manilaToday;
    // Round "now" up to the next bookable 30-min slot boundary.
    const nextSlotMin = isToday ? Math.ceil(nowMinutes / 30) * 30 : null;

    BRACKETS.forEach((bracket, bracketIdx) => {
        const startMin = timeToMinutes(bracket.start);
        const endMin = bracket.end === '24:00:00' ? 1440 : timeToMinutes(bracket.end);

        // The bracket itself can run to midnight (Evening: 6:00 PM - 12:00 AM),
        // but the last selectable START time each day is capped at 11:00 PM
        // (23:00 = 1380 minutes) so a booking always has room for at least a
        // 30-min slot before closing.
        const startMaxMin = endMin === 1440 ? Math.min(endMin - 30, 1380) : endMin - 30;

        // If today, options before this many minutes are shown but disabled (already passed).
        const disableBeforeMin = isToday ? nextSlotMin : null;
        // FIX: a bracket is only "expired" once every bookable START slot
        // (up to startMaxMin, e.g. 11:00 PM for Evening) has passed — not when
        // the bracket's raw end time (midnight) has passed. The old check used
        // nextSlotMin >= endMin (i.e. >= 1440), which stayed false the whole
        // time between 11:00 PM and midnight even though every start option
        // was already disabled — leaving the <select> with zero enabled
        // options and crashing on selectedOptions[0].dataset later.
        const timeExpired = isToday && nextSlotMin > startMaxMin;

        // busyInBracket = every booking the backend sent us for this bracket.
        // get_day_bookings.php already excludes 'cancelled' and
        // 'awaiting_confirmation' (no payment submitted yet) — so everything
        // here has actually been paid for online (status 'pending' or
        // further along). That means this single list can drive both the
        // "Booked"/"Completed" tags AND the disabling of start/end options —
        // no separate "hidden but still blocking" list is needed, since an
        // unpaid booking never reaches this array in the first place.
        const busyInBracket = bookings.filter(b => {
            const bs = timeToMinutes(b.start_time);
            return bs >= startMin && bs < endMin;
        });

        const card = document.createElement('div');
        card.className = 'bracket';

        const title = document.createElement('div');
        title.className = 'bracket-title';
        title.textContent = bracket.label;
        card.appendChild(title);

        const range = document.createElement('div');
        range.className = 'bracket-range';
        range.textContent = minutesToLabel(startMin) + ' – ' + minutesToLabel(endMin === 1440 ? 0 : endMin);
        card.appendChild(range);

        // FIX: "fully booked" needs to be judged against what's actually
        // still bookable today, not the bracket's raw start time. Otherwise
        // a bracket that opens at 5:00 AM but has every slot from 10:00 AM
        // onward booked solid still reads as "open" just because the
        // (already-unbookable) 5:00–8:30 AM gap technically has no booking —
        // even though every remaining, actually-selectable start time is
        // taken. We clip the range we check to start at "now" (rounded to
        // the next slot) instead of the bracket's real opening time.
        //
        // Uses busyInBracket (ALL statuses), not just visibleBusy, so a
        // bracket that's fully taken by pending bookings still correctly
        // shows as unavailable instead of falsely offering "Book this slot".
        const effectiveStart = disableBeforeMin !== null ? Math.max(startMin, disableBeforeMin) : startMin;
        const fullyBooked = !timeExpired && isBracketFullyBooked(busyInBracket, effectiveStart, endMin);
        const disabled = timeExpired || fullyBooked;

        if (timeExpired) {
            const tpTag = document.createElement('div');
            tpTag.className = 'time-passed-tag';
            tpTag.textContent = 'Time Passed';
            card.appendChild(tpTag);
        } else if (fullyBooked) {
            const fbTag = document.createElement('div');
            fbTag.className = 'fully-booked-tag';
            fbTag.textContent = 'Fully Booked';
            card.appendChild(fbTag);
        }

        if (busyInBracket.length > 0) {
            const list = document.createElement('div');
            list.className = 'busy-list';
            busyInBracket.forEach(b => {
                const tag = document.createElement('span');
                // A booking whose end time has already passed today is done —
                // it's no longer occupying a "live" slot, it's history. Label
                // it "Completed" (green, like the rate badge) instead of
                // "Booked" (neutral grey) so the customer can tell the two
                // apart at a glance. It still blocks the slot from being
                // re-picked (that's handled separately by isMinuteBusy /
                // disableBeforeMin below) — only the label/color changes here.
                const bEndMin = endTimeToMinutes(b.end_time);
                const isCompleted = isToday && bEndMin <= nowMinutes;
                tag.className = 'busy-tag' + (isCompleted ? ' completed' : '');
                tag.textContent = minutesToLabel(timeToMinutes(b.start_time)) + '–' + minutesToLabel(timeToMinutes(b.end_time))
                    + (isCompleted ? ' Completed' : ' Booked');
                list.appendChild(tag);
            });
            card.appendChild(list);
        } else if (!timeExpired && !fullyBooked) {
            const none = document.createElement('div');
            none.className = 'no-bookings';
            none.textContent = 'Nothing booked yet — fully open.';
            card.appendChild(none);
        }

        const row = document.createElement('div');
        row.className = 'time-row';

        const startWrap = document.createElement('div');
        startWrap.style.flex = '1';
        startWrap.innerHTML = '<label>Start time</label>';
        const startSelect = document.createElement('select');
        // A start option is unselectable if it's already passed OR if it
        // falls inside a time range that's already occupied — using the
        // FULL busy list (busyInBracket) so pending/unconfirmed bookings
        // still block their time, they just aren't shown as a "Booked" tag.
        const startDisabledFn = (m) =>
            (disableBeforeMin !== null && m < disableBeforeMin) || isMinuteBusy(m, busyInBracket);
        buildTimeOptions(startSelect, startMin, startMaxMin, 30, startDisabledFn);
        startSelect.disabled = disabled;
        startWrap.appendChild(startSelect);

        const endWrap = document.createElement('div');
        endWrap.style.flex = '1';
        endWrap.innerHTML = '<label>End time</label>';
        const endSelect = document.createElement('select');
        endSelect.disabled = disabled;
        endWrap.appendChild(endSelect);

        function refreshEndOptions() {
            // Guard: if the start select has no enabled option selected
            // (can happen if every option ended up disabled), bail out
            // instead of crashing on undefined.dataset.
            const selected = startSelect.selectedOptions[0];
            if (!selected) { endSelect.innerHTML = ''; return; }
            const startVal = parseInt(selected.dataset.minutes, 10);

            // An end time is only valid up to the next existing booking
            // after the chosen start — anything past that would overlap it.
            // Everything beyond that point stays visible but disabled.
            // Uses busyInBracket (full list) for the same reason as above.
            const nextBusyStart = busyInBracket
                .map(b => timeToMinutes(b.start_time))
                .filter(bs => bs > startVal)
                .reduce((min, bs) => Math.min(min, bs), endMin);

            const endDisabledFn = (m) => m > nextBusyStart;
            buildTimeOptions(endSelect, startVal + 30, endMin, 30, endDisabledFn);
        }
        startSelect.addEventListener('change', refreshEndOptions);
        refreshEndOptions();

        row.appendChild(startWrap);
        row.appendChild(endWrap);
        card.appendChild(row);

        const bookBtn = document.createElement('button');
        bookBtn.type = 'button';
        bookBtn.className = 'book-slot-btn' + (fullyBooked ? ' fully-booked' : '');
        bookBtn.textContent = timeExpired ? 'Time Passed' : (fullyBooked ? 'FULLY BOOKED' : 'Book this slot');
        bookBtn.disabled = disabled;
        bookBtn.onclick = () => {
            if (bookBtn.disabled) return;

            // Guard: same as above, avoid crashing if somehow nothing is selected.
            const startOpt = startSelect.selectedOptions[0];
            const endOpt = endSelect.selectedOptions[0];
            if (!startOpt || !endOpt) return;

            const s = parseInt(startOpt.dataset.minutes, 10);
            const e = parseInt(endOpt.dataset.minutes, 10);

            // Guard against a slot that slipped into the past while the modal was open.
            const { dateStr: liveToday, nowMinutes: liveNow } = getManilaNow();
            if (CURRENT_DATE === liveToday && s < liveNow) {
                alert('That time has already passed. Please choose a later slot.');
                return;
            }

            // Uses busyInBracket (full list, all statuses) so this matches
            // what book.php will check server-side — prevents the "someone
            // else just booked this" rejection from ever happening in the
            // normal case, since the option would already be disabled above.
            const overlaps = busyInBracket.some(b => {
                const bs = timeToMinutes(b.start_time);
                const be = endTimeToMinutes(b.end_time);
                return s < be && e > bs;
            });
            if (overlaps) {
                alert('That range overlaps an existing booking. Please choose a different time.');
                return;
            }

            document.getElementById('fCourtId').value = currentCourtId;
            document.getElementById('fStart').value = minutesToTimeString(s);
            document.getElementById('fEnd').value = minutesToTimeString(e === 1440 ? 0 : e);
            document.getElementById('bookForm').submit();
        };
        card.appendChild(bookBtn);

        body.appendChild(card);
    });
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
}
document.getElementById('modalOverlay').addEventListener('click', (e) => {
    if (e.target.id === 'modalOverlay') closeModal();
});
</script>

</body>
</html>