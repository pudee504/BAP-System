<?php
require_once 'db.php';

$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    die("Missing category_id.");
}

// Wrap the entire process in a transaction for safety
$pdo->beginTransaction();

try {
    // Prevent regeneration
    $check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ? FOR UPDATE");
    $check->execute([$category_id]);
    if ($check->fetchColumn()) {
        $pdo->rollBack();
        die("Schedule already generated.");
    }

    // Get teams, ordered by their seed
    $stmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY seed ASC, id ASC");
    $stmt->execute([$category_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $total_teams = count($teams);
    if ($total_teams < 4 || ($total_teams & ($total_teams - 1)) !== 0) {
        $pdo->rollBack();
        die("For a 3rd place match, team count must be a power of 2 and at least 4 (e.g., 4, 8, 16, 32).");
    }

    // Clear any old games for this category
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    // --- Step 1: Create ALL games first and get their IDs ---

    $games_by_round = [];
    $total_rounds = log($total_teams, 2);

    // Prepare the insert statement
    $stmt = $pdo->prepare(
        "INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status)
         VALUES (?, ?, ?, ?, ?, 'Upcoming')"
    );

    // Round 1 (with teams)
    $games_by_round[1] = [];
    $round_name = getRoundName(1, $total_teams);
    for ($i = 0; $i < $total_teams / 2; $i++) {
        $stmt->execute([$category_id, 1, $round_name, $teams[$i], $teams[$total_teams - 1 - $i]]);
        $games_by_round[1][] = $pdo->lastInsertId();
    }

    // Subsequent rounds (TBD placeholders)
    for ($round = 2; $round <= $total_rounds; $round++) {
        $games_by_round[$round] = [];
        $num_games_in_round = count($games_by_round[$round - 1]) / 2;
        $round_name = getRoundName($round, $total_teams);
        for ($i = 0; $i < $num_games_in_round; $i++) {
            $stmt->execute([$category_id, $round, $round_name, null, null]);
            $games_by_round[$round][] = $pdo->lastInsertId();
        }
    }

    // Create the 3rd Place Match
    $stmt->execute([$category_id, $total_rounds, '3rd Place Match', null, null]);
    $third_place_game_id = $pdo->lastInsertId();

    // --- Step 2: Link the games together using their IDs ---

    // Link main bracket winners
    for ($round = 1; $round < $total_rounds; $round++) {
        $current_round_games = $games_by_round[$round];
        $next_round_games = $games_by_round[$round + 1];
        
        for ($i = 0; $i < count($current_round_games); $i++) {
            $game_id_to_update = $current_round_games[$i];
            $next_game_id = $next_round_games[floor($i / 2)];
            $slot = ($i % 2 == 0) ? 'home' : 'away';

            $updateStmt = $pdo->prepare(
                "UPDATE game SET winner_advances_to_game_id = ?, winner_advances_to_slot = ? WHERE id = ?"
            );
            $updateStmt->execute([$next_game_id, $slot, $game_id_to_update]);
        }
    }
    
    // --- Step 3: Link the semifinal losers to the 3rd place match ---

    $semifinal_round = $total_rounds - 1;
    $semifinal_games = $games_by_round[$semifinal_round];

    // Link loser of the first semifinal game
    $updateStmt = $pdo->prepare(
        "UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'home' WHERE id = ?"
    );
    $updateStmt->execute([$third_place_game_id, $semifinal_games[0]]);

    // Link loser of the second semifinal game
    $updateStmt = $pdo->prepare(
        "UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'away' WHERE id = ?"
    );
    $updateStmt->execute([$third_place_game_id, $semifinal_games[1]]);

    // --- Final Step: Mark schedule as generated and commit ---

    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    $pdo->commit();

    // Redirect back to the schedule view
    header("Location: category_details.php?id=$category_id&tab=schedule");
    exit;

} catch (Exception $e) {
    // If anything went wrong, roll back all changes
    $pdo->rollBack();
    die("An error occurred while generating the schedule: " . $e->getMessage());
}


// Helper function to get round names
function getRoundName($round_number, $total_teams) {
    if ($total_teams == 4)  return ['Semifinals', 'Finals'][$round_number - 1] ?? "Round $round_number";
    if ($total_teams == 8)  return ['Quarterfinals', 'Semifinals', 'Finals'][$round_number - 1] ?? "Round $round_number";
    if ($total_teams == 16) return ['Round of 16', 'Quarterfinals', 'Semifinals', 'Finals'][$round_number - 1] ?? "Round $round_number";
    if ($total_teams == 32) return ['Round of 32', 'Round of 16', 'Quarterfinals', 'Semifinals', 'Finals'][$round_number - 1] ?? "Round $round_number";
    return "Round $round_number";
}
?>