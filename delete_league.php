<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

$league_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($_SESSION['role_name'] !== 'Admin') {
    log_action('DELETE_LEAGUE', 'FAILURE', 'Non-admin user attempted to delete a league.');
    die("You are not authorized to delete leagues.");
}

if (!$league_id) {
    log_action('DELETE_LEAGUE', 'FAILURE', 'Attempted to delete a league with an invalid ID.');
    die("Invalid league ID.");
}

try {
    // --- STEP 1: Fetch league details BEFORE deleting ---
    // We need the name for our log entry.
    $stmt = $pdo->prepare("SELECT league_name FROM league WHERE id = ?");
    $stmt->execute([$league_id]);
    $league = $stmt->fetch();

    if (!$league) {
        // The league ID was valid but doesn't exist.
        log_action('DELETE_LEAGUE', 'FAILURE', "Attempted to delete a non-existent league (ID: {$league_id}).");
        die("League not found.");
    }
    
    // Store the name for the log message after it's deleted.
    $league_name = $league['league_name'];

    // --- STEP 2: Delete the league ---
    // Optional: You might need to delete related categories, teams, etc. first
    // depending on your database's foreign key constraints.

    $deleteStmt = $pdo->prepare("DELETE FROM league WHERE id = ?");
    $deleteStmt->execute([$league_id]);

    // --- STEP 3: Log the successful deletion ---
    $log_details = "Deleted league '{$league_name}' (ID: {$league_id}).";
    log_action('DELETE_LEAGUE', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    // This will catch errors if, for example, you try to delete a league
    // that still has categories or teams linked to it.
    $log_details = "Database error trying to delete league ID {$league_id}. Error: " . $e->getMessage();
    log_action('DELETE_LEAGUE', 'FAILURE', $log_details);
    die("A database error occurred. The league might not have been deleted if it still contains data (like categories or teams).");
}


header("Location: dashboard.php");
exit;