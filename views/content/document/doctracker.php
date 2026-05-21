<?php
require_once __DIR__ . '/../../../config/database.php';
$db = (new Database())->getConnection();

// Fetch all documents with their tags list
$query = "
    SELECT d.*, 
           GROUP_CONCAT(t.tag_name SEPARATOR ', ') as tags_list
    FROM documents d
    LEFT JOIN document_tags dt ON d.doc_id = dt.doc_id
    LEFT JOIN tags t ON dt.tag_id = t.tag_id
    GROUP BY d.doc_id
    ORDER BY d.created_at DESC
";
$documents = [];
try {
    $stmt = $db->query($query);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("doctracker documents query failed: " . $e->getMessage());
}

// Fetch distinct categories for the dropdown and tabs
$db_categories = [];
try {
    $cat_stmt = $db->query("SELECT DISTINCT category FROM documents WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
    $db_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("doctracker categories query failed: " . $e->getMessage());
}
$default_categories = ['Policy', 'Manual', 'Guidelines', 'SOP', 'Form', 'Report', 'Minutes', 'Contract'];
$categories = array_unique(array_merge($default_categories, $db_categories));
sort($categories);

// Fetch distinct offices for the dropdown filter
$offices = [];
try {
    $office_stmt = $db->query("SELECT DISTINCT office_of_origin FROM documents ORDER BY office_of_origin ASC");
    $offices = $office_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("doctracker offices filter query failed: " . $e->getMessage());
}

// Fetch all divisions/offices from system to populate the Add form
$sys_offices = [];
try {
    $sys_offices_stmt = $db->query("SELECT office_id, name, acronym FROM divisions_offices ORDER BY name ASC");
    $sys_offices = $sys_offices_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("doctracker sys offices query failed: " . $e->getMessage());
}

// Fetch all existing tags for the datalist drop-down list
$existing_tags = [];
try {
    $all_tags_stmt = $db->query("SELECT tag_name FROM tags ORDER BY tag_name ASC");
    $existing_tags = $all_tags_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("doctracker tags query failed: " . $e->getMessage());
}

// Confidentiality Labels
$confidentiality_levels = [
    1 => ['label' => 'Public', 'color' => '#10b981', 'bg' => '#ecfdf5', 'icon' => ''],
    2 => ['label' => 'Internal', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => ''],
    3 => ['label' => 'Restricted', 'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => ''],
    4 => ['label' => 'Confidential', 'color' => '#f97316', 'bg' => '#fff7ed', 'icon' => ''],
    5 => ['label' => 'Strictly Confidential', 'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => '']
];
?>

<style>
    :root {
        --accent-blue: #001C57;
        --accent-gold: #DFB641;
        --border-color: #e2e8f0;
        --text-primary: #1e293b;
        --text-secondary: #64748b;
    }

    body {
        background-color: #f8fafc;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
    }

    .qa-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
    }

    .category-tab {
        padding: 10px 20px;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        white-space: nowrap;
        transition: all 0.2s;
    }

    .category-tab:hover {
        background: #f8fafc;
        color: var(--accent-blue);
        border-color: #cbd5e1;
    }

    .category-tab.active {
        background: var(--accent-blue);
        color: white;
        border-color: var(--accent-blue);
        box-shadow: 0 4px 12px rgba(0, 28, 87, 0.2);
    }

    /* Action Dropdown Styles */
    .action-dropdown {
        position: relative;
        display: inline-block;
    }

    .three-dots-btn {
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 8px;
        border-radius: 50%;
        transition: all 0.2s;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .three-dots-btn:hover {
        background: #f1f5f9;
        color: var(--accent-blue);
    }

    .dropdown-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        background: white;
        min-width: 180px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
        border-radius: 12px;
        border: 1px solid var(--border-color);
        z-index: 1000;
        padding: 8px 0;
        margin-top: 5px;
        animation: fadeIn 0.2s ease-out;
    }

    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        color: #334155;
        text-decoration: none;
        font-size: 0.9rem;
        transition: background 0.2s;
        cursor: pointer;
        border: none;
        width: 100%;
        text-align: left;
        background: transparent;
    }

    .dropdown-item:hover {
        background: #f8fafc;
        color: var(--accent-blue);
    }

    .dropdown-item svg {
        color: #94a3b8;
    }

    .dropdown-item:hover svg {
        color: var(--accent-blue);
    }

    .dropdown-item.delete:hover {
        color: #ef4444;
        background: #fef2f2;
    }

    .dropdown-item.delete:hover svg {
        color: #ef4444; 
    }

    .tag-badge {
        font-size: 0.75rem;
        background: #f1f5f9;
        color: #475569;
        padding: 2px 8px;
        border-radius: 4px;
        font-weight: 600;
        display: inline-block;
        margin: 2px;
        border: 1px solid #cbd5e1;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem; padding-bottom: 3rem;">
    <div class="container" style="max-width: 1300px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Header Section -->
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem;">
            <div>
                <h1 style="font-size: 2rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 12px; color: var(--accent-blue);">
                    <div style="background: var(--accent-blue); color: white; padding: 8px; border-radius: 10px; display: flex;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    Document Mapping
                </h1>
                <p style="color: var(--text-secondary); font-size: 0.95rem; font-weight: 500;">Map, categorize, and discover document overlap using dynamic similarity scoring metrics.</p>
            </div>
            
            <button class="btn btn-primary" onclick="document.getElementById('addDocModal').style.display='flex'" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; padding: 12px 24px; font-weight: 700; border-radius: 8px; cursor: pointer; box-shadow: 0 4px 12px rgba(0, 28, 87, 0.15);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Document
            </button>
        </div>

        <!-- Stats Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
            <div class="qa-card" style="padding: 1.5rem;">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Total Mapped Documents</span>
                <div id="stat-total-docs" style="font-size: 2.2rem; font-weight: 800; color: var(--accent-blue); margin-top: 5px;"><?= count($documents) ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #10b981; display: flex; align-items: center; gap: 4px; font-weight: 600;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    Fully indexed
                </div>
            </div>
            
            <div class="qa-card" style="padding: 1.5rem;">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Registered Classifications</span>
                <div style="font-size: 2.2rem; font-weight: 800; color: var(--accent-gold); margin-top: 5px;"><?= count($categories) ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #64748b; font-weight: 500;">Unique categories registered</div>
            </div>

            <div class="qa-card" style="padding: 1.5rem;">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Confidential Archives</span>
                <?php
                    $restricted_cnt = 0;
                    foreach ($documents as $d) {
                        if ($d['confidentiality'] >= 3) $restricted_cnt++;
                    }
                ?>
                <div style="font-size: 2.2rem; font-weight: 800; color: #f97316; margin-top: 5px;"><?= $restricted_cnt ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #64748b; font-weight: 500;">Restricted or above status</div>
            </div>
        </div>

        <!-- Dynamic Category Tabs -->
        <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
            <button class="category-tab active" id="all-categories-tab" onclick="filterByCategory('all', this)">All Categories</button>
            <div style="width: 250px;">
                <select id="categoryFilterDropdown" onchange="filterByCategoryDropdown(this.value)" style="width: 100%; padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 30px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); outline: none; background: white; cursor: pointer; transition: all 0.2s ease;" onfocus="this.style.borderColor='var(--accent-blue)';" onblur="this.style.borderColor='var(--border-color)';">
                    <option value="">Select Category...</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Filters Block -->
        <div style="background: white; padding: 1rem; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; position: relative; min-width: 250px;">
                <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="documentSearch" oninput="resetPageAndSearch()" placeholder="Search documents by code, office or tags..." style="width: 100%; padding: 0.7rem 0.7rem 0.7rem 2.5rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;">
            </div>
            
            <div style="width: 200px;">
                <select id="officeFilter" onchange="resetPageAndSearch()" style="width: 100%; padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white; cursor: pointer;">
                    <option value="all">All Offices</option>
                    <?php foreach ($offices as $o): ?>
                        <option value="<?= htmlspecialchars($o) ?>"><?= htmlspecialchars($o) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Table Grid -->
        <div style="background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: visible; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Doc Code</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Origin / Category</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Confidentiality</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Tags</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; width: 180px; text-align: center;">Overlap Scoring</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; width: 80px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.8rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <p style="margin: 0; font-weight: 600; font-size: 0.95rem;">No documents mapped yet.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <?php 
                                $cLevel = $doc['confidentiality'];
                                $cData = $confidentiality_levels[$cLevel] ?? ['label' => 'Unknown', 'color' => '#64748b', 'bg' => '#f1f5f9', 'icon' => '❓'];
                            ?>
                            <tr class="doc-row" 
                                data-code="<?= htmlspecialchars($doc['doc_code']) ?>"
                                data-office="<?= htmlspecialchars($doc['office_of_origin']) ?>"
                                data-category="<?= htmlspecialchars($doc['category']) ?>"
                                data-tags="<?= htmlspecialchars($doc['tags_list'] ?? '') ?>">
                                
                                <td style="padding: 1.2rem; font-weight: 800; color: var(--accent-blue); font-size: 0.95rem;">
                                    <?= htmlspecialchars($doc['doc_code']) ?>
                                </td>
                                
                                <td style="padding: 1.2rem;">
                                    <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem; margin-bottom: 4px;"><?= htmlspecialchars($doc['office_of_origin']) ?></div>
                                    <span style="font-size: 0.75rem; background: rgba(0, 28, 87, 0.05); color: var(--accent-blue); padding: 2px 6px; border-radius: 4px; font-weight: 700; text-transform: uppercase;"><?= htmlspecialchars($doc['category']) ?></span>
                                </td>

                                <td style="padding: 1.2rem;">
                                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; color: <?= $cData['color'] ?>; background: <?= $cData['bg'] ?>;">
                                        <span><?= $cData['icon'] ?></span>
                                        <span><?= $cData['label'] ?></span>
                                    </span>
                                </td>

                                <td style="padding: 1.2rem; max-width: 300px;">
                                    <?php if (empty($doc['tags_list'])): ?>
                                        <span style="color: #94a3b8; font-size: 0.8rem; font-style: italic;">No tags</span>
                                    <?php else: ?>
                                        <?php foreach (explode(', ', $doc['tags_list']) as $tag): ?>
                                            <span class="tag-badge"><?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>

                                <td style="padding: 1.2rem; text-align: center;">
                                    <button class="btn btn-secondary" onclick="analyzeSimilarity(<?= $doc['doc_id'] ?>)" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                        Compare Overlap
                                    </button>
                                </td>

                                <td style="padding: 1.2rem; text-align: right;">
                                    <div class="action-dropdown">
                                        <button class="three-dots-btn" onclick="toggleDropdown(<?= $doc['doc_id'] ?>)">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                        </button>
                                        <div id="dropdown-<?= $doc['doc_id'] ?>" class="dropdown-menu">
                                            <button class="dropdown-item" onclick="viewDetails(<?= $doc['doc_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                View Details
                                            </button>
                                            <div style="border-top: 1px solid var(--border-color); margin: 4px 0;"></div>
                                            <button class="dropdown-item" onclick="openEditModal(<?= $doc['doc_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                                Edit Document
                                            </button>
                                            <div style="border-top: 1px solid var(--border-color); margin: 4px 0;"></div>
                                            <button class="dropdown-item delete" onclick="deleteDocument(<?= $doc['doc_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                Delete Document
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div style="padding: 1rem 1.2rem; background: #f8fafc; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; border-radius: 0 0 12px 12px;">
                <div style="font-size: 0.8rem; color: var(--text-secondary);" id="showing-count-container">Showing <b>0 - 0</b> of <b>0</b> documents</div>
                <div id="pagination-controls" style="display: flex; gap: 5px;"></div>
            </div>
        </div>
    </div>
</main>

<!-- Add Document Modal -->
<div id="addDocModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 550px; max-width: 90vw; max-height: 90vh; overflow-y: auto; scrollbar-width: thin; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h2 style="margin: 0; color: #0f172a; font-size: 1.4rem; font-weight: 800;">Register & Map Document</h2>
            <button onclick="document.getElementById('addDocModal').style.display='none'" style="background: transparent; border: none; font-size: 1.8rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <form action="../api/documents.php?action=add" method="POST" style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Document Code *</label>
                <input type="text" name="doc_code" required placeholder="e.g. ISO-2015-QMS-01" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Office of Origin *</label>
                <select name="office_of_origin" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white; cursor: pointer;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                    <option value="">Select Office</option>
                    <?php foreach ($sys_offices as $sys_o): ?>
                        <option value="<?= htmlspecialchars($sys_o['name']) ?>"><?= htmlspecialchars($sys_o['name']) ?> (<?= htmlspecialchars($sys_o['acronym']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Category *</label>
                    <select name="category" required onchange="handleCategoryChange(this, 'add_new_category_container')" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                        <option value="__NEW__">-- Add New Category --</option>
                    </select>
                    <div id="add_new_category_container" style="display: none; margin-top: 0.5rem;">
                        <input type="text" name="new_category" placeholder="Enter new category name..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                    </div>
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Confidentiality Level *</label>
                    <select name="confidentiality" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="1">Level 1 - Public</option>
                        <option value="2">Level 2 - Internal</option>
                        <option value="3">Level 3 - Restricted</option>
                        <option value="4">Level 4 - Confidential</option>
                        <option value="5">Level 5 - Strictly Confidential</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Purpose / Scope of Use</label>
                <textarea name="purpose" rows="3" placeholder="Describe the document's goal, application scope, or purpose..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; resize: vertical;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'"></textarea>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Document Tags</label>
                <div id="tags-inputs-container" style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="tag-input-row" style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" name="tags[]" list="existing-tags-list" placeholder="Select or type a tag..." style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <button type="button" onclick="removeTagInputRow(this)" style="background: #fee2e2; border: 1px solid #fca5a5; color: #ef4444; border-radius: 8px; padding: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; font-weight: 700; transition: background 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">&times;</button>
                    </div>
                </div>
                <button type="button" onclick="addTagInputRow()" style="background: transparent; border: 1.5px dashed var(--accent-blue); color: var(--accent-blue); padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; margin-bottom: 0.5rem; transition: background 0.2s;" onmouseover="this.style.background='rgba(0, 28, 87, 0.04)'" onmouseout="this.style.background='transparent'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Tag Field
                </button>
            </div>

            <datalist id="existing-tags-list">
                <?php foreach ($existing_tags as $et): ?>
                    <option value="<?= htmlspecialchars($et) ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('addDocModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer;">Register Document</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Document Modal -->
<div id="editDocModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 550px; max-width: 90vw; max-height: 90vh; overflow-y: auto; scrollbar-width: thin; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h2 style="margin: 0; color: #0f172a; font-size: 1.4rem; font-weight: 800;">Edit Mapped Document</h2>
            <button onclick="document.getElementById('editDocModal').style.display='none'" style="background: transparent; border: none; font-size: 1.8rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <form action="../api/documents.php?action=edit" method="POST" style="display: flex; flex-direction: column; gap: 1.2rem;">
            <input type="hidden" name="doc_id" id="edit_doc_id">

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Document Code *</label>
                <input type="text" name="doc_code" id="edit_doc_code" required placeholder="e.g. ISO-2015-QMS-01" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Office of Origin *</label>
                <select name="office_of_origin" id="edit_office_of_origin" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white; cursor: pointer;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                    <option value="">Select Office</option>
                    <?php foreach ($sys_offices as $sys_o): ?>
                        <option value="<?= htmlspecialchars($sys_o['name']) ?>"><?= htmlspecialchars($sys_o['name']) ?> (<?= htmlspecialchars($sys_o['acronym']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Category *</label>
                    <select name="category" id="edit_category" required onchange="handleCategoryChange(this, 'edit_new_category_container')" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                        <option value="__NEW__">-- Add New Category --</option>
                    </select>
                    <div id="edit_new_category_container" style="display: none; margin-top: 0.5rem;">
                        <input type="text" name="new_category" id="edit_new_category" placeholder="Enter new category name..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                    </div>
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Confidentiality Level *</label>
                    <select name="confidentiality" id="edit_confidentiality" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="1">Level 1 - Public</option>
                        <option value="2">Level 2 - Internal</option>
                        <option value="3">Level 3 - Restricted</option>
                        <option value="4">Level 4 - Confidential</option>
                        <option value="5">Level 5 - Strictly Confidential</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Purpose / Scope of Use</label>
                <textarea name="purpose" id="edit_purpose" rows="3" placeholder="Describe the document's goal, application scope, or purpose..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; resize: vertical;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'"></textarea>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Document Tags</label>
                <div id="edit_tags_inputs_container" style="display: flex; flex-direction: column; gap: 8px;">
                    <!-- dynamic edit tags row appended by JS -->
                </div>
                <button type="button" onclick="addEditTagInputRow()" style="background: transparent; border: 1.5px dashed var(--accent-blue); color: var(--accent-blue); padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; margin-bottom: 0.5rem; transition: background 0.2s;" onmouseover="this.style.background='rgba(0, 28, 87, 0.04)'" onmouseout="this.style.background='transparent'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Tag Field
                </button>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('editDocModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- View Document Details Modal -->
<div id="viewDocModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2100; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 550px; max-width: 90vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <div>
                <span id="view_doc_conf_badge" style="padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;">Public</span>
                <h2 id="view_doc_code" style="margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 800; line-height: 1.3;">Document Code</h2>
                <p id="view_doc_category" style="color: var(--accent-blue); font-size: 0.75rem; font-weight: 800; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">CATEGORY: POLICY</p>
            </div>
            <button onclick="document.getElementById('viewDocModal').style.display='none'" style="background: transparent; border: none; font-size: 2rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px;">Office of Origin</span>
                <div id="view_doc_office" style="font-size: 0.95rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    <span>Quality Assurance Office</span>
                </div>
            </div>

            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px;">Purpose / Usage Scope</span>
                <p id="view_doc_purpose" style="margin: 0; font-size: 0.95rem; color: #334155; line-height: 1.5; white-space: pre-wrap; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); max-height: 140px; overflow-y: auto; scrollbar-width: thin;">Purpose details...</p>
            </div>

            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 6px;">Associated Tags</span>
                <div id="view_doc_tags" style="display: flex; flex-wrap: wrap; gap: 4px;">
                    <!-- tags -->
                </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('viewDocModal').style.display='none'" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer;">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Analyze Similarity Modal (HIGH FIDELITY SCORING MECHANISM) -->
<div id="similarityModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 700px; max-width: 95vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); max-height: 90vh; overflow-y: auto; font-family: 'Inter', sans-serif; scrollbar-width: thin;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.2rem;">
            <div>
                <span style="padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px; background: rgba(0, 28, 87, 0.1); color: var(--accent-blue);">AI Analysis Active</span>
                <h2 style="margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 800; line-height: 1.3;">Similarity Comparison</h2>
                <p style="color: var(--text-secondary); font-size: 0.8rem; font-weight: 500; margin: 4px 0 0 0;">Comparing overlap points against other documents using scoring metrics</p>
            </div>
            <button onclick="document.getElementById('similarityModal').style.display='none'" style="background: transparent; border: none; font-size: 2rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <!-- Target Info -->
        <div style="background: rgba(0, 28, 87, 0.03); border: 1px dashed rgba(0, 28, 87, 0.2); padding: 1.2rem; border-radius: 12px; margin-bottom: 1.5rem; position: relative;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; padding-right: 28px;">
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Comparing Source Document</span>
                <span id="target_conf_badge" style="font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 10px;">Public</span>
            </div>
            <button id="target_view_details_btn" style="position: absolute; top: 12px; right: 12px; background: transparent; border: none; cursor: pointer; color: var(--text-secondary); display: flex; align-items: center; justify-content: center; padding: 6px; border-radius: 50%; transition: background 0.2s;" onmouseover="this.style.background='rgba(0,0,0,0.05)'" onmouseout="this.style.background='transparent'">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
            </button>
            <h3 id="target_code" style="margin: 0 0 4px 0; color: var(--accent-blue); font-size: 1.2rem; font-weight: 800;">CODE-101</h3>
            <div style="font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 4px;"><span id="target_office">Office</span> | <span id="target_category" style="color: var(--accent-gold);">Category</span></div>
            <p id="target_purpose" style="margin: 0; font-size: 0.8rem; color: var(--text-secondary); font-style: italic; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis;">No purpose details.</p>
        </div>

        <h4 style="margin: 0 0 1rem 0; font-size: 0.8rem; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Top Similar Document Matches</h4>
        
        <div id="similarity_results_list" style="display: flex; flex-direction: column; gap: 1rem;">
            <!-- list of matches -->
        </div>
    </div>
</div>

<script>
    const confidentiality_levels = <?= json_encode($confidentiality_levels) ?>;

    function toggleDropdown(id) {
        event.stopPropagation();
        const menu = document.getElementById('dropdown-' + id);
        const allMenus = document.querySelectorAll('.dropdown-menu');
        
        allMenus.forEach(m => {
            if (m.id !== 'dropdown-' + id) {
                m.style.display = 'none';
            }
        });
        
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    }

    let currentCategoryFilter = 'all';
    let currentPage = parseInt(sessionStorage.getItem('doctrackerPage')) || 1;
    const itemsPerPage = 10;

    function resetPageAndSearch() {
        currentPage = 1;
        searchDocuments();
    }

    function filterByCategory(category, btn) {
        currentCategoryFilter = category;
        currentPage = 1;
        document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        if (btn) {
            btn.classList.add('active');
        }
        if (category === 'all') {
            const select = document.getElementById('categoryFilterDropdown');
            if (select) {
                select.value = '';
            }
        }
        searchDocuments();
    }

    function filterByCategoryDropdown(category) {
        currentPage = 1;
        const allTab = document.getElementById('all-categories-tab');
        if (category === '') {
            currentCategoryFilter = 'all';
            if (allTab) {
                allTab.classList.add('active');
            }
        } else {
            currentCategoryFilter = category;
            if (allTab) {
                allTab.classList.remove('active');
            }
        }
        searchDocuments();
    }

    function searchDocuments() {
        const searchTerm = document.getElementById('documentSearch').value.toLowerCase();
        const officeFilter = document.getElementById('officeFilter').value;
        const rows = document.querySelectorAll('.doc-row');
        
        let matchingRows = [];
        
        rows.forEach(row => {
            const code = row.getAttribute('data-code').toLowerCase();
            const office = row.getAttribute('data-office').toLowerCase();
            const category = row.getAttribute('data-category').toLowerCase();
            const tags = row.getAttribute('data-tags').toLowerCase();
            
            const textContent = row.textContent.toLowerCase();
            
            const matchesSearch = code.includes(searchTerm) || office.includes(searchTerm) || tags.includes(searchTerm) || textContent.includes(searchTerm);
            const matchesOffice = officeFilter === 'all' || row.getAttribute('data-office') === officeFilter;
            const matchesCategory = currentCategoryFilter === 'all' || row.getAttribute('data-category') === currentCategoryFilter;
            
            if (matchesSearch && matchesOffice && matchesCategory) {
                matchingRows.push(row);
            } else {
                row.style.display = 'none';
            }
        });
        
        const totalItems = matchingRows.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
        
        if (currentPage > totalPages) {
            currentPage = totalPages;
        }
        if (currentPage < 1) {
            currentPage = 1;
        }
        
        sessionStorage.setItem('doctrackerPage', currentPage);
        
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        
        matchingRows.forEach((row, index) => {
            if (index >= startIndex && index < endIndex) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        const actualStart = totalItems === 0 ? 0 : startIndex + 1;
        const actualEnd = Math.min(endIndex, totalItems);
        
        const countContainer = document.getElementById('showing-count-container');
        if (countContainer) {
            countContainer.innerHTML = `Showing <b>${actualStart} - ${actualEnd}</b> of <b>${totalItems}</b> documents`;
        }
        
        updatePaginationUI(totalPages);
    }

    function updatePaginationUI(totalPages) {
        const controls = document.getElementById('pagination-controls');
        if (!controls) return;
        
        controls.innerHTML = '';
        
        // Previous
        const prevBtn = document.createElement('button');
        prevBtn.innerText = 'Previous';
        prevBtn.style.cssText = 'padding: 5px 12px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px; cursor: pointer;';
        if (currentPage === 1) {
            prevBtn.disabled = true;
            prevBtn.style.opacity = '0.5';
            prevBtn.style.cursor = 'default';
        } else {
            prevBtn.onclick = () => {
                currentPage--;
                searchDocuments();
            };
        }
        controls.appendChild(prevBtn);
        
        // Page Number Buttons
        for (let i = 1; i <= totalPages; i++) {
            const pageBtn = document.createElement('button');
            pageBtn.innerText = i;
            if (i === currentPage) {
                pageBtn.style.cssText = 'padding: 5px 12px; border: 1px solid var(--border-color); background: var(--accent-blue); color: white; font-size: 0.8rem; border-radius: 6px; cursor: default;';
            } else {
                pageBtn.style.cssText = 'padding: 5px 12px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px; cursor: pointer;';
                pageBtn.onclick = () => {
                    currentPage = i;
                    searchDocuments();
                };
            }
            controls.appendChild(pageBtn);
        }
        
        // Next
        const nextBtn = document.createElement('button');
        nextBtn.innerText = 'Next';
        nextBtn.style.cssText = 'padding: 5px 12px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px; cursor: pointer;';
        if (currentPage === totalPages) {
            nextBtn.disabled = true;
            nextBtn.style.opacity = '0.5';
            nextBtn.style.cursor = 'default';
        } else {
            nextBtn.onclick = () => {
                currentPage++;
                searchDocuments();
            };
        }
        controls.appendChild(nextBtn);
    }

    async function viewDetails(id) {
        try {
            const response = await fetch(`../api/documents.php?action=get&doc_id=${id}`);
            if (!response.ok) throw new Error('Network error');
            const res = await response.json();
            
            if (res.success) {
                const doc = res.data;
                const cLevel = doc.confidentiality;
                const cData = confidentiality_levels[cLevel] || {label: 'Unknown', color: '#64748b', bg: '#f1f5f9'};
                
                const cBadge = document.getElementById('view_doc_conf_badge');
                cBadge.textContent = cData.label;
                cBadge.style.cssText = `padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px; color: ${cData.color}; background: ${cData.bg};`;
                
                document.getElementById('view_doc_code').textContent = doc.doc_code;
                document.getElementById('view_doc_category').textContent = 'CATEGORY: ' + doc.category;
                document.getElementById('view_doc_office').querySelector('span').textContent = doc.office_of_origin;
                document.getElementById('view_doc_purpose').textContent = doc.purpose || 'No purpose or scope recorded.';
                
                // Tags
                let tagsHTML = '';
                if (doc.tags && doc.tags.length > 0) {
                    doc.tags.forEach(t => {
                        tagsHTML += `<span class="tag-badge" style="background:#eff6ff; color:#1e40af; border-color:#dbeafe;">${escapeHtml(t)}</span>`;
                    });
                } else {
                    tagsHTML = '<span style="color:#94a3b8; font-size:0.8rem; font-style:italic;">No tags linked</span>';
                }
                document.getElementById('view_doc_tags').innerHTML = tagsHTML;
                
                document.getElementById('viewDocModal').style.display = 'flex';
            } else {
                alert('Failed to load document: ' + res.message);
            }
        } catch (e) {
            alert('Error loading details: ' + e.message);
        }
    }

    async function analyzeSimilarity(id) {
        try {
            const response = await fetch(`../api/documents.php?action=similarity&doc_id=${id}`);
            if (!response.ok) throw new Error('API server returned a failed status');
            const res = await response.json();
            
            if (res.success) {
                const target = res.target;
                const recs = res.recommendations;
                
                // Populate source info
                const cLevel = target.confidentiality;
                const cData = confidentiality_levels[cLevel] || {label: 'Unknown', color: '#64748b', bg: '#f1f5f9'};
                const badge = document.getElementById('target_conf_badge');
                badge.textContent = cData.label;
                badge.style.cssText = `font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 10px; color: ${cData.color}; background: ${cData.bg};`;
                
                document.getElementById('target_code').textContent = target.doc_code;
                document.getElementById('target_office').textContent = target.office_of_origin;
                document.getElementById('target_category').textContent = target.category;
                document.getElementById('target_purpose').textContent = target.purpose || 'No purpose recorded.';
                document.getElementById('target_view_details_btn').onclick = () => viewDetails(target.doc_id);
                
                // Populate list
                let resultsHTML = '';
                if (!recs || recs.length === 0) {
                    resultsHTML = '<div style="padding:2rem; text-align:center; color:#94a3b8; font-size:0.9rem; font-style:italic; border:1px dashed #cbd5e1; border-radius:10px;">No other registered documents to compare overlap against. Add more documents to activate AI comparison.</div>';
                } else {
                    recs.forEach(doc => {
                        const score = doc.scores.total;
                        // Score bar colors
                        let scoreColor = '#cbd5e1';
                        if (score >= 80) scoreColor = '#10b981'; // High
                        else if (score >= 50) scoreColor = '#3b82f6'; // Medium
                        else if (score > 15) scoreColor = '#f59e0b'; // Low
                        
                        let docTagsHTML = '';
                        if (doc.tags && doc.tags.length > 0) {
                            doc.tags.forEach(t => {
                                docTagsHTML += `<span class="tag-badge" style="font-size:0.7rem; padding: 1px 6px;">${escapeHtml(t)}</span>`;
                            });
                        }
                        
                        const breakID = `breakdown-${doc.doc_id}`;
                        
                        resultsHTML += `
                            <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 1.2rem; transition: transform 0.2s; position: relative;" onmouseover="this.style.borderColor='var(--accent-blue)'" onmouseout="this.style.borderColor='var(--border-color)'">
                                <button onclick="viewDetails(${doc.doc_id})" style="position: absolute; top: 12px; right: 12px; background: transparent; border: none; cursor: pointer; color: var(--text-secondary); display: flex; align-items: center; justify-content: center; padding: 6px; border-radius: 50%; transition: background 0.2s;" onmouseover="this.style.background='rgba(0,0,0,0.05)'" onmouseout="this.style.background='transparent'">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                </button>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; padding-right: 28px;">
                                    <div>
                                        <h4 style="margin:0; font-size:1.05rem; font-weight:800; color:#0f172a;">${escapeHtml(doc.doc_code)}</h4>
                                        <span style="font-size:0.75rem; color:#64748b;">${escapeHtml(doc.office_of_origin)} | <b style="color:var(--accent-blue);">${escapeHtml(doc.category)}</b></span>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-size:1.2rem; font-weight:900; color:${scoreColor}">${score}%</div>
                                        <span style="font-size:0.7rem; color:#94a3b8; font-weight:700; text-transform:uppercase;">Overlap Match</span>
                                    </div>
                                </div>
                                
                                <div style="width:100%; height:6px; background:#e2e8f0; border-radius:10px; overflow:hidden; margin-bottom:10px;">
                                    <div style="width:${score}%; height:100%; background:${scoreColor}; border-radius:10px; transition:width 0.4s;"></div>
                                </div>
                                
                                <div style="margin-bottom:8px; display:flex; flex-wrap:wrap; gap:4px;">
                                    ${docTagsHTML}
                                </div>

                                <!-- Dynamic breakdown collapse -->
                                <button onclick="document.getElementById('${breakID}').style.display = document.getElementById('${breakID}').style.display === 'none' ? 'block' : 'none'" style="background:none; border:none; color:var(--accent-blue); font-size:0.75rem; font-weight:700; cursor:pointer; padding:0; display:flex; align-items:center; gap:4px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                                    View Score Breakdown
                                </button>
                                
                                <div id="${breakID}" style="display:none; background:#f8fafc; padding:10px; border-radius:8px; border:1px solid #cbd5e1; margin-top:8px; font-size:0.75rem; color:#475569;">
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px;">
                                        <div>🏢 Office Match Score: <b>${doc.scores.office}</b> / 25 pts</div>
                                        <div>📁 Category Match Score: <b>${doc.scores.category}</b> / 20 pts</div>
                                        <div>🏷️ Tag Overlap Score: <b>${doc.scores.tag}</b> / 30 pts</div>
                                        <div>💬 Purpose Similarity Score: <b>${doc.scores.purpose}</b> / 20 pts</div>
                                        <div>🔑 Confidentiality Score: <b>${doc.scores.confidentiality}</b> / 5 pts</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                }
                document.getElementById('similarity_results_list').innerHTML = resultsHTML;
                
                document.getElementById('similarityModal').style.display = 'flex';
            } else {
                alert('Similarity query failed: ' + res.message);
            }
        } catch(e) {
            alert('Error running comparison: ' + e.message);
        }
    }

    function deleteDocument(id) {
        if (confirm('Are you sure you want to delete this mapped document? All registered tag links will be removed.')) {
            window.location.href = `../api/documents.php?action=delete&doc_id=${id}`;
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    }

    function addTagInputRow() {
        const container = document.getElementById('tags-inputs-container');
        const newRow = document.createElement('div');
        newRow.className = 'tag-input-row';
        newRow.style.cssText = 'display: flex; gap: 8px; align-items: center;';
        newRow.innerHTML = `
            <input type="text" name="tags[]" list="existing-tags-list" placeholder="Select or type a tag..." style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            <button type="button" onclick="removeTagInputRow(this)" style="background: #fee2e2; border: 1px solid #fca5a5; color: #ef4444; border-radius: 8px; padding: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; font-weight: 700; transition: background 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">&times;</button>
        `;
        container.appendChild(newRow);
    }

    function removeTagInputRow(btn) {
        const container = document.getElementById('tags-inputs-container');
        const rows = container.querySelectorAll('.tag-input-row');
        if (rows.length > 1) {
            btn.closest('.tag-input-row').remove();
        } else {
            btn.closest('.tag-input-row').querySelector('input').value = '';
        }
    }

    async function openEditModal(id) {
        try {
            const response = await fetch(`../api/documents.php?action=get&doc_id=${id}`);
            if (!response.ok) throw new Error('Network error');
            const res = await response.json();
            
            if (res.success) {
                const doc = res.data;
                
                document.getElementById('edit_doc_id').value = doc.doc_id;
                document.getElementById('edit_doc_code').value = doc.doc_code;
                document.getElementById('edit_office_of_origin').value = doc.office_of_origin;
                document.getElementById('edit_category').value = doc.category;
                document.getElementById('edit_new_category_container').style.display = 'none';
                document.getElementById('edit_new_category').required = false;
                document.getElementById('edit_new_category').value = '';
                document.getElementById('edit_confidentiality').value = doc.confidentiality;
                document.getElementById('edit_purpose').value = doc.purpose || '';
                
                // Populating dynamic tags in edit modal
                const container = document.getElementById('edit_tags_inputs_container');
                container.innerHTML = ''; // clear previous
                
                if (doc.tags && doc.tags.length > 0) {
                    doc.tags.forEach((tag) => {
                        const row = document.createElement('div');
                        row.className = 'tag-input-row';
                        row.style.cssText = 'display: flex; gap: 8px; align-items: center;';
                        row.innerHTML = `
                            <input type="text" name="tags[]" list="existing-tags-list" value="${escapeHtml(tag)}" placeholder="Select or type a tag..." style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                            <button type="button" onclick="removeEditTagInputRow(this)" style="background: #fee2e2; border: 1px solid #fca5a5; color: #ef4444; border-radius: 8px; padding: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; font-weight: 700; transition: background 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">&times;</button>
                        `;
                        container.appendChild(row);
                    });
                } else {
                    const row = document.createElement('div');
                    row.className = 'tag-input-row';
                    row.style.cssText = 'display: flex; gap: 8px; align-items: center;';
                    row.innerHTML = `
                        <input type="text" name="tags[]" list="existing-tags-list" placeholder="Select or type a tag..." style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <button type="button" onclick="removeEditTagInputRow(this)" style="background: #fee2e2; border: 1px solid #fca5a5; color: #ef4444; border-radius: 8px; padding: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; font-weight: 700; transition: background 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">&times;</button>
                    `;
                    container.appendChild(row);
                }
                
                document.getElementById('editDocModal').style.display = 'flex';
            } else {
                alert('Failed to load document data: ' + res.message);
            }
        } catch (e) {
            alert('Error loading document: ' + e.message);
        }
    }

    function addEditTagInputRow() {
        const container = document.getElementById('edit_tags_inputs_container');
        const newRow = document.createElement('div');
        newRow.className = 'tag-input-row';
        newRow.style.cssText = 'display: flex; gap: 8px; align-items: center;';
        newRow.innerHTML = `
            <input type="text" name="tags[]" list="existing-tags-list" placeholder="Select or type a tag..." style="flex: 1; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            <button type="button" onclick="removeEditTagInputRow(this)" style="background: #fee2e2; border: 1px solid #fca5a5; color: #ef4444; border-radius: 8px; padding: 0.8rem; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; font-weight: 700; transition: background 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">&times;</button>
        `;
        container.appendChild(newRow);
    }

    function removeEditTagInputRow(btn) {
        const container = document.getElementById('edit_tags_inputs_container');
        const rows = container.querySelectorAll('.tag-input-row');
        if (rows.length > 1) {
            btn.closest('.tag-input-row').remove();
        } else {
            btn.closest('.tag-input-row').querySelector('input').value = '';
        }
    }

    function handleCategoryChange(selectElement, containerId) {
        const container = document.getElementById(containerId);
        const input = container.querySelector('input');
        if (selectElement.value === '__NEW__') {
            container.style.display = 'block';
            input.required = true;
            input.focus();
        } else {
            container.style.display = 'none';
            input.required = false;
            input.value = '';
        }
    }

    // Initialize search on load to set up pagination
    searchDocuments();

    // Close action menus when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });
</script>
