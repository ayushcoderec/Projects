<?php
require_once 'config.php';
require_once 'functions.php';

checkLogin();

$message = '';
$selectedDate = date('Y-m-d');
$selectedClass = '';
$selectedMonth = date('Y-m');
$reportType = 'daily';

// Handle filters
if (isset($_GET['date'])) {
    $selectedDate = sanitize($_GET['date']);
}
if (isset($_GET['class_id'])) {
    $selectedClass = (int)$_GET['class_id'];
}
if (isset($_GET['month'])) {
    $selectedMonth = sanitize($_GET['month']);
}
if (isset($_GET['report_type'])) {
    $reportType = sanitize($_GET['report_type']);
}

// Get available classes
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
} else if ($_SESSION['role'] == 'teacher') {
    $stmt = $pdo->prepare("
        SELECT c.*, ct.teacher_id, u.name as teacher_name 
        FROM classes c 
        JOIN class_teachers ct ON c.id = ct.class_id AND ct.is_active = TRUE
        JOIN teachers t ON ct.teacher_id = t.id
        JOIN users u ON t.user_id = u.id
        WHERE t.user_id = ?
        ORDER BY c.name, c.section
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $availableClasses = $stmt->fetchAll();
}

// Function to get daily attendance summary
function getDailyAttendanceSummary($date = null, $class_id = null) {
    global $pdo;
    
    $whereClause = "";
    $params = [];
    
    if ($date) {
        $whereClause .= " AND a.attendance_date = ?";
        $params[] = $date;
    }
    if ($class_id) {
        $whereClause .= " AND c.id = ?";
        $params[] = $class_id;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            a.attendance_date,
            c.id as class_id,
            c.name as class_name,
            c.section,
            u.name as teacher_name,
            COUNT(a.student_id) as total_students,
            SUM(CASE WHEN a.status IN ('Present', 'present') THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status IN ('Absent', 'absent') THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status IN ('Late', 'late') THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status IN ('Early_Departure') THEN 1 ELSE 0 END) as early_departure_count,
            SUM(CASE WHEN a.status IN ('Excused', 'excused') THEN 1 ELSE 0 END) as excused_count,
            ROUND((SUM(CASE WHEN a.status IN ('Present', 'present', 'Late', 'late') THEN 1 ELSE 0 END) / COUNT(a.student_id)) * 100, 2) as attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN class_teachers ct ON c.id = ct.class_id AND ct.is_active = 1
        LEFT JOIN teachers t ON ct.teacher_id = t.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE 1=1 $whereClause
        GROUP BY a.attendance_date, c.id, c.name, c.section, u.name
        ORDER BY a.attendance_date DESC, c.name, c.section
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get monthly attendance summary
function getMonthlyAttendanceSummary($month = null, $class_id = null) {
    global $pdo;
    
    $whereClause = "";
    $params = [];
    
    if ($month) {
        $whereClause .= " AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
        $params[] = $month;
    }
    if ($class_id) {
        $whereClause .= " AND c.id = ?";
        $params[] = $class_id;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(a.attendance_date, '%Y-%m') as month,
            c.id as class_id,
            c.name as class_name,
            c.section,
            u.name as teacher_name,
            COUNT(DISTINCT a.attendance_date) as total_days,
            COUNT(a.student_id) as total_records,
            AVG(COUNT(a.student_id)) OVER (PARTITION BY c.id, DATE_FORMAT(a.attendance_date, '%Y-%m')) as avg_students_per_day,
            SUM(CASE WHEN a.status IN ('Present', 'present') THEN 1 ELSE 0 END) as total_present,
            SUM(CASE WHEN a.status IN ('Absent', 'absent') THEN 1 ELSE 0 END) as total_absent,
            SUM(CASE WHEN a.status IN ('Late', 'late') THEN 1 ELSE 0 END) as total_late,
            ROUND((SUM(CASE WHEN a.status IN ('Present', 'present', 'Late', 'late') THEN 1 ELSE 0 END) / COUNT(a.student_id)) * 100, 2) as monthly_attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN class_teachers ct ON c.id = ct.class_id AND ct.is_active = 1
        LEFT JOIN teachers t ON ct.teacher_id = t.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE 1=1 $whereClause
        GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m'), c.id, c.name, c.section, u.name
        ORDER BY month DESC, c.name, c.section
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get student-wise attendance summary
function getStudentAttendanceSummary($month = null, $class_id = null) {
    global $pdo;
    
    $whereClause = "";
    $params = [];
    
    if ($month) {
        $whereClause .= " AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
        $params[] = $month;
    }
    if ($class_id) {
        $whereClause .= " AND s.class_id = ?";
        $params[] = $class_id;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            s.id as student_id,
            s.name as student_name,
            s.student_id as student_number,
            s.roll_number,
            c.name as class_name,
            c.section,
            COUNT(a.id) as total_days_recorded,
            SUM(CASE WHEN a.status IN ('Present', 'present') THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN a.status IN ('Absent', 'absent') THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN a.status IN ('Late', 'late') THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN a.status IN ('Early_Departure') THEN 1 ELSE 0 END) as early_departure_days,
            SUM(CASE WHEN a.status IN ('Excused', 'excused') THEN 1 ELSE 0 END) as excused_days,
            ROUND((SUM(CASE WHEN a.status IN ('Present', 'present', 'Late', 'late') THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 2) as attendance_percentage
        FROM students s
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN attendance a ON s.id = a.student_id $whereClause
        WHERE s.id IS NOT NULL
        GROUP BY s.id, s.name, s.student_id, s.roll_number, c.name, c.section
        HAVING total_days_recorded > 0
        ORDER BY c.name, c.section, s.roll_number
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get data based on report type
$attendanceData = [];
switch ($reportType) {
    case 'daily':
        $attendanceData = getDailyAttendanceSummary($selectedDate, $selectedClass);
        break;
    case 'monthly':
        $attendanceData = getMonthlyAttendanceSummary($selectedMonth, $selectedClass);
        break;
    case 'student':
        $attendanceData = getStudentAttendanceSummary($selectedMonth, $selectedClass);
        break;
}

// Get overall statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT a.attendance_date) as total_days_recorded,
        COUNT(DISTINCT a.student_id) as total_students_tracked,
        COUNT(DISTINCT a.class_id) as total_classes,
        AVG(CASE WHEN a.status IN ('Present', 'present', 'Late', 'late') THEN 1 ELSE 0 END) * 100 as overall_attendance_rate
    FROM attendance a
    WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute();
$overallStats = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Attendance Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js" rel="prefetch">
    <style>
        .summary-card {
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .summary-card:hover {
            transform: translateY(-5px);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
        }
        .attendance-excellent { background-color: #d4f6d4; border-left: 4px solid #28a745; }
        .attendance-good { background-color: #fff3cd; border-left: 4px solid #ffc107; }
        .attendance-poor { background-color: #f8d7da; border-left: 4px solid #dc3545; }
        
        .filter-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .data-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .progress-custom {
            height: 8px;
            border-radius: 4px;
        }
        
        .badge-excellent { background-color: #28a745; }
        .badge-good { background-color: #ffc107; color: #000; }
        .badge-poor { background-color: #dc3545; }
        
        @media print {
            .print-hide { display: none !important; }
            .summary-card { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary print-hide">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="attendance_system.php">Take Attendance</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chart-bar me-2"></i>Attendance Summary & Reports</h2>
            <div class="d-flex gap-2 print-hide">
                <button class="btn btn-success" onclick="exportToCSV()">
                    <i class="fas fa-file-csv me-2"></i>Export CSV
                </button>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print Report
                </button>
            </div>
        </div>

        <?= $message ?>

        <!-- Overall Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div style="font-size: 2rem; font-weight: bold;">
                        <?= $overallStats['total_days_recorded'] ?: 0 ?>
                    </div>
                    <div>Days Recorded</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div style="font-size: 2rem; font-weight: bold;">
                        <?= $overallStats['total_students_tracked'] ?: 0 ?>
                    </div>
                    <div>Students Tracked</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div style="font-size: 2rem; font-weight: bold;">
                        <?= $overallStats['total_classes'] ?: 0 ?>
                    </div>
                    <div>Active Classes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <div style="font-size: 2rem; font-weight: bold;">
                        <?= number_format($overallStats['overall_attendance_rate'] ?: 0, 1) ?>%
                    </div>
                    <div>Overall Attendance</div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section print-hide">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filters & Report Options</h5>
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Report Type</label>
                    <select class="form-select" name="report_type" onchange="toggleFilters(this.value)">
                        <option value="daily" <?= $reportType == 'daily' ? 'selected' : '' ?>>Daily Summary</option>
                        <option value="monthly" <?= $reportType == 'monthly' ? 'selected' : '' ?>>Monthly Summary</option>
                        <option value="student" <?= $reportType == 'student' ? 'selected' : '' ?>>Student-wise</option>
                    </select>
                </div>
                
                <div class="col-md-2" id="dateFilter" <?= $reportType != 'daily' ? 'style="display:none"' : '' ?>>
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="date" value="<?= $selectedDate ?>">
                </div>
                
                <div class="col-md-2" id="monthFilter" <?= $reportType == 'daily' ? 'style="display:none"' : '' ?>>
                    <label class="form-label">Month</label>
                    <input type="month" class="form-control" name="month" value="<?= $selectedMonth ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Class (Optional)</label>
                    <select class="form-select" name="class_id">
                        <option value="">All Classes</option>
                        <?php foreach ($availableClasses as $class): ?>
                            <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                <?= $class['name'] ?> - <?= $class['section'] ?>
                                <?= $class['teacher_name'] ? " ({$class['teacher_name']})" : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>

        <!-- Attendance Data -->
        <?php if (!empty($attendanceData)): ?>
            <div class="data-table">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-table me-2"></i>
                            <?php
                            switch ($reportType) {
                                case 'daily':
                                    echo "Daily Attendance Summary - " . formatDate($selectedDate);
                                    break;
                                case 'monthly':
                                    echo "Monthly Attendance Summary - " . date('F Y', strtotime($selectedMonth . '-01'));
                                    break;
                                case 'student':
                                    echo "Student-wise Attendance Summary - " . date('F Y', strtotime($selectedMonth . '-01'));
                                    break;
                            }
                            ?>
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="attendanceTable">
                                <thead class="bg-light">
                                    <tr>
                                        <?php if ($reportType == 'daily'): ?>
                                            <th>Date</th>
                                            <th>Class</th>
                                            <th>Teacher</th>
                                            <th>Total Students</th>
                                            <th>Present</th>
                                            <th>Absent</th>
                                            <th>Late</th>
                                            <th>Attendance %</th>
                                            <th>Status</th>
                                        <?php elseif ($reportType == 'monthly'): ?>
                                            <th>Month</th>
                                            <th>Class</th>
                                            <th>Teacher</th>
                                            <th>Days Recorded</th>
                                            <th>Total Present</th>
                                            <th>Total Absent</th>
                                            <th>Monthly %</th>
                                            <th>Performance</th>
                                        <?php else: ?>
                                            <th>Roll No.</th>
                                            <th>Student Name</th>
                                            <th>Student ID</th>
                                            <th>Class</th>
                                            <th>Days Recorded</th>
                                            <th>Present</th>
                                            <th>Absent</th>
                                            <th>Late</th>
                                            <th>Attendance %</th>
                                            <th>Status</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendanceData as $row): ?>
                                        <tr class="<?php
                                        $percentage = $reportType == 'monthly' ? $row['monthly_attendance_percentage'] : $row['attendance_percentage'];
                                        if ($percentage >= 90) echo 'attendance-excellent';
                                        elseif ($percentage >= 75) echo 'attendance-good';
                                        else echo 'attendance-poor';
                                        ?>">
                                            <?php if ($reportType == 'daily'): ?>
                                                <td><?= formatDate($row['attendance_date']) ?></td>
                                                <td><?= $row['class_name'] ?> - <?= $row['section'] ?></td>
                                                <td><?= $row['teacher_name'] ?: 'No Teacher' ?></td>
                                                <td><span class="badge bg-info"><?= $row['total_students'] ?></span></td>
                                                <td><span class="badge bg-success"><?= $row['present_count'] ?></span></td>
                                                <td><span class="badge bg-danger"><?= $row['absent_count'] ?></span></td>
                                                <td><span class="badge bg-warning text-dark"><?= $row['late_count'] ?></span></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="me-2"><?= number_format($row['attendance_percentage'], 1) ?>%</span>
                                                        <div class="progress progress-custom flex-grow-1" style="width: 60px;">
                                                            <div class="progress-bar <?php
                                                            if ($row['attendance_percentage'] >= 90) echo 'bg-success';
                                                            elseif ($row['attendance_percentage'] >= 75) echo 'bg-warning';
                                                            else echo 'bg-danger';
                                                            ?>" style="width: <?= $row['attendance_percentage'] ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge <?php
                                                    if ($row['attendance_percentage'] >= 90) echo 'badge-excellent';
                                                    elseif ($row['attendance_percentage'] >= 75) echo 'badge-good';
                                                    else echo 'badge-poor';
                                                    ?>">
                                                        <?php
                                                        if ($row['attendance_percentage'] >= 90) echo 'Excellent';
                                                        elseif ($row['attendance_percentage'] >= 75) echo 'Good';
                                                        else echo 'Poor';
                                                        ?>
                                                    </span>
                                                </td>
                                            <?php elseif ($reportType == 'monthly'): ?>
                                                <td><?= date('F Y', strtotime($row['month'] . '-01')) ?></td>
                                                <td><?= $row['class_name'] ?> - <?= $row['section'] ?></td>
                                                <td><?= $row['teacher_name'] ?: 'No Teacher' ?></td>
                                                <td><span class="badge bg-info"><?= $row['total_days'] ?></span></td>
                                                <td><span class="badge bg-success"><?= $row['total_present'] ?></span></td>
                                                <td><span class="badge bg-danger"><?= $row['total_absent'] ?></span></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="me-2"><?= number_format($row['monthly_attendance_percentage'], 1) ?>%</span>
                                                        <div class="progress progress-custom flex-grow-1" style="width: 60px;">
                                                            <div class="progress-bar <?php
                                                            if ($row['monthly_attendance_percentage'] >= 90) echo 'bg-success';
                                                            elseif ($row['monthly_attendance_percentage'] >= 75) echo 'bg-warning';
                                                            else echo 'bg-danger';
                                                            ?>" style="width: <?= $row['monthly_attendance_percentage'] ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge <?php
                                                    if ($row['monthly_attendance_percentage'] >= 90) echo 'badge-excellent';
                                                    elseif ($row['monthly_attendance_percentage'] >= 75) echo 'badge-good';
                                                    else echo 'badge-poor';
                                                    ?>">
                                                        <?php
                                                        if ($row['monthly_attendance_percentage'] >= 90) echo 'Excellent';
                                                        elseif ($row['monthly_attendance_percentage'] >= 75) echo 'Good';
                                                        else echo 'Needs Improvement';
                                                        ?>
                                                    </span>
                                                </td>
                                            <?php else: ?>
                                                <td><strong><?= $row['roll_number'] ?: 'N/A' ?></strong></td>
                                                <td><?= $row['student_name'] ?></td>
                                                <td><small class="text-muted"><?= $row['student_number'] ?></small></td>
                                                <td><?= $row['class_name'] ?> - <?= $row['section'] ?></td>
                                                <td><span class="badge bg-info"><?= $row['total_days_recorded'] ?></span></td>
                                                <td><span class="badge bg-success"><?= $row['present_days'] ?></span></td>
                                                <td><span class="badge bg-danger"><?= $row['absent_days'] ?></span></td>
                                                <td><span class="badge bg-warning text-dark"><?= $row['late_days'] ?></span></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="me-2"><?= number_format($row['attendance_percentage'], 1) ?>%</span>
                                                        <div class="progress progress-custom flex-grow-1" style="width: 60px;">
                                                            <div class="progress-bar <?php
                                                            if ($row['attendance_percentage'] >= 90) echo 'bg-success';
                                                            elseif ($row['attendance_percentage'] >= 75) echo 'bg-warning';
                                                            else echo 'bg-danger';
                                                            ?>" style="width: <?= $row['attendance_percentage'] ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge <?php
                                                    if ($row['attendance_percentage'] >= 90) echo 'badge-excellent';
                                                    elseif ($row['attendance_percentage'] >= 75) echo 'badge-good';
                                                    else echo 'badge-poor';
                                                    ?>">
                                                        <?php
                                                        if ($row['attendance_percentage'] >= 90) echo 'Excellent';
                                                        elseif ($row['attendance_percentage'] >= 75) echo 'Good';
                                                        else echo 'At Risk';
                                                        ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Report generated on <?= date('F d, Y \a\t h:i A') ?> | 
                            Total records: <?= count($attendanceData) ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <h5><i class="fas fa-info-circle me-2"></i>No Data Available</h5>
                <p>No attendance data found for the selected criteria. Please:</p>
                <ul>
                    <li>Check if attendance has been recorded for the selected date/month</li>
                    <li>Verify the class selection</li>
                    <li>Try a different date range</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleFilters(reportType) {
            const dateFilter = document.getElementById('dateFilter');
            const monthFilter = document.getElementById('monthFilter');
            
            if (reportType === 'daily') {
                dateFilter.style.display = 'block';
                monthFilter.style.display = 'none';
            } else {
                dateFilter.style.display = 'none';
                monthFilter.style.display = 'block';
            }
        }
        
        function exportToCSV() {
            const table = document.getElementById('attendanceTable');
            if (!table) return;
            
            let csv = [];
            const rows = table.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = [];
                const cols = rows[i].querySelectorAll('td, th');
                
                for (let j = 0; j < cols.length; j++) {
                    let cellData = cols[j].innerText.replace(/"/g, '""');
                    row.push('"' + cellData + '"');
                }
                csv.push(row.join(','));
            }
            
            const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
            const downloadLink = document.createElement('a');
            downloadLink.download = 'attendance_report_' + new Date().toISOString().split('T')[0] + '.csv';
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = 'none';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }
        
        // Initialize filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleFilters('<?= $reportType ?>');
        });
    </script>
</body>
</html>
