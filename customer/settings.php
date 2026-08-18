<?php
require_once '../config/session_check.php';
require_role(['customer']);
include '../config/db.php';

$user_id = $_SESSION['user_id'];
$activePage = 'settings';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gcash_number    = trim($_POST['gcash_number'] ?? '');
    $gcash_name      = trim($_POST['gcash_name'] ?? '');
    $maribank_number = trim($_POST['maribank_number'] ?? '');
    $maribank_name   = trim($_POST['maribank_name'] ?? '');

    $stmt = $conn->prepare(
        "UPDATE users SET gcash_number = ?, gcash_name = ?, maribank_number = ?, maribank_name = ? WHERE user_id = ?"
    );
    if ($stmt === false) {
        die('Prepare failed (settings save): ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("ssssi", $gcash_number, $gcash_name, $maribank_number, $maribank_name, $user_id);
    if ($stmt->execute()) {
        $message = 'Your refund details have been saved.';
    } else {
        $error = 'Something went wrong. Please try again.';
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT gcash_number, gcash_name, maribank_number, maribank_name FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$details = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings</title>
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
        --field-bg: #F4F7F5;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: var(--page-bg);
        color: var(--brand-ink);
        display: flex;
        min-height: 100vh;
    }
    .content-col { flex-grow: 1; display: flex; flex-direction: column; min-width: 0; }
    .main { flex-grow: 1; padding: 40px; }
    h2 { font-size: 28px; font-weight: 800; margin-bottom: 6px; }
    .subtitle { color: var(--muted); margin-bottom: 24px; max-width: 560px; line-height: 1.5; }

    .alert {
        max-width: 560px;
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 20px;
    }
    .alert.success { background: rgba(22,163,74,0.10); color: var(--brand-green-dark); border: 1px solid rgba(22,163,74,0.3); }
    .alert.error { background: rgba(239,68,68,0.10); color: #b91c1c; border: 1px solid rgba(239,68,68,0.3); }

    .settings-form {
        max-width: 560px;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        padding: 28px 30px;
        box-shadow: 0 20px 50px -20px rgba(23, 48, 31, 0.18), 0 2px 8px rgba(23, 48, 31, 0.05);
    }
    .settings-form h3 {
        font-size: 15px;
        color: var(--brand-green);
        margin-bottom: 14px;
        margin-top: 22px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .settings-form h3:first-child { margin-top: 0; }
    .settings-form label {
        display: block;
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 6px;
        margin-top: 14px;
    }
    .settings-form input {
        width: 100%;
        padding: 11px 14px;
        background: var(--field-bg);
        border: 1px solid var(--border-soft);
        border-radius: 8px;
        color: var(--brand-ink);
        font-size: 14px;
    }
    .settings-form input:focus {
        outline: none;
        border-color: var(--brand-green);
        background: #ffffff;
    }
    .settings-form button {
        margin-top: 26px;
        width: 100%;
        background: var(--brand-green);
        color: #ffffff;
        font-weight: 700;
        border: none;
        padding: 13px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
    }
    .settings-form button:hover { filter: brightness(1.08); }

    .site-footer { text-align: center; padding: 18px; font-size: 12px; color: var(--muted); border-top: 1px solid var(--border-soft); }
</style>
</head>
<body>

<?php include '../includes/customer_sidebar.php'; ?>

<div class="content-col">
    <div class="main">
        <div>
            <h2>Settings</h2>
            <p class="subtitle">Save your GCash and Maribank details here. If a session gets cancelled due to rain, the admin will use these details to send your refund.</p>

            <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" class="settings-form">
                <h3>GCash Details</h3>
                <label>GCash Number</label>
                <input type="text" name="gcash_number" value="<?= htmlspecialchars($details['gcash_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
                <label>GCash Name</label>
                <input type="text" name="gcash_name" value="<?= htmlspecialchars($details['gcash_name'] ?? '') ?>" placeholder="Juan Dela Cruz">

                <h3>Maribank Details</h3>
                <label>Maribank Account Number</label>
                <input type="text" name="maribank_number" value="<?= htmlspecialchars($details['maribank_number'] ?? '') ?>" placeholder="Account Number">
                <label>Maribank Account Name</label>
                <input type="text" name="maribank_name" value="<?= htmlspecialchars($details['maribank_name'] ?? '') ?>" placeholder="Juan Dela Cruz">

                <button type="submit">Save Details</button>
            </form>
        </div>
    </div>

    <div class="site-footer">&copy; <?= date('Y') ?> Paddle Ground Reservation &middot; San Francisco, Agusan del Sur</div>
</div>

</body>
</html>