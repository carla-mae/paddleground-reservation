<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

function send_verification_email(string $toEmail, string $toName, string $code): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'];
        $mail->Password   = $_ENV['MAIL_APP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom($_ENV['MAIL_USERNAME'], 'Paddle Ground Reservation');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Your verification code';
        $mail->Body    = "<p>Your 6-digit verification code is:</p><h2>{$code}</h2><p>This code expires in 10 minutes.</p>";
        $mail->AltBody  = "Your verification code is: {$code}";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer error: " . $mail->ErrorInfo);
        return false;
    }
}