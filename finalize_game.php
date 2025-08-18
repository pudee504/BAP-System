<?php
header('Content-Type: application/json');
require_once 'db.php'; // Your database connection
require_once 'winner_logic.php'; 

// --- MAIN LOGIC ---

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $game_id = $data['game_id'] ?? null;

    if (!$game_id) {
        throw new Exception('Invalid game ID provided.');
    }

    // Begin a transaction to ensure all updates happen or none do
    $pdo->beginTransaction();

    // 1. Get game scores to determine the winner
    $stmt = $pdo->prepare("SELECT hometeam_id, awayteam_id, hometeam_score, awayteam_score FROM game WHERE id = ?");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        throw new Exception('Game not found.');
    }

    $winner_id = null;
    if ($game['hometeam_score'] > $game['awayteam_score']) {
        $winner_id = $game['hometeam_id'];
    } elseif ($game['awayteam_score'] > $game['hometeam_score']) {
        $winner_id = $game['awayteam_id'];
    } else {
        // Handle a tie - can't finalize a tied game in an elimination bracket
        throw new Exception('Cannot finalize a tied game.');
    }

    // 2. Update the game with the winner and set status to 'Completed'
    $updateStmt = $pdo->prepare("UPDATE game SET game_status = 'Completed', winnerteam_id = ? WHERE id = ?");
    $updateStmt->execute([$winner_id, $game_id]);

    if ($updateStmt->rowCount() === 0) {
        throw new Exception('Failed to update the game status.');
    }

    // 3. Call the function to advance the winner to the next round
    advanceWinner($pdo, $game_id, $winner_id);

    // If all steps were successful, commit the changes
    $pdo->commit();

    error_log("Successfully finalized and advanced winner for game_id $game_id");
    echo json_encode(['success' => true, 'message' => 'Game finalized and winner advanced!']);

} catch (Exception $e) {
    // If any step failed, roll back all database changes
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("finalize_game.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>