<?php
session_start();
require 'db.php'; // Your database connection file

// --- Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['category_id']) || !isset($_POST['lock_status'])) {
    header('Location: index.php'); // Redirect if accessed improperly
    exit;
}

$category_id = $_POST['category_id'];
$new_lock_status = (int)$_POST['lock_status']; // 1 for lock, 0 for unlock
$redirect_url = "category_details.php?category_id={$category_id}&tab=standings";

// Extra safety check for unlocking
if ($new_lock_status === 0) {
    $gamesPlayedStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
    $gamesPlayedStmt->execute([$category_id]);
    if ($gamesPlayedStmt->fetchColumn() > 0) {
        // A game has been played, so we cannot allow unlocking.
        $_SESSION['swap_message'] = 'Error: Cannot unlock groups because one or more games have been completed.';
        header("Location: {$redirect_url}");
        exit;
    }
}

// --- Update the Database ---
try {
    $stmt = $pdo->prepare("UPDATE category SET groups_locked = ? WHERE id = ?");
    $stmt->execute([$new_lock_status, $category_id]);
    
    if ($new_lock_status === 1) {
        $_SESSION['swap_message'] = 'Groups have been successfully locked.';
    } else {
        $_SESSION['swap_message'] = 'Groups have been unlocked.';
    }

} catch (Exception $e) {
    $_SESSION['swap_message'] = 'Error: A database error occurred. Could not update lock status.';
}

// Redirect back to the standings page
header("Location: {$redirect_url}");
exit;