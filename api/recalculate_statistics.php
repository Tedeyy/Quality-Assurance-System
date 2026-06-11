<?php
function normalizeStoredRating($val): int {
    if ($val === null || $val === '' || !is_numeric($val)) {
        return 0;
    }

    $numeric = (float)$val;
    if ($numeric >= 1 && $numeric <= 5) {
        return (int)round($numeric);
    }

    if ($numeric >= 90) return 5;
    if ($numeric >= 75) return 4;
    if ($numeric >= 50) return 3;
    if ($numeric >= 25) return 2;
    if ($numeric === 0.0) return 1;

    return 0;
}

function recalculateActivityStatistics(PDO $db, PDO $rdb, int $activity_id, int $evaluation_id, string $table_name) {
    $resp_stmt = $rdb->query("SELECT * FROM $table_name");
    $all_responses = $resp_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_respondents = count($all_responses);

    $stats_map = [
        'osr' => ['fields' => ['osr']],
        'peor' => ['fields' => ['prog_flow', 'prog_contents', 'prog_relevance']],
        'pam' => ['fields' => []], // Will be filled dynamically with facilitator fields
        'pamlss' => ['fields' => ['mgmt_facilitation', 'mgmt_venue', 'mgmt_time']],
        'oe' => ['fields' => ['feedback_overall']]
    ];

    // Find all facilitator fields in the first response
    if (!empty($all_responses)) {
        foreach (array_keys($all_responses[0]) as $key) {
            if (strpos($key, 'speaker_') === 0 || strpos($key, 'organizer_') === 0) {
                if (preg_match('/(effectiveness|mastery|facilitation|coordination|clarity|engagement)$/', $key)) {
                    $stats_map['pam']['fields'][] = $key;
                }
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
                $rating = normalizeStoredRating($resp[$f] ?? null);

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
    $fac_stmt = $db->prepare(
        "SELECT af.role, af.person_id, af.af_id
         FROM activity_facilitators af 
         WHERE af.activity_id = :aid
         ORDER BY af.role, af.af_id"
    );
    $fac_stmt->execute(['aid' => $activity_id]);
    $facilitators = $fac_stmt->fetchAll(PDO::FETCH_ASSOC);

    $speakerIdx = 0;
    $organizerIdx = 0;

    foreach ($facilitators as $fac) {
        if ($fac['role'] === 'speaker') {
            $metrics = ['effectiveness', 'mastery', 'facilitation'];
            $f_counts = array_fill_keys($metrics, 0);
            $f_totals = array_fill_keys($metrics, 0);
            
            foreach ($all_responses as $resp) {
                foreach ($metrics as $metric) {
                    $colName = "speaker_{$speakerIdx}_{$metric}";
                    $rating = normalizeStoredRating($resp[$colName] ?? null);
                    
                    if ($rating > 0) {
                        $f_totals[$metric] += $rating;
                        $f_counts[$metric]++;
                    }
                }
            }

            $metrics_avg = [];
            foreach ($metrics as $metric) {
                $metrics_avg[$metric] = $f_counts[$metric] > 0 ? ($f_totals[$metric] / $f_counts[$metric]) : 0;
            }

            $db->prepare("UPDATE activity_speaker_rating SET eff = :eff, mot = :mot, atf = :atf WHERE evaluation_id = :eid AND speaker_id = :sid")
               ->execute([
                   'eff' => $metrics_avg['effectiveness'],
                   'mot' => $metrics_avg['mastery'],
                   'atf' => $metrics_avg['facilitation'],
                   'eid' => $evaluation_id,
                   'sid' => $fac['person_id']
               ]);
            
            $speakerIdx++;
        } else {
            $metrics = ['coordination', 'clarity', 'engagement'];
            $f_counts = array_fill_keys($metrics, 0);
            $f_totals = array_fill_keys($metrics, 0);
            
            foreach ($all_responses as $resp) {
                foreach ($metrics as $metric) {
                    $colName = "organizer_{$organizerIdx}_{$metric}";
                    $rating = normalizeStoredRating($resp[$colName] ?? null);
                    
                    if ($rating > 0) {
                        $f_totals[$metric] += $rating;
                        $f_counts[$metric]++;
                    }
                }
            }

            $metrics_avg = [];
            foreach ($metrics as $metric) {
                $metrics_avg[$metric] = $f_counts[$metric] > 0 ? ($f_totals[$metric] / $f_counts[$metric]) : 0;
            }

            $db->prepare("UPDATE activity_organizer_rating SET eff = :eff, mot = :mot, atf = :atf WHERE evaluation_id = :eid AND organizer_id = :oid")
               ->execute([
                   'eff' => $metrics_avg['coordination'],
                   'mot' => $metrics_avg['clarity'],
                   'atf' => $metrics_avg['engagement'],
                   'eid' => $evaluation_id,
                   'oid' => $fac['person_id']
               ]);
            
            $organizerIdx++;
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
}
