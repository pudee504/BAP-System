<?php
session_start();
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$game_id = $data['game_id'] ?? null;
$team_id = $data['team_id'] ?? null;
$quarter = $data['quarter'] ?? null;
$fouls = $data['fouls'] ?? null;

if (!$game_id || !$team_id || !$quarter || $fouls === null) {
  echo json_encode(['success' => false, 'error' => 'Missing parameters']);
  exit;
}

$stmt = $pdo->prepare("INSERT INTO game_team_fouls (game_id, team_id, quarter, fouls) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE fouls = VALUES(fouls)");
if ($stmt->execute([$game_id, $team_id, $quarter, $fouls])) {
  echo json_encode(['success' => true]);
} else {
  echo json_encode(['success' => false, 'error' => 'DB error']);
}
