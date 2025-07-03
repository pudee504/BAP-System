<?php
require_once 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$game_id = $data['game_id'] ?? null;
$winner_team_id = $data['winner_team_id'] ?? null;

if (!$game_id || !$winner_team_id) {
  echo json_encode(['success' => false, 'error' => 'Missing game ID or winner team ID']);
  exit;
}

try {
  $stmt = $pdo->prepare("UPDATE game SET winner_team_id = ? WHERE id = ?");
  $stmt->execute([$winner_team_id, $game_id]);

  echo json_encode(['success' => true]);
} catch (PDOException $e) {
  error_log("Error saving winner: " . $e->getMessage());
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
