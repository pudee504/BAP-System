<?php
session_start();
require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);
$game_id = $data['game_id'];
$game_clock = $data['game_clock'];
$shot_clock = $data['shot_clock'];
$quarter_id = $data['quarter_id'];  // ✅ Consistent key
$running = $data['running'] ? 1 : 0;

$_SESSION['game_timers'][$game_id] = [
  'game_clock' => $game_clock,
  'shot_clock' => $shot_clock,
  'quarter_id' => $quarter_id, // ✅ Fix here
  'running' => $running
];

$pdo->prepare("
  REPLACE INTO game_timer (game_id, game_clock, shot_clock, quarter_id, running)
  VALUES (?, ?, ?, ?, ?)
")->execute([$game_id, $game_clock, $shot_clock, $quarter_id, $running]);

echo json_encode(['success' => true]);
