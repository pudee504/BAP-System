<?php
// FILENAME: delete_category.php
// DESCRIPTION: Deletes a category from a league. Requires category_id and league_id via GET.

require 'db.php';
session_start();
require_once 'logger.php'; // For logging admin actions

// --- 1. Validate Input ---
$category_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$league_id = filter_var($_GET['league_id'] ?? null, FILTER_VALIDATE_INT);

if (!$category_id || !$league_id) {
    log_action('DELETE_CATEGORY', 'FAILURE', 'Attempted to delete a category with an invalid ID.');
    die("Invalid category or league.");
}

try {
    // --- 2. Get Category Name (for logging) ---
    // Fetch the name *before* deleting it.
    $stmt = $pdo->prepare("SELECT category_name FROM category WHERE id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch();

    if (!$category) {
        log_action('DELETE_CATEGORY', 'FAILURE', "Attempted to delete a non-existent category (ID: {$category_id}).");
        die("Category not found.");
    }
    $category_name = $category['category_name']; // Store name for logging

    // --- 3. Delete the Category ---
    $deleteStmt = $pdo->prepare("DELETE FROM category WHERE id = ?");
    $deleteStmt->execute([$category_id]);

    // --- 4. Log Success ---
    $log_details = "Deleted category '{$category_name}' (ID: {$category_id}) from league ID {$league_id}.";
    log_action('DELETE_CATEGORY', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    // --- 5. Handle Errors ---
    // Log database errors, especially foreign key constraint violations.
    $log_details = "Database error trying to delete category ID {$category_id}. Error: " . $e->getMessage();
    log_action('DELETE_CATEGORY', 'FAILURE', $log_details);
    // Provide a user-friendly error message.
    die("A database error occurred. The category could not be deleted, possibly because it still contains teams or other related data.");
}

// --- 6. Redirect Back ---
// Go back to the league details page.
header("Location: league_details.php?id=$league_id");
exit;
?>