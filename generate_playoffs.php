<?php
require_once 'db.php';

// Helper function for round names
function getBracketRoundName($format, $bracket_type, $round_num, $total_rounds) {
    if ($format === 'single_elimination') {
        if ($round_num == $total_rounds) return 'Final';
        if ($round_num == $total_rounds - 1) return 'Semifinals';
        if ($round_num == $total_rounds - 2) return 'Quarterfinals';
        return 'Round of ' . pow(2, ($total_rounds - $round_num + 1));
    }
    if ($format === 'double_elimination') {
        $prefix = ($bracket_type === 'UB') ? 'Upper Bracket ' : 'Lower Bracket ';
        if ($bracket_type === 'UB') {
            if ($round_num == $total_rounds) return 'Upper Bracket Final';
            if ($round_num == $total_rounds - 1) return $prefix . 'Semifinal';
            if ($round_num == $total_rounds - 2) return $prefix . 'Quarterfinal';
            return $prefix . 'Round ' . $round_num;
        } else {
            return $prefix . 'Round ' . $round_num;
        }
    }
    return "Round " . $round_num;
}


$category_id = $_POST['category_id'] ?? null;
$playoff_format = $_POST['playoff_format'] ?? null;
if (!$category_id || !$playoff_format) {
    die("Missing required information.");
}

$pdo->beginTransaction();
try {
    // --- STEP 1 & 2: GET AND SEED ADVANCING TEAMS (Unchanged) ---
    $format_stmt = $pdo->prepare("SELECT advance_per_group FROM category_format WHERE category_id = ?");
    $format_stmt->execute([$category_id]);
    $advance_per_group = $format_stmt->fetchColumn();
    if (!$advance_per_group) throw new Exception("Could not find 'advance_per_group' setting.");

    $wins_stmt = $pdo->prepare("SELECT t.id, t.cluster_id, COALESCE(w.wins, 0) AS wins FROM team t LEFT JOIN (SELECT winnerteam_id, COUNT(*) as wins FROM game WHERE category_id = :category_id AND stage = 'Group Stage' AND winnerteam_id IS NOT NULL GROUP BY winnerteam_id) w ON t.id = w.winnerteam_id WHERE t.category_id = :category_id ORDER BY t.cluster_id, wins DESC, t.id ASC");
    $wins_stmt->execute(['category_id' => $category_id]);
    $standings = $wins_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $teams_by_cluster = [];
    foreach ($standings as $team) { $teams_by_cluster[$team['cluster_id']][] = $team; }
    
    $playoff_teams_seeded = [];
    for ($i = 0; $i < $advance_per_group; $i++) {
        ksort($teams_by_cluster);
        foreach ($teams_by_cluster as $teams) {
            if (isset($teams[$i])) { $playoff_teams_seeded[] = $teams[$i]['id']; }
        }
    }
    
    $total_playoff_teams = count($playoff_teams_seeded);
    if ($total_playoff_teams < 2 || ($total_playoff_teams & ($total_playoff_teams - 1)) !== 0) {
        throw new Exception("The total advancing teams ($total_playoff_teams) must be a power of 2.");
    }

    // --- STEP 3: GENERATE PLAYOFF BRACKET ---
    
    $insertGameStmt = $pdo->prepare("INSERT INTO game (category_id, round_name, stage, hometeam_id, awayteam_id, game_status) VALUES (?, ?, 'Playoff', ?, ?, 'Upcoming')");
    
    if ($playoff_format === 'single_elimination') {
        // ... (This logic is correct and remains unchanged) ...
    } 
    elseif ($playoff_format === 'double_elimination') {
        
        // =================================================================
        //  ## NEW: SPECIAL CASE FOR 4 TEAMS ##
        // =================================================================
        if ($total_playoff_teams == 4) {
            // Step 1: Create the 6 games needed and get their IDs
            $insertGameStmt->execute([$category_id, "Upper Bracket Semifinal", $playoff_teams_seeded[0], $playoff_teams_seeded[3]]); $ub_semi1_id = $pdo->lastInsertId();
            $insertGameStmt->execute([$category_id, "Upper Bracket Semifinal", $playoff_teams_seeded[1], $playoff_teams_seeded[2]]); $ub_semi2_id = $pdo->lastInsertId();
            $insertGameStmt->execute([$category_id, "Lower Bracket Semifinal", null, null]); $lb_semi_id = $pdo->lastInsertId();
            $insertGameStmt->execute([$category_id, "Upper Bracket Final", null, null]); $ub_final_id = $pdo->lastInsertId();
            $insertGameStmt->execute([$category_id, "Lower Bracket Final", null, null]); $lb_final_id = $pdo->lastInsertId();
            $insertGameStmt->execute([$category_id, "Grand Final", null, null]); $grand_final_id = $pdo->lastInsertId();

            // Step 2: Link the bracket
            $updateStmt = $pdo->prepare("UPDATE game SET winner_advances_to_game_id = :w_id, winner_advances_to_slot = :w_slot, loser_advances_to_game_id = :l_id, loser_advances_to_slot = :l_slot WHERE id = :g_id");
            $updateStmt->execute(['w_id' => $ub_final_id, 'w_slot' => 'home', 'l_id' => $lb_semi_id, 'l_slot' => 'home', 'g_id' => $ub_semi1_id]);
            $updateStmt->execute(['w_id' => $ub_final_id, 'w_slot' => 'away', 'l_id' => $lb_semi_id, 'l_slot' => 'away', 'g_id' => $ub_semi2_id]);
            $updateStmt->execute(['w_id' => $lb_final_id, 'w_slot' => 'away', 'l_id' => null, 'l_slot' => null, 'g_id' => $lb_semi_id]);
            $updateStmt->execute(['w_id' => $grand_final_id, 'w_slot' => 'home', 'l_id' => $lb_final_id, 'l_slot' => 'home', 'g_id' => $ub_final_id]);
            $updateStmt->execute(['w_id' => $grand_final_id, 'w_slot' => 'away', 'l_id' => null, 'l_slot' => null, 'g_id' => $lb_final_id]);

        } else {
            // --- LOGIC FOR 8, 16, 32 TEAMS (Unchanged) ---
            $ub_games = []; $lb_games = [];
            $total_ub_rounds = log($total_playoff_teams, 2);

            // Create UB games
            for ($round = 1; $round <= $total_ub_rounds; $round++) {
                $ub_games[$round] = [];
                $round_name = getBracketRoundName('double_elimination', 'UB', $round, $total_ub_rounds);
                for ($i = 0; $i < $total_playoff_teams / pow(2, $round); $i++) {
                    $hometeam = ($round === 1) ? $playoff_teams_seeded[$i] : null;
                    $awayteam = ($round === 1) ? $playoff_teams_seeded[$total_playoff_teams - 1 - $i] : null;
                    $insertGameStmt->execute([$category_id, $round_name, $hometeam, $awayteam]);
                    $ub_games[$round][] = $pdo->lastInsertId();
                }
            }

            // Create LB games
            $total_lb_rounds = ($total_ub_rounds - 1) * 2;
            for ($round = 1; $round <= $total_lb_rounds; $round++) {
                $lb_games[$round] = [];
                $round_name = getBracketRoundName('double_elimination', 'LB', $round, $total_lb_rounds);
                $num_games = ($round % 2 != 0) ? count($ub_games[($round + 1) / 2]) : count($lb_games[$round - 1]) / 2;
                for ($i = 0; $i < $num_games; $i++) {
                    $insertGameStmt->execute([$category_id, $round_name, null, null]);
                    $lb_games[$round][] = $pdo->lastInsertId();
                }
            }

            // Create Grand Final
            $insertGameStmt->execute([$category_id, "Grand Final", null, null]);
            $grand_final_id = $pdo->lastInsertId();

            // Link the bracket
            $updateStmt = $pdo->prepare("UPDATE game SET winner_advances_to_game_id = :w_id, winner_advances_to_slot = :w_slot, loser_advances_to_game_id = :l_id, loser_advances_to_slot = :l_slot WHERE id = :g_id");
            
            for ($round = 1; $round <= $total_ub_rounds; $round++) {
                foreach ($ub_games[$round] as $i => $game_id) {
                    $win_game = ($round < $total_ub_rounds) ? $ub_games[$round + 1][floor($i / 2)] : $grand_final_id;
                    $win_slot = ($round < $total_ub_rounds) ? (($i % 2 == 0) ? 'home' : 'away') : 'home';
                    $lose_game = ($round == 1) ? $lb_games[1][floor($i/2)] : (($round < $total_ub_rounds) ? $lb_games[($round-1)*2][floor($i/2)] : end($lb_games[$total_lb_rounds]));
                    $lose_slot = ($round == 1) ? (($i % 2 == 0) ? 'home' : 'away') : (($round < $total_ub_rounds) ? 'away' : 'home');
                    $updateStmt->execute(['w_id' => $win_game, 'w_slot' => $win_slot, 'l_id' => $lose_game, 'l_slot' => $lose_slot, 'g_id' => $game_id]);
                }
            }

            for ($round = 1; $round <= $total_lb_rounds; $round++) {
                foreach ($lb_games[$round] as $i => $game_id) {
                    $win_game = ($round < $total_lb_rounds) ? $lb_games[$round + 1][($round % 2 != 0) ? $i : floor($i/2)] : $grand_final_id;
                    $win_slot = ($round < $total_lb_rounds) ? (($round % 2 != 0) ? 'home' : (($i % 2 == 0) ? 'home' : 'away')) : 'away';
                    $updateStmt->execute(['w_id' => $win_game, 'w_slot' => $win_slot, 'l_id' => null, 'l_slot' => null, 'g_id' => $game_id]);
                }
            }
        }
    }

    $pdo->commit();
    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=playoffs_generated");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("An error occurred while generating playoffs: " . $e->getMessage());
}