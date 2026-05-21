<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Query accreditations with total and approved counts of requirements
$query = "
    SELECT a.*, 
           (SELECT COUNT(*) 
            FROM accreditation_requirement r 
            JOIN accreditation_categories c ON r.category_id = c.category_id 
            WHERE c.accreditation_id = a.accreditation_id) as total_reqs,
           (SELECT COUNT(*) 
            FROM accreditation_requirement r 
            JOIN accreditation_categories c ON r.category_id = c.category_id 
            JOIN accreditation_requirement_submissions s ON r.requirement_id = s.requirement_id
            WHERE c.accreditation_id = a.accreditation_id AND s.status = 'Approved') as approved_reqs
    FROM accreditations a
    ORDER BY a.name ASC
";
$stmt = $db->query($query);
$accreditations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    :root {
        --accent-blue: #1e3a8a;
        --accent-gold: #d97706;
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
        background: linear-gradient(135deg, rgba(30, 58, 138, 0.04) 0%, rgba(217, 119, 6 0.04) 100%);
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
        box-shadow: 0 4px 10px rgba(30, 58, 138, 0.15);
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
        <a href="feed.php?action=accreditation" style="display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-blue)'" onmouseout="this.style.color='var(--text-secondary)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Accreditation Panel
        </a>
    </div>

    <div class="qa-card" style="margin-bottom: 2rem;">
        <!-- Header -->
        <div class="qa-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
            <div>
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 0.4rem;">
                    <div style="background: rgba(30, 58, 138, 0.1); padding: 8px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--accent-blue);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    </div>
                    <h1 style="margin: 0; font-size: 1.8rem; font-weight: 800; color: #0f172a;">Accreditation Masterlist</h1>
                </div>
                <p style="margin: 0; color: var(--text-secondary); font-size: 0.95rem; font-weight: 500;">Comprehensive institutional accreditations and standards database registry</p>
            </div>
            
            <button onclick="document.getElementById('addAccModal').style.display='flex'" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; font-weight: 700; border-radius: 10px; cursor: pointer; box-shadow: 0 4px 12px rgba(30, 58, 138, 0.15);">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Accreditation
            </button>
        </div>

        <!-- Filters Section -->
        <div style="padding: 1.5rem 2rem; border-bottom: 1px solid var(--border-color); background: rgba(255,255,255,0.4);">
            <!-- Status Tabs -->
            <div class="month-tabs-container">
                <button class="month-tab active" onclick="filterByStatusTab('all', this)">All Statuses</button>
                <button class="month-tab" onclick="filterByStatusTab('In Progress', this)">In Progress</button>
                <button class="month-tab" onclick="filterByStatusTab('Completed', this)">Completed</button>
                <button class="month-tab" onclick="filterByStatusTab('Inactive', this)">Inactive</button>
            </div>

            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px; position: relative;">
                    <span style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); display: flex; align-items: center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </span>
                    <input type="text" id="accreditationSearch" oninput="searchAccreditations()" placeholder="Search by name, code or description..." style="width: 100%; padding: 0.8rem 1rem 0.8rem 2.8rem; border: 1px solid var(--border-color); border-radius: 10px; font-size: 0.9rem; outline: none; background: white; transition: border-color 0.2s;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                </div>

                <div style="width: 200px;">
                    <select id="statusFilter" onchange="searchAccreditations()" style="width: 100%; padding: 0.8rem 1rem; border: 1px solid var(--border-color); border-radius: 10px; font-size: 0.9rem; outline: none; background: white; cursor: pointer; transition: border-color 0.2s;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                        <option value="all">All Statuses</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Table Grid -->
        <div style="overflow-x: auto;">
            <table class="qa-table" style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr>
                        <th style="width: 80px; padding-left: 2rem;">Code</th>
                        <th>Accreditation / Standards Name</th>
                        <th>Deadline</th>
                        <th style="width: 220px;">Progress Percentage</th>
                        <th style="width: 140px;">Status</th>
                        <th style="width: 80px; text-align: right; padding-right: 2rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accreditations)): ?>
                        <tr>
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.8rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <p style="margin: 0; font-weight: 600; font-size: 0.95rem;">No accreditations found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($accreditations as $acc): ?>
                            <?php 
                                $total = (int)$acc['total_reqs'];
                                $approved = (int)$acc['approved_reqs'];
                                $pct = ($total > 0) ? round(($approved / $total) * 100) : 0;
                                
                                // Dynamic progress bar colors
                                $barColor = 'var(--accent-blue)';
                                if ($pct === 100) {
                                    $barColor = '#10b981';
                                } elseif ($pct > 50) {
                                    $barColor = '#3b82f6';
                                } elseif ($pct > 0) {
                                    $barColor = '#f59e0b';
                                } else {
                                    $barColor = '#cbd5e1';
                                }
                            ?>
                            <tr class="acc-row" 
                                data-code="<?= htmlspecialchars($acc['code']) ?>" 
                                data-name="<?= htmlspecialchars($acc['name']) ?>" 
                                data-desc="<?= htmlspecialchars($acc['description']) ?>"
                                data-status="<?= htmlspecialchars($acc['status']) ?>">
                                
                                <td style="padding: 1.2rem 1.2rem 1.2rem 2rem; font-weight: 800; color: var(--accent-blue); font-size: 0.9rem;">
                                    <?= htmlspecialchars($acc['code']) ?>
                                </td>
                                
                                <td style="padding: 1.2rem;">
                                    <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem; margin-bottom: 4px;"><?= htmlspecialchars($acc['name']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); max-width: 450px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($acc['description'] ?: 'No description provided.') ?>
                                    </div>
                                </td>
                                
                                <td style="padding: 1.2rem; font-size: 0.9rem; font-weight: 600; color: #334155;">
                                    <?php if ($acc['deadline']): ?>
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                            <?= date('M d, Y', strtotime($acc['deadline'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-weight: 500;">None Set</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td style="padding: 1.2rem;">
                                    <div style="display: flex; flex-direction: column; gap: 4px; width: 100%;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; font-weight: 700; color: #475569;">
                                            <span><?= $pct ?>%</span>
                                            <span style="color: #94a3b8; font-weight: 600;"><?= $approved ?>/<?= $total ?> Approved</span>
                                        </div>
                                        <div style="width: 100%; height: 6px; background: #e2e8f0; border-radius: 10px; overflow: hidden;">
                                            <div style="width: <?= $pct ?>%; height: 100%; background: <?= $barColor ?>; border-radius: 10px; transition: width 0.3s ease;"></div>
                                        </div>
                                    </div>
                                </td>
                                
                                <td style="padding: 1.2rem;">
                                    <?php
                                        $statusStyle = '';
                                        switch($acc['status']) {
                                            case 'Completed':
                                                $statusStyle = 'background: #dcfce7; color: #166534;';
                                                break;
                                            case 'In Progress':
                                                $statusStyle = 'background: #dbeafe; color: #1e40af;';
                                                break;
                                            default:
                                                $statusStyle = 'background: #f1f5f9; color: #475569;';
                                        }
                                    ?>
                                    <span style="<?= $statusStyle ?> padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block;"><?= $acc['status'] ?></span>
                                </td>
                                
                                <td style="padding: 1.2rem 2rem 1.2rem 1.2rem; text-align: right;">
                                    <div class="action-dropdown">
                                        <button class="three-dots-btn" onclick="toggleDropdown(<?= $acc['accreditation_id'] ?>)">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                        </button>
                                        <div id="dropdown-<?= $acc['accreditation_id'] ?>" class="dropdown-menu">
                                            <button class="dropdown-item" onclick="viewAccreditation(<?= $acc['accreditation_id'] ?>, '<?= htmlspecialchars($acc['code']) ?>', '<?= htmlspecialchars(addslashes($acc['name'])) ?>', '<?= htmlspecialchars(addslashes($acc['description'] ?? '')) ?>', '<?= $acc['deadline'] ?>', '<?= $acc['status'] ?>', <?= $total ?>, <?= $approved ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                View Details
                                            </button>
                                            
                                            <button class="dropdown-item" onclick="openEditModal(<?= $acc['accreditation_id'] ?>, '<?= htmlspecialchars($acc['code']) ?>', '<?= htmlspecialchars(addslashes($acc['name'])) ?>', '<?= htmlspecialchars(addslashes($acc['description'] ?? '')) ?>', '<?= $acc['deadline'] ?>', '<?= $acc['status'] ?>')">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit Accreditation
                                            </button>
                                            
                                            <div style="border-top: 1px solid var(--border-color); margin: 4px 0;"></div>
                                            
                                            <button class="dropdown-item delete" onclick="deleteAccreditation(<?= $acc['accreditation_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                Delete Accreditation
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
                <div style="font-size: 0.8rem; color: var(--text-secondary);">Showing <b id="showing-count"><?= count($accreditations) ?></b> accreditations</div>
                <div style="display: flex; gap: 5px;">
                    <button class="btn" style="padding: 5px 12px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px;">Previous</button>
                    <button class="btn" style="padding: 5px 12px; border: 1px solid var(--border-color); background: var(--accent-blue); color: white; font-size: 0.8rem; border-radius: 6px;">1</button>
                    <button class="btn" style="padding: 5px 12px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem; border-radius: 6px;">Next</button>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Add Accreditation Modal -->
<div id="addAccModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 520px; max-width: 90vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h2 style="margin: 0; color: #0f172a; font-size: 1.4rem; font-weight: 800;">Add New Accreditation</h2>
            <button onclick="document.getElementById('addAccModal').style.display='none'" style="background: transparent; border: none; font-size: 1.8rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=add" method="POST" style="display: flex; flex-direction: column; gap: 1.2rem;">
            <input type="hidden" name="redirect_url" value="../views/feed.php?action=accmasterlist">

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Code *</label>
                <input type="text" name="code" required placeholder="e.g. ISO 9001:2015" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Accreditation/Standards Name *</label>
                <input type="text" name="name" required placeholder="e.g. Quality Management System" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Description</label>
                <textarea name="description" rows="3" placeholder="Overview of the accreditation standard..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; resize: vertical;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'"></textarea>
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Deadline Date</label>
                <input type="date" name="deadline" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Initial Status *</label>
                <select name="status" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; background: white; cursor: pointer;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
                    <option value="In Progress">In Progress</option>
                    <option value="Completed">Completed</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('addAccModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer;">Create Standard</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Accreditation Modal -->
<div id="editAccModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 520px; max-width: 90vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <h2 style="margin: 0; color: #0f172a; font-size: 1.4rem; font-weight: 800;">Edit Accreditation</h2>
            <button onclick="document.getElementById('editAccModal').style.display='none'" style="background: transparent; border: none; font-size: 1.8rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <form action="../api/accreditation.php?action=edit" method="POST" style="display: flex; flex-direction: column; gap: 1.2rem;">
            <input type="hidden" name="redirect_url" value="../views/feed.php?action=accmasterlist">
            <input type="hidden" id="edit_acc_id" name="accreditation_id" value="">

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Code *</label>
                <input type="text" id="edit_acc_code" name="code" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Accreditation/Standards Name *</label>
                <input type="text" id="edit_acc_name" name="name" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <div>
                <label style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Description</label>
                <textarea id="edit_acc_desc" name="description" rows="3" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; resize: vertical;" onfocus="this.style.borderColor='var(--accent-blue)'" onblur="this.style.borderColor='var(--border-color)'"></textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('editAccModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- View Accreditation Details Modal -->
<div id="viewAccModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 600px; max-width: 90vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <div>
                <span id="view_acc_status" style="padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;">In Progress</span>
                <h2 id="view_acc_name" style="margin: 0; color: #0f172a; font-size: 1.5rem; font-weight: 800; line-height: 1.3;">Accreditation Title</h2>
                <p id="view_acc_code" style="color: #64748b; font-size: 0.8rem; font-weight: 700; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">CODE: ISO 9001</p>
            </div>
            <button onclick="document.getElementById('viewAccModal').style.display='none'" style="background: transparent; border: none; font-size: 2rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1.2rem;">
            <div>
                <h4 style="margin: 0 0 0.4rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Description</h4>
                <p id="view_acc_desc" style="margin: 0; font-size: 0.95rem; color: #334155; line-height: 1.5; white-space: pre-wrap; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); max-height: 120px; overflow-y: auto; scrollbar-width: thin;">Overview description of the accreditation...</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; background: #f8fafc; padding: 1.2rem; border-radius: 12px; border: 1px solid var(--border-color);">
                <div>
                    <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Deadline Date</span>
                    <span id="view_acc_deadline" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">None Set</span>
                </div>
                <div>
                    <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Requirements Checked</span>
                    <span id="view_acc_req_ratio" style="font-size: 0.95rem; font-weight: 700; color: var(--accent-blue);">0/0 Approved</span>
                </div>
            </div>

            <div>
                <h4 style="margin: 0 0 0.5rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Accreditation Progress Summary</h4>
                <div style="background: #f8fafc; padding: 1.2rem; border-radius: 12px; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 8px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem; font-weight: 700;">
                        <span>Overall Completion</span>
                        <span id="view_acc_pct_label" style="color: var(--accent-blue);">0%</span>
                    </div>
                    <div style="width: 100%; height: 10px; background: #e2e8f0; border-radius: 10px; overflow: hidden;">
                        <div id="view_acc_progress_bar" style="width: 0%; height: 100%; background: var(--accent-blue); border-radius: 10px; transition: width 0.4s ease-out;"></div>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
                <button type="button" onclick="document.getElementById('viewAccModal').style.display='none'" class="btn" style="padding: 10px 20px; font-weight: 600; border: 1px solid var(--border-color); background: white; color: #475569; border-radius: 8px; cursor: pointer;">Close</button>
                <a id="view_acc_sheet_btn" href="#" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 24px; font-weight: 700; border-radius: 8px; text-decoration: none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    Open Requirements Sheet
                </a>
            </div>
        </div>
    </div>
</div>

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

    let currentStatusTab = sessionStorage.getItem('accmasterlistStatus') || 'all';

    function filterByStatusTab(status, btn) {
        currentStatusTab = status;
        sessionStorage.setItem('accmasterlistStatus', status);
        document.querySelectorAll('.month-tab').forEach(t => t.classList.remove('active'));
        if (btn) btn.classList.add('active');
        searchAccreditations();
    }

    function searchAccreditations() {
        const searchTerm = document.getElementById('accreditationSearch').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('.acc-row');
        
        let visibleCount = 0;
        
        rows.forEach(row => {
            const code = row.getAttribute('data-code').toLowerCase();
            const name = row.getAttribute('data-name').toLowerCase();
            const desc = row.getAttribute('data-desc').toLowerCase();
            const status = row.getAttribute('data-status');
            
            const matchesSearch = code.includes(searchTerm) || name.includes(searchTerm) || desc.includes(searchTerm);
            const matchesSelectStatus = statusFilter === 'all' || status === statusFilter;
            const matchesTabStatus = currentStatusTab === 'all' || status === currentStatusTab;
            
            if (matchesSearch && matchesSelectStatus && matchesTabStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        document.getElementById('showing-count').textContent = visibleCount;
    }

    function viewAccreditation(id, code, name, description, deadline, status, total, approved) {
        // Status Badge Style
        const badge = document.getElementById('view_acc_status');
        badge.textContent = status;
        
        let statusStyle = '';
        switch(status) {
            case 'Completed':
                statusStyle = 'background: #dcfce7; color: #166534;';
                break;
            case 'In Progress':
                statusStyle = 'background: #dbeafe; color: #1e40af;';
                break;
            default:
                statusStyle = 'background: #f1f5f9; color: #475569;';
        }
        badge.style.cssText = statusStyle + ' padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;';
        
        // Text population
        document.getElementById('view_acc_name').textContent = name;
        document.getElementById('view_acc_code').textContent = 'CODE: ' + code;
        document.getElementById('view_acc_desc').textContent = description || 'No description provided.';
        
        // Deadline
        if (deadline && deadline !== '0000-00-00') {
            const dateObj = new Date(deadline);
            document.getElementById('view_acc_deadline').textContent = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        } else {
            document.getElementById('view_acc_deadline').textContent = 'None Set';
        }
        
        // Requirements Count Ratio
        document.getElementById('view_acc_req_ratio').textContent = `${approved}/${total} Approved`;
        
        // Progress percentage calculation
        const pct = total > 0 ? Math.round((approved / total) * 100) : 0;
        document.getElementById('view_acc_pct_label').textContent = `${pct}%`;
        
        // Progress bar width & color selection
        const pbar = document.getElementById('view_acc_progress_bar');
        pbar.style.width = `${pct}%`;
        
        let barColor = 'var(--accent-blue)';
        if (pct === 100) {
            barColor = '#10b981';
        } else if (pct > 50) {
            barColor = '#3b82f6';
        } else if (pct > 0) {
            barColor = '#f59e0b';
        } else {
            barColor = '#cbd5e1';
        }
        pbar.style.background = barColor;
        
        // Redirect button targets the requirement page
        document.getElementById('view_acc_sheet_btn').href = `feed.php?action=accreditation&accreditation_id=${id}`;
        
        // Show modal overlay
        document.getElementById('viewAccModal').style.display = 'flex';
    }

    function openEditModal(id, code, name, description, deadline, status) {
        document.getElementById('edit_acc_id').value = id;
        document.getElementById('edit_acc_code').value = code;
        document.getElementById('edit_acc_name').value = name;
        document.getElementById('edit_acc_desc').value = description;
        
        // Show Modal
        document.getElementById('editAccModal').style.display = 'flex';
    }

    async function deleteAccreditation(id) {
        if(confirm('WARNING: Deleting this accreditation will remove all of its categories, requirements, and submissions recursively. Are you sure you want to proceed?')) {
            try {
                const response = await fetch(`../api/accreditation.php?action=delete_accreditation&accreditation_id=${id}`);
                if (!response.ok) throw new Error('API server returned a failed response code.');
                const result = await response.json();
                
                if (result.success) {
                    alert('Accreditation deleted successfully!');
                    window.location.reload();
                } else {
                    alert('Failed to delete accreditation: ' + result.message);
                }
            } catch (e) {
                console.error(e);
                alert('An error occurred while deleting: ' + e.message);
            }
        }
    }

    // Close action menus when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });

    window.addEventListener('DOMContentLoaded', () => {
        const savedStatus = sessionStorage.getItem('accmasterlistStatus');
        if (savedStatus && savedStatus !== 'all') {
            document.querySelectorAll('.month-tab').forEach(t => {
                t.classList.remove('active');
                if (t.innerText.trim() === savedStatus || (savedStatus === 'In Progress' && t.innerText.trim() === 'In Progress') || (savedStatus === 'Completed' && t.innerText.trim() === 'Completed') || (savedStatus === 'Inactive' && t.innerText.trim() === 'Inactive')) {
                    t.classList.add('active');
                }
            });
            searchAccreditations();
        }
    });
</script>
