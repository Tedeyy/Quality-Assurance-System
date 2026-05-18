<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/responses_database.php';

header('Content-Type: application/json');

$activity_id = $_GET['id'] ?? null;
$email = $_GET['email'] ?? null;

if (!$activity_id || !$email) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

$resp_db_class = new ResponsesDatabase();
$rdb = $resp_db_class->getConnection();

if ($rdb) {
    $table_name = "activity_" . $activity_id;
    try {
        $stmt = $rdb->prepare("SELECT id FROM $table_name WHERE email = :email");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            echo json_encode(['status' => 'duplicate', 'message' => 'You have already sent a response.']);
        } else {
            echo json_encode(['status' => 'unique']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Connection failed']);
}
?>
