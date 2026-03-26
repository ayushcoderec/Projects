<?php
ini_set('display_errors', 1); // Show errors on screen (useful for development)
// ini_set('log_errors', 1); // Ensure errors are logged
// ini_set('error_log', '/path/to/your/php-error.log'); // Specify log file path if needed
error_reporting(E_ALL);
require_once 'config.php';
require_once 'functions.php';

// --- SESSION & ROLE CHECKS ---
checkLogin();
if (!in_array($_SESSION['role'], ['super_admin', 'admin', 'teacher'])) {
    header("Location: dashboard.php");
    exit;
}

// --- INITIALIZE VARIABLES ---
$message = '';
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$selectedDate = isset($_GET['date']) ? sanitize($_GET['date']) : date('Y-m-d');
$eventInfo = null;
$participantData = [];
$classInfo = null;
$teacherClassId = null;

// --- GET TEACHER CLASS (if applicable) ---
if ($_SESSION['role'] == 'teacher') {
    try {
        $stmt_tc = $pdo->prepare("SELECT ct.class_id, c.name, c.section FROM class_teachers ct JOIN teachers t ON ct.teacher_id = t.id JOIN classes c ON ct.class_id = c.id WHERE t.user_id = ? AND ct.is_active = TRUE");
        $stmt_tc->execute([$_SESSION['user_id']]);
        $teacherClass = $stmt_tc->fetch(PDO::FETCH_ASSOC);
        if ($teacherClass) {
            $teacherClassId = $teacherClass['class_id'];
            $classInfo = $teacherClass;
            // Only default if no class is selected via GET *and* teacher role
             if (!isset($_GET['class_id'])) {
                 // Note: This page doesn't use class_id filter directly, relies on event participants
            }
        } else {
            $message = showAlert('You are not assigned as a class teacher. Attendance marking might be restricted.', 'warning');
            $eventId = null; // Prevent loading data if not assigned? Or allow viewing? Decide policy.
        }
    } catch(PDOException $e) {
         $message = showAlert('Error checking teacher assignment: ' . $e->getMessage(), 'danger');
         $eventId = null; // Prevent loading on DB error
    }
}

// --- GET URL PARAMETERS (Override defaults if set) ---
if (isset($_GET['class_id'])) {
    // Although not directly used for filtering data query (uses participants), keep for context if needed
    //$selectedClass = (int)$_GET['class_id'];
}
if (isset($_GET['date'])) $selectedDate = sanitize($_GET['date']);
// Event ID already filtered above

// --- TEACHER SECURITY CHECK (Applied during data loading & saving) ---
// If a teacher tries to access via GET params for event/date related to other classes,
// the data loading query below handles filtering. Saving also checks.

// --- FETCH EVENT DETAILS ---
if ($eventId) {
    try {
        $stmt_event = $pdo->prepare("SELECT * FROM attendance_events WHERE id = ?");
        $stmt_event->execute([$eventId]);
        $eventInfo = $stmt_event->fetch(PDO::FETCH_ASSOC);
        if (!$eventInfo) {
            throw new Exception("Event not found.");
        }
        // If date wasn't passed in GET and event has a date, use event date as default ONLY if GET date isn't set
        if (!isset($_GET['date']) && $eventInfo['event_date']) {
             $selectedDate = $eventInfo['event_date'];
        }

    } catch (Exception $e) {
        $message .= showAlert('Error loading event details: ' . $e->getMessage(), 'danger');
        $eventId = null; // Invalidate if error
        $eventInfo = null;
    }
}

// --- HANDLE POST REQUEST (SAVE ATTENDANCE) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_misc_attendance') {
    error_log("--- Starting Save Misc Attendance ---"); // DEBUG

    $post_event_id = filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT);
    $post_date = sanitize($_POST['attendance_date']);
    $attendance_status = $_POST['attendance'] ?? []; // Submitted statuses [student_id => status]
    $remarks = $_POST['remarks'] ?? [];             // Submitted remarks [student_id => remark]

    error_log("Save Request - Event ID: " . var_export($post_event_id, true)); // DEBUG
    error_log("Save Request - Date: " . var_export($post_date, true)); // DEBUG
    error_log("Save Request - Received Status Array Count: " . count($attendance_status)); // DEBUG
    // error_log("Save Request - Received Status Array: " . var_export($attendance_status, true)); // DEBUG - Can be large, uncomment if needed

    if ($post_event_id && $post_date) {
        // Security check placeholder (can be enhanced)
        $is_allowed_to_save = true;
        if ($_SESSION['role'] == 'teacher' && !$teacherClassId) {
             $is_allowed_to_save = false; // Teacher not assigned to any class
             $message = showAlert('Unauthorized: You are not assigned as a class teacher.', 'danger');
             error_log("Unauthorized save attempt: Teacher user ID {$_SESSION['user_id']} not assigned to a class."); // DEBUG
        }

        if (!$is_allowed_to_save) {
             // Message already set
        } else {
            try {
                $pdo->beginTransaction();
                $processed_count = 0;
                $inserted_count = 0; // DEBUG
                $updated_count = 0; // DEBUG

                // Get ALL participant student IDs AND their class IDs for THIS event
                $sql_participants = "SELECT ep.student_id, ep.class_id FROM event_participants ep WHERE ep.event_id = ?";
                $params_participants = [$post_event_id];

                // If user is a teacher, ONLY fetch participants belonging to THEIR assigned class
                if ($_SESSION['role'] == 'teacher' && $teacherClassId) {
                    $sql_participants .= " AND ep.class_id = ?";
                    $params_participants[] = $teacherClassId;
                    error_log("Teacher Save: Filtering participants for class ID: $teacherClassId"); // DEBUG
                }

                $stmt_participants = $pdo->prepare($sql_participants);
                $stmt_participants->execute($params_participants);
                $event_participants = $stmt_participants->fetchAll(PDO::FETCH_ASSOC); // Get [ {student_id: X, class_id: Y}, ... ]

                error_log("Found " . count($event_participants) . " participants matching criteria for Event ID: " . $post_event_id); // DEBUG

                if (empty($event_participants)) {
                     // Don't throw exception, just show message and commit (nothing to save)
                     $pdo->commit(); // Commit empty transaction
                     $message = showAlert("No participants found to save attendance for." . ($_SESSION['role']=='teacher' ? ' in your class' : ''), 'info');
                     error_log("No participants found for saving."); // DEBUG
                } else {

                    $stmt_save = $pdo->prepare("
                        INSERT INTO miscellaneous_attendance
                        (student_id, class_id, event_id, attendance_date, status, remarks, marked_by)
                        VALUES (:sid, :cid, :eid, :adate, :status, :remarks, :marker)
                        ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        remarks = VALUES(remarks),
                        marked_by = VALUES(marked_by),
                        updated_at = CURRENT_TIMESTAMP
                    ");

                    foreach ($event_participants as $participant) {
                        $student_id = (int)$participant['student_id'];
                        $student_class_id = (int)$participant['class_id'];

                        // Determine status: Use submitted value, default to 'No' if no radio was selected/submitted for this student
                        $status = $attendance_status[$student_id] ?? 'No';
                        if (!in_array($status, ['Yes', 'No', 'Excused'])) {
                            error_log("Invalid status '$status' received for student ID $student_id, defaulting to 'No'"); // DEBUG
                            $status = 'No'; // Force valid default
                        }

                        $student_remarks = sanitize($remarks[$student_id] ?? '');

                        error_log("Processing Student ID: $student_id, Class ID: $student_class_id, Status: $status, Remarks: '$student_remarks'"); // DEBUG

                        $exec_result = $stmt_save->execute([
                            ':sid' => $student_id,
                            ':cid' => $student_class_id,
                            ':eid' => $post_event_id,
                            ':adate' => $post_date,
                            ':status' => $status,
                            ':remarks' => $student_remarks,
                            ':marker' => $_SESSION['user_id']
                        ]);

                        if ($exec_result) {
                            $processed_count++;
                             // Check rowCount specifically for debug
                             $rc = $stmt_save->rowCount();
                             error_log("Student ID $student_id - Execute success. rowCount: $rc"); // DEBUG
                             if ($rc == 1) $inserted_count++;
                             if ($rc == 2) $updated_count++;
                        } else {
                             // This block might not be reached if PDO throws exceptions on error
                             error_log("PDO execute() returned false for student ID: $student_id - Error Info: " . var_export($stmt_save->errorInfo(), true)); // DEBUG with error info
                             // Consider throwing an exception here to ensure rollback
                             // throw new Exception("Failed to save attendance for student ID $student_id");
                        }
                    } // end foreach

                    error_log("Finished processing loop. Processed Count: $processed_count (Inserts: $inserted_count, Updates: $updated_count)"); // DEBUG

                    $pdo->commit();
                    error_log("Transaction committed successfully."); // DEBUG

                    logAudit($_SESSION['user_id'], 'UPDATE', 'miscellaneous_attendance', 0, '', "Misc Attend Save: Evt $post_event_id, Dt $post_date, Cnt $processed_count");

                    header("Location: mark_misc_attendance.php?event_id=$post_event_id&date=$post_date&status=saved&count=$processed_count");
                    exit;
                } // end else (if participants found)

            } catch (Exception $e) {
                $pdo->rollback();
                error_log("Error saving attendance (Exception): " . $e->getMessage()); // DEBUG - Log the specific error
                error_log("Stack Trace: " . $e->getTraceAsString()); // DEBUG - Get more detail
                $message = showAlert('Error saving attendance: ' . $e->getMessage(), 'danger');
                $selectedEvent = $post_event_id; // Keep selections for form redisplay
                $selectedDate = $post_date;
                // No redirect on error
            }
        }
    } else {
        $message = showAlert('Missing Event ID or Date for saving.', 'warning');
         error_log("Save attempt failed: Missing Event ID or Date."); // DEBUG
    }
}

// --- CHECK FOR REDIRECT STATUS MESSAGE ---
if (isset($_GET['status']) && $_GET['status'] == 'saved' && empty($message)) { // Check empty($message) to avoid overwriting POST errors
     $count = $_GET['count'] ?? '?';
     $message = showAlert("Attendance saved/updated for {$count} records.", 'success');
}


// --- LOAD DATA FOR DISPLAY ---
$presentCount = $noCount = $excusedCount = 0;
if ($eventId && $selectedDate && $eventInfo) {
    try {
        $sql = "
            SELECT
                ep.student_id,
                s.name as student_name,
                s.roll_number,
                c.name as class_name,
                c.section,
                COALESCE(ma.status, 'No') as status, -- Default to No if no record exists for this date/event
                ma.remarks
            FROM event_participants ep
            JOIN students s ON ep.student_id = s.id
            JOIN classes c ON ep.class_id = c.id
            LEFT JOIN miscellaneous_attendance ma ON ep.student_id = ma.student_id
                                                AND ep.event_id = ma.event_id
                                                AND ma.attendance_date = :attendance_date
            WHERE ep.event_id = :event_id ";

        $params = [':event_id' => $eventId, ':attendance_date' => $selectedDate];

        // If user is a teacher, restrict to their class participants ONLY
        if ($_SESSION['role'] == 'teacher' && $teacherClassId) {
             $sql .= " AND ep.class_id = :class_id ";
             $params[':class_id'] = $teacherClassId;
        }

        $sql .= " ORDER BY c.name, c.section, s.roll_number, s.name";

        $stmt_parts = $pdo->prepare($sql);
        $stmt_parts->execute($params);
        $participantData = $stmt_parts->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats after fetching data
        foreach($participantData as $p) {
             switch ($p['status']) { // Use status directly now (includes COALESCE default)
                  case 'Yes': $presentCount++; break;
                  case 'No': $noCount++; break;
                  case 'Excused': $excusedCount++; break;
             }
        }

    } catch (PDOException $e) {
        $message .= showAlert('Error loading participant data: ' . $e->getMessage(), 'danger');
        $participantData = []; // Ensure data is empty on error
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Mark Misc Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .attendance-table tbody tr { transition: background-color 0.2s ease; }
        .status-Yes { background-color: #d1e7dd !important; } /* Green */
        .status-No { background-color: #f8d7da !important; } /* Red */
        .status-Excused { background-color: #e2e3e5 !important; } /* Grey */
        .summary-card { background-color: #fff; border-radius: 10px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 4px solid #0d6efd; }
         .summary-card h6 { color: #6c757d; }
         .summary-card .display-6 { font-weight: 500; }
        #attendanceTable_filter input { margin-left: 0.5em; }
        /* Style Yes/No Radios like buttons */
        .btn-group-attendance .form-check-input { display: none; }
        .btn-group-attendance .form-check-label {
            border: 1px solid #dee2e6; padding: 0.25rem 0.6rem; border-radius: 0.25rem; cursor: pointer;
            background-color: #fff; color: #6c757d; font-size: 0.85em; /* Slightly smaller */
            transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, color 0.15s ease-in-out;
             white-space: nowrap; /* Prevent wrapping */
        }
        .btn-group-attendance .form-check-input:checked + .form-check-label { z-index: 1; box-shadow: 0 0 0 0.1rem rgba(13,110,253,.25); }
        .btn-group-attendance .form-check-input[value="Yes"]:checked + .form-check-label { background-color: #198754; border-color: #198754; color: white; }
        .btn-group-attendance .form-check-input[value="No"]:checked + .form-check-label { background-color: #dc3545; border-color: #dc3545; color: white; }
        .btn-group-attendance .form-check-input[value="Excused"]:checked + .form-check-label { background-color: #6c757d; border-color: #6c757d; color: white; }
        /* Ensure table cells don't wrap excessively */
         #attendanceTable td { white-space: nowrap; }
         #attendanceTable td:nth-child(2) { white-space: normal; } /* Allow student name to wrap */
         #attendanceTable td:nth-child(5) { white-space: normal; } /* Allow remarks to wrap */
    </style>
</head>
<body>
     <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
         <div class="container">
             <a class="navbar-brand" href="dashboard.php"><i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?></a>
              <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavAltMarkup">
               <span class="navbar-toggler-icon"></span>
             </button>
             <div class="collapse navbar-collapse" id="navbarNavAltMarkup">
               <div class="navbar-nav ms-auto">
                   <a class="nav-link" href="dashboard.php">Dashboard</a>
                   <a class="nav-link" href="manage_events.php">Manage Events</a>
                   <a class="nav-link active" href="mark_misc_attendance.php">Mark Attendance</a>
                   <a class="nav-link" href="misc_attendance_reports.php">Misc Reports</a>
                   <a class="nav-link" href="logout.php">Logout</a>
               </div>
             </div>
         </div>
     </nav>

     <div class="container mt-4">
         <h2><i class="fas fa-user-check me-2"></i>Mark Miscellaneous Attendance</h2>
         <?= $message ?>

          <?php if (!$eventId || !$eventInfo): ?>
             <div class="alert alert-warning">Please select an event from the <a href="manage_events.php">Manage Events</a> page.</div>
          <?php else: ?>
             <!-- Event Info and Date Selector -->
             <div class="card shadow-sm mb-3">
                 <div class="card-body">
                      <div class="row align-items-center">
                           <div class="col-md-6">
                                <h5 class="mb-1"><?= htmlspecialchars($eventInfo['name']) ?> <span class="badge bg-secondary"><?= htmlspecialchars($eventInfo['event_type']) ?></span></h5>
                                <p class="mb-0 text-muted">
                                    <?php if ($classInfo): // Display teacher's class if applicable ?>
                                         Class Context: <?= htmlspecialchars($classInfo['name']) ?> - <?= htmlspecialchars($classInfo['section']) ?> |
                                    <?php endif; ?>
                                    Date:
                                </p>
                           </div>
                           <div class="col-md-3">
                                 <input type="date" class="form-control form-control-sm" id="dateSelector"
                                       value="<?= $selectedDate ?>" onchange="changeAttendanceDate()">
                           </div>
                            <div class="col-md-3 text-end">
                                <a href="assign_event_students.php?event_id=<?= $eventId ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-users me-1"></i> Manage Participants
                                </a>
                           </div>
                      </div>
                 </div>
             </div>

             <?php if (empty($participantData)): ?>
                 <div class="alert alert-info">
                     No students are assigned to this event<?= ($_SESSION['role']=='teacher' && $teacherClassId ? ' for your class ('.$classInfo['name'].' - '.$classInfo['section'].')' : '') ?>.
                     <a href="assign_event_students.php?event_id=<?= $eventId ?>">Assign students here</a>.
                 </div>
             <?php else: ?>
                 <!-- Summary Stats -->
                 <div class="row mb-3">
                     <div class="col-md-3"><div class="summary-card text-center"><h6 class="text-success">Yes (Present)</h6><div class="display-6 text-success" id="summary-yes"><?= $presentCount ?></div></div></div>
                     <div class="col-md-3"><div class="summary-card text-center"><h6 class="text-danger">No (Absent)</h6><div class="display-6 text-danger" id="summary-no"><?= $noCount ?></div></div></div>
                     <div class="col-md-3"><div class="summary-card text-center"><h6 class="text-secondary">Excused</h6><div class="display-6 text-secondary" id="summary-excused"><?= $excusedCount ?></div></div></div>
                     <div class="col-md-3"><div class="summary-card text-center"><h6>Total Assigned</h6><div class="display-6" id="summary-total"><?= count($participantData) ?></div></div></div>
                 </div>

                 <!-- Attendance Form -->
                 <form method="POST" id="attendanceForm">
                      <input type="hidden" name="action" value="save_misc_attendance">
                      <input type="hidden" name="event_id" value="<?= $eventId ?>">
                      <input type="hidden" name="attendance_date" value="<?= $selectedDate ?>">
                       <!-- Include class context if teacher -->
                       <?php if ($_SESSION['role'] == 'teacher' && $teacherClassId): ?>
                            <input type="hidden" name="class_id_context" value="<?= $teacherClassId ?>">
                       <?php endif; ?>


                      <div class="card shadow-sm">
                           <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                     <h5 class="mb-0">Mark Attendance</h5>
                                     <div>
                                          <button type="button" class="btn btn-sm btn-outline-success" onclick="markAllAs('Yes')">Mark All Yes</button>
                                          <button type="button" class="btn btn-sm btn-outline-danger" onclick="markAllAs('No')">Mark All No</button>
                                          <button type="submit" class="btn btn-primary ms-2"><i class="fas fa-save me-1"></i>Save Attendance</button>
                                     </div>
                                </div>
                           </div>
                           <div class="card-body p-0">
                                <div class="table-responsive">
                                     <table id="attendanceTable" class="table table-hover mb-0 attendance-table">
                                          <thead class="table-light">
                                               <tr>
                                                   <th>Roll No.</th>
                                                   <th>Student Name</th>
                                                   <th>Class</th>
                                                   <th>Attendance</th>
                                                   <th>Remarks</th>
                                               </tr>
                                          </thead>
                                          <tbody>
                                          <?php foreach ($participantData as $index => $p): ?>
                                              <?php $currentStatus = $p['status'] ?? 'No'; // Default to No if NULL ?>
                                              <tr class="student-row status-<?= $currentStatus ?>" id="student-row-<?= $index ?>">
                                                  <td><?= htmlspecialchars($p['roll_number'] ?? 'N/A') ?></td>
                                                  <td><?= htmlspecialchars($p['student_name']) ?></td>
                                                  <td><?= htmlspecialchars($p['class_name']) . ($p['section'] ? ' - ' . htmlspecialchars($p['section']) : '') ?></td>
                                                  <td>
                                                      <div class="btn-group btn-group-sm btn-group-attendance" role="group">
                                                          <?php
                                                          $statuses = ['Yes', 'No', 'Excused'];
                                                          foreach ($statuses as $statusOption):
                                                              $checked = ($currentStatus == $statusOption) ? 'checked' : '';
                                                              $inputId = "status_{$p['student_id']}_{$statusOption}";
                                                          ?>
                                                          <input type="radio" class="form-check-input attendance-status"
                                                                 name="attendance[<?= $p['student_id'] ?>]"
                                                                 id="<?= $inputId ?>"
                                                                 value="<?= $statusOption ?>"
                                                                 <?= $checked ?>
                                                                 data-row-index="<?= $index ?>">
                                                          <label class="form-check-label btn btn-outline-secondary" for="<?= $inputId ?>">
                                                              <?= $statusOption == 'Yes' ? '<i class="fas fa-check"></i> Yes' : ($statusOption == 'No' ? '<i class="fas fa-times"></i> No' : '<i class="fas fa-ban"></i> Exc') ?>
                                                          </label>
                                                          <?php endforeach; ?>
                                                      </div>
                                                  </td>
                                                  <td>
                                                      <input type="text" class="form-control form-control-sm"
                                                             name="remarks[<?= $p['student_id'] ?>]"
                                                             value="<?= htmlspecialchars($p['remarks'] ?? '') ?>"
                                                             placeholder="Optional remarks" style="min-width: 150px;">
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
             <?php endif; ?>
          <?php endif; ?> <!-- End check for valid event -->

     </div><!-- End Container -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            if ($('#attendanceTable').length) {
                $('#attendanceTable').DataTable({
                     pageLength: 50, // Show more students
                     order: [[ 0, 'asc' ]], // Sort by Roll No.
                     columnDefs: [ { orderable: false, targets: [3, 4] } ], // Disable sort on status/remarks
                     language: { search: "_INPUT_", searchPlaceholder: "Search students..." }
                });
            }
        });

        function changeAttendanceDate() {
            const date = document.getElementById('dateSelector').value;
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('date', date);
            window.location.search = urlParams.toString(); // Reload page with new date
        }

        function markAllAs(status) {
            const radios = document.querySelectorAll(`.attendance-status[value="${status}"]`);
            radios.forEach(radio => {
                radio.checked = true;
                updateRowStyleFromRadio(radio); // Update style based on the clicked radio
            });
            updateSummaryCounts(); // Update summary
        }

        // Update row style based on the radio button clicked/changed
        function updateRowStyleFromRadio(radioElement) {
            const rowIndex = radioElement.getAttribute('data-row-index');
            // Use querySelector for robustness, especially with DataTables potentially reordering rows
            const row = document.querySelector(`#student-row-${rowIndex}`);
            if (row) {
                const status = radioElement.value;
                // Use classList for modern browsers
                row.classList.remove('status-Yes', 'status-No', 'status-Excused');
                row.classList.add('status-' + status);
            } else {
                 console.warn("Could not find row for index:", rowIndex);
            }
        }

         function updateSummaryCounts() {
            let yes = 0, no = 0, excused = 0;
            // Query only the radio buttons that are currently checked
            const checkedRadios = document.querySelectorAll('.attendance-status:checked');

            checkedRadios.forEach(radio => {
                switch(radio.value) {
                    case 'Yes': yes++; break;
                    case 'No': no++; break;
                    case 'Excused': excused++; break;
                }
            });

             // Update summary display using jQuery for simplicity here
             $('#summary-yes').text(yes);
             $('#summary-no').text(no);
             $('#summary-excused').text(excused);
             // Total assigned count doesn't change based on status changes
        }

        // Add event listeners to radio buttons using event delegation on the table body
        const tableBody = document.querySelector('#attendanceTable tbody');
        if(tableBody) {
             tableBody.addEventListener('change', function(event) {
                 if (event.target.classList.contains('attendance-status')) {
                     updateRowStyleFromRadio(event.target);
                     updateSummaryCounts();
                 }
             });
        }


         // Initial style update for rows on page load
         document.addEventListener('DOMContentLoaded', function() {
              document.querySelectorAll('.attendance-status:checked').forEach(radio => {
                  updateRowStyleFromRadio(radio);
              });
         });
    </script>
</body>
</html>

