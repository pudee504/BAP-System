<?php
require_once 'db.php';
header('Content-Type: application/json');

$game_id = $_GET['game_id'] ?? null;
if (!$game_id) {
    echo json_encode(null);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            gt.game_clock, 
            gt.shot_clock, 
            gt.quarter_id, 
            gt.running, 
            gt.last_updated_at, 
            g.hometeam_score, 
            g.awayteam_score
        FROM game_timer gt
        LEFT JOIN game g ON gt.game_id = g.id
        WHERE gt.game_id = ?
    ");
    $stmt->execute([$game_id]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($state) {
        // Ensure values have the correct type for JavaScript
        $state['running'] = (bool)$state['running'];
        $state['game_clock'] = (int)$state['game_clock'];
        $state['last_updated_at'] = (int)$state['last_updated_at'];
        
        // ** THE KEY **: Add the current server time to the payload for calculation.
        $state['current_server_time'] = round(microtime(true) * 1000);
        
        echo json_encode($state);
    } else {
        echo json_encode(null);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>