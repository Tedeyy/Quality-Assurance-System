<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/MailService.php';

$database = new Database();
$db = $database->getConnection();

$activity_id = $_POST['activity_id'] ?? null;
if (!$activity_id) die("Missing Activity ID");

// 1. Fetch Evaluation ID
$stmt = $db->prepare("SELECT evaluation_id FROM activity_evaluation WHERE activity_id = :aid");
$stmt->execute(['aid' => $activity_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);
$evaluation_id = $eval['evaluation_id'];

// 2. Save Raw Response to Dynamic Activity Table in Dedicated DB
require_once __DIR__ . '/../config/responses_database.php';
$resp_db_class = new ResponsesDatabase();
$rdb = $resp_db_class->getConnection();

if ($rdb) {
    $table_name = "activity_" . $activity_id;

    // Check if email already exists
    $check = $rdb->prepare("SELECT id FROM $table_name WHERE email = :email");
    $check->execute(['email' => $_POST['email']]);
    if ($check->fetch()) {
        echo "<div style='text-align:center; padding: 100px; font-family: sans-serif;'>
                <h1 style='color: #dc2626;'>Already Submitted</h1>
                <p>You have already sent a response for this activity evaluation.</p>
                <a href='javascript:history.back()' style='color: #2563eb; text-decoration: none;'>Go Back</a>
              </div>";
        exit;
    }

    $cols = []; $vals = []; $params = [];
    $fields = ['email', 'fullname', 'age', 'gender', 'contact', 'unit', 'osr', 'best_topics', 'improvements', 'oe'];
    $rating_map = [1 => 0, 2 => 25, 3 => 50, 4 => 75, 5 => 100];

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $cols[] = $f;
            $vals[] = ":$f";
            $val = $_POST[$f];
            if (in_array($f, ['osr', 'oe']) && isset($rating_map[$val])) {
                $val = $rating_map[$val];
            }
            $params[$f] = $val;
        }
    }
    foreach ($_POST as $key => $val) {
        if (strpos($key, 'fac_') === 0 || strpos($key, 'prog_') === 0 || strpos($key, 'log_') === 0) {
            $cols[] = $key;
            $vals[] = ":$key";
            if (isset($rating_map[$val])) {
                $val = $rating_map[$val];
            }
            $params[$key] = $val;
        }
    }
    $insertQuery = "INSERT INTO $table_name (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
    $rdb->prepare($insertQuery)->execute($params);

    // 3. Recalculate Statistics
    $resp_stmt = $rdb->query("SELECT * FROM $table_name");
    $all_responses = $resp_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_respondents = count($all_responses);

    $stats_map = [
        'osr' => ['fields' => ['osr']],
        'peor' => ['fields' => ['prog_0', 'prog_1', 'prog_2', 'prog_3']],
        'pam' => ['fields' => []], // Will be filled dynamically
        'pamlss' => ['fields' => ['log_0', 'log_1', 'log_2']],
        'oe' => ['fields' => ['oe']]
    ];

    // Find all facilitator fields in the first response
    if (!empty($all_responses)) {
        foreach (array_keys($all_responses[0]) as $key) {
            if (strpos($key, 'fac_') === 0) {
                $stats_map['pam']['fields'][] = $key;
            }
        }
    }

    $labels = [5 => 'Excellent', 4 => 'Very Satisfactory', 3 => 'Satisfactory', 2 => 'Fair', 1 => 'Poor'];
    $final_stats = [];

    foreach ($stats_map as $category => $config) {
        $counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $cat_total_responses = 0;

        foreach ($all_responses as $resp) {
            foreach ($config['fields'] as $f) {
                $val = $resp[$f];
                $rating = 0;
                if ($val == 100) $rating = 5;
                elseif ($val == 75) $rating = 4;
                elseif ($val == 50) $rating = 3;
                elseif ($val == 25) $rating = 2;
                elseif ($val == 0) $rating = 1;

                if ($rating > 0) {
                    $counts[$rating]++;
                    $cat_total_responses++;
                }
            }
        }

        // Calculate WA
        $weighted_score = 0;
        foreach ($counts as $r => $c) $weighted_score += ($c * $r);
        $max_score = $cat_total_responses * 5;
        $wa = $max_score > 0 ? ($weighted_score / $max_score) * 100 : 0;
        $final_stats[$category . '_wa'] = number_format($wa, 2) . "%";

        // Calculate Distribution String
        $dist_parts = [];
        foreach ($labels as $r => $l) {
            if ($counts[$r] > 0) {
                $perc = ($counts[$r] / $cat_total_responses) * 100;
                $dist_parts[] = number_format($perc, 1) . "% ($l)";
            }
        }
        $final_stats[$category] = implode('; ', $dist_parts);
    }

    // Overall Average
    $wa_sum = 0;
    foreach (['osr_wa', 'peor_wa', 'pam_wa', 'pamlss_wa', 'oe_wa'] as $k) {
        $wa_sum += (float) str_replace('%', '', $final_stats[$k]);
    }
    $final_stats['overall_average'] = number_format($wa_sum / 5, 2) . "%";

    // 4. Update Main DB
    $update_query = "UPDATE activity_statistics SET 
        osr = :osr, osr_wa = :osr_wa,
        peor = :peor, peor_wa = :peor_wa,
        pam = :pam, pam_wa = :pam_wa,
        pamlss = :pamlss, pamlss_wa = :pamlss_wa,
        oe = :oe, oe_wa = :oe_wa,
        overall_average = :overall_average
        WHERE evaluation_id = :eid";
    $final_stats_db = $final_stats;
    $final_stats_db['eid'] = $evaluation_id;
    $db->prepare($update_query)->execute($final_stats_db);

    // Update individual facilitator ratings
    $fac_stmt = $db->prepare("SELECT af.role, af.person_id FROM activity_facilitators af WHERE af.activity_id = :aid");
    $fac_stmt->execute(['aid' => $activity_id]);
    $facilitators = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($facilitators as $i => $fac) {
        $f_counts = ['eff' => 0, 'mot' => 0, 'atf' => 0];
        $f_totals = ['eff' => 0, 'mot' => 0, 'atf' => 0];
        
        foreach ($all_responses as $resp) {
            foreach (['eff', 'mot', 'atf'] as $m) {
                $val = $resp["fac_{$i}_{$m}"];
                $rating = 0;
                if ($val == 100) $rating = 5;
                elseif ($val == 75) $rating = 4;
                elseif ($val == 50) $rating = 3;
                elseif ($val == 25) $rating = 2;
                elseif ($val == 0) $rating = 1;
                
                if ($rating > 0) {
                    $f_totals[$m] += $rating;
                    $f_counts[$m]++;
                }
            }
        }

        $metrics_avg = [];
        foreach (['eff', 'mot', 'atf'] as $m) {
            $metrics_avg[$m] = $f_counts[$m] > 0 ? ($f_totals[$m] / $f_counts[$m]) : 0;
        }

        if ($fac['role'] === 'speaker') {
            $db->prepare("UPDATE activity_speaker_rating SET eff = :eff, mot = :mot, atf = :atf WHERE evaluation_id = :eid AND speaker_id = :sid")
               ->execute(['eff' => $metrics_avg['eff'], 'mot' => $metrics_avg['mot'], 'atf' => $metrics_avg['atf'], 'eid' => $evaluation_id, 'sid' => $fac['person_id']]);
        } else {
            $db->prepare("UPDATE activity_organizer_rating SET eff = :eff, mot = :mot, atf = :atf WHERE evaluation_id = :eid AND organizer_id = :oid")
               ->execute(['eff' => $metrics_avg['eff'], 'mot' => $metrics_avg['mot'], 'atf' => $metrics_avg['atf'], 'eid' => $evaluation_id, 'oid' => $fac['person_id']]);
        }
    }

    // Update Respondent count and Response Rate
    $act_stmt = $db->prepare("SELECT number_of_participants FROM activities WHERE activity_id = :aid");
    $act_stmt->execute(['aid' => $activity_id]);
    $act_data = $act_stmt->fetch(PDO::FETCH_ASSOC);
    $target = (int)($act_data['number_of_participants'] ?? 0);
    $rate = $target > 0 ? ($total_respondents / $target) * 100 : 0;

    $db->prepare("UPDATE activity_evaluation SET number_of_respondents = :num, response_rate = :rate WHERE evaluation_id = :eid")
       ->execute(['num' => $total_respondents, 'rate' => $rate, 'eid' => $evaluation_id]);

    // Update Demographic Distributions
    $demographics = ['gender' => [], 'age' => [], 'unit' => []];
    foreach ($all_responses as $resp) {
        foreach (array_keys($demographics) as $key) {
            $val = trim($resp[$key] ?? '');
            if ($val) {
                $demographics[$key][$val] = ($demographics[$key][$val] ?? 0) + 1;
            }
        }
    }

    $dist_results = [];
    foreach ($demographics as $key => $counts) {
        $total = array_sum($counts);
        $parts = [];
        if ($total > 0) {
            arsort($counts); // Show highest first
            foreach ($counts as $val => $c) {
                $p = ($c / $total) * 100;
                $parts[] = number_format($p, 1) . "% (" . $val . ")";
            }
        }
        $dist_results[$key . '_distribution'] = implode('; ', $parts);
    }

    $db->prepare("UPDATE activity_statistics_others SET 
        gender_distribution = :gender_distribution, 
        age_distribution = :age_distribution, 
        unit_distribution = :unit_distribution 
        WHERE evaluation_id = :eid")
       ->execute(array_merge($dist_results, ['eid' => $evaluation_id]));

    // --- SEND EMAIL ACKNOWLEDGMENT ---
    $act_stmt = $db->prepare("SELECT title FROM activities WHERE activity_id = :aid");
    $act_stmt->execute(['aid' => $activity_id]);
    $act_title = $act_stmt->fetchColumn();

    $summary = [];
    $label_map = [1 => 'Poor', 2 => 'Fair', 3 => 'Satisfactory', 4 => 'Very Satisfactory', 5 => 'Excellent'];
    
    // Core ratings
    if (isset($_POST['osr'])) $summary['Overall Service Rating'] = $label_map[$_POST['osr']] ?? $_POST['osr'];
    if (isset($_POST['oe'])) $summary['Overall Experience'] = $label_map[$_POST['oe']] ?? $_POST['oe'];
    
    // Feedback
    if (isset($_POST['best_topics'])) $summary['Best Topics/Insights'] = htmlspecialchars($_POST['best_topics']);
    if (isset($_POST['improvements'])) $summary['Suggested Improvements'] = htmlspecialchars($_POST['improvements']);

    MailService::sendAcknowledgment($_POST['email'], $_POST['fullname'] ?: 'Valued Participant', $act_title, $summary);
}

echo "<script>
        localStorage.removeItem('eval_data_" . $activity_id . "');
        localStorage.removeItem('eval_step_" . $activity_id . "');
      </script>
      <div style='text-align:center; padding: 100px; font-family: sans-serif;'>
        <h1 style='color: #059669;'>Evaluation Submitted Successfully!</h1>
        <p>Thank you for your feedback. Your response has been recorded and analytics have been updated.</p>
        <a href='javascript:window.close()' style='color: #2563eb; text-decoration: none;'>Close Window</a>
      </div>";
