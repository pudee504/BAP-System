<?php
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$game_id = $data['game_id'] ?? null;
$winner_team_id = $data['winner_team_id'] ?? null;

if (!$game_id || !$winner_team_id) {
  echo json_encode(['success' => false, 'error' => 'Invalid input']);
  exit;
}

try {
  // Verify game exists and teams are valid
  $stmt = $pdo->prepare("
    SELECT hometeam_id, awayteam_id FROM game WHERE id = ?
  ");
  $stmt->execute([$game_id]);
  $game = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$game) {
    echo json_encode(['success' => false, 'error' => 'Game not found']);
    exit;
  }

  if ($winner_team_id != $game['hometeam_id'] && $winner_team_id != $game['awayteam_id']) {
    echo json_encode(['success' => false, 'error' => 'Invalid winner team ID']);
    exit;
  }

  // Update winnerteam_id
  $stmt = $pdo->prepare("
    UPDATE game 
    SET winnerteam_id = ?
    WHERE id = ?
  ");
  $stmt->execute([$winner_team_id, $game_id]);

  echo json_encode(['success' => true]);
} catch (Exception $e) {
  error_log("Error in override_winner.php: " . $e->getMessage());
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>