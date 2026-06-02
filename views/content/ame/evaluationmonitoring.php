<?php
require_once __DIR__ . '/../../../config/database.php';

$db = (new Database())->getConnection();

// Fetch stats
$stats = [
    'total' => 0,
    'improved' => 0,
    'in_progress' => 0,
    'pending' => 0
];

$stat_query = $db->query("
    SELECT status, COUNT(*) as count 
    FROM activity_evaluation_monitoring 
    GROUP BY status
");

while ($row = $stat_query->fetch(PDO::FETCH_ASSOC)) {
    $stats['total'] += $row['count'];
    if ($row['status'] === 'Improved' || $row['status'] === 'Solved') {
        $stats['improved'] += $row['count'];
    } elseif ($row['status'] === 'In Progress') {
        $stats['in_progress'] += $row['count'];
    } elseif ($row['status'] === 'Pending') {
        $stats['pending'] += $row['count'];
    }
}

// Pagination setup
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Count total records for pagination
$total_query = $db->query("
    SELECT COUNT(*) FROM activity_evaluation_monitoring m
    JOIN activity_evaluation e ON m.evaluation_id = e.evaluation_id
    JOIN activities a ON e.activity_id = a.activity_id
");
$total_records = $total_query->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch monitoring entries with pagination
$query = "
    SELECT 
        m.feedback_id as id,
        m.created_at as date,
        a.title as activity,
        m.tag as type,
        COALESCE(m.complaints, m.suggestions_for_improvement) as feedback,
        m.actions_taken as action_taken,
        m.case_status as status
    FROM activity_evaluation_monitoring m
    JOIN activity_evaluation e ON m.evaluation_id = e.evaluation_id
    JOIN activities a ON e.activity_id = a.activity_id
    ORDER BY m.created_at DESC
    LIMIT $limit OFFSET $offset
";
$monitoring_data = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .em-header {
        background: linear-gradient(135deg, var(--accent-blue) 0%, #1e3a8a 100%);
        color: white;
        padding: 2rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        box-shadow: 0 10px 25px -5px rgba(30, 58, 138, 0.2);
    }
    
    .em-title {
        font-size: 2rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 12px;
        color: white;
    }
    
    .em-subtitle {
        color: #e2e8f0;
        font-size: 1rem;
        max-width: 800px;
        line-height: 1.5;
    }

    .em-stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .em-stat-card {
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 8px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .em-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    .em-stat-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .em-stat-value {
        font-size: 2rem;
        font-weight: 800;
        color: #0f172a;
    }

    .em-table-container {
        background: white;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .em-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .em-table th {
        padding: 1.2rem;
        font-size: 0.85rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        background: #f8fafc;
        border-bottom: 2px solid var(--border-color);
    }

    .em-table td {
        padding: 1.2rem;
        border-bottom: 1px solid var(--border-color);
        vertical-align: top;
    }

    .em-table tr:hover {
        background: #f8fafc;
    }

    .em-badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        display: inline-block;
    }

    .badge-resolved { background: #dcfce7; color: #166534; }
    .badge-unresolved { background: #fee2e2; color: #991b1b; }
    
    .badge-complaint { background: #fee2e2; color: #991b1b; }
    .badge-suggestion { background: #f3e8ff; color: #6b21a8; }
    
    .action-cell {
        position: relative;
        text-align: center;
    }
    .three-dots-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 5px;
        color: #64748b;
    }
    .dropdown-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        background: white;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        z-index: 10;
        min-width: 120px;
    }
    .dropdown-menu.show {
        display: block;
    }
    .dropdown-item {
        display: block;
        padding: 8px 16px;
        color: #334155;
        text-decoration: none;
        font-size: 0.85rem;
        text-align: left;
    }
    .dropdown-item:hover {
        background: #f8fafc;
    }
    .pagination {
        display: flex;
        justify-content: center;
        gap: 5px;
        padding: 1rem;
        background: white;
        border-top: 1px solid var(--border-color);
    }
    .page-btn {
        padding: 6px 12px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: white;
        color: #475569;
        text-decoration: none;
        font-size: 0.85rem;
    }
    .page-btn.active {
        background: var(--accent-blue);
        color: white;
        border-color: var(--accent-blue);
    }
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem; padding-bottom: 4rem;">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">

        <div class="em-header">
            <h1 class="em-title">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                Evaluation Monitoring
            </h1>
            <p class="em-subtitle">Track complaints and suggestions gathered from activity evaluations, monitor actions taken, and verify if improvements have been implemented effectively.</p>
        </div>

        <div class="em-stats-container">
            <div class="em-stat-card">
                <div class="em-stat-title">Total Feedbacks</div>
                <div class="em-stat-value"><?= $stats['total'] ?></div>
            </div>
            <div class="em-stat-card">
                <div class="em-stat-title">Improvements Made</div>
                <div class="em-stat-value" style="color: #16a34a;"><?= $stats['improved'] ?></div>
            </div>
            <div class="em-stat-card">
                <div class="em-stat-title">In Progress</div>
                <div class="em-stat-value" style="color: #2563eb;"><?= $stats['in_progress'] ?></div>
            </div>
            <div class="em-stat-card">
                <div class="em-stat-title">Pending Action</div>
                <div class="em-stat-value" style="color: #ea580c;"><?= $stats['pending'] ?></div>
            </div>
        </div>

        <div class="em-table-container">
            <div style="padding: 1rem 1.2rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: white;">
                <h3 style="margin: 0; font-size: 1.1rem; color: #0f172a;">Feedback Log</h3>
                <div style="display: flex; gap: 10px;">
                    <button class="btn btn-primary" style="font-size: 0.85rem; padding: 8px 16px;">Export Report</button>
                </div>
            </div>
            <table class="em-table">
                <thead>
                    <tr>
                        <th>ID & Date</th>
                        <th>Activity & Type</th>
                        <th style="width: 30%;">Feedback</th>
                        <th style="width: 30%;">Action Taken</th>
                        <th>Status</th>
                        <th style="text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monitoring_data)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 3rem; color: #64748b;">
                                <strong>No feedback records found.</strong>
                                <p style="margin-top: 5px; font-size: 0.9rem;">Analyze activity evaluations to generate tracking entries.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monitoring_data as $row): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700; color: #334155; font-family: monospace;">#<?= htmlspecialchars($row['id']) ?></div>
                                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 4px;"><?= date('M d, Y', strtotime($row['date'])) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--accent-blue); margin-bottom: 6px;"><?= htmlspecialchars($row['activity']) ?></div>
                                    <span class="em-badge <?= $row['type'] === 'Complaint' ? 'badge-complaint' : 'badge-suggestion' ?>">
                                        <?= htmlspecialchars($row['type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <p style="margin: 0; font-size: 0.9rem; color: #475569; line-height: 1.5;"><?= nl2br(htmlspecialchars($row['feedback'])) ?></p>
                                </td>
                                <td>
                                    <p style="margin: 0; font-size: 0.9rem; color: #475569; line-height: 1.5;">
                                        <?php if ($row['action_taken']): ?>
                                            <?= nl2br(htmlspecialchars($row['action_taken'])) ?>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">No action recorded yet.</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                                <td>
                                    <?php
                                        $statusClass = 'badge-unresolved';
                                        if ($row['status'] === 'Resolved') {
                                            $statusClass = 'badge-resolved';
                                        }
                                    ?>
                                    <span class="em-badge <?= $statusClass ?>">
                                        <?= htmlspecialchars($row['status']) ?>
                                    </span>
                                </td>
                                <td class="action-cell">
                                    <button class="three-dots-btn" onclick="toggleDropdown(event, 'dropdown-<?= htmlspecialchars($row['id']) ?>')">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                    </button>
                                    <div id="dropdown-<?= htmlspecialchars($row['id']) ?>" class="dropdown-menu">
                                        <a href="#" class="dropdown-item">Details</a>
                                        <a href="#" class="dropdown-item">Archive</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="page-btn">Previous</a>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="page-btn">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
    function toggleDropdown(event, id) {
        event.stopPropagation();
        const dropdowns = document.querySelectorAll('.dropdown-menu');
        dropdowns.forEach(d => {
            if (d.id !== id) d.classList.remove('show');
        });
        document.getElementById(id).classList.toggle('show');
    }

    document.addEventListener('click', () => {
        const dropdowns = document.querySelectorAll('.dropdown-menu');
        dropdowns.forEach(d => d.classList.remove('show'));
    });
</script>
