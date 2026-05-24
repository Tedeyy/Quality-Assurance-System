<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/responses_database.php';
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
$rdb = (new ResponsesDatabase())->getConnection();
if (!$rdb) {
    $_SESSION['error'] = "Responses database connection failed. AME form table was not created.";
    header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
    exit;
}

// 1. Fetch Activity Details
$stmt = $db->prepare("SELECT * FROM activities WHERE activity_id = :id");
$stmt->execute(['id' => $activity_id]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    $_SESSION['error'] = "Activity not found.";
    header("Location: ../views/feed.php?action=activity");
    exit;
}

// 2. Fetch User's Google Token
$stmt = $db->prepare("SELECT google_access_token, google_refresh_token FROM users WHERE user_id = :uid");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (empty($user['google_access_token'])) {
    $_SESSION['error'] = "Please link your Google account first.";
    header("Location: ../views/feed.php?action=profile");
    exit;
}

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
$sheetsService = new Google\Service\Sheets($client);

// 3. Drive Folder Organization
$rootFolderUrl = $_ENV['GOOGLE_FORM_FILEPATH'] ?? '';
preg_match('/folders\/([a-zA-Z0-9_-]+)/', $rootFolderUrl, $matches);
$rootFolderId = $matches[1] ?? '1yNI_uaSeM815nd7cW_b6rytc0IYdTR2x';

$eventDate = $data['eventdate'] ? strtotime($data['eventdate']) : time();
$monthYear = date('F Y', $eventDate);
$activityCode = $data['activity_code'];
$activityTitle = $data['title'];
$eventDateStr = $data['eventdate'] ? date('Y-m-d', $eventDate) : '';

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

// 4. Form & Sheet Creation
$formsService = new Google\Service\Forms($client);
$formTitle = "AME Evaluation: " . $activityTitle;
$form = new Google\Service\Forms\Form();
$form->setInfo(new Google\Service\Forms\Info([
    'title' => $formTitle,
    'documentTitle' => $formTitle
]));

$createdForm = $formsService->forms->create($form);
$formId = $createdForm->getFormId();
$formEditLink = "https://docs.google.com/forms/d/" . $formId . "/edit";
$formResponseLink = "https://docs.google.com/forms/d/" . $formId . "/edit#responses";
$responderUri = $createdForm->getResponderUri();

$emptyFile = new Google\Service\Drive\DriveFile();
$driveService->files->update($formId, $emptyFile, [
    'addParents' => $activityFolderId,
    'removeParents' => 'root',
    'fields' => 'id, parents'
]);

$sheetTitle = $activityCode . " Responses";
$spreadsheet = new Google\Service\Sheets\Spreadsheet(['properties' => ['title' => $sheetTitle]]);
$ss = $sheetsService->spreadsheets->create($spreadsheet);
$spreadsheetId = $ss->getSpreadsheetId();
$sheetUrl = $ss->getSpreadsheetUrl();

$driveService->files->update($spreadsheetId, $emptyFile, [
    'addParents' => $activityFolderId,
    'removeParents' => 'root',
    'fields' => 'id, parents'
]);

// 5. Index Sheet Integration
$indexSheetUrl = $_ENV['RESPONSES_GOOGLE_SHEET'] ?? '';
$indexSheetId = $indexSheetUrl;
if (preg_match('/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $indexSheetUrl, $matches)) {
    $indexSheetId = $matches[1];
} elseif (preg_match('/folders\/([a-zA-Z0-9_-]+)/', $indexSheetUrl, $matches)) {
    $indexSheetId = $indexSheetUrl;
}

try {
    $generationDate = date('Y-m-d H:i:s');
    $folderUrl = "https://drive.google.com/drive/folders/" . $activityFolderId;
    $values = [[$activityCode, $activityTitle, $eventDateStr, $generationDate, $responderUri, $folderUrl, $sheetUrl]];
    $body = new Google\Service\Sheets\ValueRange(['values' => $values]);
    $params = ['valueInputOption' => 'USER_ENTERED'];
    $sheetsService->spreadsheets_values->append($indexSheetId, "A:G", $body, $params);
} catch (Exception $e) {}

// ── Fetch facilitators from junction table ───────────────────────────────────
$fac_stmt = $db->prepare(
    "SELECT af.role, af.person_id,
            COALESCE(sp.name, og.name) AS name
     FROM   activity_facilitators af
     LEFT JOIN speakers   sp ON af.role = 'speaker'   AND af.person_id = sp.speaker_id
     LEFT JOIN organizers og ON af.role = 'organizer' AND af.person_id = og.organizer_id
     WHERE  af.activity_id = :id
     ORDER BY af.role, af.af_id"
);
$fac_stmt->execute(['id' => $activity_id]);
$facilitators_list = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($facilitators_list)) {
    if (!empty($data['speaker'])) {
        foreach (explode(',', $data['speaker']) as $n) {
            $n = trim($n); if ($n) $facilitators_list[] = ['role' => 'speaker', 'name' => $n];
        }
    }
    if (!empty($data['organizer'])) {
        foreach (explode(',', $data['organizer']) as $n) {
            $n = trim($n); if ($n) $facilitators_list[] = ['role' => 'organizer', 'name' => $n];
        }
    }
}
$facilitatorsCount = count($facilitators_list);

// 6. Build Form Structure (Batch Update)
$requests = [];
$index = 0;

function createTextQuestion($title, &$index, $paragraph = false) {
    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => $title,
                'questionItem' => [
                    'question' => [
                        'required' => true,
                        'textQuestion' => [
                            'paragraph' => $paragraph
                        ]
                    ]
                ]
            ],
            'location' => ['index' => $index++]
        ]
    ]);
}

function createChoiceQuestion($title, $options, &$index) {
    $choices = [];
    foreach($options as $opt) {
        $choices[] = ['value' => $opt];
    }
    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => $title,
                'questionItem' => [
                    'question' => [
                        'required' => true,
                        'choiceQuestion' => [
                            'type' => 'RADIO',
                            'options' => $choices
                        ]
                    ]
                ]
            ],
            'location' => ['index' => $index++]
        ]
    ]);
}

function createScaleQuestion($title, $low, $high, $lowLabel, $highLabel, &$index) {
    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => $title,
                'questionItem' => [
                    'question' => [
                        'required' => true,
                        'scaleQuestion' => [
                            'low' => $low,
                            'high' => $high,
                            'lowLabel' => $lowLabel,
                            'highLabel' => $highLabel
                        ]
                    ]
                ]
            ],
            'location' => ['index' => $index++]
        ]
    ]);
}

function createGridQuestion($title, $rows, $columns, &$index) {
    $r = [];
    foreach($rows as $row) {
        $r[] = ['rowQuestion' => ['title' => $row]];
    }
    $c = [];
    foreach($columns as $col) {
        $c[] = ['value' => (string)$col];
    }
    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => $title,
                'questionGroupItem' => [
                    'questions' => $r,
                    'grid' => [
                        'columns' => [
                             'type' => 'RADIO',
                             'options' => $c
                        ]
                    ]
                ]
            ],
            'location' => ['index' => $index++]
        ]
    ]);
}

// Section 1: Profile
$requests[] = createTextQuestion("Email Address", $index);
$requests[] = createTextQuestion("Full Name (Last Name, First Name, Middle Initial)", $index);
$requests[] = createTextQuestion("Age", $index);
$requests[] = createTextQuestion("Contact Number", $index);
$requests[] = createTextQuestion("Unit / Office / Division", $index);
$requests[] = createChoiceQuestion("Gender", ["Male", "Female", "Others"], $index);

// Section 2: Quality Assessment
$requests[] = createScaleQuestion("I. Overall Service Rating (General success of the totality of the activity execution)", 1, 5, "Poor", "Excellent", $index);

// Section 3: Speakers
foreach ($facilitators_list as $fac) {
    $requests[] = createGridQuestion(
        "II. Performance: " . $fac['name'] . " (" . ucfirst($fac['role']) . ")",
        ["Expertise and Delivery", "Mastery of Topic", "Interaction & Engagement", "General Impact"],
        ["1", "2", "3", "4", "5"],
        $index
    );
}

// Section 4: Program & Methodology
$requests[] = createGridQuestion(
    "III. Evaluation Results",
    ["Program Flow", "Program Contents", "Relevance to Objective", "Future Applicability"],
    ["1", "2", "3", "4", "5"],
    $index
);

// Section 5: Management & Logistics
$requests[] = createGridQuestion(
    "IV. Logistics",
    ["Secretariat Service", "Logistics/Venue", "Timing/Scheduling"],
    ["1", "2", "3", "4", "5"],
    $index
);

// Section 6: Feedback
$requests[] = createTextQuestion("Which of the topics did you like BEST? Why?", $index, true);
$requests[] = createTextQuestion("Which parts could be improved? (Least Liked)", $index, true);
$requests[] = createScaleQuestion("Overall Experience", 1, 5, "Poor", "Excellent", $index);

try {
    $batchRequest = new \Google\Service\Forms\BatchUpdateFormRequest(['requests' => $requests]);
    $formsService->forms->batchUpdate($formId, $batchRequest);
} catch (Exception $e) {
    // Ignore batch update errors, form is created anyway
}

// Helper to add working days
function addWorkingDays($startDate, $days) {
    $date = new DateTime($startDate);
    while ($days > 0) {
        $date->modify('+1 day');
        if ($date->format('N') < 6) { // 1 (Mon) to 5 (Fri)
            $days--;
        }
    }
    return $date->format('Y-m-d');
}

$date_released = date('Y-m-d');
$deadline = addWorkingDays($date_released, 20);

$stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :aid");
$stmt->execute(['aid' => $activity_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if ($eval) {
    $evaluation_id = $eval['evaluation_id'];
    $update = $db->prepare("UPDATE activity_evaluation SET ame_form_link = :link, ame_form_id = :fid, published_options = 'Open', date_released = :dr, deadline = :dl WHERE evaluation_id = :eid");
    $update->execute(['link' => $responderUri, 'fid' => $formId, 'eid' => $evaluation_id, 'dr' => $date_released, 'dl' => $deadline]);
} else {
    $insert = $db->prepare("INSERT INTO activity_evaluation (activity_id, ame_form_link, ame_form_id, evaluation_status, published_options, date_released, deadline) VALUES (:aid, :link, :fid, 'Pending', 'Open', :dr, :dl)");
    $insert->execute(['aid' => $activity_id, 'link' => $responderUri, 'fid' => $formId, 'dr' => $date_released, 'dl' => $deadline]);
    $evaluation_id = $db->lastInsertId();
}

// 7. Initialize stats and ratings
$stmt = $db->prepare("SELECT statistics_id FROM activity_statistics WHERE evaluation_id = :eid");
$stmt->execute(['eid' => $evaluation_id]);
if (!$stmt->fetch()) {
    $db->prepare("INSERT INTO activity_statistics (evaluation_id, osr, osr_wa, peor, peor_wa, pam, pam_wa, pamlss, pamlss_wa, oe, oe_wa, overall_average) 
                  VALUES (:eid, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)")
       ->execute(['eid' => $evaluation_id]);
}

$stmt = $db->prepare("SELECT otherstat_id FROM activity_statistics_others WHERE evaluation_id = :eid");
$stmt->execute(['eid' => $evaluation_id]);
if (!$stmt->fetch()) {
    $db->prepare("INSERT INTO activity_statistics_others (evaluation_id) VALUES (:eid)")
       ->execute(['eid' => $evaluation_id]);
}

// Initialize per-person rating rows from the junction table list
foreach ($facilitators_list as $fac) {
    $name = $fac['name'];
    $role = $fac['role'];

    if ($role === 'speaker') {
        $s_stmt = $db->prepare("SELECT speaker_id FROM speakers WHERE name = :name");
        $s_stmt->execute(['name' => $name]);
        $person = $s_stmt->fetch(PDO::FETCH_ASSOC);
        if ($person) {
            $sid  = $person['speaker_id'];
            $chk  = $db->prepare("SELECT speaker_rating_id FROM activity_speaker_rating WHERE evaluation_id = :eid AND speaker_id = :sid");
            $chk->execute(['eid' => $evaluation_id, 'sid' => $sid]);
            if (!$chk->fetch()) {
                $db->prepare("INSERT INTO activity_speaker_rating (evaluation_id, speaker_id, eff, mot, atf) VALUES (:eid, :sid, 0, 0, 0)")
                   ->execute(['eid' => $evaluation_id, 'sid' => $sid]);
            }
        }
    } else {
        $o_stmt = $db->prepare("SELECT organizer_id FROM organizers WHERE name = :name");
        $o_stmt->execute(['name' => $name]);
        $person = $o_stmt->fetch(PDO::FETCH_ASSOC);
        if ($person) {
            $oid  = $person['organizer_id'];
            $chk  = $db->prepare("SELECT organizer_rating_id FROM activity_organizer_rating WHERE evaluation_id = :eid AND organizer_id = :oid");
            $chk->execute(['eid' => $evaluation_id, 'oid' => $oid]);
            if (!$chk->fetch()) {
                $db->prepare("INSERT INTO activity_organizer_rating (evaluation_id, organizer_id, eff, mot, atf) VALUES (:eid, :oid, 0, 0, 0)")
                   ->execute(['eid' => $evaluation_id, 'oid' => $oid]);
            }
        }
    }
}

$_SESSION['success'] = "Custom Evaluation activated successfully via Google Forms!";
header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
exit;
