<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

try {
    $db->exec("ALTER TABLE accreditation_requirement MODIFY COLUMN codename VARCHAR(50)");
    echo "Column codename modified to VARCHAR(50).\n";
} catch (Exception $e) {
    echo "Error modifying codename: " . $e->getMessage() . "\n";
}
?>
