<?php
// 📁 billing/create.php - Updated for Batch Management
session_start();

// Check authentication first
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Handle AJAX requests BEFORE including any HTML templates
if (isset($_GET['debug']) || isset($_GET['search'])) {
    // Include database connection
    include '../config/db.php';
    
    // Set JSON header
    header('Content-Type: application/json');
    
    // Handle debug request
    if (isset($_GET['debug'])) {
        try {
            // Check if $pdo variable exists
            if (!isset($pdo)) {
                throw new Exception("PDO connection variable not found. Check your config/db.php file.");
            }
            
            // Test connection
            $pdo->query("SELECT 1");
            
            // Test medicines table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'medicines'");
            $medicine_table_exists = $stmt->fetch();

            $stmt = $pdo->query("SHOW TABLES LIKE 'medicine_batches'");
            $batch_table_exists = $stmt->fetch();
            
            if (!$medicine_table_exists) {
                throw new Exception("Medicines table does not exist in database");
            }
            if (!$batch_table_exists) {
                throw new Exception("Medicine_batches table does not exist in database");
            }
            
            // Test medicines table structure
            $stmt_med_cols = $pdo->query("DESCRIBE medicines");
            $med_columns = $stmt_med_cols->fetchAll(PDO::FETCH_ASSOC);

            // Test medicine_batches table structure
            $stmt_batch_cols = $pdo->query("DESCRIBE medicine_batches");
            $batch_columns = $stmt_batch_cols->fetchAll(PDO::FETCH_ASSOC);
            
            // Test sample data from medicines
            $stmt_med_count = $pdo->query("SELECT COUNT(*) as count FROM medicines");
            $med_count = $stmt_med_count->fetch()['count'];
            
            $stmt_med_sample = $pdo->query("SELECT id, name, category FROM medicines LIMIT 3");
            $med_sample_data = $stmt_med_sample->fetchAll(PDO::FETCH_ASSOC);

            // Test sample data from medicine_batches
            $stmt_batch_count = $pdo->query("SELECT COUNT(*) as count FROM medicine_batches");
            $batch_count = $stmt_batch_count->fetch()['count'];
            
            $stmt_batch_sample = $pdo->query("SELECT id, medicine_id, batch_number, quantity, selling_price, expiry_date FROM medicine_batches LIMIT 3");
            $batch_sample_data = $stmt_batch_sample->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'pdo_exists' => true,
                'medicine_table_exists' => true,
                'batch_table_exists' => true,
                'medicine_columns' => $med_columns,
                'batch_columns' => $batch_columns,
                'medicine_record_count' => $med_count,
                'batch_record_count' => $batch_count,
                'medicine_sample_data' => $med_sample_data,
                'batch_sample_data' => $batch_sample_data
            ]);
            
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'pdo_exists' => isset($pdo),
                'file' => __FILE__,
                'line' => __LINE__
            ]);
        }
        exit();
    }
    
    // Handle search request
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        try {
            $search = '%' . $_GET['search'] . '%';
            
            // Check if PDO exists
            if (!isset($pdo)) {
                throw new Exception("PDO connection not available. Check config/db.php file.");
            }
            
            // Test the connection
            $pdo->query("SELECT 1");
            
            // Query to get medicine batches that are in stock and not expired, ordered by expiry date
            $stmt = $pdo->prepare("
                SELECT 
                    m.id AS medicine_id, 
                    m.name AS medicine_name, 
                    m.unit,
                    mb.id AS batch_id, 
                    mb.batch_number, 
                    mb.quantity AS batch_quantity, 
                    mb.selling_price, 
                    mb.expiry_date 
                FROM 
                    medicines m
                JOIN 
                    medicine_batches mb ON m.id = mb.medicine_id
                WHERE 
                    (m.name LIKE ? OR m.generic_name LIKE ?) 
                    AND mb.quantity > 0 
                    AND mb.expiry_date >= CURDATE()
                ORDER BY 
                    m.name ASC, mb.expiry_date ASC
                LIMIT 20
            ");
            $stmt->execute([$search, $search]); // Search both name and generic_name
            
            $medicines_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'data' => $medicines_batches, // Return flat list of batches
                'search_term' => $_GET['search'],
                'found_count' => count($medicines_batches)
            ]);
            
        } catch (PDOException $e) {
            echo json_encode([
                'status' => 'error',
                'type' => 'PDO Error',
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'search_term' => $_GET['search'] ?? 'N/A'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'type' => 'General Error',
                'message' => $e->getMessage(),
                'search_term' => $_GET['search'] ?? 'N/A'
            ]);
        }
        exit();
    }
}

// Now include templates and handle regular page requests
include '../config/db.php';
// Assuming header.php and footer.php exist and contain necessary HTML structure.
// If not, you might need to create them or embed the HTML directly.
// include '../templates/header.php'; // Removed as it was causing issues with full page HTML.

// Handle bill processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_bill'])) {
    $cart_items = json_decode($_POST['cart_data'], true);
    $customer_name = $_POST['customer_name'] ?? '';
    $customer_phone = $_POST['customer_phone'] ?? '';
    $gst_rate = floatval($_POST['gst_rate'] ?? 0);
    
    if (empty($cart_items)) {
        $error = "Cart is empty! Please add some medicines.";
    } else {
        try {
            $pdo->beginTransaction();
            
            $subtotal = 0;
            $bill_items = [];
            
            // Process each cart item
            foreach ($cart_items as $item) {
                $batch_id = $item['batch_id']; // Now using batch_id
                $quantity_to_sell = $item['quantity'];
                $selling_price_per_unit = $item['selling_price'];
                
                // Check stock for the specific batch
                $stock_check = $pdo->prepare("SELECT mb.quantity, m.name, mb.batch_number FROM medicine_batches mb JOIN medicines m ON mb.medicine_id = m.id WHERE mb.id = ?");
                $stock_check->execute([$batch_id]);
                $batch_data = $stock_check->fetch(PDO::FETCH_ASSOC);
                
                if (!$batch_data) {
                    throw new Exception("Medicine batch (ID: " . htmlspecialchars($batch_id) . ") not found.");
                }
                
                if ($batch_data['quantity'] < $quantity_to_sell) {
                    throw new Exception("Insufficient stock for " . htmlspecialchars($batch_data['name']) . " (Batch: " . htmlspecialchars($batch_data['batch_number']) . "). Available: " . htmlspecialchars($batch_data['quantity']));
                }
                
                // Update stock for the specific batch
                $update_stock = $pdo->prepare("UPDATE medicine_batches SET quantity = quantity - ? WHERE id = ?");
                $update_stock->execute([$quantity_to_sell, $batch_id]);
                
                $item_total = $selling_price_per_unit * $quantity_to_sell;
                $subtotal += $item_total;
                
                $bill_items[] = [
                    'medicine_id' => $item['medicine_id'],
                    'medicine_name' => $item['medicine_name'],
                    'batch_id' => $batch_id,
                    'batch_number' => $batch_data['batch_number'], // Store batch number in bill items
                    'quantity' => $quantity_to_sell,
                    'selling_price' => $selling_price_per_unit,
                    'total' => $item_total
                ];
            }
            
            // Calculate totals
            $gst_amount = ($subtotal * $gst_rate) / 100;
            $total = $subtotal + $gst_amount;
            
            // Insert bill
            $insert_bill = $pdo->prepare("INSERT INTO bills (customer_name, customer_phone, items, subtotal, gst_rate, gst_amount, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $items_json = json_encode(['items' => $bill_items]); // Wrap items in an 'items' key for consistency
            $insert_bill->execute([$customer_name, $customer_phone, $items_json, $subtotal, $gst_rate, $gst_amount, $total]);
            
            $bill_id = $pdo->lastInsertId();
            
            $pdo->commit();
            
            // Redirect to print page
            header("Location: print.php?id=" . $bill_id);
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Bill - Medical Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .search-result-item:hover {
            background-color: #f8f9fa;
        }
        .cart-item {
            transition: all 0.3s ease;
        }
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .debug-panel {
            display:none; /* Hidden by default, can be toggled */
            background: #1f2937;
            color: #f9fafb;
            font-family: monospace;
            font-size: 12px;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .status-success { color: #10b981; }
        .status-error { color: #ef4444; }
        .status-warning { color: #f59e0b; }
        .status-info { color: #3b82f6; }
    </style>
</head>
<body class="bg-gray-100">
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
                    <a href="history.php" class="text-gray-600 hover:text-gray-800">Bill History</a>
                    <a href="../auth/logout.php" class="text-gray-600 hover:text-gray-800">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-6">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Create New Bill</h1>
        
        <!-- Debug Panel -->
        <div class="debug-panel">
            <h3 class="text-green-400 font-bold mb-2">🔧 Debug Panel</h3>
            <div id="debug-info">Click "Run Connection Test" to check database connection...</div>
            <button onclick="runDebugTest()" class="bg-blue-600 text-white px-3 py-1 rounded mt-2 text-sm hover:bg-blue-700">
                Run Connection Test
            </button>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 fade-in">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Medicine Search Section -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">Add Medicines</h2>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Search Medicine</label>
                    <input type="text" 
                           id="medicine-search" 
                           placeholder="Type medicine name..." 
                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <div id="search-results" class="mt-2 max-h-60 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-sm"></div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-600 mb-2">Search Status</label>
                    <div id="search-status" class="text-sm text-gray-500">Ready to search...</div>
                </div>
            </div>
            
            <!-- Shopping Cart Section -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">Shopping Cart</h2>
                <div id="cart-items" class="space-y-3 mb-4 min-h-[200px]">
                    <p class="text-gray-500 text-center py-8">Cart is empty</p>
                </div>
                
                <div class="border-t pt-4">
                    <div class="flex justify-between mb-2">
                        <span class="text-gray-600">Subtotal:</span>
                        <span id="subtotal" class="font-semibold">₹0.00</span>
                    </div>
                    <div class="flex justify-between mb-2">
                        <span class="text-gray-600">GST (<span id="gst-rate-display">18</span>%):</span>
                        <span id="gst-amount" class="font-semibold">₹0.00</span>
                    </div>
                    <div class="flex justify-between font-bold text-lg border-t pt-2">
                        <span>Total:</span>
                        <span id="total" class="text-green-600">₹0.00</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Customer Details & Billing Section -->
        <div class="bg-white p-6 rounded-lg shadow-md mt-6">
            <h2 class="text-xl font-semibold mb-4 text-gray-700">Customer Details & Billing</h2>
            
            <form method="POST" onsubmit="return processBill()">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-2">Customer Name</label>
                        <input type="text" 
                               name="customer_name" 
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-2">Customer Phone</label>
                        <input type="tel" 
                               name="customer_phone" 
                               pattern="[0-9]{10}"
                               class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-600 mb-2">GST Rate (%)</label>
                    <select name="gst_rate" 
                            id="gst-rate" 
                            onchange="updateTotal()" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="0">0% - No GST</option>
                        <option value="5">5% - Essential Items</option>
                        <option value="12">12% - Standard Rate</option>
                        <option value="18" selected>18% - Most Medicines</option>
                        <option value="28">28% - Luxury Items</option>
                    </select>
                </div>
                
                <input type="hidden" name="cart_data" id="cart-data">
                <input type="hidden" name="process_bill" value="1">
                
                <div class="flex space-x-4">
                    <button type="submit" 
                            class="bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 transition-colors font-semibold">
                        Process Bill & Print
                    </button>
                    <button type="button" 
                            onclick="clearCart()" 
                            class="bg-red-600 text-white px-6 py-3 rounded-lg hover:bg-red-700 transition-colors font-semibold">
                        Clear Cart
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let cart = [];
        let searchTimeout;

        // Set search status
        function setSearchStatus(message, type = 'info') {
            const statusDiv = document.getElementById('search-status');
            statusDiv.textContent = message;
            statusDiv.className = `text-sm status-${type}`;
        }

        // Run debug test
        function runDebugTest() {
            const debugDiv = document.getElementById('debug-info');
            debugDiv.innerHTML = '<div class="text-yellow-400">🔄 Testing connection...</div>';
            
            fetch('?debug=1')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'success') {
                        debugDiv.innerHTML = `
                            <div class="status-success">✅ Database Connection: OK</div>
                            <div class="status-success">✅ PDO Available: ${data.pdo_exists}</div>
                            <div class="status-success">✅ Medicines Table: EXISTS</div>
                            <div class="status-success">✅ Medicine_batches Table: EXISTS</div>
                            <div class="status-info">📊 Medicine Types Count: ${data.medicine_record_count}</div>
                            <div class="status-info">📊 Batch Records Count: ${data.batch_record_count}</div>
                            <div class="status-warning">🔍 Medicine Table Columns:</div>
                            <div class="ml-4 text-gray-300">
                                ${data.medicine_columns.map(col => `${col.Field} (${col.Type})`).join('<br>')}
                            </div>
                            <div class="status-warning mt-2">🔍 Batch Table Columns:</div>
                            <div class="ml-4 text-gray-300">
                                ${data.batch_columns.map(col => `${col.Field} (${col.Type})`).join('<br>')}
                            </div>
                            ${data.medicine_sample_data && data.medicine_sample_data.length > 0 ? `
                                <div class="status-warning mt-2">📋 Sample Medicine Data:</div>
                                <div class="ml-4 text-gray-300">
                                    ${data.medicine_sample_data.map(row => `ID: ${row.id}, Name: ${row.name}, Category: ${row.category}`).join('<br>')}
                                </div>
                            ` : '<div class="status-warning">⚠️ No sample medicine data found</div>'}
                            ${data.batch_sample_data && data.batch_sample_data.length > 0 ? `
                                <div class="status-warning mt-2">📋 Sample Batch Data:</div>
                                <div class="ml-4 text-gray-300">
                                    ${data.batch_sample_data.map(row => `Batch ID: ${row.id}, Med ID: ${row.medicine_id}, Batch No: ${row.batch_number}, Qty: ${row.quantity}, Price: ₹${row.selling_price}, Expiry: ${row.expiry_date}`).join('<br>')}
                                </div>
                            ` : '<div class="status-warning">⚠️ No sample batch data found</div>'}
                        `;
                    } else {
                        debugDiv.innerHTML = `
                            <div class="status-error">❌ Error: ${data.message}</div>
                            <div class="status-warning">PDO Exists: ${data.pdo_exists}</div>
                            <div class="status-info">File: ${data.file}</div>
                            <div class="status-info">Line: ${data.line}</div>
                        `;
                    }
                })
                .catch(error => {
                    debugDiv.innerHTML = `
                        <div class="status-error">❌ Connection Test Failed</div>
                        <div class="status-error">Error: ${error.message}</div>
                        <div class="text-gray-300 text-xs mt-2">
                            This usually means:
                            <br>• Database connection failed
                            <br>• config/db.php has errors
                            <br>• Server returned HTML instead of JSON
                        </div>
                    `;
                });
        }

        // Medicine search functionality
        document.getElementById('medicine-search').addEventListener('input', function() {
            const query = this.value.trim();
            
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                document.getElementById('search-results').innerHTML = '';
                setSearchStatus('Ready to search...');
                return;
            }
            
            setSearchStatus('Searching...', 'info');
            
            searchTimeout = setTimeout(() => {
                fetch(`?search=${encodeURIComponent(query)}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        const resultsDiv = document.getElementById('search-results');
                        
                        if (data.status === 'error') {
                            resultsDiv.innerHTML = `
                                <div class="text-red-500 p-3 border border-red-200 rounded">
                                    <p class="font-medium">${data.type || 'Error'}</p>
                                    <p class="text-sm">${data.message}</p>
                                    <p class="text-xs mt-1">Search: "${data.search_term}"</p>
                                </div>
                            `;
                            setSearchStatus(`Search failed: ${data.message}`, 'error');
                            return;
                        }
                        
                        const batches = data.data || []; // Now we receive batches directly
                        
                        if (!Array.isArray(batches) || batches.length === 0) {
                            resultsDiv.innerHTML = '<p class="text-gray-500 p-3">No active medicines or batches found matching your search.</p>';
                            setSearchStatus('No medicines found', 'warning');
                            return;
                        }
                        
                        // Group batches by medicine name for better display
                        const groupedMedicines = batches.reduce((acc, batch) => {
                            if (!acc[batch.medicine_id]) {
                                acc[batch.medicine_id] = {
                                    medicine_id: batch.medicine_id,
                                    medicine_name: batch.medicine_name,
                                    unit: batch.unit,
                                    batches: []
                                };
                            }
                            acc[batch.medicine_id].batches.push(batch);
                            return acc;
                        }, {});

                        let html = '';
                        for (const medId in groupedMedicines) {
                            const medicine = groupedMedicines[medId];
                            html += `
                                <div class="p-3 border-b border-gray-100 bg-gray-50">
                                    <div class="font-bold text-gray-800">${medicine.medicine_name}</div>
                                    <div class="text-xs text-gray-500">${medicine.unit ? `Unit: ${medicine.unit}` : ''}</div>
                                </div>
                            `;
                            medicine.batches.forEach(batch => {
                                const expiryStatus = new Date(batch.expiry_date) < new Date() ? 'Expired' : '';
                                const daysToExpiry = Math.ceil((new Date(batch.expiry_date) - new Date()) / (1000 * 60 * 60 * 24));
                                let expiryText = `Expires: ${batch.expiry_date}`;
                                let expiryClass = 'text-gray-600';

                                if (daysToExpiry < 0) {
                                    expiryText = `Expired ${Math.abs(daysToExpiry)} days ago`;
                                    expiryClass = 'text-red-500 font-semibold';
                                } else if (daysToExpiry <= 30) {
                                    expiryText = `Expires in ${daysToExpiry} days`;
                                    expiryClass = 'text-orange-500 font-semibold';
                                }

                                html += `
                                    <div class="search-result-item p-3 border-b cursor-pointer hover:bg-blue-50 transition-colors" 
                                            onclick="addToCart(
                                                ${batch.medicine_id}, 
                                                '${batch.medicine_name.replace(/'/g, "\\'")}', 
                                                '${batch.unit.replace(/'/g, "\\'")}', 
                                                ${batch.batch_id}, 
                                                '${batch.batch_number.replace(/'/g, "\\'")}', 
                                                ${batch.selling_price}, 
                                                ${batch.batch_quantity}
                                            )">
                                        <div class="flex justify-between items-center">
                                            <div class="text-sm text-gray-700">Batch: <span class="font-medium">${batch.batch_number}</span></div>
                                            <div class="text-sm text-green-600 font-semibold">₹${parseFloat(batch.selling_price).toFixed(2)}</div>
                                        </div>
                                        <div class="flex justify-between items-center text-xs mt-1">
                                            <div class="text-gray-500">Stock: ${batch.batch_quantity}</div>
                                            <div class="${expiryClass}">${expiryText}</div>
                                        </div>
                                    </div>
                                `;
                            });
                        }
                        resultsDiv.innerHTML = html;
                        
                        setSearchStatus(`Found ${batches.length} matching batches`, 'success');
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        document.getElementById('search-results').innerHTML = `
                            <div class="text-red-500 p-3 border border-red-200 rounded">
                                <p class="font-medium">Search Failed</p>
                                <p class="text-sm">${error.message}</p>
                                <p class="text-xs mt-1">Check browser console for details</p>
                            </div>
                        `;
                        setSearchStatus(`Search failed: ${error.message}`, 'error');
                    });
            }, 300); // Debounce search input
        });

        // Add medicine (batch) to cart
        function addToCart(medicineId, medicineName, unit, batchId, batchNumber, sellingPrice, maxQty) {
            const existingItem = cart.find(item => item.batch_id === batchId);
            
            if (existingItem) {
                if (existingItem.quantity < maxQty) {
                    existingItem.quantity++;
                } else {
                    // Using a custom alert for better UI consistency
                    showCustomAlert('Maximum available quantity reached for this batch!');
                    return;
                }
            } else {
                cart.push({
                    medicine_id: medicineId,
                    medicine_name: medicineName,
                    unit: unit,
                    batch_id: batchId,
                    batch_number: batchNumber,
                    selling_price: parseFloat(sellingPrice),
                    quantity: 1,
                    maxQty: maxQty
                });
            }
            
            updateCartDisplay();
            document.getElementById('medicine-search').value = '';
            document.getElementById('search-results').innerHTML = '';
            setSearchStatus('Medicine batch added to cart', 'success');
        }

        // Update cart display
        function updateCartDisplay() {
            const cartDiv = document.getElementById('cart-items');
            
            if (cart.length === 0) {
                cartDiv.innerHTML = '<p class="text-gray-500 text-center py-8">Cart is empty</p>';
                updateTotal();
                return;
            }
            
            cartDiv.innerHTML = cart.map((item, index) => `
                <div class="cart-item flex justify-between items-center p-3 border border-gray-200 rounded-lg bg-gray-50 fade-in">
                    <div class="flex-1">
                        <div class="font-medium text-gray-800">${item.medicine_name}</div>
                        <div class="text-sm text-gray-600">Batch: ${item.batch_number} | ₹${item.selling_price.toFixed(2)} each</div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button onclick="updateQuantity(${index}, -1)" 
                                class="bg-gray-300 hover:bg-gray-400 px-2 py-1 rounded transition-colors">-</button>
                        <span class="px-3 py-1 bg-white border rounded min-w-[40px] text-center">${item.quantity}</span>
                        <button onclick="updateQuantity(${index}, 1)" 
                                class="bg-gray-300 hover:bg-gray-400 px-2 py-1 rounded transition-colors">+</button>
                        <button onclick="removeFromCart(${index})" 
                                class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded ml-2 transition-colors">×</button>
                    </div>
                </div>
            `).join('');
            
            updateTotal();
        }

        // Update item quantity
        function updateQuantity(index, change) {
            const item = cart[index];
            const newQty = item.quantity + change;
            
            if (newQty <= 0) {
                // Using a custom confirmation for better UI consistency
                showCustomConfirm('Remove this item from cart?', () => {
                    cart.splice(index, 1);
                    updateCartDisplay();
                });
            } else if (newQty <= item.maxQty) {
                item.quantity = newQty;
                updateCartDisplay();
            } else {
                showCustomAlert('Maximum available quantity for this batch reached!');
            }
        }

        // Remove item from cart
        function removeFromCart(index) {
            showCustomConfirm('Remove this item from cart?', () => {
                cart.splice(index, 1);
                updateCartDisplay();
            });
        }

        // Update total calculations
        function updateTotal() {
            const subtotal = cart.reduce((sum, item) => sum + (item.selling_price * item.quantity), 0);
            const gstRate = parseFloat(document.getElementById('gst-rate').value) || 0;
            const gstAmount = (subtotal * gstRate) / 100;
            const total = subtotal + gstAmount;
            
            document.getElementById('subtotal').textContent = `₹${subtotal.toFixed(2)}`;
            document.getElementById('gst-rate-display').textContent = gstRate;
            document.getElementById('gst-amount').textContent = `₹${gstAmount.toFixed(2)}`;
            document.getElementById('total').textContent = `₹${total.toFixed(2)}`;
        }

        // Clear cart
        function clearCart() {
            if (cart.length === 0) {
                showCustomAlert('Cart is already empty!');
                return;
            }
            
            showCustomConfirm('Are you sure you want to clear the cart?', () => {
                cart = [];
                updateCartDisplay();
            });
        }

        // Process bill
        function processBill() {
            if (cart.length === 0) {
                showCustomAlert('Cart is empty! Please add some medicines.');
                return false;
            }
            
            const customerName = document.querySelector('input[name="customer_name"]').value.trim();
            const customerPhone = document.querySelector('input[name="customer_phone"]').value.trim();
            
            if (!customerName) {
                showCustomAlert('Please enter customer name.');
                return false;
            }
            
            if (customerPhone && !/^\d{10}$/.test(customerPhone)) {
                showCustomAlert('Please enter a valid 10-digit phone number.');
                return false;
            }
            
            document.getElementById('cart-data').value = JSON.stringify(cart);
            return true;
        }

        // Custom Alert/Confirm Modals (replacing native alert/confirm)
        function showCustomAlert(message) {
            const modalHtml = `
                <div id="custom-alert-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full text-center">
                        <p class="text-lg font-semibold text-gray-800 mb-4">${message}</p>
                        <button onclick="document.getElementById('custom-alert-modal').remove()" 
                                class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                            OK
                        </button>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        }

        function showCustomConfirm(message, onConfirmCallback) {
            const modalHtml = `
                <div id="custom-confirm-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full text-center">
                        <p class="text-lg font-semibold text-gray-800 mb-4">${message}</p>
                        <div class="flex justify-center space-x-4">
                            <button id="confirm-yes" 
                                    class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition duration-200">
                                Yes
                            </button>
                            <button id="confirm-no" 
                                    class="bg-gray-300 text-gray-800 px-4 py-2 rounded-md hover:bg-gray-400 transition duration-200">
                                No
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);

            document.getElementById('confirm-yes').onclick = () => {
                onConfirmCallback();
                document.getElementById('custom-confirm-modal').remove();
            };
            document.getElementById('confirm-no').onclick = () => {
                document.getElementById('custom-confirm-modal').remove();
            };
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateTotal();
            
            // Auto-run debug test
            setTimeout(() => {
                runDebugTest();
            }, 500);
            
            // Click outside to close search results
            document.addEventListener('click', function(e) {
                if (!e.target.closest('#medicine-search') && !e.target.closest('#search-results')) {
                    document.getElementById('search-results').innerHTML = '';
                }
            });
        });
    </script>
</body>
</html>
