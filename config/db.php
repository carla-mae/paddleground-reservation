<?php
$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: 'paddle_reservation_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$port     = (int) (getenv('DB_PORT') ?: 3306);
$useSSL   = getenv('DB_SSL') === '1';

$conn = mysqli_init();
if ($useSSL) {
    $conn->ssl_set(null, null, null, null, null);
    $conn->real_connect($host, $username, $password, $dbname, $port, null, MYSQLI_CLIENT_SSL);
} else {
    $conn->real_connect($host, $username, $password, $dbname, $port);
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
?>