<?php
/**
 * Utility functions for logging user actions and logins
 */

function logLogin($db, $user_id) {
    try {
        $stmt = $db->prepare("INSERT INTO user_login (user_id) VALUES (:user_id)");
        $stmt->execute(['user_id' => $user_id]);
    } catch (PDOException $e) {
        error_log("Failed to log login: " . $e->getMessage());
    }
}

function logActivity($db, $user_id, $description) {
    try {
        $stmt = $db->prepare("INSERT INTO user_activity (user_id, activity_description) VALUES (:user_id, :description)");
        $stmt->execute([
            'user_id' => $user_id,
            'description' => $description
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
?>
