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

function ensureActivityArchiveColumns(PDO $db): void {
    $columns = $db->query("SHOW COLUMNS FROM activities")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_archived', $columns, true)) {
        $db->exec("ALTER TABLE activities ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER eventstatus");
    }
    if (!in_array('archived_at', $columns, true)) {
        $db->exec("ALTER TABLE activities ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER is_archived");
    }
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
        $eventtime           = $_POST['eventtime']            ?? '';
        $duration            = $_POST['duration']             ?? '';
        $eventstatus         = $_POST['eventstatus']          ?? 'Pending';

        if ($eventdate && $eventtime) {
            $eventdate = $eventdate . ' ' . $eventtime;
        }
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
                (activity_code, title, description, speaker, organizer, eventdate, duration, eventstatus, eventvenue,
                 requesting_office_id, number_of_participants, target_participants,
                 request_email_link, email_link)
             VALUES
                (:code, :title, :description, :speaker, :organizer, :eventdate, :duration, :eventstatus, :eventvenue,
                 :office_id, :num_part, :target_participants, :request_email_link, :email_link)"
        );
        $stmt->execute([
            ':code'               => $activity_code,
            ':title'              => $title,
            ':description'        => $description,
            ':speaker'            => $speaker_str,
            ':organizer'          => $organizer_str,
            ':eventdate'          => $eventdate,
            ':duration'           => $duration,
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
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $activity_id = $_POST['activity_id'] ?? null;
    $redirect = $_POST['redirect_url'] ?? '../views/feed.php?action=activity';

    if (!$activity_id) {
        $_SESSION['error'] = 'Please select an activity to archive.';
        header("Location: " . $redirect);
        exit;
    }

    try {
        ensureActivityArchiveColumns($db);
        $stmt = $db->prepare("UPDATE activities SET is_archived = 1, archived_at = NOW() WHERE activity_id = :id");
        $stmt->execute([':id' => $activity_id]);
        $_SESSION['success'] = 'Activity archived successfully.';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error archiving activity: ' . $e->getMessage();
    }

    header("Location: " . $redirect);
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();

        $activity_id = $_POST['activity_id'] ?? null;
        if (!$activity_id) throw new Exception("Activity ID is required");

        $title               = $_POST['title']                ?? '';
        $description         = $_POST['description']          ?? '';
        $eventdate           = $_POST['eventdate']            ?? '';
        $eventtime           = $_POST['eventtime']            ?? '';
        $duration            = $_POST['duration']             ?? '';
        $eventstatus         = $_POST['eventstatus']          ?? 'Pending';

        if ($eventdate && $eventtime) {
            $eventdate = $eventdate . ' ' . $eventtime;
        }
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
                eventdate = :eventdate, duration = :duration, eventstatus = :eventstatus, eventvenue = :eventvenue,
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
            ':duration'           => $duration,
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
        $redirect = $_POST['redirect_url'] ?? '';
        if (empty($redirect)) $redirect = '../views/feed.php?action=activity';
        $_SESSION['success'] = "Activity updated successfully!";
        header("Location: " . $redirect);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error'] = "Error updating activity: " . $e->getMessage();
        $redirect = $_POST['redirect_url'] ?? '';
        if (empty($redirect)) $redirect = '../views/feed.php?action=activity';
        header("Location: " . $redirect);
    }
    exit;
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    $id = $_GET['id'];
    try {
        $db->beginTransaction();

        // 1. Find evaluation_id and activity_code
        $stmt = $db->prepare("SELECT e.evaluation_id, a.activity_code FROM activities a LEFT JOIN activity_evaluation e ON a.activity_id = e.activity_id WHERE a.activity_id = :id");
        $stmt->execute([':id' => $id]);
        $activityData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activityData && !empty($activityData['evaluation_id'])) {
            $eid = $activityData['evaluation_id'];
            
            // Delete related stats and ratings
            $db->prepare("DELETE FROM activity_speaker_rating WHERE evaluation_id = :eid")->execute([':eid' => $eid]);
            $db->prepare("DELETE FROM activity_organizer_rating WHERE evaluation_id = :eid")->execute([':eid' => $eid]);
            $db->prepare("DELETE FROM activity_statistics WHERE evaluation_id = :eid")->execute([':eid' => $eid]);
            $db->prepare("DELETE FROM activity_statistics_others WHERE evaluation_id = :eid")->execute([':eid' => $eid]);
            $db->prepare("DELETE FROM activity_evaluation WHERE evaluation_id = :eid")->execute([':eid' => $eid]);
        }

        // --- GOOGLE INTEGRATION DELETION ---
        $activityCode = $activityData ? $activityData['activity_code'] : null;
        
        if ($activityCode && isset($_SESSION['user_id'])) {
            $stmtToken = $db->prepare("SELECT google_access_token, google_refresh_token FROM users WHERE user_id = :uid");
            $stmtToken->execute(['uid' => $_SESSION['user_id']]);
            $user = $stmtToken->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['google_access_token'])) {
                require_once __DIR__ . '/../config/env.php';
                require_once __DIR__ . '/../vendor/autoload.php';

                try {
                    $client = new Google\Client();
                    $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
                    $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
                    $client->setAccessToken($user['google_access_token']);

                    if ($client->isAccessTokenExpired() && $user['google_refresh_token']) {
                        $newToken = $client->fetchAccessTokenWithRefreshToken($user['google_refresh_token']);
                        if ($newToken) {
                            $client->setAccessToken($newToken);
                            $stmtUpd = $db->prepare("UPDATE users SET google_access_token = :token WHERE user_id = :uid");
                            $stmtUpd->execute(['token' => json_encode($client->getAccessToken()), 'uid' => $_SESSION['user_id']]);
                        }
                    }

                    $driveService = new Google\Service\Drive($client);
                    $sheetsService = new Google\Service\Sheets($client);

                    // Delete the specific activity folder in Google Drive (removes the form and sheet inside it)
                    $query = "name = '" . str_replace("'", "\'", $activityCode) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
                    $search = $driveService->files->listFiles(['q' => $query]);
                    if (count($search->getFiles()) > 0) {
                        $folderId = $search->getFiles()[0]->getId();
                        $driveService->files->delete($folderId);
                    }

                    // Delete from Index Sheet
                    $indexSheetUrl = $_ENV['RESPONSES_GOOGLE_SHEET'] ?? '';
                    $indexSheetId = $indexSheetUrl;
                    if (preg_match('/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $indexSheetUrl, $matches)) {
                        $indexSheetId = $matches[1];
                    }

                    if ($indexSheetId) {
                        $response = $sheetsService->spreadsheets_values->get($indexSheetId, 'A:A');
                        $values = $response->getValues();
                        $rowIndexToDelete = -1;

                        if ($values) {
                            foreach ($values as $index => $row) {
                                if (isset($row[0]) && $row[0] === $activityCode) {
                                    $rowIndexToDelete = $index;
                                    break;
                                }
                            }
                        }

                        if ($rowIndexToDelete !== -1) {
                            $spreadsheet = $sheetsService->spreadsheets->get($indexSheetId);
                            $sheetId = $spreadsheet->getSheets()[0]->getProperties()->getSheetId();

                            $deleteRequest = new Google\Service\Sheets\Request([
                                'deleteDimension' => [
                                    'range' => [
                                        'sheetId' => $sheetId,
                                        'dimension' => 'ROWS',
                                        'startIndex' => $rowIndexToDelete,
                                        'endIndex' => $rowIndexToDelete + 1
                                    ]
                                ]
                            ]);

                            $batchUpdateRequest = new Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                                'requests' => [$deleteRequest]
                            ]);
                            $sheetsService->spreadsheets->batchUpdate($indexSheetId, $batchUpdateRequest);
                        }
                    }
                } catch (Exception $e) {
                    error_log("Failed to delete Google Drive resources for $activityCode: " . $e->getMessage());
                }
            }
        }
        // --- END GOOGLE INTEGRATION DELETION ---

        // 2. Delete junction rows
        $db->prepare("DELETE FROM activity_facilitators WHERE activity_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM activity_sdgs WHERE activity_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM activity_target_groups WHERE activity_id = :id")->execute([':id' => $id]);

        // 3. Delete the activity itself
        $db->prepare("DELETE FROM activities WHERE activity_id = :id")->execute([':id' => $id]);

        // 4. Drop dynamic response table in the other database
        require_once __DIR__ . '/../config/responses_database.php';
        $rdb = (new ResponsesDatabase())->getConnection();
        if ($rdb) {
            $rdb->exec("DROP TABLE IF EXISTS activity_$id");
        }

        $db->commit();
        $redirect = $_GET['redirect_url'] ?? '';
        if (empty($redirect)) $redirect = '../views/feed.php?action=activity';
        $_SESSION['success'] = "Activity and all related evaluation data deleted successfully!";
        header("Location: " . $redirect);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error'] = "Error deleting activity: " . $e->getMessage();
        $redirect = $_GET['redirect_url'] ?? '';
        if (empty($redirect)) $redirect = '../views/feed.php?action=activity';
        header("Location: " . $redirect);
    }
    exit;
}
