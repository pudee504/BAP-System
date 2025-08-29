<?php
// redo_specific_action.php (UPDATED AND COMPLETE)
session_start();
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$log_id = $data['log_id'] ?? null;

if (!$log_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Log ID']);
    exit;
}

try {
    // Start a transaction for safety
    $pdo->beginTransaction();

    // Find the specific log entry by its ID
    $stmt = $pdo->prepare("SELECT * FROM game_log WHERE id = ?");
    $stmt->execute([$log_id]);
    $action_to_redo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$action_to_redo) {
        throw new Exception("Action to redo not found in log.");
    }
    
    // Define which stats can be redone
    $stat_map = ['1PM', '2PM', '3PM', 'FOUL', 'REB', 'AST', 'BLK', 'STL', 'TO'];
    
    // RE-APPLY the stat change
    if (in_array($action_to_redo['action_type'], $stat_map)) {
        $redo_stmt = $pdo->prepare(
            "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
             SELECT ?, ?, ?, s.id, 1 FROM statistic s WHERE s.statistic_name = ?
             ON DUPLICATE KEY UPDATE value = value + 1"
        );
        $redo_stmt->execute([
            $action_to_redo['game_id'],
            $action_to_redo['player_id'],
            $action_to_redo['team_id'],
            $action_to_redo['action_type']
        ]);
    }
    // (You can add more logic here for redoing timeouts, etc.)

    // Mark the log entry as NOT undone
    $update_stmt = $pdo->prepare("UPDATE game_log SET is_undone = 0 WHERE id = ?");
    $update_stmt->execute([$action_to_redo['id']]);

    // Commit the changes to the database
    $pdo->commit();

    // Return the updated log entry so the frontend can redraw it
    $action_to_redo['is_undone'] = 0;
    echo json_encode(['success' => true, 'log_entry' => $action_to_redo]);

} catch (Exception $e) {
    // If anything fails, roll back all changes
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>