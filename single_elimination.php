<?php
require 'db.php';

$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
  die("Missing category_id.");
}

$check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ?");
$check->execute([$category_id]);
$already = $check->fetchColumn();
if ($already) {
  die("Schedule already generated.");
}

// Fetch teams sorted by seed
$stmt = $pdo->prepare("SELECT id, team_name FROM team WHERE category_id = ? ORDER BY seed ASC");
$stmt->execute([$category_id]);
$teams = $stmt->fetchAll();

$total_teams = count($teams);
if ($total_teams < 2 || ($total_teams & ($total_teams - 1)) !== 0) {
  die("Team count must be a power of 2 (e.g., 4, 8, 16).");
}

// Optional: Clear existing games
$pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

$round = 1;
$games = [];
$game_refs = [];

// Create first round matchups (real team IDs)
for ($i = 0; $i < $total_teams / 2; $i++) {
  $home = $teams[$i];
  $away = $teams[$total_teams - 1 - $i];
  $games[] = [
    'round' => $round,
    'home_id' => $home['id'],
    'away_id' => $away['id'],
    'home_placeholder' => null,
    'away_placeholder' => null
  ];
  $game_refs[] = "Game " . (count($games)); // Used for next round
}

// Generate future rounds
$current_round_games = $total_teams / 2;

while ($current_round_games > 1) {
  $round++;
  $next_round_games = $current_round_games / 2;

  for ($i = 0; $i < $next_round_games; $i++) {
    $games[] = [
      'round' => $round,
      'home_id' => null,
      'away_id' => null,
      'home_placeholder' => "Winner of " . $game_refs[$i * 2],
      'away_placeholder' => "Winner of " . $game_refs[$i * 2 + 1]
    ];
    $game_refs[] = "Game " . (count($games));
  }

  $current_round_games = $next_round_games;
}

// Insert games into database
$stmt = $pdo->prepare("INSERT INTO game (category_id, round, hometeam_id, awayteam_id, game_status, game_date)
VALUES (?, ?, ?, ?, 'upcoming', NULL)");


foreach ($games as $g) {
  $stmt->execute([
    $category_id,
    $g['round'],
    $g['home_id'],
    $g['away_id']
  ]);
}

$pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);


header("Location: category_details.php?category_id=$category_id&tab=schedule");
exit;
