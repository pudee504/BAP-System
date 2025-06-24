<?php
require 'db.php';

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
  die("Missing category_id.");
}

$check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ?");
$check->execute([$category_id]);
if ($check->fetchColumn()) {
  die("Schedule already generated.");
}

$stmt = $pdo->prepare("SELECT id, team_name FROM team WHERE category_id = ? ORDER BY seed ASC");
$stmt->execute([$category_id]);
$teams = $stmt->fetchAll();
$total_teams = count($teams);

if (!in_array($total_teams, [4, 8])) {
  die("Only 4 or 8 teams supported for this format.");
}

$pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

$insert = $pdo->prepare("INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status, game_date)
VALUES (?, ?, ?, ?, ?, 'upcoming', NULL)");

$round = 1;

if ($total_teams == 4) {
  // --- Upper Bracket ---
  $insert->execute([$category_id, $round, "Upper Bracket Semifinal", $teams[0]['id'], $teams[3]['id']]);
  $insert->execute([$category_id, $round, "Upper Bracket Semifinal", $teams[1]['id'], $teams[2]['id']]);

  // UB Final
  $insert->execute([$category_id, ++$round, "Upper Bracket Final", null, null]);

  // LB Semifinal
  $insert->execute([$category_id, ++$round, "Lower Bracket Semifinal", null, null]);

  // LB Final
  $insert->execute([$category_id, ++$round, "Lower Bracket Final", null, null]);

  // Grand Final
  $insert->execute([$category_id, ++$round, "Grand Final", null, null]);

} elseif ($total_teams == 8) {
  // --- Upper Bracket Quarterfinals ---
  $insert->execute([$category_id, $round, "Upper Bracket Quarterfinal", $teams[0]['id'], $teams[7]['id']]);
  $insert->execute([$category_id, $round, "Upper Bracket Quarterfinal", $teams[1]['id'], $teams[6]['id']]);
  $insert->execute([$category_id, $round, "Upper Bracket Quarterfinal", $teams[2]['id'], $teams[5]['id']]);
  $insert->execute([$category_id, $round, "Upper Bracket Quarterfinal", $teams[3]['id'], $teams[4]['id']]);

  // UB Semifinals
  $insert->execute([$category_id, ++$round, "Upper Bracket Semifinal", null, null]);
  $insert->execute([$category_id, $round, "Upper Bracket Semifinal", null, null]);

  // UB Final
  $insert->execute([$category_id, ++$round, "Upper Bracket Final", null, null]);

  // LB Round 1
  $insert->execute([$category_id, 1, "Lower Bracket Round 1", null, null]);
  $insert->execute([$category_id, 1, "Lower Bracket Round 1", null, null]);

  // LB Quarterfinals
  $insert->execute([$category_id, 2, "Lower Bracket Quarterfinal", null, null]);
  $insert->execute([$category_id, 2, "Lower Bracket Quarterfinal", null, null]);

  // LB Semifinal
  $insert->execute([$category_id, 3, "Lower Bracket Semifinal", null, null]);

  // LB Final
  $insert->execute([$category_id, 4, "Lower Bracket Final", null, null]);

  // Grand Final
  $insert->execute([$category_id, 5, "Grand Final", null, null]);
}

$pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);

header("Location: category_details.php?category_id=$category_id&tab=schedule");
exit;
