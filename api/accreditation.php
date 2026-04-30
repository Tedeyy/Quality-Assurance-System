<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $status = $_POST['status'] ?? 'In Progress';

        if (empty($code) || empty($name) || ($status !== 'Inactive' && $status !== 'Completed' && empty($deadline))) {
            $_SESSION['error'] = 'Code, Name, and Deadline are required.';
            header('Location: ../views/feed.php?action=accreditation');
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO accreditations (code, name, description, deadline, status) VALUES (:code, :name, :description, :deadline, :status)");
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'deadline' => $deadline,
                'status' => $status
            ]);

            $_SESSION['success'] = 'Accreditation added successfully!';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $db->lastInsertId());
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add accreditation: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation');
            exit;
        }
    }
}
