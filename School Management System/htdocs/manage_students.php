<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';

// Check if the enhanced columns exist by looking for one of the last ones to be added.
$columnsExist = checkStudentsTableColumns();

function checkStudentsTableColumns() {
    global $pdo;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'session_year'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $student_id = sanitize($_POST['student_id']);
                    $name = sanitize($_POST['name']);
                    $class_id = (int)$_POST['class_id'];
                    $roll_number = sanitize($_POST['roll_number']);
                    $phone = sanitize($_POST['phone']);
                    $email = sanitize($_POST['email']);
                    $address = sanitize($_POST['address']);

                    if ($columnsExist) {
                        $admission_number = sanitize($_POST['admission_number'] ?? '');
                        $date_of_birth = !empty($_POST['date_of_birth']) ? sanitize($_POST['date_of_birth']) : null;
                        $admission_date = !empty($_POST['admission_date']) ? sanitize($_POST['admission_date']) : date('Y-m-d');
                        $session_year = sanitize($_POST['session_year'] ?? '');
                        $guardian_name = sanitize($_POST['guardian_name'] ?? '');
                        $guardian_phone = sanitize($_POST['guardian_phone'] ?? '');
                        $father_name = sanitize($_POST['father_name'] ?? '');
                        $mother_name = sanitize($_POST['mother_name'] ?? '');

                        $stmt = $pdo->prepare(
                            "INSERT INTO students (student_id, admission_number, name, class_id, roll_number, phone, email, address, date_of_birth, admission_date, session_year, guardian_name, guardian_phone, father_name, mother_name) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $success = $stmt->execute([$student_id, $admission_number, $name, $class_id, $roll_number, $phone, $email, $address, $date_of_birth, $admission_date, $session_year, $guardian_name, $guardian_phone, $father_name, $mother_name]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO students (student_id, name, class_id, roll_number, phone, email, address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $success = $stmt->execute([$student_id, $name, $class_id, $roll_number, $phone, $email, $address]);
                    }

                    if ($success) {
                        logAudit($_SESSION['user_id'], 'INSERT', 'students', $pdo->lastInsertId());
                        $message = showAlert('Student added successfully!');
                    } else {
                        $message = showAlert('Error adding student!', 'danger');
                    }
                } catch (PDOException $e) {
                    $message = showAlert('Database Error: ' . $e->getMessage(), 'danger');
                }
                break;

            case 'edit':
                try {
                    $id = (int)$_POST['id'];
                    $student_id = sanitize($_POST['student_id']);
                    $name = sanitize($_POST['name']);
                    $class_id = (int)$_POST['class_id'];
                    $roll_number = sanitize($_POST['roll_number']);
                    $phone = sanitize($_POST['phone']);
                    $email = sanitize($_POST['email']);
                    $address = sanitize($_POST['address']);

                    if ($columnsExist) {
                        $admission_number = sanitize($_POST['admission_number'] ?? '');
                        $date_of_birth = !empty($_POST['date_of_birth']) ? sanitize($_POST['date_of_birth']) : null;
                        $admission_date = !empty($_POST['admission_date']) ? sanitize($_POST['admission_date']) : null;
                        $session_year = sanitize($_POST['session_year'] ?? '');
                        $guardian_name = sanitize($_POST['guardian_name'] ?? '');
                        $guardian_phone = sanitize($_POST['guardian_phone'] ?? '');
                        $father_name = sanitize($_POST['father_name'] ?? '');
                        $mother_name = sanitize($_POST['mother_name'] ?? '');

                        $stmt = $pdo->prepare(
                            "UPDATE students SET student_id = ?, admission_number = ?, name = ?, class_id = ?, roll_number = ?, phone = ?, email = ?, address = ?, 
                             date_of_birth = ?, admission_date = ?, session_year = ?, guardian_name = ?, guardian_phone = ?, father_name = ?, mother_name = ? WHERE id = ?"
                        );
                        $success = $stmt->execute([$student_id, $admission_number, $name, $class_id, $roll_number, $phone, $email, $address, $date_of_birth, $admission_date, $session_year, $guardian_name, $guardian_phone, $father_name, $mother_name, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE students SET student_id = ?, name = ?, class_id = ?, roll_number = ?, phone = ?, email = ?, address = ? WHERE id = ?");
                        $success = $stmt->execute([$student_id, $name, $class_id, $roll_number, $phone, $email, $address, $id]);
                    }

                    if ($success) {
                        logAudit($_SESSION['user_id'], 'UPDATE', 'students', $id);
                        $message = showAlert('Student updated successfully!');
                    } else {
                        $message = showAlert('Error updating student!', 'danger');
                    }
                } catch (PDOException $e) {
                    $message = showAlert('Database Error: ' . $e->getMessage(), 'danger');
                }
                break;

            case 'delete':
                $id = (int)$_POST['id'];
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                if ($stmt->execute([$id])) {
                    logAudit($_SESSION['user_id'], 'DELETE', 'students', $id);
                    $message = showAlert('Student deleted successfully!');
                } else {
                    $message = showAlert('Error deleting student!', 'danger');
                }
                break;

            case 'bulk_delete':
                if (!empty($_POST['selected_students'])) {
                    $student_ids = array_map('intval', $_POST['selected_students']);
                    $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
                    
                    $stmt = $pdo->prepare("DELETE FROM students WHERE id IN ($placeholders)");
                    if ($stmt->execute($student_ids)) {
                        $count = count($student_ids);
                        logAudit($_SESSION['user_id'], 'BULK_DELETE', 'students', 0, '', "Deleted $count students");
                        $message = showAlert("Successfully deleted $count students!");
                    } else {
                        $message = showAlert('Error deleting selected students!', 'danger');
                    }
                } else {
                    $message = showAlert('No students selected for deletion!', 'warning');
                }
                break;

            case 'bulk_transfer':
                if (!empty($_POST['selected_students']) && !empty($_POST['new_class_id'])) {
                    $student_ids = array_map('intval', $_POST['selected_students']);
                    $new_class_id = (int)$_POST['new_class_id'];
                    $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
                    
                    $stmt = $pdo->prepare("UPDATE students SET class_id = ? WHERE id IN ($placeholders)");
                    $params = array_merge([$new_class_id], $student_ids);
                    
                    if ($stmt->execute($params)) {
                        $count = count($student_ids);
                        logAudit($_SESSION['user_id'], 'BULK_TRANSFER', 'students', 0, '', "Transferred $count students to class $new_class_id");
                        $message = showAlert("Successfully transferred $count students to new class!");
                    } else {
                        $message = showAlert('Error transferring selected students!', 'danger');
                    }
                } else {
                    $message = showAlert('Please select students and target class!', 'warning');
                }
                break;

            case 'bulk_upload':
                if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
                    $uploadResult = processBulkUpload($_FILES['excel_file'], (int)$_POST['upload_class_id']);
                    $message = $uploadResult['message'];
                } else {
                    $message = showAlert('Please select a valid Excel file!', 'danger');
                }
                break;

            case 'update_database':
                // Add missing columns to database
                $updateResult = updateStudentsTable();
                $message = $updateResult['message'];
                if ($updateResult['success']) {
                    $columnsExist = true;
                    echo "<script>window.location.reload();</script>";
                }
                break;
        }
    }
}

function updateStudentsTable() {
    global $pdo;
    try {
        $pdo->beginTransaction();
        
        // Add missing columns
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS admission_number VARCHAR(50) NULL AFTER student_id");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL AFTER address");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS admission_date DATE NULL DEFAULT (CURRENT_DATE) AFTER date_of_birth");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS session_year VARCHAR(10) NULL AFTER admission_date");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS guardian_name VARCHAR(100) NULL AFTER session_year");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS guardian_phone VARCHAR(20) NULL AFTER guardian_name");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS father_name VARCHAR(100) NULL AFTER guardian_phone");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS mother_name VARCHAR(100) NULL AFTER father_name");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Inactive', 'Transferred') DEFAULT 'Active' AFTER mother_name");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");
        $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => showAlert('Database updated successfully! Enhanced features are now available.')
        ];
    } catch (Exception $e) {
        $pdo->rollback();
        return [
            'success' => false,
            'message' => showAlert('Error updating database: ' . $e->getMessage(), 'danger')
        ];
    }
}

function processBulkUpload($file, $class_id) {
    global $pdo, $columnsExist;
    
    try {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('Could not read file');
        }
        
        fgetcsv($handle); // Skip header row
        
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        $row_number = 2;
        
        $pdo->beginTransaction();
        
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) continue;
            
            $student_id = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            
            if (empty($student_id) || empty($name)) {
                $errors[] = "Row $row_number: Student ID and Name are required";
                $error_count++;
                $row_number++;
                continue;
            }
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Row $row_number: Student ID '$student_id' already exists";
                $error_count++;
                $row_number++;
                continue;
            }
            
            $roll_number = trim($row[2] ?? '');
            $phone = trim($row[3] ?? '');
            $email = trim($row[4] ?? '');
            $address = trim($row[5] ?? '');

            if ($columnsExist) {
                $admission_number = trim($row[6] ?? '');
                $date_of_birth = !empty($row[7]) ? date('Y-m-d', strtotime($row[7])) : null;
                $session_year = trim($row[8] ?? '');
                $father_name = trim($row[9] ?? '');
                $mother_name = trim($row[10] ?? '');
                
                $stmt = $pdo->prepare(
                   "INSERT INTO students (student_id, name, class_id, roll_number, phone, email, address, admission_number, date_of_birth, session_year, father_name, mother_name, admission_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $success = $stmt->execute([$student_id, $name, $class_id, $roll_number, $phone, $email, $address, $admission_number, $date_of_birth, $session_year, $father_name, $mother_name]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO students (student_id, name, class_id, roll_number, phone, email, address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $success = $stmt->execute([$student_id, $name, $class_id, $roll_number, $phone, $email, $address]);
            }
            
            if ($success) {
                $success_count++;
            } else {
                $errors[] = "Row $row_number: Database error for student '$name'";
                $error_count++;
            }
            
            $row_number++;
        }
        
        fclose($handle);
        $pdo->commit();
        
        $message_text = "<h5>Bulk Upload Results:</h5>";
        $message_text .= "<ul><li><strong>Successfully imported:</strong> $success_count students</li>";
        if ($error_count > 0) {
            $message_text .= "<li><strong>Errors:</strong> $error_count</li>";
        }
        $message_text .= "</ul>";
        
        if (!empty($errors)) {
            $message_text .= "<div class='mt-3'><strong>Error Details:</strong><ul>";
            foreach (array_slice($errors, 0, 10) as $error) {
                $message_text .= "<li>$error</li>";
            }
            if (count($errors) > 10) {
                $message_text .= "<li>... and " . (count($errors) - 10) . " more errors</li>";
            }
            $message_text .= "</ul></div>";
        }
        
        logAudit($_SESSION['user_id'], 'BULK_UPLOAD', 'students', 0, '', "Uploaded $success_count students, $error_count errors");
        
        return [
            'success' => $success_count > 0,
            'message' => showAlert($message_text, $success_count > 0 ? 'success' : 'danger')
        ];
        
    } catch (Exception $e) {
        if (isset($handle) && is_resource($handle)) fclose($handle);
        if ($pdo->inTransaction()) $pdo->rollback();
        return [
            'success' => false,
            'message' => showAlert('Error processing file: ' . $e->getMessage(), 'danger')
        ];
    }
}

// Handle filtering and sorting
$whereClause = "WHERE 1=1";
$params = [];
$search = '';
$classFilter = '';
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'ASC';

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = sanitize($_GET['search']);
    $whereClause .= " AND (s.name LIKE ? OR s.student_id LIKE ? OR s.admission_number LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
    $params = array_fill(0, 5, "%$search%");
}

if (isset($_GET['class']) && !empty($_GET['class'])) {
    $classFilter = (int)$_GET['class'];
    $whereClause .= " AND s.class_id = ?";
    $params[] = $classFilter;
}

// Validate sort parameters
$allowedSorts = ['name', 'student_id', 'class_name', 'roll_number'];
if ($columnsExist) {
    $allowedSorts[] = 'admission_date';
    $allowedSorts[] = 'admission_number';
    $allowedSorts[] = 'session_year';
    $allowedSorts[] = 'date_of_birth';
}
$sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'name';
$sortOrder = in_array(strtoupper($sortOrder), ['ASC', 'DESC']) ? $sortOrder : 'ASC';

// Build the select query
$selectColumns = "s.*, c.name as class_name, c.section";

// Get students with filtering and sorting
$query = "SELECT $selectColumns FROM students s 
          LEFT JOIN classes c ON s.class_id = c.id 
          $whereClause 
          ORDER BY $sortBy $sortOrder";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get all classes for dropdowns
$classes = getAllClasses();

// Get statistics
if ($columnsExist) {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_students,
            COUNT(DISTINCT class_id) as classes_with_students,
            AVG(CASE WHEN date_of_birth IS NOT NULL THEN YEAR(CURDATE()) - YEAR(date_of_birth) ELSE NULL END) as avg_age
        FROM students
    ")->fetch();
} else {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_students,
            COUNT(DISTINCT class_id) as classes_with_students,
            NULL as avg_age
        FROM students
    ")->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Manage Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
        }
        .stats-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            text-align: center;
            padding: 20px;
        }
        .bulk-actions {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border: 2px dashed #dee2e6;
        }
        .student-row.selected {
            background-color: #e3f2fd !important;
        }
        .sortable-header {
            cursor: pointer;
            user-select: none;
        }
        .sortable-header:hover {
            background-color: rgba(0,0,0,0.1);
        }
        .upgrade-notice {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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
            <h2><i class="fas fa-user-graduate me-2"></i>Manage Students</h2>
            <div class="btn-group">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                    <i class="fas fa-plus me-2"></i>Add Student
                </button>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkUploadModal">
                    <i class="fas fa-upload me-2"></i>Bulk Upload
                </button>
                <button class="btn btn-info" onclick="downloadTemplate()">
                    <i class="fas fa-download me-2"></i>CSV Template
                </button>
            </div>
        </div>

        <?= $message ?>

        <!-- Database Update Notice -->
        <?php if (!$columnsExist): ?>
            <div class="upgrade-notice">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5><i class="fas fa-database me-2"></i>Enhanced Features Available!</h5>
                        <p class="mb-0">
                            Your database can be upgraded to include additional student fields like Admission Number, Father's Name,
                            Mother's Name, Session Year, and more advanced features.
                        </p>
                    </div>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="update_database">
                        <button type="submit" class="btn btn-light" 
                                onclick="return confirm('This will add new columns to your students table. Continue?')">
                            <i class="fas fa-sync-alt me-2"></i>Upgrade Database
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card">
                    <h3><?= $stats['total_students'] ?: 0 ?></h3>
                    <p>Total Students</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3><?= $stats['classes_with_students'] ?: 0 ?></h3>
                    <p>Active Classes</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <h3><?= $stats['avg_age'] ? number_format($stats['avg_age'], 1) : 'N/A' ?></h3>
                    <p>Average Age</p>
                </div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <h5 class="text-white mb-3"><i class="fas fa-filter me-2"></i>Filters & Search</h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label text-white">Search Students</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Search by name, ID, admission no, phone...">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label text-white">Filter by Class</label>
                        <select class="form-select" name="class">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $classFilter == $class['id'] ? 'selected' : '' ?>>
                                    <?= $class['name'] ?> - <?= $class['section'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label text-white">Sort By</label>
                        <select class="form-select" name="sort">
                            <option value="name" <?= $sortBy == 'name' ? 'selected' : '' ?>>Name</option>
                            <option value="student_id" <?= $sortBy == 'student_id' ? 'selected' : '' ?>>Student ID</option>
                            <?php if ($columnsExist): ?>
                                <option value="admission_number" <?= $sortBy == 'admission_number' ? 'selected' : '' ?>>Admission No.</option>
                                <option value="session_year" <?= $sortBy == 'session_year' ? 'selected' : '' ?>>Session</option>
                            <?php endif; ?>
                            <option value="class_name" <?= $sortBy == 'class_name' ? 'selected' : '' ?>>Class</option>
                            <option value="roll_number" <?= $sortBy == 'roll_number' ? 'selected' : '' ?>>Roll Number</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label text-white">Order</label>
                        <select class="form-select" name="order">
                            <option value="ASC" <?= $sortOrder == 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            <option value="DESC" <?= $sortOrder == 'DESC' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    
                    <div class="col-md-1">
                        <label class="form-label text-white">&nbsp;</label>
                        <button type="submit" class="btn btn-light w-100">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
                
                <?php if (!empty($search) || !empty($classFilter)): ?>
                    <div class="mt-3">
                        <a href="?" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-times me-1"></i>Clear Filters
                        </a>
                        <span class="text-white-50 ms-3">
                            Showing <?= count($students) ?> students
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions mb-4" id="bulkActions" style="display: none;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong><span id="selectedCount">0</span> students selected</strong>
                </div>
                <div class="btn-group">
                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#bulkTransferModal">
                        <i class="fas fa-exchange-alt me-1"></i>Transfer Class
                    </button>
                    <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">
                        <i class="fas fa-trash me-1"></i>Delete Selected
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="clearSelection()">
                        <i class="fas fa-times me-1"></i>Clear Selection
                    </button>
                </div>
            </div>
        </div>

        <!-- Students Table -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="selectAllHeader" class="form-check-input">
                                </th>
                                <th class="sortable-header" onclick="sortTable('student_id')">Student ID <?php if ($sortBy == 'student_id'): ?><i class="fas fa-sort-<?= strtolower($sortOrder) == 'asc' ? 'up' : 'down' ?>"></i><?php endif; ?></th>
                                <?php if ($columnsExist): ?>
                                <th class="sortable-header" onclick="sortTable('admission_number')">Admission No. <?php if ($sortBy == 'admission_number'): ?><i class="fas fa-sort-<?= strtolower($sortOrder) == 'asc' ? 'up' : 'down' ?>"></i><?php endif; ?></th>
                                <?php endif; ?>
                                <th class="sortable-header" onclick="sortTable('name')">Name <?php if ($sortBy == 'name'): ?><i class="fas fa-sort-<?= strtolower($sortOrder) == 'asc' ? 'up' : 'down' ?>"></i><?php endif; ?></th>
                                <th class="sortable-header" onclick="sortTable('class_name')">Class <?php if ($sortBy == 'class_name'): ?><i class="fas fa-sort-<?= strtolower($sortOrder) == 'asc' ? 'up' : 'down' ?>"></i><?php endif; ?></th>
                                <th class="sortable-header" onclick="sortTable('roll_number')">Roll No. <?php if ($sortBy == 'roll_number'): ?><i class="fas fa-sort-<?= strtolower($sortOrder) == 'asc' ? 'up' : 'down' ?>"></i><?php endif; ?></th>
                                <?php if ($columnsExist): ?>
                                <th class="sortable-header" onclick="sortTable('session_year')">Session <?php if ($sortBy == 'session_year'): ?><i class="fas fa-sort-<?= strtolower($sortOrder) == 'asc' ? 'up' : 'down' ?>"></i><?php endif; ?></th>
                                <th>Age</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="<?= $columnsExist ? '10' : '7' ?>" class="text-center text-muted py-4">
                                        <i class="fas fa-user-slash fa-3x mb-3"></i>
                                        <br>No students found matching your criteria
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr class="student-row">
                                        <td>
                                            <input type="checkbox" class="form-check-input student-checkbox" 
                                                   value="<?= $student['id'] ?>">
                                        </td>
                                        <td><strong><?= htmlspecialchars($student['student_id']) ?></strong></td>
                                        <?php if ($columnsExist): ?>
                                        <td><?= htmlspecialchars($student['admission_number'] ?? '-') ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-placeholder bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2"
                                                     style="width: 35px; height: 35px; font-size: 14px;">
                                                    <?= strtoupper(substr($student['name'], 0, 2)) ?>
                                                </div>
                                                <div>
                                                    <strong><?= htmlspecialchars($student['name']) ?></strong>
                                                    <?php if ($student['email']): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($student['email']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?= htmlspecialchars($student['class_name']) ?><?= $student['section'] ? ' - ' . htmlspecialchars($student['section']) : '' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($student['roll_number'] ?: '-') ?></td>
                                        <?php if ($columnsExist): ?>
                                        <td><?= htmlspecialchars($student['session_year'] ?? '-') ?></td>
                                        <td>
                                            <?php if (isset($student['date_of_birth']) && $student['date_of_birth']): ?>
                                                <?= date_diff(date_create($student['date_of_birth']), date_create())->y ?> yrs
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-outline-primary edit-btn" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editStudentModal"
                                                        data-id="<?= $student['id'] ?>"
                                                        data-student-id="<?= htmlspecialchars($student['student_id']) ?>"
                                                        data-name="<?= htmlspecialchars($student['name']) ?>"
                                                        data-class-id="<?= $student['class_id'] ?>"
                                                        data-roll-number="<?= htmlspecialchars($student['roll_number']) ?>"
                                                        data-phone="<?= htmlspecialchars($student['phone']) ?>"
                                                        data-email="<?= htmlspecialchars($student['email']) ?>"
                                                        data-address="<?= htmlspecialchars($student['address']) ?>"
                                                        <?php if ($columnsExist): ?>
                                                        data-admission-number="<?= htmlspecialchars($student['admission_number'] ?? '') ?>"
                                                        data-date-of-birth="<?= $student['date_of_birth'] ?? '' ?>"
                                                        data-admission-date="<?= $student['admission_date'] ?? '' ?>"
                                                        data-session-year="<?= htmlspecialchars($student['session_year'] ?? '') ?>"
                                                        data-guardian-name="<?= htmlspecialchars($student['guardian_name'] ?? '') ?>"
                                                        data-guardian-phone="<?= htmlspecialchars($student['guardian_phone'] ?? '') ?>"
                                                        data-father-name="<?= htmlspecialchars($student['father_name'] ?? '') ?>"
                                                        data-mother-name="<?= htmlspecialchars($student['mother_name'] ?? '') ?>"
                                                        <?php endif; ?>>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger delete-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deleteStudentModal"
                                                        data-id="<?= $student['id'] ?>"
                                                        data-name="<?= htmlspecialchars($student['name']) ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Student</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Student ID *</label><input type="text" class="form-control" name="student_id" required></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Full Name *</label><input type="text" class="form-control" name="name" required></div></div>
                        </div>
                        
                        <?php if ($columnsExist): ?>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Admission Number</label><input type="text" class="form-control" name="admission_number"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Session Year</label>
                                <select class="form-select" name="session_year">
                                    <?php $currentYear = date('Y'); for ($i = $currentYear - 2; $i <= $currentYear + 2; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i == $currentYear ? 'selected' : '' ?>><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div></div>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Class *</label>
                                <select class="form-select" name="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>"><?= $class['name'] ?> - <?= $class['section'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Roll Number</label><input type="text" class="form-control" name="roll_number"></div></div>
                        </div>
                        
                        <?php if ($columnsExist): ?>
                        <div class="row">
                             <div class="col-md-6"><div class="mb-3"><label class="form-label">Father's Name</label><input type="text" class="form-control" name="father_name"></div></div>
                             <div class="col-md-6"><div class="mb-3"><label class="form-label">Mother's Name</label><input type="text" class="form-control" name="mother_name"></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Guardian Name</label><input type="text" class="form-control" name="guardian_name"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Guardian Phone</label><input type="text" class="form-control" name="guardian_phone"></div></div>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div></div>
                        </div>
                        
                        <?php if ($columnsExist): ?>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="date_of_birth"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Admission Date</label><input type="date" class="form-control" name="admission_date" value="<?= date('Y-m-d') ?>"></div></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="3"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                     <div class="modal-header">
                        <h5 class="modal-title">Edit Student</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Student ID *</label><input type="text" class="form-control" name="student_id" id="edit_student_id" required></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Full Name *</label><input type="text" class="form-control" name="name" id="edit_name" required></div></div>
                        </div>
                        
                        <?php if ($columnsExist): ?>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Admission Number</label><input type="text" class="form-control" name="admission_number" id="edit_admission_number"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Session Year</label>
                                <select class="form-select" name="session_year" id="edit_session_year">
                                    <?php $currentYear = date('Y'); for ($i = $currentYear - 2; $i <= $currentYear + 2; $i++): ?>
                                    <option value="<?= $i ?>"><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Class *</label>
                                <select class="form-select" name="class_id" id="edit_class_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>"><?= $class['name'] ?> - <?= $class['section'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Roll Number</label><input type="text" class="form-control" name="roll_number" id="edit_roll_number"></div></div>
                        </div>

                        <?php if ($columnsExist): ?>
                        <div class="row">
                             <div class="col-md-6"><div class="mb-3"><label class="form-label">Father's Name</label><input type="text" class="form-control" name="father_name" id="edit_father_name"></div></div>
                             <div class="col-md-6"><div class="mb-3"><label class="form-label">Mother's Name</label><input type="text" class="form-control" name="mother_name" id="edit_mother_name"></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Guardian Name</label><input type="text" class="form-control" name="guardian_name" id="edit_guardian_name"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Guardian Phone</label><input type="text" class="form-control" name="guardian_phone" id="edit_guardian_phone"></div></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone" id="edit_phone"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="edit_email"></div></div>
                        </div>
                        
                        <?php if ($columnsExist): ?>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Date of Birth</label><input type="date" class="form-control" name="date_of_birth" id="edit_date_of_birth"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Admission Date</label><input type="date" class="form-control" name="admission_date" id="edit_admission_date"></div></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" id="edit_address" rows="3"></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Other Modals (Delete, Bulk Upload, Transfer, Delete) remain the same... -->
    <!-- Delete Student Modal -->
    <div class="modal fade" id="deleteStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header"><h5 class="modal-title">Delete Student</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete_id">
                        <p>Are you sure you want to delete <strong id="delete_name"></strong>?</p>
                        <p class="text-danger">This action cannot be undone!</p>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Delete</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Upload Modal -->
    <div class="modal fade" id="bulkUploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header"><h5 class="modal-title">Bulk Upload Students</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="bulk_upload">
                        <div class="mb-3"><label class="form-label">Select Class *</label>
                            <select class="form-select" name="upload_class_id" required>
                                <option value="">Choose Class for Upload</option>
                                <?php foreach ($classes as $class): ?><option value="<?= $class['id'] ?>"><?= $class['name'] ?> - <?= $class['section'] ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3"><label class="form-label">CSV File *</label><input type="file" class="form-control" name="excel_file" accept=".csv" required></div>
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>CSV Format Requirements:</h6>
                            <p><strong>Column Order:</strong></p>
                            <ol class="mb-0">
                                <li>Student ID (Required)</li>
                                <li>Full Name (Required)</li>
                                <li>Roll Number</li>
                                <li>Phone</li>
                                <li>Email</li>
                                <li>Address</li>
                                <?php if ($columnsExist): ?>
                                <li>Admission Number</li>
                                <li>Date of Birth (YYYY-MM-DD)</li>
                                <li>Session Year (e.g., 2025)</li>
                                <li>Father's Name</li>
                                <li>Mother's Name</li>
                                <?php endif; ?>
                            </ol>
                        </div>
                        <div class="text-center"><button type="button" class="btn btn-outline-primary" onclick="downloadTemplate()"><i class="fas fa-download me-2"></i>Download CSV Template</button></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Upload Students</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Transfer Modal -->
    <div class="modal fade" id="bulkTransferModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header"><h5 class="modal-title">Transfer Selected Students</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="bulk_transfer">
                        <div class="mb-3"><label class="form-label">Transfer to Class</label>
                            <select class="form-select" name="new_class_id" required>
                                <option value="">Select Target Class</option>
                                <?php foreach ($classes as $class): ?><option value="<?= $class['id'] ?>"><?= $class['name'] ?> - <?= $class['section'] ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-warning"><p><strong>Selected Students:</strong> <span id="transferStudentCount">0</span></p><p class="mb-0">This action will move all selected students to the new class.</p></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Transfer Students</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Delete Modal -->
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header"><h5 class="modal-title">Delete Selected Students</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="bulk_delete">
                        <div class="alert alert-danger"><h6><i class="fas fa-exclamation-triangle me-2"></i>Warning!</h6><p><strong>Selected Students:</strong> <span id="deleteStudentCount">0</span></p><p class="mb-0">This action cannot be undone. All selected students will be permanently deleted.</p></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Delete Students</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const columnsExist = <?= json_encode($columnsExist) ?>;

        // Edit button functionality
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('edit_id').value = this.dataset.id;
                document.getElementById('edit_student_id').value = this.dataset.studentId;
                document.getElementById('edit_name').value = this.dataset.name;
                document.getElementById('edit_class_id').value = this.dataset.classId;
                document.getElementById('edit_roll_number').value = this.dataset.rollNumber;
                document.getElementById('edit_phone').value = this.dataset.phone;
                document.getElementById('edit_email').value = this.dataset.email;
                document.getElementById('edit_address').value = this.dataset.address;
                
                if (columnsExist) {
                    document.getElementById('edit_admission_number').value = this.dataset.admissionNumber || '';
                    document.getElementById('edit_date_of_birth').value = this.dataset.dateOfBirth || '';
                    document.getElementById('edit_admission_date').value = this.dataset.admissionDate || '';
                    document.getElementById('edit_session_year').value = this.dataset.sessionYear || '';
                    document.getElementById('edit_guardian_name').value = this.dataset.guardianName || '';
                    document.getElementById('edit_guardian_phone').value = this.dataset.guardianPhone || '';
                    document.getElementById('edit_father_name').value = this.dataset.fatherName || '';
                    document.getElementById('edit_mother_name').value = this.dataset.motherName || '';
                }
            });
        });

        // Delete button functionality
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('delete_id').value = this.dataset.id;
                document.getElementById('delete_name').textContent = this.dataset.name;
            });
        });

        // Bulk selection functionality
        let selectedStudents = [];

        function updateSelectedCount() {
            const count = selectedStudents.length;
            document.getElementById('selectedCount').textContent = count;
            document.getElementById('bulkActions').style.display = count > 0 ? 'block' : 'none';
            document.getElementById('transferStudentCount').textContent = count;
            document.getElementById('deleteStudentCount').textContent = count;
        }

        document.querySelectorAll('.student-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const studentId = parseInt(this.value);
                const row = this.closest('tr');
                if (this.checked) {
                    selectedStudents.push(studentId);
                    row.classList.add('selected');
                } else {
                    selectedStudents = selectedStudents.filter(id => id !== studentId);
                    row.classList.remove('selected');
                }
                updateSelectedCount();
            });
        });

        document.getElementById('selectAllHeader').addEventListener('change', function() {
            document.querySelectorAll('.student-checkbox').forEach(checkbox => checkbox.checked = this.checked);
            updateSelection();
        });

        function updateSelection() {
             selectedStudents = [];
             document.querySelectorAll('.student-checkbox:checked').forEach(checkbox => {
                 selectedStudents.push(parseInt(checkbox.value));
                 checkbox.closest('tr').classList.add('selected');
             });
             document.querySelectorAll('.student-checkbox:not(:checked)').forEach(checkbox => {
                 checkbox.closest('tr').classList.remove('selected');
             });
             updateSelectedCount();
        }

        function clearSelection() {
            document.getElementById('selectAllHeader').checked = false;
            document.querySelectorAll('.student-checkbox').forEach(checkbox => {
                checkbox.checked = false;
                checkbox.closest('tr').classList.remove('selected');
            });
            selectedStudents = [];
            updateSelectedCount();
        }

        // Add selected students to bulk action forms before submission
        document.querySelectorAll('#bulkTransferModal form, #bulkDeleteModal form').forEach(form => {
            form.addEventListener('submit', function(e) {
                form.querySelectorAll('input[name="selected_students[]"]').forEach(input => input.remove());
                selectedStudents.forEach(studentId => {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'selected_students[]';
                    hiddenInput.value = studentId;
                    form.appendChild(hiddenInput);
                });
            });
        });

        // Sorting functionality
        function sortTable(column) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentSort = urlParams.get('sort');
            const currentOrder = urlParams.get('order');
            
            let newOrder = 'ASC';
            if (currentSort === column && currentOrder === 'ASC') {
                newOrder = 'DESC';
            }
            
            urlParams.set('sort', column);
            urlParams.set('order', newOrder);
            window.location.search = urlParams.toString();
        }

        // Download CSV template
        function downloadTemplate() {
            let csvContent = "Student ID,Full Name,Roll Number,Phone,Email,Address";
            if (columnsExist) {
                csvContent += ",Admission Number,Date of Birth (YYYY-MM-DD),Session Year,Father's Name,Mother's Name";
            }
            csvContent += "\n";
            csvContent += "STU001,John Doe,1,1234567890,john@example.com,123 Main St";
            if (columnsExist) {
                csvContent += ",ADM001,2005-06-15,2025,Robert Doe,Mary Doe";
            }
            
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'students_template.csv';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            a.remove();
        }
    </script>
</body>
</html>
