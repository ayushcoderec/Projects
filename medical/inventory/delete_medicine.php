<?php
// inventory/delete_medicine.php - Delete Medicine (and all its batches)
session_start();
// Ensure admin is logged in, redirect if not
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

$medicine_id = $_GET['id'] ?? null;

$message = '';
$error = '';

// Fetch medicine details for confirmation
$medicine_to_delete = null;
if ($medicine_id && is_numeric($medicine_id)) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM medicines WHERE id = ?");
        $stmt->execute([$medicine_id]);
        $medicine_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$medicine_to_delete) {
            $error = "Medicine not found.";
        }
    } catch (PDOException $e) {
        $error = "Error fetching medicine details: " . $e->getMessage();
    }
} else {
    $error = "Invalid medicine ID provided.";
}

// Handle deletion confirmation
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes' && $medicine_to_delete) {
    try {
        $pdo->beginTransaction(); // Start transaction

        // Due to ON DELETE CASCADE on medicine_batches, deleting from 'medicines' will automatically
        // delete all associated batches.
        $stmt_delete = $pdo->prepare("DELETE FROM medicines WHERE id = ?");
        $stmt_delete->execute([$medicine_id]);
        
        $pdo->commit(); // Commit the transaction
        
        $message = "Medicine '" . htmlspecialchars($medicine_to_delete['name']) . "' and all its batches deleted successfully!";
        header("Location: index.php?success=" . urlencode($message));
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack(); // Rollback on error
        $error = "Error deleting medicine: " . $e->getMessage();
        header("Location: index.php?error=" . urlencode($error));
        exit();
    }
}

// If no medicine was found or an error occurred during initial fetch, redirect
if (!$medicine_to_delete && !$error) {
    header("Location: index.php?error=" . urlencode("No medicine specified for deletion or medicine not found."));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Medicine - Medical Management System</title>
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

    <div class="max-w-lg mx-auto px-4 py-8">
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

        <?php if ($medicine_to_delete): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-2xl font-bold mb-4 text-red-600">Delete Medicine</h2>
            
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded">
                <p class="text-red-800">
                    <strong>Warning:</strong> Are you sure you want to delete the medicine "<?php echo htmlspecialchars($medicine_to_delete['name']); ?>" and <strong>ALL</strong> its associated batches? This action cannot be undone.
                </p>
            </div>
            
            <div class="flex space-x-4">
                <a href="delete_medicine.php?id=<?php echo htmlspecialchars($medicine_id); ?>&confirm=yes" 
                   class="bg-red-600 text-white px-6 py-2 rounded-md hover:bg-red-700 transition duration-200">
                    Yes, Delete Medicine
                </a>
                <a href="view.php?id=<?php echo htmlspecialchars($medicine_id); ?>" 
                   class="bg-gray-600 text-white px-6 py-2 rounded-md hover:bg-gray-700 transition duration-200">
                    Cancel
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="text-center py-8">
                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 12h6m-6-4h6m2 5.291A7.962 7.962 0 0112 15c-2.34 0-4.29-1.01-5.824-2.562M15 6.306a7.962 7.962 0 00-6 0M15 6.306V6a3 3 0 00-3-3 3 3 0 00-3 3v.306"></path>
                </svg>
                <h3 class="text-lg font-medium text-gray-800 mb-2">Operation Failed</h3>
                <p class="text-gray-600">The medicine could not be found or an error occurred. Please try again.</p>
                <a href="index.php" class="mt-4 inline-block bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition duration-200">
                    Back to Inventory
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
