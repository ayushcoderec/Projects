<?php
require_once 'config.php';
require_once 'functions.php';

checkLogin();

$user = getUserDetails($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            border-radius: 8px;
            margin: 2px 0;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white !important;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <div class="text-center text-white mb-4">
                    <i class="fas fa-graduation-cap fa-2x mb-2"></i>
                    <h5>School System</h5>
                    <small><?= ucwords(str_replace('_', ' ', $_SESSION['role'])) ?></small>
                </div>

                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                    </li>

                    <?php if ($_SESSION['role'] == 'super_admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_users.php">
                                <i class="fas fa-users me-2"></i>Manage Users
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_classes.php">
                                <i class="fas fa-chalkboard me-2"></i>Manage Classes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_subjects.php">
                                <i class="fas fa-book me-2"></i>Manage Subjects
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_students.php">
                                <i class="fas fa-user-graduate me-2"></i>Manage Students
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_teachers.php">
                                <i class="fas fa-chalkboard-teacher me-2"></i>Manage Teachers
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="teacher_performance.php">
                                <i class="fas fa-chalkboard-teacher me-2"></i>Teacher Performance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_exams.php">
                                <i class="fas fa-clipboard-list me-2"></i>Manage Exams
                            </a>
                        </li>
                         <li class="nav-item">
                            <a class="nav-link" href="ctp.php">
                                <i class="fas fa-clipboard-list me-2"></i>Class Teachers
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php if (in_array($_SESSION['role'], ['super_admin'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="ap.php">
                                <i class="fas fa-chalkboard me-2"></i>Academic Promotion
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="archive_data.php">
                                <i class="fas fa-chalkboard me-2"></i>Archive Data
                            </a>
                        </li>
                             <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link" href="enter_marks.php">
                            <i class="fas fa-edit me-2"></i>Enter Marks
                        </a>
                        <a class="nav-link" href="attendance_system.php">
    <i class="fas fa-calendar-check me-2"></i>Attendance
</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="view_results.php">
                            <i class="fas fa-chart-bar me-2"></i>View Results
                        </a>
                    </li>

                    <hr class="text-white">
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Welcome, <?= $_SESSION['name'] ?>!</h2>
                    <div class="text-muted">
                        <i class="fas fa-calendar me-2"></i><?= date('d M Y') ?>
                    </div>
                </div>

                <!-- Dashboard Cards -->
                <div class="row">
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                        <!-- Admin Dashboard -->
                        <div class="col-md-3 mb-4">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Total Students</h6>
                                            <?php
                                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM students");
                                            $count = $stmt->fetch()['count'];
                                            ?>
                                            <h3><?= $count ?></h3>
                                        </div>
                                        <i class="fas fa-user-graduate fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3 mb-4">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Total Teachers</h6>
                                            <?php
                                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM teachers");
                                            $count = $stmt->fetch()['count'];
                                            ?>
                                            <h3><?= $count ?></h3>
                                        </div>
                                        <i class="fas fa-chalkboard-teacher fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3 mb-4">
                            <div class="card bg-info text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Total Classes</h6>
                                            <?php
                                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM classes");
                                            $count = $stmt->fetch()['count'];
                                            ?>
                                            <h3><?= $count ?></h3>
                                        </div>
                                        <i class="fas fa-chalkboard fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3 mb-4">
                            <div class="card bg-warning text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Total Subjects</h6>
                                            <?php
                                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM subjects");
                                            $count = $stmt->fetch()['count'];
                                            ?>
                                            <h3><?= $count ?></h3>
                                        </div>
                                        <i class="fas fa-book fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Teacher Dashboard -->
                        <div class="col-md-4 mb-4">
                            <div class="card bg-primary text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Assigned Subjects</h6>
                                            <?php
                                            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT subject_id) as count FROM teacher_subjects ts JOIN teachers t ON ts.teacher_id = t.id WHERE t.user_id = ?");
                                            $stmt->execute([$_SESSION['user_id']]);
                                            $count = $stmt->fetch()['count'];
                                            ?>
                                            <h3><?= $count ?></h3>
                                        </div>
                                        <i class="fas fa-book fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4 mb-4">
                            <div class="card bg-success text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Assigned Classes</h6>
                                            <?php
                                            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT class_id) as count FROM teacher_subjects ts JOIN teachers t ON ts.teacher_id = t.id WHERE t.user_id = ?");
                                            $stmt->execute([$_SESSION['user_id']]);
                                            $count = $stmt->fetch()['count'];
                                            ?>
                                            <h3><?= $count ?></h3>
                                        </div>
                                        <i class="fas fa-chalkboard fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4 mb-4">
                            <div class="card bg-info text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Marks Entered</h6>
                                            <?php
                                            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM marks WHERE entered_by = ?");
                                            $stmt->execute([$_SESSION['user_id']]);
                                            $count = $stmt->fetch()['count'];
                                            ?>
                                            <h3><?= $count ?></h3>
                                        </div>
                                        <i class="fas fa-edit fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Activity -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-clock me-2"></i>Recent Activity
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php
                                $stmt = $pdo->prepare("SELECT al.*, u.name FROM audit_logs al JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 10");
                                $stmt->execute();
                                $activities = $stmt->fetchAll();
                                ?>

                                <?php if (empty($activities)): ?>
                                    <p class="text-muted">No recent activity found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Action</th>
                                                    <th>Table</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($activities as $activity): ?>
                                                    <tr>
                                                        <td><?= $activity['name'] ?></td>
                                                        <td><span class="badge bg-primary"><?= $activity['action'] ?></span></td>
                                                        <td><?= $activity['table_name'] ?></td>
                                                        <td><?= formatDate($activity['created_at']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
