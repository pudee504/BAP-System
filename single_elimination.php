<?php
require_once 'db.php'; // Ensure this path is correct
session_start(); // Start session for logging
require_once 'logger.php'; // Include the logger

$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
    die("Error: Missing category_id.");
}

// Wrap the entire process in a transaction for safety
$pdo->beginTransaction();

try {
    // --- PRE-GENERATION CHECKS with LOGGING ---
    $catStmt = $pdo->prepare("SELECT category_name FROM category WHERE id = ?");
    $catStmt->execute([$category_id]);
    $category_name = $catStmt->fetchColumn();

    $lockStmt = $pdo->prepare("SELECT is_locked FROM category_format WHERE category_id = ?");
    $lockStmt->execute([$category_id]);
    if (!$lockStmt->fetchColumn()) {
        $log_details = "Failed to generate schedule for category '{$category_name}' (ID: {$category_id}). Reason: Seedings were not locked.";
        log_action('GENERATE_SCHEDULE', 'FAILURE', $log_details);
        $pdo->rollBack();
        die("Cannot generate schedule: Seedings are not locked.");
    }

    $checkStmt = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ? FOR UPDATE");
    $checkStmt->execute([$category_id]);
    if ($checkStmt->fetchColumn()) {
        $log_details = "Failed to generate schedule for category '{$category_name}' (ID: {$category_id}) because a schedule already exists.";
        log_action('GENERATE_SCHEDULE', 'FAILURE', $log_details);
        $pdo->rollBack();
        header("Location: category_details.php?category_id=$category_id&tab=schedule&error=already_generated");
        exit;
    }

    $teamStmt = $pdo->prepare("SELECT id FROM team WHERE category_id = ? ORDER BY seed ASC, id ASC");
    $teamStmt->execute([$category_id]);
    $teams = $teamStmt->fetchAll(PDO::FETCH_COLUMN);
    $total_teams = count($teams);

    if ($total_teams < 4 || ($total_teams & ($total_teams - 1)) !== 0) {
        $log_details = "Failed to generate schedule for category '{$category_name}' (ID: {$category_id}). Reason: Invalid team count ({$total_teams}) for Single Elimination.";
        log_action('GENERATE_SCHEDULE', 'FAILURE', $log_details);
        $pdo->rollBack();
        die("Error: For a single elimination with a 3rd place match, the team count must be a power of 2 and at least 4 (e.g., 4, 8, 16, 32).");
    }

    // --- YOUR ORIGINAL SCHEDULE GENERATION LOGIC ---
    $games_created_count = 0; // Initialize counter

    // Clear any old games for this category
    $pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

    $games_by_round = [];
    $total_rounds = log($total_teams, 2);

    $insertGameStmt = $pdo->prepare(
        "INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status)
         VALUES (?, ?, ?, ?, ?, 'Pending')"
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
            $games_created_count++; // Increment counter
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
            $games_created_count++; // Increment counter
        }
    }

    // Create the 3rd Place Match separately
    $insertGameStmt->execute([$category_id, $total_rounds, '3rd Place Match', null, null]);
    $third_place_game_id = $pdo->lastInsertId();
    $games_created_count++; // Increment counter

    // Link winners of the main bracket
    for ($round = 1; $round < $total_rounds; $round++) {
        $current_round_games = $games_by_round[$round];
        $next_round_games = $games_by_round[$round + 1];
        for ($i = 0; $i < count($current_round_games); $i++) {
            $game_id_to_update = $current_round_games[$i];
            $next_game_id = $next_round_games[floor($i / 2)];
            $slot = ($i % 2 == 0) ? 'home' : 'away';
            $updateStmt = $pdo->prepare("UPDATE game SET winner_advances_to_game_id = ?, winner_advances_to_slot = ? WHERE id = ?");
            $updateStmt->execute([$next_game_id, $slot, $game_id_to_update]);
        }
    }
    
    // Link losers of the semifinal round to the 3rd place match
    $semifinal_round_num = $total_rounds - 1;
    $semifinal_games = $games_by_round[$semifinal_round_num];
    $updateLoserStmt1 = $pdo->prepare("UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'home' WHERE id = ?");
    $updateLoserStmt1->execute([$third_place_game_id, $semifinal_games[0]]);
    $updateLoserStmt2 = $pdo->prepare("UPDATE game SET loser_advances_to_game_id = ?, loser_advances_to_slot = 'away' WHERE id = ?");
    $updateLoserStmt2->execute([$third_place_game_id, $semifinal_games[1]]);

    // Mark schedule as generated and commit
    $pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);
    
    $pdo->commit();

    // --- SUMMARY LOGGING ---
    $log_details = "Generated a Single Elimination schedule with {$games_created_count} games for category '{$category_name}' (ID: {$category_id}).";
    log_action('GENERATE_SCHEDULE', 'SUCCESS', $log_details);

    header("Location: category_details.php?category_id=$category_id&tab=schedule&success=true");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    // Log the exception
    log_action('GENERATE_SCHEDULE', 'FAILURE', "Error generating schedule for category ID {$category_id}. Error: " . $e->getMessage());
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