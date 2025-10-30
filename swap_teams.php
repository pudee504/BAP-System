<?php
// FILENAME: swap_teams.php
// DESCRIPTION: Server-side script to swap the group assignments (cluster_id) of two teams in a Round Robin category.
// Called via POST from the drag-and-drop interface in round_robins_standings.php (Setup mode).

session_start();
require 'db.php'; // Database connection

// --- 1. Validation ---
// Ensure it's a POST request and all required IDs are present.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['team1_id']) || empty($_POST['team2_id']) || empty($_POST['category_id'])) {
    header('Location: index.php'); // Redirect if invalid
    exit;
}

$category_id = $_POST['category_id'];
$team1_id = (int)$_POST['team1_id'];
$team2_id = (int)$_POST['team2_id'];
// URL to redirect back to after the swap attempt.
$redirect_url = "category_details.php?category_id={$category_id}&tab=standings";

// --- 2. Database Swap Logic (Transaction) ---
try {
    $pdo->beginTransaction();

    // a. Get the current cluster IDs for both teams.
    $stmt = $pdo->prepare("SELECT id, cluster_id FROM team WHERE id IN (?, ?)");
    $stmt->execute([$team1_id, $team2_id]);
    // Fetch into an associative array [team_id => cluster_id].
    $teams_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Ensure both teams were found.
    if (count($teams_data) !== 2) {
        throw new Exception("One or both teams not found.");
    }
    
    $team1_cluster_id = $teams_data[$team1_id];
    $team2_cluster_id = $teams_data[$team2_id];
    
    // b. Perform the swap.
    // Update team 1 with team 2's cluster ID.
    $update1 = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
    $update1->execute([$team2_cluster_id, $team1_id]);
    
    // Update team 2 with team 1's original cluster ID.
    $update2 = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
    $update2->execute([$team1_cluster_id, $team2_id]);
    
    // c. Commit the transaction.
    $pdo->commit();
    $_SESSION['swap_message'] = 'Teams swapped successfully!'; // Set success message
    
} catch (Exception $e) {
    // --- 3. Error Handling ---
    $pdo->rollBack(); // Undo changes if anything failed.
    $_SESSION['swap_message'] = 'Error: A database error occurred. Swap failed.'; // Set error message.
}

// --- 4. Redirect Back ---
// Send the user back to the groupings preview page.
header("Location: {$redirect_url}");
exit;
?>