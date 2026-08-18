<?php
session_start();
include '../config/db.php';

// Safely load the mailer helper. If it's missing or broken, fall back to a
// no-op function so registration still works (dev-mode code display kicks in below).
$mailerPath = __DIR__ . '/../PHPMailer/mailer_helper.php';
if (file_exists($mailerPath)) {
    require_once $mailerPath;
}
if (!function_exists('send_smtp_mail')) {
    function send_smtp_mail(string $toEmail, string $toName, string $subject, string $bodyText): bool {
        return false; // mailer not available — caller will fall back to dev-mode code display
    }
}

$errors = [];
$full_name = $_POST['full_name'] ?? '';
$email = $_POST['email'] ?? '';

// Password rule: 8+ chars, at least one uppercase letter, one number, one special character.
// Shared with reset_password.php — keep both in sync if this ever changes.
$passwordPattern = '/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/';
$passwordHint = 'At least 8 characters, with an uppercase letter, a number, and a special character.';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!preg_match($passwordPattern, $password)) {
        $errors[] = 'Password must be at least 8 characters and include an uppercase letter, a number, and a special character.';
    }

    if (empty($errors)) {
        $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (empty($errors)) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION['pending_registration'] = [
            'full_name'     => $full_name,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'code'          => $code,
            'expires'       => time() + 600, // 10 minutes
        ];

        // Attempt to send the verification email via Gmail SMTP.
        $subject = 'Verify your PaddleGround account';
        $body = "Hi {$full_name},\n\nYour PaddleGround verification code is: {$code}\n\nThis code expires in 10 minutes.";
        $sent = send_smtp_mail($email, $full_name, $subject, $body);

        // Local/dev fallback: if the server has no mail transport configured (common on
        // localhost/XAMPP without SMTP setup), still let the person continue testing by
        // showing the code on the verification screen itself.
        if (!$sent) {
            $_SESSION['dev_show_code'] = $code;
        }

        header("Location: verify_email.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — PaddleGround</title>
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

    .brand {
        font-weight: 800;
        font-size: 18px;
        margin-bottom: 26px;
        position: relative;
        z-index: 1;
        color: var(--brand-ink);
    }
    .brand span { color: var(--brand-green); }

    h2 {
        font-size: 24px;
        font-weight: 800;
        margin-bottom: 6px;
        position: relative;
        z-index: 1;
    }
    .subtitle {
        color: var(--muted);
        font-size: 14px;
        margin-bottom: 26px;
        position: relative;
        z-index: 1;
    }

    .error-box {
        background: rgba(239, 68, 68, 0.10);
        border: 1px solid rgba(239, 68, 68, 0.35);
        color: #b91c1c;
        font-size: 13px;
        padding: 12px 14px;
        border-radius: 10px;
        margin-bottom: 18px;
        position: relative;
        z-index: 1;
    }
    .error-box ul { list-style: none; }
    .error-box li { margin-bottom: 4px; }
    .error-box li:last-child { margin-bottom: 0; }

    form { position: relative; z-index: 1; }

    .field { margin-bottom: 16px; }
    .field label {
        display: block;
        font-size: 12px;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .field input {
        width: 100%;
        background: var(--field-bg);
        border: 1px solid var(--border-soft);
        color: var(--brand-ink);
        border-radius: 8px;
        padding: 11px 13px;
        font-size: 14px;
    }
    .field input:focus {
        outline: none;
        border-color: var(--brand-green);
        background: #ffffff;
    }
    .field input::placeholder { color: #9aa79f; }
    .field .hint {
        font-size: 11.5px;
        color: var(--muted);
        margin-top: 6px;
        line-height: 1.4;
    }

    /* Password field with eye toggle */
    .password-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    .password-wrapper input {
        padding-right: 42px;
    }
    .toggle-password {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #9ca3af;
        display: flex;
        align-items: center;
        line-height: 0;
    }
    .toggle-password:hover { color: #4b5563; }

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
        margin-top: 6px;
    }
    .submit-btn:hover { filter: brightness(1.08); }

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

    /* Browsers force their own blue/yellow background on autofilled
       fields — force it back to match the normal input look. */
    input:-webkit-autofill,
    input:-webkit-autofill:hover,
    input:-webkit-autofill:focus,
    input:-webkit-autofill:active {
        -webkit-box-shadow: 0 0 0px 1000px var(--field-bg) inset !important;
        box-shadow: 0 0 0px 1000px var(--field-bg) inset !important;
        -webkit-text-fill-color: var(--brand-ink) !important;
        caret-color: var(--brand-ink);
        transition: background-color 9999s ease-in-out 0s;
    }

    /* Responsive: phones */
    @media (max-width: 480px) {
        .auth-card {
            padding: 28px 22px;
        }
        h2 { font-size: 20px; }
        .field input {
            font-size: 16px; /* prevents iOS Safari auto-zoom on focus */
        }
    }
</style>
</head>
<body>

<div class="auth-card">
    <div class="brand">Paddle<span>Ground</span></div>
    <h2>Create Customer Account</h2>
    <p class="subtitle">Sign up to start booking courts.</p>

    <?php if (!empty($errors)): ?>
        <div class="error-box">
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li>&bull; <?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="field">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" placeholder="Juan Dela Cruz"
                   value="<?= htmlspecialchars($full_name) ?>" required>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="you@example.com"
                   value="<?= htmlspecialchars($email) ?>" required>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="password-wrapper">
                <input type="password" id="password" name="password"
                       pattern="^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$"
                       title="At least 8 characters, with an uppercase letter, a number, and a special character."
                       required>
                <span class="toggle-password" id="togglePassword">
                    <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                        <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </span>
            </div>
            <p class="hint">Must be at least 8 characters, with an uppercase letter, a number, and a special character.</p>
        </div>
        <button type="submit" class="submit-btn">Create Account</button>
    </form>

    <div class="switch-link">
        Already have an account? <a href="login.php">Login</a>
    </div>
</div>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const password = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    const eyeOpen = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    const eyeOff = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';

    togglePassword.addEventListener('click', function () {
        const isHidden = password.getAttribute('type') === 'password';
        password.setAttribute('type', isHidden ? 'text' : 'password');
        eyeIcon.innerHTML = isHidden ? eyeOpen : eyeOff;
    });
</script>

</body>
</html>