<?php
// FILENAME: delete_user.php
// DESCRIPTION: Admin-only script to delete a user and their league assignments.

session_start();
// --- Authentication & Authorization Check ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pdo = require 'db.php';

// --- 1. Get User ID ---
$user_id_to_delete = $_GET['id'] ?? null;

if ($user_id_to_delete) {
    // --- 2. Database Deletion (Transaction) ---
    $pdo->beginTransaction();
    try {
        // a. Delete user's league assignments first (due to lack of CASCADE).
        $stmt_assign = $pdo->prepare("DELETE FROM league_manager_assignment WHERE user_id = ?");
        $stmt_assign->execute([$user_id_to_delete]);

        // b. Delete the user record.
        $stmt_user = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt_user->execute([$user_id_to_delete]);
        
        // c. Commit if both deletions succeed.
        $pdo->commit();
        // TODO: Add logging for successful deletion.
    } catch (Exception $e) {
        // d. Roll back on any error.
        $pdo->rollBack();
        
    }
}

// --- 3. Redirect Back ---
// Always redirect back to the user list, whether deletion succeeded or not.
header('Location: users.php');
exit();
?>