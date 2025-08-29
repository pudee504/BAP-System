<?php
// log_game_action.php
session_start();
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

try {
    $sql = "INSERT INTO game_log (game_id, player_id, team_id, quarter, game_clock_ms, action_type, action_details) 
            VALUES (:game_id, :player_id, :team_id, :quarter, :game_clock_ms, :action_type, :action_details)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':game_id' => $data['game_id'],
        ':player_id' => $data['player_id'],
        ':team_id' => $data['team_id'],
        ':quarter' => $data['quarter'],
        ':game_clock_ms' => $data['game_clock'],
        ':action_type' => $data['action_type'],
        ':action_details' => $data['action_details']
    ]);

    // Return the newly created log entry with its ID
    echo json_encode(['success' => true, 'log_id' => $pdo->lastInsertId(), 'log_entry' => $data]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>