<?php
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$game_id = $data['game_id'];
$team_id = $data['team_id'];
$half = $data['half'];

$stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ?");
$stmt->execute([$game_id, $team_id, $half]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && $row['remaining_timeouts'] > 0) {
  $new_count = $row['remaining_timeouts'] - 1;
  $update = $pdo->prepare("UPDATE game_timeouts SET remaining_timeouts = ? WHERE game_id = ? AND team_id = ? AND half = ?");
  $update->execute([$new_count, $game_id, $team_id, $half]);
  echo json_encode(['success' => true, 'remaining' => $new_count]);
} else {
  echo json_encode(['success' => false, 'error' => 'No timeouts left']);
}
