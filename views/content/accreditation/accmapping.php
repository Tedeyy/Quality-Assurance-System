<?php
require_once __DIR__ . '/../../../config/database.php';
$db = (new Database())->getConnection();

$stmt = $db->query("SELECT office_id, name, acronym FROM divisions_offices ORDER BY name ASC");
$sys_offices = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories = [];

// QAO check for add/delete proof
$stmt = $db->prepare("
    SELECT o.name
    FROM users u
    LEFT JOIN divisions_offices o ON u.office_id = o.office_id
    WHERE u.user_id = :id
");
$stmt->execute(['id' => $_SESSION['user_id'] ?? 0]);
$user_office_name = $stmt->fetchColumn();
$is_qao = (stripos($user_office_name ?? '', 'Quality Assurance') !== false) || (($_SESSION['user_office_id'] ?? 0) == 4);

function getProofDisplayMeta(array $bridge): array {
    if (!empty($bridge['document_id'])) {
        return [
            'status' => 'Linked',
            'status_color' => '#10b981',
            'status_bg' => '#ecfdf5',
            'detail' => $bridge['doc_code'] ?? 'Document',
            'office' => null,
        ];
    }
    if (!empty($bridge['submission_id'])) {
        $status = $bridge['sub_status'] ?? 'Pending';
        $colors = [
            'Approved' => ['#10b981', '#ecfdf5'],
            'Pending' => ['#3b82f6', '#eff6ff'],
            'Uploaded' => ['#3b82f6', '#eff6ff'],
            'Returned' => ['#ef4444', '#fef2f2'],
        ];
        [$color, $bg] = $colors[$status] ?? ['#f59e0b', '#fef3c7'];
        return [
            'status' => $status,
            'status_color' => $color,
            'status_bg' => $bg,
            'detail' => 'File submission',
            'office' => $bridge['office_name'] ?? null,
        ];
    }
    return [
        'status' => null,
        'status_color' => null,
        'status_bg' => null,
        'detail' => null,
        'office' => null,
    ];
}

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

    .proof-list-cell {
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-width: 360px;
    }

    .proof-chip {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
        padding: 6px 8px;
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.78rem;
    }

    .proof-chip-name {
        font-weight: 700;
        color: #1e293b;
        flex: 1;
        min-width: 100px;
    }

    .proof-chip-status {
        padding: 2px 8px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 0.7rem;
        white-space: nowrap;
    }

    .proof-chip-office {
        width: 100%;
        font-size: 0.72rem;
        color: var(--text-secondary);
        font-weight: 600;
    }

    .proof-empty {
        font-size: 0.8rem;
        color: var(--text-secondary);
        font-style: italic;
    }

    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(6px);
        z-index: 3000;
    }

    .modal-overlay .modal-content {
        background: white;
        border-radius: 16px;
        padding: 2rem;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.2);
        margin: auto;
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
            </div>
            
            <button onclick="document.getElementById('addReqModal').style.display='flex'" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px; background: var(--accent-blue); color: white; font-weight: 700; font-size: 0.85rem; padding: 12px 24px; border: none; border-radius: 10px; cursor: pointer; box-shadow: 0 4px 10px rgba(0, 28, 87, 0.2); transition: all 0.2s;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Map Requirement
            </button>
        </div>

        <!-- Dynamic Category Tabs / Dropdown filter -->
        <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
            <button class="category-tab active" id="all-categories-tab" onclick="resetCategoryFilters()">Clear Filters</button>
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

            </div>
        </div>

        <!-- Table Grid -->
        <div id="req-table-section" style="background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: visible; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);">
            <div id="req-table-loading" class="req-table-loader" aria-live="polite" aria-busy="false">
                <div class="req-table-spinner" aria-hidden="true"></div>
                <p class="req-table-loader-text">Loading requirements…</p>
            </div>
            <div class="req-table-content">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Req Code</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Requirement Title / Category</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Proofs of Compliance</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; width: 80px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="req-table-body">
                    <tr id="req-filter-prompt-row">
                        <td colspan="4" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.8rem;"><path d="M3 4h18l-7 8v6l-4 2v-8L3 4z"/></svg>
                            <p style="margin: 0; font-weight: 700; font-size: 0.95rem;">Select an accreditation or category filter to view requirements.</p>
                            <p style="margin: 0.35rem 0 0 0; font-size: 0.85rem;">Requirement data is loaded in the background and will appear after a filter is selected.</p>
                        </td>
                    </tr>
                    <tr id="req-no-results-row" style="display: none;">
                        <td colspan="4" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                            <p style="margin: 0; font-weight: 700; font-size: 0.95rem;">No requirements match the selected filter.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
            <div class="req-table-footer" style="padding: 1.2rem 2rem; background: #f8fafc; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; border-radius: 0 0 12px 12px;">
                <div style="font-size: 0.8rem; color: var(--text-secondary);" id="showing-count-container">Select a filter to view requirements</div>
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

<!-- View Requirement Details Modal -->
<div id="viewReqModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2100; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 760px; max-width: 92vw; max-height: 88vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <div>
                <h2 id="view_req_title" style="margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 800; line-height: 1.3;">Requirement Title</h2>
                <p id="view_req_code" style="color: var(--accent-blue); font-size: 0.75rem; font-weight: 800; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">CODE: REQ-GOV-01</p>
            </div>
            <button onclick="document.getElementById('viewReqModal').style.display='none'" style="background: transparent; border: none; font-size: 2rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 4px;">Area / Category</span>
                <span id="view_req_category" style="font-size: 0.9rem; font-weight: 700; color: #0f172a;">Area I: Governance & Management</span>
            </div>

            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 6px;">Proofs of Compliance</span>
                <div id="view_req_proofs" style="display: flex; flex-direction: column; gap: 8px;"></div>
            </div>

            <div>
                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 6px;">Submissions</span>
                <div id="view_req_submissions" style="display: flex; flex-direction: column; gap: 10px;"></div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('viewReqModal').style.display='none'" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none; background: var(--accent-blue); color: white;">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Compliance Tracker Modal (proofs) -->
<div id="complianceTrackerModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 900px; width: 95%; max-height: 85vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <span id="comp_req_codename" style="font-weight: 700; color: var(--accent-blue); font-size: 0.9rem;"></span>
                <h2 id="comp_req_title" style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Proofs of Compliance</h2>
            </div>
            <button type="button" onclick="closeComplianceTrackerModal()" style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        <div style="flex: 1; overflow-y: auto; padding-right: 5px;">
            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                <div style="font-weight: 600; font-size: 0.9rem; color: var(--accent-blue);">Proof completion</div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 150px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                        <div id="comp_progress_bar" style="width: 0%; height: 100%; background: #22c55e; transition: width 0.3s;"></div>
                    </div>
                    <span id="comp_progress_text" style="font-weight: 700; font-size: 0.85rem; color: #22c55e;">0%</span>
                </div>
            </div>
            <?php if ($is_qao): ?>
            <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px dashed var(--border-color); margin-bottom: 1.5rem;">
                <h3 style="font-size: 0.85rem; margin: 0 0 0.8rem 0; color: var(--accent-blue);">Add Required Proof of Compliance</h3>
                <form action="../api/accreditation.php?action=add_proof" method="POST" id="addProofForm" style="display: flex; flex-direction: column; gap: 10px;">
                    <input type="hidden" name="accreditation_id" id="add_proof_acc_id" value="">
                    <input type="hidden" name="requirement_id" id="add_proof_req_id">
                    <input type="hidden" name="redirect_url" value="../views/feed.php?action=accmapping">
                    <div id="add_proof_fields_container" style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" name="proof_names[]" required placeholder="e.g. Syllabus, Class Schedule" style="flex: 1; padding: 0.5rem 0.8rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: 8px;">
                            <div style="width: 28px;"></div>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px;">
                        <button type="button" onclick="addProofField()" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: transparent; border: 1px solid var(--accent-blue); color: var(--accent-blue); border-radius: 6px; cursor: pointer;">+ Add Another Proof</button>
                        <button type="submit" style="padding: 0.5rem 1rem; font-size: 0.85rem; background: var(--accent-blue); color: white; border: none; border-radius: 6px; cursor: pointer;">Save Proofs</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            <div id="proofs_container" style="display: flex; flex-direction: column; gap: 1rem;"></div>
        </div>
    </div>
</div>

<!-- Link Document Modal -->
<div id="linkDocumentModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 800px; width: 90%; max-height: 80vh; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="color: var(--accent-blue); margin: 0; font-size: 1.25rem;">Select Institutional Document</h2>
            <button type="button" onclick="document.getElementById('linkDocumentModal').style.display='none'" style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <input type="text" id="doc_selector_search" placeholder="Search documents..." oninput="filterSelectorDocs()" style="width: 100%; padding: 0.6rem 1rem; margin-bottom: 1rem; border: 1px solid var(--border-color); border-radius: 8px;">
        <div style="flex: 1; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--border-color); text-align: left;">
                        <th style="padding: 12px 8px;">Code</th>
                        <th style="padding: 12px 8px;">Category</th>
                        <th style="padding: 12px 8px;">Purpose</th>
                        <th style="padding: 12px 8px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody id="doc_selector_rows"></tbody>
            </table>
            <p id="doc_selector_empty" style="display: none; text-align: center; color: var(--text-secondary); padding: 2rem;">No documents found.</p>
        </div>
    </div>
</div>

<script>
    // --- Browser-side cached data ---
    let allAccreditations = [];
    let allCategories = [];
    let allRequirements = [];
    let allInstitutionalDocs = [];
    const isQAOGlobal = <?= json_encode($is_qao) ?>;
    const accmappingCacheKey = 'qa.accmapping.dataset.v1';

    // --- State ---
    let selectedAccreditationId = null;
    let selectedCategoryIds = []; // Parent / child category selections after accreditation
    let currentPage = parseInt(sessionStorage.getItem('accmappingPage')) || 1;
    const itemsPerPage = 10;
    let requirementsDataReady = false;

    function readAccMappingCache() {
        try {
            const raw = localStorage.getItem(accmappingCacheKey);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writeAccMappingCache(version, data) {
        try {
            localStorage.setItem(accmappingCacheKey, JSON.stringify({
                version,
                cachedAt: Date.now(),
                data
            }));
        } catch (e) {
            console.warn('Accreditation mapping browser cache could not be saved.', e);
        }
    }

    function applyAccMappingDataset(data) {
        allAccreditations = data.allAccreditations || [];
        allCategories = data.allCategories || [];
        allRequirements = data.allRequirements || [];
        allInstitutionalDocs = data.allInstitutionalDocs || [];
        requirementsDataReady = true;
        renderRequirementRows();
        buildDropdowns();
        searchRequirements();
    }

    async function loadAccMappingDataset() {
        const cached = readAccMappingCache();
        if (cached?.data) {
            applyAccMappingDataset(cached.data);
        } else {
            setRequirementTableMessage('prompt');
        }

        try {
            const versionResponse = await fetch('../api/cache_data.php?dataset=accmapping&mode=version', {
                headers: { 'Accept': 'application/json' }
            });
            const versionPayload = await versionResponse.json();
            if (!versionPayload.success) throw new Error(versionPayload.message || 'Version check failed.');

            if (cached?.version === versionPayload.version && cached?.data) {
                return;
            }

            const dataResponse = await fetch('../api/cache_data.php?dataset=accmapping&mode=data', {
                headers: { 'Accept': 'application/json' }
            });
            const dataPayload = await dataResponse.json();
            if (!dataPayload.success) throw new Error(dataPayload.message || 'Dataset load failed.');

            writeAccMappingCache(dataPayload.version, dataPayload.data);
            applyAccMappingDataset(dataPayload.data);
        } catch (e) {
            console.error('Failed to load accreditation mapping cache.', e);
            const countContainer = document.getElementById('showing-count-container');
            if (countContainer) countContainer.textContent = 'Unable to load cached requirements.';
        } finally {
            hideRequirementLoading();
        }
    }

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
            runRequirementSearch();
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
                runRequirementSearch();
                return;
            }

            selectedCategoryIds[level] = val;
            currentPage = 1;
            updateAllTabState();

            const children = getChildCategories(val);
            if (children.length > 0) {
                addCategoryDropdownLevel(container, children, level + 1);
            }

            runRequirementSearch();
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

    function runRequirementSearch() {
        if (hasSelectedFilter() && !requirementsDataReady) {
            showRequirementLoading();
            requestAnimationFrame(() => {
                requestAnimationFrame(() => searchRequirements());
            });
            return;
        }

        searchRequirements();
    }

    function hasSelectedFilter() {
        return Boolean(selectedAccreditationId || selectedCategoryIds.length > 0);
    }

    function setRequirementTableMessage(type, totalItems = 0) {
        const promptRow = document.getElementById('req-filter-prompt-row');
        const noResultsRow = document.getElementById('req-no-results-row');
        const countContainer = document.getElementById('showing-count-container');

        if (promptRow) promptRow.style.display = type === 'prompt' ? '' : 'none';
        if (noResultsRow) noResultsRow.style.display = type === 'empty' ? '' : 'none';

        if (countContainer) {
            if (type === 'prompt') {
                countContainer.innerHTML = 'Select a filter to view requirements';
            } else if (type === 'empty') {
                countContainer.innerHTML = 'Showing <b>0 - 0</b> of <b>0</b> requirements';
            } else {
                countContainer.innerHTML = `Showing <b>${totalItems === 0 ? 0 : 1} - ${Math.min(itemsPerPage, totalItems)}</b> of <b>${totalItems}</b> requirements`;
            }
        }
    }

    function showRequirementLoading() {
        const section = document.getElementById('req-table-section');
        const loader = document.getElementById('req-table-loading');
        if (section) section.classList.add('is-loading');
        if (loader) loader.setAttribute('aria-busy', 'true');
    }

    function hideRequirementLoading() {
        const section = document.getElementById('req-table-section');
        const loader = document.getElementById('req-table-loading');
        if (section) section.classList.remove('is-loading');
        if (loader) loader.setAttribute('aria-busy', 'false');
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
        runRequirementSearch();
    }

    function renderRequirementRows() {
        const body = document.getElementById('req-table-body');
        if (!body) return;

        const rowsHtml = allRequirements.map(req => `
            <tr class="req-row"
                style="display: none;"
                data-req-id="${escapeHtml(req.req_id)}"
                data-code="${escapeHtml(req.req_code)}"
                data-title="${escapeHtml(req.title)}"
                data-desc="${escapeHtml(req.description || '')}"
                data-category-id="${escapeHtml(req.category_id)}"
                data-accreditation-id="${escapeHtml(req.accreditation_id || '')}">
                <td style="padding: 1.2rem; font-weight: 800; color: var(--accent-blue); font-size: 0.95rem;">
                    ${escapeHtml(req.req_code)}
                </td>
                <td style="padding: 1.2rem;">
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem; margin-bottom: 4px;">${escapeHtml(req.title)}</div>
                    <span style="font-size: 0.75rem; background: rgba(0, 28, 87, 0.05); color: var(--accent-blue); padding: 2px 6px; border-radius: 4px; font-weight: 700; text-transform: uppercase;">${escapeHtml(req.category)}</span>
                </td>
                <td style="padding: 1.2rem;">
                    <div class="proof-list-cell" id="proof-list-${escapeHtml(req.req_id)}">
                        ${(req.proofs || []).length ? (req.proofs || []).map(renderProofChip).join('') : '<span class="proof-empty">No proofs defined</span>'}
                    </div>
                </td>
                <td style="padding: 1.2rem; text-align: right;">
                    <div class="action-dropdown">
                        <button class="three-dots-btn" onclick="toggleDropdown(${Number(req.req_id)})">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                        </button>
                        <div id="dropdown-${escapeHtml(req.req_id)}" class="dropdown-menu">
                            <button class="dropdown-item" onclick="openManageProofs(${Number(req.req_id)})">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                Manage Proofs
                            </button>
                            <div style="border-top: 1px solid var(--border-color); margin: 4px 0;"></div>
                            <button class="dropdown-item" onclick="viewDetails(${Number(req.req_id)})">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                View Details
                            </button>
                            <button class="dropdown-item delete" onclick="deleteRequirement(${Number(req.req_id)})">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                Delete Mapping
                            </button>
                        </div>
                    </div>
                </td>
            </tr>
        `).join('');

        body.innerHTML = rowsHtml + `
            <tr id="req-filter-prompt-row">
                <td colspan="4" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.8rem;"><path d="M3 4h18l-7 8v6l-4 2v-8L3 4z"/></svg>
                    <p style="margin: 0; font-weight: 700; font-size: 0.95rem;">Select an accreditation or category filter to view requirements.</p>
                    <p style="margin: 0.35rem 0 0 0; font-size: 0.85rem;">Requirement data is loaded in the background and will appear after a filter is selected.</p>
                </td>
            </tr>
            <tr id="req-no-results-row" style="display: none;">
                <td colspan="4" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                    <p style="margin: 0; font-weight: 700; font-size: 0.95rem;">No requirements match the selected filter.</p>
                </td>
            </tr>
        `;
    }

    // --- Main search/filter/paginate ---
    function searchRequirements() {
        const searchTerm = document.getElementById('requirementSearch').value.toLowerCase();
        const rows = document.querySelectorAll('.req-row');

        if (!hasSelectedFilter()) {
            rows.forEach(row => row.style.display = 'none');
            setRequirementTableMessage('prompt');
            updatePaginationUI(0);
            hideRequirementLoading();
            return;
        }
        
        // Determine which category_ids to match
        let allowedCategoryIds = null; // null means all
        if (selectedCategoryIds.length > 0) {
            const deepest = selectedCategoryIds[selectedCategoryIds.length - 1];
            allowedCategoryIds = getDescendantCategoryIds(deepest);
        } else if (selectedAccreditationId) {
            allowedCategoryIds = getCategoryIdsForAccreditation(selectedAccreditationId);
        }

        let matchingRows = [];

        rows.forEach(row => {
            const code = (row.getAttribute('data-code') || '').toLowerCase();
            const title = (row.getAttribute('data-title') || '').toLowerCase();
            const desc = (row.getAttribute('data-desc') || '').toLowerCase();
            const catId = row.getAttribute('data-category-id');

            const matchesSearch = !searchTerm || code.includes(searchTerm) || title.includes(searchTerm) || desc.includes(searchTerm);
            const matchesCategory = allowedCategoryIds === null || allowedCategoryIds.includes(String(catId));

            if (matchesSearch && matchesCategory) {
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

        setRequirementTableMessage(totalItems === 0 ? 'empty' : 'results', totalItems);

        const countContainer = document.getElementById('showing-count-container');
        if (countContainer) {
            countContainer.innerHTML = `Showing <b>${actualStart} - ${actualEnd}</b> of <b>${totalItems}</b> requirements`;
        }

        updatePaginationUI(totalPages);
        requirementsDataReady = true;
        hideRequirementLoading();
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
        if (totalPages < 1) return;

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

    // --- Proofs of compliance ---
    let currentRequirement = null;
    let activeRequirementBridges = [];
    let currentBridgeIdToLink = null;

    function getProofMeta(b) {
        if (b.document_id) {
            return { status: 'Linked', color: '#10b981', bg: '#ecfdf5', detail: (b.doc_code || 'Document') + (b.doc_category ? ' · ' + b.doc_category : ''), office: null };
        }
        if (b.submission_id) {
            const status = b.sub_status || 'Pending';
            const colors = { Approved: ['#10b981', '#ecfdf5'], Pending: ['#3b82f6', '#eff6ff'], Uploaded: ['#3b82f6', '#eff6ff'], Returned: ['#ef4444', '#fef2f2'] };
            const [color, bg] = colors[status] || ['#f59e0b', '#fef3c7'];
            return { status, color, bg, detail: b.sub_link ? 'File uploaded' : 'Submission on file', office: b.office_name || null };
        }
        return { status: null, color: null, bg: null, detail: null, office: null };
    }

    function renderProofChip(b) {
        const meta = getProofMeta(b);
        const statusHTML = meta.status ? `<span class="proof-chip-status" style="color: ${meta.color}; background: ${meta.bg};">${escapeHtml(meta.status)}</span>` : '';
        const detailHTML = meta.detail ? `<span class="proof-chip-office">${escapeHtml(meta.detail)}</span>` : '';
        const officeHTML = meta.office ? `<span class="proof-chip-office">Office: ${escapeHtml(meta.office)}</span>` : '';

        return `
            <div class="proof-chip">
                <span class="proof-chip-name">${escapeHtml(b.proof_name)}</span>
                ${statusHTML}
                ${detailHTML}
                ${officeHTML}
            </div>
        `;
    }

    function refreshRequirementProofs(reqId) {
        const req = allRequirements.find(r => r.req_id == reqId);
        if (!req) return;

        req.proof_count = (req.proofs || []).length;
        req.proof_linked = (req.proofs || []).filter(p => p.document_id || p.submission_id).length;

        const proofList = document.getElementById(`proof-list-${reqId}`);
        if (proofList) {
            proofList.innerHTML = req.proofs.length
                ? req.proofs.map(renderProofChip).join('')
                : '<span class="proof-empty">No proofs defined</span>';
        }

        activeRequirementBridges = req.proofs || [];
        if (currentRequirement && currentRequirement.id == reqId) {
            openComplianceTracker(req.req_id, req.title, req.req_code, req.proofs || [], req.accreditation_id);
        }
    }

    function openManageProofs(reqId) {
        const req = allRequirements.find(r => r.req_id == reqId);
        if (!req) return;
        openComplianceTracker(req.req_id, req.title, req.req_code, req.proofs || [], req.accreditation_id);
    }

    function openComplianceTracker(reqId, reqName, reqCodename, bridges, accreditationId) {
        currentRequirement = { id: reqId, name: reqName, codename: reqCodename, accreditationId };
        activeRequirementBridges = bridges || [];

        document.getElementById('comp_req_codename').textContent = reqCodename ? reqCodename + ':' : '';
        document.getElementById('comp_req_title').textContent = reqName;

        const accInput = document.getElementById('add_proof_acc_id');
        const reqInput = document.getElementById('add_proof_req_id');
        if (accInput) accInput.value = accreditationId || '';
        if (reqInput) reqInput.value = reqId;

        const fieldsContainer = document.getElementById('add_proof_fields_container');
        if (fieldsContainer) {
            fieldsContainer.innerHTML = `
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="text" name="proof_names[]" required placeholder="e.g. Syllabus, Class Schedule" style="flex: 1; padding: 0.5rem 0.8rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: 8px;">
                    <div style="width: 28px;"></div>
                </div>
            `;
        }

        const container = document.getElementById('proofs_container');
        container.innerHTML = '';

        let linkedCount = 0;
        const totalCount = bridges.length;

        if (totalCount === 0) {
            container.innerHTML = '<p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">No proofs defined yet. Use the form above to add required proofs of compliance.</p>';
        } else {
            bridges.forEach(b => {
                const meta = getProofMeta(b);
                if (b.document_id || (b.submission_id && b.sub_status === 'Approved')) linkedCount++;

                let detailsHTML = '';
                if (b.document_id) {
                    detailsHTML = `<div style="font-size: 0.85rem; margin-top: 5px; color: var(--text-secondary);">Mapped to: <strong>${escapeHtml(b.doc_code || '')}</strong> (${escapeHtml(b.doc_category || '')})</div>`;
                } else if (b.submission_id) {
                    detailsHTML = `<div style="font-size: 0.85rem; margin-top: 5px; color: var(--text-secondary);">
                        ${b.office_name ? `Office: <strong>${escapeHtml(b.office_name)}</strong><br>` : ''}
                        ${b.sub_link ? `<a href="${escapeHtml(b.sub_link)}" target="_blank" style="color: var(--accent-blue);">View uploaded file</a>` : 'Submission recorded'}
                    </div>`;
                } else {
                    detailsHTML = '<p style="margin: 5px 0 0; font-size: 0.85rem; color: var(--text-secondary);">No document linked or file uploaded.</p>';
                }

                let actionsHTML = '';
                if (b.document_id || b.submission_id) {
                    actionsHTML = `<button type="button" onclick="unlinkProof(${b.bridge_id})" style="padding: 4px 8px; font-size: 0.75rem; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; border-radius: 6px; cursor: pointer;">Unlink</button>`;
                } else {
                    actionsHTML = `
                        <button type="button" onclick="openLinkDocumentSelector(${b.bridge_id})" style="padding: 4px 8px; font-size: 0.75rem; background: white; border: 1px solid var(--accent-blue); color: var(--accent-blue); border-radius: 6px; cursor: pointer; margin-right: 5px;">Link Document</button>
                    `;
                }

                const deleteBtn = isQAOGlobal ? `<button type="button" onclick="deleteProof(${b.bridge_id})" title="Delete proof" style="background: transparent; border: none; color: #ef4444; cursor: pointer; font-size: 1.1rem; line-height: 1;">&times;</button>` : '';
                const statusHTML = meta.status ? `<span style="background: ${meta.bg}; color: ${meta.color}; padding: 2px 8px; font-size: 0.75rem; border-radius: 4px; font-weight: 700;">${escapeHtml(meta.status)}</span>` : '';

                container.innerHTML += `
                    <div style="padding: 1rem; border: 1px solid var(--border-color); border-radius: 8px; background: white;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <h4 style="margin: 0; font-size: 0.95rem; color: var(--accent-blue);">${escapeHtml(b.proof_name)}</h4>
                                    ${deleteBtn}
                                </div>
                                ${detailsHTML}
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0;">
                                ${statusHTML}
                                ${actionsHTML}
                            </div>
                        </div>
                    </div>
                `;
            });
        }

        const pct = totalCount ? Math.round((linkedCount / totalCount) * 100) : 0;
        document.getElementById('comp_progress_bar').style.width = pct + '%';
        document.getElementById('comp_progress_text').textContent = pct + '%';

        document.getElementById('complianceTrackerModal').style.display = 'flex';
    }

    function closeComplianceTrackerModal() {
        document.getElementById('complianceTrackerModal').style.display = 'none';
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
                const req = allRequirements.find(r => (r.proofs || []).some(p => String(p.bridge_id) === String(bridgeId)));
                const linkedDoc = allInstitutionalDocs.find(doc => String(doc.doc_id) === String(docId));

                if (req) {
                    req.proofs = (req.proofs || []).map(proof => {
                        if (String(proof.bridge_id) !== String(bridgeId)) {
                            return proof;
                        }

                        return {
                            ...proof,
                            document_id: docId,
                            doc_code: linkedDoc ? linkedDoc.doc_code : proof.doc_code,
                            doc_category: linkedDoc ? linkedDoc.category : proof.doc_category,
                            doc_purpose: linkedDoc ? linkedDoc.purpose : proof.doc_purpose,
                            submission_id: null,
                            sub_status: null,
                            sub_link: null,
                            sub_path: null,
                            google_drive_file_id: null,
                            sub_remarks: null,
                            sub_user_id: null,
                            uploader_fname: null,
                            uploader_lname: null
                        };
                    });
                    refreshRequirementProofs(req.req_id);
                }

                document.getElementById('linkDocumentModal').style.display = 'none';
            } else alert(result.message || 'Failed to link document.');
        } catch (e) {
            alert('Failed to link document.');
        }
    }

    async function unlinkProof(bridgeId) {
        if (!confirm('Unlink this proof from its document or submission?')) return;
        try {
            const response = await fetch('../api/accreditation.php?action=unlink_proof', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `bridge_id=${bridgeId}`
            });
            const result = await response.json();
            if (result.success) window.location.reload();
            else alert(result.message || 'Failed to unlink proof.');
        } catch (e) {
            alert('Failed to unlink proof.');
        }
    }

    async function deleteProof(bridgeId) {
        if (!confirm('Delete this proof requirement?')) return;
        try {
            const response = await fetch(`../api/accreditation.php?action=delete_proof&bridge_id=${bridgeId}`);
            const result = await response.json();
            if (result.success) window.location.reload();
            else alert(result.message || 'Failed to delete proof.');
        } catch (e) {
            alert('Failed to delete proof.');
        }
    }

    function addProofField() {
        const container = document.getElementById('add_proof_fields_container');
        if (!container) return;
        const row = document.createElement('div');
        row.style.cssText = 'display: flex; gap: 8px; align-items: center;';
        row.innerHTML = `
            <input type="text" name="proof_names[]" required placeholder="e.g. Syllabus, Class Schedule" style="flex: 1; padding: 0.5rem 0.8rem; font-size: 0.85rem; border: 1px solid var(--border-color); border-radius: 8px;">
            <button type="button" onclick="this.parentElement.remove()" style="background: transparent; border: none; font-size: 1.25rem; color: #ef4444; cursor: pointer;">&times;</button>
        `;
        container.appendChild(row);
    }

    async function handleAddProofSubmit(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const reqId = form.querySelector('[name="requirement_id"]')?.value;
        const req = allRequirements.find(r => r.req_id == reqId);
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn ? submitBtn.textContent : '';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new FormData(form)
            });
            const result = await response.json();

            if (!result.success) {
                alert(result.message || 'Failed to add proofs.');
                return;
            }

            if (req) {
                req.proofs = req.proofs || [];
                req.proofs.push(...(result.proofs || []));
                refreshRequirementProofs(req.req_id);
            }
        } catch (error) {
            alert('Failed to add proofs.');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        }
    }

    function openLinkDocumentSelector(bridgeId) {
        currentBridgeIdToLink = bridgeId;
        document.getElementById('doc_selector_search').value = '';
        renderSelectorDocs();
        document.getElementById('linkDocumentModal').style.display = 'flex';
    }

    function renderSelectorDocs() {
        const query = (document.getElementById('doc_selector_search').value || '').toLowerCase();
        const container = document.getElementById('doc_selector_rows');
        const emptyMsg = document.getElementById('doc_selector_empty');
        container.innerHTML = '';

        const filtered = allInstitutionalDocs.filter(d => {
            const code = (d.doc_code || '').toLowerCase();
            const category = (d.category || '').toLowerCase();
            const purpose = (d.purpose || '').toLowerCase();
            return code.includes(query) || category.includes(query) || purpose.includes(query);
        });

        if (filtered.length === 0) {
            emptyMsg.style.display = 'block';
            return;
        }
        emptyMsg.style.display = 'none';
        filtered.forEach(d => {
            const tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid var(--border-color)';
            const purposeTrunc = d.purpose ? d.purpose.substring(0, 80) + (d.purpose.length > 80 ? '...' : '') : 'N/A';
            tr.innerHTML = `
                <td style="padding: 10px 8px; font-weight: 600; color: var(--accent-blue);">${escapeHtml(d.doc_code)}</td>
                <td style="padding: 10px 8px;">${escapeHtml(d.category)}</td>
                <td style="padding: 10px 8px;">${escapeHtml(purposeTrunc)}</td>
                <td style="padding: 10px 8px; text-align: right;">
                    <button type="button" onclick="linkInstitutionalDoc(${currentBridgeIdToLink}, ${d.doc_id})" style="padding: 4px 10px; font-size: 0.75rem; background: var(--accent-blue); color: white; border: none; border-radius: 6px; cursor: pointer;">Link</button>
                </td>
            `;
            container.appendChild(tr);
        });
    }

    function filterSelectorDocs() {
        renderSelectorDocs();
    }

    function getDrivePreviewUrl(link) {
        if (!link) return '';
        if (link.includes('/folders/')) return '';
        return link.includes('/view') ? link.replace('/view', '/preview') : link;
    }

    // --- View / Delete ---
    function viewDetails(id) {
        const req = allRequirements.find(r => r.req_id == id);
        if (!req) return;

        document.getElementById('view_req_title').textContent = req.title;
        document.getElementById('view_req_code').textContent = 'CODE: ' + req.req_code;
        document.getElementById('view_req_category').textContent = req.category;

        const proofsEl = document.getElementById('view_req_proofs');
        const proofs = req.proofs || [];
        if (proofs.length === 0) {
            proofsEl.innerHTML = '<span style="color:#94a3b8; font-size:0.85rem; font-style:italic;">No proofs defined</span>';
        } else {
            proofsEl.innerHTML = proofs.map(b => {
                const meta = getProofMeta(b);
                let officeLine = meta.office ? `<div style="font-size:0.75rem;color:#64748b;margin-top:2px;">Office: ${escapeHtml(meta.office)}</div>` : '';
                const statusBadge = meta.status ? `<span class="proof-chip-status" style="color:${meta.color};background:${meta.bg};">${escapeHtml(meta.status)}</span>` : '';
                return `<div class="proof-chip" style="margin:0;">
                    <span class="proof-chip-name">${escapeHtml(b.proof_name)}</span>
                    ${statusBadge}
                    ${meta.detail ? `<span class="proof-chip-office">${escapeHtml(meta.detail)}</span>` : ''}
                    ${officeLine}
                </div>`;
            }).join('');
        }

        const submissionsEl = document.getElementById('view_req_submissions');
        const submissions = proofs.filter(b => b.submission_id);
        if (submissions.length === 0) {
            submissionsEl.innerHTML = '<span style="color:#94a3b8; font-size:0.85rem; font-style:italic;">No submissions yet</span>';
        } else {
            submissionsEl.innerHTML = submissions.map(b => {
                const meta = getProofMeta(b);
                const uploader = [b.uploader_fname, b.uploader_lname].filter(Boolean).join(' ');
                const previewUrl = getDrivePreviewUrl(b.sub_link || '');
                const previewHTML = previewUrl
                    ? `<iframe src="${escapeHtml(previewUrl)}" title="${escapeHtml(b.proof_name)} preview" style="width:100%; height:260px; border:1px solid var(--border-color); border-radius:8px; background:#f8fafc;"></iframe>`
                    : `<div style="padding: 1rem; border: 1px dashed var(--border-color); border-radius: 8px; color: var(--text-secondary); font-size: 0.85rem;">Preview is unavailable for this submission. ${b.sub_link ? `<a href="${escapeHtml(b.sub_link)}" target="_blank" style="color: var(--accent-blue); font-weight: 700;">Open document</a>` : ''}</div>`;

                return `
                    <div style="border:1px solid var(--border-color); border-radius:8px; padding:12px; background:#fff;">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:10px;">
                            <div>
                                <div style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase;">Type of Proof</div>
                                <div style="font-size:0.95rem; color:var(--accent-blue); font-weight:800;">${escapeHtml(b.proof_name)}</div>
                                ${uploader ? `<div style="font-size:0.8rem; color:var(--text-secondary); margin-top:2px;">Submitted by ${escapeHtml(uploader)}</div>` : ''}
                                ${b.office_name ? `<div style="font-size:0.8rem; color:var(--text-secondary);">Office: ${escapeHtml(b.office_name)}</div>` : ''}
                            </div>
                            ${meta.status ? `<span class="proof-chip-status" style="color:${meta.color};background:${meta.bg};">${escapeHtml(meta.status)}</span>` : ''}
                        </div>
                        ${previewHTML}
                    </div>
                `;
            }).join('');
        }

        document.getElementById('viewReqModal').style.display = 'flex';
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
        searchRequirements();
        loadAccMappingDataset();

        const addProofForm = document.getElementById('addProofForm');
        if (addProofForm) {
            addProofForm.addEventListener('submit', handleAddProofSubmit);
        }
    });

    // Close action menus when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });
</script>
