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
$sdg_query = "SELECT s.sdg_id, s.title FROM activity_sdgs asg JOIN SDGs s ON asg.sdg_id = s.sdg_id WHERE asg.activity_id = :id";
$sdg_stmt = $db->prepare($sdg_query);
$sdg_stmt->execute([':id' => $activity_id]);
$sdgs = $sdg_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Target Groups
$tg_query = "SELECT target_group FROM activity_target_groups WHERE activity_id = :id";
$tg_stmt = $db->prepare($tg_query);
$tg_stmt->execute([':id' => $activity_id]);
$target_groups = $tg_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Evaluation and Statistics if available
$eval_query = "SELECT e.*, s.* FROM activity_evaluation e 
               LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id 
               WHERE e.activity_id = :id";
$eval_stmt = $db->prepare($eval_query);
$eval_stmt->execute([':id' => $activity_id]);
$evaluation = $eval_stmt->fetch(PDO::FETCH_ASSOC);

$other_stats = null;
$speaker_ratings = [];
$organizer_ratings = [];

if ($evaluation) {
    $eval_id = $evaluation['evaluation_id'];
    
    // Other stats
    $other_stmt = $db->prepare("SELECT * FROM activity_statistics_others WHERE evaluation_id = :id");
    $other_stmt->execute([':id' => $eval_id]);
    $other_stats = $other_stmt->fetch(PDO::FETCH_ASSOC);

    // Speaker ratings
    $speaker_stmt = $db->prepare("SELECT * FROM activity_speaker_rating WHERE evaluation_id = :id");
    $speaker_stmt->execute([':id' => $eval_id]);
    $speaker_ratings = $speaker_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organizer ratings (Table pending)
    $organizer_ratings = [];
}
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
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                Target Participants
                            </h3>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php 
                                $groups = $activity['target_participants'] ? explode(', ', $activity['target_participants']) : [];
                                if (empty($groups)): 
                                ?>
                                    <span style="color: var(--text-secondary); font-size: 0.9rem;">No target groups specified</span>
                                <?php else: ?>
                                    <?php foreach($groups as $group): ?>
                                        <span style="font-size: 0.8rem; background: #f0fdf4; color: #166534; padding: 6px 12px; border-radius: 6px; border: 1px solid #bbf7d0; font-weight: 600;">
                                            <?= htmlspecialchars(trim($group)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 2.5rem; padding-top: 2rem; border-top: 1px solid var(--border-color);">
                        <h3 style="font-size: 1rem; margin-bottom: 1.5rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            Sustainable Development Goals (SDGs)
                        </h3>
                        <div style="display: flex; flex-wrap: wrap; gap: 0;">
                            <?php if (empty($sdgs)): ?>
                                <span style="color: var(--text-secondary); font-size: 0.9rem;">No SDGs linked</span>
                            <?php else: ?>
                                <?php foreach($sdgs as $sdg): ?>
                                    <div style="position: relative; group;">
                                        <img src="../assets/img/sdgs/SDG<?= $sdg['sdg_id'] ?>.png" 
                                             alt="<?= htmlspecialchars($sdg['title']) ?>" 
                                             title="<?= htmlspecialchars($sdg['title']) ?>"
                                             style="width: 120px; height: 120px; object-fit: cover; box-shadow: 0 2px 4px rgba(0,0,0,0.1); cursor: help; transition: transform 0.2s; display: block;"
                                             onmouseover="this.style.transform='scale(1.05)'; this.style.zIndex='10'; this.style.position='relative';"
                                             onmouseout="this.style.transform='scale(1)'; this.style.zIndex='1';">
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border-color);">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            Activity Links & Resources
                        </h3>
                        <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                            <?php if ($evaluation && $evaluation['ame_form_link']): 
                                $form_url = $evaluation['ame_form_link'];
                                $edit_url = str_replace('/viewform', '/edit', $form_url);
                            ?>
                                <div style="position: relative; display: inline-block;">
                                    <button onclick="toggleAMEDropdown(event)" style="display: flex; align-items: center; gap: 10px; background: #eff6ff; color: #1e40af; padding: 12px 20px; border-radius: 10px; border: 1px solid #dbeafe; text-decoration: none; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        AME Evaluation Form
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                    </button>
                                    <div id="ameDropdown" style="display: none; position: absolute; top: 100%; left: 0; margin-top: 8px; background: white; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 100; min-width: 200px; overflow: hidden;">
                                        <a href="<?= $edit_url ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); text-decoration: none; font-size: 0.85rem; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            Open as Editor
                                        </a>
                                        <a href="<?= $form_url ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); text-decoration: none; font-size: 0.85rem; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View Respondent Form
                                        </a>
                                        <button onclick="copyToClipboard('<?= $form_url ?>')" style="width: 100%; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); background: white; font-size: 0.85rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                            Copy Respondent Link
                                        </button>
                                        <div style="height: 1px; background: var(--border-color); margin: 4px 0;"></div>
                                        <button onclick="deleteAMEForm(<?= $activity_id ?>)" style="width: 100%; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: #ef4444; background: white; font-size: 0.85rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                            Delete Form
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($activity['request_email_link']): ?>
                                <a href="<?= htmlspecialchars($activity['request_email_link']) ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; background: #f0fdf4; color: #166534; padding: 12px 20px; border-radius: 10px; border: 1px solid #bbf7d0; text-decoration: none; font-size: 0.9rem; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.background='#bbf7d0'" onmouseout="this.style.background='#f0fdf4'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    Request Email Link
                                </a>
                            <?php endif; ?>

                            <?php if ($activity['email_link']): ?>
                                <a href="<?= htmlspecialchars($activity['email_link']) ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; background: #fff7ed; color: #9a3412; padding: 12px 20px; border-radius: 10px; border: 1px solid #fed7aa; text-decoration: none; font-size: 0.9rem; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.background='#fed7aa'" onmouseout="this.style.background='#fff7ed'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                    Activity Documentation
                                </a>
                            <?php endif; ?>

                            <?php if (!$activity['request_email_link'] && !$activity['email_link'] && (!$evaluation || !$evaluation['ame_form_link'])): ?>
                                <div style="display: flex; flex-direction: column; gap: 10px; width: 100%;">
                                    <span style="color: var(--text-secondary); font-size: 0.9rem;">No external links available for this activity.</span>
                                    <?php if (!$evaluation || !$evaluation['ame_form_link']): ?>
                                        <button onclick="generateAMEForm(this)" style="width: fit-content; display: flex; align-items: center; gap: 10px; background: white; color: var(--accent-blue); padding: 10px 18px; border-radius: 10px; border: 1px dashed var(--accent-blue); text-decoration: none; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='var(--accent-blue)'; this.style.color='white'" onmouseout="this.style.background='white'; this.style.color='var(--accent-blue)'">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                                            Generate AME Evaluation Form
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php elseif (!$evaluation || !$evaluation['ame_form_link']): ?>
                                <button onclick="generateAMEForm(this)" style="display: flex; align-items: center; gap: 10px; background: white; color: var(--accent-blue); padding: 12px 20px; border-radius: 10px; border: 1px dashed var(--accent-blue); text-decoration: none; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='var(--accent-blue)'; this.style.color='white'" onmouseout="this.style.background='white'; this.style.color='var(--accent-blue)'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                                    Generate Form
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <script>
                function toggleAMEDropdown(e) {
                    e.stopPropagation();
                    const dropdown = document.getElementById('ameDropdown');
                    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
                }

                function copyToClipboard(text) {
                    navigator.clipboard.writeText(text).then(() => {
                        alert('Link copied to clipboard!');
                    });
                    document.getElementById('ameDropdown').style.display = 'none';
                }

                function deleteAMEForm(id) {
                    if (confirm('Are you sure you want to delete this Google Form? This will remove it from Google Drive and reset the link in the database.')) {
                        window.location.href = '../api/delete_ame_form.php?id=' + id;
                    }
                }

                document.addEventListener('click', function() {
                    const dropdown = document.getElementById('ameDropdown');
                    if (dropdown) dropdown.style.display = 'none';
                });

                function generateAMEForm(btn) {
                    if (confirm('This will create a new Google Form and Google Sheet (if not exists) for this activity. Proceed?')) {
                        const originalContent = btn.innerHTML;
                        btn.disabled = true;
                        btn.style.opacity = '0.7';
                        btn.style.cursor = 'not-allowed';
                        btn.innerHTML = `
                            <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="animation: spin 1s linear infinite;">
                                <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                            </svg>
                            Generating...
                        `;
                        window.location.href = '../api/generate_ame_form.php?id=<?= $activity_id ?>';
                    }
                }
                </script>

                <style>
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
                </style>

                <div style="margin: 1rem 0; display: flex; align-items: center; gap: 15px;">
                    <h2 style="font-size: 1.2rem; color: var(--text-secondary); white-space: nowrap; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Evaluation & Statistics</h2>
                    <div style="height: 1px; background: var(--border-color); flex: 1;"></div>
                </div>

                <?php if ($evaluation): ?>
                    <!-- Evaluation Administrative Details -->
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2rem;">
                        <div style="background: white; padding: 1.2rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Status</div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span style="width: 8px; height: 8px; border-radius: 50%; background: <?= $evaluation['evaluation_status'] === 'Completed' ? '#10b981' : ($evaluation['evaluation_status'] === 'Overdue' ? '#ef4444' : '#f59e0b') ?>;"></span>
                                <span style="font-weight: 700; color: var(--text-primary);"><?= htmlspecialchars($evaluation['evaluation_status']) ?></span>
                            </div>
                        </div>
                        <div style="background: white; padding: 1.2rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Released</div>
                            <div style="font-weight: 700; color: var(--text-primary);"><?= $evaluation['date_released'] ? date('M d, Y', strtotime($evaluation['date_released'])) : 'TBA' ?></div>
                        </div>
                        <div style="background: white; padding: 1.2rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Deadline</div>
                            <div style="font-weight: 700; color: <?= (strtotime($evaluation['deadline']) < time() && $evaluation['evaluation_status'] !== 'Completed') ? '#ef4444' : 'var(--text-primary)' ?>;">
                                <?= $evaluation['deadline'] ? date('M d, Y', strtotime($evaluation['deadline'])) : 'TBA' ?>
                            </div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                        <div style="background: #fff; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color);">
                            <h4 style="margin: 0 0 1rem 0; font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 0 0 6 0v-4"/><path d="M10 5h6a3 3 0 0 1 3 3v4a3 3 0 0 1-3 3h-6a3 3 0 0 1-3-3V8a3 3 0 0 1 3-3z"/></svg>
                                Complaints
                            </h4>
                            <div style="font-size: 0.9rem; color: #64748b; line-height: 1.6; min-height: 50px;">
                                <?= $evaluation['complaints'] ? nl2br(htmlspecialchars($evaluation['complaints'])) : '<i>No complaints reported.</i>' ?>
                            </div>
                        </div>
                        <div style="background: #fff; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color);">
                            <h4 style="margin: 0 0 1rem 0; font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                Suggestions for Improvement
                            </h4>
                            <div style="font-size: 0.9rem; color: #64748b; line-height: 1.6; min-height: 50px;">
                                <?= $evaluation['suggestions_for_improvement'] ? nl2br(htmlspecialchars($evaluation['suggestions_for_improvement'])) : '<i>No suggestions provided.</i>' ?>
                            </div>
                        </div>
                    </div>

                    <!-- Evaluation Statistics Card -->
                    <div style="background: #0f172a; padding: 2.5rem; border-radius: 20px; color: white; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.05);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem;">
                            <div>
                                <h2 style="font-size: 1.6rem; margin: 0; display: flex; align-items: center; gap: 12px; color: #f8fafc; font-weight: 800; letter-spacing: -0.5px;">
                                    <div style="background: #fbbf24; padding: 8px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2.5"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                                    </div>
                                    Performance Analytics
                                </h2>
                                <div style="margin-top: 8px; display: flex; align-items: center; gap: 8px;">
                                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 10px #10b981;"></span>
                                    <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">Live Evaluation Stats</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 12px;">
                                <?php if ($evaluation['ame_form_link']): ?>
                                    <a href="<?= htmlspecialchars($evaluation['ame_form_link']) ?>" target="_blank" style="background: rgba(255,255,255,0.05); color: #f8fafc; border: 1px solid rgba(255,255,255,0.1); padding: 10px 18px; border-radius: 10px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.2s;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 22 3 22 10"/><line x1="14" y1="10" x2="22" y2="2"/></svg>
                                        Form
                                    </a>
                                <?php endif; ?>
                                <div style="background: rgba(255,255,255,0.03); padding: 10px 18px; border-radius: 10px; border: 1px solid <?= ($evaluation['published_options'] === 'Open') ? '#10b981' : 'rgba(255,255,255,0.1)' ?>; display: flex; flex-direction: column; justify-content: center;">
                                    <div style="font-size: 0.6rem; color: #64748b; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px;">Visibility</div>
                                    <div style="font-size: 0.85rem; font-weight: 700; color: <?= ($evaluation['published_options'] === 'Open') ? '#10b981' : '#cbd5e1' ?>;"><?= $evaluation['published_options'] ?: 'Closed' ?></div>
                                </div>
                                <div style="background: rgba(255,255,255,0.03); padding: 10px 18px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column; justify-content: center;">
                                    <div style="font-size: 0.6rem; color: #64748b; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px;">Status</div>
                                    <div style="font-size: 0.85rem; font-weight: 700; color: #10b981;"><?= $evaluation['evaluation_status'] ?></div>
                                </div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2.5rem;">
                            <div style="background: rgba(255,255,255,0.03); padding: 2rem 1.5rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); position: relative; overflow: hidden;">
                                <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 12px;">Overall Rating</div>
                                <div style="display: flex; align-items: baseline; gap: 4px;">
                                    <span style="font-size: 3rem; font-weight: 900; color: #fbbf24; line-height: 1;"><?= number_format($evaluation['overall_average'] ?: 0, 1) ?></span>
                                    <span style="font-size: 1.2rem; color: #475569; font-weight: 600;">/ 5.0</span>
                                </div>
                                <div style="position: absolute; right: -10px; bottom: -10px; opacity: 0.05;">
                                    <svg width="80" height="80" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                </div>
                            </div>
                            <div style="background: rgba(255,255,255,0.03); padding: 2rem 1.5rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 12px;">Respondents</div>
                                <div style="font-size: 3rem; font-weight: 900; color: #f8fafc; line-height: 1;"><?= $evaluation['number_of_respondents'] ?: 0 ?></div>
                                <div style="font-size: 0.85rem; color: #475569; margin-top: 5px; font-weight: 600;">Evaluations Collected</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.03); padding: 2rem 1.5rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                <div style="font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 12px;">Response Rate</div>
                                <div style="font-size: 3rem; font-weight: 900; color: #f8fafc; line-height: 1;"><?= number_format($evaluation['response_rate'] ?: 0, 1) ?><span style="font-size: 1.5rem; margin-left: 2px;">%</span></div>
                                <div style="height: 4px; background: rgba(255,255,255,0.05); border-radius: 2px; margin-top: 15px; overflow: hidden;">
                                    <div style="width: <?= $evaluation['response_rate'] ?: 0 ?>%; height: 100%; background: #fbbf24;"></div>
                                </div>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                            <?php
                            $metrics = [
                                ['label' => 'Overall Service Rating', 'val' => 'osr', 'wa' => 'osr_wa', 'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
                                ['label' => 'Presenter/Organizer', 'val' => 'peor', 'wa' => 'peor_wa', 'icon' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'],
                                ['label' => 'Program & Methodology', 'val' => 'pam', 'wa' => 'pam_wa', 'icon' => 'M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'],
                                ['label' => 'Management & Logistics', 'val' => 'pamlss', 'wa' => 'pamlss_wa', 'icon' => 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z'],
                                ['label' => 'Overall Experience', 'val' => 'oe', 'wa' => 'oe_wa', 'icon' => 'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z']
                            ];
                            foreach($metrics as $m):
                            ?>
                                <div style="background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.04)'; this.style.borderColor='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.02)'; this.style.borderColor='rgba(255,255,255,0.05)'">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(255,255,255,0.03); display: flex; align-items: center; justify-content: center; color: #94a3b8;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $m['icon'] ?>"/></svg>
                                        </div>
                                        <div>
                                            <div style="font-size: 0.9rem; color: #f8fafc; font-weight: 600;"><?= $m['label'] ?></div>
                                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">Weighted Avg: <span style="color: #94a3b8; font-weight: 700;"><?= number_format($evaluation[$m['wa']] ?: 0, 2) ?></span></div>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-size: 1.4rem; font-weight: 800; color: #fbbf24;"><?= number_format($evaluation[$m['val']] ?: 0, 2) ?></div>
                                        <div style="font-size: 0.65rem; color: #475569; text-transform: uppercase; font-weight: 800; letter-spacing: 1px;">Category Score</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Demographics Section -->
                        <?php if ($other_stats): ?>
                            <div style="margin-top: 3rem;">
                                <h3 style="font-size: 1rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                    Demographic Distribution
                                </h3>
                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem;">
                                    <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                                        <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 800; margin-bottom: 8px;">Gender</div>
                                        <div style="font-size: 0.95rem; color: #f8fafc; font-weight: 600;"><?= htmlspecialchars($other_stats['gender_distribution'] ?: 'Not recorded') ?></div>
                                    </div>
                                    <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                                        <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 800; margin-bottom: 8px;">Age Group</div>
                                        <div style="font-size: 0.95rem; color: #f8fafc; font-weight: 600;"><?= htmlspecialchars($other_stats['age_distribution'] ?: 'Not recorded') ?></div>
                                    </div>
                                    <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                                        <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 800; margin-bottom: 8px;">Unit / Department</div>
                                        <div style="font-size: 0.95rem; color: #f8fafc; font-weight: 600;"><?= htmlspecialchars($other_stats['unit_distribution'] ?: 'Not recorded') ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Facilitator Ratings Section -->
                        <?php if (!empty($speaker_ratings)): ?>
                            <div style="margin-top: 3rem;">
                                <h3 style="font-size: 1rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                                    Facilitator Excellence Ratings
                                </h3>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                    <?php foreach($speaker_ratings as $s): ?>
                                        <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 1rem;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 36px; height: 36px; border-radius: 50%; background: #fbbf24; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem;">SP</div>
                                                    <div>
                                                        <div style="font-size: 0.95rem; color: #f8fafc; font-weight: 700;"><?= htmlspecialchars($s['name']) ?></div>
                                                        <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Speaker</div>
                                                    </div>
                                                </div>
                                                <?php 
                                                $avg = ($s['eandd'] + $s['mot'] + $s['iae'] + $s['gi']) / 4;
                                                ?>
                                                <div style="text-align: right;">
                                                    <div style="font-size: 1.4rem; font-weight: 800; color: #fbbf24;"><?= number_format($avg, 2) ?></div>
                                                    <div style="font-size: 0.6rem; color: #475569; font-weight: 800; text-transform: uppercase;">Average</div>
                                                </div>
                                            </div>
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                                <div style="background: rgba(255,255,255,0.02); padding: 10px; border-radius: 8px;">
                                                    <div style="font-size: 0.65rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Expertise</div>
                                                    <div style="font-size: 0.9rem; color: #cbd5e1; font-weight: 700;"><?= number_format($s['eandd'], 2) ?></div>
                                                </div>
                                                <div style="background: rgba(255,255,255,0.02); padding: 10px; border-radius: 8px;">
                                                    <div style="font-size: 0.65rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Mastery</div>
                                                    <div style="font-size: 0.9rem; color: #cbd5e1; font-weight: 700;"><?= number_format($s['mot'], 2) ?></div>
                                                </div>
                                                <div style="background: rgba(255,255,255,0.02); padding: 10px; border-radius: 8px;">
                                                    <div style="font-size: 0.65rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Engagement</div>
                                                    <div style="font-size: 0.9rem; color: #cbd5e1; font-weight: 700;"><?= number_format($s['iae'], 2) ?></div>
                                                </div>
                                                <div style="background: rgba(255,255,255,0.02); padding: 10px; border-radius: 8px;">
                                                    <div style="font-size: 0.65rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Impact</div>
                                                    <div style="font-size: 0.9rem; color: #cbd5e1; font-weight: 700;"><?= number_format($s['gi'], 2) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="background: white; padding: 2.5rem; border-radius: 16px; border: 1px dashed var(--border-color); text-align: center; color: var(--text-secondary);">
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color: #cbd5e1;">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            <div style="font-weight: 600; font-size: 1.1rem;">No Evaluation Data</div>
                            <p style="font-size: 0.9rem; margin: 0; max-width: 300px;">Statistics will be available once the event evaluation has been processed.</p>
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
                                <?php 
                                $groups = $activity['target_participants'] ? explode(', ', $activity['target_participants']) : [];
                                if (empty($groups)): 
                                ?>
                                    <span style="color: var(--text-secondary); font-size: 0.85rem;">None specified</span>
                                <?php else: ?>
                                    <?php foreach($groups as $tg): ?>
                                        <span style="font-size: 0.75rem; background: #f0fdf4; color: #166534; padding: 4px 10px; border-radius: 6px; border: 1px solid #bbf7d0; font-weight: 600;">
                                            <?= htmlspecialchars(trim($tg)) ?>
                                        </span>
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
