<?php
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
// Keep the cookie/session params IDENTICAL to session_check.php. If these
// differ between the login page and every other page, the session cookie
// the browser stores at login may not match what later pages expect —
// which is what was silently kicking you back to the login screen.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    session_set_cookie_params([
        'lifetime' => 86400,   // 1 day, instead of 0 (0 = "until browser closes",
                                // which some mobile browsers treat as expired
                                // the moment the app goes to background/gets killed)
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

include '../config/db.php';

$error = "";
$email = "";
$loginSuccess = false;
$redirectUrl = "";
$needsReset = false;

// Same rule enforced on register/reset password: 8+ chars, an uppercase
// letter, a number, and a special character. Accounts created before this
// rule existed may still have a weaker password stored — those are no
// longer allowed to log in until they reset to a compliant password.
$passwordPattern = '/^(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}$/';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {

            if (!preg_match($passwordPattern, $password)) {
                // Correct password, but it predates the complexity rule —
                // don't establish a session, send them to reset instead.
                $needsReset = true;
                $error = "For your security, please reset your password to include an uppercase letter, a number, and a special character.";
            } else {

            // Prevent session fixation: issue a fresh session ID on login,
            // keeping the existing session data.
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['last_activity'] = time(); // used by session_check.php for inactivity timeout

            if ($user['role'] == 'customer') $redirectUrl = '../customer/dashboard.php';
            elseif ($user['role'] == 'staff') $redirectUrl = '../staff/dashboard.php';
            else $redirectUrl = '../admin/dashboard.php';

            // Don't redirect with header() here — render the page below so
            // the "Successfully signed in" message shows first, then JS
            // sends the browser on after a short delay.
            $loginSuccess = true;
            }
        } else {
            $error = "Wrong password";
        }
    } else {
        $error = "No account found";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Paddle Ground</title>
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

        /* Logo: was two-tone white/gray, now unified ink + brand green */
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
            position: relative;
            padding-top: 42px;
            box-shadow: 0 20px 50px -20px rgba(23, 48, 31, 0.18), 0 2px 8px rgba(23, 48, 31, 0.05);
        }

        .brand {
            margin-bottom: 38px;
        }

        /* Welcome icon badge — same idea as the red avatar reference,
           recolored to the brand green gradient */
        .welcome-badge {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            position: absolute;
            top: 0;
            left: 50%;
            transform: translate(-50%, -50%);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #22C55E 0%, #128A3E 100%);
            box-shadow: 0 10px 22px -6px rgba(18, 138, 62, 0.55);
            border: 4px solid #ffffff;
        }
        .welcome-badge svg {
            width: 32px;
            height: 32px;
        }
        .auth-card h2 {
            text-align: center;
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
        .forgot-link {
            text-align: right;
            margin: 6px 0 18px;
        }
        .forgot-link a {
            color: var(--brand-green) !important;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .forgot-link a:hover {
            text-decoration: underline;
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

        /* ---------- Success / redirecting overlay ---------- */
        .redirect-overlay {
            position: fixed;
            inset: 0;
            background: var(--page-bg);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            text-align: center;
            gap: 18px;
            animation: overlayIn 0.25s ease;
        }
        @keyframes overlayIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .redirect-check {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(34,197,94,0.1);
            border: 1px solid rgba(34,197,94,0.35);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .redirect-check svg {
            width: 30px;
            height: 30px;
            stroke: var(--brand-green-dark);
        }
        .redirect-overlay h2 {
            color: #1f2937;
            font-size: 1.3rem;
        }
        .redirect-overlay p {
            color: #6b7280;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .redirect-spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(34,197,94,0.25);
            border-top-color: var(--brand-green-dark);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
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
            .form-group input,
            .password-wrapper input {
                font-size: 16px; /* 16px+ prevents iOS Safari from auto-zooming on focus */
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

    <?php if ($loginSuccess): ?>
    <div class="redirect-overlay">
        <div class="redirect-check">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>
        <h2>Successfully signed in!</h2>
        <p><span class="redirect-spinner"></span> Redirecting...</p>
    </div>
    <script>
        setTimeout(function () {
            window.location.href = <?= json_encode($redirectUrl) ?>;
        }, 1200);
    </script>
    <?php endif; ?>

    <?php if (!$loginSuccess): ?>
    <div class="brand">
        <svg viewBox="0 0 24 24" fill="none" stroke="#16A34A" stroke-width="2">
            <path d="M2 12h4l2-7 4 14 3-10 2 3h5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <h1><span class="white">PADDLE</span><span class="gray">GROUND</span></h1>
    </div>

    <div class="auth-card">
        <div class="welcome-badge">
            <svg viewBox="0 0 24 24" fill="#ffffff">
                <circle cx="12" cy="8" r="4"/>
                <path d="M4 20c0-4.4 3.6-8 8-8s8 3.6 8 8v1H4v-1z"/>
            </svg>
        </div>

        <?php if ($error): ?>
            <div class="msg-error">
                <?= $error ?>
                <?php if ($needsReset): ?>
                    <a href="forgot_password.php" style="color:#16A34A; font-weight:600; text-decoration:none;">Reset it now &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="msg-success">Account created! Please log in.</div>
        <?php endif; ?>

        <?php if (isset($_GET['reset_success'])): ?>
            <div class="msg-success">Password reset! Please log in with your new password.</div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="msg-error">You were logged out due to inactivity. Please sign in again.</div>
        <?php endif; ?>

        <?php if (isset($_GET['denied'])): ?>
            <div class="msg-error">You don't have permission to access that page.</div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" required>
                    <span class="toggle-password" id="togglePassword">
                        <!-- eye-off icon shown by default (password hidden) -->
                        <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </span>
                </div>
            </div>

            <div class="forgot-link">
                <a href="forgot_password.php">Forgot password?</a>
            </div>

            <button type="submit" class="btn-submit">Sign In</button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        if (togglePassword && password && eyeIcon) {
            const eyeOpen = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            const eyeOff = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';

            togglePassword.addEventListener('click', function () {
                const isHidden = password.getAttribute('type') === 'password';
                password.setAttribute('type', isHidden ? 'text' : 'password');
                eyeIcon.innerHTML = isHidden ? eyeOpen : eyeOff;
            });
        }
    </script>

</body>
</html>