<?php
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['game_id'], $data['player_id'], $data['team_id'])) {
  http_response_code(400);
  error_log("Invalid input in update_player_status.php: " . json_encode($data));
  echo json_encode(['error' => 'Invalid input']);
  exit;
}

try {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare("
    UPDATE player_game
    SET is_playing = ?, jersey_number = ?
    WHERE game_id = ? AND player_id = ? AND team_id = ?
  ");
  $stmt->execute([
    $data['is_playing'] ?? 0,
    isset($data['jersey_number']) ? $data['jersey_number'] : null,
    $data['game_id'],
    $data['player_id'],
    $data['team_id']
  ]);
  $pdo->commit();
  echo json_encode(['success' => true]);
} catch (Exception $e) {
  $pdo->rollBack();
  error_log("Error in update_player_status.php: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Failed to update player status']);
}

exit;
?>