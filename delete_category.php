<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

$category_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$league_id = filter_var($_GET['league_id'] ?? null, FILTER_VALIDATE_INT);

if (!$category_id || !$league_id) {
    log_action('DELETE_CATEGORY', 'FAILURE', 'Attempted to delete a category with an invalid ID.');
    die("Invalid category or league.");
}

try {
    // --- STEP 1: Fetch the category name BEFORE deleting ---
    $stmt = $pdo->prepare("SELECT category_name FROM category WHERE id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch();

    if (!$category) {
        log_action('DELETE_CATEGORY', 'FAILURE', "Attempted to delete a non-existent category (ID: {$category_id}).");
        die("Category not found.");
    }
    
    // Store the name for the log message.
    $category_name = $category['category_name'];

    // --- STEP 2: Delete the category ---
    // Note: This will fail if the category still has teams and you have foreign key constraints.
    $deleteStmt = $pdo->prepare("DELETE FROM category WHERE id = ?");
    $deleteStmt->execute([$category_id]);

    // --- STEP 3: Log the successful deletion ---
    $log_details = "Deleted category '{$category_name}' (ID: {$category_id}) from league ID {$league_id}.";
    log_action('DELETE_CATEGORY', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    // Catch database errors, like trying to delete a category that still contains teams.
    $log_details = "Database error trying to delete category ID {$category_id}. Error: " . $e->getMessage();
    log_action('DELETE_CATEGORY', 'FAILURE', $log_details);
    die("A database error occurred. The category could not be deleted, possibly because it still contains teams.");
}

header("Location: league_details.php?id=$league_id");
exit;