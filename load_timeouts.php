<?php
require_once 'db.php';
$data = json_decode(file_get_contents("php://input"), true);

$game_id = $data['game_id'] ?? null;
$team_id = $data['team_id'] ?? null;
$half = $data['half'] ?? null;
$overtime = $data['overtime'] ?? 0;

function getInitialTimeouts($half, $overtimeCount = 0) {
  if ($half === 1) return 2;
  if ($half === 2) return 3;
  return 1;
}

if ($game_id && $team_id && $half !== null) {
  // Try load
  $stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ?");
  $stmt->execute([$game_id, $team_id, $half]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    echo json_encode(['success' => true, 'remaining' => (int)$row['remaining_timeouts']]);
  } else {
    // Create default
    $initial = getInitialTimeouts($half, $overtime);
    $insert = $pdo->prepare("INSERT INTO game_timeouts (game_id, team_id, half, remaining_timeouts) VALUES (?, ?, ?, ?)");
    $insert->execute([$game_id, $team_id, $half, $initial]);
    echo json_encode(['success' => true, 'remaining' => $initial]);
  }
} else {
  echo json_encode(['success' => false, 'error' => 'Missing data']);
}
