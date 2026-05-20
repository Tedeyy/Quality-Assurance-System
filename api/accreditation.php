<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/utils/logger.php';

$db = (new Database())->getConnection();
$action = $_GET['action'] ?? '';

function deleteCategoryRecursive($db, $cat_id) {
    // 1. Get subcategories
    $stmt = $db->prepare("SELECT category_id FROM accreditation_categories WHERE parent_category_id = ?");
    $stmt->execute([$cat_id]);
    $subs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($subs as $sub_id) {
        deleteCategoryRecursive($db, $sub_id);
    }
    
    // 2. Delete requirements in this category
    $stmt = $db->prepare("DELETE FROM accreditation_requirement WHERE category_id = ?");
    $stmt->execute([$cat_id]);
    
    // 3. Delete this category
    $stmt = $db->prepare("DELETE FROM accreditation_categories WHERE category_id = ?");
    $stmt->execute([$cat_id]);
}

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

            $redirect = $_POST['redirect_url'] ?? '';
            if (empty($redirect)) $redirect = '../views/feed.php?action=accreditation&accreditation_id=' . $db->lastInsertId();
            header("Location: " . $redirect);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to add accreditation: ' . $e->getMessage();
            $redirect = $_POST['redirect_url'] ?? '';
            if (empty($redirect)) $redirect = '../views/feed.php?action=accreditation';
            header("Location: " . $redirect);
            exit;
        }
    } elseif ($action === 'edit') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($acc_id) || empty($code) || empty($name)) {
            $_SESSION['error'] = 'ID, Code, and Name are required.';
            header('Location: ../views/feed.php?action=accreditation&accreditation_id=' . $acc_id);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE accreditations SET code = :code, name = :name, description = :description WHERE accreditation_id = :id");
            $stmt->execute([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'id' => $acc_id
            ]);

            $_SESSION['success'] = 'Accreditation updated successfully!';
            
            // Log activity
            logActivity($db, $_SESSION['user_id'], "Updated accreditation: $name ($code)");

            $redirect = $_POST['redirect_url'] ?? '';
            if (empty($redirect)) $redirect = '../views/feed.php?action=accreditation&accreditation_id=' . $acc_id;
            header("Location: " . $redirect);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to update accreditation: ' . $e->getMessage();
            $redirect = $_POST['redirect_url'] ?? '';
            if (empty($redirect)) $redirect = '../views/feed.php?action=accreditation&accreditation_id=' . $acc_id;
            header("Location: " . $redirect);
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
            $parent_id = !empty($_POST['parent_category_id']) ? $_POST['parent_category_id'] : null;
            $stmt = $db->prepare("UPDATE accreditation_categories SET name = :name, parent_category_id = :parent_id WHERE category_id = :id");
            $stmt->execute(['name' => $name, 'parent_id' => $parent_id, 'id' => $cat_id]);
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
            $cat_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
            $stmt = $db->prepare("UPDATE accreditation_requirement SET name = :name, codename = :codename, category_id = :cat_id WHERE requirement_id = :id");
            $stmt->execute([
                'name' => $name,
                'codename' => !empty($codename) ? $codename : null,
                'cat_id' => $cat_id,
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
    } elseif ($action === 'review_submission') {
        $req_id = $_POST['requirement_id'] ?? null;
        $status = $_POST['status'] ?? 'Pending';
        $remarks = trim($_POST['remarks'] ?? '');

        if (empty($req_id)) {
            echo json_encode(['success' => false, 'message' => 'Requirement ID is required.']);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE accreditation_requirement_submissions SET status = :status, remarks = :remarks, marked_by = :marked_by WHERE requirement_id = :req_id");
            $stmt->execute([
                'status' => $status,
                'remarks' => $remarks,
                'marked_by' => $_SESSION['user_id'],
                'req_id' => $req_id
            ]);
            
            // Log activity
            logActivity($db, $_SESSION['user_id'], "Marked submission for requirement ID: $req_id as $status");

            echo json_encode(['success' => true, 'message' => 'Review saved successfully!']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to save review: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'add_proof') {
        $acc_id = $_POST['accreditation_id'] ?? null;
        $req_id = $_POST['requirement_id'] ?? null;
        $redirect = $_POST['redirect_url'] ?? '';
        if (empty($redirect)) $redirect = '../views/feed.php?action=accreditation&accreditation_id=' . $acc_id;
        $wants_json = (
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        );
        
        $proof_names = $_POST['proof_names'] ?? [];
        if (!is_array($proof_names)) {
            $proof_names = [$proof_names];
        }
        if (isset($_POST['proof_name']) && trim($_POST['proof_name']) !== '') {
            $proof_names[] = $_POST['proof_name'];
        }

        // Clean up empty values
        $proof_names = array_filter(array_map('trim', $proof_names));

        if (empty($req_id) || empty($proof_names)) {
            $_SESSION['error'] = 'Requirement ID and Proof Name are required.';
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $_SESSION['error']]);
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO document_bridge (requirement_id, proof_name) VALUES (:req_id, :proof_name)");
            $created_proofs = [];
            $db->beginTransaction();
            foreach ($proof_names as $proof_name) {
                $stmt->execute([
                    'req_id' => $req_id,
                    'proof_name' => $proof_name
                ]);
                $created_proofs[] = [
                    'bridge_id' => $db->lastInsertId(),
                    'requirement_id' => $req_id,
                    'proof_name' => $proof_name,
                    'document_id' => null,
                    'submission_id' => null,
                    'doc_code' => null,
                    'doc_category' => null,
                    'doc_purpose' => null,
                    'sub_status' => null,
                    'sub_link' => null,
                    'office_name' => null,
                ];
                logActivity($db, $_SESSION['user_id'], "Added proof of compliance '$proof_name' to requirement ID: $req_id");
            }
            $db->commit();
            $_SESSION['success'] = 'Proofs of compliance added successfully!';
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => $_SESSION['success'],
                    'requirement_id' => $req_id,
                    'proofs' => $created_proofs
                ]);
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = 'Failed to add proofs of compliance: ' . $e->getMessage();
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $_SESSION['error']]);
                exit;
            }
            header('Location: ' . $redirect);
            exit;
        }
    } elseif ($action === 'link_document') {
        header('Content-Type: application/json');
        $bridge_id = $_POST['bridge_id'] ?? null;
        $doc_id = $_POST['document_id'] ?? null;

        if (empty($bridge_id) || empty($doc_id)) {
            echo json_encode(['success' => false, 'message' => 'Bridge ID and Document ID are required.']);
            exit;
        }

        try {
            // Unlink any existing submission if we are linking an institutional document
            $stmt = $db->prepare("UPDATE document_bridge SET document_id = :doc_id, submission_id = NULL WHERE bridge_id = :bridge_id");
            $stmt->execute([
                'doc_id' => $doc_id,
                'bridge_id' => $bridge_id
            ]);
            logActivity($db, $_SESSION['user_id'], "Linked document ID: $doc_id to bridge ID: $bridge_id");
            echo json_encode(['success' => true, 'message' => 'Document linked successfully!']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to link document: ' . $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'unlink_proof') {
        header('Content-Type: application/json');
        $bridge_id = $_POST['bridge_id'] ?? null;

        if (empty($bridge_id)) {
            echo json_encode(['success' => false, 'message' => 'Bridge ID is required.']);
            exit;
        }

        try {
            $stmt = $db->prepare("UPDATE document_bridge SET document_id = NULL, submission_id = NULL WHERE bridge_id = :bridge_id");
            $stmt->execute(['bridge_id' => $bridge_id]);
            logActivity($db, $_SESSION['user_id'], "Unlinked document/submission from bridge ID: $bridge_id");
            echo json_encode(['success' => true, 'message' => 'Proof of compliance unlinked successfully!']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to unlink proof: ' . $e->getMessage()]);
            exit;
        }
    }
}

// GET ACTIONS (AJAX)
if ($action === 'delete_submission') {
    header('Content-Type: application/json');
    $req_id = $_GET['requirement_id'] ?? null;

    try {
        $stmt = $db->prepare("DELETE FROM accreditation_requirement_submissions WHERE requirement_id = :id");
        $stmt->execute(['id' => $req_id]);
        
        // Log activity
        logActivity($db, $_SESSION['user_id'] ?? 0, "Deleted submission for requirement ID: $req_id");

        echo json_encode(['success' => true, 'message' => 'Submission removed successfully!']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to remove submission: ' . $e->getMessage()]);
        exit;
    }
} elseif ($action === 'delete_category') {
    header('Content-Type: application/json');
    $cat_id = $_GET['category_id'] ?? null;

    try {
        $db->beginTransaction();
        deleteCategoryRecursive($db, $cat_id);
        $db->commit();

        // Log activity
        logActivity($db, $_SESSION['user_id'] ?? 0, "Deleted category ID: $cat_id and all its contents recursively");

        echo json_encode(['success' => true, 'message' => 'Category and all its sub-items deleted successfully!']);
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
} elseif ($action === 'delete_proof') {
    header('Content-Type: application/json');
    $bridge_id = $_GET['bridge_id'] ?? null;

    try {
        $stmt = $db->prepare("DELETE FROM document_bridge WHERE bridge_id = :id");
        $stmt->execute(['id' => $bridge_id]);

        logActivity($db, $_SESSION['user_id'] ?? 0, "Deleted proof of compliance bridge ID: $bridge_id");
        echo json_encode(['success' => true, 'message' => 'Proof of compliance deleted successfully!']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete proof: ' . $e->getMessage()]);
        exit;
    }
}
?>
