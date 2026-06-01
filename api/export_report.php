<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Shuchkin\SimpleXLSXGen;

$db = (new Database())->getConnection();

$type = $_GET['type'] ?? 'all';
$office_id = $_GET['office_id'] ?? null;
$month = $_GET['month'] ?? null;
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

function export_report_fail(string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function is_valid_export_date(?string $date): bool {
    if (!$date) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

$query = "SELECT a.*, o.name as office_name, e.*, s.*,
                 GROUP_CONCAT(sdg.title SEPARATOR ', ') as sdg_titles
          FROM activities a 
          LEFT JOIN divisions_offices o ON a.requesting_office_id = o.office_id
          LEFT JOIN activity_evaluation e ON a.activity_id = e.activity_id
          LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id
          LEFT JOIN activity_sdgs asg ON a.activity_id = asg.activity_id
          LEFT JOIN sdgs sdg ON asg.sdg_id = sdg.sdg_id
          WHERE 1=1";

$params = [];
$where = [];

if (strpos($type, 'office') !== false && !$office_id) {
    export_report_fail('Please select a requesting office before exporting this report.');
}

if ($office_id && strpos($type, 'office') !== false) {
    $where[] = "a.requesting_office_id = :oid";
    $params['oid'] = $office_id;
}

if (strpos($type, 'month') !== false && $month) {
    $where[] = "DATE_FORMAT(a.eventdate, '%M %Y') = :month";
    $params['month'] = $month;
} elseif (strpos($type, 'range') !== false) {
    if (!is_valid_export_date($start_date) || !is_valid_export_date($end_date)) {
        export_report_fail('Please select a valid start and end date before exporting this report.');
    }

    if ($start_date > $end_date) {
        export_report_fail('Start date cannot be later than end date.');
    }

    $where[] = "DATE(a.eventdate) BETWEEN :start AND :end";
    $params['start'] = $start_date;
    $params['end'] = $end_date;
}

if (!empty($where)) {
    $query = str_replace("WHERE 1=1", "WHERE " . implode(" AND ", $where), $query);
}

$query .= " GROUP BY a.activity_id ORDER BY a.eventdate DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

function percent_to_float($val): float {
    if ($val === null || $val === '') {
        return 0.0;
    }

    return (float) str_replace('%', '', (string)$val);
}

function build_rank_map(array $data, string $field): array {
    $ranked = [];
    foreach ($data as $row) {
        $score = percent_to_float($row[$field] ?? null);
        if ($score <= 0) {
            continue;
        }

        $ranked[] = [
            'activity_id' => (int)$row['activity_id'],
            'score' => $score,
        ];
    }

    usort($ranked, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $rankMap = [];
    foreach ($ranked as $index => $row) {
        $rankMap[$row['activity_id']] = '#' . ($index + 1);
    }

    return $rankMap;
}

$participationRanks = build_rank_map($data, 'response_rate');
$performanceRanks = build_rank_map($data, 'overall_average');

$rows = [
    [
        'Activity Title', 'SDG(s) Addressed', 'Request Email Link', 'Email Link', 'Requesting Office/Unit', 
        'Date', 'Venue', 'Speaker/s or Organizer\'s Office/Unit', 'Target Participants', 'AME Form Link', 
        'Number of Participants', 'Number of Respondents', 'Response Rate (%)', 'Participation Rank', 'Overall Service Rating',
        'Weighted Average (OSR)', 'Presenter Effectiveness /Organizer Rating', 'Weighted Average (PE/OOR)', 
        'Program and Methodology', 'Weighted Average (PAM)', 'Program/Activity Management/ Logistics and Support Services', 
        'Weighted Average (P/AM)', 'Overall Experience', 'Weighted Average (OE)', 'Overall Average', 'Performance Rank',
        'Complaints', 'Suggestions for Improvement', 'Published Options', 'Deadline (20 Working Days)', 
        'Date Released', 'Status', 'Justification Letter (If applicable)'
    ]
];

function ensurePercent($val) {
    if ($val === null || $val === '') return '0.00%';
    $val = trim($val);
    if (strpos($val, '%') !== false) return $val;
    if (is_numeric($val)) {
        // If it was already a decimal (e.g. 0.923), convert to percent (92.3%)
        if ((float)$val <= 1.0 && (float)$val > 0) {
            return number_format((float)$val * 100, 2) . '%';
        }
        return number_format((float)$val, 2) . '%';
    }
    return $val . '%';
}

foreach ($data as $r) {
    // Determine status (Simplified: if released after deadline, then Delayed)
    $status = $r['evaluation_status'];
    if ($r['date_released'] && $r['deadline']) {
        $status = (strtotime($r['date_released']) > strtotime($r['deadline'])) ? 'Delayed' : 'On Time';
    }

    $rows[] = [
        $r['title'],
        $r['sdg_titles'],
        $r['request_email_link'],
        $r['email_link'],
        $r['office_name'],
        $r['eventdate'],
        $r['eventvenue'],
        $r['speaker'] . ($r['organizer'] ? " / " . $r['organizer'] : ""),
        $r['target_participants'],
        $r['ame_form_link'],
        $r['number_of_participants'],
        $r['number_of_respondents'],
        ensurePercent($r['response_rate']),
        $participationRanks[(int)$r['activity_id']] ?? 'No data',
        $r['osr'],
        ensurePercent($r['osr_wa']),
        $r['peor'],
        ensurePercent($r['peor_wa']),
        $r['pam'],
        ensurePercent($r['pam_wa']),
        $r['pamlss'],
        ensurePercent($r['pamlss_wa']),
        $r['oe'],
        ensurePercent($r['oe_wa']),
        ensurePercent($r['overall_average']),
        $performanceRanks[(int)$r['activity_id']] ?? 'Pending',
        $r['complaints'],
        $r['suggestions_for_improvement'],
        $r['published_options'],
        $r['deadline'],
        $r['date_released'],
        $status,
        $r['justification_letter']
    ];
}

$filename = "AME_Report_" . date('Y-m-d_His') . ".xlsx";

SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
exit;
