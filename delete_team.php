<?php
// FILENAME: delete_team.php
// DESCRIPTION: Deletes a team and its primary associations (bracket position, standings, player links).

require 'db.php';
session_start();
require_once 'logger.php';
require_once 'includes/auth_functions.php';

// --- 1. Validate Request Method ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- 2. Validate Input ---
    $team_id = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

    if (!$team_id || !$category_id) {
        log_action('DELETE_TEAM', 'FAILURE', 'Attempted to delete a team with missing information.');
        die("Missing information.");
    }

    // --- Authorization Check ---
    if (!has_league_permission($pdo, $_SESSION['user_id'], 'team', $team_id)) {
        $_SESSION['error'] = 'You do not have permission to delete this team.';
        log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for team {$team_id} on delete_team.php");
        header('Location: dashboard.php');
        exit;
    }

    // --- 3. Security Check: Prevent deletion if category is locked ---
    $lockCheckStmt = $pdo->prepare("SELECT playoff_seeding_locked, groups_locked FROM category WHERE id = ?");
    $lockCheckStmt->execute([$category_id]);
    $lockStatus = $lockCheckStmt->fetch(PDO::FETCH_ASSOC);

    // Stop if either the bracket seeding or group stage is locked.
    if ($lockStatus && ($lockStatus['playoff_seeding_locked'] || $lockStatus['groups_locked'])) {
        log_action('DELETE_TEAM', 'FAILURE', "Attempted to delete team ID {$team_id} from a locked category (ID: {$category_id}).");
        $_SESSION['error_message'] = "Cannot delete a team because the category is locked."; 
        header("Location: category_details.php?category_id=$category_id&tab=teams");
        exit;
    }
    // --- End Security Check ---

    $pdo->beginTransaction();

    try {
        // --- 4. Get Team Name (for logging) ---
        $stmt = $pdo->prepare("SELECT team_name FROM team WHERE id = ?");
        $stmt->execute([$team_id]);
        $team = $stmt->fetch();

        if (!$team) {
            throw new Exception("Team not found."); // Trigger rollback and error message
        }
        $team_name = $team['team_name'];

        // --- 5. Clean Up Associations ---
        // a. Clear team from any bracket position it occupies.
        $updateBpStmt = $pdo->prepare("UPDATE bracket_positions SET team_id = NULL WHERE team_id = ?");
        $updateBpStmt->execute([$team_id]);

        // b. Remove the team's record from round robin standings.
        $deleteStandingStmt = $pdo->prepare("DELETE FROM cluster_standing WHERE team_id = ?");
        $deleteStandingStmt->execute([$team_id]);

        // c. Remove links between this team and its players.
        $deletePlayerTeamStmt = $pdo->prepare("DELETE FROM player_team WHERE team_id = ?");
        $deletePlayerTeamStmt->execute([$team_id]);

        // --- 6. Delete the Team Record ---
        $deleteStmt = $pdo->prepare("DELETE FROM team WHERE id = ?");
        $deleteStmt->execute([$team_id]);

        $pdo->commit();

        // --- 7. Log Success ---
        $log_details = "Deleted team '{$team_name}' (ID: {$team_id}) and associations from category ID {$category_id}.";
        log_action('DELETE_TEAM', 'SUCCESS', $log_details);

    } catch (Exception $e) { // Catch both PDO exceptions and manual throws
        // --- 8. Handle Errors ---
        $pdo->rollBack();
        $log_details = "Database error trying to delete team ID {$team_id}. Error: " . $e->getMessage();
        log_action('DELETE_TEAM', 'FAILURE', $log_details);
        // Use a generic message unless debugging
        die("A database error occurred. The team could not be deleted."); 
    }

    // --- 9. Redirect Back ---
    header("Location: category_details.php?category_id=$category_id&tab=teams");
    exit;
}

// Redirect if not a POST request
header("Location: dashboard.php");
exit;
?>