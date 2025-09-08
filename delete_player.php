<?php
require 'db.php';
session_start();
require_once 'logger.php'; 

$id = (int) ($_GET['id'] ?? 0);
$team_id = (int) ($_GET['team_id'] ?? 0);

if (!$id || !$team_id) {
    log_action('REMOVE_PLAYER_FROM_TEAM', 'FAILURE', 'Attempted to remove a player with a missing player or team ID.');
    die("Invalid ID provided.");
}

// --- Start Transaction ---
$pdo->beginTransaction();

try {
    // --- STEP 1: Fetch player and team details for logging ---
    $playerStmt = $pdo->prepare("SELECT first_name, last_name FROM player WHERE id = ?");
    $playerStmt->execute([$id]);
    $player = $playerStmt->fetch();

    $teamStmt = $pdo->prepare("SELECT team_name FROM team WHERE id = ?");
    $teamStmt->execute([$team_id]);
    $team = $teamStmt->fetch();

    if (!$player || !$team) {
        throw new Exception("Player or Team not found.");
    }
    
    $player_name = $player['first_name'] . ' ' . $player['last_name'];
    $team_name = $team['team_name'];

    // --- STEP 2: The Core Change - Only delete from the linking table ---
    // This removes the player from the team's roster but keeps the player's
    // record and all historical stats in other tables (like game_statistic).
    $stmt = $pdo->prepare("DELETE FROM player_team WHERE player_id = ? AND team_id = ?");
    $stmt->execute([$id, $team_id]);

    // --- STEP 3: Commit the change ---
    $pdo->commit();

    // --- STEP 4: Log the successful action ---
    $log_details = "Removed player '{$player_name}' (ID: {$id}) from team '{$team_name}' (ID: {$team_id}).";
    log_action('REMOVE_PLAYER_FROM_TEAM', 'SUCCESS', $log_details);

} catch (Exception $e) {
    // If anything failed, roll back all changes
    $pdo->rollBack();
    
    // Log the error
    $log_details = "Database error trying to remove player (ID: {$id}) from team (ID: {$team_id}). Error: " . $e->getMessage();
    log_action('REMOVE_PLAYER_FROM_TEAM', 'FAILURE', $log_details);
    die("A database error occurred. The player could not be removed from the team.");
}

header("Location: team_details.php?team_id=$team_id");
exit;