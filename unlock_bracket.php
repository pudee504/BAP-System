<?php
session_start();
require_once 'db.php';
require_once 'logger.php';

$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    header("Location: category_details.php?tab=standings&error=missing_id");
    exit;
}

try {
    // Check if any games in this category have a winner.
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $checkStmt->execute([$category_id]);
    $finished_games = $checkStmt->fetchColumn();

    if ($finished_games > 0) {
        // If games are finished, block the unlock and redirect with an error.
        log_action('UNLOCK_BRACKET', 'FAILURE', "Attempted to unlock bracket with finished games for category ID: {$category_id}.");
        header("Location: category_details.php?category_id=$category_id&tab=standings&error=has_winners");
        exit;
    }

    // If no games are finished, proceed with unlocking.
    $updateStmt = $pdo->prepare("UPDATE category SET playoff_seeding_locked = 0 WHERE id = ?");
    $updateStmt->execute([$category_id]);

    log_action('UNLOCK_BRACKET', 'SUCCESS', "Bracket unlocked for category ID: {$category_id}.");
    header("Location: category_details.php?category_id=$category_id&tab=standings&success=unlocked");
    exit;

} catch (Exception $e) {
    log_action('UNLOCK_BRACKET', 'FAILURE', "Database error for category ID {$category_id}. Error: " . $e->getMessage());
    header("Location: category_details.php?category_id=$category_id&tab=standings&error=db_error");
    exit;
}
?>