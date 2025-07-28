<?php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $game_id = $data['game_id'] ?? null;

    if (!$game_id) {
        error_log("finalize_game.php: Invalid game ID");
        echo json_encode(['success' => false, 'error' => 'Invalid game ID']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE game SET game_status = 'Completed' WHERE id = ?");
    $stmt->execute([$game_id]);

    if ($stmt->rowCount() === 0) {
        error_log("finalize_game.php: No rows updated for game_id $game_id");
        echo json_encode(['success' => false, 'error' => 'No game found or already completed']);
        exit;
    }

    error_log("finalize_game.php: Successfully set game_status to Completed for game_id $game_id");
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("finalize_game.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>