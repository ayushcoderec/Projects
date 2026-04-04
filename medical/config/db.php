<?php
// config/db.php - Complete PDO configuration
$host = 'localhost';
$db = 'medical';
$user = 'root';
$pass = '';

try {
    // Establish PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    
    // Set PDO error mode to exception for robust error handling
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    // If PDO connection fails, terminate script and display error
    die('Database Connection Failed: ' . $e->getMessage());
}
?>
