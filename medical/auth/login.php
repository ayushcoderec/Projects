<?php
// auth/login.php - Admin Login Page
session_start();

// Include PDO database connection
include '../config/db.php';

$error_message = ''; // Initialize error message variable

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Basic validation
    if (empty($username) || empty($password)) {
        $error_message = "Please enter both username and password.";
    } else {
        try {
            // Prepare the SQL statement using PDO
            $stmt = $pdo->prepare("SELECT id, password FROM admins WHERE username = ?");
            
            // Execute the statement with the username
            $stmt->execute([$username]);
            
            // Fetch the result as an associative array
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($admin) {
                // User found, verify password
                if (password_verify($password, $admin['password'])) {
                    // Password is correct, set session and redirect
                    $_SESSION['admin_id'] = $admin['id'];
                    header("Location: ../dashboard/index.php");
                    exit(); // Always exit after a header redirect
                } else {
                    $error_message = "Invalid password.";
                }
            } else {
                // User not found
                $error_message = "User not found.";
            }
        } catch (PDOException $e) {
            // Catch any PDO database errors
            $error_message = "Database error: " . $e->getMessage();
            // In a production environment, you might log this error instead of displaying it to the user
            error_log("Login PDO Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <script src='https://cdn.tailwindcss.com'></script>
    <title>Admin Login - Medical Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class='bg-gray-100 flex items-center justify-center h-screen'>
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-sm">
        <h2 class='text-3xl font-bold mb-6 text-center text-gray-800'>Admin Login</h2>
        
        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md mb-4 text-sm">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method='POST' class='space-y-4'>
            <div>
                <label for="username" class="sr-only">Username</label>
                <input type='text' name='username' id="username" placeholder='Username' 
                       class='w-full p-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent' 
                       required autocomplete="username">
            </div>
            <div>
                <label for="password" class="sr-only">Password</label>
                <input type='password' name='password' id="password" placeholder='Password' 
                       class='w-full p-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent' 
                       required autocomplete="current-password">
            </div>
            <button type='submit' 
                    class='w-full bg-blue-600 text-white p-3 rounded-md hover:bg-blue-700 transition duration-200 font-semibold'>
                Login
            </button>
        </form>
    </div>
</body>
</html>
