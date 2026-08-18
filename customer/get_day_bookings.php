<?php
// Suppress warnings/notices from leaking into the JSON response body.
// (Errors are still logged server-side via the default error_log, just not echoed.)
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Keep this in sync with SESSION_INACTIVITY_LIMIT in config/session_check.php
// (30 minutes). This endpoint is hit via AJAX, so it can't redirect like the
// page-level guard does — it needs to return JSON either way — but it should
// still expire a session that's been idle too long, otherwise an AJAX call
// could keep a stale session "alive" past the point every other page would
// have logged it out.
const API_SESSION_INACTIVITY_LIMIT = 1800;

$sessionExpired = !empty($_SESSION['last_activity'])
    && (time() - $_SESSION['last_activity']) > API_SESSION_INACTIVITY_LIMIT;

if ($sessionExpired) {
    $_SESSION = [];
    session_destroy();
}

if ($sessionExpired || ($_SESSION['role'] ?? '') != 'customer') {
    // 401, not 403 — tells the frontend specifically "your session is gone,
    // please log in again" rather than a generic authorization failure.
    http_response_code(401);
    echo json_encode(['error' => 'Session expired. Please log in again.']);
    exit();
}

// Valid + active: refresh the timestamp, same as the page-level guard does.
$_SESSION['last_activity'] = time();

include '../config/db.php';

// Keep PHP and MySQL aligned on the same "today" (Asia/Manila), so a booking
// for today doesn't fall on the wrong side of the date boundary.
date_default_timezone_set('Asia/Manila');

// FIX: wrap this in try/catch. Since PHP 8.1, mysqli's default error mode
// throws a mysqli_sql_exception on query failure instead of returning false.
// This call used to sit outside any try/catch, so a thrown exception here
// would cause a raw uncaught fatal error -> empty/broken response body ->
// JSON.parse() failure on the frontend ("Could not load availability").
try {
    if ($conn->query("SET time_zone = '+08:00'") === false) {
        error_log('get_day_bookings.php: SET time_zone failed: ' . $conn->error);
    }
} catch (Throwable $e) {
    error_log('get_day_bookings.php: SET time_zone threw: ' . $e->getMessage());
}

$date     = $_GET['date'] ?? '';
$court_id = $_GET['court_id'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !ctype_digit((string)$court_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date or court_id']);
    exit();
}

try {
    // A slot counts as "Booked" once the customer has actually submitted
    // payment online — that's status 'pending' (receipt uploaded, method
    // chosen) or anything past that (e.g. 'confirmed' once admin verifies).
    // We do NOT wait for admin verification to lock the slot, since the
    // customer already paid; admin review is just a fraud/mistake check
    // afterward, not a precondition for the slot being taken.
    //
    // Only two statuses are excluded here:
    //   - 'cancelled'             — booking was cancelled, slot is free
    //   - 'awaiting_confirmation' — customer picked a time but hasn't
    //                               submitted any payment yet, so nothing
    //                               is actually reserved
    $stmt = $conn->prepare(
        "SELECT s.start_time, s.end_time, b.status
         FROM bookings b
         JOIN schedules s ON b.schedule_id = s.schedule_id
         WHERE s.date = ? AND s.court_id = ?
           AND b.status NOT IN ('cancelled', 'awaiting_confirmation')
         ORDER BY s.start_time"
    );
    if ($stmt === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    $stmt->bind_param("si", $date, $court_id);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['bookings' => $bookings]);
} catch (Throwable $e) {
    error_log('get_day_bookings.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
exit();