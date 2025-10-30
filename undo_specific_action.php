<?php
// FILENAME: undo_specific_action.php
// DESCRIPTION: API endpoint to reverse a specific game action recorded in the `game_log`.
// Updates game scores, player stats, team fouls, and timeouts accordingly.

session_start();
header('Content-Type: application/json');
require_once 'db.php';

// Get JSON data sent from the client (game log interface).
$data = json_decode(file_get_contents('php://input'), true);
$log_id = $data['log_id'] ?? null;

if (!$log_id) {
    echo json_encode(['success' => false, 'error' => 'No log ID provided.']);
    exit;
}

/** Helper function to get the ID for a statistic name. */
function getStatisticId($pdo, $name) {
    static $stat_ids = []; // Cache results
    if (isset($stat_ids[$name])) return $stat_ids[$name];
    $stmt = $pdo->prepare("SELECT id FROM statistic WHERE statistic_name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetchColumn();
    if ($result) $stat_ids[$name] = $result;
    return $result;
}

$pdo->beginTransaction();

try {
    // --- 1. Fetch the Log Entry ---
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) throw new Exception("Log entry not found.");
    // Ensure the action hasn't already been undone.
    if ($log['is_undone'] == 1) throw new Exception("This action has already been undone.");

    $action_type = $log['action_type'];
    $game_id = $log['game_id'];
    $team_id = $log['team_id'];
    $player_id = $log['player_id'];
    $quarter = $log['quarter'];

    // --- 2. Reverse the Action based on its type ---
    switch ($action_type) {
        case 'TIMEOUT':
            // Determine the correct timeout period based on the quarter.
            if ($quarter <= 2) { $timeout_period = 1; }
            else if ($quarter <= 4) { $timeout_period = 2; }
            else { $timeout_period = $quarter; } // OT periods
            
            // Increment the remaining timeouts for the team/period.
            $stmt = $pdo->prepare(
                "UPDATE game_timeouts SET remaining_timeouts = remaining_timeouts + 1 
                 WHERE game_id = ? AND team_id = ? AND half = ?"
            );
            $stmt->execute([$game_id, $team_id, $timeout_period]);
            break;

        case 'FOUL': // Standard foul
            // a. Decrement team foul count (ensure it doesn't go below 0).
            $stmt = $pdo->prepare(
                "UPDATE game_team_fouls SET fouls = GREATEST(0, fouls - 1) 
                 WHERE game_id = ? AND team_id = ? AND quarter = ?"
            );
            $stmt->execute([$game_id, $team_id, $quarter]);

            // b. Decrement player foul stat.
            $foul_stat_id = getStatisticId($pdo, 'FOUL');
            if ($foul_stat_id && $player_id) {
                // Use negative value with ON DUPLICATE KEY UPDATE to subtract.
                $stmt = $pdo->prepare(
                    "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                     VALUES (?, ?, ?, ?, -1) ON DUPLICATE KEY UPDATE value = GREATEST(0, value - 1)"
                );
                $stmt->execute([$game_id, $player_id, $team_id, $foul_stat_id]);
            }
            break;
            
        case 'FOUL_OFFENSIVE': // Offensive foul
            // Only decrement player foul stat, not team fouls.
            $foul_stat_id = getStatisticId($pdo, 'FOUL');
            if ($foul_stat_id && $player_id) {
                $stmt = $pdo->prepare(
                    "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                     VALUES (?, ?, ?, ?, -1) ON DUPLICATE KEY UPDATE value = GREATEST(0, value - 1)"
                );
                $stmt->execute([$game_id, $player_id, $team_id, $foul_stat_id]);
            }
            break;
            
        case '1PM':
        case '2PM':
        case '3PM': // Points scored
            // Determine points value.
            $points = 0;
            if ($action_type === '1PM') $points = 1;
            if ($action_type === '2PM') $points = 2;
            if ($action_type === '3PM') $points = 3;

            // a. Decrement player stat for the specific point type.
            $stat_id = getStatisticId($pdo, $action_type);
            if ($stat_id && $player_id) {
                 $stmt = $pdo->prepare(
                     "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                      VALUES (?, ?, ?, ?, -1) ON DUPLICATE KEY UPDATE value = GREATEST(0, value - 1)"
                 );
                 $stmt->execute([$game_id, $player_id, $team_id, $stat_id]);
            }

            // b. Decrement team score.
            $gameStmt = $pdo->prepare("SELECT hometeam_id FROM game WHERE id = ?");
            $gameStmt->execute([$game_id]);
            $gameInfo = $gameStmt->fetch(PDO::FETCH_ASSOC);

            if ($gameInfo) {
                // Determine which score column to update.
                $score_column = ($team_id == $gameInfo['hometeam_id']) ? 'hometeam_score' : 'awayteam_score';
                // Subtract points (ensure score doesn't go below 0).
                $updateScoreStmt = $pdo->prepare(
                    "UPDATE game SET $score_column = GREATEST(0, $score_column - ?) WHERE id = ?"
                );
                $updateScoreStmt->execute([$points, $game_id]);
            }
            break;
        
        // Other single-decrement player stats
        case 'REB':
        case 'AST':
        case 'BLK':
        case 'STL':
        case 'TO':
             $stat_id = getStatisticId($pdo, $action_type);
             if ($stat_id && $player_id) {
                 $stmt = $pdo->prepare(
                     "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                      VALUES (?, ?, ?, ?, -1) ON DUPLICATE KEY UPDATE value = GREATEST(0, value - 1)"
                 );
                 $stmt->execute([$game_id, $player_id, $team_id, $stat_id]);
             }
             break;
    }

    // --- 3. Mark the Log Entry as Undone ---
    // Set `is_undone` to 1 (true).
    $stmt = $pdo->prepare("UPDATE game_log SET is_undone = 1 WHERE id = ?");
    $stmt->execute([$log_id]);

    $pdo->commit(); // Finalize changes.
    
    // --- 4. Return Updated Log Entry ---
    // Fetch the entry again to confirm the update.
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $updated_log = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'log_entry' => $updated_log]);

} catch (Exception $e) {
    // --- Error Handling ---
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>