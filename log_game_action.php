<?php
// FILENAME: log_game_action.php
// DESCRIPTION: API endpoint to receive game actions (like points scored, fouls) from the game manager page
// and insert them into the `game_log` table.

require_once 'db.php';
header('Content-Type: application/json');

// Get JSON data sent from the game manager interface.
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

// Extract data from the input JSON.
$game_id = $input['game_id'] ?? null;
$player_id = $input['player_id'] ?? null; // Can be null for team actions like timeouts
$team_id = $input['team_id'] ?? null;
$quarter = $input['quarter'] ?? null;
$game_clock = $input['game_clock'] ?? null; // Clock time in milliseconds from the browser
$action_type = $input['action_type'] ?? null; // e.g., 'SCORE', 'FOUL'
$action_details = $input['action_details'] ?? null; // e.g., '+2', 'Personal'

// --- Validate required fields ---
if (!$game_id || !$team_id || !$quarter || !isset($game_clock) || !$action_type || !$action_details) {
    echo json_encode(['success' => false, 'error' => 'Missing required log data from browser.']);
    exit;
}

try {
    // --- Insert the log entry into the database ---
    // Uses the data directly provided by the client.
    $stmt = $pdo->prepare(
        "INSERT INTO game_log (game_id, player_id, team_id, quarter, game_clock_ms, action_type, action_details, is_undone) 
         VALUES (?, ?, ?, ?, ?, ?, ?, 0)" // is_undone defaults to 0 (false)
    );
    $stmt->execute([$game_id, $player_id, $team_id, $quarter, $game_clock, $action_type, $action_details]);
    $log_id = $pdo->lastInsertId(); // Get the ID of the newly inserted log entry

    // Respond with success and the new log entry's ID.
    if ($log_id) {
        echo json_encode(['success' => true, 'log_id' => $log_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to insert log entry.']);
    }

} catch (PDOException $e) {
    // Handle potential database errors.
    http_response_code(500); // Set appropriate HTTP status code
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>