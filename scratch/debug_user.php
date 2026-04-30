<?php
session_start();
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

$user_id = $_SESSION['user_id'] ?? 0;
$stmt = $db->prepare("SELECT u.fname, u.office_id, o.name as office_name 
                      FROM users u 
                      LEFT JOIN divisions_offices o ON u.office_id = o.office_id 
                      WHERE u.user_id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Session User ID: $user_id\n";
echo "Session Office ID: " . ($_SESSION['user_office_id'] ?? 'NULL') . "\n";
echo "Database Office ID: " . ($user['office_id'] ?? 'NULL') . "\n";
echo "Office Name: " . ($user['office_name'] ?? 'NULL') . "\n";
?>
