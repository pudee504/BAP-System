<?php
// undo_specific_action.php (UPDATED AND COMPLETE)
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
    $action_to_undo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$action_to_undo) {
        throw new Exception("Action not found in log.");
    }

    // Define which stats can be undone
    $stat_map = ['1PM', '2PM', '3PM', 'FOUL', 'REB', 'AST', 'BLK', 'STL', 'TO'];
    
    // Reverse the stat change if it's a standard stat
    if (in_array($action_to_undo['action_type'], $stat_map)) {
        $undo_stmt = $pdo->prepare(
            "INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
             SELECT ?, ?, ?, s.id, -1 FROM statistic s WHERE s.statistic_name = ?
             ON DUPLICATE KEY UPDATE value = value - 1"
        );
        $undo_stmt->execute([
            $action_to_undo['game_id'],
            $action_to_undo['player_id'],
            $action_to_undo['team_id'],
            $action_to_undo['action_type']
        ]);
    }
    // (You can add more logic here for undoing timeouts, etc.)

    // Instead of deleting, mark the log entry as undone
    $update_stmt = $pdo->prepare("UPDATE game_log SET is_undone = 1 WHERE id = ?");
    $update_stmt->execute([$action_to_undo['id']]);

    // Commit the changes to the database
    $pdo->commit();

    // Return the updated log entry so the frontend can redraw it
    $action_to_undo['is_undone'] = 1;
    echo json_encode(['success' => true, 'log_entry' => $action_to_undo]);

} catch (Exception $e) {
    // If anything fails, roll back all changes
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>