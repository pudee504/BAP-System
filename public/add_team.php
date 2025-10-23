<?php
require '../src/db.php';
session_start();
require_once '../src/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$category_id = (int) ($_POST['category_id'] ?? 0);
$team_name = trim($_POST['team_name'] ?? '');

if (!$category_id || !$team_name) {
    log_action('ADD_TEAM', 'FAILURE', 'Attempted to add a team with missing category ID or team name.');
    die("Missing category or team name.");
}

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
$is_bracket_format = in_array(strtolower($format_name), ['single elimination', 'double elimination']);
$is_round_robin = strtolower($format_name) === 'round robin';

// Get the current count of teams already in the category.
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM team WHERE category_id = ?");
$checkStmt->execute([$category_id]);
$current_count = (int) $checkStmt->fetchColumn();

if ($current_count >= $max_teams) {
    $log_details = "Failed to add team '{$team_name}' to category '{$category_name}' (ID: {$category_id}) because the team limit ({$max_teams}) was reached.";
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("Team limit reached. You cannot add more teams.");
}

$pdo->beginTransaction();

try {
    $insert = $pdo->prepare("INSERT INTO team (category_id, team_name) VALUES (?, ?)");
    $insert->execute([$category_id, $team_name]);
    $team_id = $pdo->lastInsertId();

    // **FIXED & ROBUST LOGIC FOR ROUND ROBIN GROUP ASSIGNMENT**
    if ($is_round_robin && $num_groups > 0) {
        
        // 1. Check if the existing clusters are valid.
        $clusterCheckStmt = $pdo->prepare("SELECT cluster_name FROM cluster WHERE category_id = ?");
        $clusterCheckStmt->execute([$category_id]);
        $existing_clusters = $clusterCheckStmt->fetchAll(PDO::FETCH_COLUMN);

        $is_invalid = false;
        if (count($existing_clusters) !== $num_groups) {
            $is_invalid = true; // Wrong number of groups.
        } elseif (count($existing_clusters) > 1 && count(array_unique($existing_clusters)) === 1) {
            $is_invalid = true; // More than one group, but all have the same name (e.g., all are '0').
        }

        // 2. If clusters are invalid or don't exist, wipe and recreate them correctly.
        if ($is_invalid || empty($existing_clusters)) {
            // Delete any potentially broken cluster entries for this category.
            $deleteClustersStmt = $pdo->prepare("DELETE FROM cluster WHERE category_id = ?");
            $deleteClustersStmt->execute([$category_id]);

            // Recreate them with 1-based indexing (1 for Group A, 2 for Group B, etc.) to match original logic.
            $createClusterStmt = $pdo->prepare("INSERT INTO cluster (category_id, cluster_name) VALUES (?, ?)");
            for ($i = 1; $i <= $num_groups; $i++) {
                $createClusterStmt->execute([$category_id, $i]);
            }
        }
        
        // 3. With guaranteed correct clusters, proceed with the original assignment logic.
        $clusterStmt = $pdo->prepare("SELECT id FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
        $clusterStmt->execute([$category_id]);
        $cluster_ids = $clusterStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($cluster_ids)) {
            $target_cluster_index = $current_count % $num_groups;
            if (isset($cluster_ids[$target_cluster_index])) {
                $target_cluster_id = $cluster_ids[$target_cluster_index];
                $updateTeamStmt = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
                $updateTeamStmt->execute([$target_cluster_id, $team_id]);
            } else {
                 throw new Exception("Calculated cluster index is out of bounds.");
            }
        }
    }
    
    // Logic for bracket formats (remains unchanged)
    if ($is_bracket_format) {
        $slotCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
        $slotCheckStmt->execute([$category_id]);
        if ($slotCheckStmt->fetchColumn() == 0 && $max_teams > 0) {
            $createSlotsStmt = $pdo->prepare("INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, NULL)");
            for ($i = 1; $i <= $max_teams; $i++) {
                $createSlotsStmt->execute([$category_id, $i, $i]);
            }
        }

        $findSlotStmt = $pdo->prepare("SELECT position FROM bracket_positions WHERE category_id = ? AND team_id IS NULL ORDER BY position ASC LIMIT 1");
        $findSlotStmt->execute([$category_id]);
        $available_slot = $findSlotStmt->fetchColumn();

        if ($available_slot) {
            $updateBpStmt = $pdo->prepare("UPDATE bracket_positions SET team_id = ? WHERE category_id = ? AND position = ?");
            $updateBpStmt->execute([$team_id, $category_id, $available_slot]);
        } else {
            throw new Exception("Could not find an empty bracket slot despite passing the count check.");
        }
    }

    $pdo->commit();

    $log_details = "Added team '{$team_name}' (ID: {$team_id}) to category '{$category_name}' (ID: {$category_id}).";
    log_action('ADD_TEAM', 'SUCCESS', $log_details);

} catch (Exception $e) {
    $pdo->rollBack();
    
    $log_details = "Database error adding team '{$team_name}' to category '{$category_name}'. Error: " . $e->getMessage();
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("A database error occurred while adding the team: " . $e->getMessage());
}

header("Location: category_details.php?category_id=" . $category_id . "&tab=teams");
exit;
?>