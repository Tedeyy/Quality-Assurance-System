<?php
require_once __DIR__ . '/../../../config/database.php';
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
$sdgs = [];
try {
    $sdg_query = "SELECT s.sdg_id, s.title FROM activity_sdgs asg JOIN sdgs s ON asg.sdg_id = s.sdg_id WHERE asg.activity_id = :id";
    $sdg_stmt = $db->prepare($sdg_query);
    $sdg_stmt->execute([':id' => $activity_id]);
    $sdgs = $sdg_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("activitypane SDG query failed: " . $e->getMessage());
}

$sdg_descriptions = [
    1 => 'End poverty in all its forms everywhere.',
    2 => 'End hunger, achieve food security and improved nutrition, and promote sustainable agriculture.',
    3 => 'Ensure healthy lives and promote well-being for all at all ages.',
    4 => 'Ensure inclusive and equitable quality education and promote lifelong learning opportunities for all.',
    5 => 'Achieve gender equality and empower all women and girls.',
    6 => 'Ensure availability and sustainable management of water and sanitation for all.',
    7 => 'Ensure access to affordable, reliable, sustainable, and modern energy for all.',
    8 => 'Promote sustained, inclusive, and sustainable economic growth, full and productive employment, and decent work for all.',
    9 => 'Build resilient infrastructure, promote inclusive and sustainable industrialization, and foster innovation.',
    10 => 'Reduce inequality within and among countries.',
    11 => 'Make cities and human settlements inclusive, safe, resilient, and sustainable.',
    12 => 'Ensure sustainable consumption and production patterns.',
    13 => 'Take urgent action to combat climate change and its impacts.',
    14 => 'Conserve and sustainably use the oceans, seas, and marine resources for sustainable development.',
    15 => 'Protect, restore, and promote sustainable use of terrestrial ecosystems, sustainably manage forests, combat desertification, halt and reverse land degradation, and halt biodiversity loss.',
    16 => 'Promote peaceful and inclusive societies, provide access to justice for all, and build effective, accountable, and inclusive institutions.',
    17 => 'Strengthen the means of implementation and revitalize the global partnership for sustainable development.'
];

// Fetch Target Groups
$tg_query = "SELECT target_group FROM activity_target_groups WHERE activity_id = :id";
$tg_stmt = $db->prepare($tg_query);
$tg_stmt->execute([':id' => $activity_id]);
$target_groups = $tg_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch Facilitators from the junction table
$fac_query = 
    "SELECT af.role, af.person_id,
            COALESCE(sp.name, og.name) AS name
     FROM   activity_facilitators af
     LEFT JOIN speakers   sp ON af.role = 'speaker'   AND af.person_id = sp.speaker_id
     LEFT JOIN organizers og ON af.role = 'organizer' AND af.person_id = og.organizer_id
     WHERE  af.activity_id = :id
     ORDER BY af.role, af.af_id";
$fac_stmt_list = $db->prepare($fac_query);
$fac_stmt_list->execute([':id' => $activity_id]);
$facilitators_list = $fac_stmt_list->fetchAll(PDO::FETCH_ASSOC);

// Fallback: parse legacy comma strings if junction table is empty (old data)
if (empty($facilitators_list)) {
    if (!empty($activity['speaker'])) {
        foreach (explode(',', $activity['speaker']) as $n) {
            $n = trim($n);
            if ($n) $facilitators_list[] = ['role' => 'speaker', 'name' => $n];
        }
    }
    if (!empty($activity['organizer'])) {
        foreach (explode(',', $activity['organizer']) as $n) {
            $n = trim($n);
            if ($n) $facilitators_list[] = ['role' => 'organizer', 'name' => $n];
        }
    }
}

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
    $speaker_stmt = $db->prepare("SELECT r.*, s.name FROM activity_speaker_rating r JOIN speakers s ON r.speaker_id = s.speaker_id WHERE r.evaluation_id = :id");
    $speaker_stmt->execute([':id' => $eval_id]);
    $speaker_ratings = $speaker_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organizer ratings
    $organizer_stmt = $db->prepare("SELECT r.*, o.name FROM activity_organizer_rating r JOIN organizers o ON r.organizer_id = o.organizer_id WHERE r.evaluation_id = :id");
    $organizer_stmt->execute([':id' => $eval_id]);
    $organizer_ratings = $organizer_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<main class="hero" style="min-height: calc(100vh - 100px); display: block; padding-top: 2rem;">
    <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
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
                <button class="btn btn-primary" onclick="downloadPDF()" style="font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download PDF
                </button>
            </div>
        </div>

        <?php require_once __DIR__ . '/../../component/activity_modal.php'; ?>

        <!-- PDF Wrapper -->
        <div id="activity-report">
            <!-- PDF Only Header -->
            <div class="pdf-only-header" style="display: none; margin-bottom: 30px; border-bottom: 2px solid #0f172a; padding-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <img src="../assets/img/NBSC_logo.png" style="height: 60px;">
                        <img src="../assets/img/QAO_logo.png" style="height: 60px;">
                        <div>
                            <div style="font-weight: 800; font-size: 1.2rem; color: #0f172a;">Northern Bukidnon State College</div>
                            <div style="font-weight: 600; font-size: 0.9rem; color: #64748b;">Quality Assurance Office</div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-weight: 700; font-size: 0.8rem; text-transform: uppercase; color: #2563eb;">Activity Monitoring & Evaluation</div>
                        <div style="font-size: 0.7rem; color: #94a3b8;">Report Generated: <?= date('F d, Y') ?></div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 320px; gap: 2rem;">
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
                                <?php if (!empty($facilitators_list)): ?>
                                    <?php foreach ($facilitators_list as $fac):
                                        $isSpeaker  = ($fac['role'] === 'speaker');
                                        $badgeBg    = $isSpeaker ? '#fee2e2' : '#e0f2fe';
                                        $badgeColor = $isSpeaker ? '#ef4444' : '#0ea5e9';
                                        $badgeLetter = $isSpeaker ? 'S' : 'O';
                                        $roleLabel   = $isSpeaker ? 'Speaker' : 'Organizer';
                                    ?>
                                        <div style="display: flex; align-items: center; gap: 12px; background: #f8fafc; padding: 12px; border-radius: 10px; border: 1px solid #f1f5f9;">
                                            <div style="width: 32px; height: 32px; background: <?= $badgeBg ?>; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700; color: <?= $badgeColor ?>;"><?= $badgeLetter ?></div>
                                            <div>
                                                <div style="font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase;"><?= $roleLabel ?></div>
                                                <div style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($fac['name']) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary); font-size: 0.9rem;">No facilitators assigned</span>
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
                                    <button type="button"
                                        class="activity-sdg-card"
                                        data-sdg-id="<?= (int)$sdg['sdg_id'] ?>"
                                        data-sdg-title="<?= htmlspecialchars($sdg['title']) ?>"
                                        data-sdg-description="<?= htmlspecialchars($sdg_descriptions[(int)$sdg['sdg_id']] ?? 'No description available for this Sustainable Development Goal.') ?>"
                                        data-sdg-icon="../assets/img/sdgs/SDG<?= (int)$sdg['sdg_id'] ?>.png"
                                        onclick="toggleActivitySdgDescription(this)"
                                        aria-expanded="false"
                                        style="position: relative; border: 0; background: transparent; padding: 0; cursor: pointer;">
                                        <img src="../assets/img/sdgs/SDG<?= $sdg['sdg_id'] ?>.png" 
                                             alt="<?= htmlspecialchars($sdg['title']) ?>" 
                                             title="<?= htmlspecialchars($sdg['title']) ?>"
                                             style="width: 120px; height: 120px; object-fit: cover; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: transform 0.2s, outline 0.2s; display: block;"
                                             onmouseover="this.style.transform='scale(1.05)'; this.style.zIndex='10'; this.style.position='relative';"
                                             onmouseout="if (!this.closest('.activity-sdg-card').classList.contains('active')) this.style.transform='scale(1)'; this.style.zIndex='1';">
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div id="activitySdgDetailPanel" style="display: none; margin-top: 1rem; background: #f8fafc; border: 1px solid var(--border-color); border-left: 4px solid var(--accent-blue); border-radius: 10px; padding: 1.1rem; gap: 1rem; align-items: flex-start;">
                            <img id="activitySdgDetailIcon" src="" alt="" style="width: 58px; height: 58px; border-radius: 4px; object-fit: cover; flex-shrink: 0;">
                            <div>
                                <h4 id="activitySdgDetailTitle" style="margin: 0 0 0.35rem; color: var(--accent-blue); font-size: 1rem;"></h4>
                                <p id="activitySdgDetailDescription" style="margin: 0; color: var(--text-secondary); line-height: 1.6; font-size: 0.9rem;"></p>
                            </div>
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
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                            Edit Form
                                        </a>
                                        <a href="<?= $form_url ?>" target="_blank" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); text-decoration: none; font-size: 0.85rem; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View Respondent Form
                                        </a>
                                        <button onclick="copyToClipboard('<?= $form_url ?>')" style="width: 100%; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); background: white; font-size: 0.85rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                            Copy Respondent Link
                                        </button>
                                        <a href="feed.php?action=respondents&id=<?= (int)$activity_id ?>" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); text-decoration: none; font-size: 0.85rem; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                                            Respondents
                                        </a>
                                        <button onclick="syncGoogleResponses(<?= $activity_id ?>)" style="width: 100%; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: #16a34a; background: white; font-size: 0.85rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='white'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                            Sync Google Responses
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

                <!-- PDF Library -->
                <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
                <script>
                async function downloadPDF() {
                    const element = document.getElementById('activity-report');
                    const pdfHeader = element.querySelector('.pdf-only-header');
                    const interpretBtn = element.querySelector('.action-dropdown');
                    const visToggle = element.querySelector('#visibilityToggleBtn');
                    
                    // Show PDF header and hide interactive elements
                    pdfHeader.style.display = 'block';
                    if (interpretBtn) interpretBtn.style.display = 'none';
                    if (visToggle) visToggle.style.display = 'none';
                    
                    const opt = {
                        margin:       [0.5, 0.5],
                        filename:     'Activity_Report_<?= str_replace(' ', '_', $activity['title']) ?>.pdf',
                        image:        { type: 'jpeg', quality: 0.98 },
                        html2canvas:  { scale: 2, useCORS: true, letterRendering: true },
                        jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
                    };

                    try {
                        const btn = event.currentTarget;
                        const originalContent = btn.innerHTML;
                        btn.disabled = true;
                        btn.innerHTML = `<svg class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Generating...`;

                        await html2pdf().set(opt).from(element).save();
                        
                        btn.disabled = false;
                        btn.innerHTML = originalContent;
                    } catch (error) {
                        console.error('PDF Generation Error:', error);
                        alert('Failed to generate PDF. Please try again.');
                    } finally {
                        // Revert UI changes
                        pdfHeader.style.display = 'none';
                        if (interpretBtn) interpretBtn.style.display = 'block';
                        if (visToggle) visToggle.style.display = 'flex';
                    }
                }

                function toggleInterpretDropdown(e) {
                    e.stopPropagation();
                    const dropdown = document.getElementById('interpretDropdown');
                    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
                }

                document.addEventListener('click', function() {
                    const dropdown = document.getElementById('interpretDropdown');
                    if (dropdown) dropdown.style.display = 'none';
                });

                async function runAIInterpretation() {
                    if (!confirm('Run AI Analysis? This will overwrite current complaints and suggestions.')) return;
                    
                    const btn = event.currentTarget;
                    const originalText = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = `<svg class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="animation: spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg> Analyzing...`;

                    try {
                        const res = await fetch(`../api/analyze_feedback.php?id=<?= $activity_id ?>`);
                        const data = await res.json();
                        
                        if (data.success) {
                            document.getElementById('complaints-display').innerHTML = data.complaints.replace(/\n/g, '<br>');
                            document.getElementById('suggestions-display').innerHTML = data.suggestions.replace(/\n/g, '<br>');
                            document.getElementById('manualComplaints').value = data.complaints;
                            document.getElementById('manualSuggestions').value = data.suggestions;
                            alert('AI Analysis Complete!');
                        } else {
                            alert('AI Analysis Failed: ' + data.error);
                        }
                    } catch (e) {
                        alert('Network error during AI analysis.');
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                }

                async function openManualInterpret() {
                    document.getElementById('manualInterpretModal').style.display = 'flex';
                    const tableBody = document.getElementById('rawResponsesTableBody');
                    tableBody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 3rem; color: #94a3b8;">Loading responses...</td></tr>';

                    try {
                        const res = await fetch(`../api/get_raw_responses.php?id=<?= $activity_id ?>`);
                        const data = await res.json();
                        
                        if (data.success) {
                            if (data.responses.length === 0) {
                                tableBody.innerHTML = '<tr><td colspan="2" style="text-align: center; padding: 3rem; color: #94a3b8;">No responses found.</td></tr>';
                            } else {
                                tableBody.innerHTML = data.responses.map(r => `
                                    <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                        <td style="padding: 16px; font-size: 0.85rem; color: #475569; vertical-align: top; line-height: 1.5; border-right: 1px solid #f1f5f9;">${r.best_topics || '<span style="color: #cbd5e1;">N/A</span>'}</td>
                                        <td style="padding: 16px; font-size: 0.85rem; color: #475569; vertical-align: top; line-height: 1.5;">${r.improvements || '<span style="color: #cbd5e1;">N/A</span>'}</td>
                                    </tr>
                                `).join('');
                            }
                        } else {
                            tableBody.innerHTML = `<tr><td colspan="2" style="color: #ef4444; text-align: center; padding: 3rem;">Error: ${data.error}</td></tr>`;
                        }
                    } catch (e) {
                        tableBody.innerHTML = '<tr><td colspan="2" style="color: #ef4444; text-align: center; padding: 3rem;">Failed to load responses.</td></tr>';
                    }
                }

                function closeManualInterpret() {
                    document.getElementById('manualInterpretModal').style.display = 'none';
                }

                async function saveManualInterpretation() {
                    const btn = event.currentTarget;
                    const complaints = document.getElementById('manualComplaints').value;
                    const suggestions = document.getElementById('manualSuggestions').value;

                    btn.disabled = true;
                    btn.textContent = 'Saving...';

                    try {
                        const fd = new FormData();
                        fd.append('activity_id', '<?= $activity_id ?>');
                        fd.append('complaints', complaints);
                        fd.append('suggestions', suggestions);

                        const res = await fetch('../api/save_manual_interpretation.php', {
                            method: 'POST',
                            body: fd
                        });
                        const data = await res.json();

                        if (data.success) {
                            document.getElementById('complaints-display').innerHTML = complaints.replace(/\n/g, '<br>');
                            document.getElementById('suggestions-display').innerHTML = suggestions.replace(/\n/g, '<br>');
                            closeManualInterpret();
                            alert('Interpretation saved successfully!');
                        } else {
                            alert('Failed to save: ' + data.error);
                        }
                    } catch (e) {
                        alert('Network error saving interpretation.');
                    } finally {
                        btn.disabled = false;
                        btn.textContent = 'Save Interpretation';
                    }
                }

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

                async function syncGoogleResponses(id) {
                    try {
                        const btn = event.currentTarget;
                        const originalText = btn.innerHTML;
                        btn.innerHTML = '<span style="display:inline-block; width:16px; height:16px; border:2px solid #16a34a; border-right-color:transparent; border-radius:50%; animation:spin 1s linear infinite;"></span> Syncing...';
                        btn.disabled = true;

                        const response = await fetch(`../api/sync_google_responses.php?id=${id}`);
                        const data = await response.json();
                        
                        if (data.success) {
                            alert(data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                        }
                    } catch (err) {
                        alert('Failed to sync responses.');
                    }
                }

                function toggleActivitySdgDescription(card) {
                    const panel = document.getElementById('activitySdgDetailPanel');
                    const isActive = card.classList.contains('active');

                    document.querySelectorAll('.activity-sdg-card').forEach(item => {
                        item.classList.remove('active');
                        item.setAttribute('aria-expanded', 'false');
                        const img = item.querySelector('img');
                        if (img) {
                            img.style.transform = 'scale(1)';
                            img.style.outline = 'none';
                            img.style.outlineOffset = '0';
                        }
                    });

                    if (isActive) {
                        panel.style.display = 'none';
                        return;
                    }

                    const img = card.querySelector('img');
                    card.classList.add('active');
                    card.setAttribute('aria-expanded', 'true');
                    if (img) {
                        img.style.transform = 'scale(1.05)';
                        img.style.outline = '3px solid rgba(0, 28, 87, 0.18)';
                        img.style.outlineOffset = '3px';
                    }

                    document.getElementById('activitySdgDetailIcon').src = card.dataset.sdgIcon;
                    document.getElementById('activitySdgDetailIcon').alt = card.dataset.sdgTitle;
                    document.getElementById('activitySdgDetailTitle').textContent = `SDG ${card.dataset.sdgId}: ${card.dataset.sdgTitle}`;
                    document.getElementById('activitySdgDetailDescription').textContent = card.dataset.sdgDescription;
                    panel.style.display = 'flex';
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

                async function toggleVisibility(evaluationId, activityId) {
                    const btn    = document.getElementById('visibilityToggleBtn');
                    const track  = document.getElementById('visToggleTrack');
                    const thumb  = document.getElementById('visToggleThumb');
                    const label  = document.getElementById('visToggleLabel');

                    // Optimistic disable during request
                    btn.disabled = true;
                    btn.style.opacity = '0.6';

                    try {
                        const fd = new FormData();
                        fd.append('activity_id', activityId);

                        const res  = await fetch('../api/evaluation_settings.php?action=toggle_visibility', {
                            method: 'POST', body: fd
                        });
                        const data = await res.json();

                        if (data.success) {
                            const isNowOpen = (data.published_options === 'Open');

                            // Animate pill
                            track.style.background = isNowOpen ? '#10b981' : '#334155';
                            thumb.style.left        = isNowOpen ? '19px'   : '3px';

                            // Update label
                            label.textContent   = isNowOpen ? 'Open' : 'Closed';
                            label.style.color   = isNowOpen ? '#10b981' : '#94a3b8';

                            // Update button border
                            btn.style.borderColor = isNowOpen ? '#10b981' : 'rgba(255,255,255,0.1)';
                        } else {
                            alert('Failed to update visibility: ' + (data.error || 'Unknown error'));
                        }
                    } catch (e) {
                        alert('Network error. Please try again.');
                    } finally {
                        btn.disabled = false;
                        btn.style.opacity = '1';
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

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3 style="font-size: 1.1rem; color: var(--text-primary); margin: 0;">Evaluation Interpretation</h3>
                        <div class="action-dropdown" style="position: relative;">
                            <button onclick="toggleInterpretDropdown(event)" style="display: flex; align-items: center; gap: 8px; background: white; color: var(--accent-blue); padding: 8px 16px; border-radius: 8px; border: 1px solid var(--accent-blue); font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 1 1-7.6-11.7 8.38 8.38 0 0 1 3.8.9L21 3.5l-1 4.5 4.5-1z"/></svg>
                                Interpret Results
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <div id="interpretDropdown" style="display: none; position: absolute; right: 0; top: 100%; margin-top: 5px; background: white; border: 1px solid var(--border-color); border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 100; min-width: 180px; overflow: hidden;">
                                <button onclick="runAIInterpretation()" style="width: 100%; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); background: white; font-size: 0.85rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                                    AI Interpret
                                </button>
                                <button onclick="openManualInterpret()" style="width: 100%; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 12px 16px; color: var(--text-primary); background: white; font-size: 0.85rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    Manual Interpret
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                        <div style="background: #fff; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); position: relative;">
                            <h4 style="margin: 0 0 1rem 0; font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 0 0 6 0v-4"/><path d="M10 5h6a3 3 0 0 1 3 3v4a3 3 0 0 1-3 3h-6a3 3 0 0 1-3-3V8a3 3 0 0 1 3-3z"/></svg>
                                Complaints
                            </h4>
                            <div id="complaints-display" style="font-size: 0.9rem; color: #64748b; line-height: 1.6; min-height: 50px;">
                                <?= $evaluation['complaints'] ? nl2br(htmlspecialchars($evaluation['complaints'])) : '<i>No complaints reported.</i>' ?>
                            </div>
                        </div>
                        <div style="background: #fff; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-color); position: relative;">
                            <h4 style="margin: 0 0 1rem 0; font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                Suggestions for Improvement
                            </h4>
                            <div id="suggestions-display" style="font-size: 0.9rem; color: #64748b; line-height: 1.6; min-height: 50px;">
                                <?= $evaluation['suggestions_for_improvement'] ? nl2br(htmlspecialchars($evaluation['suggestions_for_improvement'])) : '<i>No suggestions provided.</i>' ?>
                            </div>
                        </div>
                    </div>

                    <!-- Manual Interpretation Modal -->
                    <div id="manualInterpretModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1100; align-items: center; justify-content: center;">
                        <div style="background: white; width: 95%; max-width: 1200px; height: 85vh; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; display: flex; flex-direction: column; animation: modalPop 0.3s ease;">
                            <div style="padding: 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
                                <div>
                                    <h2 style="font-size: 1.25rem; font-weight: 800; color: #1e293b; margin: 0;">Manual Interpretation</h2>
                                    <p style="font-size: 0.8rem; color: #64748b; margin-top: 4px;">Review raw responses and write your summary.</p>
                                </div>
                                <button onclick="closeManualInterpret()" style="background: none; border: none; cursor: pointer; color: #94a3b8; transition: color 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#94a3b8'">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 350px; flex: 1; overflow: hidden;">
                                <!-- Raw Responses List -->
                                <div style="padding: 24px; overflow-y: auto; background: #f8fafc; border-right: 1px solid #e2e8f0;">
                                    <h3 style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1rem;">Respondent Feedback</h3>
                                    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                        <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                                            <thead style="background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
                                                <tr>
                                                    <th style="padding: 12px 16px; text-align: left; font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 800; width: 50%;">Liked Best</th>
                                                    <th style="padding: 12px 16px; text-align: left; font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 800; width: 50%;">Least Liked / Improved</th>
                                                </tr>
                                            </thead>
                                            <tbody id="rawResponsesTableBody">
                                                <!-- Dynamic rows -->
                                                <tr>
                                                    <td colspan="2" style="text-align: center; padding: 3rem; color: #94a3b8;">Loading responses...</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                
                                <!-- Manual Input Form -->
                                <div style="padding: 24px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px;">
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase;">Complaints</label>
                                        <textarea id="manualComplaints" rows="6" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.9rem; resize: vertical;" placeholder="Summarize common complaints..."><?= htmlspecialchars($evaluation['complaints'] ?? '') ?></textarea>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <label style="font-size: 0.85rem; font-weight: 700; color: #475569; text-transform: uppercase;">Suggestions</label>
                                        <textarea id="manualSuggestions" rows="6" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 0.9rem; resize: vertical;" placeholder="Summarize suggestions for improvement..."><?= htmlspecialchars($evaluation['suggestions_for_improvement'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 12px;">
                                <button onclick="closeManualInterpret()" style="padding: 10px 20px; border-radius: 10px; border: 1px solid #cbd5e1; background: white; color: #475569; font-weight: 700; cursor: pointer; transition: all 0.2s;">Cancel</button>
                                <button onclick="saveManualInterpretation()" style="padding: 10px 24px; border-radius: 10px; border: none; background: #2563eb; color: white; font-weight: 700; cursor: pointer; transition: all 0.2s;">Save Interpretation</button>
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
                                <?php
                                    $isOpen   = ($evaluation['published_options'] === 'Open');
                                    $togBorder = $isOpen ? '#10b981' : 'rgba(255,255,255,0.1)';
                                    $togLabel  = $isOpen ? 'Open' : 'Closed';
                                    $togColor  = $isOpen ? '#10b981' : '#94a3b8';
                                ?>
                                <button
                                    id="visibilityToggleBtn"
                                    onclick="toggleVisibility(<?= $evaluation['evaluation_id'] ?>, <?= $activity_id ?>)"
                                    title="Toggle form visibility"
                                    style="background: rgba(255,255,255,0.03); padding: 10px 18px; border-radius: 10px; border: 1px solid <?= $togBorder ?>; display: flex; flex-direction: column; justify-content: center; cursor: pointer; transition: all 0.25s; gap: 6px; min-width: 110px;"
                                    onmouseover="this.style.background='rgba(255,255,255,0.07)'"
                                    onmouseout="this.style.background='rgba(255,255,255,0.03)'">
                                    <div style="font-size: 0.6rem; color: #64748b; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; text-align: left;">Visibility</div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <!-- Pill switch -->
                                        <div id="visToggleTrack" style="width: 36px; height: 20px; border-radius: 20px; background: <?= $isOpen ? '#10b981' : '#334155' ?>; position: relative; transition: background 0.25s; flex-shrink: 0;">
                                            <div id="visToggleThumb" style="width: 14px; height: 14px; border-radius: 50%; background: white; position: absolute; top: 3px; left: <?= $isOpen ? '19px' : '3px' ?>; transition: left 0.25s; box-shadow: 0 1px 4px rgba(0,0,0,0.3);"></div>
                                        </div>
                                        <span id="visToggleLabel" style="font-size: 0.85rem; font-weight: 700; color: <?= $togColor ?>;"><?= $togLabel ?></span>
                                    </div>
                                </button>
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
                                    <span style="font-size: 3rem; font-weight: 900; color: #fbbf24; line-height: 1;"><?= $evaluation['overall_average'] ?: '0%' ?></span>
                                    <span style="font-size: 1.2rem; color: #475569; font-weight: 600;">Score</span>
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
                                            <div style="font-size: 1rem; color: #f8fafc; font-weight: 700;"><?= $m['label'] ?></div>
                                            <div style="font-size: 0.65rem; color: #64748b; margin-top: 4px; line-height: 1.4;"><?= $evaluation[$m['val']] ?: 'No data yet' ?></div>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-size: 1.6rem; font-weight: 900; color: #fbbf24;"><?= $evaluation[$m['wa']] ?: '0%' ?></div>
                                        <div style="font-size: 0.6rem; color: #475569; text-transform: uppercase; font-weight: 800; letter-spacing: 1px;">Weighted Avg</div>
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
                        <?php if (!empty($speaker_ratings) || !empty($organizer_ratings)): ?>
                            <div style="margin-top: 3rem;">
                                <h3 style="font-size: 1rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                                    Facilitator Excellence Ratings
                                </h3>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                    <?php 
                                    $all_ratings = [];
                                    foreach($speaker_ratings as $s) { $s['role_label'] = 'Speaker'; $s['role_code'] = 'SP'; $all_ratings[] = $s; }
                                    foreach($organizer_ratings as $o) { $o['role_label'] = 'Organizer'; $o['role_code'] = 'OG'; $all_ratings[] = $o; }
                                    
                                    foreach($all_ratings as $r): 
                                        $avg = ($r['eff'] + $r['mot'] + $r['atf']) / 3;
                                    ?>
                                        <div style="background: rgba(255,255,255,0.03); padding: 1.5rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05);">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 1rem;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 36px; height: 36px; border-radius: 50%; background: <?= $r['role_code'] === 'SP' ? '#fbbf24' : '#3b82f6' ?>; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem;"><?= $r['role_code'] ?></div>
                                                    <div>
                                                        <div style="font-size: 0.95rem; color: #f8fafc; font-weight: 700;"><?= htmlspecialchars($r['name']) ?></div>
                                                        <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;"><?= $r['role_label'] ?></div>
                                                    </div>
                                                </div>
                                                <div style="text-align: right;">
                                                    <div style="font-size: 1.4rem; font-weight: 800; color: #fbbf24;"><?= number_format($avg, 2) ?></div>
                                                    <div style="font-size: 0.6rem; color: #475569; font-weight: 800; text-transform: uppercase;">Average</div>
                                                </div>
                                            </div>
                                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem;">
                                                <div style="background: rgba(255,255,255,0.02); padding: 8px; border-radius: 8px; text-align: center;">
                                                    <div style="font-size: 0.55rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Effectiveness</div>
                                                    <div style="font-size: 0.85rem; color: #cbd5e1; font-weight: 700;"><?= number_format($r['eff'], 2) ?></div>
                                                </div>
                                                <div style="background: rgba(255,255,255,0.02); padding: 8px; border-radius: 8px; text-align: center;">
                                                    <div style="font-size: 0.55rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Mastery</div>
                                                    <div style="font-size: 0.85rem; color: #cbd5e1; font-weight: 700;"><?= number_format($r['mot'], 2) ?></div>
                                                </div>
                                                <div style="background: rgba(255,255,255,0.02); padding: 8px; border-radius: 8px; text-align: center;">
                                                    <div style="font-size: 0.55rem; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Facilitation</div>
                                                    <div style="font-size: 0.85rem; color: #cbd5e1; font-weight: 700;"><?= number_format($r['atf'], 2) ?></div>
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
