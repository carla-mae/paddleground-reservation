<?php
require_once '../config/session_check.php';
require_role(['admin']);
include '../config/db.php';

$activePage = 'payments';

// --- Handle verify action ---
if (isset($_GET['verify'])) {
    $payment_id = (int) $_GET['verify'];
    $admin_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "UPDATE payments p
         JOIN bookings b ON b.booking_id = p.booking_id
         SET p.verified = 1, p.verified_by = ?, b.status = 'approved'
         WHERE p.payment_id = ?"
    );
    if ($stmt === false) {
        die('Prepare failed (verify): ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("ii", $admin_id, $payment_id);
    $stmt->execute();
    $stmt->close();

    require_once '../PHPMailer/mailer_helper.php';

    $infoStmt = $conn->prepare(
        "SELECT u.email, u.full_name, p.amount, p.method, c.court_name, s.date, s.start_time, s.end_time
         FROM payments p
         JOIN bookings b ON p.booking_id = b.booking_id
         JOIN users u ON b.user_id = u.user_id
         JOIN schedules s ON b.schedule_id = s.schedule_id
         JOIN courts c ON s.court_id = c.court_id
         WHERE p.payment_id = ?"
    );
    $infoStmt->bind_param("i", $payment_id);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();

    if ($info) {
        $formattedDate = date('F j, Y', strtotime($info['date']));
        $formattedTime = date('g:i A', strtotime($info['start_time'])) . ' - ' . date('g:i A', strtotime($info['end_time']));
        $subject = 'PaddleGround: Payment Received';
        $body = "Hi {$info['full_name']},\n\n"
              . "Thank you for your payment, boss! We received ₱" . number_format((float)$info['amount'], 2)
              . " via " . strtoupper($info['method']) . " for your booking on {$info['court_name']} ({$formattedDate}, {$formattedTime}).\n\n"
              . "Your reservation is now confirmed. See you on the court!\n\n"
              . "— PaddleGround Team";

        send_smtp_mail($info['email'], $info['full_name'], $subject, $body);
    }

    header("Location: verify_payment.php");
    exit();
}

// --- Handle "request resend" action ---
if (isset($_GET['resend'])) {
    $payment_id = (int) $_GET['resend'];
    $admin_id = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("UPDATE payments SET verified = 2, verified_by = ? WHERE payment_id = ? AND verified = 0");
    if ($stmt === false) {
        die('Prepare failed (resend request): ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("ii", $admin_id, $payment_id);
    $stmt->execute();
    $stmt->close();

    header("Location: verify_payment.php");
    exit();
}

// --- Handle "cancel booking" action: CASH-only ---
if (isset($_GET['cancel'])) {
    $payment_id = (int) $_GET['cancel'];

    $stmt = $conn->prepare(
        "UPDATE bookings b
         JOIN payments p ON p.booking_id = b.booking_id
         SET b.status = 'cancelled', b.cancelled_by = 'admin'
         WHERE p.payment_id = ? AND p.method = 'cash' AND p.verified = 0"
    );
    if ($stmt === false) {
        die('Prepare failed (cancel booking): ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $stmt->close();

    header("Location: verify_payment.php");
    exit();
}

// --- Handle refund submission (rain cancellation) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_payment_id'])) {
    $payment_id = (int) $_POST['refund_payment_id'];
    $admin_id = (int) $_SESSION['user_id'];

    if (!isset($_FILES['refund_proof']) || $_FILES['refund_proof']['error'] !== UPLOAD_ERR_OK) {
        die('Please upload a photo as proof of refund.');
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES['refund_proof']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) {
        die('Invalid file type. Please upload a JPG, PNG, or WEBP image.');
    }

    $uploadDir = '../uploads/refunds/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'refund_' . $payment_id . '_' . time() . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['refund_proof']['tmp_name'], $destination)) {
        die('Failed to upload refund proof. Please try again.');
    }

    $relativePath = 'uploads/refunds/' . $filename;

    // Mark the payment as refunded AND cancel the booking in one go, so
    // view_bookings.php reflects the cancellation immediately.
    $stmt = $conn->prepare(
        "UPDATE payments p
         JOIN bookings b ON b.booking_id = p.booking_id
         SET p.refund_status = 1, p.refund_proof_path = ?, p.refunded_by = ?, p.refunded_at = NOW(),
             b.status = 'cancelled', b.cancelled_by = 'admin'
         WHERE p.payment_id = ?"
    );
    if ($stmt === false) {
        die('Prepare failed (refund): ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("sii", $relativePath, $admin_id, $payment_id);
    $stmt->execute();
    $stmt->close();

    // --- Send email notification to the customer ---
    require_once '../PHPMailer/mailer_helper.php';

    $infoStmt = $conn->prepare(
        "SELECT u.email, u.full_name, p.amount, p.method, c.court_name, s.date, s.start_time, s.end_time
         FROM payments p
         JOIN bookings b ON p.booking_id = b.booking_id
         JOIN users u ON b.user_id = u.user_id
         JOIN schedules s ON b.schedule_id = s.schedule_id
         JOIN courts c ON s.court_id = c.court_id
         WHERE p.payment_id = ?"
    );
    $infoStmt->bind_param("i", $payment_id);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();

    if ($info) {
        $formattedDate = date('F j, Y', strtotime($info['date']));
        $formattedTime = date('g:i A', strtotime($info['start_time'])) . ' - ' . date('g:i A', strtotime($info['end_time']));
        $subject = 'PaddleGround: Refund Sent';
        $body = "Hi {$info['full_name']},\n\n"
              . "Due to rain, your booking on {$info['court_name']} ({$formattedDate}, {$formattedTime}) has been cancelled.\n\n"
              . "We have refunded ₱" . number_format((float)$info['amount'], 2)
              . " to your " . strtoupper($info['method']) . " account. Please check your account to confirm receipt.\n\n"
              . "We apologize for the inconvenience and hope to see you again soon!\n\n"
              . "— PaddleGround Team";

        send_smtp_mail($info['email'], $info['full_name'], $subject, $body);
    }

    header("Location: verify_payment.php");
    exit();
}

$sql = "SELECT p.payment_id, b.booking_id, b.players, u.full_name, c.court_name, s.date,
               s.start_time, s.end_time,
               p.method, p.amount, p.receipt_path, p.verified,
               p.refund_status, p.refund_proof_path,
               u.gcash_number, u.gcash_name, u.maribank_number, u.maribank_name
        FROM payments p
        JOIN bookings b ON p.booking_id = b.booking_id
        JOIN users u ON b.user_id = u.user_id
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN courts c ON s.court_id = c.court_id
        WHERE (b.status != 'cancelled' OR p.refund_status = 1)
        ORDER BY s.date DESC, c.court_name ASC, p.verified ASC";
$result = $conn->query($sql);
$payments = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Verify Payments</title>
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
        min-width: 0;
        height: 100vh;
        overflow-y: auto;
    }
    .main-inner {
        max-width: 1200px;
        width: 100%;
        margin: 0 auto;
    }
    h2 { font-size: 28px; font-weight: 800; margin-bottom: 6px; }
    .subtitle { color: var(--muted); margin-bottom: 28px; }

    table {
        width: 100%;
        max-width: 1200px;
        border-collapse: collapse;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        overflow: hidden;
    }
    th {
        text-align: left;
        font-size: 12px;
        letter-spacing: 0.5px;
        color: var(--muted);
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-soft);
        text-transform: uppercase;
    }
    td {
        padding: 14px 18px;
        border-bottom: 1px solid var(--border-soft);
        font-size: 14px;
    }
    tr:last-child td { border-bottom: none; }

    .time-cell {
        white-space: nowrap;
        color: #3f4b45;
    }

    .method-tag {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        background: rgba(107,114,128,0.12);
        color: var(--muted);
        text-transform: uppercase;
    }
    .receipt-link { color: var(--brand-green); font-size: 13px; text-decoration: none; }
    .receipt-link:hover { text-decoration: underline; }
    .no-receipt { color: #9aa79f; font-size: 13px; }

    .verified-yes { color: var(--brand-green-dark); font-weight: 700; }
    .resend-pending { color: #b45309; font-weight: 700; }
    .refunded-tag { color: #2563eb; font-weight: 700; }
    .action-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .btn-verify, .btn-resend, .btn-cancel, .btn-refund {
        display: inline-block;
        text-decoration: none;
        font-weight: 700;
        font-size: 12px;
        padding: 7px 14px;
        border-radius: 7px;
        white-space: nowrap;
        border: none;
        cursor: pointer;
        font-family: inherit;
    }
    .btn-verify {
        background: rgba(22,163,74,0.12);
        color: var(--brand-green-dark);
    }
    .btn-verify:hover { background: rgba(22,163,74,0.2); }
    .btn-resend {
        background: rgba(217,119,6,0.10);
        color: #b45309;
        border: 1px solid rgba(217,119,6,0.35);
    }
    .btn-resend:hover { background: rgba(217,119,6,0.18); }
    .btn-cancel {
        background: rgba(239,68,68,0.10);
        color: #b91c1c;
        border: 1px solid rgba(239,68,68,0.35);
    }
    .btn-cancel:hover { background: rgba(239,68,68,0.18); }
    .btn-refund {
        background: rgba(37,99,235,0.10);
        color: #2563eb;
        border: 1px solid rgba(37,99,235,0.35);
    }
    .btn-refund:hover { background: rgba(37,99,235,0.18); }

    .empty-state { color: var(--muted); font-size: 14px; }

    .date-group {
        max-width: 1200px;
        margin-bottom: 24px;
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 12px 30px -18px rgba(23, 48, 31, 0.16);
    }
    .date-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 20px;
        background: var(--page-bg);
        border-bottom: 1px solid var(--border-soft);
        font-size: 15px;
        font-weight: 800;
    }
    .date-header .done-tag {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        padding: 4px 10px;
        border-radius: 999px;
        display: none;
    }
    .date-group.past .date-header {
        background: rgba(239, 68, 68, 0.08);
        border-bottom-color: rgba(239, 68, 68, 0.25);
    }
    .date-group.past .date-header .done-tag {
        display: inline-block;
        background: rgba(239, 68, 68, 0.14);
        color: #b91c1c;
    }
    .date-group table {
        border: none;
        border-radius: 0;
        max-width: none;
    }
    .date-group table tr:last-child td { border-bottom: none; }

    .court-divider-row td {
        background: var(--page-bg);
        color: var(--brand-green-dark);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 8px 18px;
        border-bottom: 1px solid var(--border-soft);
    }

    .site-footer {
        text-align: center;
        color: var(--muted);
        font-size: 13px;
        margin-top: 40px;
        padding-top: 20px;
    }

    /* ---------- Refund Modal ---------- */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(23, 48, 31, 0.5);
        z-index: 700;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .modal-overlay.visible { display: flex; }
    .modal-box {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        width: 100%;
        max-width: 440px;
        padding: 26px 28px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 50px rgba(23, 48, 31, 0.2);
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }
    .modal-header h3 { font-size: 19px; font-weight: 800; }
    .modal-close {
        background: none;
        border: none;
        color: var(--muted);
        font-size: 22px;
        cursor: pointer;
        line-height: 1;
    }
    .modal-close:hover { color: var(--brand-ink); }
    .modal-customer-name { color: var(--muted); font-size: 13.5px; margin-bottom: 18px; }
    .refund-detail-group { margin-bottom: 14px; }
    .refund-detail-group label {
        display: block;
        font-size: 11.5px;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 4px;
    }
    .refund-detail-value {
        background: #F4F7F5;
        border: 1px solid var(--border-soft);
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 14px;
        font-weight: 600;
        color: var(--brand-ink);
    }
    .refund-upload-group { margin-top: 20px; margin-bottom: 4px; }
    .refund-upload-group label {
        display: block;
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 8px;
    }
    .refund-upload-group input[type="file"] {
        width: 100%;
        font-size: 13px;
        color: var(--brand-ink);
    }
    .refund-upload-error {
        display: none;
        color: #b91c1c;
        font-size: 12.5px;
        margin-top: 8px;
    }
    .refund-upload-error.visible { display: block; }

    /* ---------- Upload Proof of Refund: file preview (thumbnail + filename + Remove) ---------- */
    .refund-preview-box {
        display: none;
        margin-top: 12px;
        border: 1px solid var(--border-soft);
        border-radius: 10px;
        padding: 10px;
        background: #F4F7F5;
        flex-direction: column;
        align-items: center;
        gap: 8px;
    }
    .refund-preview-box.visible { display: flex; }
    .refund-preview-thumb {
        width: 100%;
        max-height: 220px;
        object-fit: contain;
        border-radius: 8px;
        border: 1px solid var(--border-soft);
        background: #ffffff;
    }
    .refund-preview-remove {
        background: none;
        border: none;
        color: #b91c1c;
        font-weight: 700;
        font-size: 12.5px;
        cursor: pointer;
        text-decoration: underline;
        padding: 0;
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        margin-top: 24px;
    }
    .btn-modal-cancel, .btn-modal-submit {
        flex: 1;
        padding: 12px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 14px;
        border: none;
        cursor: pointer;
        font-family: inherit;
    }
    .btn-modal-cancel {
        background: var(--page-bg);
        color: var(--brand-ink);
        border: 1px solid var(--border-soft);
    }
    .btn-modal-cancel:hover { background: #DDE6E0; }
    .btn-modal-submit {
        background: var(--brand-green);
        color: #ffffff;
    }
    .btn-modal-submit:hover { filter: brightness(1.08); }

    @media (max-width: 900px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 28px 20px; height: auto; overflow-y: visible; }
    }

    @media (max-width: 700px) {
        .main { padding: 76px 14px 20px; }
        h2 { font-size: 22px; }
        .subtitle { font-size: 13.5px; margin-bottom: 20px; }

        .date-group, table { max-width: none; }
        .date-header { padding: 12px 16px; font-size: 14px; }

        table, thead, tbody, tr, th, td { display: block; }
        thead { display: none; }

        .court-divider-row { padding: 0; }
        .court-divider-row td {
            display: block;
            border-top: none;
        }

        tr:not(.court-divider-row) {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-soft);
        }
        tr:not(.court-divider-row) td {
            padding: 4px 0;
            border-bottom: none;
            font-size: 13.5px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            text-align: right;
        }
        tr:not(.court-divider-row) td::before {
            content: attr(data-label);
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            text-align: left;
            flex-shrink: 0;
        }
        .method-tag { font-size: 11px; }
        .action-group { justify-content: flex-end; }
    }

    @supports (padding: max(0px)) {
        .main { padding-left: max(40px, env(safe-area-inset-left)); padding-right: max(40px, env(safe-area-inset-right)); }
    }
    @media (max-width: 900px) {
        @supports (padding: max(0px)) {
            .main { padding-left: max(28px, env(safe-area-inset-left)); padding-right: max(28px, env(safe-area-inset-right)); }
        }
    }
    @media (max-width: 700px) {
        @supports (padding: max(0px)) {
            .main {
                padding-left: max(14px, env(safe-area-inset-left));
                padding-right: max(14px, env(safe-area-inset-right));
                padding-top: max(76px, calc(env(safe-area-inset-top) + 60px));
            }
        }
    }
</style>
</head>
<body>

<?php include '../includes/admin_sidebar.php'; ?>

<div class="main">
    <div class="main-inner">
    <h2>Verify Payments</h2>
    <p class="subtitle">Confirm customer payments against uploaded receipts.</p>

    <?php if (empty($payments)): ?>
        <p class="empty-state">No payments have been submitted yet.</p>
    <?php else: ?>
        <?php
            $groups = [];
            foreach ($payments as $row) {
                $groups[$row['date']][$row['court_name']][] = $row;
            }
            $today = date('Y-m-d');
        ?>
        <?php foreach ($groups as $groupDate => $rows): ?>
            <?php $isPast = $groupDate < $today; ?>
            <div class="date-group<?= $isPast ? ' past' : '' ?>">
                <div class="date-header">
                    <span><?= date('F j, Y', strtotime($groupDate)) ?></span>
                    <span class="done-tag">Done</span>
                </div>
                <table>
                    <tr>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Amount</th>
                        <th>Time</th>
                        <th>Receipt</th>
                        <th>Status</th>
                    </tr>
                    <?php foreach ($rows as $courtName => $courtRows): ?>
                    <tr class="court-divider-row">
                        <td colspan="6"><?= htmlspecialchars($courtName) ?></td>
                    </tr>
                    <?php foreach ($courtRows as $row): ?>
                    <tr>
                        <td data-label="Customer"><?= htmlspecialchars($row['full_name']) ?></td>
                        <td data-label="Method"><span class="method-tag"><?= htmlspecialchars($row['method']) ?></span></td>
                        <td data-label="Amount">&#8369;<?= number_format((float)$row['amount'], 2) ?></td>
                        <td class="time-cell" data-label="Time"><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= date('g:i A', strtotime($row['end_time'])) ?></td>
                        <td data-label="Receipt">
                            <?php if (!empty($row['receipt_path'])): ?>
                                <?php
                                // New receipts store a full Cloudinary URL (https://...),
                                // so it must be used as-is. Older receipts (uploaded
                                // before the Cloudinary switch) stored a local relative
                                // path and still need the "../" prefix — those will 404
                                // since Render's local disk doesn't persist files across
                                // deploys, but this keeps the markup from breaking either way.
                                $receiptUrl = $row['receipt_path'];
                                $isAbsoluteUrl = (strpos($receiptUrl, 'http://') === 0 || strpos($receiptUrl, 'https://') === 0);
                                $receiptHref = $isAbsoluteUrl ? $receiptUrl : ('../' . $receiptUrl);
                            ?>
                            <a class="receipt-link" href="<?= htmlspecialchars($receiptHref) ?>" target="_blank">View &rarr;</a>
                            <?php else: ?>
                                <span class="no-receipt">Cash — no receipt</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if ((int)$row['verified'] === 1): ?>
                                <?php if ((int)$row['refund_status'] === 1): ?>
                                    <span class="refunded-tag">Refunded</span>
                                <?php else: ?>
                                    <div class="action-group">
                                        <span class="verified-yes">Verified</span>
                                        <button type="button" class="btn-refund"
                                            onclick="openRefundModal(
                                                <?= (int)$row['payment_id'] ?>,
                                                '<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($row['gcash_number'] ?? '', ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($row['gcash_name'] ?? '', ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($row['maribank_number'] ?? '', ENT_QUOTES) ?>',
                                                '<?= htmlspecialchars($row['maribank_name'] ?? '', ENT_QUOTES) ?>'
                                            )">Refund</button>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ((int)$row['verified'] === 2): ?>
                                <span class="resend-pending">Resend requested &hellip;</span>
                            <?php else: ?>
                                <div class="action-group">
                                    <a class="btn-verify" href="?verify=<?= (int)$row['payment_id'] ?>"
                                       onclick="return confirm('Mark this payment as verified?')">Verify</a>
                                    <?php if ($row['method'] === 'cash'): ?>
                                        <a class="btn-cancel" href="?cancel=<?= (int)$row['payment_id'] ?>"
                                           onclick="return confirm('Cancel this booking? Use this if the customer is taking too long to pay cash on-site. The slot will be freed up for other customers. This cannot be undone.')">Cancel Booking</a>
                                    <?php else: ?>
                                        <a class="btn-resend" href="?resend=<?= (int)$row['payment_id'] ?>"
                                           onclick="return confirm('Ask the customer to resend their receipt? The booking stays as is — the customer will see a message asking for a valid receipt.')">Request Resend</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="site-footer">&copy; 2026 Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
    </div>
</div>

<!-- ---------- Refund Modal ---------- -->
<div class="modal-overlay" id="refundModalOverlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Refund Payment</h3>
            <button type="button" class="modal-close" onclick="closeRefundModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="refundForm" novalidate>
            <input type="hidden" name="refund_payment_id" id="refundPaymentId">
            <p class="modal-customer-name">Customer: <b id="refundCustomerName"></b></p>

            <div class="refund-detail-group">
                <label>GCash Number</label>
                <div class="refund-detail-value" id="refundGcashNumber">—</div>
            </div>
            <div class="refund-detail-group">
                <label>GCash Name</label>
                <div class="refund-detail-value" id="refundGcashName">—</div>
            </div>
            <div class="refund-detail-group">
                <label>Maribank Account Number</label>
                <div class="refund-detail-value" id="refundMaribankNumber">—</div>
            </div>
            <div class="refund-detail-group">
                <label>Maribank Account Name</label>
                <div class="refund-detail-value" id="refundMaribankName">—</div>
            </div>

            <div class="refund-upload-group">
                <label>Upload Proof of Refund (screenshot/photo)</label>
                <input type="file" name="refund_proof" id="refundProofInput" accept="image/*" required>
                <div class="refund-upload-error" id="refundUploadError">Please attach a photo/screenshot as proof of refund before confirming.</div>

                <!-- Preview of the chosen proof file: image only + Remove -->
                <div class="refund-preview-box" id="refundPreviewBox">
                    <img class="refund-preview-thumb" id="refundPreviewThumb" src="" alt="Preview">
                    <button type="button" class="refund-preview-remove" id="refundPreviewRemove">Remove</button>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeRefundModal()">Cancel</button>
                <button type="submit" class="btn-modal-submit">Confirm Refund</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRefundModal(paymentId, customerName, gcashNumber, gcashName, maribankNumber, maribankName) {
    document.getElementById('refundPaymentId').value = paymentId;
    document.getElementById('refundCustomerName').textContent = customerName;
    document.getElementById('refundGcashNumber').textContent = gcashNumber || 'Not provided';
    document.getElementById('refundGcashName').textContent = gcashName || 'Not provided';
    document.getElementById('refundMaribankNumber').textContent = maribankNumber || 'Not provided';
    document.getElementById('refundMaribankName').textContent = maribankName || 'Not provided';
    document.getElementById('refundProofInput').value = '';
    document.getElementById('refundUploadError').classList.remove('visible');

    // Reset the file preview whenever the modal is (re)opened for a payment.
    document.getElementById('refundPreviewBox').classList.remove('visible');
    document.getElementById('refundPreviewThumb').src = '';

    document.getElementById('refundModalOverlay').classList.add('visible');
}
function closeRefundModal() {
    document.getElementById('refundModalOverlay').classList.remove('visible');
}

// Belt-and-suspenders check: even with the "required" attribute on the file
// input, this makes sure "Confirm Refund" can never submit the form to the
// server unless a proof file has actually been attached.
document.getElementById('refundForm').addEventListener('submit', function (e) {
    const fileInput = document.getElementById('refundProofInput');
    const errorMsg = document.getElementById('refundUploadError');

    if (!fileInput.files || fileInput.files.length === 0) {
        e.preventDefault();
        errorMsg.classList.add('visible');
        fileInput.focus();
        return false;
    }

    errorMsg.classList.remove('visible');
});

// Hide the error the moment the admin picks a file, and show the
// thumbnail + filename preview for the chosen proof photo.
document.getElementById('refundProofInput').addEventListener('change', function () {
    const previewBox = document.getElementById('refundPreviewBox');

    if (this.files && this.files.length > 0) {
        document.getElementById('refundUploadError').classList.remove('visible');

        const file = this.files[0];
        const reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('refundPreviewThumb').src = e.target.result;
            previewBox.classList.add('visible');
        };
        reader.readAsDataURL(file);
    } else {
        previewBox.classList.remove('visible');
        document.getElementById('refundPreviewThumb').src = '';
    }
});

// Let the admin clear the chosen file straight from the preview.
document.getElementById('refundPreviewRemove').addEventListener('click', function () {
    const fileInput = document.getElementById('refundProofInput');
    fileInput.value = '';
    document.getElementById('refundPreviewBox').classList.remove('visible');
    document.getElementById('refundPreviewThumb').src = '';
});
</script>

</body>
</html>