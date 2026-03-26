<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = sanitize($_POST['name']);
                $exam_type = sanitize($_POST['exam_type']);
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];

                // Validate dates
                if (strtotime($start_date) > strtotime($end_date)) {
                    $message = showAlert('Start date cannot be after end date!', 'danger');
                    break;
                }

                $stmt = $pdo->prepare("INSERT INTO exams (name, exam_type, start_date, end_date) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$name, $exam_type, $start_date, $end_date])) {
                    logAudit($_SESSION['user_id'], 'INSERT', 'exams', $pdo->lastInsertId());
                    $message = showAlert('Exam added successfully!');
                } else {
                    $message = showAlert('Error adding exam!', 'danger');
                }
                break;

            case 'edit':
                $id = (int)$_POST['id'];
                $name = sanitize($_POST['name']);
                $exam_type = sanitize($_POST['exam_type']);
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];

                // Validate dates
                if (strtotime($start_date) > strtotime($end_date)) {
                    $message = showAlert('Start date cannot be after end date!', 'danger');
                    break;
                }

                $stmt = $pdo->prepare("UPDATE exams SET name = ?, exam_type = ?, start_date = ?, end_date = ? WHERE id = ?");
                if ($stmt->execute([$name, $exam_type, $start_date, $end_date, $id])) {
                    logAudit($_SESSION['user_id'], 'UPDATE', 'exams', $id);
                    $message = showAlert('Exam updated successfully!');
                } else {
                    $message = showAlert('Error updating exam!', 'danger');
                }
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                
                // Check if exam has marks entered
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM marks WHERE exam_id = ?");
                $stmt->execute([$id]);
                $marks_count = $stmt->fetch()['count'];
                
                if ($marks_count > 0) {
                    $message = showAlert('Cannot delete exam with existing marks entries!', 'danger');
                } else {
                    $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        logAudit($_SESSION['user_id'], 'DELETE', 'exams', $id);
                        $message = showAlert('Exam deleted successfully!');
                    } else {
                        $message = showAlert('Error deleting exam!', 'danger');
                    }
                }
                break;
        }
    }
}

// Get all exams
$exams = getAllExams();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Manage Exams</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .exam-type-badge {
            font-size: 0.8rem;
        }
        .date-range {
            font-size: 0.9rem;
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
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-clipboard-list me-2"></i>Manage Exams</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExamModal">
                <i class="fas fa-plus me-2"></i>Add Exam
            </button>
        </div>

        <?= $message ?>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Exam Name</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Marks Entries</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exams as $exam): ?>
                                <?php
                                // Get marks entries count for this exam
                                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM marks WHERE exam_id = ?");
                                $stmt->execute([$exam['id']]);
                                $marks_count = $stmt->fetch()['count'];

                                // Determine exam status
                                $today = date('Y-m-d');
                                $status = '';
                                $status_class = '';
                                
                                if ($today < $exam['start_date']) {
                                    $status = 'Upcoming';
                                    $status_class = 'bg-info';
                                } elseif ($today >= $exam['start_date'] && $today <= $exam['end_date']) {
                                    $status = 'Ongoing';
                                    $status_class = 'bg-warning';
                                } else {
                                    $status = 'Completed';
                                    $status_class = 'bg-success';
                                }

                                // Exam type badge color
                                $type_class = '';
                                switch ($exam['exam_type']) {
                                    case 'term':
                                        $type_class = 'bg-primary';
                                        break;
                                    case 'unit_test':
                                        $type_class = 'bg-info';
                                        break;
                                    case 'class_test':
                                        $type_class = 'bg-secondary';
                                        break;
                                    case 'final':
                                        $type_class = 'bg-danger';
                                        break;
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= $exam['name'] ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge exam-type-badge <?= $type_class ?>">
                                            <?= ucwords(str_replace('_', ' ', $exam['exam_type'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="date-range">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            <?= formatDate($exam['start_date']) ?> - <?= formatDate($exam['end_date']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $status_class ?>"><?= $status ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark"><?= $marks_count ?> entries</span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary edit-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editExamModal"
                                                data-id="<?= $exam['id'] ?>"
                                                data-name="<?= $exam['name'] ?>"
                                                data-type="<?= $exam['exam_type'] ?>"
                                                data-start="<?= $exam['start_date'] ?>"
                                                data-end="<?= $exam['end_date'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteExamModal"
                                                data-id="<?= $exam['id'] ?>"
                                                data-name="<?= $exam['name'] ?>"
                                                data-marks="<?= $marks_count ?>">
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

        <!-- Exam Statistics -->
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>Total Exams</h6>
                                <h3><?= count($exams) ?></h3>
                            </div>
                            <i class="fas fa-clipboard-list fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>Upcoming</h6>
                                <?php
                                $upcoming = array_filter($exams, function($exam) {
                                    return date('Y-m-d') < $exam['start_date'];
                                });
                                ?>
                                <h3><?= count($upcoming) ?></h3>
                            </div>
                            <i class="fas fa-clock fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>Ongoing</h6>
                                <?php
                                $ongoing = array_filter($exams, function($exam) {
                                    $today = date('Y-m-d');
                                    return $today >= $exam['start_date'] && $today <= $exam['end_date'];
                                });
                                ?>
                                <h3><?= count($ongoing) ?></h3>
                            </div>
                            <i class="fas fa-play-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6>Completed</h6>
                                <?php
                                $completed = array_filter($exams, function($exam) {
                                    return date('Y-m-d') > $exam['end_date'];
                                });
                                ?>
                                <h3><?= count($completed) ?></h3>
                            </div>
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Exam Modal -->
    <div class="modal fade" id="addExamModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Exam</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label class="form-label">Exam Name</label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g., Term 1 Examination">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Exam Type</label>
                            <select class="form-control" name="exam_type" required>
                                <option value="">Select Type</option>
                                <option value="term">Term Examination</option>
                                <option value="unit_test">Unit Test</option>
                                <option value="class_test">Class Test</option>
                                <option value="final">Final Examination</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="start_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="end_date" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Exam</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Exam Modal -->
    <div class="modal fade" id="editExamModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Exam</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Exam Name</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Exam Type</label>
                            <select class="form-control" name="exam_type" id="edit_type" required>
                                <option value="term">Term Examination</option>
                                <option value="unit_test">Unit Test</option>
                                <option value="class_test">Class Test</option>
                                <option value="final">Final Examination</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="start_date" id="edit_start" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="end_date" id="edit_end" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Exam</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Exam Modal -->
    <div class="modal fade" id="deleteExamModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Exam</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete_id">
                        <p>Are you sure you want to delete <strong id="delete_name"></strong>?</p>
                        <div id="marks_warning" class="alert alert-warning" style="display: none;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            This exam has <strong id="marks_count"></strong> marks entries and cannot be deleted!
                        </div>
                        <p class="text-danger" id="delete_confirmation">This action cannot be undone!</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="delete_submit_btn">Delete</button>
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
                document.getElementById('edit_type').value = this.dataset.type;
                document.getElementById('edit_start').value = this.dataset.start;
                document.getElementById('edit_end').value = this.dataset.end;
            });
        });

        // Delete button functionality
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('delete_id').value = this.dataset.id;
                document.getElementById('delete_name').textContent = this.dataset.name;
                
                const marksCount = parseInt(this.dataset.marks);
                const warningDiv = document.getElementById('marks_warning');
                const confirmationP = document.getElementById('delete_confirmation');
                const submitBtn = document.getElementById('delete_submit_btn');
                
                if (marksCount > 0) {
                    document.getElementById('marks_count').textContent = marksCount;
                    warningDiv.style.display = 'block';
                    confirmationP.style.display = 'none';
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Cannot Delete';
                } else {
                    warningDiv.style.display = 'none';
                    confirmationP.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Delete';
                }
            });
        });

        // Date validation
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInputs = document.querySelectorAll('input[name="start_date"]');
            const endDateInputs = document.querySelectorAll('input[name="end_date"]');
            
            function validateDates(startInput, endInput) {
                if (startInput.value && endInput.value) {
                    if (new Date(startInput.value) > new Date(endInput.value)) {
                        endInput.setCustomValidity('End date must be after start date');
                    } else {
                        endInput.setCustomValidity('');
                    }
                }
            }
            
            startDateInputs.forEach((input, index) => {
                input.addEventListener('change', function() {
                    validateDates(this, endDateInputs[index]);
                });
            });
            
            endDateInputs.forEach((input, index) => {
                input.addEventListener('change', function() {
                    validateDates(startDateInputs[index], this);
                });
            });
        });
    </script>
</body>
</html>
