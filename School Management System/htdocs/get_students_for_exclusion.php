<?php
require_once 'config.php';
require_once 'functions.php';

// Add error logging
error_log("get_students_for_exclusion.php called with params: " . print_r($_GET, true));

checkRole(['super_admin', 'admin']);

if (isset($_GET['teacher_id']) && isset($_GET['exam_id']) && isset($_GET['subject_id'])) {
    $teacher_id = (int)$_GET['teacher_id'];
    $exam_id = (int)$_GET['exam_id'];
    $subject_id = (int)$_GET['subject_id'];
    
    try {
        // Get class for this teacher-subject combination
        $stmt = $pdo->prepare("SELECT class_id FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ?");
        $stmt->execute([$teacher_id, $subject_id]);
        $assignment = $stmt->fetch();
        
        if ($assignment) {
            $stmt = $pdo->prepare("
                SELECT 
                    s.id,
                    s.name,
                    s.roll_number,
                    s.student_id as reg_id,
                    m.marks,
                    m.written_marks,
                    m.oral_marks,
                    COALESCE(tpe.is_active, FALSE) as is_excluded,
                    tpe.reason as exclusion_reason
                FROM students s
                LEFT JOIN marks m ON s.id = m.student_id AND m.subject_id = ? AND m.exam_id = ?
                LEFT JOIN teacher_performance_exclusions tpe ON s.id = tpe.student_id 
                    AND tpe.teacher_id = ? AND tpe.subject_id = ? AND tpe.exam_id = ? AND tpe.is_active = TRUE
                WHERE s.class_id = ?
                ORDER BY s.roll_number
            ");
            $stmt->execute([$subject_id, $exam_id, $teacher_id, $subject_id, $exam_id, $assignment['class_id']]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert boolean values for JSON
            foreach ($students as &$student) {
                $student['is_excluded'] = (bool)$student['is_excluded'];
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'students' => $students,
                'debug' => [
                    'teacher_id' => $teacher_id,
                    'exam_id' => $exam_id,
                    'subject_id' => $subject_id,
                    'class_id' => $assignment['class_id'],
                    'student_count' => count($students)
                ]
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'No assignment found for teacher-subject combination',
                'students' => []
            ]);
        }
    } catch (Exception $e) {
        error_log("Error in get_students_for_exclusion.php: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'students' => []
        ]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameters',
        'students' => []
    ]);
}
?>
