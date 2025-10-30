<?php
// FILENAME: use_timeout.php
// DESCRIPTION: API endpoint called when a team uses a timeout during a game.
// Decrements the remaining timeouts for the specified team and period (half/OT).

require_once 'db.php'; // Database connection

header('Content-Type: application/json'); // Respond with JSON
$input = json_decode(file_get_contents('php://input'), true);

// --- 1. Get Input ---
$game_id = $input['game_id'] ?? null;
$team_id = $input['team_id'] ?? null;
$period = $input['half'] ?? null; // 'half' represents the period (1=1st H, 2=2nd H, 5=OT1, 6=OT2...)

// --- 2. Validate Input ---
if (!$game_id || !$team_id || !$period) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
    exit;
}

try {
    // --- 3. Database Update (Transaction) ---
    $pdo->beginTransaction();

    // a. Check existing timeouts for this period (lock the row for update).
    $stmt = $pdo->prepare("SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ? FOR UPDATE");
    $stmt->execute([$game_id, $team_id, $period]);
    $existing_timeouts = $stmt->fetchColumn();
    
    $current_timeouts = 0;

    // b. Determine current timeout count (use defaults if no record exists).
    if ($existing_timeouts !== false) {
        $current_timeouts = (int)$existing_timeouts; // Use value from DB
    } else {
        // Use FIBA default timeouts based on the period.
        if ($period == 1)      { $current_timeouts = 2; } // 1st Half
        else if ($period == 2) { $current_timeouts = 3; } // 2nd Half
        else                   { $current_timeouts = 1; } // Overtime
    }

    // c. Check if timeouts are available.
    if ($current_timeouts <= 0) {
        $pdo->rollBack(); // No changes needed
        echo json_encode(['success' => false, 'error' => 'No timeouts remaining for this period.']);
        exit;
    }

    // d. Decrement timeout count.
    $new_remaining = $current_timeouts - 1;

    // e. Use INSERT...ON DUPLICATE KEY UPDATE to create or update the record efficiently.
    $upsert_stmt = $pdo->prepare("
        INSERT INTO game_timeouts (game_id, team_id, half, remaining_timeouts)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE remaining_timeouts = ?
    ");
    // Both VALUES() and the final parameter use the new decremented value.
    $upsert_stmt->execute([$game_id, $team_id, $period, $new_remaining, $new_remaining]);

    $pdo->commit(); // Finalize changes.

    // --- 4. Send Response ---
    // Return success and the new remaining timeout count.
    echo json_encode(['success' => true, 'remaining' => $new_remaining]);

} catch (Exception $e) {
    // --- 5. Handle Errors ---
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // TODO: Consider adding logging here.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>