<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/utils/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or invalid request.']);
    exit;
}

$requirement_id = $_POST['requirement_id'] ?? null;
$files = $_FILES['files'] ?? null;

if (!$requirement_id || !$files || empty($files['name'][0])) {
    echo json_encode(['success' => false, 'message' => 'Requirement ID and at least one PDF file are required.']);
    exit;
}

$db = (new Database())->getConnection();

// Fetch Codename and Requirement Name directly from DB for reliability
$stmt = $db->prepare("SELECT codename, name FROM accreditation_requirement WHERE requirement_id = :req_id");
$stmt->execute(['req_id' => $requirement_id]);
$req_data = $stmt->fetch(PDO::FETCH_ASSOC);
$codename = trim($req_data['codename'] ?? '');
$req_name = trim($req_data['name'] ?? 'Requirement');

// 1. Get User's Google Token and Metadata
$stmt = $db->prepare("SELECT google_access_token, google_refresh_token, division_id, office_id FROM users WHERE user_id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || empty($user['google_access_token'])) {
    echo json_encode(['success' => false, 'message' => 'Please re-login with Google to grant Drive access.']);
    exit;
}

$access_token = $user['google_access_token'];
$division_id = $user['division_id'] ?? null;
$office_id = $user['office_id'] ?? null;

// Helper for Google API Calls
function driveApiRequest($url, $method = 'GET', $body = null, $token) {
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

// Helper to find or create a folder
function getOrCreateFolder($name, $parentId, $token) {
    $name = trim($name);
    $query = "name = '" . str_replace("'", "\\'", $name) . "' and mimeType = 'application/vnd.google-apps.folder' and '" . $parentId . "' in parents and trashed = false";
    $search = driveApiRequest("https://www.googleapis.com/drive/v3/files?q=" . urlencode($query) . "&supportsAllDrives=true&includeItemsFromAllDrives=true", 'GET', null, $token);
    
    if (!empty($search['data']['files'])) {
        return $search['data']['files'][0]['id'];
    }
    
    $create = driveApiRequest("https://www.googleapis.com/drive/v3/files?supportsAllDrives=true", 'POST', [
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId]
    ], $token);
    
    if (isset($create['data']['error'])) {
        return $create['data']['error'];
    }
    
    return $create['data']['id'] ?? null;
}

// 2. Resolve Folder Hierarchy (Accreditation > Categories)
$category_path = [];
$stmt = $db->prepare("
    SELECT r.category_id, a.name as acc_name 
    FROM accreditation_requirement r 
    JOIN accreditation_categories c ON r.category_id = c.category_id
    JOIN accreditations a ON c.accreditation_id = a.accreditation_id
    WHERE r.requirement_id = :req_id
");
$stmt->execute(['req_id' => $requirement_id]);
$initial_data = $stmt->fetch(PDO::FETCH_ASSOC);

$current_cat_id = $initial_data['category_id'] ?? null;
if (!empty($initial_data['acc_name'])) {
    $category_path[] = $initial_data['acc_name']; // Top level is accreditation
}

$temp_cats = [];
while ($current_cat_id) {
    $stmt = $db->prepare("SELECT name, parent_category_id FROM accreditation_categories WHERE category_id = :cat_id");
    $stmt->execute(['cat_id' => $current_cat_id]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cat) {
        array_unshift($temp_cats, $cat['name']);
        $current_cat_id = $cat['parent_category_id'];
    } else break;
}
$category_path = array_merge($category_path, $temp_cats);

// Get Root Folder from .env
$root_url = $_ENV['GOOGLE_DRIVE_URL'] ?? '';
preg_match('/folders\/([a-zA-Z0-9_-]+)/', $root_url, $matches);
$parent_id = $matches[1] ?? null;

if (!$parent_id) {
    echo json_encode(['success' => false, 'message' => 'Google Drive Root Folder not configured in .env.']);
    exit;
}

// Create/Navigate Category Folders
foreach ($category_path as $folder_name) {
    $parent_id_result = getOrCreateFolder($folder_name, $parent_id, $access_token);
    
    // Check if there was an API error we need to surface
    if (is_array($parent_id_result)) {
        echo json_encode(['success' => false, 'message' => "Failed to resolve folder: $folder_name. API Error: " . json_encode($parent_id_result)]);
        exit;
    }
    
    $parent_id = $parent_id_result;
    
    if (!$parent_id) {
        echo json_encode(['success' => false, 'message' => "Failed to resolve folder: $folder_name"]);
        exit;
    }
}

// If multiple files, create a requirement folder
$is_multiple = count($files['name']) > 1;
$submission_file_id = null;

if ($is_multiple) {
    $req_folder_name = !empty($codename) ? $codename : $req_name;
    $parent_id = getOrCreateFolder($req_folder_name, $parent_id, $access_token);
    if (!$parent_id) {
        echo json_encode(['success' => false, 'message' => "Failed to resolve requirement folder: $req_folder_name"]);
        exit;
    }
    $submission_file_id = $parent_id;
}

// 3. Upload Files
$success_count = 0;
$file_count = count($files['name']);
$clean_req_name = preg_replace('/[^A-Za-z0-9\- ]/', '', $req_name); // Sanitize for filename

foreach ($files['name'] as $i => $original_name) {
    if ($file_count === 1) {
        $target_name = !empty($codename) ? $codename . ".pdf" : $clean_req_name . ".pdf";
    } else {
        $target_name = "file" . ($i + 1) . ".pdf";
    }

    // Check if file already exists to update instead of duplicate
    $query = "name = '" . str_replace("'", "\\'", $target_name) . "' and '" . $parent_id . "' in parents and trashed = false";
    $search = driveApiRequest("https://www.googleapis.com/drive/v3/files?q=" . urlencode($query) . "&supportsAllDrives=true&includeItemsFromAllDrives=true", 'GET', null, $access_token);
    $existing_file_id = !empty($search['data']['files']) ? $search['data']['files'][0]['id'] : null;
    
    $tmp_name = $files['tmp_name'][$i];
    $metadata = ['name' => $target_name];
    if (!$existing_file_id) {
        $metadata['parents'] = [$parent_id];
    }

    $multipart_boundary = '-------' . md5(time());
    $post_data = "--$multipart_boundary\r\n" .
                 "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
                 json_encode($metadata) . "\r\n" .
                 "--$multipart_boundary\r\n" .
                 "Content-Type: application/pdf\r\n\r\n" .
                 file_get_contents($tmp_name) . "\r\n" .
                 "--$multipart_boundary--";

    if ($existing_file_id) {
        // UPDATE existing file
        $url = "https://www.googleapis.com/upload/drive/v3/files/" . $existing_file_id . "?uploadType=multipart&supportsAllDrives=true";
        $method = 'PATCH';
    } else {
        // CREATE new file
        $url = "https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true";
        $method = 'POST';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: multipart/related; boundary=' . $multipart_boundary,
        'Content-Length: ' . strlen($post_data)
    ]);
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $res_data = json_decode($response, true);
    if ($status == 200) {
        $success_count++;
        if ($file_count === 1) $submission_file_id = $res_data['id'];
    }
}

if ($success_count > 0) {
    // Save to Submissions Table
    $drive_link = $is_multiple 
        ? "https://drive.google.com/drive/folders/" . $submission_file_id
        : "https://drive.google.com/file/d/" . $submission_file_id . "/view";
    $folder_url = "https://drive.google.com/drive/folders/" . $parent_id;

    $bridge_id = $_POST['bridge_id'] ?? null;
    $existing_submission_id = null;
    
    if ($bridge_id) {
        $stmt = $db->prepare("SELECT submission_id FROM document_bridge WHERE bridge_id = :bridge_id");
        $stmt->execute(['bridge_id' => $bridge_id]);
        $existing_submission_id = $stmt->fetchColumn();
    }

    if ($existing_submission_id) {
        // Update existing submission record to trigger re-review
        $stmt = $db->prepare("UPDATE accreditation_requirement_submissions SET 
            google_drive_file_id = :file_id, 
            google_drive_link = :link, 
            file_path = :file_path,
            division_id = :division_id, 
            office_id = :office_id, 
            status = 'Pending', 
            remarks = NULL, 
            marked_by = NULL,
            user_id = :user_id,
            updated_at = NOW() 
            WHERE submission_id = :sub_id");
        $stmt->execute([
            'file_id' => $submission_file_id,
            'link' => $drive_link,
            'file_path' => $folder_url,
            'division_id' => $division_id,
            'office_id' => $office_id,
            'user_id' => $_SESSION['user_id'],
            'sub_id' => $existing_submission_id
        ]);
    } else {
        // Insert new submission
        $stmt = $db->prepare("INSERT INTO accreditation_requirement_submissions 
            (requirement_id, user_id, google_drive_file_id, google_drive_link, file_path, division_id, office_id, status) 
            VALUES (:req_id, :user_id, :file_id, :link, :file_path, :division_id, :office_id, 'Pending')");
        $stmt->execute([
            'req_id' => $requirement_id,
            'user_id' => $_SESSION['user_id'],
            'file_id' => $submission_file_id,
            'link' => $drive_link,
            'file_path' => $folder_url,
            'division_id' => $division_id,
            'office_id' => $office_id
        ]);
        $new_sub_id = $db->lastInsertId();

        if ($bridge_id) {
            $stmt = $db->prepare("UPDATE document_bridge SET submission_id = :sub_id WHERE bridge_id = :bridge_id");
            $stmt->execute([
                'sub_id' => $new_sub_id,
                'bridge_id' => $bridge_id
            ]);
        }
    }

    logActivity($db, $_SESSION['user_id'], "Uploaded file(s) for proof compliance of requirement ID: $requirement_id" . ($bridge_id ? " (Bridge ID: $bridge_id)" : ""));
    echo json_encode(['success' => true, 'message' => "Successfully uploaded $success_count file(s) and tracked compliance!"]);
} else {
    echo json_encode(['success' => false, 'message' => 'Upload failed. Check Drive permissions.']);
}
?>
