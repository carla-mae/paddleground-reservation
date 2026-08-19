<?php
session_start();
include '../config/db.php';

// Safely load the mailer helper. If it's missing or broken, fall back to a
// no-op function so resending a code still works (dev-mode code display kicks in below).
$mailerPath = __DIR__ . '/../PHPMailer/mailer_helper.php';
if (file_exists($mailerPath)) {
    require_once $mailerPath;
}
if (!function_exists('send_smtp_mail')) {
    function send_smtp_mail(string $toEmail, string $toName, string $subject, string $bodyText): bool {
        return false; // mailer not available — caller will fall back to dev-mode code display
    }
}

if (empty($_SESSION['pending_registration'])) {
    header("Location: register.php");
    exit();
}

$pending = $_SESSION['pending_registration'];
$error = '';
$resendMessage = '';

// --- Handle "start again" ---
if (isset($_GET['action']) && $_GET['action'] === 'restart') {
    unset($_SESSION['pending_registration'], $_SESSION['dev_show_code']);
    header("Location: register.php");
    exit();
}

// --- Handle resend code ---
if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pending['code'] = $code;
    $pending['expires'] = time() + 600;
    $_SESSION['pending_registration'] = $pending;

    $subject = 'Your new PaddleGround verification code';
    $body = "Hi {$pending['full_name']},\n\nYour new verification code is: {$code}\n\nThis code expires in 10 minutes.";
    $sent = send_smtp_mail($pending['email'], $pending['full_name'], $subject, $body);

    if (!$sent) {
        $_SESSION['dev_show_code'] = $code;
    } else {
        unset($_SESSION['dev_show_code']);
    }
    $resendMessage = 'A new code has been sent to ' . $pending['email'] . '.';
}

// --- Handle verify submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $submitted = trim($_POST['code'] ?? '');

    if (time() > $pending['expires']) {
        $error = 'This code has expired. Please request a new one.';
    } elseif (!preg_match('/^\d{6}$/', $submitted)) {
        $error = 'Please enter the full 6-digit code.';
    } elseif ($submitted !== $pending['code']) {
        $error = 'That code is incorrect. Please try again.';
    } else {
        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, 'customer')");
        $stmt->bind_param("sss", $pending['full_name'], $pending['email'], $pending['password_hash']);

        if ($stmt->execute()) {
            unset($_SESSION['pending_registration'], $_SESSION['dev_show_code']);
            header("Location: login.php?verified=1");
            exit();
        } else {
            $error = 'Something went wrong creating your account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email — PaddleGround</title>
<style>
    /* ---------- Brand palette (unified green, sage background) ----------
       Matches login.php / register.php / forgot_password.php / reset_password.php */
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
    html, body { height: 100%; }
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        background: var(--page-bg);
        color: var(--brand-ink);
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 20px;
    }

    .auth-card {
        width: 420px;
        max-width: 100%;
        background: var(--card-bg);
        border: 1px solid var(--border-soft);
        border-radius: 16px;
        padding: 40px 36px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 20px 50px -20px rgba(23, 48, 31, 0.18), 0 2px 8px rgba(23, 48, 31, 0.05);
    }
    .auth-card::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 180px;
        height: 180px;
        background: radial-gradient(circle, rgba(22,163,74,0.14), transparent 70%);
        pointer-events: none;
    }

    .back-link {
        display: inline-block;
        color: var(--muted);
        text-decoration: none;
        font-size: 13px;
        margin-bottom: 20px;
        position: relative;
        z-index: 1;
    }
    .back-link:hover { color: var(--brand-ink); }

    .mail-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        background: rgba(22,163,74,0.12);
        border: 1px solid rgba(22,163,74,0.35);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        margin-bottom: 20px;
        position: relative;
        z-index: 1;
        color: var(--brand-green-dark);
    }

    h2 {
        font-size: 24px;
        font-weight: 800;
        margin-bottom: 8px;
        position: relative;
        z-index: 1;
        color: var(--brand-ink);
    }
    .subtitle {
        color: var(--muted);
        font-size: 14px;
        line-height: 1.5;
        margin-bottom: 26px;
        position: relative;
        z-index: 1;
    }
    .subtitle b { color: var(--brand-ink); }

    .error-box, .info-box {
        font-size: 13px;
        padding: 12px 14px;
        border-radius: 10px;
        margin-bottom: 18px;
        position: relative;
        z-index: 1;
    }
    .error-box {
        background: rgba(239, 68, 68, 0.10);
        border: 1px solid rgba(239, 68, 68, 0.35);
        color: #b91c1c;
    }
    .info-box {
        background: rgba(34,197,94,0.10);
        border: 1px solid rgba(34,197,94,0.3);
        color: #15803d;
    }

    .dev-box {
        background: rgba(234, 179, 8, 0.12);
        border: 1px solid rgba(234, 179, 8, 0.4);
        color: #92400e;
        font-size: 12px;
        padding: 10px 14px;
        border-radius: 10px;
        margin-bottom: 18px;
        position: relative;
        z-index: 1;
    }
    .dev-box b { letter-spacing: 2px; font-size: 14px; }

    form { position: relative; z-index: 1; }

    .code-label {
        font-size: 12px;
        color: var(--muted);
        margin-bottom: 10px;
        display: block;
    }
    .code-inputs {
        display: flex;
        gap: 8px;
        margin-bottom: 22px;
    }
    .code-inputs input {
        width: 100%;
        aspect-ratio: 1;
        text-align: center;
        font-size: 20px;
        font-weight: 700;
        background: var(--field-bg);
        border: 1px solid var(--border-soft);
        color: var(--brand-ink);
        border-radius: 8px;
    }
    .code-inputs input:focus {
        outline: none;
        border-color: var(--brand-green);
        background: #ffffff;
    }

    .submit-btn {
        width: 100%;
        background: var(--brand-green);
        color: #ffffff;
        font-weight: 700;
        font-size: 14px;
        border: none;
        padding: 12px;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color .15s ease;
    }
    .submit-btn:hover { background: var(--brand-green-dark); }

    .switch-link {
        text-align: center;
        margin-top: 20px;
        font-size: 13px;
        color: var(--muted);
        position: relative;
        z-index: 1;
    }
    .switch-link a {
        color: var(--brand-green);
        text-decoration: none;
        font-weight: 600;
    }
    .switch-link a:hover { text-decoration: underline; }

    /* Responsive: phones */
    @media (max-width: 480px) {
        .auth-card {
            padding: 28px 22px;
        }
        h2 { font-size: 20px; }
        .code-inputs input { font-size: 18px; }
    }
</style>
</head>
<body>

<div class="auth-card">
    <a class="back-link" href="verify_email.php?action=restart">&larr; Back</a>

    <div class="mail-icon">&#9993;</div>
    <h2>Verify your email</h2>
    <p class="subtitle">
        We sent a 6-digit code to <b><?= htmlspecialchars($pending['email']) ?></b>.
        Enter it below to continue.
    </p>

    <?php if (!empty($_SESSION['dev_show_code'])): ?>
        <div class="dev-box">
            Dev mode (no mail server configured): your code is <b><?= htmlspecialchars($_SESSION['dev_show_code']) ?></b>
        </div>
    <?php endif; ?>

    <?php if ($resendMessage): ?>
        <div class="info-box"><?= htmlspecialchars($resendMessage) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="verifyForm">
        <label class="code-label">Verification code</label>
        <div class="code-inputs">
            <?php for ($i = 0; $i < 6; $i++): ?>
                <input type="text" inputmode="numeric" maxlength="1" class="code-box" autocomplete="off">
            <?php endfor; ?>
        </div>
        <input type="hidden" name="code" id="codeField">
        <button type="submit" class="submit-btn">Verify account &rarr;</button>
    </form>

    <div class="switch-link">
        Didn't get the code?
        <a href="verify_email.php?action=resend">Resend code</a>
        or
        <a href="verify_email.php?action=restart">start again</a>.
    </div>
</div>

<script>
    const boxes = document.querySelectorAll('.code-box');
    const codeField = document.getElementById('codeField');
    const form = document.getElementById('verifyForm');

    boxes[0].focus();

    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/[^0-9]/g, '');
            if (box.value && i < boxes.length - 1) {
                boxes[i + 1].focus();
            }
            updateCodeField();
        });
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !box.value && i > 0) {
                boxes[i - 1].focus();
            }
        });
        box.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
            pasted.split('').slice(0, boxes.length).forEach((char, idx) => {
                boxes[idx].value = char;
            });
            const nextEmpty = Array.from(boxes).findIndex(b => !b.value);
            boxes[nextEmpty === -1 ? boxes.length - 1 : nextEmpty].focus();
            updateCodeField();
        });
    });

    function updateCodeField() {
        codeField.value = Array.from(boxes).map(b => b.value).join('');
    }

    form.addEventListener('submit', (e) => {
        updateCodeField();
        if (codeField.value.length !== 6) {
            e.preventDefault();
            boxes[0].focus();
        }
    });
</script>

</body>
</html>