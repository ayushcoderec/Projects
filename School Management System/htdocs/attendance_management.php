<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin', 'teacher']);

$message = '';

// Handle working days update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_working_days') {
        $exam_id = (int)$_POST['exam_id'];
        $class_id = (int)$_POST['class_id'];
        $working_days = (int)$_POST['working_days'];
        
        $stmt = $pdo->prepare("
            INSERT INTO exam_working_days (exam_id, class_id, working_days, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE working_days = VALUES(working_days), updated_by = VALUES(updated_by)
        ");
        
        if ($stmt->execute([$exam_id, $class_id, $working_days, $_SESSION['user_id']])) {
            $message = showAlert("Working days updated successfully!", 'success');
        } else {
            $message = showAlert("Error updating working days!", 'danger');
        }
    }
    
    if ($_POST['action'] == 'update_attendance') {
        $exam_id = (int)$_POST['exam_id'];
        $attendance_data = $_POST['attendance'] ?? [];
        
        $updated = 0;
        foreach ($attendance_data as $student_id => $present_days) {
            $student_id = (int)$student_id;
            $present_days = (int)$present_days;
            
            $stmt = $pdo->prepare("
                INSERT INTO student_exam_attendance (student_id, exam_id, present_days, updated_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE present_days = VALUES(present_days), updated_by = VALUES(updated_by)
            ");
            
            if ($stmt->execute([$student_id, $exam_id, $present_days, $_SESSION['user_id']])) {
                $updated++;
            }
        }
        
        $message = showAlert("Attendance updated for $updated students!", 'success');
    }
}

$exams = getAllExams();
$classes = getAllClasses();

// Load data if filters selected
$selectedExam = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : '';
$selectedClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : '';
$studentsData = [];
$currentWorkingDays = 220;

if ($selectedExam && $selectedClass) {
    // Get working days
    $stmt = $pdo->prepare("SELECT working_days FROM exam_working_days WHERE exam_id = ? AND class_id = ?");
    $stmt->execute([$selectedExam, $selectedClass]);
    $wd = $stmt->fetch();
    $currentWorkingDays = $wd ? $wd['working_days'] : 220;
    
    // Get students with attendance
    $stmt = $pdo->prepare("
        SELECT s.*, 
               COALESCE(sea.present_days, 0) as present_days
        FROM students s
        LEFT JOIN student_exam_attendance sea ON s.id = sea.student_id AND sea.exam_id = ?
        WHERE s.class_id = ?
        ORDER BY s.roll_number
    ");
    $stmt->execute([$selectedExam, $selectedClass]);
    $studentsData = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Attendance Management</title>
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
        <h2><i class="fas fa-calendar-check me-2"></i>Attendance Management</h2>
        
        <?= $message ?>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-filter me-2"></i>Select Exam & Class</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Exam</label>
                        <select class="form-select" name="exam_id" required>
                            <option value="">Choose Exam</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= $exam['id'] ?>" <?= $selectedExam == $exam['id'] ? 'selected' : '' ?>>
                                    <?= $exam['name'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Class</label>
                        <select class="form-select" name="class_id" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                    <?= $class['name'] ?> - <?= $class['section'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Load
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($studentsData)): ?>
            <!-- Working Days Section -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-calendar me-2"></i>Total Working Days</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="update_working_days">
                        <input type="hidden" name="exam_id" value="<?= $selectedExam ?>">
                        <input type="hidden" name="class_id" value="<?= $selectedClass ?>">
                        
                        <div class="col-md-8">
                            <label class="form-label">Enter Total Working Days for this Exam & Class:</label>
                            <input type="number" class="form-control" name="working_days" 
                                   value="<?= $currentWorkingDays ?>" min="1" max="365" required>
                            <small class="text-muted">This will apply to all students in this class for this exam</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-info w-100">
                                <i class="fas fa-save me-2"></i>Update Working Days
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Attendance Entry Section -->
            <form method="POST">
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="exam_id" value="<?= $selectedExam ?>">
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-user-check me-2"></i>Student Attendance (Present Days)</h5>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Save Attendance
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <strong>Total Working Days:</strong> <?= $currentWorkingDays ?> days
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Roll No</th>
                                        <th>Student Name</th>
                                        <th>Student ID</th>
                                        <th>Present Days</th>
                                        <th>Attendance %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studentsData as $student): ?>
                                        <?php
                                        $percentage = $currentWorkingDays > 0 ? 
                                            ($student['present_days'] / $currentWorkingDays) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?= $student['roll_number'] ?></td>
                                            <td><?= $student['name'] ?></td>
                                            <td><?= $student['student_id'] ?></td>
                                            <td>
                                                <input type="number" 
                                                       class="form-control form-control-sm attendance-input" 
                                                       name="attendance[<?= $student['id'] ?>]" 
                                                       value="<?= $student['present_days'] ?>"
                                                       min="0" 
                                                       max="<?= $currentWorkingDays ?>"
                                                       data-working-days="<?= $currentWorkingDays ?>"
                                                       data-row="<?= $student['id'] ?>">
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $percentage >= 75 ? 'success' : 'warning' ?>" 
                                                      id="percentage_<?= $student['id'] ?>">
                                                    <?= number_format($percentage, 1) ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-calculate percentage when present days change
        document.querySelectorAll('.attendance-input').forEach(input => {
            input.addEventListener('input', function() {
                const presentDays = parseFloat(this.value) || 0;
                const workingDays = parseFloat(this.dataset.workingDays) || 1;
                const rowId = this.dataset.row;
                const percentage = (presentDays / workingDays) * 100;
                
                const badge = document.getElementById('percentage_' + rowId);
                badge.textContent = percentage.toFixed(1) + '%';
                
                // Update badge color
                if (percentage >= 75) {
                    badge.className = 'badge bg-success';
                } else {
                    badge.className = 'badge bg-warning';
                }
            });
        });
    </script>
</body>
</html>
