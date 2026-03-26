<?php
require_once 'config.php';
require_once 'functions.php';

checkRole(['super_admin']);

// Get unique graduation years for the filter dropdown
$yearsStmt = $pdo->query("SELECT DISTINCT graduation_year FROM alumni ORDER BY graduation_year DESC");
$graduationYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

$search = $_GET['search'] ?? '';
$filterYear = $_GET['year'] ?? '';

// Build the query
$query = "SELECT * FROM alumni WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (name LIKE ? OR student_id LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filterYear) {
    $query .= " AND graduation_year = ?";
    $params[] = $filterYear;
}

$query .= " ORDER BY graduation_year DESC, name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$alumni = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Alumni Database</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .alumni-card {
            border-radius: 15px;
            overflow: hidden;
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .search-section {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .table-responsive {
            background: #fff;
            border-radius: 10px;
            padding: 10px;
        }
        .badge-year {
            background-color: #e9ecef;
            color: #495057;
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="promotion_management.php">Promotion Panel</a>
                <a class="nav-link" href="archive_data.php">Archives</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-user-graduate me-2 text-primary"></i>Alumni Database</h2>
            <div class="text-muted">Total Records: <?= count($alumni) ?></div>
        </div>

        <!-- Filters -->
        <div class="search-section">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" 
                               placeholder="Search by name, ID or email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="year" class="form-select">
                        <option value="">All Graduation Years</option>
                        <?php foreach ($graduationYears as $year): ?>
                            <option value="<?= $year ?>" <?= $filterYear == $year ? 'selected' : '' ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary px-4">Apply Filters</button>
                    <a href="alumni_database.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="table-responsive shadow-sm">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Graduation Year</th>
                        <th>Performance</th>
                        <th>Contact</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alumni)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-user-slash fa-3x mb-3 d-block"></i>
                                No alumni records found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alumni as $row): ?>
                            <tr>
                                <td><code class="fw-bold"><?= htmlspecialchars($row['student_id']) ?></code></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($row['current_occupation'] ?? 'Occupation not set') ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-year p-2"><?= htmlspecialchars($row['graduation_year']) ?></span>
                                </td>
                                <td>
                                    <div class="small">Score: <strong><?= number_format($row['final_percentage'], 2) ?>%</strong></div>
                                    <div class="small text-primary">Grade: <?= htmlspecialchars($row['final_grade'] ?: 'N/A') ?></div>
                                </td>
                                <td>
                                    <div class="small"><i class="fas fa-envelope me-1 text-muted"></i> <?= htmlspecialchars($row['email'] ?: 'No Email') ?></div>
                                    <div class="small"><i class="fas fa-phone me-1 text-muted"></i> <?= htmlspecialchars($row['phone'] ?: 'No Phone') ?></div>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary" title="View Profile" 
                                                onclick="alert('Profile details for <?= addslashes($row['name']) ?> would open here.')">
                                            <i class="fas fa-user"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-success" title="Edit Info"
                                                onclick="alert('Edit functionality for occupation/contact info.')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mt-4 p-3 bg-white rounded border small text-muted">
            <i class="fas fa-info-circle me-1 text-primary"></i> 
            Note: Records are added to this database automatically when students graduate from the highest class level (Class 12).
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>