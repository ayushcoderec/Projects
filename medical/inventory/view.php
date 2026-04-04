<?php
// inventory/view.php - View Medicine Details
session_start();
// Ensure admin is logged in, redirect if not
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit;
}

$medicine_id = (int)$_GET['id'];

// 1. Get general medicine details
$stmt_medicine = $pdo->prepare("SELECT id, name, generic_name, category, manufacturer, description, unit, minimum_stock, created_at FROM medicines WHERE id = ?");
$stmt_medicine->execute([$medicine_id]);
$medicine = $stmt_medicine->fetch(PDO::FETCH_ASSOC);

if (!$medicine) {
    header("Location: index.php?error=Medicine not found");
    exit;
}

// 2. Get all batches for this medicine, ordered by expiry date (soonest first)
$stmt_batches = $pdo->prepare("SELECT * FROM medicine_batches WHERE medicine_id = ? ORDER BY expiry_date ASC, received_date ASC");
$stmt_batches->execute([$medicine_id]);
$batches = $stmt_batches->fetchAll(PDO::FETCH_ASSOC);

// Initialize aggregated values for overall medicine status
$total_quantity = 0;
$total_cost_value = 0;
$total_selling_value = 0;
$earliest_expiry_date = null;
$has_expired_batch = false;
$has_expiring_soon_batch = false;
$is_out_of_stock_overall = true; // Assume out of stock until quantity is found

foreach ($batches as $batch) {
    $total_quantity += $batch['quantity'];
    $total_cost_value += ($batch['quantity'] * $batch['cost_price']);
    $total_selling_value += ($batch['quantity'] * $batch['selling_price']);

    // Check expiry for each batch to determine overall status
    $batch_expiry_time = strtotime($batch['expiry_date']);
    if ($batch_expiry_time < time()) {
        $has_expired_batch = true;
    } elseif ($batch_expiry_time <= strtotime('+30 days')) {
        $has_expiring_soon_batch = true;
    }

    // Determine the earliest expiry date among all batches
    if ($earliest_expiry_date === null || $batch_expiry_time < strtotime($earliest_expiry_date)) {
        $earliest_expiry_date = $batch['expiry_date'];
    }
}

// Update overall stock status based on aggregated quantity and minimum_stock
$is_out_of_stock_overall = ($total_quantity == 0);
$is_low_stock_overall = ($total_quantity > 0 && $total_quantity < $medicine['minimum_stock']);

// Calculate days to expiry for the earliest expiring batch
$days_to_earliest_expiry = ($earliest_expiry_date) ? ceil((strtotime($earliest_expiry_date) - time()) / (60 * 60 * 24)) : null;

// Calculate potential profit
$potential_profit = $total_selling_value - $total_cost_value;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($medicine['name']); ?> - Medicine Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
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
                    <a href="index.php" class="text-gray-600 hover:text-gray-800">← Back to Inventory</a>
                    <a href="edit.php?table=medicines&id=<?php echo $medicine['id']; ?>" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                        Edit Medicine Details
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-3xl font-bold text-gray-800 mb-2"><?php echo htmlspecialchars($medicine['name']); ?></h2>
                    <?php if ($medicine['generic_name']): ?>
                    <p class="text-lg text-gray-600"><?php echo htmlspecialchars($medicine['generic_name']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex space-x-2">
                    <?php if ($has_expired_batch): ?>
                    <span class="px-3 py-1 text-sm font-semibold rounded-full bg-red-100 text-red-800">
                        Expired Batches
                    </span>
                    <?php elseif ($has_expiring_soon_batch): ?>
                    <span class="px-3 py-1 text-sm font-semibold rounded-full bg-yellow-100 text-yellow-800">
                        Expiring Soon Batches
                    </span>
                    <?php endif; ?>
                    
                    <?php if ($is_out_of_stock_overall): ?>
                    <span class="px-3 py-1 text-sm font-semibold rounded-full bg-red-100 text-red-800">
                        Out of Stock
                    </span>
                    <?php elseif ($is_low_stock_overall): ?>
                    <span class="px-3 py-1 text-sm font-semibold rounded-full bg-yellow-100 text-yellow-800">
                        Low Stock
                    </span>
                    <?php else: ?>
                    <span class="px-3 py-1 text-sm font-semibold rounded-full bg-green-100 text-green-800">
                        In Stock
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Alert Messages (based on aggregated batch statuses) -->
        <?php if ($has_expired_batch || $has_expiring_soon_batch || $is_out_of_stock_overall || $is_low_stock_overall): ?>
        <div class="mb-8">
            <?php if ($has_expired_batch): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-red-800 font-semibold">One or more batches of this medicine have expired!</span>
                </div>
            </div>
            <?php elseif ($has_expiring_soon_batch): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-yellow-800 font-semibold">One or more batches of this medicine are expiring soon!</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($is_out_of_stock_overall): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-red-800 font-semibold">This medicine is out of stock!</span>
                </div>
            </div>
            <?php elseif ($is_low_stock_overall): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <span class="text-yellow-800 font-semibold">Low stock alert! Only <?php echo $total_quantity; ?> <?php echo htmlspecialchars($medicine['unit']); ?> remaining (minimum: <?php echo htmlspecialchars($medicine['minimum_stock']); ?>)</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Basic Information (from 'medicines' table) -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Basic Information</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Medicine Name</label>
                        <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($medicine['name']); ?></p>
                    </div>
                    
                    <?php if ($medicine['generic_name']): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Generic Name</label>
                        <p class="text-gray-800"><?php echo htmlspecialchars($medicine['generic_name']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Category</label>
                        <span class="inline-block px-2 py-1 text-sm font-semibold rounded-full bg-blue-100 text-blue-800">
                            <?php echo htmlspecialchars($medicine['category']); ?>
                        </span>
                    </div>
                    
                    <?php if ($medicine['manufacturer']): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Manufacturer</label>
                        <p class="text-gray-800"><?php echo htmlspecialchars($medicine['manufacturer']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($medicine['description']): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Description</label>
                        <p class="text-gray-800"><?php echo nl2br(htmlspecialchars($medicine['description'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Date Added (Medicine)</label>
                        <p class="text-gray-800"><?php echo date('F j, Y g:i A', strtotime($medicine['created_at'])); ?></p>
                    </div>
                </div>
            </div>

            <!-- Overall Stock Information (aggregated from 'medicine_batches' table) -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Overall Stock Information</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Total Current Quantity</label>
                        <p class="text-2xl font-bold <?php echo $is_out_of_stock_overall ? 'text-red-600' : ($is_low_stock_overall ? 'text-yellow-600' : 'text-green-600'); ?>">
                            <?php echo number_format($total_quantity); ?>
                            <?php if ($medicine['unit']): ?>
                            <span class="text-base font-normal text-gray-500"><?php echo htmlspecialchars($medicine['unit']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Minimum Stock Level</label>
                        <p class="text-gray-800 font-medium"><?php echo number_format($medicine['minimum_stock']); ?> <?php echo htmlspecialchars($medicine['unit'] ?? ''); ?></p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Overall Stock Status</label>
                        <div class="flex items-center space-x-2">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <?php 
                                $stock_percentage = ($medicine['minimum_stock'] > 0) ? ($total_quantity / $medicine['minimum_stock']) * 100 : ($total_quantity > 0 ? 100 : 0);
                                $bar_color = $stock_percentage > 100 ? 'bg-green-500' : ($stock_percentage > 50 ? 'bg-yellow-500' : 'bg-red-500');
                                ?>
                                <div class="<?php echo $bar_color; ?> h-2 rounded-full" style="width: <?php echo min(100, $stock_percentage); ?>%"></div>
                            </div>
                            <span class="text-sm text-gray-600"><?php echo round($stock_percentage); ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overall Pricing Information (aggregated from 'medicine_batches' table) -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Overall Financials</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Total Inventory Cost Value</label>
                        <p class="text-xl font-bold text-gray-800">₹<?php echo number_format($total_cost_value, 2); ?></p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Total Inventory Selling Value</label>
                        <p class="text-xl font-bold text-green-600">₹<?php echo number_format($total_selling_value, 2); ?></p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Potential Profit Across All Batches</label>
                        <p class="text-lg font-semibold text-blue-600">₹<?php echo number_format($potential_profit, 2); ?></p>
                    </div>
                </div>
            </div>

            <!-- Overall Expiry Information (aggregated, focusing on earliest) -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Overall Expiry Information</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Earliest Expiry Date</label>
                        <p class="text-xl font-bold <?php echo $has_expired_batch ? 'text-red-600' : ($has_expiring_soon_batch ? 'text-yellow-600' : 'text-gray-800'); ?>">
                            <?php echo $earliest_expiry_date ? date('F j, Y', strtotime($earliest_expiry_date)) : 'N/A'; ?>
                        </p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Days Until Earliest Expiry</label>
                        <p class="text-lg font-semibold <?php echo $has_expired_batch ? 'text-red-600' : ($has_expiring_soon_batch ? 'text-yellow-600' : 'text-green-600'); ?>">
                            <?php 
                            if ($days_to_earliest_expiry !== null) {
                                if ($days_to_earliest_expiry < 0) {
                                    echo 'Expired ' . abs($days_to_earliest_expiry) . ' days ago';
                                } else {
                                    echo $days_to_earliest_expiry . ' days remaining';
                                }
                            } else {
                                echo 'N/A (No active batches)';
                            }
                            ?>
                        </p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-600">Expiry Status</label>
                        <div class="flex items-center space-x-2">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <?php 
                                $total_shelf_life = 365; // Assume 1 year shelf life for visualization, adjust as needed
                                $remaining_days = max(0, $days_to_earliest_expiry ?? 0); // Handle null for no batches
                                $expiry_percentage = ($total_shelf_life > 0) ? ($remaining_days / $total_shelf_life) * 100 : 0;
                                $expiry_bar_color = $expiry_percentage > 50 ? 'bg-green-500' : ($expiry_percentage > 20 ? 'bg-yellow-500' : 'bg-red-500');
                                ?>
                                <div class="<?php echo $expiry_bar_color; ?> h-2 rounded-full" style="width: <?php echo min(100, $expiry_percentage); ?>%"></div>
                            </div>
                            <span class="text-sm text-gray-600"><?php echo round($expiry_percentage); ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New Section: Individual Batches -->
        <div class="mt-8 bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Individual Batches</h3>
            <?php if (!empty($batches)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch No.</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cost Price</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Selling Price</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expiry Date</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($batches as $batch): ?>
                                <?php
                                $batch_is_expired = strtotime($batch['expiry_date']) < time();
                                $batch_is_expiring_soon = strtotime($batch['expiry_date']) <= strtotime('+30 days');
                                ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($batch['batch_number']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo number_format($batch['quantity']); ?> <?php echo htmlspecialchars($medicine['unit']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        ₹<?php echo number_format($batch['cost_price'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-semibold">
                                        ₹<?php echo number_format($batch['selling_price'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm <?php echo $batch_is_expired ? 'text-red-600' : ($batch_is_expiring_soon ? 'text-yellow-600' : 'text-gray-500'); ?>">
                                        <?php echo date('F j, Y', strtotime($batch['expiry_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php
                                        if ($batch_is_expired) {
                                            echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Expired</span>';
                                        } elseif ($batch_is_expiring_soon) {
                                            echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Expiring Soon</span>';
                                        } else {
                                            echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex space-x-2 justify-end">
                                            <a href="edit_batch.php?id=<?php echo $batch['id']; ?>" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                            <a href="delete_batch.php?id=<?php echo $batch['id']; ?>" 
                                               class="text-red-600 hover:text-red-900"
                                               onclick="return confirm('Are you sure you want to delete this batch? This action cannot be undone.')">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-600">No batches found for this medicine. Please add a new batch.</p>
            <?php endif; ?>
            <div class="mt-4 text-right">
                <a href="add_batch.php?medicine_id=<?php echo $medicine['id']; ?>" class="bg-purple-600 text-white px-4 py-2 rounded-md hover:bg-purple-700 transition duration-200">
                    Add New Batch
                </a>
            </div>
        </div>

        <!-- Actions -->
        <div class="mt-8 bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Actions</h3>
            <div class="flex flex-wrap gap-4">
                <a href="edit.php?table=medicines&id=<?php echo $medicine['id']; ?>"
                   class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                    Edit Medicine General Details
                </a>
                <a href="index.php"
                   class="bg-gray-600 text-white px-6 py-2 rounded-md hover:bg-gray-700 transition duration-200">
                    Back to Inventory List
                </a>
                <a href="add.php"
                   class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700 transition duration-200">
                    Add New Medicine (New Type)
                </a>
                <a href="delete_medicine.php?id=<?php echo $medicine['id']; ?>"
                   class="bg-red-600 text-white px-6 py-2 rounded-md hover:bg-red-700 transition duration-200"
                   onclick="return confirm('Are you sure you want to delete this medicine and ALL its batches? This action cannot be undone.')">
                    Delete Medicine
                </a>
            </div>
        </div>
    </div>
</body>
</html>
