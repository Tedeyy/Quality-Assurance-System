<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$type = $_GET['type'] ?? '';

try {
    if ($type === 'divisions') {
        $stmt = $db->prepare("SELECT division_id as id, acronym, name FROM divisions ORDER BY name ASC");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results);
    } elseif ($type === 'offices') {
        $division_id = $_GET['division_id'] ?? null;
        if (!$division_id) {
            echo json_encode([]);
            exit;
        }
        $stmt = $db->prepare("SELECT office_id as id, acronym, name FROM divisions_offices WHERE division_id = :div_id ORDER BY name ASC");
        $stmt->execute(['div_id' => $division_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results);
    } else {
        echo json_encode(['error' => 'Invalid type']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
