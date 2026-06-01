<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/MailService.php';

$database = new Database();
$db = $database->getConnection();

$activity_id = $_POST['activity_id'] ?? null;
if (!$activity_id) die("Missing Activity ID");
$activity_id = (int) $activity_id;
if ($activity_id <= 0) die("Invalid Activity ID");

function ensureActivityResponseTable(PDO $rdb, PDO $db, int $activity_id): string {
    $table_name = "activity_" . $activity_id;
    $quoted_table = "`" . str_replace("`", "``", $table_name) . "`";

    $fac_stmt = $db->prepare("SELECT COUNT(*) FROM activity_facilitators WHERE activity_id = :aid");
    $fac_stmt->execute(['aid' => $activity_id]);
    $facilitatorsCount = (int) $fac_stmt->fetchColumn();

    $createTable = "CREATE TABLE IF NOT EXISTS $quoted_table (
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

    return $quoted_table;
}

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
    $table_name = ensureActivityResponseTable($rdb, $db, $activity_id);

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

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $cols[] = $f;
            $vals[] = ":$f";
            $params[$f] = $_POST[$f];
        }
    }
    foreach ($_POST as $key => $val) {
        if (strpos($key, 'fac_') === 0 || strpos($key, 'prog_') === 0 || strpos($key, 'log_') === 0) {
            $cols[] = $key;
            $vals[] = ":$key";
            $params[$key] = $val;
        }
    }
    $insertQuery = "INSERT INTO $table_name (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
    $rdb->prepare($insertQuery)->execute($params);

    // 3. Recalculate Statistics
    require_once __DIR__ . '/recalculate_statistics.php';
    recalculateActivityStatistics($db, $rdb, $activity_id, $evaluation_id, $table_name);

    // --- SEND EMAIL ACKNOWLEDGMENT ---
    $act_stmt = $db->prepare("SELECT title FROM activities WHERE activity_id = :aid");
    $act_stmt->execute(['aid' => $activity_id]);
    $act_title = $act_stmt->fetchColumn();

    $summary = [];
    $label_map = [1 => 'Poor', 2 => 'Fair', 3 => 'Satisfactory', 4 => 'Very Satisfactory', 5 => 'Excellent'];
    
    // Core ratings
    if (isset($_POST['osr'])) $summary['Overall Service Rating'] = $label_map[$_POST['osr']] ?? $_POST['osr'];
    if (isset($_POST['oe'])) $summary['Overall Experience'] = $label_map[$_POST['oe']] ?? $_POST['oe'];
    
    // Feedback
    if (isset($_POST['best_topics'])) $summary['Best Topics/Insights'] = htmlspecialchars($_POST['best_topics']);
    if (isset($_POST['improvements'])) $summary['Suggested Improvements'] = htmlspecialchars($_POST['improvements']);

    MailService::sendAcknowledgment($_POST['email'], $_POST['fullname'] ?: 'Valued Participant', $act_title, $summary);
}

echo "<script>
        localStorage.removeItem('eval_data_" . $activity_id . "');
        localStorage.removeItem('eval_step_" . $activity_id . "');
      </script>
      <div style='text-align:center; padding: 100px; font-family: sans-serif;'>
        <h1 style='color: #059669;'>Evaluation Submitted Successfully!</h1>
        <p>Thank you for your feedback. Your response has been recorded and analytics have been updated.</p>
        <a href='javascript:window.close()' style='color: #2563eb; text-decoration: none;'>Close Window</a>
      </div>";
