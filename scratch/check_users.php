<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$stmt = $db->query("DESCRIBE users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
