<?php
// FILENAME: add_team.php
// DESCRIPTION: Server-side script to add a new team to a category.
// This script is called via a POST request, typically from the "Teams" tab in category_details.php.

require 'db.php';
session_start();
require_once 'logger.php';
require_once 'includes/auth_functions.php';

// --- 1. Validate Request Method ---
// This page should only be accessed via POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

// --- 2. Get and Validate Input ---
$category_id = (int) ($_POST['category_id'] ?? 0);
$team_name = trim($_POST['team_name'] ?? '');

if (!$category_id || !$team_name) {
    log_action('ADD_TEAM', 'FAILURE', 'Attempted to add a team with missing category ID or team name.');
    die("Missing category or team name.");
}

// --- Authorization Check ---
if (!has_league_permission($pdo, $_SESSION['user_id'], 'category', $category_id)) {
    $_SESSION['error'] = 'You do not have permission to add a team to this category.';
    log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for category {$category_id} on add_team.php");
    header('Location: dashboard.php'); // Redirect to a safe page
    exit;
}

// --- 3. Fetch Category Information ---
// Get the category's max teams, format, and group settings.
$stmt = $pdo->prepare("
    SELECT cf.num_teams, f.format_name, c.category_name, cf.num_groups 
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
$num_groups = (int) $info['num_groups'];
$is_round_robin = strtolower($format_name) === 'round robin';
$is_bracket_format = in_array(strtolower($format_name), ['single elimination', 'double elimination']);

// --- 4. Check Team Limit ---
// Get the current number of teams in this category.
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM team WHERE category_id = ?");
$checkStmt->execute([$category_id]);
$current_count = (int) $checkStmt->fetchColumn();

// Stop if the category is already full.
if ($current_count >= $max_teams) {
    $log_details = "Failed to add team '{$team_name}' to category '{$category_name}' (ID: {$category_id}) because the team limit ({$max_teams}) was reached.";
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("Team limit reached. You cannot add more teams.");
}

// --- 5. Add Team and Assign Slot/Group (Transaction) ---
$pdo->beginTransaction();

try {
    // Insert the new team into the 'team' table.
    $insert = $pdo->prepare("INSERT INTO team (category_id, team_name) VALUES (?, ?)");
    $insert->execute([$category_id, $team_name]);
    $team_id = $pdo->lastInsertId();

    // --- 5a. Logic for Round Robin: Assign to a group (cluster) ---
    if ($is_round_robin && $num_groups > 0) {
        
        // --- This block is a failsafe to ensure clusters exist correctly ---
        // 1. Check if the cluster setup in the DB is valid.
        $clusterCheckStmt = $pdo->prepare("SELECT cluster_name FROM cluster WHERE category_id = ?");
        $clusterCheckStmt->execute([$category_id]);
        $existing_clusters = $clusterCheckStmt->fetchAll(PDO::FETCH_COLUMN);

        $is_invalid = false;
        if (count($existing_clusters) !== $num_groups) {
            $is_invalid = true; // Wrong number of groups.
        } elseif (count($existing_clusters) > 1 && count(array_unique($existing_clusters)) === 1) {
            $is_invalid = true; // All groups have the same name (e.g., all are '0').
        }

        // 2. If clusters are invalid (or don't exist), wipe and recreate them.
        if ($is_invalid || empty($existing_clusters)) {
            $deleteClustersStmt = $pdo->prepare("DELETE FROM cluster WHERE category_id = ?");
            $deleteClustersStmt->execute([$category_id]);

            $createClusterStmt = $pdo->prepare("INSERT INTO cluster (category_id, cluster_name) VALUES (?, ?)");
            for ($i = 1; $i <= $num_groups; $i++) {
                $createClusterStmt->execute([$category_id, $i]); // Use 1-based names (1, 2, 3...)
            }
        }
        
        // 3. Assign the new team to the next available group in sequence.
        $clusterStmt = $pdo->prepare("SELECT id FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
        $clusterStmt->execute([$category_id]);
        $cluster_ids = $clusterStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($cluster_ids)) {
            // Use modulo to loop through groups (team 1 -> group 0, team 2 -> group 1, ...)
            $target_cluster_index = $current_count % $num_groups;
            if (isset($cluster_ids[$target_cluster_index])) {
                $target_cluster_id = $cluster_ids[$target_cluster_index];
                // Update the team record with its assigned cluster_id.
                $updateTeamStmt = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
                $updateTeamStmt->execute([$target_cluster_id, $team_id]);
            } else {
                 throw new Exception("Calculated cluster index is out of bounds.");
            }
        }
    }
    
    // --- 5b. Logic for Bracket Formats: Assign to a bracket position ---
    if ($is_bracket_format) {
        // Check if bracket positions have been created yet.
        $slotCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
        $slotCheckStmt->execute([$category_id]);
        
        // If no positions exist, create them (e.g., 1 to 8 for an 8-team bracket).
        if ($slotCheckStmt->fetchColumn() == 0 && $max_teams > 0) {
            $createSlotsStmt = $pdo->prepare("INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, NULL)");
            for ($i = 1; $i <= $max_teams; $i++) {
                $createSlotsStmt->execute([$category_id, $i, $i]);
            }
        }

        // Find the first available (NULL) slot in the bracket.
        $findSlotStmt = $pdo->prepare("SELECT position FROM bracket_positions WHERE category_id = ? AND team_id IS NULL ORDER BY position ASC LIMIT 1");
        $findSlotStmt->execute([$category_id]);
        $available_slot = $findSlotStmt->fetchColumn();

        // Assign the new team_id to that empty slot.
        if ($available_slot) {
            $updateBpStmt = $pdo->prepare("UPDATE bracket_positions SET team_id = ? WHERE category_id = ? AND position = ?");
            $updateBpStmt->execute([$team_id, $category_id, $available_slot]);
        } else {
            throw new Exception("Could not find an empty bracket slot despite passing the count check.");
        }
    }

    // --- 6. Finalize Transaction ---
    $pdo->commit();

    $log_details = "Added team '{$team_name}' (ID: {$team_id}) to category '{$category_name}' (ID: {$category_id}).";
    log_action('ADD_TEAM', 'SUCCESS', $log_details);

} catch (Exception $e) {
    // If anything failed, roll back all database changes.
    $pdo->rollBack();
    
    $log_details = "Database error adding team '{$team_name}' to category '{$category_name}'. Error: " . $e->getMessage();
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("A database error occurred while adding the team: " . $e->getMessage());
}

// --- 7. Redirect on Success ---
// Send the user back to the "Teams" tab for the category.
header("Location: category_details.php?category_id=" . $category_id . "&tab=teams");
exit;
?>