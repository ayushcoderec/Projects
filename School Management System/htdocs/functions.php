<?php
require_once 'config.php';

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

// Log audit trail
function logAudit($user_id, $action, $table_name, $record_id, $old_values = '', $new_values = '') {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $table_name, $record_id, $old_values, $new_values]);
}

// Calculate grade based on percentage
function calculateGrade($percentage) {
    if ($percentage >= 90) return ['grade' => 'A+', 'grade_point' => 4.0];
    if ($percentage >= 80) return ['grade' => 'A', 'grade_point' => 3.7];
    if ($percentage >= 70) return ['grade' => 'B+', 'grade_point' => 3.3];
    if ($percentage >= 60) return ['grade' => 'B', 'grade_point' => 3.0];
    if ($percentage >= 50) return ['grade' => 'C+', 'grade_point' => 2.7];
    if ($percentage >= 40) return ['grade' => 'C', 'grade_point' => 2.3];
    if ($percentage >= 35) return ['grade' => 'D', 'grade_point' => 2.0];
    return ['grade' => 'F', 'grade_point' => 0.0];
}

// Calculate grade including oral marks
function calculateGradeWithOral($written_marks, $oral_marks, $subject_id) {
    global $pdo;
    $total_marks = $written_marks + $oral_marks;
    
    // Get subject's total full marks
    $stmt = $pdo->prepare("SELECT (written_full_marks + oral_full_marks) as total_full_marks FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    $subject = $stmt->fetch();
    $total_full_marks = $subject['total_full_marks'] ?? 100;
    
    $percentage = ($total_marks / $total_full_marks) * 100;
    
    return calculateGrade($percentage);
}

// Calculate overall grade (alias for calculateGrade)
function calculateOverallGrade($percentage) {
    return calculateGrade($percentage);
}

// Get grade CSS class
function getGradeClass($grade) {
    switch ($grade) {
        case 'A+':
        case 'A':
            return 'grade-A';
        case 'B+':
        case 'B':
            return 'grade-B';
        case 'C+':
        case 'C':
            return 'grade-C';
        case 'D':
            return 'grade-D';
        default:
            return 'grade-F';
    }
}

// Generate performance remarks
function generateRemarks($percentage, $passedSubjects, $totalSubjects) {
    if ($percentage >= 90) {
        return "Excellent performance! The student has demonstrated outstanding academic achievement and shows exceptional understanding of all subjects. Continue with the same dedication and enthusiasm.";
    } elseif ($percentage >= 80) {
        return "Very good performance! The student shows strong academic abilities and consistent effort across subjects. With continued focus, even better results can be achieved.";
    } elseif ($percentage >= 70) {
        return "Good performance overall. The student demonstrates satisfactory understanding of most concepts. Focus on weaker areas and practice regularly to improve further.";
    } elseif ($percentage >= 50) {
        return "Average performance. The student needs to put in more effort and focus on understanding fundamental concepts. Regular practice and seeking help when needed is recommended.";
    } elseif ($percentage >= 35) {
        return "Below average performance. The student needs significant improvement in study habits and concept understanding. Additional support and guidance are strongly recommended.";
    } else {
        return "Unsatisfactory performance. Immediate attention and intervention required. The student should seek additional academic support and develop better study strategies.";
    }
}

// Check teacher assignment for specific class-subject combination
function checkTeacherAssignment($user_id, $class_id, $subject_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM teacher_subjects ts 
        JOIN teachers t ON ts.teacher_id = t.id 
        WHERE t.user_id = ? AND ts.subject_id = ? AND ts.class_id = ?
    ");
    $stmt->execute([$user_id, $subject_id, $class_id]);
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

// Get teacher's assigned subject-class combinations
function getTeacherAssignedCombinations($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT ts.subject_id, ts.class_id, s.name as subject_name, c.name as class_name 
        FROM teacher_subjects ts 
        JOIN teachers t ON ts.teacher_id = t.id 
        JOIN subjects s ON ts.subject_id = s.id 
        JOIN classes c ON ts.class_id = c.id 
        WHERE t.user_id = ?
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Get user details
function getUserDetails($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

// Get all classes - FIXED SORTING HERE
function getAllClasses() {
    global $pdo;
    // Sort by extracting the first number from the class name
    $stmt = $pdo->query("
        SELECT * FROM classes 
        ORDER BY CAST(REGEXP_REPLACE(name, '[^0-9]', '') AS UNSIGNED) ASC, name ASC
    ");
    return $stmt->fetchAll();
}

// Get all subjects
function getAllSubjects() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM subjects ORDER BY name");
    return $stmt->fetchAll();
}

// Get all exams
function getAllExams() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM exams ORDER BY start_date DESC");
    return $stmt->fetchAll();
}

// Get teacher assigned subjects
function getTeacherSubjects($teacher_id) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT ts.*, s.name as subject_name, s.code, c.name as class_name 
        FROM teacher_subjects ts 
        JOIN subjects s ON ts.subject_id = s.id 
        JOIN classes c ON ts.class_id = c.id 
        WHERE ts.teacher_id = ?
        ORDER BY CAST(REGEXP_REPLACE(c.name, '[^0-9]', '') AS UNSIGNED) ASC, c.name ASC, s.name ASC
    ");
    $stmt->execute([$teacher_id]);
    return $stmt->fetchAll();
}

// Check if marks are locked
function areMarksLocked($student_id, $subject_id, $exam_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT is_locked FROM marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");
    $stmt->execute([$student_id, $subject_id, $exam_id]);
    $result = $stmt->fetch();
    return $result ? $result['is_locked'] : false;
}

// Format date
function formatDate($date) {
    return date('d M Y', strtotime($date));
}

// Show alert message
function showAlert($message, $type = 'success') {
    return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                {$message}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
}

/**
 * Fetches a single record from a table by its ID.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $table The name of the table to query.
 * @param int $id The ID of the record to fetch.
 * @return array|false The associative array of the record, or false if not found.
 */
function getSingleRecord($pdo, $table, $id) {
    // Sanitize the table name (basic protection)
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Log or handle the error appropriately
        // For debugging, you can uncomment the line below:
        // echo "Error: " . $e->getMessage();
        return false;
    }
}
?>