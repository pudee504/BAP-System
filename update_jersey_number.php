<?php
// FILENAME: update_jersey_number.php
// DESCRIPTION: API endpoint to update a player's jersey number for a specific game.

require_once 'db.php';
header('Content-Type: application/json'); // Respond with JSON

// --- 1. Get Input ---
$data = json_decode(file_get_contents('php://input'), true);

$game_id = $data['game_id'] ?? '';
$player_id = $data['player_id'] ?? '';
$team_id = $data['team_id'] ?? '';
$jersey_number = $data['jersey_number'] ?? ''; // Can be empty string if cleared

// --- 2. Validate Input ---
if (!$game_id || !$player_id || !$team_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// --- 3. Sanitize Jersey Number ---
// Convert empty string to NULL for the database, otherwise cast to integer.
$jersey_to_save = ($jersey_number === '') ? null : (int)$jersey_number;

try {
    // --- 4. Update Database ---
    // Prepare and execute the UPDATE statement for the `player_game` table.
    $stmt = $pdo->prepare("
        UPDATE player_game 
        SET jersey_number = ? 
        WHERE game_id = ? AND player_id = ? AND team_id = ?
    ");
    $success = $stmt->execute([$jersey_to_save, $game_id, $player_id, $team_id]);

    // --- 5. Send Response ---
    echo json_encode(['success' => $success]);

} catch (PDOException $e) {
    // --- 6. Handle Errors ---
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>