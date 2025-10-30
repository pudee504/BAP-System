<?php
// FILENAME: update_timer_action.php
// DESCRIPTION: Central API endpoint for handling all timer-related actions from the timer_control.php interface.
// Updates game clock, shot clock, quarter, and running status in the database.

require_once 'db.php';
header('Content-Type: application/json'); // Respond with JSON

// --- 1. Get Input ---
$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;
$action = $input['action'] ?? null; // e.g., 'toggle', 'adjustGameClock', 'nextQuarter'

// --- 2. Validate Basic Input ---
if (!$game_id || !$action) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters.']);
    exit;
}

try {
    // --- 3. Fetch Current Timer State (Transaction with Lock) ---
    $pdo->beginTransaction();
    // Fetch the current timer state FOR UPDATE to prevent race conditions.
    $stmt = $pdo->prepare("SELECT * FROM game_timer WHERE game_id = ? FOR UPDATE");
    $stmt->execute([$game_id]);
    $timer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$timer) {
        throw new Exception('Timer not initialized for this game.'); // Trigger rollback
    }
    
    // --- Update local timer state based on client's current clock ---
    // This synchronizes the server state with the client's potentially slightly drifted clock before applying the action.
    if (isset($input['game_clock'])) {
        $timer['game_clock'] = (int)$input['game_clock'];
    }
    if (isset($input['shot_clock'])) {
        $timer['shot_clock'] = (int)$input['shot_clock'];
    }

    // --- Constants for Durations ---
    $overtime_duration = 5 * 60 * 1000;  // 5 minutes (ms)
    $regular_duration = 10 * 60 * 1000; // 10 minutes (ms)
    $default_shot_clock = 24000;        // 24 seconds (ms)
    $offensive_rebound_shot_clock = 14000; // 14 seconds (ms)

    // --- 4. Process Action ---
    // Apply changes to the local $timer array based on the requested action.
    switch ($action) {
        case 'toggle': // Start or Pause the clock
            if ($timer['game_clock'] > 0) { // Only toggle if game clock > 0
                $timer['running'] = !$timer['running'];
            } else {
                $timer['running'] = false; // Ensure clock stays stopped if time is up
            }
            break;
        case 'adjustGameClock': // Add or subtract time from the game clock
            $value = (int)($input['value'] ?? 0);
            $timer['game_clock'] = max(0, $timer['game_clock'] + $value);
            // Ensure shot clock doesn't exceed game clock.
            if ($timer['shot_clock'] > $timer['game_clock']) {
                $timer['shot_clock'] = $timer['game_clock'];
            }
            break;
        case 'adjustShotClock': // Add or subtract time from the shot clock
            $value = (int)($input['value'] ?? 0);
            $max_shot = $default_shot_clock; // Max shot clock is 24s
            // Adjust, ensuring it stays between 0 and max/game clock limit.
            $timer['shot_clock'] = max(0, min($timer['shot_clock'] + $value, min($max_shot, $timer['game_clock'])));
            break;
        case 'resetShotClock': // Reset shot clock to 24s or 14s
            $maxShot = ($input['isOffensive'] ?? false) ? $offensive_rebound_shot_clock : $default_shot_clock;
            // Set to the reset value, but not more than the remaining game clock.
            $timer['shot_clock'] = min($timer['game_clock'], $maxShot);
            break;
        case 'nextQuarter': // Advance to the next quarter
            // Only allowed if clock is stopped.
            if (!$timer['running']) {
                $timer['quarter_id']++;
                // Set appropriate duration (regular or overtime).
                $timer['game_clock'] = $timer['quarter_id'] <= 4 ? $regular_duration : $overtime_duration;
                // Reset shot clock.
                $timer['shot_clock'] = min($timer['game_clock'], $default_shot_clock);
                $timer['running'] = false; // Ensure clock remains paused
            }
            break;
        case 'prevQuarter': // Go back to the previous quarter
             // Only allowed if clock is stopped and not already Q1.
             if (!$timer['running'] && $timer['quarter_id'] > 1) {
                 $timer['quarter_id']--;
                 // Reset clocks to 0 for the previous quarter.
                 $timer['game_clock'] = 0;
                 $timer['shot_clock'] = 0;
                 $timer['running'] = false; // Ensure clock remains paused
             }
             break;
    }

    // Get current server time to store with the updated state.
    $currentTimeMs = round(microtime(true) * 1000);

    // --- 5. Update Database ---
    // Save the modified timer state back to the database.
    $update_stmt = $pdo->prepare(
        "UPDATE game_timer SET game_clock = ?, shot_clock = ?, quarter_id = ?, running = ?, last_updated_at = ? WHERE game_id = ?"
    );
    $update_stmt->execute([
        (int)$timer['game_clock'], 
        (int)$timer['shot_clock'], 
        (int)$timer['quarter_id'], 
        (int)$timer['running'], 
        $currentTimeMs, // Store the timestamp of this update
        $game_id
    ]);
    
    // Fetch current scores to include in the response.
    $scores_stmt = $pdo->prepare("SELECT hometeam_score, awayteam_score FROM game WHERE id = ?");
    $scores_stmt->execute([$game_id]);
    $scores = $scores_stmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit(); // Commit the transaction.

    // --- 6. Send Response ---
    // Include the update timestamp in the response state.
    $timer['last_updated_at'] = $currentTimeMs;

    // Send the complete new state back to the client.
    echo json_encode([
        'success' => true,
        'newState' => array_merge($timer, $scores) // Combine timer and score data
    ]);

} catch (Exception $e) {
    // --- 7. Handle Errors ---
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // TODO: Add failure logging.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>