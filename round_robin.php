<?php
require_once 'db.php';
session_start();
require_once 'logger.php';
require_once 'schedule_helpers.php'; // ADDED HELPER

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Missing category_id.");
}

$pdo->beginTransaction(); // **FIX: ADDED TRANSACTION**
try {
    $catStmt = $pdo->prepare("SELECT category_name FROM category WHERE id = ?");
    $catStmt->execute([$category_id]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);

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

    // Clear old games to allow for regeneration
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    // Prepare statement for inserting games
    $insert = $pdo->prepare("INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status, game_date) VALUES (?, ?, ?, ?, ?, 'Pending', NULL)");

    // Generate Round Robin matchups for each group
    foreach ($teams_by_cluster as $cluster_id => $group_teams) {
        $round_name = "Group " . ($cluster_map[$cluster_id] ?? '?');
        $num_teams = count($group_teams);

        if ($num_teams < 2) continue; // Skip groups with less than 2 teams

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
                $new_game_id = $pdo->lastInsertId();
                initialize_game_timer($pdo, $new_game_id); // **FIX: INITIALIZE TIMER**
            }
        }
    }

    // Mark schedule as generated
    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);

    $pdo->commit(); // **FIX: COMMIT TRANSACTION**
    log_action('GENERATE_SCHEDULE', 'SUCCESS', "Generated Round Robin schedule for category '{$category['category_name']}'.");

    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    $pdo->rollBack(); // **FIX: ROLLBACK ON ERROR**
    log_action('GENERATE_SCHEDULE', 'FAILURE', "Error: " . $e->getMessage());
    die("An error occurred: " . $e->getMessage());
}
?>