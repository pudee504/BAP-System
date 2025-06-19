<?php
require 'db.php';

$game_id = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
$category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
$game_date = $_POST['game_date'] ?? '';

if (!$game_id || !$category_id || !$game_date) {
  die("Invalid input.");
}

$stmt = $pdo->prepare("UPDATE game SET game_date = ? WHERE id = ?");
$stmt->execute([$game_date, $game_id]);

header("Location: category_details.php?category_id=$category_id&tab=schedule");
exit;

