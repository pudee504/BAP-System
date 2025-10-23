<?php
require 'db.php';
session_start();

// Ensure this is a POST request for security
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

// Validate all the necessary inputs from the form
$game_id = filter_input(INPUT_POST, 'game_id', FILTER_VALIDATE_INT);
$category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
$game_date = $_POST['game_date'] ?? null;

if (!$game_id || !$category_id || !$game_date) {
    die("Missing or invalid information.");
}

try {
    // Update the game date in the database
    $stmt = $pdo->prepare("UPDATE game SET game_date = ? WHERE id = ?");
    $stmt->execute([$game_date, $game_id]);

} catch (PDOException $e) {
    // In case of a database error
    die("Error updating game date: " . $e->getMessage());
}

// This is the corrected redirect link with the anchor at the end
header("Location: category_details.php?category_id=$category_id&tab=schedule#game-row-$game_id");
exit;