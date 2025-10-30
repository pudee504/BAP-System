<?php
// FILENAME: update_stat.php
// DESCRIPTION: API endpoint to add or subtract a statistic value for a player in a game.
// Uses `ON DUPLICATE KEY UPDATE` to efficiently handle stat increments/decrements.

require_once 'db.php';
header('Content-Type: application/json'); // Respond with JSON

// --- 1. Get Input ---
$data = json_decode(file_get_contents('php://input'), true);

$game_id = $data['game_id'] ?? null;
$player_id = $data['player_id'] ?? null;
$team_id = $data['team_id'] ?? null;
$stat_name = $data['statistic_name'] ?? null; // e.g., '2PM', 'FOUL', 'REB'
$value = isset($data['value']) ? (int)$data['value'] : 0; // The amount to add (positive) or subtract (negative)

// --- 2. Validate Input ---
if (!$game_id || !$player_id || !$team_id || !$stat_name || !$value) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// --- 3. Get Statistic ID ---
// Find the numeric ID corresponding to the statistic name (e.g., '2PM' -> 2).
$stmt = $pdo->prepare("SELECT id FROM statistic WHERE statistic_name = ?");
$stmt->execute([$stat_name]);
$stat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stat) {
    echo json_encode(['success' => false, 'error' => 'Invalid stat name']);
    exit;
}
$statistic_id = $stat['id'];

try {
    // --- 4. Update Database ---
    // Prepare the INSERT...ON DUPLICATE KEY UPDATE statement.
    // If a record for this player/game/stat exists, it adds the $value to the existing 'value'.
    // If not, it inserts a new record with the given $value.
    $stmt = $pdo->prepare("
        INSERT INTO game_statistic (game_id, player_id, team_id, statistic_id, value)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE value = GREATEST(0, value + VALUES(value)) -- Use GREATEST to prevent negative stats
    ");
    $stmt->execute([$game_id, $player_id, $team_id, $statistic_id, $value]);

    // --- 5. Send Response ---
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    // --- 6. Handle Errors ---
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>