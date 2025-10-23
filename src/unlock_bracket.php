<?php
session_start();
require_once 'db.php';
require_once 'logger.php';

$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    header("Location: category_details.php?tab=standings&error=missing_id");
    exit;
}

$pdo->beginTransaction();
try {
    // 1. Check if any games in this category have a winner.
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $checkStmt->execute([$category_id]);
    $finished_games = $checkStmt->fetchColumn();

    if ($finished_games > 0) {
        // If games are finished, block the unlock and redirect with an error.
        $pdo->rollBack();
        log_action('UNLOCK_BRACKET', 'FAILURE', "Attempted to unlock bracket with finished games for category ID: {$category_id}.");
        header("Location: category_details.php?category_id=$category_id&tab=standings&error=has_winners");
        exit;
    }

    // 2. If no games are finished, delete the existing schedule.
    $deleteStmt = $pdo->prepare("DELETE FROM game WHERE category_id = ?");
    $deleteStmt->execute([$category_id]);

    // 3. Reset the flags to unlock the bracket and mark the schedule as not generated.
    $updateStmt = $pdo->prepare("UPDATE category SET playoff_seeding_locked = 0, schedule_generated = 0 WHERE id = ?");
    $updateStmt->execute([$category_id]);
    
    $pdo->commit();

    log_action('UNLOCK_BRACKET', 'SUCCESS', "Bracket unlocked and schedule cleared for category ID: {$category_id}.");
    header("Location: category_details.php?category_id=$category_id&tab=standings&success=unlocked");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_action('UNLOCK_BRACKET', 'FAILURE', "Database error for category ID {$category_id}. Error: " . $e->getMessage());
    header("Location: category_details.php?category_id=$category_id&tab=standings&error=db_error");
    exit;
}
?>