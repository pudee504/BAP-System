<?php
require_once 'db.php';

header('Content-Type: application/json');

$game_id = $_GET['game_id'] ?? null;
if (!$game_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Game ID is required.']);
    exit;
}

try {
    // Check if a timer entry exists
    $stmt = $pdo->prepare("SELECT * FROM game_timer WHERE game_id = ?");
    $stmt->execute([$game_id]);
    $timer = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no timer exists, create a default one
    if (!$timer) {
        $insert_stmt = $pdo->prepare(
            "INSERT INTO game_timer (game_id, game_clock, shot_clock, quarter_id, running) VALUES (?, ?, ?, ?, ?)"
        );
        // Default: 10 minutes (600,000 ms), 24s shot clock, 1st quarter, not running
        $insert_stmt->execute([$game_id, 600000, 24000, 1, 0]);
        
        // Re-fetch the newly created timer
        $stmt->execute([$game_id]);
        $timer = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Fetch scores
    $score_stmt = $pdo->prepare("SELECT hometeam_score, awayteam_score FROM game WHERE id = ?");
    $score_stmt->execute([$game_id]);
    $scores = $score_stmt->fetch(PDO::FETCH_ASSOC);

    // Combine and format the state
    $state = [
        'game_clock' => (int)$timer['game_clock'],
        'shot_clock' => (int)$timer['shot_clock'],
        'quarter_id' => (int)$timer['quarter_id'],
        'running' => (bool)$timer['running'],
        'hometeam_score' => (int)($scores['hometeam_score'] ?? 0),
        'awayteam_score' => (int)($scores['awayteam_score'] ?? 0)
    ];

    echo json_encode($state);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}