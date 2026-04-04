<?php
// inventory/index.php - Medicine Inventory Management
session_start();
// Ensure admin is logged in, redirect if not
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

// Get filter parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$filter = $_GET['filter'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';

// Build WHERE clause for medicines table
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(m.name LIKE ? OR m.generic_name LIKE ? OR m.manufacturer LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where_conditions[] = "m.category = ?";
    $params[] = $category;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch all medicines with aggregated batch data
// We need to fetch all medicines first, then aggregate their batch data in PHP
// because complex aggregation with conditional filtering (like low_stock, expired)
// across two tables and then applying filters on the aggregated results in a single SQL query
// can become very complex and inefficient, especially with sorting.
// A more scalable approach for very large datasets would involve materialized views or a reporting table.
$sql_medicines = "SELECT m.* FROM medicines m $where_clause ORDER BY m.$sort $order";
$stmt_medicines = $pdo->prepare($sql_medicines);
$stmt_medicines->execute($params);
$medicines_raw = $stmt_medicines->fetchAll(PDO::FETCH_ASSOC);

$medicines = [];
foreach ($medicines_raw as $med) {
    $medicine_id = $med['id'];
    
    // Fetch all batches for the current medicine
    $stmt_batches = $pdo->prepare("SELECT * FROM medicine_batches WHERE medicine_id = ? ORDER BY expiry_date ASC");
    $stmt_batches->execute([$medicine_id]);
    $batches = $stmt_batches->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate batch data for the medicine
    $total_quantity = 0;
    $total_cost_value = 0;
    $total_selling_value = 0;
    $earliest_expiry_date = null;
    $has_expired_batch = false;
    $has_expiring_soon_batch = false;

    foreach ($batches as $batch) {
        $total_quantity += $batch['quantity'];
        $total_cost_value += ($batch['quantity'] * $batch['cost_price']);
        $total_selling_value += ($batch['quantity'] * $batch['selling_price']);

        $batch_expiry_time = strtotime($batch['expiry_date']);
        if ($batch_expiry_time < time()) {
            $has_expired_batch = true;
        } elseif ($batch_expiry_time <= strtotime('+30 days')) {
            $has_expiring_soon_batch = true;
        }

        if ($earliest_expiry_date === null || $batch_expiry_time < strtotime($earliest_expiry_date)) {
            $earliest_expiry_date = $batch['expiry_date'];
        }
    }

    // Determine overall status for the medicine
    $is_out_of_stock_overall = ($total_quantity == 0);
    $is_low_stock_overall = ($total_quantity > 0 && $total_quantity < $med['minimum_stock']);

    // Append aggregated data to the medicine array
    $med['total_quantity'] = $total_quantity;
    $med['total_cost_value'] = $total_cost_value;
    $med['total_selling_value'] = $total_selling_value;
    $med['earliest_expiry_date'] = $earliest_expiry_date;
    $med['has_expired_batch'] = $has_expired_batch;
    $med['has_expiring_soon_batch'] = $has_expiring_soon_batch;
    $med['is_out_of_stock_overall'] = $is_out_of_stock_overall;
    $med['is_low_stock_overall'] = $is_low_stock_overall;
    $med['batches'] = $batches; // Store batches for potential future use or detailed display

    $medicines[] = $med;
}

// Apply quick filters on the aggregated data
$filtered_medicines = [];
foreach ($medicines as $med) {
    $match = true;
    switch ($filter) {
        case 'low_stock':
            if (!$med['is_low_stock_overall']) $match = false;
            break;
        case 'expired':
            if (!$med['has_expired_batch']) $match = false;
            break;
        case 'expiring_soon':
            if (!$med['has_expiring_soon_batch']) $match = false;
            break;
        case 'out_of_stock':
            if (!$med['is_out_of_stock_overall']) $match = false;
            break;
    }
    if ($match) {
        $filtered_medicines[] = $med;
    }
}
$medicines = $filtered_medicines; // Use the filtered list

// Get categories for filter dropdown
$stmt = $pdo->query("SELECT DISTINCT category FROM medicines ORDER BY category");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate summary statistics from the (potentially filtered) medicines list
$total_medicines_count = count($medicines); // Count of unique medicine types displayed
$total_inventory_value = array_sum(array_map(function($medicine) {
    return $medicine['total_selling_value'];
}, $medicines));

$low_stock_count = 0;
$expired_count = 0;
$expiring_soon_count = 0;
$out_of_stock_count = 0;

foreach ($medicines as $medicine) {
    if ($medicine['is_low_stock_overall']) {
        $low_stock_count++;
    }
    if ($medicine['has_expired_batch']) {
        $expired_count++;
    }
    if ($medicine['has_expiring_soon_batch']) {
        $expiring_soon_count++;
    }
    if ($medicine['is_out_of_stock_overall']) {
        $out_of_stock_count++;
    }
}

$success_message = '';
if (isset($_GET['deleted'])) {
    $success_message = 'Medicine deleted successfully!';
} elseif (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
}

$error_message = '';
if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medicine Inventory - Medical Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <a href="../dashboard/" class="flex items-center">
                        <svg class="w-8 h-8 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 8.172V5L8 4z"></path>
                        </svg>
                        <h1 class="text-2xl font-bold text-gray-800">Medical Management System</h1>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="add.php" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                        Add New Medicine
                    </a>
                    <a href="../dashboard/" class="text-gray-600 hover:text-gray-800">Dashboard</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h2 class="text-3xl font-bold text-gray-800 mb-2">Medicine Inventory</h2>
            <p class="text-gray-600">Manage your medicine inventory with complete tracking and alerts</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-green-800"><?php echo htmlspecialchars($success_message); ?></span>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <span class="text-red-800"><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-blue-100 text-blue-600 mr-3">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 8.172V5L8 4z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Medicine Types</p>
                        <p class="text-xl font-semibold text-gray-800"><?php echo number_format($total_medicines_count); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-green-100 text-green-600 mr-3">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Total Inventory Value</p>
                        <p class="text-xl font-semibold text-gray-800">₹<?php echo number_format($total_inventory_value, 2); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-yellow-100 text-yellow-600 mr-3">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Low Stock Types</p>
                        <p class="text-xl font-semibold text-gray-800"><?php echo number_format($low_stock_count); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-red-100 text-red-600 mr-3">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a2 2 0 012-2h4a2 2 0 012 2v4m-4 8l-3-3m0 0l-3 3m3-3v6m-1 1h-4a2 2 0 01-2-2V9a2 2 0 012-2h4a2 2 0 012 2v9a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Expired Types</p>
                        <p class="text-xl font-semibold text-gray-800"><?php echo number_format($expired_count); ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-orange-100 text-orange-600 mr-3">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Expiring Soon Types</p>
                        <p class="text-xl font-semibold text-gray-800"><?php echo number_format($expiring_soon_count); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <form method="GET" action="" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Search -->
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                        <input type="text" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Search by name, generic name, or manufacturer"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Category Filter -->
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                        <select id="category" name="category"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"
                                    <?php echo ($category === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Quick Filters -->
                    <div>
                        <label for="filter" class="block text-sm font-medium text-gray-700 mb-2">Quick Filter</label>
                        <select id="filter" name="filter"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Items</option>
                            <option value="low_stock" <?php echo ($filter === 'low_stock') ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="expired" <?php echo ($filter === 'expired') ? 'selected' : ''; ?>>Expired</option>
                            <option value="expiring_soon" <?php echo ($filter === 'expiring_soon') ? 'selected' : ''; ?>>Expiring Soon</option>
                            <option value="out_of_stock" <?php echo ($filter === 'out_of_stock') ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>

                    <!-- Sort -->
                    <div>
                        <label for="sort" class="block text-sm font-medium text-gray-700 mb-2">Sort By</label>
                        <div class="flex space-x-2">
                            <select id="sort" name="sort"
                                    class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="name" <?php echo ($sort === 'name') ? 'selected' : ''; ?>>Name</option>
                                <option value="category" <?php echo ($sort === 'category') ? 'selected' : ''; ?>>Category</option>
                                <!-- Quantity and Expiry Date sorting will be based on aggregated values -->
                                <option value="total_quantity" <?php echo ($sort === 'total_quantity') ? 'selected' : ''; ?>>Total Quantity</option>
                                <option value="earliest_expiry_date" <?php echo ($sort === 'earliest_expiry_date') ? 'selected' : ''; ?>>Earliest Expiry</option>
                                <option value="total_selling_value" <?php echo ($sort === 'total_selling_value') ? 'selected' : ''; ?>>Total Value</option>
                                <option value="created_at" <?php echo ($sort === 'created_at') ? 'selected' : ''; ?>>Date Added</option>
                            </select>
                            <select name="order"
                                    class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="ASC" <?php echo ($order === 'ASC') ? 'selected' : ''; ?>>↑ Asc</option>
                                <option value="DESC" <?php echo ($order === 'DESC') ? 'selected' : ''; ?>>↓ Desc</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                        Apply Filters
                    </button>
                    <a href="index.php" class="text-gray-600 hover:text-gray-800">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Medicine List -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">
                    Medicine List (<?php echo number_format($total_medicines_count); ?> types found)
                </h3>
            </div>

            <?php if (empty($medicines)): ?>
            <div class="text-center py-12">
                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <p class="text-gray-500 text-lg">No medicines found</p>
                <p class="text-gray-400 text-sm mt-2">Try adjusting your search criteria or add some medicines to your inventory</p>
                <a href="add.php" class="mt-4 inline-block bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                    Add First Medicine
                </a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Medicine</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Quantity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Value</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Earliest Expiry</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($medicines as $medicine): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div>
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($medicine['name']); ?></div>
                                    <?php if ($medicine['generic_name']): ?>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($medicine['generic_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($medicine['manufacturer']): ?>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($medicine['manufacturer']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                    <?php echo htmlspecialchars($medicine['category']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="flex items-center">
                                    <span class="<?php echo $medicine['is_out_of_stock_overall'] ? 'text-red-600 font-semibold' : ($medicine['is_low_stock_overall'] ? 'text-yellow-600 font-semibold' : ''); ?>">
                                        <?php echo number_format($medicine['total_quantity']); ?>
                                    </span>
                                    <?php if ($medicine['unit']): ?>
                                    <span class="text-gray-500 ml-1"><?php echo htmlspecialchars($medicine['unit']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-gray-400">Min: <?php echo $medicine['minimum_stock']; ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                ₹<?php echo number_format($medicine['total_selling_value'], 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <div class="<?php echo $medicine['has_expired_batch'] ? 'text-red-600 font-semibold' : ($medicine['has_expiring_soon_batch'] ? 'text-yellow-600 font-semibold' : 'text-gray-900'); ?>">
                                    <?php echo $medicine['earliest_expiry_date'] ? date('M d, Y', strtotime($medicine['earliest_expiry_date'])) : 'N/A'; ?>
                                </div>
                                <div class="text-xs text-gray-400">
                                    <?php
                                    if ($medicine['earliest_expiry_date']) {
                                        $days_to_expiry = ceil((strtotime($medicine['earliest_expiry_date']) - time()) / (60 * 60 * 24));
                                        if ($days_to_expiry < 0) {
                                            echo 'Expired ' . abs($days_to_expiry) . ' days ago';
                                        } else {
                                            echo $days_to_expiry . ' days remaining';
                                        }
                                    } else {
                                        echo 'No active batches';
                                    }
                                    ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col space-y-1">
                                    <?php if ($medicine['has_expired_batch']): ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                        Expired Batches
                                    </span>
                                    <?php elseif ($medicine['has_expiring_soon_batch']): ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                        Expiring Soon Batches
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($medicine['is_out_of_stock_overall']): ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                        Out of Stock
                                    </span>
                                    <?php elseif ($medicine['is_low_stock_overall']): ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                        Low Stock
                                    </span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        In Stock
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex space-x-2">
                                    <a href="view.php?id=<?php echo $medicine['id']; ?>"
                                       class="text-blue-600 hover:text-blue-900 text-sm">View Details</a>
                                    <a href="edit.php?table=medicines&id=<?php echo $medicine['id']; ?>"
                                       class="text-indigo-600 hover:text-indigo-900 text-sm">Edit General</a>
                                    <a href="delete_medicine.php?id=<?php echo $medicine['id']; ?>"
                                       class="text-red-600 hover:text-red-900 text-sm"
                                       onclick="return confirm('Are you sure you want to delete this medicine and ALL its batches? This action cannot be undone.')">Delete All</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-submit form when filters change
        document.addEventListener('DOMContentLoaded', function() {
            const filterSelects = document.querySelectorAll('#category, #filter, #sort, [name="order"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>
