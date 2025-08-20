<?php
require 'db.php';
session_start(); // << START SESSION
require_once 'logger.php'; // << INCLUDE THE LOGGER

$id = (int) ($_GET['id'] ?? 0);
$team_id = (int) ($_GET['team_id'] ?? 0);

if (!$id || !$team_id) {
    log_action('DELETE_PLAYER', 'FAILURE', 'Attempted to delete a player with a missing player or team ID.');
    die("Invalid ID provided.");
}

try {
    // --- STEP 1: Fetch player details BEFORE deleting ---
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM player WHERE id = ?");
    $stmt->execute([$id]);
    $player = $stmt->fetch();

    if (!$player) {
        log_action('DELETE_PLAYER', 'FAILURE', "Attempted to delete a non-existent player (ID: {$id}).");
        die("Player not found.");
    }
    
    // Store the name for the log message.
    $player_name = $player['first_name'] . ' ' . $player['last_name'];

    // --- STEP 2: Delete the player using a transaction ---
    $pdo->beginTransaction();

    // First, remove the link from the player_team table
    $linkStmt = $pdo->prepare("DELETE FROM player_team WHERE player_id = ? AND team_id = ?");
    $linkStmt->execute([$id, $team_id]);

    // Then, delete the player from the player table
    $playerStmt = $pdo->prepare("DELETE FROM player WHERE id = ?");
    $playerStmt->execute([$id]);
    
    // If both queries were successful, commit the changes
    $pdo->commit();

    // --- STEP 3: Log the successful deletion ---
    $log_details = "Deleted player '{$player_name}' (ID: {$id}) from team ID {$team_id}.";
    log_action('DELETE_PLAYER', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    // If anything failed, roll back all changes
    $pdo->rollBack();
    
    // Log the database error
    $log_details = "Database error trying to delete player '{$player_name}' (ID: {$id}). Error: " . $e->getMessage();
    log_action('DELETE_PLAYER', 'FAILURE', $log_details);
    die("A database error occurred. The player could not be deleted.");
}

header("Location: team_details.php?team_id=$team_id");
exit;