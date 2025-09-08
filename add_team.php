<?php
require 'db.php';
session_start();
require_once 'logger.php';

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

$current_count = 0;
if ($is_bracket_format) {
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ? AND team_id IS NOT NULL");
    $checkStmt->execute([$category_id]);
    $current_count = (int) $checkStmt->fetchColumn();
} else {
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM team WHERE category_id = ?");
    $checkStmt->execute([$category_id]);
    $current_count = (int) $checkStmt->fetchColumn();
}

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

    if ($is_bracket_format) {
        // === START: NEW LOGIC TO CREATE SLOTS IF THEY DON'T EXIST ===
        // First, check if bracket slots have been created for this category yet.
        $slotCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM bracket_positions WHERE category_id = ?");
        $slotCheckStmt->execute([$category_id]);
        $slot_count = $slotCheckStmt->fetchColumn();

        // If no slots exist, this is a new bracket. Create them all now.
        if ($slot_count == 0 && $max_teams > 0) {
            $createSlotsStmt = $pdo->prepare(
                "INSERT INTO bracket_positions (category_id, position, seed, team_id) VALUES (?, ?, ?, NULL)"
            );
            for ($i = 1; $i <= $max_teams; $i++) {
                // Position and Seed are set, but team_id is left NULL
                $createSlotsStmt->execute([$category_id, $i, $i]);
            }
        }
        // === END: NEW LOGIC ===

        // Now, find the first available (NULL) slot and update it.
        $findSlotStmt = $pdo->prepare(
            "SELECT position FROM bracket_positions 
             WHERE category_id = ? AND team_id IS NULL 
             ORDER BY position ASC LIMIT 1"
        );
        $findSlotStmt->execute([$category_id]);
        $available_slot = $findSlotStmt->fetchColumn();

        if ($available_slot) {
            $updateBpStmt = $pdo->prepare(
                "UPDATE bracket_positions SET team_id = ? WHERE category_id = ? AND position = ?"
            );
            $updateBpStmt->execute([$team_id, $category_id, $available_slot]);
        } else {
            // This error should now be impossible to reach.
            throw new Exception("Could not find an empty bracket slot despite passing the count check.");
        }
    }
    
    if ($is_round_robin && $num_groups > 0) {
        $clusterStmt = $pdo->prepare("SELECT id FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
        $clusterStmt->execute([$category_id]);
        $cluster_ids = $clusterStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($cluster_ids)) {
            $target_cluster_index = $current_count % $num_groups;
            $target_cluster_id = $cluster_ids[$target_cluster_index];
            $updateTeamStmt = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
            $updateTeamStmt->execute([$target_cluster_id, $team_id]);
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