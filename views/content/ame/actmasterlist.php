<?php
require_once __DIR__ . '/../../../config/database.php';
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
?>

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
    .activity-pagination {
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
    .pagination-ellipsis {
        color: #94a3b8;
    }
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem;">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        <!-- Header Section -->
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.5rem;">
            <div>
                <h1 style="font-size: 2rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 12px;">
                    <div style="background: var(--accent-blue); color: white; padding: 8px; border-radius: 10px; display: flex;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </div>
                    Activity Masterlist
                </h1>
                <p style="color: var(--text-secondary); font-size: 0.95rem;">Comprehensive registry of institutional activities, facilitators, venues, and status logs.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-primary" onclick="openAddModal('../views/feed.php?action=actmasterlist')" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add Activity
                </button>
            </div>
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
                <input type="text" id="activitySearch" onkeyup="handleActivityFilterChange()" placeholder="Search activities by title, description or facilitator..." style="width: 100%; padding: 0.7rem 0.7rem 0.7rem 2.5rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;">
            </div>
            <select id="statusFilter" onchange="handleActivityFilterChange()" style="padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; min-width: 150px; background: white;">
                <option value="all">All Status</option>
                <option value="upcoming">Upcoming (Pending)</option>
                <option value="ongoing">In Progress (Ongoing)</option>
                <option value="completed">Completed</option>
            </select>
        </div>

        <!-- Activities Table -->
        <div style="background: white; border-radius: 12px; border: 1px solid var(--border-color); overflow: visible; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); margin-bottom: 3rem;">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Activity Details</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Facilitator</th>
                        <th style="padding: 1.2rem; font-size: 0.85rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase;">Date & Venue</th>
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
                                data-date="<?= $activity['eventdate'] ?>"
                                data-month="<?= date('F Y', strtotime($activity['eventdate'])) ?>" 
                                data-status="<?= $status_val ?>" 
                                data-sdgs="<?= $activity['sdg_ids'] ?>"
                                data-title="<?= htmlspecialchars($activity['title']) ?>"
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
                                    <div style="font-size: 0.9rem; font-weight: 700; color: #1e293b; margin-bottom: 2px;"><?= date('M d, Y', strtotime($activity['eventdate'])) ?></div>
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                                        <div style="font-size: 0.8rem; color: #475569; display: flex; align-items: center; gap: 4px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            <?= date('h:i A', strtotime($activity['eventdate'])) ?>
                                        </div>
                                        <?php if (!empty($activity['duration'])): ?>
                                            <div style="font-size: 0.7rem; color: #64748b; background: #f1f5f9; padding: 1px 6px; border-radius: 4px; border: 1px solid #e2e8f0; font-weight: 500;">
                                                <?= htmlspecialchars($activity['duration']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <?= $activity['eventvenue'] ?: 'Location TBD' ?>
                                    </div>
                                </td>
                                <td style="padding: 1.2rem;">
                                    <?php
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
                                            <button class="dropdown-item" onclick="editActivity(<?= $activity['activity_id'] ?>, '../views/feed.php?action=actmasterlist')">
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
            
            <div style="padding: 1rem; background: #f8fafc; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                <div style="font-size: 0.8rem; color: var(--text-secondary);">Showing <b id="showing-range">0</b> of <b id="showing-count"><?= count($activities) ?></b> activities</div>
                <div class="activity-pagination" id="activityPagination"></div>
            </div>
        </div>
    </div>
</main>

<!-- View Activity Details Modal -->
<div id="viewActivityModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(8px); animation: fadeIn 0.25s ease-out;">
    <div style="background: white; padding: 2.2rem; border-radius: 16px; width: 800px; max-width: 95vw; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); max-height: 90vh; overflow-y: auto; font-family: 'Inter', sans-serif;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1.2rem;">
            <div>
                <span id="view_status_badge" style="padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;">Pending</span>
                <h2 id="view_title" style="margin: 0; color: #0f172a; font-size: 1.6rem; font-weight: 800; line-height: 1.3;">Activity Title</h2>
                <p id="view_code" style="color: #64748b; font-size: 0.8rem; font-weight: 700; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 0.5px;">CODE: ABC12345</p>
            </div>
            <button onclick="document.getElementById('viewActivityModal').style.display='none'" style="background: transparent; border: none; font-size: 2rem; cursor: pointer; color: #94a3b8; line-height: 1; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">&times;</button>
        </div>

        <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 2rem;">
            <!-- Left Side: Basic Info -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <div>
                    <h4 style="margin: 0 0 0.5rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Description</h4>
                    <p id="view_description" style="margin: 0; font-size: 0.95rem; color: #334155; line-height: 1.6; white-space: pre-wrap; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid var(--border-color); max-height: 150px; overflow-y: auto; scrollbar-width: thin;">Activity description goes here...</p>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; background: #f8fafc; padding: 1.2rem; border-radius: 12px; border: 1px solid var(--border-color);">
                    <div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Date</span>
                        <span id="view_date" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">Oct 12, 2026</span>
                    </div>
                    <div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Time</span>
                        <span id="view_time" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">10:00 AM</span>
                    </div>
                    <div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Duration</span>
                        <span id="view_duration" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">2 Hours</span>
                    </div>
                    <div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Venue</span>
                        <span id="view_venue" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">Conference Room A</span>
                    </div>
                </div>

                <div>
                    <h4 style="margin: 0 0 0.5rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Requesting Office</h4>
                    <div style="font-size: 0.95rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid var(--border-color);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        <span id="view_office_text">Quality Assurance Office</span>
                    </div>
                </div>
            </div>

            <!-- Right Side: Facilitators, SDGs, Links -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <div>
                    <h4 style="margin: 0 0 0.6rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Facilitators</h4>
                    <div id="view_facilitators" style="display: flex; flex-direction: column; gap: 8px; max-height: 140px; overflow-y: auto; scrollbar-width: thin;">
                        <!-- Dynamic list -->
                    </div>
                </div>

                <div>
                    <h4 style="margin: 0 0 0.6rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Target Participants</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px; background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid var(--border-color);">
                        <div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">Groups:</span>
                            <span id="view_target_groups" style="font-size: 0.85rem; font-weight: 600; color: #334155;">Everyone, Student</span>
                        </div>
                        <div style="border-top: 1px solid var(--border-color); margin-top: 4px; padding-top: 6px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; font-weight: 700; color: #64748b;">Expected Count:</span>
                            <span id="view_target_count" style="font-size: 0.9rem; font-weight: 800; color: var(--accent-blue);">150</span>
                        </div>
                    </div>
                </div>

                <div>
                    <h4 style="margin: 0 0 0.6rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">SDGs Addressed</h4>
                    <div id="view_sdgs" style="display: flex; flex-wrap: wrap; gap: 6px; max-height: 100px; overflow-y: auto; scrollbar-width: thin;">
                        <!-- SDGs tags -->
                    </div>
                </div>

                <div>
                    <h4 style="margin: 0 0 0.6rem 0; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Documentation Links</h4>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <a id="view_request_email" href="#" target="_blank" class="btn" style="display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.85rem; padding: 10px; width: 100%; text-decoration: none; border: 1px solid var(--border-color); background: white; color: #334155; font-weight: 600; border-radius: 8px; transition: all 0.2s;" onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.background='white'; this.style.borderColor='var(--border-color)';">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            View Request Email
                        </a>
                        <a id="view_doc_link" href="#" target="_blank" class="btn" style="display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.85rem; padding: 10px; width: 100%; text-decoration: none; border: 1px solid var(--border-color); background: white; color: #334155; font-weight: 600; border-radius: 8px; transition: all 0.2s;" onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.background='white'; this.style.borderColor='var(--border-color)';">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            Documentation Link
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.2rem;">
            <button type="button" onclick="document.getElementById('viewActivityModal').style.display='none'" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700; border-radius: 8px; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../component/activity_modal.php'; ?>

<script>
    const sdgTitles = {
        1: "No Poverty",
        2: "Zero Hunger",
        3: "Good Health and Well-being",
        4: "Quality Education",
        5: "Gender Equality",
        6: "Clean Water and Sanitation",
        7: "Affordable and Clean Energy",
        8: "Decent Work and Economic Growth",
        9: "Industry, Innovation and Infrastructure",
        10: "Reduced Inequalities",
        11: "Sustainable Cities and Communities",
        12: "Responsible Consumption and Production",
        13: "Climate Action",
        14: "Life Below Water",
        15: "Life on Land",
        16: "Peace, Justice and Strong Institutions",
        17: "Partnerships for the Goals"
    };

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
            editActivity(editId, '../views/feed.php?action=actmasterlist');
        }
        
        const savedMonth = sessionStorage.getItem('actmasterlistMonth');
        if (savedMonth && savedMonth !== 'all') {
            document.querySelectorAll('.month-tab').forEach(t => {
                t.classList.remove('active');
                if (t.innerText.trim() === savedMonth) {
                    t.classList.add('active');
                }
            });
        }
        
        searchActivities();
    });

    async function viewActivity(id) {
        try {
            const response = await fetch('../api/activities.php?action=get&id=' + id);
            if (!response.ok) throw new Error('Failed to fetch activity details');
            const data = await response.json();
            
            // Show status badge
            const statusBadge = document.getElementById('view_status_badge');
            statusBadge.textContent = data.eventstatus;
            
            let statusStyle = '';
            switch(data.eventstatus) {
                case 'Completed':
                    statusStyle = 'background: #dcfce7; color: #166534;';
                    break;
                case 'Ongoing':
                    statusStyle = 'background: #dbeafe; color: #1e40af;';
                    break;
                default:
                    statusStyle = 'background: #fef9c3; color: #854d0e;';
            }
            statusBadge.style.cssText = statusStyle + ' padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; display: inline-block; margin-bottom: 8px;';
            
            // Populate fields
            document.getElementById('view_title').textContent = data.title;
            document.getElementById('view_code').textContent = 'CODE: ' + (data.activity_code || 'N/A');
            document.getElementById('view_description').textContent = data.description || 'No description provided.';
            
            // Date & Time split
            if (data.eventdate) {
                const parts = data.eventdate.split(' ');
                const dateObj = new Date(parts[0]);
                document.getElementById('view_date').textContent = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                
                if (parts[1]) {
                    const timeParts = parts[1].split(':');
                    const hour = parseInt(timeParts[0]);
                    const min = timeParts[1];
                    const ampm = hour >= 12 ? 'PM' : 'AM';
                    const dispHour = hour % 12 || 12;
                    document.getElementById('view_time').textContent = `${dispHour}:${min} ${ampm}`;
                } else {
                    document.getElementById('view_time').textContent = 'N/A';
                }
            } else {
                document.getElementById('view_date').textContent = 'N/A';
                document.getElementById('view_time').textContent = 'N/A';
            }
            
            document.getElementById('view_duration').textContent = data.duration || 'N/A';
            document.getElementById('view_venue').textContent = data.eventvenue || 'TBD';
            
            // Fetch office name from modal dropdown
            const officeSelect = document.querySelector('[name="requesting_office_id"]');
            let officeName = 'Not Specified';
            if (officeSelect && data.requesting_office_id) {
                const opt = officeSelect.querySelector(`option[value="${data.requesting_office_id}"]`);
                if (opt) officeName = opt.textContent.trim();
            }
            document.getElementById('view_office_text').textContent = officeName;
            
            // Target Participants
            document.getElementById('view_target_groups').textContent = data.target_participants || 'None Specified';
            document.getElementById('view_target_count').textContent = data.number_of_participants || '0';
            
            // Facilitators
            let facilitatorsHTML = '';
            const facilitators = data.facilitators || [];
            if (facilitators.length === 0) {
                const legacySpeakers = data.speaker ? data.speaker.split(', ').filter(Boolean) : [];
                const legacyOrganizers = data.organizer ? data.organizer.split(', ').filter(Boolean) : [];
                
                if (legacySpeakers.length === 0 && legacyOrganizers.length === 0) {
                    facilitatorsHTML = '<span style="color: #94a3b8; font-size: 0.85rem;">Not Specified</span>';
                } else {
                    legacySpeakers.forEach(name => {
                        facilitatorsHTML += renderFacilitatorRow(name, 'speaker');
                    });
                    legacyOrganizers.forEach(name => {
                        facilitatorsHTML += renderFacilitatorRow(name, 'organizer');
                    });
                }
            } else {
                facilitators.forEach(f => {
                    facilitatorsHTML += renderFacilitatorRow(f.name, f.role);
                });
            }
            document.getElementById('view_facilitators').innerHTML = facilitatorsHTML;
            
            // SDGs
            let sdgsHTML = '';
            const sdgIds = data.sdg_ids || [];
            if (sdgIds.length === 0) {
                sdgsHTML = '<span style="color: #94a3b8; font-size: 0.85rem;">None</span>';
            } else {
                sdgIds.forEach(id => {
                    const title = sdgTitles[id] || `SDG ${id}`;
                    sdgsHTML += `
                        <span style="font-size: 0.75rem; background: #eff6ff; color: #1e40af; padding: 4px 10px; border-radius: 6px; border: 1px solid #dbeafe; font-weight: 600;">
                            <b>SDG ${id}</b>: ${title}
                        </span>
                    `;
                });
            }
            document.getElementById('view_sdgs').innerHTML = sdgsHTML;
            
            // Link details
            const reqEmailBtn = document.getElementById('view_request_email');
            if (data.request_email_link) {
                reqEmailBtn.href = data.request_email_link;
                reqEmailBtn.style.display = 'flex';
            } else {
                reqEmailBtn.style.display = 'none';
            }
            
            const docLinkBtn = document.getElementById('view_doc_link');
            if (data.email_link) {
                docLinkBtn.href = data.email_link;
                docLinkBtn.style.display = 'flex';
            } else {
                docLinkBtn.style.display = 'none';
            }
            
            // Display modal
            document.getElementById('viewActivityModal').style.display = 'flex';
        } catch (e) {
            console.error('Error viewing activity:', e);
            alert('Error fetching activity details: ' + e.message);
        }
    }

    function renderFacilitatorRow(name, role) {
        const isSpeaker = role === 'speaker';
        const strokeColor = isSpeaker ? '#ef4444' : '#0ea5e9';
        const iconSVG = isSpeaker 
            ? `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="${strokeColor}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`
            : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="${strokeColor}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`;
        const roleName = isSpeaker ? 'Speaker' : 'Organizer';
        
        return `
            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    ${iconSVG}
                    <span style="font-size: 0.85rem; font-weight: 600; color: #334155;">${escapeHtml(name)}</span>
                </div>
                <span style="font-size: 0.7rem; font-weight: 700; color: ${strokeColor}; background: ${isSpeaker ? '#fef2f2' : '#eff6ff'}; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">${roleName}</span>
            </div>
        `;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    }

    function deleteActivity(id) {
        if(confirm('Are you sure you want to delete this activity?')) {
            window.location.href = '../api/activities.php?action=delete&id=' + id + '&redirect_url=' + encodeURIComponent('../views/feed.php?action=actmasterlist');
        }
    }

    const activitiesPerPage = 10;
    let currentActivityPage = 1;
    let currentMonthFilter = sessionStorage.getItem('actmasterlistMonth') || 'all';

    function filterByMonth(month, btn) {
        currentMonthFilter = month;
        currentActivityPage = 1;
        sessionStorage.setItem('actmasterlistMonth', month);
        document.querySelectorAll('.month-tab').forEach(t => t.classList.remove('active'));
        if (btn) btn.classList.add('active');
        searchActivities(false);
    }

    function handleActivityFilterChange() {
        currentActivityPage = 1;
        searchActivities(false);
    }

    function searchActivities(resetPage = true) {
        if (resetPage) currentActivityPage = 1;

        const searchTerm = document.getElementById('activitySearch').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('.activity-row');
        const matchingRows = [];
        
        rows.forEach(row => {
            const title = row.getAttribute('data-title').toLowerCase();
            const rowMonth = row.getAttribute('data-month');
            const status = row.getAttribute('data-status');
            const textContent = row.textContent.toLowerCase();
            
            const matchesSearch = title.includes(searchTerm) || textContent.includes(searchTerm);
            const matchesStatus = statusFilter === 'all' || status === statusFilter;
            const matchesMonth = currentMonthFilter === 'all' || rowMonth === currentMonthFilter;
            
            if (matchesSearch && matchesStatus && matchesMonth) {
                matchingRows.push(row);
            } else {
                row.style.display = 'none';
            }
        });

        renderActivityPage(matchingRows);
    }

    function renderActivityPage(rows) {
        const totalItems = rows.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / activitiesPerPage));
        currentActivityPage = Math.min(Math.max(currentActivityPage, 1), totalPages);

        const startIndex = (currentActivityPage - 1) * activitiesPerPage;
        const endIndex = startIndex + activitiesPerPage;

        rows.forEach((row, index) => {
            row.style.display = index >= startIndex && index < endIndex ? '' : 'none';
        });

        const visibleStart = totalItems === 0 ? 0 : startIndex + 1;
        const visibleEnd = Math.min(endIndex, totalItems);
        document.getElementById('showing-range').textContent = totalItems === 0 ? '0' : `${visibleStart}-${visibleEnd}`;
        document.getElementById('showing-count').textContent = totalItems;
        renderActivityPagination(totalPages);
    }

    function getPaginationPages(totalPages) {
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, (_, i) => i + 1);
        }

        const pages = [1];
        const start = Math.max(2, currentActivityPage - 1);
        const end = Math.min(totalPages - 1, currentActivityPage + 1);

        if (start > 2) pages.push('ellipsis-start');
        for (let page = start; page <= end; page++) pages.push(page);
        if (end < totalPages - 1) pages.push('ellipsis-end');
        pages.push(totalPages);

        return pages;
    }

    function renderActivityPagination(totalPages) {
        const pagination = document.getElementById('activityPagination');
        if (!pagination) return;

        const pageItems = getPaginationPages(totalPages);
        const pageButtons = pageItems.map(item => {
            if (typeof item === 'string') {
                return '<span class="pagination-ellipsis">...</span>';
            }

            return `<button type="button" class="pagination-btn ${item === currentActivityPage ? 'active' : ''}" onclick="goToActivityPage(${item})">${item}</button>`;
        }).join('');

        pagination.innerHTML = `
            <button type="button" class="pagination-btn" onclick="goToActivityPage(${currentActivityPage - 1})" ${currentActivityPage === 1 ? 'disabled' : ''}>Previous</button>
            ${pageButtons}
            <button type="button" class="pagination-btn" onclick="goToActivityPage(${currentActivityPage + 1})" ${currentActivityPage === totalPages ? 'disabled' : ''}>Next</button>
        `;
    }

    function goToActivityPage(page) {
        currentActivityPage = page;
        searchActivities(false);
    }

    // Close action menus when clicking outside
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    });
</script>
