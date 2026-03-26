<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = sanitize($_POST['name']);
                $section = sanitize($_POST['section']);

                $stmt = $pdo->prepare("INSERT INTO classes (name, section) VALUES (?, ?)");
                if ($stmt->execute([$name, $section])) {
                    logAudit($_SESSION['user_id'], 'INSERT', 'classes', $pdo->lastInsertId());
                    $message = showAlert('Class added successfully!');
                } else {
                    $message = showAlert('Error adding class!', 'danger');
                }
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $name = sanitize($_POST['name']);
                $section = sanitize($_POST['section']);

                $stmt = $pdo->prepare("UPDATE classes SET name = ?, section = ? WHERE id = ?");
                if ($stmt->execute([$name, $section, $id])) {
                    logAudit($_SESSION['user_id'], 'UPDATE', 'classes', $id);
                    $message = showAlert('Class updated successfully!');
                } else {
                    $message = showAlert('Error updating class!', 'danger');
                }
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ?");
                if ($stmt->execute([$id])) {
                    logAudit($_SESSION['user_id'], 'DELETE', 'classes', $id);
                    $message = showAlert('Class deleted successfully!');
                } else {
                    $message = showAlert('Error deleting class!', 'danger');
                }
                break;
        }
    }
}

$classes = getAllClasses();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Manage Classes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chalkboard me-2"></i>Manage Classes</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
                <i class="fas fa-plus me-2"></i>Add Class
            </button>
        </div>

        <?= $message ?>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Class Name</th>
                                <th>Section</th>
                                <th>Students Count</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $class): ?>
                                <?php
                                // Get student count for this class
                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE class_id = ?");
                                $stmt->execute([$class['id']]);
                                $student_count = $stmt->fetch()['count'];
                                ?>
                                <tr>
                                    <td><?= $class['id'] ?></td>
                                    <td><?= $class['name'] ?></td>
                                    <td><?= $class['section'] ?></td>
                                    <td>
                                        <span class="badge bg-info"><?= $student_count ?> students</span>
                                    </td>
                                    <td><?= formatDate($class['created_at']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary edit-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editClassModal"
                                                data-id="<?= $class['id'] ?>"
                                                data-name="<?= $class['name'] ?>"
                                                data-section="<?= $class['section'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteClassModal"
                                                data-id="<?= $class['id'] ?>"
                                                data-name="<?= $class['name'] ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Class Modal -->
    <div class="modal fade" id="addClassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Class</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label class="form-label">Class Name</label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g., Class 10">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Section</label>
                            <input type="text" class="form-control" name="section" placeholder="e.g., A">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Class Modal -->
    <div class="modal fade" id="editClassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Class</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Class Name</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Section</label>
                            <input type="text" class="form-control" name="section" id="edit_section">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Class</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Class Modal -->
    <div class="modal fade" id="deleteClassModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Class</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete_id">
                        <p>Are you sure you want to delete <strong id="delete_name"></strong>?</p>
                        <p class="text-danger">This action cannot be undone!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit button functionality
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_name').value = this.dataset.name;
                document.getElementById('edit_section').value = this.dataset.section;
            });
        });

        // Delete button functionality
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('delete_id').value = this.dataset.id;
                document.getElementById('delete_name').textContent = this.dataset.name;
            });
        });
    </script>
</body>
</html>
