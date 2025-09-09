<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$pdo = require 'db.php';

$user_id_to_delete = $_GET['id'] ?? null;

if ($user_id_to_delete) {
    // Begin a transaction
    $pdo->beginTransaction();
    try {
        // Step 1: Delete all assignments for this user from the linking table.
        // This is necessary because your schema does not have ON DELETE CASCADE.
        $stmt_assign = $pdo->prepare("DELETE FROM league_manager_assignment WHERE user_id = ?");
        $stmt_assign->execute([$user_id_to_delete]);

        // Step 2: Delete the user from the users table.
        $stmt_user = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt_user->execute([$user_id_to_delete]);
        
        // If both queries succeed, commit the transaction
        $pdo->commit();
    } catch (Exception $e) {
        // If anything fails, roll back the transaction
        $pdo->rollBack();
        // Optionally, you could set an error message in the session and display it on users.php
        // For now, we'll just redirect.
    }
}

// Redirect back to the user list
header('Location: users.php');
exit();