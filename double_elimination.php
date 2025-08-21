<?php
require_once 'db.php';

// --- Helper Functions ---

/**
 * Inserts a game into the database and returns its ID.
 */
function createGame(PDO $pdo, PDOStatement $stmt, int $category_id, string $round_name, ?int $hometeam_id, ?int $awayteam_id): string {
    $stmt->execute([$category_id, $round_name, $hometeam_id, $awayteam_id]);
    return $pdo->lastInsertId();
}

/**
 * Links the winner and loser of a game to their next respective games.
 */
function linkGame(PDOStatement $stmt, int $game_id, ?int $win_game, ?string $win_slot, ?int $lose_game, ?string $lose_slot): void {
    $stmt->execute([
        'win_game' => $win_game, 'win_slot' => $win_slot,
        'lose_game' => $lose_game, 'lose_slot' => $lose_slot,
        'game_id' => $game_id
    ]);
}

/**
 * Generates a proper round name for the Upper Bracket.
 */
function getUpperRoundName(int $num_teams_in_round): string {
    switch ($num_teams_in_round) {
        case 2: return "Upper Bracket Final";
        case 4: return "Upper Bracket Semifinals";
        case 8: return "Upper Bracket Quarterfinals";
        case 16: return "Upper Bracket Round of 16";
        case 32: return "Upper Bracket Round of 32";
        default: return "Upper Bracket";
    }
}


// --- Main Script ---

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Missing category_id.");
}

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

    if (!in_array($total_teams, [4, 8, 16, 32])) {
        $pdo->rollBack();
        die("Only 4, 8, 16, or 32 teams are supported for this double-elimination script.");
    }

    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    $insertGameStmt = $pdo->prepare(
        "INSERT INTO game (category_id, round_name, hometeam_id, awayteam_id, game_status)
         VALUES (?, ?, ?, ?, 'Pending')"
    );
    $updateGameStmt = $pdo->prepare(
        "UPDATE game SET 
            winner_advances_to_game_id = :win_game, winner_advances_to_slot = :win_slot,
            loser_advances_to_game_id = :lose_game, loser_advances_to_slot = :lose_slot
         WHERE id = :game_id"
    );

    // --- BRACKET GENERATION LOGIC ---

    $upper_bracket = [];
    $lower_bracket = [];

    // 1. Create all Upper Bracket Rounds
    $num_games_in_round = $total_teams / 2;
    $round_index = 0;
    while ($num_games_in_round >= 1) {
        $upper_bracket[$round_index] = [];
        $round_name = getUpperRoundName($num_games_in_round * 2);
        for ($i = 0; $i < $num_games_in_round; $i++) {
            $home_team = null;
            $away_team = null;
            // Only populate the very first round with teams
            if ($round_index == 0) {
                $home_team = $teams[$i * 2];
                $away_team = $teams[$i * 2 + 1];
            }
            $upper_bracket[$round_index][] = createGame($pdo, $insertGameStmt, $category_id, $round_name, $home_team, $away_team);
        }
        $num_games_in_round /= 2;
        $round_index++;
    }

    // 2. Create all Lower Bracket Rounds
    $num_lb_rounds = 2 * (log($total_teams, 2) - 1);
    $games_in_lb_round = $total_teams / 4;
    for ($i = 0; $i < $num_lb_rounds; $i++) {
        $lower_bracket[$i] = [];
        $round_name = "";
        if ($i == $num_lb_rounds - 1) {
            $round_name = "Lower Bracket Final";
        } elseif ($i == $num_lb_rounds - 2) {
            $round_name = "Lower Bracket Semifinal";
        } else {
            $round_name = "Lower Bracket Round " . ($i + 1);
        }

        for ($j = 0; $j < $games_in_lb_round; $j++) {
            $lower_bracket[$i][] = createGame($pdo, $insertGameStmt, $category_id, $round_name, null, null);
        }
        
        // Number of games halves after every two rounds
        if ($i % 2 != 0) {
            $games_in_lb_round /= 2;
        }
    }

    // 3. Create Championship Finals
    $grand_final_id = createGame($pdo, $insertGameStmt, $category_id, "Grand Final", null, null);
    $if_necessary_final_id = createGame($pdo, $insertGameStmt, $category_id, "Grand Final (If Necessary)", null, null);

    // --- BRACKET LINKING LOGIC ---

    // 1. Link Upper Bracket rounds and drop-downs to Lower Bracket
    for ($i = 0; $i < count($upper_bracket) - 1; $i++) {
        $current_round_games = $upper_bracket[$i];
        $next_round_games = $upper_bracket[$i + 1];
        
        for ($j = 0; $j < count($current_round_games); $j++) {
            $game_id = $current_round_games[$j];
            $next_game_id = $next_round_games[floor($j / 2)];
            $slot = ($j % 2 == 0) ? 'home' : 'away';

            $loser_game_id = null;
            $loser_slot = null;
            if ($i == 0) { // Losers from the first round drop into the first LB round
                $loser_game_id = $lower_bracket[0][floor($j / 2)];
                $loser_slot = ($j % 2 == 0) ? 'home' : 'away';
            } else { // Losers from subsequent UB rounds drop into later, odd-numbered LB rounds
                $loser_game_id = $lower_bracket[(2 * $i) - 1][$j];
                $loser_slot = 'home';
            }
            linkGame($updateGameStmt, $game_id, $next_game_id, $slot, $loser_game_id, $loser_slot);
        }
    }

    // 2. Link Lower Bracket rounds
    for ($i = 0; $i < count($lower_bracket) - 1; $i++) {
        $current_round_games = $lower_bracket[$i];
        $next_round_games = $lower_bracket[$i + 1];
        
        if ($i % 2 != 0) { // Odd-indexed rounds (1, 3, 5...) are elimination matches before the next drop-downs
             for ($j = 0; $j < count($current_round_games); $j++) {
                $next_game_id = $next_round_games[floor($j / 2)];
                $slot = ($j % 2 == 0) ? 'home' : 'away';
                linkGame($updateGameStmt, $current_round_games[$j], $next_game_id, $slot, null, null);
            }
        } else { // Even-indexed rounds (0, 2, 4...) feed winners to meet the next UB losers
            for ($j = 0; $j < count($current_round_games); $j++) {
                linkGame($updateGameStmt, $current_round_games[$j], $next_round_games[$j], 'away', null, null);
            }
        }
    }

    // 3. Link Finals
    $upper_final_id = end($upper_bracket)[0];
    $lower_final_id = end($lower_bracket)[0];

    // Upper Final: Winner to GF (home), Loser to LF (home)
    linkGame($updateGameStmt, $upper_final_id, $grand_final_id, 'home', $lower_final_id, 'home');

    // Lower Final: Winner to GF (away), Loser is eliminated
    linkGame($updateGameStmt, $lower_final_id, $grand_final_id, 'away', null, null);

    // Grand Final: If the UB champ loses, a reset is forced.
    // Both winner (LB champ) and loser (UB champ) advance to the final match to handle the reset.
    // Your application logic will then determine if this "If Necessary" game is actually played or cancelled.
    linkGame($updateGameStmt, $grand_final_id, $if_necessary_final_id, 'home', $if_necessary_final_id, 'away');
    
    // The "If Necessary" Final: Winner is champion. No further advancement.
    linkGame($updateGameStmt, $if_necessary_final_id, null, null, null, null);

    // Mark schedule as generated
    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    
    $pdo->commit();

    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("An error occurred while generating the double elimination schedule: " . $e->getMessage());
}
