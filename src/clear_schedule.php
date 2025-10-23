<?php
require 'db.php';
session_start();

// Security: Ensure it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$category_id = (int) ($_POST['category_id'] ?? 0);

if (!$category_id) {
    die("Missing category ID.");
}

try {
    $pdo->beginTransaction();

    // 1. Delete all games for this category
    $deleteGames = $pdo->prepare("DELETE FROM game WHERE category_id = ?");
    $deleteGames->execute([$category_id]);

    // 2. Reset the schedule_generated flag in the category table
    $resetFlag = $pdo->prepare("UPDATE category SET schedule_generated = FALSE WHERE id = ?");
    $resetFlag->execute([$category_id]);

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    die("A database error occurred while trying to clear the schedule. Error: " . $e->getMessage());
}

// Redirect back to the schedule tab
header("Location: category_details.php?category_id=" . $category_id . "&tab=schedule");
exit;
?>