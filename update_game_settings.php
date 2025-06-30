<?php
require_once 'db.php';

$game_id = $_POST['game_id'] ?? null;
if (!$game_id) die('No game ID.');

$fields = [];
$params = [];

foreach (['q1', 'q2', 'q3', 'q4'] as $q) {
  $input_min = isset($_POST["{$q}_duration"]) ? (int)$_POST["{$q}_duration"] : 10;
  $duration_sec = max(1, min($input_min, 12)) * 60;
  $fields[] = "{$q}_duration = ?";
  $params[] = $duration_sec;
}

$params[] = $game_id;

$sql = "UPDATE game_settings SET " . implode(", ", $fields) . " WHERE game_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header("Location: start_game.php?game_id=" . urlencode($game_id));
exit;
?>