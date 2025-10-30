<?php
// FILENAME: update_player_status.php
// DESCRIPTION: API endpoint to update a player's 'is_playing' status and jersey number for a specific game.

header('Content-Type: application/json'); // Respond with JSON
require_once 'db.php'; // Database connection

// --- 1. Get Input ---
$data = json_decode(file_get_contents('php://input'), true);
// Basic validation: Check if required data is present.
if (!$data || !isset($data['game_id'], $data['player_id'], $data['team_id'])) {
  http_response_code(400); // Bad Request
  error_log("Invalid input in update_player_status.php: " . json_encode($data));
  echo json_encode(['error' => 'Invalid input']);
  exit;
}

try {
  // --- 2. Update Database (Transaction) ---
  $pdo->beginTransaction();
  // Prepare the UPDATE statement for the `player_game` table.
  $stmt = $pdo->prepare("
    UPDATE player_game
    SET is_playing = ?, jersey_number = ?
    WHERE game_id = ? AND player_id = ? AND team_id = ?
  ");
  // Execute the update with sanitized data.
  $stmt->execute([
    $data['is_playing'] ?? 0, // Default to 0 (not playing) if not provided
    isset($data['jersey_number']) ? $data['jersey_number'] : null, // Use null if jersey is not set
    $data['game_id'],
    $data['player_id'],
    $data['team_id']
  ]);
  $pdo->commit(); // Commit the changes.
  
  // --- 3. Send Response ---
  echo json_encode(['success' => true]);

} catch (Exception $e) {
  // --- 4. Handle Errors ---
  $pdo->rollBack();
  error_log("Error in update_player_status.php: " . $e->getMessage());
  http_response_code(500); // Internal Server Error
  echo json_encode(['error' => 'Failed to update player status']);
}

exit; // Explicitly exit
?>