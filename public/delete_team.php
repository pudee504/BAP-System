<?php
require '../src/db.php';
session_start();
require_once '../src/logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $team_id = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

    if (!$team_id || !$category_id) {
        log_action('DELETE_TEAM', 'FAILURE', 'Attempted to delete a team with missing information.');
        die("Missing information.");
    }

    // === START: MODIFIED SECURITY CHECK ===
    // Check if the category is locked (either bracket or groups) before allowing deletion.
    $lockCheckStmt = $pdo->prepare("SELECT playoff_seeding_locked, groups_locked FROM category WHERE id = ?");
    $lockCheckStmt->execute([$category_id]);
    $lockStatus = $lockCheckStmt->fetch(PDO::FETCH_ASSOC);

    // If either lock is active, stop the script.
    if ($lockStatus && ($lockStatus['playoff_seeding_locked'] || $lockStatus['groups_locked'])) {
        log_action('DELETE_TEAM', 'FAILURE', "Attempted to delete team ID {$team_id} from a locked category (ID: {$category_id}).");
        
        $_SESSION['error_message'] = "Cannot delete a team because the category is locked.";
        
        header("Location: category_details.php?category_id=$category_id&tab=teams");
        exit;
    }
    // === END: MODIFIED SECURITY CHECK ===

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT team_name FROM team WHERE id = ?");
        $stmt->execute([$team_id]);
        $team = $stmt->fetch();

        if (!$team) {
            $pdo->rollBack();
            log_action('DELETE_TEAM', 'FAILURE', "Attempted to delete a non-existent team (ID: {$team_id}).");
            die("Team not found.");
        }
        
        $team_name = $team['team_name'];

        $updateBpStmt = $pdo->prepare("UPDATE bracket_positions SET team_id = NULL WHERE team_id = ?");
        $updateBpStmt->execute([$team_id]);

        $deleteStandingStmt = $pdo->prepare("DELETE FROM cluster_standing WHERE team_id = ?");
        $deleteStandingStmt->execute([$team_id]);

        $deletePlayerTeamStmt = $pdo->prepare("DELETE FROM player_team WHERE team_id = ?");
        $deletePlayerTeamStmt->execute([$team_id]);

        $deleteStmt = $pdo->prepare("DELETE FROM team WHERE id = ?");
        $deleteStmt->execute([$team_id]);

        $pdo->commit();

        $log_details = "Deleted team '{$team_name}' (ID: {$team_id}) and all its primary associations from category ID {$category_id}.";
        log_action('DELETE_TEAM', 'SUCCESS', $log_details);

    } catch (PDOException $e) {
        $pdo->rollBack();
        
        $log_details = "Database error trying to delete team ID {$team_id}. Error: " . $e->getMessage();
        log_action('DELETE_TEAM', 'FAILURE', $log_details);
        die("A database error occurred. The team could not be deleted.");
    }

    header("Location: category_details.php?category_id=$category_id&tab=teams");
    exit;
}

header("Location: dashboard.php");
exit;