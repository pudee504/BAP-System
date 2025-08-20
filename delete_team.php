<?php
require 'db.php';
session_start(); // << START SESSION
require_once 'logger.php'; // << INCLUDE THE LOGGER

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $team_id = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

    if (!$team_id || !$category_id) {
        log_action('DELETE_TEAM', 'FAILURE', 'Attempted to delete a team with missing information.');
        die("Missing information.");
    }

    try {
        // --- STEP 1: Fetch team details BEFORE deleting ---
        $stmt = $pdo->prepare("SELECT team_name FROM team WHERE id = ?");
        $stmt->execute([$team_id]);
        $team = $stmt->fetch();

        if (!$team) {
            log_action('DELETE_TEAM', 'FAILURE', "Attempted to delete a non-existent team (ID: {$team_id}).");
            die("Team not found.");
        }
        
        // Store the name for the log message.
        $team_name = $team['team_name'];

        // --- STEP 2: Delete the team ---
        $deleteStmt = $pdo->prepare("DELETE FROM team WHERE id = ?");
        $deleteStmt->execute([$team_id]);

        // --- STEP 3: Log the successful deletion ---
        $log_details = "Deleted team '{$team_name}' (ID: {$team_id}) from category ID {$category_id}.";
        log_action('DELETE_TEAM', 'SUCCESS', $log_details);

    } catch (PDOException $e) {
        // This will catch any database errors during the process.
        $log_details = "Database error trying to delete team ID {$team_id}. Error: " . $e->getMessage();
        log_action('DELETE_TEAM', 'FAILURE', $log_details);
        die("A database error occurred. The team could not be deleted.");
    }

    header("Location: category_details.php?category_id=$category_id#teams");
    exit;
}

// Redirect if not a POST request
header("Location: dashboard.php");
exit;