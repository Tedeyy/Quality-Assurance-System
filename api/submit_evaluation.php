<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';

$database = new Database();
$db = $database->getConnection();

$activity_id = $_POST['activity_id'] ?? null;
if (!$activity_id) die("Missing Activity ID");

// 1. Fetch Evaluation ID
$stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :aid");
$stmt->execute(['aid' => $activity_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);
$evaluation_id = $eval['evaluation_id'];

// 2. Save Raw Response to Dynamic Activity Table in Dedicated DB
require_once __DIR__ . '/../config/responses_database.php';
$resp_db_class = new ResponsesDatabase();
$rdb = $resp_db_class->getConnection();

if ($rdb) {
    $table_name = "activity_" . $activity_id;

    // Check if email already exists
    $check = $rdb->prepare("SELECT id FROM $table_name WHERE email = :email");
    $check->execute(['email' => $_POST['email']]);
    if ($check->fetch()) {
        echo "<div style='text-align:center; padding: 100px; font-family: sans-serif;'>
                <h1 style='color: #dc2626;'>Already Submitted</h1>
                <p>You have already sent a response for this activity evaluation.</p>
                <a href='javascript:history.back()' style='color: #2563eb; text-decoration: none;'>Go Back</a>
              </div>";
        exit;
    }

    $cols = []; $vals = []; $params = [];
    $fields = ['email', 'fullname', 'age', 'gender', 'contact', 'unit', 'osr', 'best_topics', 'improvements', 'oe'];
    $rating_map = [1 => 0, 2 => 25, 3 => 50, 4 => 75, 5 => 100];

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $cols[] = $f;
            $vals[] = ":$f";
            $val = $_POST[$f];
            if (in_array($f, ['osr', 'oe']) && isset($rating_map[$val])) {
                $val = $rating_map[$val];
            }
            $params[$f] = $val;
        }
    }
    foreach ($_POST as $key => $val) {
        if (strpos($key, 'fac_') === 0 || strpos($key, 'prog_') === 0 || strpos($key, 'log_') === 0) {
            $cols[] = $key;
            $vals[] = ":$key";
            if (isset($rating_map[$val])) {
                $val = $rating_map[$val];
            }
            $params[$key] = $val;
        }
    }
    $insertQuery = "INSERT INTO $table_name (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
    $rdb->prepare($insertQuery)->execute($params);
}

/* 
// 3. Recalculate Statistics (Temporarily disabled by USER request)
$all_responses = [];
$total_respondents = 0;
...
*/

echo "<div style='text-align:center; padding: 100px; font-family: sans-serif;'>
        <h1 style='color: #059669;'>Evaluation Submitted Successfully!</h1>
        <p>Thank you for your feedback. You may now close this window.</p>
      </div>";
