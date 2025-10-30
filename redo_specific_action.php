<?php
// FILENAME: redo_specific_action.php
// DESCRIPTION: API endpoint to re-apply a game action that was previously undone via the game log.

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

/**
 * Helper function to get the ID for a statistic name (e.g., 'FOUL', '2PM').
 * Uses static caching for efficiency within a single request.
 */
function getStatisticId($pdo, $name) {
    static $stat_ids = []; // Cache results
    if (isset($stat_ids[$name])) return $stat_ids[$name];
    $stmt = $pdo->prepare("SELECT id FROM statistic WHERE statistic_name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetchColumn();
    if ($result) $stat_ids[$name] = $result; // Store in cache if found
    return $result;
}

$pdo->beginTransaction();

try {
    // --- 1. Fetch the Log Entry ---
    // Get the details of the action to be redone.
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) throw new Exception("Log entry not found.");
    // Ensure the action was actually undone before redoing it.
    if ($log['is_undone'] == 0) throw new Exception("This action has not been undone, so it cannot be redone.");

    $action_type = $log['action_type'];
    $game_id = $log['game_id'];
    $team_id = $log['team_id'];
    $player_id = $log['player_id'];
    $quarter = $log['quarter'];

    // --- 2. Re-apply the Action based on its type ---
    switch ($action_type) {
        case 'TIMEOUT':
            // Determine the correct timeout period (half or OT) based on the quarter.
            if ($quarter <= 2) { $timeout_period = 1; } 
            else if ($quarter <= 4) { $timeout_period = 2; } 
            else { $timeout_period = $quarter; } // OT periods

            // Decrement the remaining timeouts for the correct team and period.
            $stmt = $pdo->prepare(
                "UPDATE game_timeouts SET remaining_timeouts = GREATEST(0, remaining_timeouts - 1) 
                 WHERE game_id = ? AND team_id = ? AND half = ?"
            );
            $stmt->execute([$game_id, $team_id, $timeout_period]);
            break;

        case 'FOUL': // Standard defensive or loose ball foul
            // a. Increment team foul count for the quarter.
            $stmt = $pdo->prepare(
                "UPDATE game_team_fouls SET fouls = fouls + 1 
                 WHERE game_id = ? AND team_id = ? AND quarter = ?"
            );
            $stmt->execute([$game_id, $team_id, $quarter]);

            // b. Increment player foul count.
            $foul_stat_id = getStatisticId($pdo, 'FOUL');
            if ($foul_stat_id && $player_id) {
                // Use ON DUPLICATE KEY UPDATE to handle existing stat entries.
                $stmt = $pdo->prepare(
                    "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                     VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE value = value + 1"
                );
                $stmt->execute([$game_id, $player_id, $team_id, $foul_stat_id]);
            }
            break;

        case 'FOUL_OFFENSIVE': // Offensive foul
            // Only increment player foul count, not team fouls.
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
        case '3PM': // Points scored
            $points = ($action_type === '1PM') ? 1 : (($action_type === '2PM') ? 2 : 3);
            
            // a. Increment player stat for the specific point type.
            $stat_id = getStatisticId($pdo, $action_type);
            if ($stat_id && $player_id) {
                 $stmt = $pdo->prepare(
                     "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
                      VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE value = value + 1"
                 );
                 $stmt->execute([$game_id, $player_id, $team_id, $stat_id]);
            }

            // b. Increment team score.
            $gameStmt = $pdo->prepare("SELECT hometeam_id FROM game WHERE id = ?");
            $gameStmt->execute([$game_id]);
            $gameInfo = $gameStmt->fetch(PDO::FETCH_ASSOC);

            if ($gameInfo) {
                // Determine whether to update hometeam_score or awayteam_score.
                $score_column = ($team_id == $gameInfo['hometeam_id']) ? 'hometeam_score' : 'awayteam_score';
                $updateScoreStmt = $pdo->prepare(
                    "UPDATE game SET $score_column = $score_column + ? WHERE id = ?"
                );
                $updateScoreStmt->execute([$points, $game_id]);
            }
            break;
            
        // Other single-increment player stats
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

    // --- 3. Mark the Log Entry as Redone ---
    // Set is_undone back to 0 (false).
    $stmt = $pdo->prepare("UPDATE game_log SET is_undone = 0 WHERE id = ?");
    $stmt->execute([$log_id]);
    
    $pdo->commit(); // Finalize all database changes.
    
    // --- 4. Return Updated Log Entry ---
    // Fetch the log entry again to confirm the 'is_undone' status is updated.
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $updated_log = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'log_entry' => $updated_log]);

} catch (Exception $e) {
    // --- Error Handling ---
    $pdo->rollBack(); // Undo any partial changes.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>