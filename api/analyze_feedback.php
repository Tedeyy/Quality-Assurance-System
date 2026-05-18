<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/responses_database.php';
require_once __DIR__ . '/ai_service.php';

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
    $database = new Database();
    $db = $database->getConnection();

    // 1. Fetch Evaluation ID
    $stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :id");
    $stmt->execute([':id' => $activity_id]);
    $eval = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$eval) {
        echo json_encode(['success' => false, 'error' => 'Evaluation not found for this activity']);
        exit;
    }
    $evaluation_id = $eval['evaluation_id'];

    // 2. Fetch Raw Responses
    $resp_db = (new ResponsesDatabase())->getConnection();
    if (!$resp_db) {
        echo json_encode(['success' => false, 'error' => 'Could not connect to responses database']);
        exit;
    }

    $table_name = "activity_" . (int)$activity_id;
    $resp_stmt = $resp_db->query("SELECT best_topics, improvements FROM $table_name");
    $responses = $resp_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($responses)) {
        echo json_encode(['success' => false, 'error' => 'No responses found to analyze']);
        exit;
    }

    // 3. Call AI Service
    $ai = new AIService();
    $interpretation = $ai->interpretFeedback($responses);

    if (isset($interpretation['error'])) {
        echo json_encode(['success' => false, 'error' => $interpretation['error']]);
        exit;
    }

    // 4. Update Database
    $update_stmt = $db->prepare("UPDATE activity_evaluation SET complaints = :complaints, suggestions_for_improvement = :suggestions WHERE evaluation_id = :id");
    $update_stmt->execute([
        ':complaints' => $interpretation['complaints'],
        ':suggestions' => $interpretation['suggestions'],
        ':id' => $evaluation_id
    ]);

    echo json_encode([
        'success' => true,
        'complaints' => $interpretation['complaints'],
        'suggestions' => $interpretation['suggestions']
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
