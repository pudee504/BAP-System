<?php
require 'db.php';

$team_id = (int) ($_POST['team_id'] ?? 0);
$cluster_id = (int) ($_POST['cluster_id'] ?? 0);

if ($team_id && $cluster_id) {
    $stmt = $pdo->prepare("UPDATE team SET cluster_id = ? WHERE id = ?");
    $stmt->execute([$cluster_id, $team_id]);
    echo "success";
} else {
    http_response_code(400);
    echo "Invalid input";
}
