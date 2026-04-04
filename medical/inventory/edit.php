<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

// Get the table and ID from URL parameters
$table = $_GET['table'] ?? '';
$id = $_GET['id'] ?? '';

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $table = $_POST['table'];
    $id = $_POST['id'];
    
    try {
        if ($table === 'medicines') {
            // Updated to reflect the new 'medicines' table structure
            // This form will now only edit general medicine details, not batch details.
            $name = trim($_POST['name']);
            $generic_name = trim($_POST['generic_name']);
            $category = trim($_POST['category']);
            $manufacturer = trim($_POST['manufacturer']);
            $description = trim($_POST['description']);
            $unit = trim($_POST['unit']);
            $location = trim($_POST['location']);
            $minimum_stock = (int)$_POST['minimum_stock'];

            // Basic validation for general medicine details
            if (empty($name) || empty($category)) {
                $error = 'Medicine Name and Category are required.';
            } elseif ($minimum_stock < 0) {
                $error = 'Minimum stock cannot be negative.';
            } else {
                $stmt = $pdo->prepare("UPDATE medicines SET 
                    name = ?, generic_name = ?, category = ?, manufacturer = ?, 
                    description = ?, unit = ?, location = ?, minimum_stock = ? 
                    WHERE id = ?");
                $stmt->execute([
                    $name, $generic_name, $category, $manufacturer, 
                    $description, $unit, $location, $minimum_stock, $id
                ]);
                $message = "Medicine general details updated successfully!";
            }

        } elseif ($table === 'bills') {
            $stmt = $pdo->prepare("UPDATE bills SET customer_name = ?, customer_phone = ?, items = ?, subtotal = ?, gst_rate = ?, gst_amount = ?, total = ? WHERE id = ?");
            $stmt->execute([
                $_POST['customer_name'],
                $_POST['customer_phone'],
                $_POST['items'],
                $_POST['subtotal'],
                $_POST['gst_rate'],
                $_POST['gst_amount'],
                $_POST['total'],
                $id
            ]);
            $message = "Bill record updated successfully!";
        } elseif ($table === 'admins') {
            if (!empty($_POST['password'])) {
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET username = ?, password = ? WHERE id = ?");
                $stmt->execute([$_POST['username'], $hashed_password, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE admins SET username = ? WHERE id = ?");
                $stmt->execute([$_POST['username'], $id]);
            }
            $message = "Admin record updated successfully!";
        } else {
            $error = "Invalid table specified for editing.";
        }
    } catch(PDOException $e) {
        $error = "Error updating record: " . $e->getMessage();
    }
}

// Fetch record data
$record = null;
if ($table && $id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record) {
            $error = "Record not found!";
        }
    } catch(PDOException $e) {
        $error = "Error fetching record: " . $e->getMessage();
    }
}

// Get categories and units for dropdowns (only if editing medicines)
$categories = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Cream', 'Ointment', 'Drops', 'Inhaler', 'Powder', 'Other'];
$units = ['Pieces', 'Bottles', 'Boxes', 'Packets', 'Tubes', 'Vials', 'Strips'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Record - Medical Management System</title>
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
                    <a href="../dashboard/" class="text-gray-600 hover:text-gray-800">Dashboard</a>
                    <a href="../inventory/index.php" class="text-gray-600 hover:text-gray-800">Medicines</a>
                    <a href="../billing/history.php" class="text-gray-600 hover:text-gray-800">Bills</a>
                    <a href="../reports/index.php" class="text-gray-600 hover:text-gray-800">Reports</a>
                    <a href="../auth/logout.php" class="text-gray-600 hover:text-gray-800">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <!-- Page Header -->
        <div class="mb-8">
            <a href="javascript:history.back()" class="text-blue-600 hover:text-blue-800 mb-4 inline-block">
                <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back
            </a>
            <h2 class="text-3xl font-bold text-gray-800 mb-2">Edit <?php echo ucfirst($table); ?> Record</h2>
            <p class="text-gray-600">Update the details below to modify this record</p>
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

        <!-- Edit Form -->
        <?php if ($record): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="table" value="<?php echo htmlspecialchars($table); ?>">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">
                
                <?php if ($table === 'medicines'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Medicine Name *</label>
                            <input type="text" id="name" name="name" required 
                                   value="<?php echo htmlspecialchars($record['name']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label for="generic_name" class="block text-sm font-medium text-gray-700 mb-2">Generic Name</label>
                            <input type="text" id="generic_name" name="generic_name" 
                                   value="<?php echo htmlspecialchars($record['generic_name'] ?? ''); ?>"
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
                                <option value="<?php echo $cat; ?>" <?php echo (($record['category'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                    <?php echo $cat; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="manufacturer" class="block text-sm font-medium text-gray-700 mb-2">Manufacturer</label>
                            <input type="text" id="manufacturer" name="manufacturer" 
                                   value="<?php echo htmlspecialchars($record['manufacturer'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="unit" class="block text-sm font-medium text-gray-700 mb-2">Unit</label>
                            <select id="unit" name="unit" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Select Unit</option>
                                <?php foreach ($units as $unit_option): ?>
                                <option value="<?php echo $unit_option; ?>" <?php echo (($record['unit'] ?? '') === $unit_option) ? 'selected' : ''; ?>>
                                    <?php echo $unit_option; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="location" class="block text-sm font-medium text-gray-700 mb-2">Storage Location</label>
                            <input type="text" id="location" name="location" 
                                   value="<?php echo htmlspecialchars($record['location'] ?? ''); ?>"
                                   placeholder="e.g., Shelf A-1, Room 2"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>

                    <div>
                        <label for="minimum_stock" class="block text-sm font-medium text-gray-700 mb-2">Minimum Stock Level</label>
                        <input type="number" id="minimum_stock" name="minimum_stock" min="0" 
                               value="<?php echo htmlspecialchars($record['minimum_stock'] ?? '0'); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea id="description" name="description" rows="3" 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                  placeholder="Enter medicine description, usage instructions, etc."><?php echo htmlspecialchars($record['description'] ?? ''); ?></textarea>
                    </div>

                <?php elseif ($table === 'bills'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="customer_name" class="block text-sm font-medium text-gray-700 mb-2">Customer Name *</label>
                            <input type="text" id="customer_name" name="customer_name" required
                                   value="<?php echo htmlspecialchars($record['customer_name']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label for="customer_phone" class="block text-sm font-medium text-gray-700 mb-2">Customer Phone *</label>
                            <input type="tel" id="customer_phone" name="customer_phone" required
                                   value="<?php echo htmlspecialchars($record['customer_phone']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div>
                        <label for="items" class="block text-sm font-medium text-gray-700 mb-2">Items (JSON format) *</label>
                        <textarea id="items" name="items" required rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                  placeholder="Enter items in JSON format"><?php echo htmlspecialchars($record['items']); ?></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="subtotal" class="block text-sm font-medium text-gray-700 mb-2">Subtotal (₹) *</label>
                            <input type="number" id="subtotal" name="subtotal" required min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($record['subtotal']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label for="gst_rate" class="block text-sm font-medium text-gray-700 mb-2">GST Rate (%) *</label>
                            <input type="number" id="gst_rate" name="gst_rate" required min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($record['gst_rate']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label for="gst_amount" class="block text-sm font-medium text-gray-700 mb-2">GST Amount (₹) *</label>
                            <input type="number" id="gst_amount" name="gst_amount" required min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($record['gst_amount']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="total" class="block text-sm font-medium text-gray-700 mb-2">Total (₹) *</label>
                            <input type="number" id="total" name="total" required min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($record['total']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                
                <?php elseif ($table === 'admins'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
                            <input type="text" id="username" name="username" required
                                   value="<?php echo htmlspecialchars($record['username']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                            <input type="password" id="password" name="password"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                   placeholder="Leave blank to keep current password">
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Form Actions -->
                <div class="flex justify-end space-x-4 pt-6">
                    <a href="javascript:history.back()" class="px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition duration-200">
                        Cancel
                    </a>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-200">
                        Update Record
                    </button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="text-center py-8">
                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 12h6m-6-4h6m2 5.291A7.962 7.962 0 0112 15c-2.34 0-4.29-1.01-5.824-2.562M15 6.306a7.962 7.962 0 00-6 0M15 6.306V6a3 3 0 00-3-3 3 3 0 00-3 3v.306"></path>
                </svg>
                <h3 class="text-lg font-medium text-gray-800 mb-2">Record Not Found</h3>
                <p class="text-gray-600">The record you're looking for doesn't exist or invalid parameters were provided.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-calculate GST and total for bills (remains the same)
        if (document.getElementById('subtotal') && document.getElementById('gst_rate')) {
            const subtotal = document.getElementById('subtotal');
            const gstRate = document.getElementById('gst_rate');
            const gstAmount = document.getElementById('gst_amount');
            const total = document.getElementById('total');
            
            function calculateGST() {
                const subtotalValue = parseFloat(subtotal.value) || 0;
                const gstRateValue = parseFloat(gstRate.value) || 0;
                const gstAmountValue = (subtotalValue * gstRateValue) / 100;
                const totalValue = subtotalValue + gstAmountValue;
                
                gstAmount.value = gstAmountValue.toFixed(2);
                total.value = totalValue.toFixed(2);
            }
            
            subtotal.addEventListener('input', calculateGST);
            gstRate.addEventListener('input', calculateGST);
        }
        
        // Format JSON in items textarea (remains the same)
        const itemsTextarea = document.getElementById('items');
        if (itemsTextarea) {
            itemsTextarea.addEventListener('blur', function() {
                try {
                    const json = JSON.parse(this.value);
                    this.value = JSON.stringify(json, null, 2);
                } catch (e) {
                    // Invalid JSON, leave as is
                }
            });
        }
        
        // Removed expiry date min date setting as it's no longer on this form for medicines
    </script>
</body>
</html>
