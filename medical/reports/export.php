<?php
// reports/export.php - Export functionality for Sales Reports
session_start();

// Ensure admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php'; // Use the PDO connection

// Get format and date range from URL parameters
$format = $_GET['format'] ?? 'csv'; // Default to CSV
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// --- Function to get export data (replicated from index.php) ---
function getExportSalesData($pdo, $start_date, $end_date) {
    $sql = "SELECT 
                DATE(created_at) as sale_date,
                COUNT(*) as total_bills,
                SUM(total) as total_revenue,
                SUM(gst_amount) as total_gst,
                AVG(total) as avg_bill_amount
            FROM bills 
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY sale_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$export_data = getExportSalesData($pdo, $start_date, $end_date);

// --- Export Logic ---
if ($format == 'excel' || $format == 'csv') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="sales_report_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Total Bills', 'Revenue (INR)', 'GST (INR)', 'Avg Bill Amount (INR)']);
    
    foreach ($export_data as $row) {
        fputcsv($output, [
            date('M d, Y', strtotime($row['sale_date'])),
            $row['total_bills'],
            number_format($row['total_revenue'], 2, '.', ''), // Use '.' for decimal, no thousands separator
            number_format($row['total_gst'], 2, '.', ''),
            number_format($row['avg_bill_amount'], 2, '.', '')
        ]);
    }
    
    fclose($output);
} elseif ($format == 'pdf') {
    // Simple HTML to PDF conversion. For robust PDF generation, consider libraries like TCPDF or mPDF.
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment;filename="sales_report_' . date('Y-m-d') . '.pdf"');
    
    // Start HTML for PDF
    echo '<html><head><style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2, p { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin: 20px auto; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .header { margin-bottom: 20px; }
    </style></head><body>';
    
    echo '<div class="header"><h1>Sales Report</h1>';
    echo '<p>Period: ' . date('M d, Y', strtotime($start_date)) . ' to ' . date('M d, Y', strtotime($end_date)) . '</p></div>';
    
    echo '<table>';
    echo '<thead><tr><th>Date</th><th>Total Bills</th><th>Revenue (INR)</th><th>GST (INR)</th><th>Avg Bill Amount (INR)</th></tr></thead>';
    echo '<tbody>';
    
    foreach ($export_data as $row) {
        echo '<tr>';
        echo '<td>' . date('M d, Y', strtotime($row['sale_date'])) . '</td>';
        echo '<td>' . number_format($row['total_bills']) . '</td>';
        echo '<td>₹' . number_format($row['total_revenue'], 2) . '</td>';
        echo '<td>₹' . number_format($row['total_gst'], 2) . '</td>';
        echo '<td>₹' . number_format($row['avg_bill_amount'], 2) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table></body></html>';
}

exit(); // Terminate script after export
?>
