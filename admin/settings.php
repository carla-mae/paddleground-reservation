<?php
require_once '../config/session_check.php';
require_role(['admin']);
include '../config/db.php';

$activePage = 'settings';

// A small generic key/value table for site-wide settings (payment account
// numbers, etc.) that don't warrant their own dedicated table. Created here
// so this page works the first time it's opened, without a manual migration.
$conn->query(
    "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL
    )"
);

// Soft-delete support for courts: instead of hard-deleting a court (which
// breaks once it has booking/payment history), we just hide it by setting
// is_active = 0. This adds the column the first time this page runs, so no
// manual migration is needed.
$colCheck = $conn->query("SHOW COLUMNS FROM courts LIKE 'is_active'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE courts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
}

function get_setting($conn, string $key, string $default = ''): string {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['setting_value'] ?? $default;
}

function set_setting($conn, string $key, string $value): void {
    $stmt = $conn->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $stmt->close();
}

$successMsg = '';
$errorMsg   = '';

// Which court (if any) is currently open for editing, so we can re-open the
// edit form and show the error instead of silently losing it on failure.
$editingCourtId = 0;

// ---------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Add a new court ---
    if ($action === 'add_court') {
        $courtName   = trim($_POST['court_name'] ?? '');
        $dayRate     = trim($_POST['day_rate'] ?? '');
        $nightRate   = trim($_POST['night_rate'] ?? '');
        $openTime    = trim($_POST['open_time'] ?? '');
        $closeTime   = trim($_POST['close_time'] ?? '');
        // Description is no longer collected from the admin form; the
        // courts table still has the column, so we just store an empty
        // string for new courts.
        $description = '';

        if ($courtName === '') {
            $errorMsg = 'Court name cannot be empty.';
        } elseif ($dayRate === '' || !is_numeric($dayRate) || (float) $dayRate < 0) {
            $errorMsg = 'Please enter a valid day rate.';
        } elseif ($nightRate === '' || !is_numeric($nightRate) || (float) $nightRate < 0) {
            $errorMsg = 'Please enter a valid night rate.';
        } elseif ($openTime === '' || $closeTime === '') {
            $errorMsg = 'Please provide both an open time and a close time.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO courts (court_name, day_rate, night_rate, description, open_time, close_time)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if ($stmt === false) {
                $errorMsg = 'Could not add court: ' . $conn->error;
            } else {
                $stmt->bind_param(
                    "sddsss",
                    $courtName,
                    $dayRate,
                    $nightRate,
                    $description,
                    $openTime,
                    $closeTime
                );
                try {
                    $stmt->execute();
                    $successMsg = 'Court "' . $courtName . '" added.';
                } catch (mysqli_sql_exception $e) {
                    $errorMsg = 'Could not add court: ' . $e->getMessage();
                }
                $stmt->close();
            }
        }
    }

    // --- Edit an existing court ---
    if ($action === 'edit_court') {
        $courtId     = (int) ($_POST['court_id'] ?? 0);
        $courtName   = trim($_POST['court_name'] ?? '');
        $dayRate     = trim($_POST['day_rate'] ?? '');
        $nightRate   = trim($_POST['night_rate'] ?? '');
        $openTime    = trim($_POST['open_time'] ?? '');
        $closeTime   = trim($_POST['close_time'] ?? '');

        $editingCourtId = $courtId;

        if ($courtName === '') {
            $errorMsg = 'Court name cannot be empty.';
        } elseif ($dayRate === '' || !is_numeric($dayRate) || (float) $dayRate < 0) {
            $errorMsg = 'Please enter a valid day rate.';
        } elseif ($nightRate === '' || !is_numeric($nightRate) || (float) $nightRate < 0) {
            $errorMsg = 'Please enter a valid night rate.';
        } elseif ($openTime === '' || $closeTime === '') {
            $errorMsg = 'Please provide both an open time and a close time.';
        } else {
            // Description is no longer editable from this form, so it's
            // intentionally left out of the UPDATE — existing values stay
            // untouched instead of being overwritten with a blank string.
            $stmt = $conn->prepare(
                "UPDATE courts
                 SET court_name = ?, day_rate = ?, night_rate = ?, open_time = ?, close_time = ?
                 WHERE court_id = ?"
            );
            if ($stmt === false) {
                $errorMsg = 'Could not update court: ' . $conn->error;
            } else {
                $stmt->bind_param(
                    "sddssi",
                    $courtName,
                    $dayRate,
                    $nightRate,
                    $openTime,
                    $closeTime,
                    $courtId
                );
                try {
                    $stmt->execute();
                    $successMsg    = 'Court "' . $courtName . '" updated.';
                    $editingCourtId = 0;
                } catch (mysqli_sql_exception $e) {
                    $errorMsg = 'Could not update court: ' . $e->getMessage();
                }
                $stmt->close();
            }
        }
    }

    // --- Remove a court (soft-delete) ---
    if ($action === 'delete_court') {
        $courtId = (int) ($_POST['court_id'] ?? 0);

        // Only block removal if there's still an *active* booking — i.e. one
        // that hasn't been completed, cancelled, or rejected, AND whose
        // schedule date/time hasn't passed yet. Bookings from past dates are
        // treated as done even if nobody ever flipped their status/completed
        // flag, so they no longer block removing the court.
        $checkStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM bookings b
             INNER JOIN schedules s ON b.schedule_id = s.schedule_id
             WHERE s.court_id = ?
               AND b.completed = 0
               AND b.status NOT IN ('cancelled', 'rejected')
               AND (s.date > CURDATE() OR (s.date = CURDATE() AND s.end_time > CURTIME()))"
        );
        $checkStmt->bind_param("i", $courtId);
        $checkStmt->execute();
        $activeBookingCount = (int) $checkStmt->get_result()->fetch_assoc()['cnt'];
        $checkStmt->close();

        if ($activeBookingCount > 0) {
            $errorMsg = 'Could not remove this court — it still has ' . $activeBookingCount . ' active booking(s) tied to it (not yet completed or cancelled). Remove or reassign those first.';
        } else {
            // Soft-delete: just hide the court instead of deleting the row.
            // This keeps all of its past bookings/payments intact for
            // reports, and avoids foreign key errors entirely.
            $stmt = $conn->prepare("UPDATE courts SET is_active = 0 WHERE court_id = ?");
            $stmt->bind_param("i", $courtId);
            try {
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $successMsg = 'Court removed.';
                } else {
                    $errorMsg = 'Court not found.';
                }
            } catch (mysqli_sql_exception $e) {
                $errorMsg = 'Could not remove court: ' . $e->getMessage();
            }
            $stmt->close();
        }
    }

    // --- Save payment account numbers ---
    if ($action === 'save_payment_settings') {
        $gcash        = trim($_POST['gcash_number'] ?? '');
        $gcashName    = trim($_POST['gcash_name'] ?? '');
        $maribank     = trim($_POST['maribank_number'] ?? '');
        $maribankName = trim($_POST['maribank_name'] ?? '');

        set_setting($conn, 'gcash_number', $gcash);
        set_setting($conn, 'gcash_name', $gcashName);
        set_setting($conn, 'maribank_number', $maribank);
        set_setting($conn, 'maribank_name', $maribankName);

        $successMsg = 'Payment account numbers updated.';
    }
}

// ---------------------------------------------------------------------
// Data for display
// ---------------------------------------------------------------------
$courts = $conn->query(
    "SELECT court_id, court_name, day_rate, night_rate, description, open_time, close_time
     FROM courts
     WHERE is_active = 1
     ORDER BY court_name ASC"
)->fetch_all(MYSQLI_ASSOC);

$gcashNumber    = get_setting($conn, 'gcash_number', '0972 673 6565');
$gcashName      = get_setting($conn, 'gcash_name', 'Carla Verzosa');
$maribankNumber = get_setting($conn, 'maribank_number', '0389 648 378');
$maribankName   = get_setting($conn, 'maribank_name', 'Carla Verzosa');
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
        height: 100vh;
        overflow: hidden;
    }
    .main {
        flex-grow: 1;
        padding: 40px;
        height: 100vh;
        overflow-y: auto;
        min-width: 0;
    }
    .main-inner {
        max-width: 720px;
        width: 100%;
        margin: 0 auto;
    }
    h2 { font-size: 26px; font-weight: 800; margin-bottom: 6px; }
    .subtitle { color: var(--muted); margin-bottom: 24px; }

    .alert {
        border-radius: 10px;
        padding: 13px 16px;
        font-size: 14px;
        margin-bottom: 20px;
    }
    .alert-success {
        background: rgba(22,163,74,0.10);
        border: 1px solid rgba(22,163,74,0.35);
        color: var(--brand-green-dark);
    }
    .alert-error {
        background: rgba(239,68,68,0.10);
        border: 1px solid rgba(239,68,68,0.35);
        color: #b91c1c;
    }

    .panel {
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        padding: 22px;
        margin-bottom: 24px;
        box-shadow: 0 12px 30px -18px rgba(23, 48, 31, 0.16);
    }
    .panel h3 { font-size: 16px; margin-bottom: 4px; }
    .panel .panel-desc { color: var(--muted); font-size: 13px; margin-bottom: 18px; }

    .court-card {
        border: 1px solid var(--border-soft);
        border-radius: 10px;
        margin-bottom: 10px;
        overflow: hidden;
    }
    .court-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 14px;
        font-size: 14px;
        gap: 12px;
    }
    .court-row form { margin: 0; }
    .court-info { min-width: 0; }
    .court-info .name { font-weight: 700; margin-bottom: 3px; }
    .court-info .meta {
        color: var(--muted);
        font-size: 12.5px;
        line-height: 1.5;
    }
    .court-actions {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }
    .edit-btn {
        background: rgba(37,99,235,0.10);
        color: #2563eb;
        border: 1px solid rgba(37,99,235,0.35);
        border-radius: 7px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }
    .edit-btn:hover { background: rgba(37,99,235,0.18); }
    .remove-btn {
        background: rgba(239,68,68,0.10);
        color: #b91c1c;
        border: 1px solid rgba(239,68,68,0.35);
        border-radius: 7px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }
    .remove-btn:hover { background: rgba(239,68,68,0.18); }
    .empty-state { color: var(--muted); font-size: 14px; padding: 8px 0 16px; }

    .edit-form {
        display: none;
        padding: 16px 14px 18px;
        border-top: 1px solid var(--border-soft);
        background: var(--page-bg);
    }
    .edit-form.open { display: block; }

    .form-row {
        display: flex;
        gap: 10px;
        margin-bottom: 12px;
    }
    .form-row .field-group { flex: 1; margin-bottom: 0; }

    .add-court-form {
        margin-top: 14px;
        padding-top: 16px;
        border-top: 1px solid var(--border-soft);
    }
    input[type="text"],
    input[type="number"],
    input[type="time"],
    textarea {
        width: 100%;
        background: var(--field-bg);
        border: 1px solid var(--border-soft);
        color: var(--brand-ink);
        border-radius: 8px;
        padding: 11px 14px;
        font-size: 14px;
        font-family: inherit;
    }
    textarea { resize: vertical; min-height: 60px; }
    input:focus, textarea:focus { outline: none; border-color: var(--brand-green); background: #ffffff; }

    .field-group { margin-bottom: 14px; }
    .field-group label {
        display: block;
        font-size: 12px;
        color: var(--muted);
        text-transform: uppercase;
        margin-bottom: 6px;
    }

    .btn {
        border: none;
        border-radius: 8px;
        padding: 11px 20px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
    }
    .btn-primary { background: var(--brand-green); color: #ffffff; }
    .btn-primary:hover { filter: brightness(1.08); }
    .btn-secondary {
        background: transparent;
        color: var(--muted);
        border: 1px solid var(--border-soft);
    }
    .btn-secondary:hover { color: var(--brand-ink); }
    .form-actions { display: flex; gap: 10px; }

    @media (max-width: 900px) {
        body { flex-direction: column; height: auto; overflow: visible; }
        .main { padding: 28px 20px; height: auto; overflow-y: visible; }
    }
    @media (max-width: 600px) {
        .main { padding: 76px 16px 20px; }
        .court-row { flex-wrap: wrap; }
        .form-row { flex-direction: column; gap: 12px; }
    }
</style>
</head>
<body>

<?php include '../includes/admin_sidebar.php'; ?>

<div class="main">
    <div class="main-inner">
    <h2>Settings</h2>
    <p class="subtitle">Manage courts and payment details for the reservation system.</p>

    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="panel">
        <h3>Courts</h3>
        <p class="panel-desc">Add new courts, edit their rates/schedule, or remove ones that are no longer in use.</p>

        <?php if (empty($courts)): ?>
            <p class="empty-state">No courts added yet.</p>
        <?php else: ?>
            <?php foreach ($courts as $court):
                $cid       = (int) $court['court_id'];
                $isEditing = ($editingCourtId === $cid);
                $openTimeFmt  = substr($court['open_time'], 0, 5);
                $closeTimeFmt = substr($court['close_time'], 0, 5);
            ?>
                <div class="court-card">
                    <div class="court-row">
                        <div class="court-info">
                            <div class="name"><?= htmlspecialchars($court['court_name']) ?></div>
                            <div class="meta">
                                ₱<?= number_format((float) $court['day_rate'], 2) ?>/hr day ·
                                ₱<?= number_format((float) $court['night_rate'], 2) ?>/hr night ·
                                <?= htmlspecialchars($openTimeFmt) ?>–<?= htmlspecialchars($closeTimeFmt) ?>
                            </div>
                        </div>
                        <div class="court-actions">
                            <button type="button" class="edit-btn" onclick="toggleEdit(<?= $cid ?>)">Edit</button>
                            <form method="POST" action="settings.php" onsubmit="return confirm('Remove &quot;<?= htmlspecialchars($court['court_name'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                                <input type="hidden" name="action" value="delete_court">
                                <input type="hidden" name="court_id" value="<?= $cid ?>">
                                <button type="submit" class="remove-btn">Remove</button>
                            </form>
                        </div>
                    </div>

                    <div class="edit-form<?= $isEditing ? ' open' : '' ?>" id="edit-form-<?= $cid ?>">
                        <form method="POST" action="settings.php">
                            <input type="hidden" name="action" value="edit_court">
                            <input type="hidden" name="court_id" value="<?= $cid ?>">

                            <div class="field-group">
                                <label>Court Name</label>
                                <input type="text" name="court_name" value="<?= htmlspecialchars($court['court_name']) ?>" required>
                            </div>

                            <div class="form-row">
                                <div class="field-group">
                                    <label>Day Rate (₱/hr)</label>
                                    <input type="number" step="0.01" min="0" name="day_rate" value="<?= htmlspecialchars($court['day_rate']) ?>" required>
                                </div>
                                <div class="field-group">
                                    <label>Night Rate (₱/hr)</label>
                                    <input type="number" step="0.01" min="0" name="night_rate" value="<?= htmlspecialchars($court['night_rate']) ?>" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="field-group">
                                    <label>Open Time</label>
                                    <input type="time" name="open_time" value="<?= htmlspecialchars($openTimeFmt) ?>" required>
                                </div>
                                <div class="field-group">
                                    <label>Close Time</label>
                                    <input type="time" name="close_time" value="<?= htmlspecialchars($closeTimeFmt) ?>" required>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                                <button type="button" class="btn btn-secondary" onclick="toggleEdit(<?= $cid ?>)">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" action="settings.php" class="add-court-form">
            <input type="hidden" name="action" value="add_court">

            <div class="field-group">
                <label>Court Name</label>
                <input type="text" name="court_name" placeholder="e.g. Court 5" required>
            </div>

            <div class="form-row">
                <div class="field-group">
                    <label>Day Rate (₱/hr)</label>
                    <input type="number" step="0.01" min="0" name="day_rate" placeholder="200.00" required>
                </div>
                <div class="field-group">
                    <label>Night Rate (₱/hr)</label>
                    <input type="number" step="0.01" min="0" name="night_rate" placeholder="250.00" required>
                </div>
            </div>

            <div class="form-row">
                <div class="field-group">
                    <label>Open Time</label>
                    <input type="time" name="open_time" value="05:00" required>
                </div>
                <div class="field-group">
                    <label>Close Time</label>
                    <input type="time" name="close_time" value="23:59" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Add Court</button>
        </form>
    </div>

    <div class="panel">
        <h3>Payment Accounts</h3>
        <p class="panel-desc">These numbers are shown to customers on the payment page.</p>

        <form method="POST" action="settings.php">
            <input type="hidden" name="action" value="save_payment_settings">

            <div class="field-group">
                <label for="gcash_number">GCash Number</label>
                <input type="text" id="gcash_number" name="gcash_number" value="<?= htmlspecialchars($gcashNumber) ?>">
            </div>

            <div class="field-group">
                <label for="gcash_name">GCash Name</label>
                <input type="text" id="gcash_name" name="gcash_name" value="<?= htmlspecialchars($gcashName) ?>">
            </div>

            <div class="field-group">
                <label for="maribank_number">MariBank Account Number</label>
                <input type="text" id="maribank_number" name="maribank_number" value="<?= htmlspecialchars($maribankNumber) ?>">
            </div>

            <div class="field-group">
                <label for="maribank_name">Account Name</label>
                <input type="text" id="maribank_name" name="maribank_name" value="<?= htmlspecialchars($maribankName) ?>">
            </div>

            <button type="submit" class="btn btn-primary">Save Payment Details</button>
        </form>
    </div>

    </div>
</div>

<script>
function toggleEdit(courtId) {
    const el = document.getElementById('edit-form-' + courtId);
    if (el) {
        el.classList.toggle('open');
    }
}
</script>

</body>
</html>