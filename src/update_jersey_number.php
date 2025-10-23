<?php
require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$game_id = $data['game_id'] ?? '';
$player_id = $data['player_id'] ?? '';
$team_id = $data['team_id'] ?? '';
// We leave the original variable as is
$jersey_number = $data['jersey_number'] ?? '';

if (!$game_id || !$player_id || !$team_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// --- FIX STARTS HERE ---
// If the received jersey_number is an empty string, we set the value to save to null.
// Otherwise, we make sure it's an integer.
$jersey_to_save = ($jersey_number === '') ? null : (int)$jersey_number;
// --- FIX ENDS HERE ---


$stmt = $pdo->prepare("
    UPDATE player_game 
    SET jersey_number = ? 
    WHERE game_id = ? AND player_id = ? AND team_id = ?
");

// We now use our new, corrected variable in the execute() call
$success = $stmt->execute([$jersey_to_save, $game_id, $player_id, $team_id]);

echo json_encode(['success' => $success]);