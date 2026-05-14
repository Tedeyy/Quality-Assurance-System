<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/responses_database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$activity_id = $_GET['id'] ?? null;
if (!$activity_id) {
    echo json_encode(['success' => false, 'error' => 'Missing Activity ID']);
    exit;
}

try {
    $resp_db = (new ResponsesDatabase())->getConnection();
    if (!$resp_db) {
        echo json_encode(['success' => false, 'error' => 'Could not connect to responses database']);
        exit;
    }

    $table_name = "activity_" . (int)$activity_id;
    
    // Check if table exists
    $check = $resp_db->query("SHOW TABLES LIKE '$table_name'");
    if ($check->rowCount() == 0) {
        echo json_encode(['success' => true, 'responses' => []]);
        exit;
    }

    $resp_stmt = $resp_db->query("SELECT fullname, email, best_topics, improvements FROM $table_name ORDER BY id DESC");
    $responses = $resp_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'responses' => $responses]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
