<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';
$selectedClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selectedType = isset($_GET['type']) ? $_GET['type'] : 'all';
$selectedGroup = isset($_GET['group']) ? $_GET['group'] : 'all';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_subject':
                $name = sanitize($_POST['name']);
                $code = sanitize($_POST['code']);
                $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
                $subject_type = sanitize($_POST['subject_type']);
                $class_group = sanitize($_POST['class_group']);
                $has_written = isset($_POST['has_written']) ? 1 : 0;
                $has_oral = isset($_POST['has_oral']) ? 1 : 0;
                // These are now saved as DEFAULT marks in the subjects table
                $written_marks = $has_written ? (int)$_POST['written_full_marks'] : 0;
                $oral_marks = $has_oral ? (int)$_POST['oral_full_marks'] : 0;
                $description = sanitize($_POST['description'] ?? '');

                // Validation
                if (!$has_written && !$has_oral) {
                    $message = showAlert('Subject must have at least written or oral assessment!', 'danger');
                    break;
                }

                // For co-scholastic subjects, usually just oral/practical assessment (enforced logic here)
                if ($subject_type === 'co_scholastic') {
                    // Default co-scholastic to oral only with 100 marks
                    $has_written = 0;
                    $written_marks = 0;
                    $has_oral = 1;
                    $oral_marks = $oral_marks ?: 100;
                }

                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO subjects (name, code, class_id, class_group, written_full_marks, oral_full_marks, 
                                            has_written, has_oral, description, subject_type, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    if ($stmt->execute([$name, $code, $class_id, $class_group, $written_marks, $oral_marks, 
                                        $has_written, $has_oral, $description, $subject_type, $_SESSION['user_id']])) {
                        logAudit($_SESSION['user_id'], 'INSERT', 'subjects', $pdo->lastInsertId());
                        $message = showAlert('Subject added successfully! Remember to set class-specific full marks if needed.', 'success');
                    } else {
                        $message = showAlert('Error adding subject!', 'danger');
                    }
                } catch (Exception $e) {
                    $message = showAlert('Error: ' . $e->getMessage(), 'danger');
                }
                break;

            case 'edit_subject':
                $id = (int)$_POST['id'];
                $name = sanitize($_POST['name']);
                $code = sanitize($_POST['code']);
                $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
                $subject_type = sanitize($_POST['subject_type']);
                $class_group = sanitize($_POST['class_group']);
                $has_written = isset($_POST['has_written']) ? 1 : 0;
                $has_oral = isset($_POST['has_oral']) ? 1 : 0;
                // These are now saved as DEFAULT marks in the subjects table
                $written_marks = $has_written ? (int)$_POST['written_full_marks'] : 0;
                $oral_marks = $has_oral ? (int)$_POST['oral_full_marks'] : 0;
                $description = sanitize($_POST['description'] ?? '');

                try {
                    $stmt = $pdo->prepare("
                        UPDATE subjects 
                        SET name = ?, code = ?, class_id = ?, class_group = ?, written_full_marks = ?, oral_full_marks = ?, 
                            has_written = ?, has_oral = ?, description = ?, subject_type = ?
                        WHERE id = ?
                    ");
                    
                    if ($stmt->execute([$name, $code, $class_id, $class_group, $written_marks, $oral_marks, 
                                        $has_written, $has_oral, $description, $subject_type, $id])) {
                        logAudit($_SESSION['user_id'], 'UPDATE', 'subjects', $id);
                        $message = showAlert('Subject updated successfully! Class-specific full marks (if set) remain unchanged.', 'success');
                    } else {
                        $message = showAlert('Error updating subject!', 'danger');
                    }
                } catch (Exception $e) {
                    $message = showAlert('Error: ' . $e->getMessage(), 'danger');
                }
                break;

            case 'delete_subject':
                $id = (int)$_POST['id'];
                
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM marks WHERE subject_id = ?");
                $stmt->execute([$id]);
                $marksCount = $stmt->fetchColumn();
                
                if ($marksCount > 0) {
                    $message = showAlert("Cannot delete subject! It has $marksCount marks entries.", 'danger');
                } else {
                    try {
                        // Delete class-specific marks first to prevent foreign key issues if one existed
                        $pdo->prepare("DELETE FROM subject_class_full_marks WHERE subject_id = ?")->execute([$id]);

                        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
                        if ($stmt->execute([$id])) {
                            logAudit($_SESSION['user_id'], 'DELETE', 'subjects', $id);
                            $message = showAlert('Subject deleted successfully!');
                        } else {
                            $message = showAlert('Error deleting subject!', 'danger');
                        }
                    } catch (Exception $e) {
                        $message = showAlert('Error deleting subject configuration: ' . $e->getMessage(), 'danger');
                    }
                }
                break;
        }
    }
}

// Build query with filters
$whereClause = "WHERE s.is_active = 1";
$params = [];

if ($selectedClass > 0) {
    // If filtering by class, show only subjects relevant to that class
    $whereClause .= " AND (s.class_id = ? OR s.class_id IS NULL OR s.class_group = 'all')";
    $params[] = $selectedClass;
}

if ($selectedType !== 'all') {
    $whereClause .= " AND s.subject_type = ?";
    $params[] = $selectedType;
}

if ($selectedGroup !== 'all') {
    $whereClause .= " AND s.class_group = ?";
    $params[] = $selectedGroup;
}

// Get subjects with class information. Note: This list still shows DEFAULT marks.
// Actual marks must be resolved at the input/report stage.
$stmt = $pdo->prepare("
    SELECT s.*, 
            s.class_group,
            c.name as class_name, 
            c.section,
            u.name as created_by_name,
            (SELECT COUNT(*) FROM marks WHERE subject_id = s.id) as marks_count
    FROM subjects s
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN users u ON s.created_by = u.id
    $whereClause
    ORDER BY s.class_group, s.subject_type, s.display_order, s.name
");
$stmt->execute($params);
$subjects = $stmt->fetchAll();

// Get all classes for dropdowns
$classes = getAllClasses();

// Get statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_subjects,
        SUM(CASE WHEN subject_type = 'scholastic' THEN 1 ELSE 0 END) as scholastic_subjects,
        SUM(CASE WHEN subject_type = 'co_scholastic' THEN 1 ELSE 0 END) as co_scholastic_subjects,
        SUM(CASE WHEN class_group = 'pre_school' THEN 1 ELSE 0 END) as preschool_subjects,
        SUM(CASE WHEN class_group = 'higher' THEN 1 ELSE 0 END) as higher_subjects,
        COUNT(DISTINCT class_id) as classes_with_subjects
    FROM subjects WHERE is_active = 1
")->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Manage Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .subject-card {
            transition: transform 0.2s ease;
            border-left: 4px solid #007bff;
        }
        .subject-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .subject-card.co-scholastic {
            border-left-color: #28a745;
        }
        .subject-card.scholastic {
            border-left-color: #007bff;
        }
        .type-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        .stats-card {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border-radius: 10px;
            text-align: center;
            padding: 15px;
        }
        .filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
        }
        .group-badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
        }
        .pre-school-badge {
            background: #ff6b6b;
            color: white;
        }
        .higher-badge {
            background: #4ecdc4;
            color: white;
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

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-book me-2"></i>Manage Subjects</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                <i class="fas fa-plus me-2"></i>Add Subject
            </button>
        </div>

        <?= $message ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="stats-card">
                    <h4><?= $stats['total_subjects'] ?: 0 ?></h4>
                    <small>Total Subjects</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card bg-primary">
                    <h4><?= $stats['scholastic_subjects'] ?: 0 ?></h4>
                    <small>Scholastic</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card bg-success">
                    <h4><?= $stats['co_scholastic_subjects'] ?: 0 ?></h4>
                    <small>Co-Scholastic</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card bg-danger">
                    <h4><?= $stats['preschool_subjects'] ?: 0 ?></h4>
                    <small>Pre-School</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card bg-info">
                    <h4><?= $stats['higher_subjects'] ?: 0 ?></h4>
                    <small>Higher Classes</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card bg-dark">
                    <h4><?= count($subjects) ?></h4>
                    <small>Filtered Results</small>
                </div>
            </div>
        </div>

        <!-- Enhanced Filter -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label text-white">Filter by Class Group</label>
                        <select class="form-select" name="group" onchange="this.form.submit()">
                            <option value="all" <?= $selectedGroup === 'all' ? 'selected' : '' ?>>All Groups</option>
                            <option value="pre_school" <?= $selectedGroup === 'pre_school' ? 'selected' : '' ?>>Pre-School (Nursery/Lower/Upper)</option>
                            <option value="higher" <?= $selectedGroup === 'higher' ? 'selected' : '' ?>>Higher Classes (1-10)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white">Filter by Type</label>
                        <select class="form-select" name="type" onchange="this.form.submit()">
                            <option value="all" <?= $selectedType === 'all' ? 'selected' : '' ?>>All Types</option>
                            <option value="scholastic" <?= $selectedType === 'scholastic' ? 'selected' : '' ?>>Scholastic Only</option>
                            <option value="co_scholastic" <?= $selectedType === 'co_scholastic' ? 'selected' : '' ?>>Co-Scholastic Only</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white">Filter by Class</label>
                        <select class="form-select" name="class_id" onchange="this.form.submit()">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                    <?= $class['name'] ?> - <?= $class['section'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-white">&nbsp;</label>
                        <div class="d-grid">
                            <button type="button" class="btn btn-outline-light" onclick="location.href='manage_subjects.php'">
                                <i class="fas fa-sync-alt me-1"></i>Reset Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Subjects List -->
        <div class="row">
            <?php if (empty($subjects)): ?>
                <div class="col-12">
                    <div class="card text-center py-5">
                        <div class="card-body">
                            <i class="fas fa-book fa-3x text-muted mb-3"></i>
                            <h5>No Subjects Found</h5>
                            <p class="text-muted">No subjects match your current filter criteria.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                                <i class="fas fa-plus me-2"></i>Add First Subject
                            </button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($subjects as $subject): ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="card subject-card h-100 <?= $subject['subject_type'] ?>">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0"><?= $subject['name'] ?></h6>
                                    <?php if ($subject['code']): ?>
                                        <small class="text-muted"><?= $subject['code'] ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <button class="dropdown-item edit-subject-btn" 
                                                    data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                                                    data-id="<?= $subject['id'] ?>"
                                                    data-name="<?= htmlspecialchars($subject['name']) ?>"
                                                    data-code="<?= htmlspecialchars($subject['code']) ?>"
                                                    data-class-id="<?= $subject['class_id'] ?>"
                                                    data-subject-type="<?= $subject['subject_type'] ?>"
                                                    data-class-group="<?= $subject['class_group'] ?>"
                                                    data-has-written="<?= $subject['has_written'] ?>"
                                                    data-has-oral="<?= $subject['has_oral'] ?>"
                                                    data-written-marks="<?= $subject['written_full_marks'] ?>"
                                                    data-oral-marks="<?= $subject['oral_full_marks'] ?>"
                                                    data-description="<?= htmlspecialchars($subject['description']) ?>">
                                                <i class="fas fa-edit me-2"></i>Edit Subject
                                            </button>
                                        </li>
                                        <li>
                                            <a href="manage_class_marks.php?subject_id=<?= $subject['id'] ?>" class="dropdown-item">
                                                <i class="fas fa-layer-group me-2"></i>Set Class Marks
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_subject">
                                                <input type="hidden" name="id" value="<?= $subject['id'] ?>">
                                                <button type="submit" class="dropdown-item text-danger" 
                                                        onclick="return confirm('Are you sure you want to delete this subject? This will also delete any class-specific mark configurations.')">
                                                    <i class="fas fa-trash me-2"></i>Delete
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            
                            <div class="card-body">
                                <div class="mb-2">
                                    <span class="badge bg-<?= $subject['subject_type'] === 'co_scholastic' ? 'success' : 'primary' ?> type-badge me-1">
                                        <?= $subject['subject_type'] === 'co_scholastic' ? 'Co-Scholastic' : 'Scholastic' ?>
                                    </span>
                                    
                                    <?php if ($subject['class_group'] === 'pre_school'): ?>
                                        <span class="badge pre-school-badge group-badge me-1">Pre-School</span>
                                    <?php elseif ($subject['class_group'] === 'higher'): ?>
                                        <span class="badge higher-badge group-badge me-1">Higher (1-10)</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($subject['class_name']): ?>
                                        <span class="badge bg-info type-badge">
                                            <?= $subject['class_name'] ?> - <?= $subject['section'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary type-badge">General</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <h6 class="small text-muted">DEFAULT Full Marks:</h6>
                                    <?php if ($subject['has_written']): ?>
                                        <small class="d-block">✓ Written: <strong><?= $subject['written_full_marks'] ?></strong> marks</small>
                                    <?php endif; ?>
                                    <?php if ($subject['has_oral']): ?>
                                        <small class="d-block">✓ Oral/Practical: <strong><?= $subject['oral_full_marks'] ?></strong> marks</small>
                                    <?php endif; ?>
                                    <p class="small text-danger mt-1">
                                        <i class="fas fa-exclamation-triangle me-1"></i> These marks can be overridden for specific classes.
                                    </p>
                                </div>
                                
                                <?php if ($subject['description']): ?>
                                    <p class="small text-muted"><?= substr($subject['description'], 0, 100) ?><?= strlen($subject['description']) > 100 ? '...' : '' ?></p>
                                <?php endif; ?>
                                
                                <?php if ($subject['marks_count'] > 0): ?>
                                    <div class="alert alert-info py-2">
                                        <small><i class="fas fa-chart-bar me-1"></i><?= $subject['marks_count'] ?> marks entries</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal fade" id="addSubjectModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Subject (Set Default Full Marks)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_subject">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Subject Name *</label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Subject Code</label>
                                    <input type="text" class="form-control" name="code">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Subject Type *</label>
                                    <select class="form-select" name="subject_type" id="add_subject_type" required>
                                        <option value="scholastic">Scholastic (Academic)</option>
                                        <option value="co_scholastic">Co-Scholastic (Skills/Activities)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Class Group *</label>
                                    <select class="form-select" name="class_group" id="add_class_group" required>
                                        <option value="all">All Classes</option>
                                        <option value="pre_school">Pre-School (Nursery/Lower/Upper)</option>
                                        <option value="higher">Higher Classes (1-10)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Assign to Specific Class</label>
                                    <select class="form-select" name="class_id">
                                        <option value="">General (All in Group)</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?= $class['id'] ?>">
                                                <?= $class['name'] ?> - <?= $class['section'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="assessment-config" style="border: 2px dashed #dee2e6; border-radius: 10px; padding: 15px; background: #f8f9fa;">
                            <h6><i class="fas fa-cog me-2"></i>Default Assessment Configuration (Overridable per class)</h6>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="has_written" id="add_has_written" checked>
                                        <label class="form-check-label" for="add_has_written">
                                            <strong>Written Assessment</strong>
                                        </label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Written Default Full Marks</label>
                                        <input type="number" class="form-control" name="written_full_marks" value="80" min="0" max="1000">
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="has_oral" id="add_has_oral" checked>
                                        <label class="form-check-label" for="add_has_oral">
                                            <strong>Oral Assessment</strong>
                                        </label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Oral Default Full Marks</label>
                                        <input type="number" class="form-control" name="oral_full_marks" value="20" min="0" max="1000">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal fade" id="editSubjectModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Subject (Editing Default Full Marks Only)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_subject">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Subject Name *</label>
                                    <input type="text" class="form-control" name="name" id="edit_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Subject Code</label>
                                    <input type="text" class="form-control" name="code" id="edit_code">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Subject Type *</label>
                                    <select class="form-select" name="subject_type" id="edit_subject_type" required>
                                        <option value="scholastic">Scholastic (Academic)</option>
                                        <option value="co_scholastic">Co-Scholastic (Skills/Activities)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Class Group *</label>
                                    <select class="form-select" name="class_group" id="edit_class_group" required>
                                        <option value="all">All Classes</option>
                                        <option value="pre_school">Pre-School (Nursery/Lower/Upper)</option>
                                        <option value="higher">Higher Classes (1-10)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Assign to Specific Class</label>
                                    <select class="form-select" name="class_id" id="edit_class_id">
                                        <option value="">General (All in Group)</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?= $class['id'] ?>">
                                                <?= $class['name'] ?> - <?= $class['section'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="assessment-config" style="border: 2px dashed #dee2e6; border-radius: 10px; padding: 15px; background: #f8f9fa;">
                            <h6><i class="fas fa-cog me-2"></i>Default Assessment Configuration (Use the 'Set Class Marks' button to override)</h6>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="has_written" id="edit_has_written">
                                        <label class="form-check-label" for="edit_has_written">
                                            <strong>Written Assessment</strong>
                                        </label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Written Default Full Marks</label>
                                        <input type="number" class="form-control" name="written_full_marks" id="edit_written_marks" min="0" max="1000">
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="has_oral" id="edit_has_oral">
                                        <label class="form-check-label" for="edit_has_oral">
                                            <strong>Oral Assessment</strong>
                                        </label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Oral Default Full Marks</label>
                                        <input type="number" class="form-control" name="oral_full_marks" id="edit_oral_marks" min="0" max="1000">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit subject functionality
        document.querySelectorAll('.edit-subject-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_name').value = this.dataset.name;
                document.getElementById('edit_code').value = this.dataset.code;
                document.getElementById('edit_class_id').value = this.dataset.classId || '';
                document.getElementById('edit_subject_type').value = this.dataset.subjectType;
                document.getElementById('edit_class_group').value = this.dataset.classGroup || 'all';
                document.getElementById('edit_has_written').checked = this.dataset.hasWritten == '1';
                document.getElementById('edit_has_oral').checked = this.dataset.hasOral == '1';
                document.getElementById('edit_written_marks').value = this.dataset.writtenMarks;
                document.getElementById('edit_oral_marks').value = this.dataset.oralMarks;
                document.getElementById('edit_description').value = this.dataset.description;
            });
        });

        // Subject type change handler for default values
        document.getElementById('add_subject_type').addEventListener('change', function() {
            // Find inputs within the add modal only
            const writtenInput = document.querySelector('#addSubjectModal input[name="written_full_marks"]');
            const oralInput = document.querySelector('#addSubjectModal input[name="oral_full_marks"]');
            const hasWrittenCheck = document.getElementById('add_has_written');
            const hasOralCheck = document.getElementById('add_has_oral');
            
            if (this.value === 'co_scholastic') {
                // Set defaults for co-scholastic
                hasWrittenCheck.checked = false;
                hasOralCheck.checked = true;
                writtenInput.value = 0;
                oralInput.value = 100;
            } else {
                // Set defaults for scholastic
                hasWrittenCheck.checked = true;
                hasOralCheck.checked = true;
                writtenInput.value = 80;
                oralInput.value = 20;
            }
        });
        
        // Class group change handler for default values
        document.getElementById('add_class_group').addEventListener('change', function() {
            // Find inputs within the add modal only
            const writtenInput = document.querySelector('#addSubjectModal input[name="written_full_marks"]');
            const oralInput = document.querySelector('#addSubjectModal input[name="oral_full_marks"]');

            if (this.value === 'pre_school' && document.getElementById('add_subject_type').value !== 'co_scholastic') {
                // Pre-school scholastic subjects might have lower marks
                writtenInput.value = 50;
                oralInput.value = 50;
            } else if (this.value === 'higher' && document.getElementById('add_subject_type').value !== 'co_scholastic') {
                // Higher classes have standard marks
                writtenInput.value = 80;
                oralInput.value = 20;
            }
        });
    </script>
</body>
</html>
