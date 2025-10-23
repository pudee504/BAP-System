<?php

function processGameResult($pdo, $completed_game_id) {
    try {
        // 1. Get the completed game's details, including the road map and the teams.
        $stmt = $pdo->prepare(
            "SELECT winnerteam_id, hometeam_id, awayteam_id, 
                    winner_advances_to_game_id, winner_advances_to_slot, 
                    loser_advances_to_game_id, loser_advances_to_slot 
             FROM game WHERE id = ?"
        );
        $stmt->execute([$completed_game_id]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game || $game['winnerteam_id'] === null) {
            // No winner set, so nothing to do.
            return;
        }

        $winner_id = $game['winnerteam_id'];
        // Determine the loser by finding the team that is NOT the winner.
        $loser_id = ($winner_id == $game['hometeam_id']) ? $game['awayteam_id'] : $game['hometeam_id'];

        // 2. Process the WINNER'S advancement
        if ($game['winner_advances_to_game_id'] && $game['winner_advances_to_slot']) {
            $slot_column = ($game['winner_advances_to_slot'] == 'home') ? 'hometeam_id' : 'awayteam_id';
            $sql = "UPDATE game SET $slot_column = ? WHERE id = ?";
            
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute([$winner_id, $game['winner_advances_to_game_id']]);
        }

        // 3. Process the LOSER'S advancement
        if ($game['loser_advances_to_game_id'] && $game['loser_advances_to_slot']) {
            $slot_column = ($game['loser_advances_to_slot'] == 'home') ? 'hometeam_id' : 'awayteam_id';
            $sql = "UPDATE game SET $slot_column = ? WHERE id = ?";

            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute([$loser_id, $game['loser_advances_to_game_id']]);
        }

    } catch (Exception $e) {
        error_log("processGameResult error: " . $e->getMessage());
    }
}