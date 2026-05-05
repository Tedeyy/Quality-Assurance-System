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
        $request_email_link = $_POST['request_email_link'] ?? '';
        $email_link = $_POST['email_link'] ?? '';
        
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

        $target_participants_str = implode(', ', $target_groups);

        // Insert Activity
        $query = "INSERT INTO activities (title, description, speaker, organizer, eventdate, eventstatus, eventvenue, requesting_office_id, number_of_participants, target_participants, request_email_link, email_link) 
                  VALUES (:title, :description, :speaker, :organizer, :eventdate, :eventstatus, :eventvenue, :office_id, :num_part, :target_participants, :request_email_link, :email_link)";
        
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
            ':num_part' => $number_of_participants,
            ':target_participants' => $target_participants_str,
            ':request_email_link' => $request_email_link,
            ':email_link' => $email_link
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
        $redirect = $_POST['redirect_url'] ?? '../views/feed.php?action=activity';
        if (empty($redirect)) $redirect = '../views/feed.php?action=activity';
        
        $_SESSION['success'] = "Activity created successfully!";
        header("Location: " . $redirect);
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Error creating activity: " . $e->getMessage();
        header("Location: ../views/feed.php?action=activity");
    }
    exit;
}

if ($action === 'get' && isset($_GET['id'])) {
    try {
        $id = $_GET['id'];
        $stmt = $db->prepare("SELECT * FROM activities WHERE activity_id = :id");
        $stmt->execute([':id' => $id]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activity) {
            // Get SDGs
            $sdg_stmt = $db->prepare("SELECT sdg_id FROM activity_sdgs WHERE activity_id = :id");
            $sdg_stmt->execute([':id' => $id]);
            $activity['sdg_ids'] = $sdg_stmt->fetchAll(PDO::FETCH_COLUMN);

            // Get Target Groups
            $tg_stmt = $db->prepare("SELECT target_group FROM activity_target_groups WHERE activity_id = :id");
            $tg_stmt->execute([':id' => $id]);
            $activity['target_groups'] = $tg_stmt->fetchAll(PDO::FETCH_COLUMN);

            header('Content-Type: application/json');
            echo json_encode($activity);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Activity not found']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $activity_id = $_POST['activity_id'] ?? null;
        if (!$activity_id) throw new Exception("Activity ID is required");

        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $eventdate = $_POST['eventdate'] ?? '';
        $eventstatus = $_POST['eventstatus'] ?? 'Pending';
        $eventvenue = $_POST['eventvenue'] ?? '';
        $requesting_office_id = $_POST['requesting_office_id'] ?? null;
        $number_of_participants = $_POST['number_of_participants'] ?? 0;
        $request_email_link = $_POST['request_email_link'] ?? '';
        $email_link = $_POST['email_link'] ?? '';
        
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

        $target_participants_str = implode(', ', $target_groups);

        $speaker_str = implode(', ', $speakers);
        $organizer_str = implode(', ', $organizers);

        // Update Activity
        $query = "UPDATE activities SET 
                    title = :title, 
                    description = :description, 
                    speaker = :speaker, 
                    organizer = :organizer, 
                    eventdate = :eventdate, 
                    eventstatus = :eventstatus, 
                    eventvenue = :eventvenue, 
                    requesting_office_id = :office_id, 
                    number_of_participants = :num_part,
                    target_participants = :target_participants,
                    request_email_link = :request_email_link,
                    email_link = :email_link
                  WHERE activity_id = :id";
        
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
            ':num_part' => $number_of_participants,
            ':target_participants' => $target_participants_str,
            ':request_email_link' => $request_email_link,
            ':email_link' => $email_link,
            ':id' => $activity_id
        ]);

        // Sync SDGs
        $db->prepare("DELETE FROM activity_sdgs WHERE activity_id = :id")->execute([':id' => $activity_id]);
        if (!empty($sdg_ids)) {
            $sdg_stmt = $db->prepare("INSERT INTO activity_sdgs (activity_id, sdg_id) VALUES (:id, :sdg_id)");
            foreach ($sdg_ids as $sdg_id) {
                $sdg_stmt->execute([':id' => $activity_id, ':sdg_id' => $sdg_id]);
            }
        }

        // Sync Target Groups
        $db->prepare("DELETE FROM activity_target_groups WHERE activity_id = :id")->execute([':id' => $activity_id]);
        if (!empty($target_groups)) {
            $tg_stmt = $db->prepare("INSERT INTO activity_target_groups (activity_id, target_group) VALUES (:id, :target_group)");
            foreach ($target_groups as $group) {
                $tg_stmt->execute([':id' => $activity_id, ':target_group' => $group]);
            }
        }

        $db->commit();
        $redirect = $_POST['redirect_url'] ?? '../views/feed.php?action=activity';
        if (empty($redirect)) $redirect = '../views/feed.php?action=activity';

        $_SESSION['success'] = "Activity updated successfully!";
        header("Location: " . $redirect);
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "Error updating activity: " . $e->getMessage();
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
