<?php
// FILENAME: update_player_order.php
// DESCRIPTION: API endpoint to save the display order of players within a team for a specific game.
// Called when players are marked as 'in game' or manually reordered.

header('Content-Type: application/json');
require_once 'db.php';

// --- 1. Get Input ---
$data = json_decode(file_get_contents('php://input'), true);
// Expects JSON: { game_id: X, team_id: Y, order: [player_id1, player_id2, ...] }
if (!$data || !isset($data['game_id'], $data['team_id'], $data['order'])) {
  http_response_code(400); // Bad Request
  error_log("Invalid input in update_player_order.php: " . json_encode($data));
  echo json_encode(['error' => 'Invalid input']);
  exit;
}

try {
  // --- 2. Update Database (Transaction) ---
  $pdo->beginTransaction();
  // Prepare a single UPDATE statement to be reused.
  $stmt = $pdo->prepare("UPDATE player_game SET display_order = ? WHERE game_id = ? AND team_id = ? AND player_id = ?");
  
  // Loop through the received player ID order.
  foreach ($data['order'] as $index => $player_id) {
      // Execute the update for each player, setting `display_order` to their index in the array.
      $stmt->execute([$index, $data['game_id'], $data['team_id'], $player_id]);
  }
  
  $pdo->commit(); // Commit all updates together.
  
  // --- 3. Send Response ---
  echo json_encode(['success' => true]);

} catch (Exception $e) {
  // --- 4. Handle Errors ---
  $pdo->rollBack();
  error_log("Error in update_player_order.php: " . $e->getMessage());
  http_response_code(500); // Internal Server Error
  echo json_encode(['error' => 'Failed to update player order']);
}

exit; // Explicitly exit
?>