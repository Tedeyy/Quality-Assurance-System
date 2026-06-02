<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/responses_database.php';
require_once __DIR__ . '/../vendor/autoload.php';

function sync_json_response(array $payload, int $status = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log("Sync Google responses failed: " . $e->getMessage());
    sync_json_response(['success' => false, 'message' => 'Sync failed: ' . $e->getMessage()], 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log("Sync Google responses fatal error: {$error['message']} in {$error['file']}:{$error['line']}");
    if (!headers_sent()) {
        sync_json_response(['success' => false, 'message' => 'Sync failed due to a server error. Check the PHP error log for details.'], 500);
    }
});

if (!isset($_SESSION['user_id'])) {
    sync_json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$activity_id = $_GET['id'] ?? null;
if (!$activity_id) {
    sync_json_response(['success' => false, 'message' => 'Missing activity ID'], 400);
}
$activity_id = (int)$activity_id;

$database = new Database();
$db = $database->getConnection();
$rdb = (new ResponsesDatabase())->getConnection();

// Fetch form_id
$stmt = $db->prepare("SELECT ame_form_id FROM activity_evaluation WHERE activity_id = :aid");
$stmt->execute(['aid' => $activity_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eval || empty($eval['ame_form_id'])) {
    sync_json_response(['success' => false, 'message' => 'No Google Form is associated with this activity. Please generate one first.'], 404);
}
$formId = $eval['ame_form_id'];

// Get User Token
$stmt = $db->prepare("SELECT google_access_token, google_refresh_token FROM users WHERE user_id = :uid");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (empty($user['google_access_token']) && empty($user['google_refresh_token'])) {
    sync_json_response(['success' => false, 'message' => 'Please link your Google account in your profile.'], 403);
}

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);

$storedAccessToken = json_decode($user['google_access_token'], true);
$hasValidStoredAccessToken = json_last_error() === JSON_ERROR_NONE && is_array($storedAccessToken) && !empty($storedAccessToken);

if ($hasValidStoredAccessToken) {
    $client->setAccessToken($storedAccessToken);
} elseif (!empty($user['google_refresh_token'])) {
    $newToken = $client->fetchAccessTokenWithRefreshToken($user['google_refresh_token']);
    if (!empty($newToken['error'])) {
        sync_json_response(['success' => false, 'message' => 'Google access could not be refreshed. Please link your Google account again.'], 403);
    }

    $client->setAccessToken($newToken);
    $stmt = $db->prepare("UPDATE users SET google_access_token = :token WHERE user_id = :uid");
    $stmt->execute(['token' => json_encode($client->getAccessToken()), 'uid' => $_SESSION['user_id']]);
} else {
    sync_json_response(['success' => false, 'message' => 'Stored Google access is invalid. Please link your Google account again.'], 403);
}

if ($client->isAccessTokenExpired() && $user['google_refresh_token']) {
    $newToken = $client->fetchAccessTokenWithRefreshToken($user['google_refresh_token']);
    if (!empty($newToken['error'])) {
        sync_json_response(['success' => false, 'message' => 'Google access could not be refreshed. Please link your Google account again.'], 403);
    }
    $client->setAccessToken($newToken);
    $stmt = $db->prepare("UPDATE users SET google_access_token = :token WHERE user_id = :uid");
    $stmt->execute(['token' => json_encode($client->getAccessToken()), 'uid' => $_SESSION['user_id']]);
} elseif ($client->isAccessTokenExpired()) {
    sync_json_response(['success' => false, 'message' => 'Google access expired. Please link your Google account again.'], 403);
}

$formsService = new Google\Service\Forms($client);

try {
    // 1. Get Form Structure to map questions
    $formStructure = $formsService->forms->get($formId);
    $items = $formStructure->getItems();
    
    // Fetch facilitators to know how many speakers there are
    $fac_stmt = $db->prepare("SELECT af.role FROM activity_facilitators af WHERE af.activity_id = :id ORDER BY af.role, af.af_id");
    $fac_stmt->execute(['id' => $activity_id]);
    $facilitatorsCount = count($fac_stmt->fetchAll(PDO::FETCH_ASSOC));

    function extractId($item) {
        if(isset($item['questionItem']['question']['questionId'])) return $item['questionItem']['question']['questionId'];
        if(isset($item['questionId'])) return $item['questionId'];
        if(isset($item['question']['questionId'])) return $item['question']['questionId'];
        return null;
    }
    
    $qMap = [];
    // 0: Data Privacy consent question
    $qMap[extractId($items[0])] = 'data_privacy';
    // 1-4: Static Text Items (Title, Venue, Date, SDG)
    // 5: Name
    $qMap[extractId($items[5])] = 'fullname';
    // 6: Age
    $qMap[extractId($items[6])] = 'age';
    // 7: Unit
    $qMap[extractId($items[7])] = 'unit';
    // 8: Contact
    $qMap[extractId($items[8])] = 'contact';
    // 9: Gender
    $qMap[extractId($items[9])] = 'gender';
    // 10: OSR
    $qMap[extractId($items[10])] = 'osr';
    
    $idx = 11;
    for($i=0; $i<$facilitatorsCount; $i++) {
        $idx++; // Skip Name TextItem
        $qMap[extractId($items[$idx++])] = "fac_{$i}_eff";
        $qMap[extractId($items[$idx++])] = "fac_{$i}_mot";
        $qMap[extractId($items[$idx++])] = "fac_{$i}_atf";
    }
    
    // Program Section
    $idx++; // Skip III. Program TextItem
    $qMap[extractId($items[$idx++])] = 'prog_0';
    $qMap[extractId($items[$idx++])] = 'prog_1';
    $qMap[extractId($items[$idx++])] = 'prog_2';
    
    // Logistics Section
    $idx++; // Skip IV. Activity Management TextItem
    $qMap[extractId($items[$idx++])] = 'log_0';
    $qMap[extractId($items[$idx++])] = 'log_1';
    $qMap[extractId($items[$idx++])] = 'log_2';
    
    $qMap[extractId($items[$idx++])] = 'best_topics';
    $qMap[extractId($items[$idx++])] = 'improvements';
    $qMap[extractId($items[$idx++])] = 'suggestions';
    $qMap[extractId($items[$idx++])] = 'oe';

    
    // 2. Fetch Responses
    $responsesData = $formsService->forms_responses->listFormsResponses($formId);
    $responses = $responsesData->getResponses();
    
    if(empty($responses)) {
        $responses = [];
    }
    
    // 3. Insert into Responses Database
    $table_name = "activity_" . $activity_id;
    $quoted_table = "`" . str_replace("`", "``", $table_name) . "`";
    
    // Create table if it doesn't exist
    $create_sql = "CREATE TABLE IF NOT EXISTS $quoted_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        response_id VARCHAR(255) UNIQUE,
        submitted_at DATETIME";
        
    $uniqueCols = array_unique(array_values($qMap));
    foreach ($uniqueCols as $colName) {
        $create_sql .= ",\n        `$colName` TEXT";
    }
    $create_sql .= "\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    try {
        $rdb->exec($create_sql);
    } catch(Exception $e) {
        error_log("Failed to create responses table: " . $e->getMessage());
    }
    
    $insertedCount = 0;
    foreach($responses as $response) {
        $responseId = $response['responseId'];
        $submittedAt = date('Y-m-d H:i:s', strtotime($response['createTime']));
        $answers = $response['answers'];
        
        $row = ['response_id' => $responseId, 'submitted_at' => $submittedAt];
        foreach($qMap as $qId => $colName) {
            if(!$qId) continue;
            if(isset($answers[$qId])) {
                $val = $answers[$qId]['textAnswers']['answers'][0]['value'] ?? null;
                $row[$colName] = $val;
            } else {
                $row[$colName] = null;
            }
        }
        
        // Build Insert Query
        $cols = array_keys($row);
        $placeholders = array_map(function($c) { return ":$c"; }, $cols);
        
        $sql = "INSERT IGNORE INTO $quoted_table (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $rdb->prepare($sql);
        $stmt->execute($row);
        if ($stmt->rowCount() > 0) {
            $insertedCount++;
        }
    }
    
    // 4. Recalculate Statistics
    require_once __DIR__ . '/recalculate_statistics.php';
    
    // We need evaluation_id to run recalculation
    $eval_stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :aid");
    $eval_stmt->execute(['aid' => $activity_id]);
    $eval = $eval_stmt->fetch(PDO::FETCH_ASSOC);
    
    $totalCount = 0;
    if ($eval && !empty($eval['evaluation_id'])) {
        recalculateActivityStatistics($db, $rdb, $activity_id, $eval['evaluation_id'], $quoted_table);
        
        $countStmt = $rdb->query("SELECT COUNT(*) FROM $quoted_table");
        $totalCount = $countStmt->fetchColumn();
    }
    
    sync_json_response([
        'success' => true, 
        'message' => "Successfully synced $insertedCount new responses.",
        'count' => $insertedCount,
        'total' => $totalCount
    ]);

} catch (Throwable $e) {
    error_log("Sync Google responses API error: " . $e->getMessage());
    sync_json_response(['success' => false, 'message' => 'API Error: ' . $e->getMessage()], 500);
}
