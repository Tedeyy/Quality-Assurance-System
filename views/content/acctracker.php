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

    // Group categories by parent
    $categories_by_parent = [];
    foreach ($categories as $cat) {
        $parent_id = $cat['parent_category_id'] ?: 0;
        $categories_by_parent[$parent_id][] = $cat;
    }

    // Helper to calculate total requirements (including nested)
    $total_counts = [];
    function calculateTotalReqs($parent_id, $categories_by_parent, $direct_counts, &$total_counts) {
        if (!isset($categories_by_parent[$parent_id])) return 0;
        
        foreach ($categories_by_parent[$parent_id] as $cat) {
            $cat_id = $cat['category_id'];
            $direct = $direct_counts[$cat_id] ?? 0;
            $sub_total = calculateTotalReqs($cat_id, $categories_by_parent, $direct_counts, $total_counts);
            $total_counts[$cat_id] = $direct + $sub_total;
        }
        
        // Sum up all top-level for the parent
        $sum = 0;
        foreach ($categories_by_parent[$parent_id] as $cat) {
            $sum += $total_counts[$cat['category_id']];
        }
        return $sum;
    }
    calculateTotalReqs(0, $categories_by_parent, $direct_counts, $total_counts);
}

function renderCategories($parent_id, $categories_by_parent, $db, $total_counts) {
    if (!isset($categories_by_parent[$parent_id])) return;

    foreach ($categories_by_parent[$parent_id] as $cat) {
        $cat_id = $cat['category_id'];
        $total_reqs = $total_counts[$cat_id] ?? 0;
        ?>
        <div style="border: 1px solid var(--border-color); border-radius: 4px; overflow: hidden; margin-bottom: 0.4rem; margin-left: <?= $parent_id == 0 ? '0' : '1rem' ?>;">
            <!-- Category Header (Toggle) -->
            <div onclick="toggleCategory(this)" 
                 style="background: #f8fafc; padding: 0.5rem 0.8rem; font-weight: 700; border-bottom: 1px solid var(--border-color); color: var(--accent-blue); display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: background 0.2s;"
                 onmouseover="this.style.background='#f1f5f9'"
                 onmouseout="this.style.background='#f8fafc'">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.3s; transform: rotate(0deg);">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                    <span style="font-size: <?= $parent_id == 0 ? '0.95rem' : '0.85rem' ?>;"><?= htmlspecialchars($cat['name']) ?></span>
                </div>
                <span style="font-weight: normal; font-size: 0.75rem; color: var(--text-secondary);"><?= $total_reqs ?> Requirements</span>
            </div>

            <!-- Category Content (Hidden by default) -->
            <div class="category-content" style="display: none; padding: 0.6rem 0.8rem;">
                <?php
                // Render Requirements
                $stmt = $db->prepare("SELECT * FROM accreditation_requirement WHERE category_id = :cat_id");
                $stmt->execute(['cat_id' => $cat['category_id']]);
                $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($requirements)) {
                    ?>
                    <ul style="list-style: none; display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 0.5rem;">
                        <?php foreach ($requirements as $req): ?>
                            <li style="display: flex; align-items: flex-start; gap: 8px; font-size: 0.85rem;">
                                <input type="checkbox" disabled style="width: 14px; height: 14px; margin-top: 2px;">
                                <div>
                                    <?php if (!empty($req['codename'])): ?>
                                        <span style="font-weight: 700; color: var(--accent-blue);"><?= htmlspecialchars($req['codename']) ?>:</span>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($req['name']) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php
                }

                // Render Sub-categories
                renderCategories($cat['category_id'], $categories_by_parent, $db, $total_counts);
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
        <aside id="accSidebar" style="width: 300px; background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); height: fit-content; transition: all 0.3s ease;">
            <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 1.2rem; color: var(--accent-blue); margin: 0;">Accreditations</h2>
                    <button onclick="document.getElementById('addAccreditationModal').style.display='flex'" 
                            style="background: var(--accent-blue); color: white; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: transform 0.2s;"
                            onmouseover="this.style.transform='scale(1.1)'"
                            onmouseout="this.style.transform='scale(1)'"
                            title="Add New Accreditation">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </button>
                </div>
                <!-- Search Bar -->
                <div style="position: relative;">
                    <input type="text" id="accSearch" placeholder="Search accreditations..." 
                           style="width: 100%; padding: 0.6rem 0.8rem 0.6rem 2.2rem; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem; outline: none;"
                           onkeyup="filterAccreditations()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%);">
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
                           class="acc-item"
                           data-code="<?= strtolower($acc['code']) ?>"
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
        <!-- Main Content: Selected Accreditation Details -->
        <div id="accMainContent" style="flex: 1; background: white; padding: 2.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); transition: all 0.3s ease; position: relative;">
            <?php if ($current_acc): ?>
                <!-- Top Right Action Buttons -->
                <div style="position: absolute; top: 1.5rem; right: 1.5rem; display: flex; gap: 8px; align-items: center; z-index: 10;">
                    <button onclick="toggleMaximize()" 
                            id="maximizeBtn"
                            style="background: transparent; border: none; padding: 5px; cursor: pointer; color: var(--text-secondary); border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: background 0.2s;"
                            onmouseover="this.style.background='#f1f5f9'"
                            onmouseout="this.style.background='transparent'"
                            title="Toggle Maximize">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>
                        </svg>
                    </button>

                    <div style="position: relative;">
                        <button onclick="toggleActionMenu(event)" 
                                style="background: transparent; border: none; padding: 5px; cursor: pointer; color: var(--text-secondary); border-radius: 4px; display: flex; align-items: center; justify-content: center; transition: background 0.2s;"
                                onmouseover="this.style.background='#f1f5f9'"
                                onmouseout="this.style.background='transparent'">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1"></circle>
                                <circle cx="12" cy="5" r="1"></circle>
                                <circle cx="12" cy="19" r="1"></circle>
                            </svg>
                        </button>
                    
                        <div id="accActionMenu" style="display: none; position: absolute; right: 0; top: 100%; background: white; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); width: 180px; z-index: 100; overflow: hidden; margin-top: 5px;">
                            <button onclick="openModal('changeStatusModal')" style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-primary);" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.66V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h5.34"></path><polygon points="18 2 22 6 12 16 8 16 8 12 18 2"></polygon></svg>
                                Change Status
                            </button>
                            <button onclick="openModal('addCategoryModal')" style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-primary);" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                Add Category
                            </button>
                            <button onclick="openModal('addRequirementModal')" style="width: 100%; padding: 0.8rem 1rem; border: none; background: transparent; text-align: left; cursor: pointer; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; color: var(--text-primary);" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                                Add Requirement
                            </button>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 2rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 1.5rem;">
                    <div style="margin-bottom: 1rem; padding-right: 60px;">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 0.5rem;">
                            <h1 style="color: var(--accent-blue); margin: 0;"><?= htmlspecialchars($current_acc['name']) ?></h1>
                            <?php
                                $status_color = '#94a3b8'; // Default Gray
                                if ($current_acc['status'] === 'In Progress') $status_color = '#3b82f6'; // Blue
                                if ($current_acc['status'] === 'Completed') $status_color = '#22c55e'; // Green
                            ?>
                            <span class="user-badge" style="background: <?= $status_color ?>; font-size: 0.75rem; padding: 4px 12px; color: white;">
                                <?= htmlspecialchars($current_acc['status']) ?>
                            </span>
                        </div>
                        <p style="color: var(--text-secondary); margin: 0;"><?= htmlspecialchars($current_acc['description']) ?></p>
                    </div>
                    
                    <div style="display: flex; gap: 2rem; align-items: center;">
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 0.5rem;">
                                <span style="font-weight: 600;">Overall Progress</span>
                                <span>0%</span>
                            </div>
                            <div style="height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                <div style="width: 0%; height: 100%; background: var(--accent-blue);"></div>
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
                        <p style="text-align: center; color: var(--text-secondary); padding: 3rem;">No categories defined for this accreditation.</p>
                    <?php else: ?>
                        <?php renderCategories(0, $categories_by_parent, $db, $total_counts); ?>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div style="text-align: center; padding: 5rem 0;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--border-color)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 1.5rem;">
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
<div id="addAccreditationModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
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
                <input type="text" name="name" required placeholder="e.g. Quality Management System" class="form-control">
            </div>
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Description</label>
                <textarea name="description" rows="3" placeholder="Brief overview..." class="form-control" style="resize: vertical;"></textarea>
            </div>
            
            <div id="deadline_container" style="margin-bottom: 1rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Deadline *</label>
                <input type="date" id="acc_deadline" name="deadline" class="form-control">
            </div>
            
            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Initial Status *</label>
                <select id="acc_status" name="status" required class="form-control" onchange="toggleDeadline(this.value)">
                    <option value="In Progress">In Progress</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Create Accreditation</button>
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
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category Name *</label>
                <input type="text" name="name" required placeholder="e.g. Governance, Facilities..." class="form-control">
            </div>
            
            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Parent Category (Optional)</label>
                <select name="parent_category_id" class="form-control">
                    <option value="">None (Top Level)</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Leave empty to create a main category.</p>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Add Category</button>
        </form>
    </div>
</div>

<!-- Add Requirement Modal -->
<div id="addRequirementModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 450px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0;">Add Requirement</h2>
            <button onclick="document.getElementById('addRequirementModal').style.display='none'" 
                    style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        
        <form action="../api/accreditation.php?action=add_requirement" method="POST">
            <input type="hidden" name="accreditation_id" value="<?= $selected_id ?>">
            
            <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Codename</label>
                    <input type="text" name="codename" placeholder="e.g. 1.1, A.1..." class="form-control">
                </div>
                <div style="flex: 2;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Requirement Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Certificate of Compliance..." class="form-control">
                </div>
            </div>
            
            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Category *</label>
                <select name="category_id" required class="form-control">
                    <option value="">Select Category...</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Select which category this requirement belongs to.</p>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Add Requirement</button>
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
                    <option value="In Progress" <?= $current_acc['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Inactive" <?= $current_acc['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="Completed" <?= $current_acc['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                </select>
            </div>
            
            <div id="status_deadline_container" style="margin-bottom: 2rem; display: <?= $current_acc['status'] === 'In Progress' ? 'block' : 'none' ?>;">
                <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">New Deadline</label>
                <input type="date" name="deadline" value="<?= $current_acc['deadline'] ?>" class="form-control">
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Set a target completion date.</p>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem;">Update Status</button>
        </form>
    </div>
</div>

<script>
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
        
        if (content.style.display === 'none') {
            content.style.display = 'block';
            chevron.style.transform = 'rotate(90deg)';
        } else {
            content.style.display = 'none';
            chevron.style.transform = 'rotate(0deg)';
        }
    }

    function toggleActionMenu(e) {
        e.stopPropagation();
        const menu = document.getElementById('accActionMenu');
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
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

    // Close modals and menus on click outside
    window.onclick = function(event) {
        const accModal = document.getElementById('addAccreditationModal');
        const catModal = document.getElementById('addCategoryModal');
        const reqModal = document.getElementById('addRequirementModal');
        const statusModal = document.getElementById('changeStatusModal');
        const actionMenu = document.getElementById('accActionMenu');
        
        if (event.target == accModal) accModal.style.display = "none";
        if (event.target == catModal) catModal.style.display = "none";
        if (event.target == reqModal) reqModal.style.display = "none";
        if (event.target == statusModal) statusModal.style.display = "none";
        
        if (actionMenu && !actionMenu.contains(event.target)) {
            actionMenu.style.display = 'none';
        }
    }
</script>