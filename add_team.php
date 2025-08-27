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

// --- START: MODIFIED QUERY ---
// Fetch num_groups as well
$stmt = $pdo->prepare("
    SELECT cf.num_teams, f.format_name, c.category_name, cf.num_groups 
    FROM category_format cf
    JOIN category c ON c.id = cf.category_id
    JOIN format f ON f.id = cf.format_id
    WHERE c.id = ?
");
// --- END: MODIFIED QUERY ---
$stmt->execute([$category_id]);
$info = $stmt->fetch();

if (!$info) {
    log_action('ADD_TEAM', 'FAILURE', "Attempted to add a team to a non-existent category (ID: {$category_id}).");
    die("Category not found.");
}

$max_teams = (int) $info['num_teams'];
$format_name = $info['format_name'];
$category_name = $info['category_name'];
$num_groups = (int) $info['num_groups']; // <-- Get the number of groups
$is_bracket_format = in_array(strtolower($format_name), ['single elimination', 'double elimination']);
$is_round_robin = strtolower($format_name) === 'round robin'; // <-- Check for Round Robin

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

    // Step 2: If it's a bracket format, add to bracket_positions
    if ($is_bracket_format) {
        $next_position = $current_count + 1;
        $bpStmt = $pdo->prepare(
            "INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, ?)"
        );
        $bpStmt->execute([$category_id, $next_position, $next_position, $team_id]);
    }
    
    // --- START: NEW LOGIC FOR ROUND ROBIN ---
    // Step 3: If it's Round Robin, assign the new team to a cluster/group.
    if ($is_round_robin && $num_groups > 0) {
        // Get the list of cluster IDs for this category
        $clusterStmt = $pdo->prepare("SELECT id FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
        $clusterStmt->execute([$category_id]);
        $cluster_ids = $clusterStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($cluster_ids)) {
            // Distribute teams evenly using the modulo operator
            $target_cluster_index = $current_count % $num_groups;
            $target_cluster_id = $cluster_ids[$target_cluster_index];

            // Update the team record with its assigned cluster_id
            $updateTeamStmt = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
            $updateTeamStmt->execute([$target_cluster_id, $team_id]);
        }
    }
    // --- END: NEW LOGIC FOR ROUND ROBIN ---

    $pdo->commit();

    // --- Success Logging ---
    $log_details = "Added team '{$team_name}' (ID: {$team_id}) to category '{$category_name}' (ID: {$category_id}).";
    log_action('ADD_TEAM', 'SUCCESS', $log_details);

} catch (PDOException $e) {
    $pdo->rollBack();
    
    $log_details = "Database error adding team '{$team_name}' to category '{$category_name}'. Error: " . $e->getMessage();
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("A database error occurred while adding the team.");
}

header("Location: category_details.php?category_id=" . $category_id . "&tab=teams");
exit;