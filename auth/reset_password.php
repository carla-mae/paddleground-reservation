<?php
include '../config/db.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = "";
$validToken = false;
$user = null;

// Password rule: 8+ chars, at least one uppercase letter, one number, one special character.
// Shared with register.php — keep both in sync if this ever changes.
$passwordPattern = '/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/';
$passwordHint = 'At least 8 characters, with an uppercase letter, a number, and a special character.';

if ($token === '') {
    $error = "Invalid or missing reset link.";
} else {
    $stmt = $conn->prepare("SELECT user_id, full_name, reset_token_expiry FROM users WHERE reset_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (strtotime($user['reset_token_expiry']) >= time()) {
            $validToken = true;
        } else {
            $error = "This reset link has expired. Please request a new one.";
        }
    } else {
        $error = "This reset link is invalid or has already been used.";
    }
}

if ($validToken && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!preg_match($passwordPattern, $newPassword)) {
        $error = "Password must be at least 8 characters and include an uppercase letter, a number, and a special character.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE user_id = ?");
        $update->bind_param("si", $hashed, $user['user_id']);
        $update->execute();

        header("Location: login.php?reset_success=1");
        exit();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Paddle Ground</title>
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

        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-wrapper input {
            width: 100%;
            box-sizing: border-box;
            padding-right: 44px;
        }
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            display: flex;
            align-items: center;
            line-height: 0;
        }
        .toggle-password:hover {
            color: #4b5563;
        }
        .field-hint {
            font-size: 11.5px;
            color: #6b7a70;
            margin-top: 6px;
            line-height: 1.4;
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
            .form-group input,
            .password-wrapper input {
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
        <h2>Reset password</h2>
        <p class="subtitle">Choose a new password for your account</p>

        <?php if ($error): ?>
            <div class="msg-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($validToken): ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="form-group">
                <label>New Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password"
                           pattern="^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$"
                           title="At least 8 characters, with an uppercase letter, a number, and a special character."
                           required>
                    <span class="toggle-password" data-target="password">
                        <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </span>
                </div>
                <p class="field-hint">Must be at least 8 characters, with an uppercase letter, a number, and a special character.</p>
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" id="confirm_password" required>
                    <span class="toggle-password" data-target="confirm_password">
                        <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </span>
                </div>
            </div>

            <button type="submit" class="btn-submit">Reset Password</button>
        </form>
        <?php else: ?>
            <div class="auth-footer">
                <a href="forgot_password.php">Request a new reset link</a>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="login.php">Back to Sign In</a>
        </div>
    </div>

    <script>
        const eyeOpen = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        const eyeOff = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';

        document.querySelectorAll('.toggle-password').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const targetId = toggle.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const icon = toggle.querySelector('.eye-icon');
                const isHidden = input.getAttribute('type') === 'password';
                input.setAttribute('type', isHidden ? 'text' : 'password');
                icon.innerHTML = isHidden ? eyeOpen : eyeOff;
            });
        });
    </script>

</body>
</html>