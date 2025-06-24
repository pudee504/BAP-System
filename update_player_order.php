\<?php
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['game_id'], $data['team_id'], $data['order'])) {
  http_response_code(400);
  error_log("Invalid input in update_player_order.php: " . json_encode($data));
  echo json_encode(['error' => 'Invalid input']);
  exit;
}

try {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare("UPDATE player_game SET display_order = ? WHERE game_id = ? AND team_id = ? AND player_id = ?");
  foreach ($data['order'] as $index => $player_id) {
    $stmt->execute([$index, $data['game_id'], $data['team_id'], $player_id]);
  }
  $pdo->commit();
  echo json_encode(['success' => true]);
} catch (Exception $e) {
  $pdo->rollBack();
  error_log("Error in update_player_order.php: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Failed to update player order']);
}

exit;
?>