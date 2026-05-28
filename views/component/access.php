<?php

function qa_current_user_can_access_all_modules(PDO $db): bool
{
    static $can_access_all = null;

    if ($can_access_all !== null) {
        return $can_access_all;
    }

    $can_access_all = false;
    $user_id = $_SESSION['user_id'] ?? null;

    if (!$user_id) {
        return false;
    }

    try {
        $stmt = $db->prepare("
            SELECT d.name AS division_name, o.name AS office_name
            FROM users u
            LEFT JOIN divisions d ON u.division_id = d.division_id
            LEFT JOIN divisions_offices o ON u.office_id = o.office_id
            WHERE u.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $user_id]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $division_name = strtolower((string)($profile['division_name'] ?? ''));
        $office_name = strtolower((string)($profile['office_name'] ?? ''));

        $can_access_all = (
            strpos($division_name, 'office of the college president') !== false
            && strpos($office_name, 'quality assurance') !== false
        );
    } catch (Throwable $e) {
        error_log('Access profile query failed: ' . $e->getMessage());
    }

    return $can_access_all;
}

