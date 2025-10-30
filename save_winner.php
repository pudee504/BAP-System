<?php
// FILENAME: save_winner.php
// DESCRIPTION: API endpoint to manually set the winner of a game (override),
// finalize the game status, stop the timer, update standings (if applicable),
// and advance teams in brackets (if applicable).

header('Content-Type: application/json');
require_once 'db.php';
require_once 'winner_logic.php'; // Required for bracket advancement logic

try {
    // --- 1. Get Input ---
    $data = json_decode(file_get_contents('php://input'), true);
    $game_id = $data['game_id'] ?? null;
    $winner_team_id = $data['winnerteam_id'] ?? null; // Team ID selected in the dropdown, or null if 'None'

    if (!$game_id) {
        throw new Exception('Invalid game ID provided.');
    }

    // --- 2. Start Transaction ---
    $pdo->beginTransaction();

    // --- 3. Fetch Game Info ---
    // Get details needed for validation, updating standings, and advancing brackets.
    $stmt = $pdo->prepare("
        SELECT
            g.hometeam_id, g.awayteam_id, g.hometeam_score, g.awayteam_score,
            g.category_id, f.format_name, g.stage,
            ht.cluster_id AS home_cluster_id,
            at.cluster_id AS away_cluster_id
        FROM game g
        JOIN category_format cf ON g.category_id = cf.category_id
        JOIN format f ON cf.format_id = f.id
        LEFT JOIN team ht ON g.hometeam_id = ht.id
        LEFT JOIN team at ON g.awayteam_id = at.id
        WHERE g.id = ?
    ");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        throw new Exception('Game not found.');
    }

    // --- 4. Validate Winner ID ---
    // Ensure the selected winner is actually one of the participating teams (or null).
    if ($winner_team_id !== null && $winner_team_id != $game['hometeam_id'] && $winner_team_id != $game['awayteam_id']) {
        throw new Exception('Invalid winner team ID. Winner must be one of the two teams.');
    }

    // --- 5. Update Game Status and Winner ---
    // Set the winner ID and mark the game as 'Final'.
    // Also, save the current scores from the game table (potentially from live scoring) upon finalization.
    $stmt = $pdo->prepare("
        UPDATE game SET
            winnerteam_id = ?,
            game_status = 'Final',
            hometeam_score = ?,
            awayteam_score = ?
        WHERE id = ?
    ");
    $stmt->execute([$winner_team_id, $game['hometeam_score'], $game['awayteam_score'], $game_id]);

    // --- 6. Stop the Game Timer ---
    $timerStmt = $pdo->prepare("UPDATE game_timer SET running = 0 WHERE game_id = ?");
    $timerStmt->execute([$game_id]);

    // --- 7. Update Standings or Advance Brackets ---
    $isRoundRobinGroupGame = (strtolower($game['format_name']) === 'round robin' && $game['stage'] === 'Group Stage');

    if ($isRoundRobinGroupGame) {
        // --- Round Robin: Update cluster_standing ---
        $home_cluster_id = $game['home_cluster_id'];
        $away_cluster_id = $game['away_cluster_id'];

        // Only update standings if there was a winner (not a tie/forfeit marked as 'None').
        if ($home_cluster_id && $away_cluster_id && $winner_team_id !== null) {
            $loser_team_id = ($winner_team_id == $game['hometeam_id']) ? $game['awayteam_id'] : $game['hometeam_id'];

            // Update stats for the home team.
            $updateHomeStmt = $pdo->prepare("
                UPDATE cluster_standing SET
                    matches_played = matches_played + 1,
                    wins = wins + (CASE WHEN team_id = ? THEN 1 ELSE 0 END),
                    losses = losses + (CASE WHEN team_id = ? THEN 1 ELSE 0 END),
                    point_scored = point_scored + ?,
                    points_allowed = points_allowed + ?
                WHERE cluster_id = ? AND team_id = ?
            ");
            $updateHomeStmt->execute([
                $winner_team_id, $loser_team_id,
                $game['hometeam_score'], $game['awayteam_score'],
                $home_cluster_id, $game['hometeam_id']
            ]);

            // Update stats for the away team.
            $updateAwayStmt = $pdo->prepare("
                UPDATE cluster_standing SET
                    matches_played = matches_played + 1,
                    wins = wins + (CASE WHEN team_id = ? THEN 1 ELSE 0 END),
                    losses = losses + (CASE WHEN team_id = ? THEN 1 ELSE 0 END),
                    point_scored = point_scored + ?,
                    points_allowed = points_allowed + ?
                WHERE cluster_id = ? AND team_id = ?
            ");
            $updateAwayStmt->execute([
                $winner_team_id, $loser_team_id,
                $game['awayteam_score'], $game['hometeam_score'],
                $away_cluster_id, $game['awayteam_id']
            ]);
        }
    } else {
        // --- Bracket Game: Advance Winner/Loser ---
        // Call the function from winner_logic.php to handle bracket advancement.
        if ($winner_team_id !== null) {
            processGameResult($pdo, $game_id, $winner_team_id);
        }
    }

    // --- 8. Commit Transaction ---
    $pdo->commit();
    // TODO: Add success logging.

    echo json_encode(['success' => true, 'message' => 'Winner saved, stats updated, and game finalized!']);

} catch (Exception $e) {
    // --- 9. Handle Errors ---
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("save_winner.php error: " . $e->getMessage()); // Log error for debugging
    // TODO: Add failure logging.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>