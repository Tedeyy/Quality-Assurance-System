<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/responses_database.php';

$db = (new Database())->getConnection();
$activity_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($activity_id <= 0) {
    header('Location: feed.php?action=activity');
    exit;
}

$stmt = $db->prepare("
    SELECT a.*, e.evaluation_id, e.number_of_respondents, e.response_rate, e.ame_form_link,
           s.overall_average
    FROM activities a
    LEFT JOIN activity_evaluation e ON a.activity_id = e.activity_id
    LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id
    WHERE a.activity_id = :id
");
$stmt->execute(['id' => $activity_id]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    header('Location: feed.php?action=activity');
    exit;
}

$responses = [];
$table_exists = false;
$rating_columns = [];
$category_counts = [
    'Excellent' => 0,
    'Very Satisfactory' => 0,
    'Satisfactory' => 0,
    'Fair' => 0,
    'Poor' => 0
];

$response_db = (new ResponsesDatabase())->getConnection();
$table_name = 'activity_' . $activity_id;

if ($response_db) {
    $check = $response_db->query("SHOW TABLES LIKE " . $response_db->quote($table_name));
    $table_exists = $check && $check->rowCount() > 0;

    if ($table_exists) {
        $responses = $response_db->query("SELECT * FROM `$table_name` ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($responses)) {
            $excluded = ['id', 'email', 'fullname', 'age', 'gender', 'contact', 'unit', 'best_topics', 'improvements', 'created_at', 'submitted_at'];
            foreach (array_keys($responses[0]) as $column) {
                if (!in_array($column, $excluded, true)) {
                    $has_numeric = false;
                    foreach ($responses as $response) {
                        if (isset($response[$column]) && is_numeric($response[$column])) {
                            $has_numeric = true;
                            break;
                        }
                    }
                    if ($has_numeric) {
                        $rating_columns[] = $column;
                    }
                }
            }
        }
    }
}

function respondent_average(array $response, array $rating_columns): float
{
    $sum = 0;
    $count = 0;

    foreach ($rating_columns as $column) {
        if (isset($response[$column]) && is_numeric($response[$column])) {
            $sum += (float)$response[$column];
            $count++;
        }
    }

    return $count > 0 ? round($sum / $count, 2) : 0.0;
}

function rating_label(float $score): string
{
    if ($score >= 90) return 'Excellent';
    if ($score >= 75) return 'Very Satisfactory';
    if ($score >= 50) return 'Satisfactory';
    if ($score >= 25) return 'Fair';
    return $score > 0 ? 'Poor' : 'No Rating';
}

function rating_color(string $label): string
{
    return [
        'Excellent' => '#16a34a',
        'Very Satisfactory' => '#2563eb',
        'Satisfactory' => '#d97706',
        'Fair' => '#ea580c',
        'Poor' => '#dc2626',
        'No Rating' => '#94a3b8'
    ][$label] ?? '#94a3b8';
}

function pretty_field_name(string $column): string
{
    $labels = [
        'osr' => 'Overall Service Rating',
        'oe' => 'Overall Experience'
    ];

    if (isset($labels[$column])) return $labels[$column];

    $name = preg_replace('/^(fac|prog|log)_/i', '', $column);
    $name = str_replace('_', ' ', $name);
    return ucwords($name);
}

$respondent_rows = [];
$total_score = 0;
$rated_count = 0;

foreach ($responses as $response) {
    $average = respondent_average($response, $rating_columns);
    $label = rating_label($average);
    if (isset($category_counts[$label])) {
        $category_counts[$label]++;
    }
    if ($average > 0) {
        $total_score += $average;
        $rated_count++;
    }
    $response['_average'] = $average;
    $response['_rating_label'] = $label;
    $respondent_rows[] = $response;
}

$total_responses = count($responses);
$average_score = $rated_count > 0 ? round($total_score / $rated_count, 2) : 0;
$target = (int)($activity['number_of_participants'] ?? 0);
$response_rate = $target > 0 ? round(($total_responses / $target) * 100, 2) : (float)($activity['response_rate'] ?? 0);

$pie_colors = [
    'Excellent' => '#16a34a',
    'Very Satisfactory' => '#2563eb',
    'Satisfactory' => '#d97706',
    'Fair' => '#ea580c',
    'Poor' => '#dc2626'
];
$pie_segments = [];
$cursor = 0;
foreach ($category_counts as $label => $count) {
    $degrees = $total_responses > 0 ? ($count / $total_responses) * 360 : 0;
    if ($degrees > 0) {
        $pie_segments[] = $pie_colors[$label] . ' ' . $cursor . 'deg ' . ($cursor + $degrees) . 'deg';
        $cursor += $degrees;
    }
}
$pie_background = $pie_segments ? implode(', ', $pie_segments) : '#e2e8f0 0deg 360deg';
?>

<style>
    .respondents-page {
        background: #f8fafc;
        min-height: calc(100vh - 100px);
        padding: 2rem 5% 4rem;
    }
    .respondents-shell {
        margin: 0 auto;
        max-width: 1200px;
    }
    .respondents-topbar {
        align-items: center;
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .respondents-back {
        align-items: center;
        color: var(--text-secondary);
        display: inline-flex;
        gap: 8px;
        font-size: 0.9rem;
        font-weight: 700;
        text-decoration: none;
    }
    .respondents-hero {
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        box-shadow: 0 14px 35px rgba(15, 23, 42, 0.06);
        display: grid;
        gap: 2rem;
        grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.8fr);
        margin-bottom: 1.5rem;
        padding: 2rem;
    }
    .respondents-kicker {
        color: var(--accent-gold);
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 900;
        letter-spacing: 0.12em;
        margin-bottom: 0.7rem;
        text-transform: uppercase;
    }
    .respondents-title {
        color: #0f172a;
        font-size: clamp(2rem, 4vw, 3rem);
        line-height: 1.08;
        margin: 0 0 0.85rem;
    }
    .respondents-subtitle {
        color: #64748b;
        font-size: 1rem;
        line-height: 1.6;
        margin: 0;
        max-width: 680px;
    }
    .response-stats {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(3, 1fr);
        margin: 1.5rem 0;
    }
    .response-stat {
        background: #f8fafc;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 1rem;
    }
    .response-stat span {
        color: #64748b;
        display: block;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .response-stat strong {
        color: var(--accent-blue);
        display: block;
        font-size: 1.8rem;
        line-height: 1;
        margin-top: 0.55rem;
    }
    .pie-card {
        align-items: center;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .pie-chart {
        align-items: center;
        background: conic-gradient(<?= $pie_background ?>);
        border-radius: 50%;
        display: flex;
        height: 210px;
        justify-content: center;
        margin-bottom: 1.2rem;
        width: 210px;
    }
    .pie-chart::after {
        background: white;
        border-radius: 50%;
        color: var(--accent-blue);
        content: '<?= $total_responses ?>';
        display: grid;
        font-size: 2.1rem;
        font-weight: 900;
        height: 118px;
        place-items: center;
        width: 118px;
    }
    .legend-grid {
        display: grid;
        gap: 0.5rem;
        width: 100%;
    }
    .legend-item {
        align-items: center;
        color: #475569;
        display: flex;
        font-size: 0.85rem;
        font-weight: 700;
        justify-content: space-between;
    }
    .legend-label {
        align-items: center;
        display: inline-flex;
        gap: 8px;
    }
    .legend-dot {
        border-radius: 999px;
        display: inline-block;
        height: 10px;
        width: 10px;
    }
    .respondent-panel {
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        box-shadow: 0 14px 35px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }
    .respondent-panel-header {
        align-items: center;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        padding: 1rem 1.25rem;
    }
    .respondent-panel-header h2 {
        color: var(--accent-blue);
        font-size: 1.1rem;
        margin: 0;
    }
    .respondent-search {
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 0.9rem;
        max-width: 320px;
        outline: none;
        padding: 0.7rem 0.9rem;
        width: 100%;
    }
    .respondents-table-wrap {
        overflow-x: auto;
    }
    .respondents-table {
        border-collapse: collapse;
        min-width: 900px;
        width: 100%;
    }
    .respondents-table th,
    .respondents-table td {
        border-bottom: 1px solid #eef2f7;
        padding: 1rem;
        text-align: left;
    }
    .respondents-table th {
        background: #f8fafc;
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 900;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .respondent-name {
        color: #0f172a;
        font-weight: 850;
    }
    .respondent-email,
    .respondent-muted {
        color: #64748b;
        font-size: 0.82rem;
    }
    .rating-pill {
        border-radius: 999px;
        color: white;
        display: inline-flex;
        font-size: 0.75rem;
        font-weight: 900;
        padding: 5px 10px;
        white-space: nowrap;
    }
    .empty-state {
        color: #64748b;
        padding: 3rem 1.5rem;
        text-align: center;
    }
    @media (max-width: 900px) {
        .respondents-hero,
        .response-stats {
            grid-template-columns: 1fr;
        }
        .respondent-panel-header {
            align-items: stretch;
            flex-direction: column;
        }
        .respondent-search {
            max-width: none;
        }
    }
</style>

<main class="respondents-page">
    <div class="respondents-shell">
        <div class="respondents-topbar">
            <a href="feed.php?action=view_activity&id=<?= (int)$activity_id ?>" class="respondents-back">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Activity
            </a>
            <?php if (!empty($activity['ame_form_link'])): ?>
                <a href="<?= htmlspecialchars($activity['ame_form_link']) ?>" target="_blank" class="btn btn-primary">Open Form</a>
            <?php endif; ?>
        </div>

        <section class="respondents-hero">
            <div>
                <span class="respondents-kicker">AME Respondents</span>
                <h1 class="respondents-title"><?= htmlspecialchars($activity['title']) ?></h1>
                <p class="respondents-subtitle">
                    Review response analytics, rating distribution, and individual submissions for this activity evaluation.
                </p>

                <div class="response-stats">
                    <div class="response-stat">
                        <span>Responses</span>
                        <strong><?= $total_responses ?></strong>
                    </div>
                    <div class="response-stat">
                        <span>Response Rate</span>
                        <strong><?= number_format($response_rate, 1) ?>%</strong>
                    </div>
                    <div class="response-stat">
                        <span>Average Rating</span>
                        <strong><?= number_format($average_score, 1) ?>%</strong>
                    </div>
                </div>

                <p class="respondent-muted">
                    Target participants: <?= $target > 0 ? number_format($target) : 'Not specified' ?>.
                    Event date: <?= !empty($activity['eventdate']) ? date('F d, Y', strtotime($activity['eventdate'])) : 'Not set' ?>.
                </p>
            </div>

            <div class="pie-card">
                <div class="pie-chart" aria-label="Rating distribution pie chart"></div>
                <div class="legend-grid">
                    <?php foreach ($category_counts as $label => $count): ?>
                        <div class="legend-item">
                            <span class="legend-label"><span class="legend-dot" style="background: <?= $pie_colors[$label] ?>"></span><?= $label ?></span>
                            <span><?= $count ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="respondent-panel">
            <div class="respondent-panel-header">
                <h2>Individual Responses</h2>
                <input type="search" id="respondentSearch" class="respondent-search" placeholder="Search name, email, unit, feedback..." oninput="filterRespondents()">
            </div>

            <?php if (!$table_exists): ?>
                <div class="empty-state">
                    <strong>No response table found yet.</strong>
                    <p>The AME form may not have been generated or no local response table exists for this activity.</p>
                </div>
            <?php elseif (empty($respondent_rows)): ?>
                <div class="empty-state">
                    <strong>No responses yet.</strong>
                    <p>Responses will appear here as soon as participants submit the evaluation form.</p>
                </div>
            <?php else: ?>
                <div class="respondents-table-wrap">
                    <table class="respondents-table">
                        <thead>
                            <tr>
                                <th>Respondent</th>
                                <th>Profile</th>
                                <th>Average Rating</th>
                                <th>Key Ratings</th>
                                <th>Feedback</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($respondent_rows as $response): ?>
                                <?php
                                    $label = $response['_rating_label'];
                                    $rating_color = rating_color($label);
                                    $rating_preview = array_slice($rating_columns, 0, 4);
                                    $search_blob = strtolower(implode(' ', array_map('strval', $response)));
                                ?>
                                <tr class="respondent-row" data-search="<?= htmlspecialchars($search_blob) ?>">
                                    <td>
                                        <div class="respondent-name"><?= htmlspecialchars($response['fullname'] ?? 'Unnamed respondent') ?></div>
                                        <div class="respondent-email"><?= htmlspecialchars($response['email'] ?? 'No email') ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($response['unit'] ?? 'Unit not provided') ?></div>
                                        <div class="respondent-muted">
                                            <?= htmlspecialchars($response['gender'] ?? 'Gender N/A') ?>
                                            <?= !empty($response['age']) ? ' · Age ' . htmlspecialchars($response['age']) : '' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="rating-pill" style="background: <?= $rating_color ?>;"><?= number_format((float)$response['_average'], 1) ?>%</span>
                                        <div class="respondent-muted" style="margin-top: 5px;"><?= htmlspecialchars($label) ?></div>
                                    </td>
                                    <td>
                                        <?php if (empty($rating_preview)): ?>
                                            <span class="respondent-muted">No rating fields</span>
                                        <?php else: ?>
                                            <div style="display: grid; gap: 5px;">
                                                <?php foreach ($rating_preview as $column): ?>
                                                    <div class="respondent-muted">
                                                        <strong><?= htmlspecialchars(pretty_field_name($column)) ?>:</strong>
                                                        <?= isset($response[$column]) && is_numeric($response[$column]) ? number_format((float)$response[$column], 0) . '%' : 'N/A' ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="max-width: 320px;">
                                            <div class="respondent-muted"><strong>Best topics:</strong> <?= htmlspecialchars($response['best_topics'] ?? 'No answer') ?></div>
                                            <div class="respondent-muted" style="margin-top: 6px;"><strong>Improvements:</strong> <?= htmlspecialchars($response['improvements'] ?? 'No answer') ?></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
    function filterRespondents() {
        const query = document.getElementById('respondentSearch').value.toLowerCase();
        document.querySelectorAll('.respondent-row').forEach(row => {
            row.style.display = row.dataset.search.includes(query) ? '' : 'none';
        });
    }
</script>
