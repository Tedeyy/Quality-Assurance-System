<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$activity_id = $_POST['activity_id'] ?? null;
$complaints = $_POST['complaints'] ?? '';
$suggestions = $_POST['suggestions'] ?? '';

if (!$activity_id) {
    echo json_encode(['success' => false, 'error' => 'Missing Activity ID']);
    exit;
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

    $update_stmt = $db->prepare("UPDATE activity_evaluation SET complaints = :complaints, suggestions_for_improvement = :suggestions WHERE evaluation_id = :id");
    $update_stmt->execute([
        ':complaints' => $complaints,
        ':suggestions' => $suggestions,
        ':id' => $evaluation_id
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
