<?php
// inventory/edit_batch.php - Edit Medicine Batch Details
session_start();
// Ensure admin is logged in, redirect if not
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

$message = '';
$error = '';
$batch = null;
$medicine = null;
$batch_id = $_GET['id'] ?? null;

// Fetch batch data
if ($batch_id && is_numeric($batch_id)) {
    try {
        $stmt_batch = $pdo->prepare("SELECT * FROM medicine_batches WHERE id = ?");
        $stmt_batch->execute([$batch_id]);
        $batch = $stmt_batch->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            $error = "Medicine batch not found!";
        } else {
            // Fetch associated medicine details for display (e.g., medicine name, unit)
            $stmt_medicine = $pdo->prepare("SELECT id, name, unit FROM medicines WHERE id = ?");
            $stmt_medicine->execute([$batch['medicine_id']]);
            $medicine = $stmt_medicine->fetch(PDO::FETCH_ASSOC);

            if (!$medicine) {
                $error = "Associated medicine not found for this batch!";
                // Consider what to do if medicine is missing (e.g., delete orphaned batch or alert)
            }
        }
    } catch(PDOException $e) {
        $error = "Error fetching batch data: " . $e->getMessage();
    }
} else {
    $error = "Invalid batch ID provided.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $batch) {
    $updated_batch_number = trim($_POST['batch_number']);
    $updated_quantity = (int)$_POST['quantity'];
    $updated_cost_price = (float)$_POST['cost_price'];
    $updated_selling_price = (float)$_POST['selling_price'];
    $updated_expiry_date = $_POST['expiry_date'];

    // Validation
    if (empty($updated_batch_number) || empty($updated_quantity) || empty($updated_cost_price) || empty($updated_selling_price) || empty($updated_expiry_date)) {
        $error = 'Please fill in all required fields.';
    } elseif ($updated_quantity < 0) {
        $error = 'Quantity cannot be negative.';
    } elseif ($updated_cost_price < 0 || $updated_selling_price < 0) {
        $error = 'Prices cannot be negative.';
    } elseif ($updated_selling_price < $updated_cost_price) {
        $error = 'Selling price should be greater than or equal to cost price.';
    } elseif (strtotime($updated_expiry_date) < time() && $updated_quantity > 0) {
        // Allow editing of past expiry date if quantity is 0 (e.g., for record-keeping of old batches)
        // But if quantity > 0, it should not be in the past.
        $error = 'Expiry date cannot be in the past for active stock.';
    } else {
        try {
            // Check for duplicate batch number for the same medicine, excluding the current batch being edited
            $stmt_check_duplicate_batch = $pdo->prepare("SELECT id FROM medicine_batches WHERE medicine_id = ? AND batch_number = ? AND id != ?");
            $stmt_check_duplicate_batch->execute([$batch['medicine_id'], $updated_batch_number, $batch_id]);
            
            if ($stmt_check_duplicate_batch->rowCount() > 0) {
                $error = 'Another batch with this batch number already exists for this medicine.';
            } else {
                $stmt_update_batch = $pdo->prepare("UPDATE medicine_batches SET 
                    batch_number = ?, quantity = ?, cost_price = ?, selling_price = ?, expiry_date = ? 
                    WHERE id = ?");
                $stmt_update_batch->execute([
                    $updated_batch_number, $updated_quantity, $updated_cost_price, 
                    $updated_selling_price, $updated_expiry_date, $batch_id
                ]);
                $message = "Batch details updated successfully!";
                // Re-fetch the updated batch details to display
                $stmt_batch->execute([$batch_id]);
                $batch = $stmt_batch->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $error = "Error updating batch: " . $e->getMessage();
        }
    }
}

// If there was an error fetching the batch initially, or after submission,
// we might not have a $batch object to display.
if (!$batch && !$error) {
    $error = "No batch data to display. Please check the URL.";
}

// Get categories for dropdown (not directly used here, but good practice to have if needed for future expansion)
$categories = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Cream', 'Ointment', 'Drops', 'Inhaler', 'Powder', 'Other'];
$units = ['Pieces', 'Bottles', 'Boxes', 'Packets', 'Tubes', 'Vials', 'Strips'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Medicine Batch - Medical Management System</title>
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
                    <a href="index.php" class="text-gray-600 hover:text-gray-800">Inventory</a>
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
            <h2 class="text-3xl font-bold text-gray-800 mb-2">Edit Medicine Batch</h2>
            <?php if ($medicine): ?>
                <p class="text-gray-600">Editing batch for: <span class="font-semibold"><?php echo htmlspecialchars($medicine['name']); ?></span></p>
            <?php endif; ?>
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

        <!-- Edit Batch Form -->
        <?php if ($batch && $medicine): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($batch['id']); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="batch_number" class="block text-sm font-medium text-gray-700 mb-2">Batch Number *</label>
                        <input type="text" id="batch_number" name="batch_number" required 
                               value="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="quantity" class="block text-sm font-medium text-gray-700 mb-2">Quantity *</label>
                        <input type="number" id="quantity" name="quantity" required min="0"
                               value="<?php echo htmlspecialchars($batch['quantity']); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <?php if ($medicine['unit']): ?>
                            <p class="text-xs text-gray-500 mt-1">Unit: <?php echo htmlspecialchars($medicine['unit']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="cost_price" class="block text-sm font-medium text-gray-700 mb-2">Cost Price (₹) *</label>
                        <input type="number" id="cost_price" name="cost_price" required min="0" step="0.01"
                               value="<?php echo htmlspecialchars($batch['cost_price']); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label for="selling_price" class="block text-sm font-medium text-gray-700 mb-2">Selling Price (₹) *</label>
                        <input type="number" id="selling_price" name="selling_price" required min="0" step="0.01"
                               value="<?php echo htmlspecialchars($batch['selling_price']); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <div>
                    <label for="expiry_date" class="block text-sm font-medium text-gray-700 mb-2">Expiry Date *</label>
                    <input type="date" id="expiry_date" name="expiry_date" required
                           value="<?php echo htmlspecialchars($batch['expiry_date']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <!-- Form Actions -->
                <div class="flex justify-end space-x-4 pt-6">
                    <a href="view.php?id=<?php echo htmlspecialchars($batch['medicine_id']); ?>" class="px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition duration-200">
                        Cancel
                    </a>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-200">
                        Update Batch
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
                <h3 class="text-lg font-medium text-gray-800 mb-2">Batch Not Found</h3>
                <p class="text-gray-600">The medicine batch you're looking for doesn't exist or invalid parameters were provided.</p>
            </div>
        </div>
        <?php endif; ?>
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
