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

    // Fetch categories and their requirements
    $stmt = $db->prepare("
        SELECT c.*, 
               (SELECT COUNT(*) FROM accreditation_requirement r WHERE r.category_id = c.category_id) as req_count
        FROM accreditation_categories c 
        WHERE c.accreditation_id = :acc_id 
        ORDER BY c.parent_category_id ASC, c.name ASC
    ");
    $stmt->execute(['acc_id' => $selected_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<main class="hero" style="min-height: calc(100vh - 200px); align-items: flex-start; padding: 2rem 5%;">
    <div style="display: flex; gap: 2rem; width: 100%; max-width: 1200px; margin: 0 auto;">
        
        <!-- Sidebar: Accreditations List -->
        <aside style="width: 300px; background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); height: fit-content;">
            <h2 style="font-size: 1.2rem; margin-bottom: 1.5rem; color: var(--accent-blue);">Accreditations</h2>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <?php if (empty($accreditations)): ?>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">No accreditations found.</p>
                <?php else: ?>
                    <?php foreach ($accreditations as $acc): ?>
                        <a href="feed.php?action=accreditation&accreditation_id=<?= $acc['accreditation_id'] ?>" 
                           style="padding: 0.8rem; border-radius: 4px; text-decoration: none; color: <?= $selected_id == $acc['accreditation_id'] ? 'white' : 'var(--text-secondary)' ?>; background: <?= $selected_id == $acc['accreditation_id'] ? 'var(--accent-blue)' : 'transparent' ?>; border: 1px solid <?= $selected_id == $acc['accreditation_id'] ? 'var(--accent-blue)' : 'var(--border-color)' ?>; transition: all 0.3s;"
                           onmouseover="if(<?= $selected_id ?> != <?= $acc['accreditation_id'] ?>) this.style.backgroundColor='#f8fafc'"
                           onmouseout="if(<?= $selected_id ?> != <?= $acc['accreditation_id'] ?>) this.style.backgroundColor='transparent'">
                            <div style="font-weight: 600; font-size: 0.95rem;"><?= htmlspecialchars($acc['acronym']) ?></div>
                            <div style="font-size: 0.8rem; opacity: 0.8;"><?= htmlspecialchars($acc['name']) ?></div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content: Selected Accreditation Details -->
        <div style="flex: 1; background: white; padding: 2.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <?php if ($current_acc): ?>
                <div style="margin-bottom: 2rem; border-bottom: 2px solid #f1f5f9; padding-bottom: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                        <div>
                            <h1 style="color: var(--accent-blue); margin-bottom: 0.5rem;"><?= htmlspecialchars($current_acc['name']) ?></h1>
                            <p style="color: var(--text-secondary);"><?= htmlspecialchars($current_acc['description']) ?></p>
                        </div>
                        <span class="user-badge" style="background: <?= $current_acc['status'] === 'Completed' ? '#22c55e' : 'var(--accent-gold)' ?>">
                            <?= htmlspecialchars($current_acc['status']) ?>
                        </span>
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
                        <div style="font-size: 0.9rem; color: var(--text-secondary);">
                            <strong>Deadline:</strong> <?= date('M d, Y', strtotime($current_acc['deadline'])) ?>
                        </div>
                    </div>
                </div>

                <!-- Categories and Requirements -->
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <?php if (empty($categories)): ?>
                        <p style="text-align: center; color: var(--text-secondary); padding: 3rem;">No categories defined for this accreditation.</p>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <?php if ($cat['parent_category_id'] === null): ?>
                                <div style="border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;">
                                    <div style="background: #f8fafc; padding: 1rem 1.5rem; font-weight: 700; border-bottom: 1px solid var(--border-color); color: var(--accent-blue); display: flex; justify-content: space-between;">
                                        <span><?= htmlspecialchars($cat['name']) ?></span>
                                        <span style="font-weight: normal; font-size: 0.85rem; color: var(--text-secondary);"><?= $cat['req_count'] ?> Requirements</span>
                                    </div>
                                    <div style="padding: 1rem 1.5rem;">
                                        <!-- Requirements would be listed here -->
                                        <?php
                                        $stmt = $db->prepare("SELECT * FROM accreditation_requirement WHERE category_id = :cat_id");
                                        $stmt->execute(['cat_id' => $cat['category_id']]);
                                        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        ?>
                                        <?php if (empty($requirements)): ?>
                                            <p style="color: var(--text-secondary); font-style: italic; font-size: 0.9rem;">No requirements found in this category.</p>
                                        <?php else: ?>
                                            <ul style="list-style: none; display: flex; flex-direction: column; gap: 0.8rem;">
                                                <?php foreach ($requirements as $req): ?>
                                                    <li style="display: flex; align-items: center; gap: 10px; font-size: 0.95rem;">
                                                        <input type="checkbox" disabled style="width: 18px; height: 18px;">
                                                        <span><?= htmlspecialchars($req['name']) ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
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