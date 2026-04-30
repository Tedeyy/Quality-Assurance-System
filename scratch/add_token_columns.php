<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

try {
    $db->exec("ALTER TABLE users ADD COLUMN google_access_token TEXT");
    echo "Column google_access_token added.\n";
} catch (Exception $e) {
    echo "Error adding google_access_token: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE users ADD COLUMN google_refresh_token TEXT");
    echo "Column google_refresh_token added.\n";
} catch (Exception $e) {
    echo "Error adding google_refresh_token: " . $e->getMessage() . "\n";
}
?>
