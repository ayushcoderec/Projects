<?php
session_start(); // ADD THIS LINE - Sessions won't work without it!
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    
    // Debug: Check if user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Debug: Check password verification
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];
            
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid password';
        }
    } else {
        $error = 'User not found';
    }
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6 col-lg-4">
                    <div class="login-card">
                        <div class="card-body p-5">
                            <div class="text-center mb-4">
                                <i class="fas fa-graduation-cap fa-3x text-primary mb-3"></i>
                                <h3 class="card-title">School Exam System</h3>
                                <p class="text-muted">Sign in to your account</p>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger"><?= $error ?></div>
                            <?php endif; ?>

                            <form method="POST">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="superadmin@example.com" required>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="password" class="form-label">Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control" id="password" name="password" 
                                               value="password" required>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 mb-3">
                                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                                </button>
                            </form>

                            <div class="text-center mt-4">
                                <small class="text-muted">
                                    <strong>Demo Credentials:</strong><br>
                                    Super Admin: superadmin@example.com / password<br>
                                    Admin: admin@example.com / password<br>
                                    Teacher: teacher1@example.com / password
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
