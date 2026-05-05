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
        $eventdate = $_POST['eventdate'] ?? '';
        $eventstatus = $_POST['eventstatus'] ?? 'Pending';
        $eventvenue = $_POST['eventvenue'] ?? '';
        $requesting_office_id = $_POST['requesting_office_id'] ?? null;
        $number_of_participants = $_POST['number_of_participants'] ?? 0;
        
        $sdg_ids = $_POST['sdg_ids'] ?? [];
        $target_groups = $_POST['target_groups'] ?? [];

        // Handle multiple speakers/organizers
        $facilitator_names = $_POST['facilitator_names'] ?? [];
        $facilitator_roles = $_POST['facilitator_roles'] ?? [];
        
        $speakers = [];
        $organizers = [];

        foreach ($facilitator_names as $index => $name) {
            if (empty(trim($name))) continue;
            
            $role = $facilitator_roles[$index] ?? 'organizer';
            if ($role === 'speaker') {
                $speakers[] = trim($name);
            } else {
                $organizers[] = trim($name);
            }
        }

        $speaker_str = implode(', ', $speakers);
        $organizer_str = implode(', ', $organizers);

        // Insert Activity
        $query = "INSERT INTO activities (title, description, speaker, organizer, eventdate, eventstatus, eventvenue, requesting_office_id, number_of_participants) 
                  VALUES (:title, :description, :speaker, :organizer, :eventdate, :eventstatus, :eventvenue, :office_id, :num_part)";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':speaker' => $speaker_str,
            ':organizer' => $organizer_str,
            ':eventdate' => $eventdate,
            ':eventstatus' => $eventstatus,
            ':eventvenue' => $eventvenue,
            ':office_id' => $requesting_office_id,
            ':num_part' => $number_of_participants
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

        // Insert Target Groups
        if (!empty($target_groups)) {
            $tg_query = "INSERT INTO activity_target_groups (activity_id, target_group) VALUES (:activity_id, :target_group)";
            $tg_stmt = $db->prepare($tg_query);
            foreach ($target_groups as $group) {
                $tg_stmt->execute([
                    ':activity_id' => $activity_id,
                    ':target_group' => $group
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
