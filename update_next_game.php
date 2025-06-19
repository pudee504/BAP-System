<?php
require 'db.php';

$game_id = $_POST['game_id']; // ID of the game just completed

// Step 1: Fetch the game info
$stmt = $pdo->prepare("SELECT * FROM game WHERE id = ?");
$stmt->execute([$game_id]);
$game = $stmt->fetch();

if (!$game || $game['game_status'] !== 'Completed') {
    http_response_code(400);
    echo "Game not completed or not found.";
    exit;
}

$winner_id = $game['winnerteam_id'];
$round = $game['round'];
$category_id = $game['category_id'];

// Step 2: Find the next game in the bracket
$next_round = $round + 1;

$stmt = $pdo->prepare("
    SELECT * FROM game 
    WHERE category_id = ? AND round = ? 
    ORDER BY id ASC
");
$stmt->execute([$category_id, $next_round]);
$next_games = $stmt->fetchAll();

foreach ($next_games as $next_game) {
    if ($next_game['hometeam_id'] === null) {
        $update = $pdo->prepare("UPDATE game SET hometeam_id = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([$winner_id, $next_game['id']]);
        echo "Updated next game ID {$next_game['id']} as home team.\n";
        break;
    } elseif ($next_game['awayteam_id'] === null) {
        $update = $pdo->prepare("UPDATE game SET awayteam_id = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([$winner_id, $next_game['id']]);
        echo "Updated next game ID {$next_game['id']} as away team.\n";
        break;
    }
}
?>
