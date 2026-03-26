<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';
$subjectId = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if (!$subjectId) {
    die(showAlert('Invalid subject ID.', 'danger'));
}

// Fetch subject name and default marks
$stmt = $pdo->prepare("SELECT name, written_full_marks, oral_full_marks FROM subjects WHERE id = ?");
$stmt->execute([$subjectId]);
$subject = $stmt->fetch();

if (!$subject) {
    die(showAlert('Subject not found.', 'danger'));
}

// Handle form submissions for custom marks
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_class_marks') {
        $class_id = (int)$_POST['class_id'];
        $written_marks = (int)$_POST['written_full_marks'];
        $oral_marks = (int)$_POST['oral_full_marks'];

        if ($written_marks < 0 || $oral_marks < 0) {
             $message = showAlert('Marks cannot be negative!', 'danger');
        } elseif ($written_marks == $subject['written_full_marks'] && $oral_marks == $subject['oral_full_marks']) {
            // Delete if matching default to clean up
            $stmt = $pdo->prepare("DELETE FROM subject_class_full_marks WHERE subject_id = ? AND class_id = ?");
            $stmt->execute([$subjectId, $class_id]);
            $message = showAlert('Class-specific marks deleted (reverted to default)!', 'warning');
        } else {
            // INSERT or UPDATE (UPSERT)
            $sql = "
                INSERT INTO subject_class_full_marks (subject_id, class_id, written_full_marks, oral_full_marks, updated_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    written_full_marks = VALUES(written_full_marks),
                    oral_full_marks = VALUES(oral_full_marks),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ";
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute([$subjectId, $class_id, $written_marks, $oral_marks, $_SESSION['user_id']])) {
                $message = showAlert("Custom full marks saved for Class {$class_id}!", 'success');
            } else {
                $message = showAlert('Error saving custom marks!', 'danger');
            }
        }
    } elseif ($_POST['action'] === 'delete_class_marks') {
        $class_id = (int)$_POST['class_id'];
        $stmt = $pdo->prepare("DELETE FROM subject_class_full_marks WHERE subject_id = ? AND class_id = ?");
        $stmt->execute([$subjectId, $class_id]);
        $message = showAlert('Class-specific marks deleted (reverted to default)!', 'warning');
    }
}

// Fetch all classes and their custom marks for this subject
$stmt = $pdo->prepare("
    SELECT 
        c.id as class_id, 
        c.name as class_name, 
        c.section,
        csc.written_full_marks as custom_written,
        csc.oral_full_marks as custom_oral
    FROM classes c
    LEFT JOIN subject_class_full_marks csc ON c.id = csc.class_id AND csc.subject_id = ?
    ORDER BY c.name, c.section
");
$stmt->execute([$subjectId]);
$classMarks = $stmt->fetchAll();

$classes = getAllClasses(); // For the dropdown
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Manage Marks for <?= htmlspecialchars($subject['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .custom-mark-row {
            transition: background-color 0.2s;
        }
        .custom-mark-row.custom {
            background-color: #e6f7ff; /* Light blue for custom marks */
            border-left: 4px solid #007bff;
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
                <a class="nav-link" href="manage_subjects.php">Back to Subjects</a>
                <a class="nav-link" href="dashboard.php">Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-layer-group me-2"></i>Manage Class-Specific Full Marks</h2>
        </div>

        <?= $message ?>

        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">
                    Subject: <?= htmlspecialchars($subject['name']) ?> (Default Full Marks: W: <?= $subject['written_full_marks'] ?>, O: <?= $subject['oral_full_marks'] ?>)
                </h5>
            </div>
            <div class="card-body">
                <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addCustomMarkModal">
                    <i class="fas fa-plus me-2"></i>Set Marks for a Class
                </button>

                <div class="table-responsive">
                    <table class="table table-hover table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Class</th>
                                <th class="text-center">Written Full Marks</th>
                                <th class="text-center">Oral Full Marks</th>
                                <th class="text-center">Total Full Marks</th>
                                <th class="text-center">Configuration</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classMarks as $mark): ?>
                                <?php 
                                $isCustom = $mark['custom_written'] !== null;
                                $written = $isCustom ? $mark['custom_written'] : $subject['written_full_marks'];
                                $oral = $isCustom ? $mark['custom_oral'] : $subject['oral_full_marks'];
                                ?>
                                <tr class="custom-mark-row <?= $isCustom ? 'custom' : '' ?>">
                                    <td><?= htmlspecialchars($mark['class_name']) ?> - <?= htmlspecialchars($mark['section']) ?></td>
                                    <td class="text-center"><?= $written ?></td>
                                    <td class="text-center"><?= $oral ?></td>
                                    <td class="text-center"><strong><?= $written + $oral ?></strong></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $isCustom ? 'primary' : 'secondary' ?>">
                                            <?= $isCustom ? 'Custom Set' : 'Using Default' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-info edit-marks-btn me-2"
                                                data-bs-toggle="modal" data-bs-target="#editCustomMarkModal"
                                                data-class-id="<?= $mark['class_id'] ?>"
                                                data-class-name="<?= htmlspecialchars($mark['class_name'] . ' - ' . $mark['section']) ?>"
                                                data-written-marks="<?= $written ?>"
                                                data-oral-marks="<?= $oral ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if ($isCustom): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_class_marks">
                                                <input type="hidden" name="class_id" value="<?= $mark['class_id'] ?>">
                                                <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to revert marks for this class to default?')">
                                                    <i class="fas fa-undo"></i> Revert
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Custom Mark Modal -->
    <div class="modal fade" id="addCustomMarkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Set Custom Marks for a Class</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_class_marks">
                        <input type="hidden" name="subject_id" value="<?= $subjectId ?>">

                        <div class="mb-3">
                            <label class="form-label">Select Class *</label>
                            <select class="form-select" name="class_id" required>
                                <option value="">Choose Class</option>
                                <?php 
                                $assignedClassIds = array_column($classMarks, 'class_id');
                                foreach ($classes as $class): ?>
                                    <?php if (!in_array($class['id'], $assignedClassIds)): // Only show unconfigured classes for simplicity in 'Add' ?>
                                        <option value="<?= $class['id'] ?>">
                                            <?= $class['name'] ?> - <?= $class['section'] ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Written Full Marks (Default: <?= $subject['written_full_marks'] ?>)</label>
                            <input type="number" class="form-control" name="written_full_marks" value="<?= $subject['written_full_marks'] ?>" min="0" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Oral Full Marks (Default: <?= $subject['oral_full_marks'] ?>)</label>
                            <input type="number" class="form-control" name="oral_full_marks" value="<?= $subject['oral_full_marks'] ?>" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Custom Marks</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Custom Mark Modal -->
    <div class="modal fade" id="editCustomMarkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Custom Marks for <span id="edit_class_name"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_class_marks">
                        <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                        <input type="hidden" name="class_id" id="edit_class_id">

                        <div class="mb-3">
                            <label class="form-label">Written Full Marks (Default: <?= $subject['written_full_marks'] ?>)</label>
                            <input type="number" class="form-control" name="written_full_marks" id="edit_written_full_marks" min="0" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Oral Full Marks (Default: <?= $subject['oral_full_marks'] ?>)</label>
                            <input type="number" class="form-control" name="oral_full_marks" id="edit_oral_full_marks" min="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Marks</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.edit-marks-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('edit_class_name').textContent = this.dataset.className;
                document.getElementById('edit_class_id').value = this.dataset.classId;
                document.getElementById('edit_written_full_marks').value = this.dataset.writtenMarks;
                document.getElementById('edit_oral_full_marks').value = this.dataset.oralMarks;
            });
        });
    </script>
</body>
</html>
