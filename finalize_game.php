<?php
require_once 'db.php';
$data = json_decode(file_get_contents("php://input"), true);
$game_id = $data['game_id'] ?? '';

if (!$game_id) {
  echo json_encode(['success' => false, 'error' => 'Missing game ID']);
  exit;
}

$stmt = $pdo->prepare("UPDATE game SET game_status = 'Final' WHERE id = ?");
if ($stmt->execute([$game_id])) {
  echo json_encode(['success' => true]);
} else {
  echo json_encode(['success' => false, 'error' => 'Database update failed']);
}
?>
