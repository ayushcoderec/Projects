<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin']);

$selectedYear = $_GET['year'] ?? '';

// Get summarized batches to avoid duplicate buttons
$archivedBatches = $pdo->query("
    SELECT academic_year, SUM(total_students) as total_students 
    FROM academic_batches 
    WHERE status = 'Archived' 
    GROUP BY academic_year 
    ORDER BY academic_year DESC
")->fetchAll();

$batchData = [];
$totalInTable = 0;

if ($selectedYear) {
    // 1. Check if the table has ANY data at all (for debugging)
    $totalInTable = $pdo->query("SELECT COUNT(*) FROM archived_students")->fetchColumn();

    // 2. Get archived data using a very flexible search
    // We trim the year and check multiple columns to ensure we find the data
    $searchYear = trim($selectedYear);
    $shortYear = str_replace('20', '', $searchYear); // converts 2024-2025 to 24-25

    $stmt = $pdo->prepare("
        SELECT * FROM archived_students 
        WHERE academic_year LIKE ? 
           OR final_academic_year LIKE ? 
           OR academic_year LIKE ?
        ORDER BY id ASC
    ");
    $stmt->execute(["%$searchYear%", "%$searchYear%", "%$shortYear%"]);
    $batchData = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archives - Nightingale</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .year-card { transition: all 0.3s ease; border-radius: 15px; border: 1px solid #dee2e6; }
        .year-card:hover { transform: scale(1.02); box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
        .empty-state { padding: 40px; text-align: center; background: #f8f9fa; border-radius: 15px; border: 2px dashed #dee2e6; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary mb-4">
        <div class="container">
            <span class="navbar-brand"><i class="fas fa-archive me-2"></i>Archive Management</span>
            <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <?php if (!$selectedYear): ?>
            <h4 class="mb-4">Select Academic Year to View</h4>
            <div class="row">
                <?php foreach ($archivedBatches as $batch): ?>
                    <div class="col-md-4 mb-3">
                        <a href="?year=<?= urlencode($batch['academic_year']) ?>" class="text-decoration-none text-dark">
                            <div class="card year-card shadow-sm">
                                <div class="card-body text-center py-4">
                                    <h3 class="text-primary"><?= htmlspecialchars($batch['academic_year']) ?></h3>
                                    <p class="text-muted mb-0"><?= $batch['total_students'] ?> Records Found in Batches</p>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="archive_data.php" class="btn btn-outline-primary"><i class="fas fa-arrow-left me-2"></i>Back</a>
                <h3 class="mb-0">Year: <?= htmlspecialchars($selectedYear) ?></h3>
                <span class="badge bg-dark">Total Archived in DB: <?= $totalInTable ?></span>
            </div>

            <?php if (empty($batchData)): ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                    <h4>Data Not Found in Archive Table</h4>
                    <p class="text-muted">
                        The batch record exists, but the individual student records are missing.<br>
                        <strong>Reason:</strong> The promotion process was likely interrupted by a database error.
                    </p>
                    <hr>
                    <p class="small text-danger">Suggestion: Use the 'Promotion Panel' to run the promotion again. The new script is designed to handle this correctly.</p>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Final Class</th>
                                    <th>Result</th>
                                    <th>Percentage</th>
                                    <th>Archived Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batchData as $row): 
                                    $sInfo = json_decode($row['student_data'], true);
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($sInfo['name'] ?? 'Unknown') ?></strong></td>
                                        <td><?= htmlspecialchars($row['final_class']) ?></td>
                                        <td><span class="badge bg-success"><?= $row['final_result'] ?></span></td>
                                        <td><?= number_format($row['overall_percentage'], 2) ?>%</td>
                                        <td class="text-muted"><?= $row['archive_date'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>