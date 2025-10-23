<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'winner_logic.php'; // This is for bracket advancement

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $game_id = $data['game_id'] ?? null;
    $winner_team_id = $data['winnerteam_id'] ?? null; // This is passed from the "Override" dropdown

    if (!$game_id) {
        throw new Exception('Invalid game ID provided.');
    }

    // 1. Start Transaction
    $pdo->beginTransaction();

    // 2. Fetch all game info (expanded query)
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

    // 3. Validate winner ID
    if ($winner_team_id !== null && $winner_team_id != $game['hometeam_id'] && $winner_team_id != $game['awayteam_id']) {
        throw new Exception('Invalid winner team ID. Winner must be one of the two teams.');
    }

    // 4. Update the game with winner and set status to 'Final'
    // We also update the score in the game table just in case it wasn't saved by the live scoring.
    $stmt = $pdo->prepare("
        UPDATE game SET 
            winnerteam_id = ?, 
            game_status = 'Final',
            hometeam_score = ?,
            awayteam_score = ?
        WHERE id = ?
    ");
    $stmt->execute([$winner_team_id, $game['hometeam_score'], $game['awayteam_score'], $game_id]);

    // 5. Also stop the timer
    $timerStmt = $pdo->prepare("UPDATE game_timer SET running = 0 WHERE game_id = ?");
    $timerStmt->execute([$game_id]);

    // === START: LOGIC TO UPDATE STANDINGS / BRACKETS ===
    
    $isRoundRobinGroupGame = (strtolower($game['format_name']) === 'round robin' && $game['stage'] === 'Group Stage');
    
    if ($isRoundRobinGroupGame) {
        // This is a Round Robin game, so we update cluster_standing
        
        $home_cluster_id = $game['home_cluster_id'];
        $away_cluster_id = $game['away_cluster_id'];

        if ($home_cluster_id && $away_cluster_id && $winner_team_id !== null) {
            
            // Get the loser ID
            $loser_team_id = ($winner_team_id == $game['hometeam_id']) ? $game['awayteam_id'] : $game['hometeam_id'];

            // Update Home Team
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
                $winner_team_id, 
                $loser_team_id,
                $game['hometeam_score'],
                $game['awayteam_score'],
                $home_cluster_id,
                $game['hometeam_id']
            ]);

            // Update Away Team
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
                $winner_team_id,
                $loser_team_id,
                $game['awayteam_score'],
                $game['hometeam_score'],
                $away_cluster_id,
                $game['awayteam_id']
            ]);
        }
    } else {
        // This is a bracket game (Single/Double Elim), so advance the winner
        if ($winner_team_id !== null) {
            processGameResult($pdo, $game_id, $winner_team_id);
        }
    }
    // === END: NEW LOGIC ===

    // 6. Commit all changes
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Winner saved, stats updated, and game finalized!']);

} catch (Exception $e) {
    // If anything fails, roll back
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("save_winner.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>