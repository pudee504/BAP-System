<?php
// FILENAME: get_timer_state.php
// DESCRIPTION: API endpoint to fetch the current state of the game clock, shot clock,
// quarter, scores, team fouls, and timeouts for real-time updates.

require_once 'db.php';
header('Content-Type: application/json');

$game_id = $_GET['game_id'] ?? null;
if (!$game_id) {
    echo json_encode(null); // Return null if no game ID is provided
    exit;
}

try {
    // --- 1. Get Timer, Score, and Team IDs ---
    // Fetch core timer info and related game data in one query.
    $stmt = $pdo->prepare("
        SELECT 
            gt.game_clock, gt.shot_clock, gt.quarter_id, gt.running, 
            gt.last_updated_at, 
            g.hometeam_id, g.awayteam_id,
            g.hometeam_score, g.awayteam_score
        FROM game_timer gt
        LEFT JOIN game g ON gt.game_id = g.id
        WHERE gt.game_id = ?
    ");
    $stmt->execute([$game_id]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($state) {
        $home_team_id = $state['hometeam_id'];
        $away_team_id = $state['awayteam_id'];

        // --- 2. Fetch Team Fouls ---
        // Get all foul records for this game.
        $fouls_stmt = $pdo->prepare("SELECT team_id, quarter, fouls FROM game_team_fouls WHERE game_id = ?");
        $fouls_stmt->execute([$game_id]);
        $all_fouls = $fouls_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Organize fouls by team and quarter for easy access in JavaScript.
        $fouls_data = ['home' => [], 'away' => []];
        foreach ($all_fouls as $foul_record) {
            $team_key = ($foul_record['team_id'] == $home_team_id) ? 'home' : 'away';
            $fouls_data[$team_key][$foul_record['quarter']] = (int)$foul_record['fouls'];
        }
        $state['fouls'] = $fouls_data;

        // --- 3. Fetch Timeouts ---
        // Get all timeout records for this game.
        $timeouts_stmt = $pdo->prepare("SELECT team_id, half, remaining_timeouts FROM game_timeouts WHERE game_id = ?");
        $timeouts_stmt->execute([$game_id]);
        $all_timeouts = $timeouts_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Organize timeouts by team and half/period.
        $timeouts_data = ['home' => [], 'away' => []];
        foreach ($all_timeouts as $timeout_record) {
            $team_key = ($timeout_record['team_id'] == $home_team_id) ? 'home' : 'away';
            $timeouts_data[$team_key][$timeout_record['half']] = (int)$timeout_record['remaining_timeouts'];
        }
        $state['timeouts'] = $timeouts_data;

        // --- 4. Format Output and Add Server Time ---
        // Ensure correct data types for JavaScript.
        $state['running'] = (bool)$state['running'];
        $state['game_clock'] = (int)$state['game_clock'];
        $state['shot_clock'] = (int)$state['shot_clock'];
        $state['last_updated_at'] = (int)$state['last_updated_at']; // Unix timestamp (milliseconds)
        // Provide current server time for client-side clock synchronization.
        $state['current_server_time'] = round(microtime(true) * 1000); // Unix timestamp (milliseconds)
        
        echo json_encode($state);

    } else {
        // Return null if no timer state found for the game ID.
        echo json_encode(null);
    }

} catch (Exception $e) {
    // Handle potential database errors.
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>