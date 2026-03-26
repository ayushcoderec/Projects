<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once 'functions.php';

checkLogin();
// Allow Admins, Super Admins (Teachers might view their class report later if needed)
if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    // Teachers could potentially view their class report, but let's restrict for now
    // Add 'teacher' to the array above and add class checks if needed later
    header("Location: dashboard.php");
    exit;
}

$message = '';
$filterType = ''; // 'event' or 'class'
$filterId = '';
$reportTitle = 'Miscellaneous Attendance Reports';
$reportData = [];
$summaryStats = [
    'total_records' => 0,
    'present' => 0,
    'absent' => 0,
    'excused' => 0,
    'percentage' => 0
];
$filterName = '';

// Handle GET parameters
if (isset($_GET['event_id'])) {
    $filterType = 'event';
    $filterId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT); // Validate input
    if ($filterId) {
        $stmt = $pdo->prepare("SELECT name FROM attendance_events WHERE id = ?");
        $stmt->execute([$filterId]);
        $eventInfo = $stmt->fetch();
        if ($eventInfo) {
            $filterName = $eventInfo['name'];
            $reportTitle = "Report for Event: " . htmlspecialchars($filterName);
        } else {
             $message = showAlert('Invalid Event ID specified.', 'danger');
             $filterId = ''; // Reset invalid ID
             $filterType = '';
        }
    } else {
         $message = showAlert('Invalid Event ID format.', 'danger');
         $filterType = '';
    }


} elseif (isset($_GET['class_id'])) {
    $filterType = 'class';
    $filterId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT); // Validate input
     if ($filterId) {
        $stmt = $pdo->prepare("SELECT name, section FROM classes WHERE id = ?");
        $stmt->execute([$filterId]);
        $classInfo = $stmt->fetch();
         if ($classInfo) {
            $filterName = $classInfo['name'] . ($classInfo['section'] ? ' - ' . $classInfo['section'] : '');
            $reportTitle = "Report for Class: " . htmlspecialchars($filterName);
        } else {
             $message = showAlert('Invalid Class ID specified.', 'danger');
             $filterId = ''; // Reset invalid ID
             $filterType = '';
        }
    } else {
        $message = showAlert('Invalid Class ID format.', 'danger');
        $filterType = '';
    }
}

// Fetch data based on filter
if ($filterType && $filterId) {
    try {
        if ($filterType == 'event') {
            // Group by Date and Class for a specific Event
            $stmt = $pdo->prepare("
                SELECT
                    ma.attendance_date,
                    c.id as class_id,
                    c.name as class_name,
                    c.section,
                    COUNT(ma.id) as total_records, /* Count attendance records */
                    SUM(CASE WHEN ma.status = 'Yes' THEN 1 ELSE 0 END) as present_count, /* Changed from Present */
                    SUM(CASE WHEN ma.status = 'No' THEN 1 ELSE 0 END) as absent_count, /* Changed from Absent */
                    SUM(CASE WHEN ma.status = 'Excused' THEN 1 ELSE 0 END) as excused_count
                FROM miscellaneous_attendance ma
                JOIN classes c ON ma.class_id = c.id
                WHERE ma.event_id = ?
                GROUP BY ma.attendance_date, ma.class_id
                ORDER BY ma.attendance_date DESC, c.name, c.section
            ");
             $stmt->execute([$filterId]);
             $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
             $reportTitle .= " (Grouped by Date & Class)";

        } elseif ($filterType == 'class') {
             // Group by Date and Event for a specific Class
            $stmt = $pdo->prepare("
                SELECT
                    ma.attendance_date,
                    ae.id as event_id,
                    ae.name as event_name,
                    COUNT(ma.id) as total_records, /* Count attendance records */
                    SUM(CASE WHEN ma.status = 'Yes' THEN 1 ELSE 0 END) as present_count, /* Changed from Present */
                    SUM(CASE WHEN ma.status = 'No' THEN 1 ELSE 0 END) as absent_count, /* Changed from Absent */
                    SUM(CASE WHEN ma.status = 'Excused' THEN 1 ELSE 0 END) as excused_count
                FROM miscellaneous_attendance ma
                JOIN attendance_events ae ON ma.event_id = ae.id
                WHERE ma.class_id = ?
                GROUP BY ma.attendance_date, ma.event_id
                ORDER BY ma.attendance_date DESC, ae.name
            ");
             $stmt->execute([$filterId]);
             $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
             $reportTitle .= " (Grouped by Date & Event)";
        }

        // Calculate overall summary stats for the filtered data
        $total_present_for_percentage = 0; // Use only Present for percentage calculation
        foreach($reportData as $row) {
            $summaryStats['total_records'] += $row['total_records']; // Count actual attendance entries
            $summaryStats['present'] += $row['present_count'];
            $summaryStats['absent'] += $row['absent_count'];
            $summaryStats['excused'] += $row['excused_count'];
            $total_present_for_percentage += $row['present_count']; // Sum only 'Yes'
        }
        // Calculate percentage based on total records (present / (present + absent + excused))
        $denominator = $summaryStats['present'] + $summaryStats['absent'] + $summaryStats['excused'];
        if ($denominator > 0) {
             $summaryStats['percentage'] = ($summaryStats['present'] / $denominator) * 100;
        }


    } catch (PDOException $e) {
        $message = showAlert('Error fetching report data: ' . $e->getMessage(), 'danger');
        $reportData = [];
    }
}

// Get lists for filter dropdowns
try {
    // *** THIS IS THE CORRECTED LINE ***
    $allEvents = $pdo->query("SELECT id, name FROM attendance_events WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $allClasses = $pdo->query("SELECT id, name, section FROM classes ORDER BY name, section")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
     $message .= showAlert('Error fetching filter options: ' . $e->getMessage(), 'danger');
     $allEvents = [];
     $allClasses = [];
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Misc Attendance Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .filter-section { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .summary-card { background-color: #fff; border-radius: 10px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 4px solid #0d6efd; }
         .summary-card h6 { color: #6c757d; }
         .summary-card .display-6 { font-weight: 500; }
         #reportTable th { background-color: #e9ecef; }
         #reportTable_filter input { margin-left: 0.5em; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
         <div class="container">
             <a class="navbar-brand" href="dashboard.php"><i class="fas fa-graduation-cap me-2"></i><?= APP_NAME ?></a>
             <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavAltMarkup">
               <span class="navbar-toggler-icon"></span>
             </button>
             <div class="collapse navbar-collapse" id="navbarNavAltMarkup">
               <div class="navbar-nav ms-auto">
                   <a class="nav-link" href="dashboard.php">Dashboard</a>
                   <a class="nav-link" href="attendance.php">Daily Attendance</a>
                   <a class="nav-link" href="miscellaneous_attendance.php">Misc Attendance</a>
                   <a class="nav-link active" href="misc_attendance_reports.php">Misc Reports</a>
                   <a class="nav-link" href="attendance_reports.php">Daily Reports</a>
                   <a class="nav-link" href="logout.php">Logout</a>
               </div>
             </div>
         </div>
     </nav>

     <div class="container-fluid mt-4">
          <h2><i class="fas fa-chart-bar me-2"></i><?= $reportTitle ?></h2>
          <?= $message ?>

          <!-- Filter Section -->
          <div class="filter-section">
               <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-5">
                         <label class="form-label">Report Type</label>
                         <div>
                              <div class="form-check form-check-inline">
                                   <input class="form-check-input" type="radio" name="report_type" id="report_event" value="event" <?= $filterType == 'event' ? 'checked' : '' ?> onclick="toggleFilterInput('event')">
                                   <label class="form-check-label" for="report_event">By Event</label>
                              </div>
                              <div class="form-check form-check-inline">
                                   <input class="form-check-input" type="radio" name="report_type" id="report_class" value="class" <?= $filterType == 'class' ? 'checked' : '' ?> onclick="toggleFilterInput('class')">
                                   <label class="form-check-label" for="report_class">By Class</label>
                              </div>
                         </div>
                    </div>
                     <div class="col-md-5">
                         <div id="event_filter_div" style="display: <?= $filterType == 'event' ? 'block' : 'none' ?>;">
                              <label class="form-label">Select Event</label>
                              <select class="form-select" name="event_id">
                                   <option value="">-- Select Event --</option>
                                   <?php foreach ($allEvents as $event): ?>
                                       <option value="<?= $event['id'] ?>" <?= ($filterType == 'event' && $filterId == $event['id']) ? 'selected' : '' ?>>
                                           <?= htmlspecialchars($event['name']) ?>
                                       </option>
                                   <?php endforeach; ?>
                              </select>
                         </div>
                         <div id="class_filter_div" style="display: <?= $filterType == 'class' ? 'block' : 'none' ?>;">
                              <label class="form-label">Select Class</label>
                              <select class="form-select" name="class_id">
                                   <option value="">-- Select Class --</option>
                                   <?php foreach ($allClasses as $class): ?>
                                       <option value="<?= $class['id'] ?>" <?= ($filterType == 'class' && $filterId == $class['id']) ? 'selected' : '' ?>>
                                           <?= htmlspecialchars($class['name']) ?> - <?= htmlspecialchars($class['section']) ?>
                                       </option>
                                   <?php endforeach; ?>
                              </select>
                         </div>
                    </div>
                    <div class="col-md-2">
                         <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Generate Report</button>
                    </div>
               </form>
          </div>

          <!-- Report Display -->
          <?php if ($filterType && $filterId && !empty($reportData)): ?>
                <!-- Summary Stats -->
               <div class="row mb-3">
                    <div class="col-md-3"><div class="summary-card"><h6 class="text-primary">Total Records</h6><div class="display-6"><?= $summaryStats['total_records'] ?></div></div></div>
                    <div class="col-md-3"><div class="summary-card"><h6 class="text-success">Present (Yes)</h6><div class="display-6"><?= $summaryStats['present'] ?></div></div></div>
                    <div class="col-md-3"><div class="summary-card"><h6 class="text-danger">Absent (No)</h6><div class="display-6"><?= $summaryStats['absent'] ?></div></div></div>
                    <div class="col-md-3"><div class="summary-card"><h6 class="text-secondary">Excused</h6><div class="display-6"><?= $summaryStats['excused'] ?></div></div></div>
               </div>

                <div class="card shadow-sm mb-4">
                     <div class="card-header bg-light d-flex justify-content-between align-items-center">
                          <h5 class="mb-0">Detailed Summary</h5>
                           <button class="btn btn-sm btn-outline-success" onclick="exportReportTableToCSV('misc_report_<?= $filterType ?>_<?= $filterId ?>.csv')">
                               <i class="fas fa-file-csv me-1"></i> Export CSV
                           </button>
                     </div>
                     <div class="card-body p-0">
                          <div class="table-responsive">
                               <table id="reportTable" class="table table-striped table-hover mb-0">
                                    <thead>
                                         <tr>
                                             <th>Date</th>
                                             <?php if ($filterType == 'event'): ?>
                                                 <th>Class</th>
                                             <?php elseif ($filterType == 'class'): ?>
                                                 <th>Event</th>
                                             <?php endif; ?>
                                             <th>Total Records</th>
                                             <th>Present (Yes)</th>
                                             <th>Absent (No)</th>
                                             <th>Excused</th>
                                             <th>Present %</th>
                                         </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($reportData as $row): ?>
                                    <?php
                                        // Calculate percentage based on Present / (Present + Absent + Excused) for this row
                                        $row_denominator = $row['present_count'] + $row['absent_count'] + $row['excused_count'];
                                        $percentage = $row_denominator > 0 ? ($row['present_count'] / $row_denominator) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td><?= formatDate($row['attendance_date']) ?></td>
                                             <?php if ($filterType == 'event'): ?>
                                                 <td><?= htmlspecialchars($row['class_name']) . ($row['section'] ? ' - ' . htmlspecialchars($row['section']) : '') ?></td>
                                             <?php elseif ($filterType == 'class'): ?>
                                                 <td><?= htmlspecialchars($row['event_name']) ?></td>
                                             <?php endif; ?>
                                            <td><?= $row['total_records'] ?></td>
                                            <td><?= $row['present_count'] ?></td>
                                            <td><?= $row['absent_count'] ?></td>
                                            <td><?= $row['excused_count'] ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px; min-width: 80px;">
                                                    <div class="progress-bar <?= $percentage >= 80 ? 'bg-success' : ($percentage >= 50 ? 'bg-warning' : 'bg-danger') ?>" role="progressbar" style="width: <?= round($percentage) ?>%;" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                                        <?= number_format($percentage, 1) ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                     <tfoot>
                                         <tr class="table-dark fw-bold">
                                             <th colspan="2">Overall Total</th>
                                             <th><?= $summaryStats['total_records'] ?></th>
                                             <th><?= $summaryStats['present'] ?></th>
                                             <th><?= $summaryStats['absent'] ?></th>
                                             <th><?= $summaryStats['excused'] ?></th>
                                             <th><?= number_format($summaryStats['percentage'], 1) ?>%</th>
                                         </tr>
                                     </tfoot>
                               </table>
                          </div>
                     </div>
                </div>
          <?php elseif ($filterType && $filterId && empty($reportData)): ?>
              <div class="alert alert-warning">No miscellaneous attendance records found for the selected <?= $filterType ?> (<?= htmlspecialchars($filterName) ?>).</div>
          <?php elseif (!$message): ?>
              <div class="alert alert-info">Please select a report type (By Event or By Class) and choose an item to generate a report.</div>
          <?php endif; ?>

     </div><!-- End Container -->


    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
             if ($('#reportTable').length) {
                 $('#reportTable').DataTable({
                     pageLength: 25,
                     order: [[ 0, 'desc' ], [1, 'asc']], // Sort by Date desc, then Class/Event asc
                      language: { search: "_INPUT_", searchPlaceholder: "Search report..." }
                 });
             }
        });

        function toggleFilterInput(type) {
             if (type === 'event') {
                 document.getElementById('event_filter_div').style.display = 'block';
                 document.getElementById('class_filter_div').style.display = 'none';
                 // Clear the other select if needed
                 document.querySelector('#class_filter_div select').value = '';
             } else if (type === 'class') {
                 document.getElementById('event_filter_div').style.display = 'none';
                 document.getElementById('class_filter_div').style.display = 'block';
                  // Clear the other select if needed
                 document.querySelector('#event_filter_div select').value = '';
             }
        }

         function downloadCSV(csv, filename) {
             var csvFile;
             var downloadLink;
             csvFile = new Blob(["\uFEFF" + csv], {type: "text/csv;charset=utf-8;"}); // Add BOM for Excel compatibility
             downloadLink = document.createElement("a");
             downloadLink.download = filename;
             downloadLink.href = window.URL.createObjectURL(csvFile);
             downloadLink.style.display = "none";
             document.body.appendChild(downloadLink);
             downloadLink.click();
             document.body.removeChild(downloadLink);
         }

         function exportReportTableToCSV(filename) {
             var csv = [];
             var rows = document.querySelectorAll("#reportTable tr"); // Include header and footer

             for (var i = 0; i < rows.length; i++) {
                 var row = [], cols = rows[i].querySelectorAll("td, th");
                 for (var j = 0; j < cols.length; j++) {
                      var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/(\s\s+)/gm, " ").trim();
                      // Remove percentage sign from the last column if present
                      if (j === cols.length - 1 && data.includes('%')) data = data.replace('%','').trim();
                      data = data.replace(/"/g, '""'); // Escape double quotes
                     row.push('"' + data + '"');
                 }
                 csv.push(row.join(","));
             }
             downloadCSV(csv.join("\n"), filename);
         }

        // Initialize filter display based on current selection
         document.addEventListener('DOMContentLoaded', function() {
            const initialType = document.querySelector('input[name="report_type"]:checked');
            if (initialType) {
                 toggleFilterInput(initialType.value);
            } else {
                 // Default if nothing is checked (e.g., first load)
                  toggleFilterInput('event'); // Default to event filter
            }
         });
    </script>
</body>
</html>

