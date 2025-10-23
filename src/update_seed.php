<?php
require 'db.php';
session_start();

$team_id = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
$category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT); // Get category_id for duplicate check
$new_seed_value = $_POST['new_seed'] ?? null;

if (!$team_id || !$category_id) {
    http_response_code(400);
    echo "Invalid input: Missing team or category ID.";
    exit;
}

// Server-side duplicate check
if ($new_seed_value !== '') {
    $checkStmt = $pdo->prepare(
        "SELECT id FROM team WHERE category_id = ? AND seed = ? AND id != ?"
    );
    $checkStmt->execute([$category_id, $new_seed_value, $team_id]);
    if ($checkStmt->fetch()) {
        http_response_code(409); // 409 Conflict
        echo "Error: Seed is already taken by another team.";
        exit;
    }
}

// Proceed with the update
try {
    if ($new_seed_value === '') {
        // This handles the '--' option by setting the seed to NULL
        $stmt = $pdo->prepare("UPDATE team SET seed = NULL WHERE id = ?");
        $stmt->execute([$team_id]);
    } else {
        $new_seed = (int) $new_seed_value;
        if ($new_seed > 0) {
            $stmt = $pdo->prepare("UPDATE team SET seed = ? WHERE id = ?");
            $stmt->execute([$new_seed, $team_id]);
        }
    }
    echo "success";

} catch (PDOException $e) {
    http_response_code(500);
    echo "A database error occurred.";
}