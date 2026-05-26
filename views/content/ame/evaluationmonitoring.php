<?php
// Sample Evaluation Monitoring Page
// This displays dummy data to illustrate complaints/suggestions tracking and improvement.

$sample_data = [
    [
        'id' => 'EM-001',
        'date' => '2026-05-10',
        'activity' => 'Leadership Training Seminar',
        'type' => 'Complaint',
        'feedback' => 'The venue was too small for the number of participants, causing discomfort.',
        'action_taken' => 'Secured a larger auditorium for the next session. Ensured RSVP count strictly matches venue capacity.',
        'status' => 'Improved'
    ],
    [
        'id' => 'EM-002',
        'date' => '2026-05-14',
        'activity' => 'Curriculum Review Workshop',
        'type' => 'Suggestion',
        'feedback' => 'Provide more interactive materials rather than just reading from presentations.',
        'action_taken' => 'Instructed facilitators to include group activities. Currently monitoring upcoming workshop formats.',
        'status' => 'In Progress'
    ],
    [
        'id' => 'EM-003',
        'date' => '2026-05-18',
        'activity' => 'Research Ethics Forum',
        'type' => 'Complaint',
        'feedback' => 'Sound system was barely audible at the back of the room.',
        'action_taken' => 'Requested IT department to inspect and upgrade sound equipment.',
        'status' => 'Pending'
    ],
    [
        'id' => 'EM-004',
        'date' => '2026-05-20',
        'activity' => 'Student Orientation',
        'type' => 'Suggestion',
        'feedback' => 'Allocate more time for Q&A sessions at the end of the program.',
        'action_taken' => 'Revised the standard program flow template to ensure a dedicated 30-minute Q&A block.',
        'status' => 'Improved'
    ],
];
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

    .badge-improved { background: #dcfce7; color: #166534; }
    .badge-inprogress { background: #dbeafe; color: #1e40af; }
    .badge-pending { background: #fef9c3; color: #854d0e; }
    
    .badge-complaint { background: #fee2e2; color: #991b1b; }
    .badge-suggestion { background: #f3e8ff; color: #6b21a8; }
</style>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem; padding-bottom: 4rem;">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
        
        <!-- In Development Banner -->
        <div style="background-color: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 2rem; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 0.95rem;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #d97706; flex-shrink: 0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <div>
                <strong style="display: block; margin-bottom: 2px;">In Development</strong>
                This module is currently in development. The information displayed is sample data only.
            </div>
        </div>

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
                <div class="em-stat-value">4</div>
            </div>
            <div class="em-stat-card">
                <div class="em-stat-title">Improvements Made</div>
                <div class="em-stat-value" style="color: #16a34a;">2</div>
            </div>
            <div class="em-stat-card">
                <div class="em-stat-title">In Progress</div>
                <div class="em-stat-value" style="color: #2563eb;">1</div>
            </div>
            <div class="em-stat-card">
                <div class="em-stat-title">Pending Action</div>
                <div class="em-stat-value" style="color: #ea580c;">1</div>
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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sample_data as $row): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #334155;"><?= $row['id'] ?></div>
                                <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 4px;"><?= date('M d, Y', strtotime($row['date'])) ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--accent-blue); margin-bottom: 6px;"><?= $row['activity'] ?></div>
                                <span class="em-badge <?= $row['type'] === 'Complaint' ? 'badge-complaint' : 'badge-suggestion' ?>">
                                    <?= $row['type'] ?>
                                </span>
                            </td>
                            <td>
                                <p style="margin: 0; font-size: 0.9rem; color: #475569; line-height: 1.5;"><?= htmlspecialchars($row['feedback']) ?></p>
                            </td>
                            <td>
                                <p style="margin: 0; font-size: 0.9rem; color: #475569; line-height: 1.5;">
                                    <?php if ($row['action_taken']): ?>
                                        <?= htmlspecialchars($row['action_taken']) ?>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-style: italic;">No action recorded yet.</span>
                                    <?php endif; ?>
                                </p>
                            </td>
                            <td>
                                <?php
                                    $statusClass = '';
                                    if ($row['status'] === 'Improved') $statusClass = 'badge-improved';
                                    elseif ($row['status'] === 'In Progress') $statusClass = 'badge-inprogress';
                                    else $statusClass = 'badge-pending';
                                ?>
                                <span class="em-badge <?= $statusClass ?>">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>
