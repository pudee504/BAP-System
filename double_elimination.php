<?php
require 'db.php';

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
  die("Missing category_id.");
}

// Fetch teams by seed
$stmt = $pdo->prepare("SELECT id, team_name FROM team WHERE category_id = ? ORDER BY seed ASC");
$stmt->execute([$category_id]);
$teams = $stmt->fetchAll();
$total_teams = count($teams);

// Must be power of 2: 4, 8, 16...
if ($total_teams < 2 || ($total_teams & ($total_teams - 1)) !== 0) {
  die("Team count must be a power of 2 (e.g., 4, 8, 16).");
}

// Delete existing games
$pdo->prepare("DELETE FROM game WHERE category_id = ?")->execute([$category_id]);

// Prepare insertion (NO bracket_type column needed if your DB doesn't support it)
$insert = $pdo->prepare("INSERT INTO game (category_id, round, hometeam_id, awayteam_id, game_status, game_date)
VALUES (?, ?, ?, ?, 'upcoming', NULL)");

// --- 1. Winners Bracket (3 rounds, 7 games total)
$round = 1;
$games = [];
$game_refs = [];

// Round 1 (4 games)
for ($i = 0; $i < $total_teams / 2; $i++) {
  $home = $teams[$i];
  $away = $teams[$total_teams - 1 - $i];
  $games[] = [$round, $home['id'], $away['id']];
}

// Round 2 (2 games)
$round++;
$games[] = [$round, null, null];
$games[] = [$round, null, null];

// Round 3 (1 game)
$round++;
$games[] = [$round, null, null];

// --- 2. Losers Bracket (4 rounds, 6 games total)
$loser_games = [
  [1, null, null],
  [1, null, null],
  [2, null, null],
  [2, null, null],
  [3, null, null],
  [4, null, null],
];

// --- 3. Grand Final (1 game only)
$final_round = 5; // assuming highest round of losers is 4
$games[] = [$final_round, null, null];

// --- 4. Insert into database
foreach ($games as $g) {
  $insert->execute([$category_id, $g[0], $g[1], $g[2]]);
}

foreach ($loser_games as $g) {
  $insert->execute([$category_id, $g[0], $g[1], $g[2]]);
}

// --- 5. Mark schedule as generated
$pdo->prepare("UPDATE category SET schedule_generated = 1 WHERE id = ?")->execute([$category_id]);

// --- 6. Redirect
header("Location: category_details.php?category_id=$category_id&tab=schedule");
exit;
