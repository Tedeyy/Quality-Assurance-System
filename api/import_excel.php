<?php
/**
 * Primary Excel Import Entry Point
 * Routes to specific template handlers based on template_type
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Suppress HTML error output and handle errors as JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    echo json_encode(['success' => false, 'message' => "PHP Error [$errno]: $errstr in $errfile on line $errline"]);
    exit;
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

$template_type = $_POST['template_type'] ?? 'aaccup';

$normalizeSectionLabel = function($text) {
    $text = trim($text);
    $text = preg_replace('/\s+/', ' ', $text);
    return strtoupper($text);
};

$getOrCreateCat = function($db, $acc_id, $name, $parent_id) {
    $stmt = $db->prepare("SELECT category_id FROM accreditation_categories WHERE accreditation_id = ? AND name = ? AND (parent_category_id = ? OR (parent_category_id IS NULL AND ? IS NULL)) LIMIT 1");
    $stmt->execute([$acc_id, $name, $parent_id, $parent_id]);
    $res = $stmt->fetch();
    if ($res) return $res['category_id'];
    $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, ?)");
    $stmt->execute([$acc_id, $name, $parent_id]);
    return $db->lastInsertId();
};

switch ($template_type) {
    case 'aaccup':
    case 'aaccup_program':
        require_once __DIR__ . '/scanner/aaccupprogram.php';
        break;
    case 'aaccup_institution':
        require_once __DIR__ . '/scanner/aaccupinstitution.php';
        break;
    case 'copc':
        require_once __DIR__ . '/scanner/copc.php';
        break;
    case 'ched':
        echo json_encode(['success' => false, 'message' => 'CHED template is not yet implemented.']);
        break;
    case 'iso':
        echo json_encode(['success' => false, 'message' => 'ISO template is not yet implemented.']);
        break;
    default:
        echo json_encode(['success' => false, 'message' => "Unknown template type: {$template_type}"]);
        break;
}
