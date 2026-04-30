<?php
/**
 * Primary Excel Import Entry Point
 * Currently using: AACCUP Standard Template
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Shuchkin\SimpleXLSX;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!isset($_FILES['excel_file'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$acc_id_input = $_POST['accreditation_id'] ?? 'new';
$acc_name = $_POST['accreditation_name'] ?? 'Imported Accreditation';
$acc_desc = $_POST['accreditation_desc'] ?? 'Bulk imported via Excel';
$is_dry_run = ($_POST['dry_run'] ?? '0') === '1';
$template_type = $_POST['template_type'] ?? 'aaccup';

$file = $_FILES['excel_file']['tmp_name'];

if ($xlsx = SimpleXLSX::parse($file)) {
    try {
        if ($template_type !== 'aaccup') {
            throw new Exception("Template '{$template_type}' is not yet implemented.");
        }

        // --- AACCUP LOGIC START ---
        $db = null;
        if (!$is_dry_run) {
            $db = (new Database())->getConnection();
            $db->beginTransaction();
        }

        $accreditation_id = null;
        if (!$is_dry_run) {
            // 1. Get or Create Accreditation
            if ($acc_id_input !== 'new' && is_numeric($acc_id_input)) {
                $accreditation_id = (int)$acc_id_input;
            } else {
                $stmt = $db->prepare("INSERT INTO accreditations (name, description, status) VALUES (?, ?, 'Inactive')");
                $stmt->execute([$acc_name, $acc_desc]);
                $accreditation_id = $db->lastInsertId();
            }
        }

        $rows = $xlsx->rows();
        
        $current_area_id = null;
        $current_param_id = null;
        $current_section_id = null;
        $pending_new_category = false;

        $stats = ['categories' => 0, 'requirements' => 0];
        $preview_data = [];

        // Specific handling for Area headers at the top
        $area_number = '';
        $area_title = '';

        foreach ($rows as $index => $row) {
            $row_raw = array_map('trim', $row);
            $is_empty = empty(implode('', $row_raw));
            
            if ($is_empty) {
                $pending_new_category = true;
                continue;
            }

            $col0 = $row_raw[0] ?? '';
            $col1 = $row_raw[1] ?? '';
            $col2 = $row_raw[2] ?? '';
            $col3 = $row_raw[3] ?? '';
            $col4 = $row_raw[4] ?? '';

            // 1. Detect Area at top (Rows 1-2 in template)
            if ($index == 1 && !empty($col4)) $area_number = $col4;
            if ($index == 2 && !empty($col4)) {
                $area_title = trim($area_number . " " . $col4);
                if (!$is_dry_run) {
                    $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, null)");
                    $stmt->execute([$accreditation_id, $area_title]);
                    $current_area_id = $db->lastInsertId();
                } else {
                    $current_area_id = "temp_area_" . $index;
                    $preview_data[$current_area_id] = ['name' => $area_title, 'items' => []];
                }
                $stats['categories']++;
                $pending_new_category = false;
                continue;
            }

            // 2. Detect Parameter Section (e.g. PARAMETER A: ...)
            if (!empty($col0) && $col0 === $col1 && stripos($col0, 'PARAMETER') !== false) {
                if (!$is_dry_run) {
                    $parent = $current_area_id ?: null;
                    $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, ?)");
                    $stmt->execute([$accreditation_id, $col0, $parent]);
                    $current_param_id = $db->lastInsertId();
                } else {
                    $current_param_id = "temp_param_" . $index;
                    $preview_data[$current_area_id]['items'][$current_param_id] = ['name' => $col0, 'items' => []];
                }
                $current_section_id = null;
                $stats['categories']++;
                $pending_new_category = false;
                continue;
            }

            // 3. Detect Sub-Section (SYSTEM - INPUTS, IMPLEMENTATION, OUTCOME or triggered by space)
            $is_section = false;
            $section_name = '';
            
            // Look for keywords in either col0 or col1
            if (!empty($col0) || !empty($col1)) {
                $search_text = $col0 . " " . $col1;
                if (stripos($search_text, 'SYSTEM') !== false && (stripos($search_text, 'INPUT') !== false || stripos($search_text, 'PROCESS') !== false)) { 
                    $is_section = true; $section_name = "SYSTEM - INPUTS AND PROCESSES"; 
                }
                elseif (stripos($search_text, 'IMPLEMENTATION') !== false) { 
                    $is_section = true; $section_name = "IMPLEMENTATION"; 
                }
                elseif (stripos($search_text, 'OUTCOME') !== false) { 
                    $is_section = true; $section_name = "OUTCOME/S"; 
                }
                elseif ($pending_new_category && empty($col1)) { 
                    // If we had a space, and col0 has text but col1 doesn't (typical for headers)
                    $is_section = true; $section_name = $col0; 
                }
                elseif ($pending_new_category && $col0 === $col1) {
                    // Typical for full-row headers
                    $is_section = true; $section_name = $col0;
                }
            }

            if ($is_section) {
                if (!$is_dry_run) {
                    $parent = $current_param_id ?: ($current_area_id ?: null);
                    $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, ?)");
                    $stmt->execute([$accreditation_id, $section_name, $parent]);
                    $current_section_id = $db->lastInsertId();
                } else {
                    $current_section_id = "temp_sec_" . $index;
                    $preview_data[$current_area_id]['items'][$current_param_id]['items'][$current_section_id] = ['name' => $section_name, 'items' => []];
                }
                $stats['categories']++;
                $pending_new_category = false;
                continue;
            }

            // 4. Detect Requirement
            if (!empty($col0) && $col0 !== $col1) {
                // SKIP if we haven't found a Parameter yet (prevents reading the summary at the top)
                if ($current_param_id === null) {
                    $pending_new_category = false;
                    continue;
                }

                $code = ''; $name = ''; $found_code = false;
                for ($i = 1; $i < count($row_raw); $i++) {
                    if (!empty($row_raw[$i])) {
                        if (!$found_code) { $code = $row_raw[$i]; $found_code = true; }
                        else { $name = $row_raw[$i]; break; }
                    }
                }

                if (!empty($name)) {
                    if (!$is_dry_run) {
                        $cat_id = $current_section_id ?: $current_param_id;
                        $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, codename, name) VALUES (?, ?, ?)");
                        $stmt->execute([$cat_id, $col0, $name]);
                    } else {
                        $preview_data[$current_area_id]['items'][$current_param_id]['items'][$current_section_id ?? 'root']['items'][] = ['code' => $col0, 'name' => $name];
                    }
                    $stats['requirements']++;
                }
                $pending_new_category = false;
            }
        }

        if (!$is_dry_run) {
            $db->commit();
            echo json_encode([
                'success' => true, 
                'message' => "Successfully imported '{$acc_name}'.",
                'details' => "Added {$stats['categories']} categories and {$stats['requirements']} requirements."
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'is_dry_run' => true,
                'stats' => $stats,
                'preview' => $preview_data
            ]);
        }

    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Excel parsing error: ' . SimpleXLSX::parseError()]);
}
