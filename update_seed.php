<?php
require 'db.php';

$team_id = (int) ($_POST['team_id'] ?? 0);
$new_seed = (int) ($_POST['new_seed'] ?? 0);

if ($team_id && $new_seed) {
    $stmt = $pdo->prepare("UPDATE team SET seed = ? WHERE id = ?");
    $stmt->execute([$new_seed, $team_id]);
    echo "success";
} else {
    http_response_code(400);
    echo "Invalid input";
}
