<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Add event_date if it doesn't exist
    try {
        $db->exec("ALTER TABLE news ADD COLUMN event_date DATE NULL AFTER content");
    } catch (Exception $e) {}

    // Remove image_path if it exists
    try {
        $db->exec("ALTER TABLE news DROP COLUMN image_path");
    } catch (Exception $e) {}
    
    echo "News table schema updated.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
