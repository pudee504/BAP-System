<?php
require_once 'db.php';
session_start();
require_once 'logger.php';

// --- HELPER FUNCTION TO GET THE CORRECT SEED ORDER FOR ANY BRACKET SIZE ---
if (!function_exists('getSeedOrder')) {
    function getSeedOrder($num_participants) {
        if ($num_participants <= 1) return [1];
        if (!is_power_of_two($num_participants)) return []; // Safety check
        $seeds = [1, 2];
        for ($round = 1; $round < log($num_participants, 2); $round++) {
            $new_seeds = [];
            $round_max_seed = 2 ** ($round + 1) + 1;
            foreach ($seeds as $seed) {
                $new_seeds[] = $seed;
                $new_seeds[] = $round_max_seed - $seed;
            }
            $seeds = $new_seeds;
        }
        return $seeds;
    }
}
if (!function_exists('is_power_of_two')) {
    function is_power_of_two($n) {
        return ($n > 0) && (($n & ($n - 1)) == 0);
    }
}

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
        die("Cannot generate schedule: The bracket is not locked.");
    }

    // --- SETUP ---
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);
    $seed_query = $pdo->prepare("SELECT bp.seed, bp.team_id FROM bracket_positions bp WHERE bp.category_id = ? AND bp.team_id IS NOT NULL ORDER BY bp.seed ASC");
    $seed_query->execute([$category_id]);
    $teams_by_seed = [];
    while ($row = $seed_query->fetch(PDO::FETCH_ASSOC)) {
        $teams_by_seed[$row['seed']] = $row['team_id'];
    }

    $num_teams = count($teams_by_seed);
    if ($num_teams < 2) {
        die("Error: A minimum of 2 teams is required.");
    }

    $main_bracket_size = $num_teams > 1 ? 2 ** floor(log($num_teams - 1, 2)) : 0;
    if ($num_teams > 2 && $num_teams == $main_bracket_size) { $main_bracket_size = $num_teams; }
    if ($num_teams == 2) { $main_bracket_size = 2; }
    
    $num_prelim_matches = $num_teams - $main_bracket_size;
    $num_byes = $main_bracket_size - $num_prelim_matches;

    $insertGameStmt = $pdo->prepare("INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id) VALUES (?, ?, ?, ?, ?)");
    $all_games_by_round = [];
    $prelim_winner_map = []; 

    // --- PRELIMINARY ROUND ---
    if ($num_prelim_matches > 0) {
        $prelim_game_ids = [];
        $seeds_in_prelims = range($num_byes + 1, $num_teams);
        $prelim_top_half = array_slice($seeds_in_prelims, 0, $num_prelim_matches);
        $prelim_bottom_half = array_reverse(array_slice($seeds_in_prelims, $num_prelim_matches));
        
        for ($i = 0; $i < $num_prelim_matches; $i++) {
            $home_seed = $prelim_top_half[$i];
            $away_seed = $prelim_bottom_half[$i];
            $home_team_id = $teams_by_seed[$home_seed];
            $away_team_id = $teams_by_seed[$away_seed];
            $insertGameStmt->execute([$category_id, 1, 'Preliminary Round', $home_team_id, $away_team_id]);
            $game_id = $pdo->lastInsertId();
            $prelim_game_ids[] = $game_id;
            $prelim_winner_map[$home_seed] = ['type' => 'winner_from_game', 'id' => $game_id];
        }
        $all_games_by_round[1] = $prelim_game_ids;
    }
    
    // --- MAIN BRACKET ROUNDS ---
    $main_round_seeds = getSeedOrder($main_bracket_size);
    $round_participants = [];
    foreach($main_round_seeds as $seed) {
        if ($seed <= $num_byes) {
            $round_participants[] = ['type' => 'team', 'id' => $teams_by_seed[$seed]];
        } else {
            $round_participants[] = $prelim_winner_map[$seed];
        }
    }
    
    $round_counter = 2;
    while (count($round_participants) > 1) {
        $round_name = getRoundName(count($round_participants));
        $next_round_participants = [];
        $games_in_this_round = [];
        for ($i = 0; $i < count($round_participants); $i += 2) {
            $home_p = $round_participants[$i];
            $away_p = $round_participants[$i + 1] ?? null;

            if ($away_p === null) {
                 $next_round_participants[] = $home_p;
                 continue;
            }

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
        if (!empty($games_in_this_round)) {
            $all_games_by_round[$round_counter] = $games_in_this_round;
        }
        $round_participants = $next_round_participants;
        $round_counter++;
    }

    // --- 3RD PLACE MATCH ---
    $semifinal_round_num = $round_counter - 2;
    if ($num_teams > 3 && isset($all_games_by_round[$semifinal_round_num]) && count($all_games_by_round[$semifinal_round_num]) == 2) {
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