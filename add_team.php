<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$category_id = (int) ($_POST['category_id'] ?? 0);
$team_name = trim($_POST['team_name'] ?? '');

// --- Validation and Failure Logging ---
if (!$category_id || !$team_name) {
    log_action('ADD_TEAM', 'FAILURE', 'Attempted to add a team with missing category ID or team name.');
    die("Missing category or team name.");
}

$stmt = $pdo->prepare("
    SELECT cf.num_teams, f.format_name, c.category_name
    FROM category_format cf
    JOIN category c ON c.id = cf.category_id
    JOIN format f ON f.id = cf.format_id
    WHERE c.id = ?
");
$stmt->execute([$category_id]);
$info = $stmt->fetch();

if (!$info) {
    log_action('ADD_TEAM', 'FAILURE', "Attempted to add a team to a non-existent category (ID: {$category_id}).");
    die("Category not found.");
}

$max_teams = (int) $info['num_teams'];
$format_name = $info['format_name'];
$category_name = $info['category_name'];
$is_bracket_format = in_array(strtolower($format_name), ['single elimination', 'double elimination']);

// Count currently registered teams
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM team WHERE category_id = ?");
$checkStmt->execute([$category_id]);
$current_count = (int) $checkStmt->fetchColumn();

if ($current_count >= $max_teams) {
    $log_details = "Failed to add team '{$team_name}' to category '{$category_name}' (ID: {$category_id}) because the team limit ({$max_teams}) was reached.";
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("Team limit reached. You cannot add more teams.");
}

// --- Start transaction to ensure both tables are updated ---
$pdo->beginTransaction();

try {
    // Step 1: Add the team to the 'team' table
    $insert = $pdo->prepare("INSERT INTO team (category_id, team_name) VALUES (?, ?)");
    $insert->execute([$category_id, $team_name]);
    $team_id = $pdo->lastInsertId();

    // **NEW LOGIC**: If it's a bracket format, also add to bracket_positions
    if ($is_bracket_format) {
        // Find the next available position
        $posStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
        $posStmt->execute([$category_id]);
        $next_position = $posStmt->fetchColumn() + 1;

        // Insert the new team into that position
        $bpStmt = $pdo->prepare(
            "INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, ?)"
        );
        $bpStmt->execute([$category_id, $next_position, $next_position, $team_id]);
    }
    
    // If we get here, both inserts were successful. Commit the transaction.
    $pdo->commit();

    // --- Success Logging ---
    $log_details = "Added team '{$team_name}' (ID: {$team_id}) to category '{$category_name}' (ID: {$category_id}).";
    log_action('ADD_TEAM', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    // If any error occurred, roll back all changes
    $pdo->rollBack();
    
    $log_details = "Database error adding team '{$team_name}' to category '{$category_name}'. Error: " . $e->getMessage();
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("A database error occurred while adding the team.");
}

header("Location: category_details.php?category_id=" . $category_id . "&tab=teams");
exit;