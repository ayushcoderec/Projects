<?php

require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin']);

$message = '';
// Your database format: 2024-2025
$currentAcademicYear = '2024-2025'; 
$nextAcademicYear = '2025-2026';

// Handle year-end promotion
$promotionDetails = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['start_promotion'])) {
    if (isset($_POST['confirm_promotion']) && $_POST['confirm_promotion'] == '1') {
        $promotionResult = executeYearEndPromotion($currentAcademicYear, $nextAcademicYear);
        $message = $promotionResult['message'];
        $promotionDetails = $promotionResult['details'] ?? [];
    } else {
        $message = showAlert('Please confirm the promotion by checking the box!', 'warning');
    }
}

function executeYearEndPromotion($currentYear, $nextYear) {
    global $pdo;
    $details = [];
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stats = ['total' => 0, 'promoted' => 0, 'retained' => 0, 'alumni' => 0];

        // Step 1: Create Batch record
        $stmt = $pdo->prepare("INSERT INTO academic_batches (academic_year, start_date, end_date, status) VALUES (?, ?, ?, 'Processing') ON DUPLICATE KEY UPDATE status='Processing'");
        $stmt->execute([$currentYear, date('Y-04-01'), date('Y-03-31')]);

        // Step 2: Get Students
        $students = getStudentsForPromotion();
        $stats['total'] = count($students);

        foreach ($students as $student) {
            $decision = determinePromotionStatus($student);
            
            // Archive snapshot of current data before making changes
            $stmtArchive = $pdo->prepare("
                INSERT INTO archived_students (
                    original_student_id, academic_year, student_data, archive_reason, 
                    archive_date, final_class, final_academic_year, final_result, 
                    overall_percentage, archived_by
                ) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)
            ");
            
            $stmtArchive->execute([
                $student['id'],
                $currentYear,
                json_encode($student),
                ($decision['status'] == 'Alumni' ? 'graduated' : 'other'),
                $student['class_name'],
                $currentYear,
                $decision['status'],
                $student['avg_percentage'] ?? 0,
                $_SESSION['user_id'] ?? 1
            ]);

            $details[] = [
                'name' => $student['name'],
                'class' => $student['class_name'],
                'status' => $decision['status'],
                'reason' => $decision['reason']
            ];

            if ($decision['status'] === 'Promoted') {
                // FIX: Update BOTH class_id AND session_year
                $stmt = $pdo->prepare("UPDATE students SET class_id = ?, session_year = ? WHERE id = ?");
                $stmt->execute([$decision['new_class_id'], $nextYear, $student['id']]);
                $stats['promoted']++;
            } elseif ($decision['status'] === 'Alumni') {
                // Move to alumni
                $stmt = $pdo->prepare("INSERT INTO alumni (student_id, name, graduation_year, final_percentage) VALUES (?, ?, ?, ?)");
                $stmt->execute([$student['student_id'], $student['name'], $currentYear, $student['avg_percentage']]);
                
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$student['id']]);
                $stats['alumni']++;
            } else {
                // RETAINED: Update session_year even if the class stays the same
                $stmt = $pdo->prepare("UPDATE students SET session_year = ? WHERE id = ?");
                $stmt->execute([$nextYear, $student['id']]);
                $stats['retained']++;
            }
            
            // Log History
            $stmt = $pdo->prepare("INSERT INTO promotion_history (academic_year, student_id, from_class_id, to_class_id, promotion_type, overall_percentage, decision_reason, promoted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$currentYear, $student['id'], $student['class_id'], $decision['new_class_id'] ?? null, $decision['status'], $student['avg_percentage'], $decision['reason'], $_SESSION['user_id'] ?? 1]);
        }

        // Finalize Batch Record
        $stmt = $pdo->prepare("UPDATE academic_batches SET status = 'Archived', total_students = ?, promoted_students = ?, graduated_students = ?, retained_students = ? WHERE academic_year = ? AND status = 'Processing'");
        $stmt->execute([$stats['total'], $stats['promoted'], $stats['alumni'], $stats['retained'], $currentYear]);

        $pdo->commit();
        
        return [
            'success' => true, 
            'message' => showAlert("Promotion Successful! Session updated to $nextYear for all active students.", 'success'),
            'details' => $details
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollback();
        return ['success' => false, 'message' => showAlert("Error: " . $e->getMessage(), 'danger')];
    }
}

function getStudentsForPromotion() {
    global $pdo;
    return $pdo->query("
        SELECT s.*, c.name as class_name,
               COALESCE((SELECT AVG((m.marks/(sub.written_full_marks+sub.oral_full_marks))*100) FROM marks m JOIN subjects sub ON m.subject_id=sub.id WHERE m.student_id=s.id), 0) as avg_percentage
        FROM students s
        JOIN classes c ON s.class_id = c.id
        GROUP BY s.id
    ")->fetchAll();
}

function determinePromotionStatus($student) {
    global $pdo;
    $className = trim($student['class_name']);
    $map = ['Nursery' => 'Lower', 'Lower' => 'Upper', 'Upper' => 'Class 1'];

    if (isset($map[$className])) {
        $next = $map[$className];
    } else {
        preg_match('/(\d+)/', $className, $m);
        $num = isset($m[1]) ? (int)$m[1] : 0;
        if ($num >= 12) return ['status' => 'Alumni', 'reason' => 'Graduated', 'new_class_id' => null];
        $next = "Class " . ($num + 1);
    }

    $stmt = $pdo->prepare("SELECT id FROM classes WHERE name LIKE ? LIMIT 1");
    $stmt->execute(["%$next%"]);
    $row = $stmt->fetch();

    if ($row) {
        return ['status' => 'Promoted', 'reason' => "Promoted to $next", 'new_class_id' => $row['id']];
    }

    return ['status' => 'Retained', 'reason' => "Target class '$next' not found", 'new_class_id' => $student['class_id']];
}

$currentStats = $pdo->query("SELECT COUNT(DISTINCT id) as total FROM students")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mass Student Promotion</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
      <nav class="navbar navbar-dark bg-primary mb-4">
        <div class="container">
            <span class="navbar-brand"><i class="fas fa-archive me-2"></i>Academic Promotion</span>
            <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
             <a href="archive_data.php" class="btn btn-light btn-sm">Archived Data</a>
            <a href="alumini.php" class="btn btn-light btn-sm">ALumini Database</a>
        </div>
    </nav>
    <div class="container">
        <?= $message ?>
        <div class="card shadow">
            <div class="card-header bg-success text-white"><h4>Update Session & Promote Students</h4></div>
            <div class="card-body text-center">
                <p>Current Session: <strong><?= $currentAcademicYear ?></strong></p>
                <p>New Session: <strong><?= $nextAcademicYear ?></strong></p>
                <form method="POST">
                    <input type="checkbox" name="confirm_promotion" value="1" required> I understand that students will be moved to the <strong><?= $nextAcademicYear ?></strong> session.<br><br>
                    <button type="submit" name="start_promotion" class="btn btn-success btn-lg">EXECUTE PROMOTION & UPDATE SESSION</button>
                </form>
            </div>
        </div>
        
        <?php if (!empty($promotionDetails)): ?>
        <div class="mt-4">
            <h5>Execution Report</h5>
            <table class="table table-bordered bg-white small">
                <thead><tr><th>Name</th><th>Original Class</th><th>Result</th><th>Note</th></tr></thead>
                <tbody>
                    <?php foreach ($promotionDetails as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td><?= htmlspecialchars($d['class']) ?></td>
                        <td><span class="badge bg-<?= $d['status'] == 'Promoted' ? 'success' : 'danger' ?>"><?= $d['status'] ?></span></td>
                        <td><?= htmlspecialchars($d['reason']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>