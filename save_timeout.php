<?php
require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);

$game_id = $data['game_id'];
$teamA_id = $data['teamA_id'];
$teamB_id = $data['teamB_id'];
$timeouts = $data['timeoutsUsed'];

function saveTimeout($pdo, $game_id, $team_id, $used) {
  $overtimes_json = json_encode($used['overtimes']);
  $stmt = $pdo->prepare("
    INSERT INTO game_timeouts (game_id, team_id, first_half_used, second_half_used, overtimes_used)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      first_half_used = VALUES(first_half_used),
      second_half_used = VALUES(second_half_used),
      overtimes_used = VALUES(overtimes_used)
  ");
  $stmt->execute([
    $game_id,
    $team_id,
    (int)$used['firstHalf'],
    (int)$used['secondHalf'],
    $overtimes_json
  ]);
}

try {
  saveTimeout($pdo, $game_id, $teamA_id, $timeouts['teamA']);
  saveTimeout($pdo, $game_id, $teamB_id, $timeouts['teamB']);

  echo json_encode(['success' => true]);
} catch (Exception $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
