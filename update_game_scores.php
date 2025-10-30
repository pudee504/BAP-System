<?php
// FILENAME: update_game_scores.php
// DESCRIPTION: API endpoint to update the home and away scores for a specific game.
// Typically called by the manage_game.php interface when points are added/removed.

require_once 'db.php';
header('Content-Type: application/json'); // Respond with JSON

// --- 1. Get Input ---
$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;
$hometeam_score = $input['hometeam_score'] ?? 0;
$awayteam_score = $input['awayteam_score'] ?? 0;

// --- 2. Validate Input ---
if (!$game_id) {
    echo json_encode(['success' => false, 'error' => 'Missing game ID.']);
    exit;
}

try {
    // --- 3. Update Database ---
    // Prepare and execute the UPDATE statement.
    $stmt = $pdo->prepare(
        "UPDATE game SET hometeam_score = ?, awayteam_score = ? WHERE id = ?"
    );
    // Execute with integer-casted scores.
    $success = $stmt->execute([(int)$hometeam_score, (int)$awayteam_score, $game_id]);

    // --- 4. Send Response ---
    echo json_encode(['success' => $success]);

} catch (PDOException $e) {
    // --- 5. Handle Errors ---
    http_response_code(500); // Set appropriate HTTP status
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>