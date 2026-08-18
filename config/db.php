<?php
// Reads DB credentials from environment variables (set in the Render
// dashboard). Falls back to your old local XAMPP/Laragon defaults so this
// file still works unchanged on your local machine.
$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: 'paddle_reservation_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$port     = (int) (getenv('DB_PORT') ?: 3306);

$conn = new mysqli($host, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>