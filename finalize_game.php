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
        SELECT hometeam_id, awayteam_id, hometeam_score, awayteam_score 
        FROM game 
        WHERE id = ? 
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

    // STEP 2: Call the function to advance the winner/loser to the next game
    if ($winner_id) {
        processGameResult($pdo, $game_id);
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Game finalized and bracket updated successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>