<?php
header('Content-Type: application/json');
require_once 'db.php';
// STEP 1: Include the winner logic file
require_once 'winner_logic.php'; 

$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;

if (!$game_id) {
    echo json_encode(['success' => false, 'error' => 'Game ID is required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch the game details and scores
    $stmt = $pdo->prepare("
        SELECT g.category_id, g.hometeam_id, g.awayteam_id, g.hometeam_score, g.awayteam_score,
               g.stage, f.format_name
        FROM game g
        JOIN category_format cf ON g.category_id = cf.category_id
        JOIN format f ON cf.format_id = f.id
        WHERE g.id = ? 
        FOR UPDATE
    ");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        throw new Exception("Game not found.");
    }

    // 2. Determine the winner based on the score
    $winner_id = null;
    if ((int)$game['hometeam_score'] > (int)$game['awayteam_score']) {
        $winner_id = $game['hometeam_id'];
    } elseif ((int)$game['awayteam_score'] > (int)$game['hometeam_score']) {
        $winner_id = $game['awayteam_id'];
    }
    
    // 3. Update the game record with the status and winner's ID
    $updateStmt = $pdo->prepare("
        UPDATE game 
        SET game_status = 'Final', winnerteam_id = ? 
        WHERE id = ?
    ");
    $updateStmt->execute([$winner_id, $game_id]);
    
    // 4. Also stop the timer
    $timerStmt = $pdo->prepare("UPDATE game_timer SET running = 0 WHERE game_id = ?");
    $timerStmt->execute([$game_id]);

    // === START: NEW LOGIC for cluster_standing ===
    $isRoundRobinGroupGame = ($game['format_name'] === 'Round Robin' && $game['stage'] === 'Group Stage');
    
    if ($isRoundRobinGroupGame && $winner_id !== null) {
        // Get cluster IDs for both teams
        $clusterStmt = $pdo->prepare("SELECT cluster_id FROM team WHERE id = ?");
        
        $clusterStmt->execute([$game['hometeam_id']]);
        $home_cluster_id = $clusterStmt->fetchColumn();
        
        $clusterStmt->execute([$game['awayteam_id']]);
        $away_cluster_id = $clusterStmt->fetchColumn();

        if ($home_cluster_id && $away_cluster_id) {
            // Update Home Team
            $updateHomeStmt = $pdo->prepare("
                UPDATE cluster_standing SET
                    matches_played = matches_played + 1,
                    wins = wins + (CASE WHEN ? = ? THEN 1 ELSE 0 END),
                    losses = losses + (CASE WHEN ? = ? THEN 0 ELSE 1 END),
                    point_scored = point_scored + ?,
                    points_allowed = points_allowed + ?
                WHERE cluster_id = ? AND team_id = ?
            ");
            $updateHomeStmt->execute([
                $game['hometeam_id'], $winner_id, // for wins
                $game['hometeam_id'], $winner_id, // for losses
                $game['hometeam_score'],
                $game['awayteam_score'],
                $home_cluster_id,
                $game['hometeam_id']
            ]);

            // Update Away Team
            $updateAwayStmt = $pdo->prepare("
                UPDATE cluster_standing SET
                    matches_played = matches_played + 1,
                    wins = wins + (CASE WHEN ? = ? THEN 1 ELSE 0 END),
                    losses = losses + (CASE WHEN ? = ? THEN 0 ELSE 1 END),
                    point_scored = point_scored + ?,
                    points_allowed = points_allowed + ?
                WHERE cluster_id = ? AND team_id = ?
            ");
            $updateAwayStmt->execute([
                $game['awayteam_id'], $winner_id, // for wins
                $game['awayteam_id'], $winner_id, // for losses
                $game['awayteam_score'],
                $game['hometeam_score'],
                $away_cluster_id,
                $game['awayteam_id']
            ]);
        }
    }
    // === END: NEW LOGIC ===

    // STEP 5: Call advanceWinner (Modified)
    // Only advance if it's NOT a round robin group game
    if ($winner_id && !$isRoundRobinGroupGame) {
        processGameResult($pdo, $game_id);
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Game finalized and updates applied successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>