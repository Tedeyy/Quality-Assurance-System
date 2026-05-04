<?php
/**
 * AACCUP Institution Standard Import Template
 * 
 * Specifically tuned for the Institution template where:
 * - Area, Parameter, and the 3 Sections (System, Implementation, Outcome) are present.
 * - Column A contains the Requirement Code.
 * - Column B (and following) contains the Requirement Name/Description.
 * - If Column A is empty, the first filled column is the Code, making it a Sub-Requirement.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
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
$acc_name = !empty($_POST['accreditation_name']) ? $_POST['accreditation_name'] : 'Imported Institution Accreditation';
$acc_desc = !empty($_POST['accreditation_desc']) ? $_POST['accreditation_desc'] : 'Bulk imported via Institution Template';
$is_dry_run = ($_POST['dry_run'] ?? '0') === '1';
$selected_sheets = isset($_POST['selected_sheets']) ? json_decode($_POST['selected_sheets'], true) : null;

$file = $_FILES['excel_file']['tmp_name'];

if ($xlsx = SimpleXLSX::parse($file)) {
    try {
        error_reporting(E_ALL);
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
                $code_base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($_FILES['excel_file']['name'], PATHINFO_FILENAME));
                $code_base = trim($code_base, '-');
                if ($code_base === '') $code_base = 'AACCUP-INST';
                $acc_code = substr($code_base, 0, 32) . '-' . substr(strtoupper(hash('sha256', $acc_name . uniqid('', true))), 0, 8);
                $stmt = $db->prepare("INSERT INTO accreditations (code, name, description, status) VALUES (?, ?, ?, 'Inactive')");
                $stmt->execute([$acc_code, $acc_name, $acc_desc]);
                $accreditation_id = $db->lastInsertId();
            }
        }

        $workbook_name = pathinfo($_FILES['excel_file']['name'], PATHINFO_FILENAME);
        $sheetNames = $xlsx->sheetNames();
        $stats = ['categories' => 0, 'requirements' => 0, 'skipped_requirements' => 0];
        $preview_data = [];

        $workbook_root_id = null;
        if (!$is_dry_run) {
            $workbook_root_id = $getOrCreateCat($db, $accreditation_id, $workbook_name, null);
        } else {
            $workbook_root_id = "workbook_root";
            $preview_data[$workbook_root_id] = ['name' => "Workbook: " . $workbook_name, 'items' => []];
        }
        $stats['categories']++;

        if (is_array($selected_sheets) && empty($selected_sheets)) $selected_sheets = null;
        if (is_array($selected_sheets)) $selected_sheets = array_map('trim', $selected_sheets);

        foreach ($sheetNames as $sheetIndex => $sheetName) {
            $sheetNameTrimmed = trim($sheetName);
            if ($selected_sheets !== null && is_array($selected_sheets)) {
                $matched = false;
                foreach ($selected_sheets as $sel) { if (strcasecmp(trim($sel), $sheetNameTrimmed) === 0) { $matched = true; break; } }
                if (!$matched) continue;
            }

            $rows = $xlsx->rows($sheetIndex);
            $current_sheet_id = null;
            if (!$is_dry_run) {
                $current_sheet_id = $getOrCreateCat($db, $accreditation_id, $sheetName, $workbook_root_id);
            } else {
                $current_sheet_id = "sheet_" . $sheetIndex;
                $preview_data[$workbook_root_id]['items'][$current_sheet_id] = ['name' => "Worksheet: " . $sheetName, 'raw_sheet_name' => $sheetName, 'items' => []];
            }
            $stats['categories']++;

            $current_cats = [1 => $workbook_root_id, 2 => $current_sheet_id, 3 => null, 4 => null, 5 => null, 6 => null];
            $last_reqs = [];
            $area_number = '';
            $area_title_text = '';
            $scanning_started = false;

            $normalizeSectionLabel = function ($s) {
                $s = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $s);
                return trim(preg_replace('/\s+/', ' ', $s));
            };

            $applyAreaToSheetCategory = function () use (&$is_dry_run, &$db, &$accreditation_id, &$current_cats, &$workbook_root_id, &$preview_data, &$area_number, &$area_title_text, $sheetName) {
                $composed = trim(trim($area_number) . ' ' . trim($area_title_text));
                if ($composed === '') $composed = "Area: " . $sheetName;
                if (!$is_dry_run) {
                    $stmt = $db->prepare("UPDATE accreditation_categories SET name = ? WHERE category_id = ?");
                    $stmt->execute([$composed, $current_cats[2]]);
                } else {
                    $preview_data[$workbook_root_id]['items'][$current_cats[2]]['name'] = $composed;
                }
            };

            foreach ($rows as $rowIndex => $row) {
                $row_raw = array_map('trim', $row);
                if (empty(implode('', $row_raw))) continue;

                $col0 = $row_raw[0] ?? '';
                $col1 = $row_raw[1] ?? '';
                $col4 = $row_raw[4] ?? '';
                $col5 = $row_raw[5] ?? '';
                $search_text = ($col0 === $col1) ? $col0 : trim($col0 . " " . $col1);

                if (!$scanning_started) {
                    if (preg_match('/^Area\s*Number$/iu', $col4)) { if ($col5 !== '') $area_number = $col5; continue; }
                    if (preg_match('/^Area\s*Title$/iu', $col4)) { if ($col5 !== '') $area_title_text = $col5; continue; }
                    if ($col4 !== '' && stripos($col4, 'Area Number') === 0) { if (preg_match('/Area\s*Number\s*[:\-]\s*(.+)$/iu', $col4, $m)) $area_number = trim($m[1]); continue; }
                    if ($col4 !== '' && stripos($col4, 'Area Title') === 0) { if (preg_match('/Area\s*Title\s*[:\-]\s*(.+)$/iu', $col4, $m)) $area_title_text = trim($m[1]); continue; }
                    
                    if (preg_match('/^PARAMETER\s+[A-Z]:/i', $search_text)) {
                        $scanning_started = true;
                        $applyAreaToSheetCategory();
                    } else continue;
                }

                // Blacklist common headers
                $blacklist = ['Requirement Name', 'Code', 'Description', 'Rating', 'Mean', 'Total', 'Grand Total'];
                $is_header = false;
                foreach ($row_raw as $cell) { foreach ($blacklist as $term) { if (strcasecmp($cell, $term) === 0) { $is_header = true; break 2; } } }
                if ($is_header && empty($row_raw[0])) continue;

                // --- INSTITUTIONAL HIERARCHY & REQUIREMENT RULES ---
                $is_l3_param = preg_match('/^PARAMETER\s+[A-Z]:/i', $search_text);
                
                if ($is_l3_param) {
                    // L3: Parameters Only
                    $cat_name = $search_text;
                    if (!$is_dry_run) {
                        $current_cats[3] = $getOrCreateCat($db, $accreditation_id, $cat_name, $current_cats[2]);
                    } else {
                        $current_cats[3] = "temp_param_" . $sheetIndex . "_" . $rowIndex;
                        $preview_data[$workbook_root_id]['items'][$current_cats[2]]['items'][$current_cats[3]] = ['name' => $cat_name, 'items' => []];
                    }
                    for ($d = 4; $d <= 6; $d++) $current_cats[$d] = null;
                    $last_reqs = []; $stats['categories']++;
                    continue;
                }

                // If not L3/L4, it's a Requirement
                $colA = !empty($row_raw[0]);
                $colB = !empty($row_raw[1]);
                $colC = !empty($row_raw[2]);
                $is_req = false;
                $req_depth = 1;
                $codename = '';
                $full_name = '';

                if ($colA && $colB) {
                    $is_req = true; $req_depth = 1; $codename = $row_raw[0]; $full_name = trim($row_raw[0] . ' ' . $row_raw[1]);
                } elseif (!$colA && $colB && !$colC) {
                    $is_req = true; $req_depth = 1; $codename = $row_raw[1]; $full_name = $row_raw[1];
                } elseif (!$colA && $colB && $colC) {
                    $is_req = true; $req_depth = 2; $codename = $row_raw[1]; $full_name = trim($row_raw[1] . ' ' . $row_raw[2]);
                } elseif (!$colA && !$colB && $colC) {
                    $is_req = true; $req_depth = 2; $codename = $row_raw[2]; $full_name = $row_raw[2];
                }

                if ($is_req) {
                    // --- SMART DEPTH & SECTION DETECTION ---
                    // If codename follows S.1.1, I.1, etc., override section and depth
                    if (preg_match('/^([SIO])\.([\d\.]+)/i', $codename, $m)) {
                        $prefix = strtoupper($m[1]);
                        $dots = substr_count($codename, '.');
                        $req_depth = $dots; // S.1 (1 dot) = Depth 1, S.1.1 (2 dots) = Depth 2

                        // Auto-route to correct L4 Section if prefix is present
                        $target_l4 = '';
                        if ($prefix === 'S') $target_l4 = 'SYSTEM - INPUTS AND PROCESSES';
                        elseif ($prefix === 'I') $target_l4 = 'IMPLEMENTATION';
                        elseif ($prefix === 'O') $target_l4 = 'OUTCOME/S';

                        if ($target_l4 && (!$current_cats[4] || stripos($preview_data[$workbook_root_id]['items'][$current_cats[2]]['items'][$current_cats[4]]['name'] ?? '', substr($target_l4, 0, 5)) === false)) {
                             // Force L4 Section if it changed or wasn't set
                             if (!$is_dry_run) {
                                 $current_cats[4] = $getOrCreateCat($db, $accreditation_id, $target_l4, $current_cats[3] ?: $current_cats[2]);
                             } else {
                                 $current_cats[4] = "auto_l4_" . $prefix;
                                 $target_preview = &$preview_data[$workbook_root_id]['items'][$current_cats[2]]['items'];
                                 if (isset($current_cats[3]) && $current_cats[3] && isset($target_preview[$current_cats[3]])) $target_preview = &$target_preview[$current_cats[3]]['items'];
                                 if (!isset($target_preview[$current_cats[4]])) $target_preview[$current_cats[4]] = ['name' => $target_l4, 'items' => []];
                             }
                             for ($d = 5; $d <= 6; $d++) $current_cats[$d] = null;
                        }
                    }

                    if (!$is_dry_run) {
                        $cat_id = $current_cats[6] ?: ($current_cats[5] ?: ($current_cats[4] ?: ($current_cats[3] ?: $current_cats[2])));
                        $parent_req_id = ($req_depth > 1) ? ($last_reqs[$req_depth - 1] ?? null) : null;
                        
                        $stmt = $db->prepare("SELECT requirement_id FROM accreditation_requirement 
                            WHERE category_id = ? AND codename = ? AND name = ? 
                            AND (parent_requirement_id = ? OR (parent_requirement_id IS NULL AND ? IS NULL)) LIMIT 1");
                        $stmt->execute([$cat_id, $codename, $full_name, $parent_req_id, $parent_req_id]);
                        $req_res = $stmt->fetch();
                        
                        if ($req_res) {
                            $last_reqs[$req_depth] = $req_res['requirement_id'];
                            $stats['skipped_requirements']++;
                        } else {
                            try {
                                $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, codename, name, parent_requirement_id) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$cat_id, $codename, $full_name, $parent_req_id]);
                                $last_reqs[$req_depth] = $db->lastInsertId();
                                $stats['requirements']++;
                            } catch (Exception $e) {
                                error_log("[AACCUP-INST] Row {$rowIndex}: " . $e->getMessage());
                            }
                        }
                    } else {
                        $last_reqs[$req_depth] = "temp_req_" . $rowIndex;
                        $target_preview = &$preview_data[$workbook_root_id]['items'][$current_cats[2]]['items'];
                        for ($d = 3; $d <= 6; $d++) {
                            if (isset($current_cats[$d]) && $current_cats[$d] && isset($target_preview[$current_cats[$d]])) {
                                $target_preview = &$target_preview[$current_cats[$d]]['items'];
                            }
                        }
                        $indent = str_repeat("&nbsp;", ($req_depth - 1) * 4);
                        $target_preview[] = [
                            'code' => $codename,
                            'name' => $indent . ($req_depth > 1 ? "└ " : "") . $full_name
                        ];
                        $stats['requirements']++;
                    }
                    for ($r = $req_depth + 1; $r <= 5; $r++) unset($last_reqs[$r]);
                    continue;
                }
            }
        }

        if (!$is_dry_run) {
            $db->commit();
            echo json_encode(['success' => true, 'message' => "Successfully imported '{$acc_name}'.", 'details' => "Added {$stats['categories']} categories and {$stats['requirements']} requirements (" . $stats['skipped_requirements'] . " already existed)."]);
        } else {
            require_once __DIR__ . '/../../api/ai_service.php';
            $ai = new AIService();
            $ai_summary = []; foreach($preview_data as $root) { $ai_summary[$root['name']] = array_keys($root['items']); }
            $ai_insights = $ai->analyzeStructure($ai_summary);
            echo json_encode(['success' => true, 'is_dry_run' => true, 'stats' => $stats, 'preview' => $preview_data, 'ai_insights' => $ai_insights]);
        }
    } catch (Exception $e) { if ($db && $db->inTransaction()) $db->rollBack(); echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]); }
} else { echo json_encode(['success' => false, 'message' => 'Excel parsing error: ' . SimpleXLSX::parseError()]); }
