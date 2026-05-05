<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $organizer = $_POST['organizer'] ?? '';
        $eventdate = $_POST['eventdate'] ?? '';
        $eventstatus = $_POST['eventstatus'] ?? 'Pending';
        $eventvenue = $_POST['eventvenue'] ?? '';
        $sdg_ids = $_POST['sdg_ids'] ?? [];

        // Insert Activity
        $query = "INSERT INTO activities (title, description, organizer, eventdate, eventstatus, eventvenue) 
                  VALUES (:title, :description, :organizer, :eventdate, :eventstatus, :eventvenue)";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':organizer' => $organizer,
            ':eventdate' => $eventdate,
            ':eventstatus' => $eventstatus,
            ':eventvenue' => $eventvenue
        ]);

        $activity_id = $db->lastInsertId();

        // Insert SDGs
        if (!empty($sdg_ids)) {
            $sdg_query = "INSERT INTO activity_sdgs (activity_id, sdg_id) VALUES (:activity_id, :sdg_id)";
            $sdg_stmt = $db->prepare($sdg_query);
            foreach ($sdg_ids as $sdg_id) {
                $sdg_stmt->execute([
                    ':activity_id' => $activity_id,
                    ':sdg_id' => $sdg_id
                ]);
            }
        }

        $db->commit();
        $_SESSION['success'] = "Activity created successfully!";
        header("Location: ../views/feed.php?action=activity");
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Error creating activity: " . $e->getMessage();
        header("Location: ../views/feed.php?action=activity");
    }
    exit;
}

if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $id = $_GET['id'];
        $stmt = $db->prepare("DELETE FROM activities WHERE activity_id = :id");
        $stmt->execute([':id' => $id]);
        
        $_SESSION['success'] = "Activity deleted successfully!";
        header("Location: ../views/feed.php?action=activity");
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting activity: " . $e->getMessage();
        header("Location: ../views/feed.php?action=activity");
    }
    exit;
}
