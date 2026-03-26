<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'functions.php';

checkLogin();
// Allow Class Teachers, Admins, Super Admins
if (!in_array($_SESSION['role'], ['super_admin', 'admin', 'teacher'])) {
    header("Location: dashboard.php");
    exit;
}

$message = '';
$selectedDate = date('Y-m-d');
$selectedClass = '';
$selectedEvent = '';
$attendanceData = [];
$events = [];
$recentEvents = [];

// Get teacher's assigned class if they're a teacher
$teacherClassId = null;
if ($_SESSION['role'] == 'teacher') {
    $stmt = $pdo->prepare("SELECT ct.class_id FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id WHERE t.user_id = ? AND ct.is_active = TRUE");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherClass = $stmt->fetch();
    if ($teacherClass) {
        $teacherClassId = $teacherClass['class_id'];
        // Only default if no class is selected via GET
        if (!isset($_GET['class_id'])) {
             $selectedClass = $teacherClassId;
        }
    } else {
        $message = showAlert('You are not assigned as a class teacher.', 'warning');
    }
}

// Handle GET requests for selections
if (isset($_GET['date'])) $selectedDate = sanitize($_GET['date']);
// Ensure GET parameter overrides teacher default if set
if (isset($_GET['class_id'])) $selectedClass = (int)$_GET['class_id'];
if (isset($_GET['event_id'])) $selectedEvent = (int)$_GET['event_id'];

// Security check: Teacher can only access their assigned class
if ($_SESSION['role'] == 'teacher' && $selectedClass != $teacherClassId && $teacherClassId !== null) {
     $message = showAlert('Access denied. You can only manage attendance for your assigned class.', 'danger');
     $selectedClass = $teacherClassId; // Force back to assigned class
     $selectedEvent = ''; // Reset event if class was unauthorized
     $attendanceData = []; // Clear data
}

// Handle POST requests (Remains largely the same as before)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Create New Event ---
    if ($action == 'create_event' && in_array($_SESSION['role'], ['super_admin', 'admin'])) {
        $eventName = sanitize($_POST['event_name']);
        $eventDesc = sanitize($_POST['event_description']);
        $eventDate = !empty($_POST['event_date']) ? sanitize($_POST['event_date']) : null;

        if (!empty($eventName)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO attendance_events (name, description, event_date, created_by) VALUES (?, ?, ?, ?)");
                if ($stmt->execute([$eventName, $eventDesc, $eventDate, $_SESSION['user_id']])) {
                    $message = showAlert('Event created successfully!', 'success');
                    logAudit($_SESSION['user_id'], 'CREATE', 'attendance_events', $pdo->lastInsertId(), '', "Event: $eventName");
                    // Select the newly created event automatically
                    $selectedEvent = $pdo->lastInsertId();
                    // Redirect to keep the URL clean and reflect the new selection
                    header("Location: miscellaneous_attendance.php?class_id=$selectedClass&event_id=$selectedEvent&date=$selectedDate");
                    exit;
                } else {
                    $message = showAlert('Error creating event. Event name might already exist.', 'danger');
                }
            } catch (PDOException $e) {
                 $message = showAlert('Database Error: ' . $e->getMessage(), 'danger');
            }
        } else {
            $message = showAlert('Event name is required.', 'warning');
        }
    }

    // --- Submit Attendance ---
    elseif ($action == 'submit_attendance') {
        $attendance_date = sanitize($_POST['attendance_date']);
        $class_id = (int)$_POST['class_id'];
        $event_id = (int)$_POST['event_id'];
        $attendance_status = $_POST['attendance'] ?? [];
        $remarks = $_POST['remarks'] ?? [];

        // Security check again for teachers
        if ($_SESSION['role'] == 'teacher' && $class_id != $teacherClassId && $teacherClassId !== null) {
             $message = showAlert('Unauthorized attempt to submit attendance.', 'danger');
        } else {
            try {
                $pdo->beginTransaction();
                $updated_count = 0;

                $stmt_students = $pdo->prepare("SELECT id FROM students WHERE class_id = ?");
                $stmt_students->execute([$class_id]);
                $all_student_ids = $stmt_students->fetchAll(PDO::FETCH_COLUMN);

                foreach ($all_student_ids as $student_id) {
                    $student_id = (int)$student_id;
                    $status = $attendance_status[$student_id] ?? 'Absent';
                    $student_remarks = sanitize($remarks[$student_id] ?? '');

                    $stmt = $pdo->prepare("
                        INSERT INTO miscellaneous_attendance
                        (student_id, class_id, event_id, attendance_date, status, remarks, marked_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        remarks = VALUES(remarks),
                        marked_by = VALUES(marked_by),
                        updated_at = CURRENT_TIMESTAMP
                    ");

                    if ($stmt->execute([$student_id, $class_id, $event_id, $attendance_date, $status, $student_remarks, $_SESSION['user_id']])) {
                        $updated_count++;
                    }
                }

                $pdo->commit();
                logAudit($_SESSION['user_id'], 'UPDATE', 'miscellaneous_attendance', 0, '', "Misc Attend: Cls $class_id, Evt $event_id, Dt $attendance_date, Cnt $updated_count");
                $message = showAlert("Miscellaneous attendance updated successfully for $updated_count records!", 'success');
                // Refresh selection needed to show saved data immediately
                $selectedClass = $class_id;
                $selectedEvent = $event_id;
                $selectedDate = $attendance_date;
                // Redirect after POST to prevent re-submission and clear POST data
                 header("Location: miscellaneous_attendance.php?class_id=$selectedClass&event_id=$selectedEvent&date=$selectedDate&message=saved");
                 exit;

            } catch (Exception $e) {
                $pdo->rollback();
                $message = showAlert('Error saving attendance: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// Check for saved message after redirect
if (isset($_GET['message']) && $_GET['message'] == 'saved' && empty($message)) {
     $message = showAlert("Miscellaneous attendance updated successfully!", 'success');
}


// --- Data Loading ---
// Get available classes
$availableClasses = [];
if (in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    $stmt = $pdo->query("SELECT id, name, section FROM classes ORDER BY name, section");
    $availableClasses = $stmt->fetchAll();
} elseif ($teacherClassId) {
    $stmt = $pdo->prepare("SELECT id, name, section FROM classes WHERE id = ?");
    $stmt->execute([$teacherClassId]);
    $availableClasses = $stmt->fetchAll();
}

// Get available events
$stmt_events = $pdo->query("SELECT id, name FROM attendance_events WHERE is_active = TRUE ORDER BY name");
$events = $stmt_events->fetchAll();

// Get recent events
$stmt_recent = $pdo->query("SELECT id, name FROM attendance_events WHERE is_active = TRUE ORDER BY created_at DESC LIMIT 5");
$recentEvents = $stmt_recent->fetchAll();


// Load student list and existing attendance if class, event, and date are selected
$presentCount = $absentCount = $excusedCount = 0; // Initialize counts
if ($selectedClass && $selectedEvent && $selectedDate) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.name, s.roll_number, s.student_id as reg_id,
                   COALESCE(ma.status, 'Absent') as status, -- Default to Absent if no record
                   ma.remarks
            FROM students s
            LEFT JOIN miscellaneous_attendance ma ON s.id = ma.student_id
                                                AND ma.event_id = ?
                                                AND ma.attendance_date = ?
            WHERE s.class_id = ?
            ORDER BY s.roll_number, s.name
        ");
        $stmt->execute([$selectedEvent, $selectedDate, $selectedClass]);
        $attendanceData = $stmt->fetchAll();

        // Calculate stats after fetching data
        foreach ($attendanceData as $student) {
             switch ($student['status']) {
                  case 'Present': $presentCount++; break;
                  case 'Absent': $absentCount++; break;
                  case 'Excused': $excusedCount++; break;
             }
        }

    } catch (PDOException $e) {
        $message .= showAlert('Error loading attendance data: ' . $e->getMessage(), 'danger');
        $attendanceData = []; // Ensure data is empty on error
    }
}

// Get class info for display
$classInfo = null;
if ($selectedClass) {
    $stmt = $pdo->prepare("SELECT name, section FROM classes WHERE id = ?");
    $stmt->execute([$selectedClass]);
    $classInfo = $stmt->fetch();
}
$eventName = '';
if($selectedEvent){
     $stmt = $pdo->prepare("SELECT name FROM attendance_events WHERE id = ?");
     $stmt->execute([$selectedEvent]);
     $eventInfo = $stmt->fetch();
     if($eventInfo) $eventName = $eventInfo['name'];
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Miscellaneous Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .filter-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px; padding: 25px; color: white; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .student-row { border-bottom: 1px solid #eee; padding: 8px 15px; transition: background-color 0.2s ease; }
        .student-row:last-child { border-bottom: none; }
        .status-Present { background-color: #d1e7dd !important; border-left: 4px solid #198754; }
        .status-Absent { background-color: #f8d7da !important; border-left: 4px solid #dc3545; }
        .status-Excused { background-color: #e2e3e5 !important; border-left: 4px solid #6c757d; }
        .summary-card { background-color: #fff; border-radius: 10px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .summary-card h6 { color: #6c757d; }
        .summary-card .display-6 { font-weight: 500; }
        .quick-filters .btn { margin-right: 5px; }
        #attendanceTable_wrapper .row:first-child { padding-bottom: 1rem; } /* Add padding below search/length */
        #attendanceTable_filter input { margin-left: 0.5em; } /* Space for search */
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
         <div class="container">
             <a class="navbar-brand" href="dashboard.php"><i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?></a>
             <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavAltMarkup" aria-controls="navbarNavAltMarkup" aria-expanded="false" aria-label="Toggle navigation">
               <span class="navbar-toggler-icon"></span>
             </button>
             <div class="collapse navbar-collapse" id="navbarNavAltMarkup">
               <div class="navbar-nav ms-auto">
                   <a class="nav-link" href="dashboard.php">Dashboard</a>
                   <a class="nav-link" href="attendance.php">Daily Attendance</a>
                   <a class="nav-link active" href="miscellaneous_attendance.php">Misc Attendance</a>
                   <a class="nav-link" href="fee_status.php">Fee Status</a>
                   <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                       <a class="nav-link" href="attendance_reports.php">Reports</a>
                   <?php endif; ?>
                   <a class="nav-link" href="logout.php">Logout</a>
               </div>
             </div>
         </div>
     </nav>

     <div class="container-fluid mt-4">

          <div class="row">
               <div class="col-lg-9">
                   <h2><i class="fas fa-clipboard-list me-2"></i>Miscellaneous Attendance</h2>
                   <?= $message ?>

                   <!-- Filter Section -->
                   <div class="filter-section">
                       <form id="filterForm" method="GET" class="row g-3 align-items-end">
                           <div class="col-md-3">
                               <label class="form-label">Select Class</label>
                               <select class="form-select form-select-sm" name="class_id" required onchange="document.getElementById('filterForm').submit()">
                                   <option value="">-- Select Class --</option>
                                   <?php foreach ($availableClasses as $class): ?>
                                       <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                           <?= htmlspecialchars($class['name']) ?> - <?= htmlspecialchars($class['section']) ?>
                                       </option>
                                   <?php endforeach; ?>
                               </select>
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Select Event</label>
                               <div class="input-group input-group-sm">
                                   <select class="form-select" name="event_id" required onchange="document.getElementById('filterForm').submit()">
                                       <option value="">-- Select Event --</option>
                                       <?php foreach ($events as $event): ?>
                                           <option value="<?= $event['id'] ?>" <?= $selectedEvent == $event['id'] ? 'selected' : '' ?>>
                                               <?= htmlspecialchars($event['name']) ?>
                                           </option>
                                       <?php endforeach; ?>
                                   </select>
                                   <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                                       <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addEventModal" title="Add New Event">
                                           <i class="fas fa-plus"></i>
                                       </button>
                                   <?php endif; ?>
                               </div>
                           </div>
                           <div class="col-md-3">
                               <label class="form-label">Date</label>
                               <input type="date" class="form-control form-control-sm" name="date" value="<?= $selectedDate ?>" required onchange="document.getElementById('filterForm').submit()">
                           </div>
                           <div class="col-md-2">
                               <button type="submit" class="btn btn-light btn-sm w-100"><i class="fas fa-filter me-1"></i>Load Data</button>
                           </div>
                       </form>
                   </div>

                   <!-- Attendance Summary and Actions -->
                    <?php if ($selectedClass && $selectedEvent && $selectedDate && !empty($attendanceData)): ?>
                         <div class="row mb-3">
                              <div class="col-md-3">
                                   <div class="summary-card text-center">
                                        <h6>Present</h6>
                                        <div class="display-6 text-success" id="summary-present"><?= $presentCount ?></div>
                                   </div>
                              </div>
                              <div class="col-md-3">
                                    <div class="summary-card text-center">
                                         <h6>Absent</h6>
                                         <div class="display-6 text-danger" id="summary-absent"><?= $absentCount ?></div>
                                    </div>
                               </div>
                               <div class="col-md-3">
                                    <div class="summary-card text-center">
                                         <h6>Excused</h6>
                                         <div class="display-6 text-secondary" id="summary-excused"><?= $excusedCount ?></div>
                                    </div>
                               </div>
                               <div class="col-md-3">
                                    <div class="summary-card text-center">
                                         <h6>Total</h6>
                                         <div class="display-6" id="summary-total"><?= count($attendanceData) ?></div>
                                    </div>
                               </div>
                         </div>
                         <div class="d-flex justify-content-between align-items-center mb-3">
                             <div class="quick-filters">
                                 <span class="me-2">Filter:</span>
                                 <button class="btn btn-sm btn-outline-secondary active" onclick="filterTable('')">All</button>
                                 <button class="btn btn-sm btn-outline-success" onclick="filterTable('Present')">Present</button>
                                 <button class="btn btn-sm btn-outline-danger" onclick="filterTable('Absent')">Absent</button>
                                 <button class="btn btn-sm btn-outline-secondary" onclick="filterTable('Excused')">Excused</button>
                             </div>
                              <div>
                                   <button type="button" class="btn btn-sm btn-outline-success" onclick="markAllMiscAs('Present')">All Present</button>
                                   <button type="button" class="btn btn-sm btn-outline-danger" onclick="markAllMiscAs('Absent')">All Absent</button>
                              </div>
                         </div>
                    <?php endif; ?>


                   <!-- Attendance Taking Form -->
                   <?php if ($selectedClass && $selectedEvent && $selectedDate && !empty($attendanceData)): ?>
                       <form method="POST" id="attendanceForm">
                           <input type="hidden" name="action" value="submit_attendance">
                           <input type="hidden" name="attendance_date" value="<?= $selectedDate ?>">
                           <input type="hidden" name="class_id" value="<?= $selectedClass ?>">
                           <input type="hidden" name="event_id" value="<?= $selectedEvent ?>">

                           <div class="card shadow-sm mb-4">
                               <div class="card-header bg-white py-3">
                                   <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                             Attendance: <strong><?= htmlspecialchars($eventName) ?></strong>
                                             <small class="text-muted d-block">
                                                  Class: <?= htmlspecialchars($classInfo['name']) ?> - <?= htmlspecialchars($classInfo['section']) ?> | Date: <?= formatDate($selectedDate) ?>
                                             </small>
                                        </h5>
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Attendance</button>
                                   </div>
                               </div>
                               <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table id="attendanceTable" class="table table-hover mb-0">
                                             <thead class="table-light">
                                                  <tr>
                                                      <th>Roll No.</th>
                                                      <th>Student Name</th>
                                                      <th>Reg. ID</th>
                                                      <th width="15%">Status</th>
                                                      <th width="30%">Remarks</th>
                                                  </tr>
                                             </thead>
                                             <tbody>
                                             <?php foreach ($attendanceData as $index => $student): ?>
                                                  <tr class="student-row status-<?= $student['status'] ?? 'Absent' ?>" id="student-row-<?= $index ?>">
                                                       <td><?= htmlspecialchars($student['roll_number']) ?></td>
                                                       <td><?= htmlspecialchars($student['name']) ?></td>
                                                       <td><?= htmlspecialchars($student['reg_id']) ?></td>
                                                       <td>
                                                            <select class="form-select form-select-sm attendance-status"
                                                                     name="attendance[<?= $student['id'] ?>]"
                                                                     data-row-index="<?= $index ?>">
                                                                 <option value="Present" <?= ($student['status'] ?? '') == 'Present' ? 'selected' : '' ?>>Present</option>
                                                                 <option value="Absent" <?= ($student['status'] ?? 'Absent') == 'Absent' ? 'selected' : '' ?>>Absent</option>
                                                                 <option value="Excused" <?= ($student['status'] ?? '') == 'Excused' ? 'selected' : '' ?>>Excused</option>
                                                            </select>
                                                       </td>
                                                       <td>
                                                            <input type="text" class="form-control form-control-sm"
                                                                     name="remarks[<?= $student['id'] ?>]"
                                                                     value="<?= htmlspecialchars($student['remarks'] ?? '') ?>"
                                                                     placeholder="Remarks">
                                                       </td>
                                                  </tr>
                                             <?php endforeach; ?>
                                             </tbody>
                                        </table>
                                   </div>
                               </div>
                               <div class="card-footer text-end">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Attendance</button>
                               </div>
                           </div>
                       </form>
                   <?php elseif ($selectedClass && $selectedEvent && $selectedDate && empty($attendanceData)): ?>
                       <div class="alert alert-warning">No students found in the selected class (<?= htmlspecialchars($classInfo['name']) ?> - <?= htmlspecialchars($classInfo['section']) ?>). Cannot take attendance.</div>
                   <?php elseif (!$message && empty($attendanceData)) : ?>
                       <div class="alert alert-info">Please select a class, event, and date to manage miscellaneous attendance.</div>
                   <?php endif; ?>
               </div> <!-- End Left Column -->

               <div class="col-lg-3">
                    <!-- Recent Events -->
                    <div class="card shadow-sm mb-4">
                         <div class="card-header bg-light">
                              <h6 class="mb-0"><i class="fas fa-history me-2"></i>Recent Events</h6>
                         </div>
                         <ul class="list-group list-group-flush">
                              <?php if (empty($recentEvents)): ?>
                                   <li class="list-group-item text-muted">No recent events found.</li>
                              <?php else: ?>
                                   <?php foreach ($recentEvents as $event): ?>
                                        <li class="list-group-item">
                                             <a href="?class_id=<?= $selectedClass ?>&event_id=<?= $event['id'] ?>&date=<?= $selectedDate ?>" class="text-decoration-none d-block <?= $selectedEvent == $event['id'] ? 'fw-bold text-primary' : '' ?>">
                                                  <?= htmlspecialchars($event['name']) ?>
                                             </a>
                                        </li>
                                   <?php endforeach; ?>
                              <?php endif; ?>
                              <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                                   <li class="list-group-item text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#addEventModal">
                                             <i class="fas fa-plus me-1"></i> Add New Event
                                        </button>
                                   </li>
                               <?php endif; ?>
                         </ul>
                    </div>

                    <!-- Reports & Links -->
                    <div class="card shadow-sm">
                         <div class="card-header bg-light">
                              <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Reports & Summaries</h6>
                         </div>
                         <div class="list-group list-group-flush">
                              <?php if ($selectedEvent): ?>
                                   <a href="misc_attendance_reports.php?event_id=<?= $selectedEvent ?>" class="list-group-item list-group-item-action" target="_blank">
                                        <i class="fas fa-calendar-alt me-2 text-primary"></i>Event Summary Report <small>(<?= htmlspecialchars($eventName) ?>)</small>
                                   </a>
                              <?php endif; ?>
                              <?php if ($selectedClass): ?>
                                    <a href="misc_attendance_reports.php?class_id=<?= $selectedClass ?>" class="list-group-item list-group-item-action" target="_blank">
                                        <i class="fas fa-users me-2 text-info"></i>Class Summary Report <small>(<?= htmlspecialchars($classInfo['name'] ?? '') ?>)</small>
                                    </a>
                                     <a href="attendance_reports.php?class_id=<?= $selectedClass ?>" class="list-group-item list-group-item-action" target="_blank">
                                         <i class="fas fa-calendar-day me-2 text-success"></i>Daily Attendance Reports
                                     </a>
                              <?php endif; ?>
                              <a href="attendance_reports.php" class="list-group-item list-group-item-action" target="_blank">
                                   <i class="fas fa-file-alt me-2 text-secondary"></i>All Attendance Reports
                              </a>
                         </div>
                    </div>
               </div> <!-- End Right Column -->
          </div><!-- End Row -->

     </div><!-- End Container -->

     <!-- Add Event Modal (Admin/Super Admin only) -->
     <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
     <div class="modal fade" id="addEventModal" tabindex="-1">
         <div class="modal-dialog">
             <div class="modal-content">
                 <form method="POST">
                     <div class="modal-header">
                         <h5 class="modal-title">Create New Attendance Event</h5>
                         <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                     </div>
                     <div class="modal-body">
                         <input type="hidden" name="action" value="create_event">
                         <div class="mb-3">
                             <label class="form-label">Event Name *</label>
                             <input type="text" class="form-control" name="event_name" required>
                         </div>
                         <div class="mb-3">
                             <label class="form-label">Description (Optional)</label>
                             <textarea class="form-control" name="event_description" rows="2"></textarea>
                         </div>
                         <div class="mb-3">
                             <label class="form-label">Event Date (Optional)</label>
                             <input type="date" class="form-control" name="event_date">
                         </div>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                         <button type="submit" class="btn btn-primary">Create Event</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>
     <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        let attendanceTable;

        $(document).ready(function() {
            if ($('#attendanceTable').length) {
                attendanceTable = $('#attendanceTable').DataTable({
                    pageLength: 25, // Show more entries
                    order: [[ 0, 'asc' ]], // Sort by Roll No. initially
                    columnDefs: [
                       { orderable: false, targets: [3, 4] } // Disable sorting for status and remarks
                     ],
                     language: {
                        search: "_INPUT_", // Use placeholder for search
                        searchPlaceholder: "Search students..."
                    }
                });
            }
        });

        function markAllMiscAs(status) {
            const selects = document.querySelectorAll('.attendance-status');
            selects.forEach(select => {
                select.value = status;
                updateMiscRowStyle(select);
            });
            updateSummaryCounts(); // Update summary counts after bulk change
        }

        function updateMiscRowStyle(selectElement) {
             const rowIndex = selectElement.getAttribute('data-row-index');
             const row = document.getElementById('student-row-' + rowIndex);
             if(row) { // Check if row exists
                const status = selectElement.value;
                row.className = row.className.replace(/status-\w+/g, ''); // Remove old status class
                row.classList.add('status-' + status); // Add new status class
             }
        }

        function updateSummaryCounts() {
            let present = 0, absent = 0, excused = 0, total = 0;
            const selects = document.querySelectorAll('.attendance-status');

            selects.forEach(select => {
                // Only count visible rows if using DataTable filtering
                if (!attendanceTable || $(select).closest('tr').is(':visible')) {
                    total++;
                    switch(select.value) {
                         case 'Present': present++; break;
                         case 'Absent': absent++; break;
                         case 'Excused': excused++; break;
                    }
                }
            });

             // Update summary display
             $('#summary-present').text(present);
             $('#summary-absent').text(absent);
             $('#summary-excused').text(excused);
             // $('#summary-total').text(total); // Keep total as overall count
        }


        // Add event listeners to update style on change
        document.querySelectorAll('.attendance-status').forEach(select => {
            select.addEventListener('change', function() {
                updateMiscRowStyle(this);
                updateSummaryCounts(); // Update summary on individual change
            });
        });

        // Quick filter function for DataTable
        function filterTable(status) {
            if (attendanceTable) {
                 // Remove active class from all filter buttons
                 $('.quick-filters .btn').removeClass('active btn-primary').addClass('btn-outline-secondary');
                 // Add active class to the clicked button
                 $('.quick-filters .btn').filter(function() { return $(this).text().trim() === status || (status === '' && $(this).text().trim() === 'All'); }).removeClass('btn-outline-secondary').addClass('active btn-primary');

                 // Apply search filter based on the status text within the select dropdown's selected option
                 if (status === '') {
                     attendanceTable.column(3).search('').draw(); // Search in the 4th column (index 3)
                 } else {
                     // Search for the exact status text
                     attendanceTable.column(3).search('^' + status + '$', true, false).draw();
                 }
            }
        }

         // Initial style update for rows on page load
         document.addEventListener('DOMContentLoaded', function() {
              document.querySelectorAll('.attendance-status').forEach(select => {
                  updateMiscRowStyle(select);
              });
              // Set initial active filter button
               $('.quick-filters .btn').filter(function() { return $(this).text().trim() === 'All'; }).removeClass('btn-outline-secondary').addClass('active btn-primary');
         });

    </script>
</body>
</html>

