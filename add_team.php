<?php
require 'db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$category_id = (int) ($_POST['category_id'] ?? 0);
$team_name = trim($_POST['team_name'] ?? '');

if (!$category_id || !$team_name) {
    die("Missing category or team name.");
}

// Get the allowed number of teams
$stmt = $pdo->prepare("
    SELECT cf.num_teams
    FROM category_format cf
    JOIN category c ON c.id = cf.category_id
    WHERE c.id = ?
");
$stmt->execute([$category_id]);
$info = $stmt->fetch();

if (!$info) {
    die("Category not found.");
}

$max_teams = (int) $info['num_teams'];

// Check how many teams are already added
$checkStmt = $pdo->prepare("SELECT COUNT(*) FROM team WHERE category_id = ?");
$checkStmt->execute([$category_id]);
$current_count = (int) $checkStmt->fetchColumn();

if ($current_count >= $max_teams) {
    die("Team limit reached. You cannot add more teams.");
}

// Add the team
$insert = $pdo->prepare("INSERT INTO team (category_id, team_name) VALUES (?, ?)");
$insert->execute([$category_id, $team_name]);

header("Location: category_details.php?category_id=" . $category_id);
exit;
