<?php
// Database configuration
define('DB_HOST', 'sql307.ezyro.com');
define('DB_NAME', 'ezyro_40109632_abc');
define('DB_USER', 'ezyro_40109632');
define('DB_PASS', '54fb7a4bc');

// Application configuration
define('APP_NAME', 'Nightingle Nursery School');
define('BASE_URL', 'http://localhost/school_exam_system/');

// Session configuration
session_start();

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

// Check user role
function checkRole($required_roles) {
    checkLogin();
    if (!in_array($_SESSION['role'], $required_roles)) {
        header('Location: dashboard.php');
        exit;
    }
}
?>
