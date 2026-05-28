<?php
session_start();

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/MailService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php#contact');
    exit;
}

$subject = trim($_POST['subject'] ?? '');
$fromEmail = trim($_POST['inquiree_email'] ?? '');
$message = trim($_POST['message'] ?? '');
$adminEmail = getenv('ADMIN_EMAIL') ?: '';

if ($subject === '' || $fromEmail === '' || $message === '') {
    header('Location: ../index.php?contact=missing#contact');
    exit;
}

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    header('Location: ../index.php?contact=invalid_email#contact');
    exit;
}

if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    error_log('Contact form failed: ADMIN_EMAIL is missing or invalid.');
    header('Location: ../index.php?contact=config_error#contact');
    exit;
}

if (strlen($subject) > 160 || strlen($message) > 5000) {
    header('Location: ../index.php?contact=too_long#contact');
    exit;
}

$sent = MailService::sendContactInquiry($fromEmail, $subject, $message);

header('Location: ../index.php?contact=' . ($sent ? 'sent' : 'failed') . '#contact');
exit;
