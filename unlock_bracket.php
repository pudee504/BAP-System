<?php
// FILENAME: unlock_bracket.php
// DESCRIPTION: Unlocks a bracket category (Single/Double Elimination) and clears its schedule,
// but ONLY if no games in the schedule have been completed (have a winner).

session_start();
require_once 'db.php';
require_once 'logger.php';

// --- 1. Validate Input ---
$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    // Redirect if category ID is missing.
    header("Location: category_details.php?tab=standings&error=missing_id");
    exit;
}

$pdo->beginTransaction();
try {
    // --- 2. Safety Check: Ensure no games are finished ---
    // Count games in this category that have a `winnerteam_id` set.
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $checkStmt->execute([$category_id]);
    $finished_games = $checkStmt->fetchColumn();

    if ($finished_games > 0) {
        // If finished games exist, block the unlock.
        $pdo->rollBack(); // No changes needed
        log_action('UNLOCK_BRACKET', 'FAILURE', "Attempted to unlock bracket with finished games for category ID: {$category_id}.");
        header("Location: category_details.php?category_id=$category_id&tab=standings&error=has_winners");
        exit;
    }

    // --- 3. Clear Schedule ---
    // If no games are finished, proceed to delete all games for this category.
    $deleteStmt = $pdo->prepare("DELETE FROM game WHERE category_id = ?");
    $deleteStmt->execute([$category_id]);
    // Note: Consider also deleting from game_timer, game_statistic etc. if CASCADE is not set up.

    // --- 4. Update Category Flags ---
    // Set `playoff_seeding_locked` to 0 (unlocked) and `schedule_generated` to 0.
    $updateStmt = $pdo->prepare("UPDATE category SET playoff_seeding_locked = 0, schedule_generated = 0 WHERE id = ?");
    $updateStmt->execute([$category_id]);
    
    $pdo->commit(); // Commit all changes

    // --- 5. Log Success and Redirect ---
    log_action('UNLOCK_BRACKET', 'SUCCESS', "Bracket unlocked and schedule cleared for category ID: {$category_id}.");
    header("Location: category_details.php?category_id=$category_id&tab=standings&success=unlocked");
    exit;

} catch (Exception $e) {
    // --- 6. Handle Errors ---
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_action('UNLOCK_BRACKET', 'FAILURE', "Database error for category ID {$category_id}. Error: " . $e->getMessage());
    header("Location: category_details.php?category_id=$category_id&tab=standings&error=db_error");
    exit;
}
?>