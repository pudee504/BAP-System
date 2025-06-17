<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $team_id = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);

    if (!$team_id || !$category_id) die("Missing information.");

    $stmt = $pdo->prepare("DELETE FROM team WHERE id = ?");
    $stmt->execute([$team_id]);

    header("Location: category_details.php?category_id=$category_id#teams");
    exit;
}
