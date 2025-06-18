<?php
require 'db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$category_id = (int) ($_POST['category_id'] ?? 0);
$team_name = trim($_POST['team_name'] ?? '');

if (!$category_id || !$team_name) {
    die("Missing category or team name.");
}

// Get format and allowed team limit
$stmt = $pdo->prepare("
    SELECT cf.num_teams, cf.format_id
    FROM category_format cf
    JOIN category c ON c.id = cf.category_id
    WHERE c.id = ?
");
$stmt->execute([$category_id]);
$info = $stmt->fetch();

if (!$info) {
    die("Category not found.");
}

$max_teams = (int) $info['num_teams'];
$format_id = (int) $info['format_id'];
$is_round_robin = ($format_id === 3);

// Count currently registered teams
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM team WHERE category_id = ?");
$checkStmt->execute([$category_id]);
$current_count = (int) $checkStmt->fetchColumn();

if (!$is_round_robin && $current_count >= $max_teams) {
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
        die("No groups found for this category.");
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
    $cluster_id = array_key_first($team_counts); // choose the cluster with fewest teams
}

// Add the team
$insert = $pdo->prepare("INSERT INTO team (category_id, team_name, cluster_id) VALUES (?, ?, ?)");
$insert->execute([$category_id, $team_name, $cluster_id]);

// Update num_teams if exceeding initial in Round Robin
if ($is_round_robin && $current_count >= $max_teams) {
    $update = $pdo->prepare("UPDATE category_format SET num_teams = num_teams + 1 WHERE category_id = ?");
    $update->execute([$category_id]);
}

header("Location: category_details.php?category_id=" . $category_id . "#teams");
exit;
