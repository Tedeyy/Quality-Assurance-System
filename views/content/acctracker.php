<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Fetch all accreditations
$stmt = $db->query("SELECT * FROM accreditations ORDER BY name ASC");
$accreditations = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Fetch requirement counts per category
    $stmt = $db->prepare("
        SELECT category_id, COUNT(*) as count 
        FROM accreditation_requirement 
        GROUP BY category_id
    ");
    $stmt->execute();
    $direct_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch approved requirement counts per category
    $stmt = $db->prepare("
        SELECT r.category_id, COUNT(*) as count 
        FROM accreditation_requirement r
        JOIN accreditation_requirement_submissions s ON r.requirement_id = s.requirement_id
        WHERE s.status = 'Approved'
        GROUP BY r.category_id
    ");
    $stmt->execute();
    $direct_approved = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Group categories by parent
    $categories_by_parent = [];
    foreach ($categories as $cat) {
        $parent_id = $cat['parent_category_id'] ?: 0;
        $categories_by_parent[$parent_id][] = $cat;
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

    // Helper to calculate total and approved requirements (including nested)
    $category_stats = [];
    function calculateStats($parent_id, $categories_by_parent, $direct_counts, $direct_approved, &$category_stats)
    {
        if (!isset($categories_by_parent[$parent_id]))
            return ['total' => 0, 'approved' => 0];

        $sum_total = 0;
        $sum_approved = 0;

        foreach ($categories_by_parent[$parent_id] as $cat) {
            $cat_id = $cat['category_id'];
            $direct_t = $direct_counts[$cat_id] ?? 0;
            $direct_a = $direct_approved[$cat_id] ?? 0;
            
            $sub_stats = calculateStats($cat_id, $categories_by_parent, $direct_counts, $direct_approved, $category_stats);
            
            $total = $direct_t + $sub_stats['total'];
            $approved = $direct_a + $sub_stats['approved'];
            
            $category_stats[$cat_id] = ['total' => $total, 'approved' => $approved];
            
            $sum_total += $total;
            $sum_approved += $approved;
        }

        return ['total' => $sum_total, 'approved' => $sum_approved];
    }
    $overall_stats = calculateStats(0, $categories_by_parent, $direct_counts, $direct_approved, $category_stats);
}

function renderCategories($parent_id, $categories_by_parent, $db, $category_stats) {
    global $submissions, $is_qao;
    if (!isset($categories_by_parent[$parent_id])) return;

    foreach ($categories_by_parent[$parent_id] as $cat) {
        $cat_id = $cat['category_id'];
        ?>
        <div style="border: 1px solid var(--border-color); border-radius: 4px; margin-bottom: 0.4rem; margin-left: <?= $parent_id == 0 ? '0' : '1rem' ?>; position: relative;">
            <!-- Category Header -->
            <div onclick="toggleCategory(this)" data-id="cat-header-<?= $cat_id ?>"
                style="background: #f8fafc; padding: 0.5rem 0.8rem; font-weight: 700; border-radius: 4px 4px 0 0; border-bottom: 1px solid var(--border-color); color: var(--accent-blue); display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.2s;"
                onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
                
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.3s; transform: rotate(0deg);">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                    <span style="font-size: <?= $parent_id == 0 ? '0.95rem' : '0.85rem' ?>;"><?= htmlspecialchars($cat['name']) ?></span>
                </div>

                <div style="display: flex; align-items: center; gap: 15px;">
                    <?php 
                    $stats = $category_stats[$cat_id] ?? ['total' => 0, 'approved' => 0];
                    $t = $stats['total'] ?: 1;
                    $p = round(($stats['approved'] / $t) * 100);
                    ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 80px; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; border: 1px solid #f1f5f9;">
                            <div style="width: <?= $p ?>%; height: 100%; background: var(--accent-blue); transition: width 0.5s;"></div>
                        </div>
                        <span style="font-weight: 700; font-size: 0.75rem; color: var(--accent-blue);"><?= $p ?>%</span>
                        <span style="font-weight: normal; font-size: 0.75rem; color: var(--text-secondary);">(<?= $stats['approved'] ?>/<?= $stats['total'] ?>)</span>
                    </div>
                    
                    <?php if ($is_qao): ?>
                    <!-- Category Triple Dot Menu -->
                    <div style="position: relative;" onclick="event.stopPropagation()">
                        <button onclick="toggleLocalMenu(this)" style="background: transparent; border: none; padding: 4px; cursor: pointer; color: var(--text-secondary); border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle>
                            </svg>
                        </button>
                        <div class="local-dropdown" style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 160px; z-index: 100; overflow: hidden;">
                            <button onclick="openModalWithContext('addCategoryModal', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>', 'cat')" style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                Add Sub-category
                            </button>
                            <button onclick="openModalWithContext('addRequirementModal', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>', 'req')" style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                                Add Requirement
                            </button>
                            <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 0;">
                            <button onclick="openEditModal('category', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>', '', '<?= $parent_id ?>', '<?= addslashes($categories_by_parent[$parent_id][0]['name'] ?? 'Top Level') ?>')" style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                Edit Category
                            </button>
                            <button onclick="deleteItem('category', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>')" style="width: 100%; padding: 0.6rem 0.8rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.8rem; color: #ef4444; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='transparent'">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
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
                $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($requirements)) {
                    ?>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 0.5rem;">
                        <?php foreach ($requirements as $req): ?>
                            <li style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 0.85rem; padding: 2px 0;">
                                <div style="display: flex; align-items: flex-start; gap: 8px;">
                                    <?php 
                                    $sub = $submissions[$req['requirement_id']] ?? null; 
                                    $is_approved = ($sub && $sub['status'] === 'Approved');
                                    $cb_color = $sub ? ($sub['status'] === 'Approved' ? '#22c55e' : ($sub['status'] === 'Disapproved' ? '#ef4444' : '#3b82f6')) : '#e2e8f0';
                                    ?>
                                    <div style="width: 18px; height: 18px; border: 2px solid <?= $cb_color ?>; border-radius: 4px; display: flex; align-items: center; justify-content: center; background: <?= $is_approved ? $cb_color : 'transparent' ?>; margin-top: 2px; flex-shrink: 0;">
                                        <?php if ($sub): ?>
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="<?= $is_approved ? 'white' : $cb_color ?>" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                    <div onclick="handleRequirementClick(<?= $req['requirement_id'] ?>, '<?= addslashes($req['name']) ?>', '<?= addslashes($req['codename'] ?? '') ?>', <?= htmlspecialchars(json_encode($sub)) ?>)" style="cursor: pointer;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                                        <?php if (!empty($req['codename'])): ?>
                                            <span style="font-weight: 700; color: var(--accent-blue); <?= $is_approved ? 'text-decoration: line-through; opacity: 0.7;' : '' ?>"><?= htmlspecialchars($req['codename']) ?>:</span>
                                        <?php endif; ?>
                                        <span style="<?= $is_approved ? 'opacity: 0.7;' : '' ?>"><?= htmlspecialchars($req['name']) ?></span>
                                    </div>
                                </div>
                                <?php if ($is_qao): ?>
                                <div style="position: relative;">
                                    <button onclick="toggleActionMenu(event, 'req_menu_<?= $req['requirement_id'] ?>')" style="background: transparent; border: none; padding: 4px; cursor: pointer; color: var(--text-secondary); border-radius: 4px;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle>
                                        </svg>
                                    </button>
                                    <div id="req_menu_<?= $req['requirement_id'] ?>" class="local-dropdown" style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 140px; z-index: 100; overflow: hidden;">
                                        <button onclick="openEditModal('requirement', '<?= $req['requirement_id'] ?>', '<?= addslashes($req['name']) ?>', '<?= addslashes($req['codename']) ?>', '<?= $cat_id ?>', '<?= addslashes($cat['name']) ?>')" style="width: 100%; padding: 0.5rem 0.7rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.75rem; display: flex; align-items: center; gap: 6px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            Edit
                                        </button>
                                        <button onclick="deleteItem('requirement', '<?= $req['requirement_id'] ?>', '<?= addslashes($req['name']) ?>')" style="width: 100%; padding: 0.5rem 0.7rem; border: none; background: transparent; text-align: left; cursor: pointer; font-size: 0.75rem; display: flex; align-items: center; gap: 6px; color: #ef4444;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='transparent'">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            Delete
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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

<main class="hero" style="min-height: calc(100vh - 200px); align-items: flex-start; padding: 2rem 5%;">
    <div style="display: flex; gap: 2rem; width: 100%; max-width: 1200px; margin: 0 auto;">

        <!-- Sidebar: Accreditations List -->
        <aside id="accSidebar"
            style="width: 300px; background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); height: fit-content; transition: all 0.3s ease;">
            <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 1.2rem; color: var(--accent-blue); margin: 0;">Accreditations</h2>
                    <?php if ($is_qao): ?>
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
                    <?php endif; ?>
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
                            <button onclick="deleteItem('accreditation', '<?= $selected_id ?>', '<?= addslashes($current_acc['name']) ?>')"
                                style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: #ef4444;"
                                onmouseover="this.style.background='#fef2f2'"
                                onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
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
                            <?= htmlspecialchars($current_acc['description']) ?></p>
                    </div>

                    <div style="display: flex; gap: 2rem; align-items: center;">
                        <div style="flex: 1;">
                            <?php 
                            $total = $overall_stats['total'] ?: 1;
                            $percent = round(($overall_stats['approved'] / $total) * 100);
                            ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 0.5rem;">
                                <span style="font-weight: 600;">Overall Progress</span>
                                <span style="font-weight: 700; color: var(--accent-blue);"><?= $percent ?>%</span>
                            </div>
                            <div style="height: 10px; background: #e2e8f0; border-radius: 6px; overflow: hidden; border: 1px solid #f1f5f9;">
                                <div style="width: <?= $percent ?>%; height: 100%; background: var(--accent-blue); transition: width 1s ease-in-out; box-shadow: 0 0 10px rgba(59, 130, 246, 0.3);"></div>
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
                <input type="text" name="code" value="<?= htmlspecialchars($current_acc['code'] ?? '') ?>" required class="form-control">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($current_acc['name'] ?? '') ?>" required class="form-control">
            </div>

            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Description</label>
                <textarea name="description" rows="3" class="form-control" style="resize: vertical;"><?= htmlspecialchars($current_acc['description'] ?? '') ?></textarea>
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
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Parent Category (Optional)</label>
                <div id="cat_parentSelectorsContainer" style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <select class="form-control parent-cat-select" onchange="handleCascadingSelect(this, 'cat_parentSelectorsContainer', 'cat_final_parent_id')">
                        <option value="">None (Top Level)</option>
                        <?php foreach ($categories_by_parent[0] ?? [] as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="parent_category_id" id="cat_final_parent_id" value="">
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Select the parent category if this is a sub-category.</p>
            </div>

            <div id="cat_items_container">
                <div class="item-row" style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category Name *</label>
                    <input type="text" name="name[]" required placeholder="e.g. Governance, Facilities..." class="form-control">
                </div>
            </div>
            
            <button type="button" onclick="addItemRow('cat_items_container', 'category')" class="btn btn-secondary" style="width: 100%; margin-bottom: 1rem; padding: 0.6rem;">+ Add Another Category</button>
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
                    <select class="form-control parent-cat-select" onchange="handleCascadingSelect(this, 'req_parentSelectorsContainer', 'req_final_parent_id')">
                        <option value="">Select Category...</option>
                        <?php foreach ($categories_by_parent[0] ?? [] as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="category_id" id="req_final_parent_id" required value="">
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Select which category these requirements belong to.</p>
            </div>

            <div id="req_items_container">
                <div class="item-row" style="display: flex; gap: 1rem; margin-bottom: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px dashed var(--border-color);">
                    <div style="flex: 1;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Codename</label>
                        <input type="text" name="codename[]" placeholder="1.1" class="form-control">
                    </div>
                    <div style="flex: 2;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Requirement Name *</label>
                        <input type="text" name="name[]" required placeholder="Description..." class="form-control">
                    </div>
                </div>
            </div>
            
            <button type="button" onclick="addItemRow('req_items_container', 'requirement')" class="btn btn-secondary" style="width: 100%; margin-bottom: 1rem; padding: 0.6rem;">+ Add Another Requirement</button>
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
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Parent Category (Optional)</label>
                <div id="edit_cat_parentSelectorsContainer" style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <select class="form-control parent-cat-select" onchange="handleCascadingSelect(this, 'edit_cat_parentSelectorsContainer', 'edit_cat_final_parent_id')">
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
<div id="editRequirementModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
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
                    <select class="form-control parent-cat-select" onchange="handleCascadingSelect(this, 'edit_req_parentSelectorsContainer', 'edit_req_final_parent_id')">
                        <option value="">Select Category...</option>
                        <?php foreach ($categories_by_parent[0] ?? [] as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="category_id" id="edit_req_final_parent_id" required value="">
            </div>

            <div style="margin-bottom: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px dashed var(--border-color);">
                <div style="margin-bottom: 1rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Codename</label>
                    <input type="text" name="codename" id="edit_req_codename" placeholder="1.1" class="form-control">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.85rem;">Requirement Name *</label>
                    <input type="text" name="name" id="edit_req_name" required placeholder="Description..." class="form-control">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Save Changes</button>
        </form>
    </div>
</div>

<!-- Requirement Upload Modal -->
<div id="uploadRequirementModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 id="upload_req_title" style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Upload File</h2>
            <button onclick="document.getElementById('uploadRequirementModal').style.display='none'" 
                    style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        
        <form id="uploadForm" onsubmit="handleFileUpload(event)">
            <input type="hidden" name="requirement_id" id="upload_req_id">
            
            <div id="dropZone" style="border: 2px dashed var(--border-color); border-radius: 8px; padding: 2rem; text-align: center; margin-bottom: 1.5rem; cursor: pointer; transition: all 0.3s;"
                 onmouseover="this.style.borderColor='var(--accent-blue)'; this.style.background='#f8fafc'"
                 onmouseout="this.style.borderColor='var(--border-color)'; this.style.background='transparent'"
                 onclick="document.getElementById('fileInput').click()">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 1rem;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                <p style="margin: 0; font-weight: 500;">Click to select or drag and drop</p>
                <p style="margin: 0.5rem 0 0 0; font-size: 0.8rem; color: var(--text-secondary);">PDF files only (Multiple allowed)</p>
                <input type="file" id="fileInput" name="files[]" multiple accept="application/pdf" style="display: none;" onchange="updateFileInfo(this)">
            </div>
            
            <div id="fileInfo" style="display: none; margin-bottom: 1.5rem; padding: 0.8rem; background: #eff6ff; border-radius: 6px; border: 1px solid #bfdbfe; font-size: 0.9rem; color: #1e40af;">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; font-weight: 600; margin-bottom: 5px; border-bottom: 1px solid #bfdbfe; padding-bottom: 5px;">
                        <span>Selected Files:</span>
                        <button type="button" onclick="clearFile()" style="background: transparent; border: none; color: #ef4444; cursor: pointer; font-weight: bold;">&times;</button>
                    </div>
                    <div id="fileList"></div>
                </div>
            </div>
            
            <button type="submit" id="uploadBtn" class="btn btn-primary" style="width: 100%; padding: 1rem; display: flex; align-items: center; justify-content: center; gap: 10px;">
                <span>Start Upload</span>
            </button>
        </form>
    </div>
</div>

<!-- Review Submission Modal -->
<div id="reviewSubmissionModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 900px; width: 90%; height: 80vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 id="review_title" style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Review Submission</h2>
            <button onclick="document.getElementById('reviewSubmissionModal').style.display='none'" 
                    style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        
        <div style="display: flex; gap: 2rem; flex: 1; overflow: hidden;">
            <!-- File Preview -->
            <div style="flex: 2; background: #f1f5f9; border-radius: 8px; overflow: hidden; position: relative;">
                <iframe id="preview_frame" src="" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
            
            <!-- Details and Actions -->
            <div style="flex: 1; display: flex; flex-direction: column; gap: 1.5rem; overflow-y: auto; padding-right: 10px;">
                <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                    <h3 style="font-size: 0.9rem; margin: 0 0 1rem 0; color: var(--accent-blue);">Submission Details</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px; font-size: 0.85rem;">
                        <div><strong>Uploaded by:</strong> <span id="rev_user"></span></div>
                        <div><strong>Division:</strong> <span id="rev_division"></span></div>
                        <div><strong>Office:</strong> <span id="rev_office"></span></div>
                        <div><strong>Date:</strong> <span id="rev_date"></span></div>
                        <div><strong>Status:</strong> <span id="rev_status_badge" class="user-badge" style="padding: 2px 8px; font-size: 0.7rem;"></span></div>
                        <div id="rev_marker_container" style="display: none; margin-top: 5px; padding-top: 5px; border-top: 1px dashed #e2e8f0;">
                            <strong>Marked by:</strong> <span id="rev_marker"></span>
                        </div>
                    </div>
                </div>
                
                <div id="remarks_container" style="display: none;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.9rem;">Review Remarks</label>
                    <div id="remarks_display" style="display: none; padding: 10px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 1rem;"></div>
                    <textarea id="review_remarks" rows="5" class="form-control" placeholder="Add feedback or reasons for disapproval..." style="resize: none; font-size: 0.9rem;"></textarea>
                </div>
                
                <div style="margin-top: auto; display: flex; flex-direction: column; gap: 10px;">
                    <?php if ($is_qao): ?>
                    <div id="review_actions" style="display: flex; gap: 10px;">
                        <button onclick="submitReview('Approved')" class="btn btn-success" style="flex: 1; padding: 0.8rem; background: #22c55e;">Approve</button>
                        <button onclick="submitReview('Disapproved')" class="btn btn-danger" style="flex: 1; padding: 0.8rem; background: #ef4444;">Disapprove</button>
                    </div>
                    <?php endif; ?>
                    
                    <div id="uploader_actions" style="display: none; gap: 10px;">
                        <button onclick="reopenUpload()" class="btn btn-secondary" style="flex: 2; padding: 0.8rem;">Update / Replace Files</button>
                        <button onclick="removeSubmission()" class="btn btn-danger" style="flex: 1; padding: 0.8rem; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca;">Remove</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let currentRequirement = null;
    const currentUserId = <?= $_SESSION['user_id'] ?? 0 ?>;

    function handleRequirementClick(id, name, codename, sub) {
        currentRequirement = { id, name, codename, sub };
        if (sub) {
            openReviewModal(sub, name);
        } else {
            openUploadModal(id, name, codename);
        }
    }

    function openReviewModal(sub, name) {
        document.getElementById('review_title').textContent = name;
        
        // Show uploader actions only if it's the user's own submission AND it's not already Approved
        const uploaderActions = document.getElementById('uploader_actions');
        if (sub.user_id == currentUserId && sub.status !== 'Approved') {
            uploaderActions.style.display = 'flex';
        } else {
            uploaderActions.style.display = 'none';
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
        
        document.getElementById('rev_user').textContent = sub.fname + ' ' + sub.lname;
        document.getElementById('rev_division').textContent = sub.division_name || 'N/A';
        document.getElementById('rev_office').textContent = sub.office_name || 'N/A';
        
        // Format date: Date and hour:minute PM/AM
        const date = new Date(sub.created_at);
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
            newSelect.onchange = function() { handleCascadingSelect(this, containerId, inputId); };
            
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

    function openUploadModal(id, name, codename = '') {
        document.getElementById('upload_req_id').value = id;
        document.getElementById('upload_req_title').textContent = name;
        document.getElementById('upload_req_title').dataset.codename = codename;
        clearFile();
        openModal('uploadRequirementModal');
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
        const actionMenu = document.getElementById('accActionMenu');
        const allLocalMenus = document.querySelectorAll('.local-dropdown');

        if (event.target == accModal) accModal.style.display = "none";
        if (event.target == editAccModal) editAccModal.style.display = "none";
        if (event.target == catModal) catModal.style.display = "none";
        if (event.target == reqModal) reqModal.style.display = "none";
        if (event.target == statusModal) statusModal.style.display = "none";
        if (event.target == editCatModal) editCatModal.style.display = "none";
        if (event.target == editReqModal) editReqModal.style.display = "none";

        if (actionMenu && !actionMenu.contains(event.target)) {
            actionMenu.style.display = 'none';
        }

        // Close local dropdowns when clicking outside
        if (!event.target.closest('button')) {
            allLocalMenus.forEach(m => m.style.display = 'none');
        }
    }
</script>