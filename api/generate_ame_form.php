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
$eventDateDisplay = $data['eventdate'] ? date('F j, Y', $eventDate) : 'Date TBD';

$sdg_stmt = $db->prepare(
    "SELECT CONCAT('SDG ', s.sdg_id, ': ', s.title) AS sdg_title
     FROM activity_sdgs asg
     JOIN sdgs s ON asg.sdg_id = s.sdg_id
     WHERE asg.activity_id = :id
     ORDER BY s.sdg_id"
);
$sdg_stmt->execute(['id' => $activity_id]);
$sdgTitles = $sdg_stmt->fetchAll(PDO::FETCH_COLUMN);
$sdgDisplay = $sdgTitles ? implode("\n", $sdgTitles) : 'Not Specified';

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
$documentTitle = $activityTitle;
$formTitle = "Activity Evaluation | Client Satisfaction Survey";
$formDescription = "Dear Participant:\n\nGreetings from the Quality Assurance Office!\n\nThis is the Activity Evaluation Form/ Client Satisfaction Survey to the activity you have just participated. This Monitoring and Evaluation (M&E) implementation was presented and approved by the Administrative Council last March 11, 2024 to ensure the quality implementation of all PAPs (Programs/Activities/Projects) of NBSC.\nWe are interested in knowing your feedback on the activity and we would appreciate it if you could take a few seconds to complete this form.\n\nYour comments will enable us to continuously improve the Quality Management System of Northern Bukidnon State College in our programs/activities/projects.\n\nThank you very much and God bless you abundantly!\n\nSincerely,\n\nThe Quality Assurance Office";

// Google Forms API only allows title on create; description/documentTitle go via batchUpdate
$form = new Google\Service\Forms\Form();
$form->setInfo(new Google\Service\Forms\Info([
    'title' => $formTitle,
]));

$createdForm = $formsService->forms->create($form);
$formId = $createdForm->getFormId();
$formEditLink = "https://docs.google.com/forms/d/" . $formId . "/edit";
$formResponseLink = "https://docs.google.com/forms/d/" . $formId . "/edit#responses";
$responderUri = $createdForm->getResponderUri();

$formFile = new Google\Service\Drive\DriveFile([
    'name' => $documentTitle,
]);
$driveService->files->update($formId, $formFile, [
    'addParents' => $activityFolderId,
    'removeParents' => 'root',
    'fields' => 'id, parents'
]);

$sheetUrl = "";

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

function makeBoldText($text) {
    $normal = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $bold = [
        '𝐀','𝐁','𝐂','𝐃','𝐄','𝐅','𝐆','𝐇','𝐈','𝐉','𝐊','𝐋','𝐌','𝐍','𝐎','𝐏','𝐐','𝐑','𝐒','𝐓','𝐔','𝐕','𝐖','𝐗','𝐘','𝐙',
        '𝐚','𝐛','𝐜','𝐝','𝐞','𝐟','𝐠','𝐡','𝐢','𝐣','𝐤','𝐥','𝐦','𝐧','𝐨','𝐩','𝐪','𝐫','𝐬','𝐭','𝐮','𝐯','𝐰','𝐱','𝐲','𝐳',
        '𝟎','𝟏','𝟐','𝟑','𝟒','𝟓','𝟔','𝟕','𝟖','𝟗'
    ];
    $map = [];
    for ($i = 0; $i < strlen($normal); $i++) {
        $map[$normal[$i]] = $bold[$i];
    }
    return strtr((string) $text, $map);
}

function createTextQuestion($title, &$index, $paragraph = false, $required = true) {
    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => $title,
                'questionItem' => [
                    'question' => [
                        'required' => $required,
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

function createChoiceQuestion($title, $options, &$index, $description = null) {
    $choices = [];
    foreach($options as $opt) {
        $choices[] = ['value' => $opt];
    }
    $item = [
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
    ];
    if ($description !== null && $description !== '') {
        $item['description'] = $description;
    }

    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => $item,
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

function createTextItem($title, $description, &$index) {
    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'title' => $title,
                'description' => $description,
                'textItem' => new \stdClass()
            ],
            'location' => ['index' => $index++]
        ]
    ]);
}

function createImageItem($sourceUri, &$index) {
    return new \Google\Service\Forms\Request([
        'createItem' => [
            'item' => [
                'imageItem' => [
                    'image' => [
                        'sourceUri' => $sourceUri
                    ]
                ]
            ],
            'location' => ['index' => $index++]
        ]
    ]);
}

// Section 1: Data Privacy Consent
$privacyText = "This activity evaluation form/client satisfaction survey, in line with the Data Privacy Act of 2012, is committed to protect and secure personal information obtained in the process of performance of its mandate. The personal and other information you provided manually herein will be processed and utilized solely for training purposes only. Collected personal information will be kept/stored and accessed only by the QA Secretariat and will not be shared with any outside parties unless written consent is secured. The summary of results will be the only information shared to the implementing unit. By affirming this, you agree to answer the following needed information with utmost willingness to take part to this survey.";
$requests[] = createChoiceQuestion("DATA PRIVACY NOTICE", ["Yes, I acknowledge", "I'd rather opt out."], $index, $privacyText);

// Section 2-5: Activity Information
$requests[] = createTextItem("ACTIVITY NAME", makeBoldText($activityTitle), $index);
$requests[] = createTextItem("VENUE", makeBoldText($data['eventvenue'] ?: 'Location TBD'), $index);
$requests[] = createTextItem("DATE", makeBoldText($eventDateDisplay), $index);
$requests[] = createTextItem("SDG", makeBoldText(strtoupper($sdgDisplay)), $index);

// Section 6-10: Profile & Demographics
$requests[] = createTextQuestion("Name: (Last Name, First Name, M.I)", $index, false, false);
$requests[] = createChoiceQuestion("Age", ["18-24", "25-34", "35-44", "45-54", "55-64", "65 or over"], $index);
$requests[] = createTextQuestion("Unit/Office/Institute/Division (abbreviation only)", $index);
$requests[] = createTextQuestion("Contact Number", $index);
$requests[] = createChoiceQuestion("Gender (Please select the option that best describes your identity)", ["Male", "Female", "LGBTQIA+", "Prefer not to say"], $index);
// Section 12: Overall Service Rating
$requests[] = createScaleQuestion("I. Overall Service Rating", 1, 5, "Poor", "Excellent", $index);

// Section 13+: Facilitators
foreach ($facilitators_list as $fac) {
    if ($fac['role'] === 'speaker') {
        $requests[] = createTextItem("NAME", makeBoldText($fac['name']), $index);
        $requests[] = createScaleQuestion("Effectiveness", 1, 5, "Poor", "Excellent", $index);
        $requests[] = createScaleQuestion("Mastery of Topic", 1, 5, "Poor", "Excellent", $index);
        $requests[] = createScaleQuestion("Ability to Facilitate", 1, 5, "Poor", "Excellent", $index);
    } else {
        $requests[] = createTextItem("ORGANIZER'S NAME", makeBoldText($fac['name']), $index);
        $requests[] = createScaleQuestion("Organization and Coordination of the Event", 1, 5, "Poor", "Excellent", $index);
        $requests[] = createScaleQuestion("Clarity of Communication and Information Provided", 1, 5, "Poor", "Excellent", $index);
        $requests[] = createScaleQuestion("Engagement and Interaction Opportunities", 1, 5, "Poor", "Excellent", $index);
    }
}

// Section 18: Program and Methodology
$requests[] = createTextItem("III. Program and Methodology (Program flow, Program contents, and relevance)", "", $index);
$requests[] = createScaleQuestion("Program Flow", 1, 5, "Poor", "Excellent", $index);
$requests[] = createScaleQuestion("Program Contents", 1, 5, "Poor", "Excellent", $index);
$requests[] = createScaleQuestion("Relevance", 1, 5, "Poor", "Excellent", $index);

// Section 22: Activity Management
$requests[] = createTextItem("IV. Activity Management (Facilitation/Secretariat Service, Venue and Physical Arrangements, Time Allotted)", "", $index);
$requests[] = createScaleQuestion("Facilitation/Secretariat Service", 1, 5, "Poor", "Excellent", $index);
$requests[] = createScaleQuestion("Venue and Physical Arrangements", 1, 5, "Poor", "Excellent", $index);
$requests[] = createScaleQuestion("Time Allotted", 1, 5, "Poor", "Excellent", $index);

// Section 26-29: Qualitative Feedback & Overall Experience
$requests[] = createTextQuestion("V. What did you like most about the program/activity/project?", $index, true);
$requests[] = createTextQuestion("VI. Which part of the activity do you like LEAST? Why?", $index, true);
$requests[] = createTextQuestion("VII. Other comments/suggestions on how we can improve our program/activity/project.", $index, true);
$requests[] = createScaleQuestion("Please rate your OVERALL experience", 1, 5, "Poor", "Excellent", $index);

// (No footer image item — Google Forms does not support footer images)

// Step 1: Update form metadata. Drive document title is updated through Drive above.
try {
    $infoRequest = new \Google\Service\Forms\BatchUpdateFormRequest([
        'requests' => [
            new \Google\Service\Forms\Request([
                'updateFormInfo' => [
                    'info' => [
                        'description' => $formDescription,
                    ],
                    'updateMask' => 'description',
                ]
            ])
        ]
    ]);
    $formsService->forms->batchUpdate($formId, $infoRequest);
} catch (Exception $e) {
    // Non-fatal: metadata update failed, continue
    error_log('Form info update failed: ' . $e->getMessage());
}

// Step 2: Add all form items
$batchRequest = new \Google\Service\Forms\BatchUpdateFormRequest(['requests' => $requests]);
$formsService->forms->batchUpdate($formId, $batchRequest);

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
    $setup = $db->prepare(
        "INSERT INTO form_setup (evaluation_id, step1)
         VALUES (:eid, 1)
         ON DUPLICATE KEY UPDATE step1 = 1"
    );
    $setup->execute(['eid' => $evaluation_id]);
} catch (Exception $e) {
    error_log('Form setup Step 1 update failed: ' . $e->getMessage());
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
