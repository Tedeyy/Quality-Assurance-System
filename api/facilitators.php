<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = (new Database())->getConnection();
$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $speakers = $db->query("SELECT name FROM speakers ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    $organizers = $db->query("SELECT name FROM organizers ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['speakers' => $speakers, 'organizers' => $organizers]);
}
?>
