<?php
require_once 'config.php';

try {
    $pdo->beginTransaction();
    
    // Check if columns already exist
    $stmt = $pdo->query("SHOW COLUMNS FROM subjects LIKE 'class_id'");
    if ($stmt->rowCount() == 0) {
        // Add new columns to subjects table
        $pdo->exec("ALTER TABLE subjects ADD COLUMN class_id INT NULL AFTER name");
        $pdo->exec("ALTER TABLE subjects ADD COLUMN has_written BOOLEAN DEFAULT TRUE AFTER oral_full_marks");
        $pdo->exec("ALTER TABLE subjects ADD COLUMN has_oral BOOLEAN DEFAULT TRUE AFTER has_written");
        $pdo->exec("ALTER TABLE subjects ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER has_oral");
        $pdo->exec("ALTER TABLE subjects ADD COLUMN description TEXT NULL AFTER is_active");
        $pdo->exec("ALTER TABLE subjects ADD COLUMN created_by INT NULL AFTER description");
        $pdo->exec("ALTER TABLE subjects ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by");
        
        // Add foreign key constraints
        $pdo->exec("ALTER TABLE subjects ADD FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL");
        $pdo->exec("ALTER TABLE subjects ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
        
        echo "✅ Subjects table updated successfully!<br>";
    } else {
        echo "ℹ️ Subjects table already has the required columns.<br>";
    }
    
    // Create class_subjects table
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_subjects (
        id INT PRIMARY KEY AUTO_INCREMENT,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        written_full_marks INT DEFAULT 80,
        oral_full_marks INT DEFAULT 20,
        has_written BOOLEAN DEFAULT TRUE,
        has_oral BOOLEAN DEFAULT TRUE,
        is_compulsory BOOLEAN DEFAULT TRUE,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id),
        UNIQUE KEY unique_class_subject (class_id, subject_id)
    )");
    echo "✅ class_subjects table created/verified successfully!<br>";
    
    // Update marks table
    $stmt = $pdo->query("SHOW COLUMNS FROM marks LIKE 'has_written'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE marks ADD COLUMN has_written BOOLEAN DEFAULT TRUE AFTER oral_marks");
        $pdo->exec("ALTER TABLE marks ADD COLUMN has_oral BOOLEAN DEFAULT TRUE AFTER has_written");
        echo "✅ Marks table updated successfully!<br>";
    }
    
    $pdo->commit();
    echo "<br><h3>✅ Database schema updated successfully!</h3>";
    echo '<p><a href="manage_subjects.php" class="btn btn-primary">Go to Manage Subjects</a></p>';
    
} catch (Exception $e) {
    $pdo->rollback();
    echo "<h3>❌ Error updating database:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>
