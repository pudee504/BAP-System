<?php
// FILENAME: swap_bracket_position.php
// DESCRIPTION: API endpoint to swap two teams' positions in the 'bracket_positions' table.
// This is called by the drag-and-drop JavaScript on the bracket visualizer page.

// Disable error reporting for a clean JSON response, even if warnings occur.
error_reporting(0);
ini_set('display_errors', 0);

// Include the database connection file.
require_once __DIR__ . '/../db.php';

// Set the content type header to indicate a JSON response.
header('Content-Type: application/json');

try {
    // Read the raw POST data (which is a JSON string) from the request body.
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON received.');
    }

    // Get the data from the decoded JSON.
    $category_id = $data['category_id'] ?? null;
    $pos1 = $data['position1'] ?? null; // Position of the dragged team
    $pos2 = $data['position2'] ?? null; // Position of the drop target team

    // Validate the incoming data.
    if (!$category_id || !$pos1 || !$pos2 || $pos1 == $pos2) {
        throw new Exception('Invalid data provided for the swap.');
    }

    // Start a database transaction to ensure the swap is atomic (all or nothing).
    $pdo->beginTransaction();
    
    // Find the team_id currently at the first position.
    $stmt1 = $pdo->prepare("SELECT team_id FROM bracket_positions WHERE category_id = ? AND position = ?");
    $stmt1->execute([$category_id, $pos1]);
    $row1 = $stmt1->fetch(PDO::FETCH_ASSOC);

    // Find the team_id currently at the second position.
    $stmt2 = $pdo->prepare("SELECT team_id FROM bracket_positions WHERE category_id = ? AND position = ?");
    $stmt2->execute([$category_id, $pos2]);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

    if ($row1 === false || $row2 === false) {
        throw new Exception("One or both of the swap positions could not be found.");
    }

    $team1_id = $row1['team_id'];
    $team2_id = $row2['team_id'];

    // Create a reusable update statement.
    $updateStmt = $pdo->prepare("UPDATE bracket_positions SET team_id = ? WHERE category_id = ? AND position = ?");
    
    // Perform the swap:
    // Put team 2 into position 1
    $updateStmt->execute([$team2_id, $category_id, $pos1]);
    // Put team 1 into position 2
    $updateStmt->execute([$team1_id, $category_id, $pos2]);

    // Commit the transaction to make the changes permanent.
    $pdo->commit();
    
    // Send a success response back to the JavaScript.
    echo json_encode(['status' => 'success', 'message' => 'Swap successful.']);

} catch (Throwable $e) {
    // If anything went wrong, roll back any changes from the transaction.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Set an HTTP 500 Server Error status code.
    http_response_code(500);
    // Send an error response back to the JavaScript.
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
?>