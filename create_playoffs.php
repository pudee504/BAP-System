<?php
session_start();
require 'db.php'; // Your database connection file

// --- Validation: Ensure the request is valid ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['category_id'])) {
    $_SESSION['message'] = "Invalid request.";
    header('Location: index.php');
    exit;
}

$original_category_id = (int)$_POST['category_id'];

try {
    // --- STEP 1: Fetch original category and format details using a JOIN ---
    $catStmt = $pdo->prepare("
        SELECT c.league_id, c.category_name, cf.advance_per_group
        FROM category c
        JOIN category_format cf ON c.id = cf.category_id
        WHERE c.id = ?
    ");
    $catStmt->execute([$original_category_id]);
    $original_category = $catStmt->fetch(PDO::FETCH_ASSOC);

    if (!$original_category) {
        throw new Exception("Original category not found.");
    }

    $advance_per_group = (int)$original_category['advance_per_group'];
    $playoff_category_name = $original_category['category_name'] . ' - Playoffs';
    $original_league_id = $original_category['league_id'];

    // --- STEP 2: Verify all games in the original category are completed ---
    $totalGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND stage = 'Group Stage'");
    $totalGamesStmt->execute([$original_category_id]);
    $total_games = $totalGamesStmt->fetchColumn();

    $finishedGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL AND stage = 'Group Stage'");
    $finishedGamesStmt->execute([$original_category_id]);
    $finished_games = $finishedGamesStmt->fetchColumn();

    if ($total_games === 0 || $total_games !== $finished_games) {
        throw new Exception("Cannot proceed to playoffs until all round robin games are completed.");
    }

    // --- STEP 3: Fetch and calculate final standings to get the advancing teams ---
    
    // *** USE THE PRE-CALCULATED cluster_standing TABLE INSTEAD ***
    $standingsStmt = $pdo->prepare("
        SELECT
            t.id AS team_id,
            t.team_name,
            c.cluster_name,
            cs.wins AS w,
            (cs.point_scored - cs.points_allowed) AS pd,
            cs.point_scored AS ps
        FROM
            cluster_standing AS cs
        JOIN
            team AS t ON cs.team_id = t.id
        JOIN
            cluster AS c ON cs.cluster_id = c.id
        WHERE
            c.category_id = ?
    ");
    $standingsStmt->execute([$original_category_id]);
    $all_standings_raw = $standingsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch head-to-head data for tie-breaking
    $gamesStmt = $pdo->prepare("SELECT hometeam_id, awayteam_id, winnerteam_id FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $gamesStmt->execute([$original_category_id]);
    $games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $head_to_head = [];
    foreach ($games as $game) {
        $head_to_head[$game['hometeam_id']][$game['awayteam_id']] = $game['winnerteam_id'];
        $head_to_head[$game['awayteam_id']][$game['hometeam_id']] = $game['winnerteam_id'];
    }

    $grouped_standings = [];
    foreach ($all_standings_raw as $team_stats) {
        $grouped_standings[$team_stats['cluster_name']][] = $team_stats;
    }
    ksort($grouped_standings); // Sort groups by cluster_name (0, 1, 2...)
    
    // Sort teams within each group
    foreach ($grouped_standings as &$group) {
        usort($group, function($a, $b) use ($head_to_head) {
            // 1. By Wins
            if ($b['w'] !== $a['w']) { return $b['w'] <=> $a['w']; }
            
            // 2. By Head-to-head
            $team_a_id = $a['team_id']; $team_b_id = $b['team_id'];
            if (isset($head_to_head[$team_a_id][$team_b_id])) {
                $winner_id = $head_to_head[$team_a_id][$team_b_id];
                if ($winner_id == $team_a_id) return -1; // A won
                if ($winner_id == $team_b_id) return 1;  // B won
            }
            
            // 3. By Point Differential
            if ($b['pd'] !== $a['pd']) { return $b['pd'] <=> $a['pd']; }
            
            // 4. By Points Scored
            return $b['ps'] <=> $a['ps'];
        });
    }
    unset($group);

    $advancing_teams = [];
    foreach ($grouped_standings as $group) {
        $advancing_teams = array_merge($advancing_teams, array_slice($group, 0, $advance_per_group));
    }
    $num_playoff_teams = count($advancing_teams);
    
    if ($num_playoff_teams === 0) {
        throw new Exception("No teams are eligible to advance.");
    }

    // --- STEP 4: Create the new playoff category and teams within a transaction ---
    $pdo->beginTransaction();

    // 4a. Create the new category record
    $createCatStmt = $pdo->prepare("INSERT INTO category (league_id, category_name) VALUES (?, ?)");
    $createCatStmt->execute([$original_league_id, $playoff_category_name]);
    $new_category_id = $pdo->lastInsertId();

    // 4b. Create the format record for the new category (Single Elimination = 1)
    $createFormatStmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams) VALUES (?, 1, ?)");
    $createFormatStmt->execute([$new_category_id, $num_playoff_teams]);

    // 4c. Create bracket positions for the new category
    $createSlotsStmt = $pdo->prepare("INSERT INTO bracket_positions (category_id, position, seed) VALUES (?, ?, ?)");
    for ($i = 1; $i <= $num_playoff_teams; $i++) {
        $createSlotsStmt->execute([$new_category_id, $i, $i]);
    }

    // 4d. Add the advancing teams and assign them to the newly created bracket positions
    $addTeamStmt = $pdo->prepare("INSERT INTO team (category_id, team_name) VALUES (?, ?)");
    $updateSlotStmt = $pdo->prepare("UPDATE bracket_positions SET team_id = ? WHERE category_id = ? AND position = ?");
    
    $position = 1;
    foreach ($advancing_teams as $team) {
        // Add the team to the 'team' table
        $addTeamStmt->execute([$new_category_id, $team['team_name']]);
        $new_team_id = $pdo->lastInsertId();
        
        // Assign the new team to the next available bracket slot
        $updateSlotStmt->execute([$new_team_id, $new_category_id, $position]);
        $position++;
    }
    
    // === THIS IS THE NEW LINE YOU MUST ADD ===
    // 4e. Link the original category to this new playoff category
    $linkStmt = $pdo->prepare("UPDATE category SET playoff_category_id = ? WHERE id = ?");
    $linkStmt->execute([$new_category_id, $original_category_id]);
    // === END OF NEW LINE ===
    
    $pdo->commit();

    // --- STEP 5: Redirect to the new category page ---
    $_SESSION['message'] = "Playoff category created successfully!";
    header("Location: category_details.php?category_id={$new_category_id}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['message'] = "Error creating playoffs: " . $e->getMessage();
    header("Location: category_details.php?category_id={$original_category_id}&tab=standings");
    exit;
}
?>