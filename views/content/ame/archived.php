<?php
require_once __DIR__ . '/../../../config/database.php';
$db = (new Database())->getConnection();

function ensureArchivedActivityColumns(PDO $db): void {
    $columns = $db->query("SHOW COLUMNS FROM activities")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_archived', $columns, true)) {
        $db->exec("ALTER TABLE activities ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER eventstatus");
    }
    if (!in_array('archived_at', $columns, true)) {
        $db->exec("ALTER TABLE activities ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER is_archived");
    }
}

$activities = [];
try {
    ensureArchivedActivityColumns($db);
    $query = "SELECT a.*, o.position AS office_position, s.overall_average, e.response_rate,
                     (
                        SELECT GROUP_CONCAT(sdg.title SEPARATOR ', ')
                        FROM activity_sdgs asg
                        JOIN sdgs sdg ON asg.sdg_id = sdg.sdg_id
                        WHERE asg.activity_id = a.activity_id
                     ) AS sdg_titles,
                     (
                        SELECT GROUP_CONCAT(sdg.sdg_id SEPARATOR ',')
                        FROM activity_sdgs asg
                        JOIN sdgs sdg ON asg.sdg_id = sdg.sdg_id
                        WHERE asg.activity_id = a.activity_id
                     ) AS sdg_ids
              FROM activities a
              LEFT JOIN divisions_offices o ON a.requesting_office_id = o.office_id
              LEFT JOIN activity_evaluation e ON a.activity_id = e.activity_id
              LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id
              WHERE COALESCE(a.is_archived, 0) = 1
              ORDER BY COALESCE(a.archived_at, a.updated_at) DESC, a.eventdate DESC";
    $stmt = $db->query($query);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Archived AME activities query failed: " . $e->getMessage());
}

$offices = [];
$office_positions = [];
try {
    $office_stmt = $db->query("SELECT office_id, name, acronym, position FROM divisions_offices ORDER BY name ASC");
    $offices = $office_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($offices as $office) {
        if (!empty($office['position']) && !in_array($office['position'], $office_positions, true)) {
            $office_positions[] = $office['position'];
        }
    }
    sort($office_positions);
} catch (PDOException $e) {
    error_log("Archived AME offices query failed: " . $e->getMessage());
}

$months = [];
foreach ($activities as $act) {
    if (empty($act['eventdate'])) continue;
    $m = date('F Y', strtotime($act['eventdate']));
    if (!in_array($m, $months, true)) {
        $months[] = $m;
    }
}
usort($months, function($a, $b) {
    return strtotime($b) - strtotime($a);
});
?>

<style>
    .archive-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        overflow-x: auto;
        padding-bottom: 8px;
    }
    .archive-tab {
        padding: 10px 20px;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 700;
        color: #64748b;
        cursor: pointer;
        white-space: nowrap;
        transition: all 0.2s;
    }
    .archive-tab.active,
    .archive-tab:hover {
        background: var(--accent-blue);
        border-color: var(--accent-blue);
        color: white;
    }
    .archive-pagination {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        justify-content: flex-end;
    }
    .pagination-btn,
    .pagination-ellipsis {
        align-items: center;
        border-radius: 6px;
        display: inline-flex;
        font-size: 0.8rem;
        font-weight: 700;
        justify-content: center;
        min-width: 34px;
        padding: 6px 10px;
    }
    .pagination-btn {
        background: white;
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        cursor: pointer;
    }
    .pagination-btn.active {
        background: var(--accent-blue);
        border-color: var(--accent-blue);
        color: white;
    }
    .pagination-btn:disabled {
        cursor: not-allowed;
        opacity: 0.45;
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
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
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
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem;">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem;">
            <div>
                <a href="feed.php?action=activity" style="display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-blue)'" onmouseout="this.style.color='var(--text-secondary)'">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                    Active Activities
                </a>
                <h1 style="font-size: 2rem; margin: 0.8rem 0 0.5rem; display: flex; align-items: center; gap: 12px;">
                    <div style="background: #475569; color: white; padding: 8px; border-radius: 10px; display: flex;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                    </div>
                    Archived Activities
                </h1>
                <p style="color: var(--text-secondary); font-size: 0.95rem;">Review activities hidden from the active Activity Evaluation workspace.</p>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="btn btn-secondary" onclick="openExportModal()" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Export Report
                </button>
            </div>
        </div>

        <div class="archive-tabs" id="archiveMonthTabs">
            <button class="archive-tab active" onclick="filterArchivedByMonth('all', this)">All Archived</button>
            <?php foreach ($months as $m): ?>
                <button class="archive-tab" onclick="filterArchivedByMonth('<?= htmlspecialchars($m) ?>', this)"><?= htmlspecialchars($m) ?></button>
            <?php endforeach; ?>
        </div>

        <div style="background: white; padding: 1rem; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; position: relative; min-width: 250px;">
                <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="archiveSearch" onkeyup="handleArchiveFilterChange()" placeholder="Search archived activities by title, description or facilitator..." style="width: 100%; padding: 0.7rem 0.7rem 0.7rem 2.5rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;">
            </div>

            <select id="archivePositionFilter" onchange="handleArchivePositionFilterChange()" style="padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; min-width: 170px; background: white;">
                <option value="all">All Positions</option>
                <?php foreach ($office_positions as $position): ?>
                    <option value="<?= htmlspecialchars($position) ?>"><?= htmlspecialchars($position) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="archiveOfficeFilter" onchange="handleArchiveOfficeFilterChange()" disabled style="padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; min-width: 210px; background: #f8fafc; color: #94a3b8; cursor: not-allowed;">
                <option value="all">All Offices</option>
                <?php foreach ($offices as $office): ?>
                    <option value="<?= (int)$office['office_id'] ?>" data-position="<?= htmlspecialchars((string)($office['position'] ?? '')) ?>"><?= htmlspecialchars($office['name']) ?><?= !empty($office['acronym']) ? ' (' . htmlspecialchars($office['acronym']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: visible; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Activity Details</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Facilitator</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Date</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Status</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Rating</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Archived</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="7" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="color: #cbd5e1;"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                                    <p>No archived activities yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <?php
                                $status_val = strtolower($activity['eventstatus'] ?? 'pending');
                                if ($status_val === 'pending') $status_val = 'upcoming';
                                $eventTs = !empty($activity['eventdate']) ? strtotime($activity['eventdate']) : null;
                                $archivedTs = !empty($activity['archived_at']) ? strtotime($activity['archived_at']) : null;
                            ?>
                            <tr class="archive-row"
                                data-month="<?= $eventTs ? date('F Y', $eventTs) : '' ?>"
                                data-status="<?= htmlspecialchars($status_val) ?>"
                                data-office-id="<?= htmlspecialchars((string)($activity['requesting_office_id'] ?? '')) ?>"
                                data-office-position="<?= htmlspecialchars((string)($activity['office_position'] ?? '')) ?>"
                                style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <td style="padding: 1.2rem;">
                                    <div style="font-weight: 700; color: var(--accent-blue); font-size: 1rem;"><?= htmlspecialchars($activity['title']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 4px;"><?= htmlspecialchars($activity['description']) ?></div>
                                    <?php if (!empty($activity['sdg_titles'])): ?>
                                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;">
                                            <?php foreach(explode(', ', $activity['sdg_titles']) as $st): ?>
                                                <span style="font-size: 0.7rem; background: #eff6ff; color: #1e40af; padding: 2px 8px; border-radius: 4px; border: 1px solid #dbeafe;"><?= htmlspecialchars($st) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <?php if (!empty($activity['speaker'])): ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                                <span style="font-size: 0.85rem; color: #334155;"><?= htmlspecialchars($activity['speaker']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($activity['organizer'])): ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                                <span style="font-size: 0.85rem; color: #334155;"><?= htmlspecialchars($activity['organizer']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (empty($activity['speaker']) && empty($activity['organizer'])): ?>
                                            <span style="color: #94a3b8; font-size: 0.85rem;">Not Specified</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <div style="font-size: 0.9rem; font-weight: 700; color: #1e293b; margin-bottom: 2px;"><?= $eventTs ? date('M d, Y', $eventTs) : 'Not set' ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <?= !empty($activity['eventvenue']) ? htmlspecialchars($activity['eventvenue']) : 'Location TBD' ?>
                                    </div>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <?php
                                        $statusStyle = 'background: #fef9c3; color: #854d0e;';
                                        if (($activity['eventstatus'] ?? '') === 'Completed') $statusStyle = 'background: #dcfce7; color: #166534;';
                                        if (($activity['eventstatus'] ?? '') === 'Ongoing') $statusStyle = 'background: #dbeafe; color: #1e40af;';
                                    ?>
                                    <span style="<?= $statusStyle ?> padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;"><?= htmlspecialchars($activity['eventstatus'] ?? 'Pending') ?></span>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <?php if (!empty($activity['overall_average'])): ?>
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            <svg width="14" height="14" fill="#DFB641" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                                            <span style="font-weight: 700; font-size: 0.9rem;"><?= htmlspecialchars($activity['overall_average']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 0.85rem;">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <div style="font-size: 0.85rem; font-weight: 700; color: #475569;"><?= $archivedTs ? date('M d, Y', $archivedTs) : 'Archived' ?></div>
                                </td>
                                <td style="padding: 1.2rem; text-align: right;">
                                    <div class="action-dropdown">
                                        <button type="button" class="three-dots-btn" onclick="toggleDropdown(<?= (int)$activity['activity_id'] ?>, event)">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>
                                            </svg>
                                        </button>
                                        <div id="dropdown-<?= $activity['activity_id'] ?>" class="dropdown-menu">
                                            <button class="dropdown-item" onclick="viewActivity(<?= $activity['activity_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                View Activity
                                            </button>
                                            <div style="border-top: 1px solid var(--border-color); margin: 4px 0;"></div>
                                            <button class="dropdown-item delete" onclick="deleteActivity(<?= $activity['activity_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                Delete Activity
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="padding: 1rem; background: #f8fafc; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <div style="font-size: 0.8rem; color: var(--text-secondary);">Showing <b id="archive-showing-range">0</b> of <b id="archive-showing-count"><?= count($activities) ?></b> archived activities</div>
                <div class="archive-pagination" id="archivePagination"></div>
            </div>
        </div>

        <!-- Export Modal -->
        <div id="exportModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1100; align-items: center; justify-content: center;">
            <div style="background: white; width: 450px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; animation: modalPop 0.3s ease;">
                <div style="padding: 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                    <h2 style="font-size: 1.25rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Export Report
                    </h2>
                    <button onclick="closeExportModal()" style="background: none; border: none; cursor: pointer; color: #94a3b8; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div style="padding: 24px; display: flex; flex-direction: column; gap: 20px;">
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Report Type</label>
                        <select id="exportType" onchange="toggleExportFields()" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.95rem;">
                            <option value="all">All Activities (Full History)</option>
                            <option value="all_range">All Activities (Date Range)</option>
                            <option value="office_month">Office Performance (Monthly)</option>
                            <option value="office_range">Office Performance (Date Range)</option>
                        </select>
                    </div>

                    <div id="officeField" style="display: none; flex-direction: column; gap: 8px;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Requesting Office</label>
                        <select id="exportOffice" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.95rem;">
                            <option value="">Select Office</option>
                            <?php foreach ($offices as $o): ?>
                                <option value="<?= $o['office_id'] ?>"><?= htmlspecialchars($o['name']) ?> (<?= $o['acronym'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="monthField" style="display: none; flex-direction: column; gap: 8px;">
                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Select Month</label>
                        <select id="exportMonth" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.95rem;">
                            <?php foreach ($months as $m): ?>
                                <option value="<?= $m ?>"><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="rangeFields" style="display: none; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">From</label>
                            <input type="date" id="exportStart" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.95rem;">
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">To</label>
                            <input type="date" id="exportEnd" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.95rem;">
                        </div>
                    </div>
                </div>
                <div style="padding: 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 12px;">
                    <button onclick="closeExportModal()" style="padding: 12px 20px; border-radius: 10px; border: 1px solid #cbd5e1; background: white; color: #475569; font-weight: 700; cursor: pointer; transition: all 0.2s;">Cancel</button>
                    <button onclick="generateExport()" style="padding: 12px 24px; border-radius: 10px; border: none; background: #2563eb; color: white; font-weight: 700; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);">Download Excel</button>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    function openExportModal() {
        document.getElementById('exportModal').style.display = 'flex';
    }
    function closeExportModal() {
        document.getElementById('exportModal').style.display = 'none';
    }
    function toggleExportFields() {
        const type = document.getElementById('exportType').value;
        document.getElementById('officeField').style.display = (type.includes('office')) ? 'flex' : 'none';
        document.getElementById('monthField').style.display = (type === 'office_month') ? 'flex' : 'none';
        document.getElementById('rangeFields').style.display = (type.includes('range')) ? 'flex' : 'none';
    }
    function generateExport() {
        const type = document.getElementById('exportType').value;
        const office = document.getElementById('exportOffice').value;
        const month = document.getElementById('exportMonth').value;
        const start = document.getElementById('exportStart').value;
        const end = document.getElementById('exportEnd').value;

        if (type.includes('office') && !office) {
            alert('Please select a requesting office.');
            return;
        }

        if (type.includes('range')) {
            if (!start || !end) {
                alert('Please select both start and end dates.');
                return;
            }

            if (start > end) {
                alert('Start date cannot be later than end date.');
                return;
            }
        }

        const params = new URLSearchParams({ type, is_archived: '1' });
        if (type.includes('office')) params.set('office_id', office);
        if (type === 'office_month' && month) params.set('month', month);
        if (type.includes('range')) {
            params.set('start_date', start);
            params.set('end_date', end);
        }

        const url = `../api/export_report.php?${params.toString()}`;
        window.location.href = url;
        closeExportModal();
    }
</script>

<script>
    function toggleDropdown(id, event) {
        event.stopPropagation();
        const allDropdowns = document.querySelectorAll('.dropdown-menu');
        allDropdowns.forEach(menu => {
            if (menu.id !== `dropdown-${id}`) menu.style.display = 'none';
        });
        const dropdown = document.getElementById(`dropdown-${id}`);
        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
    }

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.action-dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => menu.style.display = 'none');
        }
    });

    function viewActivity(id) {
        window.location.href = `feed.php?action=view_activity&id=${id}`;
    }

    function deleteActivity(id) {
        if (confirm('Are you sure you want to permanently delete this activity and all related evaluation data?')) {
            window.location.href = `../api/activities.php?action=delete&id=${id}&redirect=${encodeURIComponent('../views/feed.php?action=archived_activities')}`;
        }
    }

    const archiveRowsPerPage = 10;
    let archiveCurrentPage = 1;
    let archiveMonthFilter = 'all';

    window.addEventListener('load', () => {
        searchArchived(false);
    });

    function filterArchivedByMonth(month, btn) {
        archiveMonthFilter = month;
        archiveCurrentPage = 1;
        document.querySelectorAll('.archive-tab').forEach(tab => tab.classList.remove('active'));
        if (btn) btn.classList.add('active');
        searchArchived(false);
    }

    function handleArchivePositionFilterChange() {
        const officeFilter = document.getElementById('archiveOfficeFilter');
        if (officeFilter) officeFilter.value = 'all';
        archiveCurrentPage = 1;
        updateArchiveOfficeFilterState();
        searchArchived(false);
    }

    function handleArchiveOfficeFilterChange() {
        archiveCurrentPage = 1;
        searchArchived(false);
    }

    function updateArchiveOfficeFilterState() {
        const officeFilter = document.getElementById('archiveOfficeFilter');
        const positionFilter = document.getElementById('archivePositionFilter');
        if (!officeFilter || !positionFilter) return;

        const selectedPosition = positionFilter.value;
        const enabled = selectedPosition !== 'all';
        officeFilter.disabled = !enabled;
        if (!enabled) officeFilter.value = 'all';

        Array.from(officeFilter.options).forEach(option => {
            option.hidden = option.value !== 'all' && option.dataset.position !== selectedPosition;
        });

        officeFilter.style.background = enabled ? 'white' : '#f8fafc';
        officeFilter.style.color = enabled ? '#0f172a' : '#94a3b8';
        officeFilter.style.cursor = enabled ? 'pointer' : 'not-allowed';
    }

    function handleArchiveFilterChange() {
        archiveCurrentPage = 1;
        searchArchived(false);
    }

    function searchArchived(resetPage = true) {
        if (resetPage) archiveCurrentPage = 1;
        const search = document.getElementById('archiveSearch').value.toLowerCase();
        const positionFilter = document.getElementById('archivePositionFilter') ? document.getElementById('archivePositionFilter').value : 'all';
        const officeFilter = document.getElementById('archiveOfficeFilter') ? document.getElementById('archiveOfficeFilter').value : 'all';
        const rows = Array.from(document.querySelectorAll('.archive-row'));
        const filtered = [];

        rows.forEach(row => {
            const matchesSearch = row.innerText.toLowerCase().includes(search);
            const matchesMonth = archiveMonthFilter === 'all' || row.dataset.month === archiveMonthFilter;
            const matchesPosition = positionFilter === 'all' || row.dataset.officePosition === positionFilter;
            const matchesOffice = officeFilter === 'all' || row.dataset.officeId === officeFilter;
            
            if (matchesSearch && matchesMonth && matchesPosition && matchesOffice) {
                filtered.push(row);
            } else {
                row.style.display = 'none';
            }
        });

        renderArchivedPage(filtered);
    }

    function renderArchivedPage(rows) {
        const total = rows.length;
        const pages = Math.max(1, Math.ceil(total / archiveRowsPerPage));
        archiveCurrentPage = Math.min(Math.max(archiveCurrentPage, 1), pages);
        const start = (archiveCurrentPage - 1) * archiveRowsPerPage;
        const end = start + archiveRowsPerPage;

        rows.forEach((row, index) => {
            row.style.display = index >= start && index < end ? '' : 'none';
        });

        document.getElementById('archive-showing-range').textContent = total === 0 ? '0' : `${start + 1}-${Math.min(end, total)}`;
        document.getElementById('archive-showing-count').textContent = total;
        renderArchivePagination(pages);
    }

    function renderArchivePagination(totalPages) {
        const pagination = document.getElementById('archivePagination');
        if (!pagination) return;
        let buttons = '';
        for (let i = 1; i <= totalPages; i++) {
            buttons += `<button type="button" class="pagination-btn ${i === archiveCurrentPage ? 'active' : ''}" onclick="goToArchivePage(${i})">${i}</button>`;
        }
        pagination.innerHTML = `
            <button type="button" class="pagination-btn" onclick="goToArchivePage(${archiveCurrentPage - 1})" ${archiveCurrentPage === 1 ? 'disabled' : ''}>Previous</button>
            ${buttons}
            <button type="button" class="pagination-btn" onclick="goToArchivePage(${archiveCurrentPage + 1})" ${archiveCurrentPage === totalPages ? 'disabled' : ''}>Next</button>
        `;
    }

    function goToArchivePage(page) {
        archiveCurrentPage = page;
        searchArchived(false);
    }
</script>
