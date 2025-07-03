
<?php
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$game_id = $data['game_id'] ?? null;
$player_id = $data['player_id'] ?? null;
$team_id = $data['team_id'] ?? null;
$stat_name = $data['statistic_name'] ?? null;
$value = isset($data['value']) ? (int)$data['value'] : 0;

if (!$game_id || !$player_id || !$team_id || !$stat_name || !$value) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Get statistic_id from statistic_name
$stmt = $pdo->prepare("SELECT id FROM statistic WHERE statistic_name = ?");
$stmt->execute([$stat_name]);
$stat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stat) {
    echo json_encode(['success' => false, 'error' => 'Invalid stat name']);
    exit;
}

$statistic_id = $stat['id'];

// Insert or update
$stmt = $pdo->prepare("
    INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE value = value + VALUES(value)
");
$stmt->execute([$game_id, $player_id, $team_id, $statistic_id, $value]);

echo json_encode(['success' => true]);
