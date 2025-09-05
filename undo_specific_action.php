<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php'; // Your database connection file

$data = json_decode(file_get_contents('php://input'), true);
$log_id = $data['log_id'] ?? null;

if (!$log_id) {
    echo json_encode(['success' => false, 'error' => 'No log ID provided.']);
    exit;
}

// Helper function to get a statistic's ID from its name
function getStatisticId($pdo, $name) {
    static $stat_ids = []; // Use a static array to cache results for the request
    if (isset($stat_ids[$name])) {
        return $stat_ids[$name];
    }
    $stmt = $pdo->prepare("SELECT id FROM statistic WHERE statistic_name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetchColumn();
    if ($result) {
        $stat_ids[$name] = $result;
    }
    return $result;
}

// Use a transaction to ensure all database changes succeed or fail together
$pdo->beginTransaction();

try {
    // 1. Fetch the log entry to be undone
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        throw new Exception("Log entry not found.");
    }
    if ($log['is_undone'] == 1) {
        throw new Exception("This action has already been undone.");
    }

    // 2. Reverse the action based on its type
    $action_type = $log['action_type'];
    $game_id = $log['game_id'];
    $team_id = $log['team_id'];
    $player_id = $log['player_id'];
    $quarter = $log['quarter'];
    $half = $quarter <= 2 ? 1 : ($quarter <= 4 ? 2 : 3);

    switch ($action_type) {
        case 'TIMEOUT':
            // Add the timeout back to the team
            $stmt = $pdo->prepare("
                UPDATE game_timeouts 
                SET remaining_timeouts = remaining_timeouts + 1 
                WHERE game_id = ? AND team_id = ? AND half = ?
            ");
            $stmt->execute([$game_id, $team_id, $half]);
            break;

        case 'FOUL':
            // Decrement the team's foul count for that quarter
            $stmt = $pdo->prepare("
                UPDATE game_team_fouls 
                SET fouls = GREATEST(0, fouls - 1) 
                WHERE game_id = ? AND team_id = ? AND quarter = ?
            ");
            $stmt->execute([$game_id, $team_id, $quarter]);

            // Decrement the player's personal foul count
            $foul_stat_id = getStatisticId($pdo, 'FOUL');
            if ($foul_stat_id && $player_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                    VALUES (?, ?, ?, ?, -1)
                    ON DUPLICATE KEY UPDATE value = value - 1
                ");
                $stmt->execute([$game_id, $player_id, $team_id, $foul_stat_id]);
            }
            break;
            
        case '1PM':
        case '2PM':
        case '3PM':
            $points = 0;
            if ($action_type === '1PM') $points = 1;
            if ($action_type === '2PM') $points = 2;
            if ($action_type === '3PM') $points = 3;

            // Decrement the player's specific point stat
            $stat_id = getStatisticId($pdo, $action_type);
            if ($stat_id && $player_id) {
                 $stmt = $pdo->prepare("
                    INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                    VALUES (?, ?, ?, ?, -1)
                    ON DUPLICATE KEY UPDATE value = value - 1
                ");
                $stmt->execute([$game_id, $player_id, $team_id, $stat_id]);
            }

            // Decrement the team's total score
            $gameStmt = $pdo->prepare("SELECT hometeam_id FROM game WHERE id = ?");
            $gameStmt->execute([$game_id]);
            $gameInfo = $gameStmt->fetch(PDO::FETCH_ASSOC);

            if ($gameInfo) {
                $score_column = ($team_id == $gameInfo['hometeam_id']) ? 'hometeam_score' : 'awayteam_score';
                $updateScoreStmt = $pdo->prepare("
                    UPDATE game SET $score_column = GREATEST(0, $score_column - ?) WHERE id = ?
                ");
                $updateScoreStmt->execute([$points, $game_id]);
            }
            break;
        
        // Handle other stats
        case 'REB':
        case 'AST':
        case 'BLK':
        case 'STL':
        case 'TO':
             $stat_id = getStatisticId($pdo, $action_type);
             if ($stat_id && $player_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                    VALUES (?, ?, ?, ?, -1)
                    ON DUPLICATE KEY UPDATE value = value - 1
                ");
                $stmt->execute([$game_id, $player_id, $team_id, $stat_id]);
             }
             break;
    }

    // 3. Mark the log entry as "undone"
    $stmt = $pdo->prepare("UPDATE game_log SET is_undone = 1 WHERE id = ?");
    $stmt->execute([$log_id]);

    $pdo->commit();
    
    // 4. Fetch the newly updated log entry to send back to the front-end
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $updated_log = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'log_entry' => $updated_log]);

} catch (Exception $e) {
    $pdo->rollBack(); // Roll back all changes if an error occurred
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}