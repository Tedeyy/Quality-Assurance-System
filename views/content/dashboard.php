<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../component/access.php';
$db = (new Database())->getConnection();

function safe_trim_text($text, $width = 40): string {
    $text = (string)$text;
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $width, "...");
    }

    return strlen($text) > $width ? substr($text, 0, max(0, $width - 3)) . "..." : $text;
}

$dashboard_error = null;
$userProfile = [];
$recent_activities = [];
$active_accreditation_deadlines = [];

try {
    $stmt = $db->prepare("
        SELECT u.birthdate, u.gender, u.province, u.city, u.barangay, u.address, u.contact_number,
               u.division_id, u.office_id, u.position,
               d.name AS division_name,
               o.name AS office_name,
               o.acronym AS office_acronym
        FROM users u
        LEFT JOIN divisions d ON u.division_id = d.division_id
        LEFT JOIN divisions_offices o ON u.office_id = o.office_id
        WHERE u.user_id = :user_id
    ");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $dashboard_error = "Dashboard profile data could not be loaded.";
    error_log("Dashboard profile query failed: " . $e->getMessage());
}

$needsProfileCompletion = false;
if (empty($userProfile['birthdate']) || empty($userProfile['gender']) || empty($userProfile['province']) || empty($userProfile['city']) || empty($userProfile['barangay']) || empty($userProfile['address']) || empty($userProfile['contact_number']) || empty($userProfile['division_id']) || empty($userProfile['office_id']) || empty($userProfile['position'])) {
    $needsProfileCompletion = true;
}

// Fetch recent user activities
try {
    $recent_activities_stmt = $db->query("
        SELECT ua.*, u.fname, u.lname 
        FROM user_activity ua
        JOIN users u ON ua.user_id = u.user_id
        ORDER BY ua.activity_time DESC
        LIMIT 5
    ");
    $recent_activities = $recent_activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Dashboard recent activity query failed: " . $e->getMessage());
}

// Active accreditation deadlines for dashboard countdown
try {
    $active_deadlines_stmt = $db->query("
        SELECT accreditation_id, code, name, deadline
        FROM accreditations
        WHERE status = 'In Progress'
          AND deadline IS NOT NULL
        ORDER BY deadline ASC, name ASC
    ");
    $active_accreditation_deadlines = $active_deadlines_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Dashboard accreditation deadline query failed: " . $e->getMessage());
}
$today = new DateTimeImmutable('today');
?>
<style>
    .qa-dashboard {
        --dash-ink: #0f172a;
        --dash-muted: #64748b;
        --dash-border: #dbe3ef;
        --dash-soft: #f8fafc;
        --dash-blue: var(--accent-blue, #001c57);
        --dash-gold: var(--accent-gold, #f4b000);
        background: #f5f7fb;
        min-height: calc(100vh - 200px);
        padding: 2rem 5% 3rem;
    }
    .dash-shell { max-width: 1320px; margin: 0 auto; display: flex; flex-direction: column; gap: 1.5rem; }
    .dash-section { background: #fff; border: 1px solid var(--dash-border); border-radius: 8px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.05); overflow: hidden; }
    .dash-section-pad { padding: 1.5rem; }
    .dash-kicker { margin: 0 0 0.35rem; color: var(--dash-blue); font-size: 0.74rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.08em; }
    .dash-title { margin: 0; color: var(--dash-ink); font-size: 1.35rem; line-height: 1.25; font-weight: 900; }
    .dash-copy { margin: 0; color: var(--dash-muted); line-height: 1.65; font-size: 0.94rem; }
    .dash-grid { display: grid; gap: 1rem; }
    .dash-hero-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1.5rem; align-items: stretch; }
    .dash-office-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.65fr); gap: 1rem; }
    .dash-office-overview { display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 1rem; align-items: center; }
    .dash-policy-grid { grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr); }
    .dash-history-grid { display: grid; grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr); gap: 1rem; margin-top: 1rem; align-items: stretch; }
    .dash-card { border: 1px solid var(--dash-border); border-radius: 8px; background: #fff; padding: 1rem; }
    .dash-chip { display: inline-flex; align-items: center; gap: 0.4rem; border-radius: 999px; padding: 0.4rem 0.7rem; font-size: 0.75rem; font-weight: 900; border: 1px solid #dbeafe; background: #eff6ff; color: #1e40af; }
    .dash-icon { width: 42px; height: 42px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .module-card { text-decoration: none; color: inherit; transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease; }
    .module-card:hover { transform: translateY(-2px); border-color: #b7c5dc; box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08); }
    .org-node { border: 1px solid var(--dash-border); border-radius: 8px; background: #fff; padding: 0.9rem; text-align: center; min-height: 88px; display: flex; flex-direction: column; justify-content: center; }
    .org-node strong { color: var(--dash-ink); font-size: 0.9rem; line-height: 1.3; }
    .org-node span { color: var(--dash-muted); font-size: 0.75rem; margin-top: 0.3rem; line-height: 1.35; }
    @media (max-width: 900px) {
        .qa-dashboard { padding: 1rem; }
        .dash-section-pad { padding: 1rem; }
        .dash-title { font-size: 1.15rem; }
        .dash-hero-grid,
        .dash-office-grid,
        .dash-office-overview,
        .dash-policy-grid,
        .dash-history-grid { grid-template-columns: 1fr; }
    }
</style>

<main class="hero qa-dashboard" style="display: block;">
    <?php if ($dashboard_error): ?>
        <div style="max-width: 1320px; margin: 0 auto 1rem; background: #fff7ed; color: #9a3412; padding: 0.9rem 1rem; border: 1px solid #fed7aa; border-radius: 8px; font-weight: 800;">
            <?= htmlspecialchars($dashboard_error) ?>
        </div>
    <?php endif; ?>

    <?php
        $displayName = trim(($_SESSION['user_fname'] ?? '') . ' ' . ($_SESSION['user_lname'] ?? ''));
        if ($displayName === '') {
            $displayName = $_SESSION['user_fname'] ?? 'User';
        }
        $officeName = $userProfile['office_name'] ?? 'Quality Assurance Office';
        $officeAcronym = $userProfile['office_acronym'] ?? 'QAO';
        $divisionName = $userProfile['division_name'] ?? 'Institutional Quality Assurance';
        $positionName = $userProfile['position'] ?? ($_SESSION['user_position'] ?? 'User');
        $staff = [
            ['name' => 'Daniel S. Lerongan, PhD', 'role' => 'Unit Head', 'init' => 'DSL'],
            ['name' => 'May Rose E. Madrid', 'role' => 'Administrative Officer II', 'init' => 'MRM'],
            ['name' => 'Mia Marisol M. Magpulong, LPT', 'role' => 'Administrative Aide VI', 'init' => 'MMM'],
            ['name' => 'Michelle Darlyne B. Ricarte', 'role' => 'Administrative Aide VI', 'init' => 'MBR'],
            ['name' => 'Fretzel Vann L. Ayo-on, LPT', 'role' => 'Administrative Assistant', 'init' => 'FVA'],
            ['name' => 'Teddy Justin C. Bermudo', 'role' => 'Intern', 'init' => 'TJB'],
        ];
        $canAccessAllModules = qa_current_user_can_access_all_modules($db);
        $modules = [
            ['title' => 'Activity Evaluation', 'desc' => 'Manage evaluation forms, responses, interpretation, and activity records.', 'href' => 'feed.php?action=activity', 'color' => '#1d4ed8', 'bg' => '#eff6ff', 'icon' => 'activity'],
            ['title' => 'Accreditation Tracking', 'desc' => 'Track standards, proof requirements, submissions, review status, and deadlines.', 'href' => 'feed.php?action=accreditation', 'color' => '#b45309', 'bg' => '#fffbeb', 'icon' => 'check'],
            ['title' => 'Accreditation Mapping', 'desc' => 'Map requirements to institutional documents and proof sources.', 'href' => 'feed.php?action=accmapping', 'color' => '#7c3aed', 'bg' => '#f5f3ff', 'icon' => 'map'],
            ['title' => 'Document Management', 'desc' => 'Map documents, categories, offices of origin, and accreditation linkages.', 'href' => 'feed.php?action=document', 'color' => '#047857', 'bg' => '#ecfdf5', 'icon' => 'file'],
        ];
        if (!$canAccessAllModules) {
            $modules = array_values(array_filter($modules, function ($module) {
                return $module['href'] === 'feed.php?action=accreditation';
            }));
        }
    ?>

    <div class="dash-shell">
        <section class="dash-section" style="background: linear-gradient(135deg, #ffffff 0%, #f8fbff 56%, #fff8e6 100%);">
            <div class="dash-section-pad dash-hero-grid">
                <div style="display: flex; gap: 1rem; align-items: flex-start;">
                    <div style="width: 68px; height: 68px; border-radius: 12px; background: #fff; border: 1px solid var(--dash-border); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <img src="../assets/img/QAO_logo.png" alt="QAO Logo" style="width: 52px; height: 52px; object-fit: contain;">
                    </div>
                    <div>
                        <p class="dash-kicker">Quality Assurance System</p>
                        <h1 style="margin: 0 0 0.65rem; color: var(--dash-ink); font-size: clamp(1.8rem, 4vw, 3rem); line-height: 1.06; font-weight: 950;">Welcome, <?= htmlspecialchars($displayName) ?></h1>
                        <p class="dash-copy" style="max-width: 720px;">A professional workspace for monitoring accreditation readiness, institutional documents, activity evaluations, and quality assurance operations.</p>
                        <div style="display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 1rem;">
                            <span class="dash-chip"><?= htmlspecialchars($positionName) ?></span>
                            <span class="dash-chip" style="background:#ecfdf5;color:#047857;border-color:#bbf7d0;"><?= $needsProfileCompletion ? 'Profile needs update' : 'Profile complete' ?></span>
                            <span class="dash-chip" style="background:#fff7ed;color:#9a3412;border-color:#fed7aa;"><?= count($active_accreditation_deadlines) ?> active deadlines</span>
                        </div>
                    </div>
                </div>
                <div class="dash-card" style="background: rgba(255,255,255,0.8);">
                    <p class="dash-kicker">Today</p>
                    <div style="font-size: 2rem; font-weight: 950; color: var(--dash-ink);"><?= date('M d, Y') ?></div>
                    <p class="dash-copy" style="margin-top: 0.5rem;">Recent office activity and upcoming accreditation deadlines are summarized below.</p>
                </div>
            </div>
        </section>

        <section class="dash-section">
            <div class="dash-section-pad">
                <p class="dash-kicker">Office Information</p>
                <h2 class="dash-title">Quality Assurance Office</h2>
                <div class="dash-office-grid" style="margin-top: 1rem;">
                    <div class="dash-card dash-office-overview">
                        <div style="height: 150px; border-radius: 8px; background: #f8fafc; border: 1px solid var(--dash-border); display: flex; align-items: center; justify-content: center;">
                            <img src="../assets/img/QAO_logo.png" alt="Quality Assurance Office logo" style="max-width: 118px; max-height: 118px; object-fit: contain;">
                        </div>
                        <div>
                            <h3 style="margin: 0 0 0.45rem; color: var(--dash-ink); font-size: 1.1rem;"><?= htmlspecialchars($officeName) ?><?= $officeAcronym ? ' (' . htmlspecialchars($officeAcronym) . ')' : '' ?></h3>
                            <p class="dash-copy">The office coordinates quality assurance mechanisms for accreditation, document management, activity evaluation, evidence monitoring, and continuous improvement initiatives across the institution.</p>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.7rem; margin-top: 1rem;">
                                <div style="background:#f8fafc;border:1px solid var(--dash-border);border-radius:8px;padding:0.8rem;">
                                    <div style="font-size:0.72rem;color:var(--dash-muted);font-weight:900;text-transform:uppercase;">Division</div>
                                    <div style="margin-top:0.25rem;color:var(--dash-ink);font-weight:850;"><?= htmlspecialchars($divisionName) ?></div>
                                </div>
                                <div style="background:#f8fafc;border:1px solid var(--dash-border);border-radius:8px;padding:0.8rem;">
                                    <div style="font-size:0.72rem;color:var(--dash-muted);font-weight:900;text-transform:uppercase;">Your Role</div>
                                    <div style="margin-top:0.25rem;color:var(--dash-ink);font-weight:850;"><?= htmlspecialchars($positionName) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="dash-card">
                        <p class="dash-kicker">Office Priorities</p>
                        <div style="display:flex;flex-direction:column;gap:0.7rem;margin-top:0.8rem;">
                            <?php foreach (['Accreditation readiness monitoring', 'Document evidence mapping', 'Stakeholder feedback analysis', 'Continuous improvement reporting'] as $priority): ?>
                                <div style="display:flex;gap:0.65rem;align-items:flex-start;color:#334155;font-size:0.9rem;font-weight:750;line-height:1.45;">
                                    <span style="width:22px;height:22px;border-radius:50%;background:#eff6ff;color:var(--dash-blue);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                    </span>
                                    <?= htmlspecialchars($priority) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="dash-section">
            <div class="dash-section-pad">
                <p class="dash-kicker">Institutional Direction</p>
                <h2 class="dash-title">Vision, Mission, Goals, Core Values, and Quality Policy Statement</h2>
                <div class="dash-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-top: 1rem;">
                    <div class="dash-card"><p class="dash-kicker">Vision</p><p class="dash-copy">A recognized institution for inclusive, culturally responsive, and sustainable higher education.</p></div>
                    <div class="dash-card"><p class="dash-kicker">Mission</p><p class="dash-copy">Northern Bukidnon State College advances excellence in teaching, innovative research, and impactful community service through strategic partnerships, and inclusive and equitable education, empowering transformative development in Bukidnon and Region X.</p></div>
                    <div class="dash-card"><p class="dash-kicker">Institutional Goals</p><div style="display:flex;flex-direction:column;gap:0.55rem;">
                        <?php foreach (['Effective Governance and Efficient Resource Management', 'Transformative Excellence in Student-Centered Innovation', 'Vibrant Research Culture and Inclusive Extension Programs'] as $goal): ?>
                            <p class="dash-copy" style="display:flex;gap:0.45rem;"><span style="color:var(--dash-blue);font-weight:950;">-</span><?= htmlspecialchars($goal) ?></p>
                        <?php endforeach; ?>
                    </div></div>
                </div>
                <div class="dash-grid dash-policy-grid" style="margin-top: 1rem;">
                    <div class="dash-card">
                        <p class="dash-kicker">Core Values</p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.6rem;">
                            <?php foreach (['Responsibility', 'Adaptability', 'Inclusivity', 'Sustainability', 'Excellence'] as $value): ?>
                                <div style="background:#f8fafc;border:1px solid var(--dash-border);border-radius:8px;padding:0.75rem;font-weight:900;color:var(--dash-ink);"><?= htmlspecialchars($value) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="dash-card" style="border-left: 5px solid var(--dash-blue);">
                        <p class="dash-kicker">Quality Policy Statement</p>
                        <p class="dash-copy">At Northern Bukidnon State College, we commit to provide gender-responsive and high-quality standards of educational programs and service delivery that fosters diversity, equity, and inclusivity and cultivates the holistic development of lifelong learners to meet the evolving needs of the 21st century workforce.</p>
                        <p class="dash-copy" style="margin-top:0.75rem;">We commit to full adherence to applicable statutory and regulatory requirements and continuous improvement in all our administrative and academic operational processes to satisfy our clients, stakeholders, and the community.</p>
                        <p class="dash-copy" style="margin-top:0.75rem;">We further commit that this policy is communicated, understood, implemented, maintained, and reviewed periodically to ensure client satisfaction and quality services are at their best.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="dash-section">
            <div class="dash-section-pad">
                <p class="dash-kicker">Modules</p>
                <h2 class="dash-title">System Workspaces</h2>
                <div class="dash-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-top: 1rem;">
                    <?php foreach ($modules as $module): ?>
                        <a href="<?= htmlspecialchars($module['href']) ?>" class="dash-card module-card">
                            <div style="display:flex;gap:0.9rem;align-items:flex-start;">
                                <div class="dash-icon" style="background:<?= htmlspecialchars($module['bg']) ?>;color:<?= htmlspecialchars($module['color']) ?>;">
                                    <?php if ($module['icon'] === 'activity'): ?><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                                    <?php elseif ($module['icon'] === 'check'): ?><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                    <?php elseif ($module['icon'] === 'map'): ?><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                                    <?php else: ?><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php endif; ?>
                                </div>
                                <div>
                                    <h3 style="margin:0 0 0.4rem;color:var(--dash-ink);font-size:1rem;font-weight:950;"><?= htmlspecialchars($module['title']) ?></h3>
                                    <p class="dash-copy" style="font-size:0.86rem;"><?= htmlspecialchars($module['desc']) ?></p>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:1rem;">
                    <p class="dash-kicker">Active Accreditation Deadlines</p>
                    <?php if (empty($active_accreditation_deadlines)): ?>
                        <div class="dash-card"><p class="dash-copy">No active accreditation deadlines right now.</p></div>
                    <?php else: ?>
                        <div class="dash-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
                            <?php foreach ($active_accreditation_deadlines as $deadline): ?>
                                <?php
                                    $deadlineDate = new DateTimeImmutable($deadline['deadline']);
                                    $daysUntil = (int)$today->diff($deadlineDate)->format('%r%a');
                                    $statusColor = $daysUntil < 0 ? '#b91c1c' : ($daysUntil === 0 ? '#92400e' : '#166534');
                                    $statusBg = $daysUntil < 0 ? '#fee2e2' : ($daysUntil === 0 ? '#fef3c7' : '#dcfce7');
                                    $statusLabel = $daysUntil < 0 ? 'Overdue' : ($daysUntil === 0 ? 'Due today' : $daysUntil . ' days left');
                                ?>
                                <a href="feed.php?action=accreditation&accreditation_id=<?= (int)$deadline['accreditation_id'] ?>" class="dash-card module-card" style="display:block;text-decoration:none;">
                                    <div style="display:flex;justify-content:space-between;gap:0.8rem;align-items:flex-start;">
                                        <div>
                                            <div style="color:var(--dash-blue);font-size:0.75rem;font-weight:950;"><?= htmlspecialchars($deadline['code']) ?></div>
                                            <div style="color:var(--dash-ink);font-weight:900;line-height:1.35;margin-top:0.25rem;"><?= htmlspecialchars($deadline['name']) ?></div>
                                            <div style="color:var(--dash-muted);font-size:0.82rem;font-weight:750;margin-top:0.55rem;"><?= $deadlineDate->format('F j, Y') ?></div>
                                        </div>
                                        <span style="background:<?= $statusBg ?>;color:<?= $statusColor ?>;border-radius:999px;padding:0.35rem 0.6rem;font-size:0.72rem;font-weight:950;white-space:nowrap;"><?= htmlspecialchars($statusLabel) ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="dash-section">
            <div class="dash-section-pad">
                <p class="dash-kicker">Organizational Chart</p>
                <h2 class="dash-title">Quality Assurance Office Structure</h2>
                <div style="margin-top:1.2rem;background:#f8fafc;border:1px solid var(--dash-border);border-radius:8px;padding:1.2rem;overflow-x:auto;">
                    <div style="display:grid;gap:0;justify-items:center;min-width:720px;">
                        <div style="font-size:0.72rem;font-weight:950;color:var(--dash-muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.45rem;">Level 1</div>
                        <div class="org-node" style="max-width:430px;width:100%;background:#001c57;color:white;border-color:#001c57;border-top:4px solid var(--dash-gold);">
                            <strong style="color:white;">Christie Jean Villanueva Ganiera, EdD, CESE</strong>
                            <span style="color:#dbeafe;">College President</span>
                        </div>

                        <div style="width:2px;height:28px;background:#b7c5dc;"></div>
                        <div style="font-size:0.72rem;font-weight:950;color:var(--dash-muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.45rem;">Level 2</div>
                        <div class="org-node" style="max-width:380px;width:100%;border-top:4px solid var(--dash-gold);">
                            <strong>Daniel S. Lerongan, PhD</strong>
                            <span>Quality Assurance Office Unit Head</span>
                        </div>

                        <div style="width:2px;height:28px;background:#b7c5dc;"></div>
                        <div style="font-size:0.72rem;font-weight:950;color:var(--dash-muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.45rem;">Level 3</div>
                        <div class="org-node" style="max-width:340px;width:100%;">
                            <strong>May Rose E. Madrid</strong>
                            <span>Administrative Officer II</span>
                        </div>

                        <div style="width:2px;height:28px;background:#b7c5dc;"></div>
                        <div style="width:66%;height:2px;background:#b7c5dc;"></div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.85rem;width:100%;margin-top:0;">
                            <?php foreach ([
                                ['name' => 'Michelle Darlyne B. Ricarte', 'role' => 'Administrative Aide VI'],
                                ['name' => 'Mia Marisol M. Magpulong, LPT', 'role' => 'Administrative Aide VI'],
                                ['name' => 'Fretzel Vann L. Ayo-on, LPT', 'role' => 'Administrative Assistant'],
                            ] as $member): ?>
                                <div style="display:grid;justify-items:center;">
                                    <div style="width:2px;height:22px;background:#b7c5dc;"></div>
                                    <div class="org-node" style="width:100%;">
                                        <strong><?= htmlspecialchars($member['name']) ?></strong>
                                        <span><?= htmlspecialchars($member['role']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size:0.72rem;font-weight:950;color:var(--dash-muted);text-transform:uppercase;letter-spacing:0.08em;margin-top:0.75rem;">Level 4</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="dash-section">
            <div class="dash-section-pad">
                <p class="dash-kicker">History of the Office</p>
                <h2 class="dash-title">Building a Culture of Quality</h2>
                <div class="dash-history-grid">
                    <div style="border-radius:8px;overflow:hidden;border:1px solid var(--dash-border);min-height:260px;background:#e2e8f0;">
                        <img src="../assets/img/nbsc_campus.jpg" alt="NBSC campus" style="width:100%;height:100%;object-fit:cover;display:block;">
                    </div>
                    <div class="dash-card" style="display:flex;flex-direction:column;gap:0.85rem;">
                        <p class="dash-copy">The Quality Assurance Office supports Northern Bukidnon State College in sustaining a disciplined, evidence-based approach to institutional improvement. Its work centers on accreditation preparation, document traceability, standards monitoring, and feedback-driven enhancement of academic and administrative services.</p>
                        <p class="dash-copy">As institutional requirements and stakeholder expectations continue to grow, the office serves as a coordination point for offices, programs, and units that contribute evidence of compliance and improvement. This dashboard reflects that work by bringing monitoring, documentation, evaluation, and reporting into one accessible system.</p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0.7rem;margin-top:auto;">
                            <div style="background:#f8fafc;border:1px solid var(--dash-border);border-radius:8px;padding:0.85rem;"><strong style="display:block;color:var(--dash-ink);">Evidence</strong><span style="color:var(--dash-muted);font-size:0.83rem;">Documents and proofs are easier to trace.</span></div>
                            <div style="background:#f8fafc;border:1px solid var(--dash-border);border-radius:8px;padding:0.85rem;"><strong style="display:block;color:var(--dash-ink);">Evaluation</strong><span style="color:var(--dash-muted);font-size:0.83rem;">Activities are reviewed with usable feedback.</span></div>
                            <div style="background:#f8fafc;border:1px solid var(--dash-border);border-radius:8px;padding:0.85rem;"><strong style="display:block;color:var(--dash-ink);">Improvement</strong><span style="color:var(--dash-muted);font-size:0.83rem;">Quality work stays visible and actionable.</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="dash-section">
            <div class="dash-section-pad">
                <p class="dash-kicker">Recent Activity</p>
                <h2 class="dash-title">System Log Feed</h2>
                <div class="dash-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-top: 1rem;">
                    <?php if (empty($recent_activities)): ?>
                        <div class="dash-card"><p class="dash-copy">No recent system activity yet.</p></div>
                    <?php else: ?>
                        <?php foreach ($recent_activities as $ua): ?>
                            <div class="dash-card">
                                <div style="font-weight:950;color:var(--dash-ink);font-size:0.9rem;"><?= htmlspecialchars(trim(($ua['fname'] ?? '') . ' ' . ($ua['lname'] ?? ''))) ?></div>
                                <p class="dash-copy" style="font-size:0.84rem;margin-top:0.35rem;"><?= htmlspecialchars(safe_trim_text($ua['activity_description'] ?? '', 72)) ?></p>
                                <div style="margin-top:0.7rem;color:#94a3b8;font-size:0.75rem;font-weight:850;"><?= date('M d, Y h:i A', strtotime($ua['activity_time'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</main>

<?php if ($needsProfileCompletion): ?>
    <!-- Profile Completion Modal -->
    <div id="profileModal" class="modal-overlay">
        <div class="modal-content">
            <h2 style="color: var(--accent-blue); margin-bottom: 0.5rem; text-align: center;">Complete Your Profile</h2>
            <p style="color: var(--text-secondary); margin-bottom: 2rem; text-align: center;">Please provide your
                demographic and geographic information.</p>

            <form action="../api/auth.php?action=complete_profile" method="POST" style="text-align: left;">
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="birthdate"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Birthdate
                            *</label>
                        <input type="date" id="birthdate" name="birthdate" value="<?= htmlspecialchars($userProfile['birthdate'] ?? '') ?>" required class="form-control">
                    </div>
                    <div style="flex: 1;">
                        <label for="gender"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Gender
                            *</label>
                        <select id="gender" name="gender" required class="form-control">
                            <option value="">Select Gender</option>
                            <option value="Male" <?= ($userProfile['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= ($userProfile['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            <option value="Others" <?= ($userProfile['gender'] ?? '') === 'Others' ? 'selected' : '' ?>>Others</option>
                        </select>
                    </div>
                </div>

                <!-- Philippine Address Selectors -->
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="province"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Province
                            *</label>
                        <select id="province" name="province" required data-selected="<?= htmlspecialchars($userProfile['province'] ?? '') ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select Province...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="city"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">City
                            / Municipality *</label>
                        <select id="city" name="city" required disabled data-selected="<?= htmlspecialchars($userProfile['city'] ?? '') ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select City...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="barangay"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Barangay
                            *</label>
                        <select id="barangay" name="barangay" required disabled data-selected="<?= htmlspecialchars($userProfile['barangay'] ?? '') ?>"
                            style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; background-color: white;">
                            <option value="">Select Barangay...</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label for="address"
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Street
                        / Building Name *</label>
                    <textarea id="address" name="address" rows="2" required placeholder="123 Example Street, Apt 4B..."
                        style="width: 100%; padding: 0.8rem; border: 1px solid var(--border-color); border-radius: 4px; font-family: inherit; font-size: 1rem; outline: none; transition: border-color 0.3s, box-shadow 0.3s; resize: vertical;"
                        onfocus="this.style.borderColor='var(--accent-blue)'; this.style.boxShadow='0 0 0 3px rgba(0,28,87,0.1)';"
                        onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';"><?= htmlspecialchars($userProfile['address'] ?? '') ?></textarea>
                </div>

                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="contact_number"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Contact
                            Number *</label>
                        <input type="tel" id="contact_number" maxlength="11" name="contact_number" 
                            value="<?= !empty($userProfile['contact_number']) ? htmlspecialchars($userProfile['contact_number']) : '09' ?>" required
                            class="form-control">
                    </div>
                </div>
                <div style="display: flex; gap: 1rem; margin-bottom: 1rem;">
                    <div style="flex: 1;">
                        <label for="division_id"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Division
                            *</label>
                        <select id="division_id" name="division_id" required class="form-control" data-selected="<?= htmlspecialchars($userProfile['division_id'] ?? '') ?>">
                            <option value="">Select Division...</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="office_id"
                            style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Office
                            *</label>
                        <select id="office_id" name="office_id" required disabled class="form-control" data-selected="<?= htmlspecialchars($userProfile['office_id'] ?? '') ?>">
                            <option value="">Select Office...</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 2rem;">
                    <label for="position"
                        style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--text-primary); font-size: 0.95rem;">Position
                        *</label>
                    <input type="text" id="position" name="position" value="<?= htmlspecialchars($userProfile['position'] ?? '') ?>" required class="form-control">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1.05rem;">Save
                    Profile</button>
            </form>
        </div>
    </div>


<?php endif; ?>
