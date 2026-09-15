<?php
/**
 * Database Configuration
 * Hotel PMS - Resort Management System
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hotel_pms_v2');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($conn, 'utf8mb4');

// Base URL / path helper (adjust if app runs in a subfolder)
define('BASE_URL', '/');

// Timezone
date_default_timezone_set('Asia/Dhaka');
