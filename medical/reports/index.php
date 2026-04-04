<?php
// reports/index.php - Sales Reports Dashboard
session_start();

// Ensure admin is logged in, redirect if not
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

// Get date range from URL parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$report_type = $_GET['report_type'] ?? 'daily';

// --- Functions to fetch report data ---

/**
 * Fetches daily sales statistics for a given date range.
 * @param PDO $pdo The PDO database connection object.
 * @param string $start_date Start date in YYYY-MM-DD format.
 * @param string $end_date End date in YYYY-MM-DD format.
 * @return array Associative array of daily sales data.
 */
function getSalesStats($pdo, $start_date, $end_date) {
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

/**
 * Calculates top-selling medicines by aggregating data from bill items.
 * @param PDO $pdo The PDO database connection object.
 * @param string $start_date Start date in YYYY-MM-DD format.
 * @param string $end_date End date in YYYY-MM-DD format.
 * @param int $limit Maximum number of top medicines to return.
 * @return array Associative array of top-selling medicine statistics.
 */
function getTopSellingMedicines($pdo, $start_date, $end_date, $limit = 10) {
    // Get the raw JSON data from bills within the date range
    $sql = "SELECT items, created_at
            FROM bills 
            WHERE DATE(created_at) BETWEEN ? AND ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $medicine_stats = [];
    
    foreach ($results as $row) {
        // Decode the JSON directly. The 'items' column now stores a JSON object with a key 'items'.
        $items_data = json_decode($row['items'], true);
        
        // Check if JSON decode was successful and 'items' key exists and is an array
        if ($items_data && isset($items_data['items']) && is_array($items_data['items'])) {
            foreach ($items_data['items'] as $item) {
                // Use 'medicine_name' as per the new structure from billing/create.php
                $name = $item['medicine_name'] ?? 'Unknown Medicine'; 
                
                // Initialize medicine stats if not exists
                if (!isset($medicine_stats[$name])) {
                    $medicine_stats[$name] = [
                        'name' => $name,
                        'total_quantity' => 0,
                        'total_revenue' => 0,
                        'total_sales' => 0 // Count of times this medicine appeared in a bill
                    ];
                }
                
                // Add to totals. Ensure type casting for safety.
                $medicine_stats[$name]['total_quantity'] += intval($item['quantity'] ?? 0);
                $medicine_stats[$name]['total_revenue'] += floatval($item['total'] ?? 0); // 'total' is the line item total
                $medicine_stats[$name]['total_sales']++; // Increment sales count for this medicine
            }
        } else {
            // Log if JSON structure is unexpected or decoding failed for a bill
            error_log("Failed to decode or parse items for bill on " . $row['created_at'] . ": " . ($row['items'] ?? 'NULL'));
        }
    }
    
    // Convert associative array to indexed array for sorting
    $medicine_stats_array = array_values($medicine_stats);

    // Sort by total revenue (descending)
    usort($medicine_stats_array, function($a, $b) {
        return $b['total_revenue'] <=> $a['total_revenue'];
    });
    
    return array_slice($medicine_stats_array, 0, $limit);
}

/**
 * Fetches overall summary statistics for a given date range.
 * @param PDO $pdo The PDO database connection object.
 * @param string $start_date Start date in YYYY-MM-DD format.
 * @param string $end_date End date in YYYY-MM-DD format.
 * @return array Associative array of summary statistics.
 */
function getSummaryStats($pdo, $start_date, $end_date) {
    $sql = "SELECT 
                COUNT(*) as total_bills,
                COALESCE(SUM(total), 0) as total_revenue,
                COALESCE(SUM(gst_amount), 0) as total_gst,
                COALESCE(AVG(total), 0) as avg_bill_amount
            FROM bills 
            WHERE DATE(created_at) BETWEEN ? AND ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start_date, $end_date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Execute data fetching functions
$sales_data = getSalesStats($pdo, $start_date, $end_date);
$top_medicines = getTopSellingMedicines($pdo, $start_date, $end_date);
$summary = getSummaryStats($pdo, $start_date, $end_date);

// Prepare chart data (reverse order for chronological display on chart)
$chart_labels = array_reverse(array_column($sales_data, 'sale_date'));
$chart_revenue = array_reverse(array_column($sales_data, 'total_revenue'));
$chart_bills = array_reverse(array_column($sales_data, 'total_bills'));

// Format chart labels to 'Mon DD'
foreach ($chart_labels as &$label) {
    $label = date('M d', strtotime($label));
}
unset($label); // Unset reference

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Reports - Medical Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        @media print {
            .no-print { display: none !important; }
            .print-section { page-break-inside: avoid; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation (retained from dashboard.php for consistency) -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <a href="../dashboard.php" class="flex items-center">
                        <svg class="w-8 h-8 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 8.172V5L8 4z"></path>
                        </svg>
                        <h1 class="text-2xl font-bold text-gray-800">Medical Management System</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="../dashboard.php" class="text-gray-600 hover:text-gray-800">Dashboard</a>
                    <a href="../inventory/index.php" class="text-gray-600 hover:text-gray-800">Inventory</a>
                    <a href="../billing/history.php" class="text-gray-600 hover:text-gray-800">Bill History</a>
                    <a href="../auth/logout.php" class="text-gray-600 hover:text-gray-800">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex justify-between items-center">
                <h1 class="text-3xl font-bold text-gray-800">Sales Reports</h1>
                <div class="flex space-x-2 no-print">
                    <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                        🖨️ Print Report
                    </button>
                    <!-- Export links will point to a separate export.php -->
                    <a href="export.php?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&format=excel" 
                       class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition duration-200">
                        Export Excel
                    </a>
                    <a href="export.php?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&format=pdf" 
                       class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition duration-200">
                        Export PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6 no-print">
            <h2 class="text-xl font-semibold mb-4 text-gray-700">Filter Reports</h2>
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                           class="border rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                           class="border rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Report Type</label>
                    <select name="report_type" class="border rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="daily" <?php echo $report_type == 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="weekly" <?php echo $report_type == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                        <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                    Generate Report
                </button>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-md p-6 print-section">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Bills</p>
                        <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($summary['total_bills']); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6 print-section">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-green-100 text-green-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Revenue</p>
                        <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($summary['total_revenue'], 2); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6 print-section">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-yellow-100 text-yellow-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total GST</p>
                        <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($summary['total_gst'], 2); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6 print-section">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-purple-100 text-purple-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Avg Bill Amount</p>
                        <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($summary['avg_bill_amount'], 2); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-md p-6 print-section">
                <h3 class="text-lg font-semibold mb-4 text-gray-800">Revenue Trend</h3>
                <canvas id="revenueChart" width="400" height="200"></canvas>
            </div>
            <div class="bg-white rounded-lg shadow-md p-6 print-section">
                <h3 class="text-lg font-semibold mb-4 text-gray-800">Bills Count Trend</h3>
                <canvas id="billsChart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- Top Selling Medicines -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6 print-section">
            <h3 class="text-lg font-semibold mb-4 text-gray-800">Top Selling Medicines</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Medicine Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Quantity Sold</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Number of Sales</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!empty($top_medicines)): ?>
                            <?php foreach ($top_medicines as $medicine): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($medicine['name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo number_format($medicine['total_quantity']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo number_format($medicine['total_sales']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    ₹<?php echo number_format($medicine['total_revenue'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="px-6 py-4 text-center text-gray-500">No top-selling medicines found for this period.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Daily Sales Details -->
        <div class="bg-white rounded-lg shadow-md p-6 print-section">
            <h3 class="text-lg font-semibold mb-4 text-gray-800">Daily Sales Summary</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Bills</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">GST</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Bill</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (!empty($sales_data)): ?>
                            <?php foreach ($sales_data as $data): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo date('M d, Y', strtotime($data['sale_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo number_format($data['total_bills']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    ₹<?php echo number_format($data['total_revenue'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    ₹<?php echo number_format($data['total_gst'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    ₹<?php echo number_format($data['avg_bill_amount'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-gray-500">No sales data found for this period.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Back to Dashboard -->
        <div class="text-center mt-6 no-print">
            <a href="../dashboard.php" class="bg-gray-600 text-white px-6 py-2 rounded-md hover:bg-gray-700 transition duration-200">
                Back to Dashboard
            </a>
        </div>
    </div>

    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Revenue (₹)',
                    data: <?php echo json_encode($chart_revenue); ?>,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Revenue (₹)'
                        }
                    }
                }
            }
        });

        // Bills Chart
        const billsCtx = document.getElementById('billsChart').getContext('2d');
        new Chart(billsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Number of Bills',
                    data: <?php echo json_encode($chart_bills); ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)',
                    borderColor: 'rgb(16, 185, 129)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Bills'
                        },
                        ticks: {
                            precision: 0 // Ensure integer ticks for bill count
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
