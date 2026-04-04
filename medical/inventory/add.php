<?php
// inventory/add.php - Add Medicine Page
session_start();
// Ensure admin is logged in, redirect if not
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and retrieve common medicine fields
    $name = trim($_POST['name']);
    $generic_name = trim($_POST['generic_name']);
    $category = trim($_POST['category']);
    $manufacturer = trim($_POST['manufacturer']);
    $description = trim($_POST['description']);
    $unit = trim($_POST['unit']);
    $location = trim($_POST['location']);
    $minimum_stock = (int)$_POST['minimum_stock'];

    // Sanitize and retrieve batch-specific fields
    $batch_number = trim($_POST['batch_number']);
    $quantity = (int)$_POST['quantity'];
    $cost_price = (float)$_POST['cost_price'];
    $selling_price = (float)$_POST['selling_price'];
    $expiry_date = $_POST['expiry_date'];
    
    // Validation
    if (empty($name) || empty($category) || empty($quantity) || empty($cost_price) || empty($selling_price) || empty($expiry_date) || empty($batch_number)) {
        $error = 'Please fill in all required fields (Medicine Name, Category, Batch Number, Quantity, Cost Price, Selling Price, Expiry Date).';
    } elseif ($quantity < 0) {
        $error = 'Quantity cannot be negative.';
    } elseif ($cost_price < 0 || $selling_price < 0) {
        $error = 'Prices cannot be negative.';
    } elseif ($selling_price < $cost_price) {
        $error = 'Selling price should be greater than or equal to cost price.';
    } elseif (strtotime($expiry_date) < time()) {
        $error = 'Expiry date cannot be in the past.';
    } else {
        try {
            $pdo->beginTransaction(); // Start transaction for atomicity

            // 1. Check if the medicine (general info) already exists
            $stmt_check_medicine = $pdo->prepare("SELECT id FROM medicines WHERE name = ?");
            $stmt_check_medicine->execute([$name]);
            $existing_medicine = $stmt_check_medicine->fetch(PDO::FETCH_ASSOC);

            $medicine_id = null;

            if ($existing_medicine) {
                // Medicine name already exists, use its ID
                $medicine_id = $existing_medicine['id'];

                // Optionally, update general medicine details if they can change (e.g., manufacturer, description)
                // For simplicity, we're not updating general medicine details here if it exists.
                // If you want to allow updates to generic_name, category, etc. when adding a new batch,
                // you would add an UPDATE query here.
                // For now, we assume these are set once when the medicine is first added.

            } else {
                // Medicine name does not exist, insert new general medicine details
                $stmt_insert_medicine = $pdo->prepare("INSERT INTO medicines 
                    (name, generic_name, category, manufacturer, description, unit, location, minimum_stock, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                
                $stmt_insert_medicine->execute([
                    $name, $generic_name, $category, $manufacturer, $description, $unit, $location, $minimum_stock
                ]);
                $medicine_id = $pdo->lastInsertId(); // Get the ID of the newly inserted medicine
            }

            // 2. Check if the batch number already exists for this specific medicine
            $stmt_check_batch = $pdo->prepare("SELECT id FROM medicine_batches WHERE medicine_id = ? AND batch_number = ?");
            $stmt_check_batch->execute([$medicine_id, $batch_number]);
            
            if ($stmt_check_batch->rowCount() > 0) {
                // Batch already exists for this medicine, so we should update its quantity, not create a new entry
                // This scenario means the user is adding more stock to an existing batch.
                $existing_batch = $stmt_check_batch->fetch(PDO::FETCH_ASSOC);
                $stmt_update_batch = $pdo->prepare("UPDATE medicine_batches SET quantity = quantity + ?, cost_price = ?, selling_price = ?, expiry_date = ? WHERE id = ?");
                $stmt_update_batch->execute([$quantity, $cost_price, $selling_price, $expiry_date, $existing_batch['id']]);
                $message = 'Existing batch updated successfully! Quantity added to batch ' . htmlspecialchars($batch_number) . '.';
            } else {
                // Batch does not exist, insert new batch details
                $stmt_insert_batch = $pdo->prepare("INSERT INTO medicine_batches 
                    (medicine_id, batch_number, quantity, cost_price, selling_price, expiry_date, received_date) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())");
                
                $stmt_insert_batch->execute([
                    $medicine_id, $batch_number, $quantity, $cost_price, $selling_price, $expiry_date
                ]);
                $message = 'Medicine and batch added successfully!';
                if ($existing_medicine) {
                    $message = 'New batch added successfully for existing medicine: ' . htmlspecialchars($name) . '!';
                }
            }
            
            $pdo->commit(); // Commit the transaction
            
            // Clear form data after successful submission
            $_POST = array();

        } catch (PDOException $e) {
            $pdo->rollBack(); // Rollback on error
            $error = 'Error adding medicine/batch: ' . $e->getMessage();
        }
    }
}

// Get categories and units for dropdowns
$categories = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Cream', 'Ointment', 'Drops', 'Inhaler', 'Powder', 'Other'];
$units = ['Pieces', 'Bottles', 'Boxes', 'Packets', 'Tubes', 'Vials', 'Strips'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Medicine - Medical Management System</title>
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
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">View Inventory</a>
                    <a href="../dashboard/" class="text-gray-600 hover:text-gray-800">Dashboard</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <h2 class="text-3xl font-bold text-gray-800 mb-2">Add New Medicine or Batch</h2>
            <p class="text-gray-600">Fill in the details below to add a new medicine or a new batch to an existing medicine in your inventory</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-green-800"><?php echo htmlspecialchars($message); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <span class="text-red-800"><?php echo htmlspecialchars($error); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Add Medicine Form -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <form method="POST" action="" class="space-y-6">
                <!-- Basic Information -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Medicine Name *</label>
                        <input type="text" id="name" name="name" required
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="generic_name" class="block text-sm font-medium text-gray-700 mb-2">Generic Name</label>
                        <input type="text" id="generic_name" name="generic_name"
                               value="<?php echo htmlspecialchars($_POST['generic_name'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category *</label>
                        <select id="category" name="category" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo (($_POST['category'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                <?php echo $cat; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="manufacturer" class="block text-sm font-medium text-gray-700 mb-2">Manufacturer</label>
                        <input type="text" id="manufacturer" name="manufacturer"
                               value="<?php echo htmlspecialchars($_POST['manufacturer'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <!-- Batch Information -->
                <h3 class="text-lg font-semibold text-gray-800 mt-8 mb-4">Batch Details</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="batch_number" class="block text-sm font-medium text-gray-700 mb-2">Batch Number *</label>
                        <input type="text" id="batch_number" name="batch_number" required
                               value="<?php echo htmlspecialchars($_POST['batch_number'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="quantity" class="block text-sm font-medium text-gray-700 mb-2">Quantity *</label>
                        <input type="number" id="quantity" name="quantity" required min="0"
                               value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="unit" class="block text-sm font-medium text-gray-700 mb-2">Unit</label>
                        <select id="unit" name="unit"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Select Unit</option>
                            <?php foreach ($units as $unit_option): // Renamed $unit to $unit_option to avoid conflict with variable $unit above ?>
                            <option value="<?php echo $unit_option; ?>" <?php echo (($_POST['unit'] ?? '') === $unit_option) ? 'selected' : ''; ?>>
                                <?php echo $unit_option; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Pricing Information for Batch -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="cost_price" class="block text-sm font-medium text-gray-700 mb-2">Cost Price (₹) *</label>
                        <input type="number" id="cost_price" name="cost_price" required min="0" step="0.01"
                               value="<?php echo htmlspecialchars($_POST['cost_price'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="selling_price" class="block text-sm font-medium text-gray-700 mb-2">Selling Price (₹) *</label>
                        <input type="number" id="selling_price" name="selling_price" required min="0" step="0.01"
                               value="<?php echo htmlspecialchars($_POST['selling_price'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <!-- Additional Information for Batch -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="expiry_date" class="block text-sm font-medium text-gray-700 mb-2">Expiry Date *</label>
                        <input type="date" id="expiry_date" name="expiry_date" required
                               value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="minimum_stock" class="block text-sm font-medium text-gray-700 mb-2">Minimum Stock Level</label>
                        <input type="number" id="minimum_stock" name="minimum_stock" min="0"
                               value="<?php echo htmlspecialchars($_POST['minimum_stock'] ?? '10'); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-700 mb-2">Storage Location</label>
                        <input type="text" id="location" name="location"
                               value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>"
                               placeholder="e.g., Shelf A-1, Room 2"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                    <textarea id="description" name="description" rows="3"
                                 class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                 placeholder="Enter medicine description, usage instructions, etc."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <!-- Form Actions -->
                <div class="flex justify-end space-x-4 pt-6">
                    <a href="index.php" class="px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition duration-200">
                        Cancel
                    </a>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-200">
                        Add Medicine/Batch
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Auto-calculate profit margin (remains the same)
        document.getElementById('cost_price').addEventListener('input', calculateProfit);
        document.getElementById('selling_price').addEventListener('input', calculateProfit);
        
        function calculateProfit() {
            const costPrice = parseFloat(document.getElementById('cost_price').value) || 0;
            const sellingPrice = parseFloat(document.getElementById('selling_price').value) || 0;
            
            if (costPrice > 0 && sellingPrice > 0) {
                const profit = ((sellingPrice - costPrice) / costPrice * 100).toFixed(2);
                // You can add a profit margin display here if needed
            }
        }
        
        // Set minimum date for expiry date (today)
        document.getElementById('expiry_date').min = new Date().toISOString().split('T')[0];
    </script>
</body>
</html>
