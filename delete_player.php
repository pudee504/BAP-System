<?php
require 'db.php';

$id = (int) ($_GET['id'] ?? 0);
$team_id = (int) ($_GET['team_id'] ?? 0);

if ($id) {
    // Remove from `player_team` first to satisfy foreign key constraint
    $pdo->prepare("DELETE FROM player_team WHERE player_id = ?")->execute([$id]);

    // Then delete from `player`
    $pdo->prepare("DELETE FROM player WHERE id = ?")->execute([$id]);
}

header("Location: team_details.php?team_id=$team_id");
exit;
