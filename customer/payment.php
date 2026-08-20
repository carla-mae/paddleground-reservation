<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

$user_id = $_SESSION['user_id'];
$activePage = 'payment';

// Account numbers are now managed from the admin Settings page rather than
// hardcoded here. Falls back to the original numbers if not set yet.
// Creates the settings table itself if it hasn't been created yet (e.g. if
// the admin hasn't opened Settings page before a customer hits Payment).
$conn->query(
    "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    )"
);

function get_setting($conn, string $key, string $default = ''): string {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        if ($stmt === false) { return $default; }
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['setting_value'] ?? $default;
    } catch (\Throwable $e) {
        // mysqli is set to throw on errors in this project, so if the table
        // is somehow still missing (or any other DB hiccup), just fall back
        // to the default number instead of crashing the payment page.
        return $default;
    }
}
$gcashNumber    = get_setting($conn, 'gcash_number', '0972 673 6565');
$gcashName      = get_setting($conn, 'gcash_name', 'Carla Verzosa');
$maribankNumber = get_setting($conn, 'maribank_number', '0389 648 378');
$maribankName   = get_setting($conn, 'maribank_name', 'Carla Verzosa');

// Render's disk is ephemeral — anything saved locally (uploads/receipts/)
// disappears on the next redeploy/restart, which is why old receipt links
// eventually 404. Receipts are now uploaded to Cloudinary instead, so the
// stored receipt_path is a permanent https:// URL that survives redeploys.
//
// Requires two environment variables (set in Render dashboard):
//   CLOUDINARY_CLOUD_NAME   - from Cloudinary dashboard home page
//   CLOUDINARY_UPLOAD_PRESET - an "unsigned" upload preset you create in
//                              Cloudinary: Settings -> Upload -> Add upload preset
function upload_receipt_to_cloud(string $tmpFilePath): ?string {
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME') ?: '';
    $uploadPreset = getenv('CLOUDINARY_UPLOAD_PRESET') ?: '';

    if ($cloudName === '' || $uploadPreset === '') {
        error_log('Receipt upload error: CLOUDINARY_CLOUD_NAME or CLOUDINARY_UPLOAD_PRESET env var is missing.');
        return null;
    }

    $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'           => new CURLFile($tmpFilePath),
            'upload_preset'  => $uploadPreset,
            'folder'         => 'paddleground_receipts',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Receipt upload error: Cloudinary HTTP {$httpCode}. cURL error: {$curlError}. Response: {$response}");
        return null;
    }

    $data = json_decode($response, true);
    return $data['secure_url'] ?? null;
}

// ---------- Handle POST actions: confirm_pay (confirm + pay combined) or cancel ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $booking_id = (int) ($_POST['booking_id'] ?? 0);
    $isAjax     = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    $respond_error = function (string $msg) use ($isAjax, $booking_id) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $msg]);
            exit();
        }
        die(htmlspecialchars($msg) . ' <a href="payment.php?booking_id=' . (int)$booking_id . '">Go back</a>.');
    };

    // --- Customer cancels: awaiting_confirmation -> cancelled. Frees the slot, no charge. ---
    // Tagged cancelled_by = 'customer' so admin/staff views can hide this —
    // a customer backing out before ever paying should be invisible to
    // staff, unlike an admin-initiated cancellation which they need to see.
    if ($action === 'cancel') {
        $upd = $conn->prepare(
            "UPDATE bookings SET status = 'cancelled', cancelled_by = 'customer'
             WHERE booking_id = ? AND user_id = ? AND status = 'awaiting_confirmation'"
        );
        if ($upd === false) {
            die('Prepare failed (cancel): ' . htmlspecialchars($conn->error));
        }
        $upd->bind_param("ii", $booking_id, $user_id);
        $upd->execute();
        $upd->close();

        header("Location: schedule.php?cancelled=1");
        exit();
    }

    // --- Customer picks a payment method AND confirms in one step. ---
    if ($action === 'confirm_pay') {
        $method = $_POST['method'] ?? '';

        if (!in_array($method, ['gcash', 'maribank'], true)) {
            $respond_error('Invalid payment method.');
        }

        $stmt = $conn->prepare("SELECT total_price, status FROM bookings WHERE booking_id = ? AND user_id = ?");
        if ($stmt === false) {
            die('Prepare failed (booking lookup): ' . htmlspecialchars($conn->error));
        }
        $stmt->bind_param("ii", $booking_id, $user_id);
        $stmt->execute();
        $bookingRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$bookingRow) {
            $respond_error('Booking not found.');
        }
        if ($bookingRow['status'] !== 'awaiting_confirmation') {
            $respond_error('This booking is no longer awaiting confirmation.');
        }
        $amount = (float) $bookingRow['total_price'];

        $receiptPath = null;

        if (empty($_FILES['receipt']['name'])) {
            $respond_error('Please upload your receipt of payment.');
        }
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $respond_error('Only JPG, PNG, or PDF receipts are allowed.');
        }
        if ($_FILES['receipt']['size'] > 5 * 1024 * 1024) {
            $respond_error('Receipt file is too large (max 5MB).');
        }

        $receiptPath = upload_receipt_to_cloud($_FILES['receipt']['tmp_name']);

        if ($receiptPath === null) {
            $respond_error('Failed to upload receipt. Please try again.');
        }

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare(
                "UPDATE bookings SET status = 'pending'
                 WHERE booking_id = ? AND user_id = ? AND status = 'awaiting_confirmation'"
            );
            if ($upd === false) {
                throw new Exception('Prepare failed (confirm): ' . $conn->error);
            }
            $upd->bind_param("ii", $booking_id, $user_id);
            $upd->execute();
            $confirmed = $upd->affected_rows > 0;
            $upd->close();

            if (!$confirmed) {
                throw new Exception('This booking could not be confirmed (it may have already been processed).');
            }

            $ins = $conn->prepare(
                "INSERT INTO payments (booking_id, method, receipt_path, amount) VALUES (?, ?, ?, ?)"
            );
            if ($ins === false) {
                throw new Exception('Prepare failed (payment insert): ' . $conn->error);
            }
            $ins->bind_param("issd", $booking_id, $method, $receiptPath, $amount);
            $ins->execute();
            $ins->close();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $respond_error($e->getMessage());
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        }

        header("Location: booking_status.php");
        exit();
    }

    // --- Customer resends a receipt after an admin flagged the previous one as not valid ---
    if ($action === 'resend_receipt') {
        $stmt = $conn->prepare(
            "SELECT p.payment_id, b.status
             FROM payments p
             JOIN bookings b ON b.booking_id = p.booking_id
             WHERE p.booking_id = ? AND b.user_id = ? AND p.verified = 2"
        );
        if ($stmt === false) {
            die('Prepare failed (resend lookup): ' . htmlspecialchars($conn->error));
        }
        $stmt->bind_param("ii", $booking_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $respond_error('This booking has no receipt currently awaiting resend.');
        }
        $payment_id = (int) $row['payment_id'];

        if (empty($_FILES['receipt']['name'])) {
            $respond_error('Please attach your receipt of payment.');
        }
        $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $respond_error('Only JPG, PNG, or PDF receipts are allowed.');
        }
        if ($_FILES['receipt']['size'] > 5 * 1024 * 1024) {
            $respond_error('Receipt file is too large (max 5MB).');
        }

        $receiptPath = upload_receipt_to_cloud($_FILES['receipt']['tmp_name']);

        if ($receiptPath === null) {
            $respond_error('Failed to upload receipt. Please try again.');
        }

        $upd = $conn->prepare(
            "UPDATE payments SET receipt_path = ?, verified = 0, verified_by = NULL WHERE payment_id = ?"
        );
        if ($upd === false) {
            die('Prepare failed (resend update): ' . htmlspecialchars($conn->error));
        }
        $upd->bind_param("si", $receiptPath, $payment_id);
        $upd->execute();
        $upd->close();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit();
        }

        header("Location: booking_status.php");
        exit();
    }
}

// ---------- GET: single booking, or list of unpaid bookings ----------
$booking = null;
$pendingBookings = [];
$needsResend = false;

$requestedId = $_GET['booking_id'] ?? null;

if ($requestedId !== null) {
    $booking_id = (int) $requestedId;
    $stmt = $conn->prepare(
        "SELECT b.booking_id, b.status, b.players, s.date, s.start_time, s.end_time, c.court_name, b.total_price,
                p.payment_id, p.method AS paid_method, p.verified
         FROM bookings b
         JOIN schedules s ON b.schedule_id = s.schedule_id
         JOIN courts c ON s.court_id = c.court_id
         LEFT JOIN payments p ON p.booking_id = b.booking_id
         WHERE b.booking_id = ? AND b.user_id = ? AND b.status != 'cancelled'
           AND (p.payment_id IS NULL OR p.verified = 2)"
    );
    if ($stmt === false) {
        die('Prepare failed (single booking query): ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        header("Location: payment.php");
        exit();
    }

    $needsResend = $booking['payment_id'] !== null;
} else {
    $stmt = $conn->prepare(
        "SELECT b.booking_id, b.status, s.date, s.start_time, s.end_time, c.court_name, b.total_price,
                p.payment_id, p.verified
         FROM bookings b
         JOIN schedules s ON b.schedule_id = s.schedule_id
         JOIN courts c ON s.court_id = c.court_id
         LEFT JOIN payments p ON p.booking_id = b.booking_id
         WHERE b.user_id = ? AND b.status != 'cancelled'
           AND ( (p.payment_id IS NULL AND b.status = 'awaiting_confirmation')
                 OR p.verified = 2 )
         ORDER BY s.date, s.start_time"
    );
    if ($stmt === false) {
        die('Prepare failed (pending bookings query): ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pendingBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
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
        padding: 40px 40px 40px 72px;
        display: flex;
        flex-direction: column;
        height: 100vh;
        overflow-y: auto;
        min-width: 0;
    }
    .content { flex-grow: 1; min-width: 0; max-width: 640px; width: 100%; margin: 0 auto; }
    h2 { font-size: 28px; font-weight: 800; margin-bottom: 6px; color: #1f2937; }
    .subtitle { color: #6b7280; margin-bottom: 28px; }

    .booking-summary {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 24px;
        font-size: 14px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.05);
    }
    .booking-summary .row { display: flex; justify-content: space-between; padding: 4px 0; color: #6b7280; gap: 10px; flex-wrap: wrap; }
    .booking-summary .row b { color: #1f2937; text-align: right; }
    .booking-summary .total {
        margin-top: 8px;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        font-size: 16px;
        font-weight: 700;
        color: #16a34a;
    }

    .method-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 24px;
    }
    .method-card {
        background: #ffffff;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        padding: 18px 10px;
        text-align: center;
        cursor: pointer;
        font-weight: 700;
        font-size: 14px;
        color: #374151;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .method-card:hover { border-color: #86efac; }
    .method-card.selected { border-color: #16a34a; background: rgba(22,163,74,0.08); color: #16a34a; }

    .detail-panel {
        display: none;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 22px;
        margin-bottom: 24px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.05);
    }
    .detail-panel.open { display: block; }
    .detail-panel .field-label {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 4px;
    }
    .detail-panel .account-number {
        font-size: 18px;
        font-weight: 700;
        color: #16a34a;
        margin-bottom: 18px;
        letter-spacing: 0.5px;
        word-break: break-word;
    }
    .detail-panel .account-name {
        font-size: 15px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 18px;
        word-break: break-word;
    }
    .detail-panel .cash-note {
        color: #6b7280;
        font-size: 14px;
        line-height: 1.5;
        margin-bottom: 4px;
        text-align: justify;
    }
    input[type="file"] {
        width: 100%;
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #1f2937;
        border-radius: 7px;
        padding: 10px;
        font-size: 13px;
        margin-bottom: 4px;
    }
    input[type="file"].input-invalid {
        border-color: #dc2626;
        margin-bottom: 6px;
    }
    .file-error {
        color: #dc2626;
        font-size: 12px;
        margin-bottom: 10px;
    }

    .receipt-preview {
        display: none;
        margin-top: 10px;
        margin-bottom: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
        max-width: 220px;
    }
    .receipt-preview.visible { display: block; }
    .receipt-preview img {
        display: block;
        width: 100%;
        height: auto;
        max-height: 200px;
        object-fit: cover;
    }
    .receipt-preview .file-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 10px 12px;
        background: #f9fafb;
        font-size: 12.5px;
        color: #6b7280;
    }
    .receipt-preview .remove-file {
        background: none;
        border: none;
        color: #dc2626;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        flex-shrink: 0;
    }
    .receipt-preview .remove-file:hover { text-decoration: underline; }

    .confirm-note {
        color: #6b7280;
        font-size: 13px;
        line-height: 1.5;
        margin-bottom: 16px;
    }
    .confirm-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-bottom: 24px;
    }
    .confirm-actions form { margin: 0; }
    .confirm-btn, .cancel-btn {
        width: 100%;
        font-weight: 700;
        font-size: 14px;
        border: none;
        padding: 14px;
        border-radius: 8px;
        cursor: pointer;
    }
    .confirm-btn {
        background: #16a34a;
        color: #ffffff;
    }
    .confirm-btn:hover { filter: brightness(1.08); }
    .confirm-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .cancel-btn {
        background: rgba(220, 38, 38, 0.08);
        color: #dc2626;
        border: 1px solid rgba(220, 38, 38, 0.35);
    }
    .cancel-btn:hover { background: rgba(220, 38, 38, 0.14); }

    .pending-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px 18px;
        margin-bottom: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        text-decoration: none;
        color: inherit;
        gap: 12px;
        flex-wrap: wrap;
        box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    }
    .pending-card:hover { border-color: #86efac; }
    .pending-card .court { font-weight: 700; margin-bottom: 2px; color: #1f2937; }
    .pending-card .when { font-size: 12px; color: #6b7280; }
    .pending-card .amount { color: #16a34a; font-weight: 700; }
    .empty-state { color: #9ca3af; font-size: 14px; }

    .resend-tag {
        display: inline-block;
        margin-left: 8px;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        background: rgba(217, 119, 6, 0.12);
        color: #b45309;
    }
    .resend-banner {
        background: rgba(217, 119, 6, 0.08);
        border: 1px solid rgba(217, 119, 6, 0.35);
        color: #92400e;
        border-radius: 12px;
        padding: 16px 18px;
        font-size: 14px;
        line-height: 1.5;
        margin-bottom: 24px;
    }
    .method-readonly {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        background: #f3f4f6;
        color: #6b7280;
        text-transform: uppercase;
        margin-bottom: 18px;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 32, 0.55);
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 16px;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 32px;
        max-width: 360px;
        width: 90%;
        text-align: center;
        box-shadow: 0 20px 50px rgba(0,0,0,0.18);
    }
    .modal-icon {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: rgba(22,163,74,0.12);
        color: #16a34a;
        font-size: 28px;
        line-height: 56px;
        margin: 0 auto 16px;
    }
    .modal-text {
        color: #1f2937;
        font-size: 15px;
        line-height: 1.5;
        margin-bottom: 22px;
    }
    .modal-ok-btn {
        width: 100%;
        background: #16a34a;
        color: #ffffff;
        font-weight: 700;
        font-size: 14px;
        border: none;
        padding: 12px;
        border-radius: 8px;
        cursor: pointer;
    }
    .modal-ok-btn:hover { filter: brightness(1.08); }

    .site-footer {
        margin-top: 40px;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
        font-size: 13px;
        color: #9ca3af;
        text-align: center;
        max-width: 640px;
        width: 100%;
        margin-left: auto;
        margin-right: auto;
    }

    @media (max-width: 900px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 76px 20px 28px; height: auto; overflow: visible; }
    }
    @media (max-width: 600px) {
        .main { padding: 76px 16px 20px; }
        h2 { font-size: 22px; }
        .subtitle { margin-bottom: 20px; }
        .method-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .method-card { padding: 14px 10px; }
        .confirm-actions {
            grid-template-columns: 1fr;
        }
        .confirm-actions form { order: 2; }
        #confirmPayBtn { order: 1; }
        .booking-summary, .detail-panel { padding: 16px; }
        .pending-card { flex-direction: column; align-items: flex-start; }
        .pending-card .amount { align-self: flex-end; }
        .modal-box { padding: 24px; }
        .site-footer { font-size: 12px; }
    }
</style>
</head>
<body>

<?php include '../includes/customer_sidebar.php'; ?>

<div class="main">
    <div class="content">
    <?php if ($booking && $needsResend): ?>

        <h2>Payment</h2>
        <p class="subtitle">There's an issue with your receipt.</p>

        <div class="booking-summary">
            <div class="row"><span>Court</span> <b><?= htmlspecialchars($booking['court_name']) ?></b></div>
            <div class="row"><span>Date</span> <b><?= date('F j, Y', strtotime($booking['date'])) ?></b></div>
            <div class="row"><span>Time</span>
                <b><?= date('g:i A', strtotime($booking['start_time'])) ?> – <?= date('g:i A', strtotime($booking['end_time'])) ?></b>
            </div>
            <div class="total"><span>Total</span> <span>&#8369;<?= number_format((float)$booking['total_price'], 2) ?></span></div>
        </div>

        <div class="resend-banner">
            Your receipt sent to us is not valid. Please resend a valid receipt so we can verify your schedule.
        </div>

        <span class="method-readonly"><?= htmlspecialchars($booking['paid_method']) ?></span>

        <div class="detail-panel open">
            <div class="field-label">Upload New Receipt of Payment</div>
            <input type="file" id="resendReceiptInput" accept=".jpg,.jpeg,.png,.pdf">
            <div class="receipt-preview" id="preview-resend">
                <img alt="Receipt preview">
                <div class="file-label">
                    <span class="file-name"></span>
                    <button type="button" class="remove-file">Remove</button>
                </div>
            </div>
        </div>

        <div class="confirm-actions" style="grid-template-columns: 1fr;">
            <button type="button" class="confirm-btn" id="resendBtn">Resend Receipt</button>
        </div>

        <div class="modal-overlay" id="successModal">
            <div class="modal-box">
                <div class="modal-icon">&#10003;</div>
                <p class="modal-text">Receipt resent! We'll take another look and verify your schedule shortly.</p>
                <button class="modal-ok-btn" id="successModalOk">OK</button>
            </div>
        </div>

        <script>
            const BOOKING_ID = <?= (int)$booking['booking_id'] ?>;
            const resendBtn = document.getElementById('resendBtn');
            const resendInput = document.getElementById('resendReceiptInput');

            resendBtn.addEventListener('click', async () => {
                if (!resendInput.files || resendInput.files.length === 0) {
                    resendInput.classList.add('input-invalid');
                    let errorMsg = resendInput.parentElement.querySelector('.file-error');
                    if (!errorMsg) {
                        errorMsg = document.createElement('p');
                        errorMsg.className = 'file-error';
                        resendInput.insertAdjacentElement('afterend', errorMsg);
                    }
                    errorMsg.textContent = 'Please attach your receipt of payment before submitting.';
                    resendInput.focus();
                    return;
                }

                resendBtn.disabled = true;
                resendBtn.textContent = 'Processing...';

                const formData = new FormData();
                formData.append('booking_id', BOOKING_ID);
                formData.append('action', 'resend_receipt');
                formData.append('receipt', resendInput.files[0]);

                try {
                    const res = await fetch('payment.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        document.getElementById('successModal').classList.add('open');
                    } else {
                        alert(data.message || 'Something went wrong. Please try again.');
                        resendBtn.disabled = false;
                        resendBtn.textContent = 'Resend Receipt';
                    }
                } catch (err) {
                    alert('Network error. Please try again.');
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Receipt';
                }
            });

            document.getElementById('successModalOk').addEventListener('click', () => {
                window.location.href = 'booking_status.php';
            });
        </script>

    <?php elseif ($booking): ?>

        <h2>Payment</h2>
        <p class="subtitle">Choose your payment method, then confirm your reservation.</p>

        <div class="booking-summary">
            <div class="row"><span>Court</span> <b><?= htmlspecialchars($booking['court_name']) ?></b></div>
            <div class="row"><span>Date</span> <b><?= date('F j, Y', strtotime($booking['date'])) ?></b></div>
            <div class="row"><span>Time</span>
                <b><?= date('g:i A', strtotime($booking['start_time'])) ?> – <?= date('g:i A', strtotime($booking['end_time'])) ?></b>
            </div>
            <div class="total"><span>Total</span> <span>&#8369;<?= number_format((float)$booking['total_price'], 2) ?></span></div>
        </div>

        <div class="method-grid">
            <div class="method-card" data-method="gcash">GCASH</div>
            <div class="method-card" data-method="maribank">MariBank</div>
        </div>

        <div class="detail-panel" id="panel-gcash">
            <div class="field-label">GCash Number</div>
            <div class="account-number"><?= htmlspecialchars($gcashNumber) ?></div>
            <div class="field-label">GCash Name</div>
            <div class="account-name"><?= htmlspecialchars($gcashName) ?></div>
            <div class="field-label">Upload Receipt of Payment</div>
            <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf">
            <div class="receipt-preview" id="preview-gcash">
                <img alt="Receipt preview">
                <div class="file-label">
                    <span class="file-name"></span>
                    <button type="button" class="remove-file">Remove</button>
                </div>
            </div>
        </div>

        <div class="detail-panel" id="panel-maribank">
            <div class="field-label">MariBank Account Number</div>
            <div class="account-number"><?= htmlspecialchars($maribankNumber) ?></div>
            <div class="field-label">Account Name</div>
            <div class="account-name"><?= htmlspecialchars($maribankName) ?></div>
            <div class="field-label">Upload Receipt of Payment</div>
            <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf">
            <div class="receipt-preview" id="preview-maribank">
                <img alt="Receipt preview">
                <div class="file-label">
                    <span class="file-name"></span>
                    <button type="button" class="remove-file">Remove</button>
                </div>
            </div>
        </div>

        <p class="confirm-note">
            Confirming submits your payment and locks in this schedule.
            Cancelling releases the slot immediately — no charge either way.
        </p>
        <div class="confirm-actions">
            <button type="button" class="confirm-btn" id="confirmPayBtn">Confirm</button>
            <form method="POST" action="payment.php" onsubmit="return confirm('Cancel this reservation? This cannot be undone.');">
                <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="cancel-btn">Cancel</button>
            </form>
        </div>

        <div class="modal-overlay" id="successModal">
            <div class="modal-box">
                <div class="modal-icon">&#10003;</div>
                <p class="modal-text">Successfully send your payment, enjoy playing our court</p>
                <button class="modal-ok-btn" id="successModalOk">OK</button>
            </div>
        </div>

        <script>
            const BOOKING_ID = <?= (int)$booking['booking_id'] ?>;

            const cards = document.querySelectorAll('.method-card');
            const panels = document.querySelectorAll('.detail-panel');
            const confirmBtn = document.getElementById('confirmPayBtn');
            let selectedMethod = '';

            cards.forEach(card => {
                card.addEventListener('click', () => {
                    cards.forEach(c => c.classList.remove('selected'));
                    panels.forEach(p => p.classList.remove('open'));

                    card.classList.add('selected');
                    selectedMethod = card.dataset.method;
                    document.getElementById('panel-' + selectedMethod).classList.add('open');
                    clearFileError(selectedMethod);
                });
            });

            function clearFileError(method) {
                const panel = document.getElementById('panel-' + method);
                const existing = panel.querySelector('.file-error');
                if (existing) existing.remove();
                const fileInput = panel.querySelector('input[type="file"]');
                if (fileInput) fileInput.classList.remove('input-invalid');
            }

            confirmBtn.addEventListener('click', async () => {
                if (!selectedMethod) {
                    alert('Please select a payment method first.');
                    return;
                }

                const panel = document.getElementById('panel-' + selectedMethod);
                const fileInput = panel.querySelector('input[type="file"]');
                let receiptFile = null;

                if (!fileInput.files || fileInput.files.length === 0) {
                    fileInput.classList.add('input-invalid');

                    let errorMsg = panel.querySelector('.file-error');
                    if (!errorMsg) {
                        errorMsg = document.createElement('p');
                        errorMsg.className = 'file-error';
                        fileInput.insertAdjacentElement('afterend', errorMsg);
                    }
                    errorMsg.textContent = 'Please attach your receipt of payment before submitting.';
                    fileInput.focus();
                    return;
                }
                receiptFile = fileInput.files[0];

                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Processing...';

                const formData = new FormData();
                formData.append('booking_id', BOOKING_ID);
                formData.append('action', 'confirm_pay');
                formData.append('method', selectedMethod);
                if (receiptFile) formData.append('receipt', receiptFile);

                try {
                    const res = await fetch('payment.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        document.getElementById('successModal').classList.add('open');
                    } else {
                        alert(data.message || 'Something went wrong. Please try again.');
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = 'Confirm';
                    }
                } catch (err) {
                    alert('Network error. Please try again.');
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirm';
                }
            });

            document.getElementById('successModalOk').addEventListener('click', () => {
                window.location.href = 'booking_status.php';
            });
        </script>

    <?php else: ?>

        <h2>Payment</h2>
        <p class="subtitle">Select a booking to pay for.</p>

        <?php if (empty($pendingBookings)): ?>
            <p class="empty-state">You have no bookings awaiting payment right now.</p>
        <?php else: ?>
            <?php foreach ($pendingBookings as $pb): ?>
                <a class="pending-card" href="payment.php?booking_id=<?= (int)$pb['booking_id'] ?>">
                    <div>
                        <div class="court">
                            <?= htmlspecialchars($pb['court_name']) ?>
                            <?php if ((int)($pb['verified'] ?? 0) === 2): ?>
                                <span class="resend-tag">Receipt not valid — resend</span>
                            <?php endif; ?>
                        </div>
                        <div class="when">
                            <?= date('M j, Y', strtotime($pb['date'])) ?> &middot;
                            <?= date('g:i A', strtotime($pb['start_time'])) ?> – <?= date('g:i A', strtotime($pb['end_time'])) ?>
                        </div>
                    </div>
                    <div class="amount">&#8369;<?= number_format((float)$pb['total_price'], 2) ?></div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>
    </div>

    <footer class="site-footer">
        &copy; <?= date('Y') ?> Paddle Ground Reservation &middot; San Francisco, Agusan del Sur
    </footer>
</div>

<script>
// --- Receipt image preview (works for both the normal payment panels and
// the resend-receipt panel, whichever is present on the page) ---
function wireReceiptPreview(inputEl, previewEl) {
    if (!inputEl || !previewEl) return;

    const img = previewEl.querySelector('img');
    const fileNameEl = previewEl.querySelector('.file-name');
    const removeBtn = previewEl.querySelector('.remove-file');

    inputEl.addEventListener('change', () => {
        const file = inputEl.files && inputEl.files[0];
        if (!file) {
            previewEl.classList.remove('visible');
            return;
        }

        fileNameEl.textContent = file.name;

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
                img.src = e.target.result;
                img.style.display = 'block';
                previewEl.classList.add('visible');
            };
            reader.readAsDataURL(file);
        } else {
            // Non-image (e.g. PDF) — no thumbnail, just show the filename.
            img.removeAttribute('src');
            img.style.display = 'none';
            previewEl.classList.add('visible');
        }
    });

    removeBtn.addEventListener('click', () => {
        inputEl.value = '';
        previewEl.classList.remove('visible');
        img.removeAttribute('src');
        fileNameEl.textContent = '';
    });
}

wireReceiptPreview(document.querySelector('#panel-gcash input[type="file"]'), document.getElementById('preview-gcash'));
wireReceiptPreview(document.querySelector('#panel-maribank input[type="file"]'), document.getElementById('preview-maribank'));
wireReceiptPreview(document.getElementById('resendReceiptInput'), document.getElementById('preview-resend'));
</script>

</body>
</html>