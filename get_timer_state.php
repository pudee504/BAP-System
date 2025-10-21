<?php
require_once 'db.php';
header('Content-Type: application/json');

$game_id = $_GET['game_id'] ?? null;
if (!$game_id) {
    echo json_encode(null);
    exit;
}

try {
    // 1. Get main timer, score, and team IDs
    $stmt = $pdo->prepare("
        SELECT 
            gt.game_clock, 
            gt.shot_clock, 
            gt.quarter_id, 
            gt.running, 
            gt.last_updated_at, 
            g.hometeam_id, g.awayteam_id,
            g.hometeam_score, 
            g.awayteam_score
        FROM game_timer gt
        LEFT JOIN game g ON gt.game_id = g.id
        WHERE gt.game_id = ?
    ");
    $stmt->execute([$game_id]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($state) {
        $home_team_id = $state['hometeam_id'];
        $away_team_id = $state['awayteam_id'];

        // 2. Fetch all team fouls for the game
        $fouls_stmt = $pdo->prepare("SELECT team_id, quarter, fouls FROM game_team_fouls WHERE game_id = ?");
        $fouls_stmt->execute([$game_id]);
        $all_fouls = $fouls_stmt->fetchAll(PDO::FETCH_ASSOC);

        $fouls_data = ['home' => [], 'away' => []];
        foreach ($all_fouls as $foul_record) {
            $team_key = ($foul_record['team_id'] == $home_team_id) ? 'home' : 'away';
            $fouls_data[$team_key][$foul_record['quarter']] = (int)$foul_record['fouls'];
        }
        $state['fouls'] = $fouls_data;

        // 3. Fetch all timeouts for the game
        $timeouts_stmt = $pdo->prepare("SELECT team_id, half, remaining_timeouts FROM game_timeouts WHERE game_id = ?");
        $timeouts_stmt->execute([$game_id]);
        $all_timeouts = $timeouts_stmt->fetchAll(PDO::FETCH_ASSOC);

        $timeouts_data = ['home' => [], 'away' => []];
        foreach ($all_timeouts as $timeout_record) {
            $team_key = ($timeout_record['team_id'] == $home_team_id) ? 'home' : 'away';
            $timeouts_data[$team_key][$timeout_record['half']] = (int)$timeout_record['remaining_timeouts'];
        }
        $state['timeouts'] = $timeouts_data;

        // 4. Format and add current server time
        $state['running'] = (bool)$state['running'];
        $state['game_clock'] = (int)$state['game_clock'];
        $state['shot_clock'] = (int)$state['shot_clock'];
        $state['last_updated_at'] = (int)$state['last_updated_at'];
        $state['current_server_time'] = round(microtime(true) * 1000);
        
        echo json_encode($state);

    } else {
        echo json_encode(null);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>