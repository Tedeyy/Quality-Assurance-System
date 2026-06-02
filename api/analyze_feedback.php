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

function generateFeedbackId(PDO $db): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    do {
        $id = '';
        for ($i = 0; $i < 6; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $check = $db->prepare("SELECT 1 FROM activity_evaluation_monitoring WHERE feedback_id = ?");
        $check->execute([$id]);
    } while ($check->fetch());
    return $id;
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

    $complaints  = trim($interpretation['complaints'] ?? '');
    $suggestions = trim($interpretation['suggestions'] ?? '');

    // 4. Upsert into activity_evaluation_monitoring
    // Delete existing entries for this evaluation first to avoid duplicates on re-analyze
    $db->prepare("DELETE FROM activity_evaluation_monitoring WHERE evaluation_id = ?")->execute([$evaluation_id]);

    $inserted_complaint_id  = null;
    $inserted_suggestion_id = null;

    if ($complaints !== '') {
        $feedback_id = generateFeedbackId($db);
        $ins = $db->prepare("INSERT INTO activity_evaluation_monitoring 
            (feedback_id, evaluation_id, complaints, tag)
            VALUES (?, ?, ?, 'Complaint')");
        $ins->execute([$feedback_id, $evaluation_id, $complaints]);
        $inserted_complaint_id = $feedback_id;

        // Link back to activity_evaluation
        $db->prepare("UPDATE activity_evaluation SET complaint_id = ? WHERE evaluation_id = ?")
           ->execute([$feedback_id, $evaluation_id]);
    }

    if ($suggestions !== '') {
        $feedback_id = generateFeedbackId($db);
        $ins = $db->prepare("INSERT INTO activity_evaluation_monitoring 
            (feedback_id, evaluation_id, suggestions_for_improvement, tag)
            VALUES (?, ?, ?, 'Suggestions')");
        $ins->execute([$feedback_id, $evaluation_id, $suggestions]);
        $inserted_suggestion_id = $feedback_id;

        // Link back to activity_evaluation
        $db->prepare("UPDATE activity_evaluation SET suggestion_id = ? WHERE evaluation_id = ?")
           ->execute([$feedback_id, $evaluation_id]);
    }

    echo json_encode([
        'success'     => true,
        'complaints'  => $complaints,
        'suggestions' => $suggestions,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
