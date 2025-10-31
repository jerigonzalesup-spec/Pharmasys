<?php
// func/db.php - central mysqli connection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Report mysqli errors as exceptions during development
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = 'localhost';
$username = 'root';
$password = '';
$db_name = 'pharmasys';

// Optional external config override
$config_path = __DIR__ . '/../config/config.php';
if (file_exists($config_path)) {
    include $config_path; // may set $db_config
    if (!empty($db_config) && is_array($db_config)) {
        $host = $db_config['host'] ?? $host;
        $username = $db_config['username'] ?? $username;
        $password = $db_config['password'] ?? $password;
        $db_name = $db_config['database'] ?? $db_name;
    }
}

try {
    $conn = new mysqli($host, $username, $password, $db_name);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    // error message for missing database
    error_log('DB connection error: ' . $e->getMessage());
    die('Database connection failed.');
}
