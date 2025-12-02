<?php
// FILENAME: delete_player.php
// DESCRIPTION: Removes a player's association with a specific team (from player_team table).
// It does NOT delete the player record itself, preserving historical stats.

require 'db.php';
session_start();
require_once 'logger.php'; 

// --- 1. Validate Input ---
$id = (int) ($_GET['id'] ?? 0);       // Player ID
$team_id = (int) ($_GET['team_id'] ?? 0); // Team ID

if (!$id || !$team_id) {
    log_action('REMOVE_PLAYER_FROM_TEAM', 'FAILURE', 'Attempted to remove a player with a missing player or team ID.');
    die("Invalid ID provided.");
}

// --- Authorization Check ---
require_once 'includes/auth_functions.php';
if (!has_league_permission($pdo, $_SESSION['user_id'], 'player', $id)) {
    $_SESSION['error'] = 'You do not have permission to remove this player.';
    log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for player {$id} on delete_player.php");
    header('Location: dashboard.php');
    exit;
}
$pdo->beginTransaction();

try {
    // --- 2. Fetch Names (for logging) ---
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

    // --- 3. Remove Player-Team Link ---
    // Delete the specific row linking this player to this team.
    $stmt = $pdo->prepare("DELETE FROM player_team WHERE player_id = ? AND team_id = ?");
    $stmt->execute([$id, $team_id]);

    $pdo->commit();

    // --- 4. Log Success ---
    $log_details = "Removed player '{$player_name}' (ID: {$id}) from team '{$team_name}' (ID: {$team_id}).";
    log_action('REMOVE_PLAYER_FROM_TEAM', 'SUCCESS', $log_details);

} catch (Exception $e) {
    // --- 5. Handle Errors ---
    $pdo->rollBack();
    $log_details = "Database error trying to remove player (ID: {$id}) from team (ID: {$team_id}). Error: " . $e->getMessage();
    log_action('REMOVE_PLAYER_FROM_TEAM', 'FAILURE', $log_details);
    die("A database error occurred. The player could not be removed from the team.");
}

// --- 6. Redirect Back ---
header("Location: team_details.php?team_id=$team_id");
exit;
?>