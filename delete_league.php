<?php
require 'db.php';
session_start();

$league_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$league_id) {
    die("Invalid league ID.");
}

// Optional: delete related categories, teams, etc. (if needed)

// Delete the league
$stmt = $pdo->prepare("DELETE FROM league WHERE id = ?");
$stmt->execute([$league_id]);

header("Location: dashboard.php");
exit;
