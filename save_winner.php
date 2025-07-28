<?php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $game_id = $data['game_id'] ?? null;
    $winner_team_id = $data['winnerteam_id'] ?? null;

    if (!$game_id) {
        error_log("save_winner.php: Invalid game ID");
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    // Verify game exists
    $stmt = $pdo->prepare("SELECT hometeam_id, awayteam_id FROM game WHERE id = ?");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        error_log("save_winner.php: Game not found for game_id $game_id");
        echo json_encode(['success' => false, 'error' => 'Game not found']);
        exit;
    }

    // Validate winner_team_id only if it's not null
    if ($winner_team_id !== null && 
        $winner_team_id != $game['hometeam_id'] && 
        $winner_team_id != $game['awayteam_id']) {
        error_log("save_winner.php: Invalid winner team ID $winner_team_id for game_id $game_id");
        echo json_encode(['success' => false, 'error' => 'Invalid winner team ID']);
        exit;
    }

    // Update winnerteam_id
    $stmt = $pdo->prepare("UPDATE game SET winnerteam_id = ? WHERE id = ?");
    $stmt->execute([$winner_team_id, $game_id]);

    if ($stmt->rowCount() === 0) {
        error_log("save_winner.php: No rows updated for game_id $game_id");
        echo json_encode(['success' => false, 'error' => 'No game found']);
        exit;
    }

    error_log("save_winner.php: Successfully set winnerteam_id to $winner_team_id for game_id $game_id");
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("save_winner.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>