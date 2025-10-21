<?php
require_once 'db.php';

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

$game_id = $input['game_id'] ?? null;
$team_id = $input['team_id'] ?? null;
$period = $input['half'] ?? null; // The 'half' variable is used for periods (1, 2, 5, 6...)

if (!$game_id || !$team_id || !$period) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check for an existing timeout record for this period
    $stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ? FOR UPDATE");
    $stmt->execute([$game_id, $team_id, $period]);
    $existing_timeouts = $stmt->fetchColumn();
    
    $current_timeouts = 0;

    // Determine the current number of timeouts
    if ($existing_timeouts !== false) {
        // A record already exists, so use its value
        $current_timeouts = (int)$existing_timeouts;
    } else {
        // **THE FIX**: No record exists, so use the correct default based on the period
        if ($period == 1) {
            $current_timeouts = 2; // Default for 1st Half
        } else if ($period == 2) {
            $current_timeouts = 3; // Default for 2nd Half
        } else {
            $current_timeouts = 1; // Default for any Overtime period
        }
    }

    // Check if there are any timeouts left to use
    if ($current_timeouts <= 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'No timeouts remaining for this period.']);
        exit;
    }

    // Decrement and prepare the new value
    $new_remaining = $current_timeouts - 1;

    // **IMPROVEMENT**: Use a single query to either create or update the record.
    // This is more efficient and prevents potential race conditions.
    $upsert_stmt = $pdo->prepare("
        INSERT INTO game_timeouts (game_id, team_id, half, remaining_timeouts)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE remaining_timeouts = ?
    ");
    $upsert_stmt->execute([$game_id, $team_id, $period, $new_remaining, $new_remaining]);

    $pdo->commit();

    echo json_encode(['success' => true, 'remaining' => $new_remaining]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>