<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'winner_logic.php'; 

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $game_id = $data['game_id'] ?? null;
    $winner_team_id = $data['winnerteam_id'] ?? null;

    if (!$game_id) {
        throw new Exception('Invalid game ID provided.');
    }

    // --- Validation (Your existing code is good) ---
    $stmt = $pdo->prepare("SELECT hometeam_id, awayteam_id FROM game WHERE id = ?");
    $stmt->execute([$game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        throw new Exception('Game not found.');
    }
    if ($winner_team_id !== null && $winner_team_id != $game['hometeam_id'] && $winner_team_id != $game['awayteam_id']) {
        throw new Exception('Invalid winner team ID.');
    }
    // --- End Validation ---
    
    // 2. Start a transaction
    $pdo->beginTransaction();

    // 3. Update the winner (your original query)
    $stmt = $pdo->prepare("UPDATE game SET winnerteam_id = ? WHERE id = ?");
    $stmt->execute([$winner_team_id, $game_id]);

    // 4. Call the advanceWinner function!
    processGameResult($pdo, $game_id, $winner_team_id);
    
    // 5. Commit all changes if successful
    $pdo->commit();

    error_log("Successfully set winner and advanced for game_id $game_id");
    echo json_encode(['success' => true, 'message' => 'Winner saved and advanced successfully!']);

} catch (Exception $e) {
    // If anything fails, roll back all database changes
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("save_winner.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>