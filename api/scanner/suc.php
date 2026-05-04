<?php
/**
 * SUC Standard Bulk Import Scanner
 * Logic: Row 2 start, Col A = KRA, Col C = Code, Col E = Name
 */

use Shuchkin\SimpleXLSX;

$acc_id_input = $_POST['accreditation_id'] ?? 'new';
$acc_name = $_POST['accreditation_name'] ?? 'SUC Standard Import';
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

            // Sheet as root
            if (!$is_dry_run) {
                $sheet_root_id = $getOrCreateCat($db, $accreditation_id, $sheetName, null);
            } else {
                $sheet_root_id = "temp_root_" . $sheetIndex;
                $preview_data[$sheet_root_id] = ['name' => $sheetName, 'items' => []];
            }

            $current_kra_id = $sheet_root_id;
            $last_kra_name = '';

            if ($is_dry_run) {
                // Default category for requirements before any KRA
                $preview_data[$sheet_root_id]['items'][$sheet_root_id] = ['name' => 'General Requirements', 'items' => []];
            }

            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex < 1) continue; // Start at Row 2 (Index 1)

                $row_raw = array_map('trim', $row);
                if (empty(implode('', $row_raw))) continue;

                $kra = $row_raw[0] ?? ''; // Column A
                $code = $row_raw[2] ?? ''; // Column C
                $name = $row_raw[4] ?? ''; // Column E

                // 1. Handle KRA (Col A)
                if (!empty($kra) && $kra !== $last_kra_name) {
                    if (!$is_dry_run) {
                        $current_kra_id = $getOrCreateCat($db, $accreditation_id, $kra, $sheet_root_id);
                    } else {
                        $current_kra_id = "temp_kra_" . $sheetIndex . "_" . $rowIndex;
                        $preview_data[$sheet_root_id]['items'][$current_kra_id] = ['name' => $kra, 'items' => []];
                    }
                    $last_kra_name = $kra;
                    $stats['categories']++;
                }

                // 2. Handle Requirement (Col C + E)
                if (!empty($name)) {
                    $full_req_name = !empty($code) ? trim($code . ' ' . $name) : $name;
                    
                    if (!$is_dry_run) {
                        $stmt = $db->prepare("SELECT requirement_id FROM accreditation_requirement 
                            WHERE category_id = ? AND codename = ? AND name = ? LIMIT 1");
                        $stmt->execute([$current_kra_id, $code, $name]);
                        $req_res = $stmt->fetch();

                        if ($req_res) {
                            $stats['skipped_requirements']++;
                        } else {
                            try {
                                $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, codename, name) VALUES (?, ?, ?)");
                                $stmt->execute([$current_kra_id, $code, $name]);
                                $stats['requirements']++;
                            } catch (Exception $e) {
                                error_log("[SUC] Row {$rowIndex}: " . $e->getMessage());
                            }
                        }
                    } else {
                        $preview_data[$sheet_root_id]['items'][$current_kra_id]['items'][] = [
                            'code' => $code,
                            'name' => $name
                        ];
                        $stats['requirements']++;
                    }
                }
            }
        }

        if (!$is_dry_run) {
            $db->commit();
            echo json_encode(['success' => true, 'message' => "Successfully imported SUC standard.", 'details' => "Added {$stats['categories']} categories and {$stats['requirements']} requirements (" . $stats['skipped_requirements'] . " already existed)."]);
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
