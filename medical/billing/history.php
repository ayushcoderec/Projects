<?php
// 📁 billing/history.php - Bill History Page
session_start();

// Check authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database connection
include '../config/db.php';

// Handle AJAX requests for bill details
if (isset($_GET['get_bill_details']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    try {
        $bill_id = intval($_GET['id']);
        $stmt = $pdo->prepare("SELECT * FROM bills WHERE id = ?");
        $stmt->execute([$bill_id]);
        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$bill) {
            echo json_encode(['status' => 'error', 'message' => 'Bill not found']);
            exit();
        }
        
        // Decode items JSON. It's now expected to be an object with an 'items' key.
        $decoded_items_data = json_decode($bill['items'], true);
        
        // Ensure the items are correctly extracted, handling potential variations
        $items_array = [];
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded_items_data['items']) && is_array($decoded_items_data['items'])) {
            $items_array = $decoded_items_data['items'];
        } else if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_items_data)) {
            // Fallback for older bills where 'items' might be directly the array
            $items_array = $decoded_items_data;
        } else {
            error_log("JSON decode or structure error for bill ID {$bill_id}: " . json_last_error_msg() . " Raw: " . $bill['items']);
        }

        $bill['items_decoded'] = $items_array; // Pass the actual array of items
        
        echo json_encode(['status' => 'success', 'bill' => $bill]);
    } catch (Exception $e) {
        error_log("Error fetching bill details for ID {$_GET['id']}: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle bill deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bill']) && isset($_POST['id'])) {
    header('Content-Type: application/json');
    
    try {
        $bill_id = intval($_POST['id']);
        
        // Prepare and execute the DELETE statement
        $stmt = $pdo->prepare("DELETE FROM bills WHERE id = ?");
        $stmt->execute([$bill_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => 'Bill deleted successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Bill not found or could not be deleted.']);
        }
    } catch (Exception $e) {
        error_log("Error deleting bill ID {$_POST['id']}: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Handle search and filter parameters
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$min_amount = $_GET['min_amount'] ?? '';
$max_amount = $_GET['max_amount'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10; // ✅ Integer
$offset = ($page - 1) * $per_page; // ✅ Integer


// Build the WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search_query)) {
    // Search by customer name, phone, or bill ID
    $where_conditions[] = "(customer_name LIKE ? OR customer_phone LIKE ? OR id = ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_query; // For ID, exact match is better
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($min_amount)) {
    $where_conditions[] = "total >= ?";
    $params[] = floatval($min_amount);
}

if (!empty($max_amount)) {
    $where_conditions[] = "total <= ?";
    $params[] = floatval($max_amount);
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM bills $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// Get bills with pagination
$limit = (int)$per_page;
$offset = (int)$offset;

// IMPORTANT: Inject integers directly, don't use placeholders for LIMIT and OFFSET
$query = "SELECT * FROM bills $where_clause ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params); // Only WHERE clause params

$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics for the filtered results
$stats_query = "SELECT 
    COALESCE(COUNT(*), 0) as total_bills,
    COALESCE(SUM(total), 0) as total_revenue,
    COALESCE(SUM(gst_amount), 0) as total_gst,
    COALESCE(AVG(total), 0) as avg_bill_amount
    FROM bills $where_clause";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params); // Use original params for stats query
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bill History - Medical Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        /* Custom modal styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.5); /* Black w/ opacity */
            display: flex; /* Use flexbox for centering */
            align-items: center; /* Center vertically */
            justify-content: center; /* Center horizontally */
        }
        .modal-content {
            background-color: #fefefe;
            margin: auto; /* Removed margin-top for flex centering */
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh; /* Limit height to prevent overflow */
            overflow-y: auto; /* Enable scrolling for content if needed */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative; /* Needed for absolute positioning of close button */
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute; /* Position relative to modal-content */
            top: 10px;
            right: 20px;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        /* Confirmation/Alert Modal Specifics */
        .confirm-modal-content, .alert-modal-content {
            background-color: #fefefe;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 400px; /* Smaller for confirmation/alert */
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        .confirm-modal-content p, .alert-modal-content p {
            margin-bottom: 20px;
            font-size: 1.1rem;
            color: #333;
        }
        .confirm-modal-content .buttons, .alert-modal-content .buttons {
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .confirm-modal-content button, .alert-modal-content button {
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
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
                    <a href="history.php" class="text-blue-600 font-semibold">Bill History</a>
                    <a href="../auth/logout.php" class="text-gray-600 hover:text-gray-800">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Bill History</h1>
            <div class="flex space-x-3">
                <a href="create.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors shadow-md">
                    + New Bill
                </a>
                <button onclick="exportBills()" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors shadow-md">
                    Export CSV
                </button>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h3 class="text-lg font-semibold text-gray-700">Total Bills</h3>
                <p class="text-3xl font-bold text-blue-600"><?php echo number_format($stats['total_bills']); ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h3 class="text-lg font-semibold text-gray-700">Total Revenue</h3>
                <p class="text-3xl font-bold text-green-600">₹<?php echo number_format($stats['total_revenue'], 2); ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h3 class="text-lg font-semibold text-gray-700">Total GST</h3>
                <p class="text-3xl font-bold text-orange-600">₹<?php echo number_format($stats['total_gst'], 2); ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h3 class="text-lg font-semibold text-gray-700">Average Bill</h3>
                <p class="text-3xl font-bold text-purple-600">₹<?php echo number_format($stats['avg_bill_amount'], 2); ?></p>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <h2 class="text-xl font-semibold mb-4 text-gray-700">Search & Filter</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Search</label>
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($search_query); ?>"
                           placeholder="Bill ID, Customer Name, or Phone"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Date From</label>
                    <input type="date" 
                           name="date_from" 
                           value="<?php echo htmlspecialchars($date_from); ?>"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Date To</label>
                    <input type="date" 
                           name="date_to" 
                           value="<?php echo htmlspecialchars($date_to); ?>"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Min Amount</label>
                    <input type="number" 
                           name="min_amount" 
                           value="<?php echo htmlspecialchars($min_amount); ?>"
                           step="0.01"
                           placeholder="₹0.00"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Max Amount</label>
                    <input type="number" 
                           name="max_amount" 
                           value="<?php echo htmlspecialchars($max_amount); ?>"
                           step="0.01"
                           placeholder="₹999999.99"
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition-colors shadow-md">
                        Search
                    </button>
                    <a href="history.php" class="bg-gray-600 text-white px-6 py-3 rounded-lg hover:bg-gray-700 transition-colors shadow-md">
                        Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Bills Table -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bill ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">GST</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($bills)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                    No bills found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bills as $bill): ?>
                                <?php 
                                    $items_data_raw = json_decode($bill['items'], true);
                                    $items_for_count = [];
                                    if (json_last_error() === JSON_ERROR_NONE && isset($items_data_raw['items']) && is_array($items_data_raw['items'])) {
                                        $items_for_count = $items_data_raw['items'];
                                    } else if (json_last_error() === JSON_ERROR_NONE && is_array($items_data_raw)) {
                                        $items_for_count = $items_data_raw; // Fallback for older structure
                                    }
                                    $item_count = count($items_for_count);
                                ?>
                                <tr id="bill-row-<?php echo htmlspecialchars($bill['id']); ?>" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">#<?php echo htmlspecialchars($bill['id']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($bill['customer_name'] ?: 'N/A'); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($bill['customer_phone'] ?: 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($bill['created_at'])); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo date('H:i A', strtotime($bill['created_at'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($item_count); ?> items</div>
                                        <div class="text-sm text-gray-500">₹<?php echo number_format($bill['subtotal'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($bill['gst_rate']); ?>%</div>
                                        <div class="text-sm text-gray-500">₹<?php echo number_format($bill['gst_amount'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-green-600">₹<?php echo number_format($bill['total'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <button onclick="viewBillDetails(<?php echo htmlspecialchars($bill['id']); ?>)" 
                                                class="text-blue-600 hover:text-blue-900 mr-3">View</button>
                                        <a href="print.php?id=<?php echo htmlspecialchars($bill['id']); ?>" 
                                            target="_blank" 
                                            class="text-green-600 hover:text-green-900 mr-3">Print</a>
                                        <button onclick="showConfirmModal('Are you sure you want to delete bill #<?php echo htmlspecialchars($bill['id']); ?>? This action cannot be undone.', () => deleteBill(<?php echo htmlspecialchars($bill['id']); ?>))" 
                                                class="text-red-600 hover:text-red-900">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="flex justify-center mt-6">
                <nav class="flex items-center space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>" 
                           class="px-3 py-2 text-sm <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50'; ?> rounded-lg">
                            <?php echo htmlspecialchars($i); ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            Next
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
            
            <div class="text-center mt-4 text-sm text-gray-600">
                Showing <?php echo htmlspecialchars((($page - 1) * $per_page) + 1); ?> to <?php echo htmlspecialchars(min($page * $per_page, $total_records)); ?> of <?php echo htmlspecialchars($total_records); ?> results
            </div>
        <?php endif; ?>
    </div>

    <!-- Bill Details Modal -->
    <div id="billModal" class="modal hidden">
        <div class="modal-content">
            <span class="close" onclick="closeModal('billModal')">&times;</span>
            <div id="billDetails">
                <div class="text-center">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-2 text-gray-600">Loading bill details...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal hidden">
        <div class="confirm-modal-content rounded-lg shadow-lg p-6">
            <p id="confirmMessage" class="text-lg text-gray-800 mb-6"></p>
            <div class="buttons flex justify-center space-x-4">
                <button id="confirmYes" class="bg-red-600 text-white px-5 py-2 rounded-lg hover:bg-red-700 transition-colors shadow-md">Yes</button>
                <button id="confirmNo" class="bg-gray-500 text-white px-5 py-2 rounded-lg hover:bg-gray-600 transition-colors shadow-md">No</button>
            </div>
        </div>
    </div>

    <!-- Custom Alert Modal -->
    <div id="alertModal" class="modal hidden">
        <div class="alert-modal-content rounded-lg shadow-lg p-6">
            <p id="alertMessage" class="text-lg text-gray-800 mb-6"></p>
            <div class="buttons">
                <button onclick="closeModal('alertModal')" class="bg-blue-600 text-white px-5 py-2 rounded-lg hover:bg-blue-700 transition-colors shadow-md">OK</button>
            </div>
        </div>
    </div>

    <script>
        // Generic function to show a modal
        function showModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        // Generic function to close a modal
        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Custom Confirmation Dialog
        function showConfirmModal(message, onConfirm) {
            const confirmModal = document.getElementById('confirmModal');
            const confirmMessage = document.getElementById('confirmMessage');
            const confirmYes = document.getElementById('confirmYes');
            const confirmNo = document.getElementById('confirmNo');

            confirmMessage.textContent = message;
            showModal('confirmModal');

            // Clear previous event listeners to prevent multiple calls
            confirmYes.onclick = null;
            confirmNo.onclick = null;

            confirmYes.onclick = () => {
                closeModal('confirmModal');
                onConfirm();
            };
            confirmNo.onclick = () => {
                closeModal('confirmModal');
            };
        }

        // Custom Alert Dialog
        function showAlertModal(message) {
            const alertModal = document.getElementById('alertModal');
            const alertMessage = document.getElementById('alertMessage');
            alertMessage.textContent = message;
            showModal('alertModal');
        }

        // View bill details
        function viewBillDetails(billId) {
            showModal('billModal');
            document.getElementById('billDetails').innerHTML = `
                <div class="text-center">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-2 text-gray-600">Loading bill details...</p>
                </div>
            `;
            
            fetch(`?get_bill_details=1&id=${billId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const bill = data.bill;
                        // Ensure items is an array, default to empty if not
                        const items = bill.items_decoded && Array.isArray(bill.items_decoded) ? bill.items_decoded : [];
                        
                        document.getElementById('billDetails').innerHTML = `
                            <div class="space-y-6 p-4">
                                <div class="text-center border-b pb-4 mb-4">
                                    <h2 class="text-2xl font-bold text-gray-800">Bill #${bill.id}</h2>
                                    <p class="text-gray-600">${new Date(bill.created_at).toLocaleDateString()} at ${new Date(bill.created_at).toLocaleTimeString()}</p>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h3 class="font-semibold text-gray-700 mb-2">Customer Information</h3>
                                        <p><strong>Name:</strong> ${bill.customer_name || 'N/A'}</p>
                                        <p><strong>Phone:</strong> ${bill.customer_phone || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-700 mb-2">Bill Summary</h3>
                                        <p><strong>Subtotal:</strong> ₹${parseFloat(bill.subtotal).toFixed(2)}</p>
                                        <p><strong>GST (${bill.gst_rate}%):</strong> ₹${parseFloat(bill.gst_amount).toFixed(2)}</p>
                                        <p class="text-lg font-bold text-green-600"><strong>Total:</strong> ₹${parseFloat(bill.total).toFixed(2)}</p>
                                    </div>
                                </div>
                                
                                <div>
                                    <h3 class="font-semibold text-gray-700 mb-4 mt-6">Items</h3>
                                    <div class="overflow-x-auto">
                                        <table class="w-full table-auto border-collapse border border-gray-300">
                                            <thead>
                                                <tr class="bg-gray-50">
                                                    <th class="border border-gray-300 px-4 py-2 text-left">Medicine</th>
                                                    <th class="border border-gray-300 px-4 py-2 text-left">Batch No.</th>
                                                    <th class="border border-gray-300 px-4 py-2 text-left">Qty</th>
                                                    <th class="border border-gray-300 px-4 py-2 text-right">Price/Unit</th>
                                                    <th class="border border-gray-300 px-4 py-2 text-right">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${items.length > 0 ? items.map(item => `
                                                    <tr>
                                                        <td class="border border-gray-300 px-4 py-2">${item.medicine_name || item.name || 'N/A'}</td>
                                                        <td class="border border-gray-300 px-4 py-2">${item.batch_number || 'N/A'}</td>
                                                        <td class="border border-gray-300 px-4 py-2">${item.quantity || 0}</td>
                                                        <td class="border border-gray-300 px-4 py-2 text-right">₹${parseFloat(item.selling_price || item.price || 0).toFixed(2)}</td>
                                                        <td class="border border-gray-300 px-4 py-2 text-right">₹${parseFloat(item.total || (item.quantity * item.selling_price) || 0).toFixed(2)}</td>
                                                    </tr>
                                                `).join('') : `
                                                    <tr>
                                                        <td colspan="5" class="border border-gray-300 px-4 py-2 text-center text-gray-500">No items found for this bill.</td>
                                                    </tr>
                                                `}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <div class="flex justify-end space-x-3 pt-4 border-t mt-6">
                                    <button onclick="closeModal('billModal')" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 shadow-md">Close</button>
                                    <a href="print.php?id=${bill.id}" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 shadow-md">Print Bill</a>
                                </div>
                            </div>
                        `;
                    } else {
                        document.getElementById('billDetails').innerHTML = `
                            <div class="text-center text-red-600 p-4">
                                <p>Error loading bill details: ${data.message}</p>
                                <button onclick="closeModal('billModal')" class="mt-4 bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 shadow-md">Close</button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    document.getElementById('billDetails').innerHTML = `
                        <div class="text-center text-red-600 p-4">
                            <p>An error occurred: ${error.message}. Check console for details.</p>
                            <button onclick="closeModal('billModal')" class="mt-4 bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 shadow-md">Close</button>
                        </div>
                    `;
                });
        }

        // Delete bill function
        function deleteBill(billId) {
            fetch('history.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `delete_bill=1&id=${billId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showAlertModal(data.message);
                    // Remove the deleted row from the table
                    const row = document.getElementById(`bill-row-${billId}`);
                    if (row) {
                        row.remove();
                    }
                    // Optionally, reload the page to update statistics and pagination
                    // window.location.reload(); 
                } else {
                    showAlertModal(`Error: ${data.message}`);
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                showAlertModal(`An error occurred during deletion: ${error.message}`);
            });
        }

        // Export bills to CSV (now redirects to a dedicated export.php)
        function exportBills() {
            const searchParams = new URLSearchParams(window.location.search);
            // Remove 'page' parameter as it's not relevant for full export
            searchParams.delete('page'); 
            window.location.href = 'export.php?' + searchParams.toString();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const billModal = document.getElementById('billModal');
            const confirmModal = document.getElementById('confirmModal');
            const alertModal = document.getElementById('alertModal');

            if (event.target === billModal) {
                closeModal('billModal');
            } else if (event.target === confirmModal) {
                closeModal('confirmModal');
            } else if (event.target === alertModal) {
                closeModal('alertModal');
            }
        }
    </script>
</body>
</html>
