<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

// NOTE: This standalone page is no longer part of the active booking flow.
// Receipt upload now happens inside payment.php as part of choosing a
// payment method for a specific booking. This file is kept only so old
// links/bookmarks don't 404 -- it's not linked from the sidebar anymore.
// Safe to delete once you're confident nothing else references it.

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id    = $_SESSION['user_id'];
    $booking_id = (int) ($_POST['booking_id'] ?? 0);
    $amount     = (float) ($_POST['amount'] ?? 0);
    $method     = $_POST['method'] ?? 'gcash';

    if (!in_array($method, ['cash', 'gcash', 'maribank'], true)) {
        $method = 'gcash';
    }

    // Confirm the booking belongs to this customer
    $stmt = $conn->prepare("SELECT booking_id FROM bookings WHERE booking_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        $stmt->close();
        $message = 'That booking was not found under your account.';
    } else {
        $stmt->close();

        $target_dir = "../uploads/receipts/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        $file_name = time() . "_" . basename($_FILES["receipt"]["name"]);
        $target_file = $target_dir . $file_name;

        if (move_uploaded_file($_FILES["receipt"]["tmp_name"], $target_file)) {
            $stmt = $conn->prepare(
                "INSERT INTO payments (booking_id, method, receipt_path, amount) VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("issd", $booking_id, $method, $file_name, $amount);
            $stmt->execute();
            $stmt->close();
            $message = 'Receipt uploaded. Awaiting verification.';
        } else {
            $message = 'Upload failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Upload Receipt</title></head>
<body style="font-family:sans-serif; padding:40px;">
    <?php if ($message): ?><p><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="number" name="booking_id" placeholder="Booking ID" required><br><br>
        <input type="number" step="0.01" name="amount" placeholder="Amount Paid" required><br><br>
        <select name="method">
            <option value="gcash">GCash</option>
            <option value="maribank">MariBank</option>
            <option value="cash">Cash</option>
        </select><br><br>
        <input type="file" name="receipt" required><br><br>
        <button type="submit">Upload</button>
    </form>
</body>
</html>