<?php
// 📁 billing/export.php - Export Bill History to CSV
session_start();

// Check authentication
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Include database connection
include '../config/db.php';

// Get filter parameters from URL
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$min_amount = $_GET['min_amount'] ?? '';
$max_amount = $_GET['max_amount'] ?? '';

// Build the WHERE clause for filtering
$where_conditions = [];
$params = [];

if (!empty($search_query)) {
    $where_conditions[] = "(customer_name LIKE ? OR customer_phone LIKE ? OR id = ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_query;
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

// Fetch bills based on filters
$query = "SELECT * FROM bills $where_clause ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bills_to_export = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bill_history_' . date('Y-m-d') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV Header Row
fputcsv($output, [
    'Bill ID', 
    'Customer Name', 
    'Customer Phone', 
    'Date', 
    'Time', 
    'Medicine Name', 
    'Batch Number', 
    'Quantity', 
    'Selling Price Per Unit (INR)', 
    'Line Total (INR)', 
    'Subtotal (Bill) (INR)', 
    'GST Rate (%)', 
    'GST Amount (Bill) (INR)', 
    'Total (Bill) (INR)'
]);

// Write CSV Data Rows
foreach ($bills_to_export as $bill) {
    $bill_id = $bill['id'];
    $customer_name = $bill['customer_name'] ?: 'N/A';
    $customer_phone = $bill['customer_phone'] ?: 'N/A';
    $created_date = date('Y-m-d', strtotime($bill['created_at']));
    $created_time = date('H:i:s', strtotime($bill['created_at']));
    $bill_subtotal = number_format($bill['subtotal'], 2, '.', '');
    $bill_gst_rate = $bill['gst_rate'];
    $bill_gst_amount = number_format($bill['gst_amount'], 2, '.', '');
    $bill_total = number_format($bill['total'], 2, '.', '');

    // Decode items JSON for the current bill
    $decoded_items_data = json_decode($bill['items'], true);
    $items_array = [];
    if (json_last_error() === JSON_ERROR_NONE && isset($decoded_items_data['items']) && is_array($decoded_items_data['items'])) {
        $items_array = $decoded_items_data['items'];
    } else if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_items_data)) {
        $items_array = $decoded_items_data; // Fallback for older bills
    }

    if (empty($items_array)) {
        // If no items, write a row with bill details and empty item details
        fputcsv($output, [
            $bill_id, 
            $customer_name, 
            $customer_phone, 
            $created_date, 
            $created_time, 
            'No Items', '', '', '', '', 
            $bill_subtotal, 
            $bill_gst_rate, 
            $bill_gst_amount, 
            $bill_total
        ]);
    } else {
        // For each item in the bill, write a separate row
        foreach ($items_array as $item) {
            $medicine_name = $item['medicine_name'] ?? $item['name'] ?? 'Unknown Medicine';
            $batch_number = $item['batch_number'] ?? 'N/A';
            $quantity = $item['quantity'] ?? 0;
            $selling_price = number_format($item['selling_price'] ?? $item['price'] ?? 0, 2, '.', '');
            $line_total = number_format($item['total'] ?? ($quantity * ($item['selling_price'] ?? $item['price'] ?? 0)), 2, '.', '');

            fputcsv($output, [
                $bill_id, 
                $customer_name, 
                $customer_phone, 
                $created_date, 
                $created_time, 
                $medicine_name, 
                $batch_number, 
                $quantity, 
                $selling_price, 
                $line_total, 
                $bill_subtotal, 
                $bill_gst_rate, 
                $bill_gst_amount, 
                $bill_total
            ]);
        }
    }
}

// Close the output stream
fclose($output);
exit(); // Terminate script after file generation
?>
