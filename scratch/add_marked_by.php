<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

try {
    $db->exec("ALTER TABLE accreditation_requirement_submissions ADD COLUMN marked_by INT NULL AFTER status");
    $db->exec("ALTER TABLE accreditation_requirement_submissions ADD FOREIGN KEY (marked_by) REFERENCES users(user_id)");
    echo "Added marked_by column to accreditation_requirement_submissions.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
