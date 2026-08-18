<?php
// Suppress warnings/notices from leaking into the JSON response body.
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Keep in sync with SESSION_INACTIVITY_LIMIT in config/session_check.php (30 min).
const API_SESSION_INACTIVITY_LIMIT = 1800;

$sessionExpired = !empty($_SESSION['last_activity'])
    && (time() - $_SESSION['last_activity']) > API_SESSION_INACTIVITY_LIMIT;

if ($sessionExpired) {
    $_SESSION = [];
    session_destroy();
}

if ($sessionExpired || ($_SESSION['role'] ?? '') != 'staff') {
    // 401, not 403 — this is specifically "your session is gone, please log
    // in again", which the frontend can special-case, vs. a generic
    // authorization failure.
    http_response_code(401);
    echo json_encode(['error' => 'Session expired. Please log in again.']);
    exit();
}

// Valid + active: refresh the timestamp, same as every other protected page.
$_SESSION['last_activity'] = time();

include '../config/db.php';

$booking_id = $_POST['booking_id'] ?? null;
if (!$booking_id || !ctype_digit((string)$booking_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid booking ID']);
    exit();
}

$stmt = $conn->prepare(
    "SELECT s.date, s.end_time, b.status, b.completed
     FROM bookings b
     JOIN schedules s ON b.schedule_id = s.schedule_id
     WHERE b.booking_id = ?"
);
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Booking not found']);
    exit();
}

// 'paid' is treated the same as 'approved' — both are confirmed bookings
$endableStatuses = ['approved', 'paid'];
if (!in_array($row['status'], $endableStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only approved or paid bookings can be ended.']);
    exit();
}
if ($row['completed']) {
    echo json_encode(['success' => true, 'already' => true]);
    exit();
}

$nowManila = new DateTime('now', new DateTimeZone('Asia/Manila'));
$endDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $row['date'] . ' ' . $row['end_time'], new DateTimeZone('Asia/Manila'));

if ($nowManila < $endDateTime) {
    http_response_code(400);
    echo json_encode(['error' => 'This reservation has not ended yet.']);
    exit();
}

$update = $conn->prepare("UPDATE bookings SET completed = 1, completed_seen = 0 WHERE booking_id = ?");
$update->bind_param("i", $booking_id);
$update->execute();

echo json_encode(['success' => true]);