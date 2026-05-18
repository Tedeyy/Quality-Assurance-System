<?php
require_once __DIR__ . '/config/database.php';
$db = (new Database())->getConnection();

$tables = ['activities', 'activity_statistics', 'activity_evaluation', 'activity_speaker_rating', 'activity_organizer_rating'];

foreach ($tables as $table) {
    echo "--- $table ---\n";
    try {
        $res = $db->query("DESCRIBE $table");
        while($row = $res->fetch(PDO::FETCH_ASSOC)) {
            echo "{$row['Field']} | {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
