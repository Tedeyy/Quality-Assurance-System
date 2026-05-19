<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Fetch all documents with their tags list
$query = "
    SELECT d.*, 
           GROUP_CONCAT(t.tag_name SEPARATOR ', ') as tags_list
    FROM documents d
    LEFT JOIN document_tags dt ON d.doc_id = dt.doc_id
    LEFT JOIN tags t ON dt.tag_id = t.tag_id
    GROUP BY d.doc_id
    ORDER BY d.doc_code ASC
";
$stmt = $db->query($query);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct categories for the tab filters & options
$cat_stmt = $db->query("SELECT DISTINCT category FROM documents WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$db_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
$default_categories = ['Policy', 'Manual', 'Guidelines', 'SOP', 'Form', 'Report', 'Minutes', 'Contract'];
$categories = array_unique(array_merge($default_categories, $db_categories));
sort($categories);

// Fetch all divisions/offices from system to populate the Add/Edit form
$sys_offices_stmt = $db->query("SELECT office_id, name, acronym FROM divisions_offices ORDER BY name ASC");
$sys_offices = $sys_offices_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all existing tags for datalist predictive selections
$all_tags_stmt = $db->query("SELECT tag_name FROM tags ORDER BY tag_name ASC");
$existing_tags = $all_tags_stmt->fetchAll(PDO::FETCH_COLUMN);
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
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.4);
        border-radius: 16px;
        box-shadow: 0 4px 30px rgba(0, 0, 0, 0.03);
    }

    .qa-header {
        background: linear-gradient(135deg, rgba(0, 28, 87, 0.04) 0%, rgba(223, 182, 65, 0.04) 100%);
        border-bottom: 1px solid var(--border-color);
        padding: 2rem;
        border-radius: 16px 16px 0 0;
    }

    .qa-table th {
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
        color: var(--text-secondary);
        border-bottom: 2px solid var(--border-color);
        padding: 1rem 1.2rem;
    }

    .qa-table td {
        border-bottom: 1px solid var(--border-color);
        vertical-align: middle;
    }

    .qa-table tr:hover {
        background-color: rgba(248, 250, 252, 0.8);
    }

    .month-tabs-container {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding-bottom: 5px;
        margin-bottom: 1rem;
        scrollbar-width: none;
    }

    .month-tabs-container::-webkit-scrollbar {
        display: none;
    }

    .month-tab {
        padding: 8px 18px;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .month-tab.active {
        background: var(--accent-blue);
        color: white;
        border-color: var(--accent-blue);
        box-shadow: 0 4px 10px rgba(0, 28, 87, 0.15);
    }

    /* Tag badge style */
    .tag-badge {
        display: inline-block;
        background: rgba(0, 28, 87, 0.05);
        color: var(--accent-blue);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        margin: 2px;
        border: 1px solid rgba(0, 28, 87, 0.08);
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
        padding: 6px;
        border-radius: 50%;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
    }

    .three-dots-btn:hover {
        background: #f1f5f9;
        color: var(--text-primary);
    }

    .dropdown-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        z-index: 100;
        min-width: 180px;
        padding: 4px 0;
        animation: fadeIn 0.15s ease-out;
    }

    .dropdown-item {
        width: 100%;
        padding: 10px 16px;
        text-align: left;
        background: transparent;
        border: none;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background 0.2s;
    }

    .dropdown-item:hover {
        background: #f8fafc;
        color: var(--accent-blue);
    }

    .dropdown-item.delete {
        color: #ef4444;
    }

    .dropdown-item.delete:hover {
        background: #fef2f2;
        color: #ef4444;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(5px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<main style="padding: 2rem; max-width: 1400px; margin: 0 auto;">
    <!-- Navigation History back link -->
    <div style="margin-bottom: 1.5rem;">
        <a href="feed.php?action=document" style="display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-blue)'" onmouseout="this.style.color='var(--text-secondary)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Document Mapping
        </a>
    </div>

    <div class="qa-card" style="margin-bottom: 2rem;">
        <!-- Header -->
        <div class="qa-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
            <div>
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 0.4rem;">
                    <div style="background: rgba(0, 28, 87, 0.1); padding: 8px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--accent-blue);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <h1 style="margin: 0; font-size: 1.8rem; font-weight: 800; color: #0f172a;">Document Masterlist</h1>
                </div>
                <p style="margin: 0; color: var(--text-secondary); font-size: 0.95rem; font-weight: 500;">Comprehensive mapped institutional documents registry database</p>
            </div>
            
            <button onclick="document.getElementById('addDocModal').style.display='flex'" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; font-weight: 700; border-radius: 10px; cursor: pointer; box-shadow: 0 4px 12px rgba(0, 28, 87, 0.15); border: none; background: var(--accent-blue); color: white;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Document
            </button>
        </div>

        <!-- Filters Section -->
        <div style="padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-color); background: rgba(255,255,255,0.4);">
            <!-- Category Tabs -->
            <div class="month-tabs-container">
                <button class="month-tab active" onclick="filterByCategoryTab('all', this)">All Categories</button>
                <?php foreach ($categories as $cat): ?>
                    <button class="month-tab" onclick="filterByCategoryTab('<?= $cat ?>', this)"><?= $cat ?></button>
                <?php endforeach; ?>
            </div>

            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px; position: relative;">
                    <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); display: flex; align-items: center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </span>
                    <input type="text" id="documentSearch" oninput="searchDocuments()" placeholder="Search by code, purpose, office or tags..." style="width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border: 1px solid var(--border-color); border-radius: 10px; font-size: 0.9rem; outline: none; background: white; transition: border-color 0.2s;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                </div>

                <div style="width: 250px;">
                    <select id="confidentialityFilter" onchange="searchDocuments()" style="width: 100%; padding: 0.8rem 1rem; border: 1px solid var(--border-color); border-radius: 10px; font-size: 0.9rem; outline: none; background: white; cursor: pointer; transition: border-color 0.2s;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="all">All Confidentiality Levels</option>
                        <option value="1">Level 1 - Public</option>
                        <option value="2">Level 2 - Internal</option>
                        <option value="3">Level 3 - Restricted</option>
                        <option value="4">Level 4 - Confidential</option>
                        <option value="5">Level 5 - Strictly Confidential</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Table Grid -->
        <div style="overflow-x: auto;">
            <table class="qa-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr>
                        <th style="width: 150px; padding-left: 2rem;">Document Code</th>
                        <th style="width: 280px;">Office & Category</th>
                        <th>Purpose / Scope of Use</th>
                        <th style="width: 220px;">Tags</th>
                        <th style="width: 160px;">Confidentiality</th>
                        <th style="width: 80px; text-align: right; padding-right: 2rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.8rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <p style="margin: 0; font-weight: 600; font-size: 0.95rem;">No mapped documents found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <?php 
                                // Confidentiality Level styling
                                $levelName = 'Level 1 - Public';
                                $levelStyle = 'background: #dcfce7; color: #166534;';
                                switch((int)$doc['confidentiality']) {
                                    case 1: $levelName = 'Level 1 - Public'; $levelStyle = 'background: #dcfce7; color: #166534;'; break;
                                    case 2: $levelName = 'Level 2 - Internal'; $levelStyle = 'background: #dbeafe; color: #1e40af;'; break;
                                    case 3: $levelName = 'Level 3 - Restricted'; $levelStyle = 'background: #fef3c7; color: #d97706;'; break;
                                    case 4: $levelName = 'Level 4 - Confidential'; $levelStyle = 'background: #fee2e2; color: #991b1b;'; break;
                                    case 5: $levelName = 'Level 5 - Strictly Confidential'; $levelStyle = 'background: #f3e8ff; color: #6b21a8;'; break;
                                }
                            ?>
                            <tr class="doc-row" 
                                data-code="<?= htmlspecialchars($doc['doc_code']) ?>" 
                                data-office="<?= htmlspecialchars($doc['office_of_origin']) ?>" 
                                data-category="<?= htmlspecialchars($doc['category']) ?>"
                                data-purpose="<?= htmlspecialchars($doc['purpose'] ?? '') ?>"
                                data-confidentiality="<?= $doc['confidentiality'] ?>"
                                data-tags="<?= htmlspecialchars($doc['tags_list'] ?? '') ?>">
                                
                                <td style="padding: 1.2rem 1.2rem 1.2rem 2rem; font-weight: 800; color: var(--accent-blue); font-size: 0.9rem;">
                                    <?= htmlspecialchars($doc['doc_code']) ?>
                                </td>
                                
                                <td style="padding: 1.2rem;">
                                    <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem; margin-bottom: 4px;"><?= htmlspecialchars($doc['office_of_origin']) ?></div>
                                    <span style="background: rgba(0, 28, 87, 0.05); color: var(--accent-blue); padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; display: inline-block;">
                                        <?= htmlspecialchars($doc['category']) ?>
                                    </span>
                                </td>
                                
                                <td style="padding: 1.2rem; font-size: 0.9rem; color: #334155; max-width: 350px;">
                                    <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($doc['purpose'] ?? 'No purpose defined.') ?>">
                                        <?= htmlspecialchars($doc['purpose'] ?: 'No purpose / scope of use defined.') ?>
                                    </div>
                                </td>
                                
                                <td style="padding: 1.2rem;">
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                        <?php if (!empty($doc['tags_list'])): ?>
                                            <?php foreach (explode(', ', $doc['tags_list']) as $tag): ?>
                                                <span class="tag-badge"><?= htmlspecialchars($tag) ?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.8rem; font-style: italic;">No tags</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <td style="padding: 1.2rem;">
                                    <span style="<?= $levelStyle ?> padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; white-space: nowrap;">
                                        <?= $levelName ?>
                                    </span>
                                </td>
                                
                                <td style="padding: 1.2rem 2rem 1.2rem 1.2rem; text-align: right;">
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
            
            <div style="padding: 1.2rem 2rem; background: #f8fafc; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; border-radius: 0 0 16px 16px;">
                <div style="font-size: 0.8rem; color: var(--text-secondary);">Showing <b id="showing-count"><?= count($documents) ?></b> documents</div>
                <div style="display: flex; gap: 5px;">
                    <button class="btn" style="padding: 5px 12px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px;">Previous</button>
                    <button class="btn" style="padding: 5px 12px; border: 1px solid var(--border-color); background: var(--accent-blue); color: white; font-size: 0.8rem; border-radius: 6px;">1</button>
                    <button class="btn" style="padding: 5px 12px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px;">Next</button>
                </div>
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
            <input type="hidden" name="redirect_url" value="../views/feed.php?action=docmasterlist">

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
                <div id="add_tags_inputs_container" style="display: flex; flex-direction: column; gap: 8px;">
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

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('addDocModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; background: var(--accent-blue); color: white;">Map Document</button>
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
            <input type="hidden" name="redirect_url" value="../views/feed.php?action=docmasterlist">
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
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; background: var(--accent-blue); color: white;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- View Document Details Modal -->
<div id="viewDocModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 600px; max-width: 90vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <div>
                <span id="view_doc_confidentiality" style="padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;">Level 1 - Public</span>
                <h2 id="view_office_title" style="margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 800; line-height: 1.3;">Office of Origin</h2>
                <p id="view_doc_code_subtitle" style="color: #64748b; font-size: 0.8rem; font-weight: 700; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">CODE: ISO-2015-QMS-01</p>
            </div>
            <button onclick="document.getElementById('viewDocModal').style.display='none'" style="background: transparent; border: none; font-size: 2rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <h4 style="margin: 0 0 0.4rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Category</h4>
                <span id="view_doc_category" style="background: rgba(0, 28, 87, 0.05); color: var(--accent-blue); padding: 4px 12px; border-radius: 6px; font-size: 0.85rem; font-weight: 700; display: inline-block;">Policy</span>
            </div>

            <div>
                <h4 style="margin: 0 0 0.4rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Purpose / Scope of Use</h4>
                <p id="view_doc_purpose" style="margin: 0; font-size: 0.95rem; color: #334155; line-height: 1.5; white-space: pre-wrap; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); max-height: 120px; overflow-y: auto; scrollbar-width: thin;">Overview description of the document...</p>
            </div>

            <div>
                <h4 style="margin: 0 0 0.4rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Document Tags</h4>
                <div id="view_doc_tags_container" style="display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 0;">
                    <!-- Renders tag elements dynamically -->
                </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('viewDocModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Datalist source for predictable tags -->
<datalist id="existing-tags-list">
    <?php foreach ($existing_tags as $ext_tag): ?>
        <option value="<?= htmlspecialchars($ext_tag) ?>">
    <?php endforeach; ?>
</datalist>

<script>
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

    let currentCategoryTab = 'all';

    function filterByCategoryTab(cat, btn) {
        currentCategoryTab = cat;
        document.querySelectorAll('.month-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        searchDocuments();
    }

    function searchDocuments() {
        const searchTerm = document.getElementById('documentSearch').value.toLowerCase();
        const confFilter = document.getElementById('confidentialityFilter').value;
        const rows = document.querySelectorAll('.doc-row');
        
        let visibleCount = 0;
        
        rows.forEach(row => {
            const code = row.getAttribute('data-code').toLowerCase();
            const office = row.getAttribute('data-office').toLowerCase();
            const category = row.getAttribute('data-category');
            const purpose = row.getAttribute('data-purpose').toLowerCase();
            const confidentiality = row.getAttribute('data-confidentiality');
            const tags = row.getAttribute('data-tags').toLowerCase();
            
            const matchesSearch = code.includes(searchTerm) || office.includes(searchTerm) || purpose.includes(searchTerm) || tags.includes(searchTerm);
            const matchesConf = confFilter === 'all' || confidentiality === confFilter;
            const matchesTab = currentCategoryTab === 'all' || category === currentCategoryTab;
            
            if (matchesSearch && matchesConf && matchesTab) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        document.getElementById('showing-count').textContent = visibleCount;
    }

    function escapeHtml(string) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(string).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    async function viewDetails(id) {
        try {
            const response = await fetch(`../api/documents.php?action=get&doc_id=${id}`);
            if (!response.ok) throw new Error('Network error');
            const res = await response.json();
            
            if (res.success) {
                const doc = res.data;
                
                // Confidentiality styles
                let levelName = 'Level 1 - Public';
                let levelStyle = 'background: #dcfce7; color: #166534;';
                switch(parseInt(doc.confidentiality)) {
                    case 1: levelName = 'Level 1 - Public'; levelStyle = 'background: #dcfce7; color: #166534;'; break;
                    case 2: levelName = 'Level 2 - Internal'; levelStyle = 'background: #dbeafe; color: #1e40af;'; break;
                    case 3: levelName = 'Level 3 - Restricted'; levelStyle = 'background: #fef3c7; color: #d97706;'; break;
                    case 4: levelName = 'Level 4 - Confidential'; levelStyle = 'background: #fee2e2; color: #991b1b;'; break;
                    case 5: levelName = 'Level 5 - Strictly Confidential'; levelStyle = 'background: #f3e8ff; color: #6b21a8;'; break;
                }
                
                const badge = document.getElementById('view_doc_confidentiality');
                badge.textContent = levelName;
                badge.style.cssText = levelStyle + ' padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;';
                
                document.getElementById('view_office_title').textContent = doc.office_of_origin;
                document.getElementById('view_doc_code_subtitle').textContent = 'CODE: ' + doc.doc_code;
                document.getElementById('view_doc_category').textContent = doc.category;
                document.getElementById('view_doc_purpose').textContent = doc.purpose || 'No purpose / scope of use defined.';
                
                // Populate tags container
                const container = document.getElementById('view_doc_tags_container');
                container.innerHTML = '';
                
                if (doc.tags && doc.tags.length > 0) {
                    doc.tags.forEach(tag => {
                        const span = document.createElement('span');
                        span.className = 'tag-badge';
                        span.textContent = tag;
                        container.appendChild(span);
                    });
                } else {
                    container.innerHTML = '<span style="color: #94a3b8; font-size: 0.85rem; font-style: italic;">No associated tags.</span>';
                }
                
                document.getElementById('viewDocModal').style.display = 'flex';
            } else {
                alert('Failed to retrieve document: ' + res.message);
            }
        } catch(e) {
            alert('Error loading document: ' + e.message);
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

    function addTagInputRow() {
        const container = document.getElementById('add_tags_inputs_container');
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
        const container = document.getElementById('add_tags_inputs_container');
        const rows = container.querySelectorAll('.tag-input-row');
        if (rows.length > 1) {
            btn.closest('.tag-input-row').remove();
        } else {
            btn.closest('.tag-input-row').querySelector('input').value = '';
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

    function deleteDocument(id) {
        if (confirm('Are you sure you want to permanently delete this document mapping?')) {
            window.location.href = `../api/documents.php?action=delete&doc_id=${id}&redirect_url=../views/feed.php?action=docmasterlist`;
        }
    }

    // Close action menus when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });
</script>
