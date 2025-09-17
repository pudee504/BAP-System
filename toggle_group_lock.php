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

try {
    if ($new_lock_status === 0) { // --- UNLOCKING LOGIC ---
        // 1. Safety Check: Ensure no games are completed.
        $gamesPlayedStmt = $pdo->prepare("SELECT COUNT(*) FROM game WHERE category_id = ? AND winnerteam_id IS NOT NULL");
        $gamesPlayedStmt->execute([$category_id]);
        if ($gamesPlayedStmt->fetchColumn() > 0) {
            $_SESSION['swap_message'] = 'Error: Cannot unlock groups because one or more games have been completed.';
            header("Location: {$redirect_url}");
            exit;
        }

        // 2. Perform actions in a transaction for safety
        $pdo->beginTransaction();

        // 2a. Delete all games for this category
        $deleteStmt = $pdo->prepare("DELETE FROM game WHERE category_id = ?");
        $deleteStmt->execute([$category_id]);

        // 2b. Update category to unlock groups and reset schedule flag
        $updateStmt = $pdo->prepare("UPDATE category SET groups_locked = 0, schedule_generated = 0 WHERE id = ?");
        $updateStmt->execute([$category_id]);
        
        $pdo->commit();
        $_SESSION['swap_message'] = 'Groups have been unlocked and the schedule has been cleared successfully.';

    } else { // --- LOCKING LOGIC ---
        // Just update the lock status
        $stmt = $pdo->prepare("UPDATE category SET groups_locked = 1 WHERE id = ?");
        $stmt->execute([$category_id]);
        $_SESSION['swap_message'] = 'Groups have been successfully locked.';
    }
} catch (Exception $e) {
    // If anything goes wrong, roll back the transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['swap_message'] = 'Error: A database error occurred. Could not update the status.';
    // For debugging, you might want to log the error: error_log($e->getMessage());
}

// Redirect back to the standings page
header("Location: {$redirect_url}");
exit;