<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'functions.php';

checkLogin();
checkRole(['super_admin', 'admin']); // Only admins can assign

$message = '';
$eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);
$eventInfo = null;
$assignedStudents = [];
$availableStudents = []; // Students not yet assigned
$selectedClassFilter = $_GET['class_filter'] ?? ''; // Class ID to filter available students

if (!$eventId) {
    header("Location: manage_events.php?status=error&msg=" . urlencode("Invalid event specified."));
    exit;
}

// Fetch event details
try {
    $stmt = $pdo->prepare("SELECT * FROM attendance_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $eventInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$eventInfo) {
        throw new Exception("Event not found.");
    }
} catch (Exception $e) {
    header("Location: manage_events.php?status=error&msg=" . urlencode($e->getMessage()));
    exit;
}

// Handle POST actions (Add/Remove Students)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $redirectUrl = "assign_event_students.php?event_id=" . $eventId . "&class_filter=" . $selectedClassFilter;

    try {
        $pdo->beginTransaction();

        if ($action == 'add_students') {
            $student_ids_to_add = $_POST['student_ids'] ?? [];
            if (empty($student_ids_to_add)) throw new Exception("No students selected to add.");

            $added_count = 0;
            // Fetch class_id for selected students (assuming they belong to the filtered class)
            $placeholders = implode(',', array_fill(0, count($student_ids_to_add), '?'));
            $stmt_cls = $pdo->prepare("SELECT id, class_id FROM students WHERE id IN ($placeholders)");
            $stmt_cls->execute($student_ids_to_add);
            $students_with_class = $stmt_cls->fetchAll(PDO::FETCH_KEY_PAIR); // Fetch as [student_id => class_id]

            $stmt = $pdo->prepare("INSERT IGNORE INTO event_participants (event_id, student_id, class_id, added_by) VALUES (?, ?, ?, ?)");
            foreach ($student_ids_to_add as $student_id) {
                $student_id = (int)$student_id;
                $class_id = $students_with_class[$student_id] ?? null; // Get class_id
                if ($class_id) { // Only insert if class_id is found
                     if ($stmt->execute([$eventId, $student_id, $class_id, $_SESSION['user_id']])) {
                         if ($stmt->rowCount() > 0) $added_count++; // Only count successful inserts (IGNORE doesn't throw error)
                     }
                }
            }
            $pdo->commit();
            logAudit($_SESSION['user_id'], 'ADD_PARTICIPANTS', 'event_participants', $eventId, '', "Added $added_count students");
            header("Location: manage_events.php?status=students_added&count=$added_count"); // Redirect back to manage page
            exit;

        } elseif ($action == 'remove_student') {
            $participant_id = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);
            if (!$participant_id) throw new Exception("Invalid participant ID.");

            $stmt = $pdo->prepare("DELETE FROM event_participants WHERE id = ? AND event_id = ?"); // Added event_id check for safety
            if ($stmt->execute([$participant_id, $eventId])) {
                 if ($stmt->rowCount() > 0) {
                      $pdo->commit();
                      logAudit($_SESSION['user_id'], 'REMOVE_PARTICIPANT', 'event_participants', $participant_id);
                      header("Location: {$redirectUrl}&status=removed"); // Redirect back to this page
                      exit;
                 } else {
                      throw new Exception("Participant not found or already removed.");
                 }
            } else {
                 throw new Exception("Database error removing student.");
            }
        }
    } catch (Exception $e) {
        $pdo->rollback();
        // Stay on this page for errors
        $message = showAlert('Error: ' . $e->getMessage(), 'danger');
    }
}

// Check for removed status
if (isset($_GET['status']) && $_GET['status'] == 'removed') {
     $message = showAlert('Student removed from event successfully!');
}

// Fetch currently assigned students
try {
    $stmt_assigned = $pdo->prepare("
        SELECT ep.id as participant_id, s.id as student_id, s.name, s.roll_number, c.name as class_name, c.section
        FROM event_participants ep
        JOIN students s ON ep.student_id = s.id
        JOIN classes c ON ep.class_id = c.id
        WHERE ep.event_id = ?
        ORDER BY c.name, c.section, s.roll_number, s.name
    ");
    $stmt_assigned->execute([$eventId]);
    $assignedStudents = $stmt_assigned->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
     $message .= showAlert('Error loading assigned students: ' . $e->getMessage(), 'danger');
}

// Fetch available students if a class is selected
if ($selectedClassFilter) {
    try {
        $stmt_available = $pdo->prepare("
            SELECT s.id, s.name, s.roll_number
            FROM students s
            WHERE s.class_id = ? AND s.id NOT IN (SELECT student_id FROM event_participants WHERE event_id = ?)
            ORDER BY s.roll_number, s.name
        ");
        $stmt_available->execute([$selectedClassFilter, $eventId]);
        $availableStudents = $stmt_available->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message .= showAlert('Error loading available students: ' . $e->getMessage(), 'danger');
    }
}

// Get all classes for the filter dropdown
$classes = getAllClasses();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Assign Students to Event</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .table-container { max-height: 400px; overflow-y: auto; }
        #assignedStudentsTable_wrapper .row:first-child, #availableStudentsTable_wrapper .row:first-child { padding-bottom: 0.5rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary"> <div class="container"> <a class="navbar-brand" href="dashboard.php"><i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?></a> <div class="navbar-nav ms-auto"> <a class="nav-link" href="dashboard.php">Dashboard</a> <a class="nav-link" href="manage_events.php">Manage Events</a> <a class="nav-link" href="logout.php">Logout</a> </div> </div> </nav>

    <div class="container mt-4">
        <h2><i class="fas fa-user-plus me-2"></i>Assign Students to Event</h2>
        <h5 class="text-primary mb-3"><?= htmlspecialchars($eventInfo['name']) ?></h5>
        <?= $message ?>

        <div class="row">
            <!-- Section to Add Students -->
            <div class="col-md-6">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Add Students</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3 mb-3 align-items-end">
                             <input type="hidden" name="event_id" value="<?= $eventId ?>">
                            <div class="col-8">
                                <label for="class_filter" class="form-label">Filter by Class</label>
                                <select id="class_filter" name="class_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">-- Select Class --</option>
                                     <?php foreach ($classes as $class): ?>
                                         <option value="<?= $class['id'] ?>" <?= $selectedClassFilter == $class['id'] ? 'selected' : '' ?>>
                                             <?= htmlspecialchars($class['name']) ?> - <?= htmlspecialchars($class['section']) ?>
                                         </option>
                                     <?php endforeach; ?>
                                </select>
                            </div>
                             <div class="col-4">
                                 <button type="submit" class="btn btn-sm btn-secondary w-100">Load Students</button>
                             </div>
                        </form>

                        <?php if ($selectedClassFilter): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_students">
                                <?php if (!empty($availableStudents)): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                         <div class="form-check">
                                              <input class="form-check-input" type="checkbox" id="selectAllAvailable" onchange="toggleAllCheckboxes(this, 'available_students')">
                                              <label class="form-check-label" for="selectAllAvailable">Select All Available</label>
                                         </div>
                                         <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-plus me-1"></i>Add Selected</button>
                                    </div>
                                    <div class="table-container border rounded p-2">
                                        <?php foreach ($availableStudents as $student): ?>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input available_students" type="checkbox" name="student_ids[]" value="<?= $student['id'] ?>" id="student_<?= $student['id'] ?>">
                                            <label class="form-check-label" for="student_<?= $student['id'] ?>">
                                                <?= htmlspecialchars($student['roll_number']) ?> - <?= htmlspecialchars($student['name']) ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($selectedClassFilter && empty($availableStudents)) : ?>
                                     <p class="text-muted mt-3">All students from this class are already assigned or the class is empty.</p>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <p class="text-muted">Select a class to see available students.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section for Currently Assigned Students -->
            <div class="col-md-6">
                 <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Currently Assigned Students (<?= count($assignedStudents) ?>)</h6>
                    </div>
                    <div class="card-body p-0">
                         <?php if (empty($assignedStudents)): ?>
                             <p class="text-muted p-3">No students assigned to this event yet.</p>
                         <?php else: ?>
                            <div class="table-responsive">
                                <table id="assignedStudentsTable" class="table table-sm table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Roll No.</th>
                                            <th>Name</th>
                                            <th>Class</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assignedStudents as $student): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($student['roll_number']) ?></td>
                                            <td><?= htmlspecialchars($student['name']) ?></td>
                                            <td><?= htmlspecialchars($student['class_name']) ?> - <?= htmlspecialchars($student['section']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this student?')">
                                                     <input type="hidden" name="action" value="remove_student">
                                                     <input type="hidden" name="participant_id" value="<?= $student['participant_id'] ?>">
                                                     <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Remove">
                                                         <i class="fas fa-times fa-fw"></i>
                                                     </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                         <?php endif; ?>
                    </div>
                </div>
            </div>
        </div> <!-- End Row -->

    </div> <!-- End Container -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            if ($('#assignedStudentsTable').length) {
                $('#assignedStudentsTable').DataTable({
                    pageLength: 10, // Fewer rows for assigned list
                    order: [[ 2, 'asc' ], [0, 'asc']], // Sort by Class, then Roll
                    searching: true, // Allow searching assigned students
                    lengthChange: false // Hide show entries dropdown
                });
            }
        });

        function toggleAllCheckboxes(source, className) {
             checkboxes = document.getElementsByClassName(className);
             for(var i=0, n=checkboxes.length; i<n; i++) {
                 checkboxes[i].checked = source.checked;
             }
        }
    </script>

</body>
</html>
