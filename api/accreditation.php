<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/utils/logger.php';

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
            
            // Log activity
            logActivity($db, $_SESSION['user_id'], "Added new accreditation: $name ($code)");

            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $db->lastInsertId());
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add accreditation: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation');
            exit;
        }
    } elseif ($action === 'add_category') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $parent_id = !empty($_POST['parent_category_id']) ? $_POST['parent_category_id'] : null;
        $names = is_array($_POST['name']) ? $_POST['name'] : [$_POST['name']];

        if (empty($acc_id) || empty($names)) {
            $_SESSION['error'] = 'Accreditation ID and Category Name are required.';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, parent_category_id, name) VALUES (:acc_id, :parent_id, :name)");
            foreach ($names as $name) {
                if (empty(trim($name))) continue;
                $stmt->execute([
                    'acc_id' => $acc_id,
                    'parent_id' => $parent_id,
                    'name' => trim($name)
                ]);
            }

            $_SESSION['success'] = 'Categories added successfully!';

            // Log activity
            $cat_list = implode(', ', array_filter($names, function($n) { return !empty(trim($n)); }));
            logActivity($db, $_SESSION['user_id'], "Added categories ($cat_list) to accreditation ID: $acc_id");

            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add categories: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }
    } elseif ($action === 'add_requirement') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $cat_id = $_POST['category_id'] ?? null;
        $names = is_array($_POST['name']) ? $_POST['name'] : [$_POST['name']];
        $codenames = is_array($_POST['codename']) ? $_POST['codename'] : [$_POST['codename']];

        if (empty($cat_id) || empty($names)) {
            $_SESSION['error'] = 'Category and Requirement Name are required.';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, name, codename) VALUES (:cat_id, :name, :codename)");
            foreach ($names as $i => $name) {
                if (empty(trim($name))) continue;
                $codename = $codenames[$i] ?? '';
                $stmt->execute([
                    'cat_id' => $cat_id,
                    'name' => trim($name),
                    'codename' => !empty(trim($codename)) ? trim($codename) : null
                ]);
            }

            $_SESSION['success'] = 'Requirements added successfully!';

            // Log activity
            $req_list = implode(', ', array_filter($names, function($n) { return !empty(trim($n)); }));
            logActivity($db, $_SESSION['user_id'], "Added requirements ($req_list) to category ID: $cat_id");

            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add requirements: ' . $e->getMessage();
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

            // Log activity
            logActivity($db, $_SESSION['user_id'], "Updated status of accreditation ID: $acc_id to $status");

            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to update status: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }
    } elseif ($action === 'edit_category') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $cat_id = $_POST['category_id'] ?? null;
        $name = trim($_POST['name'] ?? '');

        if (empty($cat_id) || empty($name)) {
            $_SESSION['error'] = 'Category ID and Name are required.';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE accreditation_categories SET name = :name WHERE category_id = :id");
            $stmt->execute(['name' => $name, 'id' => $cat_id]);
            $_SESSION['success'] = 'Category updated successfully!';

            // Log activity
            logActivity($db, $_SESSION['user_id'], "Updated category ID: $cat_id to '$name'");

            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to update category: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }
    } elseif ($action === 'edit_requirement') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $req_id = $_POST['requirement_id'] ?? null;
        $name = trim($_POST['name'] ?? '');
        $codename = trim($_POST['codename'] ?? '');

        try {
            $stmt = $db->prepare("UPDATE accreditation_requirement SET name = :name, codename = :codename WHERE requirement_id = :id");
            $stmt->execute([
                'name' => $name,
                'codename' => !empty($codename) ? $codename : null,
                'id' => $req_id
            ]);
            $_SESSION['success'] = 'Requirement updated successfully!';

            // Log activity
            logActivity($db, $_SESSION['user_id'], "Updated requirement ID: $req_id to '$name' ($codename)");

            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to update requirement: ' . $e->getMessage();
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }
    }
}

// GET ACTIONS (AJAX)
if ($action === 'delete_category') {
    header('Content-Type: application/json');
    $cat_id = $_GET['category_id'] ?? null;

    try {
        $stmt = $db->prepare("DELETE FROM accreditation_categories WHERE category_id = :id");
        $stmt->execute(['id' => $cat_id]);
        echo json_encode(['success' => true, 'message' => 'Category deleted successfully!']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete category: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'delete_requirement') {
    header('Content-Type: application/json');
    $req_id = $_GET['requirement_id'] ?? null;

    try {
        $stmt = $db->prepare("DELETE FROM accreditation_requirement WHERE requirement_id = :id");
        $stmt->execute(['id' => $req_id]);

        // Log activity
        logActivity($db, $_SESSION['user_id'] ?? 0, "Deleted requirement ID: $req_id");

        echo json_encode(['success' => true, 'message' => 'Requirement deleted successfully!']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete requirement: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'delete_accreditation') {
    header('Content-Type: application/json');
    $acc_id = $_GET['accreditation_id'] ?? null;

    try {
        // Categories and requirements are likely linked by FK with CASCADE or need manual deletion.
        // For now, let's assume standard deletion.
        $stmt = $db->prepare("DELETE FROM accreditations WHERE accreditation_id = :id");
        $stmt->execute(['id' => $acc_id]);

        // Log activity
        logActivity($db, $_SESSION['user_id'] ?? 0, "Deleted accreditation ID: $acc_id");

        echo json_encode(['success' => true, 'message' => 'Accreditation deleted successfully!']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete accreditation: ' . $e->getMessage()]);
        exit;
    }
}
?>
