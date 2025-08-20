<?php
require 'db.php';
session_start();
require_once 'logger.php'; // << INCLUDE THE LOGGER

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // This isn't an error, just redirecting non-POST requests. No log needed.
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

// Get format and allowed team limit
$stmt = $pdo->prepare("
    SELECT cf.num_teams, cf.format_id, c.category_name
    FROM category_format cf
    JOIN category c ON c.id = cf.category_id
    WHERE c.id = ?
");
$stmt->execute([$category_id]);
$info = $stmt->fetch();

if (!$info) {
    log_action('ADD_TEAM', 'FAILURE', "Attempted to add a team to a non-existent category (ID: {$category_id}).");
    die("Category not found.");
}

$max_teams = (int) $info['num_teams'];
$format_id = (int) $info['format_id'];
$category_name = $info['category_name']; // Get category name for better logs
$is_round_robin = ($format_id === 3);

// Count currently registered teams
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM team WHERE category_id = ?");
$checkStmt->execute([$category_id]);
$current_count = (int) $checkStmt->fetchColumn();

if (!$is_round_robin && $current_count >= $max_teams) {
    $log_details = "Failed to add team '{$team_name}' to category '{$category_name}' (ID: {$category_id}) because the team limit ({$max_teams}) was reached.";
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("Team limit reached. You cannot add more teams.");
}

$cluster_id = null;

// If Round Robin, assign to a group automatically
if ($is_round_robin) {
    // Fetch clusters (groups)
    $clustersStmt = $pdo->prepare("SELECT id FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
    $clustersStmt->execute([$category_id]);
    $clusters = $clustersStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$clusters) {
        log_action('ADD_TEAM', 'FAILURE', "Failed to add team '{$team_name}' because no groups (clusters) were found for Round Robin category '{$category_name}' (ID: {$category_id}).");
        die("No groups found for this category. Please create groups first.");
    }

    // Count teams per cluster
    $countsStmt = $pdo->prepare("
        SELECT cluster_id, COUNT(*) AS team_count
        FROM team
        WHERE category_id = ? AND cluster_id IS NOT NULL
        GROUP BY cluster_id
    ");
    $countsStmt->execute([$category_id]);
    $team_counts = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR); // cluster_id => count

    // Initialize missing counts to 0
    foreach ($clusters as $cid) {
        if (!isset($team_counts[$cid])) {
            $team_counts[$cid] = 0;
        }
    }

    // Sort by fewest teams
    asort($team_counts);
    $cluster_id = array_key_first($team_counts); // choose the cluster with the fewest teams
}

// --- Success Logging ---
try {
    // Add the team
    $insert = $pdo->prepare("INSERT INTO team (category_id, team_name, cluster_id) VALUES (?, ?, ?)");
    $insert->execute([$category_id, $team_name, $cluster_id]);
    $team_id = $pdo->lastInsertId();

    $log_details = "Added team '{$team_name}' (ID: {$team_id}) to category '{$category_name}' (ID: {$category_id}).";
    if ($is_round_robin) {
        $log_details .= " Assigned to group ID: {$cluster_id}.";
    }
    log_action('ADD_TEAM', 'SUCCESS', $log_details);

    // Update num_teams if exceeding initial in Round Robin
    if ($is_round_robin && ($current_count + 1) > $max_teams) {
        $new_team_count = $current_count + 1;
        $update = $pdo->prepare("UPDATE category_format SET num_teams = ? WHERE category_id = ?");
        $update->execute([$new_team_count, $category_id]);

        // Log this special event
        $log_details = "Round Robin team limit for category '{$category_name}' (ID: {$category_id}) auto-incremented from {$max_teams} to {$new_team_count}.";
        log_action('UPDATE_CATEGORY_LIMIT', 'INFO', $log_details);
    }

} catch (PDOException $e) {
    $log_details = "Database error adding team '{$team_name}' to category '{$category_name}'. Error: " . $e->getMessage();
    log_action('ADD_TEAM', 'FAILURE', $log_details);
    die("A database error occurred while adding the team.");
}


header("Location: category_details.php?category_id=" . $category_id . "#teams");
exit;