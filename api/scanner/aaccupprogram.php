<?php
/**
 * AACCUP Program Standard Import Template
 * 
 * This script is specifically tuned to parse the AACCUP hierarchical structure:
 * Program (Worksheet) -> Area -> Parameter -> Section (Inputs, Implementation, Outcome) -> Requirements.
 * Supports depth levels L1-L6 and nested sub-requirements.
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
$acc_name = !empty($_POST['accreditation_name']) ? $_POST['accreditation_name'] : 'Imported Accreditation';
$acc_desc = !empty($_POST['accreditation_desc']) ? $_POST['accreditation_desc'] : 'Bulk imported via Excel';
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
                if ($code_base === '') {
                    $code_base = 'AACCUP';
                }
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

        // Helper function for "Get or Create" category
        $getOrCreateCat = function($db, $acc_id, $name, $parent_id) {
            $stmt = $db->prepare("SELECT category_id FROM accreditation_categories WHERE accreditation_id = ? AND name = ? AND (parent_category_id = ? OR (parent_category_id IS NULL AND ? IS NULL)) LIMIT 1");
            $stmt->execute([$acc_id, $name, $parent_id, $parent_id]);
            $res = $stmt->fetch();
            if ($res) return $res['category_id'];

            $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name, parent_category_id) VALUES (?, ?, ?)");
            $stmt->execute([$acc_id, $name, $parent_id]);
            return $db->lastInsertId();
        };

        // Create/Get Workbook Root Category
        $workbook_root_id = null;
        if (!$is_dry_run) {
            $workbook_root_id = $getOrCreateCat($db, $accreditation_id, $workbook_name, null);
        } else {
            $workbook_root_id = "workbook_root";
            $preview_data[$workbook_root_id] = ['name' => "Workbook: " . $workbook_name, 'items' => []];
        }
        $stats['categories']++;

        // Ensure selected_sheets is a valid array or null
        if (is_array($selected_sheets) && empty($selected_sheets)) {
            $selected_sheets = null;
        }

        error_log('[AACCUP Import] selected_sheets: ' . json_encode($selected_sheets));
        error_log('[AACCUP Import] available sheets: ' . json_encode($sheetNames));

        // Normalize sheet names for matching
        if (is_array($selected_sheets)) {
            $selected_sheets = array_map('trim', $selected_sheets);
        }

        foreach ($sheetNames as $sheetIndex => $sheetName) {
            $sheetNameTrimmed = trim($sheetName);
            if ($selected_sheets !== null && is_array($selected_sheets)) {
                // Case-insensitive, trimmed matching
                $matched = false;
                foreach ($selected_sheets as $sel) {
                    if (strcasecmp(trim($sel), $sheetNameTrimmed) === 0) { $matched = true; break; }
                }
                if (!$matched) continue;
            }

            $rows = $xlsx->rows($sheetIndex);
            
            $current_sheet_id = null;
            if (!$is_dry_run) {
                $current_sheet_id = $getOrCreateCat($db, $accreditation_id, $sheetName, $workbook_root_id);
            } else {
                $current_sheet_id = "sheet_" . $sheetIndex;
                $preview_data[$workbook_root_id]['items'][$current_sheet_id] = [
                    'name' => "Worksheet: " . $sheetName,
                    'raw_sheet_name' => $sheetName,  // Preserve raw name for finalize
                    'items' => []
                ];
            }
            $stats['categories']++;

            $current_cats = [
                1 => $workbook_root_id,
                2 => $current_sheet_id,
                3 => null,
                4 => null,
                5 => null,
                6 => null
            ];
            
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

                // Search text for detection (handle merged cells)
                $search_text = ($col0 === $col1) ? $col0 : trim($col0 . " " . $col1);

                // Phase 1: Metadata & Skip Summary Table
                if (!$scanning_started) {
                    // Official template: labels in column E (index 4), values in column F (index 5)
                    if (preg_match('/^Area\s*Number$/iu', $col4)) {
                        if ($col5 !== '') $area_number = $col5;
                        continue;
                    }
                    if (preg_match('/^Area\s*Title$/iu', $col4)) {
                        if ($col5 !== '') $area_title_text = $col5;
                        continue;
                    }
                    // Inline "Area Number: X" / value-only legacy rows (cols E–F)
                    if ($col4 !== '' && stripos($col4, 'Area Number') === 0) {
                        if (preg_match('/Area\s*Number\s*[:\-]\s*(.+)$/iu', $col4, $m)) $area_number = trim($m[1]);
                        continue;
                    }
                    if ($col4 !== '' && stripos($col4, 'Area Title') === 0) {
                        if (preg_match('/Area\s*Title\s*[:\-]\s*(.+)$/iu', $col4, $m)) $area_title_text = trim($m[1]);
                        continue;
                    }
                    // Legacy: area text directly in E when not a known label (older exports)
                    if ($rowIndex <= 5 && $col4 !== '' && !preg_match('/^Area\s*(Number|Title)$/iu', $col4)
                        && stripos($col4, 'PARAMETERS') === false && stripos($col4, 'Parameter') !== 0) {
                        if ($area_number === '') $area_number = $col4;
                        else if ($area_title_text === '') $area_title_text = $col4;
                        continue;
                    }

                    // Check for the start of real content (PARAMETER A: Title)
                    if (preg_match('/^PARAMETER\s+[A-Z]:/i', $search_text)) {
                        $scanning_started = true;
                        $applyAreaToSheetCategory();
                    } else {
                        continue; // Skip this row
                    }
                }

                // Phase 2: Process Content (Once scanning started)
                // Template Header Blacklist
                $blacklist = ['Requirement Name', 'Codename', 'Description', 'Rating', 'Mean', 'Total', 'Grand Total'];
                $is_header = false;
                foreach ($row_raw as $cell) {
                    foreach ($blacklist as $term) {
                        if (strcasecmp($cell, $term) === 0) { $is_header = true; break 2; }
                    }
                }
                if ($is_header && empty($row_raw[0])) continue;

                // 2. Detect Parameter (L3)
                if (preg_match('/^PARAMETER\s+[A-Z]:/i', $search_text)) {
                    $cat_name = $search_text;
                    if (!$is_dry_run) {
                        $parent = $current_cats[2]; // Parent is always Area (L2)
                        $current_cats[3] = $getOrCreateCat($db, $accreditation_id, $cat_name, $parent);
                    } else {
                        $current_cats[3] = "temp_param_" . $sheetIndex . "_" . $rowIndex;
                        $preview_data[$workbook_root_id]['items'][$current_cats[2]]['items'][$current_cats[3]] = ['name' => $cat_name, 'items' => []];
                    }
                    for ($d = 4; $d <= 6; $d++) $current_cats[$d] = null;
                    $stats['categories']++;
                    continue;
                }

                // 3. Detect Section (L4)
                $is_sec = false;
                $sec_label = '';
                $c0n = $normalizeSectionLabel($col0);
                $searchSec = $normalizeSectionLabel($col0 . ' ' . $col1);
                if ((stripos($c0n, '1_') === 0 || preg_match('/^1\s*_/u', $col0)) && stripos($searchSec, 'SYSTEM') !== false) {
                    $is_sec = true;
                    $sec_label = 'SYSTEM - INPUTS AND PROCESSES';
                } elseif ((stripos($c0n, '2_') === 0 || preg_match('/^2\s*_/u', $col0)) && stripos($searchSec, 'IMPLEMENTATION') !== false) {
                    $is_sec = true;
                    $sec_label = 'IMPLEMENTATION';
                } elseif ((stripos($c0n, '3_') === 0 || preg_match('/^3\s*_/u', $col0)) && stripos($searchSec, 'OUTCOME') !== false) {
                    $is_sec = true;
                    $sec_label = 'OUTCOME/S';
                }

                if ($is_sec) {
                    if (!$is_dry_run) {
                        $parent = $current_cats[3] ?: $current_cats[2];
                        $current_cats[4] = $getOrCreateCat($db, $accreditation_id, $sec_label, $parent);
                    } else {
                        $current_cats[4] = "temp_sec_" . $sheetIndex . "_" . $rowIndex;
                        $target_preview = &$preview_data[$workbook_root_id]['items'][$current_cats[2]]['items'];
                        if (isset($current_cats[3]) && $current_cats[3] && isset($target_preview[$current_cats[3]])) {
                            $target_preview = &$target_preview[$current_cats[3]]['items'];
                        }
                        $target_preview[$current_cats[4]] = ['name' => $sec_label, 'items' => []];
                    }
                    for ($d = 5; $d <= 6; $d++) $current_cats[$d] = null;
                    $last_reqs = [];
                    $stats['categories']++;
                    continue;
                }

                // 5. Detect Requirement
                $filled_cols = [];
                for ($i = 1; $i <= 5; $i++) if (!empty($row_raw[$i])) $filled_cols[] = $i;

                if (!empty($col0) || count($filled_cols) >= 2) {
                    $codename = !empty($col0) ? $col0 : ($row_raw[$filled_cols[0]] ?? 'REQ-' . $rowIndex);
                    $name_parts = [];
                    $req_depth = 1;

                    if (!empty($filled_cols)) {
                        $req_depth = $filled_cols[0];
                        foreach ($filled_cols as $idx) {
                            $val = $row_raw[$idx];
                            if ($val !== '') $name_parts[] = $val;
                        }
                    }
                    $full_name = trim(implode(' ', array_filter($name_parts)));
                    if ($full_name === '' && !empty($codename)) $full_name = $codename;

                    if (!empty($full_name)) {
                        $parent_req_id = ($req_depth > 1) ? ($last_reqs[$req_depth - 1] ?? null) : null;
                        if (!$is_dry_run) {
                            $cat_id = $current_cats[6] ?: ($current_cats[5] ?: ($current_cats[4] ?: ($current_cats[3] ?: $current_cats[2])));
                            
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
                                } catch (Exception $reqE) {
                                    error_log("[AACCUP Import Error] Row {$rowIndex}: " . $reqE->getMessage());
                                }
                            }
                        } else {
                            $last_reqs[$req_depth] = "temp_req_" . $rowIndex;
                            try {
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
                            } catch (Exception $e) {
                                $preview_data[$workbook_root_id]['items'][] = ['code' => $codename, 'name' => "[Flat] " . $full_name];
                            }
                            $stats['requirements']++;
                        }
                        for ($r = $req_depth + 1; $r <= 5; $r++) unset($last_reqs[$r]);
                        continue;
                    }
                }

                // 4. Detect Sub-Category (L5/L6)
                if (empty($col0)) {
                    $sc_filled = [];
                    for ($i = 1; $i <= 4; $i++) if (!empty($row_raw[$i])) $sc_filled[] = $i;

                    if (count($sc_filled) === 1) {
                        $col_idx = $sc_filled[0];
                        $cat_name = $row_raw[$col_idx];
                        $depth = ($col_idx <= 2) ? 5 : 6;

                        $cn_lower = strtolower($cat_name);
                        $header_exact = ['requirement name', 'codename', 'description', 'rating', 'mean', 'total', 'grand total', 'parameter name'];
                        if (!in_array($cn_lower, $header_exact, true)) {
                            if (!$is_dry_run) {
                                $parent = $current_cats[$depth - 1] ?: ($current_cats[$depth - 2] ?: $current_cats[4] ?: $current_cats[3] ?: $current_cats[2]);
                                try {
                                    $current_cats[$depth] = $getOrCreateCat($db, $accreditation_id, $cat_name, $parent);
                                } catch (Exception $catE) {
                                    error_log("[AACCUP Import Error] Row {$rowIndex} (Cat): " . $catE->getMessage());
                                }
                            } else {
                                $current_cats[$depth] = "temp_l{$depth}_" . $sheetIndex . "_" . $rowIndex;
                                $target_preview = &$preview_data[$workbook_root_id]['items'][$current_cats[2]]['items'];
                                for ($d = 3; $d < $depth; $d++) {
                                    if (isset($current_cats[$d]) && $current_cats[$d] && isset($target_preview[$current_cats[$d]])) {
                                        $target_preview = &$target_preview[$current_cats[$d]]['items'];
                                    }
                                }
                                $target_preview[$current_cats[$depth]] = ['name' => $cat_name, 'items' => []];
                            }
                            for ($d = $depth + 1; $d <= 6; $d++) $current_cats[$d] = null;
                            $stats['categories']++;
                            continue;
                        }
                    }
                }
            }
        }

        if (!$is_dry_run) {
            $db->commit();
            echo json_encode([
                'success' => true, 
                'message' => "Successfully imported '{$acc_name}'.",
                'details' => "Added {$stats['categories']} categories and {$stats['requirements']} requirements (" . $stats['skipped_requirements'] . " already existed)."
            ]);
        } else {
            // AI Analysis
            require_once __DIR__ . '/../ai_service.php';
            $ai = new AIService();
            $ai_summary = [];
            foreach($preview_data as $root) { $ai_summary[$root['name']] = array_keys($root['items']); }
            $ai_insights = $ai->analyzeStructure($ai_summary);

            echo json_encode([
                'success' => true,
                'is_dry_run' => true,
                'stats' => $stats,
                'preview' => $preview_data,
                'ai_insights' => $ai_insights
            ]);
        }
    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Excel parsing error: ' . SimpleXLSX::parseError()]);
}
