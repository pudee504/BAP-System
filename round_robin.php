<?php
require_once 'db.php';
session_start();
require_once 'logger.php';
require_once 'schedule_helpers.php'; 

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Missing category_id.");
}

// Reverted to the original function which expects 1-based indexing (1=A, 2=B).
if (!function_exists('numberToLetter')) {
    function numberToLetter($num) { 
        return chr(64 + (int)$num); 
    }
}

$pdo->beginTransaction(); 
try {
    $catStmt = $pdo->prepare("SELECT category_name FROM category WHERE id = ?");
    $catStmt->execute([$category_id]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);

    $clusters_stmt = $pdo->prepare("SELECT id, cluster_name FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
    $clusters_stmt->execute([$category_id]);
    $clusters = $clusters_stmt->fetchAll(PDO::FETCH_ASSOC);

    $cluster_map = [];
    foreach ($clusters as $c) {
        // This now correctly converts 1->A, 2->B, etc.
        $cluster_map[$c['id']] = numberToLetter($c['cluster_name']);
    }

    $teams_stmt = $pdo->prepare("SELECT id, team_name, cluster_id FROM team WHERE category_id = ? ORDER BY cluster_id, id ASC");
    $teams_stmt->execute([$category_id]);
    $teams = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);

    $teams_by_cluster = [];
    foreach ($teams as $t) {
        $teams_by_cluster[$t['cluster_id']][] = $t;
    }

    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    $insert = $pdo->prepare("INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status) VALUES (?, ?, ?, ?, ?, 'Pending')");

    foreach ($teams_by_cluster as $cluster_id => $group_teams) {
        $round_name = "Group " . ($cluster_map[$cluster_id] ?? '?');
        $num_teams = count($group_teams);

        if ($num_teams < 2) continue;

        for ($i = 0; $i < $num_teams - 1; $i++) {
            for ($j = $i + 1; $j < $num_teams; $j++) {
                $home = $group_teams[$i];
                $away = $group_teams[$j];

                $insert->execute([
                    $category_id,
                    1, 
                    $round_name,
                    $home['id'],
                    $away['id']
                ]);
                $new_game_id = $pdo->lastInsertId();
                initialize_game_timer($pdo, $new_game_id);
            }
        }
    }

    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);

    $pdo->commit(); 
    log_action('GENERATE_SCHEDULE', 'SUCCESS', "Generated Round Robin schedule for category '{$category['category_name']}'.");

    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    $pdo->rollBack(); 
    log_action('GENERATE_SCHEDULE', 'FAILURE', "Error: " . $e->getMessage());
    die("An error occurred: " . $e->getMessage());
}
?>