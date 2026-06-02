<?php
require_once __DIR__ . '/../../../config/database.php';

$db = (new Database())->getConnection();
$feedback_id = $_GET['id'] ?? null;

if (!$feedback_id) {
    echo "<div class='container' style='padding: 2rem;'><h3>Error: No Feedback ID provided.</h3></div>";
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actions_taken = $_POST['actions_taken'] ?? null;
    $date_implemented = !empty($_POST['date_implemented']) ? $_POST['date_implemented'] : null;
    $status = $_POST['status'] ?? 'Pending';
    $case_status = $_POST['case_status'] ?? 'Unresolved';

    $update = $db->prepare("
        UPDATE activity_evaluation_monitoring 
        SET actions_taken = :actions_taken, 
            date_implemented = :date_implemented, 
            status = :status, 
            case_status = :case_status
        WHERE feedback_id = :id
    ");
    $update->execute([
        ':actions_taken' => $actions_taken,
        ':date_implemented' => $date_implemented,
        ':status' => $status,
        ':case_status' => $case_status,
        ':id' => $feedback_id
    ]);

    // Refresh page after post
    header("Location: ?action=monitoringdetails&id=" . urlencode($feedback_id) . "&success=1");
    exit;
}

// Fetch the monitoring details
$stmt = $db->prepare("
    SELECT 
        m.*, 
        a.title, a.eventdate, a.eventvenue, a.speaker, a.organizer, a.activity_id,
        o.name as office_name
    FROM activity_evaluation_monitoring m
    JOIN activity_evaluation e ON m.evaluation_id = e.evaluation_id
    JOIN activities a ON e.activity_id = a.activity_id
    LEFT JOIN divisions_offices o ON a.requesting_office_id = o.office_id
    WHERE m.feedback_id = :id
");
$stmt->execute([':id' => $feedback_id]);
$details = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$details) {
    echo "<div class='container' style='padding: 2rem;'><h3>Error: Feedback not found.</h3></div>";
    exit;
}

// Get facilitators to combine with speaker/organizer fields if needed
$fac_stmt = $db->prepare(
    "SELECT af.role, af.person_id, COALESCE(sp.name, og.name) AS name
     FROM activity_facilitators af
     LEFT JOIN speakers sp ON af.role = 'speaker' AND af.person_id = sp.speaker_id
     LEFT JOIN organizers og ON af.role = 'organizer' AND af.person_id = og.organizer_id
     WHERE af.activity_id = :id"
);
$fac_stmt->execute([':id' => $details['activity_id']]);
$facilitators = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

$speaker_names = [];
$organizer_names = [];
foreach ($facilitators as $fac) {
    if ($fac['role'] === 'speaker' && $fac['name']) $speaker_names[] = $fac['name'];
    if ($fac['role'] === 'organizer' && $fac['name']) $organizer_names[] = $fac['name'];
}
// Fallbacks
if (empty($speaker_names) && !empty($details['speaker'])) $speaker_names = explode(',', $details['speaker']);
if (empty($organizer_names) && !empty($details['organizer'])) $organizer_names = explode(',', $details['organizer']);

$speaker_str = !empty($speaker_names) ? implode(', ', $speaker_names) : 'Not specified';
$organizer_str = !empty($organizer_names) ? implode(', ', $organizer_names) : 'Not specified';
?>

<style>
    .md-page {
        background: #f8fafc;
        min-height: calc(100vh - 80px);
        padding: 2rem 5% 4rem;
        font-family: 'Inter', sans-serif;
    }
    .md-container {
        max-width: 900px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    .md-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }
    .md-back {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #64748b;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.95rem;
        transition: color 0.2s, transform 0.2s;
    }
    .md-back:hover {
        color: #3b82f6;
        transform: translateX(-4px);
    }
    .md-card {
        background: white;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
        padding: 2rem;
        transition: box-shadow 0.3s;
    }
    .md-card:hover {
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -2px rgba(0,0,0,0.04);
    }
    .md-card-header {
        font-size: 0.85rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #64748b;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 1rem;
    }
    .md-card-header svg {
        color: #94a3b8;
    }
    
    /* Top Div (Activity) */
    .md-activity-title {
        font-size: 1.6rem;
        font-weight: 800;
        color: #0f172a;
        margin: 0 0 1.5rem 0;
        line-height: 1.3;
        letter-spacing: -0.5px;
    }
    .md-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.25rem;
    }
    .md-meta-item {
        background: #f8fafc;
        padding: 1.25rem;
        border-radius: 12px;
        border: 1px solid #f1f5f9;
        transition: background 0.2s;
    }
    .md-meta-item:hover {
        background: #f1f5f9;
    }
    .md-meta-item small {
        display: block;
        color: #64748b;
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 6px;
        letter-spacing: 0.5px;
    }
    .md-meta-item strong {
        color: #1e293b;
        font-size: 1rem;
        line-height: 1.4;
        display: block;
    }

    /* Middle Div (Feedback) */
    .md-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 12px;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.3px;
    }
    .badge-complaint { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;}
    .badge-suggestion { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff;}
    
    .md-feedback-text {
        font-size: 1.05rem;
        color: #334155;
        line-height: 1.7;
        margin-top: 1rem;
        white-space: pre-wrap;
        background: #f8fafc;
        padding: 1.5rem;
        border-radius: 12px;
        border-left: 4px solid #cbd5e1;
    }
    .md-feedback-text.complaint-border { border-left-color: #fca5a5; }
    .md-feedback-text.suggestion-border { border-left-color: #d8b4fe; }

    /* Bottom Div (Actions) */
    .md-form-group {
        margin-bottom: 1.5rem;
    }
    .md-form-group label {
        display: block;
        font-size: 0.85rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 8px;
    }
    .md-input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        font-family: inherit;
        font-size: 0.95rem;
        color: #1e293b;
        background: #fff;
        transition: border-color 0.2s, box-shadow 0.2s;
        box-sizing: border-box;
    }
    .md-input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
    }
    textarea.md-input {
        resize: vertical;
        min-height: 100px;
    }
    .md-form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
    }
    .md-btn-submit {
        background: #3b82f6;
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.25);
    }
    .md-btn-submit:hover {
        background: #2563eb;
        transform: translateY(-1px);
        box-shadow: 0 6px 8px -1px rgba(59, 130, 246, 0.3);
    }
    .md-btn-submit:active {
        transform: translateY(0);
    }
    
    .md-alert {
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
        padding: 1rem 1.25rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        animation: slideDown 0.3s ease-out;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<main class="md-page">
    <div class="md-container">
        
        <div class="md-topbar">
            <a href="?action=evaluationmonitoring" class="md-back">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Monitoring
            </a>
            <div style="display: flex; align-items: center; gap: 8px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 10px; padding: 6px 14px;">
                <div style="display: flex; align-items: center; gap: 5px;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.5" stroke-linecap="round"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>
                    <span style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #94a3b8;">Case No.</span>
                </div>
                <span style="font-family: 'Courier New', monospace; font-weight: 900; color: #334155; font-size: 0.95rem; letter-spacing: 1px;"><?= htmlspecialchars($details['feedback_id']) ?></span>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="md-alert">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Monitoring details have been successfully updated.
            </div>
        <?php endif; ?>

        <!-- Top Div: Activity Details -->
        <div class="md-card">
            <div class="md-card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Activity Information
            </div>
            <h2 class="md-activity-title"><?= htmlspecialchars($details['title']) ?></h2>
            <div class="md-meta-grid">
                <div class="md-meta-item">
                    <small>Office of Origin</small>
                    <strong><?= htmlspecialchars($details['office_name'] ?? 'Not specified') ?></strong>
                </div>
                <div class="md-meta-item">
                    <small>Date & Venue</small>
                    <strong><?= $details['eventdate'] ? date('M d, Y', strtotime($details['eventdate'])) : 'N/A' ?> • <?= htmlspecialchars($details['eventvenue'] ?: 'N/A') ?></strong>
                </div>
                <?php if (!empty($speaker_names)): ?>
                <div class="md-meta-item">
                    <small>Speaker/s</small>
                    <strong><?= htmlspecialchars($speaker_str) ?></strong>
                </div>
                <?php endif; ?>
                <?php if (!empty($organizer_names)): ?>
                <div class="md-meta-item">
                    <small>Organizer/s</small>
                    <strong><?= htmlspecialchars($organizer_str) ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Middle Div: Feedback -->
        <div class="md-card">
            <div class="md-card-header" style="justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 1 1-7.6-11.7 8.38 8.38 0 0 1 3.8.9L21 3.5l-1 4.5 4.5-1z"/></svg>
                    Reported Feedback
                </div>
                <span class="md-badge <?= $details['tag'] === 'Complaint' ? 'badge-complaint' : 'badge-suggestion' ?>">
                    <?= htmlspecialchars($details['tag']) ?>
                </span>
            </div>
            
            <div class="md-feedback-text <?= $details['tag'] === 'Complaint' ? 'complaint-border' : 'suggestion-border' ?>">
                <?= nl2br(htmlspecialchars($details['complaints'] ?: $details['suggestions_for_improvement'] ?: 'No feedback text.')) ?>
            </div>
        </div>

        <!-- Bottom Div: Action Form -->
        <div class="md-card">
            <div class="md-card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                Resolution & Actions
            </div>
            
            <form method="POST" action="">
                <div class="md-form-group">
                    <label>Actions Taken / Interventions</label>
                    <textarea name="actions_taken" class="md-input" rows="4" placeholder="Describe what steps were taken to resolve or implement this..."><?= htmlspecialchars($details['actions_taken'] ?? '') ?></textarea>
                </div>
                
                <div class="md-form-row">
                    <div class="md-form-group">
                        <label>Date Implemented</label>
                        <input type="date" name="date_implemented" class="md-input" value="<?= $details['date_implemented'] ? date('Y-m-d', strtotime($details['date_implemented'])) : '' ?>">
                    </div>
                    <div class="md-form-group">
                        <label>Action Status</label>
                        <select name="status" class="md-input">
                            <option value="Pending" <?= $details['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="In Progress" <?= $details['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Implemented" <?= $details['status'] === 'Implemented' ? 'selected' : '' ?>>Implemented</option>
                            <option value="Solved" <?= $details['status'] === 'Solved' ? 'selected' : '' ?>>Solved</option>
                            <option value="Improved" <?= $details['status'] === 'Improved' ? 'selected' : '' ?>>Improved</option>
                        </select>
                    </div>
                </div>

                <div class="md-form-row">
                    <div class="md-form-group">
                        <label>Overall Case Status</label>
                        <select name="case_status" class="md-input">
                            <option value="Unresolved" <?= $details['case_status'] === 'Unresolved' ? 'selected' : '' ?>>Unresolved</option>
                            <option value="Resolved" <?= $details['case_status'] === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                    <button type="submit" class="md-btn-submit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Details
                    </button>
                </div>
            </form>
        </div>

    </div>
</main>
