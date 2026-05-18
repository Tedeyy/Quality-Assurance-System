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

// 3. Ensure Target Folder Access
$targetFolderId = "1yNI_uaSeM815nd7cW_b6rytc0IYdTR2x";

// 4. Google Sheet Integration (Keep this for data backup/reporting)
$monthName = date('F', strtotime($data['eventdate']));
$sheetTitle = "Form Responses " . $monthName;

try {
    $query = "name = '" . $sheetTitle . "' and mimeType = 'application/vnd.google-apps.spreadsheet' and '" . $targetFolderId . "' in parents and trashed = false";
    $search = $driveService->files->listFiles(['q' => $query]);
    
    $spreadsheetId = null;
    if (count($search->getFiles()) > 0) {
        $spreadsheetId = $search->getFiles()[0]->getId();
    } else {
        $spreadsheet = new Google\Service\Sheets\Spreadsheet(['properties' => ['title' => $sheetTitle]]);
        $ss = $sheetsService->spreadsheets->create($spreadsheet);
        $spreadsheetId = $ss->getSpreadsheetId();
        
        $driveService->files->update($spreadsheetId, new Google\Service\Drive\DriveFile(), [
            'addParents' => $targetFolderId,
            'removeParents' => 'root',
            'fields' => 'id, parents'
        ]);
    }
} catch (Exception $e) {
    // If sheet creation fails, we still proceed with local form
}

// 5. Update Database and Initialize Records
$activity_code = $data['activity_code'];
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$localUri = $protocol . "://" . $host . "/Quality-Assurance-System/evaluation.php?code=" . $activity_code;

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

// Fallback: parse legacy comma strings if junction table empty
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

if ($rdb) {
    $rdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $table_name = "activity_" . $activity_id;
    
    try {
        $rdb->exec("DROP TABLE IF EXISTS $table_name");
        $createTable = "CREATE TABLE $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE,
            fullname VARCHAR(255),
            age VARCHAR(50),
            gender VARCHAR(50),
            contact VARCHAR(100),
            unit VARCHAR(255),
            osr INT,
        ";
        
        for ($i = 0; $i < $facilitatorsCount; $i++) {
            $createTable .= "fac_{$i}_eff INT, fac_{$i}_mot INT, fac_{$i}_atf INT, ";
        }
        
        for ($i = 0; $i < 4; $i++) $createTable .= "prog_$i INT, ";
        for ($i = 0; $i < 3; $i++) $createTable .= "log_$i INT, ";
        
        $createTable .= "
            best_topics TEXT,
            improvements TEXT,
            oe INT,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $rdb->exec($createTable);
    } catch (PDOException $e) {
        $_SESSION['error'] = "Table Creation Failed: " . $e->getMessage();
        header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
        exit;
    }
}

$date_released = date('Y-m-d');
$deadline = addWorkingDays($date_released, 20);

$stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :aid");
$stmt->execute(['aid' => $activity_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if ($eval) {
    $evaluation_id = $eval['evaluation_id'];
    $update = $db->prepare("UPDATE activity_evaluation SET ame_form_link = :link, published_options = 'Open', date_released = :dr, deadline = :dl WHERE evaluation_id = :eid");
    $update->execute(['link' => $localUri, 'eid' => $evaluation_id, 'dr' => $date_released, 'dl' => $deadline]);
} else {
    $insert = $db->prepare("INSERT INTO activity_evaluation (activity_id, ame_form_link, evaluation_status, published_options, date_released, deadline) VALUES (:aid, :link, 'Pending', 'Open', :dr, :dl)");
    $insert->execute(['aid' => $activity_id, 'link' => $localUri, 'dr' => $date_released, 'dl' => $deadline]);
    $evaluation_id = $db->lastInsertId();
}

// 6. Initialize stats and ratings
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

$_SESSION['success'] = "Custom Evaluation activated successfully!";
header("Location: ../views/feed.php?action=view_activity&id=" . $activity_id);
exit;
