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
    $totalGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ?");
    $totalGamesStmt->execute([$original_category_id]);
    $total_games = $totalGamesStmt->fetchColumn();

    $finishedGamesStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $finishedGamesStmt->execute([$original_category_id]);
    $finished_games = $finishedGamesStmt->fetchColumn();

    if ($total_games === 0 || $total_games !== $finished_games) {
        throw new Exception("Cannot proceed to playoffs until all round robin games are completed.");
    }
    
    // --- STEP 3: Fetch and calculate final standings to get the advancing teams ---
    $teamsStmt = $pdo->prepare("SELECT t.id, t.team_name, c.cluster_name FROM team t JOIN cluster c ON t.cluster_id = c.id WHERE t.category_id = ?");
    $teamsStmt->execute([$original_category_id]);
    $all_teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

    $standings = [];
    foreach ($all_teams as $team) {
        $standings[$team['id']] = ['team_id' => $team['id'], 'team_name' => $team['team_name'], 'cluster_name' => $team['cluster_name'], 'w' => 0, 'ps' => 0, 'pa' => 0];
    }

    $gamesStmt = $pdo->prepare("SELECT * FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $gamesStmt->execute([$original_category_id]);
    $games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $head_to_head = [];
    foreach ($games as $game) {
        $home_id = $game['hometeam_id']; $away_id = $game['awayteam_id'];
        $head_to_head[$home_id][$away_id] = $game['winnerteam_id'];
        $head_to_head[$away_id][$home_id] = $game['winnerteam_id'];
        if (isset($standings[$home_id])) {
            if ($game['winnerteam_id'] == $home_id) { $standings[$home_id]['w']++; }
            $standings[$home_id]['ps'] += $game['hometeam_score']; $standings[$home_id]['pa'] += $game['awayteam_score'];
        }
        if (isset($standings[$away_id])) {
            if ($game['winnerteam_id'] == $away_id) { $standings[$away_id]['w']++; }
            $standings[$away_id]['ps'] += $game['awayteam_score']; $standings[$away_id]['pa'] += $game['hometeam_score'];
        }
    }

    $grouped_standings = [];
    foreach ($standings as $team_stats) {
        $team_stats['pd'] = $team_stats['ps'] - $team_stats['pa'];
        $grouped_standings[$team_stats['cluster_name']][] = $team_stats;
    }
    
    foreach ($grouped_standings as &$group) {
        usort($group, function($a, $b) use ($head_to_head) {
            if ($b['w'] !== $a['w']) { return $b['w'] <=> $a['w']; }
            if (isset($head_to_head[$a['team_id']][$b['team_id']])) {
                if ($head_to_head[$a['team_id']][$b['team_id']] == $a['team_id']) return -1;
                return 1;
            }
            return $b['pd'] <=> $a['pd'];
        });
    }
    unset($group);

    $advancing_teams = [];
    foreach ($grouped_standings as $group) {
        $advancing_teams = array_merge($advancing_teams, array_slice($group, 0, $advance_per_group));
    }
    $num_playoff_teams = count($advancing_teams);

    // --- STEP 4: Create the new playoff category and teams within a transaction ---
    $pdo->beginTransaction();

    // 4a. Create the new category record
    $createCatStmt = $pdo->prepare("INSERT INTO category (league_id, category_name) VALUES (?, ?)");
    $createCatStmt->execute([$original_league_id, $playoff_category_name]);
    $new_category_id = $pdo->lastInsertId();

    // 4b. Create the format record for the new category
    // format_id for Single Elimination is 1
    $createFormatStmt = $pdo->prepare("INSERT INTO category_format (category_id, format_id, num_teams) VALUES (?, 1, ?)");
    $createFormatStmt->execute([$new_category_id, $num_playoff_teams]);

    // 4c. Create bracket positions for the new category, replicating logic from add_team.php
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

