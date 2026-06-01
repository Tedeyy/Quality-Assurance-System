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
    $query = "SELECT a.*, s.overall_average, e.response_rate,
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
              LEFT JOIN activity_evaluation e ON a.activity_id = e.activity_id
              LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id
              WHERE COALESCE(a.is_archived, 0) = 1
              ORDER BY COALESCE(a.archived_at, a.updated_at) DESC, a.eventdate DESC";
    $stmt = $db->query($query);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Archived AME activities query failed: " . $e->getMessage());
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
            <select id="archiveStatusFilter" onchange="handleArchiveFilterChange()" style="padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; min-width: 150px; background: white;">
                <option value="all">All Status</option>
                <option value="upcoming">Upcoming (Pending)</option>
                <option value="ongoing">In Progress (Ongoing)</option>
                <option value="completed">Completed</option>
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
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
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
    </div>
</main>

<script>
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

    function handleArchiveFilterChange() {
        archiveCurrentPage = 1;
        searchArchived(false);
    }

    function searchArchived(resetPage = true) {
        if (resetPage) archiveCurrentPage = 1;
        const search = document.getElementById('archiveSearch').value.toLowerCase();
        const status = document.getElementById('archiveStatusFilter').value;
        const rows = Array.from(document.querySelectorAll('.archive-row'));
        const filtered = [];

        rows.forEach(row => {
            const matchesSearch = row.innerText.toLowerCase().includes(search);
            const matchesStatus = status === 'all' || row.dataset.status === status;
            const matchesMonth = archiveMonthFilter === 'all' || row.dataset.month === archiveMonthFilter;
            if (matchesSearch && matchesStatus && matchesMonth) {
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
