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
$selected_sheets = isset($_POST['selected_sheets']) ? json_decode($_POST['selected_sheets'], true) : null;

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

        $sheetNames = $xlsx->sheetNames();
        $stats = ['categories' => 0, 'requirements' => 0];
        $preview_data = [];

        foreach ($sheetNames as $sheetIndex => $sheetName) {
            // IF selected_sheets is provided, only process those
            if (!$is_dry_run && $selected_sheets !== null && !in_array($sheetName, $selected_sheets)) {
                continue;
            }

            $rows = $xlsx->rows($sheetIndex);
            
            $current_root_id = null; // Sheet-level category
            $current_area_id = null;
            $current_param_id = null;
            $current_section_id = null;
            $pending_new_category = false;
            
            // Track requirements at different indentation levels
            $last_req_at_level = []; 

            // 0. Create Primary Parent (Worksheet Name)
            if (!$is_dry_run) {
                $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, null)");
                $stmt->execute([$accreditation_id, $sheetName]);
                $current_root_id = $db->lastInsertId();
            } else {
                $current_root_id = "root_" . $sheetIndex;
                $preview_data[$current_root_id] = ['name' => "Worksheet: " . $sheetName, 'items' => []];
            }
            $stats['categories']++;

            // Specific handling for Area headers at the top
            $area_number = '';
            $area_title = '';

            foreach ($rows as $rowIndex => $row) {
                $row_raw = array_map('trim', $row);
                if (empty(implode('', $row_raw))) {
                    $pending_new_category = true;
                    continue;
                }

                $col0 = $row_raw[0] ?? '';
                $col1 = $row_raw[1] ?? '';
                $col2 = $row_raw[2] ?? '';
                $col3 = $row_raw[3] ?? '';
                $col4 = $row_raw[4] ?? '';

                // 1. Detect Area at top (Rows 1-2 in template)
                if ($rowIndex == 1 && !empty($col4)) $area_number = $col4;
                if ($rowIndex == 2 && !empty($col4)) {
                    $area_title = trim($area_number . " " . $col4);
                    if (!$is_dry_run) {
                        $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, ?)");
                        $stmt->execute([$accreditation_id, $area_title, $current_root_id]);
                        $current_area_id = $db->lastInsertId();
                    } else {
                        $current_area_id = "temp_area_" . $sheetIndex . "_" . $rowIndex;
                        $preview_data[$current_root_id]['items'][$current_area_id] = ['name' => $area_title, 'items' => []];
                    }
                    $stats['categories']++;
                    $pending_new_category = false;
                    continue;
                }

                // 2. Detect Parameter Section (e.g. PARAMETER A: ...)
                if (!empty($col0) && $col0 === $col1 && stripos($col0, 'PARAMETER') !== false) {
                    if (!$is_dry_run) {
                        $parent = $current_area_id ?: $current_root_id;
                        $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, ?)");
                        $stmt->execute([$accreditation_id, $col0, $parent]);
                        $current_param_id = $db->lastInsertId();
                    } else {
                        $current_param_id = "temp_param_" . $sheetIndex . "_" . $rowIndex;
                        $parent_key = $current_area_id ?: $current_root_id;
                        if ($parent_key === $current_root_id) {
                            $preview_data[$current_root_id]['items'][$current_param_id] = ['name' => $col0, 'items' => []];
                        } else {
                            $preview_data[$current_root_id]['items'][$current_area_id]['items'][$current_param_id] = ['name' => $col0, 'items' => []];
                        }
                    }
                    $current_section_id = null;
                    $stats['categories']++;
                    $pending_new_category = false;
                    $last_req_at_level = []; // Reset nesting on new param
                    continue;
                }

                // 3. Detect Sub-Section (SYSTEM, IMPLEMENTATION, OUTCOME or Space-triggered or Empty-A/Has-B)
                $is_section = false; $section_label = '';
                if (!empty($col0) || !empty($col1)) {
                    $search = $col0 . " " . $col1;
                    if (stripos($search, 'SYSTEM') !== false && (stripos($search, 'INPUT') !== false || stripos($search, 'PROCESS') !== false)) { 
                        $is_section = true; $section_label = "SYSTEM - INPUTS AND PROCESSES"; 
                    }
                    elseif (stripos($search, 'IMPLEMENTATION') !== false) { 
                        $is_section = true; $section_label = "IMPLEMENTATION"; 
                    }
                    elseif (stripos($search, 'OUTCOME') !== false) { 
                        $is_section = true; $section_label = "OUTCOME/S"; 
                    }
                    elseif ($pending_new_category && empty($col1) && !empty($col0)) { 
                        $is_section = true; $section_label = $col0; 
                    }
                    elseif (empty($col0) && !empty($col1)) {
                        $is_section = true; $section_label = $col1 . ($col2 ? " " . $col2 : "");
                    }
                }

                if ($is_section) {
                    if (!$is_dry_run) {
                        $parent = $current_param_id ?: ($current_area_id ?: $current_root_id);
                        $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, ?)");
                        $stmt->execute([$accreditation_id, $section_label, $parent]);
                        $current_section_id = $db->lastInsertId();
                    } else {
                        $current_section_id = "temp_sec_" . $sheetIndex . "_" . $rowIndex;
                        if ($current_param_id) {
                            $preview_data[$current_root_id]['items'][$current_area_id]['items'][$current_param_id]['items'][$current_section_id] = ['name' => $section_label, 'items' => []];
                        } else {
                            $target = $current_area_id ?: $current_root_id;
                            $preview_data[$current_root_id]['items'][$target]['items'][$current_section_id] = ['name' => $section_label, 'items' => []];
                        }
                    }
                    $stats['categories']++;
                    $pending_new_category = false;
                    $last_req_at_level = []; // Reset nesting on new section
                    continue;
                }

                // 4. Detect Requirement
                if (!empty($col0) && $col0 !== $col1) {
                    if ($current_param_id === null && $current_area_id === null) {
                        $pending_new_category = false;
                        continue;
                    }
                    $code = ''; $name = ''; $found_code = false;
                    $level = 0;
                    for ($i = 1; $i < count($row_raw); $i++) {
                        if (!empty($row_raw[$i])) {
                            if (!$found_code) { 
                                $code = $row_raw[$i]; 
                                $found_code = true; 
                                $level = $i; // Use column index as level
                            } else { 
                                $name = $row_raw[$i]; 
                                break; 
                            }
                        }
                    }

                    if (!empty($name)) {
                        $parent_req_id = null;
                        if ($level > 1 && isset($last_req_at_level[$level - 1])) {
                            $parent_req_id = $last_req_at_level[$level - 1];
                        }

                        if (!$is_dry_run) {
                            $cat_id = $current_section_id ?: ($current_param_id ?: $current_area_id);
                            $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, codename, name, parent_requirement_id) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$cat_id, $col0, $name, $parent_req_id]);
                            $last_req_id = $db->lastInsertId();
                            $last_req_at_level[$level] = $last_req_id;
                        } else {
                            // Find where to put it in preview
                            if ($current_param_id) {
                                if ($current_section_id) {
                                    $indent = str_repeat("&nbsp;", ($level - 1) * 4);
                                    $preview_data[$current_root_id]['items'][$current_area_id]['items'][$current_param_id]['items'][$current_section_id]['items'][] = ['code' => $col0, 'name' => $indent . ($parent_req_id ? "└ " : "") . $name];
                                }
                            }
                            $last_req_at_level[$level] = "temp_req_" . $rowIndex;
                        }
                        $stats['requirements']++;
                    }
                    $pending_new_category = false;
                }
            }
        }

        if (!$is_dry_run) {
            $db->commit();
            echo json_encode([
                'success' => true, 
                'message' => "Successfully imported '{$acc_name}'.",
                'details' => "Added {$stats['categories']} categories and {$stats['requirements']} requirements across " . count($sheetNames) . " sheets."
            ]);
        } else {
            // Add AI Analysis
            require_once __DIR__ . '/ai_service.php';
            $ai = new AIService();
            
            $ai_summary = [];
            foreach($preview_data as $root) {
                $ai_summary[$root['name']] = array_keys($root['items']);
            }
            $ai_insights = $ai->analyzeStructure($ai_summary);

            echo json_encode([
                'success' => true,
                'is_dry_run' => true,
                'stats' => $stats,
                'preview' => $preview_data,
                'ai_insights' => $ai_insights
            ]);
        }

    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Excel parsing error: ' . SimpleXLSX::parseError()]);
}
