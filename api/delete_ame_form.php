<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../views/feed.php?action=login");
    exit;
}

$activity_id = $_GET['id'] ?? null;
if (!$activity_id) {
    $_SESSION['error'] = "Invalid activity ID.";
    header("Location: ../views/feed.php?action=activity");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 1. Fetch Evaluation and User Token
$stmt = $db->prepare("SELECT e.ame_form_link, u.google_access_token, u.google_refresh_token 
                      FROM activity_evaluation e 
                      JOIN users u ON u.user_id = :uid 
                      WHERE e.activity_id = :aid");
$stmt->execute(['uid' => $_SESSION['user_id'], 'aid' => $activity_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data || empty($data['ame_form_link'])) {
    $_SESSION['error'] = "No form found to delete.";
    header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
    exit;
}

// Extract Form ID
preg_match('/forms\/d\/([a-zA-Z0-9_-]+)/', $data['ame_form_link'], $matches);
$formId = $matches[1] ?? null;

if ($formId && !empty($data['google_access_token'])) {
    // Initialize Google Client
    $client = new Google\Client();
    $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
    $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
    $client->setAccessToken($data['google_access_token']);

    if ($client->isAccessTokenExpired() && $data['google_refresh_token']) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($data['google_refresh_token']);
        $client->setAccessToken($newToken);
    }

    $driveService = new Google\Service\Drive($client);
    
    try {
        // Delete from Drive
        $driveService->files->delete($formId);
    } catch (Exception $e) {
        // If not found, we still want to clear the DB
    }
}

// 2. Clear from Database
$stmt = $db->prepare("UPDATE activity_evaluation SET ame_form_link = NULL WHERE activity_id = :aid");
$stmt->execute(['aid' => $activity_id]);

$_SESSION['success'] = "AME Form deleted and reset successfully.";
header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
exit;
