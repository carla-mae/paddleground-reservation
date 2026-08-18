<?php
// Reads Gmail SMTP credentials from environment variables (set in the
// Render dashboard) instead of being committed to the codebase.
return [
    'smtp_username' => getenv('MAIL_USERNAME') ?: '',
    'smtp_password' => getenv('MAIL_APP_PASSWORD') ?: '',
    'from_name'     => getenv('MAIL_FROM_NAME') ?: 'PaddleGround',
];