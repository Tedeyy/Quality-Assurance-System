<?php
/**
 * API: Toggle published_options for an activity evaluation
 * POST  ?action=toggle_visibility&id=<activity_id>
 * Returns JSON: { success, published_options }
 */
session_start();
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = (new Database())->getConnection();
$action = $_GET['action'] ?? '';

if ($action === 'toggle_visibility' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $activity_id = $_POST['activity_id'] ?? null;
    if (!$activity_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing activity_id']);
        exit;
    }

    try {
        // Fetch current value
        $stmt = $db->prepare("SELECT evaluation_id, published_options, ame_form_id FROM activity_evaluation WHERE activity_id = :aid");
        $stmt->execute([':aid' => $activity_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Evaluation not found']);
            exit;
        }

        // Toggle
        $newVal = ($row['published_options'] === 'Open') ? 'Closed' : 'Open';

        $db->prepare("UPDATE activity_evaluation SET published_options = :val WHERE evaluation_id = :eid")
           ->execute([':val' => $newVal, ':eid' => $row['evaluation_id']]);

        // Trigger Google Apps Script Webhook to lock/unlock the actual Google Form
        $webhookUrl = $_ENV['APPS_SCRIPT_WEBHOOK_URL'] ?? '';
        $formId = $row['ame_form_id'] ?? '';
        
        if (!empty($webhookUrl) && !empty($formId)) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $webhookUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'form_id' => $formId,
                'status'  => $newVal
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            // Ignore SSL verification if running on local XAMPP without cacert
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            curl_close($ch);
        }

        echo json_encode(['success' => true, 'published_options' => $newVal]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
