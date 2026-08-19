<?php
// Render's network does not reliably allow outbound raw SMTP connections
// (port 587/465) — confirmed via "Connection timed out (110)" errors.
// This version sends email over HTTPS using Brevo's transactional email
// API instead, which works fine from Render.
//
// Requires two environment variables (set in Render dashboard):
//   BREVO_API_KEY   - from Brevo: Settings -> SMTP & API -> API Keys
//   MAIL_USERNAME   - the sender email address, must be a "Verified Sender"
//                     in Brevo: Settings -> Senders, Domains & Dedicated IPs
//
// Keeps the exact same function name/signature as before, so nothing in
// register.php, forgot_password.php, verify_email.php, verify_payment.php,
// etc. needs to change.

function send_smtp_mail(string $toEmail, string $toName, string $subject, string $bodyText): bool {
    $apiKey = getenv('BREVO_API_KEY') ?: '';
    $fromEmail = getenv('MAIL_USERNAME') ?: '';
    $fromName  = getenv('MAIL_FROM_NAME') ?: 'PaddleGround';

    if ($apiKey === '' || $fromEmail === '') {
        error_log("Mailer Error: BREVO_API_KEY or MAIL_USERNAME environment variable is missing.");
        return false;
    }

    $payload = json_encode([
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'textContent' => $bodyText,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 10, // fail fast instead of hanging
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Brevo returns 201 Created on success
    if ($httpCode === 201) {
        return true;
    }

    error_log("Mailer Error: Brevo API call failed. HTTP {$httpCode}. cURL error: {$curlError}. Response: {$response}");
    return false;
}