<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$activity_id = $_POST['activity_id'] ?? null;
if (!$activity_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Activity ID is required']);
    exit;
}

if (!isset($_FILES['justification_letter']) || $_FILES['justification_letter']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = (new Database())->getConnection();

// Fetch Activity Details
$stmt = $db->prepare("SELECT activity_code, title, eventdate FROM activities WHERE activity_id = :id");
$stmt->execute(['id' => $activity_id]);
$activity_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity_data) {
    http_response_code(404);
    echo json_encode(['error' => 'Activity not found']);
    exit;
}

// Fetch User's Google Token
$stmt = $db->prepare("SELECT google_access_token, google_refresh_token FROM users WHERE user_id = :uid");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (empty($user['google_access_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Please link your Google account first.']);
    exit;
}

// Ensure an evaluation record exists for this activity
$stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :activity_id");
$stmt->execute([':activity_id' => $activity_id]);
$evaluation_id = $stmt->fetchColumn();

if (!$evaluation_id) {
    // Insert a blank evaluation record
    $insertStmt = $db->prepare("INSERT INTO activity_evaluation (activity_id, evaluation_status) VALUES (:activity_id, 'Pending')");
    $insertStmt->execute([':activity_id' => $activity_id]);
    $evaluation_id = $db->lastInsertId();
}

$fileInfo = pathinfo($_FILES['justification_letter']['name']);
$extension = strtolower($fileInfo['extension']);
$allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

if (!in_array($extension, $allowedExtensions)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only PDF, DOC, DOCX, and images are allowed.']);
    exit;
}

try {
    // Initialize Google Client
    $client = new Google\Client();
    $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
    $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
    $client->setAccessToken($user['google_access_token']);

    if ($client->isAccessTokenExpired() && $user['google_refresh_token']) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($user['google_refresh_token']);
        $client->setAccessToken($newToken);
        // Update token in DB
        $stmt = $db->prepare("UPDATE users SET google_access_token = :token WHERE user_id = :uid");
        $stmt->execute(['token' => json_encode($client->getAccessToken()), 'uid' => $_SESSION['user_id']]);
    }

    $driveService = new Google\Service\Drive($client);

    // Drive Folder Organization
    $rootFolderUrl = $_ENV['GOOGLE_FORM_FILEPATH'] ?? '';
    preg_match('/folders\/([a-zA-Z0-9_-]+)/', $rootFolderUrl, $matches);
    $rootFolderId = $matches[1] ?? '1yNI_uaSeM815nd7cW_b6rytc0IYdTR2x';

    $eventDate = $activity_data['eventdate'] ? strtotime($activity_data['eventdate']) : time();
    $monthYear = date('F Y', $eventDate);
    $activityCode = $activity_data['activity_code'];

    function getOrCreateDriveFolder($driveService, $folderName, $parentFolderId) {
        $query = "name = '" . str_replace("'", "\'", $folderName) . "' and mimeType = 'application/vnd.google-apps.folder' and '" . $parentFolderId . "' in parents and trashed = false";
        $search = $driveService->files->listFiles(['q' => $query]);
        if (count($search->getFiles()) > 0) {
            return $search->getFiles()[0]->getId();
        } else {
            $folderMetadata = new Google\Service\Drive\DriveFile([
                'name' => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$parentFolderId]
            ]);
            $folder = $driveService->files->create($folderMetadata, ['fields' => 'id']);
            return $folder->getId();
        }
    }

    $monthYearFolderId = getOrCreateDriveFolder($driveService, $monthYear, $rootFolderId);
    $activityFolderId = getOrCreateDriveFolder($driveService, $activityCode, $monthYearFolderId);

    // Upload file to Drive
    $originalName = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $_FILES['justification_letter']['name']);
    $newFileName = 'Justification_' . $activityCode . '_' . $originalName;
    
    $fileMetadata = new Google\Service\Drive\DriveFile([
        'name' => $newFileName,
        'parents' => [$activityFolderId]
    ]);
    
    $content = file_get_contents($_FILES['justification_letter']['tmp_name']);
    $file = $driveService->files->create($fileMetadata, [
        'data' => $content,
        'mimeType' => $_FILES['justification_letter']['type'],
        'uploadType' => 'multipart',
        'fields' => 'id, webViewLink'
    ]);

    // Set permissions so anyone with link can view
    $permission = new Google\Service\Drive\Permission([
        'type' => 'anyone',
        'role' => 'reader'
    ]);
    $driveService->permissions->create($file->getId(), $permission);

    $webViewLink = $file->webViewLink;
    if (strpos($webViewLink, '?') !== false) {
        $webViewLink .= '&folderId=' . $activityFolderId;
    } else {
        $webViewLink .= '?folderId=' . $activityFolderId;
    }

    // Save to DB (activity_evaluation table)
    $stmt = $db->prepare("UPDATE activity_evaluation SET justification_letter = :link WHERE activity_id = :activity_id");
    $stmt->execute([
        ':link' => $webViewLink,
        ':activity_id' => $activity_id
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully to Google Drive',
        'filename' => $webViewLink
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload file: ' . $e->getMessage()]);
}
