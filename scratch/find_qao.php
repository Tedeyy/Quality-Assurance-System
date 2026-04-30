<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("SELECT office_id, name FROM divisions_offices WHERE name LIKE '%Quality Assurance%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
