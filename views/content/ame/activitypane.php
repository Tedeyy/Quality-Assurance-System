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

if ($evaluation) {
    // Evaluation found
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
                        <div style="font-weight: 700; font-size: 0.8rem; text-transform: uppercase; color: #2563eb;">Activity Evaluation</div>
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
                                $edit_url = !empty($evaluation['ame_form_id']) ? "https://docs.google.com/forms/d/" . $evaluation['ame_form_id'] . "/edit" : str_replace('/viewform', '/edit', $form_url);
                            ?>
                                <div style="position: relative; display: inline-block;">
                                    <button onclick="toggleAMEDropdown(event)" style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #2563eb; color: white; padding: 12px 20px; border-radius: 12px; border: none; text-decoration: none; font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);" onmouseover="this.style.background='#1e40af'; this.style.transform='translateY(-1px)';" onmouseout="this.style.background='#2563eb'; this.style.transform='translateY(0)';">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            Activity Evaluation Form
                                        </div>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.8;"><polyline points="6 9 12 15 18 9"/></svg>
                                    </button>
                                    <div id="ameDropdown" style="display: none; position: absolute; top: 100%; left: 0; margin-top: 8px; background: white; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 100; min-width: 100%; width: max-content; overflow: hidden; padding: 4px 0;">
                                        <a href="<?= $form_url ?>" target="_blank" style="width: 100%; box-sizing: border-box; display: flex; align-items: center; gap: 10px; padding: 10px 16px; color: var(--text-primary); text-decoration: none; font-size: 0.85rem; font-weight: 500; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View Form (Live)
                                        </a>
                                        <button onclick="syncGoogleResponses(<?= $activity_id ?>)" style="width: 100%; box-sizing: border-box; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 10px 16px; color: #16a34a; background: transparent; font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='transparent'">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                            Force Sync Responses
                                        </button>
                                        <div style="height: 1px; background: var(--border-color); margin: 4px 0;"></div>
                                        <button onclick="deleteAMEForm(<?= $activity_id ?>)" style="width: 100%; box-sizing: border-box; border: none; text-align: left; display: flex; align-items: center; gap: 10px; padding: 10px 16px; color: #ef4444; background: transparent; font-size: 0.85rem; font-weight: 500; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='transparent'">
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
                                </div>
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
                    const ameDropdown = document.getElementById('ameDropdown');
                    if (ameDropdown) ameDropdown.style.display = 'none';
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
                <!-- Form Setup -->
                <div style="background: #eff6ff; padding: 1.25rem; border-radius: 16px; border: 1px dashed #93c5fd;">
                    <h3 style="font-size: 1rem; margin-bottom: 1rem; color: #1e40af; display: flex; align-items: center; gap: 8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                        Form Setup
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <div style="display: flex; flex-direction: column; gap: 6px;">
                            <div style="font-weight: 700; color: #1e3a8a; font-size: 0.85rem;">Step 1: Generate</div>
                            <div style="font-size: 0.75rem; color: #64748b; line-height: 1.3;">Creates form & sheet in Google Drive.</div>
                            <?php if (!$evaluation || !$evaluation['ame_form_link']): ?>
                                <button onclick="generateAMEForm(this)" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 6px; background: white; color: var(--accent-blue); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--accent-blue); font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='var(--accent-blue)'; this.style.color='white'" onmouseout="this.style.background='white'; this.style.color='var(--accent-blue)'">
                                    Generate Form
                                </button>
                            <?php else: ?>
                                <span style="font-size: 0.8rem; color: #16a34a; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    Generated
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 6px;">
                            <div style="font-weight: 700; color: #1e3a8a; font-size: 0.85rem;">Step 2: Link Form</div>
                            <div style="font-size: 0.75rem; color: #64748b; line-height: 1.3;">Create new spreadsheet and link it there.</div>
                            <?php if ($evaluation && $evaluation['ame_form_link']): 
                                $form_url = $evaluation['ame_form_link'];
                                $edit_url = !empty($evaluation['ame_form_id']) ? "https://docs.google.com/forms/d/" . $evaluation['ame_form_id'] . "/edit" : str_replace('/viewform', '/edit', $form_url);
                            ?>
                                <a href="<?= $edit_url ?>" target="_blank" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 6px; background: var(--accent-blue); color: white; padding: 8px 12px; border-radius: 8px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: background 0.2s;" onmouseover="this.style.background='#1e3a8a'" onmouseout="this.style.background='var(--accent-blue)'">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    Edit Form
                                </a>
                            <?php else: ?>
                                <span style="font-size: 0.8rem; color: #94a3b8; font-style: italic;">Generate form first.</span>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 6px;">
                            <div style="font-weight: 700; color: #1e3a8a; font-size: 0.85rem;">Step 3: Update Index</div>
                            <div style="font-size: 0.75rem; color: #64748b; line-height: 1.3;">Copy the response spreadsheet URL, then paste it into the Index Responses spreadsheet.</div>
                            <?php if ($evaluation && $evaluation['ame_form_link']): 
                                $index_env = $_ENV['RESPONSES_GOOGLE_SHEET'] ?? '';
                                $index_url = (strpos($index_env, 'http') === 0) ? $index_env : "https://docs.google.com/spreadsheets/d/" . $index_env . "/edit";
                            ?>
                                <a href="<?= htmlspecialchars($index_url) ?>" target="_blank" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 6px; background: white; color: var(--accent-blue); padding: 8px 12px; border-radius: 8px; border: 1px solid var(--accent-blue); text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: background 0.2s;" onmouseover="this.style.background='var(--accent-blue)'; this.style.color='white'" onmouseout="this.style.background='white'; this.style.color='var(--accent-blue)'">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                    Open Index Sheet
                                </a>
                            <?php else: ?>
                                <span style="font-size: 0.8rem; color: #94a3b8; font-style: italic;">Complete Step 2 first.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($evaluation && $evaluation['ame_form_link']): 
                    $form_url = $evaluation['ame_form_link'];
                    $edit_url = !empty($evaluation['ame_form_id']) ? "https://docs.google.com/forms/d/" . $evaluation['ame_form_id'] . "/edit" : str_replace('/viewform', '/edit', $form_url);
                    $isOpen = ($evaluation['published_options'] === 'Open');
                ?>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <button id="toggleFormStatusBtn" onclick="toggleFormStatus(<?= $activity['activity_id'] ?>)" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; background: <?= $isOpen ? '#dcfce7' : '#fee2e2' ?>; color: <?= $isOpen ? '#166534' : '#991b1b' ?>; padding: 12px; border-radius: 12px; border: 1px solid <?= $isOpen ? '#bbf7d0' : '#fecaca' ?>; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <?= $isOpen 
                                ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>' 
                                : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>' 
                            ?>
                        </svg>
                        <span id="toggleFormStatusText">Form Status: <?= $isOpen ? 'OPEN' : 'CLOSED' ?></span>
                    </button>
                    
                    <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($form_url) ?>').then(() => alert('Responders link copied!'))" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; background: white; color: #0f172a; padding: 12px; border-radius: 12px; border: 1px solid #cbd5e1; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02);" onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#94a3b8'" onmouseout="this.style.background='white'; this.style.borderColor='#cbd5e1'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        Copy Responders Link
                    </button>
                    
                    <a href="<?= htmlspecialchars($edit_url) ?>" target="_blank" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; background: white; color: #0f172a; padding: 12px; border-radius: 12px; border: 1px solid #cbd5e1; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02);" onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#94a3b8'" onmouseout="this.style.background='white'; this.style.borderColor='#cbd5e1'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        Edit Form
                    </a>
                    
                    <a href="feed.php?action=evaluations&id=<?= (int)$activity_id ?>" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; background: #2563eb; color: white; padding: 12px; border-radius: 12px; border: none; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s; box-shadow: 0 4px 6px rgba(37, 99, 235, 0.2);" onmouseover="this.style.background='#1e40af'" onmouseout="this.style.background='#2563eb'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                        See Results
                    </a>
                </div>
                <script>
                function toggleFormStatus(activityId) {
                    const btn = document.getElementById('toggleFormStatusBtn');
                    const text = document.getElementById('toggleFormStatusText');
                    const svg = btn.querySelector('svg');
                    
                    text.innerText = 'Updating...';
                    const formData = new FormData();
                    formData.append('activity_id', activityId);

                    fetch('../api/evaluation_settings.php?action=toggle_visibility', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if(data.success) {
                            if(data.published_options === 'Open') {
                                btn.style.background = '#dcfce7';
                                btn.style.color = '#166534';
                                btn.style.borderColor = '#bbf7d0';
                                text.innerText = 'Form Status: OPEN';
                                svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                            } else {
                                btn.style.background = '#fee2e2';
                                btn.style.color = '#991b1b';
                                btn.style.borderColor = '#fecaca';
                                text.innerText = 'Form Status: CLOSED';
                                svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                            }
                        } else {
                            alert('Error: ' + data.error);
                            text.innerText = 'Form Status: ERROR';
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Failed to update form status.');
                        text.innerText = 'Form Status: ERROR';
                    });
                }
                </script>
                <?php endif; ?>

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
<script>
// Lazy Background Sync
setTimeout(async () => {
    const activityId = <?= (int)$activity_id ?>;
    const lastSyncKey = 'last_sync_' + activityId;
    const lastSync = localStorage.getItem(lastSyncKey);
    const now = Date.now();
    
    // Only sync once every 2 minutes (120000 ms)
    if (!lastSync || (now - parseInt(lastSync)) > 120000) {
        try {
            const response = await fetch(`../api/sync_google_responses.php?id=${activityId}`);
            const data = await response.json();
            
            if (data.success && data.count > 0) {
                const toast = document.createElement('div');
                toast.style.position = 'fixed';
                toast.style.bottom = '20px';
                toast.style.right = '20px';
                toast.style.background = '#10b981';
                toast.style.color = 'white';
                toast.style.padding = '12px 24px';
                toast.style.borderRadius = '8px';
                toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                toast.style.zIndex = '9999';
                toast.style.fontFamily = 'system-ui, -apple-system, sans-serif';
                toast.innerHTML = `<div style="display: flex; align-items: center; gap: 10px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span><strong>${data.count} new responses found!</strong> Updating dashboard...</span>
                </div>`;
                document.body.appendChild(toast);
                
                setTimeout(() => { location.reload(); }, 2000);
            }
            localStorage.setItem(lastSyncKey, now);
        } catch (e) {
            console.error('Background sync failed:', e);
        }
    }
}, 1000);
</script>
