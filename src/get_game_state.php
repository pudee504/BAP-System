<?php
header('Content-Type: application/json');
require_once 'db.php';

$game_id = $_GET['game_id'] ?? '';
if (!$game_id) {
    echo json_encode(['success' => false, 'error' => 'Game ID required.']);
    exit;
}

// 1. Fetch game details, including team IDs
$game_stmt = $pdo->prepare("SELECT hometeam_id, awayteam_id, hometeam_score, awayteam_score FROM game WHERE id = ?");
$game_stmt->execute([$game_id]);
$game = $game_stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    echo json_encode(['success' => false, 'error' => 'Game not found.']);
    exit;
}

// === NEW: FETCH CURRENT QUARTER AND PERIODS ===
$timer_stmt = $pdo->prepare("SELECT quarter_id FROM game_timer WHERE game_id = ?");
$timer_stmt->execute([$game_id]);
$current_quarter = $timer_stmt->fetchColumn() ?: 1;

if ($current_quarter <= 2) { $timeout_period = 1; } 
elseif ($current_quarter <= 4) { $timeout_period = 2; } 
else { $timeout_period = $current_quarter; } // For overtime

// === NEW: FUNCTIONS TO LOAD FOULS AND TIMEOUTS (adapted from manager page) ===
function loadTeamFouls(PDO $pdo, $game_id, $team_id, $quarter) {
    $stmt = $pdo->prepare("SELECT fouls FROM game_team_fouls WHERE game_id = ? AND team_id = ? AND quarter = ?");
    $stmt->execute([$game_id, $team_id, $quarter]);
    return (int)($stmt->fetchColumn() ?? 0);
}

function loadTimeouts(PDO $pdo, $game_id, $team_id, $period) {
    $stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ?");
    $stmt->execute([$game_id, $team_id, $period]);
    $result = $stmt->fetchColumn();
    if ($result !== false) { return (int)$result; }
    if ($period == 1) { return 2; } 
    elseif ($period == 2) { return 3; } 
    else { return 1; }
}

$home_timeouts = loadTimeouts($pdo, $game_id, $game['hometeam_id'], $timeout_period);
$away_timeouts = loadTimeouts($pdo, $game_id, $game['awayteam_id'], $timeout_period);
$home_fouls = loadTeamFouls($pdo, $game_id, $game['hometeam_id'], $current_quarter);
$away_fouls = loadTeamFouls($pdo, $game_id, $game['awayteam_id'], $current_quarter);

// 2. Fetch all player stats for the game (this query is unchanged)
$player_query = "
    SELECT 
        pg.team_id, p.id AS player_id, p.first_name, p.last_name, pg.jersey_number, pg.is_playing,
        COALESCE(SUM(CASE WHEN s.statistic_name = '1PM' THEN gs.value ELSE 0 END), 0) AS `1PM`,
        COALESCE(SUM(CASE WHEN s.statistic_name = '2PM' THEN gs.value ELSE 0 END), 0) AS `2PM`,
        COALESCE(SUM(CASE WHEN s.statistic_name = '3PM' THEN gs.value ELSE 0 END), 0) AS `3PM`,
        COALESCE(SUM(CASE WHEN s.statistic_name = 'FOUL' THEN gs.value ELSE 0 END), 0) AS `FOUL`,
        COALESCE(SUM(CASE WHEN s.statistic_name = 'REB' THEN gs.value ELSE 0 END), 0) AS `REB`,
        COALESCE(SUM(CASE WHEN s.statistic_name = 'AST' THEN gs.value ELSE 0 END), 0) AS `AST`,
        COALESCE(SUM(CASE WHEN s.statistic_name = 'BLK' THEN gs.value ELSE 0 END), 0) AS `BLK`,
        COALESCE(SUM(CASE WHEN s.statistic_name = 'STL' THEN gs.value ELSE 0 END), 0) AS `STL`,
        COALESCE(SUM(CASE WHEN s.statistic_name = 'TO' THEN gs.value ELSE 0 END), 0) AS `TO`
    FROM player_game pg
    JOIN player p ON p.id = pg.player_id
    LEFT JOIN game_statistic gs ON pg.player_id = gs.player_id AND pg.game_id = gs.game_id
    LEFT JOIN statistic s ON gs.statistic_id = s.id
    WHERE pg.game_id = ?
    GROUP BY pg.team_id, p.id
    ORDER BY pg.team_id, pg.is_playing DESC, pg.display_order ASC
";
$stats_stmt = $pdo->prepare($player_query);
$stats_stmt->execute([$game_id]);
$all_players = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Combine and return as JSON, now with new data
$response = [
    'success' => true,
     'current_quarter' => $current_quarter, // <-- ADD THIS LINE
    'scores' => [
        'home' => $game['hometeam_score'] ?? 0,
        'away' => $game['awayteam_score'] ?? 0,
    ],
    'team_stats' => [
        'home_timeouts' => $home_timeouts,
        'away_timeouts' => $away_timeouts,
        'home_fouls' => $home_fouls,
        'away_fouls' => $away_fouls
    ],
    'players' => $all_players
];

echo json_encode($response);
?>