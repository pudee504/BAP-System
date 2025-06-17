<?php
require 'db.php';
session_start();

$category_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$league_id = filter_var($_GET['league_id'] ?? null, FILTER_VALIDATE_INT);

if (!$category_id || !$league_id) {
    die("Invalid category or league.");
}

// Optional: delete associated teams, etc.

$stmt = $pdo->prepare("DELETE FROM category WHERE id = ?");
$stmt->execute([$category_id]);

header("Location: league_details.php?id=$league_id");
exit;
