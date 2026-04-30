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

// 2. Resolve Folder Hierarchy
$category_path = [];
$stmt = $db->prepare("SELECT category_id FROM accreditation_requirement WHERE requirement_id = :req_id");
$stmt->execute(['req_id' => $requirement_id]);
$current_cat_id = $stmt->fetchColumn();

while ($current_cat_id) {
    $stmt = $db->prepare("SELECT name, parent_category_id FROM accreditation_categories WHERE category_id = :cat_id");
    $stmt->execute(['cat_id' => $current_cat_id]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cat) {
        array_unshift($category_path, $cat['name']);
        $current_cat_id = $cat['parent_category_id'];
    } else break;
}

// Get Root Folder from .env
$root_url = $_ENV['GOOGLE_DRIVE_URL'] ?? '';
preg_match('/folders\/([a-zA-Z0-9_-]+)/', $root_url, $matches);
$parent_id = $matches[1] ?? 'root';

// Create/Navigate Folders
foreach ($category_path as $folder_name) {
    $query = "name = '" . str_replace("'", "\\'", $folder_name) . "' and mimeType = 'application/vnd.google-apps.folder' and '" . $parent_id . "' in parents and trashed = false";
    $search = driveApiRequest("https://www.googleapis.com/drive/v3/files?q=" . urlencode($query), 'GET', null, $access_token);
    
    if (!empty($search['data']['files'])) {
        $parent_id = $search['data']['files'][0]['id'];
    } else {
        $create = driveApiRequest("https://www.googleapis.com/drive/v3/files", 'POST', [
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parent_id]
        ], $access_token);
        $parent_id = $create['data']['id'] ?? $parent_id;
    }
}

// If multiple files, create a requirement folder
$is_multiple = count($files['name']) > 1;
$submission_file_id = null;

if ($is_multiple) {
    $folder_name = !empty($codename) ? $codename : $req_name;
    $query = "name = '" . str_replace("'", "\\'", $folder_name) . "' and mimeType = 'application/vnd.google-apps.folder' and '" . $parent_id . "' in parents and trashed = false";
    $search = driveApiRequest("https://www.googleapis.com/drive/v3/files?q=" . urlencode($query), 'GET', null, $access_token);
    if (!empty($search['data']['files'])) {
        $parent_id = $search['data']['files'][0]['id'];
    } else {
        $create = driveApiRequest("https://www.googleapis.com/drive/v3/files", 'POST', [
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parent_id]
        ], $access_token);
        $parent_id = $create['data']['id'] ?? $parent_id;
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
    
    $tmp_name = $files['tmp_name'][$i];
    
    $metadata = ['name' => $target_name, 'parents' => [$parent_id]];
    $multipart_boundary = '-------' . md5(time());
    
    $post_data = "--$multipart_boundary\r\n" .
                 "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
                 json_encode($metadata) . "\r\n" .
                 "--$multipart_boundary\r\n" .
                 "Content-Type: application/pdf\r\n\r\n" .
                 file_get_contents($tmp_name) . "\r\n" .
                 "--$multipart_boundary--";

    $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: multipart/related; boundary=' . $multipart_boundary,
        'Content-Length: ' . strlen($post_data)
    ]);
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($status >= 200 && $status < 300) {
        $success_count++;
        $resp_data = json_decode($response, true);
        if (!$is_multiple) {
            $submission_file_id = $resp_data['id'] ?? null;
        }
    }
}

if ($success_count > 0) {
    // Save to Submissions Table
    $drive_link = $is_multiple 
        ? "https://drive.google.com/drive/folders/" . $submission_file_id
        : "https://drive.google.com/file/d/" . $submission_file_id . "/view";

    $stmt = $db->prepare("INSERT INTO accreditation_requirement_submissions 
        (requirement_id, user_id, google_drive_file_id, google_drive_link, division_id, office_id, status) 
        VALUES (:req_id, :user_id, :file_id, :link, :division_id, :office_id, 'Pending')
        ON DUPLICATE KEY UPDATE 
        google_drive_file_id = :file_id, google_drive_link = :link, division_id = :division_id, office_id = :office_id, status = 'Pending', updated_at = NOW()");
    
    $stmt->execute([
        'req_id' => $requirement_id,
        'user_id' => $_SESSION['user_id'],
        'file_id' => $submission_file_id,
        'link' => $drive_link,
        'division_id' => $division_id,
        'office_id' => $office_id
    ]);

    logActivity($db, $_SESSION['user_id'], "Directly uploaded $success_count PDF(s) as User to Drive for: $req_name");
    echo json_encode(['success' => true, 'message' => "Successfully uploaded $success_count file(s) and tracked your submission!"]);
} else {
    echo json_encode(['success' => false, 'message' => 'Upload failed. Check Drive permissions.']);
}
?>
