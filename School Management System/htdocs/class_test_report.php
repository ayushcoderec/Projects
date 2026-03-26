<?php
// DEBUGGING: Force PHP to display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'functions.php';

checkLogin();

// --- Variable Initialization ---
$selectedClass = '';
$selectedExam = '';
$class_test_exams = [];
$student_data = [];
$subjects = [];
$selectedClassDetails = null;
$selectedExamDetails = null;

// --- Get Data for Dropdowns ---

// 1. Get all classes (for all roles)
$all_classes = getAllClasses();

// 2. Get ONLY exams marked as 'class_test'
$stmt = $pdo->prepare("SELECT * FROM exams WHERE exam_type = 'class_test' ORDER BY start_date DESC");
$stmt->execute();
$class_test_exams = $stmt->fetchAll();


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['load_report'])) {
    
    $selectedClass = (int)$_POST['class_id'];
    $selectedExam = (int)$_POST['exam_id'];

    if ($selectedClass && $selectedExam) {

        // Get details for report header
        $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt->execute([$selectedClass]);
        $selectedClassDetails = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$selectedExam]);
        $selectedExamDetails = $stmt->fetch();

        // --- 1. Get all SCHOLASTIC subjects ---
        $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE subject_type = 'scholastic' ORDER BY display_order, name");
        $stmt->execute();
        $subjects_raw = $stmt->fetchAll();
        foreach ($subjects_raw as $sub) {
            $subjects[$sub['id']] = $sub['name'];
        }

        // --- 2. Get all students and their marks ---
        $sql = "SELECT 
                    s.id as student_id, 
                    s.roll_number, 
                    s.name as student_name, 
                    m.subject_id, 
                    m.written_marks
                FROM students s 
                LEFT JOIN marks m ON s.id = m.student_id AND m.exam_id = :exam_id
                WHERE s.class_id = :class_id
                ORDER BY s.roll_number, s.name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['exam_id' => $selectedExam, 'class_id' => $selectedClass]);
        $results = $stmt->fetchAll();

        // --- 3. Process the results into a pivot array ---
        foreach ($results as $row) {
            $student_id = $row['student_id'];

            if (!isset($student_data[$student_id])) {
                $student_data[$student_id] = [
                    'roll_number'   => $row['roll_number'],
                    'student_name'  => $row['student_name'],
                    'marks'         => []
                ];
            }

            if ($row['subject_id'] && isset($subjects[$row['subject_id']])) {
                 $student_data[$student_id]['marks'][$row['subject_id']] = $row['written_marks'];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Class Test Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* 🛠️ ADDED: Link style */
        td a {
            text-decoration: none;
            font-weight: 500;
        }
        td a:hover {
            text-decoration: underline;
        }
        .fa-xs {
            font-size: 0.7em;
            opacity: 0.6;
        }
    
        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .table {
                font-size: 0.9rem;
            }
            h3 {
                font-size: 1.25rem;
            }
            .alert-info {
                display: none;
            }
            td a {
                text-decoration: none !important;
                color: #000 !important;
            }
            .fa-external-link-alt {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary no-print">
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

    <div class="container mt-4">

        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Generate Class Test Report</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-5">
                            <label class="form-label">Select Class</label>
                            <select class="form-select" name="class_id" required>
                                <option value="">Choose Class</option>
                                <?php foreach ($all_classes as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                        <?= $class['name'] ?> - Section <?= $class['section'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Select Class Test</label>
                            <select class="form-select" name="exam_id" required>
                                <option value="">Choose Test</option>
                                <?php foreach ($class_test_exams as $exam): ?>
                                    <option value="<?= $exam['id'] ?>" <?= $selectedExam == $exam['id'] ? 'selected' : '' ?>>
                                        <?= $exam['name'] ?> (<?= formatDate($exam['start_date']) ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php if (empty($class_test_exams)): ?>
                                    <option disabled>No 'Class Test' exams found. Add them in Manage Exams.</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="load_report" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i>Load
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($student_data) && !empty($subjects)): ?>
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="mb-0">Class Test Report</h3>
                            <span class="text-muted">
                                Class: <?= $selectedClassDetails['name'] ?> (<?= $selectedClassDetails['section'] ?>) | 
                                Exam: <?= $selectedExamDetails['name'] ?>
                            </span>
                        </div>
                        <div class="no-print">
                             <a href="generate_class_test_report.php?bulk=1&class_id=<?= $selectedClass ?>&exam_id=<?= $selectedExam ?>" target="_blank" class="btn btn-info btn-sm">
                                <i class="fas fa-print me-2"></i>Bulk Print All
                            </a>
                            <button class="btn btn-success btn-sm" onclick="window.print()">
                                <i class="fas fa-print me-2"></i>Print This List
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center">
                            
                            <thead class="table-dark">
                                <tr>
                                    <th rowspan="2" class="align-middle">Roll No.</th>
                                    <th rowspan="2" class="align-middle" style="min-width: 150px; text-align: left;">Student Name</th>
                                    <?php foreach ($subjects as $sub_name): ?>
                                        <th><?= $sub_name ?></th>
                                    <?php endforeach; ?>
                                    <th rowspan="2" class="align-middle">Total</th>
                                    <th rowspan="2" class="align-middle">Percent</th>
                                </tr>
                                <tr>
                                    <?php
                                    $total_max_marks = count($subjects) * 20;
                                    foreach ($subjects as $sub_name):
                                    ?>
                                        <th>20</th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($student_data as $student_id => $student): ?>
                                    <tr>
                                        <td><?= $student['roll_number'] ?></td>
                                        
                                        <td style="text-align: left;">
                                            <a href="generate_class_test_report.php?student_id=<?= $student_id ?>&exam_id=<?= $selectedExam ?>" target="_blank" title="View styled report for <?= $student['student_name'] ?>">
                                                <?= $student['student_name'] ?>
                                                <i class="fas fa-external-link-alt fa-xs ms-1 no-print"></i>
                                            </a>
                                        </td>
                                        
                                        <?php
                                        $student_total = 0;
                                        foreach ($subjects as $sub_id => $sub_name):
                                            $mark = $student['marks'][$sub_id] ?? 0;
                                            $student_total += $mark;
                                            echo '<td>' . ($mark == 0 ? '-' : $mark) . '</td>';
                                        endforeach;

                                        $percentage = ($total_max_marks > 0) ? ($student_total / $total_max_marks) * 100 : 0;
                                        ?>
                                        
                                        <td><strong><?= $student_total ?></strong> / <?= $total_max_marks ?></td>
                                        <td><strong><?= number_format($percentage, 1) ?>%</strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>
                </div>
            </div>
        
        <?php elseif ($_SERVER['REQUEST_METHOD'] == 'POST'): ?>
            <div class="alert alert-warning">
                No student data or subjects found for the selected class. Please check your setup in "Manage Students" and "Manage Subjects".
            </div>
        <?php else: ?>
             <div class="alert alert-info">
                Please select a class and a class test to generate a report.
            </div>
        <?php endif; ?>

    </div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>