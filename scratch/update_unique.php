<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

try {
    $db->exec("ALTER TABLE accreditation_requirement_submissions ADD UNIQUE (requirement_id)");
    echo "Added UNIQUE constraint to requirement_id.\n";
} catch (Exception $e) {
    echo "Note: " . $e->getMessage() . "\n";
}
?>
