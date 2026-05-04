<?php
/**
 * COPC Bulk Import Scanner
 * Logic: Col A = Category, Col B = Requirement
 */

use Shuchkin\SimpleXLSX;

$acc_id_input = $_POST['accreditation_id'] ?? 'new';
$acc_name = $_POST['accreditation_name'] ?? 'COPC Import';
$is_dry_run = ($_POST['dry_run'] ?? '0') === '1';
$target_file = $_FILES['excel_file']['tmp_name'] ?? null;

if (!$target_file) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

if ($xlsx = SimpleXLSX::parse($target_file)) {
    try {
        $db = null;
        if (!$is_dry_run) {
            $db = (new Database())->getConnection();
            $db->beginTransaction();
        }

        $accreditation_id = null;
        if (!$is_dry_run) {
            if ($acc_id_input !== 'new' && is_numeric($acc_id_input)) {
                $accreditation_id = (int)$acc_id_input;
            } else {
                $stmt = $db->prepare("INSERT INTO accreditations (name, status) VALUES (?, 'Inactive')");
                $stmt->execute([$acc_name]);
                $accreditation_id = $db->lastInsertId();
            }
        }

        $stats = ['categories' => 0, 'requirements' => 0, 'skipped_requirements' => 0];
        $preview_data = [];

        foreach ($xlsx->sheetNames() as $sheetIndex => $sheetName) {
            $rows = $xlsx->rows($sheetIndex);
            if (empty($rows)) continue;

            // Sheet as root category
            if (!$is_dry_run) {
                $workbook_root_id = $getOrCreateCat($db, $accreditation_id, $sheetName, null);
            } else {
                $workbook_root_id = "temp_root_" . $sheetIndex;
                $preview_data[$workbook_root_id] = ['name' => $sheetName, 'items' => []];
            }

            $current_cat_id = $workbook_root_id;
            $last_cat_name = '';

            if ($is_dry_run) {
                // Initialize the root itself as a category to hold requirements that appear before any category header
                $preview_data[$workbook_root_id]['items'][$workbook_root_id] = ['name' => 'General Requirements', 'items' => []];
            }

            foreach ($rows as $rowIndex => $row) {
                $row_raw = array_map('trim', $row);
                if (empty(implode('', $row_raw))) continue;

                $colA = $row_raw[0] ?? '';
                $colB = $row_raw[1] ?? '';

                // Blacklist headers
                if (strcasecmp($colA, 'Category') === 0 && strcasecmp($colB, 'Requirement') === 0) continue;

                // 1. Handle Category (Col A)
                if (!empty($colA) && $colA !== $last_cat_name) {
                    if (!$is_dry_run) {
                        $current_cat_id = $getOrCreateCat($db, $accreditation_id, $colA, $workbook_root_id);
                    } else {
                        $current_cat_id = "temp_cat_" . $sheetIndex . "_" . $rowIndex;
                        $preview_data[$workbook_root_id]['items'][$current_cat_id] = ['name' => $colA, 'items' => []];
                    }
                    $last_cat_name = $colA;
                    $stats['categories']++;
                }

                // 2. Handle Requirement (Col B)
                if (!empty($colB)) {
                    if (!$is_dry_run) {
                        // Deduplication
                        $stmt = $db->prepare("SELECT requirement_id FROM accreditation_requirement 
                            WHERE category_id = ? AND name = ? LIMIT 1");
                        $stmt->execute([$current_cat_id, $colB]);
                        $req_res = $stmt->fetch();

                        if ($req_res) {
                            $stats['skipped_requirements']++;
                        } else {
                            try {
                                $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, name) VALUES (?, ?)");
                                $stmt->execute([$current_cat_id, $colB]);
                                $stats['requirements']++;
                            } catch (Exception $e) {
                                error_log("[COPC] Row {$rowIndex}: " . $e->getMessage());
                            }
                        }
                    } else {
                        $preview_data[$workbook_root_id]['items'][$current_cat_id]['items'][] = [
                            'code' => '',
                            'name' => $colB
                        ];
                        $stats['requirements']++;
                    }
                }
            }
        }

        if (!$is_dry_run) {
            $db->commit();
            echo json_encode(['success' => true, 'message' => "Successfully imported COPC standard.", 'details' => "Added {$stats['categories']} categories and {$stats['requirements']} requirements (" . $stats['skipped_requirements'] . " already existed)."]);
        } else {
            require_once __DIR__ . '/../../api/ai_service.php';
            $ai = new AIService();
            $ai_summary = []; foreach($preview_data as $root) { $ai_summary[$root['name']] = array_keys($root['items']); }
            $ai_insights = $ai->analyzeStructure($ai_summary);
            echo json_encode(['success' => true, 'is_dry_run' => true, 'stats' => $stats, 'preview' => $preview_data, 'ai_insights' => $ai_insights]);
        }

    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Excel parsing error: ' . SimpleXLSX::parseError()]);
}
