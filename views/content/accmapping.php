<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Fetch offices for filtering and modals
$stmt = $db->query("SELECT office_id, name, acronym FROM divisions_offices ORDER BY name ASC");
$sys_offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
$offices = array_column($sys_offices, 'name');

// Fetch accreditations and categories for JS hierarchical dropdowns
$stmt = $db->query("SELECT accreditation_id, name, code FROM accreditations ORDER BY name ASC");
$all_accreditations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT category_id, name, parent_category_id, accreditation_id FROM accreditation_categories ORDER BY parent_category_id ASC, name ASC");
$all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all requirements grouped to avoid duplicates, grabbing the latest status from submissions
$req_query = "
    SELECT 
        r.requirement_id as req_id, 
        r.codename as req_code, 
        r.name as title, 
        '' as description, 
        r.category_id,
        c.name as category,
        (SELECT COUNT(*) FROM accreditation_requirement_submissions s WHERE s.requirement_id = r.requirement_id) as sub_count,
        (SELECT do.name FROM accreditation_requirement_submissions s JOIN divisions_offices do ON s.office_id = do.office_id WHERE s.requirement_id = r.requirement_id ORDER BY s.updated_at DESC LIMIT 1) as assigned_office,
        (SELECT s.status FROM accreditation_requirement_submissions s WHERE s.requirement_id = r.requirement_id ORDER BY s.updated_at DESC LIMIT 1) as latest_status
    FROM accreditation_requirement r
    LEFT JOIN accreditation_categories c ON r.category_id = c.category_id
    ORDER BY c.name ASC, r.name ASC
";
$stmt = $db->query($req_query);
$raw_requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$requirements = [];
foreach ($raw_requirements as $req) {
    $requirements[] = [
        'req_id' => $req['req_id'],
        'req_code' => (!empty($req['req_code']) ? $req['req_code'] : 'REQ-' . $req['req_id']),
        'title' => $req['title'],
        'description' => $req['description'],
        'category_id' => $req['category_id'],
        'category' => (!empty($req['category']) ? $req['category'] : 'Uncategorized'),
        'assigned_office' => (!empty($req['assigned_office']) ? $req['assigned_office'] : 'Unassigned'),
        'status' => (!empty($req['latest_status']) ? $req['latest_status'] : 'Pending'),
        'tags_list' => '',
        'has_submission' => $req['sub_count'] > 0
    ];
}

$status_levels = [
    'Approved' => ['label' => 'Approved', 'color' => '#10b981', 'bg' => '#ecfdf5', 'icon' => '✅'],
    'Under Review' => ['label' => 'Under Review', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'icon' => '⏳'],
    'Pending' => ['label' => 'Pending', 'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '📥']
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

    #req-table-section.is-loading .req-table-content,
    #req-table-section.is-loading .req-table-footer {
        display: none;
    }

    .req-table-loader {
        display: none;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        min-height: 320px;
        padding: 3rem 2rem;
    }

    #req-table-section.is-loading .req-table-loader {
        display: flex;
    }

    .req-table-spinner {
        width: 44px;
        height: 44px;
        border: 3px solid #e2e8f0;
        border-top-color: var(--accent-blue);
        border-radius: 50%;
        animation: reqTableSpin 0.75s linear infinite;
    }

    @keyframes reqTableSpin {
        to { transform: rotate(360deg); }
    }

    .req-table-loader-text {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-secondary);
    }
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem; padding-bottom: 3rem;">
    <div class="container" style="max-width: 1300px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Header Section -->
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem;">
            <div>
                <h1 style="font-size: 2rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 12px; color: var(--accent-blue);">
                    <div style="background: var(--accent-blue); color: white; padding: 8px; border-radius: 10px; display: flex;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    Accreditation Mapping
                </h1>
                <p style="color: var(--text-secondary); margin: 0; font-size: 0.9rem; font-weight: 500;">
                    Map, organize, and monitor accreditation standards and requirements compliance across departments.
                </p>
                <div style="margin-top: 10px; display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 20px; background: #fffbeb; border: 1px solid #fde68a; font-size: 0.75rem; font-weight: 700; color: #b45309;">
                    <span>⚠️ Layout Preview: Backend Database Integration in Development</span>
                </div>
            </div>
            
            <button onclick="document.getElementById('addReqModal').style.display='flex'" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px; background: var(--accent-blue); color: white; font-weight: 700; font-size: 0.85rem; padding: 12px 24px; border: none; border-radius: 10px; cursor: pointer; box-shadow: 0 4px 10px rgba(0, 28, 87, 0.2); transition: all 0.2s;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Map Requirement
            </button>
        </div>

        <!-- Dynamic Category Tabs / Dropdown filter -->
        <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
            <button class="category-tab active" id="all-categories-tab" onclick="resetCategoryFilters()">All Areas</button>
            <div id="dynamic-dropdowns" style="display: flex; gap: 12px; flex-wrap: wrap;"></div>
        </div>

        <!-- Filters Block -->
        <div class="qa-card" style="padding: 1.5rem; margin-bottom: 1.5rem; background: white;">
            <div style="display: flex; gap: 1.2rem; flex-wrap: wrap;">
                
                <!-- Search input -->
                <div style="flex: 1; min-width: 280px; position: relative;">
                    <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); display: flex;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </span>
                    <input type="text" id="requirementSearch" oninput="resetPageAndSearch()" placeholder="Search requirements by code, title, tags..." style="width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border: 1px solid var(--border-color); border-radius: 10px; font-size: 0.9rem; outline: none; transition: border 0.2s;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                </div>

                <!-- Office Filter -->
                <div style="width: 230px;">
                    <select id="officeFilter" onchange="resetPageAndSearch()" style="width: 100%; padding: 0.8rem 1rem; border: 1px solid var(--border-color); border-radius: 10px; font-size: 0.9rem; outline: none; background: white; cursor: pointer;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="all">All Offices</option>
                        <?php foreach ($offices as $o): ?>
                            <option value="<?= htmlspecialchars($o) ?>"><?= htmlspecialchars($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status Filter -->
                <div style="width: 200px;">
                    <select id="statusFilter" onchange="resetPageAndSearch()" style="width: 100%; padding: 0.8rem 1rem; border: 1px solid var(--border-color); border-radius: 10px; font-size: 0.9rem; outline: none; background: white; cursor: pointer;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="all">All Statuses</option>
                        <option value="Approved">Approved</option>
                        <option value="Under Review">Under Review</option>
                        <option value="Pending">Pending</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Table Grid -->
        <div id="req-table-section" class="is-loading" style="background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: visible; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);">
            <div id="req-table-loading" class="req-table-loader" aria-live="polite" aria-busy="true">
                <div class="req-table-spinner" aria-hidden="true"></div>
                <p class="req-table-loader-text">Loading requirements…</p>
            </div>
            <div class="req-table-content">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Req Code</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Requirement Title / Category</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Assigned Office</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Compliance Status</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Submission</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; width: 80px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="req-table-body">
                    <?php foreach ($requirements as $req): ?>
                        <?php 
                            $status = $req['status'];
                            $sData = $status_levels[$status] ?? ['label' => 'Pending', 'color' => '#f59e0b', 'bg' => '#fef3c7', 'icon' => '📥'];
                        ?>
                        <tr class="req-row" 
                            data-code="<?= htmlspecialchars($req['req_code']) ?>"
                            data-title="<?= htmlspecialchars($req['title']) ?>"
                            data-desc="<?= htmlspecialchars($req['description']) ?>"
                            data-category-id="<?= htmlspecialchars($req['category_id']) ?>"
                            data-office="<?= htmlspecialchars($req['assigned_office']) ?>"
                            data-status="<?= htmlspecialchars($req['status']) ?>">
                            
                            <td style="padding: 1.2rem; font-weight: 800; color: var(--accent-blue); font-size: 0.95rem;">
                                <?= htmlspecialchars($req['req_code']) ?>
                            </td>
                            
                            <td style="padding: 1.2rem;">
                                <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem; margin-bottom: 4px;"><?= htmlspecialchars($req['title']) ?></div>
                                <span style="font-size: 0.75rem; background: rgba(0, 28, 87, 0.05); color: var(--accent-blue); padding: 2px 6px; border-radius: 4px; font-weight: 700; text-transform: uppercase;"><?= htmlspecialchars($req['category']) ?></span>
                            </td>

                            <td style="padding: 1.2rem; font-weight: 600; color: #475569; font-size: 0.85rem;">
                                <?= htmlspecialchars($req['assigned_office']) ?>
                            </td>

                            <td style="padding: 1.2rem;">
                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; color: <?= $sData['color'] ?>; background: <?= $sData['bg'] ?>;">
                                    <span><?= $sData['icon'] ?></span>
                                    <span><?= $sData['label'] ?></span>
                                </span>
                            </td>

                            <td style="padding: 1.2rem;">
                                <?php if ($req['has_submission']): ?>
                                    <span style="background: #ecfdf5; color: #10b981; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700;">True</span>
                                <?php else: ?>
                                    <span style="background: #fef2f2; color: #ef4444; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700;">False</span>
                                <?php endif; ?>
                            </td>

                            <td style="padding: 1.2rem; text-align: right;">
                                <div class="action-dropdown">
                                    <button class="three-dots-btn" onclick="toggleDropdown(<?= $req['req_id'] ?>)">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                    </button>
                                    <div id="dropdown-<?= $req['req_id'] ?>" class="dropdown-menu">
                                        <button class="dropdown-item" onclick="viewDetails(<?= $req['req_id'] ?>)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View Details
                                        </button>
                                        <div style="border-top: 1px solid var(--border-color); margin: 4px 0;"></div>
                                        <button class="dropdown-item" onclick="openEditModal(<?= $req['req_id'] ?>)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            Edit Mapping
                                        </button>
                                        <button class="dropdown-item delete" onclick="deleteRequirement(<?= $req['req_id'] ?>)">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                            Delete Mapping
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="req-table-footer" style="padding: 1.2rem 2rem; background: #f8fafc; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; border-radius: 0 0 12px 12px;">
                <div style="font-size: 0.8rem; color: var(--text-secondary);" id="showing-count-container">Showing <b>0 - 0</b> of <b>0</b> requirements</div>
                <div id="pagination-controls" style="display: flex; gap: 5px; flex-wrap: wrap; align-items: center; justify-content: flex-end;"></div>
            </div>
        </div>
    </div>
</main>

<!-- Add Requirement Modal -->
<div id="addReqModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 550px; max-width: 90vw; max-height: 90vh; overflow-y: auto; scrollbar-width: thin; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h2 style="margin: 0; color: #0f172a; font-size: 1.4rem; font-weight: 800;">Register Accreditation Requirement</h2>
            <button onclick="document.getElementById('addReqModal').style.display='none'" style="background: transparent; border: none; font-size: 1.8rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <form onsubmit="event.preventDefault(); alert('Demo Mode: Save requirement is not wired yet as the database schema is in development.'); document.getElementById('addReqModal').style.display='none';" style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Requirement Code *</label>
                <input type="text" required placeholder="e.g. REQ-GOV-01" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Requirement Title *</label>
                <input type="text" required placeholder="e.g. Institutional Development Plan" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Assigned Department/Office *</label>
                <select required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                    <option value="">Select office...</option>
                    <?php foreach ($sys_offices as $so): ?>
                        <option value="<?= $so['name'] ?>"><?= htmlspecialchars($so['name']) ?> (<?= htmlspecialchars($so['acronym']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Area / Category *</label>
                    <select required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="">Select category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Compliance Status *</label>
                    <select required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="Pending">Pending</option>
                        <option value="Under Review">Under Review</option>
                        <option value="Approved">Approved</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Requirement Description / Details</label>
                <textarea rows="3" placeholder="Provide detailed guidelines and scope of this accreditation requirement..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; resize: vertical;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'"></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('addReqModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; background: var(--accent-blue); color: white;">Save Requirement</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Requirement Modal -->
<div id="editReqModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 550px; max-width: 90vw; max-height: 90vh; overflow-y: auto; scrollbar-width: thin; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h2 style="margin: 0; color: #0f172a; font-size: 1.4rem; font-weight: 800;">Edit Requirement Mapping</h2>
            <button onclick="document.getElementById('editReqModal').style.display='none'" style="background: transparent; border: none; font-size: 1.8rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <form onsubmit="event.preventDefault(); alert('Demo Mode: Edit requirement is not wired yet as the database schema is in development.'); document.getElementById('editReqModal').style.display='none';" style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Requirement Code *</label>
                <input type="text" id="edit_req_code" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Requirement Title *</label>
                <input type="text" id="edit_req_title" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Assigned Department/Office *</label>
                <select id="edit_req_office" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                    <?php foreach ($sys_offices as $so): ?>
                        <option value="<?= $so['name'] ?>"><?= htmlspecialchars($so['name']) ?> (<?= htmlspecialchars($so['acronym']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Area / Category *</label>
                    <select id="edit_req_category" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Compliance Status *</label>
                    <select id="edit_req_status" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="Pending">Pending</option>
                        <option value="Under Review">Under Review</option>
                        <option value="Approved">Approved</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Requirement Description / Details</label>
                <textarea id="edit_req_desc" rows="3" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; resize: vertical;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'"></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('editReqModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; background: var(--accent-blue); color: white;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- View Requirement Details Modal -->
<div id="viewReqModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2100; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 550px; max-width: 90vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <div>
                <span id="view_req_status_badge" style="padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;">Pending</span>
                <h2 id="view_req_title" style="margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 800; line-height: 1.3;">Requirement Title</h2>
                <p id="view_req_code" style="color: var(--accent-blue); font-size: 0.75rem; font-weight: 800; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">CODE: REQ-GOV-01</p>
            </div>
            <button onclick="document.getElementById('viewReqModal').style.display='none'" style="background: transparent; border: none; font-size: 2rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px;">Assigned Department / Office</span>
                <div id="view_req_office" style="font-size: 0.95rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    <span>Quality Assurance Office</span>
                </div>
            </div>

            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px;">Area / Category</span>
                <span id="view_req_category" style="font-size: 0.9rem; font-weight: 700; color: #0f172a;">Area I: Governance & Management</span>
            </div>

            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px;">Description / Guidelines</span>
                <p id="view_req_desc" style="margin: 0; font-size: 0.9rem; color: #334155; line-height: 1.5; white-space: pre-wrap; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); max-height: 150px; overflow-y: auto; scrollbar-width: thin;">Requirement details...</p>
            </div>

            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 6px;">Associated Tags</span>
                <div id="view_req_tags" style="display: flex; flex-wrap: wrap; gap: 4px;">
                    <!-- tags -->
                </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('viewReqModal').style.display='none'" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; background: var(--accent-blue); color: white;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    // --- Data from PHP ---
    const allAccreditations = <?= json_encode($all_accreditations) ?>;
    const allCategories = <?= json_encode($all_categories) ?>;
    const allRequirements = <?= json_encode($requirements) ?>;
    const statusLevels = <?= json_encode($status_levels) ?>;

    // --- State ---
    let selectedAccreditationId = null;
    let selectedCategoryIds = []; // Parent / child category selections after accreditation
    let currentPage = parseInt(sessionStorage.getItem('accmappingPage')) || 1;
    const itemsPerPage = 10;
    let isTableInitialLoad = true;

    // --- Category helpers ---
    function isRootCategory(cat) {
        const pid = cat.parent_category_id;
        return pid === null || pid === '' || pid === 0 || pid === '0';
    }

    function getParentCategories(accreditationId) {
        return allCategories.filter(c =>
            String(c.accreditation_id) === String(accreditationId) && isRootCategory(c)
        );
    }

    function getChildCategories(parentId) {
        return allCategories.filter(c => String(c.parent_category_id) === String(parentId));
    }

    function getCategoryIdsForAccreditation(accreditationId) {
        return allCategories
            .filter(c => String(c.accreditation_id) === String(accreditationId))
            .map(c => String(c.category_id));
    }

    function getDescendantCategoryIds(categoryId) {
        let ids = [String(categoryId)];
        getChildCategories(categoryId).forEach(child => {
            ids = ids.concat(getDescendantCategoryIds(child.category_id));
        });
        return ids;
    }

    // --- Cascading dropdowns: accreditation → parent category → child categories ---
    function buildDropdowns() {
        const container = document.getElementById('dynamic-dropdowns');
        container.innerHTML = '';

        if (allAccreditations.length === 0) return;

        addAccreditationDropdown(container);

        if (selectedAccreditationId) {
            const parents = getParentCategories(selectedAccreditationId);
            if (parents.length > 0) {
                addCategoryDropdownLevel(container, parents, 0);
            }
        }
    }

    function addAccreditationDropdown(container) {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'min-width: 220px;';
        wrapper.setAttribute('data-dropdown-type', 'accreditation');

        const select = document.createElement('select');
        select.style.cssText = 'width: 100%; padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 30px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); outline: none; background: white; cursor: pointer; transition: all 0.2s ease;';
        select.addEventListener('focus', () => select.style.borderColor = 'var(--accent-blue)');
        select.addEventListener('blur', () => select.style.borderColor = 'var(--border-color)');

        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = 'Select Accreditation...';
        select.appendChild(defaultOpt);

        allAccreditations.forEach(acc => {
            const opt = document.createElement('option');
            opt.value = acc.accreditation_id;
            opt.textContent = acc.code ? `${acc.name} (${acc.code})` : acc.name;
            select.appendChild(opt);
        });

        if (selectedAccreditationId) {
            select.value = selectedAccreditationId;
        }

        select.addEventListener('change', function() {
            selectedAccreditationId = this.value === '' ? null : this.value;
            selectedCategoryIds = [];
            currentPage = 1;
            updateAllTabState();
            buildDropdowns();
            searchRequirements();
        });

        wrapper.appendChild(select);
        container.appendChild(wrapper);
    }

    function addCategoryDropdownLevel(container, options, level) {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'min-width: 220px;';
        wrapper.setAttribute('data-dropdown-type', 'category');
        wrapper.setAttribute('data-dropdown-level', level);

        const select = document.createElement('select');
        select.style.cssText = 'width: 100%; padding: 0.6rem 1rem; border: 1px solid var(--border-color); border-radius: 30px; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); outline: none; background: white; cursor: pointer; transition: all 0.2s ease;';
        select.addEventListener('focus', () => select.style.borderColor = 'var(--accent-blue)');
        select.addEventListener('blur', () => select.style.borderColor = 'var(--border-color)');

        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = level === 0 ? 'Select Category...' : 'Select Sub-category...';
        select.appendChild(defaultOpt);

        options.forEach(cat => {
            const opt = document.createElement('option');
            opt.value = cat.category_id;
            opt.textContent = cat.name;
            select.appendChild(opt);
        });

        if (selectedCategoryIds[level]) {
            select.value = selectedCategoryIds[level];
        }

        select.addEventListener('change', function() {
            const val = this.value;

            container.querySelectorAll('[data-dropdown-type="category"]').forEach(w => {
                if (parseInt(w.getAttribute('data-dropdown-level')) > level) {
                    w.remove();
                }
            });

            selectedCategoryIds = selectedCategoryIds.slice(0, level);

            if (val === '') {
                currentPage = 1;
                updateAllTabState();
                searchRequirements();
                return;
            }

            selectedCategoryIds[level] = val;
            currentPage = 1;
            updateAllTabState();

            const children = getChildCategories(val);
            if (children.length > 0) {
                addCategoryDropdownLevel(container, children, level + 1);
            }

            searchRequirements();
        });

        wrapper.appendChild(select);
        container.appendChild(wrapper);

        if (selectedCategoryIds[level]) {
            const children = getChildCategories(selectedCategoryIds[level]);
            if (children.length > 0 && selectedCategoryIds.length > level + 1) {
                addCategoryDropdownLevel(container, children, level + 1);
            }
        }
    }

    function updateAllTabState() {
        const allTab = document.getElementById('all-categories-tab');
        if (selectedAccreditationId || selectedCategoryIds.length > 0) {
            allTab.classList.remove('active');
        } else {
            allTab.classList.add('active');
        }
    }

    function resetCategoryFilters() {
        selectedAccreditationId = null;
        selectedCategoryIds = [];
        currentPage = 1;
        const allTab = document.getElementById('all-categories-tab');
        allTab.classList.add('active');
        buildDropdowns();
        searchRequirements();
    }

    // --- Dropdown toggle for action buttons ---
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

    function resetPageAndSearch() {
        currentPage = 1;
        searchRequirements();
    }

    // --- Main search/filter/paginate ---
    function searchRequirements() {
        const searchTerm = document.getElementById('requirementSearch').value.toLowerCase();
        const officeFilter = document.getElementById('officeFilter').value;
        const statusFilter = document.getElementById('statusFilter').value;
        
        // Determine which category_ids to match
        let allowedCategoryIds = null; // null means all
        if (selectedCategoryIds.length > 0) {
            const deepest = selectedCategoryIds[selectedCategoryIds.length - 1];
            allowedCategoryIds = getDescendantCategoryIds(deepest);
        } else if (selectedAccreditationId) {
            allowedCategoryIds = getCategoryIdsForAccreditation(selectedAccreditationId);
        }

        const rows = document.querySelectorAll('.req-row');
        let matchingRows = [];

        rows.forEach(row => {
            const code = (row.getAttribute('data-code') || '').toLowerCase();
            const title = (row.getAttribute('data-title') || '').toLowerCase();
            const desc = (row.getAttribute('data-desc') || '').toLowerCase();
            const catId = row.getAttribute('data-category-id');
            const office = row.getAttribute('data-office');
            const status = row.getAttribute('data-status');

            const matchesSearch = !searchTerm || code.includes(searchTerm) || title.includes(searchTerm) || desc.includes(searchTerm);
            const matchesOffice = officeFilter === 'all' || office === officeFilter;
            const matchesStatus = statusFilter === 'all' || status === statusFilter;
            const matchesCategory = allowedCategoryIds === null || allowedCategoryIds.includes(String(catId));

            if (matchesSearch && matchesOffice && matchesStatus && matchesCategory) {
                matchingRows.push(row);
            } else {
                row.style.display = 'none';
            }
        });

        const totalItems = matchingRows.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;

        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        sessionStorage.setItem('accmappingPage', currentPage);

        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;

        matchingRows.forEach((row, index) => {
            row.style.display = (index >= startIndex && index < endIndex) ? '' : 'none';
        });

        const actualStart = totalItems === 0 ? 0 : startIndex + 1;
        const actualEnd = Math.min(endIndex, totalItems);

        const countContainer = document.getElementById('showing-count-container');
        if (countContainer) {
            countContainer.innerHTML = `Showing <b>${actualStart} - ${actualEnd}</b> of <b>${totalItems}</b> requirements`;
        }

        updatePaginationUI(totalPages);

        if (isTableInitialLoad) {
            isTableInitialLoad = false;
            const section = document.getElementById('req-table-section');
            const loader = document.getElementById('req-table-loading');
            if (section) section.classList.remove('is-loading');
            if (loader) loader.setAttribute('aria-busy', 'false');
        }
    }

    function getPaginationPages(current, total) {
        if (total <= 6) {
            return Array.from({ length: total }, (_, i) => i + 1);
        }

        if (current <= 4) {
            const pages = [1, 2, 3, 4];
            if (total > 5) {
                pages.push('ellipsis');
                pages.push(total);
            } else {
                pages.push(5);
            }
            return pages;
        }

        if (current >= total - 3) {
            const pages = [1, 'ellipsis'];
            for (let i = total - 3; i <= total; i++) {
                pages.push(i);
            }
            return pages;
        }

        return [1, 'ellipsis', current - 1, current, current + 1, 'ellipsis', total];
    }

    function updatePaginationUI(totalPages) {
        const controls = document.getElementById('pagination-controls');
        if (!controls) return;
        controls.innerHTML = '';

        const btnBase = 'padding: 5px 10px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px; min-width: 32px;';
        const btnActive = btnBase + ' background: var(--accent-blue); color: white; cursor: default;';
        const btnInactive = btnBase + ' cursor: pointer;';

        const prevBtn = document.createElement('button');
        prevBtn.innerText = 'Previous';
        prevBtn.style.cssText = btnInactive;
        if (currentPage === 1) {
            prevBtn.disabled = true;
            prevBtn.style.opacity = '0.5';
            prevBtn.style.cursor = 'default';
        } else {
            prevBtn.onclick = () => { currentPage--; searchRequirements(); };
        }
        controls.appendChild(prevBtn);

        getPaginationPages(currentPage, totalPages).forEach(item => {
            if (item === 'ellipsis') {
                const span = document.createElement('span');
                span.textContent = '…';
                span.style.cssText = 'padding: 5px 6px; font-size: 0.8rem; color: var(--text-secondary); user-select: none;';
                controls.appendChild(span);
                return;
            }

            const pageBtn = document.createElement('button');
            pageBtn.innerText = item;
            pageBtn.style.cssText = item === currentPage ? btnActive : btnInactive;
            if (item !== currentPage) {
                pageBtn.onclick = () => { currentPage = item; searchRequirements(); };
            }
            controls.appendChild(pageBtn);
        });

        const nextBtn = document.createElement('button');
        nextBtn.innerText = 'Next';
        nextBtn.style.cssText = btnInactive;
        if (currentPage === totalPages) {
            nextBtn.disabled = true;
            nextBtn.style.opacity = '0.5';
            nextBtn.style.cursor = 'default';
        } else {
            nextBtn.onclick = () => { currentPage++; searchRequirements(); };
        }
        controls.appendChild(nextBtn);
    }

    // --- View / Edit / Delete ---
    function viewDetails(id) {
        const req = allRequirements.find(r => r.req_id == id);
        if (!req) return;

        const sData = statusLevels[req.status] || {label: req.status, color: '#64748b', bg: '#f1f5f9', icon: '❓'};
        const badge = document.getElementById('view_req_status_badge');
        badge.textContent = sData.label;
        badge.style.cssText = `padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px; color: ${sData.color}; background: ${sData.bg};`;

        document.getElementById('view_req_title').textContent = req.title;
        document.getElementById('view_req_code').textContent = 'CODE: ' + req.req_code;
        document.getElementById('view_req_office').querySelector('span').textContent = req.assigned_office;
        document.getElementById('view_req_category').textContent = req.category;
        document.getElementById('view_req_desc').textContent = req.description || 'No description recorded.';

        const tagsContainer = document.getElementById('view_req_tags');
        tagsContainer.innerHTML = '<span style="color:#94a3b8; font-size:0.8rem; font-style:italic;">No tags</span>';

        document.getElementById('viewReqModal').style.display = 'flex';
    }

    function openEditModal(id) {
        const req = allRequirements.find(r => r.req_id == id);
        if (!req) return;

        document.getElementById('edit_req_code').value = req.req_code;
        document.getElementById('edit_req_title').value = req.title;
        document.getElementById('edit_req_office').value = req.assigned_office;
        document.getElementById('edit_req_category').value = req.category;
        document.getElementById('edit_req_status').value = req.status;
        document.getElementById('edit_req_desc').value = req.description;

        document.getElementById('editReqModal').style.display = 'flex';
    }

    function deleteRequirement(id) {
        if (confirm('Are you sure you want to delete this accreditation requirement mapping?')) {
            alert('Demo Mode: Delete action is not wired to the backend yet.');
        }
    }

    function escapeHtml(string) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(string).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // --- Initialize ---
    window.addEventListener('DOMContentLoaded', () => {
        buildDropdowns();
        // Defer pagination so the loading state paints before processing rows
        requestAnimationFrame(() => {
            requestAnimationFrame(() => searchRequirements());
        });
    });

    // Close action menus when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });
</script>
