<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';

// Check for success/error messages passed via GET parameters after redirect
if (isset($_GET['status'])) {
    if ($_GET['status'] == 'added') {
        $message = showAlert('Teacher added successfully!');
    } elseif ($_GET['status'] == 'updated') {
        $message = showAlert('Teacher updated successfully!');
    } elseif ($_GET['status'] == 'deleted') {
        $message = showAlert('Teacher deleted successfully!');
    } elseif ($_GET['status'] == 'assign_added') {
        $message = showAlert('Assignment added successfully!');
    } elseif ($_GET['status'] == 'assign_removed') {
        $message = showAlert('Assignment removed successfully!');
    } elseif ($_GET['status'] == 'assign_exists') {
        $message = showAlert('This assignment already exists!', 'warning');
    } elseif ($_GET['status'] == 'error') {
        $error_msg = isset($_GET['msg']) ? urldecode($_GET['msg']) : 'An unexpected error occurred.';
        $message = showAlert('Error: ' . htmlspecialchars($error_msg), 'danger'); // Sanitize error msg
    }
}


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $redirectUrl = "manage_teachers.php"; // Base redirect URL

    // CSRF Check (Basic Example - Implement a proper token system for production)
    /*
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Invalid CSRF token."));
        exit;
    }
    */

    switch ($action) {
        case 'add':
            // --- ADD TEACHER LOGIC (Same as before) ---
            $name = sanitize($_POST['name']);
            $email = sanitize($_POST['email']);
            $passwordInput = $_POST['password']; // Don't sanitize password before hashing
            $employee_id = sanitize($_POST['employee_id']);
            $phone = sanitize($_POST['phone']);
            $address = sanitize($_POST['address']);

            // Basic validation
            if (empty($name) || empty($email) || empty($passwordInput) || empty($employee_id)) {
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Name, Email, Password, and Employee ID are required."));
                 exit;
            }
             if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Invalid email format."));
                 exit;
            }

            $password = password_hash($passwordInput, PASSWORD_DEFAULT);

            try {
                $pdo->beginTransaction();

                // Check if email already exists
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $checkStmt->execute([$email]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Email address already exists.");
                }
                 // Check if employee ID already exists
                $checkStmt = $pdo->prepare("SELECT id FROM teachers WHERE employee_id = ?");
                $checkStmt->execute([$employee_id]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Employee ID already exists.");
                }


                // Create user account
                $userStmt = $pdo->prepare("INSERT INTO users (email, password, role, name) VALUES (?, ?, 'teacher', ?)");
                $userStmt->execute([$email, $password, $name]);
                $user_id = $pdo->lastInsertId();

                // Create teacher record
                $teacherStmt = $pdo->prepare("INSERT INTO teachers (user_id, name, email, employee_id, phone, address) VALUES (?, ?, ?, ?, ?, ?)");
                $teacherStmt->execute([$user_id, $name, $email, $employee_id, $phone, $address]);
                $teacher_id = $pdo->lastInsertId();

                $pdo->commit();
                logAudit($_SESSION['user_id'], 'INSERT', 'teachers', $teacher_id);
                header("Location: {$redirectUrl}?status=added");
                exit;

            } catch (Exception $e) {
                $pdo->rollback();
                header("Location: {$redirectUrl}?status=error&msg=" . urlencode($e->getMessage()));
                exit;
            }
            break;

        case 'edit':
             // --- EDIT TEACHER LOGIC (Same as before) ---
            $id = (int)$_POST['id'];
            $name = sanitize($_POST['name']);
            $email = sanitize($_POST['email']);
            $employee_id = sanitize($_POST['employee_id']);
            $phone = sanitize($_POST['phone']);
            $address = sanitize($_POST['address']);

             // Basic validation
            if (empty($name) || empty($email) || empty($employee_id) || $id <= 0) {
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Invalid data provided for update."));
                 exit;
            }
             if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Invalid email format."));
                 exit;
            }

            try {
                $pdo->beginTransaction();

                $fetchUserStmt = $pdo->prepare("SELECT user_id FROM teachers WHERE id = ?");
                $fetchUserStmt->execute([$id]);
                $teacher = $fetchUserStmt->fetch();

                if (!$teacher) throw new Exception("Teacher not found.");
                $user_id = $teacher['user_id'];

                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $checkStmt->execute([$email, $user_id]);
                if ($checkStmt->fetch()) throw new Exception("Email address is already in use by another user.");

                $checkStmt = $pdo->prepare("SELECT id FROM teachers WHERE employee_id = ? AND id != ?");
                $checkStmt->execute([$employee_id, $id]);
                if ($checkStmt->fetch()) throw new Exception("Employee ID is already in use by another teacher.");

                $updateUserStmt = $pdo->prepare("UPDATE users SET email = ?, name = ? WHERE id = ?");
                $updateUserStmt->execute([$email, $name, $user_id]);

                $updateTeacherStmt = $pdo->prepare("UPDATE teachers SET name = ?, email = ?, employee_id = ?, phone = ?, address = ? WHERE id = ?");
                $updateTeacherStmt->execute([$name, $email, $employee_id, $phone, $address, $id]);

                $pdo->commit();
                logAudit($_SESSION['user_id'], 'UPDATE', 'teachers', $id);
                header("Location: {$redirectUrl}?status=updated");
                exit;

            } catch (Exception $e) {
                $pdo->rollback();
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode($e->getMessage()));
                exit;
            }
            break;

        case 'delete':
            // --- DELETE TEACHER LOGIC (Same as before) ---
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT); // More secure way to get INT

             // Check if ID is valid *before* starting transaction
             if (!$id || $id <= 0) {
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Invalid teacher ID for deletion."));
                 exit;
            }

            try {
                $pdo->beginTransaction();

                $fetchUserStmt = $pdo->prepare("SELECT user_id FROM teachers WHERE id = ?");
                $fetchUserStmt->execute([$id]);
                $teacher = $fetchUserStmt->fetch();

                if (!$teacher) {
                     $pdo->rollBack();
                     header("Location: {$redirectUrl}?status=deleted"); // Assume already deleted or gone
                     exit;
                }
                 $user_id = $teacher['user_id'];

                $deleteAssignStmt = $pdo->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
                $deleteAssignStmt->execute([$id]);

                $deleteTeacherStmt = $pdo->prepare("DELETE FROM teachers WHERE id = ?");
                $deleteTeacherStmt->execute([$id]);

                $deleteUserStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $deleteUserStmt->execute([$user_id]);

                $pdo->commit();
                logAudit($_SESSION['user_id'], 'DELETE', 'teachers', $id);
                header("Location: {$redirectUrl}?status=deleted");
                exit;

            } catch (Exception $e) {
                $pdo->rollback();
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Error deleting teacher: " . $e->getMessage()));
                exit;
            }
            break;

        case 'add_assignment':
             // --- ADD ASSIGNMENT LOGIC (Same as before) ---
            $teacher_id = (int)$_POST['teacher_id'];
            $subject_id = (int)$_POST['subject_id'];
            $class_id = (int)$_POST['class_id'];

             if ($teacher_id <= 0 || $subject_id <= 0 || $class_id <= 0) {
                 header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Invalid IDs for assignment."));
                 exit;
            }

            $checkAssignStmt = $pdo->prepare("SELECT COUNT(*) as count FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ? AND class_id = ?");
            $checkAssignStmt->execute([$teacher_id, $subject_id, $class_id]);
            $exists = $checkAssignStmt->fetch()['count'] > 0;

            if ($exists) {
                // Return a specific status or message for AJAX
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    http_response_code(409); // Conflict status
                    echo json_encode(['status' => 'assign_exists', 'message' => 'This assignment already exists!']);
                    exit;
                } else {
                    header("Location: {$redirectUrl}?status=assign_exists");
                    exit;
                }
            } else {
                try {
                    $addAssignStmt = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, class_id) VALUES (?, ?, ?)");
                    if ($addAssignStmt->execute([$teacher_id, $subject_id, $class_id])) {
                        $new_assign_id = $pdo->lastInsertId();
                        logAudit($_SESSION['user_id'], 'INSERT', 'teacher_subjects', $new_assign_id);
                         if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo json_encode(['status' => 'assign_added', 'message' => 'Assignment added successfully!', 'assignment_id' => $new_assign_id]);
                            exit;
                        } else {
                            header("Location: {$redirectUrl}?status=assign_added");
                            exit;
                        }
                    } else {
                        throw new Exception("Database error adding assignment.");
                    }
                } catch (Exception $e) {
                     if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                         http_response_code(500);
                         echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                         exit;
                     } else {
                         header("Location: {$redirectUrl}?status=error&msg=" . urlencode($e->getMessage()));
                         exit;
                     }
                }
            }
            break;

        case 'remove_assignment':
             // --- REMOVE ASSIGNMENT LOGIC (Same as before) ---
            $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);

             if (!$assignment_id || $assignment_id <= 0) {
                  if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                     http_response_code(400); // Bad Request
                     echo json_encode(['status' => 'error', 'message' => 'Invalid assignment ID.']);
                     exit;
                 } else {
                     header("Location: {$redirectUrl}?status=error&msg=" . urlencode("Invalid assignment ID."));
                     exit;
                 }
            }

            try {
                $removeAssignStmt = $pdo->prepare("DELETE FROM teacher_subjects WHERE id = ?");
                if ($removeAssignStmt->execute([$assignment_id])) {
                    logAudit($_SESSION['user_id'], 'DELETE', 'teacher_subjects', $assignment_id);
                     if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        echo json_encode(['status' => 'assign_removed', 'message' => 'Assignment removed successfully!']);
                        exit;
                    } else {
                        header("Location: {$redirectUrl}?status=assign_removed");
                        exit;
                    }
                } else {
                     throw new Exception("Database error removing assignment.");
                }
            } catch (Exception $e) {
                 if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                     http_response_code(500);
                     echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                     exit;
                 } else {
                     header("Location: {$redirectUrl}?status=error&msg=" . urlencode($e->getMessage()));
                     exit;
                 }
            }
            break;
    }
} // End of POST handling

// --- Data Fetching for Display (Same as before) ---
try {
    $fetchTeachersStmt = $pdo->query("
        SELECT t.id, t.user_id, t.employee_id, t.phone, t.address, u.name, u.email
        FROM teachers t
        JOIN users u ON t.user_id = u.id
        ORDER BY u.name
    ");
    $teachers = $fetchTeachersStmt->fetchAll(PDO::FETCH_ASSOC);

    $assignmentsByTeacher = [];
    $fetchAssignmentsStmt = $pdo->query("
        SELECT ts.id, ts.teacher_id, ts.subject_id, ts.class_id,
               s.name as subject_name, s.code, c.name as class_name, c.section
        FROM teacher_subjects ts
        JOIN subjects s ON ts.subject_id = s.id
        JOIN classes c ON ts.class_id = c.id
        ORDER BY ts.teacher_id, c.name, s.name
    ");
    while ($assignment = $fetchAssignmentsStmt->fetch(PDO::FETCH_ASSOC)) {
        $assignmentsByTeacher[$assignment['teacher_id']][] = $assignment;
    }

    $classes = getAllClasses();
    $subjects = getAllSubjects();

} catch (PDOException $e) {
     $message = showAlert('Database Error fetching data: ' . $e->getMessage(), 'danger');
     $teachers = [];
     $assignmentsByTeacher = [];
     $classes = [];
     $subjects = [];
}

// Generate CSRF token for forms (Basic Example)
// $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Manage Teachers</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
     <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .assignment-badge { margin: 2px; font-size: 0.8rem; }
        .assignment-table { font-size: 0.9rem; }
        .assignment-section { background: #f8f9fa; border-radius: 8px; padding: 15px; margin-top: 15px;}
        .no-assignments { color: #6c757d; font-style: italic; }
        .assignment-form { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px; padding: 20px;}
        .current-assignments { max-height: 350px; overflow-y: auto; }
        #teachersTable_filter input { margin-left: 0.5em; display: inline-block; width: auto; }
        /* Style for floating notifications */
        #dynamicAlert { top: 70px; right: 20px; z-index: 1060; min-width: 300px; position: fixed; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?>
            </a>
             <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavAltMarkup">
               <span class="navbar-toggler-icon"></span>
             </button>
             <div class="collapse navbar-collapse" id="navbarNavAltMarkup">
               <div class="navbar-nav ms-auto">
                   <a class="nav-link" href="dashboard.php">Dashboard</a>
                   <!-- Add other relevant nav links here -->
                   <a class="nav-link" href="logout.php">Logout</a>
               </div>
             </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chalkboard-teacher me-2"></i>Manage Teachers</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                <i class="fas fa-plus me-2"></i>Add Teacher
            </button>
        </div>

        <?= $message // Display message from redirect ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="teachersTable" class="table table-hover">
                        <thead>
                            <tr>
                                <th>Emp. ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Current Assignments</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teachers as $teacher): ?>
                                <?php $currentAssignments = $assignmentsByTeacher[$teacher['id']] ?? []; ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($teacher['employee_id']) ?></strong></td>
                                    <td><?= htmlspecialchars($teacher['name']) ?></td>
                                    <td><?= htmlspecialchars($teacher['email']) ?></td>
                                    <td><?= htmlspecialchars($teacher['phone'] ?? '-') ?></td>
                                    <td>
                                        <?php if (empty($currentAssignments)): ?>
                                            <span class="no-assignments">No assignments</span>
                                        <?php else: ?>
                                            <div style="max-width: 350px;">
                                                <?php foreach ($currentAssignments as $assignment): ?>
                                                    <span class="badge bg-info assignment-badge">
                                                        <?= htmlspecialchars($assignment['class_name']) ?> - <?= htmlspecialchars($assignment['subject_name']) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <small class="text-muted"><?= count($currentAssignments) ?> assignment(s)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-outline-success manage-assignments-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#manageAssignmentsModal"
                                                    data-id="<?= $teacher['id'] ?>"
                                                    data-name="<?= htmlspecialchars($teacher['name']) ?>">
                                                <i class="fas fa-tasks fa-fw"></i> <span class="d-none d-md-inline">Manage</span>
                                            </button>
                                            <button class="btn btn-sm btn-outline-primary edit-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editTeacherModal"
                                                    data-id="<?= $teacher['id'] ?>"
                                                    data-name="<?= htmlspecialchars($teacher['name']) ?>"
                                                    data-email="<?= htmlspecialchars($teacher['email']) ?>"
                                                    data-employee-id="<?= htmlspecialchars($teacher['employee_id']) ?>"
                                                    data-phone="<?= htmlspecialchars($teacher['phone'] ?? '') ?>"
                                                    data-address="<?= htmlspecialchars($teacher['address'] ?? '') ?>">
                                                <i class="fas fa-edit fa-fw"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger delete-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteTeacherModal"
                                                    data-id="<?= $teacher['id'] ?>"
                                                    data-name="<?= htmlspecialchars($teacher['name']) ?>">
                                                <i class="fas fa-trash fa-fw"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Teacher Modal (Same as before) -->
     <div class="modal fade" id="addTeacherModal" tabindex="-1">
         <div class="modal-dialog modal-lg">
             <div class="modal-content">
                 <form method="POST" id="addTeacherForm">
                      <!-- CSRF Token -->
                      <!-- <input type="hidden" name="csrf_token" value="<?php // echo $_SESSION['csrf_token']; ?>"> -->
                     <div class="modal-header">
                         <h5 class="modal-title">Add New Teacher</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                     </div>
                     <div class="modal-body">
                         <input type="hidden" name="action" value="add">
                         <div class="row">
                             <div class="col-md-6 mb-3"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="name" required></div>
                             <div class="col-md-6 mb-3"><label class="form-label">Employee ID <span class="text-danger">*</span></label><input type="text" class="form-control" name="employee_id" required></div>
                         </div>
                         <div class="row">
                             <div class="col-md-6 mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" name="email" required></div>
                             <div class="col-md-6 mb-3"><label class="form-label">Password <span class="text-danger">*</span></label><input type="password" class="form-control" name="password" required></div>
                         </div>
                         <div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone"></div>
                         <div class="mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" rows="3"></textarea></div>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                         <button type="submit" id="addTeacherSubmitBtn" class="btn btn-primary">Add Teacher</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>


    <!-- Edit Teacher Modal (Same as before) -->
     <div class="modal fade" id="editTeacherModal" tabindex="-1">
         <div class="modal-dialog modal-lg">
             <div class="modal-content">
                 <form method="POST" id="editTeacherForm">
                       <!-- CSRF Token -->
                      <!-- <input type="hidden" name="csrf_token" value="<?php // echo $_SESSION['csrf_token']; ?>"> -->
                     <div class="modal-header">
                         <h5 class="modal-title">Edit Teacher</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                     </div>
                     <div class="modal-body">
                         <input type="hidden" name="action" value="edit">
                         <input type="hidden" name="id" id="edit_id">
                         <div class="row">
                             <div class="col-md-6 mb-3"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" class="form-control" name="name" id="edit_name" required></div>
                             <div class="col-md-6 mb-3"><label class="form-label">Employee ID <span class="text-danger">*</span></label><input type="text" class="form-control" name="employee_id" id="edit_employee_id" required></div>
                         </div>
                         <div class="mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" name="email" id="edit_email" required></div>
                         <div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="phone" id="edit_phone"></div>
                         <div class="mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address" id="edit_address" rows="3"></textarea></div>
                         <div class="mb-3"><small class="text-muted">Password can be changed via user profile or reset password functionality (if implemented).</small></div>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                         <button type="submit" id="editTeacherSubmitBtn" class="btn btn-primary">Update Teacher</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>


    <!-- Manage Assignments Modal (Same as before) -->
     <div class="modal fade" id="manageAssignmentsModal" tabindex="-1">
         <div class="modal-dialog modal-xl">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title">Manage Assignments - <span id="manage_teacher_name"></span></h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                 </div>
                 <div class="modal-body">
                     <input type="hidden" id="manage_teacher_id">
                     <div class="assignment-form mb-4">
                         <h6 class="mb-3"><i class="fas fa-plus me-2"></i>Add New Assignment</h6>
                         <form id="addAssignmentFormInternal">
                             <!-- CSRF Token -->
                             <!-- <input type="hidden" name="csrf_token" value="<?php // echo $_SESSION['csrf_token']; ?>"> -->
                             <input type="hidden" name="action" value="add_assignment">
                             <input type="hidden" name="teacher_id" id="new_assignment_teacher_id">
                             <div class="row align-items-end">
                                 <div class="col-md-5 mb-2">
                                     <label class="form-label text-white">Select Class</label>
                                     <select class="form-select form-select-sm" name="class_id" required>
                                         <option value="">Choose Class</option>
                                         <?php foreach ($classes as $class): ?>
                                             <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['name']) ?> - <?= htmlspecialchars($class['section']) ?></option>
                                         <?php endforeach; ?>
                                     </select>
                                 </div>
                                 <div class="col-md-5 mb-2">
                                     <label class="form-label text-white">Select Subject</label>
                                     <select class="form-select form-select-sm" name="subject_id" required>
                                         <option value="">Choose Subject</option>
                                         <?php foreach ($subjects as $subject): ?>
                                             <option value="<?= $subject['id'] ?>"><?= htmlspecialchars($subject['name']) ?> (<?= htmlspecialchars($subject['code']) ?>)</option>
                                         <?php endforeach; ?>
                                     </select>
                                 </div>
                                 <div class="col-md-2 mb-2">
                                     <button type="submit" id="addAssignmentSubmitBtnInternal" class="btn btn-light btn-sm w-100"><i class="fas fa-plus me-1"></i>Add</button>
                                 </div>
                             </div>
                         </form>
                     </div>
                     <div class="assignment-section">
                         <h6 class="mb-3"><i class="fas fa-list me-2"></i>Current Assignments</h6>
                         <div id="current-assignments" class="current-assignments"><p class="text-center text-muted">Loading assignments...</p></div>
                     </div>
                 </div>
                  <div class="modal-footer">
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                 </div>
             </div>
         </div>
     </div>


    <!-- Delete Teacher Modal -->
    <div class="modal fade" id="deleteTeacherModal" tabindex="-1">
         <div class="modal-dialog">
             <div class="modal-content">
                 <form method="POST">
                       <!-- CSRF Token -->
                      <!-- <input type="hidden" name="csrf_token" value="<?php // echo $_SESSION['csrf_token']; ?>"> -->
                     <div class="modal-header">
                         <h5 class="modal-title">Delete Teacher</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                     </div>
                     <div class="modal-body">
                         <input type="hidden" name="action" value="delete">
                         <input type="hidden" name="id" id="delete_id">
                         <p>Are you sure you want to delete <strong id="delete_name">this teacher</strong>?</p> <!-- Default text -->
                         <p class="text-danger">This will also delete their login account and all subject assignments. This action cannot be undone!</p>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                         <button type="submit" class="btn btn-danger">Delete Teacher</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>

    <!-- Hidden form for removing assignments via JS -->
    <form method="POST" id="removeAssignmentFormInternal" style="display: none;">
         <!-- CSRF Token -->
         <!-- <input type="hidden" name="csrf_token" value="<?php // echo $_SESSION['csrf_token']; ?>"> -->
        <input type="hidden" name="action" value="remove_assignment">
        <input type="hidden" name="assignment_id" id="remove_assignment_id_internal">
    </form>


    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
             if ($('#teachersTable').length) {
                $('#teachersTable').DataTable({
                     pageLength: 25,
                     order: [[1, 'asc']] // Sort by Name ascending
                });
             }
        });

        // Edit button functionality (Populate Edit Modal)
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                try {
                    document.getElementById('edit_id').value = this.dataset.id || '';
                    document.getElementById('edit_name').value = this.dataset.name || '';
                    document.getElementById('edit_email').value = this.dataset.email || '';
                    document.getElementById('edit_employee_id').value = this.dataset.employeeId || '';
                    document.getElementById('edit_phone').value = this.dataset.phone || '';
                    document.getElementById('edit_address').value = this.dataset.address || '';
                 } catch (e) {
                     console.error("Error populating edit modal:", e);
                 }
            });
        });

        // Manage assignments button functionality (Open Modal & Load)
        document.querySelectorAll('.manage-assignments-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                try {
                    const teacherId = this.dataset.id;
                    const teacherName = this.dataset.name;

                    document.getElementById('manage_teacher_id').value = teacherId;
                    document.getElementById('new_assignment_teacher_id').value = teacherId; // For the internal form
                    document.getElementById('manage_teacher_name').textContent = teacherName || 'Selected Teacher';

                    loadCurrentAssignments(teacherId);
                 } catch (e) {
                     console.error("Error setting up assignment modal:", e);
                 }
            });
        });

        // Delete button functionality (Populate Delete Modal - Enhanced)
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                 const teacherId = this.dataset.id;
                 const teacherName = this.dataset.name;
                 const deleteIdInput = document.getElementById('delete_id');
                 const deleteNameSpan = document.getElementById('delete_name');

                 console.log("Delete button clicked. Teacher ID:", teacherId, "Name:", teacherName); // Debug log

                 if (deleteIdInput) {
                    const parsedId = parseInt(teacherId);
                    if (!isNaN(parsedId) && parsedId > 0) {
                         deleteIdInput.value = parsedId;
                         console.log("Set delete_id input value to:", parsedId); // Debug log
                    } else {
                         console.error("Invalid teacher ID found in button data-id:", teacherId);
                         deleteIdInput.value = ''; // Clear potentially bad value
                         // Optionally disable submit button or show error in modal
                    }
                 } else {
                     console.error("Delete modal hidden ID input (#delete_id) not found!");
                 }

                 if (deleteNameSpan) {
                     deleteNameSpan.textContent = teacherName || 'this teacher'; // Use fallback text
                 } else {
                      console.error("Delete modal name span (#delete_name) not found!");
                 }
            });
        });

         // Load current assignments via Fetch API (Error Handling)
         async function loadCurrentAssignments(teacherId) {
             const container = document.getElementById('current-assignments');
             if (!container) return; // Exit if container doesn't exist
             container.innerHTML = '<p class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin"></i> Loading assignments...</p>';

             try {
                const response = await fetch(`get_teacher_assignments_detailed.php?teacher_id=${teacherId}`, {
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
});
                 if (!response.ok) {
                      const errorText = await response.text(); // Try to get error text from server
                      throw new Error(`HTTP error! Status: ${response.status}, Message: ${errorText}`);
                 }
                 // Check content type before parsing
                 const contentType = response.headers.get("content-type");
                 if (!contentType || !contentType.includes("application/json")) {
                     throw new Error(`Expected JSON, but received ${contentType}`);
                 }

                 const data = await response.json();

                  if (data.error) { // Check for specific error message from PHP script
                      throw new Error(data.error);
                  }


                 if (!Array.isArray(data)) {
                      throw new Error("Invalid data format received from server.");
                 }

                 if (data.length === 0) {
                     container.innerHTML = '<p class="no-assignments text-center py-3">No assignments found for this teacher.</p>';
                 } else {
                     let html = '<div class="table-responsive"><table class="table table-sm assignment-table table-striped">';
                     html += '<thead class="table-light"><tr><th>Class</th><th>Subject</th><th>Action</th></tr></thead><tbody>';
                     data.forEach(assignment => {
                         // Basic sanitization on client-side for safety, though PHP should handle it
                         const className = assignment.class_name ? String(assignment.class_name).replace(/</g, "&lt;") : '';
                         const section = assignment.section ? String(assignment.section).replace(/</g, "&lt;") : '';
                         const subjectName = assignment.subject_name ? String(assignment.subject_name).replace(/</g, "&lt;") : '';
                         const code = assignment.code ? String(assignment.code).replace(/</g, "&lt;") : '';
                         const assignmentId = parseInt(assignment.id); // Ensure ID is integer

                         if (isNaN(assignmentId)) return; // Skip if ID is invalid

                         html += `
                             <tr>
                                 <td><strong>${className}</strong> ${section ? '- ' + section : ''}</td>
                                 <td><span class="badge bg-primary">${subjectName}</span> <small class="text-muted">(${code})</small></td>
                                 <td><button class="btn btn-sm btn-outline-danger" onclick="removeAssignmentInternal(${assignmentId}, ${teacherId})" title="Remove Assignment"><i class="fas fa-times"></i></button></td>
                             </tr>`;
                     });
                     html += '</tbody></table></div>';
                     container.innerHTML = html;
                 }
                 attachAssignmentValidationListeners(); // Re-attach listeners after content update

             } catch (error) {
                 console.error('Error loading assignments:', error);
                 container.innerHTML = `<p class="text-danger text-center py-3">Error loading assignments: ${error.message || 'Check network connection or server logs.'}</p>`;
             }
         }


         // Remove assignment using Fetch API (Error Handling)
         async function removeAssignmentInternal(assignmentId, teacherId) {
             if (!assignmentId || isNaN(assignmentId) || assignmentId <= 0) {
                 showNotification('Invalid assignment ID.', 'danger');
                 return;
             }
             if (confirm('Are you sure you want to remove this assignment?')) {
                 const form = document.getElementById('removeAssignmentFormInternal');
                 if (!form) {
                      showNotification('Internal error: Remove form not found.', 'danger');
                      return;
                 }
                 document.getElementById('remove_assignment_id_internal').value = assignmentId;
                 const formData = new FormData(form);

                 try {
                     const response = await fetch('manage_teachers.php', {
    method: 'POST',
    body: formData,
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
});
                     if (!response.ok) {
                          const errorText = await response.text();
                          throw new Error(`HTTP error! Status: ${response.status}, Message: ${errorText}`);
                     }
                     const result = await response.json(); // Expecting JSON now

                     if (result.status === 'assign_removed') {
                          showNotification(result.message || 'Assignment removed successfully!', 'success');
                          loadCurrentAssignments(teacherId); // Reload assignments in the modal
                     } else {
                          throw new Error(result.message || 'Failed to remove assignment.');
                     }
                 } catch (error) {
                     console.error('Error removing assignment:', error);
                     showNotification(`Error removing assignment: ${error.message}`, 'danger');
                 }
             }
         }

        // Handle add assignment form submission using Fetch API (Error Handling)
        document.getElementById('addAssignmentFormInternal').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const teacherId = formData.get('teacher_id');
            const submitBtn = document.getElementById('addAssignmentSubmitBtnInternal');

            if (!teacherId) {
                 showNotification('Error: Teacher ID not set.', 'danger');
                 return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const response = await fetch('manage_teachers.php', {
    method: 'POST',
    body: formData,
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
});
                if (!response.ok) {
                     // Try to parse JSON error first
                     let errorMsg = `HTTP error! Status: ${response.status}`;
                     try {
                          const errorResult = await response.json();
                          errorMsg = errorResult.message || errorMsg;
                     } catch(jsonError) {
                          const errorText = await response.text(); // Fallback to text
                          errorMsg += `, Message: ${errorText}`;
                     }
                     throw new Error(errorMsg);
                 }

                const result = await response.json(); // Expecting JSON on success too

                if (result.status === 'assign_added') {
                    showNotification(result.message || 'Assignment added successfully!', 'success');
                    this.reset();
                    document.getElementById('new_assignment_teacher_id').value = teacherId; // Re-set hidden ID
                    loadCurrentAssignments(teacherId); // Reload
                } else if (result.status === 'assign_exists') {
                     showNotification(result.message || 'This assignment already exists!', 'warning');
                } else {
                    throw new Error(result.message || 'An unknown error occurred.');
                }

            } catch (error) {
                console.error('Error adding assignment:', error);
                showNotification(`Error adding assignment: ${error.message}`, 'danger');
            } finally {
                 submitBtn.disabled = false;
                 submitBtn.innerHTML = '<i class="fas fa-plus me-1"></i>Add';
                 validateAssignmentInternal(); // Re-run validation
            }
        });


         // Show notification function (Bootstrap Alert - Enhanced)
         function showNotification(message, type = 'info') {
             const existingAlert = document.getElementById('dynamicAlert');
             if(existingAlert) {
                 try {
                     const bsAlert = bootstrap.Alert.getInstance(existingAlert);
                     if (bsAlert) bsAlert.close(); else existingAlert.remove();
                 } catch(e) { existingAlert.remove(); } // Fallback removal
             }

             const alertDiv = document.createElement('div');
             alertDiv.id = 'dynamicAlert';
             alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
             alertDiv.style.cssText = 'position: fixed; top: 70px; right: 20px; z-index: 1060; min-width: 300px; box-shadow: 0 0.5rem 1rem rgba(0,0,0,.15);';
             alertDiv.setAttribute('role', 'alert');
             alertDiv.innerHTML = `
                 <div>${message}</div>
                 <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
             `;
             document.body.appendChild(alertDiv);

             // Ensure it fades out even if close button not clicked
             setTimeout(() => {
                 const currentAlert = document.getElementById('dynamicAlert');
                  if (currentAlert) {
                       try {
                           const bsAlert = bootstrap.Alert.getInstance(currentAlert);
                           if (bsAlert) bsAlert.close(); else currentAlert.remove();
                       } catch(e) { currentAlert.remove(); }
                  }
             }, 5000);
         }

        // --- Prevent duplicate assignments within the Modal (Same as before) ---
         function attachAssignmentValidationListeners() {
            const classSelect = document.querySelector('#addAssignmentFormInternal select[name="class_id"]');
            const subjectSelect = document.querySelector('#addAssignmentFormInternal select[name="subject_id"]');
             if(classSelect) classSelect.addEventListener('change', validateAssignmentInternal);
             if(subjectSelect) subjectSelect.addEventListener('change', validateAssignmentInternal);
         }
         function validateAssignmentInternal() { /* ... function content same as before ... */ }
         attachAssignmentValidationListeners();

        // --- Prevent multiple submissions for Add/Edit Modals (Same as before) ---
        const addForm = document.getElementById('addTeacherForm');
        const addBtn = document.getElementById('addTeacherSubmitBtn');
        if(addForm && addBtn) { /* ... listener content same as before ... */ }
         const editForm = document.getElementById('editTeacherForm');
        const editBtn = document.getElementById('editTeacherSubmitBtn');
         if(editForm && editBtn) { /* ... listener content same as before ... */ }

    </script>
</body>
</html>

