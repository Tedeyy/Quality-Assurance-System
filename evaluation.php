<?php
require_once __DIR__ . '/config/database.php';
$database = new Database();
$db = $database->getConnection();

$activity_code = $_GET['code'] ?? null;
if (!$activity_code) die("Invalid Access Code");

$stmt = $db->prepare("SELECT * FROM activities WHERE activity_code = :code");
$stmt->execute(['code' => $activity_code]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$activity) die("Activity not found");

$activity_id = $activity['activity_id'];

// Gate: check if the evaluation form is open
$eval_check = $db->prepare("SELECT published_options FROM activity_evaluation WHERE activity_id = :id");
$eval_check->execute(['id' => $activity_id]);
$eval_row = $eval_check->fetch(PDO::FETCH_ASSOC);
$published_options = $eval_row['published_options'] ?? null;

if ($published_options !== 'Open') {
    // Determine a friendly reason
    $reason = (!$eval_row)
        ? "The evaluation form has not been generated yet."
        : "The evaluation form is currently closed by the administrator.";
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Closed — <?= htmlspecialchars($activity['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/img/QAO_logo.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #f8fafc;
        }
        .card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 3rem 2.5rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
            backdrop-filter: blur(20px);
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            animation: pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes pop {
            from { opacity: 0; transform: scale(0.9) translateY(20px); }
            to   { opacity: 1; transform: scale(1)   translateY(0);    }
        }
        .icon-wrap {
            width: 72px; height: 72px;
            background: rgba(239,68,68,0.15);
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .icon-wrap svg { color: #ef4444; }
        h1 { font-size: 1.6rem; font-weight: 800; margin-bottom: 0.75rem; color: #f8fafc; }
        .activity-name {
            font-size: 0.9rem; font-weight: 600; color: #60a5fa;
            background: rgba(96,165,250,0.1);
            padding: 6px 16px; border-radius: 20px; display: inline-block;
            margin-bottom: 1.25rem;
        }
        p { font-size: 0.95rem; color: #94a3b8; line-height: 1.6; }
        .divider { height: 1px; background: rgba(255,255,255,0.06); margin: 1.5rem 0; }
        .meta { font-size: 0.8rem; color: #475569; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h1>Form Closed</h1>
        <div class="activity-name"><?= htmlspecialchars($activity['title']) ?></div>
        <p><?= $reason ?></p>
        <div class="divider"></div>
        <p class="meta">If you believe this is an error, please contact the event organizer.</p>
    </div>
</body>
</html>
    <?php
    exit;
}

// Parse Template
$templatePath = __DIR__ . '/context/formtemplatespeaker';
$templateContent = file_exists($templatePath) ? file_get_contents($templatePath) : '';

// Simple parsing for the letter
preg_match('/Institutional Excellence\s+(.*?)\s+Data Privacy/s', $templateContent, $descMatches);
$introLetter = isset($descMatches[1]) ? trim($descMatches[1]) : "";

preg_match('/Data Privacy and Consent Statement\s+(.*?)\s+-Yes/s', $templateContent, $privacyMatches);
$privacyStatement = isset($privacyMatches[1]) ? trim($privacyMatches[1]) : "";

// Load facilitators from the normalized junction table
$fac_stmt = $db->prepare(
    "SELECT af.role, COALESCE(sp.name, og.name) AS name
     FROM   activity_facilitators af
     LEFT JOIN speakers   sp ON af.role = 'speaker'   AND af.person_id = sp.speaker_id
     LEFT JOIN organizers og ON af.role = 'organizer' AND af.person_id = og.organizer_id
     WHERE  af.activity_id = :id
     ORDER BY af.role, af.af_id"
);
$fac_stmt->execute(['id' => $activity_id]);
$fac_rows = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

$facilitators = [];
if (!empty($fac_rows)) {
    foreach ($fac_rows as $f) {
        if ($f['name']) {
            $facilitators[] = [
                'name' => $f['name'],
                'type' => ucfirst($f['role']),
            ];
        }
    }
} else {
    // Fallback: parse legacy comma strings for activities created before migration
    if (!empty($activity['speaker'])) {
        foreach (explode(',', $activity['speaker']) as $s) {
            $s = trim($s);
            if ($s) $facilitators[] = ['name' => $s, 'type' => 'Speaker'];
        }
    }
    if (!empty($activity['organizer'])) {
        foreach (explode(',', $activity['organizer']) as $o) {
            $o = trim($o);
            if ($o) $facilitators[] = ['name' => $o, 'type' => 'Organizer'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation: <?= htmlspecialchars($activity['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/img/QAO_logo.png">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); line-height: 1.6; padding: 20px; }

        .container { max-width: 768px; margin: 0 auto; }
        
        .header-img { width: 100%; border-radius: 16px 16px 0 0; display: block; }
        
        .form-card { background: var(--card-bg); border-radius: 0 0 16px 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); overflow: hidden; margin-bottom: 2rem; }
        
        .section { padding: 40px; display: none; }
        .section.active { display: block; animation: fadeIn 0.4s ease; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        h1 { font-size: 1.8rem; margin-bottom: 0.5rem; color: #1e3a8a; }
        .meta-info { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
        
        .letter-content { font-size: 0.95rem; color: var(--text-main); margin-bottom: 2rem; white-space: pre-wrap; text-align: justify; }
        
        .privacy-box { background: #f1f5f9; padding: 20px; border-radius: 12px; margin-bottom: 2rem; border-left: 4px solid var(--primary); }
        .privacy-box h3 { font-size: 1rem; margin-bottom: 10px; }
        .privacy-box p { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px; }

        .form-group { margin-bottom: 2rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.95rem; }
        .form-group .description { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px; }
        
        input[type="text"], input[type="email"], select, textarea {
            width: 100%; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border); font-family: inherit; font-size: 1rem; transition: border-color 0.2s;
        }
        input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }

        .rating-grid { display: flex; flex-direction: column; gap: 1.5rem; }
        .rating-item { padding: 15px; background: #f8fafc; border-radius: 10px; }
        .rating-item span { display: block; font-weight: 500; margin-bottom: 10px; }
        .rating-options { display: flex; justify-content: space-between; align-items: center; }
        .rating-options label { display: flex; flex-direction: column; align-items: center; gap: 5px; cursor: pointer; }
        .rating-options input { width: 20px; height: 20px; cursor: pointer; }
        .rating-label { font-size: 0.75rem; color: var(--text-muted); }

        .footer { display: flex; justify-content: space-between; padding: 20px 40px; background: #f8fafc; border-top: 1px solid var(--border); }
        
        button {
            padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none;
        }
        .btn-next { background: var(--primary); color: white; }
        .btn-next:hover { background: var(--primary-hover); }
        .btn-prev { background: white; border: 1px solid var(--border); color: var(--text-main); }
        .btn-prev:hover { background: #f1f5f9; }

        .progress-bar { height: 4px; background: #e2e8f0; position: sticky; top: 0; z-index: 100; }
        .progress-fill { height: 100%; background: var(--primary); transition: width 0.3s; width: 0%; }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal {
            background: white;
            padding: 40px;
            border-radius: 16px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
            animation: modalPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes modalPop {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .modal h2 { color: #dc2626; margin-bottom: 15px; }
        .modal p { color: var(--text-muted); margin-bottom: 25px; }
        .btn-close-page {
            background: #f1f5f9;
            color: var(--text-main);
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            border: 1px solid var(--border);
        }
        .btn-close-page:hover { background: #e2e8f0; }
        .btn-prev {
            background: white;
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
        
        <img src="assets/img/formheader.png" class="header-img" alt="Header">
        
        <form id="evalForm" action="api/submit_evaluation.php" method="POST" class="form-card">
            <input type="hidden" name="activity_id" value="<?= $activity_id ?>">

            <!-- SECTION 0: INTRO -->
            <div class="section active" id="sec0">
                <h1><?= htmlspecialchars($activity['title']) ?></h1>
                <div class="meta-info">
                    Venue: <?= htmlspecialchars($activity['eventvenue']) ?> | Date: <?= htmlspecialchars($activity['eventdate']) ?>
                </div>
                
                <h2 style="margin-bottom: 1rem; font-size: 1.2rem;">Commitment to Continuous Institutional Excellence</h2>
                <div class="letter-content"><?= htmlspecialchars($introLetter) ?></div>
                
                <div class="privacy-box">
                    <h3>Data Privacy and Consent Statement</h3>
                    <p><?= htmlspecialchars($privacyStatement) ?></p>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="font-weight: 500; font-size: 0.9rem; cursor: pointer;">
                            <input type="radio" name="privacy_consent" value="Yes" required> Yes, I acknowledge
                        </label>
                        <label style="font-weight: 500; font-size: 0.9rem; cursor: pointer;">
                            <input type="radio" name="privacy_consent" value="No"> Opt out
                        </label>
                    </div>
                </div>
                
                <div style="text-align: right;">
                    <button type="button" class="btn-next" onclick="nextSection(0)">Start Evaluation</button>
                </div>
            </div>

            <!-- SECTION 1: PROFILE -->
            <div class="section" id="sec1">
                <h2>Section 1: Profile</h2>
                <p class="meta-info">Please provide your details for demographic tracking.</p>
                
                <div class="form-group">
                    <label>Email Address*</label>
                    <input type="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label>Full Name</label>
                    <div class="description">Format: Last Name, First Name, Middle Initial (Optional)</div>
                    <input type="text" name="fullname">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Age*</label>
                        <input type="text" name="age" required>
                    </div>
                    <div class="form-group">
                        <label>Gender*</label>
                        <select name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Contact Number*</label>
                    <input type="text" name="contact" required>
                </div>

                <div class="form-group">
                    <label>Unit / Office / Division*</label>
                    <input type="text" name="unit" required>
                </div>

                <div class="footer">
                    <button type="button" class="btn-prev" onclick="prevSection(1)">Back</button>
                    <button type="button" class="btn-next" onclick="nextSection(1)">Next</button>
                </div>
            </div>

            <!-- SECTION 2: QUALITY ASSESSMENT -->
            <div class="section" id="sec2">
                <h2>Section 2: Quality Assessment</h2>
                <p class="meta-info">Rate the general success of the totality of the activity execution.</p>

                <div class="rating-item">
                    <span>General success of the totality of the activity execution*</span>
                    <p class="description">Overall assessment of how the activity was conducted from start to finish.</p>
                    <div class="rating-options">
                        <span class="rating-label">Poor</span>
                        <label><input type="radio" name="osr" value="1" required>1</label>
                        <label><input type="radio" name="osr" value="2">2</label>
                        <label><input type="radio" name="osr" value="3">3</label>
                        <label><input type="radio" name="osr" value="4">4</label>
                        <label><input type="radio" name="osr" value="5">5</label>
                        <span class="rating-label">Excellent</span>
                    </div>
                </div>

                <div class="footer" style="margin-top: 2rem;">
                    <button type="button" class="btn-prev" onclick="prevSection(2)">Back</button>
                    <button type="button" class="btn-next" onclick="nextSection(2)">Next</button>
                </div>
            </div>

            <!-- SECTION 3: FACILITATORS (DYNAMIC) -->
            <?php foreach ($facilitators as $index => $f): ?>
            <div class="section" id="secFac<?= $index ?>">
                <h2>Performance: <?= htmlspecialchars($f['name']) ?></h2>
                <p class="meta-info">Rate the <?= strtolower($f['type']) ?>'s expertise, mastery, engagement, and impact.</p>

                <div class="rating-grid">
                    <?php 
                    $metrics = [
                        'eff' => ['label' => 'Effectiveness', 'desc' => 'How well the facilitator achieved the intended goals of the session.'],
                        'mot' => ['label' => 'Mastery of Topic', 'desc' => 'The depth of knowledge and command shown over the subject matter.'],
                        'atf' => ['label' => 'Ability to Facilitate', 'desc' => 'The facilitator\'s skill in managing the discussion and audience participation.']
                    ];
                    foreach ($metrics as $key => $m): ?>
                    <div class="rating-item">
                        <span><?= $m['label'] ?>*</span>
                        <p class="description"><?= $m['desc'] ?></p>
                        <div class="rating-options">
                            <span class="rating-label">Poor</span>
                            <label><input type="radio" name="fac_<?= $index ?>_<?= $key ?>" value="1" required>1</label>
                            <label><input type="radio" name="fac_<?= $index ?>_<?= $key ?>" value="2">2</label>
                            <label><input type="radio" name="fac_<?= $index ?>_<?= $key ?>" value="3">3</label>
                            <label><input type="radio" name="fac_<?= $index ?>_<?= $key ?>" value="4">4</label>
                            <label><input type="radio" name="fac_<?= $index ?>_<?= $key ?>" value="5">5</label>
                            <span class="rating-label">Excellent</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="footer" style="margin-top: 2rem;">
                    <button type="button" class="btn-prev" onclick="prevFacSection(<?= $index ?>)">Back</button>
                    <button type="button" class="btn-next" onclick="nextFacSection(<?= $index ?>)">Next</button>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- SECTION 4: PROGRAM & LOGISTICS -->
            <div class="section" id="secFinal">
                <h2>Program & Logistics</h2>
                
                <h3 style="margin: 1rem 0; font-size: 1rem;">III. Evaluation Results (Program)</h3>
                <div class="rating-grid">
                    <?php $prog = [
                        ["label" => "Program Flow", "desc" => "Smoothness and logical transition between the various parts of the activity."],
                        ["label" => "Program Contents", "desc" => "Quality, relevance, and substance of the materials and topics presented."],
                        ["label" => "Relevance to Objective", "desc" => "How well the program aligned with the stated goals and expectations."],
                        ["label" => "Future Applicability", "desc" => "The likelihood of using the knowledge or skills gained in your future work."]
                    ];
                    foreach ($prog as $i => $p): ?>
                    <div class="rating-item">
                        <span><?= $p['label'] ?>*</span>
                        <p class="description"><?= $p['desc'] ?></p>
                        <div class="rating-options">
                            <label><input type="radio" name="prog_<?= $i ?>" value="1" required>1</label>
                            <label><input type="radio" name="prog_<?= $i ?>" value="2">2</label>
                            <label><input type="radio" name="prog_<?= $i ?>" value="3">3</label>
                            <label><input type="radio" name="prog_<?= $i ?>" value="4">4</label>
                            <label><input type="radio" name="prog_<?= $i ?>" value="5">5</label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <h3 style="margin: 2rem 0 1rem 0; font-size: 1rem;">IV. Logistics</h3>
                <p class="meta-info" style="margin-bottom: 1rem;">Rate the secretariat service, venue, and scheduling.</p>
                <div class="rating-grid">
                    <?php $logs = [
                        ["label" => "Secretariat Service", "desc" => "Efficiency, courtesy, and responsiveness of the registration and support staff."],
                        ["label" => "Logistics/Venue", "desc" => "Comfort, cleanliness, accessibility, and adequacy of the facilities provided."],
                        ["label" => "Timing/Scheduling", "desc" => "Punctuality, appropriate time allocation, and overall duration of the session."]
                    ];
                    foreach ($logs as $i => $l): ?>
                    <div class="rating-item">
                        <span><?= $l['label'] ?>*</span>
                        <p class="description"><?= $l['desc'] ?></p>
                        <div class="rating-options">
                            <label><input type="radio" name="log_<?= $i ?>" value="1" required>1</label>
                            <label><input type="radio" name="log_<?= $i ?>" value="2">2</label>
                            <label><input type="radio" name="log_<?= $i ?>" value="3">3</label>
                            <label><input type="radio" name="log_<?= $i ?>" value="4">4</label>
                            <label><input type="radio" name="log_<?= $i ?>" value="5">5</label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="footer" style="margin-top: 2rem;">
                    <button type="button" class="btn-prev" onclick="prevFinal()">Back</button>
                    <button type="button" class="btn-next" onclick="nextSection('Final')">Next</button>
                </div>
            </div>

            <!-- SECTION 5: FEEDBACK -->
            <div class="section" id="secFeedback">
                <h2>Feedback & Suggestions</h2>
                
                <div class="form-group" style="margin-top: 2rem;">
                    <label>Which of the topics did you like BEST? Why?*</label>
                    <textarea name="best_topics" rows="4" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Which parts could be improved? (Least Liked)*</label>
                    <textarea name="improvements" rows="4" required></textarea>
                </div>

                <div class="rating-item" style="margin-bottom: 2rem;">
                    <span>Overall Experience*</span>
                    <p class="description">Summarize your total experience with this activity.</p>
                    <div class="rating-options">
                        <span class="rating-label">Poor</span>
                        <label><input type="radio" name="oe" value="1" required>1</label>
                        <label><input type="radio" name="oe" value="2">2</label>
                        <label><input type="radio" name="oe" value="3">3</label>
                        <label><input type="radio" name="oe" value="4">4</label>
                        <label><input type="radio" name="oe" value="5">5</label>
                        <span class="rating-label">Excellent</span>
                    </div>
                </div>

                <div class="footer">
                    <button type="button" class="btn-prev" onclick="document.getElementById('secFeedback').classList.remove('active'); document.getElementById('secFinal').classList.add('active');">Back</button>
                    <button type="submit" class="btn-next" style="background: #059669;">Submit Evaluation</button>
                </div>
            </div>

        </form>
    </div>

    <!-- Duplicate Alert Modal -->
    <div id="duplicateModal" class="modal-overlay">
        <div class="modal">
            <h2>Already Responded</h2>
            <p>It looks like you have already submitted an evaluation for this activity. Each participant can only submit once.</p>
            <button type="button" class="btn-close-page" onclick="window.location.href='about:blank'; window.close();">Close Page</button>
            <button type="button" class="btn-prev" style="width: 100%; margin-top: 10px; border: none;" onclick="document.getElementById('duplicateModal').style.display='none'">Go Back</button>
        </div>
    </div>

    <!-- Form Closed Modal -->
    <div id="closedModal" class="modal-overlay">
        <div class="modal">
            <h2>Form Closed</h2>
            <p>The evaluation form has been closed by the administrator. You can no longer submit responses.</p>
            <button type="button" class="btn-close-page" onclick="window.location.reload();">Close Page</button>
        </div>
    </div>

    <script>
        const totalSteps = <?= 5 + count($facilitators) ?>;
        let currentStep = parseInt(localStorage.getItem('eval_step_<?= $activity_code ?>')) || 0;

        // Auto-load saved data
        document.addEventListener('DOMContentLoaded', () => {
            const savedData = JSON.parse(localStorage.getItem('eval_data_<?= $activity_code ?>') || '{}');
            for (let [name, value] of Object.entries(savedData)) {
                const inputs = document.getElementsByName(name);
                if (inputs.length > 0) {
                    if (inputs[0].type === 'radio') {
                        const input = [...inputs].find(i => i.value === value);
                        if (input) input.checked = true;
                    } else {
                        inputs[0].value = value;
                    }
                }
            }
            
            // Restore step
            if (currentStep > 0) {
                document.getElementById('sec0').classList.remove('active');
                let targetId = 'sec' + currentStep;
                if (currentStep >= 3) {
                    const facIdx = currentStep - 3;
                    if (facIdx < <?= count($facilitators) ?>) {
                        targetId = 'secFac' + facIdx;
                    } else {
                        targetId = 'secFinal';
                        if (currentStep > 3 + <?= count($facilitators) ?>) targetId = 'secFeedback';
                    }
                }
                const target = document.getElementById(targetId);
                if (target) target.classList.add('active');
                else document.getElementById('sec0').classList.add('active');
            }
            updateProgress(currentStep);
        });

        // Auto-save data
        document.getElementById('evalForm').addEventListener('input', (e) => {
            const formData = JSON.parse(localStorage.getItem('eval_data_<?= $activity_code ?>') || '{}');
            if (e.target.type === 'radio') {
                if (e.target.checked) formData[e.target.name] = e.target.value;
            } else {
                formData[e.target.name] = e.target.value;
            }
            localStorage.setItem('eval_data_<?= $activity_code ?>', JSON.stringify(formData));
        });

        function updateProgress(step) {
            const fill = document.getElementById('progressFill');
            fill.style.width = ((step / totalSteps) * 100) + '%';
            localStorage.setItem('eval_step_<?= $activity_code ?>', step);
        }

        async function checkFormStatus() {
            try {
                const response = await fetch(`api/check_form_status.php?code=<?= $activity_code ?>`);
                const data = await response.json();
                if (data.status !== 'Open') {
                    document.getElementById('closedModal').style.display = 'flex';
                    return false;
                }
                return true;
            } catch (e) {
                console.error("Status check failed", e);
                return true; // Default to allow if check fails (optional)
            }
        }

        async function nextSection(num) {
            if (!validateSection('sec' + num)) return;
            
            // CHECK FORM STATUS
            if (!await checkFormStatus()) return;

            // Check for duplicate email in Section 1
            if (num === 1) {
                const email = document.querySelector('input[name="email"]').value;
                const btn = document.querySelector('#sec1 .btn-next');
                const originalText = btn.innerText;
                
                btn.innerText = 'Checking...';
                btn.disabled = true;
                
                try {
                    const response = await fetch(`api/check_email_duplicate.php?id=<?= $activity_id ?>&email=${encodeURIComponent(email)}`);
                    const data = await response.json();
                    
                    if (data.status === 'duplicate') {
                        document.getElementById('duplicateModal').style.display = 'flex';
                        btn.innerText = originalText;
                        btn.disabled = false;
                        return;
                    }
                } catch (e) {
                    console.error("Duplicate check failed", e);
                }
                
                btn.innerText = originalText;
                btn.disabled = false;
            }

            document.getElementById('sec' + num).classList.remove('active');
            
            let nextStep = 0;
            if (num === 0) {
                document.getElementById('sec1').classList.add('active');
                nextStep = 1;
            } else if (num === 1) {
                document.getElementById('sec2').classList.add('active');
                nextStep = 2;
            } else if (num === 2) {
                <?php if (count($facilitators) > 0): ?>
                    document.getElementById('secFac0').classList.add('active');
                    nextStep = 3;
                <?php else: ?>
                    document.getElementById('secFinal').classList.add('active');
                    nextStep = 3;
                <?php endif; ?>
            } else if (num === 'Final') {
                document.getElementById('secFeedback').classList.add('active');
                nextStep = totalSteps;
            }
            currentStep = nextStep;
            updateProgress(currentStep);
            window.scrollTo(0,0);
        }

        function prevSection(num) {
            document.getElementById('sec' + num).classList.remove('active');
            if (num === 1) document.getElementById('sec0').classList.add('active');
            if (num === 2) document.getElementById('sec1').classList.add('active');
            currentStep--;
            updateProgress(currentStep);
        }

        async function nextFacSection(idx) {
            if (!validateSection('secFac' + idx)) return;
            
            // CHECK FORM STATUS
            if (!await checkFormStatus()) return;

            document.getElementById('secFac' + idx).classList.remove('active');
            const nextIdx = idx + 1;
            if (document.getElementById('secFac' + nextIdx)) {
                document.getElementById('secFac' + nextIdx).classList.add('active');
            } else {
                document.getElementById('secFinal').classList.add('active');
            }
            currentStep++;
            updateProgress(currentStep);
            window.scrollTo(0,0);
        }

        function prevFacSection(idx) {
            document.getElementById('secFac' + idx).classList.remove('active');
            if (idx === 0) {
                document.getElementById('sec2').classList.add('active');
            } else {
                document.getElementById('secFac' + (idx - 1)).classList.add('active');
            }
            currentStep--;
            updateProgress(currentStep);
        }

        function prevFinal() {
            document.getElementById('secFinal').classList.remove('active');
            <?php if (count($facilitators) > 0): ?>
                document.getElementById('secFac<?= count($facilitators)-1 ?>').classList.add('active');
            <?php else: ?>
                document.getElementById('sec2').classList.add('active');
            <?php endif; ?>
            currentStep--;
            updateProgress(currentStep);
        }

        function validateSection(id) {
            const sec = document.getElementById(id);
            const inputs = sec.querySelectorAll('input[required], select[required], textarea[required]');
            for (let input of inputs) {
                if (input.type === 'radio') {
                    const name = input.name;
                    if (!sec.querySelector('input[name="' + name + '"]:checked')) {
                        alert('Please answer all required questions.');
                        return false;
                    }
                } else if (!input.value) {
                    alert('Please fill out all required fields.');
                    input.focus();
                    return false;
                }
            }
            
            // Special check for privacy consent
            if (id === 'sec0') {
                const consent = sec.querySelector('input[name="privacy_consent"]:checked');
                if (consent && consent.value === 'No') {
                    alert('You must acknowledge the data privacy statement to proceed.');
                    return false;
                }
            }
            
            return true;
        }

        // Clear storage on successful submit
        document.getElementById('evalForm').onsubmit = async (e) => {
            e.preventDefault();
            
            // CHECK FORM STATUS
            if (!await checkFormStatus()) return;

            localStorage.removeItem('eval_data_<?= $activity_code ?>');
            localStorage.removeItem('eval_step_<?= $activity_code ?>');
            e.target.submit();
        };
    </script>
</body>
</html>
