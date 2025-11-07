<?php
/**
 * Database Configuration
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'tabeya_system');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

// Set charset
$conn->set_charset("utf8mb4");

?>