<?php
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['game_id'], $data['player_id'], $data['team_id'], $data['statistic_name'], $data['value'])) {
  http_response_code(400);
  error_log("Invalid input in update_stat.php: " . json_encode($data));
  echo json_encode(['error' => 'Invalid input']);
  exit;
}

try {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare("
    INSERT INTO game_stat (game_id, player_id, team_id, statistic_id, quarter_id, value)
    SELECT ?, ?, ?, s.id, q.id, ?
    FROM statistic s, quarter q
    WHERE s.statistic_name = ? AND q.quarter_name = 'Q1'
    ON DUPLICATE KEY UPDATE value = value + ?
  ");
  $stmt->execute([
    $data['game_id'],
    $data['player_id'],
    $data['team_id'],
    $data['value'],
    $data['statistic_name'],
    $data['value']
  ]);
  $pdo->commit();
  echo json_encode(['success' => true]);
} catch (Exception $e) {
  $pdo->rollBack();
  error_log("Error in update_stat.php: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Failed to update stat']);
}

exit;
?>