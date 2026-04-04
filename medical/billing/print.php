<?php
// 📁 billing/print.php - Print Bill with Error Handling
session_start();

// Ensure user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database configuration
include '../config/db.php';

// --- Data Retrieval and Processing ---

$bill_id = $_GET['id'] ?? null;

// Redirect if no bill ID is provided
if (!$bill_id) {
    header("Location: create.php");
    exit();
}

$bill = null;
$items = [];
$json_decode_error_msg = '';

try {
    // Get bill details using a prepared statement for security
    // Use PDO for database access
    $stmt = $pdo->prepare("SELECT * FROM bills WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database prepare error: " . implode(" ", $pdo->errorInfo()));
    }
    $stmt->execute([$bill_id]);
    $result = $stmt;

    if ($result->rowCount() === 0) {
        // Bill not found, redirect
        header("Location: create.php");
        exit();
    }
    // Fetch the bill as an associative array (PDO)
    $bill = $result->fetch(PDO::FETCH_ASSOC);

    // Decode JSON items with error handling
    $bill_data = json_decode($bill['items'] ?? '[]', true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_decode_error_msg = json_last_error_msg();
        error_log("JSON decode error for bill ID {$bill_id}: " . $json_decode_error_msg);
        $items = []; // Default to empty array on decode error
    } else {
        // Ensure 'items' key exists and is an array. Handle cases where 'items' might be the root.
        if (isset($bill_data['items']) && is_array($bill_data['items'])) {
            $items = $bill_data['items'];
        } elseif (is_array($bill_data)) {
            // If bill_data is an array but 'items' key is missing, assume bill_data itself is the items array
            $items = $bill_data;
        } else {
            $items = []; // Fallback if structure is unexpected
        }
    }

} catch (Exception $e) {
    error_log("Error fetching bill ID {$bill_id}: " . $e->getMessage());
    // In a production environment, you might redirect to an error page or display a user-friendly message
    header("Location: create.php?error=bill_fetch_failed");
    exit();
}

// Assign default values if bill data is incomplete
$bill['customer_name'] = $bill['customer_name'] ?: 'Walk-in Customer';
$bill['customer_phone'] = $bill['customer_phone'] ?: '';
$bill['created_at'] = $bill['created_at'] ?: date('Y-m-d H:i:s');
$bill['subtotal'] = $bill['subtotal'] ?? 0;
$bill['gst_rate'] = $bill['gst_rate'] ?? 0;
$bill['gst_amount'] = $bill['gst_amount'] ?? 0;
$bill['total'] = $bill['total'] ?? 0;

// --- HTML Presentation ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bill #<?php echo htmlspecialchars($bill_id); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            /* Specific styles for thermal print if needed */
            @page thermal { size: 80mm auto; } /* Example for 80mm thermal paper */
            .print-thermal-only { display: none; }
            body.thermal-print #a4-bill { display: none !important; }
            body.thermal-print #thermal-bill { display: block !important; }
        }
        .print-only { display: none; }
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto; /* Allow horizontal scrolling for long JSON */
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="no-print p-4 bg-white shadow-sm">
        <div class="max-w-4xl mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold">Bill Preview</h1>
            <div class="space-x-2">
                <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    🖨️ Print A4
                </button>
                <button onclick="printThermal()" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                    🖨️ Print Thermal
                </button>
                <a href="create.php" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">
                    ← New Bill
                </a>
                <button onclick="toggleDebug()" class="bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700">
                    🔍 Debug
                </button>
            </div>
        </div>
    </div>

    <div id="debug-panel" class="no-print debug-info max-w-4xl mx-auto" style="display: none;">
        <h3><strong>Debug Information:</strong></h3>
        <p><strong>Bill ID:</strong> <?php echo htmlspecialchars($bill_id); ?></p>
        <p><strong>Raw Items JSON:</strong> <pre><?php echo htmlspecialchars($bill['items'] ?? 'N/A'); ?></pre></p>
        <p><strong>JSON Decode Error:</strong> <?php echo htmlspecialchars($json_decode_error_msg ?: 'No error'); ?></p>
        <p><strong>Items Count:</strong> <?php echo count($items); ?></p>
        <p><strong>Bill Data Structure (decoded):</strong></p>
        <pre><?php print_r($bill_data); ?></pre>
    </div>

    <?php if (empty($items)): ?>
        <div class="no-print max-w-4xl mx-auto my-4">
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <strong>Warning:</strong> No items found in this bill. This may be due to:
                <ul class="mt-2 ml-4 list-disc">
                    <li>Corrupted bill data in database</li>
                    <li>Items not properly saved during bill creation</li>
                    <li>JSON format issues</li>
                </ul>
                <p class="mt-2">Please check the debug panel above for more details.</p>
            </div>
        </div>
    <?php endif; ?>

    <div id="a4-bill" class="max-w-4xl mx-auto my-8 bg-white shadow-lg">
        <div class="p-8">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-blue-600">MEDICAL PHARMACY</h1>
                <p class="text-gray-600">123 Health Street, Medical City</p>
                <p class="text-gray-600">Phone: +91 98765 43210 | Email: info@medicalpharmacy.com</p>
                <p class="text-gray-600">GST No: 29ABCDE1234F1Z5</p>
            </div>
            
            <div class="grid grid-cols-2 gap-8 mb-6">
                <div>
                    <h3 class="font-semibold text-gray-700 mb-2">Bill To:</h3>
                    <p class="font-medium"><?php echo htmlspecialchars($bill['customer_name']); ?></p>
                    <?php if ($bill['customer_phone']): ?>
                        <p class="text-gray-600">Phone: <?php echo htmlspecialchars($bill['customer_phone']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <p><strong>Bill No:</strong> #<?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?></p>
                    <p><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($bill['created_at'])); ?></p>
                    <p><strong>Time:</strong> <?php echo date('h:i A', strtotime($bill['created_at'])); ?></p>
                </div>
            </div>
            
            <table class="w-full border-collapse mb-6">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="border p-3 text-left">#</th>
                        <th class="border p-3 text-left">Medicine Name</th>
                        <th class="border p-3 text-center">Qty</th>
                        <th class="border p-3 text-right">Rate</th>
                        <th class="border p-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $index => $item): ?>
                            <tr>
                                <td class="border p-3"><?php echo $index + 1; ?></td>
                                <td class="border p-3"><?php echo htmlspecialchars($item['name'] ?? 'Unknown Item'); ?></td>
                                <td class="border p-3 text-center"><?php echo htmlspecialchars($item['quantity'] ?? 0); ?></td>
                                <td class="border p-3 text-right">₹<?php echo number_format($item['price'] ?? 0, 2); ?></td>
                                <td class="border p-3 text-right">₹<?php echo number_format(($item['quantity'] ?? 0) * ($item['price'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="border p-3 text-center text-red-500">
                                No items found in this bill
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="flex justify-end">
                <div class="w-1/2">
                    <div class="space-y-2">
                        <div class="flex justify-between py-2">
                            <span>Subtotal:</span>
                            <span>₹<?php echo number_format($bill['subtotal'], 2); ?></span>
                        </div>
                        <?php if (($bill['gst_rate'] ?? 0) > 0): ?>
                            <div class="flex justify-between py-2">
                                <span>GST (<?php echo htmlspecialchars($bill['gst_rate']); ?>%):</span>
                                <span>₹<?php echo number_format($bill['gst_amount'], 2); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex justify-between py-2 border-t-2 border-gray-300 font-bold text-lg">
                            <span>Total:</span>
                            <span>₹<?php echo number_format($bill['total'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 pt-4 border-t text-center text-gray-600">
                <p>Thank you for your business!</p>
                <p class="text-sm mt-2">This is a computer-generated bill.</p>
            </div>
        </div>
    </div>

    <div id="thermal-bill" class="hidden max-w-80 mx-auto my-8 bg-white shadow-lg print-thermal-only">
        <div class="p-4 text-sm">
            <div class="text-center mb-4">
                <h1 class="text-lg font-bold">MEDICAL PHARMACY</h1>
                <p class="text-xs">123 Health Street, Medical City</p>
                <p class="text-xs">Phone: +91 98765 43210</p>
                <p class="text-xs">GST: 29ABCDE1234F1Z5</p>
            </div>
            
            <div class="border-t border-dashed my-2"></div>
            
            <div class="mb-3">
                <p><strong>Bill #:</strong> <?php echo str_pad($bill_id, 6, '0', STR_PAD_LEFT); ?></p>
                <p><strong>Date:</strong> <?php echo date('d/m/Y h:i A', strtotime($bill['created_at'])); ?></p>
                <?php if ($bill['customer_name'] && $bill['customer_name'] !== 'Walk-in Customer'): // Only show if not default ?>
                    <p><strong>Customer:</strong> <?php echo htmlspecialchars($bill['customer_name']); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="border-t border-dashed my-2"></div>
            
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $item): ?>
                    <div class="mb-2">
                        <p class="font-medium"><?php echo htmlspecialchars($item['name'] ?? 'Unknown Item'); ?></p>
                        <p class="flex justify-between">
                            <span><?php echo htmlspecialchars($item['quantity'] ?? 0); ?> x ₹<?php echo number_format($item['price'] ?? 0, 2); ?></span>
                            <span>₹<?php echo number_format(($item['quantity'] ?? 0) * ($item['price'] ?? 0), 2); ?></span>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center text-red-500 py-4">
                    No items found
                </div>
            <?php endif; ?>
            
            <div class="border-t border-dashed my-2"></div>
            
            <div class="space-y-1">
                <p class="flex justify-between">
                    <span>Subtotal:</span>
                    <span>₹<?php echo number_format($bill['subtotal'], 2); ?></span>
                </p>
                <?php if (($bill['gst_rate'] ?? 0) > 0): ?>
                    <p class="flex justify-between">
                        <span>GST (<?php echo htmlspecialchars($bill['gst_rate']); ?>%):</span>
                        <span>₹<?php echo number_format($bill['gst_amount'], 2); ?></span>
                    </p>
                <?php endif; ?>
                <p class="flex justify-between font-bold text-base border-t border-dashed pt-1">
                    <span>Total:</span>
                    <span>₹<?php echo number_format($bill['total'], 2); ?></span>
                </p>
            </div>
            
            <div class="border-t border-dashed my-2"></div>
            
            <div class="text-center text-xs">
                <p>Thank you for your business!</p>
                <p>Visit again!</p>
            </div>
        </div>
    </div>

    <script>
        function printThermal() {
            // Add a class to the body to trigger thermal-specific print styles
            document.body.classList.add('thermal-print');
            
            // Initiate print
            window.print();
            
            // Remove the class after print dialog is closed (or immediately, as print is blocking)
            document.body.classList.remove('thermal-print');
        }
        
        function toggleDebug() {
            const debugPanel = document.getElementById('debug-panel');
            debugPanel.style.display = debugPanel.style.display === 'none' ? 'block' : 'none';
        }

        // Initialize state to ensure A4 is shown by default if coming from a non-print action
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('a4-bill').style.display = 'block';
            document.getElementById('thermal-bill').style.display = 'none';
        });
    </script>
</body>
</html>