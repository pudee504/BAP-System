<?php
// FILENAME: clear_schedule.php
// DESCRIPTION: Deletes all games for a category and resets its "schedule generated" flag.

require 'db.php';
session_start();

// --- 1. Security Check ---
// Ensure this script is called via POST to prevent accidental execution.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

// --- 2. Validate Input ---
$category_id = (int) ($_POST['category_id'] ?? 0);

if (!$category_id) {
    die("Missing category ID.");
}

try {
    // --- 3. Database Operations (Transaction) ---
    $pdo->beginTransaction();

    // a. Delete all games associated with this category.
    $deleteGames = $pdo->prepare("DELETE FROM game WHERE category_id = ?");
    $deleteGames->execute([$category_id]);

    // b. Reset the `schedule_generated` flag to FALSE.
    $resetFlag = $pdo->prepare("UPDATE category SET schedule_generated = FALSE WHERE id = ?");
    $resetFlag->execute([$category_id]);

    $pdo->commit();

} catch (PDOException $e) {
    // If anything fails, roll back the changes.
    $pdo->rollBack();
    die("A database error occurred while trying to clear the schedule. Error: " . $e->getMessage());
}

// --- 4. Redirect Back ---
// Send the user back to the schedule tab for the same category.
header("Location: category_details.php?category_id=" . $category_id . "&tab=schedule");
exit;
?>