<?php
/**
 * API: Check if an evaluation form is open or closed
 * GET  ?code=<activity_code>
 * Returns JSON: { status: 'Open'|'Closed', activity_id: int }
 */
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$db = (new Database())->getConnection();
$code = $_GET['code'] ?? '';

if (!$code) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing activity_code']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT a.activity_id, ae.published_options 
        FROM activities a
        LEFT JOIN activity_evaluation ae ON a.activity_id = ae.activity_id
        WHERE a.activity_code = :code
    ");
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Activity not found']);
        exit;
    }

    $status = ($row['published_options'] === 'Open') ? 'Open' : 'Closed';
    echo json_encode(['status' => $status, 'activity_id' => $row['activity_id']]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
