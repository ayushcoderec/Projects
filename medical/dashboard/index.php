<?php
// dashboard.php - Updated Complete Dashboard
session_start();
// Ensure admin is logged in, redirect if not
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

// Get dashboard statistics
$stats = [];

// --- Medicine Inventory Statistics ---
// Total distinct medicine types
$stmt = $pdo->query("SELECT COUNT(*) FROM medicines");
$stats['total_medicine_types'] = $stmt->fetchColumn();

// Aggregate total quantity, low stock, expired, expiring soon from batches
$total_medicines_in_stock = 0;
$low_stock_types = 0;
$expired_types = 0;
$expiring_soon_types = 0;
$out_of_stock_types = 0; // New stat for medicines completely out of stock

// Fetch all medicines to process their batches
$stmt_all_medicines = $pdo->query("SELECT id, name, minimum_stock FROM medicines");
$all_medicines = $stmt_all_medicines->fetchAll(PDO::FETCH_ASSOC);

$recent_expired = [];
$recent_expiring = [];
$recent_low_stock = [];

foreach ($all_medicines as $medicine) {
    $medicine_id = $medicine['id'];
    $minimum_stock = $medicine['minimum_stock'];

    // Get total quantity for this medicine across all its batches
    $stmt_total_qty = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM medicine_batches WHERE medicine_id = ?");
    $stmt_total_qty->execute([$medicine_id]);
    $current_total_quantity = $stmt_total_qty->fetchColumn();
    $total_medicines_in_stock += $current_total_quantity; // Sum of all quantities across all types and batches

    // Check for low stock for this medicine type
    if ($current_total_quantity > 0 && $current_total_quantity < $minimum_stock) {
        $low_stock_types++;
        // Add to recent low stock list if quantity is low
        if (count($recent_low_stock) < 5) { // Limit to 5 for dashboard display
            $recent_low_stock[] = ['name' => $medicine['name'], 'quantity' => $current_total_quantity];
        }
    } elseif ($current_total_quantity == 0) {
        $out_of_stock_types++;
    }

    // Check for expired/expiring batches for this medicine type
    $stmt_batches_for_expiry = $pdo->prepare("SELECT batch_number, expiry_date, quantity FROM medicine_batches WHERE medicine_id = ? ORDER BY expiry_date ASC");
    $stmt_batches_for_expiry->execute([$medicine_id]);
    $batches_for_expiry = $stmt_batches_for_expiry->fetchAll(PDO::FETCH_ASSOC);

    $has_expired_batch_for_type = false;
    $has_expiring_soon_batch_for_type = false;

    foreach ($batches_for_expiry as $batch) {
        $batch_expiry_time = strtotime($batch['expiry_date']);
        $now = time();
        $thirty_days = 30 * 24 * 60 * 60;

        if ($batch_expiry_time < $now) {
            $has_expired_batch_for_type = true;
            if (count($recent_expired) < 5) { // Limit to 5 for dashboard display
                $recent_expired[] = ['name' => $medicine['name'] . ' (Batch: ' . $batch['batch_number'] . ')', 'expiry_date' => $batch['expiry_date']];
            }
        } elseif ($batch_expiry_time > $now && $batch_expiry_time <= ($now + $thirty_days)) {
            $has_expiring_soon_batch_for_type = true;
            if (count($recent_expiring) < 5) { // Limit to 5 for dashboard display
                $recent_expiring[] = ['name' => $medicine['name'] . ' (Batch: ' . $batch['batch_number'] . ')', 'expiry_date' => $batch['expiry_date']];
            }
        }
    }
    if ($has_expired_batch_for_type) {
        $expired_types++;
    }
    if ($has_expiring_soon_batch_for_type) {
        $expiring_soon_types++;
    }
}

$stats['low_stock_types'] = $low_stock_types;
$stats['expired_types'] = $expired_types;
$stats['expiring_soon_types'] = $expiring_soon_types;
$stats['out_of_stock_types'] = $out_of_stock_types;
$stats['total_medicines_in_stock'] = $total_medicines_in_stock; // This is the sum of all quantities

// --- Sales Statistics (remain largely the same, as bills table structure is unchanged) ---
// Today's sales
$stmt = $pdo->query("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM bills WHERE DATE(created_at) = CURDATE()");
$today_sales = $stmt->fetch(PDO::FETCH_NUM);
$stats['today_bills'] = $today_sales[0];
$stats['today_revenue'] = $today_sales[1];

// This month's sales
$stmt = $pdo->query("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM bills WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$month_sales = $stmt->fetch(PDO::FETCH_NUM);
$stats['month_bills'] = $month_sales[0];
$stats['month_revenue'] = $month_sales[1];

// Sort recent lists (optional, but good for consistent display)
usort($recent_expired, function($a, $b) {
    return strtotime($a['expiry_date']) - strtotime($b['expiry_date']);
});
usort($recent_expiring, function($a, $b) {
    return strtotime($a['expiry_date']) - strtotime($b['expiry_date']);
});
usort($recent_low_stock, function($a, $b) {
    return $a['quantity'] - $b['quantity'];
});

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Management Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <svg class="w-8 h-8 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 8.172V5L8 4z"></path>
                        </svg>
                        <h1 class="text-2xl font-bold text-gray-800">Medical Management System</h1>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-gray-600">Welcome, Admin</span>
                    <a href="../auth/logout.php" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Quick Actions -->
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Quick Actions</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="../inventory/add.php" class="bg-blue-500 text-white p-4 rounded-lg hover:bg-blue-600 transition duration-200">
                    <div class="flex items-center">
                        <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        <span class="font-medium">Add New Medicine</span>
                    </div>
                </a>
                <a href="../billing/create.php" class="bg-green-500 text-white p-4 rounded-lg hover:bg-green-600 transition duration-200">
                    <div class="flex items-center">
                        <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span class="font-medium">Create Bill</span>
                    </div>
                </a>
                <a href="../billing/history.php" class="bg-green-500 text-white p-4 rounded-lg hover:bg-green-600 transition duration-200">
                    <div class="flex items-center">
                        <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span class="font-medium">Bill History</span>
                    </div>
                </a>
                <a href="../inventory/index.php" class="bg-purple-500 text-white p-4 rounded-lg hover:bg-purple-600 transition duration-200">
                    <div class="flex items-center">
                        <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <span class="font-medium">View Inventory</span>
                    </div>
                </a>
                <a href="../reports/index.php" class="bg-orange-500 text-white p-4 rounded-lg hover:bg-orange-600 transition duration-200">
                    <div class="flex items-center">
                        <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        <span class="font-medium">Sales Reports</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Total Medicine Types -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 8.172V5L8 4z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Medicine Types</p>
                        <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['total_medicine_types']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Total Medicines in Stock (aggregated quantity) -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-green-100 text-green-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10m0 0h16m0 0V7m0 10l-2-2m2 2l-2 2M4 7l2-2m-2 2l2 2"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Units in Stock</p>
                        <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['total_medicines_in_stock']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Low Stock Types -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-yellow-100 text-yellow-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Low Stock Types</p>
                        <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['low_stock_types']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Expired Types -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-red-100 text-red-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a2 2 0 012-2h4a2 2 0 012 2v4m-4 8l-3-3m0 0l-3 3m3-3v6m-1 1h-4a2 2 0 01-2-2V9a2 2 0 012-2h4a2 2 0 012 2v9a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Expired Types</p>
                        <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['expired_types']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Today's Bills -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-green-100 text-green-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Today's Bills</p>
                        <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['today_bills']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Today's Revenue -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-green-100 text-green-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Today's Revenue</p>
                        <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($stats['today_revenue'], 2); ?></p>
                    </div>
                </div>
            </div>

            <!-- Month's Bills -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Month's Bills</p>
                        <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['month_bills']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Month's Revenue -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Month's Revenue</p>
                        <p class="text-2xl font-semibold text-gray-800">₹<?php echo number_format($stats['month_revenue'], 2); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Notifications -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Expired Medicines Alert -->
            <?php if ($stats['expired_types'] > 0): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-6">
                <div class="flex items-center mb-3">
                    <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <h3 class="font-semibold text-red-800">Expired Medicines</h3>
                </div>
                <p class="text-red-700 text-sm mb-3"><?php echo $stats['expired_types']; ?> medicine types have expired batches</p>
                <?php foreach ($recent_expired as $medicine): ?>
                <div class="text-sm text-red-600 mb-1">
                    • <?php echo htmlspecialchars($medicine['name']); ?> (Expires: <?php echo date('M d, Y', strtotime($medicine['expiry_date'])); ?>)
                </div>
                <?php endforeach; ?>
                <a href="../inventory/index.php?filter=expired" class="text-red-600 text-sm font-medium hover:underline">View All →</a>
            </div>
            <?php endif; ?>

            <!-- Expiring Soon Alert -->
            <?php if ($stats['expiring_soon_types'] > 0): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
                <div class="flex items-center mb-3">
                    <svg class="w-5 h-5 text-yellow-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h3 class="font-semibold text-yellow-800">Expiring Soon</h3>
                </div>
                <p class="text-yellow-700 text-sm mb-3"><?php echo $stats['expiring_soon_types']; ?> medicine types expiring within 30 days</p>
                <?php foreach ($recent_expiring as $medicine): ?>
                <div class="text-sm text-yellow-600 mb-1">
                    • <?php echo htmlspecialchars($medicine['name']); ?> (Expires: <?php echo date('M d, Y', strtotime($medicine['expiry_date'])); ?>)
                </div>
                <?php endforeach; ?>
                <a href="../inventory/index.php?filter=expiring_soon" class="text-yellow-600 text-sm font-medium hover:underline">View All →</a>
            </div>
            <?php endif; ?>

            <!-- Low Stock Alert -->
            <?php if ($stats['low_stock_types'] > 0): ?>
            <div class="bg-orange-50 border border-orange-200 rounded-lg p-6">
                <div class="flex items-center mb-3">
                    <svg class="w-5 h-5 text-orange-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <h3 class="font-semibold text-orange-800">Low Stock</h3>
                </div>
                <p class="text-orange-700 text-sm mb-3"><?php echo $stats['low_stock_types']; ?> medicine types have low stock</p>
                <?php foreach ($recent_low_stock as $medicine): ?>
                <div class="text-sm text-orange-600 mb-1">
                    • <?php echo htmlspecialchars($medicine['name']); ?> (<?php echo $medicine['quantity']; ?> remaining)
                </div>
                <?php endforeach; ?>
                <a href="../inventory/index.php?filter=low_stock" class="text-orange-600 text-sm font-medium hover:underline">View All →</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Main Navigation Menu -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">System Features</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Medicine Inventory -->
                <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                    <div class="flex items-center mb-3">
                        <svg class="w-8 h-8 text-blue-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 8.172V5L8 4z"></path>
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-800">Medicine Inventory</h3>
                    </div>
                    <p class="text-gray-600 text-sm mb-4">Manage your medicine inventory with complete tracking</p>
                    <div class="space-y-2">
                        <a href="../inventory/add.php" class="block text-blue-600 text-sm hover:underline">• Add New Medicine</a>
                        <a href="../inventory/index.php" class="block text-blue-600 text-sm hover:underline">• View All Medicines</a>
                        <!-- Removed direct link to expiry.php, as index.php with filter handles it now -->
                        <a href="../inventory/index.php?filter=expired" class="block text-blue-600 text-sm hover:underline">• Expired Medicines</a>
                        <a href="../inventory/index.php?filter=expiring_soon" class="block text-blue-600 text-sm hover:underline">• Expiring Soon Medicines</a>
                    </div>
                </div>

                <!-- Billing System -->
                <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                    <div class="flex items-center mb-3">
                        <svg class="w-8 h-8 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-800">Billing System</h3>
                    </div>
                    <p class="text-gray-600 text-sm mb-4">Create bills with automatic stock management</p>
                    <div class="space-y-2">
                        <a href="../billing/create.php" class="block text-green-600 text-sm hover:underline">• Create New Bill</a>
                        <a href="../billing/history.php" class="block text-green-600 text-sm hover:underline">• View Bill History</a>
                        <a href="../billing/search.php" class="block text-green-600 text-sm hover:underline">• Search Bills</a>
                    </div>
                </div>

                <!-- Sales Reports -->
                <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                    <div class="flex items-center mb-3">
                        <svg class="w-8 h-8 text-purple-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-800">Sales Reports</h3>
                    </div>
                    <p class="text-gray-600 text-sm mb-4">Comprehensive sales analytics and reporting</p>
                    <div class="space-y-2">
                        <a href="../reports/index.php" class="block text-purple-600 text-sm hover:underline">• Sales Dashboard</a>
                        <a href="../reports/index.php?report_type=daily" class="block text-purple-600 text-sm hover:underline">• Daily Reports</a>
                        <a href="../reports/index.php?report_type=monthly" class="block text-purple-600 text-sm hover:underline">• Monthly Reports</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
