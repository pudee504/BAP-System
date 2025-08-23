<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON received.');
    }

    $category_id = $data['category_id'] ?? null;
    $pos1 = $data['position1'] ?? null;
    $pos2 = $data['position2'] ?? null;

    if (!$category_id || !$pos1 || !$pos2 || $pos1 == $pos2) {
        throw new Exception('Invalid data provided for the swap.');
    }

    $pdo->beginTransaction();
    
    $stmt1 = $pdo->prepare("SELECT team_id FROM bracket_positions WHERE category_id = ? AND position = ?");
    $stmt1->execute([$category_id, $pos1]);
    $row1 = $stmt1->fetch(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT team_id FROM bracket_positions WHERE category_id = ? AND position = ?");
    $stmt2->execute([$category_id, $pos2]);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

    if ($row1 === false || $row2 === false) {
        throw new Exception("One or both of the swap positions could not be found.");
    }

    $team1_id = $row1['team_id'];
    $team2_id = $row2['team_id'];

    $updateStmt = $pdo->prepare("UPDATE bracket_positions SET team_id = ? WHERE category_id = ? AND position = ?");
    
    $updateStmt->execute([$team2_id, $category_id, $pos1]);
    $updateStmt->execute([$team1_id, $category_id, $pos2]);

    $pdo->commit();
    
    echo json_encode(['status' => 'success', 'message' => 'Swap successful.']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>