<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

try {
    $stmt = $db->query("DESCRIBE users");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('division_id', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN division_id INT NULL");
        echo "Added division_id to users.\n";
    }
    if (!in_array('office_id', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN office_id INT NULL");
        echo "Added office_id to users.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
