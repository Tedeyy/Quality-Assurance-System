<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

// 1. Create submissions table
$db->exec("CREATE TABLE IF NOT EXISTS accreditation_requirement_submissions (
    submission_id INT AUTO_INCREMENT PRIMARY KEY,
    requirement_id INT NOT NULL,
    user_id INT NOT NULL,
    google_drive_file_id VARCHAR(255) NOT NULL,
    google_drive_link TEXT,
    status ENUM('Pending', 'Approved', 'Disapproved') DEFAULT 'Pending',
    remarks TEXT,
    division VARCHAR(100),
    office VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

echo "Table accreditation_requirement_submissions created/verified.\n";

// 2. Check users table for division/office columns
$stmt = $db->query("DESCRIBE users");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('division', $cols)) {
    $db->exec("ALTER TABLE users ADD COLUMN division VARCHAR(100)");
    echo "Added division column to users.\n";
}
if (!in_array('office', $cols)) {
    $db->exec("ALTER TABLE users ADD COLUMN office VARCHAR(100)");
    echo "Added office column to users.\n";
}
?>
