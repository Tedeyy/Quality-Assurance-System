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

switch ($template_type) {
    case 'aaccup':
    case 'aaccup_program':
        require_once __DIR__ . '/scanner/aaccupprogram.php';
        break;
    case 'aaccup_institution':
        require_once __DIR__ . '/scanner/aaccupinstitution.php';
        break;
        echo json_encode(['success' => false, 'message' => 'CHED template is not yet implemented.']);
        break;
    case 'iso':
        echo json_encode(['success' => false, 'message' => 'ISO template is not yet implemented.']);
        break;
    default:
        echo json_encode(['success' => false, 'message' => "Unknown template type: {$template_type}"]);
        break;
}
