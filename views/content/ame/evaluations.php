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
        'colors' => $pie_colors,
        'total' => array_sum($counts),
        'background' => pie_background($counts, $pie_colors)
    ];
}

function build_distribution_chart(string $field, string $title, string $description, array $source_responses, array $palette): array
{
    $counts = [];

    foreach ($source_responses as $response) {
        $value = trim((string)($response[$field] ?? ''));
        $label = $value !== '' ? $value : 'No answer';
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }

    if (empty($counts)) {
        $counts = ['No answer' => 0];
    }

    arsort($counts);
    $colors = [];
    $index = 0;
    foreach (array_keys($counts) as $label) {
        $colors[$label] = $palette[$index % count($palette)];
        $index++;
    }

    return [
        'column' => $field,
        'title' => $title,
        'description' => $description,
        'counts' => $counts,
        'colors' => $colors,
        'total' => array_sum($counts),
        'background' => pie_background($counts, $colors)
    ];
}

function build_profile_group(array $source_responses, array $palette): array
{
    return [
        'title' => 'Profile',
        'description' => 'Respondent demographic distribution by age, gender, and unit.',
        'charts' => [
            build_distribution_chart('age', 'Age', 'Respondent age distribution.', $source_responses, $palette),
            build_distribution_chart('gender', 'Gender', 'Respondent gender distribution.', $source_responses, $palette),
            build_distribution_chart('unit', 'Unit / Office', 'Respondent unit, office, or division distribution.', $source_responses, $palette)
        ]
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
$profile_pie_palette = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#be185d', '#475569'];

$overall_pie_background = pie_background($category_counts, $pie_colors);
$global_profile_group = build_profile_group($responses, $profile_pie_palette);
$global_rating_groups = build_rating_groups($rating_columns, $responses, $facilitators, $pie_colors);

$other_stats = null;
$speaker_ratings = [];
$organizer_ratings = [];
$evaluation = [];

// Get the evaluation_id
$eval_id = $activity['evaluation_id'] ?? null;
if ($eval_id) {
    // We already have some evaluation fields in $activity, let's fetch the full evaluation record
    $eval_stmt = $db->prepare("SELECT e.*, s.* FROM activity_evaluation e LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id WHERE e.evaluation_id = :id");
    $eval_stmt->execute([':id' => $eval_id]);
    $evaluation = $eval_stmt->fetch(PDO::FETCH_ASSOC);

    if ($evaluation) {
        $other_stmt = $db->prepare("SELECT * FROM activity_statistics_others WHERE evaluation_id = :id");
        $other_stmt->execute([':id' => $eval_id]);
        $other_stats = $other_stmt->fetch(PDO::FETCH_ASSOC);

        $speaker_stmt = $db->prepare("SELECT r.*, s.name FROM activity_speaker_rating r JOIN speakers s ON r.speaker_id = s.speaker_id WHERE r.evaluation_id = :id");
        $speaker_stmt->execute([':id' => $eval_id]);
        $speaker_ratings = $speaker_stmt->fetchAll(PDO::FETCH_ASSOC);

        $organizer_stmt = $db->prepare("SELECT r.*, o.name FROM activity_organizer_rating r JOIN organizers o ON r.organizer_id = o.organizer_id WHERE r.evaluation_id = :id");
        $organizer_stmt->execute([':id' => $eval_id]);
        $organizer_ratings = $organizer_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
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
    .detail-score {
        text-align: right;
    }
    .detail-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
    .profile-card {
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-color: #dbe4f0;
        grid-column: 1 / -1;
        padding: 1rem;
    }
    .profile-card span,
    .feedback-card span {
        color: var(--accent-blue);
    }
    .profile-meta-grid {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin-top: 0.75rem;
    }
    .profile-meta-item {
        background: #ffffff;
        border: 1px solid #eef2f7;
        border-radius: 8px;
        padding: 0.75rem;
    }
    .profile-meta-item small {
        color: #64748b;
        display: block;
        font-size: 0.68rem;
        font-weight: 900;
        letter-spacing: 0.05em;
        margin-bottom: 0.25rem;
        text-transform: uppercase;
    }
    .profile-meta-item strong {
        color: #0f172a;
        display: block;
        font-size: 0.9rem;
        line-height: 1.35;
    }
    .individual-rating-groups {
        grid-column: 1 / -1;
        margin: 0.25rem 0;
    }
    .individual-rating-groups .rating-section {
        border-top-color: #e2e8f0;
    }
    .feedback-card {
        background: #ffffff;
        border-color: #dbe4f0;
        min-height: 150px;
        padding: 1rem;
    }
    .feedback-card div {
        color: #334155;
        font-size: 0.95rem;
        line-height: 1.6;
        white-space: pre-wrap;
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
        .detail-score {
            text-align: left;
        }
        .detail-grid,
        .profile-meta-grid {
            grid-template-columns: 1fr;
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
                <button type="button" class="response-tab" data-tab="analytics" onclick="switchResponseTab('analytics')">Analytics</button>
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

                    <section class="rating-section">
                        <div class="rating-section-head">
                            <h3><?= htmlspecialchars($global_profile_group['title']) ?></h3>
                            <p><?= htmlspecialchars($global_profile_group['description']) ?></p>
                        </div>
                        <div class="rating-chart-grid">
                            <?php foreach ($global_profile_group['charts'] as $chart): ?>
                                <article class="rating-chart-card">
                                    <div class="pie-chart" style="--pie-bg: <?= htmlspecialchars($chart['background']) ?>;" data-total="<?= (int)$chart['total'] ?>" aria-label="<?= htmlspecialchars($chart['title']) ?> distribution"></div>
                                    <div>
                                        <h4><?= htmlspecialchars($chart['title']) ?></h4>
                                        <p class="rating-question-desc"><?= htmlspecialchars($chart['description']) ?></p>
                                        <div class="legend-grid">
                                            <?php foreach ($chart['counts'] as $label => $count): ?>
                                                <div class="legend-item <?= $count === 0 ? 'is-zero' : '' ?>">
                                                    <span class="legend-label"><span class="legend-dot" style="background: <?= $chart['colors'][$label] ?? '#94a3b8' ?>"></span><?= htmlspecialchars($label) ?></span>
                                                    <span><?= $count ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

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
                                                            <span class="legend-label"><span class="legend-dot" style="background: <?= $chart['colors'][$label] ?? '#94a3b8' ?>"></span><?= htmlspecialchars($label) ?></span>
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
                                        <div class="detail-score">
                                            <span class="rating-pill" style="background: <?= $rating_color ?>;"><?= number_format((float)$response['_average'], 1) ?>%</span>
                                            <div class="respondent-muted" style="margin-top: 5px;"><?= htmlspecialchars($label) ?></div>
                                        </div>
                                    </div>

                                    <div class="detail-grid">
                                        <div class="detail-field profile-card">
                                            <span>Profile</span>
                                            <div class="profile-meta-grid">
                                                <div class="profile-meta-item">
                                                    <small>Unit / Office</small>
                                                    <strong><?= htmlspecialchars($response['unit'] ?? 'Unit not provided') ?></strong>
                                                </div>
                                                <div class="profile-meta-item">
                                                    <small>Gender</small>
                                                    <strong><?= htmlspecialchars($response['gender'] ?? 'Gender N/A') ?></strong>
                                                </div>
                                                <div class="profile-meta-item">
                                                    <small>Age</small>
                                                    <strong><?= htmlspecialchars($response['age'] ?? 'N/A') ?></strong>
                                                </div>
                                                <div class="profile-meta-item">
                                                    <small>Contact</small>
                                                    <strong><?= htmlspecialchars($response['contact'] ?? 'No contact') ?></strong>
                                                </div>
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
                                                                                <span class="legend-label"><span class="legend-dot" style="background: <?= $chart['colors'][$rating_text] ?? '#94a3b8' ?>"></span><?= htmlspecialchars($rating_text) ?></span>
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

                                        <div class="detail-field feedback-card">
                                            <span>Best Topics / Insights</span>
                                            <div><?= htmlspecialchars($response['best_topics'] ?? 'No answer') ?></div>
                                        </div>
                                        <div class="detail-field feedback-card">
                                            <span>Suggested Improvements</span>
                                            <div><?= htmlspecialchars($response['improvements'] ?? 'No answer') ?></div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            
                <div id="analyticsPanel" class="response-panel">
                    <div class="pane-header">
                        <div>
                            <h2>Analytics & Interpretation</h2>
                            <div class="respondent-muted">Detailed performance statistics and qualitative analysis.</div>
                        </div>
                        <div style="position: relative;">
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