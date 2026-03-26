<?php
require_once 'config.php';
require_once 'functions.php';

checkLogin();

$message = '';
$selectedDate = date('Y-m-d');
$selectedClass = '';
$attendanceData = [];

// Get teacher's assigned class if they're a teacher
if ($_SESSION['role'] == 'teacher') {
    $stmt = $pdo->prepare("
        SELECT ct.class_id, c.name as class_name, c.section 
        FROM class_teachers ct 
        JOIN classes c ON ct.class_id = c.id 
        JOIN teachers t ON ct.teacher_id = t.id 
        WHERE t.user_id = ? AND ct.is_active = TRUE
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $teacherClass = $stmt->fetch();
    
    if ($teacherClass) {
        $selectedClass = $teacherClass['class_id'];
    } else {
        $message = showAlert('You are not assigned as a class teacher to any class. Please contact the administrator.', 'info');
    }
}

// Handle date and class selection
if (isset($_GET['date'])) {
    $selectedDate = sanitize($_GET['date']);
}
if (isset($_GET['class_id']) && (in_array($_SESSION['role'], ['super_admin', 'admin']) || $selectedClass)) {
    $selectedClass = (int)$_GET['class_id'];
}

// Handle attendance submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = sanitize($_POST['attendance_date']);
    $class_id = (int)$_POST['class_id'];
    $attendance_data = $_POST['attendance'] ?? [];
    $arrival_times = $_POST['arrival_time'] ?? [];
    $departure_times = $_POST['departure_time'] ?? [];
    $remarks = $_POST['remarks'] ?? [];

    try {
        $pdo->beginTransaction();
        
        $updated_count = 0;
        foreach ($attendance_data as $student_id => $status) {
            $student_id = (int)$student_id;
            $arrival_time = !empty($arrival_times[$student_id]) ? $arrival_times[$student_id] : null;
            $departure_time = !empty($departure_times[$student_id]) ? $departure_times[$student_id] : null;
            $student_remarks = sanitize($remarks[$student_id] ?? '');

            // Insert or update attendance record
            $stmt = $pdo->prepare("
                INSERT INTO attendance 
                (student_id, class_id, attendance_date, status, arrival_time, departure_time, remarks, marked_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                arrival_time = VALUES(arrival_time),
                departure_time = VALUES(departure_time),
                remarks = VALUES(remarks),
                updated_by = VALUES(marked_by),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            if ($stmt->execute([$student_id, $class_id, $attendance_date, $status, $arrival_time, $departure_time, $student_remarks, $_SESSION['user_id']])) {
                $updated_count++;
            }
        }
        
        // Update attendance summary
        updateAttendanceSummary($class_id, $attendance_date);
        
        $pdo->commit();
        logAudit($_SESSION['user_id'], 'UPDATE', 'attendance', 0, '', "Updated attendance for $updated_count students");
        $message = showAlert("Attendance updated successfully for $updated_count students!");
        
    } catch (Exception $e) {
        $pdo->rollback();
        $message = showAlert('Error updating attendance: ' . $e->getMessage(), 'danger');
    }
}

// Load attendance data if class and date are selected
if ($selectedClass && $selectedDate) {
    $attendanceData = getAttendanceData($selectedClass, $selectedDate);
}

// Get available classes for admin
$availableClasses = [];
if (in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    $stmt = $pdo->query("
        SELECT c.*, ct.teacher_id, u.name as teacher_name 
        FROM classes c 
        LEFT JOIN class_teachers ct ON c.id = ct.class_id AND ct.is_active = TRUE
        LEFT JOIN teachers t ON ct.teacher_id = t.id
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY c.name, c.section
    ");
    $availableClasses = $stmt->fetchAll();
}

function getAttendanceData($class_id, $date) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT s.*, a.status, a.arrival_time, a.departure_time, a.remarks,
               a.marked_by, a.marked_at, a.updated_by, a.updated_at
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id AND a.attendance_date = ?
        WHERE s.class_id = ?
        ORDER BY s.roll_number
    ");
    $stmt->execute([$date, $class_id]);
    return $stmt->fetchAll();
}

function updateAttendanceSummary($class_id, $date) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_students,
            SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_count
        FROM attendance 
        WHERE class_id = ? AND attendance_date = ?
    ");
    $stmt->execute([$class_id, $date]);
    $stats = $stmt->fetch();
    
    $attendance_percentage = $stats['total_students'] > 0 ? 
        ($stats['present_count'] / $stats['total_students']) * 100 : 0;
    
    $stmt = $pdo->prepare("
        INSERT INTO attendance_summary 
        (class_id, attendance_date, total_students, present_count, absent_count, late_count, attendance_percentage)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        total_students = VALUES(total_students),
        present_count = VALUES(present_count),
        absent_count = VALUES(absent_count),
        late_count = VALUES(late_count),
        attendance_percentage = VALUES(attendance_percentage)
    ");
    
    $stmt->execute([$class_id, $date, $stats['total_students'], 
                   $stats['present_count'], $stats['absent_count'], 
                   $stats['late_count'], $attendance_percentage]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .attendance-card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .student-row {
            border-bottom: 1px solid #eee;
            padding: 15px;
        }
        .student-row:last-child {
            border-bottom: none;
        }
        .status-present { background-color: #d4edda; border-left: 4px solid #28a745; }
        .status-absent { background-color: #f8d7da; border-left: 4px solid #dc3545; }
        .status-late { background-color: #fff3cd; border-left: 4px solid #ffc107; }
        .status-early_departure { background-color: #d1ecf1; border-left: 4px solid #17a2b8; }
        .status-excused { background-color: #e2e3e5; border-left: 4px solid #6c757d; }
        
        .bulk-actions {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 15px;
        }
        .time-input {
            width: 100px;
        }
        .attendance-stats {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            padding: 15px;
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
                <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                    <a class="nav-link" href="class_teacher_management.php">Class Teachers</a>
                    <a class="nav-link" href="attendance_reports.php">Reports</a>
                <?php endif; ?>
                <a class="nav-link" href="manage_events.php">Miscelleneous</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-calendar-check me-2"></i>Daily Attendance</h2>
            <div class="d-flex gap-2">
                <input type="date" class="form-control" id="dateSelector" 
                       value="<?= $selectedDate ?>" onchange="changeDate()">
                <?php if ($selectedClass && $selectedDate): ?>
                    <a href="export_attendance.php?class_id=<?= $selectedClass ?>&date=<?= $selectedDate ?>" 
                       class="btn btn-success">
                        <i class="fas fa-file-excel me-2"></i>Export
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?= $message ?>

        <!-- Class Selection (for Admin) -->
        <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-school me-2"></i>Select Class</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($availableClasses as $class): ?>
                            <div class="col-md-3 mb-2">
                                <a href="?class_id=<?= $class['id'] ?>&date=<?= $selectedDate ?>" 
                                   class="btn <?= $selectedClass == $class['id'] ? 'btn-primary' : 'btn-outline-primary' ?> w-100">
                                    <?= $class['name'] ?> - <?= $class['section'] ?>
                                    <?php if ($class['teacher_name']): ?>
                                        <br><small><?= $class['teacher_name'] ?></small>
                                    <?php else: ?>
                                        <br><small class="text-warning">No Class Teacher</small>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Attendance Form -->
        <?php if ($selectedClass && !empty($attendanceData)): ?>
            <?php
            // Get class details
            $stmt = $pdo->prepare("SELECT name, section FROM classes WHERE id = ?");
            $stmt->execute([$selectedClass]);
            $classInfo = $stmt->fetch();
            
            // Calculate current stats
            $totalStudents = count($attendanceData);
            $presentCount = 0;
            $absentCount = 0;
            $lateCount = 0;
            
            foreach ($attendanceData as $student) {
                switch ($student['status']) {
                    case 'Present':
                        $presentCount++;
                        break;
                    case 'Absent':
                        $absentCount++;
                        break;
                    case 'Late':
                        $lateCount++;
                        break;
                }
            }
            
            $attendancePercentage = $totalStudents > 0 ? ($presentCount / $totalStudents) * 100 : 0;
            ?>

            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="attendance-stats">
                        <h5 class="text-white mb-3">
                            <?= $classInfo['name'] ?> - Section <?= $classInfo['section'] ?> 
                            (<?= formatDate($selectedDate) ?>)
                        </h5>
                        <div class="row text-center">
                            <div class="col-3">
                                <div style="font-size: 1.5rem; font-weight: bold;"><?= $totalStudents ?></div>
                                <small>Total Students</small>
                            </div>
                            <div class="col-3">
                                <div style="font-size: 1.5rem; font-weight: bold;"><?= $presentCount ?></div>
                                <small>Present</small>
                            </div>
                            <div class="col-3">
                                <div style="font-size: 1.5rem; font-weight: bold;"><?= $absentCount ?></div>
                                <small>Absent</small>
                            </div>
                            <div class="col-3">
                                <div style="font-size: 1.5rem; font-weight: bold;"><?= number_format($attendancePercentage, 1) ?>%</div>
                                <small>Attendance</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bulk-actions">
                        <h6 class="text-white mb-3">Bulk Actions</h6>
                        <div class="btn-group w-100 mb-2">
                            <button class="btn btn-light btn-sm" onclick="markAllAs('Present')">
                                <i class="fas fa-check"></i> All Present
                            </button>
                            <button class="btn btn-light btn-sm" onclick="markAllAs('Absent')">
                                <i class="fas fa-times"></i> All Absent
                            </button>
                        </div>
                        <button class="btn btn-light btn-sm w-100" onclick="setCurrentTime()">
                            <i class="fas fa-clock"></i> Set Current Time
                        </button>
                    </div>
                </div>
            </div>

            <form method="POST" id="attendanceForm">
                <input type="hidden" name="attendance_date" value="<?= $selectedDate ?>">
                <input type="hidden" name="class_id" value="<?= $selectedClass ?>">
                
                <div class="card attendance-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Student Attendance</h5>
                            <button type="submit" name="submit_attendance" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Attendance
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($attendanceData as $index => $student): ?>
                            <div class="student-row status-<?= strtolower(str_replace('_', '', $student['status'] ?: 'present')) ?>">
                                <div class="row align-items-center">
                                    <div class="col-md-3">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <strong><?= $student['roll_number'] ?></strong>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?= $student['name'] ?></div>
                                                <small class="text-muted"><?= $student['student_id'] ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <select class="form-select form-select-sm" 
                                                name="attendance[<?= $student['id'] ?>]" 
                                                onchange="updateRowStyle(this, <?= $index ?>)">
                                            <option value="Present" <?= $student['status'] == 'Present' ? 'selected' : '' ?>>Present</option>
                                            <option value="Absent" <?= $student['status'] == 'Absent' ? 'selected' : '' ?>>Absent</option>
                                            <option value="Late" <?= $student['status'] == 'Late' ? 'selected' : '' ?>>Late</option>
                                            <option value="Early_Departure" <?= $student['status'] == 'Early_Departure' ? 'selected' : '' ?>>Early Departure</option>
                                            <option value="Excused" <?= $student['status'] == 'Excused' ? 'selected' : '' ?>>Excused</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <input type="time" class="form-control form-control-sm time-input" 
                                               name="arrival_time[<?= $student['id'] ?>]" 
                                               value="<?= $student['arrival_time'] ?>"
                                               placeholder="Arrival">
                                    </div>
                                    
                                    <div class="col-md-2">
                                        <input type="time" class="form-control form-control-sm time-input" 
                                               name="departure_time[<?= $student['id'] ?>]" 
                                               value="<?= $student['departure_time'] ?>"
                                               placeholder="Departure">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <input type="text" class="form-control form-control-sm" 
                                               name="remarks[<?= $student['id'] ?>]" 
                                               value="<?= $student['remarks'] ?>"
                                               placeholder="Remarks (optional)">
                                    </div>
                                </div>
                                
                                <?php if ($student['marked_by']): ?>
                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Last updated: <?= formatDate($student['updated_at'] ?: $student['marked_at']) ?>
                                                <?php if ($student['updated_by']): ?>
                                                    (Modified)
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="card-footer">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted">
                                    <i class="fas fa-keyboard me-1"></i>
                                    Tip: Use Tab key to navigate quickly between fields
                                </small>
                            </div>
                            <button type="submit" name="submit_attendance" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Attendance
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        <?php elseif ($selectedClass && empty($attendanceData)): ?>
            <div class="alert alert-info">
                <h5><i class="fas fa-info-circle me-2"></i>No Students Found</h5>
                <p>No students are enrolled in the selected class, or there was an error loading the student list.</p>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>Select Class</h5>
                <p><?php if ($_SESSION['role'] == 'teacher'): ?>
                    You need to be assigned as a class teacher to take attendance.
                <?php else: ?>
                    Please select a class to view and manage attendance.
                <?php endif; ?></p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function changeDate() {
            const date = document.getElementById('dateSelector').value;
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('date', date);
            window.location.search = urlParams.toString();
        }

        function markAllAs(status) {
            const selects = document.querySelectorAll('select[name^="attendance["]');
            selects.forEach((select, index) => {
                select.value = status;
                updateRowStyle(select, index);
            });
            updateStats();
        }

        function setCurrentTime() {
            const now = new Date();
            const currentTime = now.getHours().toString().padStart(2, '0') + ':' + 
                               now.getMinutes().toString().padStart(2, '0');
            
            const arrivalInputs = document.querySelectorAll('input[name^="arrival_time["]');
            arrivalInputs.forEach(input => {
                if (!input.value) {
                    input.value = currentTime;
                }
            });
        }

        function updateRowStyle(selectElement, index) {
            const row = selectElement.closest('.student-row');
            const status = selectElement.value.toLowerCase().replace('_', '');
            
            // Remove existing status classes
            row.className = row.className.replace(/status-\w+/g, '');
            // Add new status class
            row.classList.add('status-' + status);
            
            updateStats();
        }

        function updateStats() {
            const selects = document.querySelectorAll('select[name^="attendance["]');
            let present = 0, absent = 0, late = 0, total = selects.length;
            
            selects.forEach(select => {
                switch(select.value) {
                    case 'Present':
                        present++;
                        break;
                    case 'Absent':
                        absent++;
                        break;
                    case 'Late':
                        late++;
                        break;
                }
            });
            
            const percentage = total > 0 ? ((present + late) / total * 100).toFixed(1) : 0;
            
            // Update the stats display (if elements exist)
            const statsElements = document.querySelectorAll('.attendance-stats div');
            if (statsElements.length >= 4) {
                statsElements[1].querySelector('div').textContent = present;
                statsElements[2].querySelector('div').textContent = absent;
                statsElements[3].querySelector('div').textContent = percentage + '%';
            }
        }

        // Auto-save functionality
        let autoSaveTimeout;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                // Optional: implement auto-save functionality
                console.log('Auto-save triggered');
            }, 30000); // 30 seconds
        }

        // Add event listeners to form elements
        document.querySelectorAll('select, input').forEach(element => {
            element.addEventListener('change', scheduleAutoSave);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('attendanceForm').submit();
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateStats();
        });
    </script>
</body>
</html>
