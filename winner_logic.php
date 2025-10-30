<?php
// FILENAME: winner_logic.php
// DESCRIPTION: Contains the function `processGameResult` which handles the logic for advancing
// winners and losers in single and double elimination brackets after a game is finalized.

/**
 * Updates subsequent game records based on the result of a completed game.
 * Finds the winner and loser, then places them into the correct slots
 * (`hometeam_id` or `awayteam_id`) of the games they advance to.
 *
 * @param PDO $pdo The database connection object.
 * @param int $completed_game_id The ID of the game that just finished.
 */
function processGameResult($pdo, $completed_game_id) {
    try {
        // --- 1. Get Completed Game Details ---
        // Fetch winner, loser, and where they advance (winner/loser bracket game IDs and slots).
        $stmt = $pdo->prepare(
            "SELECT winnerteam_id, hometeam_id, awayteam_id, 
                    winner_advances_to_game_id, winner_advances_to_slot, 
                    loser_advances_to_game_id, loser_advances_to_slot 
             FROM game WHERE id = ?"
        );
        $stmt->execute([$completed_game_id]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        // Exit if game not found or winner isn't set yet.
        if (!$game || $game['winnerteam_id'] === null) {
            return;
        }

        $winner_id = $game['winnerteam_id'];
        // Determine loser ID (the team that is not the winner).
        $loser_id = ($winner_id == $game['hometeam_id']) ? $game['awayteam_id'] : $game['hometeam_id'];

        // --- 2. Process Winner Advancement ---
        // Check if there's a defined path for the winner.
        if ($game['winner_advances_to_game_id'] && $game['winner_advances_to_slot']) {
            // Determine which column ('hometeam_id' or 'awayteam_id') to update.
            $slot_column = ($game['winner_advances_to_slot'] == 'home') ? 'hometeam_id' : 'awayteam_id';
            // Prepare and execute the UPDATE statement for the next game.
            $sql = "UPDATE game SET $slot_column = ? WHERE id = ?";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute([$winner_id, $game['winner_advances_to_game_id']]);
        }

        // --- 3. Process Loser Advancement (for double elimination) ---
        // Check if there's a defined path for the loser.
        if ($game['loser_advances_to_game_id'] && $game['loser_advances_to_slot']) {
            // Determine which column ('hometeam_id' or 'awayteam_id') to update.
            $slot_column = ($game['loser_advances_to_slot'] == 'home') ? 'hometeam_id' : 'awayteam_id';
            // Prepare and execute the UPDATE statement for the next game (in the loser's bracket).
            $sql = "UPDATE game SET $slot_column = ? WHERE id = ?";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute([$loser_id, $game['loser_advances_to_game_id']]);
        }

    } catch (Exception $e) {
        // Log errors to PHP error log if something goes wrong.
        error_log("processGameResult error for game ID {$completed_game_id}: " . $e->getMessage());
        // Do not throw the exception further to prevent interrupting the main script (e.g., finalize_game.php).
    }
}
?>