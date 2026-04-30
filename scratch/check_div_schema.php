<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

echo "Table divisions:\n";
$stmt = $db->query("DESCRIBE divisions");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nTable divisions_offices:\n";
$stmt = $db->query("DESCRIBE divisions_offices");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
