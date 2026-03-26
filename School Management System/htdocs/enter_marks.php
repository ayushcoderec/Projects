<?php
/**
 * Enter Marks System - Complete Fixed Version v3
 * Kept all existing features.
 * FIXED: Roll number sorting (1, 2, 12 instead of 1, 12, 2)
 */

require_once 'config.php';
require_once 'functions.php';

checkLogin();

$message = '';
$selectedExam = '';
$selectedClass = '';
$selectedSubject = '';

// ============================================
// TEACHER ACCESS CONTROL
// ============================================
function checkTeacherAccess($teacher_id, $class_id, $subject_id) {
    global $pdo;

    if (in_array($_SESSION['role'], ['super_admin', 'admin'])) {
        return true;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM teacher_subjects
        WHERE teacher_id = ? AND class_id = ? AND subject_id = ?
    ");
    $stmt->execute([$teacher_id, $class_id, $subject_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result['count'] > 0;
}

function getTeacherId($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id FROM teachers WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['id'] : null;
}

// ============================================
// SAVE DRAFT FUNCTION
// ============================================
function saveMarksDraft() {
    global $pdo;

    $exam_id = (int)$_POST['exam_id'];
    $subject_id = (int)$_POST['subject_id'];
    $marks_data = $_POST['marks'] ?? [];

    if (empty($marks_data)) {
        return ['success' => false, 'message' => showAlert('No marks data to save!', 'warning')];
    }

    try {
        $pdo->beginTransaction();
        $updated = 0;

        foreach ($marks_data as $student_id => $data) {
            $student_id = (int)$student_id;

            $written_marks = isset($data['written']) && $data['written'] !== '' ? (float)$data['written'] : null;
            $oral_marks = isset($data['oral']) && $data['oral'] !== '' ? (float)$data['oral'] : null;

            if ($written_marks === null && $oral_marks === null) continue;

            // Check if record exists
            $checkStmt = $pdo->prepare("
                SELECT id, written_marks, oral_marks, written_locked, oral_locked
                FROM marks
                WHERE student_id = ? AND exam_id = ? AND subject_id = ?
                LIMIT 1
            ");
            $checkStmt->execute([$student_id, $exam_id, $subject_id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['id'] > 0) {
                // Update existing record
                $updateFields = [];
                $updateParams = [];

                if (!$existing['written_locked'] && $written_marks !== null) {
                    $updateFields[] = "written_marks = ?";
                    $updateParams[] = $written_marks;
                }

                if (!$existing['oral_locked'] && $oral_marks !== null) {
                    $updateFields[] = "oral_marks = ?";
                    $updateParams[] = $oral_marks;
                }

                if (!empty($updateFields)) {
                    $final_written = (!$existing['written_locked'] && $written_marks !== null)
                        ? $written_marks : (float)($existing['written_marks'] ?? 0);
                    $final_oral = (!$existing['oral_locked'] && $oral_marks !== null)
                        ? $oral_marks : (float)($existing['oral_marks'] ?? 0);
                    $total = $final_written + $final_oral;

                    $updateFields[] = "marks = ?";
                    $updateFields[] = "is_draft = 1";
                    $updateFields[] = "updated_by = ?";
                    $updateFields[] = "updated_at = NOW()";

                    $updateParams[] = $total;
                    $updateParams[] = $_SESSION['user_id'];
                    $updateParams[] = $existing['id'];

                    $updateSql = "UPDATE marks SET " . implode(", ", $updateFields) . " WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);

                    if ($updateStmt->execute($updateParams) && $updateStmt->rowCount() > 0) {
                        $updated++;
                    }
                }
            } else {
                // Insert new record
                $total = ($written_marks ?? 0) + ($oral_marks ?? 0);

                $insertStmt = $pdo->prepare("
                    INSERT INTO marks
                    (student_id, exam_id, subject_id, written_marks, oral_marks, marks,
                     is_draft, created_by, updated_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
                ");

                if ($insertStmt->execute([$student_id, $exam_id, $subject_id, $written_marks,
                    $oral_marks, $total, $_SESSION['user_id'], $_SESSION['user_id']])) {
                    $updated++;
                }
            }
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => showAlert("Draft saved successfully for {$updated} student(s)!", 'success')
        ];

    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'message' => showAlert('Error saving draft: ' . $e->getMessage(), 'danger')];
    }
}


// ============================================
// SUBMIT WRITTEN MARKS
// ============================================
function submitWrittenMarks() {
    global $pdo;

    $exam_id = (int)$_POST['exam_id'];
    $subject_id = (int)$_POST['subject_id'];
    $marks_data = $_POST['marks'] ?? [];
    $user_id = $_SESSION['user_id'];

    if (empty($marks_data)) {
        return ['success' => false, 'message' => showAlert('No marks data to submit!', 'warning')];
    }

    try {
        $pdo->beginTransaction();

        $sql = "
            INSERT INTO marks (
                student_id, exam_id, subject_id, written_marks, marks,
                written_locked, written_locked_by, written_locked_at, is_draft,
                created_by, updated_by, created_at, updated_at
            ) VALUES (
                :student_id, :exam_id, :subject_id, :written_marks, :written_marks + COALESCE(oral_marks, 0),
                1, :user_id, NOW(), 0,
                :user_id, :user_id, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                written_marks = IF(written_locked = 0, VALUES(written_marks), written_marks),
                marks = IF(written_locked = 0, VALUES(written_marks) + COALESCE(oral_marks, 0), marks),
                written_locked = IF(written_locked = 0, 1, written_locked),
                written_locked_by = IF(written_locked = 0, :user_id_update, written_locked_by),
                written_locked_at = IF(written_locked = 0, NOW(), written_locked_at),
                is_draft = IF(written_locked = 0, 0, is_draft),
                updated_by = :user_id_update_2;
        ";
        $stmt = $pdo->prepare($sql);

        $updated_count = 0;
        foreach ($marks_data as $student_id => $data) {
            if (!isset($data['written']) || $data['written'] === '' || (float)$data['written'] < 0) {
                continue;
            }

            $written_marks = (float)$data['written'];

            $stmt->execute([
                'student_id' => $student_id,
                'exam_id' => $exam_id,
                'subject_id' => $subject_id,
                'written_marks' => $written_marks,
                'user_id' => $user_id,
                'user_id_update' => $user_id,
                'user_id_update_2' => $user_id
            ]);
            
            if ($stmt->rowCount() > 0) {
                $updated_count++;
            }
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => showAlert("Written marks submitted and locked for {$updated_count} student(s)!", 'success')
        ];

    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'message' => showAlert('Error submitting written marks: ' . $e->getMessage(), 'danger')];
    }
}

// ============================================
// SUBMIT ORAL MARKS
// ============================================
function submitOralMarks() {
    global $pdo;

    $exam_id = (int)$_POST['exam_id'];
    $subject_id = (int)$_POST['subject_id'];
    $marks_data = $_POST['marks'] ?? [];
    $user_id = $_SESSION['user_id'];

    if (empty($marks_data)) {
        return ['success' => false, 'message' => showAlert('No marks data to submit!', 'warning')];
    }

    try {
        $pdo->beginTransaction();
        
        $sql = "
            INSERT INTO marks (
                student_id, exam_id, subject_id, oral_marks, marks,
                oral_locked, oral_locked_by, oral_locked_at, is_draft,
                created_by, updated_by, created_at, updated_at
            ) VALUES (
                :student_id, :exam_id, :subject_id, :oral_marks, :oral_marks + COALESCE(written_marks, 0),
                1, :user_id, NOW(), 0,
                :user_id, :user_id, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                oral_marks = IF(oral_locked = 0, VALUES(oral_marks), oral_marks),
                marks = IF(oral_locked = 0, VALUES(oral_marks) + COALESCE(written_marks, 0), marks),
                oral_locked = IF(oral_locked = 0, 1, oral_locked),
                oral_locked_by = IF(oral_locked = 0, :user_id_update, oral_locked_by),
                oral_locked_at = IF(oral_locked = 0, NOW(), oral_locked_at),
                is_draft = IF(oral_locked = 0, 0, is_draft),
                updated_by = :user_id_update_2;
        ";
        $stmt = $pdo->prepare($sql);

        $updated_count = 0;
        foreach ($marks_data as $student_id => $data) {
            if (!isset($data['oral']) || $data['oral'] === '' || (float)$data['oral'] < 0) {
                continue;
            }

            $oral_marks = (float)$data['oral'];

            $stmt->execute([
                'student_id' => $student_id,
                'exam_id' => $exam_id,
                'subject_id' => $subject_id,
                'oral_marks' => $oral_marks,
                'user_id' => $user_id,
                'user_id_update' => $user_id,
                'user_id_update_2' => $user_id
            ]);

            if ($stmt->rowCount() > 0) {
                $updated_count++;
            }
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => showAlert("Oral marks submitted and locked for {$updated_count} student(s)!", 'success')
        ];

    } catch (Exception $e) {
        $pdo->rollback();
        return ['success' => false, 'message' => showAlert('Error submitting oral marks: ' . $e->getMessage(), 'danger')];
    }
}


// ============================================
// UNLOCK WRITTEN MARKS (ADMIN ONLY)
// ============================================
function unlockWrittenMarks() {
    global $pdo;

    $exam_id = (int)$_POST['exam_id'];
    $subject_id = (int)$_POST['subject_id'];
    $student_ids = $_POST['student_ids'] ?? [];

    if (empty($student_ids)) {
        return ['success' => false, 'message' => showAlert('No students selected!', 'warning')];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        
        $sql = "
            UPDATE marks
            SET written_locked = 0,
                written_locked_by = NULL,
                written_locked_at = NULL,
                is_draft = 1,
                updated_by = ?,
                updated_at = NOW()
            WHERE student_id IN ($placeholders) AND exam_id = ? AND subject_id = ?
        ";

        $params = array_merge([$_SESSION['user_id']], $student_ids, [$exam_id, $subject_id]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $updated = $stmt->rowCount();

        return [
            'success' => true,
            'message' => showAlert("Written marks unlocked for {$updated} student(s)!", 'info')
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => showAlert('Error unlocking written marks: ' . $e->getMessage(), 'danger')];
    }
}

// ============================================
// UNLOCK ORAL MARKS (ADMIN ONLY)
// ============================================
function unlockOralMarks() {
    global $pdo;

    $exam_id = (int)$_POST['exam_id'];
    $subject_id = (int)$_POST['subject_id'];
    $student_ids = $_POST['student_ids'] ?? [];

    if (empty($student_ids)) {
        return ['success' => false, 'message' => showAlert('No students selected!', 'warning')];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));

        $sql = "
            UPDATE marks
            SET oral_locked = 0,
                oral_locked_by = NULL,
                oral_locked_at = NULL,
                is_draft = 1,
                updated_by = ?,
                updated_at = NOW()
            WHERE student_id IN ($placeholders) AND exam_id = ? AND subject_id = ?
        ";
        
        $params = array_merge([$_SESSION['user_id']], $student_ids, [$exam_id, $subject_id]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $updated = $stmt->rowCount();

        return [
            'success' => true,
            'message' => showAlert("Oral marks unlocked for {$updated} student(s)!", 'info')
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => showAlert('Error unlocking oral marks: ' . $e->getMessage(), 'danger')];
    }
}


// ============================================
// FORM SUBMISSION HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {

        $exam_id = (int)$_POST['exam_id'];
        $subject_id = (int)$_POST['subject_id'];
        $class_id = (int)$_POST['class_id'];

        $teacher_id = getTeacherId($_SESSION['user_id']);

        if ($_SESSION['role'] === 'teacher') {
            if (!$teacher_id || !checkTeacherAccess($teacher_id, $class_id, $subject_id)) {
                $message = showAlert('Access Denied! You are not assigned to this class and subject.', 'danger');
            } else {
                switch ($_POST['action']) {
                    case 'save_draft':
                        $result = saveMarksDraft();
                        $message = $result['message'];
                        break;
                    case 'submit_written':
                        $result = submitWrittenMarks();
                        $message = $result['message'];
                        break;
                    case 'submit_oral':
                        $result = submitOralMarks();
                        $message = $result['message'];
                        break;
                    default:
                        $message = showAlert('Invalid action!', 'danger');
                }
            }
        } else {
            // Admin/Super Admin actions
            switch ($_POST['action']) {
                case 'save_draft':
                    $result = saveMarksDraft();
                    $message = $result['message'];
                    break;
                case 'submit_written':
                    $result = submitWrittenMarks();
                    $message = $result['message'];
                    break;
                case 'submit_oral':
                    $result = submitOralMarks();
                    $message = $result['message'];
                    break;
                case 'unlock_written':
                    $result = unlockWrittenMarks();
                    $message = $result['message'];
                    break;
                case 'unlock_oral':
                    $result = unlockOralMarks();
                    $message = $result['message'];
                    break;
                default:
                    $message = showAlert('Invalid action!', 'danger');
            }
        }
    }
}

// ============================================
// GET FILTER DATA
// ============================================
$exams = getAllExams();
$classes = getAllClasses();
$subjects = getAllSubjects();

if ($_SESSION['role'] === 'teacher') {
    $teacher_id = getTeacherId($_SESSION['user_id']);
    if ($teacher_id) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.*
            FROM classes c
            INNER JOIN teacher_subjects ts ON c.id = ts.class_id
            WHERE ts.teacher_id = ?
            ORDER BY c.name
        ");
        $stmt->execute([$teacher_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT DISTINCT s.*
            FROM subjects s
            INNER JOIN teacher_subjects ts ON s.id = ts.subject_id
            WHERE ts.teacher_id = ?
            ORDER BY s.name
        ");
        $stmt->execute([$teacher_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ============================================
// LOAD MARKS DATA
// ============================================
$marksData = [];
$accessDenied = false;

if (isset($_GET['exam_id']) && isset($_GET['class_id']) && isset($_GET['subject_id'])) {
    $selectedExam = (int)$_GET['exam_id'];
    $selectedClass = (int)$_GET['class_id'];
    $selectedSubject = (int)$_GET['subject_id'];

    if ($_SESSION['role'] === 'teacher') {
        $teacher_id = getTeacherId($_SESSION['user_id']);
        if (!$teacher_id || !checkTeacherAccess($teacher_id, $selectedClass, $selectedSubject)) {
            $accessDenied = true;
            $message = showAlert('Access Denied! You are not assigned to this class and subject.', 'danger');
        }
    }

    if (!$accessDenied) {
        // 🔧 UPDATED CRITICAL FIX: CAST roll_number to UNSIGNED for correct numerical sorting
        $stmt = $pdo->prepare("
            SELECT
                s.id,
                s.student_id,
                s.name,
                s.roll_number,
                s.class_id,
                MAX(m.written_marks) as written_marks,
                MAX(m.oral_marks) as oral_marks,
                MAX(m.marks) as marks,
                MAX(COALESCE(m.written_locked, 0)) as written_locked,
                MAX(COALESCE(m.oral_locked, 0)) as oral_locked,
                MAX(COALESCE(m.is_draft, 1)) as is_draft,
                COALESCE(csc.written_full_marks, sub.written_full_marks) AS written_full_marks,
                COALESCE(csc.oral_full_marks, sub.oral_full_marks) AS oral_full_marks
            FROM students s
            LEFT JOIN marks m ON s.id = m.student_id
                AND m.exam_id = :exam_id
                AND m.subject_id = :subject_id
            JOIN subjects sub ON sub.id = :subject_id_2
            LEFT JOIN subject_class_full_marks csc ON csc.subject_id = sub.id AND csc.class_id = s.class_id
            WHERE s.class_id = :class_id
            GROUP BY 
                s.id, s.student_id, s.name, s.roll_number, s.class_id, 
                sub.written_full_marks, sub.oral_full_marks, 
                csc.written_full_marks, csc.oral_full_marks
            ORDER BY CAST(s.roll_number AS UNSIGNED) ASC, s.roll_number ASC
        ");
        $stmt->execute([
            ':exam_id' => $selectedExam,
            ':subject_id' => $selectedSubject,
            ':subject_id_2' => $selectedSubject,
            ':class_id' => $selectedClass,
        ]);
        $marksData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Enter Marks</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .marks-table th, .marks-table td {
            text-align: center;
            vertical-align: middle;
        }
        .marks-table td:nth-child(2), .marks-table td:nth-child(3) {
            text-align: left;
        }
        .locked-input {
            background-color: #e9ecef !important;
            cursor: not-allowed;
        }
        .written-locked {
            border-left: 4px solid #dc3545;
        }
        .oral-locked {
            border-right: 4px solid #ffc107;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .table thead th {
            position: sticky;
            top: 0;
            background-color: #343a40;
            z-index: 10;
            cursor: pointer; /* Add cursor pointer for sortable headers */
        }
        /* Add basic sort icon style */
        .sort-icon {
            float: right;
            opacity: 0.3;
        }
        .table thead th:hover .sort-icon {
            opacity: 1;
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
                <span class="nav-link text-white">
                    <i class="fas fa-user me-1"></i><?= $_SESSION['name'] ?> (<?= ucfirst($_SESSION['role']) ?>)
                </span>
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h2><i class="fas fa-edit me-2"></i>Enter Marks</h2>

        <?= $message ?>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Select Exam, Class & Subject</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Exam</label>
                        <select class="form-select" name="exam_id" required>
                            <option value="">Choose Exam</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= $exam['id'] ?>" <?= $selectedExam == $exam['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($exam['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Class</label>
                        <select class="form-select" name="class_id" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $selectedClass == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['name']) ?> - <?= htmlspecialchars($class['section']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Subject</label>
                        <select class="form-select" name="subject_id" required>
                            <option value="">Choose Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $selectedSubject == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-search me-2"></i>Load Students
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Marks Entry Table -->
        <?php if (!empty($marksData) && !$accessDenied): ?>
            <?php 
            $writtenMax = $marksData[0]['written_full_marks'] ?? 0;
            $oralMax = $marksData[0]['oral_full_marks'] ?? 0;
            ?>
            <div class="alert alert-info border-primary">
                <h5 class="mb-0">
                    <i class="fas fa-info-circle me-2"></i>Full Marks for this Class/Subject: 
                    Written: <strong><?= $writtenMax ?></strong> | Oral/Practical: <strong><?= $oralMax ?></strong>
                </h5>
            </div>
            <form method="POST" id="marksForm">
                <input type="hidden" name="exam_id" value="<?= $selectedExam ?>">
                <input type="hidden" name="subject_id" value="<?= $selectedSubject ?>">
                <input type="hidden" name="class_id" value="<?= $selectedClass ?>">

                <div class="card">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Marks Entry</h5>
                        <div class="btn-group" role="group">
                            <button type="submit" name="action" value="save_draft" class="btn btn-light">
                                <i class="fas fa-save me-2"></i>Save as Draft
                            </button>
                            <button type="submit" name="action" value="submit_written" class="btn btn-primary">
                                <i class="fas fa-lock me-2"></i>Submit Written
                            </button>
                            <button type="submit" name="action" value="submit_oral" class="btn btn-warning">
                                <i class="fas fa-microphone me-2"></i>Submit Oral
                            </button>
                            <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-info dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-unlock me-2"></i>Admin Unlock
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="#" onclick="unlockSelected('written'); return false;">
                                            <i class="fas fa-unlock me-2"></i>Unlock Written
                                        </a></li>
                                        <li><a class="dropdown-item" href="#" onclick="unlockSelected('oral'); return false;">
                                            <i class="fas fa-unlock me-2"></i>Unlock Oral
                                        </a></li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover marks-table mb-0" id="marksTable">
                                <thead class="table-dark">
                                    <tr>
                                        <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                                            <th style="width: 50px;">
                                                <input type="checkbox" id="selectAll" class="form-check-input">
                                            </th>
                                        <?php endif; ?>
                                        <!-- Add onclick events for manual client-side sorting if needed -->
                                        <th style="width: 80px;" onclick="sortTable(<?= in_array($_SESSION['role'], ['super_admin', 'admin']) ? 1 : 0 ?>)">
                                            Roll No <i class="fas fa-sort sort-icon"></i>
                                        </th>
                                        <th onclick="sortTable(<?= in_array($_SESSION['role'], ['super_admin', 'admin']) ? 2 : 1 ?>)">
                                            Student Name <i class="fas fa-sort sort-icon"></i>
                                        </th>
                                        <th style="width: 150px;">Written Marks (Max: <?= $writtenMax ?>)</th>
                                        <th style="width: 150px;">Oral Marks (Max: <?= $oralMax ?>)</th>
                                        <th style="width: 100px;">Total</th>
                                        <th style="width: 200px;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($marksData as $student): ?>
                                        <?php
                                        $rowClass = '';
                                        if ($student['written_locked']) $rowClass .= ' written-locked';
                                        if ($student['oral_locked']) $rowClass .= ' oral-locked';
                                        
                                        $written_val = $student['written_marks'];
                                        $oral_val = $student['oral_marks'];
                                        $total_val = ($written_val ?? 0) + ($oral_val ?? 0);
                                        ?>
                                        <tr class="<?= $rowClass ?>">
                                            <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                                                <td>
                                                    <input type="checkbox" name="student_ids[]"
                                                           value="<?= $student['id'] ?>"
                                                           class="student-checkbox form-check-input">
                                                </td>
                                            <?php endif; ?>
                                            <td><?= htmlspecialchars($student['roll_number']) ?></td>
                                            <td class="text-start"><?= htmlspecialchars($student['name']) ?></td>
                                            <td>
                                                <input type="number"
                                                       class="form-control form-control-sm marks-input <?= $student['written_locked'] ? 'locked-input' : '' ?>"
                                                       name="marks[<?= $student['id'] ?>][written]"
                                                       value="<?= ($written_val !== null && $written_val > 0) ? $written_val : '' ?>"
                                                       max="<?= $writtenMax ?>"
                                                       min="0"
                                                       step="0.01"
                                                       data-type="written"
                                                       <?= $student['written_locked'] ? 'readonly' : '' ?>>
                                                <small class="text-muted">Max: <?= $writtenMax ?></small>
                                            </td>
                                            <td>
                                                <input type="number"
                                                       class="form-control form-control-sm marks-input <?= $student['oral_locked'] ? 'locked-input' : '' ?>"
                                                       name="marks[<?= $student['id'] ?>][oral]"
                                                       value="<?= ($oral_val !== null && $oral_val > 0) ? $oral_val : '' ?>"
                                                       max="<?= $oralMax ?>"
                                                       min="0"
                                                       step="0.01"
                                                       data-type="oral"
                                                       <?= $student['oral_locked'] ? 'readonly' : '' ?>>
                                                <small class="text-muted">Max: <?= $oralMax ?></small>
                                            </td>
                                            <td>
                                                <strong class="total-marks"><?= number_format($total_val, 2) ?></strong>
                                            </td>
                                            <td>
                                                <?php if (!$student['written_locked'] && !$student['oral_locked'] && ($written_val !== null || $oral_val !== null)): ?>
                                                    <span class="badge bg-secondary">Draft</span>
                                                <?php endif; ?>

                                                <?php if ($student['written_locked']): ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-lock me-1"></i>Written Locked
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($student['oral_locked']): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-lock me-1"></i>Oral Locked
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($student['written_locked'] && $student['oral_locked']): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i>Complete
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-muted">
                        <div class="row">
                            <div class="col-md-6">
                                <i class="fas fa-info-circle me-2"></i>
                                Total Students: <strong><?= count($marksData) ?></strong>
                            </div>
                            <div class="col-md-6 text-end">
                                <span class="me-3">
                                    <i class="fas fa-save me-1"></i> Draft: Save without locking
                                </span>
                                <span>
                                    <i class="fas fa-lock me-1"></i> Submit: Lock marks (cannot be changed by teachers)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php elseif (empty($marksData) && isset($_GET['exam_id'])): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No students found for the selected class, or you don't have access to this class.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select All Functionality
        document.getElementById('selectAll')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

        // Auto-calculate totals on input
        document.querySelectorAll('.marks-input').forEach(input => {
            input.addEventListener('input', function() {
                const row = this.closest('tr');
                const writtenInput = row.querySelector('input[data-type="written"]');
                const oralInput = row.querySelector('input[data-type="oral"]');
                const totalCell = row.querySelector('.total-marks');

                let written = parseFloat(writtenInput.value) || 0;
                let oral = parseFloat(oralInput.value) || 0;

                // Validate max marks
                const maxWritten = parseFloat(writtenInput.getAttribute('max'));
                const maxOral = parseFloat(oralInput.getAttribute('max'));

                if (written > maxWritten) {
                    writtenInput.value = maxWritten;
                    written = maxWritten;
                    console.warn(`Written marks cannot exceed ${maxWritten}`);
                }

                if (oral > maxOral) {
                    oralInput.value = maxOral;
                    oral = maxOral;
                    console.warn(`Oral marks cannot exceed ${maxOral}`);
                }
                
                const total = written + oral;
                totalCell.textContent = total.toFixed(2);
            });
        });

        // Unlock selected students (Admin only)
        function unlockSelected(type) {
            const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');

            if (checkedBoxes.length === 0) {
                console.error('Please select at least one student to unlock marks.');
                alert('Please select at least one student.'); // Kept alert for simplicity in this context
                return false;
            }

            const typeName = type === 'written' ? 'Written' : 'Oral';

            if (confirm(`Unlock ${typeName} marks for ${checkedBoxes.length} student(s)?\n\nThis will allow teachers to edit the marks again.`)) {
                const form = document.getElementById('marksForm');
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'unlock_' + type;
                form.appendChild(actionInput);
                form.submit();
            }

            return false;
        }

        // Prevent accidental navigation
        let formChanged = false;

        document.querySelectorAll('.marks-input').forEach(input => {
            input.addEventListener('change', function() {
                formChanged = true;
            });
        });

        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
                return e.returnValue;
            }
        });

        // Reset form changed flag on submit
        document.getElementById('marksForm')?.addEventListener('submit', function() {
            formChanged = false;
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + S to save draft
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.querySelector('button[name="action"][value="save_draft"]').click();
            }
        });

        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert-success, .alert-info').forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });

        // Focus first empty input
        window.addEventListener('load', function() {
            const firstEmptyInput = document.querySelector('.marks-input:not([readonly]):not([value=""])');
            if (firstEmptyInput) {
                firstEmptyInput.focus();
                // firstEmptyInput.select(); // Optional: select text on focus
            }
        });

        // CLIENT-SIDE SORTING FUNCTION
        function sortTable(n) {
            var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
            table = document.getElementById("marksTable");
            switching = true;
            // Set the sorting direction to ascending:
            dir = "asc";
            /* Make a loop that will continue until
            no switching has been done: */
            while (switching) {
                // Start by saying: no switching is done:
                switching = false;
                rows = table.rows;
                /* Loop through all table rows (except the first, which contains table headers): */
                for (i = 1; i < (rows.length - 1); i++) {
                    // Start by saying there should be no switching:
                    shouldSwitch = false;
                    /* Get the two elements you want to compare,
                    one from current row and one from the next: */
                    x = rows[i].getElementsByTagName("TD")[n];
                    y = rows[i + 1].getElementsByTagName("TD")[n];
                    
                    // Check if the two rows should switch place,
                    // based on the direction, asc or desc:
                     var xVal = x.innerText.toLowerCase();
                     var yVal = y.innerText.toLowerCase();
                     // Try numerical comparison first if possible
                     if (!isNaN(parseFloat(xVal)) && !isNaN(parseFloat(yVal))) {
                         xVal = parseFloat(xVal);
                         yVal = parseFloat(yVal);
                     }

                    if (dir == "asc") {
                        if (xVal > yVal) {
                            shouldSwitch = true;
                            break;
                        }
                    } else if (dir == "desc") {
                        if (xVal < yVal) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                }
                if (shouldSwitch) {
                    /* If a switch has been marked, make the switch
                    and mark that a switch has been done: */
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    // Each time a switch is done, increase this count by 1:
                    switchcount ++;
                } else {
                    /* If no switching has been done AND the direction is "asc",
                    set the direction to "desc" and run the while loop again. */
                    if (switchcount == 0 && dir == "asc") {
                        dir = "desc";
                        switching = true;
                    }
                }
            }
        }
    </script>
</body>
</html>