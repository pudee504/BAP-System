<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$log_id = $data['log_id'] ?? null;

if (!$log_id) {
    echo json_encode(['success' => false, 'error' => 'No log ID provided.']);
    exit;
}

function getStatisticId($pdo, $name) {
    static $stat_ids = [];
    if (isset($stat_ids[$name])) return $stat_ids[$name];
    $stmt = $pdo->prepare("SELECT id FROM statistic WHERE statistic_name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetchColumn();
    if ($result) $stat_ids[$name] = $result;
    return $result;
}

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) throw new Exception("Log entry not found.");
    if ($log['is_undone'] == 0) throw new Exception("This action has not been undone, so it cannot be redone.");

    $action_type = $log['action_type'];
    $game_id = $log['game_id'];
    $team_id = $log['team_id'];
    $player_id = $log['player_id'];
    $quarter = $log['quarter'];

    switch ($action_type) {
        case 'TIMEOUT':
            // **THE FIX**: Correctly determine the timeout period from the quarter
            if ($quarter <= 2) {
                $timeout_period = 1; // 1st Half
            } else if ($quarter <= 4) {
                $timeout_period = 2; // 2nd Half
            } else {
                $timeout_period = $quarter; // Overtime period (5, 6, etc.)
            }

            $stmt = $pdo->prepare(
                "UPDATE game_timeouts SET remaining_timeouts = GREATEST(0, remaining_timeouts - 1) 
                 WHERE game_id = ? AND team_id = ? AND half = ?"
            );
            $stmt->execute([$game_id, $team_id, $timeout_period]);
            break;

        // ... (rest of your cases for FOUL, 1PM, etc. are correct and remain unchanged)
        case 'FOUL':
            $stmt = $pdo->prepare(
                "UPDATE game_team_fouls SET fouls = fouls + 1 
                 WHERE game_id = ? AND team_id = ? AND quarter = ?"
            );
            $stmt->execute([$game_id, $team_id, $quarter]);

            $foul_stat_id = getStatisticId($pdo, 'FOUL');
            if ($foul_stat_id && $player_id) {
                $stmt = $pdo->prepare(
                    "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                     VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE value = value + 1"
                );
                $stmt->execute([$game_id, $player_id, $team_id, $foul_stat_id]);
            }
            break;

        case '1PM':
        case '2PM':
        case '3PM':
            $points = ($action_type === '1PM') ? 1 : (($action_type === '2PM') ? 2 : 3);
            
            $stat_id = getStatisticId($pdo, $action_type);
            if ($stat_id && $player_id) {
                 $stmt = $pdo->prepare(
                    "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                     VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE value = value + 1"
                );
                $stmt->execute([$game_id, $player_id, $team_id, $stat_id]);
            }

            $gameStmt = $pdo->prepare("SELECT hometeam_id FROM game WHERE id = ?");
            $gameStmt->execute([$game_id]);
            $gameInfo = $gameStmt->fetch(PDO::FETCH_ASSOC);

            if ($gameInfo) {
                $score_column = ($team_id == $gameInfo['hometeam_id']) ? 'hometeam_score' : 'awayteam_score';
                $updateScoreStmt = $pdo->prepare(
                    "UPDATE game SET $score_column = $score_column + ? WHERE id = ?"
                );
                $updateScoreStmt->execute([$points, $game_id]);
            }
            break;
            
        case 'REB':
        case 'AST':
        case 'BLK':
        case 'STL':
        case 'TO':
             $stat_id = getStatisticId($pdo, $action_type);
             if ($stat_id && $player_id) {
                $stmt = $pdo->prepare(
                    "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                     VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE value = value + 1"
                );
                $stmt->execute([$game_id, $player_id, $team_id, $stat_id]);
             }
             break;
    }

    $stmt = $pdo->prepare("UPDATE game_log SET is_undone = 0 WHERE id = ?");
    $stmt->execute([$log_id]);
    
    $pdo->commit();
    
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $updated_log = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'log_entry' => $updated_log]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>