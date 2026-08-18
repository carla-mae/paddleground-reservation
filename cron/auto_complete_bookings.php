<?php
// Auto-completes bookings whose end time has already passed.
// This is the reliable fallback: it does NOT depend on any staff member
// having the Today's Bookings page open, so a booking still gets marked
// done even if staff are busy or the browser tab is closed.
//
// Suggested cron entry (runs every minute):
//   * * * * * /usr/bin/php /full/path/to/paddle-reservation/cron/auto_complete_bookings.php >> /full/path/to/paddle-reservation/cron/auto_complete.log 2>&1

require_once __DIR__ . '/../config/db.php';

// Same rule as mark_done.php: 'paid' is treated the same as 'approved'.
$endableStatuses = ['approved', 'paid'];
$placeholders = implode(',', array_fill(0, count($endableStatuses), '?'));
$types = str_repeat('s', count($endableStatuses));

$sql = "SELECT b.booking_id, s.date, s.end_time
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        WHERE b.completed = 0
          AND b.status IN ($placeholders)";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    fwrite(STDERR, 'Prepare failed: ' . $conn->error . PHP_EOL);
    exit(1);
}
$stmt->bind_param($types, ...$endableStatuses);
$stmt->execute();
$result = $stmt->get_result();

$nowManila = new DateTime('now', new DateTimeZone('Asia/Manila'));
$toComplete = [];

while ($row = $result->fetch_assoc()) {
    $endDateTime = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $row['date'] . ' ' . $row['end_time'],
        new DateTimeZone('Asia/Manila')
    );
    if ($nowManila >= $endDateTime) {
        $toComplete[] = (int) $row['booking_id'];
    }
}
$stmt->close();

if (empty($toComplete)) {
    echo date('Y-m-d H:i:s') . " - No bookings to complete.\n";
    exit(0);
}

// IDs are cast to int above, so safe to inline directly.
$idList = implode(',', $toComplete);
$conn->query("UPDATE bookings SET completed = 1, completed_seen = 0 WHERE booking_id IN ($idList)");

echo date('Y-m-d H:i:s') . " - Auto-completed booking IDs: $idList\n";