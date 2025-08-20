<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

$category_id = (int) ($_POST['category_id'] ?? 0);

if (!$category_id) {
    // Log the failure
    log_action('LOCK_SEEDINGS', 'FAILURE', 'Attempted to lock seedings with a missing category ID.');
    die("Missing category ID.");
}

try {
    // Update the database
    $stmt = $pdo->prepare("UPDATE category_format SET is_locked = TRUE WHERE category_id = ?");
    $stmt->execute([$category_id]);

    // Log the successful action
    // Fetching the category name makes the log more descriptive
    $catStmt = $pdo->prepare("SELECT category_name FROM category WHERE id = ?");
    $catStmt->execute([$category_id]);
    $category_name = $catStmt->fetchColumn();

    $log_details = "Locked seedings for category '{$category_name}' (ID: {$category_id}).";
    log_action('LOCK_SEEDINGS', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    // Log any database errors
    $log_details = "Database error trying to lock seedings for category ID {$category_id}. Error: " . $e->getMessage();
    log_action('LOCK_SEEDINGS', 'FAILURE', $log_details);
    die("A database error occurred.");
}


header("Location: category_details.php?category_id=" . $category_id);
exit;