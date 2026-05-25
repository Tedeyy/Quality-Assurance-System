<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/responses_database.php';
require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$activity_id = $_GET['id'] ?? null;
if (!$activity_id) {
    echo json_encode(['success' => false, 'message' => 'Missing activity ID']);
    exit;
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
    echo json_encode(['success' => false, 'message' => 'No Google Form is associated with this activity. Please generate one first.']);
    exit;
}
$formId = $eval['ame_form_id'];

// Get User Token
$stmt = $db->prepare("SELECT google_access_token, google_refresh_token FROM users WHERE user_id = :uid");
$stmt->execute(['uid' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (empty($user['google_access_token'])) {
    echo json_encode(['success' => false, 'message' => 'Please link your Google account in your profile.']);
    exit;
}

$client = new Google\Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setAccessToken(json_decode($user['google_access_token'], true));

if ($client->isAccessTokenExpired() && $user['google_refresh_token']) {
    $newToken = $client->fetchAccessTokenWithRefreshToken($user['google_refresh_token']);
    $client->setAccessToken($newToken);
    $stmt = $db->prepare("UPDATE users SET google_access_token = :token WHERE user_id = :uid");
    $stmt->execute(['token' => json_encode($client->getAccessToken()), 'uid' => $_SESSION['user_id']]);
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
    $qMap[extractId($items[0])] = 'email';
    $qMap[extractId($items[1])] = 'fullname';
    $qMap[extractId($items[2])] = 'age';
    $qMap[extractId($items[3])] = 'contact';
    $qMap[extractId($items[4])] = 'unit';
    $qMap[extractId($items[5])] = 'gender';
    $qMap[extractId($items[6])] = 'osr';
    
    $idx = 7;
    for($i=0; $i<$facilitatorsCount; $i++) {
        $gridQs = $items[$idx]['questionGroupItem']['questions'];
        $qMap[extractId($gridQs[0])] = "fac_{$i}_eff";
        $qMap[extractId($gridQs[1])] = "fac_{$i}_mot";
        $qMap[extractId($gridQs[2])] = "fac_{$i}_atf";
        $idx++;
    }
    
    // Program Grid
    $gridQs = $items[$idx]['questionGroupItem']['questions'];
    $qMap[extractId($gridQs[0])] = 'prog_0';
    $qMap[extractId($gridQs[1])] = 'prog_1';
    $qMap[extractId($gridQs[2])] = 'prog_2';
    $qMap[extractId($gridQs[3])] = 'prog_3';
    $idx++;
    
    // Logistics Grid
    $gridQs = $items[$idx]['questionGroupItem']['questions'];
    $qMap[extractId($gridQs[0])] = 'log_0';
    $qMap[extractId($gridQs[1])] = 'log_1';
    $qMap[extractId($gridQs[2])] = 'log_2';
    $idx++;
    
    $qMap[extractId($items[$idx++])] = 'best_topics';
    $qMap[extractId($items[$idx++])] = 'improvements';
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
                
                // Convert 1-5 scale to 0-100 scale for rating columns
                if ($val !== null && (strpos($colName, 'fac_') === 0 || strpos($colName, 'prog_') === 0 || strpos($colName, 'log_') === 0 || in_array($colName, ['osr', 'oe']))) {
                    if (is_numeric($val) && in_array((int)$val, [1, 2, 3, 4, 5])) {
                        $rating_map = [1 => 0, 2 => 25, 3 => 50, 4 => 75, 5 => 100];
                        $val = $rating_map[(int)$val];
                    }
                }
                
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
    
    echo json_encode([
        'success' => true, 
        'message' => "Successfully synced $insertedCount new responses.",
        'count' => $insertedCount,
        'total' => $totalCount
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'API Error: ' . $e->getMessage()]);
}
