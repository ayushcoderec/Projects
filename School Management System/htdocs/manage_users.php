<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin']);

$message = '';
$selectedRole = 'admin'; // fixed for admins only

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $email = sanitize($_POST['email']);
        $name = sanitize($_POST['name']);
        $password = $_POST['password'];
        $role = 'admin'; // fixed role

        if (!$email || !$name || !$password) {
            $message = showAlert('All fields are required.', 'danger');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = showAlert('Invalid email format.', 'danger');
        } elseif (strlen($password) < 6) {
            $message = showAlert('Password must be at least 6 characters.', 'danger');
        } else {
            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetchColumn()) {
                $message = showAlert('Email already exists.', 'danger');
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmtInsert = $pdo->prepare('INSERT INTO users (email, name, password, role, created_at) VALUES (?, ?, ?, ?, NOW())');
                if ($stmtInsert->execute([$email, $name, $hashedPassword, $role])) {
                    $message = showAlert('Admin created successfully.', 'success');
                    logAudit($_SESSION['id'], 'INSERT', 'users', $pdo->lastInsertId(), '', "Created admin: $email");
                } else {
                    $message = showAlert('Error creating admin.', 'danger');
                }
            }
        }
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)($_SESSION['id'] ?? 0)) {
            $message = showAlert('You cannot delete your own account.', 'danger');
        } else {
            $stmtDelete = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "admin"');
            if ($stmtDelete->execute([$userId])) {
                $message = showAlert('Admin deleted successfully.', 'success');
                logAudit($_SESSION['id'], 'DELETE', 'users', $userId, '', 'Deleted admin user');
            } else {
                $message = showAlert('Error deleting admin.', 'danger');
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT id, email, name, role, created_at FROM users WHERE role = "admin" ORDER BY created_at DESC');
$stmt->execute();
$admins = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Manage Admins - <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
<div class="container mt-4">
    <h1>Manage Admins</h1>
    <?= $message ?>

    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#createAdminModal">Create New Admin</button>

    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$admins): ?>
            <tr><td colspan="5" class="text-center">No admins found.</td></tr>
            <?php else: ?>
                <?php foreach ($admins as $admin): ?>
                <tr>
                    <td><?= htmlspecialchars($admin['id'] ?? '') ?></td>
                    <td><?= htmlspecialchars($admin['name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($admin['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($admin['created_at'] ?? '') ?></td>
                    <td>
                        <?php if (($admin['id'] ?? 0) !== ($_SESSION['id'] ?? 0)): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this admin?');">
                            <input type="hidden" name="user_id" value="<?= htmlspecialchars($admin['id'] ?? 0) ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted">Current User</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Create Admin Modal -->
<div class="modal fade" id="createAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create" />
            <div class="modal-header">
                <h5 class="modal-title">Create New Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="createName" class="form-label">Name*</label>
                    <input type="text" id="createName" name="name" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="createEmail" class="form-label">Email*</label>
                    <input type="email" id="createEmail" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="createPassword" class="form-label">Password*</label>
                    <input type="password" id="createPassword" name="password" class="form-control" minlength="6" required>
                    <div class="form-text">Minimum 6 characters</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Admin</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
