<?php
require_once 'config.php';
require_once 'functions.php';

// Basic security check - ensure user is logged in with appropriate role
checkRole(['super_admin', 'admin']);

// Ensure it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

$teacher_id = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);

if (!$teacher_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid teacher ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT ts.id, ts.teacher_id, ts.subject_id, ts.class_id,
               s.name as subject_name, s.code, c.name as class_name, c.section
        FROM teacher_subjects ts
        JOIN subjects s ON ts.subject_id = s.id
        JOIN classes c ON ts.class_id = c.id
        WHERE ts.teacher_id = ?
        ORDER BY c.name, s.name
    ");
    $stmt->execute([$teacher_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($assignments);

} catch (PDOException $e) {
    http_response_code(500);
    // Log the actual error for admin, send generic error to client
    error_log("Database error in get_teacher_assignments_detailed.php: " . $e->getMessage());
    echo json_encode(['error' => 'Database error fetching assignments']);
}
?>