<?php
// FILENAME: redo_specific_action.php (Refactored)
// DESCRIPTION: Re-applies a game action by updating aggregate tables and marking the log entry as 'not undone'.

session_start();
header('Content-Type: application/json');
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$log_id = $data['log_id'] ?? null;

if (!$log_id) {
    echo json_encode(['success' => false, 'error' => 'No log ID provided.']);
    exit;
}

$pdo->beginTransaction();

try {
    // --- 1. Fetch the Log Entry ---
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ? FOR UPDATE");
    $stmt->execute([$log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) throw new Exception("Log entry not found.");
    if ($log['is_undone'] == 0) throw new Exception("This action has not been undone.");

    $action_type = $log['action_type'];
    $game_id = $log['game_id'];
    $team_id = $log['team_id'];
    $quarter = $log['quarter'];

    // --- 2. Re-apply Aggregated Actions ---
    switch ($action_type) {
        case 'TIMEOUT':
            if ($quarter <= 2) { 
                $timeout_period = 1; 
            } else if ($quarter <= 4) { 
                $timeout_period = 2; 
            } else { 
                $timeout_period = $quarter; // OT
            }

            $stmt = $pdo->prepare(
                "UPDATE game_timeouts 
                 SET remaining_timeouts = GREATEST(0, remaining_timeouts - 1) 
                 WHERE game_id = ? AND team_id = ? AND half = ?"
            );
            $stmt->execute([$game_id, $team_id, $timeout_period]);
            break;

        case 'FOUL': // Standard defensive or loose ball foul
            // Increment team foul count for the quarter.
            $stmt = $pdo->prepare(
                "UPDATE game_team_fouls 
                 SET fouls = fouls + 1 
                 WHERE game_id = ? AND team_id = ? AND quarter = ?"
            );
            $stmt->execute([$game_id, $team_id, $quarter]);
            // Player stat is handled by the log, no action needed here.
            break;

        case '1PM':
        case '2PM':
        case '3PM':
            $points = ($action_type === '1PM') ? 1 : (($action_type === '2PM') ? 2 : 3);
            
            // Increment team score in the 'game' table.
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
            // Player stat is handled by the log, no action needed here.
            break;
        
        // FOUL_OFFENSIVE, REB, AST, BLK, STL, TO:
        // These actions only affect player stats, which are read from game_log.
        // Therefore, no aggregate tables need to be updated.
    }

    // --- 3. Mark the Log Entry as Redone ---
    $stmt = $pdo->prepare("UPDATE game_log SET is_undone = 0 WHERE id = ?");
    $stmt->execute([$log_id]);
    
    $pdo->commit(); 
    
    // --- 4. Return Updated Log Entry ---
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $updated_log = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'log_entry' => $updated_log]);

} catch (Exception $e) {
    $pdo->rollBack(); 
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
