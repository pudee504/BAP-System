<?php
require 'db.php';
session_start();
require_once 'logger.php';

// Validate input
$player_id = filter_var($_POST['player_id'] ?? null, FILTER_VALIDATE_INT);
$team_id = filter_var($_POST['team_id'] ?? null, FILTER_VALIDATE_INT);

if (!$player_id || !$team_id) {
    die("Invalid data provided.");
}

try {
    // Simply insert a new link into the player_team table
    $stmt = $pdo->prepare("INSERT INTO player_team (player_id, team_id) VALUES (?, ?)");
    $stmt->execute([$player_id, $team_id]);

    // Logging for context
    log_action('ASSIGN_PLAYER', 'SUCCESS', "Assigned existing player (ID: {$player_id}) to team (ID: {$team_id}).");

} catch (PDOException $e) {
    // Handle potential errors, like trying to add the same player twice
    log_action('ASSIGN_PLAYER', 'FAILURE', "Error assigning player ID {$player_id} to team ID {$team_id}. Error: " . $e->getMessage());
    die("A database error occurred.");
}

// Redirect back to the team details page
header("Location: team_details.php?team_id=" . $team_id);
exit;