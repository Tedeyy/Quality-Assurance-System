<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$activity_id = $_POST['activity_id'] ?? null;
$complaints  = trim($_POST['complaints'] ?? '');
$suggestions = trim($_POST['suggestions'] ?? '');

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

    // Fetch Evaluation ID
    $stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :id");
    $stmt->execute([':id' => $activity_id]);
    $eval = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$eval) {
        echo json_encode(['success' => false, 'error' => 'Evaluation not found']);
        exit;
    }
    $evaluation_id = $eval['evaluation_id'];

    // Delete existing monitoring entries for this evaluation before re-saving
    $db->prepare("DELETE FROM activity_evaluation_monitoring WHERE evaluation_id = ?")->execute([$evaluation_id]);

    // Reset links on the evaluation row
    $db->prepare("UPDATE activity_evaluation SET complaint_id = NULL, suggestion_id = NULL WHERE evaluation_id = ?")
       ->execute([$evaluation_id]);

    if ($complaints !== '') {
        $feedback_id = generateFeedbackId($db);
        $db->prepare("INSERT INTO activity_evaluation_monitoring 
            (feedback_id, evaluation_id, complaints, tag) 
            VALUES (?, ?, ?, 'Complaint')")
           ->execute([$feedback_id, $evaluation_id, $complaints]);
        $db->prepare("UPDATE activity_evaluation SET complaint_id = ? WHERE evaluation_id = ?")
           ->execute([$feedback_id, $evaluation_id]);
    }

    if ($suggestions !== '') {
        $feedback_id = generateFeedbackId($db);
        $db->prepare("INSERT INTO activity_evaluation_monitoring 
            (feedback_id, evaluation_id, suggestions_for_improvement, tag) 
            VALUES (?, ?, ?, 'Suggestions')")
           ->execute([$feedback_id, $evaluation_id, $suggestions]);
        $db->prepare("UPDATE activity_evaluation SET suggestion_id = ? WHERE evaluation_id = ?")
           ->execute([$feedback_id, $evaluation_id]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
