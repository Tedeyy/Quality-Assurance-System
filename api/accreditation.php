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
    } elseif ($action === 'add_category') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $parent_id = !empty($_POST['parent_category_id']) ? $_POST['parent_category_id'] : null;

        if (empty($acc_id) || empty($name)) {
            $_SESSION['error'] = 'Accreditation ID and Category Name are required.';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, parent_category_id, name) VALUES (:acc_id, :parent_id, :name)");
            $stmt->execute([
                'acc_id' => $acc_id,
                'parent_id' => $parent_id,
                'name' => $name
            ]);

            $_SESSION['success'] = 'Category added successfully!';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add category: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }
    } elseif ($action === 'add_requirement') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $cat_id = $_POST['category_id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $codename = trim($_POST['codename'] ?? '');

        if (empty($cat_id) || empty($name)) {
            $_SESSION['error'] = 'Category and Requirement Name are required.';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, name, codename) VALUES (:cat_id, :name, :codename)");
            $stmt->execute([
                'cat_id' => $cat_id,
                'name' => $name,
                'codename' => !empty($codename) ? $codename : null
            ]);

            $_SESSION['success'] = 'Requirement added successfully!';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add requirement: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }
    } elseif ($action === 'update_status') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $status = $_POST['status'] ?? null;
        $deadline = $_POST['deadline'] ?? null;

        if (empty($acc_id) || empty($status)) {
            $_SESSION['error'] = 'Accreditation ID and Status are required.';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }

        try {
            $sql = "UPDATE accreditations SET status = :status";
            $params = [
                'status' => $status,
                'id' => $acc_id
            ];

            if ($status === 'In Progress' && !empty($deadline)) {
                $sql .= ", deadline = :deadline";
                $params['deadline'] = $deadline;
            } elseif ($status === 'Inactive' || $status === 'Completed') {
                $sql .= ", deadline = NULL";
            }

            $sql .= " WHERE accreditation_id = :id";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $_SESSION['success'] = 'Status updated successfully!';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to update status: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }
    }
}
