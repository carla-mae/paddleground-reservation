<?php
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
// Based on the folder structure shown (PHPMailer/ sits at project root next
// to auth/, staff/, config/, etc.), so from auth/ we go up one level.
include '../config/db.php';
require_once '../PHPMailer/mailer_helper.php';

$message = "";
$messageType = ""; // "success" or "error"

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $message = "Please enter your email address.";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare("SELECT user_id, full_name, email FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        // Always show the same generic message whether or not the email
        // exists — this prevents someone from using this form to figure out
        // which emails are registered in the system (user enumeration).
        $message = "A password reset link has been sent.";
        $messageType = "success";

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600); // valid for 1 hour

            $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE user_id = ?");
            $update->bind_param("ssi", $token, $expiry, $user['user_id']);
            $update->execute();

            // Build the reset link. Adjust the domain/path here if your
            // local setup differs (e.g. if it's not under /paddle-reservation/).
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $basePath = dirname(dirname($_SERVER['SCRIPT_NAME'])); // one level up from /auth
            $resetLink = "{$protocol}{$host}{$basePath}/auth/reset_password.php?token={$token}";

            $subject = "PaddleGround - Password Reset Request";
            $body = "Hi {$user['full_name']},\n\n"
                  . "We received a request to reset your PaddleGround password.\n\n"
                  . "Click the link below to set a new password. This link expires in 1 hour:\n"
                  . "{$resetLink}\n\n"
                  . "If you didn't request this, you can safely ignore this email — "
                  . "your password will remain unchanged.\n\n"
                  . "- PaddleGround Team";

            send_smtp_mail($user['email'], $user['full_name'], $subject, $body);
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Paddle Ground</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ---------- Brand palette (unified green, sage background) ---------- */
        :root {
            --brand-green: #16A34A;
            --brand-green-dark: #128A3E;
            --brand-ink: #17301F;
            --page-bg: #EAF1EC;
        }

        body {
            background: var(--page-bg);
            min-height: 100vh;
        }

        /* Logo: unified ink + brand green */
        .brand h1 .white {
            color: var(--brand-ink);
        }
        .brand h1 .gray {
            color: var(--brand-green);
        }
        .brand svg {
            stroke: var(--brand-green) !important;
        }

        /* Card lifts more clearly off the tinted background */
        .auth-card {
            box-shadow: 0 20px 50px -20px rgba(23, 48, 31, 0.18), 0 2px 8px rgba(23, 48, 31, 0.05);
        }

        /* Primary action button uses the single brand green */
        .btn-submit {
            background-color: var(--brand-green) !important;
            border-color: var(--brand-green) !important;
            transition: background-color .15s ease;
        }
        .btn-submit:hover {
            background-color: var(--brand-green-dark) !important;
        }

        .auth-footer a {
            color: var(--brand-green) !important;
        }

        .msg-success {
            background: rgba(34, 197, 94, 0.1);
            color: #15803d;
            border: 1px solid rgba(34, 197, 94, 0.3);
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
        .msg-error {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }

        /* Browsers force their own blue/yellow background on autofilled
           fields — force it back to match the normal input look. */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0px 1000px #F4F7F5 inset !important;
            box-shadow: 0 0 0px 1000px #F4F7F5 inset !important;
            -webkit-text-fill-color: var(--brand-ink) !important;
            caret-color: var(--brand-ink);
            transition: background-color 9999s ease-in-out 0s;
        }

        /* Responsive: tablets and small laptops */
        @media (max-width: 768px) {
            .auth-card {
                max-width: 90%;
                margin: 0 auto;
            }
        }

        /* Responsive: phones */
        @media (max-width: 480px) {
            .brand h1 {
                font-size: 1.4rem;
            }
            .brand svg {
                width: 28px;
                height: 28px;
            }
            .auth-card {
                max-width: 100%;
                width: 100%;
                padding: 24px 20px;
                border-radius: 12px;
                box-sizing: border-box;
            }
            .auth-card h2 {
                font-size: 1.4rem;
            }
            .auth-card .subtitle {
                font-size: 0.85rem;
            }
            .form-group input {
                font-size: 16px;
                padding: 10px 12px;
            }
            .btn-submit {
                padding: 12px;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>

    <div class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2">
            <path d="M2 12h4l2-7 4 14 3-10 2 3h5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <h1><span class="white">PADDLE</span><span class="gray">GROUND</span></h1>
    </div>

    <div class="auth-card">
        <h2>Forgot password?</h2>
        <p class="subtitle">Enter your email and we'll send you a reset link</p>

        <?php if ($message): ?>
            <div class="<?= $messageType === 'success' ? 'msg-success' : 'msg-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($messageType !== 'success'): ?>
        <form method="POST">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>
            <button type="submit" class="btn-submit">Send Reset Link</button>
        </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="login.php">Back to Sign In</a>
        </div>
    </div>

</body>
</html>