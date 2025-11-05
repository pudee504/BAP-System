<?php
// FILENAME: undo_specific_action.php (Refactored)
// DESCRIPTION: API endpoint to reverse a specific game action recorded in the `game_log`.
// Reverses aggregate scores (game table) and team fouls (game_team_fouls),
// then marks the log entry as 'is_undone = 1'.

session_start();
header('Content-Type: application/json');
require_once 'db.php';

// Get JSON data sent from the client.
$data = json_decode(file_get_contents('php://input'), true);
$log_id = $data['log_id'] ?? null;

if (!$log_id) {
    echo json_encode(['success' => false, 'error' => 'No log ID provided.']);
    exit;
}

// The getStatisticId() function is no longer needed and has been removed.

$pdo->beginTransaction();

try {
    // --- 1. Fetch the Log Entry ---
    // Lock the row for update to prevent race conditions
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ? FOR UPDATE");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) throw new Exception("Log entry not found.");
    if ($log['is_undone'] == 1) throw new Exception("This action has already been undone.");

    $action_type = $log['action_type'];
    $game_id = $log['game_id'];
    $team_id = $log['team_id'];
    $quarter = $log['quarter'];

    // --- 2. Reverse the Action in AGGREGATE tables ---
    // We only modify aggregate tables. Player stats are handled by the 'is_undone' flag.
    switch ($action_type) {
        case 'TIMEOUT':
            // Determine the correct timeout period.
            if ($quarter <= 2) { $timeout_period = 1; }
            else if ($quarter <= 4) { $timeout_period = 2; }
            else { $timeout_period = $quarter; } // OT periods
            
            // Increment the remaining timeouts (give one back).
            $stmt = $pdo->prepare(
                "UPDATE game_timeouts SET remaining_timeouts = remaining_timeouts + 1 
                 WHERE game_id = ? AND team_id = ? AND half = ?"
            );
            $stmt->execute([$game_id, $team_id, $timeout_period]);
            break;

        case 'FOUL': // Standard foul
            // a. Decrement team foul count.
            $stmt = $pdo->prepare(
                "UPDATE game_team_fouls SET fouls = GREATEST(0, fouls - 1) 
                 WHERE game_id = ? AND team_id = ? AND quarter = ?"
            );
            $stmt->execute([$game_id, $team_id, $quarter]);
            // b. Player foul stats are NOT touched. The log flag handles it.
            break;
            
        case '1PM':
        case '2PM':
        case '3PM': // Points scored
            $points = 0;
            if ($action_type === '1PM') $points = 1;
            if ($action_type === '2PM') $points = 2;
            if ($action_type === '3PM') $points = 3;

            // a. Player point stats are NOT touched. The log flag handles it.

            // b. Decrement team score.
            $gameStmt = $pdo->prepare("SELECT hometeam_id FROM game WHERE id = ?");
            $gameStmt->execute([$game_id]);
            $gameInfo = $gameStmt->fetch(PDO::FETCH_ASSOC);

            if ($gameInfo) {
                $score_column = ($team_id == $gameInfo['hometeam_id']) ? 'hometeam_score' : 'awayteam_score';
                $updateScoreStmt = $pdo->prepare(
                    "UPDATE game SET $score_column = GREATEST(0, $score_column - ?) WHERE id = ?"
                );
                $updateScoreStmt->execute([$points, $game_id]);
            }
            break;
        
        // These actions only affect player stats, which are read from game_log.
        // No aggregate tables are affected, so no action is needed here.
        case 'FOUL_OFFENSIVE':
        case 'REB':
        case 'AST':
        case 'BLK':
        case 'STL':
        case 'TO':
             break;
    }

    // --- 3. Mark the Log Entry as Undone ---
    // This is the most important step for player stats.
    $stmt = $pdo->prepare("UPDATE game_log SET is_undone = 1 WHERE id = ?");
    $stmt->execute([$log_id]);

    $pdo->commit(); // Finalize changes.
    
    // --- 4. Return Updated Log Entry ---
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