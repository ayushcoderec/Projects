<?php
//  DEBUGGING: Force PHP to display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'functions.php';

checkLogin();

$selectedClass = '';
$selectedExam = '';
$results = [];
$classStats = [];
$teacher_assignments = [];

// Get teacher's ID and assignments if user is a teacher
if ($_SESSION['role'] == 'teacher') {
    $stmt = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch();
    $teacher_id = $teacher['id'] ?? 0;
    
    // Get teacher's specific subject-class assignments
    $stmt = $pdo->prepare("
        SELECT ts.*, s.name as subject_name, s.code, c.name as class_name, c.section,
               s.written_full_marks, s.oral_full_marks
        FROM teacher_subjects ts 
        JOIN subjects s ON ts.subject_id = s.id 
        JOIN classes c ON ts.class_id = c.id 
        WHERE ts.teacher_id = ?
    ");
    $stmt->execute([$teacher_id]);
    $teacher_assignments = $stmt->fetchAll();
    
    // If teacher has no assignments, show message
    if (empty($teacher_assignments)) {
        $message = showAlert('You have not been assigned to any classes or subjects yet. Please contact the administrator.', 'info');
    }
}

// Handle filter submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['load_results'])) {
    $selectedClass = (int)$_POST['class_id'];
    $selectedExam = (int)$_POST['exam_id'];

    // Validate teacher assignment if user is a teacher
    if ($_SESSION['role'] == 'teacher') {
        $hasAssignment = false;
        foreach ($teacher_assignments as $assignment) {
            if ($assignment['class_id'] == $selectedClass) {
                $hasAssignment = true;
                break;
            }
        }
        
        if (!$hasAssignment) {
            $message = showAlert('You are not authorized to view results for this class!', 'danger');
            $selectedClass = $selectedExam = '';
        }
    }

    if ($selectedClass && $selectedExam) {

        // 🛠️ DEFINE: Logic to get correct full marks (Custom or Default)
        $written_marks_coalesce = "COALESCE(csc.written_full_marks, sub.written_full_marks)";
        $oral_marks_coalesce = "COALESCE(csc.oral_full_marks, sub.oral_full_marks)";
        $full_marks_coalesce = "(({$written_marks_coalesce}) + ({$oral_marks_coalesce}))";
        
        // 🛠️ DEFINE: Join to the new custom marks table
        // We join on s.class_id which is available in all queries
        $join_config = "LEFT JOIN subject_class_full_marks csc ON sub.id = csc.subject_id AND s.class_id = csc.class_id";

        // Build the query based on user role
        if ($_SESSION['role'] == 'teacher') {
            // For teachers, only show results for subjects they teach in the selected class
            $teacher_subject_ids = [];
            foreach ($teacher_assignments as $assignment) {
                if ($assignment['class_id'] == $selectedClass) {
                    $teacher_subject_ids[] = $assignment['subject_id'];
                }
            }
            
            if (empty($teacher_subject_ids)) {
                $message = showAlert('You do not teach any subjects in this class!', 'warning');
                $results = [];
            } else {
                $placeholders = str_repeat('?,', count($teacher_subject_ids) - 1) . '?';
                
                // 🛠️ FIXED: Using your original query structure + COALESCE
                $stmt = $pdo->prepare("
                    SELECT 
                        s.id as student_id,
                        s.name as student_name,
                        s.roll_number,
                        s.student_id as student_reg_id,
                        sub.name as subject_name,
                        sub.code as subject_code,
                        {$written_marks_coalesce} as written_full_marks,
                        {$oral_marks_coalesce} as oral_full_marks,
                        {$full_marks_coalesce} as total_full_marks,
                        m.written_marks,
                        m.oral_marks,
                        m.marks as total_marks,
                        CASE 
                            WHEN m.marks IS NOT NULL AND {$full_marks_coalesce} > 0 
                            THEN ROUND((m.marks / {$full_marks_coalesce}) * 100, 2)
                            ELSE NULL
                        END as percentage,
                        m.is_locked
                    FROM students s
                    LEFT JOIN marks m ON s.id = m.student_id AND m.exam_id = ? AND m.subject_id IN ($placeholders)
                    LEFT JOIN subjects sub ON m.subject_id = sub.id
                    {$join_config} -- Joined the custom marks table
                    WHERE s.class_id = ? AND (m.subject_id IN ($placeholders) OR m.subject_id IS NULL)
                    ORDER BY CAST(s.roll_number AS UNSIGNED), sub.display_order, sub.name
                ");
                $stmt->execute(array_merge([$selectedExam], $teacher_subject_ids, [$selectedClass], $teacher_subject_ids));
                $results = $stmt->fetchAll();
            }
        } else {
            // For admin/super_admin, show all results
            // 🛠️ FIXED: Using your original query structure + COALESCE
            $stmt = $pdo->prepare("
                SELECT 
                    s.id as student_id,
                    s.name as student_name,
                    s.roll_number,
                    s.student_id as student_reg_id,
                    sub.name as subject_name,
                    sub.code as subject_code,
                    {$written_marks_coalesce} as written_full_marks,
                    {$oral_marks_coalesce} as oral_full_marks,
                    {$full_marks_coalesce} as total_full_marks,
                    m.written_marks,
                    m.oral_marks,
                    m.marks as total_marks,
                    CASE 
                        WHEN m.marks IS NOT NULL AND {$full_marks_coalesce} > 0
                        THEN ROUND((m.marks / {$full_marks_coalesce}) * 100, 2)
                        ELSE NULL
                    END as percentage,
                    m.is_locked
                FROM students s
                LEFT JOIN marks m ON s.id = m.student_id AND m.exam_id = ?
                LEFT JOIN subjects sub ON m.subject_id = sub.id
                {$join_config} -- Joined the custom marks table
                WHERE s.class_id = ?
                ORDER BY CAST(s.roll_number AS UNSIGNED), sub.display_order, sub.name
            ");
            $stmt->execute([$selectedExam, $selectedClass]);
            $results = $stmt->fetchAll();
        }

        // Calculate class statistics (only for subjects teacher can see)
        if (!empty($results)) {
            $studentStats = [];
            
            foreach ($results as $result) {
                $studentId = $result['student_id'];
                
                if (!isset($studentStats[$studentId])) {
                    $studentStats[$studentId] = [
                        'student_name' => $result['student_name'],
                        'roll_number' => $result['roll_number'],
                        'student_reg_id' => $result['student_reg_id'],
                        'total_marks' => 0,
                        'total_full_marks' => 0,
                        'subjects' => 0,
                        'passed_subjects' => 0,
                        'written_total' => 0,
                        'oral_total' => 0
                    ];
                }
                
                // 🛠️ FIXED: Check total_full_marks > 0 to avoid division by zero
                if ($result['total_marks'] !== null && $result['total_full_marks'] > 0) {
                    $studentStats[$studentId]['total_marks'] += $result['total_marks'];
                    $studentStats[$studentId]['total_full_marks'] += $result['total_full_marks'];
                    $studentStats[$studentId]['subjects']++;
                    $studentStats[$studentId]['written_total'] += $result['written_marks'] ?? 0;
                    $studentStats[$studentId]['oral_total'] += $result['oral_marks'] ?? 0;
                    
                    if ($result['percentage'] >= 35) {
                        $studentStats[$studentId]['passed_subjects']++;
                    }
                }
            }
            
            // Calculate overall statistics
            $totalStudents = count($studentStats);
            $passedStudents = 0;
            $totalPercentage = 0;
            $highestMarks = 0;
            $lowestMarks = 100;
            $topStudent = '';
            $avgWrittenMarks = 0;
            $avgOralMarks = 0;
            
            foreach ($studentStats as $stats) {
                if ($stats['total_full_marks'] > 0) {
                    $percentage = ($stats['total_marks'] / $stats['total_full_marks']) * 100;
                    $totalPercentage += $percentage;
                    
                    if ($percentage >= 35) {
                        $passedStudents++;
                    }
                    
                    if ($percentage > $highestMarks) {
                        $highestMarks = $percentage;
                        $topStudent = $stats['student_name'];
                    }
                    
                    if ($percentage < $lowestMarks) {
                        $lowestMarks = $percentage;
                    }
                    
                    $avgWrittenMarks += $stats['written_total'];
                    $avgOralMarks += $stats['oral_total'];
                }
            }
            
            $classStats = [
                'total_students' => $totalStudents,
                'passed_students' => $passedStudents,
                'pass_percentage' => $totalStudents > 0 ? round(($passedStudents / $totalStudents) * 100, 2) : 0,
                'average_marks' => $totalStudents > 0 ? round($totalPercentage / $totalStudents, 2) : 0,
                'highest_marks' => $highestMarks,
                'lowest_marks' => $totalStudents > 0 ? $lowestMarks : 0,
                'top_student' => $topStudent,
                'avg_written' => $totalStudents > 0 ? round($avgWrittenMarks / $totalStudents, 2) : 0,
                'avg_oral' => $totalStudents > 0 ? round($avgOralMarks / $totalStudents, 2) : 0
            ];
        }
    }
}

// Get dropdown data based on role
if ($_SESSION['role'] == 'teacher') {
    $classes = [];
    $assignedClasses = [];
    
    foreach ($teacher_assignments as $assignment) {
        if (!in_array($assignment['class_id'], $assignedClasses)) {
            $classes[] = [
                'id' => $assignment['class_id'],
                'name' => $assignment['class_name'],
                'section' => $assignment['section']
            ];
            $assignedClasses[] = $assignment['class_id'];
        }
    }
} else {
    $classes = getAllClasses();
}

$exams = getAllExams();

// Get selected class and exam details for display
$selectedClassDetails = null;
$selectedExamDetails = null;

if ($selectedClass) {
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$selectedClass]);
    $selectedClassDetails = $stmt->fetch();
}

if ($selectedExam) {
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
    $stmt->execute([$selectedExam]);
    $selectedExamDetails = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - View Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .result-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color:black;
        }
        .pass-mark {
            background-color: #d4edda;
        }
        .fail-mark {
            background-color: #f8d7da;
        }
        .grade-A { background-color: #d4edda; color: #155724; }
        .grade-B { background-color: #d1ecf1; color: #0c5460; }
        .grade-C { background-color: #fff3cd; color: #856404; }
        .grade-D { background-color: #f8d7da; color: #721c24; }
        .locked-badge { font-size: 0.7rem; }
        .marks-breakdown {
            font-size: 0.75rem;
            color: #6c757d;
        }
        .subject-header {
            writing-mode: vertical-rl;
            text-orientation: mixed;
            min-width: 120px;
        }
        .marks-cell {
            min-width: 100px;
            text-align: center;
        }
        .student-info {
            min-width: 150px;
        }
        .oral-written-split {
            font-size: 0.8em;
            color: #6c757d;
        }
        .stats-card {
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .export-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
        }
        .teacher-restriction-notice {
            background: linear-gradient(45deg, #17a2b8, #138496);
            color: white;
            border-radius: 8px;
            padding: 15px;
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
            <h2><i class="fas fa-chart-bar me-2"></i>View Results</h2>
          <?php if (!empty($results)): ?>
    <div class="export-section">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div>
                <strong>Export & Print Options:</strong><br>
                <small>
                    <?= $selectedClassDetails['name'] ?? '' ?> - <?= $selectedExamDetails['name'] ?? '' ?>
                    <?php if ($_SESSION['role'] == 'teacher'): ?>
                        <br><span class="badge bg-light text-dark">Your Subjects Only</span>
                    <?php endif; ?>
                </small>
            </div>
            <div class="btn-group flex-wrap">
               
                
                <!-- Bulk Print Button -->
                <button class="btn btn-success btn-sm" onclick="bulkPrintReports()" title="Print all student reports at once (Ctrl+Shift+B)">
                    <i class="fas fa-print me-2"></i>Bulk Print Reports
                </button>
                
                <!-- 🛠️ ADDED: Individual Reports Button -->
                <button class="btn btn-info btn-sm" onclick="toggleIndividualReports()" title="Show/Hide Individual Student Reports">
                    <i class="fas fa-user-graduate me-2"></i>Individual Reports
                </button>
                
                <a href="attendance_management.php"> <button class="btn btn-light btn-sm dropdown-toggle" type="button">
                        <i class="fas fa-user-check me-1"></i>Enter Attendance
                    </button></a>
                <a href="class_test_report.php"> <button class="btn btn-info btn-sm" type="button">
                        <i class="fas fa-class1"></i>Class Test Report
                    </button></a>
                 <a href="export_excel.php"> <button class="btn btn-info btn-sm" type="button">
                        <i class="fas fa-pdf"></i>Excel
                    </button></a>

            </div>
        </div>
    </div>
<?php endif; ?>

        </div>

        <!-- Teacher Restriction Notice -->
        <?php if ($_SESSION['role'] == 'teacher'): ?>
            <div class="teacher-restriction-notice">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h6 class="mb-1"><i class="fas fa-info-circle me-2"></i>Teacher View Restrictions</h6>
                        <p class="mb-0">You can only view results for classes and subjects assigned to you. Contact administrator for additional access.</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <small>
                            <strong>Your Assignments:</strong><br>
                            <?php 
                            $assignmentSummary = [];
                            foreach ($teacher_assignments as $assignment) {
                                $key = $assignment['class_name'] . ' (' . $assignment['section'] . ')';
                                if (!isset($assignmentSummary[$key])) {
                                    $assignmentSummary[$key] = [];
                                }
                                $assignmentSummary[$key][] = $assignment['subject_name'];
                            }
                            
                            foreach ($assignmentSummary as $class => $subjects) {
                                echo "<span class='badge bg-light text-dark me-1'>{$class}: " . implode(', ', array_unique($subjects)) . "</span><br>";
                            }
                            ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($message)): ?>
            <?= $message ?>
        <?php endif; ?>

        <!-- Filter Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Filter Results
                    <?php if ($_SESSION['role'] == 'teacher'): ?>
                        <span class="badge bg-info ms-2">Assigned Classes Only</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($_SESSION['role'] == 'teacher' && empty($teacher_assignments)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>No Assignments Found:</strong> You have not been assigned to any classes or subjects. Please contact the administrator to get assignments.
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-5">
                                <label class="form-label">
                                    Select Class
                                    <?php if ($_SESSION['role'] == 'teacher'): ?>
                                        <small class="text-muted">(Your assigned classes only)</small>
                                    <?php endif; ?>
                                </label>
                                <select class="form-select" name="class_id" required>
                                    <option value="">Choose Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                            <?= $class['name'] ?> - Section <?= $class['section'] ?>
                                            <?php if ($_SESSION['role'] == 'teacher'): ?>
                                                <?php
                                                // Show subjects for this class
                                                $classSubjects = [];
                                                foreach ($teacher_assignments as $assignment) {
                                                    if ($assignment['class_id'] == $class['id']) {
                                                        $classSubjects[] = $assignment['subject_name'];
                                                    }
                                                }
                                                if (!empty($classSubjects)) {
                                                    echo " (" . implode(', ', array_unique($classSubjects)) . ")";
                                                }
                                                ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-5">
                                <label class="form-label">Select Exam</label>
                                <select class="form-select" name="exam_id" required>
                                    <option value="">Choose Exam</option>
                                    <?php foreach ($exams as $exam): ?>
                                        <option value="<?= $exam['id'] ?>" <?= $selectedExam == $exam['id'] ? 'selected' : '' ?>>
                                            <?= $exam['name'] ?> (<?= formatDate($exam['start_date']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" name="load_results" class="btn btn-primary w-100">
                                    <i class="fas fa-search me-2"></i>Load Results
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Class Statistics -->
        <?php if (!empty($classStats)): ?>
            <div class="row mb-4">
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                    <div class="card bg-primary text-white stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-users fa-2x mb-2"></i>
                            <h3><?= $classStats['total_students'] ?></h3>
                            <small>Total Students</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                    <div class="card bg-success text-white stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-check-circle fa-2x mb-2"></i>
                            <h3><?= $classStats['passed_students'] ?></h3>
                            <small>Passed</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                    <div class="card bg-info text-white stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-percentage fa-2x mb-2"></i>
                            <h3><?= $classStats['pass_percentage'] ?>%</h3>
                            <small>
                                Pass Rate
                                <?php if ($_SESSION['role'] == 'teacher'): ?>
                                    <br><span style="font-size: 0.7em;">(Your Subjects)</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                    <div class="card bg-warning text-white stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-chart-line fa-2x mb-2"></i>
                            <h3><?= $classStats['average_marks'] ?>%</h3>
                            <small>Class Average</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                    <div class="card bg-dark text-white stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-trophy fa-2x mb-2"></i>
                            <h3><?= number_format($classStats['highest_marks'], 1) ?>%</h3>
                            <small>Highest Score</small>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                    <div class="card bg-secondary text-white stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-chart-line fa-2x mb-2"></i>
                            <h3><?= number_format($classStats['lowest_marks'], 1) ?>%</h3>
                            <small>Lowest Score</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Performance Summary -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="alert alert-success">
                        <i class="fas fa-star me-2"></i>
                        <strong>Top Performer:</strong> <?= $classStats['top_student'] ?? 'N/A' ?>
                        <?php if ($classStats['top_student']): ?>
                            with <?= number_format($classStats['highest_marks'], 2) ?>%
                        <?php endif; ?>
                        <?php if ($_SESSION['role'] == 'teacher'): ?>
                            <br><small class="text-muted">Based on your assigned subjects only</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info">
                        <i class="fas fa-chart-pie me-2"></i>
                        <strong>Marks Distribution:</strong> 
                        Written: <?= $classStats['avg_written'] ?> | Oral: <?= $classStats['avg_oral'] ?> (Average)
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Results Table -->
        <?php if (!empty($results)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i>Detailed Results - 
                        <?= $selectedClassDetails['name'] ?? '' ?> Section <?= $selectedClassDetails['section'] ?? '' ?> - 
                        <?= $selectedExamDetails['name'] ?? '' ?>
                        <?php if ($_SESSION['role'] == 'teacher'): ?>
                            <span class="badge bg-warning ms-2">Your Subjects Only</span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <?php
                        // Organize results by student
                        $studentResults = [];
                        $subjects = [];
                        
                        foreach ($results as $result) {
                            $studentId = $result['student_id'];
                            
                            if (!isset($studentResults[$studentId])) {
                                $studentResults[$studentId] = [
                                    'student_name' => $result['student_name'],
                                    'roll_number' => $result['roll_number'],
                                    'student_reg_id' => $result['student_reg_id'],
                                    'subjects' => [],
                                    'total_marks' => 0,
                                    'total_full_marks' => 0
                                ];
                            }
                            
                            if ($result['subject_name']) {
                                $subjects[$result['subject_name']] = [
                                    'name' => $result['subject_name'],
                                    'code' => $result['subject_code'],
                                    // 🛠️ USE: The corrected full marks
                                    'written_full' => $result['written_full_marks'],
                                    'oral_full' => $result['oral_full_marks'],
                                    'total_full' => $result['total_full_marks']
                                ];
                                
                                $studentResults[$studentId]['subjects'][$result['subject_name']] = [
                                    'written_marks' => $result['written_marks'],
                                    'oral_marks' => $result['oral_marks'],
                                    'total_marks' => $result['total_marks'],
                                    'total_full_marks' => $result['total_full_marks'],
                                    'percentage' => $result['percentage'],
                                    'is_locked' => $result['is_locked']
                                ];
                                
                                // 🛠️ FIXED: Check total_full_marks > 0
                                if ($result['total_marks'] !== null && $result['total_full_marks'] > 0) {
                                    $studentResults[$studentId]['total_marks'] += $result['total_marks'];
                                    $studentResults[$studentId]['total_full_marks'] += $result['total_full_marks'];
                                }
                            }
                        }
                        ?>

                        <?php if (empty($subjects)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>No Results Found:</strong> 
                                <?php if ($_SESSION['role'] == 'teacher'): ?>
                                    No marks have been entered for your assigned subjects in this class and exam combination.
                                <?php else: ?>
                                    No marks have been entered for this class and exam combination.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <table class="table table-bordered result-table table-sm">
                                <thead class="table-dark">
                                    <tr>
                                        <th rowspan="3" class="align-middle">Roll No.</th>
                                        <th rowspan="3" class="align-middle student-info">Student Details</th>
                                        <?php foreach ($subjects as $subject): ?>
                                            <th colspan="4" class="text-center">
                                                <?= $subject['name'] ?> (<?= $subject['code'] ?>)
                                                <!-- 🛠️ DISPLAY: Corrected full marks -->
                                                <br><small>W: <?= $subject['written_full'] ?> | O: <?= $subject['oral_full'] ?> | T: <?= $subject['total_full'] ?></small>
                                            </th>
                                        <?php endforeach; ?>
                                        <th rowspan="3" class="align-middle">Grand Total</th>
                                        <th rowspan="3" class="align-middle">Overall %</th>
                                        <th rowspan="3" class="align-middle">Grade</th>
                                        <th rowspan="3" class="align-middle">Result</th>
                                    </tr>
                                    <tr>
                                        <?php foreach ($subjects as $subject): ?>
                                            <th class="text-center">Written</th>
                                            <th class="text-center">Oral</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">%</th>
                                        <?php endforeach; ?>
                                    </tr>
                                    <tr>
                                        <?php foreach ($subjects as $subject): ?>
                                            <!-- 🛠️ DISPLAY: Corrected full marks -->
                                            <th class="text-center marks-breakdown">/<?= $subject['written_full'] ?></th>
                                            <th class="text-center marks-breakdown">/<?= $subject['oral_full'] ?></th>
                                            <th class="text-center marks-breakdown">/<?= $subject['total_full'] ?></th>
                                            <th class="text-center marks-breakdown">Grade</th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studentResults as $studentId => $studentResult): ?>
                                        <?php
                                        $overallPercentage = $studentResult['total_full_marks'] > 0 
                                            ? ($studentResult['total_marks'] / $studentResult['total_full_marks']) * 100 
                                            : 0;
                                        
                                        $overallGrade = '';
                                        $gradeClass = '';
                                        if ($overallPercentage >= 90) {
                                            $overallGrade = 'A+';
                                            $gradeClass = 'grade-A';
                                        } elseif ($overallPercentage >= 80) {
                                            $overallGrade = 'A';
                                            $gradeClass = 'grade-A';
                                        } elseif ($overallPercentage >= 70) {
                                            $overallGrade = 'B+';
                                            $gradeClass = 'grade-B';
                                        } elseif ($overallPercentage >= 60) {
                                            $overallGrade = 'B';
                                            $gradeClass = 'grade-B';
                                        } elseif ($overallPercentage >= 50) {
                                            $overallGrade = 'C+';
                                            $gradeClass = 'grade-C';
                                        } elseif ($overallPercentage >= 40) {
                                            $overallGrade = 'C';
                                            $gradeClass = 'grade-C';
                                        } elseif ($overallPercentage >= 35) {
                                            $overallGrade = 'D';
                                            $gradeClass = 'grade-D';
                                        } else {
                                            $overallGrade = 'F';
                                            $gradeClass = 'grade-D';
                                        }
                                        
                                        $result = $overallPercentage >= 35 ? 'PASS' : 'FAIL';
                                        $resultClass = $overallPercentage >= 35 ? 'text-success' : 'text-danger';
                                        ?>
                                        <tr>
                                            <td class="text-center align-middle">
                                                <strong><?= $studentResult['roll_number'] ?></strong>
                                            </td>
                                            <td class="student-info">
                                                <strong><?= $studentResult['student_name'] ?></strong><br>
                                                <small class="text-muted"><?= $studentResult['student_reg_id'] ?></small>
                                            </td>
                                            <?php foreach (array_keys($subjects) as $subjectName): ?>
                                                <?php 
                                                $subjectData = $studentResult['subjects'][$subjectName] ?? null;
                                                $subjectPassMark = 35; // Default pass percentage
                                                $isPassing = $subjectData && $subjectData['percentage'] !== null && $subjectData['percentage'] >= $subjectPassMark;
                                                $markClass = $isPassing ? 'pass-mark' : ($subjectData && $subjectData['percentage'] !== null ? 'fail-mark' : '');
                                                
                                                // Individual subject grade
                                                $subjectGrade = '';
                                                if ($subjectData && $subjectData['percentage'] !== null) {
                                                    $pct = $subjectData['percentage'];
                                                    if ($pct >= 90) $subjectGrade = 'A+';
                                                    elseif ($pct >= 80) $subjectGrade = 'A';
                                                    elseif ($pct >= 70) $subjectGrade = 'B+';
                                                    elseif ($pct >= 60) $subjectGrade = 'B';
                                                    elseif ($pct >= 50) $subjectGrade = 'C+';
                                                    elseif ($pct >= 40) $subjectGrade = 'C';
                                                    elseif ($pct >= 35) $subjectGrade = 'D';
                                                    else $subjectGrade = 'F';
                                                }
                                                ?>
                                                <!-- Written Marks -->
                                                <td class="text-center marks-cell <?= $markClass ?>">
                                                    <?= $subjectData ? ($subjectData['written_marks'] ?? '-') : '-' ?>
                                                </td>
                                                <!-- Oral Marks -->
                                                <td class="text-center marks-cell <?= $markClass ?>">
                                                    <?= $subjectData ? ($subjectData['oral_marks'] ?? '-') : '-' ?>
                                                </td>
                                                <!-- Total Marks -->
                                                <td class="text-center marks-cell <?= $markClass ?>">
                                                    <?php if ($subjectData): ?>
                                                        <strong><?= $subjectData['total_marks'] ?? '-' ?></strong>
                                                        <?php if ($subjectData['is_locked']): ?>
                                                            <br><span class="badge bg-success locked-badge">✓</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <!-- Percentage & Grade -->
                                                <td class="text-center marks-cell <?= $markClass ?>">
                                                    <?php if ($subjectData && $subjectData['percentage'] !== null): ?>
                                                        <strong><?= number_format($subjectData['percentage'], 1) ?>%</strong><br>
                                                        <span class="badge <?= $isPassing ? 'bg-success' : 'bg-danger' ?>"><?= $subjectGrade ?></span>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="text-center align-middle">
                                                <strong><?= $studentResult['total_marks'] ?></strong><br>
                                                <small class="text-muted">/ <?= $studentResult['total_full_marks'] ?></small>
                                            </td>
                                            <td class="text-center align-middle">
                                                <strong><?= number_format($overallPercentage, 2) ?>%</strong>
                                                <?php if ($_SESSION['role'] == 'teacher'): ?>
                                                    <br><small class="text-muted">(Your subjects)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center align-middle">
                                                <span class="badge <?= $gradeClass ?> fs-6"><?= $overallGrade ?></span>
                                            </td>
                                            <td class="text-center align-middle">
                                                <strong class="<?= $resultClass ?>"><?= $result ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- 🛠️ MOVED: Individual Report Cards Section (FIXED) -->
                    <?php if (!empty($subjects) && !empty($studentResults)): ?>
                        <div class="mt-4" id="individual-reports" style="display: none;">
                            <div class="card bg-light">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Individual Report Cards</h6>
                                    <button type="button" class="btn-close" onclick="toggleIndividualReports()" aria-label="Close"></button>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($studentResults as $studentId => $studentResult): ?>
                                            <div class="col-md-6 col-lg-4 mb-2">
                                                <!-- 🛠️ FIXED: Added exam_id to the link -->
                                                <a href="generate_report.php?student_id=<?= $studentId ?>&exam_id=<?= $selectedExam ?><?= $_SESSION['role'] == 'teacher' ? '&teacher_view=1' : '' ?>" 
                                                   class="btn btn-outline-primary btn-sm w-100" target="_blank" title="Print report for <?= $studentResult['student_name'] ?>">
                                                    <i class="fas fa-file-pdf me-2"></i>
                                                    <?= $studentResult['student_name'] ?>
                                                    <br><small><?= $studentResult['student_reg_id'] ?></small>
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Subject-wise Analysis -->
                    <?php if (!empty($subjects)): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">
                                            <i class="fas fa-chart-bar me-2"></i>Subject-wise Performance Analysis
                                            <?php if ($_SESSION['role'] == 'teacher'): ?>
                                                <span class="badge bg-info ms-2">Your Subjects Only</span>
                                            <?php endif; ?>
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Subject</th>
                                                        <th>Students Appeared</th>
                                                        <th>Average Written</th>
                                                        <th>Average Oral</th>
                                                        <th>Average Total</th>
                                                        <th>Pass Rate</th>
                                                        <th>Highest Score</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($subjects as $subjectName => $subjectInfo): ?>
                                                        <?php
                                                        $subjectStats = [
                                                            'appeared' => 0,
                                                            'total_written' => 0,
                                                            'total_oral' => 0,
                                                            'total_marks' => 0,
                                                            'passed' => 0,
                                                            'highest' => 0
                                                        ];
                                                        
                                                        foreach ($studentResults as $student) {
                                                            if (isset($student['subjects'][$subjectName]) && $student['subjects'][$subjectName]['total_marks'] !== null) {
                                                                $subjectStats['appeared']++;
                                                                $subjectStats['total_written'] += $student['subjects'][$subjectName]['written_marks'] ?? 0;
                                                                $subjectStats['total_oral'] += $student['subjects'][$subjectName]['oral_marks'] ?? 0;
                                                                $subjectStats['total_marks'] += $student['subjects'][$subjectName]['total_marks'];
                                                                
                                                                if ($student['subjects'][$subjectName]['percentage'] >= 35) {
                                                                    $subjectStats['passed']++;
                                                                }
                                                                
                                                                if ($student['subjects'][$subjectName]['percentage'] > $subjectStats['highest']) {
                                                                    $subjectStats['highest'] = $student['subjects'][$subjectName]['percentage'];
                                                                }
                                                            }
                                                        }
                                                        
                                                        $avgWritten = $subjectStats['appeared'] > 0 ? round($subjectStats['total_written'] / $subjectStats['appeared'], 1) : 0;
                                                        $avgOral = $subjectStats['appeared'] > 0 ? round($subjectStats['total_oral'] / $subjectStats['appeared'], 1) : 0;
                                                        // 🛠️ FIXED: Check for division by zero
                                                        $avgTotal = ($subjectStats['appeared'] > 0 && $subjectInfo['total_full'] > 0) ? round(($subjectStats['total_marks'] / $subjectStats['appeared'] / $subjectInfo['total_full']) * 100, 1) : 0;
                                                        $passRate = $subjectStats['appeared'] > 0 ? round(($subjectStats['passed'] / $subjectStats['appeared']) * 100, 1) : 0;
                                                        ?>
                                                        <tr>
                                                            <td><strong><?= $subjectName ?></strong> (<?= $subjectInfo['code'] ?>)</td>
                                                            <td><?= $subjectStats['appeared'] ?></td>
                                                            <td><?= $avgWritten ?> / <?= $subjectInfo['written_full'] ?></td>
                                                            <td><?= $avgOral ?> / <?= $subjectInfo['oral_full'] ?></td>
                                                            <td><?= $avgTotal ?>%</td>
                                                            <td>
                                                                <span class="badge <?= $passRate >= 70 ? 'bg-success' : ($passRate >= 50 ? 'bg-warning' : 'bg-danger') ?>">
                                                                    <?= $passRate ?>%
                                                                </span>
                                                            </td>
                                                            <td><?= number_format($subjectStats['highest'], 1) ?>%</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- 
    =====================================================
    JAVASCRIPT (including popup blocker fix)
    =====================================================
    -->
    <script>
    // Enhanced notification system
    function showNotification(message, type, duration = 4000) {
        // Remove any existing notifications
        document.querySelectorAll('.position-fixed.alert').forEach(alert => alert.remove());

        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px; max-width: 500px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);';
        
        const icons = {
            success: 'fas fa-check-circle',
            danger: 'fas fa-exclamation-triangle',
            warning: 'fas fa-exclamation-circle',
            info: 'fas fa-info-circle'
        };
        
        alertDiv.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="${icons[type] || icons.info} me-2 fs-5"></i>
                <div class="flex-grow-1">${message}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        document.body.appendChild(alertDiv);
        
        // Auto remove after duration
        setTimeout(() => {
            if (alertDiv.parentNode) {
                // Use Bootstrap's built-in close method if available
                const bsAlert = bootstrap.Alert.getInstance(alertDiv);
                if (bsAlert) {
                    bsAlert.close();
                } else {
                    alertDiv.remove();
                }
            }
        }, duration);
        
        return alertDiv;
    }

    // Show Loading Modal
    function showLoadingModal(message) {
        // Remove existing modal if any
        hideLoadingModal(document.getElementById('loadingModal'));

        const modal = document.createElement('div');
        modal.className = 'modal fade show';
        modal.id = 'loadingModal';
        modal.style.display = 'block';
        modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body text-center p-4">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <h5>${message}</h5>
                        <p class="text-muted mb-0">Please wait while we process your request...</p>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        return modal;
    }

    // Hide Loading Modal
    function hideLoadingModal(modal) {
        if (modal && modal.parentNode) {
            modal.remove();
        }
    }

    // 🛠️ FIXED: Bulk Print Reports Function (with Pop-up Blocker Detection)
    function bulkPrintReports() {
        const classId = <?= $selectedClass ?: 0 ?>;
        const examId = <?= $selectedExam ?: 0 ?>;
        const teacherView = <?= $_SESSION['role'] == 'teacher' ? '1' : '0' ?>;
        
        if (!classId || !examId) {
            showNotification('Please select class and exam first', 'warning');
            return;
        }
        
        const loadingModal = showLoadingModal('Generating reports for all students...');
        
        const bulkUrl = `generate_report.php?class_id=${classId}&exam_id=${examId}&bulk=1${teacherView ? '&teacher_view=1' : ''}`;
        
        try {
            const printWindow = window.open(bulkUrl, '_blank', 'width=1200,height=800,scrollbars=yes');
            
            if (!printWindow || printWindow.closed || typeof printWindow.closed === 'undefined') {
                hideLoadingModal(loadingModal);
                showNotification('<strong>Pop-up Blocker Detected!</strong><br>Please allow pop-ups for this site to print bulk reports.', 'danger', 8000);
                return;
            }

            // This listener waits for the new window to be fully loaded
            printWindow.addEventListener('load', function() {
                setTimeout(() => {
                    if (typeof printWindow.print === 'function') {
                        // Auto-print logic is now inside generate_report.php for bulk
                    } else {
                        showNotification('Could not trigger print automatically. Please use Ctrl+P in the new window.', 'info');
                    }
                    hideLoadingModal(loadingModal);
                }, 1500); // Increased timeout for better loading of images/styles
            });
            
            // Fallback in case 'load' event doesn't fire (e.g., if page errors)
            setTimeout(() => {
                hideLoadingModal(document.getElementById('loadingModal'));
            }, 7000); // 7 seconds

        } catch (e) {
            hideLoadingModal(loadingModal);
            showNotification('An error occurred while trying to open the print window.', 'danger');
            console.error(e);
        }
    }

    // Toggle for Individual Reports
    function toggleIndividualReports() {
        const reportsDiv = document.getElementById('individual-reports');
        if (reportsDiv.style.display === 'none') {
            reportsDiv.style.display = 'block';
            reportsDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            reportsDiv.style.display = 'none';
        }
    }

    // Print Current View
    function printCurrentView() {
        window.print();
    }
    
    // Add smooth animations for stats cards
    document.addEventListener('DOMContentLoaded', function() {
        const statsCards = document.querySelectorAll('.stats-card');
        statsCards.forEach((card, index) => {
            card.style.animation = `fadeInUp 0.5s ease forwards ${index * 0.1}s`;
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
        });
    });

    // Add CSS for animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* 🛠️ ADDED: More comprehensive print styles */
        @media print {
            .export-section,
            .btn-group,
            .dropdown,
            nav,
            .card.mb-4, /* Hide filter card */
            .teacher-restriction-notice,
            #individual-reports, /* Hide individual reports section */
            .card-header .btn {
                display: none !important;
            }

            h2, .d-flex.justify-content-between {
                display: none !important;
            }
            
            .container-fluid {
                padding: 0 !important;
                margin-top: 0 !important;
            }
            
            .card {
                border: none !important;
                box-shadow: none !important;
                margin-top: 0 !important;
            }
            
            .table {
                font-size: 0.8rem !important;
            }

            .stats-card {
                break-inside: avoid; /* Try to keep stats cards from splitting */
            }

            .alert {
                border: 1px solid #ddd !important;
                background: #f8f9fa !important;
                color: #333 !important;
            }
        }
    `;
    document.head.appendChild(style);

    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.metaKey) {
            switch(e.key) {
                case 'p':
                    e.preventDefault();
                    printCurrentView();
                    break;
                case 'b':
                    if (e.shiftKey) {
                        e.preventDefault();
                        bulkPrintReports();
                    }
                    break;
            }
        }
    });

    // Show keyboard shortcuts hint
    setTimeout(() => {
        showNotification('💡 <b>Tip:</b> Use <b>Ctrl+P</b> to print current view, <b>Ctrl+Shift+B</b> for bulk print', 'info', 6000);
    }, 2000);
    </script>

</body>
</html>

