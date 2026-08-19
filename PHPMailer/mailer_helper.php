<?php
require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/PHPMailer.php';
require_once __DIR__ . '/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_smtp_mail(string $toEmail, string $toName, string $subject, string $bodyText): bool {
    $configPath = __DIR__ . '/mail_config.php';

    if (!file_exists($configPath)) {
        error_log("Mailer Error: mail_config.php is missing. Create it with your Gmail credentials.");
        return false;
    }

    $config = require $configPath;

    $username = trim($config['smtp_username'] ?? '');
    $password = str_replace(' ', '', trim($config['smtp_password'] ?? ''));

    if ($username === '' || $password === '') {
        error_log("Mailer Error: smtp_username or smtp_password is empty in mail_config.php.");
        return false;
    }

    if (strlen($password) !== 16) {
        error_log("Mailer Warning: SMTP password is " . strlen($password) . " chars — expected 16. Check mail_config.php for a copy-paste mistake.");
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // IMPORTANT: without these, a blocked/slow outbound connection
        // (common on PaaS hosts like Render) makes PHP hang indefinitely
        // instead of failing with a clear error. Timeout is in seconds.
        $mail->Timeout       = 10;
        $mail->SMTPKeepAlive = false;

        // Log exactly what SMTP is doing to error_log (not echoed to the
        // browser) — check this via Render's Logs tab to see the real
        // failure reason (auth error vs connection refused vs timeout).
        $mail->SMTPDebug   = 2; // 2 = client + server messages
        $mail->Debugoutput = function ($str, $level) {
            error_log("SMTP DEBUG [{$level}]: {$str}");
        };

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        $mail->setFrom($username, $config['from_name'] ?? 'PaddleGround');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $bodyText;

        $mail->send();
        return true;

    } catch (Exception $e) {
        $errorMsg = $mail->ErrorInfo;
        // Render's filesystem is read-only outside a few writable dirs, so
        // writing to a local mail_error.log file fails there (and a failed
        // file_put_contents() prints a warning that can break header()
        // redirects downstream). error_log() alone is enough — it shows up
        // in Render's Logs tab.
        error_log("Mailer Error: {$errorMsg}");

        return false;
    }
}