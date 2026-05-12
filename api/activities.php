<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';

/**
 * Get or insert a person in the speakers/organizers registry.
 * Returns the registry ID (speaker_id or organizer_id).
 */
function getOrInsertFacilitator($db, $name, $role) {
    $table  = ($role === 'speaker') ? 'speakers'   : 'organizers';
    $id_col = ($role === 'speaker') ? 'speaker_id' : 'organizer_id';

    $stmt = $db->prepare("SELECT $id_col FROM $table WHERE name = :name");
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return $row[$id_col];
    }
    $stmt = $db->prepare("INSERT INTO $table (name) VALUES (:name)");
    $stmt->execute([':name' => $name]);
    return $db->lastInsertId();
}

function generateUniqueCode($db) {
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM activities WHERE activity_code = :code");
        $stmt->execute([':code' => $code]);
    } while ($stmt->fetchColumn() > 0);
    return $code;
}

/**
 * Sync activity_facilitators rows for a given activity.
 * Deletes all old rows and re-inserts the new set atomically (within the
 * caller's transaction).
 */
function syncFacilitators($db, $activity_id, $facilitator_names, $facilitator_roles) {
    // Remove old junction rows
    $db->prepare("DELETE FROM activity_facilitators WHERE activity_id = :aid")
       ->execute([':aid' => $activity_id]);

    foreach ($facilitator_names as $index => $name) {
        $name = trim($name);
        if ($name === '') continue;

        $role      = $facilitator_roles[$index] ?? 'organizer';
        $person_id = getOrInsertFacilitator($db, $name, $role);

        $db->prepare("INSERT IGNORE INTO activity_facilitators (activity_id, person_id, role)
                      VALUES (:aid, :pid, :role)")
           ->execute([':aid' => $activity_id, ':pid' => $person_id, ':role' => $role]);
    }
}

// ── CREATE ───────────────────────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $title               = $_POST['title']                ?? '';
        $description         = $_POST['description']          ?? '';
        $eventdate           = $_POST['eventdate']            ?? '';
        $eventstatus         = $_POST['eventstatus']          ?? 'Pending';
        $eventvenue          = $_POST['eventvenue']           ?? '';
        $requesting_office_id = $_POST['requesting_office_id'] ?? null;
        $number_of_participants = $_POST['number_of_participants'] ?? 0;
        $request_email_link  = $_POST['request_email_link']   ?? '';
        $email_link          = $_POST['email_link']           ?? '';

        $sdg_ids       = $_POST['sdg_ids']       ?? [];
        $target_groups = $_POST['target_groups'] ?? [];

        $facilitator_names = $_POST['facilitator_names'] ?? [];
        $facilitator_roles = $_POST['facilitator_roles'] ?? [];

        // Build legacy comma strings for backward-compat columns
        $speaker_names   = [];
        $organizer_names = [];
        foreach ($facilitator_names as $i => $n) {
            $n = trim($n);
            if ($n === '') continue;
            $r = $facilitator_roles[$i] ?? 'organizer';
            if ($r === 'speaker')   $speaker_names[]   = $n;
            else                    $organizer_names[]  = $n;
        }
        $speaker_str              = implode(', ', $speaker_names);
        $organizer_str            = implode(', ', $organizer_names);
        $target_participants_str  = implode(', ', $target_groups);

        $activity_code = generateUniqueCode($db);

        // Insert Activity (keep legacy columns populated for any old tooling)
        $stmt = $db->prepare(
            "INSERT INTO activities
                (activity_code, title, description, speaker, organizer, eventdate, eventstatus, eventvenue,
                 requesting_office_id, number_of_participants, target_participants,
                 request_email_link, email_link)
             VALUES
                (:code, :title, :description, :speaker, :organizer, :eventdate, :eventstatus, :eventvenue,
                 :office_id, :num_part, :target_participants, :request_email_link, :email_link)"
        );
        $stmt->execute([
            ':code'               => $activity_code,
            ':title'              => $title,
            ':description'        => $description,
            ':speaker'            => $speaker_str,
            ':organizer'          => $organizer_str,
            ':eventdate'          => $eventdate,
            ':eventstatus'        => $eventstatus,
            ':eventvenue'         => $eventvenue,
            ':office_id'          => $requesting_office_id,
            ':num_part'           => $number_of_participants,
            ':target_participants'=> $target_participants_str,
            ':request_email_link' => $request_email_link,
            ':email_link'         => $email_link,
        ]);

        $activity_id = $db->lastInsertId();

        // Sync junction table
        syncFacilitators($db, $activity_id, $facilitator_names, $facilitator_roles);

        // Insert SDGs
        if (!empty($sdg_ids)) {
            $sdg_stmt = $db->prepare("INSERT INTO activity_sdgs (activity_id, sdg_id) VALUES (:activity_id, :sdg_id)");
            foreach ($sdg_ids as $sdg_id) {
                $sdg_stmt->execute([':activity_id' => $activity_id, ':sdg_id' => $sdg_id]);
            }
        }

        $db->commit();
        $redirect = $_POST['redirect_url'] ?? '';
        if (empty($redirect)) $redirect = '../views/feed.php?action=activity';

        $_SESSION['success'] = "Activity created successfully!";
        header("Location: " . $redirect);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error'] = "Error creating activity: " . $e->getMessage();
        header("Location: ../views/feed.php?action=activity");
    }
    exit;
}

// ── GET (single activity for the edit modal) ─────────────────────────────────
if ($action === 'get' && (isset($_GET['id']) || isset($_GET['code']))) {
    try {
        if (isset($_GET['id'])) {
            $stmt = $db->prepare("SELECT * FROM activities WHERE activity_id = :id");
            $stmt->execute([':id' => $_GET['id']]);
        } else {
            $stmt = $db->prepare("SELECT * FROM activities WHERE activity_code = :code");
            $stmt->execute([':code' => $_GET['code']]);
        }
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activity) {
            $id = $activity['activity_id'];
            // SDGs
            $sdg_stmt = $db->prepare("SELECT sdg_id FROM activity_sdgs WHERE activity_id = :id");
            $sdg_stmt->execute([':id' => $id]);
            $activity['sdg_ids'] = $sdg_stmt->fetchAll(PDO::FETCH_COLUMN);

            // Target groups
            $tg_stmt = $db->prepare("SELECT target_group FROM activity_target_groups WHERE activity_id = :id");
            $tg_stmt->execute([':id' => $id]);
            $activity['target_groups'] = $tg_stmt->fetchAll(PDO::FETCH_COLUMN);

            // Facilitators from junction table (preferred)
            $fac_stmt = $db->prepare(
                "SELECT af.role, af.person_id,
                        COALESCE(sp.name, og.name) AS name
                 FROM   activity_facilitators af
                 LEFT JOIN speakers   sp ON af.role = 'speaker'   AND af.person_id = sp.speaker_id
                 LEFT JOIN organizers og ON af.role = 'organizer' AND af.person_id = og.organizer_id
                 WHERE  af.activity_id = :id
                 ORDER BY af.role, af.af_id"
            );
            $fac_stmt->execute([':id' => $id]);
            $activity['facilitators'] = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback: if junction table is empty but legacy strings exist, parse them
            if (empty($activity['facilitators'])) {
                $fallback = [];
                if (!empty($activity['speaker'])) {
                    foreach (explode(',', $activity['speaker']) as $n) {
                        $n = trim($n);
                        if ($n) $fallback[] = ['role' => 'speaker', 'name' => $n];
                    }
                }
                if (!empty($activity['organizer'])) {
                    foreach (explode(',', $activity['organizer']) as $n) {
                        $n = trim($n);
                        if ($n) $fallback[] = ['role' => 'organizer', 'name' => $n];
                    }
                }
                $activity['facilitators'] = $fallback;
            }

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

// ── UPDATE ───────────────────────────────────────────────────────────────────
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $activity_id = $_POST['activity_id'] ?? null;
        if (!$activity_id) throw new Exception("Activity ID is required");

        $title               = $_POST['title']                ?? '';
        $description         = $_POST['description']          ?? '';
        $eventdate           = $_POST['eventdate']            ?? '';
        $eventstatus         = $_POST['eventstatus']          ?? 'Pending';
        $eventvenue          = $_POST['eventvenue']           ?? '';
        $requesting_office_id = $_POST['requesting_office_id'] ?? null;
        $number_of_participants = $_POST['number_of_participants'] ?? 0;
        $request_email_link  = $_POST['request_email_link']   ?? '';
        $email_link          = $_POST['email_link']           ?? '';

        $sdg_ids       = $_POST['sdg_ids']       ?? [];
        $target_groups = $_POST['target_groups'] ?? [];

        $facilitator_names = $_POST['facilitator_names'] ?? [];
        $facilitator_roles = $_POST['facilitator_roles'] ?? [];

        // Rebuild legacy comma strings
        $speaker_names   = [];
        $organizer_names = [];
        foreach ($facilitator_names as $i => $n) {
            $n = trim($n);
            if ($n === '') continue;
            $r = $facilitator_roles[$i] ?? 'organizer';
            if ($r === 'speaker')   $speaker_names[]   = $n;
            else                    $organizer_names[]  = $n;
        }
        $speaker_str              = implode(', ', $speaker_names);
        $organizer_str            = implode(', ', $organizer_names);
        $target_participants_str  = implode(', ', $target_groups);

        // Update Activity
        $stmt = $db->prepare(
            "UPDATE activities SET
                title = :title, description = :description,
                speaker = :speaker, organizer = :organizer,
                eventdate = :eventdate, eventstatus = :eventstatus, eventvenue = :eventvenue,
                requesting_office_id = :office_id, number_of_participants = :num_part,
                target_participants = :target_participants,
                request_email_link = :request_email_link, email_link = :email_link
             WHERE activity_id = :id"
        );
        $stmt->execute([
            ':title'              => $title,
            ':description'        => $description,
            ':speaker'            => $speaker_str,
            ':organizer'          => $organizer_str,
            ':eventdate'          => $eventdate,
            ':eventstatus'        => $eventstatus,
            ':eventvenue'         => $eventvenue,
            ':office_id'          => $requesting_office_id,
            ':num_part'           => $number_of_participants,
            ':target_participants'=> $target_participants_str,
            ':request_email_link' => $request_email_link,
            ':email_link'         => $email_link,
            ':id'                 => $activity_id,
        ]);

        // Sync junction table
        syncFacilitators($db, $activity_id, $facilitator_names, $facilitator_roles);

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
        $_SESSION['success'] = "Activity updated successfully!";
        header("Location: ../views/feed.php?action=activity");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error'] = "Error updating activity: " . $e->getMessage();
        header("Location: ../views/feed.php?action=activity");
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        // Junction rows will cascade or be cleaned separately; delete activity
        $db->prepare("DELETE FROM activity_facilitators WHERE activity_id = :id")->execute([':id' => $_GET['id']]);
        $db->prepare("DELETE FROM activities WHERE activity_id = :id")->execute([':id' => $_GET['id']]);
        $_SESSION['success'] = "Activity deleted successfully!";
        header("Location: ../views/feed.php?action=activity");
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error deleting activity: " . $e->getMessage();
        header("Location: ../views/feed.php?action=activity");
    }
    exit;
}
