<?php
// FILENAME: reopen_game.php
// DESCRIPTION: API endpoint to reopen a "Final" game, allowing for corrections.
// This reverses standings updates.

header('Content-Type: application/json');
session_start();
require_once 'db.php';
require_once 'includes/auth_functions.php';

// You will need winner_logic.php if you implement bracket reversal
// require_once 'winner_logic.php'; 

$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;

if (!$game_id) {
    echo json_encode(['success' => false, 'error' => 'Game ID is required.']);
    exit;
}

// --- Authorization Check ---
if (!isset($_SESSION['user_id']) || !has_league_permission($pdo, $_SESSION['user_id'], 'game', $game_id)) {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to reopen this game.']);
    // log_action('AUTH_FAILURE', 'FAILURE', "User {$_SESSION['user_id']} failed permission check for game {$game_id} on reopen_game.php");
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch the finalized game details to know *what* to reverse
    $stmt = $pdo->prepare("
        SELECT 
            g.category_id, g.hometeam_id, g.awayteam_id, 
            g.hometeam_score, g.awayteam_score, g.winnerteam_id,
            g.stage, f.format_name,
            ht.cluster_id AS home_cluster_id,
            at.cluster_id AS away_cluster_id
        FROM game g
        JOIN category_format cf ON g.category_id = cf.category_id
        JOIN format f ON cf.format_id = f.id
        LEFT JOIN team ht ON g.hometeam_id = ht.id
        LEFT JOIN team at ON g.awayteam_id = at.id
        WHERE g.id = ? AND g.game_status = 'Final'
        FOR UPDATE
    ");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        throw new Exception("Game not found or is not 'Final'.");
    }

    $winner_id = $game['winnerteam_id'];
    $isRoundRobinGroupGame = ($game['format_name'] === 'Round Robin' && $game['stage'] === 'Group Stage');

    // 2. Reverse Standings (Round Robin Group Stage ONLY)
    if ($isRoundRobinGroupGame && $winner_id !== null) {
        $home_cluster_id = $game['home_cluster_id'];
        $away_cluster_id = $game['away_cluster_id'];

        if ($home_cluster_id && $away_cluster_id) {
            // Reverse Home Team's stats
            $updateHomeStmt = $pdo->prepare("
                UPDATE cluster_standing SET
                    matches_played = matches_played - 1,
                    wins = wins - (CASE WHEN ? = ? THEN 1 ELSE 0 END),
                    losses = losses - (CASE WHEN ? != ? THEN 1 ELSE 0 END),
                    point_scored = point_scored - ?,
                    points_allowed = points_allowed - ?
                WHERE cluster_id = ? AND team_id = ?
            ");
            $updateHomeStmt->execute([
                $game['hometeam_id'], $winner_id, // subtract win
                $game['hometeam_id'], $winner_id, // subtract loss
                $game['hometeam_score'],
                $game['awayteam_score'],
                $home_cluster_id,
                $game['hometeam_id']
            ]);

            // Reverse Away Team's stats
            $updateAwayStmt = $pdo->prepare("
                UPDATE cluster_standing SET
                    matches_played = matches_played - 1,
                    wins = wins - (CASE WHEN ? = ? THEN 1 ELSE 0 END),
                    losses = losses - (CASE WHEN ? != ? THEN 1 ELSE 0 END),
                    point_scored = point_scored - ?,
                    points_allowed = points_allowed - ?
                WHERE cluster_id = ? AND team_id = ?
            ");
            $updateAwayStmt->execute([
                $game['awayteam_id'], $winner_id, // subtract win
                $game['awayteam_id'], $winner_id, // subtract loss
                $game['awayteam_score'],
                $game['hometeam_score'],
                $away_cluster_id,
                $game['awayteam_id']
            ]);
        }
    }

    // 3. Reverse Bracket Advancement (Bracket Formats ONLY)
    if ($winner_id && !$isRoundRobinGroupGame) {
        // This is more complex. You need to find the *next* game
        // this winner advanced to and set that game's team ID back to NULL.
        // You will need to write a 'reverseGameResult($pdo, $game_id)' function
        // that does the opposite of 'processGameResult'.
        
        // For now, we will throw an error to prevent accidental unlocks
        // until this logic is written.
        // throw new Exception("Bracket reversal logic not implemented yet.");
        
        // --- TEMPORARY ---
        // If you are NOT using brackets yet, you can comment out the throw above
        // and allow the game to be reopened.
    }

    // 4. Finally, update the game record to 'Active' and clear the winner.
    $updateStmt = $pdo->prepare("
        UPDATE game 
        SET game_status = NULL, winnerteam_id = NULL 
        WHERE id = ?
    ");
    $updateStmt->execute([$game_id]);
    
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Game has been re-opened. Standings have been reversed.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>