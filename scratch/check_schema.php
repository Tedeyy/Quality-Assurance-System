<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

$table = 'accreditation_requirement';
$stmt = $db->query("DESCRIBE $table");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Table: $table\n";
foreach ($columns as $col) {
    echo "{$col['Field']} - {$col['Type']}\n";
}
?>
