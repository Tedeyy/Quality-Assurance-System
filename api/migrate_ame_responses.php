<?php
/**
 * Migration script to update existing activity evaluation response tables
 * from old column names to new AME format column names.
 * 
 * Run this script if you have existing response tables that need to be migrated.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/responses_database.php';

// Mapping from old column names to new column names
$columnMapping = [
    'prog_0' => 'prog_flow',
    'prog_1' => 'prog_contents',
    'prog_2' => 'prog_relevance',
    'log_0' => 'mgmt_facilitation',
    'log_1' => 'mgmt_venue',
    'log_2' => 'mgmt_time',
    'best_topics' => 'feedback_best',
    'improvements' => 'feedback_least',
    'suggestions' => 'feedback_suggestions',
    'oe' => 'feedback_overall',
];

// Old facilitator column pattern: fac_{i}_{metric}
// New facilitator column patterns: speaker_{i}_{metric}, organizer_{i}_{metric}

$db = new Database();
$rdb = (new ResponsesDatabase())->getConnection();

try {
    // Get all activity response tables
    $tables = $rdb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        if (!preg_match('/^activity_\d+$/', $table)) {
            continue; // Skip non-activity tables
        }
        
        echo "Processing table: $table\n";
        
        // Get existing columns
        $columns = $rdb->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $existingColumns = array_column($columns, 'Field');
        
        // Check if migration is needed
        $needsMigration = false;
        foreach (array_keys($columnMapping) as $oldCol) {
            if (in_array($oldCol, $existingColumns)) {
                $needsMigration = true;
                break;
            }
        }
        
        if (!$needsMigration && !preg_grep('/^fac_\d+/', $existingColumns)) {
            echo "  ✓ Already migrated or uses new format\n";
            continue;
        }
        
        // Add new columns if they don't exist
        foreach ($columnMapping as $oldCol => $newCol) {
            if (!in_array($newCol, $existingColumns)) {
                $sql = "ALTER TABLE `$table` ADD COLUMN `$newCol` TEXT";
                if (preg_grep('/^(osr|prog_|mgmt_|feedback_overall)/', [$newCol])) {
                    $sql = "ALTER TABLE `$table` ADD COLUMN `$newCol` INT";
                }
                try {
                    $rdb->exec($sql);
                    echo "  Added column: $newCol\n";
                } catch (Exception $e) {
                    echo "  Warning: Could not add column $newCol: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // Migrate data
        foreach ($columnMapping as $oldCol => $newCol) {
            if (in_array($oldCol, $existingColumns)) {
                $sql = "UPDATE `$table` SET `$newCol` = `$oldCol` WHERE `$oldCol` IS NOT NULL";
                try {
                    $rdb->exec($sql);
                    echo "  Migrated data: $oldCol → $newCol\n";
                } catch (Exception $e) {
                    echo "  Warning: Could not migrate $oldCol: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // Note: Manual migration of fac_* columns may be needed as they require logic to map
        // old indices to speaker/organizer indices. This requires activity context.
        $facColumns = preg_grep('/^fac_\d+_/', $existingColumns);
        if (!empty($facColumns)) {
            echo "  ⚠ Old facilitator columns found: " . implode(', ', $facColumns) . "\n";
            echo "  These require manual mapping based on activity facilitator roles.\n";
        }
        
        echo "\n";
    }
    
    echo "Migration completed.\n";
    
} catch (Exception $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
}
?>
