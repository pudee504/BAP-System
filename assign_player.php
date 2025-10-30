<?php
// FILENAME: assign_player.php
// DESCRIPTION: Server-side script to link an existing player to a team.

require 'db.php';
session_start();
require_once 'logger.php';

// --- 1. Validate Input ---
$player_id = filter_var($_POST['player_id'] ?? null, FILTER_VALIDATE_INT);
$team_id = filter_var($_POST['team_id'] ?? null, FILTER_VALIDATE_INT);

if (!$player_id || !$team_id) {
    die("Invalid data provided.");
}

try {
    // --- 2. Create the Association ---
    // Insert a new row into the link table `player_team`.
    $stmt = $pdo->prepare("INSERT INTO player_team (player_id, team_id) VALUES (?, ?)");
    $stmt->execute([$player_id, $team_id]);

    // --- 3. Log the Action ---
    log_action('ASSIGN_PLAYER', 'SUCCESS', "Assigned existing player (ID: {$player_id}) to team (ID: {$team_id}).");

} catch (PDOException $e) {
    // Handle errors, e.g., duplicate entry (player already on team).
    log_action('ASSIGN_PLAYER', 'FAILURE', "Error assigning player ID {$player_id} to team ID {$team_id}. Error: " . $e->getMessage());
    die("A database error occurred.");
}

// --- 4. Redirect Back ---
// Go back to the team details page to see the updated player list.
header("Location: team_details.php?team_id=" . $team_id);
exit;
?>