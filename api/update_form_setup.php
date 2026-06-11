<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit;
}

$activity_id = (int) ($_POST['activity_id'] ?? 0);
$step = (int) ($_POST['step'] ?? 0);

if ($activity_id <= 0 || $step < 1 || $step > 4) {
    echo json_encode(['success' => false, 'message' => 'Invalid setup update.']);
    exit;
}

$db = (new Database())->getConnection();

try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS form_setup (
            form_id INT PRIMARY KEY AUTO_INCREMENT,
            evaluation_id INT NOT NULL UNIQUE,
            step1 TINYINT(1) NOT NULL DEFAULT 0,
            step2 TINYINT(1) NOT NULL DEFAULT 0,
            step3 TINYINT(1) NOT NULL DEFAULT 0,
            step4 TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :aid");
    $stmt->execute(['aid' => $activity_id]);
    $evaluation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$evaluation) {
        echo json_encode(['success' => false, 'message' => 'Generate the AME form first.']);
        exit;
    }

    $evaluation_id = (int) $evaluation['evaluation_id'];
    $column = 'step' . $step;

    $stmt = $db->prepare(
        "INSERT INTO form_setup (evaluation_id, {$column})
         VALUES (:eid, 1)
         ON DUPLICATE KEY UPDATE {$column} = 1"
    );
    $stmt->execute(['eid' => $evaluation_id]);

    $stmt = $db->prepare("SELECT step1, step2, step3, step4 FROM form_setup WHERE evaluation_id = :eid");
    $stmt->execute(['eid' => $evaluation_id]);
    $setup = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['step1' => 0, 'step2' => 0, 'step3' => 0, 'step4' => 0];

    echo json_encode([
        'success' => true,
        'message' => 'Setup step marked complete.',
        'setup' => $setup,
    ]);
} catch (Exception $e) {
    error_log('Form setup update failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update setup progress.']);
}
