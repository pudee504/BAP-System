<?php
// FILENAME: delete_league.php
// DESCRIPTION: Admin-only script to delete an entire league.

require 'db.php';
session_start();
require_once 'logger.php'; // For logging actions

// --- 1. Validate Input & Permissions ---
$league_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

// Ensure the user is an Admin.
if ($_SESSION['role_name'] !== 'Admin') {
    log_action('DELETE_LEAGUE', 'FAILURE', 'Non-admin user attempted to delete a league.');
    die("You are not authorized to delete leagues.");
}

// Ensure a valid league ID was provided.
if (!$league_id) {
    log_action('DELETE_LEAGUE', 'FAILURE', 'Attempted to delete a league with an invalid ID.');
    die("Invalid league ID.");
}

try {
    // --- 2. Get League Name (for logging) ---
    // Fetch the name *before* deleting it.
    $stmt = $pdo->prepare("SELECT league_name FROM league WHERE id = ?");
    $stmt->execute([$league_id]);
    $league = $stmt->fetch();

    if (!$league) {
        log_action('DELETE_LEAGUE', 'FAILURE', "Attempted to delete a non-existent league (ID: {$league_id}).");
        die("League not found.");
    }
    $league_name = $league['league_name']; // Store name for logging

    // --- 3. Delete the League ---
    // Note: Database foreign key constraints (ON DELETE CASCADE) should handle related data (categories, teams, etc.).
    // If constraints are not set up, this might fail or leave orphaned data.
    $deleteStmt = $pdo->prepare("DELETE FROM league WHERE id = ?");
    $deleteStmt->execute([$league_id]);

    // --- 4. Log Success ---
    $log_details = "Deleted league '{$league_name}' (ID: {$league_id}).";
    log_action('DELETE_LEAGUE', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    // --- 5. Handle Errors ---
    // Catch errors, likely due to foreign key constraints if related data exists.
    $log_details = "Database error trying to delete league ID {$league_id}. Error: " . $e->getMessage();
    log_action('DELETE_LEAGUE', 'FAILURE', $log_details);
    die("A database error occurred. The league might not have been deleted if it still contains data (like categories or teams).");
}

// --- 6. Redirect Back ---
header("Location: dashboard.php");
exit;
?>