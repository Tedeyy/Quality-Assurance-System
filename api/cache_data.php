<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../views/content/cache_helpers.php';

$db = (new Database())->getConnection();
$dataset = $_GET['dataset'] ?? '';
$mode = $_GET['mode'] ?? 'version';

header('Content-Type: application/json');

function buildAccMappingBrowserDataset(PDO $db): array {
    $stmt = $db->query("SELECT accreditation_id, name, code FROM accreditations ORDER BY name ASC");
    $all_accreditations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("SELECT category_id, name, parent_category_id, accreditation_id FROM accreditation_categories ORDER BY parent_category_id ASC, name ASC");
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT b.*,
               doc.doc_code, doc.category AS doc_category, doc.purpose AS doc_purpose,
               s.status AS sub_status, s.google_drive_link AS sub_link,
               s.google_drive_file_id, s.file_path AS sub_path, s.remarks AS sub_remarks,
               s.user_id AS sub_user_id,
               u.fname AS uploader_fname, u.lname AS uploader_lname,
               do.name AS office_name, do.acronym AS office_acronym
        FROM document_bridge b
        LEFT JOIN documents doc ON b.document_id = doc.doc_id
        LEFT JOIN accreditation_requirement_submissions s ON b.submission_id = s.submission_id
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN divisions_offices do ON s.office_id = do.office_id
        ORDER BY b.requirement_id ASC, b.proof_name ASC
    ");
    $all_bridges_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bridges_by_requirement = [];
    foreach ($all_bridges_raw as $bridge) {
        $bridges_by_requirement[$bridge['requirement_id']][] = $bridge;
    }

    $stmt = $db->query("SELECT doc_id, doc_code, category, purpose FROM documents ORDER BY doc_code ASC");
    $all_inst_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->query("
        SELECT
            r.requirement_id AS req_id,
            r.codename AS req_code,
            r.name AS title,
            '' AS description,
            r.category_id,
            c.name AS category,
            c.accreditation_id
        FROM accreditation_requirement r
        LEFT JOIN accreditation_categories c ON r.category_id = c.category_id
        ORDER BY c.name ASC, r.name ASC
    ");
    $raw_requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requirements = [];
    foreach ($raw_requirements as $req) {
        $req_id = $req['req_id'];
        $proofs = $bridges_by_requirement[$req_id] ?? [];
        $requirements[] = [
            'req_id' => $req_id,
            'req_code' => (!empty($req['req_code']) ? $req['req_code'] : 'REQ-' . $req_id),
            'title' => $req['title'],
            'description' => $req['description'],
            'category_id' => $req['category_id'],
            'category' => (!empty($req['category']) ? $req['category'] : 'Uncategorized'),
            'accreditation_id' => $req['accreditation_id'],
            'proofs' => $proofs,
            'proof_count' => count($proofs),
            'proof_linked' => count(array_filter($proofs, function ($p) {
                return !empty($p['document_id']) || !empty($p['submission_id']);
            })),
        ];
    }

    return [
        'allAccreditations' => $all_accreditations,
        'allCategories' => $all_categories,
        'allRequirements' => $requirements,
        'allInstitutionalDocs' => $all_inst_docs,
    ];
}

if ($dataset !== 'accmapping') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown dataset.']);
    exit;
}

$tables = [
    'accreditations',
    'accreditation_categories',
    'accreditation_requirement',
    'document_bridge',
    'accreditation_requirement_submissions',
    'documents',
];
$version = qa_table_cache_version($db, $tables);

if ($mode === 'version') {
    echo json_encode(['success' => true, 'dataset' => $dataset, 'version' => $version]);
    exit;
}

if ($mode === 'data') {
    echo json_encode([
        'success' => true,
        'dataset' => $dataset,
        'version' => $version,
        'data' => buildAccMappingBrowserDataset($db),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown cache mode.']);
