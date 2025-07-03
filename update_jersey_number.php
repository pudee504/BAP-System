<?php
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$game_id = $data['game_id'] ?? '';
$player_id = $data['player_id'] ?? '';
$team_id = $data['team_id'] ?? '';
$jersey_number = $data['jersey_number'] ?? '';

if (!$game_id || !$player_id || !$team_id) {
  echo json_encode(['success' => false, 'error' => 'Missing required fields']);
  exit;
}

$stmt = $pdo->prepare("
  UPDATE player_game 
  SET jersey_number = ? 
  WHERE game_id = ? AND player_id = ? AND team_id = ?
");
$success = $stmt->execute([$jersey_number, $game_id, $player_id, $team_id]);

echo json_encode(['success' => $success]);
