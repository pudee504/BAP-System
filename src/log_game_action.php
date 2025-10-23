<?php
require_once 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$game_id = $input['game_id'] ?? null;
$player_id = $input['player_id'] ?? null;
$team_id = $input['team_id'] ?? null;
$quarter = $input['quarter'] ?? null;
$game_clock = $input['game_clock'] ?? null; // Use the clock time sent from the browser
$action_type = $input['action_type'] ?? null;
$action_details = $input['action_details'] ?? null;

// --- FIX: Stricter validation for all required fields ---
if (!$game_id || !$team_id || !$quarter || !isset($game_clock) || !$action_type || !$action_details) {
    echo json_encode(['success' => false, 'error' => 'Missing required log data from browser.']);
    exit;
}

try {
    // --- FIX: Insert the log entry directly with the provided data ---
    // No need to query the timer table here.
    $stmt = $pdo->prepare(
        "INSERT INTO game_log (game_id, player_id, team_id, quarter, game_clock_ms, action_type, action_details, is_undone) 
         VALUES (?, ?, ?, ?, ?, ?, ?, 0)"
    );
    $stmt->execute([$game_id, $player_id, $team_id, $quarter, $game_clock, $action_type, $action_details]);
    $log_id = $pdo->lastInsertId();

    if ($log_id) {
        echo json_encode(['success' => true, 'log_id' => $log_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to insert log entry.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
