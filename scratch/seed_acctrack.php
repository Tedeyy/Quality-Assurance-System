<?php
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();

try {
    // 1. Insert an Accreditation
    $stmt = $db->prepare("INSERT INTO accreditations (acronym, name, description, deadline, status, scope) 
                          VALUES ('ISO 9001:2015', 'Quality Management System', 'International standard for quality management systems.', '2026-12-31', 'In Progress', 'Division')");
    $stmt->execute();
    $acc_id = $db->lastInsertId();

    // 2. Insert Categories
    $stmt = $db->prepare("INSERT INTO accreditation_categories (accreditation_id, name) VALUES (:acc_id, :name)");
    
    $stmt->execute(['acc_id' => $acc_id, 'name' => 'Context of the Organization']);
    $cat1_id = $db->lastInsertId();
    
    $stmt->execute(['acc_id' => $acc_id, 'name' => 'Leadership']);
    $cat2_id = $db->lastInsertId();

    // 3. Insert Requirements
    $stmt = $db->prepare("INSERT INTO accreditation_requirement (category_id, name) VALUES (:cat_id, :name)");
    
    $stmt->execute(['cat_id' => $cat1_id, 'name' => 'Understanding the organization and its context']);
    $stmt->execute(['cat_id' => $cat1_id, 'name' => 'Understanding the needs and expectations of interested parties']);
    
    $stmt->execute(['cat_id' => $cat2_id, 'name' => 'Leadership and commitment']);
    $stmt->execute(['cat_id' => $cat2_id, 'name' => 'Policy']);

    echo "Mock data inserted successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
