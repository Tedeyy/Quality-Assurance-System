<?php
/**
 * SUC Standard Bulk Import Scanner
 * Row 2 onward (1-based): Col A = KRA, Col C = criterion code, Col E = name/indicator,
 * Col H (optional) = expected proof(s) of compliance — split on newline, semicolon, or pipe.
 * Proofs are stored as document_bridge rows (requirement_id + proof_name); files are linked later in the tracker.
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

        $stats = [
            'categories' => 0,
            'requirements' => 0,
            'skipped_requirements' => 0,
            'proofs_added' => 0,
            'proofs_skipped_duplicate' => 0,
        ];

        $splitProofCell = static function (string $cell): array {
            if ($cell === '') {
                return [];
            }
            $parts = preg_split('/[\r\n;|]+/u', $cell) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $out[] = $p;
                }
            }
            return $out;
        };

        $ensureProofBridges = static function (PDO $db, int $requirement_id, array $proof_names) use (&$stats): void {
            if ($proof_names === []) {
                return;
            }
            $sel = $db->prepare('SELECT bridge_id FROM document_bridge WHERE requirement_id = ? AND proof_name = ? LIMIT 1');
            $ins = $db->prepare('INSERT INTO document_bridge (requirement_id, proof_name) VALUES (?, ?)');
            foreach ($proof_names as $proof_name) {
                $sel->execute([$requirement_id, $proof_name]);
                if ($sel->fetch()) {
                    $stats['proofs_skipped_duplicate']++;
                    continue;
                }
                $ins->execute([$requirement_id, $proof_name]);
                $stats['proofs_added']++;
            }
        };

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
                // Column C: hierarchical codes (e.g. 1.1.1). Excel may return floats; DB column must be VARCHAR.
                $rawCode = $row_raw[2] ?? '';
                if ($rawCode === '' || $rawCode === null) {
                    $code = '';
                } elseif (is_float($rawCode)) {
                    $code = rtrim(rtrim(sprintf('%.12F', $rawCode), '0'), '.');
                } else {
                    $code = trim((string) $rawCode);
                }
                $name = $row_raw[4] ?? ''; // Column E
                $proofsRaw = trim((string) ($row_raw[7] ?? '')); // Column H — expected proof(s)

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
                    $proof_names = $splitProofCell($proofsRaw);

                    if (!$is_dry_run) {
                        $stmt = $db->prepare("SELECT requirement_id FROM accreditation_requirement 
                            WHERE category_id = ? AND codename = ? AND name = ? LIMIT 1");
                        $stmt->execute([$current_kra_id, $code, $name]);
                        $req_res = $stmt->fetch();

                        if ($req_res) {
                            $stats['skipped_requirements']++;
                            $req_id = (int) $req_res['requirement_id'];
                            $ensureProofBridges($db, $req_id, $proof_names);
                        } else {
                            try {
                                $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, codename, name) VALUES (?, ?, ?)");
                                $stmt->execute([$current_kra_id, $code, $name]);
                                $stats['requirements']++;
                                $req_id = (int) $db->lastInsertId();
                                $ensureProofBridges($db, $req_id, $proof_names);
                            } catch (Exception $e) {
                                error_log("[SUC] Row {$rowIndex}: " . $e->getMessage());
                            }
                        }
                    } else {
                        $preview_data[$sheet_root_id]['items'][$current_kra_id]['items'][] = [
                            'code' => $code,
                            'name' => $name,
                            'proofs' => $proofsRaw !== '' ? $proofsRaw : null,
                        ];
                        $stats['requirements']++;
                    }
                }
            }
        }

        if (!$is_dry_run) {
            $db->commit();
            $proofPart = ($stats['proofs_added'] > 0 || $stats['proofs_skipped_duplicate'] > 0)
                ? ' Proofs: ' . $stats['proofs_added'] . ' added'
                    . ($stats['proofs_skipped_duplicate'] > 0 ? ', ' . $stats['proofs_skipped_duplicate'] . ' duplicate name(s) skipped.' : '.')
                : '';
            echo json_encode([
                'success' => true,
                'message' => 'Successfully imported SUC standard.',
                'details' => "Added {$stats['categories']} categories and {$stats['requirements']} requirements ({$stats['skipped_requirements']} already existed).{$proofPart}",
            ]);
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
