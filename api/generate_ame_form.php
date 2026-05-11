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

// 1. Fetch Activity and User Token
$stmt = $db->prepare("SELECT a.*, u.google_access_token, u.google_refresh_token 
                      FROM activities a 
                      JOIN users u ON u.user_id = :uid 
                      WHERE a.activity_id = :aid");
$stmt->execute(['uid' => $_SESSION['user_id'], 'aid' => $activity_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data || empty($data['google_access_token'])) {
    $_SESSION['error'] = "Please re-login with Google to grant necessary permissions.";
    header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
    exit;
}

// 2. Initialize Google Client
$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setAccessToken($data['google_access_token']);

if ($client->isAccessTokenExpired()) {
    if ($data['google_refresh_token']) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($data['google_refresh_token']);
        if (isset($newToken['access_token'])) {
            $update = $db->prepare("UPDATE users SET google_access_token = :at WHERE user_id = :uid");
            $update->execute(['at' => $newToken['access_token'], 'uid' => $_SESSION['user_id']]);
        } else {
            $_SESSION['error'] = "Session expired. Please re-login with Google.";
            header("Location: ../views/feed.php?action=login");
            exit;
        }
    } else {
        $_SESSION['error'] = "Session expired. Please re-login with Google.";
        header("Location: ../views/feed.php?action=login");
        exit;
    }
}

$formsService = new Google\Service\Forms($client);
$driveService = new Google\Service\Drive($client);
$sheetsService = new Google\Service\Sheets($client);

try {
    // 3. Folder Management
    $targetFolderId = '1yNI_uaSeM815nd7cW_b6rytc0IYdTR2x';
    
    // Test Folder Access
    try {
        $driveService->files->get($targetFolderId, ['fields' => 'id, name, capabilities']);
    } catch (Exception $e) {
        throw new Exception("Cannot access target folder. Please ensure you have Editor access to the folder and have re-logged in: " . $e->getMessage());
    }
    
    // 4. Create the Form
    $newForm = new Google\Service\Forms\Form();
    $newForm->setInfo(new Google\Service\Forms\Info([
        'title' => "AME Evaluation: " . $data['title'],
        'documentTitle' => "AME Form - " . $data['title']
    ]));

    $form = $formsService->forms->create($newForm);
    $formId = $form->getFormId();

    // Move form to target folder
    $file = new Google\Service\Drive\DriveFile();
    $driveService->files->update($formId, $file, [
        'addParents' => $targetFolderId,
        'removeParents' => 'root', // Form is created in root by default
        'fields' => 'id, parents'
    ]);

    // 5. Add Questions to the Form
    $requests = [
        new Google\Service\Forms\Request([
            'updateFormInfo' => [
                'info' => [
                    'description' => "Activity Monitoring & Evaluation Form for: " . $data['title'] . "\nDate: " . $data['eventdate'] . "\nVenue: " . $data['eventvenue']
                ],
                'updateMask' => 'description'
            ]
        ])
    ];

    $standardMetrics = [
        "Overall Service Rating (OSR)",
        "Presenter Effectiveness / Organizer Rating (PEOR)",
        "Program and Methodology (PAM)",
        "Management, Logistics and Support Services (PAMLSS)",
        "Overall Experience (OE)"
    ];

    foreach ($standardMetrics as $metric) {
        $requests[] = new Google\Service\Forms\Request([
            'createItem' => [
                'item' => [
                    'title' => $metric,
                    'questionItem' => [
                        'question' => [
                            'required' => true,
                            'scaleQuestion' => [
                                'low' => 1,
                                'high' => 5,
                                'lowLabel' => 'Poor',
                                'highLabel' => 'Excellent'
                            ]
                        ]
                    ]
                ],
                'location' => ['index' => count($requests) - 1]
            ]
        ]);
    }

    // Add Comments and Suggestions
    $requests[] = new Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => "Complaints",
                'questionItem' => [
                    'question' => [
                        'textQuestion' => ['paragraph' => true]
                    ]
                ]
            ],
            'location' => ['index' => count($requests) - 1]
        ]
    ]);

    $requests[] = new Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => "Suggestions for Improvement",
                'questionItem' => [
                    'question' => [
                        'textQuestion' => ['paragraph' => true]
                    ]
                ]
            ],
            'location' => ['index' => count($requests) - 1]
        ]
    ]);

    $batchUpdate = new Google\Service\Forms\BatchUpdateFormRequest();
    $batchUpdate->setRequests($requests);
    $formsService->forms->batchUpdate($formId, $batchUpdate);

    // 6. Spreadsheet Integration
    $monthName = date('F', strtotime($data['eventdate']));
    $sheetTitle = "Form Responses " . $monthName;
    
    // Search for existing sheet in target folder
    $query = "name = '" . $sheetTitle . "' and mimeType = 'application/vnd.google-apps.spreadsheet' and '" . $targetFolderId . "' in parents and trashed = false";
    $search = $driveService->files->listFiles(['q' => $query]);
    
    $spreadsheetId = null;
    if (count($search->getFiles()) > 0) {
        $spreadsheetId = $search->getFiles()[0]->getId();
    } else {
        // Create new spreadsheet
        $spreadsheet = new Google\Service\Sheets\Spreadsheet([
            'properties' => ['title' => $sheetTitle]
        ]);
        $ss = $sheetsService->spreadsheets->create($spreadsheet);
        $spreadsheetId = $ss->getSpreadsheetId();
        
        // Move to target folder
        $driveService->files->update($spreadsheetId, new Google\Service\Drive\DriveFile(), [
            'addParents' => $targetFolderId,
            'removeParents' => 'root',
            'fields' => 'id, parents'
        ]);
    }

    // Link Form to Spreadsheet
    // Note: The Google Forms API doesn't have a direct 'createResponseDestination' method yet (as of some versions)
    // but we can use the legacy v1 batchUpdate or simply inform the user.
    // UPDATE: Actually, linking is usually done via the UI or by setting the destination.
    // In Google Forms API v1, there is no direct "createResponseDestination" request.
    // However, we can use the Spreadsheet ID to manage responses if we were building a custom responder.
    // But since we want Google's native linking, we have to rely on the user or use a workaround.
    // ACTUALLY, most automated tools use the Drive API to just group them.
    
    // Let's at least try to organize the files.

    // 7. Update Database and Initialize Records
    $responderUri = "https://docs.google.com/forms/d/" . $formId . "/viewform";
    
    // Check if evaluation record exists
    $stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :aid");
    $stmt->execute(['aid' => $activity_id]);
    $eval = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($eval) {
        $evaluation_id = $eval['evaluation_id'];
        $update = $db->prepare("UPDATE activity_evaluation SET ame_form_link = :link WHERE evaluation_id = :eid");
        $update->execute(['link' => $responderUri, 'eid' => $evaluation_id]);
    } else {
        $insert = $db->prepare("INSERT INTO activity_evaluation (activity_id, ame_form_link, evaluation_status) VALUES (:aid, :link, 'Pending')");
        $insert->execute(['aid' => $activity_id, 'link' => $responderUri]);
        $evaluation_id = $db->lastInsertId();
    }

    // Initialize activity_statistics
    $stmt = $db->prepare("SELECT statistics_id FROM activity_statistics WHERE evaluation_id = :eid");
    $stmt->execute(['eid' => $evaluation_id]);
    if (!$stmt->fetch()) {
        $db->prepare("INSERT INTO activity_statistics (evaluation_id, osr, osr_wa, peor, peor_wa, pam, pam_wa, pamlss, pamlss_wa, oe, oe_wa, overall_average) 
                      VALUES (:eid, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)")
           ->execute(['eid' => $evaluation_id]);
    }

    // Initialize activity_statistics_others
    $stmt = $db->prepare("SELECT otherstat_id FROM activity_statistics_others WHERE evaluation_id = :eid");
    $stmt->execute(['eid' => $evaluation_id]);
    if (!$stmt->fetch()) {
        $db->prepare("INSERT INTO activity_statistics_others (evaluation_id) VALUES (:eid)")
           ->execute(['eid' => $evaluation_id]);
    }

    // Initialize facilitator ratings (Speakers)
    if (!empty($data['speaker'])) {
        $speakers = explode(',', $data['speaker']);
        foreach ($speakers as $sname) {
            $sname = trim($sname);
            if (empty($sname)) continue;
            
            $stmt = $db->prepare("SELECT speaker_id FROM activity_speaker_rating WHERE evaluation_id = :eid AND name = :name");
            $stmt->execute(['eid' => $evaluation_id, 'name' => $sname]);
            if (!$stmt->fetch()) {
                $db->prepare("INSERT INTO activity_speaker_rating (evaluation_id, name, eandd, mot, iae, gi) VALUES (:eid, :name, 0, 0, 0, 0)")
                   ->execute(['eid' => $evaluation_id, 'name' => $sname]);
            }
        }
    }

    // Initialize facilitator ratings (Organizers)
    if (!empty($data['organizer'])) {
        $organizers = explode(',', $data['organizer']);
        foreach ($organizers as $oname) {
            $oname = trim($oname);
            if (empty($oname)) continue;
            
            $stmt = $db->prepare("SELECT organizer_id FROM activity_organizer_rating WHERE evaluation_id = :eid AND name = :name");
            $stmt->execute(['eid' => $evaluation_id, 'name' => $oname]);
            if (!$stmt->fetch()) {
                $db->prepare("INSERT INTO activity_organizer_rating (evaluation_id, name, eandd, mot, iae, gi) VALUES (:eid, :name, 0, 0, 0, 0)")
                   ->execute(['eid' => $evaluation_id, 'name' => $oname]);
            }
        }
    }

    $_SESSION['success'] = "AME Form generated and evaluation records initialized!";
    header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
    exit;

} catch (Exception $e) {
    $_SESSION['error'] = "Error generating form: " . $e->getMessage();
    header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
    exit;
}
