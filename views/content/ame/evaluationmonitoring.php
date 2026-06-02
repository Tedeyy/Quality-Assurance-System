<?php
require_once __DIR__ . '/../../../config/database.php';
$db = (new Database())->getConnection();

// Load ALL records — client-side JS handles search, filter & pagination
$data_sql = "
    SELECT
        m.feedback_id,
        m.created_at,
        m.tag,
        m.case_status,
        m.status,
        COALESCE(m.complaints, m.suggestions_for_improvement) AS feedback,
        m.actions_taken,
        a.title AS activity_title
    FROM activity_evaluation_monitoring m
    JOIN activity_evaluation e ON m.evaluation_id = e.evaluation_id
    JOIN activities a ON e.activity_id = a.activity_id
    ORDER BY m.created_at DESC
";
$monitoring_data = $db->query($data_sql)->fetchAll(PDO::FETCH_ASSOC);

// Stats (global)
$stats = ['total' => 0, 'resolved' => 0, 'unresolved' => 0, 'complaint' => 0, 'suggestion' => 0];
foreach ($monitoring_data as $r) {
    $stats['total']++;
    if ($r['case_status'] === 'Resolved')   $stats['resolved']++;
    else                                     $stats['unresolved']++;
    if ($r['tag'] === 'Complaint')           $stats['complaint']++;
    else                                     $stats['suggestion']++;
}
?>

<style>
    .em-page { padding: 2rem 5% 4rem; min-height: calc(100vh - 80px); background-color: #f8fafc; font-family: 'Inter', sans-serif;}

    .em-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        color: white; padding: 2.5rem 2rem; border-radius: 16px;
        margin-bottom: 2rem;
        box-shadow: 0 10px 25px -5px rgba(30,58,138,.25);
        position: relative;
        overflow: hidden;
    }
    .em-header::after {
        content: ''; position: absolute; top: -50%; right: -10%; width: 50%; height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        transform: rotate(-45deg); pointer-events: none;
    }
    .em-title { font-size: 2.2rem; font-weight: 800; margin-bottom: .75rem; display: flex; align-items: center; gap: 14px; color: white; letter-spacing: -0.5px;}
    .em-subtitle { color: #e0e7ff; font-size: 1.05rem; max-width: 800px; line-height: 1.6; font-weight: 400;}

    .em-stats { display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
    .em-stat-card { 
        background: white; border: 1px solid #e2e8f0; border-radius: 16px; padding: 1.5rem; 
        box-shadow: 0 4px 6px -1px rgba(0,0,0,.05), 0 2px 4px -1px rgba(0,0,0,.03); 
        transition: transform .3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow .3s cubic-bezier(0.4, 0, 0.2, 1); 
        display: flex; flex-direction: column; position: relative; overflow: hidden;
    }
    .em-stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 20px -5px rgba(0,0,0,.1), 0 8px 10px -5px rgba(0,0,0,.04); }
    .em-stat-icon-wrapper {
        width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
        margin-bottom: 12px;
    }
    .em-stat-title { font-size: .85rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
    .em-stat-value { font-size: 2.2rem; font-weight: 800; color: #0f172a; line-height: 1.1; }

    .em-table-wrap { background: white; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,.05), 0 4px 6px -2px rgba(0,0,0,.025); overflow: visible; }

    .em-toolbar { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; padding: 1.25rem 1.5rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; border-radius: 16px 16px 0 0; }
    .em-search-wrap { flex: 1; position: relative; min-width: 260px; }
    .em-search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }
    .em-search-input { width: 100%; padding: .75rem .75rem .75rem 2.75rem; border: 1px solid #cbd5e1; border-radius: 10px; outline: none; font-size: .95rem; background: white; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s; font-family: inherit;}
    .em-search-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,.15); }
    .em-filter-select { padding: .75rem 1rem; border: 1px solid #cbd5e1; border-radius: 10px; outline: none; font-size: .95rem; background: white; min-width: 170px; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s; font-family: inherit; appearance: auto;}
    .em-filter-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59,130,246,.15); }

    .em-table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; }
    .em-table thead tr { background: #f8fafc; }
    .em-table th { padding: 1.25rem 1.5rem; font-size: .8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; border-bottom: 2px solid #e2e8f0; }
    .em-table td { padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; transition: background-color 0.2s;}
    .em-table tbody tr:hover td { background-color: #f8fafc; }
    .em-table tbody tr:last-child td { border-bottom: none; }

    .em-badge { display: inline-flex; align-items: center; justify-content: center; padding: 4px 12px; border-radius: 9999px; font-size: .75rem; font-weight: 700; white-space: nowrap; letter-spacing: 0.3px;}
    .badge-complaint  { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;}
    .badge-suggestion { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff;}
    .badge-resolved   { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;}
    .badge-unresolved { background: #fef9c3; color: #a16207; border: 1px solid #fef08a;}

    /* three-dot dropdown */
    .action-dropdown { position: relative; display: inline-block; }
    .three-dots-btn { background: transparent; border: none; cursor: pointer; padding: 8px; border-radius: 50%; transition: all .2s; color: #94a3b8; display: flex; align-items: center; justify-content: center; }
    .three-dots-btn:hover { background: #f1f5f9; color: #3b82f6; }
    .em-dropdown-menu {
        display: none; position: absolute; right: 0; top: calc(100% + 4px);
        background: white; min-width: 180px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,.15), 0 8px 10px -6px rgba(0,0,0,.1);
        border-radius: 12px; border: 1px solid #e2e8f0;
        z-index: 1000; padding: 8px 0;
        animation: emFadeIn .2s cubic-bezier(0.16, 1, 0.3, 1);
        transform-origin: top right;
    }
    @keyframes emFadeIn { from { opacity:0; transform:scale(0.95); } to { opacity:1; transform:scale(1); } }
    .em-dropdown-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; color: #475569; text-decoration: none; font-size: .9rem; font-weight: 500; transition: background .15s, color .15s; cursor: pointer; border: none; width: 100%; text-align: left; background: transparent; font-family: inherit; }
    .em-dropdown-item:hover { background: #f8fafc; color: #3b82f6; }
    .em-dropdown-item svg { color: #94a3b8; flex-shrink: 0; transition: color 0.15s;}
    .em-dropdown-item:hover svg { color: #3b82f6; }
    .em-dropdown-item.danger { color: #dc2626; }
    .em-dropdown-item.danger:hover { background: #fef2f2; color: #b91c1c; }
    .em-dropdown-item.danger:hover svg { color: #b91c1c; }
    .em-dropdown-divider { border-top: 1px solid #f1f5f9; margin: 6px 0; }

    /* pagination */
    .em-pagination { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-top: 1px solid #e2e8f0; background: white; border-radius: 0 0 16px 16px; flex-wrap: wrap; gap: 12px; }
    .em-page-info { font-size: .9rem; color: #64748b; font-weight: 500;}
    .em-page-btns { display: flex; gap: 6px; flex-wrap: wrap; }
    .em-page-btn { padding: 8px 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: white; color: #475569; font-size: .9rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: all .2s; display: inline-flex; align-items: center; justify-content: center; min-width: 36px;}
    .em-page-btn:hover:not(.disabled):not(.active) { background: #f8fafc; border-color: #94a3b8; color: #1e293b;}
    .em-page-btn.active { background: #3b82f6; color: white; border-color: #3b82f6; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.25);}
    .em-page-btn.disabled { opacity: .4; pointer-events: none; cursor: not-allowed; background: #f8fafc;}

    .em-empty { padding: 4rem 2rem; text-align: center; color: #94a3b8; }
    .em-empty strong { display: block; color: #334155; font-size: 1.1rem; margin-bottom: 8px; font-weight: 700;}
    .em-empty span { font-size: .95rem; color: #64748b;}

    .em-hidden { display: none !important; }
</style>

<main class="em-page">
<div style="max-width:1200px; margin:0 auto;">

    <!-- Header -->
    <div class="em-header">
        <h1 class="em-title">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            Evaluation Monitoring
        </h1>
        <p class="em-subtitle">Track complaints and suggestions gathered from activity evaluations, monitor actions taken, and verify improvements.</p>
    </div>

    <!-- Stats -->
    <div class="em-stats">
        <div class="em-stat-card">
            <div class="em-stat-icon-wrapper" style="background: #f1f5f9; color: #64748b;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="em-stat-title">Total Cases</div>
            <div class="em-stat-value"><?= $stats['total'] ?></div>
        </div>
        <div class="em-stat-card">
            <div class="em-stat-icon-wrapper" style="background: #fef2f2; color: #ef4444;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="em-stat-title">Complaints</div>
            <div class="em-stat-value" style="color:#b91c1c;"><?= $stats['complaint'] ?></div>
        </div>
        <div class="em-stat-card">
            <div class="em-stat-icon-wrapper" style="background: #f3e8ff; color: #a855f7;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v2"/><path d="M12 20v2"/><path d="M4.93 4.93l1.41 1.41"/><path d="M17.66 17.66l1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M6.34 17.66l-1.41 1.41"/><path d="M19.07 4.93l-1.41 1.41"/></svg>
            </div>
            <div class="em-stat-title">Suggestions</div>
            <div class="em-stat-value" style="color:#7e22ce;"><?= $stats['suggestion'] ?></div>
        </div>
        <div class="em-stat-card">
            <div class="em-stat-icon-wrapper" style="background: #dcfce7; color: #22c55e;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <div class="em-stat-title">Resolved</div>
            <div class="em-stat-value" style="color:#15803d;"><?= $stats['resolved'] ?></div>
        </div>
        <div class="em-stat-card">
            <div class="em-stat-icon-wrapper" style="background: #fef3c7; color: #f59e0b;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="em-stat-title">Unresolved</div>
            <div class="em-stat-value" style="color:#b45309;"><?= $stats['unresolved'] ?></div>
        </div>
    </div>

    <!-- Table -->
    <div class="em-table-wrap">

        <!-- Toolbar -->
        <div class="em-toolbar">
            <div class="em-search-wrap">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" id="emSearch" class="em-search-input"
                       placeholder="Search by ID or activity name..."
                       oninput="emApplyFilters()">
            </div>

            <select id="emTypeFilter" class="em-filter-select" onchange="emApplyFilters()">
                <option value="all">All Types</option>
                <option value="Complaint">Complaint</option>
                <option value="Suggestions">Suggestion</option>
            </select>

            <select id="emCaseFilter" class="em-filter-select" onchange="emApplyFilters()">
                <option value="all">All Statuses</option>
                <option value="Unresolved">Unresolved</option>
                <option value="Resolved">Resolved</option>
            </select>
        </div>

        <!-- Table -->
        <table class="em-table" id="emTable">
            <thead>
                <tr>
                    <th>ID & Date</th>
                    <th>Type</th>
                    <th>Activity Name</th>
                    <th style="width:35%;">Feedback</th>
                    <th>Case Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="emTableBody">
                <?php if (empty($monitoring_data)): ?>
                    <tr id="emEmptyAll">
                        <td colspan="6">
                            <div class="em-empty">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="color:#cbd5e1; display:block; margin:0 auto 12px;">
                                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 1 1-7.6-11.7 8.38 8.38 0 0 1 3.8.9L21 3.5l-1 4.5 4.5-1z"/>
                                </svg>
                                <strong>No monitoring records yet</strong>
                                <span>Analyze activity evaluations to generate entries.</span>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($monitoring_data as $row): ?>
                        <tr class="em-row"
                            data-id="<?= htmlspecialchars(strtolower($row['feedback_id'])) ?>"
                            data-activity="<?= htmlspecialchars(strtolower($row['activity_title'])) ?>"
                            data-type="<?= htmlspecialchars($row['tag']) ?>"
                            data-case="<?= htmlspecialchars($row['case_status']) ?>">

                            <td>
                                <div style="font-weight:700; color:#1e293b; font-family:monospace; font-size:.95rem; letter-spacing: 0.5px;">#<?= htmlspecialchars($row['feedback_id']) ?></div>
                                <div style="font-size:.8rem; color:#64748b; margin-top:4px; display: flex; align-items: center; gap: 4px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                </div>
                            </td>

                            <td>
                                <span class="em-badge <?= $row['tag'] === 'Complaint' ? 'badge-complaint' : 'badge-suggestion' ?>">
                                    <?= htmlspecialchars($row['tag']) ?>
                                </span>
                            </td>

                            <td>
                                <div style="font-weight:600; color:var(--accent-blue); font-size:.9rem; line-height:1.4;">
                                    <?= htmlspecialchars($row['activity_title']) ?>
                                </div>
                            </td>

                            <td>
                                <p style="margin:0; font-size:.88rem; color:#475569; line-height:1.5; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden;">
                                    <?= htmlspecialchars($row['feedback'] ?? 'No feedback text.') ?>
                                </p>
                            </td>

                            <td>
                                <span class="em-badge <?= $row['case_status'] === 'Resolved' ? 'badge-resolved' : 'badge-unresolved' ?>">
                                    <?= htmlspecialchars($row['case_status'] ?? 'Unresolved') ?>
                                </span>
                            </td>

                            <td style="text-align:right;">
                                <div class="action-dropdown">
                                    <button type="button" class="three-dots-btn"
                                            onclick="emToggleDropdown('emdrop-<?= htmlspecialchars($row['feedback_id']) ?>', event)">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>
                                        </svg>
                                    </button>
                                    <div id="emdrop-<?= htmlspecialchars($row['feedback_id']) ?>" class="em-dropdown-menu">
                                        <a href="feed.php?action=monitoringdetails&id=<?= urlencode($row['feedback_id']) ?>" class="em-dropdown-item">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                            </svg>
                                            Details
                                        </a>
                                        <div class="em-dropdown-divider"></div>
                                        <button class="em-dropdown-item danger"
                                                onclick="emArchive('<?= htmlspecialchars($row['feedback_id']) ?>')">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/>
                                                <line x1="10" y1="12" x2="14" y2="12"/>
                                            </svg>
                                            Archive
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- No-results row (injected by JS) -->
        <div id="emNoResults" class="em-hidden">
            <div class="em-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="color:#cbd5e1; display:block; margin:0 auto 12px;">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <strong>No matching records</strong>
                <span>Try a different search term or filter.</span>
            </div>
        </div>

        <!-- Pagination -->
        <div class="em-pagination">
            <div class="em-page-info" id="emPageInfo"></div>
            <div class="em-page-btns" id="emPageBtns"></div>
        </div>
    </div>

</div>
</main>

<script>
(function () {
    const ROWS_PER_PAGE = 10;
    let currentPage = 1;
    let filteredRows = [];

    /* ── Dropdown ── */
    window.emToggleDropdown = function (id, e) {
        if (e && e.stopPropagation) e.stopPropagation();
        var menu = document.getElementById(id);
        if (!menu) return;
        document.querySelectorAll('.em-dropdown-menu').forEach(function (m) {
            if (m.id !== id) m.style.display = 'none';
        });
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    };

    document.addEventListener('click', function () {
        document.querySelectorAll('.em-dropdown-menu').forEach(function (m) {
            m.style.display = 'none';
        });
    });

    /* ── Archive ── */
    window.emArchive = function (id) {
        if (confirm('Archive monitoring record #' + id + '? This cannot be undone.')) {
            alert('Archive feature coming soon.');
        }
    };

    /* ── Filter & Search ── */
    window.emApplyFilters = function () {
        currentPage = 1;
        applyAndRender();
    };

    function applyAndRender() {
        const search  = (document.getElementById('emSearch').value || '').toLowerCase().trim();
        const type    = document.getElementById('emTypeFilter').value;
        const caseS   = document.getElementById('emCaseFilter').value;

        const allRows = Array.from(document.querySelectorAll('.em-row'));

        filteredRows = allRows.filter(function (row) {
            const matchSearch = !search ||
                row.dataset.id.includes(search) ||
                row.dataset.activity.includes(search);
            const matchType = type === 'all' || row.dataset.type === type;
            const matchCase = caseS === 'all' || row.dataset.case === caseS;
            return matchSearch && matchType && matchCase;
        });

        renderPage();
    }

    function renderPage() {
        const allRows = Array.from(document.querySelectorAll('.em-row'));

        // Hide all rows first
        allRows.forEach(function (r) { r.classList.add('em-hidden'); });

        const totalFiltered = filteredRows.length;
        const totalPages    = Math.max(1, Math.ceil(totalFiltered / ROWS_PER_PAGE));
        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * ROWS_PER_PAGE;
        const end   = Math.min(start + ROWS_PER_PAGE, totalFiltered);
        const pageRows = filteredRows.slice(start, end);

        // Show only current page rows
        pageRows.forEach(function (r) { r.classList.remove('em-hidden'); });

        // No-results state
        const noRes = document.getElementById('emNoResults');
        if (noRes) noRes.classList.toggle('em-hidden', totalFiltered > 0);

        // Page info
        const info = document.getElementById('emPageInfo');
        if (info) {
            info.textContent = totalFiltered === 0
                ? 'No records found'
                : 'Showing ' + (start + 1) + '–' + end + ' of ' + totalFiltered + ' records';
        }

        // Pagination buttons
        buildPager(totalPages);
    }

    function buildPager(totalPages) {
        const container = document.getElementById('emPageBtns');
        if (!container) return;
        container.innerHTML = '';

        function btn(label, page, extraClass) {
            var el = document.createElement('button');
            el.className = 'em-page-btn ' + (extraClass || '');
            el.innerHTML = label;
            if (!extraClass || !extraClass.includes('disabled')) {
                el.onclick = function () { currentPage = page; renderPage(); };
            }
            container.appendChild(el);
        }

        btn('&laquo; Prev', currentPage - 1, currentPage <= 1 ? 'disabled' : '');

        // Smart range
        var rangeStart = Math.max(1, currentPage - 2);
        var rangeEnd   = Math.min(totalPages, currentPage + 2);

        if (rangeStart > 1) {
            btn('1', 1);
            if (rangeStart > 2) { var dots = document.createElement('span'); dots.className = 'em-page-btn disabled'; dots.textContent = '…'; container.appendChild(dots); }
        }
        for (var i = rangeStart; i <= rangeEnd; i++) {
            btn(i, i, i === currentPage ? 'active' : '');
        }
        if (rangeEnd < totalPages) {
            if (rangeEnd < totalPages - 1) { var dots2 = document.createElement('span'); dots2.className = 'em-page-btn disabled'; dots2.textContent = '…'; container.appendChild(dots2); }
            btn(totalPages, totalPages);
        }

        btn('Next &raquo;', currentPage + 1, currentPage >= totalPages ? 'disabled' : '');
    }

    // Initial render
    applyAndRender();
})();
</script>
