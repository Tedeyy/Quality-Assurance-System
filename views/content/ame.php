<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

// Fetch activities with their ratings and SDGs
$query = "SELECT a.*, s.overall_average, GROUP_CONCAT(sdg.title SEPARATOR ', ') as sdg_titles
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
?>

<style>
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
                <button class="btn btn-secondary" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Export Report
                </button>
                <button class="btn btn-primary" onclick="document.getElementById('addActivityModal').style.display='flex'" style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add Activity
                </button>
            </div>
        </div>

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
                        if($act['overall_average'] > 0) {
                            $total_rating += $act['overall_average'];
                            $rated_count++;
                        }
                    }
                    $avg = $rated_count > 0 ? number_format($total_rating / $rated_count, 2) : '0.00';
                ?>
                <div style="font-size: 2rem; font-weight: 800; color: var(--accent-gold); margin-top: 5px;"><?= $avg ?> / 5.0</div>
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

        <!-- Filter & Search Section -->
        <div style="background: white; padding: 1rem; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; position: relative; min-width: 250px;">
                <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" placeholder="Search activities..." style="width: 100%; padding: 0.7rem 0.7rem 0.7rem 2.5rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem;">
            </div>
            <select style="padding: 0.7rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; font-size: 0.9rem; min-width: 150px; background: white;">
                <option value="">All Status</option>
                <option value="upcoming">Upcoming</option>
                <option value="ongoing">In Progress</option>
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
                            <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
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
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 32px; height: 32px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: var(--accent-blue);">
                                            <?= strtoupper(substr($activity['organizer'] ?: '?', 0, 1)) ?>
                                        </div>
                                        <span style="font-size: 0.9rem;"><?= htmlspecialchars($activity['organizer'] ?: 'Not Specified') ?></span>
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
                                            <span style="font-weight: 700; font-size: 0.9rem;"><?= number_format($activity['overall_average'], 1) ?></span>
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
    </div>
</main>

<!-- Add Activity Modal -->
<div id="addActivityModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div style="background: white; padding: 2rem; border-radius: 12px; width: 600px; max-width: 90vw; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="margin: 0; color: var(--accent-blue);">Create New Activity</h2>
            <button onclick="document.getElementById('addActivityModal').style.display='none'" style="background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>
        
        <form id="addActivityForm" method="POST" action="../api/activities.php?action=create">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; margin-bottom: 1.2rem;">
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Activity Title *</label>
                    <input type="text" name="title" placeholder="Enter activity title" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.8rem;">Sustainable Development Goals (SDGs)</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; background: #f8fafc; padding: 1.2rem; border-radius: 8px; border: 1px solid var(--border-color); max-height: 220px; overflow-y: auto;">
                        <?php foreach($sdgs as $sdg): ?>
                            <label style="display: flex; align-items: flex-start; gap: 10px; font-size: 0.85rem; cursor: pointer; padding: 8px; border-radius: 6px; transition: background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" name="sdg_ids[]" value="<?= $sdg['sdg_id'] ?>" style="margin-top: 2px; width: 17px; height: 17px; cursor: pointer; accent-color: var(--accent-blue);">
                                <span style="color: #334155; line-height: 1.4;"><b>SDG <?= $sdg['sdg_id'] ?></b>: <?= htmlspecialchars($sdg['title']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Facilitator/Organizer</label>
                    <input type="text" name="organizer" placeholder="Name of organizer" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Event Date *</label>
                    <input type="date" name="eventdate" required style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Status</label>
                    <select name="eventstatus" style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; background: white;">
                        <option value="Pending">Upcoming</option>
                        <option value="Ongoing">In Progress</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Venue/Location</label>
                    <input type="text" name="eventvenue" placeholder="e.g. Conference Room A, Zoom Link, etc." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none;">
                </div>
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 0.9rem; font-weight: 600; margin-bottom: 0.5rem;">Description</label>
                    <textarea name="description" placeholder="Briefly describe the activity..." style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 8px; outline: none; height: 100px; resize: none;"></textarea>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 2rem;">
                <button type="button" onclick="document.getElementById('addActivityModal').style.display='none'" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Activity</button>
            </div>
        </form>
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

    // Close modals and dropdowns on click outside
    window.onclick = function(event) {
        const modal = document.getElementById('addActivityModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
        
        if (!event.target.closest('.action-dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(m => {
                m.style.display = 'none';
            });
        }
    }

    function viewActivity(id) {
        console.log('Viewing activity:', id);
        // Implement view logic
    }

    function editActivity(id) {
        console.log('Editing activity:', id);
        // Implement edit logic
    }

    function deleteActivity(id) {
        if(confirm('Are you sure you want to delete this activity?')) {
            window.location.href = '../api/activities.php?action=delete&id=' + id;
        }
    }
</script>
