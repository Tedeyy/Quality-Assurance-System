<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

try {
    // Drop the old columns and table to recreate correctly as per user request
    $db->exec("DROP TABLE IF EXISTS accreditation_requirement_submissions");
    
    $db->exec("CREATE TABLE accreditation_requirement_submissions (
        submission_id INT AUTO_INCREMENT PRIMARY KEY,
        requirement_id INT NOT NULL,
        user_id INT NOT NULL,
        division_id INT NULL,
        office_id INT NULL,
        google_drive_file_id VARCHAR(255) NOT NULL,
        google_drive_link TEXT,
        status ENUM('Pending', 'Disapproved', 'Approved') DEFAULT 'Pending',
        remarks TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE (requirement_id)
    )");
    
    echo "Table accreditation_requirement_submissions updated successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
