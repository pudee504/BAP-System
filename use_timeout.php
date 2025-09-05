<?php
require_once 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;
$team_id = $input['team_id'] ?? null;
$half = $input['half'] ?? null;

if (!$game_id || !$team_id || !$half) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // --- FIX: Check if a record for this half exists ---
    $check_stmt = $pdo->prepare(
        "SELECT remaining_timeouts FROM game_timeouts WHERE game_id = ? AND team_id = ? AND half = ? FOR UPDATE"
    );
    $check_stmt->execute([$game_id, $team_id, $half]);
    $remaining = $check_stmt->fetchColumn();

    $new_remaining = 0;

    // --- FIX: If no record exists, create one ---
    if ($remaining === false) {
        // Define your league's timeout rules here
        $initial_timeouts = ($half == 1) ? 2 : 3; // Example: 2 for 1st half, 3 for 2nd
        
        if ($initial_timeouts > 0) {
            $new_remaining = $initial_timeouts - 1;
            $insert_stmt = $pdo->prepare(
                "INSERT INTO game_timeouts (game_id, team_id, half, remaining_timeouts) VALUES (?, ?, ?, ?)"
            );
            $insert_stmt->execute([$game_id, $team_id, $half, $new_remaining]);
        } else {
            // This case shouldn't happen with the rule above, but it's a safe fallback
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'No timeouts allocated for this half.']);
            exit;
        }
    
    // --- FIX: If a record exists and has timeouts, decrement it ---
    } else if ($remaining > 0) {
        $new_remaining = $remaining - 1;
        $update_stmt = $pdo->prepare(
            "UPDATE game_timeouts SET remaining_timeouts = ? WHERE game_id = ? AND team_id = ? AND half = ?"
        );
        $update_stmt->execute([$new_remaining, $game_id, $team_id, $half]);
        
    // --- FIX: If a record exists but has no timeouts left, fail ---
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'No timeouts remaining for this half.']);
        exit;
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'remaining' => $new_remaining]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
