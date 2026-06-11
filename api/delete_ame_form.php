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
$activity_id = (int) $activity_id;
if ($activity_id <= 0) {
    $_SESSION['error'] = "Invalid activity ID.";
    header("Location: ../views/feed.php?action=activity");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 1. Fetch Evaluation, Activity, and User Token
$stmt = $db->prepare("SELECT e.evaluation_id, e.ame_form_link, e.ame_form_id, a.activity_code, u.google_access_token, u.google_refresh_token 
                      FROM activity_evaluation e
                      JOIN activities a ON e.activity_id = a.activity_id
                      JOIN users u ON u.user_id = :uid 
                      WHERE e.activity_id = :aid");
$stmt->execute(['uid' => $_SESSION['user_id'], 'aid' => $activity_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data || (empty($data['ame_form_link']) && empty($data['ame_form_id']))) {
    $_SESSION['error'] = "No form found to delete.";
    header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
    exit;
}

// Extract Form ID
$formId = $data['ame_form_id'] ?? '';
if (!$formId && !empty($data['ame_form_link']) && preg_match('/forms\/d\/(?:e\/)?([a-zA-Z0-9_-]+)/', $data['ame_form_link'], $matches)) {
    $formId = $matches[1];
}

if (!empty($data['google_access_token'])) {
    // Initialize Google Client
    $client = new Google\Client();
    $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
    $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
    $client->setAccessToken($data['google_access_token']);

    if ($client->isAccessTokenExpired() && $data['google_refresh_token']) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($data['google_refresh_token']);
        if ($newToken && empty($newToken['error'])) {
            $client->setAccessToken($newToken);
            $stmt = $db->prepare("UPDATE users SET google_access_token = :token WHERE user_id = :uid");
            $stmt->execute(['token' => json_encode($client->getAccessToken()), 'uid' => $_SESSION['user_id']]);
        }
    }

    $driveService = new Google\Service\Drive($client);
    $sheetsService = new Google\Service\Sheets($client);
    
    if ($formId) {
        try {
            // Delete from Drive
            $driveService->files->delete($formId);
        } catch (Exception $e) {
            // If not found, we still want to clear the DB
            error_log('AME form Drive delete failed: ' . $e->getMessage());
        }
    }

    try {
        // Delete matching row from the Index Responses spreadsheet.
        $indexSheetUrl = $_ENV['RESPONSES_GOOGLE_SHEET'] ?? '';
        $indexSheetId = $indexSheetUrl;
        if (preg_match('/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $indexSheetUrl, $matches)) {
            $indexSheetId = $matches[1];
        }

        if ($indexSheetId) {
            $response = $sheetsService->spreadsheets_values->get($indexSheetId, 'A:G');
            $values = $response->getValues();
            $rowIndexToDelete = -1;
            $activityCode = $data['activity_code'] ?? '';
            $formLink = $data['ame_form_link'] ?? '';

            if ($values) {
                foreach ($values as $index => $row) {
                    $rowActivityCode = $row[0] ?? '';
                    $rowResponderLink = $row[4] ?? '';
                    if (
                        ($activityCode && $rowActivityCode === $activityCode) ||
                        ($formId && strpos($rowResponderLink, $formId) !== false) ||
                        ($formLink && $rowResponderLink === $formLink)
                    ) {
                        $rowIndexToDelete = $index;
                        break;
                    }
                }
            }

            if ($rowIndexToDelete !== -1) {
                $spreadsheet = $sheetsService->spreadsheets->get($indexSheetId);
                $sheetId = $spreadsheet->getSheets()[0]->getProperties()->getSheetId();

                $deleteRequest = new Google\Service\Sheets\Request([
                    'deleteDimension' => [
                        'range' => [
                            'sheetId' => $sheetId,
                            'dimension' => 'ROWS',
                            'startIndex' => $rowIndexToDelete,
                            'endIndex' => $rowIndexToDelete + 1
                        ]
                    ]
                ]);

                $batchUpdateRequest = new Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                    'requests' => [$deleteRequest]
                ]);
                $sheetsService->spreadsheets->batchUpdate($indexSheetId, $batchUpdateRequest);
            }
        }
    } catch (Exception $e) {
        // Keep local cleanup going even if the sheet row is already missing or inaccessible.
        error_log('AME form index row delete failed: ' . $e->getMessage());
    }
}

// 2. Clear from Database
$stmt = $db->prepare("UPDATE activity_evaluation SET ame_form_link = NULL, ame_form_id = NULL WHERE activity_id = :aid");
$stmt->execute(['aid' => $activity_id]);

if (!empty($data['evaluation_id'])) {
    try {
        $stmt = $db->prepare("DELETE FROM form_setup WHERE evaluation_id = :eid");
        $stmt->execute(['eid' => $data['evaluation_id']]);
    } catch (Exception $e) {
        error_log('Form setup reset failed: ' . $e->getMessage());
    }
}

$_SESSION['success'] = "AME Form deleted and reset successfully.";
header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
exit;
