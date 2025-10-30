<?php
// FILENAME: lock_bracket_double_elim.php
// DESCRIPTION: Locks the bracket seeding (sets `playoff_seeding_locked` flag to 1) for a double-elimination category.

require_once 'db.php'; // Database connection
session_start();
require_once 'logger.php'; // Logging functionality

// Get category ID from the POST request.
$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Error: Missing category_id.");
}

try {
    // Update the category's `playoff_seeding_locked` status to 1 (locked).
    $stmt = $pdo->prepare("UPDATE category SET playoff_seeding_locked = 1 WHERE id = ?");
    $stmt->execute([$category_id]);
    
    // Log the successful action.
    log_action('LOCK_BRACKET', 'SUCCESS', "Bracket locked for category ID: $category_id.");
    
    // Redirect back to the standings tab, indicating success.
    header("Location: category_details.php?category_id=$category_id&tab=standings&lock_success=true");
    exit;

} catch (Exception $e) {
    // Log any errors that occur during the database update.
    log_action('LOCK_BRACKET', 'FAILURE', "Error: " . $e->getMessage());
    die("An error occurred while locking the bracket: " . $e->getMessage());
}
?>