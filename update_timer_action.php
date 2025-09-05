<?php
require_once 'db.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$game_id = $input['game_id'] ?? null;
$action = $input['action'] ?? null;

if (!$game_id || !$action) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM game_timer WHERE game_id = ? FOR UPDATE");
    $stmt->execute([$game_id]);
    $timer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$timer) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Timer not initialized for this game.']);
        exit;
    }
    
    // Use the time from the remote control as the source of truth for the clock value
    if (isset($input['game_clock'])) {
        $timer['game_clock'] = (int)$input['game_clock'];
    }
    if (isset($input['shot_clock'])) {
        $timer['shot_clock'] = (int)$input['shot_clock'];
    }

    $overtime_duration = 300 * 1000; // 5 minutes

    switch ($action) {
        case 'toggle':
            if ($timer['game_clock'] > 0) {
                $timer['running'] = !$timer['running'];
            } else {
                $timer['running'] = false;
            }
            break;
        case 'adjustGameClock':
            $value = (int)($input['value'] ?? 0);
            $timer['game_clock'] = max(0, $timer['game_clock'] + $value);
            if ($timer['shot_clock'] > $timer['game_clock']) {
                $timer['shot_clock'] = $timer['game_clock'];
            }
            break;
        case 'adjustShotClock':
            $value = (int)($input['value'] ?? 0);
            $max_shot = 24000;
            $timer['shot_clock'] = max(0, min($timer['shot_clock'] + $value, min($max_shot, $timer['game_clock'])));
            break;
        case 'resetShotClock':
            $maxShot = ($input['isOffensive'] ?? false) ? 14000 : 24000;
            $timer['shot_clock'] = min($timer['game_clock'], $maxShot);
            break;
        case 'nextQuarter':
            $timer['quarter_id']++;
            $timer['game_clock'] = $timer['quarter_id'] <= 4 ? (600 * 1000) : $overtime_duration;
            $timer['shot_clock'] = min($timer['game_clock'], 24000);
            $timer['running'] = false;
            break;
    }

    // Get the current server time in milliseconds to store with the state
    $currentTimeMs = round(microtime(true) * 1000);

    $update_stmt = $pdo->prepare(
        "UPDATE game_timer SET game_clock = ?, shot_clock = ?, quarter_id = ?, running = ?, last_updated_at = ? WHERE game_id = ?"
    );
    $update_stmt->execute([
        (int)$timer['game_clock'], 
        (int)$timer['shot_clock'], 
        (int)$timer['quarter_id'], 
        (int)$timer['running'], 
        $currentTimeMs, // ** THE FIX **: Save the timestamp with every action
        $game_id
    ]);
    
    $scores_stmt = $pdo->prepare("SELECT hometeam_score, awayteam_score FROM game WHERE id = ?");
    $scores_stmt->execute([$game_id]);
    $scores = $scores_stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    // Add the new timestamp to the state returned to the client
    $timer['last_updated_at'] = $currentTimeMs;

    echo json_encode([
        'success' => true,
        'newState' => array_merge($timer, $scores)
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>