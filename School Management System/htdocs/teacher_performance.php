<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin', 'admin']);

$message = '';
$selectedTeacher = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : '';
$selectedExam = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : '';
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'all'; // 'all' or 'individual'

// Handle exclusion/inclusion actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'exclude_student':
            $teacher_id = (int)$_POST['teacher_id'];
            $student_id = (int)$_POST['student_id'];
            $exam_id = (int)$_POST['exam_id'];
            $subject_id = (int)$_POST['subject_id'];
            $reason = sanitize($_POST['reason']);

            $stmt = $pdo->prepare("
                INSERT INTO teacher_performance_exclusions 
                (teacher_id, student_id, exam_id, subject_id, reason, excluded_by) 
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                reason = VALUES(reason), excluded_by = VALUES(excluded_by), 
                excluded_at = CURRENT_TIMESTAMP, is_active = TRUE
            ");
            
            if ($stmt->execute([$teacher_id, $student_id, $exam_id, $subject_id, $reason, $_SESSION['user_id']])) {
                logAudit($_SESSION['user_id'], 'EXCLUDE', 'teacher_performance_exclusions', $pdo->lastInsertId());
                $message = showAlert('Student excluded successfully!');
            } else {
                $message = showAlert('Error excluding student!', 'danger');
            }
            break;

        case 'include_student':
            $exclusion_id = (int)$_POST['exclusion_id'];
            $stmt = $pdo->prepare("UPDATE teacher_performance_exclusions SET is_active = FALSE WHERE id = ?");
            if ($stmt->execute([$exclusion_id])) {
                logAudit($_SESSION['user_id'], 'INCLUDE', 'teacher_performance_exclusions', $exclusion_id);
                $message = showAlert('Student included back in performance calculation!');
            }
            break;

        case 'bulk_exclude':
            $teacher_id = (int)$_POST['teacher_id'];
            $exam_id = (int)$_POST['exam_id'];
            $subject_id = (int)$_POST['subject_id'];
            $student_ids = $_POST['student_ids'] ?? [];
            $reason = sanitize($_POST['bulk_reason']);
            $excluded_count = 0;
            foreach ($student_ids as $student_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO teacher_performance_exclusions 
                    (teacher_id, student_id, exam_id, subject_id, reason, excluded_by) 
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE reason = VALUES(reason), is_active = TRUE
                ");
                if ($stmt->execute([$teacher_id, $student_id, $exam_id, $subject_id, $reason, $_SESSION['user_id']])) {
                    $excluded_count++;
                }
            }
            $message = showAlert("Successfully excluded $excluded_count students!");
            break;
    }
}

// Get dropdown data
$exams = getAllExams();
$teachers = $pdo->query("SELECT t.*, u.name FROM teachers t JOIN users u ON t.user_id = u.id ORDER BY u.name")->fetchAll();

/**
 * CORE LOGIC: Automatically picks 60 vs 80 from DB using COALESCE
 */
function getTeacherOverallPerformance($teacher_id, $exam_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT ts.*, s.name as subject_name, c.name as class_name
        FROM teacher_subjects ts 
        JOIN subjects s ON ts.subject_id = s.id 
        JOIN classes c ON ts.class_id = c.id 
        WHERE ts.teacher_id = ?
    ");
    $stmt->execute([$teacher_id]);
    $assignments = $stmt->fetchAll();
    
    $total_students = 0; $excluded_students = 0; $students_with_marks = 0;
    $sum_obtained = 0; $sum_possible = 0; $passed_students = 0;
    $locked_entries = 0; $subjects_data = [];
    
    foreach ($assignments as $assignment) {
        $subject_id = $assignment['subject_id'];
        $class_id = $assignment['class_id'];
        
        $stmt = $pdo->prepare("
            SELECT s.id, m.written_marks, m.is_locked,
                   COALESCE(scfm.written_full_marks, sub.written_full_marks) as resolved_max,
                   tpe.is_active as is_excluded
            FROM students s
            LEFT JOIN marks m ON s.id = m.student_id AND m.subject_id = ? AND m.exam_id = ?
            JOIN subjects sub ON sub.id = ?
            LEFT JOIN subject_class_full_marks scfm ON scfm.subject_id = sub.id AND scfm.class_id = s.class_id
            LEFT JOIN teacher_performance_exclusions tpe ON s.id = tpe.student_id 
                AND tpe.teacher_id = ? AND tpe.subject_id = ? AND tpe.exam_id = ? AND tpe.is_active = TRUE
            WHERE s.class_id = ?
        ");
        $stmt->execute([$subject_id, $exam_id, $subject_id, $teacher_id, $subject_id, $exam_id, $class_id]);
        $students = $stmt->fetchAll();
        
        $sub_obtained = 0; $sub_possible = 0; $sub_passed = 0; $sub_count = 0;
        
        foreach ($students as $student) {
            $total_students++;
            if ($student['is_excluded']) { $excluded_students++; continue; }
            if ($student['written_marks'] !== null) {
                $students_with_marks++;
                $sub_count++;
                $score = (float)$student['written_marks'];
                $full = (float)$student['resolved_max'];
                
                $sum_obtained += $score; $sum_possible += $full;
                $sub_obtained += $score; $sub_possible += $full;
                
                if ($full > 0 && ($score / $full) * 100 >= 35) { $passed_students++; $sub_passed++; }
                if ($student['is_locked']) $locked_entries++;
            }
        }
        $subjects_data[] = array_merge($assignment, [
            'stats' => ['avg_percentage' => $sub_possible > 0 ? ($sub_obtained / $sub_possible) * 100 : 0]
        ]);
    }
    
    $eligible = $total_students - $excluded_students;
    return [
        'total_students' => $total_students,
        'excluded_students' => $excluded_students,
        'eligible_students' => $eligible,
        'students_with_marks' => $students_with_marks,
        'avg_percentage' => $sum_possible > 0 ? ($sum_obtained / $sum_possible) * 100 : 0,
        'pass_rate' => $students_with_marks > 0 ? ($passed_students / $students_with_marks) * 100 : 0,
        'completion_rate' => $eligible > 0 ? ($students_with_marks / $eligible) * 100 : 0,
        'passed_students' => $passed_students,
        'failed_students' => $students_with_marks - $passed_students,
        'locked_entries' => $locked_entries,
        'subjects_data' => $subjects_data
    ];
}

function getTeacherPerformanceData($teacher_id, $exam_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT ts.*, s.name as subject_name, c.name as class_name, c.section FROM teacher_subjects ts JOIN subjects s ON ts.subject_id = s.id JOIN classes c ON ts.class_id = c.id WHERE ts.teacher_id = ?");
    $stmt->execute([$teacher_id]);
    $assignments = $stmt->fetchAll();
    
    $performance = [];
    foreach ($assignments as $assignment) {
        $stmt = $pdo->prepare("
            SELECT s.*, m.written_marks, m.is_locked,
                   COALESCE(scfm.written_full_marks, sub.written_full_marks) as res_max,
                   tpe.id as exclusion_id, tpe.reason as exclusion_reason, tpe.is_active as is_excluded
            FROM students s
            LEFT JOIN marks m ON s.id = m.student_id AND m.subject_id = ? AND m.exam_id = ?
            JOIN subjects sub ON sub.id = ?
            LEFT JOIN subject_class_full_marks scfm ON scfm.subject_id = sub.id AND scfm.class_id = s.class_id
            LEFT JOIN teacher_performance_exclusions tpe ON s.id = tpe.student_id 
                AND tpe.teacher_id = ? AND tpe.subject_id = ? AND tpe.exam_id = ? AND tpe.is_active = TRUE
            WHERE s.class_id = ?
            ORDER BY s.roll_number
        ");
        $stmt->execute([$assignment['subject_id'], $exam_id, $assignment['subject_id'], $teacher_id, $assignment['subject_id'], $exam_id, $assignment['class_id']]);
        $students = $stmt->fetchAll();
        
        $stats = ['total'=>count($students), 'excluded'=>0, 'with_marks'=>0, 'obtained'=>0, 'possible'=>0, 'passed'=>0, 'locked'=>0];
        foreach ($students as &$student) {
            $student['db_full_marks'] = (float)$student['res_max'];
            if ($student['is_excluded']) { $stats['excluded']++; continue; }
            if ($student['written_marks'] !== null) {
                $stats['with_marks']++;
                $score = (float)$student['written_marks'];
                $stats['obtained'] += $score;
                $stats['possible'] += $student['db_full_marks'];
                $student['percentage'] = $student['db_full_marks'] > 0 ? ($score / $student['db_full_marks']) * 100 : 0;
                if ($student['percentage'] >= 35) $stats['passed']++;
                if ($student['is_locked']) $stats['locked']++;
            }
        }
        $eligible = $stats['total'] - $stats['excluded'];
        $performance[] = [
            'assignment' => $assignment,
            'students' => $students,
            'statistics' => [
                'total_students' => $stats['total'],
                'excluded_students' => $stats['excluded'],
                'eligible_students' => $eligible,
                'students_with_marks' => $stats['with_marks'],
                'avg_percentage' => $stats['possible'] > 0 ? ($stats['obtained'] / $stats['possible']) * 100 : 0,
                'pass_rate' => $stats['with_marks'] > 0 ? ($stats['passed'] / $stats['with_marks']) * 100 : 0,
                'completion_rate' => $eligible > 0 ? ($stats['with_marks'] / $eligible) * 100 : 0,
                'locked_entries' => $stats['locked']
            ]
        ];
    }
    return $performance;
}

function getAllTeachersPerformance($exam_id) {
    global $pdo;
    $stmt = $pdo->query("SELECT t.id as teacher_id, u.name as teacher_name, t.employee_id, COUNT(DISTINCT ts.subject_id) as subjects_count, COUNT(DISTINCT ts.class_id) as classes_count FROM teachers t JOIN users u ON t.user_id = u.id LEFT JOIN teacher_subjects ts ON t.id = ts.teacher_id GROUP BY t.id, u.name, t.employee_id ORDER BY u.name");
    $teachers_list = $stmt->fetchAll();
    $results = [];
    foreach ($teachers_list as $t) { $results[] = array_merge($t, getTeacherOverallPerformance($t['teacher_id'], $exam_id)); }
    return $results;
}

function getPerformanceClass($p) { return $p >= 80 ? 'performance-excellent' : ($p >= 70 ? 'performance-good' : ($p >= 60 ? 'performance-average' : 'performance-poor')); }
function getRowClass($p) { return $p >= 80 ? 'table-success' : ($p >= 70 ? 'table-info' : ($p >= 60 ? 'table-warning' : 'table-danger')); }

$allTeachersData = ($selectedExam && $viewMode == 'all') ? getAllTeachersPerformance($selectedExam) : [];
$individualTeacherData = ($selectedTeacher && $selectedExam && $viewMode == 'individual') ? getTeacherPerformanceData($selectedTeacher, $selectedExam) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Teacher Performance Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        .performance-card { border-left: 4px solid #007bff; transition: all 0.3s ease; }
        .performance-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .metric-value { font-size: 2rem; font-weight: bold; }
        .metric-label { color: #6c757d; font-size: 0.9rem; }
        .performance-excellent { border-left-color: #28a745; }
        .performance-good { border-left-color: #17a2b8; }
        .performance-average { border-left-color: #ffc107; }
        .performance-poor { border-left-color: #dc3545; }
        .filter-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px; padding: 25px; color: white; margin-bottom: 30px;
        }
        .stat-icon { font-size: 2.5rem; opacity: 0.3; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); }
        .student-row.excluded { background-color: #fff3cd; opacity: 0.7; }
        .bulk-actions { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px; padding: 15px; }
        .view-toggle { background: white; border-radius: 10px; padding: 5px; }
        .view-toggle .btn { border-radius: 8px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php"><i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?></a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chart-line me-2"></i>Teacher Performance Dashboard</h2>
            <?php if ($selectedExam): ?>
                <div class="btn-group">
                    <?php if ($viewMode == 'individual' && $selectedTeacher): ?>
                        <a href="export_teacher_performance.php?teacher_id=<?= $selectedTeacher ?>&exam_id=<?= $selectedExam ?>" class="btn btn-success"><i class="fas fa-file-excel me-2"></i>Export Excel</a>
                    <?php else: ?>
                        <a href="export_all_teachers_performance.php?exam_id=<?= $selectedExam ?>" class="btn btn-success"><i class="fas fa-file-excel me-2"></i>Export All</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?= $message ?>

        <!-- FILTER SECTION: Logic is now fully automatic from database -->
        <div class="filter-section shadow">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label text-white"><i class="fas fa-calendar me-2"></i>Select Exam (System handles 60 vs 80 marks base)</label>
                    <select class="form-select shadow-sm" name="exam_id" required onchange="this.form.submit()">
                        <option value="">Choose Exam</option>
                        <?php foreach ($exams as $exam): ?>
                            <option value="<?= $exam['id'] ?>" <?= $selectedExam == $exam['id'] ? 'selected' : '' ?>><?= $exam['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($selectedExam): ?>
                    <div class="col-md-7">
                        <label class="form-label text-white"><i class="fas fa-eye me-2"></i>View Mode</label>
                        <div class="view-toggle shadow-sm">
                            <div class="btn-group w-100">
                                <a href="?exam_id=<?= $selectedExam ?>&view=all" class="btn <?= $viewMode == 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">Compare All Teachers</a>
                                <button type="button" class="btn <?= $viewMode == 'individual' ? 'btn-primary' : 'btn-outline-primary' ?>" data-bs-toggle="modal" data-bs-target="#selectTeacherModal">Teacher Details</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($viewMode == 'all' && !empty($allTeachersData)): ?>
            <!-- Stats Header Cards -->
            <div class="row mb-4">
                <?php
                $totalTeachers = count($allTeachersData); $excellentCount = 0; $poorCount = 0; $avgOverall = 0;
                foreach ($allTeachersData as $t) {
                    $avgOverall += $t['avg_percentage'];
                    if ($t['avg_percentage'] >= 80) $excellentCount++;
                    elseif ($t['avg_percentage'] < 60) $poorCount++;
                }
                $avgOverall = $totalTeachers > 0 ? $avgOverall / $totalTeachers : 0;
                ?>
                <div class="col-md-3"><div class="card bg-primary text-white text-center position-relative p-2"><div class="card-body"><i class="fas fa-users stat-icon"></i><h3><?= $totalTeachers ?></h3>Total Teachers</div></div></div>
                <div class="col-md-3"><div class="card bg-success text-white text-center position-relative p-2"><div class="card-body"><i class="fas fa-star stat-icon"></i><h3><?= $excellentCount ?></h3>Excellent (≥80%)</div></div></div>
                <div class="col-md-3"><div class="card bg-warning text-white text-center position-relative p-2"><div class="card-body"><i class="fas fa-exclamation-triangle stat-icon"></i><h3><?= $poorCount ?></h3>Need Attention</div></div></div>
                <div class="col-md-3"><div class="card bg-info text-white text-center position-relative p-2"><div class="card-body"><i class="fas fa-chart-line stat-icon"></i><h3><?= number_format($avgOverall, 1) ?>%</h3>Overall Average</div></div></div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <table id="teachersTable" class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Rank</th><th>Teacher Name</th><th>Avg Written %</th><th>Pass Rate</th><th>Completion</th><th>Students</th><th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            usort($allTeachersData, function($a, $b) { return $b['avg_percentage'] <=> $a['avg_percentage']; });
                            $rank = 1;
                            foreach ($allTeachersData as $t): 
                            ?>
                                <tr class="<?= getRowClass($t['avg_percentage']) ?>">
                                    <td><?= $rank++ ?></td>
                                    <td><strong><?= $t['teacher_name'] ?></strong></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?= $t['avg_percentage'] >= 80 ? 'bg-success' : ($t['avg_percentage'] < 60 ? 'bg-danger' : 'bg-warning') ?>" style="width: <?= $t['avg_percentage'] ?>%">
                                                <?= number_format($t['avg_percentage'], 1) ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= number_format($t['pass_rate'], 1) ?>%</td>
                                    <td><?= number_format($t['completion_rate'], 1) ?>%</td>
                                    <td><?= $t['students_with_marks'] ?>/<?= $t['eligible_students'] ?></td>
                                    <td><a href="?exam_id=<?= $selectedExam ?>&view=individual&teacher_id=<?= $t['teacher_id'] ?>" class="btn btn-sm btn-primary">Details</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-md-6"><div class="card"><div class="card-body"><canvas id="performanceChart"></canvas></div></div></div>
                <div class="col-md-6"><div class="card"><div class="card-body"><canvas id="comparisonChart"></canvas></div></div></div>
            </div>
        <?php endif; ?>

        <?php if ($viewMode == 'individual' && $individualTeacherData): ?>
            <?php
            $teacherInfo = $pdo->prepare("SELECT t.*, u.name FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
            $teacherInfo->execute([$selectedTeacher]);
            $teacher = $teacherInfo->fetch();
            ?>
            <div class="card mb-4 border-0 shadow-sm"><div class="card-body"><h3><i class="fas fa-user-tie me-2"></i><?= $teacher['name'] ?></h3><p class="text-muted">Calculations based on dynamic class full marks (60 vs 80).</p></div></div>
            
            <?php foreach ($individualTeacherData as $index => $data): $s = $data['statistics']; $a = $data['assignment']; ?>
                <div class="card performance-card <?= getPerformanceClass($s['avg_percentage']) ?> mb-4 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?= $a['subject_name'] ?> - <?= $a['class_name'] ?> (<?= $a['section'] ?>)</h5>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#det-<?= $index ?>">View Students</button>
                            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#bulkExcludeModal" onclick="setBulkExcludeData(<?= $selectedTeacher ?>, <?= $selectedExam ?>, <?= $a['subject_id'] ?>, '<?= $a['subject_name'] ?>')"><i class="fas fa-user-slash me-1"></i>Bulk Exclude</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row text-center align-items-center">
                            <div class="col-md-3"><strong><?= number_format($s['avg_percentage'], 1) ?>%</strong><br><small class="text-muted">Avg Written</small></div>
                            <div class="col-md-3"><strong><?= number_format($s['pass_rate'], 1) ?>%</strong><br><small class="text-muted">Pass Rate</small></div>
                            <div class="col-md-3"><strong><?= $s['students_with_marks'] ?>/<?= $s['eligible_students'] ?></strong><br><small class="text-muted">Entries</small></div>
                            <div class="col-md-3"><span class="badge bg-dark">Full Marks Base: <?= $data['students'][0]['db_full_marks'] ?></span></div>
                        </div>
                        <div class="collapse mt-3" id="det-<?= $index ?>">
                            <table class="table table-sm">
                                <thead class="table-light"><tr><th>Roll</th><th>Name</th><th>Written Marks</th><th>Out Of</th><th>%</th><th>Action</th></tr></thead>
                                <tbody>
                                    <?php foreach ($data['students'] as $stu): ?>
                                        <tr class="<?= $stu['is_excluded'] ? 'student-row excluded' : '' ?>">
                                            <td><?= $stu['roll_number'] ?></td>
                                            <td><?= $stu['name'] ?><?php if($stu['is_excluded']) echo '<br><small class="badge bg-warning">Excluded: '.$stu['exclusion_reason'].'</small>'; ?></td>
                                            <td><?= $stu['written_marks'] ?? '-' ?></td>
                                            <td><?= $stu['db_full_marks'] ?></td>
                                            <td><?= isset($stu['percentage']) ? number_format($stu['percentage'], 1).'%' : '-' ?></td>
                                            <td>
                                                <?php if (!$stu['is_excluded']): ?>
                                                    <button class="btn btn-sm btn-outline-warning exclude-student-btn" data-bs-toggle="modal" data-bs-target="#excludeStudentModal" data-teacher="<?= $selectedTeacher ?>" data-student="<?= $stu['id'] ?>" data-student-name="<?= $stu['name'] ?>" data-exam="<?= $selectedExam ?>" data-subject="<?= $a['subject_id'] ?>" data-subject-name="<?= $a['subject_name'] ?>"><i class="fas fa-user-slash"></i></button>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="include_student"><input type="hidden" name="exclusion_id" value="<?= $stu['exclusion_id'] ?>"><button class="btn btn-sm btn-outline-success"><i class="fas fa-user-check"></i></button></form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- All Modals -->
    <div class="modal fade" id="selectTeacherModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <form method="GET"><div class="modal-header"><h5>Select Teacher</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="exam_id" value="<?= $selectedExam ?>"><input type="hidden" name="view" value="individual">
                    <select class="form-select" name="teacher_id" required>
                        <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= $t['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary">Go</button></div>
            </form>
        </div></div>
    </div>

    <div class="modal fade" id="excludeStudentModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <form method="POST"><div class="modal-header"><h5>Exclude Student</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="exclude_student">
                    <input type="hidden" name="teacher_id" id="exclude_teacher_id"><input type="hidden" name="student_id" id="exclude_student_id"><input type="hidden" name="exam_id" id="exclude_exam_id"><input type="hidden" name="subject_id" id="exclude_subject_id">
                    <p>Exclude <strong id="exclude_student_name"></strong> from calculations?</p>
                    <select class="form-select" name="reason" required><option value="Medical Leave">Medical Leave</option><option value="Absence">Absence</option><option value="Other">Other</option></select>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-warning">Exclude</button></div>
            </form>
        </div></div>
    </div>

    <div class="modal fade" id="bulkExcludeModal" tabindex="-1">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <form method="POST"><div class="modal-header"><h5>Bulk Exclude Students</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="bulk_exclude">
                    <input type="hidden" name="teacher_id" id="bulk_teacher_id"><input type="hidden" name="exam_id" id="bulk_exam_id"><input type="hidden" name="subject_id" id="bulk_subject_id">
                    <div class="bulk-actions mb-3"><h6 class="text-white">Subject: <span id="bulk_subject_name"></span></h6><select class="form-select" name="bulk_reason" required><option value="Medical Leave - Multiple">Medical Leave - Multiple</option><option value="Other">Other</option></select></div>
                    <div id="bulk_student_list" class="p-3 border rounded bg-light" style="max-height:300px; overflow-y:auto;"></div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-warning">Exclude Selected</button></div>
            </form>
        </div></div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    $(document).ready(function() {
        $('#teachersTable').DataTable({ order: [[4, 'desc']] });
        $('.exclude-student-btn').on('click', function() {
            $('#exclude_teacher_id').val($(this).data('teacher')); $('#exclude_student_id').val($(this).data('student'));
            $('#exclude_exam_id').val($(this).data('exam')); $('#exclude_subject_id').val($(this).data('subject'));
            $('#exclude_student_name').text($(this).data('student-name'));
        });

        <?php if ($viewMode == 'all' && !empty($allTeachersData)): ?>
        new Chart(document.getElementById('performanceChart'), {
            type: 'doughnut', data: { labels: ['Excellent (≥80%)', 'Needs Attention (<60%)'], datasets: [{ data: [<?= $excellentCount ?>, <?= $poorCount ?>], backgroundColor: ['#28a745', '#dc3545'] }] }
        });
        const top10 = <?= json_encode(array_slice($allTeachersData, 0, 10)) ?>;
        new Chart(document.getElementById('comparisonChart'), {
            type: 'bar', data: { labels: top10.map(t => t.teacher_name.substring(0, 15)), datasets: [{ label: 'Avg Written %', data: top10.map(t => t.avg_percentage.toFixed(1)), backgroundColor: '#0d6efd' }] }
        });
        <?php endif; ?>
    });

    function setBulkExcludeData(teacherId, examId, subjectId, subjectName) {
        $('#bulk_teacher_id').val(teacherId); $('#bulk_exam_id').val(examId); $('#bulk_subject_id').val(subjectId); $('#bulk_subject_name').text(subjectName);
        $('#bulk_student_list').html('Loading students...');
        fetch(`get_students_for_exclusion.php?teacher_id=${teacherId}&exam_id=${examId}&subject_id=${subjectId}`)
            .then(r => r.json()).then(data => {
                if(data.success) {
                    let html = '<div class="row">';
                    data.students.forEach(s => {
                        if(!s.is_excluded) html += `<div class="col-md-6 mb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="student_ids[]" value="${s.id}" id="s${s.id}"><label class="form-check-label" for="s${s.id}">${s.roll_number} - ${s.name}</label></div></div>`;
                    });
                    html += '</div>'; $('#bulk_student_list').html(html);
                }
            });
    }
    </script>
</body>
</html>