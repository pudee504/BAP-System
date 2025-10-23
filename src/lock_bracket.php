<?php
session_start();
require_once 'db.php'; // Assumes db.php is in the same directory
require_once 'logger.php'; // Assumes logger.php is in the same directory

// --- 1. VALIDATE INPUT ---
$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    // If category_id is missing, we can't do anything.
    // Redirect back with an error message.
    header("Location: category_details.php?category_id=" . ($category_id ?? '') . "&tab=standings&error=missing_id");
    exit;
}

try {
    // --- 2. VERIFY ALL TEAMS ARE PRESENT ---
    // Before locking, we must verify that all team slots have been filled.
    $stmt = $pdo->prepare("
        SELECT cf.num_teams, COUNT(t.id) as team_count 
        FROM category_format cf 
        LEFT JOIN team t ON cf.category_id = t.category_id 
        WHERE cf.category_id = ? 
        GROUP BY cf.num_teams
    ");
    $stmt->execute([$category_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // If the number of registered teams is less than the required number, redirect with an error.
    if (!$result || $result['team_count'] < $result['num_teams']) {
         header("Location: category_details.php?category_id=$category_id&tab=standings&error=not_full");
         exit;
    }

    // --- 3. PERFORM THE UPDATE ---
    // Update the category table to set the locked flag.
    $updateStmt = $pdo->prepare("UPDATE category SET playoff_seeding_locked = 1 WHERE id = ?");
    $updateStmt->execute([$category_id]);

    // Log this important action for auditing purposes.
    log_action('LOCK_BRACKET', 'SUCCESS', "Bracket has been successfully locked for category ID: {$category_id}.");

    // --- 4. REDIRECT ON SUCCESS ---
    // Redirect the user back to the standings tab with a success message.
    header("Location: category_details.php?category_id=$category_id&tab=standings&success=locked");
    exit;

} catch (Exception $e) {
    // If any database error occurs, log it and redirect with a generic error.
    log_action('LOCK_BRACKET', 'FAILURE', "Failed to lock bracket for category ID {$category_id}. Error: " . $e->getMessage());
    header("Location: category_details.php?category_id=$category_id&tab=standings&error=db_error");
    exit;
}
?>
