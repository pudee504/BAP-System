<?php
require_once 'db.php'; // Ensure this path is correct

$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    die("Error: Missing category_id.");
}

// Wrap the entire process in a transaction for safety
$pdo->beginTransaction();

try {
    // Prevent regeneration by locking the row during the check
    $checkStmt = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ? FOR UPDATE");
    $checkStmt->execute([$category_id]);
    if ($checkStmt->fetchColumn()) {
        $pdo->rollBack(); // Release the lock
        // Redirect with a message instead of dying
        header("Location: category_details.php?id=$category_id&tab=schedule&error=already_generated");
        exit;
    }

    // Get teams, ordered by their seed
    $teamStmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY seed ASC, id ASC");
    $teamStmt->execute([$category_id]);
    $teams = $teamStmt->fetchAll(PDO::FETCH_COLUMN);

    $total_teams = count($teams);
    // A 3rd place match requires at least 4 teams
    if ($total_teams < 4 || ($total_teams & ($total_teams - 1)) !== 0) {
        $pdo->rollBack();
        die("Error: For a single elimination with a 3rd place match, the team count must be a power of 2 and at least 4 (e.g., 4, 8, 16, 32).");
    }

    // Clear any old games for this category
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    // --- Step 1: Create ALL games first and get their IDs ---

    $games_by_round = [];
    $total_rounds = log($total_teams, 2);

    $insertGameStmt = $pdo->prepare(
        "INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status)
         VALUES (?, ?, ?, ?, ?, 'Upcoming')"
    );

    $games_by_round[1] = [];
$round_name = getRoundName(1, $total_teams);
// Loop through the teams array, taking two at a time for sequential matchups
for ($i = 0; $i < $total_teams; $i += 2) {
    // Check to ensure there is a second team to form a pair
    if (isset($teams[$i + 1])) {
        $hometeam_id = $teams[$i];
        $awayteam_id = $teams[$i + 1];
        
        $insertGameStmt->execute([$category_id, 1, $round_name, $hometeam_id, $awayteam_id]);
        $games_by_round[1][] = $pdo->lastInsertId();
    }
}

    // Subsequent rounds up to the final (TBD placeholders)
    for ($round = 2; $round <= $total_rounds; $round++) {
        $games_by_round[$round] = [];
        $num_games_in_round = count($games_by_round[$round - 1]) / 2;
        $round_name = getRoundName($round, $total_teams);
        for ($i = 0; $i < $num_games_in_round; $i++) {
            $insertGameStmt->execute([$category_id, $round, $round_name, null, null]);
            $games_by_round[$round][] = $pdo->lastInsertId();
        }
    }

    // Create the 3rd Place Match separately
    $insertGameStmt->execute([$category_id, $total_rounds, '3rd Place Match', null, null]);
    $third_place_game_id = $pdo->lastInsertId();

    // --- Step 2: Link the games together to create the "road map" ---

    // Link winners of the main bracket
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
    
    // Link losers of the semifinal round to the 3rd place match
    $semifinal_round_num = $total_rounds - 1;
    $semifinal_games = $games_by_round[$semifinal_round_num];

    // Loser of the first semifinal game
    $updateLoserStmt1 = $pdo->prepare(
        "UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'home' WHERE id = ?"
    );
    $updateLoserStmt1->execute([$third_place_game_id, $semifinal_games[0]]);

    // Loser of the second semifinal game
    $updateLoserStmt2 = $pdo->prepare(
        "UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'away' WHERE id = ?"
    );
    $updateLoserStmt2->execute([$third_place_game_id, $semifinal_games[1]]);

    // --- Final Step: Mark schedule as generated and commit ---

    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    
    // If we reach here, everything was successful. Make the changes permanent.
    $pdo->commit();

    // Redirect back to the schedule view
    // Ensure this line looks exactly like this:
    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    // If any error occurred, undo all database changes from this script
    $pdo->rollBack();
    // Provide a detailed error message for debugging
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