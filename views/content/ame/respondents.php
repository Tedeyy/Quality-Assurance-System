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

$fac_stmt = $db->prepare(
    "SELECT af.role, af.person_id,
            COALESCE(sp.name, og.name) AS name
     FROM activity_facilitators af
     LEFT JOIN speakers sp ON af.role = 'speaker' AND af.person_id = sp.speaker_id
     LEFT JOIN organizers og ON af.role = 'organizer' AND af.person_id = og.organizer_id
     WHERE af.activity_id = :id
     ORDER BY af.role, af.af_id"
);
$fac_stmt->execute(['id' => $activity_id]);
$facilitators = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($facilitators)) {
    if (!empty($activity['speaker'])) {
        foreach (explode(',', $activity['speaker']) as $name) {
            $name = trim($name);
            if ($name) $facilitators[] = ['role' => 'speaker', 'name' => $name];
        }
    }
    if (!empty($activity['organizer'])) {
        foreach (explode(',', $activity['organizer']) as $name) {
            $name = trim($name);
            if ($name) $facilitators[] = ['role' => 'organizer', 'name' => $name];
        }
    }
}

$responses = [];
$table_exists = false;
$rating_columns = [];
$all_columns = [];
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
        $quoted_table = "`" . str_replace("`", "``", $table_name) . "`";
        $responses = $response_db->query("SELECT * FROM $quoted_table ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($responses)) {
            $all_columns = array_keys($responses[0]);
            $excluded = ['id', 'email', 'fullname', 'age', 'gender', 'contact', 'unit', 'best_topics', 'improvements', 'created_at', 'submitted_at'];
            foreach ($all_columns as $column) {
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

function column_rating_label($value): string
{
    if (!is_numeric($value)) return 'No Rating';

    $score = (float)$value;
    if ($score >= 90) return 'Excellent';
    if ($score >= 75) return 'Very Satisfactory';
    if ($score >= 50) return 'Satisfactory';
    if ($score >= 25) return 'Fair';
    return 'Poor';
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
        'oe' => 'Overall Experience',
        'eff' => 'Effectiveness',
        'mot' => 'Mastery of Topic',
        'atf' => 'Ability to Facilitate'
    ];

    if (isset($labels[$column])) return $labels[$column];

    if (preg_match('/^fac_(\d+)_(eff|mot|atf)$/', $column, $matches)) {
        return 'Facilitator ' . ((int)$matches[1] + 1) . ' - ' . $labels[$matches[2]];
    }

    if (preg_match('/^prog_(\d+)$/', $column, $matches)) {
        return 'Program Evaluation ' . ((int)$matches[1] + 1);
    }

    if (preg_match('/^log_(\d+)$/', $column, $matches)) {
        return 'Logistics Support ' . ((int)$matches[1] + 1);
    }

    $name = preg_replace('/^(fac|prog|log)_/i', '', $column);
    $name = str_replace('_', ' ', $name);
    return ucwords($name);
}

function base_rating_counts(): array
{
    return [
        'Excellent' => 0,
        'Very Satisfactory' => 0,
        'Satisfactory' => 0,
        'Fair' => 0,
        'Poor' => 0
    ];
}

function pie_background(array $counts, array $colors): string
{
    $total = array_sum($counts);
    if ($total <= 0) return '#e2e8f0 0deg 360deg';

    $segments = [];
    $cursor = 0;
    foreach ($counts as $label => $count) {
        $degrees = ($count / $total) * 360;
        if ($degrees > 0) {
            $segments[] = $colors[$label] . ' ' . $cursor . 'deg ' . ($cursor + $degrees) . 'deg';
            $cursor += $degrees;
        }
    }

    return $segments ? implode(', ', $segments) : '#e2e8f0 0deg 360deg';
}

function make_rating_chart(string $column, string $title, string $description, array $source_responses, array $pie_colors): array
{
    $counts = base_rating_counts();

    foreach ($source_responses as $response) {
        $label = column_rating_label($response[$column] ?? null);
        if (isset($counts[$label])) {
            $counts[$label]++;
        }
    }

    return [
        'column' => $column,
        'title' => $title,
        'description' => $description,
        'counts' => $counts,
        'total' => array_sum($counts),
        'background' => pie_background($counts, $pie_colors)
    ];
}

function add_chart_if_present(array &$groups, string $group_key, string $group_title, string $group_description, string $column, string $title, string $description, array $rating_columns, array $source_responses, array $pie_colors): void
{
    if (!in_array($column, $rating_columns, true)) return;

    if (!isset($groups[$group_key])) {
        $groups[$group_key] = [
            'title' => $group_title,
            'description' => $group_description,
            'charts' => []
        ];
    }

    $groups[$group_key]['charts'][] = make_rating_chart($column, $title, $description, $source_responses, $pie_colors);
}

function build_rating_groups(array $rating_columns, array $source_responses, array $facilitators, array $pie_colors): array
{
    $groups = [];
    $fac_metrics = [
        'eff' => ['label' => 'Effectiveness', 'desc' => 'How well the facilitator achieved the intended goals of the session.'],
        'mot' => ['label' => 'Mastery of Topic', 'desc' => 'The depth of knowledge and command shown over the subject matter.'],
        'atf' => ['label' => 'Ability to Facilitate', 'desc' => 'The facilitator\'s skill in managing the discussion and audience participation.']
    ];
    $program_questions = [
        ['label' => 'Program Flow', 'desc' => 'Smoothness and logical transition between the various parts of the activity.'],
        ['label' => 'Program Contents', 'desc' => 'Quality, relevance, and substance of the materials and topics presented.'],
        ['label' => 'Relevance to Objective', 'desc' => 'How well the program aligned with the stated goals and expectations.'],
        ['label' => 'Future Applicability', 'desc' => 'The likelihood of using the knowledge or skills gained in your future work.']
    ];
    $logistics_questions = [
        ['label' => 'Secretariat Service', 'desc' => 'Efficiency, courtesy, and responsiveness of the registration and support staff.'],
        ['label' => 'Logistics/Venue', 'desc' => 'Comfort, cleanliness, accessibility, and adequacy of the facilities provided.'],
        ['label' => 'Timing/Scheduling', 'desc' => 'Punctuality, appropriate time allocation, and overall duration of the session.']
    ];

    add_chart_if_present(
        $groups,
        'overall',
        'Overall Ratings',
        'General activity and experience ratings.',
        'osr',
        'General success of the totality of the activity execution',
        'Overall assessment of how the activity was conducted from start to finish.',
        $rating_columns,
        $source_responses,
        $pie_colors
    );
    add_chart_if_present(
        $groups,
        'overall',
        'Overall Ratings',
        'General activity and experience ratings.',
        'oe',
        'Overall Experience',
        'Summarize your total experience with this activity.',
        $rating_columns,
        $source_responses,
        $pie_colors
    );

    foreach ($facilitators as $index => $facilitator) {
        $name = $facilitator['name'] ?? ('Facilitator ' . ($index + 1));
        $role = !empty($facilitator['role']) ? ucfirst($facilitator['role']) : 'Facilitator';
        foreach ($fac_metrics as $metric => $question) {
            add_chart_if_present(
                $groups,
                'fac_' . $index,
                'Facilitator Ratings: ' . $name,
                $role . ' performance ratings.',
                "fac_{$index}_{$metric}",
                $question['label'],
                $question['desc'],
                $rating_columns,
                $source_responses,
                $pie_colors
            );
        }
    }

    foreach ($program_questions as $index => $question) {
        add_chart_if_present(
            $groups,
            'program',
            'Program Evaluation',
            'Ratings for program flow, contents, relevance, and future applicability.',
            'prog_' . $index,
            $question['label'],
            $question['desc'],
            $rating_columns,
            $source_responses,
            $pie_colors
        );
    }

    foreach ($logistics_questions as $index => $question) {
        add_chart_if_present(
            $groups,
            'logistics',
            'Logistics',
            'Ratings for secretariat service, venue, and scheduling.',
            'log_' . $index,
            $question['label'],
            $question['desc'],
            $rating_columns,
            $source_responses,
            $pie_colors
        );
    }

    return array_values($groups);
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

$overall_pie_background = pie_background($category_counts, $pie_colors);
$global_rating_groups = build_rating_groups($rating_columns, $responses, $facilitators, $pie_colors);
?>

<style>
    .respondents-page {
        background: #f8fafc;
        min-height: calc(100vh - 100px);
        padding: 2rem 5% 4rem;
    }
    .respondents-shell {
        margin: 0 auto;
        max-width: 1240px;
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
        background: conic-gradient(var(--pie-bg));
        border-radius: 50%;
        display: flex;
        flex: 0 0 auto;
        height: var(--pie-size, 210px);
        justify-content: center;
        margin-bottom: 1.2rem;
        width: var(--pie-size, 210px);
    }
    .pie-chart::after {
        background: white;
        border-radius: 50%;
        color: var(--accent-blue);
        content: attr(data-total);
        display: grid;
        font-size: var(--pie-number-size, 2.1rem);
        font-weight: 900;
        height: calc(var(--pie-size, 210px) * 0.56);
        place-items: center;
        width: calc(var(--pie-size, 210px) * 0.56);
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
        gap: 1rem;
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
    .respondent-muted {
        color: #64748b;
        font-size: 0.82rem;
    }
    .response-pane {
        background: #ffffff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        box-shadow: 0 14px 35px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }
    .response-tabs {
        align-items: center;
        background: #f8fafc;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        gap: 0.5rem;
        padding: 0.75rem;
    }
    .response-tab {
        background: transparent;
        border: 1px solid transparent;
        border-radius: 8px;
        color: #64748b;
        cursor: pointer;
        font-size: 0.88rem;
        font-weight: 850;
        padding: 0.65rem 1rem;
    }
    .response-tab.active {
        background: #ffffff;
        border-color: var(--border-color);
        color: var(--accent-blue);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
    }
    .response-panel {
        display: none;
        padding: 1.5rem;
    }
    .response-panel.active {
        display: block;
    }
    .pane-header {
        align-items: center;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-bottom: 1.35rem;
    }
    .pane-header h2 {
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
    .rating-chart-grid {
        display: grid;
        gap: 1.25rem;
        grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    }
    .rating-section {
        border-top: 1px solid #eef2f7;
        padding-top: 1.25rem;
    }
    .rating-section:first-of-type {
        border-top: 0;
        padding-top: 0;
    }
    .rating-section + .rating-section {
        margin-top: 1.5rem;
    }
    .rating-section-head {
        margin-bottom: 0.9rem;
    }
    .rating-section-head h3 {
        color: #0f172a;
        font-size: 1rem;
        font-weight: 900;
        margin: 0 0 0.25rem;
    }
    .rating-section-head p {
        color: #64748b;
        font-size: 0.84rem;
        margin: 0;
    }
    .rating-chart-card {
        align-items: center;
        background: #ffffff;
        border: 0;
        border-radius: 8px;
        display: grid;
        gap: 1.35rem;
        grid-template-columns: 100px minmax(0, 1fr);
        min-height: 150px;
        padding: 1rem 0.9rem;
    }
    .rating-chart-card h4 {
        color: #0f172a;
        font-size: 1rem;
        font-weight: 900;
        line-height: 1.3;
        margin: 0 0 0.35rem;
    }
    .rating-question-desc {
        color: #64748b;
        font-size: 0.76rem;
        line-height: 1.35;
        margin: 0 0 0.8rem;
    }
    .rating-chart-card .pie-chart {
        --pie-size: 92px;
        --pie-number-size: 1.1rem;
        margin: 0;
    }
    .rating-chart-card .legend-grid {
        gap: 0.42rem;
        grid-template-columns: 1fr;
    }
    .rating-chart-card .legend-item {
        background: transparent;
        border: 0;
        border-radius: 0;
        color: #475569;
        font-size: 0.8rem;
        font-weight: 850;
        gap: 0.75rem;
        min-height: 18px;
        padding: 0;
    }
    .rating-chart-card .legend-item.is-zero {
        color: #94a3b8;
    }
    .rating-chart-card .legend-item.is-zero .legend-dot {
        opacity: 0.45;
    }
    .rating-chart-card .legend-label {
        min-width: 0;
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
    .individual-layout {
        display: grid;
        gap: 1rem;
        grid-template-columns: minmax(260px, 0.38fr) minmax(0, 0.62fr);
    }
    .responder-list {
        border: 1px solid var(--border-color);
        border-radius: 10px;
        max-height: 650px;
        overflow: auto;
    }
    .responder-button {
        background: #ffffff;
        border: 0;
        border-bottom: 1px solid #eef2f7;
        cursor: pointer;
        display: block;
        padding: 0.95rem;
        text-align: left;
        width: 100%;
    }
    .responder-button:hover,
    .responder-button.active {
        background: #f8fafc;
    }
    .responder-name {
        color: #0f172a;
        font-weight: 850;
        margin-bottom: 0.25rem;
    }
    .responder-detail {
        border: 1px solid var(--border-color);
        border-radius: 10px;
        display: none;
        padding: 1.2rem;
    }
    .responder-detail.active {
        display: block;
    }
    .detail-head {
        align-items: flex-start;
        border-bottom: 1px solid #eef2f7;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
    }
    .detail-head h3 {
        color: #0f172a;
        font-size: 1.25rem;
        margin: 0 0 0.35rem;
    }
    .detail-grid {
        display: grid;
        gap: 0.8rem;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    }
    .detail-field {
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 8px;
        padding: 0.85rem;
    }
    .detail-field span {
        color: #64748b;
        display: block;
        font-size: 0.72rem;
        font-weight: 900;
        letter-spacing: 0.05em;
        margin-bottom: 0.35rem;
        text-transform: uppercase;
    }
    .detail-field strong,
    .detail-field div {
        color: #0f172a;
        font-size: 0.92rem;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }
    .individual-rating-groups {
        grid-column: 1 / -1;
        margin: 0.25rem 0;
    }
    .individual-rating-groups .rating-section {
        border-top-color: #e2e8f0;
    }
    .feedback-summary {
        border-top: 1px solid #eef2f7;
        margin-top: 1.5rem;
        padding-top: 1.25rem;
    }
    .feedback-summary h3 {
        color: #0f172a;
        font-size: 1rem;
        font-weight: 900;
        margin: 0 0 0.9rem;
    }
    .feedback-summary-table-wrap {
        border: 1px solid var(--border-color);
        border-radius: 10px;
        overflow: hidden;
    }
    .feedback-summary-table {
        border-collapse: collapse;
        width: 100%;
    }
    .feedback-summary-table th,
    .feedback-summary-table td {
        border-bottom: 1px solid #eef2f7;
        padding: 0.95rem;
        text-align: left;
        vertical-align: top;
        width: 50%;
    }
    .feedback-summary-table th {
        background: #f8fafc;
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 900;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .feedback-summary-table tr:last-child td {
        border-bottom: 0;
    }
    .feedback-summary-table td {
        color: #334155;
        font-size: 0.88rem;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }
    .empty-state {
        color: #64748b;
        padding: 3rem 1.5rem;
        text-align: center;
    }
    @media (max-width: 900px) {
        .respondents-hero,
        .response-stats,
        .individual-layout {
            grid-template-columns: 1fr;
        }
        .pane-header,
        .detail-head {
            align-items: stretch;
            flex-direction: column;
        }
        .respondent-search {
            max-width: none;
        }
    }
    @media (max-width: 560px) {
        .rating-chart-card {
            grid-template-columns: 1fr;
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
                    Review response analytics, rating distribution, and responder-level submissions for this activity evaluation.
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
                <div class="pie-chart" style="--pie-bg: <?= htmlspecialchars($overall_pie_background) ?>;" data-total="<?= $total_responses ?>" aria-label="Overall rating distribution pie chart"></div>
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

        <section class="response-pane">
            <div class="response-tabs" role="tablist" aria-label="Response views">
                <button type="button" class="response-tab active" data-tab="global" onclick="switchResponseTab('global')">Global</button>
                <button type="button" class="response-tab" data-tab="individual" onclick="switchResponseTab('individual')">Individual</button>
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
                <div id="globalPanel" class="response-panel active">
                    <div class="pane-header">
                        <div>
                            <h2>Global Responses</h2>
                            <div class="respondent-muted">All submitted records and distribution charts for every rated column.</div>
                        </div>
                    </div>

                    <?php if (!empty($global_rating_groups)): ?>
                        <?php foreach ($global_rating_groups as $group): ?>
                            <section class="rating-section">
                                <div class="rating-section-head">
                                    <h3><?= htmlspecialchars($group['title']) ?></h3>
                                    <p><?= htmlspecialchars($group['description']) ?></p>
                                </div>
                                <div class="rating-chart-grid">
                                    <?php foreach ($group['charts'] as $chart): ?>
                                        <article class="rating-chart-card">
                                            <div class="pie-chart" style="--pie-bg: <?= htmlspecialchars($chart['background']) ?>;" data-total="<?= (int)$chart['total'] ?>" aria-label="<?= htmlspecialchars($chart['title']) ?> distribution"></div>
                                            <div>
                                                <h4><?= htmlspecialchars($chart['title']) ?></h4>
                                                <p class="rating-question-desc"><?= htmlspecialchars($chart['description']) ?></p>
                                                <div class="legend-grid">
                                                    <?php foreach ($chart['counts'] as $label => $count): ?>
                                                        <div class="legend-item <?= $count === 0 ? 'is-zero' : '' ?>">
                                                            <span class="legend-label"><span class="legend-dot" style="background: <?= $pie_colors[$label] ?>"></span><?= $label ?></span>
                                                            <span><?= $count ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <strong>No rating fields found.</strong>
                            <p>The response table exists, but it does not contain rating columns for charting.</p>
                        </div>
                    <?php endif; ?>

                    <section class="feedback-summary">
                        <h3>Activity Feedback</h3>
                        <div class="feedback-summary-table-wrap">
                            <table class="feedback-summary-table">
                                <thead>
                                    <tr>
                                        <th>Most Liked</th>
                                        <th>Least Liked / Could Be Improved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($responses as $response): ?>
                                        <?php
                                            $most_liked = trim((string)($response['best_topics'] ?? ''));
                                            $least_liked = trim((string)($response['improvements'] ?? ''));
                                        ?>
                                        <?php if ($most_liked !== '' || $least_liked !== ''): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($most_liked !== '' ? $most_liked : 'No answer') ?></td>
                                                <td><?= htmlspecialchars($least_liked !== '' ? $least_liked : 'No answer') ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div id="individualPanel" class="response-panel">
                    <div class="pane-header">
                        <div>
                            <h2>Individual Responder</h2>
                            <div class="respondent-muted">Select a responder to inspect their profile, ratings, and text feedback.</div>
                        </div>
                        <input type="search" id="individualSearch" class="respondent-search" placeholder="Search responder..." oninput="filterIndividuals()">
                    </div>

                    <div class="individual-layout">
                        <div class="responder-list">
                            <?php foreach ($respondent_rows as $index => $response): ?>
                                <?php $search_blob = strtolower(implode(' ', array_map('strval', $response))); ?>
                                <button type="button" class="responder-button <?= $index === 0 ? 'active' : '' ?>" data-search="<?= htmlspecialchars($search_blob) ?>" data-responder="<?= $index ?>" onclick="selectResponder(<?= $index ?>)">
                                    <div class="responder-name"><?= htmlspecialchars($response['fullname'] ?? 'Unnamed respondent') ?></div>
                                    <div class="respondent-muted"><?= htmlspecialchars($response['email'] ?? 'No email') ?></div>
                                    <div class="respondent-muted"><?= htmlspecialchars($response['unit'] ?? 'Unit not provided') ?></div>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div>
                            <?php foreach ($respondent_rows as $index => $response): ?>
                                <?php
                                    $label = $response['_rating_label'];
                                    $rating_color = rating_color($label);
                                    $individual_rating_groups = build_rating_groups($rating_columns, [$response], $facilitators, $pie_colors);
                                ?>
                                <article class="responder-detail <?= $index === 0 ? 'active' : '' ?>" data-responder-detail="<?= $index ?>">
                                    <div class="detail-head">
                                        <div>
                                            <h3><?= htmlspecialchars($response['fullname'] ?? 'Unnamed respondent') ?></h3>
                                            <div class="respondent-muted"><?= htmlspecialchars($response['email'] ?? 'No email') ?></div>
                                        </div>
                                        <div>
                                            <span class="rating-pill" style="background: <?= $rating_color ?>;"><?= number_format((float)$response['_average'], 1) ?>%</span>
                                            <div class="respondent-muted" style="margin-top: 5px; text-align: right;"><?= htmlspecialchars($label) ?></div>
                                        </div>
                                    </div>

                                    <div class="detail-grid">
                                        <div class="detail-field">
                                            <span>Profile</span>
                                            <div>
                                                <?= htmlspecialchars($response['unit'] ?? 'Unit not provided') ?><br>
                                                <?= htmlspecialchars($response['gender'] ?? 'Gender N/A') ?>
                                                <?= !empty($response['age']) ? ', Age ' . htmlspecialchars($response['age']) : '' ?><br>
                                                <?= htmlspecialchars($response['contact'] ?? 'No contact') ?>
                                            </div>
                                        </div>

                                        <div class="individual-rating-groups">
                                            <?php foreach ($individual_rating_groups as $group): ?>
                                                <section class="rating-section">
                                                    <div class="rating-section-head">
                                                        <h3><?= htmlspecialchars($group['title']) ?></h3>
                                                        <p><?= htmlspecialchars($group['description']) ?></p>
                                                    </div>
                                                    <div class="rating-chart-grid">
                                                        <?php foreach ($group['charts'] as $chart): ?>
                                                            <article class="rating-chart-card">
                                                                <div class="pie-chart" style="--pie-bg: <?= htmlspecialchars($chart['background']) ?>;" data-total="<?= (int)$chart['total'] ?>" aria-label="<?= htmlspecialchars($chart['title']) ?> distribution"></div>
                                                                <div>
                                                                    <h4><?= htmlspecialchars($chart['title']) ?></h4>
                                                                    <p class="rating-question-desc"><?= htmlspecialchars($chart['description']) ?></p>
                                                                    <div class="legend-grid">
                                                                        <?php foreach ($chart['counts'] as $rating_text => $count): ?>
                                                                            <div class="legend-item <?= $count === 0 ? 'is-zero' : '' ?>">
                                                                                <span class="legend-label"><span class="legend-dot" style="background: <?= $pie_colors[$rating_text] ?>"></span><?= $rating_text ?></span>
                                                                                <span><?= $count ?></span>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            </article>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </section>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="detail-field">
                                            <span>Best Topics / Insights</span>
                                            <div><?= htmlspecialchars($response['best_topics'] ?? 'No answer') ?></div>
                                        </div>
                                        <div class="detail-field">
                                            <span>Suggested Improvements</span>
                                            <div><?= htmlspecialchars($response['improvements'] ?? 'No answer') ?></div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
    function switchResponseTab(tabName) {
        document.querySelectorAll('.response-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabName);
        });
        document.querySelectorAll('.response-panel').forEach(panel => {
            panel.classList.toggle('active', panel.id === tabName + 'Panel');
        });
    }

    function selectResponder(index) {
        document.querySelectorAll('.responder-button').forEach(button => {
            button.classList.toggle('active', button.dataset.responder === String(index));
        });
        document.querySelectorAll('.responder-detail').forEach(detail => {
            detail.classList.toggle('active', detail.dataset.responderDetail === String(index));
        });
    }

    function filterIndividuals() {
        const query = document.getElementById('individualSearch').value.toLowerCase();
        let firstVisible = null;

        document.querySelectorAll('.responder-button').forEach(button => {
            const visible = button.dataset.search.includes(query);
            button.style.display = visible ? '' : 'none';
            if (visible && firstVisible === null) {
                firstVisible = Number(button.dataset.responder);
            }
        });

        if (firstVisible !== null) {
            selectResponder(firstVisible);
        }
    }
</script>
