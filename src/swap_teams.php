<?php
session_start();
require 'db.php';

// --- VALIDATION ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['team1_id']) || empty($_POST['team2_id']) || empty($_POST['category_id'])) {
    header('Location: index.php'); // Or a generic error page
    exit;
}

$category_id = $_POST['category_id'];
$team1_id = (int)$_POST['team1_id'];
$team2_id = (int)$_POST['team2_id'];
$redirect_url = "category_details.php?category_id={$category_id}&tab=standings";

// --- DATABASE LOGIC ---
try {
    $pdo->beginTransaction();

    // 1. Get current cluster IDs for both teams
    $stmt = $pdo->prepare("SELECT id, cluster_id FROM team WHERE id IN (?, ?)");
    $stmt->execute([$team1_id, $team2_id]);
    $teams_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (count($teams_data) !== 2) {
        throw new Exception("One or both teams not found.");
    }
    
    $team1_cluster_id = $teams_data[$team1_id];
    $team2_cluster_id = $teams_data[$team2_id];
    
    // 2. Perform the swap
    // Update team 1 with team 2's cluster ID
    $update1 = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
    $update1->execute([$team2_cluster_id, $team1_id]);
    
    // Update team 2 with team 1's original cluster ID
    $update2 = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
    $update2->execute([$team1_cluster_id, $team2_id]);
    
    $pdo->commit();
    $_SESSION['swap_message'] = 'Teams swapped successfully!';
    
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['swap_message'] = 'Error: A database error occurred. Swap failed.';
}

header("Location: {$redirect_url}");
exit;