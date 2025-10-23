<?php
// This script ONLY locks the bracket seeding. It does not generate any games.

require_once 'db.php';
session_start();
require_once 'logger.php';

$category_id = $_POST['category_id'] ?? null;
if (!$category_id) {
    die("Error: Missing category_id.");
}

try {
    // This is the only action this script should perform
    $stmt = $pdo->prepare("UPDATE category SET playoff_seeding_locked = 1 WHERE id = ?");
    $stmt->execute([$category_id]);
    
    log_action('LOCK_BRACKET', 'SUCCESS', "Bracket locked for category ID: $category_id.");
    
    // Redirect back to the standings tab so the user can see the locked bracket
    header("Location: category_details.php?category_id=$category_id&tab=standings&lock_success=true");
    exit;

} catch (Exception $e) {
    log_action('LOCK_BRACKET', 'FAILURE', "Error: " . $e->getMessage());
    die("An error occurred while locking the bracket: " . $e->getMessage());
}