<?php
require_once 'db.php';

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Missing category_id.");
}

// Start a transaction for an all-or-nothing operation
$pdo->beginTransaction();

try {
    // Check if schedule is already generated
    $check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ? FOR UPDATE");
    $check->execute([$category_id]);
    if ($check->fetchColumn()) {
        $pdo->rollBack();
        header("Location: category_details.php?id=$category_id&tab=schedule&error=already_generated");
        exit;
    }

    // Get teams ordered by seed
    $stmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY seed ASC, id ASC");
    $stmt->execute([$category_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $total_teams = count($teams);

    // New validation: team count must be a power of 2
    if ($total_teams < 4 || ($total_teams & ($total_teams - 1)) !== 0) {
        $pdo->rollBack();
        die("Error: Team count must be a power of 2 (4, 8, 16, or 32).");
    }

    // Clear any old games for this category
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    // Reusable insert statement
    $insertGameStmt = $pdo->prepare(
        "INSERT INTO game (category_id, round_name, hometeam_id, awayteam_id, game_status)
         VALUES (?, ?, ?, ?, 'Upcoming')"
    );

    // --- STEP 1: Programmatically Create All Game Placeholders ---

    $ub_games = []; // To store IDs of upper bracket games, organized by round
    $lb_games = []; // To store IDs of lower bracket games
    
    $total_ub_rounds = log($total_teams, 2);

    // 1A: Create Upper Bracket games
    for ($round = 1; $round <= $total_ub_rounds; $round++) {
        $ub_games[$round] = [];
        $num_games_in_round = $total_teams / pow(2, $round);
        for ($i = 0; $i < $num_games_in_round; $i++) {
            $hometeam = null;
            $awayteam = null;
            if ($round === 1) { // Assign teams only in the first round
                $hometeam = $teams[$i];
                $awayteam = $teams[$total_teams - 1 - $i];
            }
            $insertGameStmt->execute([$category_id, "UB Round $round, Game " . ($i + 1), $hometeam, $awayteam]);
            $ub_games[$round][] = $pdo->lastInsertId();
        }
    }

    // 1B: Create Lower Bracket games
    $total_lb_rounds = ($total_ub_rounds - 1) * 2;
    for ($round = 1; $round <= $total_lb_rounds; $round++) {
        $lb_games[$round] = [];
        // The number of games in lower bracket rounds alternates
        $num_games_in_round = ($round % 2 != 0) ? count($ub_games[($round + 1) / 2]) : count($lb_games[$round - 1]) / 2;
        for ($i = 0; $i < $num_games_in_round; $i++) {
            $insertGameStmt->execute([$category_id, "LB Round $round, Game " . ($i + 1), null, null]);
            $lb_games[$round][] = $pdo->lastInsertId();
        }
    }

    // 1C: Create Grand Final
    $insertGameStmt->execute([$category_id, "Grand Final", null, null]);
    $grand_final_id = $pdo->lastInsertId();

    // --- STEP 2: Link the Entire Bracket Using the Stored IDs ---

    $updateStmt = $pdo->prepare(
        "UPDATE game SET 
            winner_advances_to_game_id = :win_game, winner_advances_to_slot = :win_slot,
            loser_advances_to_game_id = :lose_game, loser_advances_to_slot = :lose_slot
         WHERE id = :game_id"
    );

    // 2A: Link Upper Bracket games
    for ($round = 1; $round <= $total_ub_rounds; $round++) {
        foreach ($ub_games[$round] as $i => $game_id) {
            $win_game = null; $win_slot = null;
            $lose_game = null; $lose_slot = null;

            // Link winner to next UB round (except for the final)
            if ($round < $total_ub_rounds) {
                $win_game = $ub_games[$round + 1][floor($i / 2)];
                $win_slot = ($i % 2 == 0) ? 'home' : 'away';
            } else { // Winner of UB Final goes to Grand Final
                $win_game = $grand_final_id;
                $win_slot = 'home';
            }

            // Link loser to LB
            if ($round == 1) { // Losers from UB Round 1
                $lose_game = $lb_games[1][floor($i / 2)];
                $lose_slot = ($i % 2 == 0) ? 'home' : 'away';
            } else if ($round < $total_ub_rounds) { // Losers from other UB rounds (not final)
                $lb_round_to_drop_to = ($round - 1) * 2;
                $lose_game = $lb_games[$lb_round_to_drop_to][floor($i/2)];
                $lose_slot = 'away';
            } else { // Loser of UB Final drops to LB Final
                $lose_game = end($lb_games[$total_lb_rounds]);
                $lose_slot = 'home';
            }
            $updateStmt->execute(compact('win_game', 'win_slot', 'lose_game', 'lose_slot', 'game_id'));
        }
    }

    // 2B: Link Lower Bracket games
    for ($round = 1; $round <= $total_lb_rounds; $round++) {
        foreach ($lb_games[$round] as $i => $game_id) {
            $win_game = null; $win_slot = null;
            
            if ($round < $total_lb_rounds) { // If it's not the last LB round
                // In odd rounds, winners play against UB losers. In even rounds, they play each other.
                $next_game_idx = ($round % 2 != 0) ? $i : floor($i / 2);
                $win_game = $lb_games[$round + 1][$next_game_idx];
                $win_slot = ($round % 2 != 0) ? 'home' : (($i % 2 == 0) ? 'home' : 'away');
            } else { // Winner of the LB Final goes to Grand Final
                $win_game = $grand_final_id;
                $win_slot = 'away';
            }
            
            // Loser is eliminated
            $lose_game = null; $lose_slot = null;
            $updateStmt->execute(compact('win_game', 'win_slot', 'lose_game', 'lose_slot', 'game_id'));
        }
    }

    // --- Final Step: Mark as generated and commit ---

    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    $pdo->commit();

    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("An error occurred while generating the schedule: " . $e->getMessage());
}