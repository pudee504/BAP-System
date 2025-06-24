<?php
require 'db.php';

$category_id = $_POST['category_id'] ?? null;

if (!$category_id) {
  die("Missing category_id.");
}

// Prevent regeneration
$check = $pdo->prepare("SELECT schedule_generated FROM category WHERE id = ?");
$check->execute([$category_id]);
if ($check->fetchColumn()) {
  die("Schedule already generated.");
}

// Get teams
$stmt = $pdo->prepare("SELECT id, team_name FROM team WHERE category_id = ? ORDER BY seed ASC");
$stmt->execute([$category_id]);
$teams = $stmt->fetchAll();

$total_teams = count($teams);
if ($total_teams < 2 || ($total_teams & ($total_teams - 1)) !== 0) {
  die("Team count must be a power of 2 (e.g., 4, 8, 16, 32).");
}

// Clear old games
$pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

function getRoundName($round_number, $total_teams) {
    $round_labels = [
        2 => ['Semifinals', 'Finals'],
        3 => ['Quarterfinals', 'Semifinals', 'Finals'],
        4 => ['Round of 16', 'Quarterfinals', 'Semifinals', 'Finals'],
        5 => ['Round of 32', 'Round of 16', 'Quarterfinals', 'Semifinals', 'Finals']
    ];
    $totalRounds = log($total_teams, 2);
    $labels = $round_labels[(int)$totalRounds] ?? [];

    return $labels[$round_number - 1] ?? "Round $round_number";
}


$games = [];
$round = 1;
$game_refs = [];

// First round matchups
for ($i = 0; $i < $total_teams / 2; $i++) {
  $games[] = [
    'round' => $round,
    'home_id' => $teams[$i]['id'],
    'away_id' => $teams[$total_teams - 1 - $i]['id'],
    'round_name' => null
  ];
  $game_refs[] = "Game " . count($games);
}

$current_round_games = $total_teams / 2;

while ($current_round_games > 1) {
  $round++;
  $next_round_games = $current_round_games / 2;
  for ($i = 0; $i < $next_round_games; $i++) {
    $games[] = [
      'round' => $round,
      'home_id' => null,
      'away_id' => null,
      'round_name' => null
    ];
    $game_refs[] = "Game " . count($games);
  }
  $current_round_games = $next_round_games;
}

$total_main_rounds = $round;

// Add 3rd place game
$games[] = [
  'round' => $round + 1,
  'home_id' => null,
  'away_id' => null,
'home_placeholder' => 'Winner of ' . $game_refs[$i * 2],
'away_placeholder' => 'Winner of ' . $game_refs[$i * 2 + 1],

  'round_name' => '3rd Place Match'
];

// Assign round names now
foreach ($games as $i => $g) {
  if (!$games[$i]['round_name']) {
    $games[$i]['round_name'] = getRoundName($g['round'], $total_teams);
  }
}

// Insert into DB
$stmt = $pdo->prepare("INSERT INTO game (category_id, round, round_name, hometeam_id, awayteam_id, game_status, game_date)
VALUES (?, ?, ?, ?, ?, 'upcoming', NULL)");

foreach ($games as $g) {
  $stmt->execute([
    $category_id,
    $g['round'],
    $g['round_name'],
    $g['home_id'],
    $g['away_id']
  ]);
}

$pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);

header("Location: category_details.php?category_id=$category_id&tab=schedule");
exit;
