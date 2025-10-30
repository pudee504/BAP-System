<?php
require_once 'db.php';
session_start();
require_once 'logger.php';
require_once 'schedule_helpers.php'; // Helper functions for round names and timer setup

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Missing category_id.");
}

// --- Converts numeric cluster name (1, 2, 3) into letter (A, B, C) ---
if (!function_exists('numberToLetter')) {
    function numberToLetter($num) { 
        return chr(64 + (int)$num); 
    }
}

$pdo->beginTransaction(); 
try {
    // --- 1. FETCH CATEGORY INFO ---
    $catStmt = $pdo->prepare("SELECT category_name FROM category WHERE id = ?");
    $catStmt->execute([$category_id]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);

    // --- 2. FETCH CLUSTERS UNDER THIS CATEGORY ---
    $clusters_stmt = $pdo->prepare("
        SELECT id, cluster_name 
        FROM cluster 
        WHERE category_id = ? 
        ORDER BY cluster_name ASC
    ");
    $clusters_stmt->execute([$category_id]);
    $clusters = $clusters_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map cluster IDs to letters (e.g., 1 → A, 2 → B)
    $cluster_map = [];
    foreach ($clusters as $c) {
        $cluster_map[$c['id']] = numberToLetter($c['cluster_name']);
    }

    // --- 3. FETCH TEAMS AND GROUP THEM BY CLUSTER ---
    $teams_stmt = $pdo->prepare("
        SELECT id, team_name, cluster_id 
        FROM team 
        WHERE category_id = ? 
        ORDER BY cluster_id, id ASC
    ");
    $teams_stmt->execute([$category_id]);
    $teams = $teams_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize teams per cluster
    $teams_by_cluster = [];
    foreach ($teams as $t) {
        $teams_by_cluster[$t['cluster_id']][] = $t;
    }

    // --- 4. CLEAR EXISTING GAMES BEFORE GENERATING NEW ONES ---
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    // Prepare insert statement for new games
    $insert = $pdo->prepare("
        INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status) 
        VALUES (?, ?, ?, ?, ?, 'Pending')
    ");

    // --- 5. GENERATE ROUND ROBIN MATCHES PER CLUSTER ---
    foreach ($teams_by_cluster as $cluster_id => $group_teams) {
        $round_name = "Group " . ($cluster_map[$cluster_id] ?? '?');
        $num_teams = count($group_teams);

        // Skip clusters with less than 2 teams
        if ($num_teams < 2) continue;

        // Each team faces every other team once
        for ($i = 0; $i < $num_teams - 1; $i++) {
            for ($j = $i + 1; $j < $num_teams; $j++) {
                $home = $group_teams[$i];
                $away = $group_teams[$j];

                // Insert game into database
                $insert->execute([
                    $category_id,
                    1, // Round 1 used since round-robin does not have elimination rounds
                    $round_name,
                    $home['id'],
                    $away['id']
                ]);

                // Initialize timer for this match
                $new_game_id = $pdo->lastInsertId();
                initialize_game_timer($pdo, $new_game_id);
            }
        }
    }

    // --- 6. MARK SCHEDULE AS GENERATED ---
    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);

    $pdo->commit(); 
    log_action('GENERATE_SCHEDULE', 'SUCCESS', "Generated Round Robin schedule for category '{$category['category_name']}'.");

    // Redirect to schedule tab after success
    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    // Rollback on error and log failure
    $pdo->rollBack(); 
    log_action('GENERATE_SCHEDULE', 'FAILURE', "Error: " . $e->getMessage());
    die("An error occurred: " . $e->getMessage());
}
?>
