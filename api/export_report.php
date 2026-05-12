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

$query = "SELECT a.*, o.name as office_name, s.overall_average, s.osr_wa, s.peor_wa, s.pam_wa, s.pamlss_wa, s.oe_wa 
          FROM activities a 
          LEFT JOIN divisions_offices o ON a.requesting_office_id = o.office_id
          LEFT JOIN activity_evaluation e ON a.activity_id = e.activity_id
          LEFT JOIN activity_statistics s ON e.evaluation_id = s.evaluation_id
          WHERE 1=1";

$params = [];

if ($office_id) {
    $query .= " AND a.requesting_office_id = :oid";
    $params['oid'] = $office_id;
}

if ($type === 'office_month' && $month) {
    $query .= " AND DATE_FORMAT(a.eventdate, '%F %Y') = :month";
    $params['month'] = $month;
} elseif ($type === 'office_range' && $start_date && $end_date) {
    $query .= " AND a.eventdate BETWEEN :start AND :end";
    $params['start'] = $start_date;
    $params['end'] = $end_date;
}

$query .= " ORDER BY a.eventdate DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows = [
    ['Activity Code', 'Title', 'Date', 'Status', 'Office', 'Overall Rating', 'OSR (WA)', 'PEOR (WA)', 'PAM (WA)', 'PAMLSS (WA)', 'OE (WA)']
];

foreach ($data as $r) {
    $rows[] = [
        $r['activity_code'],
        $r['title'],
        $r['eventdate'],
        $r['eventstatus'],
        $r['office_name'],
        $r['overall_average'] ?: 'N/A',
        $r['osr_wa'] ?: 'N/A',
        $r['peor_wa'] ?: 'N/A',
        $r['pam_wa'] ?: 'N/A',
        $r['pamlss_wa'] ?: 'N/A',
        $r['oe_wa'] ?: 'N/A'
    ];
}

$filename = "AME_Report_" . date('Y-m-d_His') . ".xlsx";

SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
exit;
