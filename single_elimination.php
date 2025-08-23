<?php
require_once 'db.php';
session_start();
require_once 'logger.php';

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Error: Missing category_id.");
}

$pdo->beginTransaction();
try {
    $catStmt = $pdo->prepare("SELECT c.category_name, c.playoff_seeding_locked FROM category c WHERE c.id = ?");
    $catStmt->execute([$category_id]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);

    if (!$category || !$category['playoff_seeding_locked']) {
        die("Cannot generate schedule: The bracket is not locked or the category was not found.");
    }

    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);
    $posStmt = $pdo->prepare("SELECT team_id FROM bracket_positions WHERE category_id = ? AND team_id IS NOT NULL ORDER BY position ASC");
    $posStmt->execute([$category_id]);
    $teams_in_order = $posStmt->fetchAll(PDO::FETCH_COLUMN);
    $num_teams = count($teams_in_order);

    // *** THIS IS THE FIX ***
    // The check is now lowered to 2 teams, allowing 3-team brackets to be generated.
    if ($num_teams < 2) {
        die("Error: A minimum of 2 teams is required to generate a bracket.");
    }

    // --- BRACKET CALCULATION ---
    $next_power_of_two = 2 ** ceil(log($num_teams, 2));
    $main_bracket_size = $next_power_of_two / 2;
    $num_prelim_matches = $num_teams - $main_bracket_size;
    $num_teams_in_prelims = $num_prelim_matches * 2;
    $num_byes = $num_teams - $num_teams_in_prelims;

    $insertGameStmt = $pdo->prepare("INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id) VALUES (?, ?, ?, ?, ?)");
    $all_games_by_round = [];
    $round_participants = [];

    // --- PRELIMINARY ROUND & BYES ---
    $teams_with_byes = array_slice($teams_in_order, 0, $num_byes);
    $teams_in_prelims = array_slice($teams_in_order, $num_byes);
    
    if ($num_prelim_matches > 0) {
        $prelim_game_ids = [];
        for ($i = 0; $i < $num_teams_in_prelims; $i += 2) {
            $insertGameStmt->execute([$category_id, 1, 'Preliminary Round', $teams_in_prelims[$i], $teams_in_prelims[$i+1]]);
            $game_id = $pdo->lastInsertId();
            $prelim_game_ids[] = $game_id;
            $round_participants[] = ['type' => 'winner_from_game', 'id' => $game_id];
        }
        $all_games_by_round[1] = $prelim_game_ids;
    }
    foreach ($teams_with_byes as $team_id) {
        $round_participants[] = ['type' => 'team', 'id' => $team_id];
    }
    
    // --- MAIN BRACKET ROUNDS ---
    $round_counter = 2;
    while (count($round_participants) > 1) {
        $round_name = getRoundName(count($round_participants));
        $next_round_participants = [];
        $games_in_this_round = [];
        for ($i = 0; $i < count($round_participants); $i += 2) {
            $home_p = $round_participants[$i];
            $away_p = $round_participants[$i + 1];
            $hometeam_id = ($home_p['type'] == 'team') ? $home_p['id'] : null;
            $awayteam_id = ($away_p['type'] == 'team') ? $away_p['id'] : null;
            $insertGameStmt->execute([$category_id, $round_counter, $round_name, $hometeam_id, $awayteam_id]);
            $new_game_id = $pdo->lastInsertId();
            $games_in_this_round[] = $new_game_id;
            if ($home_p['type'] == 'winner_from_game') {
                $pdo->prepare("UPDATE game SET winner_advances_to_game_id = ?, winner_advances_to_slot = 'home' WHERE id = ?")->execute([$new_game_id, $home_p['id']]);
            }
            if ($away_p['type'] == 'winner_from_game') {
                 $pdo->prepare("UPDATE game SET winner_advances_to_game_id = ?, winner_advances_to_slot = 'away' WHERE id = ?")->execute([$new_game_id, $away_p['id']]);
            }
            $next_round_participants[] = ['type' => 'winner_from_game', 'id' => $new_game_id];
        }
        $all_games_by_round[$round_counter] = $games_in_this_round;
        $round_participants = $next_round_participants;
        $round_counter++;
    }

    // --- 3RD PLACE MATCH (only runs if there were exactly 2 semifinal games) ---
    $semifinal_round_num = $round_counter - 2;
    if (isset($all_games_by_round[$semifinal_round_num]) && count($all_games_by_round[$semifinal_round_num]) == 2) {
        $semifinal_games = $all_games_by_round[$semifinal_round_num];
        $insertGameStmt->execute([$category_id, $semifinal_round_num, '3rd Place Match', null, null]);
        $third_place_game_id = $pdo->lastInsertId();
        $pdo->prepare("UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'home' WHERE id = ?")->execute([$third_place_game_id, $semifinal_games[0]]);
        $pdo->prepare("UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'away' WHERE id = ?")->execute([$third_place_game_id, $semifinal_games[1]]);
    }

    // --- FINALIZE ---
    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    $pdo->commit();
    
    log_action('GENERATE_SCHEDULE', 'SUCCESS', "Generated schedule for category '{$category['category_name']}'.");
    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    log_action('GENERATE_SCHEDULE', 'FAILURE', "Error: " . $e->getMessage());
    die("An error occurred: " . $e->getMessage());
}

function getRoundName($num_participants) {
    if ($num_participants == 2) return 'Finals';
    if ($num_participants == 4) return 'Semifinals';
    if ($num_participants == 8) return 'Quarterfinals';
    return "Round of " . $num_participants;
}
?>