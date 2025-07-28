<?php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $game_id = $data['game_id'] ?? null;
    $hometeam_score = $data['hometeam_score'] ?? null;
    $awayteam_score = $data['awayteam_score'] ?? null;

    if (!$game_id || !is_numeric($hometeam_score) || !is_numeric($awayteam_score)) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE game 
        SET hometeam_score = ?, awayteam_score = ?
        WHERE id = ? AND game_status != 'Final'
    ");
    $stmt->execute([$hometeam_score, $awayteam_score, $game_id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>