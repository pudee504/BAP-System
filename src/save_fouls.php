<?php
require_once 'db.php';
$data = json_decode(file_get_contents('php://input'), true);

$stmt = $pdo->prepare("
  INSERT INTO game_team_foul (game_id, team_id, quarter_id, foul_count)
  VALUES (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE foul_count = VALUES(foul_count)
");
$stmt->execute([$data['game_id'], $data['team_id'], $data['quarter_id'], $data['foul_count']]);

echo json_encode(['success' => true]);
