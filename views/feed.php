<?php
session_start();

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/component/header.php';
require_once __DIR__ . '/component/navbar.php';
require_once __DIR__ . '/component/confirmationpane.php';

// Display any session messages
if (isset($_SESSION['error'])) {
    echo '<div style="background-color: #fee2e2; color: #991b1b; padding: 1rem; text-align: center; font-weight: 500; border-bottom: 1px solid #f87171;">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    echo '<div style="background-color: #dcfce7; color: #166534; padding: 1rem; text-align: center; font-weight: 500; border-bottom: 1px solid #4ade80;">' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}

$action = $_GET['action'] ?? '';

if (isset($_SESSION['user_id'])) {
    // Authenticated routes
    if ($action === 'dashboard' || $action === '' || $action === 'login' || $action === 'signup') {
        require_once __DIR__ . '/content/dashboard.php';
    } elseif ($action === 'accreditation') {
        require_once __DIR__ . '/content/acctracker.php';
    } elseif ($action === 'activity') {
        require_once __DIR__ . '/content/ame.php';
    } elseif ($action === 'document') {
        require_once __DIR__ . '/content/doctracker.php';
    } else {
        require_once __DIR__ . '/content/dashboard.php';
    }
} else {
    // Unauthenticated routes
    if ($action === 'signup') {
        require_once __DIR__ . '/content/signup.php';
    } else {
        require_once __DIR__ . '/content/login.php';
    }
}

require_once __DIR__ . '/component/footer.php';
?>