<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/utils/logger.php';

$db = (new Database())->getConnection();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $doc_code = trim($_POST['doc_code'] ?? '');
        $office_of_origin = trim($_POST['office_of_origin'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $confidentiality = (int)($_POST['confidentiality'] ?? 1);
        $tags_input = $_POST['tags'] ?? '';
        
        if (empty($doc_code) || empty($office_of_origin) || empty($category)) {
            $_SESSION['error'] = 'Document Code, Office of Origin, and Category are required.';
            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        }

        try {
            $db->beginTransaction();

            // 1. Insert Document
            $stmt = $db->prepare("
                INSERT INTO documents (doc_code, office_of_origin, category, purpose, confidentiality) 
                VALUES (:doc_code, :office_of_origin, :category, :purpose, :confidentiality)
            ");
            $stmt->execute([
                'doc_code' => $doc_code,
                'office_of_origin' => $office_of_origin,
                'category' => $category,
                'purpose' => $purpose ?: null,
                'confidentiality' => $confidentiality
            ]);
            $doc_id = $db->lastInsertId();

            // 2. Link Tags
            if (!empty($tags_input)) {
                $tags_arr = [];
                if (is_array($tags_input)) {
                    $tags_arr = array_filter(array_map('trim', $tags_input));
                } else {
                    $tags_arr = array_filter(array_map('trim', explode(',', $tags_input)));
                }

                foreach ($tags_arr as $tname) {
                    if (empty($tname)) continue;
                    
                    // Try to insert/get tag
                    $tag_stmt = $db->prepare("SELECT tag_id FROM tags WHERE tag_name = :tname");
                    $tag_stmt->execute(['tname' => $tname]);
                    $tag_id = $tag_stmt->fetchColumn();

                    if (!$tag_id) {
                        $ins_tag = $db->prepare("INSERT INTO tags (tag_name) VALUES (:tname)");
                        $ins_tag->execute(['tname' => $tname]);
                        $tag_id = $db->lastInsertId();
                    }

                    // Link to document
                    $link_stmt = $db->prepare("INSERT IGNORE INTO document_tags (doc_id, tag_id) VALUES (:doc_id, :tag_id)");
                    $link_stmt->execute(['doc_id' => $doc_id, 'tag_id' => $tag_id]);
                }
            }

            $db->commit();
            $_SESSION['success'] = 'Document mapped and registered successfully!';
            
            // Log activity if log function exists
            if (function_exists('logActivity')) {
                logActivity($db, $_SESSION['user_id'] ?? 0, "Registered new document: $doc_code ($category)");
            }

            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to register document: ' . $e->getMessage();
            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        }
    } elseif ($action === 'edit') {
        $doc_id = $_POST['doc_id'] ?? null;
        $doc_code = trim($_POST['doc_code'] ?? '');
        $office_of_origin = trim($_POST['office_of_origin'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $confidentiality = (int)($_POST['confidentiality'] ?? 1);
        $tags_input = $_POST['tags'] ?? '';

        if (empty($doc_id) || empty($doc_code) || empty($office_of_origin) || empty($category)) {
            $_SESSION['error'] = 'Required fields missing for document update.';
            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        }

        try {
            $db->beginTransaction();

            // 1. Update Document
            $stmt = $db->prepare("
                UPDATE documents 
                SET doc_code = :doc_code, 
                    office_of_origin = :office_of_origin, 
                    category = :category, 
                    purpose = :purpose, 
                    confidentiality = :confidentiality 
                WHERE doc_id = :doc_id
            ");
            $stmt->execute([
                'doc_code' => $doc_code,
                'office_of_origin' => $office_of_origin,
                'category' => $category,
                'purpose' => $purpose ?: null,
                'confidentiality' => $confidentiality,
                'doc_id' => $doc_id
            ]);

            // 2. Clear existing tags links
            $del_tags = $db->prepare("DELETE FROM document_tags WHERE doc_id = :doc_id");
            $del_tags->execute(['doc_id' => $doc_id]);

            // 3. Insert new tag mappings
            if (!empty($tags_input)) {
                $tags_arr = [];
                if (is_array($tags_input)) {
                    $tags_arr = array_filter(array_map('trim', $tags_input));
                } else {
                    $tags_arr = array_filter(array_map('trim', explode(',', $tags_input)));
                }

                foreach ($tags_arr as $tname) {
                    if (empty($tname)) continue;

                    // Try to insert/get tag
                    $tag_stmt = $db->prepare("SELECT tag_id FROM tags WHERE tag_name = :tname");
                    $tag_stmt->execute(['tname' => $tname]);
                    $tag_id = $tag_stmt->fetchColumn();

                    if (!$tag_id) {
                        $ins_tag = $db->prepare("INSERT INTO tags (tag_name) VALUES (:tname)");
                        $ins_tag->execute(['tname' => $tname]);
                        $tag_id = $db->lastInsertId();
                    }

                    // Link to document
                    $link_stmt = $db->prepare("INSERT IGNORE INTO document_tags (doc_id, tag_id) VALUES (:doc_id, :tag_id)");
                    $link_stmt->execute(['doc_id' => $doc_id, 'tag_id' => $tag_id]);
                }
            }

            $db->commit();
            $_SESSION['success'] = 'Document updated successfully!';

            if (function_exists('logActivity')) {
                logActivity($db, $_SESSION['user_id'] ?? 0, "Updated document: $doc_code ($category)");
            }

            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        } catch (PDOException $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Failed to update document: ' . $e->getMessage();
            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'delete') {
        $doc_id = $_GET['doc_id'] ?? null;
        if (empty($doc_id)) {
            $_SESSION['error'] = 'Document ID is required.';
            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        }

        try {
            $stmt = $db->prepare("DELETE FROM documents WHERE doc_id = :id");
            $stmt->execute(['id' => $doc_id]);

            $_SESSION['success'] = 'Document deleted successfully!';
            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Failed to delete document: ' . $e->getMessage();
            $redirect_url = $_POST['redirect_url'] ?? $_GET['redirect_url'] ?? '../views/feed.php?action=document';
            header('Location: ' . $redirect_url);
            exit;
        }
    } elseif ($action === 'get') {
        header('Content-Type: application/json');
        $doc_id = $_GET['doc_id'] ?? null;
        if (empty($doc_id)) {
            echo json_encode(['success' => false, 'message' => 'Document ID is required.']);
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM documents WHERE doc_id = :id");
            $stmt->execute(['id' => $doc_id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($doc) {
                // Fetch tags
                $tag_stmt = $db->prepare("
                    SELECT t.tag_name 
                    FROM tags t 
                    JOIN document_tags dt ON t.tag_id = dt.tag_id 
                    WHERE dt.doc_id = :id
                ");
                $tag_stmt->execute(['id' => $doc_id]);
                $doc['tags'] = $tag_stmt->fetchAll(PDO::FETCH_COLUMN);

                echo json_encode(['success' => true, 'data' => $doc]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Document not found.']);
            }
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'similarity') {
        header('Content-Type: application/json');
        $doc_id = $_GET['doc_id'] ?? null;
        if (empty($doc_id)) {
            echo json_encode(['success' => false, 'message' => 'Document ID is required.']);
            exit;
        }

        try {
            // 1. Fetch selected document
            $stmt = $db->prepare("SELECT * FROM documents WHERE doc_id = :id");
            $stmt->execute(['id' => $doc_id]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                echo json_encode(['success' => false, 'message' => 'Document not found.']);
                exit;
            }

            // Get target tags
            $tag_stmt = $db->prepare("
                SELECT t.tag_name 
                FROM tags t 
                JOIN document_tags dt ON t.tag_id = dt.tag_id 
                WHERE dt.doc_id = :id
            ");
            $tag_stmt->execute(['id' => $doc_id]);
            $target_tags = $tag_stmt->fetchAll(PDO::FETCH_COLUMN);

            // 2. Fetch all other documents
            $stmt_others = $db->prepare("SELECT * FROM documents WHERE doc_id != :id");
            $stmt_others->execute(['id' => $doc_id]);
            $others = $stmt_others->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($others as $doc) {
                $id = $doc['doc_id'];

                // Get other tags
                $tag_stmt2 = $db->prepare("
                    SELECT t.tag_name 
                    FROM tags t 
                    JOIN document_tags dt ON t.tag_id = dt.tag_id 
                    WHERE dt.doc_id = :id
                ");
                $tag_stmt2->execute(['id' => $id]);
                $other_tags = $tag_stmt2->fetchAll(PDO::FETCH_COLUMN);

                // Calculation variables
                // A. Office Match (max 25 pts)
                $officeScore = (strtolower($target['office_of_origin']) === strtolower($doc['office_of_origin'])) ? 25 : 0;

                // B. Category Match (max 20 pts)
                $categoryScore = (strtolower($target['category']) === strtolower($doc['category'])) ? 20 : 0;

                // C. Tag Overlap (max 30 pts) using Jaccard Similarity
                $tagIntersection = array_intersect($target_tags, $other_tags);
                $tagUnion = array_unique(array_merge($target_tags, $other_tags));
                $tagScore = (count($tagUnion) > 0) ? (count($tagIntersection) / count($tagUnion)) * 30 : 0;

                // D. Purpose Similarity (max 20 pts)
                $purposeScore = 0;
                if (!empty($target['purpose']) && !empty($doc['purpose'])) {
                    // Tokenize words
                    $words1 = str_word_count(strtolower($target['purpose']), 1);
                    $words2 = str_word_count(strtolower($doc['purpose']), 1);
                    // Stopwords
                    $stopwords = ['the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were', 'to', 'for', 'in', 'on', 'at', 'by', 'with', 'of', 'this', 'that', 'our', 'their'];
                    $clean1 = array_diff($words1, $stopwords);
                    $clean2 = array_diff($words2, $stopwords);

                    $pIntersection = array_intersect($clean1, $clean2);
                    $pUnion = array_unique(array_merge($clean1, $clean2));
                    $purposeScore = (count($pUnion) > 0) ? (count($pIntersection) / count($pUnion)) * 20 : 0;
                }

                // E. Confidentiality Compatibility (max 5 pts)
                $confScore = ($target['confidentiality'] == $doc['confidentiality']) ? 5 : 0;

                $totalScore = $officeScore + $categoryScore + $tagScore + $purposeScore + $confScore;

                $results[] = [
                    'doc_id' => $doc['doc_id'],
                    'doc_code' => $doc['doc_code'],
                    'office_of_origin' => $doc['office_of_origin'],
                    'category' => $doc['category'],
                    'purpose' => $doc['purpose'],
                    'confidentiality' => $doc['confidentiality'],
                    'tags' => $other_tags,
                    'scores' => [
                        'office' => round($officeScore, 1),
                        'category' => round($categoryScore, 1),
                        'tag' => round($tagScore, 1),
                        'purpose' => round($purposeScore, 1),
                        'confidentiality' => round($confScore, 1),
                        'total' => round($totalScore, 1)
                    ]
                ];
            }

            // Sort from highest score to lowest
            usort($results, function($a, $b) {
                return $b['scores']['total'] <=> $a['scores']['total'];
            });

            // Return target document info + results
            echo json_encode([
                'success' => true,
                'target' => [
                    'doc_id' => $target['doc_id'],
                    'doc_code' => $target['doc_code'],
                    'office_of_origin' => $target['office_of_origin'],
                    'category' => $target['category'],
                    'purpose' => $target['purpose'],
                    'confidentiality' => $target['confidentiality'],
                    'tags' => $target_tags
                ],
                'recommendations' => $results
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}
