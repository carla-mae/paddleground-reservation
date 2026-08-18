<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: schedule.php");
    exit();
}

$user_id  = $_SESSION['user_id'];
$date     = $_POST['date']     ?? '';
$court_id = $_POST['court_id'] ?? '';
$start    = $_POST['start']    ?? '';
$end      = $_POST['end']      ?? '';

// The "number of players" field was removed from the booking form, so it's
// no longer read or validated from the request. The bookings table still
// has a NOT NULL players column, so we just store a fixed placeholder value.
$players = 1;

// --- Basic input validation ---
$valid = true;
$valid = $valid && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
$valid = $valid && ctype_digit((string)$court_id);
$valid = $valid && preg_match('/^\d{2}:\d{2}:\d{2}$/', $start);
$valid = $valid && preg_match('/^\d{2}:\d{2}:\d{2}$/', $end);

// FIX: "00:00:00" as the end time means midnight / end-of-day (24:00), not
// the very start of the day. A plain string comparison ($start < $end)
// broke for every slot ending at midnight (e.g. 6:30 PM - 12:00 AM), since
// "18:30:00" < "00:00:00" is false lexicographically -> always rejected as
// invalid even though the booking is legitimate. Compare using minutes
// instead, with the same "00:00:00 means end-of-day" rule used later in
// this file's price calculation.
if ($valid) {
    $startMinCheck = ((int)substr($start, 0, 2)) * 60 + (int)substr($start, 3, 2);
    $endMinCheck   = ((int)substr($end, 0, 2)) * 60 + (int)substr($end, 3, 2);
    if ($endMinCheck === 0) { $endMinCheck = 24 * 60; } // midnight end = end-of-day
    $valid = $valid && ($startMinCheck < $endMinCheck);
}

$valid = $valid && ($date >= date('Y-m-d'));

if (!$valid) {
    http_response_code(400);
    die('Invalid booking request. <a href="schedule.php">Go back</a>.');
}

// --- Confirm the court actually exists AND is still active, and grab its rates ---
// FIX: added "AND is_active = 1" so a court the admin has removed can no
// longer be booked, even if a customer's browser still had the old
// select_court.php page open (with the court's Book Now button visible)
// from before it was removed.
$courtCheck = $conn->prepare("SELECT day_rate, night_rate FROM courts WHERE court_id = ? AND is_active = 1");
$courtCheck->bind_param("i", $court_id);
$courtCheck->execute();
$courtRow = $courtCheck->get_result()->fetch_assoc();
$courtCheck->close();

if (!$courtRow) {
    die('This court is no longer available for booking. <a href="schedule.php">Go back and pick another court</a>.');
}

// --- Compute price: split at the 18:00 day/night boundary if the booking spans it ---
function time_to_minutes(string $t): int {
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

$DAY_NIGHT_BOUNDARY = 18 * 60; // 18:00
$startMin = time_to_minutes($start);
$endMin   = time_to_minutes($end);
if ($endMin === 0) { $endMin = 24 * 60; } // midnight end ("00:00:00") means end-of-day

$dayMinutes   = max(0, min($endMin, $DAY_NIGHT_BOUNDARY) - min($startMin, $DAY_NIGHT_BOUNDARY));
$nightMinutes = max(0, $endMin - max($startMin, $DAY_NIGHT_BOUNDARY));

$total_price = round(
    ($dayMinutes / 60) * (float)$courtRow['day_rate']
    + ($nightMinutes / 60) * (float)$courtRow['night_rate'],
    2
);

// --- Re-check for overlap server-side (belt-and-suspenders; the modal already
//     checked this client-side, but the client can't be trusted) ---
//
// Only block against bookings that have actually been paid for online —
// i.e. status is neither 'cancelled' nor 'awaiting_confirmation'. A booking
// still sitting at 'awaiting_confirmation' means the other customer picked
// this time but never went through with payment, so it shouldn't hold the
// slot hostage against anyone else. This matches the exact same criteria
// used in get_day_bookings.php, so a time the modal shows as pickable will
// never get rejected here — no more "someone else just booked this" wall.
$overlapStmt = $conn->prepare(
    "SELECT s.schedule_id
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.schedule_id
     WHERE s.date = ? AND s.court_id = ?
       AND b.status NOT IN ('cancelled', 'awaiting_confirmation')
       AND s.start_time < ? AND s.end_time > ?"
);
$overlapStmt->bind_param("siss", $date, $court_id, $end, $start);
$overlapStmt->execute();
$overlapResult = $overlapStmt->get_result();
if ($overlapResult->num_rows > 0) {
    $overlapStmt->close();
    die('That time slot was just booked by someone else. <a href="select_court.php?date=' . urlencode($date) . '">Go back and pick another time</a>.');
}
$overlapStmt->close();

// --- Create the schedules row + bookings row together ---
$conn->begin_transaction();
try {
    $insSchedule = $conn->prepare(
        "INSERT INTO schedules (court_id, date, start_time, end_time) VALUES (?, ?, ?, ?)"
    );
    $insSchedule->bind_param("isss", $court_id, $date, $start, $end);
    $insSchedule->execute();
    $schedule_id = $conn->insert_id;
    $insSchedule->close();

    $insBooking = $conn->prepare(
        "INSERT INTO bookings (user_id, schedule_id, status, total_price, players) VALUES (?, ?, 'awaiting_confirmation', ?, ?)"
    );
    $insBooking->bind_param("iidi", $user_id, $schedule_id, $total_price, $players);
    $insBooking->execute();
    $booking_id = $conn->insert_id;
    $insBooking->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    die('Booking failed: ' . htmlspecialchars($e->getMessage()));
}

header("Location: payment.php?booking_id=" . $booking_id);
exit();