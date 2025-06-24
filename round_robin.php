<?php
require 'db.php';

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) die("Missing category_id.");

// Prevent re-generation
$check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ?");
$check->execute([$category_id]);
if ($check->fetchColumn()) die("Schedule already generated.");

// Get all clusters (groups) under this category
$clusters_stmt = $pdo->prepare("SELECT id, cluster_name FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
$clusters_stmt->execute([$category_id]);
$clusters = $clusters_stmt->fetchAll();

$cluster_map = []; // e.g. [1 => 'A', 2 => 'B']
foreach ($clusters as $c) {
    $cluster_map[$c['id']] = chr(64 + $c['cluster_name']); // converts 1->A, 2->B, etc.
}

// Get teams by cluster
$teams_stmt = $pdo->prepare("SELECT id, team_name, cluster_id FROM team WHERE category_id = ? ORDER BY cluster_id, id ASC");
$teams_stmt->execute([$category_id]);
$teams = $teams_stmt->fetchAll();

$teams_by_cluster = [];
foreach ($teams as $t) {
    $teams_by_cluster[$t['cluster_id']][] = $t;
}

// Clear old games
$pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

// Insert games (Round Robin per group)
$insert = $pdo->prepare("INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status, game_date)
VALUES (?, ?, ?, ?, ?, 'upcoming', NULL)");

foreach ($teams_by_cluster as $cluster_id => $group_teams) {
    $round_name = "Group " . ($cluster_map[$cluster_id] ?? '?');

    $num_teams = count($group_teams);
    for ($i = 0; $i < $num_teams - 1; $i++) {
        for ($j = $i + 1; $j < $num_teams; $j++) {
            $home = $group_teams[$i];
            $away = $group_teams[$j];

            $insert->execute([
                $category_id,
                1, // Round number isn't meaningful here, set to 1
                $round_name,
                $home['id'],
                $away['id']
            ]);
        }
    }
}

// Mark schedule generated
$pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);

// Redirect
header("Location: category_details.php?category_id=$category_id&tab=schedule");
exit;
