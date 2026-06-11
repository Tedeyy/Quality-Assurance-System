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
    
    // Fetch facilitators with their roles
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
    $facilitatorsList = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    // 10: OSR (Overall Service Rating)
    $qMap[extractId($items[10])] = 'osr';
    
    // 11+: Facilitator Sections (speakers and organizers)
    $idx = 11;
    $speakerIdx = 0;
    $organizerIdx = 0;
    
    foreach($facilitatorsList as $fac) {
        $idx++; // Skip Name TextItem
        if($fac['role'] === 'speaker') {
            // For speakers: Effectiveness, Mastery of Topic, Ability to Facilitate
            $qMap[extractId($items[$idx++])] = "speaker_{$speakerIdx}_effectiveness";
            $qMap[extractId($items[$idx++])] = "speaker_{$speakerIdx}_mastery";
            $qMap[extractId($items[$idx++])] = "speaker_{$speakerIdx}_facilitation";
            $speakerIdx++;
        } else {
            // For organizers: Organization & Coordination, Clarity of Communication, Engagement & Interaction
            $qMap[extractId($items[$idx++])] = "organizer_{$organizerIdx}_coordination";
            $qMap[extractId($items[$idx++])] = "organizer_{$organizerIdx}_clarity";
            $qMap[extractId($items[$idx++])] = "organizer_{$organizerIdx}_engagement";
            $organizerIdx++;
        }
    }
    
    // Program Section (III. Program and Methodology)
    $idx++; // Skip III. Program TextItem
    $qMap[extractId($items[$idx++])] = 'prog_flow';
    $qMap[extractId($items[$idx++])] = 'prog_contents';
    $qMap[extractId($items[$idx++])] = 'prog_relevance';
    
    // Activity Management Section (IV. Activity Management)
    $idx++; // Skip IV. Activity Management TextItem
    $qMap[extractId($items[$idx++])] = 'mgmt_facilitation';
    $qMap[extractId($items[$idx++])] = 'mgmt_venue';
    $qMap[extractId($items[$idx++])] = 'mgmt_time';
    
    // Qualitative Feedback (Section V-VIII)
    $qMap[extractId($items[$idx++])] = 'feedback_best';
    $qMap[extractId($items[$idx++])] = 'feedback_least';
    $qMap[extractId($items[$idx++])] = 'feedback_suggestions';
    $qMap[extractId($items[$idx++])] = 'feedback_overall';

    
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
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `response_id` VARCHAR(255) UNIQUE,
        `submitted_at` DATETIME,
        `data_privacy` VARCHAR(50),
        `fullname` VARCHAR(255),
        `age` VARCHAR(50),
        `unit` VARCHAR(255),
        `contact` VARCHAR(50),
        `gender` VARCHAR(50),
        `osr` INT,
        `prog_flow` INT,
        `prog_contents` INT,
        `prog_relevance` INT,
        `mgmt_facilitation` INT,
        `mgmt_venue` INT,
        `mgmt_time` INT,
        `feedback_best` TEXT,
        `feedback_least` TEXT,
        `feedback_suggestions` TEXT,
        `feedback_overall` INT";
    
    // Add facilitator columns dynamically
    $speakerIdx = 0;
    $organizerIdx = 0;
    foreach($facilitatorsList as $fac) {
        if($fac['role'] === 'speaker') {
            $create_sql .= ",\n        `speaker_{$speakerIdx}_effectiveness` INT,\n        `speaker_{$speakerIdx}_mastery` INT,\n        `speaker_{$speakerIdx}_facilitation` INT";
            $speakerIdx++;
        } else {
            $create_sql .= ",\n        `organizer_{$organizerIdx}_coordination` INT,\n        `organizer_{$organizerIdx}_clarity` INT,\n        `organizer_{$organizerIdx}_engagement` INT";
            $organizerIdx++;
        }
    }
    
    $create_sql .= "\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    try {
        $rdb->exec($create_sql);
    } catch(Exception $e) {
        error_log("Failed to create responses table: " . $e->getMessage());
    }
    
    // Check if table exists and add missing columns if needed
    try {
        $checkTable = $rdb->query("DESCRIBE $quoted_table")->fetchAll(PDO::FETCH_ASSOC);
        $existingCols = array_column($checkTable, 'Field');
        
        // Define all required columns
        $requiredCols = [
            'data_privacy' => 'VARCHAR(50)',
            'fullname' => 'VARCHAR(255)',
            'age' => 'VARCHAR(50)',
            'unit' => 'VARCHAR(255)',
            'contact' => 'VARCHAR(50)',
            'gender' => 'VARCHAR(50)',
            'osr' => 'INT',
            'prog_flow' => 'INT',
            'prog_contents' => 'INT',
            'prog_relevance' => 'INT',
            'mgmt_facilitation' => 'INT',
            'mgmt_venue' => 'INT',
            'mgmt_time' => 'INT',
            'feedback_best' => 'TEXT',
            'feedback_least' => 'TEXT',
            'feedback_suggestions' => 'TEXT',
            'feedback_overall' => 'INT'
        ];
        
        // Add facilitator columns to required list
        $speakerIdx = 0;
        $organizerIdx = 0;
        foreach($facilitatorsList as $fac) {
            if($fac['role'] === 'speaker') {
                $requiredCols["speaker_{$speakerIdx}_effectiveness"] = 'INT';
                $requiredCols["speaker_{$speakerIdx}_mastery"] = 'INT';
                $requiredCols["speaker_{$speakerIdx}_facilitation"] = 'INT';
                $speakerIdx++;
            } else {
                $requiredCols["organizer_{$organizerIdx}_coordination"] = 'INT';
                $requiredCols["organizer_{$organizerIdx}_clarity"] = 'INT';
                $requiredCols["organizer_{$organizerIdx}_engagement"] = 'INT';
                $organizerIdx++;
            }
        }
        
        // Add missing columns
        foreach ($requiredCols as $colName => $colType) {
            if (!in_array($colName, $existingCols)) {
                $alterSql = "ALTER TABLE $quoted_table ADD COLUMN `$colName` $colType";
                try {
                    $rdb->exec($alterSql);
                    error_log("Added missing column: $colName");
                } catch (Exception $e) {
                    error_log("Failed to add column $colName: " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error checking table structure: " . $e->getMessage());
    }
    
    // Get the actual columns in the table to avoid inserting into non-existent columns
    $tableColumns = [];
    $columnInfo = $rdb->query("DESCRIBE $quoted_table")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columnInfo as $col) {
        $tableColumns[] = $col['Field'];
    }
    
    $insertedCount = 0;
    foreach($responses as $response) {
        $responseId = $response['responseId'];
        $submittedAt = date('Y-m-d H:i:s', strtotime($response['createTime']));
        $answers = $response['answers'];
        
        $row = [];
        
        // Always include response_id and submitted_at if they exist in table
        if(in_array('response_id', $tableColumns)) {
            $row['response_id'] = $responseId;
        }
        if(in_array('submitted_at', $tableColumns)) {
            $row['submitted_at'] = $submittedAt;
        }
        
        // Build list of numeric columns for this request
        $numericCols = ['osr', 'prog_flow', 'prog_contents', 'prog_relevance', 
                       'mgmt_facilitation', 'mgmt_venue', 'mgmt_time', 'feedback_overall'];
        $speakerCounter = 0;
        $organizerCounter = 0;
        foreach($facilitatorsList as $fac) {
            if($fac['role'] === 'speaker') {
                $numericCols[] = "speaker_{$speakerCounter}_effectiveness";
                $numericCols[] = "speaker_{$speakerCounter}_mastery";
                $numericCols[] = "speaker_{$speakerCounter}_facilitation";
                $speakerCounter++;
            } else {
                $numericCols[] = "organizer_{$organizerCounter}_coordination";
                $numericCols[] = "organizer_{$organizerCounter}_clarity";
                $numericCols[] = "organizer_{$organizerCounter}_engagement";
                $organizerCounter++;
            }
        }
        
        foreach($qMap as $qId => $colName) {
            if(!$qId) continue;
            // Only process columns that exist in the table
            if(!in_array($colName, $tableColumns)) {
                continue;
            }
            
            if(isset($answers[$qId])) {
                // Extract value from textAnswers (works for both text and choice responses)
                $val = $answers[$qId]['textAnswers']['answers'][0]['value'] ?? null;
                
                if(in_array($colName, $numericCols) && $val !== null) {
                    $row[$colName] = (int)$val;
                } else {
                    $row[$colName] = $val;
                }
            } else {
                $row[$colName] = null;
            }
        }
        
        // Build Insert Query - only use columns that exist
        $cols = array_filter(array_keys($row), function($c) use ($tableColumns) {
            return in_array($c, $tableColumns);
        });
        
        if(empty($cols)) {
            continue; // Skip if no valid columns to insert
        }
        
        $placeholders = array_map(function($c) { return ":$c"; }, $cols);
        $rowData = array_intersect_key($row, array_flip($cols));
        
        $sql = "INSERT IGNORE INTO $quoted_table (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $rdb->prepare($sql);
        $stmt->execute($rowData);
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
