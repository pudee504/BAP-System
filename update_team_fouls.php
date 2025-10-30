<?php
// FILENAME: update_team_fouls.php
// DESCRIPTION: API endpoint to update or insert the team foul count for a specific quarter of a game.

session_start();
require_once 'db.php';
header('Content-Type: application/json'); // Respond with JSON

// --- 1. Get Input ---
$data = json_decode(file_get_contents('php://input'), true);
$game_id = $data['game_id'] ?? null;
$team_id = $data['team_id'] ?? null;
$quarter = $data['quarter'] ?? null;
$fouls = $data['fouls'] ?? null; // The new total foul count for the quarter

// --- 2. Validate Input ---
if (!$game_id || !$team_id || !$quarter || $fouls === null) {
  echo json_encode(['success' => false, 'error' => 'Missing parameters']);
  exit;
}

try {
    // --- 3. Update Database ---
    // Use INSERT...ON DUPLICATE KEY UPDATE to either create or update the foul record.
    $stmt = $pdo->prepare("INSERT INTO game_team_fouls (game_id, team_id, quarter, fouls) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE fouls = VALUES(fouls)");
                            
    // Execute with the provided total foul count.
    if ($stmt->execute([(int)$game_id, (int)$team_id, (int)$quarter, (int)$fouls])) {
        // --- 4. Send Success Response ---
        echo json_encode(['success' => true]);
    } else {
        // --- 5. Send Failure Response (DB Error) ---
        echo json_encode(['success' => false, 'error' => 'DB error during execution']);
    }
} catch (PDOException $e) {
    // --- 5. Handle Errors ---
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>