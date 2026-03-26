<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'functions.php';

checkLogin();
// Only Admins/Super Admins can manage events
checkRole(['super_admin', 'admin']);

$message = '';

// Handle POST actions (Add/Edit Event)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $redirectUrl = 'manage_events.php';

    try {
        if ($action == 'add_event' || $action == 'edit_event') {
            $event_id = ($action == 'edit_event') ? filter_input(INPUT_POST, 'event_id', FILTER_VALIDATE_INT) : null;
            $name = sanitize($_POST['event_name']);
            $type = sanitize($_POST['event_type']);
            $description = sanitize($_POST['description'] ?? '');
            $date = !empty($_POST['event_date']) ? sanitize($_POST['event_date']) : null;
            $requires_attendance = isset($_POST['requires_attendance']) ? 1 : 0;
            $status = sanitize($_POST['status']);

            // Validation
            if (empty($name) || empty($type) || empty($status)) {
                throw new Exception("Event Name, Type, and Status are required.");
            }
            if ($action == 'edit_event' && !$event_id) {
                 throw new Exception("Invalid Event ID for editing.");
            }

            if ($action == 'add_event') {
                $stmt = $pdo->prepare("INSERT INTO attendance_events (name, event_type, description, event_date, requires_attendance, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $params = [$name, $type, $description, $date, $requires_attendance, $status, $_SESSION['user_id']];
                $logAction = 'CREATE';
                $successStatus = 'added';
            } else { // edit_event
                $stmt = $pdo->prepare("UPDATE attendance_events SET name=?, event_type=?, description=?, event_date=?, requires_attendance=?, status=? WHERE id=?");
                $params = [$name, $type, $description, $date, $requires_attendance, $status, $event_id];
                $logAction = 'UPDATE';
                $successStatus = 'updated';
            }

            if ($stmt->execute($params)) {
                $logId = ($action == 'add_event') ? $pdo->lastInsertId() : $event_id;
                logAudit($_SESSION['user_id'], $logAction, 'attendance_events', $logId, '', "Event: $name");
                header("Location: {$redirectUrl}?status={$successStatus}");
                exit;
            } else {
                throw new Exception("Database error saving event.");
            }
        }
    } catch (Exception $e) {
         header("Location: {$redirectUrl}?status=error&msg=" . urlencode($e->getMessage()));
         exit;
    }
}


// Check for status messages from redirect
if (isset($_GET['status'])) {
     if ($_GET['status'] == 'added') $message = showAlert('Event created successfully!');
     elseif ($_GET['status'] == 'updated') $message = showAlert('Event updated successfully!');
     elseif ($_GET['status'] == 'students_added') $message = showAlert('Students added to event successfully!');
     elseif ($_GET['status'] == 'students_removed') $message = showAlert('Students removed from event successfully!');
     elseif ($_GET['status'] == 'error') $message = showAlert('Error: ' . htmlspecialchars(urldecode($_GET['msg'] ?? 'Unknown error')), 'danger');
}


// Fetch existing events
$events = [];
try {
    $stmt = $pdo->query("SELECT * FROM attendance_events ORDER BY event_date DESC, name ASC");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message .= showAlert('Error fetching events: ' . $e->getMessage(), 'danger');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Manage Events</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style> /* Basic styling */ </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
             <a class="navbar-brand" href="dashboard.php"><i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?></a>
             <div class="navbar-nav ms-auto">
                 <a class="nav-link" href="dashboard.php">Dashboard</a>
                 <a class="nav-link" href="miscellaneous_attendance.php">Misc Attendance</a>
                 <a class="nav-link" href="logout.php">Logout</a>
             </div>
         </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-calendar-alt me-2"></i>Manage Attendance Events</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#eventModal" onclick="prepareEventModal('add')">
                <i class="fas fa-plus me-2"></i>Add New Event
            </button>
        </div>
        <?= $message ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="eventsTable" class="table table-hover">
                        <thead>
                            <tr>
                                <th>Event Title</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Attendance Req.</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= htmlspecialchars($event['name']) ?></td>
                                <td><?= htmlspecialchars($event['event_type']) ?></td>
                                <td><?= $event['event_date'] ? formatDate($event['event_date']) : 'N/A' ?></td>
                                <td><?= $event['requires_attendance'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                                <td><?= $event['status'] == 'Active' ? '<span class="badge bg-primary">Active</span>' : '<span class="badge bg-warning text-dark">Inactive</span>' ?></td>
                                <td>
                                    <div class="btn-group">
                                         <a href="assign_event_students.php?event_id=<?= $event['id'] ?>" class="btn btn-sm btn-outline-info" title="Assign Students">
                                             <i class="fas fa-user-plus fa-fw"></i>
                                         </a>
                                         <a href="mark_misc_attendance.php?event_id=<?= $event['id'] ?>&date=<?= $event['event_date'] ?? date('Y-m-d') ?>" class="btn btn-sm btn-outline-success" title="Mark Attendance">
                                             <i class="fas fa-check-square fa-fw"></i>
                                         </a>
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#eventModal"
                                                onclick='prepareEventModal("edit", <?= json_encode($event) ?>)'
                                                title="Edit Event">
                                            <i class="fas fa-edit fa-fw"></i>
                                        </button>
                                        <!-- Add Delete button if needed, with confirmation -->
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

    <!-- Add/Edit Event Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="eventForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="eventModalTitle">Add New Event</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" id="event_action">
                        <input type="hidden" name="event_id" id="event_id">

                        <div class="mb-3">
                            <label class="form-label">Event Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="event_name" id="event_name" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Event Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="event_type" id="event_type" required>
                                    <option value="Competition">Competition</option>
                                    <option value="Workshop">Workshop</option>
                                    <option value="Seminar">Seminar</option>
                                    <option value="Extra Class">Extra Class</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Event Date (Optional)</label>
                                <input type="date" class="form-control" name="event_date" id="event_date">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description (Optional)</label>
                            <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                        </div>
                        <div class="row">
                             <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" id="status" required>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3 d-flex align-items-center">
                                 <div class="form-check form-switch mt-3">
                                      <input class="form-check-input" type="checkbox" role="switch" id="requires_attendance" name="requires_attendance" value="1" checked>
                                      <label class="form-check-label" for="requires_attendance">Requires Attendance Marking?</label>
                                 </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="eventSubmitBtn">Save Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#eventsTable').DataTable({ order: [[2, 'desc']] }); // Order by date descending
        });

        function prepareEventModal(mode, eventData = null) {
            const form = document.getElementById('eventForm');
            form.reset(); // Clear previous data

            if (mode === 'add') {
                document.getElementById('eventModalTitle').textContent = 'Add New Event';
                document.getElementById('event_action').value = 'add_event';
                document.getElementById('event_id').value = '';
                document.getElementById('requires_attendance').checked = true; // Default to yes
                document.getElementById('status').value = 'Active'; // Default
                document.getElementById('eventSubmitBtn').textContent = 'Create Event';
            } else if (mode === 'edit' && eventData) {
                document.getElementById('eventModalTitle').textContent = 'Edit Event';
                document.getElementById('event_action').value = 'edit_event';
                document.getElementById('event_id').value = eventData.id;
                document.getElementById('event_name').value = eventData.name;
                document.getElementById('event_type').value = eventData.event_type;
                document.getElementById('description').value = eventData.description || '';
                document.getElementById('event_date').value = eventData.event_date || '';
                document.getElementById('requires_attendance').checked = eventData.requires_attendance == 1;
                document.getElementById('status').value = eventData.status;
                document.getElementById('eventSubmitBtn').textContent = 'Update Event';
            }
        }
    </script>
</body>
</html>
