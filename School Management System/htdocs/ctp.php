<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_class_teacher':
                $class_id = (int)$_POST['class_id'];
                $teacher_id = (int)$_POST['teacher_id'];
                $academic_year = sanitize($_POST['academic_year']);
                $notes = sanitize($_POST['notes']);

                try {
                    $pdo->beginTransaction();
                    
                    // Deactivate existing assignment for this class
                    $stmt = $pdo->prepare("UPDATE class_teachers SET is_active = FALSE WHERE class_id = ? AND academic_year = ?");
                    $stmt->execute([$class_id, $academic_year]);
                    
                    // Create new assignment
                    $stmt = $pdo->prepare("
                        INSERT INTO class_teachers (class_id, teacher_id, academic_year, assigned_by, notes) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$class_id, $teacher_id, $academic_year, $_SESSION['user_id'], $notes]);
                    
                    $pdo->commit();
                    logAudit($_SESSION['user_id'], 'ASSIGN', 'class_teachers', $pdo->lastInsertId());
                    $message = showAlert('Class teacher assigned successfully!');
                } catch (Exception $e) {
                    $pdo->rollback();
                    $message = showAlert('Error assigning class teacher: ' . $e->getMessage(), 'danger');
                }
                break;

            case 'remove_assignment':
                $assignment_id = (int)$_POST['assignment_id'];
                
                $stmt = $pdo->prepare("UPDATE class_teachers SET is_active = FALSE WHERE id = ?");
                if ($stmt->execute([$assignment_id])) {
                    logAudit($_SESSION['user_id'], 'REMOVE', 'class_teachers', $assignment_id);
                    $message = showAlert('Class teacher assignment removed successfully!');
                } else {
                    $message = showAlert('Error removing assignment!', 'danger');
                }
                break;
        }
    }
}

// Get all active assignments
$stmt = $pdo->query("
    SELECT ct.*, c.name as class_name, c.section, u.name as teacher_name, t.employee_id,
           au.name as assigned_by_name
    FROM class_teachers ct
    JOIN classes c ON ct.class_id = c.id
    JOIN teachers t ON ct.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    JOIN users au ON ct.assigned_by = au.id
    WHERE ct.is_active = TRUE
    ORDER BY c.name, c.section
");
$assignments = $stmt->fetchAll();

// Get available teachers and classes
$teachers = $pdo->query("
    SELECT t.id, u.name, t.employee_id 
    FROM teachers t 
    JOIN users u ON t.user_id = u.id 
    ORDER BY u.name
")->fetchAll();

$classes = $pdo->query("SELECT * FROM classes ORDER BY name, section")->fetchAll();

// Get unassigned classes
$stmt = $pdo->query("
    SELECT c.* FROM classes c
    LEFT JOIN class_teachers ct ON c.id = ct.class_id AND ct.is_active = TRUE
    WHERE ct.id IS NULL
    ORDER BY c.name, c.section
");
$unassigned_classes = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Class Teacher Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .assignment-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s ease;
        }
        .assignment-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .unassigned-card {
            border-left: 4px solid #dc3545;
            background-color: #fff5f5;
        }
        .teacher-info {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .assignment-actions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="attendance_system.php">Attendance</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chalkboard-teacher me-2"></i>Class Teacher Management</h2>
            <div class="btn-group">
                <a href="attendance_system.php" class="btn btn-success">
                    <i class="fas fa-calendar-check me-2"></i>Attendance System
                </a>
                <a href="attendance_reports.php" class="btn btn-info">
                    <i class="fas fa-chart-bar me-2"></i>Attendance Reports
                </a>
            </div>
        </div>

        <?= $message ?>

        <!-- Assign New Class Teacher -->
        <div class="assignment-actions mb-4">
            <h5 class="text-white mb-3"><i class="fas fa-plus me-2"></i>Assign Class Teacher</h5>
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="assign_class_teacher">
                
                <div class="col-md-3">
                    <label class="form-label text-white">Select Class</label>
                    <select class="form-select" name="class_id" required>
                        <option value="">Choose Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= $class['id'] ?>">
                                <?= $class['name'] ?> - Section <?= $class['section'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label text-white">Select Teacher</label>
                    <select class="form-select" name="teacher_id" required>
                        <option value="">Choose Teacher</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= $teacher['id'] ?>">
                                <?= $teacher['name'] ?> (<?= $teacher['employee_id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label text-white">Academic Year</label>
                    <input type="text" class="form-control" name="academic_year" 
                           value="2025-2026" required>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label text-white">Notes (Optional)</label>
                    <input type="text" class="form-control" name="notes" 
                           placeholder="Special instructions...">
                </div>
                
                <div class="col-md-1">
                    <label class="form-label text-white">&nbsp;</label>
                    <button type="submit" class="btn btn-light w-100">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Current Assignments -->
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Current Class Teacher Assignments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($assignments)): ?>
                            <p class="text-muted">No class teacher assignments found.</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($assignments as $assignment): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card assignment-card h-100">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="card-title mb-1">
                                                            <?= $assignment['class_name'] ?> - Section <?= $assignment['section'] ?>
                                                        </h6>
                                                        <p class="card-text mb-2">
                                                            <strong><?= $assignment['teacher_name'] ?></strong><br>
                                                            <small class="teacher-info">
                                                                Employee ID: <?= $assignment['employee_id'] ?><br>
                                                                Academic Year: <?= $assignment['academic_year'] ?>
                                                            </small>
                                                        </p>
                                                        <?php if ($assignment['notes']): ?>
                                                            <small class="text-muted">
                                                                <i class="fas fa-sticky-note me-1"></i>
                                                                <?= $assignment['notes'] ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                                type="button" data-bs-toggle="dropdown">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a class="dropdown-item" 
                                                                   href="attendance_system.php?class_id=<?= $assignment['class_id'] ?>">
                                                                    <i class="fas fa-calendar-check me-2"></i>View Attendance
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <form method="POST" class="dropdown-item-form">
                                                                    <input type="hidden" name="action" value="remove_assignment">
                                                                    <input type="hidden" name="assignment_id" value="<?= $assignment['id'] ?>">
                                                                    <button type="submit" class="dropdown-item text-danger"
                                                                            onclick="return confirm('Remove this class teacher assignment?')">
                                                                        <i class="fas fa-trash me-2"></i>Remove Assignment
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                                <div class="mt-2">
                                                    <small class="text-muted">
                                                        Assigned by: <?= $assignment['assigned_by_name'] ?><br>
                                                        Date: <?= formatDate($assignment['assigned_date']) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 text-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>Unassigned Classes
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($unassigned_classes)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                All classes have been assigned class teachers!
                            </div>
                        <?php else: ?>
                            <?php foreach ($unassigned_classes as $class): ?>
                                <div class="card unassigned-card mb-2">
                                    <div class="card-body py-2">
                                        <strong><?= $class['name'] ?> - Section <?= $class['section'] ?></strong>
                                        <br><small class="text-muted">No class teacher assigned</small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Quick Stats</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="metric-value text-success"><?= count($assignments) ?></div>
                                <div class="metric-label">Assigned</div>
                            </div>
                            <div class="col-6">
                                <div class="metric-value text-danger"><?= count($unassigned_classes) ?></div>
                                <div class="metric-label">Unassigned</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .metric-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .dropdown-item-form {
            margin: 0;
            padding: 0;
        }
        .dropdown-item-form button {
            background: none;
            border: none;
            width: 100%;
            text-align: left;
            padding: 0.25rem 1rem;
        }
    </style>
</body>
</html>
