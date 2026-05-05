<?php
require_once __DIR__ . '/../../config/database.php';
$db = (new Database())->getConnection();

$activity_id = $_GET['id'] ?? null;

if (!$activity_id) {
    header("Location: feed.php?action=activity");
    exit;
}

// Fetch Activity Details
$query = "SELECT a.*, o.name as office_name, o.acronym as office_acronym 
          FROM activities a 
          LEFT JOIN divisions_offices o ON a.requesting_office_id = o.office_id 
          WHERE a.activity_id = :id";
$stmt = $db->prepare($query);
$stmt->execute([':id' => $activity_id]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    header("Location: feed.php?action=activity");
    exit;
}

// Fetch SDGs
$sdg_query = "SELECT s.title FROM activity_sdgs asg JOIN SDGs s ON asg.sdg_id = s.sdg_id WHERE asg.activity_id = :id";
$sdg_stmt = $db->prepare($sdg_query);
$sdg_stmt->execute([':id' => $activity_id]);
$sdgs = $sdg_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Target Groups
$tg_query = "SELECT target_group FROM activity_target_groups WHERE activity_id = :id";
$tg_stmt = $db->prepare($tg_query);
$tg_stmt->execute([':id' => $activity_id]);
$target_groups = $tg_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Evaluation and Statistics if available
$eval_query = "SELECT e.*, s.overall_average FROM activity_evaluation e 
               LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id 
               WHERE e.activity_id = :id";
$eval_stmt = $db->prepare($eval_query);
$eval_stmt->execute([':id' => $activity_id]);
$evaluation = $eval_stmt->fetch(PDO::FETCH_ASSOC);
?>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem;">
    <div class="container" style="max-width: 1000px; margin: 0 auto; padding: 0 20px;">
        <!-- Header & Navigation -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <a href="feed.php?action=activity" style="display: flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: color 0.2s;" onmouseover="this.style.color='var(--accent-blue)'" onmouseout="this.style.color='var(--text-secondary)'">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Activities
            </a>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-secondary" onclick="editActivity(<?= $activity_id ?>, '../views/feed.php?action=view_activity&id=<?= $activity_id ?>')" style="font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                </button>
                <button class="btn btn-primary" style="font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download PDF
                </button>
            </div>
        </div>

        <?php require_once __DIR__ . '/../component/activity_modal.php'; ?>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
            <!-- Main Content -->
            <div style="display: flex; flex-direction: column; gap: 2rem;">
                <div style="background: white; padding: 2.5rem; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                        <div>
                            <?php
                                $statusStyle = '';
                                switch($activity['eventstatus']) {
                                    case 'Completed': $statusStyle = 'background: #dcfce7; color: #166534;'; break;
                                    case 'Ongoing': $statusStyle = 'background: #dbeafe; color: #1e40af;'; break;
                                    default: $statusStyle = 'background: #fef9c3; color: #854d0e;';
                                }
                            ?>
                            <span style="<?= $statusStyle ?> padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;"><?= $activity['eventstatus'] ?></span>
                            <h1 style="font-size: 2.2rem; margin: 1rem 0 0.5rem 0; color: var(--text-primary); line-height: 1.2;"><?= htmlspecialchars($activity['title']) ?></h1>
                        </div>
                    </div>

                    <div style="display: flex; gap: 2rem; margin-bottom: 2.5rem; padding-bottom: 2rem; border-bottom: 1px solid var(--border-color);">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="background: #f1f5f9; padding: 10px; border-radius: 10px; color: var(--accent-blue);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Date</div>
                                <div style="font-weight: 600;"><?= date('F d, Y', strtotime($activity['eventdate'])) ?></div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="background: #f1f5f9; padding: 10px; border-radius: 10px; color: var(--accent-blue);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Venue</div>
                                <div style="font-weight: 600;"><?= $activity['eventvenue'] ?: 'Not Specified' ?></div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 2.5rem;">
                        <h3 style="font-size: 1.1rem; margin-bottom: 1rem; color: var(--text-primary);">Description</h3>
                        <p style="color: #475569; line-height: 1.7; font-size: 1.05rem;"><?= nl2br(htmlspecialchars($activity['description'])) ?></p>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                        <div>
                            <h3 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                Facilitators
                            </h3>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <?php if ($activity['speaker']): ?>
                                    <div style="display: flex; align-items: center; gap: 12px; background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #f1f5f9;">
                                        <div style="width: 32px; height: 32px; background: #fee2e2; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: #ef4444;">S</div>
                                        <div>
                                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase;">Speaker</div>
                                            <div style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($activity['speaker']) ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($activity['organizer']): ?>
                                    <div style="display: flex; align-items: center; gap: 12px; background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #f1f5f9;">
                                        <div style="width: 32px; height: 32px; background: #e0f2fe; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: #0ea5e9;">O</div>
                                        <div>
                                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase;">Organizer</div>
                                            <div style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($activity['organizer']) ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <h3 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                SDGs
                            </h3>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php if (empty($sdgs)): ?>
                                    <span style="color: var(--text-secondary); font-size: 0.9rem;">No SDGs linked</span>
                                <?php else: ?>
                                    <?php foreach($sdgs as $sdg): ?>
                                        <span style="font-size: 0.8rem; background: #eff6ff; color: #1e40af; padding: 6px 12px; border-radius: 6px; border: 1px solid #dbeafe; font-weight: 500;"><?= htmlspecialchars($sdg) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Evaluation Preview -->
                <?php if ($evaluation): ?>
                    <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 2.5rem; border-radius: 16px; color: white; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                            <h2 style="font-size: 1.5rem; margin: 0; display: flex; align-items: center; gap: 12px;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                Evaluation Summary
                            </h2>
                            <div style="background: rgba(255,255,255,0.1); padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; border: 1px solid rgba(255,255,255,0.1);">
                                Status: <span style="color: #10b981; font-weight: 700;"><?= $evaluation['evaluation_status'] ?></span>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem;">
                            <div style="background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);">
                                <div style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Overall Rating</div>
                                <div style="font-size: 2.5rem; font-weight: 800; color: #fbbf24;"><?= number_format($evaluation['overall_average'] ?: 0, 1) ?><span style="font-size: 1rem; color: #94a3b8; font-weight: 400;"> / 5.0</span></div>
                            </div>
                            <div style="background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);">
                                <div style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Respondents</div>
                                <div style="font-size: 2.5rem; font-weight: 800;"><?= $evaluation['number_of_respondents'] ?: 0 ?></div>
                            </div>
                            <div style="background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);">
                                <div style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Response Rate</div>
                                <div style="font-size: 2.5rem; font-weight: 800;"><?= number_format($evaluation['response_rate'] ?: 0, 1) ?>%</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar Info -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <div style="background: white; padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border-color);">
                    <h3 style="font-size: 0.9rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1.2rem;">Activity Info</h3>
                    
                    <div style="display: flex; flex-direction: column; gap: 1.2rem;">
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px;">Requesting Office</div>
                            <div style="font-weight: 600; color: var(--accent-blue);">
                                <?= htmlspecialchars($activity['office_name']) ?> (<?= htmlspecialchars($activity['office_acronym']) ?>)
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px;">Est. Participants</div>
                            <div style="font-weight: 600;"><?= $activity['number_of_participants'] ?: 'Not specified' ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 8px;">Target Groups</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                <?php if (empty($target_groups)): ?>
                                    <span style="color: var(--text-secondary); font-size: 0.85rem;">None specified</span>
                                <?php else: ?>
                                    <?php foreach($target_groups as $tg): ?>
                                        <span style="font-size: 0.75rem; background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 6px; border: 1px solid #e2e8f0;"><?= htmlspecialchars($tg) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="background: #f8fafc; padding: 1.5rem; border-radius: 16px; border: 1px solid var(--border-color);">
                    <h3 style="font-size: 0.9rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1rem;">System Details</h3>
                    <div style="font-size: 0.8rem; display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary);">Created</span>
                            <span style="font-weight: 500;"><?= date('M d, Y', strtotime($activity['created_at'])) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary);">Last Updated</span>
                            <span style="font-weight: 500;"><?= date('M d, Y', strtotime($activity['updated_at'])) ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: var(--text-secondary);">Activity ID</span>
                            <span style="font-weight: 500;">#<?= $activity['activity_id'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
