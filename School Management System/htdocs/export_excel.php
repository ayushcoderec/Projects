<?php
// DEBUGGING: Force PHP to display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'functions.php';

checkLogin();

// Check if we are generating an export
if (isset($_GET['class_id']) && isset($_GET['exam_id'])) {
    $selectedClass = (int)$_GET['class_id'];
    $selectedExam = (int)$_GET['exam_id'];
    $results = [];
    $teacher_assignments = [];

    // Get class and exam details for filename
    $stmt = $pdo->prepare("SELECT name, section FROM classes WHERE id = ?");
    $stmt->execute([$selectedClass]);
    $class = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT name FROM exams WHERE id = ?");
    $stmt->execute([$selectedExam]);
    $exam = $stmt->fetch();
    
    if (!$class || !$exam) {
        die('Invalid class or exam selection.');
    }

    // Get teacher's ID and assignments if user is a teacher
    if ($_SESSION['role'] == 'teacher') {
        $stmt = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $teacher = $stmt->fetch();
        $teacher_id = $teacher['id'] ?? 0;
        
        // Get teacher's specific subject-class assignments
        $stmt = $pdo->prepare("
            SELECT ts.*, s.name as subject_name, s.code, c.name as class_name, c.section
            FROM teacher_subjects ts 
            JOIN subjects s ON ts.subject_id = s.id 
            JOIN classes c ON ts.class_id = c.id 
            WHERE ts.teacher_id = ?
        ");
        $stmt->execute([$teacher_id]);
        $teacher_assignments = $stmt->fetchAll();
    }

    // -----------------------------------------------------------------
    // REPLICATE DATA FETCHING LOGIC FROM view_results.php
    // -----------------------------------------------------------------
    
    // DEFINE: Logic to get correct full marks (Custom or Default)
    $written_marks_coalesce = "COALESCE(csc.written_full_marks, sub.written_full_marks)";
    $oral_marks_coalesce = "COALESCE(csc.oral_full_marks, sub.oral_full_marks)";
    $full_marks_coalesce = "(({$written_marks_coalesce}) + ({$oral_marks_coalesce}))";
    
    // DEFINE: Join to the new custom marks table
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
            die('You do not teach any subjects in this class!');
        } else {
            $placeholders = str_repeat('?,', count($teacher_subject_ids) - 1) . '?';
            
            $stmt = $pdo->prepare("
                SELECT 
                    s.id as student_id,
                    s.name as student_name,
                    s.roll_number,
                    s.student_id as student_reg_id,
                    sub.name as subject_name,
                    sub.code as subject_code,
                    sub.display_order,
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
                {$join_config}
                WHERE s.class_id = ? AND (m.subject_id IN ($placeholders) OR m.subject_id IS NULL)
                ORDER BY s.roll_number, sub.display_order, sub.name
            ");
            $stmt->execute(array_merge([$selectedExam], $teacher_subject_ids, [$selectedClass], $teacher_subject_ids));
            $results = $stmt->fetchAll();
        }
    } else {
        // For admin/super_admin, show all results
        $stmt = $pdo->prepare("
            SELECT 
                s.id as student_id,
                s.name as student_name,
                s.roll_number,
                s.student_id as student_reg_id,
                sub.name as subject_name,
                sub.code as subject_code,
                sub.display_order,
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
            {$join_config}
            WHERE s.class_id = ?
            ORDER BY s.roll_number, sub.display_order, sub.name
        ");
        $stmt->execute([$selectedExam, $selectedClass]);
        $results = $stmt->fetchAll();
    }

    if (empty($results)) {
        die('No results found for this selection.');
    }

    // -----------------------------------------------------------------
    // REPLICATE DATA PIVOTING LOGIC FROM view_results.php
    // -----------------------------------------------------------------
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
            
            if ($result['total_marks'] !== null && $result['total_full_marks'] > 0) {
                $studentResults[$studentId]['total_marks'] += $result['total_marks'];
                $studentResults[$studentId]['total_full_marks'] += $result['total_full_marks'];
            }
        }
    }

    if (empty($subjects)) {
        die('No subjects with marks found for this selection.');
    }

    // -----------------------------------------------------------------
    // GENERATE WIDE-FORMAT CSV
    // -----------------------------------------------------------------
    
    $filename = "marks_export_" . preg_replace('/[^a-z0-9]/i', '_', $class['name']) . "_" . preg_replace('/[^a-z0-9]/i', '_', $exam['name']) . "_" . date('Y-m-d') . ".csv";
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Write Title Row
    fputcsv($output, ["Class: {$class['name']} - {$class['section']}", "Exam: {$exam['name']}"]);
    fputcsv($output, []); // Blank row

    // Build and Write Header Rows (matching view_results.php table)
    $header_row_1 = ['Roll No.', 'Student Name', 'Student ID'];
    $header_row_2 = ['', '', ''];
    $header_row_3 = ['', '', ''];

    foreach ($subjects as $subjectName => $subjectInfo) {
        $header_row_1[] = $subjectInfo['name'] . " (" . $subjectInfo['code'] . ")";
        $header_row_1[] = ''; // for merging
        $header_row_1[] = ''; // for merging
        $header_row_1[] = ''; // for merging

        $header_row_2[] = 'Written';
        $header_row_2[] = 'Oral';
        $header_row_2[] = 'Total';
        $header_row_2[] = '% / Grade';
        
        $header_row_3[] = '/' . $subjectInfo['written_full'];
        $header_row_3[] = '/' . $subjectInfo['oral_full'];
        $header_row_3[] = '/' . $subjectInfo['total_full'];
        $header_row_3[] = '';
    }

    // Add total columns
    $header_row_1[] = 'Grand Total';
    $header_row_1[] = 'Total Full Marks';
    $header_row_1[] = 'Overall %';
    $header_row_1[] = 'Overall Grade';
    $header_row_1[] = 'Result';

    // Add placeholders for total columns
    for ($i = 0; $i < 5; $i++) {
        $header_row_2[] = '';
        $header_row_3[] = '';
    }

    fputcsv($output, $header_row_1);
    fputcsv($output, $header_row_2);
    fputcsv($output, $header_row_3);

    // Write Data Rows
    foreach ($studentResults as $studentId => $studentResult) {
        $data_row = [
            $studentResult['roll_number'],
            $studentResult['student_name'],
            $studentResult['student_reg_id']
        ];

        // Loop through subjects in the *exact* same order as headers
        foreach (array_keys($subjects) as $subjectName) {
            $subjectData = $studentResult['subjects'][$subjectName] ?? null;
            
            $data_row[] = $subjectData ? ($subjectData['written_marks'] ?? 'N/A') : 'N/A';
            $data_row[] = $subjectData ? ($subjectData['oral_marks'] ?? 'N/A') : 'N/A';
            $data_row[] = $subjectData ? ($subjectData['total_marks'] ?? 'N/A') : 'N/A';
            
            // Percentage/Grade cell
            $pct_cell = 'N/A';
            if ($subjectData && $subjectData['percentage'] !== null) {
                $pct = $subjectData['percentage'];
                $subjectGrade = 'F';
                if ($pct >= 90) $subjectGrade = 'A+';
                elseif ($pct >= 80) $subjectGrade = 'A';
                elseif ($pct >= 70) $subjectGrade = 'B+';
                elseif ($pct >= 60) $subjectGrade = 'B';
                elseif ($pct >= 50) $subjectGrade = 'C+';
                elseif ($pct >= 40) $subjectGrade = 'C';
                elseif ($pct >= 35) $subjectGrade = 'D';
                $pct_cell = number_format($pct, 1) . '% (' . $subjectGrade . ')';
            }
            $data_row[] = $pct_cell;
        }

        // Calculate Overall Totals (from view_results.php)
        $overallPercentage = $studentResult['total_full_marks'] > 0 
            ? ($studentResult['total_marks'] / $studentResult['total_full_marks']) * 100 
            : 0;
        
        $overallGrade = 'F';
        if ($overallPercentage >= 90) { $overallGrade = 'A+'; }
        elseif ($overallPercentage >= 80) { $overallGrade = 'A'; }
        elseif ($overallPercentage >= 70) { $overallGrade = 'B+'; }
        elseif ($overallPercentage >= 60) { $overallGrade = 'B'; }
        elseif ($overallPercentage >= 50) { $overallGrade = 'C+'; }
        elseif ($overallPercentage >= 40) { $overallGrade = 'C'; }
        elseif ($overallPercentage >= 35) { $overallGrade = 'D'; }
        
        $resultText = $overallPercentage >= 35 ? 'PASS' : 'FAIL';

        // Add total columns
        $data_row[] = $studentResult['total_marks'];
        $data_row[] = $studentResult['total_full_marks'];
        $data_row[] = number_format($overallPercentage, 2) . '%';
        $data_row[] = $overallGrade;
        $data_row[] = $resultText;

        fputcsv($output, $data_row);
    }

    fclose($output);
    exit;
}

// -----------------------------------------------------------------
// IF NO PARAMS, SHOW HTML FORM (from old export file)
// -----------------------------------------------------------------

// Get dropdown data based on role (Copied from view_results.php)
if ($_SESSION['role'] == 'teacher') {
    $classes = [];
    $assignedClasses = [];
    
    // Get teacher's ID and assignments
    $stmt = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch();
    $teacher_id = $teacher['id'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT ts.*, s.name as subject_name, s.code, c.name as class_name, c.section
        FROM teacher_subjects ts 
        JOIN subjects s ON ts.subject_id = s.id 
        JOIN classes c ON ts.class_id = c.id 
        WHERE ts.teacher_id = ?
    ");
    $stmt->execute([$teacher_id]);
    $teacher_assignments = $stmt->fetchAll();
    
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Export Excel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="view_results.php">View Results</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h2><i class="fas fa-file-excel me-2"></i>Export Grade Sheet to Excel (CSV)</h2>
        <p>Select a class and exam to generate a CSV export of the grade sheet.</p>

        <?php if ($_SESSION['role'] == 'teacher' && empty($teacher_assignments)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>No Assignments Found:</strong> You have not been assigned to any classes. You cannot export results.
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Select Export Parameters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="export_excel.php" target="_blank">
                        <div class="row">
                            <div class="col-md-5">
                                <label class="form-label">
                                    Select Class
                                    <?php if ($_SESSION['role'] == 'teacher'): ?>
                                        <small class="text-muted">(Your assigned classes)</small>
                                    <?php endif; ?>
                                </label>
                                <select class="form-select" name="class_id" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?= $class['id'] ?>">
                                            <?= $class['name'] ?> - <?= $class['section'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-5">
                                <label class="form-label">Select Exam</label>
                                <select class="form-select" name="exam_id" required>
                                    <option value="">Select Exam</option>
                                    <?php foreach ($exams as $exam): ?>
                                        <option value="<?= $exam['id'] ?>">
                                            <?= $exam['name'] ?> (<?= formatDate($exam['start_date']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-download me-2"></i>Export CSV
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>