<?php
require_once 'db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;
$hometeam_score = $input['hometeam_score'] ?? 0;
$awayteam_score = $input['awayteam_score'] ?? 0;

if (!$game_id) {
    echo json_encode(['success' => false, 'error' => 'Missing game ID.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "UPDATE game SET hometeam_score = ?, awayteam_score = ? WHERE id = ?"
    );
    $success = $stmt->execute([(int)$hometeam_score, (int)$awayteam_score, $game_id]);

    echo json_encode(['success' => $success]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
