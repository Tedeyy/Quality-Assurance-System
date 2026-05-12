<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Fetch activities with their ratings and SDGs
$query = "SELECT a.*, s.overall_average, e.response_rate,
                  GROUP_CONCAT(sdg.title SEPARATOR ', ') as sdg_titles,
                  GROUP_CONCAT(sdg.sdg_id SEPARATOR ',') as sdg_ids
          FROM activities a 
          LEFT JOIN activity_evaluation e ON a.activity_id = e.activity_id
          LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id
          LEFT JOIN activity_sdgs asg ON a.activity_id = asg.activity_id
          LEFT JOIN SDGs sdg ON asg.sdg_id = sdg.sdg_id
          GROUP BY a.activity_id
          ORDER BY a.eventdate DESC";
$stmt = $db->query($query);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch SDGs for the dropdown
$sdg_stmt = $db->query("SELECT sdg_id, title FROM SDGs ORDER BY sdg_id ASC");
$sdgs = $sdg_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch offices for the dropdown
$office_stmt = $db->query("SELECT office_id, name, acronym FROM divisions_offices ORDER BY name ASC");
$offices = $office_stmt->fetchAll(PDO::FETCH_ASSOC);

$target_groups = ['Everyone', 'Student', 'Non-teaching Faculty', 'Teaching Faculty', 'Staff', 'Stakeholders', 'Out of School Youth', 'Guests', 'Others'];

// Extract unique months for tabbing
$months = [];
foreach ($activities as $act) {
    $m = date('F Y', strtotime($act['eventdate']));
    if (!in_array($m, $months)) {
        $months[] = $m;
    }
}
usort($months, function($a, $b) {
    return strtotime($b) - strtotime($a);
});

// Fetch all SDGs and their activity counts for the dashboard
$sdg_counts_query = "SELECT s.sdg_id, s.title, COUNT(asg.activity_id) as count 
                     FROM SDGs s 
                     LEFT JOIN activity_sdgs asg ON s.sdg_id = asg.sdg_id 
                     GROUP BY s.sdg_id 
                     ORDER BY s.sdg_id ASC";
$sdg_stats = $db->query($sdg_counts_query)->fetchAll(PDO::FETCH_ASSOC);

// Fetch Speaker Ratings
$speaker_ratings = $db->query("
    SELECT r.*, s.name, e.activity_id 
    FROM activity_speaker_rating r 
    JOIN speakers s ON r.speaker_id = s.speaker_id 
    JOIN activity_evaluation e ON r.evaluation_id = e.evaluation_id
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Organizer Ratings
$organizer_ratings = $db->query("
    SELECT r.*, o.name, e.activity_id 
    FROM activity_organizer_rating r 
    JOIN organizers o ON r.organizer_id = o.organizer_id 
    JOIN activity_evaluation e ON r.evaluation_id = e.evaluation_id
")->fetchAll(PDO::FETCH_ASSOC);
?>

<script>
    const speakerRatingsData = <?= json_encode($speaker_ratings) ?>;
    const organizerRatingsData = <?= json_encode($organizer_ratings) ?>;
</script>

<style>
    .month-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        overflow-x: auto;
        padding-bottom: 8px;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    .month-tabs::-webkit-scrollbar {
        height: 4px;
    }
    .month-tabs::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    .month-tab {
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
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .month-tab:hover {
        background: #f8fafc;
        color: var(--accent-blue);
        border-color: #cbd5e1;
    }
    .month-tab.active {
        background: var(--accent-blue);
        color: white;
        border-color: var(--accent-blue);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
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

    .sdg-container {
        display: flex;
        gap: 2px;
        overflow-x: auto;
        padding: 10px 0 20px 0;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
        margin-bottom: 2rem;
        justify-content: space-between;
    }
    .sdg-container::-webkit-scrollbar {
        height: 4px;
    }
    .sdg-container::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    .sdg-card {
        flex: 0 1 auto;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        transition: transform 0.2s;
        min-width: 0;
    }
    .sdg-card:hover {
        transform: translateY(-3px);
    }
    .sdg-icon {
        width: 64px;
        height: 64px;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: filter 0.3s;
        object-fit: cover;
    }
    .sdg-icon.inactive {
        filter: grayscale(100%);
        opacity: 0.6;
    }
    .sdg-count {
        font-size: 0.8rem;
        font-weight: 800;
        color: #64748b;
        background: #f1f5f9;
        padding: 2px 8px;
        border-radius: 10px;
    }
    .sdg-icon.active-icon {
        box-shadow: 0 0 15px rgba(37, 99, 235, 0.2);
    }

    /* Ranking Section Styles */
    .ranking-card {
        background: white;
        padding: 1.5rem;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
    }
    .ranking-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .ranking-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #f1f5f9;
        transition: transform 0.2s;
    }
    .ranking-item:last-child { border-bottom: none; }
    .ranking-item:hover {
        transform: translateX(5px);
    }
    .ranking-badge {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        font-weight: 800;
        flex-shrink: 0;
    }
    .badge-1 { background: #fef3c7; color: #92400e; border: 2px solid #fbbf24; }
    .badge-2 { background: #f1f5f9; color: #475569; border: 2px solid #cbd5e1; }
    .badge-3 { background: #ffedd5; color: #9a3412; border: 2px solid #fdba74; }
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem;">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        <!-- Header Section -->
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem;">
            <div>
                <h1 style="font-size: 2rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 12px;">
                    <div style="background: var(--accent-blue); color: white; padding: 8px; border-radius: 10px; display: flex;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                        </svg>
                    </div>
                    Activity Monitoring & Evaluation
                </h1>
                <p style="color: var(--text-secondary); font-size: 0.95rem;">Track, evaluate, and report institutional activities and faculty performance.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-secondary" onclick="openExportModal()" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Export Report
                </button>
                <button class="btn btn-primary" onclick="openAddModal()" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add Activity
                </button>
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

                let url = `../api/export_report.php?type=${type}`;
                if (office && type.includes('office')) url += `&office_id=${office}`;
                if (type === 'office_month' && month) url += `&month=${month}`;
                if (type.includes('range')) {
                    if (start) url += `&start_date=${start}`;
                    if (end) url += `&end_date=${end}`;
                }

                window.location.href = url;
                closeExportModal();
            }
        </script>


        <!-- Stats Overview -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
            <div style="background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Total Activities</span>
                <div style="font-size: 2rem; font-weight: 800; color: var(--accent-blue); margin-top: 5px;"><?= count($activities) ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #10b981; display: flex; align-items: center; gap: 4px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    Updated just now
                </div>
            </div>
            <div style="background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Avg Evaluation Score</span>
                <?php
                    $total_rating = 0;
                    $rated_count = 0;
                    foreach($activities as $act) {
                        $score = (float) str_replace('%', '', $act['overall_average']);
                        if($score > 0) {
                            $total_rating += $score;
                            $rated_count++;
                        }
                    }
                    $avg = $rated_count > 0 ? number_format($total_rating / $rated_count, 2) . "%" : '0.00%';
                ?>
                <div style="font-size: 2rem; font-weight: 800; color: var(--accent-gold); margin-top: 5px;"><?= $avg ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #64748b;">Based on <?= $rated_count ?> evaluations</div>
            </div>
            <div style="background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                <span style="color: var(--text-secondary); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Upcoming Events</span>
                <?php
                    $upcoming = array_filter($activities, function($a) { return $a['eventstatus'] === 'Pending'; });
                ?>
                <div style="font-size: 2rem; font-weight: 800; color: #f59e0b; margin-top: 5px;"><?= count($upcoming) ?></div>
                <div style="margin-top: 10px; font-size: 0.8rem; color: #64748b;">Scheduled for this month</div>
            </div>
        </div>

        <!-- SDG Icons Dashboard -->
        <div class="sdg-container">
            <?php foreach ($sdg_stats as $sdg): ?>
                <?php 
                    $has_activities = $sdg['count'] > 0;
                    $icon_num = $sdg['sdg_id'];
                    $icon_path = "../assets/img/sdgs/SDG{$icon_num}.png";
                ?>
                <div class="sdg-card" title="<?= htmlspecialchars($sdg['title']) ?>" data-sdg-id="<?= $icon_num ?>">
                    <img src="<?= $icon_path ?>" 
                         alt="SDG <?= $icon_num ?>" 
                         class="sdg-icon <?= $has_activities ? 'active-icon' : 'inactive' ?>">
                    <span class="sdg-count-val" style="<?= $has_activities ? 'color: var(--accent-blue); background: #eff6ff;' : '' ?> font-size: 0.8rem; font-weight: 800; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 10px;">
                        <?= $sdg['count'] ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Monthly Tabs -->
        <div class="month-tabs" id="monthTabs">
            <button class="month-tab active" onclick="filterByMonth('all', this)">All Activities</button>
            <?php foreach ($months as $m): ?>
                <button class="month-tab" onclick="filterByMonth('<?= $m ?>', this)"><?= $m ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Filter & Search Section -->
        <div style="background: white; padding: 1rem; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; position: relative; min-width: 250px;">
                <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="activitySearch" onkeyup="searchActivities()" placeholder="Search activities by title, description or facilitator..." style="width: 100%; padding: 0.7rem 0.7rem 0.7rem 2.5rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;">
            </div>
            <select id="statusFilter" onchange="searchActivities()" style="padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; min-width: 150px; background: white;">
                <option value="all">All Status</option>
                <option value="upcoming">Upcoming (Pending)</option>
                <option value="ongoing">In Progress (Ongoing)</option>
                <option value="completed">Completed</option>
            </select>
        </div>

        <!-- Activities Table -->
        <div style="background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: visible; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Activity Details</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Facilitator</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Date</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Status</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Rating</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="6" style="padding: 3rem; text-align: center; color: var(--text-secondary);">
                                <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="color: #cbd5e1;">
                                        <rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/>
                                    </svg>
                                    <p>No activities found. Click "Add Activity" to create one.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <?php 
                                $status_val = strtolower($activity['eventstatus']);
                                if ($status_val === 'pending') $status_val = 'upcoming';
                            ?>
                            <tr class="activity-row" 
                                data-id="<?= $activity['activity_id'] ?>"
                                data-month="<?= date('F Y', strtotime($activity['eventdate'])) ?>" 
                                data-status="<?= $status_val ?>" 
                                data-sdgs="<?= $activity['sdg_ids'] ?>"
                                data-title="<?= htmlspecialchars($activity['title']) ?>"
                                data-response-rate="<?= (float)$activity['response_rate'] ?>"
                                data-overall-average="<?= (float)str_replace('%', '', $activity['overall_average']) ?>"
                                style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" 
                                onmouseover="this.style.background='#f8fafc'" 
                                onmouseout="this.style.background='transparent'">
                                <td style="padding: 1.2rem;">
                                    <div style="font-weight: 700; color: var(--accent-blue); font-size: 1rem;"><?= htmlspecialchars($activity['title']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 4px;"><?= htmlspecialchars($activity['description']) ?></div>
                                    <?php if ($activity['sdg_titles']): ?>
                                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;">
                                            <?php foreach(explode(', ', $activity['sdg_titles']) as $st): ?>
                                                <span style="font-size: 0.7rem; background: #eff6ff; color: #1e40af; padding: 2px 8px; border-radius: 4px; border: 1px solid #dbeafe;"><?= htmlspecialchars($st) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <?php if ($activity['speaker']): ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                                <span style="font-size: 0.85rem; color: #334155;"><?= htmlspecialchars($activity['speaker']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($activity['organizer']): ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                                <span style="font-size: 0.85rem; color: #334155;"><?= htmlspecialchars($activity['organizer']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$activity['speaker'] && !$activity['organizer']): ?>
                                            <span style="color: #94a3b8; font-size: 0.85rem;">Not Specified</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <div style="font-size: 0.9rem; font-weight: 500;"><?= date('M d, Y', strtotime($activity['eventdate'])) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= $activity['eventvenue'] ?: 'Location TBD' ?></div>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <?php
                                        $statusClass = '';
                                        $statusStyle = '';
                                        switch($activity['eventstatus']) {
                                            case 'Completed':
                                                $statusStyle = 'background: #dcfce7; color: #166534;';
                                                break;
                                            case 'Ongoing':
                                                $statusStyle = 'background: #dbeafe; color: #1e40af;';
                                                break;
                                            default:
                                                $statusStyle = 'background: #fef9c3; color: #854d0e;';
                                        }
                                    ?>
                                    <span style="<?= $statusStyle ?> padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700;"><?= $activity['eventstatus'] ?></span>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <?php if ($activity['overall_average']): ?>
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            <svg width="14" height="14" fill="#DFB641" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                                            <span style="font-weight: 700; font-size: 0.9rem;"><?= $activity['overall_average'] ?: '0%' ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 0.85rem;">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1.2rem; text-align: right;">
                                    <div class="action-dropdown">
                                        <button class="three-dots-btn" onclick="toggleDropdown(<?= $activity['activity_id'] ?>)">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>
                                            </svg>
                                        </button>
                                        <div id="dropdown-<?= $activity['activity_id'] ?>" class="dropdown-menu">
                                            <button class="dropdown-item" onclick="viewActivity(<?= $activity['activity_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                View Activity
                                            </button>
                                            <button class="dropdown-item" onclick="editActivity(<?= $activity['activity_id'] ?>)">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                Edit Activity
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
            
            <div style="padding: 1rem; background: #f8fafc; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 0.8rem; color: var(--text-secondary);">Showing <b><?= count($activities) ?></b> activities</div>
                <div style="display: flex; gap: 5px;">
                    <button class="btn" style="padding: 5px 10px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem;">Previous</button>
                    <button class="btn" style="padding: 5px 10px; border: 1px solid var(--border-color); background: var(--accent-blue); color: white; font-size: 0.8rem;">1</button>
                    <button class="btn" style="padding: 5px 10px; border: 1px solid var(--border-color); background: white; font-size: 0.8rem;">Next</button>
                </div>
            </div>
        </div>
        <!-- Ranking Section -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 2rem; margin-bottom: 3rem;">
            <div class="ranking-card">
                <div class="ranking-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Top Participation (Response Rate)
                </div>
                <div id="participationRanking">
                    <!-- Dynamic Items -->
                </div>
            </div>

            <div class="ranking-card">
                <div class="ranking-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DFB641" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Top Performance (Overall Average)
                </div>
                <div id="performanceRanking">
                    <!-- Dynamic Items -->
                </div>
            </div>
        </div>

        <!-- Facilitator Ranking Section -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 0rem; margin-bottom: 3rem;">
            <div class="ranking-card">
                <div class="ranking-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Top Speakers (Average Rating)
                </div>
                <div id="speakerRanking">
                    <!-- Dynamic Items -->
                </div>
            </div>

            <div class="ranking-card">
                <div class="ranking-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Top Organizers (Average Rating)
                </div>
                <div id="organizerRanking">
                    <!-- Dynamic Items -->
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../component/activity_modal.php'; ?>

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

    // Handle direct edit link and initialization
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const editId = urlParams.get('edit_id');
        if (editId) {
            editActivity(editId);
        }
        searchActivities(); // Initialize rankings and counts
    });

    function viewActivity(id) {
        window.location.href = 'feed.php?action=view_activity&id=' + id;
    }

    function deleteActivity(id) {
        if(confirm('Are you sure you want to delete this activity?')) {
            window.location.href = '../api/activities.php?action=delete&id=' + id;
        }
    }

    let currentMonthFilter = 'all';

    function filterByMonth(month, btn) {
        currentMonthFilter = month;
        // Update active tab
        document.querySelectorAll('.month-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        
        searchActivities(); // Trigger general filter
    }

    function searchActivities() {
        const searchTerm = document.getElementById('activitySearch').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('.activity-row');
        
        const activeActivities = [];
        const sdgCounts = {};
        for(let i=1; i<=17; i++) sdgCounts[i] = 0;

        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            const status = row.dataset.status;
            const month = row.dataset.month;
            const sdgs = row.dataset.sdgs ? row.dataset.sdgs.split(',') : [];

            const matchesSearch = text.includes(searchTerm);
            const matchesStatus = statusFilter === 'all' || status === statusFilter;
            const matchesMonth = currentMonthFilter === 'all' || month === currentMonthFilter;

            if (matchesSearch && matchesStatus && matchesMonth) {
                row.style.display = '';
                
                // Collect data for rankings
                activeActivities.push({
                    id: row.dataset.id,
                    title: row.dataset.title,
                    rate: parseFloat(row.dataset.responseRate || 0),
                    avg: parseFloat(row.dataset.overallAverage || 0)
                });

                // Count SDGs for visible rows
                sdgs.forEach(id => {
                    if(sdgCounts[id] !== undefined) sdgCounts[id]++;
                });

                if (row.classList.contains('hidden-row')) {
                    row.style.animation = 'slideIn 0.3s ease-out';
                    row.classList.remove('hidden-row');
                }
            } else {
                row.style.display = 'none';
                row.classList.add('hidden-row');
            }
        });

        // Update Rankings
        updateRankings(activeActivities);

        // Update SDG UI
        document.querySelectorAll('.sdg-card').forEach(card => {
            const id = card.dataset.sdgId;
            const count = sdgCounts[id];
            const countLabel = card.querySelector('.sdg-count-val');
            const icon = card.querySelector('.sdg-icon');

            countLabel.innerText = count;
            
            if (count > 0) {
                icon.classList.remove('inactive');
                icon.classList.add('active-icon');
                countLabel.style.color = 'var(--accent-blue)';
                countLabel.style.background = '#eff6ff';
            } else {
                icon.classList.add('inactive');
                icon.classList.remove('active-icon');
                countLabel.style.color = '#64748b';
                countLabel.style.background = '#f1f5f9';
            }
        });
    }

    function updateRankings(activities) {
        const activeIds = activities.map(a => a.id);

        // 1. Activity Participation Ranking (Top 5)
        const participation = [...activities]
            .sort((a, b) => b.rate - a.rate)
            .slice(0, 5);
        renderRankingList('participationRanking', participation, 'rate', '%', '#2563eb');

        // 2. Activity Performance Ranking (Top 5)
        const performance = [...activities]
            .sort((a, b) => b.avg - a.avg)
            .slice(0, 5);
        renderRankingList('performanceRanking', performance, 'avg', '%', '#b45309');

        // 3. Speaker Ranking
        const speakerMap = {};
        speakerRatingsData.forEach(r => {
            if (activeIds.includes(r.activity_id.toString())) {
                if (!speakerMap[r.name]) speakerMap[r.name] = { sum: 0, count: 0 };
                // Calculate average of eff, mot, atf for this session
                const sessionAvg = (parseFloat(r.eff) + parseFloat(r.mot) + parseFloat(r.atf)) / 3;
                speakerMap[r.name].sum += sessionAvg;
                speakerMap[r.name].count++;
            }
        });
        const speakers = Object.keys(speakerMap).map(name => ({
            title: name,
            score: (speakerMap[name].sum / speakerMap[name].count) * 20 // Convert to 100% scale if stored as 1-5
            // Wait, let's assume if it's float it might be 1-5 or 0-100.
            // If eff is 5, (5/5)*100 = 100. Let's check sample data later.
        })).sort((a, b) => b.score - a.score).slice(0, 5);
        renderRankingList('speakerRanking', speakers, 'score', '%', '#ef4444');

        // 4. Organizer Ranking
        const organizerMap = {};
        organizerRatingsData.forEach(r => {
            if (activeIds.includes(r.activity_id.toString())) {
                if (!organizerMap[r.name]) organizerMap[r.name] = { sum: 0, count: 0 };
                const sessionAvg = (parseFloat(r.eff) + parseFloat(r.mot) + parseFloat(r.atf)) / 3;
                organizerMap[r.name].sum += sessionAvg;
                organizerMap[r.name].count++;
            }
        });
        const organizers = Object.keys(organizerMap).map(name => ({
            title: name,
            score: (organizerMap[name].sum / organizerMap[name].count) * 20
        })).sort((a, b) => b.score - a.score).slice(0, 5);
        renderRankingList('organizerRanking', organizers, 'score', '%', '#0ea5e9');
    }

    function renderRankingList(containerId, list, key, suffix, color) {
        const container = document.getElementById(containerId);
        if (list.length === 0) {
            container.innerHTML = '<div style="color: #94a3b8; font-size: 0.85rem; padding: 10px 0; text-align: center;">No ranked data for this selection.</div>';
            return;
        }

        container.innerHTML = list.map((item, index) => `
            <div class="ranking-item">
                <div class="ranking-badge ${index < 3 ? 'badge-' + (index + 1) : ''}" style="${index >= 3 ? 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;' : ''}">
                    ${index + 1}
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div style="font-size: 0.85rem; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${item.title}">
                        ${item.title}
                    </div>
                </div>
                <div style="font-size: 0.85rem; font-weight: 800; color: ${color}">
                    ${item[key].toFixed(1)}${suffix}
                </div>
            </div>
        `).join('');
    }
</script>

<style>
@keyframes slideIn {
    from { opacity: 0; transform: translateX(-10px); }
    to { opacity: 1; transform: translateX(0); }
}
</style>
