<?php
require_once __DIR__ . '/../../../config/database.php';
$db = (new Database())->getConnection();

// Fetch all accreditations
$accreditations = [];
try {
    $stmt = $db->query("SELECT * FROM accreditations ORDER BY name ASC");
    $accreditations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("acctracker accreditations query failed: " . $e->getMessage());
}

// Selected accreditation (default to first one or from GET)
$selected_id = $_GET['accreditation_id'] ?? ($accreditations[0]['accreditation_id'] ?? null);

$current_acc = null;
$categories = [];

if ($selected_id) {
    $stmt = $db->prepare("SELECT * FROM accreditations WHERE accreditation_id = :id");
    $stmt->execute(['id' => $selected_id]);
    $current_acc = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch categories
    $stmt = $db->prepare("SELECT * FROM accreditation_categories WHERE accreditation_id = :acc_id ORDER BY name ASC");
    $stmt->execute(['acc_id' => $selected_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group categories by parent
    $categories_by_parent = [];
    foreach ($categories as $cat) {
        $parent_id = $cat['parent_category_id'] ?? 0;
        $categories_by_parent[$parent_id][] = $cat;
    }

    // Fetch all requirements for this accreditation
    $stmt = $db->prepare("
        SELECT r.* 
        FROM accreditation_requirement r
        JOIN accreditation_categories c ON r.category_id = c.category_id
        WHERE c.accreditation_id = :acc_id
    ");
    $stmt->execute(['acc_id' => $selected_id]);
    $all_requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group requirements by category_id
    $requirements_by_category = [];
    foreach ($all_requirements as $req) {
        $requirements_by_category[$req['category_id']][] = $req;
    }

    // Fetch submissions for this accreditation
    $stmt = $db->prepare("
        SELECT s.*, u.fname, u.lname, d.name as division_name, o.name as office_name,
               m.fname as marker_fname, m.lname as marker_lname
        FROM accreditation_requirement_submissions s
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN users m ON s.marked_by = m.user_id
        LEFT JOIN divisions d ON s.division_id = d.division_id
        LEFT JOIN divisions_offices o ON s.office_id = o.office_id
        JOIN accreditation_requirement r ON s.requirement_id = r.requirement_id
        JOIN accreditation_categories c ON r.category_id = c.category_id
        WHERE c.accreditation_id = :acc_id
    ");
    $stmt->execute(['acc_id' => $selected_id]);
    $submissions = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $submissions[$row['requirement_id']] = $row;
    }

    // Fetch document bridges/proofs of compliance for this accreditation
    $stmt = $db->prepare("
        SELECT b.*, doc.doc_code, doc.category as doc_category, doc.purpose as doc_purpose,
               s.status as sub_status, s.google_drive_link as sub_link, s.file_path as sub_path,
               s.google_drive_file_id, s.remarks as sub_remarks, s.user_id as sub_user_id,
               s.updated_at as sub_created_at, d.name as sub_division_name, o.name as sub_office_name,
               u.fname as uploader_fname, u.lname as uploader_lname,
               m.fname as reviewer_fname, m.lname as reviewer_lname
        FROM document_bridge b
        LEFT JOIN documents doc ON b.document_id = doc.doc_id
        LEFT JOIN accreditation_requirement_submissions s ON b.submission_id = s.submission_id
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN users m ON s.marked_by = m.user_id
        LEFT JOIN divisions d ON s.division_id = d.division_id
        LEFT JOIN divisions_offices o ON s.office_id = o.office_id
        JOIN accreditation_requirement r ON b.requirement_id = r.requirement_id
        JOIN accreditation_categories c ON r.category_id = c.category_id
        WHERE c.accreditation_id = :acc_id
    ");
    $stmt->execute(['acc_id' => $selected_id]);
    $document_bridges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group bridges by requirement_id
    $bridges_by_requirement = [];
    foreach ($document_bridges as $bridge) {
        $bridges_by_requirement[$bridge['requirement_id']][] = $bridge;
    }

    // Fetch institutional documents for selection
    $stmt = $db->query("SELECT doc_id, doc_code, category, purpose FROM documents ORDER BY doc_code ASC");
    $all_inst_docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Robust QAO check
    $stmt = $db->prepare("
        SELECT o.name 
        FROM users u 
        LEFT JOIN divisions_offices o ON u.office_id = o.office_id 
        WHERE u.user_id = :id
    ");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user_office_name = $stmt->fetchColumn();

    $is_qao = (stripos($user_office_name ?? '', 'Quality Assurance') !== false) || (($_SESSION['user_office_id'] ?? 0) == 4);

    // Helper to calculate requirement compliance status and percentage progress
    function getRequirementProgress($req_id, $bridges_by_req, $submission_legacy) {
        if (!isset($bridges_by_req[$req_id]) || empty($bridges_by_req[$req_id])) {
            // Legacy mode: check if the requirement has an approved submission
            if ($submission_legacy && $submission_legacy['status'] === 'Approved') {
                return ['total' => 1, 'approved' => 1, 'percentage' => 100, 'status' => 'Approved'];
            }
            if ($submission_legacy) {
                return ['total' => 1, 'approved' => 0, 'percentage' => 0, 'status' => $submission_legacy['status']];
            }
            return ['total' => 1, 'approved' => 0, 'percentage' => 0, 'status' => 'Missing'];
        }

        $total = count($bridges_by_req[$req_id]);
        $approved = 0;
        $has_pending = false;
        $has_disapproved = false;

        foreach ($bridges_by_req[$req_id] as $b) {
            if ($b['document_id'] !== null) {
                // Linked institutional documents do not count as approved/completed progress
            } elseif ($b['submission_id'] !== null) {
                if ($b['sub_status'] === 'Approved') {
                    $approved++;
                } elseif ($b['sub_status'] === 'Pending') {
                    $has_pending = true;
                } elseif ($b['sub_status'] === 'Disapproved' || $b['sub_status'] === 'Returned') {
                    $has_disapproved = true;
                }
            }
        }

        $percentage = round(($approved / $total) * 100);
        
        $status = 'Missing';
        if ($approved === $total) {
            $status = 'Approved';
        } elseif ($has_pending) {
            $status = 'Pending';
        } elseif ($has_disapproved) {
            $status = 'Returned';
        }

        return [
            'total' => $total,
            'approved' => $approved,
            'percentage' => $percentage,
            'status' => $status
        ];
    }

    // Helper to calculate total and approved requirements (including nested)
    $category_stats = [];
    function calculateStats($parent_id, $categories_by_parent, $requirements_by_category, $bridges_by_requirement, $submissions, &$category_stats) {
        if (!isset($categories_by_parent[$parent_id])) {
            return ['total' => 0, 'approved' => 0];
        }

        $sum_total = 0;
        $sum_approved = 0;

        foreach ($categories_by_parent[$parent_id] as $cat) {
            $cat_id = $cat['category_id'];
            
            $direct_total = 0;
            $direct_approved = 0;
            if (isset($requirements_by_category[$cat_id])) {
                foreach ($requirements_by_category[$cat_id] as $req) {
                    $direct_total++;
                    $progress = getRequirementProgress($req['requirement_id'], $bridges_by_requirement, $submissions[$req['requirement_id']] ?? null);
                    if ($progress['status'] === 'Approved') {
                        $direct_approved++;
                    }
                }
            }

            $sub_stats = calculateStats($cat_id, $categories_by_parent, $requirements_by_category, $bridges_by_requirement, $submissions, $category_stats);

            $total = $direct_total + $sub_stats['total'];
            $approved = $direct_approved + $sub_stats['approved'];

            $category_stats[$cat_id] = ['total' => $total, 'approved' => $approved];

            $sum_total += $total;
            $sum_approved += $approved;
        }

        return ['total' => $sum_total, 'approved' => $sum_approved];
    }
    $overall_stats = calculateStats(0, $categories_by_parent, $requirements_by_category, $bridges_by_requirement, $submissions, $category_stats);
}

function renderRequirements($parent_id, $reqs_by_parent, $submissions, $is_qao, $cat_id, $cat_name, $depth = 0)
{
    global $bridges_by_requirement;
    if (!isset($reqs_by_parent[$parent_id])) return;

    foreach ($reqs_by_parent[$parent_id] as $req) {
        $req_id = $req['requirement_id'];
        $sub = $submissions[$req_id] ?? null;
        
        $progress = getRequirementProgress($req_id, $bridges_by_requirement, $sub);
        $is_approved = ($progress['status'] === 'Approved');
        
        $cb_color = '#e2e8f0';
        if ($progress['status'] === 'Approved') {
            $cb_color = '#22c55e';
        } elseif ($progress['status'] === 'Pending') {
            $cb_color = '#3b82f6';
        } elseif ($progress['status'] === 'Returned') {
            $cb_color = '#ef4444';
        }
        ?>
        <div style="margin-left: <?= $depth * 1.5 ?>rem; margin-bottom: 0.3rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 0.85rem; padding: 2px 0;">
                <div style="display: flex; align-items: flex-start; gap: 8px;">
                    <?php if ($depth > 0): ?>
                        <span style="color: #cbd5e1; font-family: monospace; font-size: 1.2rem; line-height: 1; margin-top: -2px;">└</span>
                    <?php endif; ?>

                    <div
                        style="width: 18px; height: 18px; border: 2px solid <?= $cb_color ?>; border-radius: 4px; display: flex; align-items: center; justify-content: center; background: <?= $is_approved ? $cb_color : 'transparent' ?>; margin-top: 2px; flex-shrink: 0;">
                        <?php if ($progress['approved'] > 0): ?>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                stroke="<?= $is_approved ? 'white' : $cb_color ?>" stroke-width="4" stroke-linecap="round"
                                stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div onclick="handleRequirementClick(<?= $req_id ?>, '<?= addslashes($req['name']) ?>', '<?= addslashes($req['codename'] ?? '') ?>', <?= htmlspecialchars(json_encode($sub)) ?>, <?= htmlspecialchars(json_encode($bridges_by_requirement[$req_id] ?? [])) ?>)"
                        style="cursor: pointer; display: flex; flex-direction: column;" onmouseover="this.style.textDecoration='underline'"
                        onmouseout="this.style.textDecoration='none'">
                        <div>
                            <?php if (!empty($req['codename'])): ?>
                                <span
                                    style="font-weight: 700; color: var(--accent-blue); <?= $is_approved ? 'text-decoration: line-through; opacity: 0.7;' : '' ?>"><?= htmlspecialchars($req['codename']) ?>:</span>
                            <?php endif; ?>
                            <span
                                style="<?= $is_approved ? 'opacity: 0.7;' : '' ?>"><?= htmlspecialchars($req['name']) ?></span>
                            <?php if (isset($bridges_by_requirement[$req_id]) && count($bridges_by_requirement[$req_id]) > 0): ?>
                                <span style="font-size: 0.75rem; color: var(--text-secondary); margin-left: 5px;">(<?= $progress['approved'] ?>/<?= $progress['total'] ?> proofs)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($is_qao): ?>
                    <div style="position: relative;">
                        <button onclick="toggleActionMenu(event, 'req_menu_<?= $req_id ?>')"
                            style="background: transparent; border: none; padding: 4px; cursor: pointer; color: var(--text-secondary); border-radius: 4px;"
                            onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1"></circle>
                                <circle cx="12" cy="5" r="1"></circle>
                                <circle cx="12" cy="19" r="1"></circle>
                            </svg>
                        </button>
                        <div id="req_menu_<?= $req_id ?>" class="local-dropdown"
                            style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 140px; z-index: 100; overflow: hidden;">
                            <button
                                onclick="openEditModal('requirement', '<?= $req_id ?>', '<?= addslashes($req['name']) ?>', '<?= addslashes($req['codename']) ?>', '<?= $cat_id ?>', '<?= addslashes($cat_name) ?>')"
                                style="width: 100%; padding: 0.5rem 0.7rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.75rem; display: flex; align-items: center; gap: 6px;"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                Edit
                            </button>
                            <button
                                id="manage_proofs_btn_<?= $req_id ?>"
                                onclick="openComplianceTracker(<?= $req_id ?>, '<?= addslashes($req['name']) ?>', '<?= addslashes($req['codename'] ?? '') ?>', <?= htmlspecialchars(json_encode($bridges_by_requirement[$req_id] ?? [])) ?>)"
                                style="width: 100%; padding: 0.5rem 0.7rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.75rem; display: flex; align-items: center; gap: 6px;"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                                Manage Proofs
                            </button>
                            <button
                                onclick="deleteItem('requirement', '<?= $req_id ?>', '<?= addslashes($req['name']) ?>')"
                                style="width: 100%; padding: 0.5rem 0.7rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.75rem; display: flex; align-items: center; gap: 6px; color: #ef4444;"
                                onmouseover="this.style.background='#fef2f2'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path
                                        d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                    </path>
                                </svg>
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Recursive call for children -->
            <?php renderRequirements($req_id, $reqs_by_parent, $submissions, $is_qao, $cat_id, $cat_name, $depth + 1); ?>
        </div>
        <?php
    }
}

function renderCategories($parent_id, $categories_by_parent, $db, $category_stats)
{
    global $submissions, $is_qao;
    if (!isset($categories_by_parent[$parent_id]))
        return;

    foreach ($categories_by_parent[$parent_id] as $cat) {
        $cat_id = $cat['category_id'];
        ?>
        <div
            style="border: 1px solid var(--border-color); border-radius: 4px; margin-bottom: 0.4rem; margin-left: <?= $parent_id == 0 ? '0' : '1rem' ?>; position: relative;">
            <!-- Category Header -->
            <div onclick="toggleCategory(this)" data-id="cat-header-<?= $cat_id ?>"
                style="background: #f8fafc; padding: 0.5rem 0.8rem; font-weight: 700; border-radius: 4px 4px 0 0; border-bottom: 1px solid var(--border-color); color: var(--accent-blue); display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.2s;"
                onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">

                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                        style="transition: transform 0.3s; transform: rotate(0deg);">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                    <span
                        style="font-size: <?= $parent_id == 0 ? '0.95rem' : '0.85rem' ?>;"><?= htmlspecialchars($cat['name']) ?></span>
                </div>

                <div style="display: flex; align-items: center; gap: 15px;">
                    <?php
                    $stats = $category_stats[$cat_id] ?? ['total' => 0, 'approved' => 0];
                    $t = $stats['total'] ?: 1;
                    $p = round(($stats['approved'] / $t) * 100);
                    ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div
                            style="width: 80px; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; border: 1px solid #f1f5f9;">
                            <div
                                style="width: <?= $p ?>%; height: 100%; background: var(--accent-blue); transition: width 0.5s;">
                            </div>
                        </div>
                        <span style="font-weight: 700; font-size: 0.75rem; color: var(--accent-blue);"><?= $p ?>%</span>
                        <span
                            style="font-weight: normal; font-size: 0.75rem; color: var(--text-secondary);">(<?= $stats['approved'] ?>/<?= $stats['total'] ?>)</span>
                    </div>

                    <?php if ($is_qao): ?>
                        <!-- Category Triple Dot Menu -->
                        <div style="position: relative;" onclick="event.stopPropagation()">
                            <button onclick="toggleLocalMenu(this)"
                                style="background: transparent; border: none; padding: 4px; cursor: pointer; color: var(--text-secondary); border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="1"></circle>
                                    <circle cx="12" cy="5" r="1"></circle>
                                    <circle cx="12" cy="19" r="1"></circle>
                                </svg>
                            </button>
                            <div class="local-dropdown"
                                style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 160px; z-index: 100; overflow: hidden;">
                                <button
                                    onclick="openModalWithContext('addCategoryModal', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>', 'cat')"
                                    style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;"
                                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="12" y1="5" x2="12" y2="19"></line>
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                    </svg>
                                    Add Sub-category
                                </button>
                                <button
                                    onclick="openModalWithContext('addRequirementModal', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>', 'req')"
                                    style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;"
                                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="12" y1="18" x2="12" y2="12"></line>
                                        <line x1="9" y1="15" x2="15" y2="15"></line>
                                    </svg>
                                    Add Requirement
                                </button>
                                <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 0;">
                                <button
                                    onclick="openEditModal('category', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>', '', '<?= $parent_id ?>', '<?= addslashes($categories_by_parent[$parent_id][0]['name'] ?? 'Top Level') ?>')"
                                    style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;"
                                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                    Edit Category
                                </button>
                                <button onclick="deleteItem('category', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>')"
                                    style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; color: #ef4444; display: flex; align-items: center; gap: 8px;"
                                    onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='transparent'">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                        </path>
                                    </svg>
                                    Delete
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Category Content -->
            <div class="category-content" data-id="cat-content-<?= $cat_id ?>" style="display: none; padding: 0.6rem 0.8rem;">
                <?php
                $stmt = $db->prepare("SELECT * FROM accreditation_requirement WHERE category_id = :cat_id");
                $stmt->execute(['cat_id' => $cat['category_id']]);
                $all_reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($all_reqs)) {
                    // Group requirements by parent for this category
                    $reqs_by_parent = [];
                    foreach ($all_reqs as $r) {
                        $pid = $r['parent_requirement_id'] ?? 0;
                        $reqs_by_parent[$pid][] = $r;
                    }
                    ?>
                    <div style="display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.5rem;">
                        <?php renderRequirements(0, $reqs_by_parent, $submissions, $is_qao, $cat_id, $cat['name']); ?>
                    </div>
                    <?php
                }
                renderCategories($cat['category_id'], $categories_by_parent, $db, $category_stats);
                ?>
            </div>
        </div>
        <?php
    }
}
?>

<main class="hero" style="display: block; min-height: calc(100vh - 200px); align-items: flex-start; padding: 2rem 5%;">
    <div style="display: flex; gap: 2rem; width: 100%; max-width: 1200px; margin: 0 auto;">

        <!-- Sidebar: Accreditations List -->
        <aside id="accSidebar"
            style="width: 300px; background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); height: fit-content; transition: all 0.3s ease;">
            <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 1.2rem; color: var(--accent-blue); margin: 0;">Accreditations</h2>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <button onclick="document.getElementById('importAccreditationModal').style.display='flex'"
                            style="background: #10b981; color: white; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: transform 0.2s;"
                            onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"
                            title="Import from Excel">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                <polyline points="7 10 12 15 17 10" />
                                <line x1="12" y1="15" x2="12" y2="3" />
                            </svg>
                        </button>
                        <button onclick="document.getElementById('addAccreditationModal').style.display='flex'"
                            style="background: var(--accent-blue); color: white; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: transform 0.2s;"
                            onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"
                            title="Add New Accreditation">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                        </button>
                    </div>
                </div>
                <!-- Search Bar -->
                <div style="position: relative;">
                    <input type="text" id="accSearch" placeholder="Search accreditations..."
                        style="width: 100%; padding: 0.6rem 0.8rem 0.6rem 2.2rem; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem; outline: none;"
                        onkeyup="filterAccreditations()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        style="position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%);">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </div>
            </div>
            <div id="accList" style="display: flex; flex-direction: column; gap: 0.5rem;">
                <?php if (empty($accreditations)): ?>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">No accreditations found.</p>
                <?php else: ?>
                    <?php foreach ($accreditations as $acc): ?>
                        <a href="feed.php?action=accreditation&accreditation_id=<?= $acc['accreditation_id'] ?>"
                            class="acc-item" data-code="<?= strtolower($acc['code']) ?>"
                            data-name="<?= strtolower($acc['name']) ?>"
                            style="padding: 0.8rem; border-radius: 4px; text-decoration: none; color: <?= $selected_id == $acc['accreditation_id'] ? 'white' : 'var(--text-secondary)' ?>; background: <?= $selected_id == $acc['accreditation_id'] ? 'var(--accent-blue)' : 'transparent' ?>; border: 1px solid <?= $selected_id == $acc['accreditation_id'] ? 'var(--accent-blue)' : 'var(--border-color)' ?>; transition: all 0.3s;"
                            onmouseover="if(<?= $selected_id ?> != <?= $acc['accreditation_id'] ?>) this.style.backgroundColor='#f8fafc'"
                            onmouseout="if(<?= $selected_id ?> != <?= $acc['accreditation_id'] ?>) this.style.backgroundColor='transparent'">
                            <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($acc['code']) ?></div>
                            <div style="font-size: 0.8rem; opacity: 0.8;"><?= htmlspecialchars($acc['name']) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content: Selected Accreditation Details -->
        <div id="accMainContent"
            style="flex: 1; background: white; padding: 2.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: all 0.3s ease; position: relative;">
            <?php if ($current_acc): ?>
                <!-- Top Right Action Buttons -->
                <div
                    style="position: absolute; top: 1.5rem; right: 1.5rem; display: flex; gap: 8px; align-items: center; z-index: 10;">
                    <button onclick="toggleMaximize()" id="maximizeBtn"
                        style="background: transparent; border: none; padding: 5px; cursor: pointer; color: var(--text-secondary); border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: background 0.2s;"
                        onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'"
                        title="Toggle Maximize">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                        </svg>
                    </button>

                    <div style="position: relative;">
                        <?php if ($is_qao): ?>
                            <button onclick="toggleActionMenu(event, 'accActionMenu')"
                                style="background: white; border: 1px solid var(--border-color); padding: 8px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; color: var(--text-secondary);"
                                onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="1"></circle>
                                    <circle cx="12" cy="5" r="1"></circle>
                                    <circle cx="12" cy="19" r="1"></circle>
                                </svg>
                            </button>
                        <?php endif; ?>

                        <div id="accActionMenu"
                            style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); width: 180px; z-index: 100; overflow: hidden; margin-top: 5px;">
                            <button onclick="openModal('changeStatusModal')"
                                style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-primary);"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"></path>
                                    <polygon points="18 2 22 6 12 16 8 16 8 12 18 2"></polygon>
                                </svg>
                                Change Status
                            </button>
                            <button onclick="openModal('addCategoryModal')"
                                style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-primary);"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                                Add Category
                            </button>
                            <button onclick="openModal('addRequirementModal')"
                                style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-primary);"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="12" y1="18" x2="12" y2="12"></line>
                                    <line x1="9" y1="15" x2="15" y2="15"></line>
                                </svg>
                                Add Requirement
                            </button>
                            <button onclick="openEditAccModal()"
                                style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-primary);"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                Edit Accreditation
                            </button>
                            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 0;">
                            <button
                                onclick="deleteItem('accreditation', '<?= $selected_id ?>', '<?= addslashes($current_acc['name']) ?>')"
                                style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #ef4444;"
                                onmouseover="this.style.background='#fef2f2'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path
                                        d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                    </path>
                                </svg>
                                Delete Accreditation
                            </button>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 2rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 1.5rem;">
                    <div style="margin-bottom: 1rem; padding-right: 60px;">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 0.5rem;">
                            <h1 style="color: var(--accent-blue); margin: 0;"><?= htmlspecialchars($current_acc['name']) ?>
                            </h1>
                            <?php
                            $status_color = '#94a3b8'; // Default Gray
                            if ($current_acc['status'] === 'In Progress')
                                $status_color = '#3b82f6'; // Blue
                            if ($current_acc['status'] === 'Completed')
                                $status_color = '#22c55e'; // Green
                            ?>
                            <span class="user-badge"
                                style="background: <?= $status_color ?>; font-size: 0.75rem; padding: 4px 12px; color: white;">
                                <?= htmlspecialchars($current_acc['status']) ?>
                            </span>
                        </div>
                        <p style="color: var(--text-secondary); margin: 0;">
                            <?= htmlspecialchars($current_acc['description']) ?>
                        </p>
                    </div>

                    <div style="display: flex; gap: 2rem; align-items: center;">
                        <div style="flex: 1;">
                            <?php
                            $total = $overall_stats['total'] ?: 1;
                            $percent = round(($overall_stats['approved'] / $total) * 100);
                            ?>
                            <div
                                style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 0.5rem;">
                                <span style="font-weight: 600;">Overall Progress</span>
                                <span style="font-weight: 700; color: var(--accent-blue);"><?= $percent ?>%</span>
                            </div>
                            <div
                                style="height: 10px; background: #e2e8f0; border-radius: 6px; overflow: hidden; border: 1px solid #f1f5f9;">
                                <div
                                    style="width: <?= $percent ?>%; height: 100%; background: var(--accent-blue); transition: width 1s ease-in-out; box-shadow: 0 0 10px rgba(59, 130, 246, 0.3);">
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($current_acc['deadline'])): ?>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">
                                <strong>Deadline:</strong> <?= date('M d, Y', strtotime($current_acc['deadline'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Categories and Requirements -->
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php if (empty($categories)): ?>
                        <p style="text-align: center; color: var(--text-secondary); padding: 3rem;">No categories defined for
                            this accreditation.</p>
                    <?php else: ?>
                        <?php renderCategories(0, $categories_by_parent, $db, $category_stats); ?>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div style="text-align: center; padding: 5rem 0;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--border-color)"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 1.5rem;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <h2 style="color: var(--text-secondary);">Select an accreditation to view its details</h2>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<!-- Add Accreditation Modal -->
<div id="addAccreditationModal" class="modal-overlay"
    style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Add New Accreditation</h2>
            <button onclick="document.getElementById('addAccreditationModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=add" method="POST">
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Code *</label>
                <input type="text" name="code" required placeholder="e.g. ISO 9001:2015" class="form-control">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Name *</label>
                <input type="text" name="name" required placeholder="e.g. Quality Management System"
                    class="form-control">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Description</label>
                <textarea name="description" rows="3" placeholder="Brief overview..." class="form-control"
                    style="resize: vertical;"></textarea>
            </div>

            <div id="deadline_container" style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Deadline *</label>
                <input type="date" id="acc_deadline" name="deadline" class="form-control">
            </div>

            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Initial Status *</label>
                <select id="acc_status" name="status" required class="form-control"
                    onchange="toggleDeadline(this.value)">
                    <option value="In Progress">In Progress</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Create
                Accreditation</button>
        </form>
    </div>
</div>

<!-- Edit Accreditation Modal -->
<div id="editAccreditationModal" class="modal-overlay"
    style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Edit Accreditation</h2>
            <button onclick="document.getElementById('editAccreditationModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=edit" method="POST">
            <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">

            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Code *</label>
                <input type="text" name="code" value="<?= htmlspecialchars($current_acc['code'] ?? '') ?>" required
                    class="form-control">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($current_acc['name'] ?? '') ?>" required
                    class="form-control">
            </div>

            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Description</label>
                <textarea name="description" rows="3" class="form-control"
                    style="resize: vertical;"><?= htmlspecialchars($current_acc['description'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Save Changes</button>
        </form>
    </div>
</div>

<!-- Add Category Modal -->
<div id="addCategoryModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 450px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Add Category</h2>
            <button onclick="document.getElementById('addCategoryModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=add_category" method="POST">
            <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Parent Category
                    (Optional)</label>
                <div id="cat_parentSelectorsContainer" style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <select class="form-control parent-cat-select"
                        onchange="handleCascadingSelect(this, 'cat_parentSelectorsContainer', 'cat_final_parent_id')">
                        <option value="">None (Top Level)</option>
                        <?php foreach ($categories_by_parent[0] ?? [] as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="parent_category_id" id="cat_final_parent_id" value="">
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Select the parent
                    category if this is a sub-category.</p>
            </div>

            <div id="cat_items_container">
                <div class="item-row" style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category Name *</label>
                    <input type="text" name="name[]" required placeholder="e.g. Governance, Facilities..."
                        class="form-control">
                </div>
            </div>

            <button type="button" onclick="addItemRow('cat_items_container', 'category')" class="btn btn-secondary"
                style="width: 100%; margin-bottom: 1rem; padding: 0.6rem;">+ Add Another Category</button>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Add Categories</button>
        </form>
    </div>
</div>

<!-- Add Requirement Modal -->
<div id="addRequirementModal" class="modal-overlay"
    style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 450px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Add Requirement</h2>
            <button onclick="document.getElementById('addRequirementModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=add_requirement" method="POST">
            <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category *</label>
                <div id="req_parentSelectorsContainer" style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <select class="form-control parent-cat-select"
                        onchange="handleCascadingSelect(this, 'req_parentSelectorsContainer', 'req_final_parent_id')">
                        <option value="">Select Category...</option>
                        <?php foreach ($categories_by_parent[0] ?? [] as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="category_id" id="req_final_parent_id" required value="">
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Select which category
                    these requirements belong to.</p>
            </div>

            <div id="req_items_container">
                <div class="item-row"
                    style="display: flex; gap: 1rem; margin-bottom: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px dashed var(--border-color);">
                    <div style="flex: 1;">
                        <label
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Codename</label>
                        <input type="text" name="codename[]" placeholder="1.1" class="form-control">
                    </div>
                    <div style="flex: 2;">
                        <label
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Requirement
                            Name *</label>
                        <input type="text" name="name[]" required placeholder="Description..." class="form-control">
                    </div>
                </div>
            </div>

            <button type="button" onclick="addItemRow('req_items_container', 'requirement')" class="btn btn-secondary"
                style="width: 100%; margin-bottom: 1rem; padding: 0.6rem;">+ Add Another Requirement</button>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Add Requirements</button>
        </form>
    </div>
</div>

<!-- Change Status Modal -->
<div id="changeStatusModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 400px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Change Status</h2>
            <button onclick="document.getElementById('changeStatusModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=update_status" method="POST">
            <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Status *</label>
                <select name="status" required class="form-control" onchange="toggleStatusDeadline(this.value)">
                    <option value="In Progress" <?= $current_acc['status'] === 'In Progress' ? 'selected' : '' ?>>In
                        Progress</option>
                    <option value="Inactive" <?= $current_acc['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive
                    </option>
                    <option value="Completed" <?= $current_acc['status'] === 'Completed' ? 'selected' : '' ?>>Completed
                    </option>
                </select>
            </div>

            <div id="status_deadline_container"
                style="margin-bottom: 2rem; display: <?= $current_acc['status'] === 'In Progress' ? 'block' : 'none' ?>;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">New Deadline</label>
                <input type="date" name="deadline" value="<?= $current_acc['deadline'] ?>" class="form-control">
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Set a target completion
                    date.</p>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Update Status</button>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div id="editCategoryModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 450px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Edit Category</h2>
            <button onclick="document.getElementById('editCategoryModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=edit_category" method="POST">
            <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">
            <input type="hidden" name="category_id" id="edit_cat_id">

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Parent Category
                    (Optional)</label>
                <div id="edit_cat_parentSelectorsContainer" style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <select class="form-control parent-cat-select"
                        onchange="handleCascadingSelect(this, 'edit_cat_parentSelectorsContainer', 'edit_cat_final_parent_id')">
                        <option value="">None (Top Level)</option>
                        <?php foreach ($categories_by_parent[0] ?? [] as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="parent_category_id" id="edit_cat_final_parent_id" value="">
            </div>

            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category Name *</label>
                <input type="text" name="name" id="edit_cat_name" required class="form-control">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Save Changes</button>
        </form>
    </div>
</div>

<!-- Edit Requirement Modal -->
<div id="editRequirementModal" class="modal-overlay"
    style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 450px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Edit Requirement</h2>
            <button onclick="document.getElementById('editRequirementModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=edit_requirement" method="POST">
            <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">
            <input type="hidden" name="requirement_id" id="edit_req_id">

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category *</label>
                <div id="edit_req_parentSelectorsContainer" style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <select class="form-control parent-cat-select"
                        onchange="handleCascadingSelect(this, 'edit_req_parentSelectorsContainer', 'edit_req_final_parent_id')">
                        <option value="">Select Category...</option>
                        <?php foreach ($categories_by_parent[0] ?? [] as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="category_id" id="edit_req_final_parent_id" required value="">
            </div>

            <div
                style="margin-bottom: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px dashed var(--border-color);">
                <div style="margin-bottom: 1rem;">
                    <label
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Codename</label>
                    <input type="text" name="codename" id="edit_req_codename" placeholder="1.1" class="form-control">
                </div>
                <div>
                    <label
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Requirement
                        Name *</label>
                    <input type="text" name="name" id="edit_req_name" required placeholder="Description..."
                        class="form-control">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Save Changes</button>
        </form>
    </div>
</div>

<!-- Requirement Upload Modal -->
<div id="uploadRequirementModal" class="modal-overlay"
    style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 id="upload_req_title" style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Upload File</h2>
            <button onclick="document.getElementById('uploadRequirementModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form id="uploadForm" onsubmit="handleFileUpload(event)">
            <input type="hidden" name="requirement_id" id="upload_req_id">
            <input type="hidden" name="bridge_id" id="upload_bridge_id">

            <!-- Step 1: Selection (only shown if proofs exist) -->
            <div id="upload_step_1" style="display: none; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
                <div id="upload_proof_container">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: var(--text-primary);">Select Proof Requirement</label>
                    <select id="upload_proof_select" class="form-control" style="width: 100%; padding: 0.6rem 0.8rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; background: white;">
                        <!-- populated dynamically -->
                    </select>
                </div>
                <button type="button" id="upload_next_btn" class="btn btn-primary" style="width: 100%; padding: 1rem; display: flex; align-items: center; justify-content: center;" onclick="goToUploadStep2()">
                    Start Uploading
                </button>
            </div>

            <!-- Step 2: Drag & Drop (shown instantly if no proofs/preselected) -->
            <div id="upload_step_2" style="display: none; flex-direction: column; gap: 1rem;">
                <div id="dropZone"
                    style="border: 2px dashed var(--border-color); border-radius: 8px; padding: 2rem; text-align: center; cursor: pointer; transition: all 0.3s;"
                    onmouseover="this.style.borderColor='var(--accent-blue)'; this.style.background='#f8fafc'"
                    onmouseout="this.style.borderColor='var(--border-color)'; this.style.background='transparent'"
                    onclick="document.getElementById('fileInput').click()">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 1rem;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    <p style="margin: 0; font-weight: 500;">Click to select or drag and drop</p>
                    <p style="margin: 0.5rem 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">PDF files only
                        (Multiple allowed)</p>
                    <input type="file" id="fileInput" name="files[]" multiple accept="application/pdf"
                        style="display: none;" onchange="updateFileInfo(this)">
                </div>

                <div id="fileInfo"
                    style="display: none; padding: 0.8rem; background: #eff6ff; border-radius: 6px; border: 1px solid #bfdbfe; font-size: 0.9rem; color: #1e40af;">
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <div
                            style="display: flex; align-items: center; justify-content: space-between; font-weight: 600; margin-bottom: 5px; border-bottom: 1px solid #bfdbfe; padding-bottom: 5px;">
                            <span>Selected Files:</span>
                            <button type="button" onclick="clearFile()"
                                style="background: transparent; border: none; color: #ef4444; cursor: pointer; font-weight: bold;">&times;</button>
                        </div>
                        <div id="fileList"></div>
                    </div>
                </div>

                <!-- Back button to change choice -->
                <button type="button" id="upload_back_btn" class="btn btn-secondary" style="width: 100%; padding: 0.6rem; display: none; align-items: center; justify-content: center; font-size: 0.85rem;" onclick="goToUploadStep1()">
                    ← Select Different Proof
                </button>

                <button type="submit" id="uploadBtn" class="btn btn-primary"
                    style="width: 100%; padding: 1rem; display: flex; align-items: center; justify-content: center; gap: 10px;">
                    <span>Start Upload</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Review Submission Modal -->
<div id="reviewSubmissionModal" class="modal-overlay"
    style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content"
        style="max-width: 900px; width: 90%; height: 80vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 id="review_title" style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Review Submission
            </h2>
            <button onclick="document.getElementById('reviewSubmissionModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <div style="display: flex; gap: 2rem; flex: 1; overflow: hidden;">
            <div style="flex: 2; background: #f1f5f9; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column;">
                <div style="padding: 10px; background: #e2e8f0; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem;">
                    <span>Document Preview</span>
                    <a id="rev_drive_link" href="#" target="_blank" class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem; text-decoration: none;">
                        Open in Google Drive ↗
                    </a>
                </div>
                <iframe id="preview_frame" src="" style="width: 100%; flex: 1; border: none;"></iframe>
            </div>

            <!-- Details and Actions -->
            <div
                style="flex: 1; display: flex; flex-direction: column; gap: 1.5rem; overflow-y: auto; padding-right: 10px;">
                <div
                    style="background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                    <h3 style="font-size: 0.9rem; margin: 0 0 1rem 0; color: var(--accent-blue);">Submission Details
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 10px; font-size: 0.85rem;">
                        <div><strong>Uploaded by:</strong> <span id="rev_user"></span></div>
                        <div><strong>Division:</strong> <span id="rev_division"></span></div>
                        <div><strong>Office:</strong> <span id="rev_office"></span></div>
                        <div><strong>Date:</strong> <span id="rev_date"></span></div>
                        <div><strong>Status:</strong> <span id="rev_status_badge" class="user-badge"
                                style="padding: 2px 8px; font-size: 0.7rem;"></span></div>
                        <div id="rev_marker_container"
                            style="display: none; margin-top: 5px; padding-top: 5px; border-top: 1px dashed #e2e8f0;">
                            <strong>Marked by:</strong> <span id="rev_marker"></span>
                        </div>
                    </div>
                </div>

                <div id="remarks_container" style="display: none;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem;">Review
                        Remarks</label>
                    <div id="remarks_display"
                        style="display: none; padding: 10px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1rem;">
                    </div>
                    <textarea id="review_remarks" rows="5" class="form-control"
                        placeholder="Add feedback or reasons for disapproval..."
                        style="resize: none; font-size: 0.9rem;"></textarea>
                </div>

                <div style="margin-top: auto; display: flex; flex-direction: column; gap: 10px;">
                    <?php if ($is_qao): ?>
                        <div id="review_actions" style="display: flex; gap: 10px;">
                            <button onclick="submitReview('Approved')" class="btn btn-success"
                                style="flex: 1; padding: 0.8rem; background: #22c55e;">Approve</button>
                            <button onclick="submitReview('Disapproved')" class="btn btn-danger"
                                style="flex: 1; padding: 0.8rem; background: #ef4444;">Disapprove</button>
                        </div>
                    <?php endif; ?>

                    <div id="uploader_actions" style="display: none; gap: 10px;">
                        <button onclick="reopenUpload()" class="btn btn-secondary"
                            style="flex: 2; padding: 0.8rem;">Update / Replace Files</button>
                        <button onclick="removeSubmission()" class="btn btn-danger"
                            style="flex: 1; padding: 0.8rem; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;">Remove</button>
                    </div>
                    <button id="add_another_doc_btn" onclick="openUploadModalForCurrent()" class="btn btn-secondary" style="display: none; margin-top: 10px; width: 100%; padding: 0.6rem; font-size: 0.85rem; border: 1px dashed var(--accent-blue); color: var(--accent-blue); background: #f8fafc;">
                        + Add Another Document / Proof
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Compliance Tracker Modal -->
<div id="complianceTrackerModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 9999;">
    <div class="modal-content" style="max-width: 1100px; width: 95%; max-height: 85vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <span id="comp_req_codename" style="font-weight: 700; color: var(--accent-blue); font-size: 0.9rem;"></span>
                <h2 id="comp_req_title" style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Compliance Checklist</h2>
            </div>
            <button onclick="closeComplianceTrackerModal()"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <div style="flex: 1; overflow-y: auto; padding-right: 5px;">
            <!-- Progress Section -->
            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                <div style="font-weight: 600; font-size: 0.9rem; color: var(--accent-blue);">Overall Requirement Compliance</div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 150px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                        <div id="comp_progress_bar" style="width: 0%; height: 100%; background: #22c55e; transition: width 0.3s;"></div>
                    </div>
                    <span id="comp_progress_text" style="font-weight: 700; font-size: 0.85rem; color: #22c55e;">0%</span>
                </div>
            </div>

            <!-- QAO: Add Proof Form -->
            <?php if ($is_qao): ?>
            <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px dashed var(--border-color); margin-bottom: 1.5rem;">
                <h3 style="font-size: 0.85rem; margin: 0 0 0.8rem 0; color: var(--accent-blue);">Add Required Proof of Compliance</h3>
                <form action="../api/accreditation.php?action=add_proof" method="POST" id="addProofForm" style="display: flex; flex-direction: column; gap: 10px;">
                    <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">
                    <input type="hidden" name="requirement_id" id="add_proof_req_id">
                    
                    <div id="add_proof_fields_container" style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" name="proof_names[]" required placeholder="e.g. Syllabus, Class Schedule, OBE Curriculum Map" class="form-control" style="flex: 1; padding: 0.5rem 0.8rem; font-size: 0.85rem;">
                            <div style="width: 28px;"></div>
                        </div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px;">
                        <button type="button" onclick="addProofField()" class="btn btn-secondary" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: transparent; border: 1px solid var(--accent-blue); color: var(--accent-blue);">
                            + Add Another Proof
                        </button>
                        <button type="submit" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">Save Proofs</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Proofs Checklist -->
            <div id="proofs_container" style="display: flex; flex-direction: column; gap: 1rem;">
                <!-- Cards will be dynamically inserted here -->
            </div>
        </div>
    </div>
</div>

<!-- Link Document Selector Modal -->
<div id="linkDocumentModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 10000;">
    <div class="modal-content" style="max-width: 800px; width: 90%; max-height: 80vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Select Institutional Document</h2>
            <button onclick="document.getElementById('linkDocumentModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <div style="margin-bottom: 1rem;">
            <input type="text" id="doc_selector_search" placeholder="Search documents by code, category, or purpose..." class="form-control" oninput="filterSelectorDocs()" style="padding: 0.6rem 1rem;">
        </div>

        <div style="flex: 1; overflow-y: auto; padding-right: 5px;">
            <table class="qa-table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--border-color); text-align: left;">
                        <th style="padding: 12px 8px;">Code</th>
                        <th style="padding: 12px 8px;">Category</th>
                        <th style="padding: 12px 8px;">Purpose</th>
                        <th style="padding: 12px 8px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody id="doc_selector_rows">
                    <!-- Dynamic rows -->
                </tbody>
            </table>
            <div id="doc_selector_empty" style="display: none; text-align: center; padding: 2rem; color: var(--text-secondary);">
                No matching documents found.
            </div>
        </div>
    </div>
</div>

<script>
    let currentRequirement = null;
    let activeRequirementBridges = [];
    const currentUserId = <?= $_SESSION['user_id'] ?? 0 ?>;
    const currentSubmissions = <?= json_encode($submissions) ?>;
    const allInstitutionalDocs = <?= json_encode($all_inst_docs) ?>;
    const isQAOGlobal = <?= json_encode($is_qao) ?>;

    function handleRequirementClick(id, name, codename, sub, bridges) {
        currentRequirement = { id, name, codename, sub };
        activeRequirementBridges = bridges || [];
        
        let uploadedBridge = null;
        if (bridges && bridges.length > 0) {
            uploadedBridge = bridges.find(b => b.submission_id != null);
        }

        if (uploadedBridge) {
            const mockSub = {
                submission_id: uploadedBridge.submission_id,
                requirement_id: uploadedBridge.requirement_id,
                status: uploadedBridge.sub_status,
                google_drive_link: uploadedBridge.sub_link,
                google_drive_file_id: uploadedBridge.google_drive_file_id,
                file_path: uploadedBridge.sub_path,
                remarks: uploadedBridge.sub_remarks,
                user_id: uploadedBridge.sub_user_id,
                created_at: uploadedBridge.sub_created_at,
                division_name: uploadedBridge.sub_division_name,
                office_name: uploadedBridge.sub_office_name,
                fname: uploadedBridge.uploader_fname,
                lname: uploadedBridge.uploader_lname,
                marker_fname: uploadedBridge.reviewer_fname,
                marker_lname: uploadedBridge.reviewer_lname
            };
            openReviewModal(mockSub, name);
        } else if (sub) {
            openReviewModal(sub, name);
        } else {
            openUploadModal(id, name, codename, bridges);
        }
    }
    
    function openUploadModalForCurrent() {
        document.getElementById('reviewSubmissionModal').style.display = 'none';
        openUploadModal(currentRequirement.id, currentRequirement.name, currentRequirement.codename, activeRequirementBridges);
    }

    function openComplianceTracker(reqId, reqName, reqCodename, bridges) {
        currentRequirement = { id: reqId, name: reqName, codename: reqCodename };
        activeRequirementBridges = bridges || [];
        sessionStorage.setItem('active_tracker_req_id', reqId);
        
        document.getElementById('comp_req_codename').textContent = reqCodename ? reqCodename + ':' : '';
        document.getElementById('comp_req_title').textContent = reqName;
        
        if (document.getElementById('add_proof_req_id')) {
            document.getElementById('add_proof_req_id').value = reqId;
        }

        const fieldsContainer = document.getElementById('add_proof_fields_container');
        if (fieldsContainer) {
            fieldsContainer.innerHTML = `
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="text" name="proof_names[]" required placeholder="e.g. Syllabus, Class Schedule, OBE Curriculum Map" class="form-control" style="flex: 1; padding: 0.5rem 0.8rem; font-size: 0.85rem;">
                    <div style="width: 28px;"></div>
                </div>
            `;
        }

        const container = document.getElementById('proofs_container');
        container.innerHTML = '';

        let approvedCount = 0;
        let totalCount = bridges.length;

        if (totalCount === 0) {
            // Legacy / general submission mode
            const sub = currentSubmissions[reqId] || null;
            totalCount = 1;
            if (sub && sub.status === 'Approved') approvedCount = 1;

            let statusHTML = '';
            let detailsHTML = '';
            let actionsHTML = '';

            if (sub) {
                const statusColor = sub.status === 'Approved' ? '#22c55e' : (sub.status === 'Disapproved' || sub.status === 'Returned' ? '#ef4444' : '#3b82f6');
                statusHTML = `<span class="user-badge" style="background: ${statusColor}; color: white; padding: 2px 8px; font-size: 0.75rem; border-radius: 4px;">${sub.status}</span>`;
                detailsHTML = `
                    <div style="font-size: 0.85rem; margin-top: 5px; color: var(--text-secondary);">
                        Uploaded by: <strong>${sub.fname} ${sub.lname}</strong><br>
                        Link: <a href="${sub.google_drive_link}" target="_blank" style="color: var(--accent-blue); text-decoration: underline;">View File on Google Drive</a>
                    </div>
                `;
                actionsHTML = `
                    <button class="btn btn-secondary" onclick="openReviewModalFromTracker(${JSON.stringify(sub).replace(/"/g, '&quot;')})" style="padding: 4px 8px; font-size: 0.75rem;">View & Review</button>
                `;
            } else {
                statusHTML = `<span class="user-badge" style="background: #cbd5e1; color: var(--text-primary); padding: 2px 8px; font-size: 0.75rem; border-radius: 4px;">Missing</span>`;
                detailsHTML = `<p style="margin: 5px 0 0 0; font-size: 0.85rem; color: var(--text-secondary);">No general submission has been uploaded yet.</p>`;
                actionsHTML = `
                    <button class="btn btn-primary" onclick="triggerUpload(null)" style="padding: 4px 8px; font-size: 0.75rem;">Upload File</button>
                `;
            }

            container.innerHTML = `
                <div class="qa-card" style="padding: 1rem; border: 1px solid var(--border-color); border-radius: 8px; background: white;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h4 style="margin: 0; font-size: 0.95rem; color: var(--accent-blue);">General Submission</h4>
                            ${detailsHTML}
                        </div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
                            ${statusHTML}
                            <div style="display: flex; gap: 5px;">
                                ${actionsHTML}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } else {
            // Render specific compliance proofs
            bridges.forEach(b => {
                let statusColor = '#cbd5e1';
                let statusLabel = 'Missing';
                let detailsHTML = '';
                let actionsHTML = '';

                if (b.document_id) {
                    statusColor = '#10b981';
                    statusLabel = 'Institutional Document';
                    detailsHTML = `
                        <div style="font-size: 0.85rem; margin-top: 5px; color: var(--text-secondary);">
                            Mapped to Masterlist: <strong>${b.doc_code}</strong> (${b.doc_category})<br>
                            Purpose: <em>${b.doc_purpose || 'N/A'}</em>
                        </div>
                    `;
                    actionsHTML = `
                        <button class="btn btn-secondary" onclick="unlinkProof(${b.bridge_id})" style="padding: 4px 8px; font-size: 0.75rem; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;">Unlink</button>
                    `;
                } else if (b.submission_id) {
                    statusLabel = b.sub_status;
                    if (b.sub_status === 'Approved') {
                        approvedCount++;
                        statusColor = '#22c55e';
                    } else if (b.sub_status === 'Pending') {
                        statusColor = '#3b82f6';
                    } else {
                        statusColor = '#ef4444';
                    }

                    detailsHTML = `
                        <div style="font-size: 0.85rem; margin-top: 5px; color: var(--text-secondary);">
                            File Submission by: <strong>${b.uploader_fname} ${b.uploader_lname}</strong><br>
                            Link: <a href="${b.sub_link}" target="_blank" style="color: var(--accent-blue); text-decoration: underline;">View File</a>
                            ${b.sub_remarks ? `<div style="margin-top: 3px; padding: 4px; background: #f8fafc; border-left: 3px solid ${statusColor}; font-size: 0.75rem;">Remarks: ${b.sub_remarks}</div>` : ''}
                        </div>
                    `;

                    // Parse sub details to pass to review modal
                    const mockSub = {
                        submission_id: b.submission_id,
                        requirement_id: b.requirement_id,
                        status: b.sub_status,
                        google_drive_link: b.sub_link,
                        google_drive_file_id: b.google_drive_file_id,
                        file_path: b.sub_path,
                        remarks: b.sub_remarks,
                        user_id: b.sub_user_id,
                        fname: b.uploader_fname,
                        lname: b.uploader_lname,
                        marker_fname: b.reviewer_fname,
                        marker_lname: b.reviewer_lname
                    };

                    actionsHTML = `
                        <button class="btn btn-secondary" onclick="openReviewModalFromTracker(${JSON.stringify(mockSub).replace(/"/g, '&quot;')})" style="padding: 4px 8px; font-size: 0.75rem;">View & Review</button>
                        <button class="btn btn-secondary" onclick="unlinkProof(${b.bridge_id})" style="padding: 4px 8px; font-size: 0.75rem; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;">Unlink</button>
                    `;
                } else {
                    detailsHTML = `<p style="margin: 5px 0 0 0; font-size: 0.85rem; color: var(--text-secondary);">No file uploaded or institutional document linked.</p>`;
                    
                    actionsHTML = `
                        <button class="btn btn-secondary" onclick="openLinkDocumentSelector(${b.bridge_id})" style="padding: 4px 8px; font-size: 0.75rem; background: transparent; border: 1px solid var(--accent-blue); color: var(--accent-blue); display: inline-block; margin-right: 5px;">Link Document</button>
                        <button class="btn btn-primary" onclick="triggerUpload(${b.bridge_id})" style="padding: 4px 8px; font-size: 0.75rem; display: inline-block;">Upload File</button>
                    `;
                }

                // Delete button for QAO
                let deleteBtnHTML = '';
                if (isQAOGlobal) {
                    deleteBtnHTML = `
                        <button onclick="deleteProof(${b.bridge_id})" style="background: transparent; border: none; padding: 4px; cursor: pointer; color: #ef4444; border-radius: 4px; margin-left: 5px;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'" title="Delete proof requirement">
                            &times;
                        </button>
                    `;
                }

                container.innerHTML += `
                    <div class="qa-card" style="padding: 1rem; border: 1px solid var(--border-color); border-radius: 8px; background: white;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div style="flex: 1; padding-right: 15px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <h4 style="margin: 0; font-size: 0.95rem; color: var(--accent-blue);">${b.proof_name}</h4>
                                    ${deleteBtnHTML}
                                </div>
                                ${detailsHTML}
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0;">
                                <span class="user-badge" style="background: ${statusColor}; color: ${statusColor === '#cbd5e1' ? 'var(--text-primary)' : 'white'}; padding: 2px 8px; font-size: 0.75rem; border-radius: 4px;">${statusLabel}</span>
                                <div style="display: flex; gap: 5px; align-items: center;">
                                    ${actionsHTML}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
        }

        const percentage = Math.round((approvedCount / totalCount) * 100);
        document.getElementById('comp_progress_bar').style.width = `${percentage}%`;
        document.getElementById('comp_progress_text').textContent = `${percentage}%`;

        openModal('complianceTrackerModal');
    }

    function openReviewModalFromTracker(sub) {
        document.getElementById('complianceTrackerModal').style.display = 'none';
        openReviewModal(sub, currentRequirement.name);
    }

    function triggerUpload(bridgeId) {
        document.getElementById('complianceTrackerModal').style.display = 'none';
        openUploadModal(currentRequirement.id, currentRequirement.name, currentRequirement.codename || '', activeRequirementBridges, bridgeId);
    }

    async function linkInstitutionalDoc(bridgeId, docId) {
        if (!docId) return;
        try {
            const response = await fetch('../api/accreditation.php?action=link_document', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `bridge_id=${bridgeId}&document_id=${docId}`
            });
            const result = await response.json();
            if (result.success) {
                window.location.reload();
            } else {
                showConfirmation({ title: 'Error', message: result.message, type: 'danger' });
            }
        } catch (error) {
            console.error('Link error:', error);
            showConfirmation({ title: 'Error', message: 'Failed to link document.', type: 'danger' });
        }
    }

    async function unlinkProof(bridgeId) {
        try {
            const response = await fetch('../api/accreditation.php?action=unlink_proof', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `bridge_id=${bridgeId}`
            });
            const result = await response.json();
            if (result.success) {
                window.location.reload();
            } else {
                showConfirmation({ title: 'Error', message: result.message, type: 'danger' });
            }
        } catch (error) {
            console.error('Unlink error:', error);
            showConfirmation({ title: 'Error', message: 'Failed to unlink proof.', type: 'danger' });
        }
    }

    async function deleteProof(bridgeId) {
        try {
            const response = await fetch(`../api/accreditation.php?action=delete_proof&bridge_id=${bridgeId}`);
            const result = await response.json();
            if (result.success) {
                window.location.reload();
            } else {
                showConfirmation({ title: 'Error', message: result.message, type: 'danger' });
            }
        } catch (error) {
            console.error('Delete error:', error);
            showConfirmation({ title: 'Error', message: 'Failed to delete compliance proof.', type: 'danger' });
        }
    }

    function addProofField() {
        const container = document.getElementById('add_proof_fields_container');
        if (!container) return;
        const row = document.createElement('div');
        row.style.display = 'flex';
        row.style.gap = '8px';
        row.style.alignItems = 'center';
        row.innerHTML = `
            <input type="text" name="proof_names[]" required placeholder="e.g. Syllabus, Class Schedule, OBE Curriculum Map" class="form-control" style="flex: 1; padding: 0.5rem 0.8rem; font-size: 0.85rem;">
            <button type="button" onclick="this.parentElement.remove()" style="background: transparent; border: none; font-size: 1.25rem; color: #ef4444; cursor: pointer; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 4px;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">&times;</button>
        `;
        container.appendChild(row);
    }

    let currentBridgeIdToLink = null;

    function openLinkDocumentSelector(bridgeId) {
        currentBridgeIdToLink = bridgeId;
        document.getElementById('doc_selector_search').value = '';
        renderSelectorDocs();
        openModal('linkDocumentModal');
    }

    function renderSelectorDocs() {
        const query = document.getElementById('doc_selector_search').value.toLowerCase();
        const container = document.getElementById('doc_selector_rows');
        const emptyMsg = document.getElementById('doc_selector_empty');
        if (!container) return;
        container.innerHTML = '';

        const filtered = allInstitutionalDocs.filter(d => {
            const code = (d.doc_code || '').toLowerCase();
            const category = (d.category || '').toLowerCase();
            const purpose = (d.purpose || '').toLowerCase();
            return code.includes(query) || category.includes(query) || purpose.includes(query);
        });

        if (filtered.length === 0) {
            emptyMsg.style.display = 'block';
        } else {
            emptyMsg.style.display = 'none';
            filtered.forEach(d => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid var(--border-color)';
                
                const purposeTrunc = d.purpose ? d.purpose.substring(0, 80) + (d.purpose.length > 80 ? '...' : '') : 'N/A';
                
                tr.innerHTML = `
                    <td style="padding: 10px 8px; font-weight: 600; color: var(--accent-blue);">${escapeHTML(d.doc_code)}</td>
                    <td style="padding: 10px 8px;">${escapeHTML(d.category)}</td>
                    <td style="padding: 10px 8px; color: var(--text-secondary); max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHTML(d.purpose || '')}">${escapeHTML(purposeTrunc)}</td>
                    <td style="padding: 10px 8px; text-align: right;">
                        <button class="btn btn-primary" onclick="selectDocForBridge(${d.doc_id})" style="padding: 4px 10px; font-size: 0.75rem;">Select</button>
                    </td>
                `;
                container.appendChild(tr);
            });
        }
    }

    function filterSelectorDocs() {
        renderSelectorDocs();
    }

    function selectDocForBridge(docId) {
        document.getElementById('linkDocumentModal').style.display = 'none';
        linkInstitutionalDoc(currentBridgeIdToLink, docId);
    }

    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    }

    function openReviewModal(sub, name) {
        document.getElementById('review_title').textContent = name;

        // Show uploader actions only if it's the user's own submission AND it's not already Approved
        const uploaderActions = document.getElementById('uploader_actions');
        const addAnotherBtn = document.getElementById('add_another_doc_btn');
        if (sub.user_id == currentUserId && sub.status !== 'Approved') {
            uploaderActions.style.display = 'flex';
        } else {
            uploaderActions.style.display = 'none';
        }
        
        if (addAnotherBtn) {
            addAnotherBtn.style.display = 'block';
        }

        // Show review buttons only if status is Pending (and user is QAO)
        const reviewActions = document.getElementById('review_actions');
        if (reviewActions) {
            if (sub.status === 'Pending') {
                reviewActions.style.display = 'flex';
            } else {
                reviewActions.style.display = 'none';
            }
        }

        // Preview logic: if it's a folder, we can't easily iframe it without auth issues in some browsers, 
        // but for files /view works well.
        const previewUrl = sub.google_drive_link.replace('/view', '/preview');
        document.getElementById('preview_frame').src = previewUrl;
        document.getElementById('rev_drive_link').href = sub.file_path || sub.google_drive_link;

        document.getElementById('rev_user').textContent = sub.fname + ' ' + sub.lname;
        document.getElementById('rev_division').textContent = sub.division_name || 'N/A';
        document.getElementById('rev_office').textContent = sub.office_name || 'N/A';

        // Format date: Date and hour:minute PM/AM
        const dateValue = sub.created_at || sub.updated_at || sub.uploaded_at || new Date();
        const date = new Date(dateValue);
        const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
        document.getElementById('rev_date').textContent = date.toLocaleDateString('en-US', options);

        const badge = document.getElementById('rev_status_badge');
        badge.textContent = sub.status;
        badge.style.background = sub.status === 'Approved' ? '#22c55e' : (sub.status === 'Disapproved' ? '#ef4444' : '#3b82f6');

        const markerContainer = document.getElementById('rev_marker_container');
        if (sub.marked_by && sub.marker_fname) {
            markerContainer.style.display = 'block';
            document.getElementById('rev_marker').textContent = sub.marker_fname + ' ' + sub.marker_lname;
        } else {
            markerContainer.style.display = 'none';
        }

        // Remarks handling
        const remarksContainer = document.getElementById('remarks_container');
        const remarksDisplay = document.getElementById('remarks_display');
        const remarksTextarea = document.getElementById('review_remarks');
        const isQAO = <?= json_encode($is_qao) ?>;

        if (remarksContainer) {
            // Show remarks if user is QAO OR if they are the uploader
            if (isQAO || sub.user_id == currentUserId) {
                remarksContainer.style.display = 'block';

                if (sub.status === 'Pending' && isQAO) {
                    remarksTextarea.style.display = 'block';
                    remarksDisplay.style.display = 'none';
                    remarksTextarea.value = sub.remarks || '';
                } else {
                    remarksTextarea.style.display = 'none';
                    remarksDisplay.style.display = 'block';
                    remarksDisplay.textContent = sub.remarks || 'No remarks provided.';
                }
            } else {
                remarksContainer.style.display = 'none';
            }
        }

        openModal('reviewSubmissionModal');
    }

    async function removeSubmission() {
        if (!confirm('Are you sure you want to remove this submission? This will only remove the record from the tracker, not the files from Drive.')) return;

        const reqId = currentRequirement.id;
        try {
            const response = await fetch(`../api/accreditation.php?action=delete_submission&requirement_id=${reqId}`);
            const result = await response.json();
            if (result.success) {
                window.location.reload();
            } else {
                alert(result.message);
            }
        } catch (error) {
            console.error('Remove error:', error);
        }
    }

    async function submitReview(status) {
        const remarks = document.getElementById('review_remarks').value;
        const reqId = currentRequirement.id;

        try {
            const response = await fetch('../api/accreditation.php?action=review_submission', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `requirement_id=${reqId}&status=${status}&remarks=${encodeURIComponent(remarks)}`
            });
            const result = await response.json();
            if (result.success) {
                showConfirmation({
                    title: 'Review Saved',
                    message: `Requirement has been marked as ${status}.`,
                    type: 'success',
                    onConfirm: () => window.location.reload()
                });
            } else {
                showConfirmation({ title: 'Error', message: result.message, type: 'danger' });
            }
        } catch (error) {
            console.error('Review error:', error);
            showConfirmation({ title: 'Error', message: 'Failed to save review.', type: 'danger' });
        }
    }

    function reopenUpload() {
        document.getElementById('reviewSubmissionModal').style.display = 'none';
        openUploadModal(currentRequirement.id, currentRequirement.name, currentRequirement.codename);
    }
    const categoriesByParent = <?= json_encode($categories_by_parent) ?>;

    function handleCascadingSelect(select, containerId, inputId) {
        const parentId = select.value;
        const container = document.getElementById(containerId);
        const finalInput = document.getElementById(inputId);

        // Remove all following selects
        let next = select.nextElementSibling;
        while (next) {
            const toRemove = next;
            next = next.nextElementSibling;
            toRemove.remove();
        }

        finalInput.value = parentId;

        // If a parent is selected and it has children, add a new dropdown
        if (parentId && categoriesByParent[parentId]) {
            const newSelect = document.createElement('select');
            newSelect.className = 'form-control parent-cat-select';
            newSelect.style.marginTop = '0.8rem';
            newSelect.onchange = function () { handleCascadingSelect(this, containerId, inputId); };

            let options = '<option value="">Select sub-category...</option>';
            categoriesByParent[parentId].forEach(cat => {
                options += `<option value="${cat.category_id}">${cat.name}</option>`;
            });
            newSelect.innerHTML = options;
            container.appendChild(newSelect);
        }
    }

    function addItemRow(containerId, type) {
        const container = document.getElementById(containerId);
        const div = document.createElement('div');
        div.className = 'item-row';
        div.style.marginBottom = '1rem';

        if (type === 'category') {
            div.innerHTML = `
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Additional Category Name</label>
                <input type="text" name="name[]" placeholder="Category Name..." class="form-control">
            `;
        } else {
            div.style.background = '#f8fafc';
            div.style.padding = '1rem';
            div.style.borderRadius = '6px';
            div.style.border = '1px dashed var(--border-color)';
            div.style.display = 'flex';
            div.style.gap = '1rem';
            div.innerHTML = `
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Codename</label>
                    <input type="text" name="codename[]" placeholder="1.1" class="form-control">
                </div>
                <div style="flex: 2;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Requirement Name *</label>
                    <input type="text" name="name[]" required placeholder="Description..." class="form-control">
                </div>
            `;
        }
        container.appendChild(div);
    }

    function toggleStatusDeadline(status) {
        const container = document.getElementById('status_deadline_container');
        container.style.display = status === 'In Progress' ? 'block' : 'none';
    }

    let isMaximized = false;
    function toggleMaximize() {
        const sidebar = document.getElementById('accSidebar');
        const mainContent = document.getElementById('accMainContent');
        const btn = document.getElementById('maximizeBtn');

        isMaximized = !isMaximized;

        if (isMaximized) {
            sidebar.style.display = 'none';
            mainContent.style.maxWidth = '100%';
            btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v5H3M21 8h-5V3M3 16h5v5M16 21v-5h5"/></svg>`;
            btn.title = "Restore View";
        } else {
            sidebar.style.display = 'block';
            mainContent.style.maxWidth = '';
            btn.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>`;
            btn.title = "Maximize View";
        }
    }

    function toggleCategory(header) {
        const content = header.nextElementSibling;
        const chevron = header.querySelector('.chevron');
        const catId = header.dataset.id;

        if (content.style.display === 'none') {
            content.style.display = 'block';
            chevron.style.transform = 'rotate(90deg)';
            localStorage.setItem(catId, 'expanded');
        } else {
            content.style.display = 'none';
            chevron.style.transform = 'rotate(0deg)';
            localStorage.setItem(catId, 'collapsed');
        }
    }

    // Restore category states
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-id^="cat-header-"]').forEach(header => {
            const catId = header.dataset.id;
            const state = localStorage.getItem(catId);
            if (state === 'expanded') {
                const content = header.nextElementSibling;
                const chevron = header.querySelector('.chevron');
                if (content && chevron) {
                    content.style.display = 'block';
                    chevron.style.transform = 'rotate(90deg)';
                }
            }
        });
    });

    function toggleActionMenu(e, menuId) {
        e.stopPropagation();
        const menu = document.getElementById(menuId);
        if (!menu) return;

        // Toggle the target menu
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    }

    function openModalWithContext(modalId, categoryId, categoryName, type) {
        openModal(modalId);

        const containerId = type === 'cat' ? 'cat_parentSelectorsContainer' : 'req_parentSelectorsContainer';
        const inputId = type === 'cat' ? 'cat_final_parent_id' : 'req_final_parent_id';
        const container = document.getElementById(containerId);
        const finalInput = document.getElementById(inputId);

        // Reset container to top level
        const firstSelect = container.querySelector('select');
        firstSelect.value = "";
        handleCascadingSelect(firstSelect, containerId, inputId);

        // Set the value (this is a simplified pre-select, in a real cascading UI you'd want to rebuild the chain, 
        // but for now setting the hidden ID and showing the target name is effective)
        finalInput.value = categoryId;

        // Add a "Targeting" indicator if it doesn't exist
        let indicator = document.getElementById(type + '_targeting_info');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = type + '_targeting_info';
            indicator.style.background = '#eff6ff';
            indicator.style.border = '1px solid #bfdbfe';
            indicator.style.padding = '0.5rem 0.8rem';
            indicator.style.borderRadius = '4px';
            indicator.style.marginBottom = '1rem';
            indicator.style.fontSize = '0.85rem';
            indicator.style.color = '#1e40af';
            indicator.style.display = 'flex';
            indicator.style.justifyContent = 'space-between';
            indicator.style.alignItems = 'center';
            container.parentNode.insertBefore(indicator, container);
        }
        indicator.innerHTML = `<span>Target: <strong>${categoryName}</strong></span><button type="button" onclick="resetTarget('${type}')" style="background:transparent;border:none;color:#1e40af;cursor:pointer;font-weight:bold;">&times;</button>`;
        container.style.display = 'none'; // Hide the cascading selectors when targeting specific
    }

    function resetTarget(type) {
        const indicator = document.getElementById(type + '_targeting_info');
        const containerId = type === 'cat' ? 'cat_parentSelectorsContainer' : 'req_parentSelectorsContainer';
        const inputId = type === 'cat' ? 'cat_final_parent_id' : 'req_final_parent_id';

        if (indicator) indicator.remove();
        document.getElementById(containerId).style.display = 'flex';
        document.getElementById(inputId).value = "";

        const firstSelect = document.getElementById(containerId).querySelector('select');
        firstSelect.value = "";
        handleCascadingSelect(firstSelect, containerId, inputId);
    }

    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
        document.getElementById('accActionMenu').style.display = 'none';
    }

    function filterAccreditations() {
        const query = document.getElementById('accSearch').value.toLowerCase();
        const items = document.querySelectorAll('.acc-item');

        items.forEach(item => {
            const code = item.dataset.code;
            const name = item.dataset.name;
            if (code.includes(query) || name.includes(query)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleDeadline(status) {
        const container = document.getElementById('deadline_container');
        const input = document.getElementById('acc_deadline');
        if (status === 'Inactive' || status === 'Completed') {
            container.style.display = 'none';
            input.required = false;
        } else {
            container.style.display = 'block';
            input.required = true;
        }
    }

    function toggleLocalMenu(btn) {
        const menu = btn.nextElementSibling;
        const allMenus = document.querySelectorAll('.local-dropdown');

        allMenus.forEach(m => {
            if (m !== menu) m.style.display = 'none';
        });

        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    }

    function openEditModal(type, id, name, codename = '', parentId = '', parentName = '') {
        const modalId = type === 'category' ? 'editCategoryModal' : 'editRequirementModal';
        const prefix = type === 'category' ? 'edit_cat' : 'edit_req';
        const typeKey = type === 'category' ? 'cat' : 'req';

        document.getElementById(prefix + '_id').value = id;
        document.getElementById(prefix + '_name').value = name;
        if (type === 'requirement') document.getElementById('edit_req_codename').value = codename;

        openModal(modalId);

        if (parentId) {
            const containerId = type === 'category' ? 'edit_cat_parentSelectorsContainer' : 'edit_req_parentSelectorsContainer';
            const inputId = type === 'category' ? 'edit_cat_final_parent_id' : 'edit_req_final_parent_id';
            const container = document.getElementById(containerId);
            const finalInput = document.getElementById(inputId);

            // Re-use targeting indicator logic
            finalInput.value = parentId;

            let indicator = document.getElementById(prefix + '_targeting_info');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.id = prefix + '_targeting_info';
                indicator.style.background = '#f8fafc';
                indicator.style.border = '1px solid var(--border-color)';
                indicator.style.padding = '0.5rem 0.8rem';
                indicator.style.borderRadius = '4px';
                indicator.style.marginBottom = '1rem';
                indicator.style.fontSize = '0.85rem';
                indicator.style.color = 'var(--text-secondary)';
                indicator.style.display = 'flex';
                indicator.style.justifyContent = 'space-between';
                indicator.style.alignItems = 'center';
                container.parentNode.insertBefore(indicator, container);
            }
            indicator.innerHTML = `<span>Current Category: <strong>${parentName || 'Top Level'}</strong></span><button type="button" onclick="resetEditTarget('${type}')" style="background:transparent;border:none;color:var(--accent-blue);cursor:pointer;font-size:1.2rem;">&times;</button>`;
            container.style.display = 'none';
        }
    }

    function resetEditTarget(type) {
        const prefix = type === 'category' ? 'edit_cat' : 'edit_req';
        const containerId = prefix + '_parentSelectorsContainer';
        const inputId = prefix + '_final_parent_id';
        const indicator = document.getElementById(prefix + '_targeting_info');

        if (indicator) indicator.remove();
        document.getElementById(containerId).style.display = 'flex';
        document.getElementById(inputId).value = "";

        const firstSelect = document.getElementById(containerId).querySelector('select');
        firstSelect.value = "";
        handleCascadingSelect(firstSelect, containerId, inputId);
    }

    let currentBridges = []; // to store bridges for the active upload modal

    function openUploadModal(id, name, codename = '', bridges = [], selectedBridgeId = null) {
        document.getElementById('upload_req_id').value = id;
        document.getElementById('upload_req_title').textContent = name;
        document.getElementById('upload_req_title').dataset.codename = codename;
        
        const step1 = document.getElementById('upload_step_1');
        const step2 = document.getElementById('upload_step_2');
        const select = document.getElementById('upload_proof_select');
        const bridgeInput = document.getElementById('upload_bridge_id');
        const backBtn = document.getElementById('upload_back_btn');
        
        currentBridges = bridges || [];
        
        select.onchange = (e) => {
            const val = e.target.value;
            if (val) {
                bridgeInput.value = val === 'general' ? '' : val;
                document.getElementById('upload_next_btn').disabled = false;
                document.getElementById('upload_next_btn').style.opacity = '1';
                document.getElementById('upload_next_btn').style.cursor = 'pointer';
            } else {
                bridgeInput.value = '';
                document.getElementById('upload_next_btn').disabled = true;
                document.getElementById('upload_next_btn').style.opacity = '0.5';
                document.getElementById('upload_next_btn').style.cursor = 'not-allowed';
            }
        };
        
        if (currentBridges.length > 0) {
            select.innerHTML = '<option value="" disabled selected>-- Select Proof Requirement to Upload For --</option>';
            currentBridges.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b.bridge_id;
                let suffix = '';
                if (b.document_id) {
                    suffix = ' (Linked: ' + b.doc_code + ')';
                } else if (b.submission_id) {
                    suffix = ' (Uploaded File)';
                }
                opt.textContent = b.proof_name + suffix;
                select.appendChild(opt);
            });
            
            const generalOpt = document.createElement('option');
            generalOpt.value = 'general';
            generalOpt.textContent = '-- General Upload (No specific proof) --';
            select.appendChild(generalOpt);
            
            if (selectedBridgeId) {
                // Pre-selected from compliance checklist modal (skip Step 1)
                select.value = selectedBridgeId;
                bridgeInput.value = selectedBridgeId;
                
                step1.style.display = 'none';
                step2.style.display = 'flex';
                backBtn.style.display = 'flex';
            } else {
                // Open from requirement heading click - show step 1, hide step 2
                select.value = "";
                bridgeInput.value = "";
                
                step1.style.display = 'flex';
                step2.style.display = 'none';
                backBtn.style.display = 'none';
                
                document.getElementById('upload_next_btn').disabled = true;
                document.getElementById('upload_next_btn').style.opacity = '0.5';
                document.getElementById('upload_next_btn').style.cursor = 'not-allowed';
            }
        } else {
            // No proofs defined, skip Step 1
            step1.style.display = 'none';
            step2.style.display = 'flex';
            backBtn.style.display = 'none';
            bridgeInput.value = selectedBridgeId || '';
        }
        
        clearFile();
        openModal('uploadRequirementModal');
    }

    function goToUploadStep2() {
        document.getElementById('upload_step_1').style.display = 'none';
        document.getElementById('upload_step_2').style.display = 'flex';
        document.getElementById('upload_back_btn').style.display = 'flex';
    }

    function goToUploadStep1() {
        document.getElementById('upload_step_1').style.display = 'flex';
        document.getElementById('upload_step_2').style.display = 'none';
    }

    function updateFileInfo(input) {
        const info = document.getElementById('fileInfo');
        const list = document.getElementById('fileList');
        list.innerHTML = '';

        if (input.files && input.files.length > 0) {
            for (let i = 0; i < input.files.length; i++) {
                const file = input.files[i];
                if (file.type !== 'application/pdf') {
                    showConfirmation({ title: 'Invalid File', message: `"${file.name}" is not a PDF. Only PDF files are allowed.`, type: 'danger', actionLabel: 'OK' });
                    clearFile();
                    return;
                }
                const item = document.createElement('div');
                item.style.fontSize = '0.8rem';
                item.textContent = `• ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                list.appendChild(item);
            }
            info.style.display = 'block';
        }
    }

    function clearFile() {
        document.getElementById('fileInput').value = '';
        document.getElementById('fileInfo').style.display = 'none';
        document.getElementById('fileList').innerHTML = '';
    }

    async function handleFileUpload(e) {
        e.preventDefault();
        const btn = document.getElementById('uploadBtn');
        const fileInput = document.getElementById('fileInput');
        const reqId = document.getElementById('upload_req_id').value;
        const codename = document.getElementById('upload_req_title').dataset.codename;

        if (!fileInput.files || fileInput.files.length === 0) {
            showConfirmation({ title: 'Error', message: 'Please select at least one PDF file.', type: 'danger', actionLabel: 'OK' });
            return;
        }

        const formData = new FormData();
        for (let i = 0; i < fileInput.files.length; i++) {
            formData.append('files[]', fileInput.files[i]);
        }
        formData.append('requirement_id', reqId);
        formData.append('codename', codename);
        
        const bridgeId = document.getElementById('upload_bridge_id').value;
        if (bridgeId) {
            formData.append('bridge_id', bridgeId);
        }

        btn.disabled = true;
        btn.innerHTML = '<span>Uploading...</span>';

        try {
            const response = await fetch('../api/upload.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                showConfirmation({
                    title: 'Success',
                    message: result.message || 'File(s) uploaded successfully!',
                    type: 'success',
                    onConfirm: () => window.location.reload()
                });
            } else {
                showConfirmation({ title: 'Error', message: result.message || 'Upload failed.', type: 'danger', actionLabel: 'OK' });
            }
        } catch (error) {
            console.error('Upload error:', error);
            showConfirmation({ title: 'Error', message: 'An unexpected error occurred. Please check your network connection.', type: 'danger', actionLabel: 'OK' });
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span>Start Upload</span>';
        }
    }

    function deleteItem(type, id, name) {
        const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);
        showConfirmation({
            title: `Delete ${typeLabel}`,
            message: `Are you sure you want to delete "${name}"? This action cannot be undone.`,
            type: 'danger',
            actionLabel: `Delete ${typeLabel}`,
            onConfirm: async () => {
                try {
                    const response = await fetch(`../api/accreditation.php?action=delete_${type}&${type}_id=${id}`);
                    const text = await response.text();

                    let result;
                    try {
                        result = JSON.parse(text);
                    } catch (e) {
                        console.error('Server response was not JSON:', text);
                        showConfirmation({ title: 'Error', message: 'Received invalid response from server.', type: 'danger' });
                        return;
                    }

                    if (result.success) {
                        showConfirmation({
                            title: 'Deleted',
                            message: result.message,
                            type: 'success',
                            onConfirm: () => {
                                if (type === 'accreditation') {
                                    window.location.href = 'feed.php?action=accreditation';
                                } else {
                                    window.location.reload();
                                }
                            }
                        });
                    } else {
                        showConfirmation({ title: 'Error', message: result.message, type: 'danger' });
                    }
                } catch (error) {
                    console.error('Delete error:', error);
                    showConfirmation({ title: 'Error', message: 'An unexpected error occurred.', type: 'danger' });
                }
            }
        });
    }

    function openEditAccModal() {
        openModal('editAccreditationModal');
    }

    // Close modals and menus on click outside
    window.onclick = function (event) {
        const accModal = document.getElementById('addAccreditationModal');
        const editAccModal = document.getElementById('editAccreditationModal');
        const catModal = document.getElementById('addCategoryModal');
        const reqModal = document.getElementById('addRequirementModal');
        const statusModal = document.getElementById('changeStatusModal');
        const editCatModal = document.getElementById('editCategoryModal');
        const editReqModal = document.getElementById('editRequirementModal');
        const importModal = document.getElementById('importAccreditationModal');
        const actionMenu = document.getElementById('accActionMenu');
        const allLocalMenus = document.querySelectorAll('.local-dropdown');

        if (event.target == accModal) accModal.style.display = "none";
        if (event.target == editAccModal) editAccModal.style.display = "none";
        if (event.target == catModal) catModal.style.display = "none";
        if (event.target == reqModal) reqModal.style.display = "none";
        if (event.target == statusModal) statusModal.style.display = "none";
        if (event.target == editCatModal) editCatModal.style.display = "none";
        if (event.target == editReqModal) editReqModal.style.display = "none";
        if (event.target == importModal) importModal.style.display = "none";
        
        const compModal = document.getElementById('complianceTrackerModal');
        const linkDocModal = document.getElementById('linkDocumentModal');
        if (event.target == compModal) closeComplianceTrackerModal();
        if (event.target == linkDocModal) linkDocModal.style.display = "none";

        if (actionMenu && !actionMenu.contains(event.target)) {
            actionMenu.style.display = 'none';
        }

        // Close local dropdowns when clicking outside
        if (!event.target.closest('button')) {
            allLocalMenus.forEach(m => m.style.display = 'none');
        }
    }

    async function handleImportExcel(e, isFinal = false) {
        if (e) e.preventDefault();
        const form = document.getElementById('importExcelForm');
        const formData = new FormData(form);

        if (!isFinal) {
            formData.append('dry_run', '1');
        } else {
            formData.append('dry_run', '0');
            // Get selected sheets
            const selectedSheets = Array.from(document.querySelectorAll('.sheet-selector:checked')).map(cb => cb.value);
            if (selectedSheets.length === 0) {
                showConfirmation({ title: 'No Selection', message: 'Please select at least one worksheet to import.', type: 'danger' });
                return;
            }
            formData.append('selected_sheets', JSON.stringify(selectedSheets));
            console.log('[Import Finalize] selected_sheets being sent:', selectedSheets);
            document.getElementById('importSummaryModal').style.display = 'none';
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerText;

        submitBtn.disabled = true;
        submitBtn.innerText = isFinal ? 'Importing...' : 'Analyzing...';

        try {
            const response = await fetch('../api/import_excel.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                if (result.is_dry_run) {
                    showImportSummary(result);
                } else {
                    showConfirmation({
                        title: 'Success',
                        message: result.message + ' ' + result.details,
                        type: 'success',
                        onConfirm: () => window.location.reload()
                    });
                }
            } else {
                showConfirmation({ title: 'Import Failed', message: result.message, type: 'danger' });
            }
        } catch (error) {
            console.error('Import error:', error);
            showConfirmation({ title: 'Error', message: 'An unexpected error occurred.', type: 'danger' });
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerText = originalText;
        }
    }

    function showImportSummary(data) {
        const modal = document.getElementById('importSummaryModal');
        const content = document.getElementById('summaryContent');

        let html = `
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.7rem; border-radius: 8px; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <p style="margin: 0; color: #166534; font-weight: 600; font-size: 0.95rem;">Analysis Complete!</p>
                    <p style="margin: 2px 0 0; font-size: 0.8rem; color: #166534;">
                        Found <b>${data.stats.categories}</b> categories and <b>${data.stats.requirements}</b> requirements across <b>${Object.keys(data.preview).length}</b> sheets.
                    </p>
                </div>
                <div style="font-size: 0.75rem; color: #15803d; background: #dcfce7; padding: 6px 14px; border-radius: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;"> Ready to Import </div>
            </div>

            <div style="display: flex; gap: 1rem; align-items: flex-start; margin-bottom: 1.5rem;">
                <!-- Left: Preview List -->
                <div style="flex: 1.6; max-height: 50vh; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 1rem; background: #fafafa; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
        `;

        const countRequirements = (item) => {
            let total = 0;
            if (item.items) {
                if (Array.isArray(item.items)) {
                    total += item.items.length;
                } else {
                    for (const key in item.items) {
                        total += countRequirements(item.items[key]);
                    }
                }
            }
            return total;
        };

        const renderPreviewItems = (items, depth = 0) => {
            let innerHtml = '';
            if (!items) return '';
            
            if (Array.isArray(items)) {
                // Requirements
                items.forEach(req => {
                    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                    const proofPart = req.proofs
                        ? ` <span style="color:#64748b;font-size:0.8rem;">(H: ${esc(req.proofs)})</span>`
                        : '';
                    innerHtml += `<div style="margin-left: ${depth * 1.5}rem; color: #475569; font-size: 0.85rem; margin-top: 4px;">
                        • ${req.code} ${req.name}${proofPart}
                    </div>`;
                });
            } else {
                // Categories
                for (const key in items) {
                    const item = items[key];
                    const count = countRequirements(item);
                    
                    if (depth === 0) { // Area level
                         innerHtml += `<div style="margin-left: 1.5rem; font-weight: 600; font-size: 0.95rem; color: #1e293b; margin-top: 1rem; border-left: 3px solid #cbd5e1; padding-left: 10px;">
                            ${item.name} <span style="font-weight: 400; color: #94a3b8; font-size: 0.8rem;">(${count} reqs)</span>
                        </div>`;
                    } else { // Parameter or deeper
                        innerHtml += `<div style="margin-left: ${depth * 1.5}rem; color: ${depth === 1 ? '#334155' : '#64748b'}; font-size: ${depth === 1 ? '0.9rem' : '0.8rem'}; margin-top: 5px; font-weight: ${depth === 1 ? '600' : 'normal'}; font-style: ${depth > 2 ? 'italic' : 'normal'};">
                            ${depth > 1 ? '── ' : '└ '}${item.name} <span style="font-weight: 400; color: #94a3b8; font-size: 0.75rem;">(${count})</span>
                        </div>`;
                    }
                    
                    if (item.items) {
                        innerHtml += renderPreviewItems(item.items, depth + 1);
                    }
                }
            }
            return innerHtml;
        };

        for (const rootId in data.preview) {
            const workbook = data.preview[rootId];
            const workbookReqCount = countRequirements(workbook);

            html += `<div style="margin-bottom: 2rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1.5rem;">
                <div style="background: #f8fafc; padding: 0.8rem; border-radius: 8px; margin-bottom: 0.8rem;">
                    <span style="font-weight: 800; color: var(--accent-blue); font-size: 1.1rem;">${workbook.name}</span>
                    <span style="display: block; font-size: 0.8rem; color: #64748b;">${workbookReqCount} total requirements detected</span>
                </div>`;

            // Iterate nested worksheets inside the workbook
            if (workbook.items && !Array.isArray(workbook.items)) {
                for (const sheetKey in workbook.items) {
                    const sheet = workbook.items[sheetKey];
                    if (!sheet) continue;
                    // The real sheet name sent to PHP — MUST match raw_sheet_name
                    const sheetName = sheet.name || 'Untitled Section';
                    const realSheetName = sheet.raw_sheet_name || sheetName.replace(/^Worksheet:\s*/i, '').replace(/^Program\/Sheet:\s*/i, '');
                    const sheetReqCount = countRequirements(sheet);
                    
                    html += `<div style="margin-left: 1rem; margin-bottom: 0.5rem; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.7rem;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" value="${realSheetName}" checked class="sheet-selector" style="width: 18px; height: 18px; accent-color: var(--accent-blue);">
                            <div>
                                <span style="font-weight: 700; color: #334155; font-size: 0.95rem;">${sheet.name}</span>
                                <span style="display: block; font-size: 0.75rem; color: #64748b;">${sheetReqCount} requirements</span>
                            </div>
                        </label>`;
                    html += renderPreviewItems(sheet.items, 0);
                    html += `</div>`;
                }
            }

            html += `</div>`;
        }

            html += `</div>
                
                <!-- Right: AI Analysis -->
                <div style="flex: 1; max-height: 50vh; overflow-y: auto; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 1px solid #bae6fd; border-radius: 10px; padding: 1rem; position: sticky; top: 0;">
                    <h3 style="font-size: 0.9rem; color: #0369a1; margin: 0 0 1rem; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid rgba(3, 105, 161, 0.1); padding-bottom: 0.8rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                        </svg>
                        AI Assistant Analysis
                    </h3>
                    <div style="font-size: 0.85rem; color: #0c4a6e; line-height: 1.6;">
                        ${data.ai_insights || 'No analysis available.'}
                    </div>
                </div>
            </div>`;
        content.innerHTML = html;
        modal.style.display = 'flex';
    }

    function previewImportFile(input) {
        const file = input.files[0];
        const info = document.getElementById('importFileInfo');
        const text = document.getElementById('importUploadText');
        const icon = document.getElementById('importUploadIcon');
        const zone = document.getElementById('importDropZone');

        if (file) {
            info.innerText = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
            info.style.display = 'block';
            text.innerText = 'File selected and ready!';
            icon.innerHTML = `<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>`;
            zone.style.borderColor = '#10b981';
            zone.style.background = '#f0fdf4';
        }
    }

    function toggleNewAccFields(val) {
        const container = document.getElementById('newAccFields');
        const nameInput = container.querySelector('input[name="accreditation_name"]');
        if (val === 'new') {
            container.style.display = 'block';
            nameInput.required = true;
        } else {
            container.style.display = 'none';
            nameInput.required = false;
        }
    }

    function updateDownloadLink(val) {
        const link = document.getElementById('templateDownloadLink');
        const text = document.getElementById('templateDownloadText');
        const container = link.parentElement;
        
        const templates = {
            'aaccup_program': { name: 'AACCUP Program Standard', file: 'aaccupprogramtemplate.xlsx' },
            'aaccup_institution': { name: 'AACCUP Institution Standard', file: 'aaccupinstitutiontemplate.xlsx' },
            'copc': { name: 'COPC Standard', file: 'copctemplate.xlsx' },
            'suc': { name: 'SUC Standard', file: 'suctemplate.xlsx' }
        };

        if (templates[val]) {
            link.href = `../context/${templates[val].file}`;
            text.innerText = `Download ${templates[val].name} Template`;
            container.style.display = 'block';
        }
    }

    function closeComplianceTrackerModal() {
        document.getElementById('complianceTrackerModal').style.display = 'none';
        sessionStorage.removeItem('active_tracker_req_id');
    }

    document.addEventListener('submit', async (e) => {
        if (e.target && e.target.id === 'addProofForm') {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    form.submit();
                }
            } catch (error) {
                console.error('Error submitting proofs:', error);
                form.submit();
            }
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        const autoOpenReqId = sessionStorage.getItem('active_tracker_req_id');
        if (autoOpenReqId) {
            const btn = document.getElementById(`manage_proofs_btn_${autoOpenReqId}`);
            if (btn) {
                // Ensure dropdown menu contains it, we may need to temporarily show req_menu or click directly
                btn.click();
            }
        }
    });
</script>

<!-- Import Accreditation Modal -->
<div id="importAccreditationModal" class="modal"
    style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div
        style="background: white; padding: 2rem; border-radius: 12px; width: 450px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="margin: 0; color: var(--accent-blue);">Bulk Import Accreditation</h2>
            <button onclick="document.getElementById('importAccreditationModal').style.display='none'"
                style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        <form id="importExcelForm" onsubmit="handleImportExcel(event)" enctype="multipart/form-data">
            <div style="margin-bottom: 1.2rem;">
                <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Import
                    Template</label>
                <select name="template_type" id="templateTypeSelect" onchange="updateDownloadLink(this.value)" required
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; background: white;">
                    <option value="" disabled selected>Select Import Template...</option>
                    <option value="aaccup_institution">AACCUP Institution Standard (Area -> Parameter -> Section)</option>
                    <option value="aaccup_program">AACCUP Program Standard (Area -> Parameter -> Section)</option>
                    <option value="copc">COPC Standard (Category -> Requirement)</option>
                    <option value="suc">SUC Standard (A=KRA, C=code, E=name, H=proofs)</option>
                    <option value="ched" disabled>CHED Standard (Coming Soon)</option>
                    <option value="iso" disabled>ISO Standard (Coming Soon)</option>
                </select>
            </div>

            <div style="margin-bottom: 1.2rem;">
                <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Target
                    Accreditation</label>
                <select name="accreditation_id" onchange="toggleNewAccFields(this.value)" required
                    style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; background: white;">
                    <option value="" disabled selected>Select an accreditation...</option>
                    <option value="new" style="font-weight: 700; color: var(--accent-blue);">+ Create New Accreditation
                    </option>
                    <?php foreach ($accreditations as $acc): ?>
                        <option value="<?= $acc['accreditation_id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="newAccFields" style="display: none;">
                <div style="margin-bottom: 1.2rem;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">New
                        Accreditation Name</label>
                    <input type="text" name="accreditation_name" placeholder="e.g., BSIT Accreditation 2024"
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div style="margin-bottom: 1.2rem;">
                    <label
                        style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Description</label>
                    <textarea name="accreditation_desc" placeholder="Brief details about this import..."
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; height: 80px; resize: none;"></textarea>
                </div>
            </div>

            <div id="importDropZone"
                style="margin-bottom: 1rem; padding: 1.5rem; border: 2px dashed #e2e8f0; border-radius: 8px; text-align: center; background: #f8fafc; transition: all 0.3s ease;">
                <label style="cursor: pointer; display: block;">
                    <div id="importUploadIcon" style="margin-bottom: 0.5rem; color: #10b981;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
                        </svg>
                    </div>
                    <span id="importUploadText" style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);">Click to upload
                        XLSX</span>
                    <input type="file" name="excel_file" accept=".xlsx" required style="display: none;" onchange="previewImportFile(this)">
                    <div id="importFileInfo" style="font-size: 0.75rem; color: #10b981; margin-top: 0.5rem; display: none; font-weight: 600;"></div>
                </label>
            </div>

            <div style="margin-bottom: 1.5rem; text-align: center; display: none;">
                <a id="templateDownloadLink" href="#" download
                    style="display: inline-flex; align-items: center; gap: 5px; font-size: 0.85rem; color: var(--accent-blue); text-decoration: none; font-weight: 600;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    <span id="templateDownloadText">Download Template</span>
                </a>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="document.getElementById('importAccreditationModal').style.display='none'"
                    style="padding: 0.7rem 1.2rem; border: 1px solid var(--border-color); background: white; border-radius: 8px; cursor: pointer; font-weight: 600;">Cancel</button>
                <button type="submit"
                    style="padding: 0.7rem 1.5rem; border: none; background: #10b981; color: white; border-radius: 8px; cursor: pointer; font-weight: 600; transition: opacity 0.2s;">Analyze
                    File</button>
            </div>
        </form>
    </div>
</div>

<!-- Import Summary Modal -->
<div id="importSummaryModal" class="modal"
    style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1001; align-items: flex-start; justify-content: center; backdrop-filter: blur(4px); overflow-y: auto; padding: 2rem 0;">
    <div
        style="background: white; padding: 1.5rem; border-radius: 16px; width: 950px; max-width: 95vw; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); position: relative; margin: auto;">
        <h2 style="margin: 0 0 1.5rem; color: var(--accent-blue);">Import Summary Preview</h2>

        <div id="summaryContent" style="margin-bottom: 2rem;">
            <!-- Content populated by JS -->
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" onclick="document.getElementById('importSummaryModal').style.display='none'"
                style="padding: 0.7rem 1.2rem; border: 1px solid var(--border-color); background: white; border-radius: 8px; cursor: pointer; font-weight: 600;">Back</button>
            <button type="button" onclick="handleImportExcel(null, true)"
                style="padding: 0.7rem 1.5rem; border: none; background: var(--accent-blue); color: white; border-radius: 8px; cursor: pointer; font-weight: 600;">Finalize
                Import</button>
        </div>
    </div>
</div>
