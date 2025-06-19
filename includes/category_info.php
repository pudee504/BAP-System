<?php
require_once __DIR__ . '/../db.php';

$category_id = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);

if (!$category_id) {
    die("Invalid category ID.");
}

// Fetch category details
$stmt = $pdo->prepare("
    SELECT c.category_name, f.format_name, cf.format_id, cf.num_teams, cf.num_groups, cf.advance_per_group, cf.is_locked, c.schedule_generated
    FROM category c
    JOIN category_format cf ON c.id = cf.category_id
    JOIN format f ON cf.format_id = f.id
    WHERE c.id = ?
");
$stmt->execute([$category_id]);
$category = $stmt->fetch();

if (!$category) {
    die("Category not found.");
}

// If Round Robin, ensure clusters exist
if (strtolower($category['format_name']) === 'round robin') {
    $checkClusters = $pdo->prepare("SELECT COUNT(*) FROM cluster WHERE category_id = ?");
    $checkClusters->execute([$category_id]);
    $existing_clusters = (int) $checkClusters->fetchColumn();

    if ($existing_clusters === 0 && $category['num_groups'] > 0) {
        $insertCluster = $pdo->prepare("INSERT INTO cluster (category_id, cluster_name) VALUES (?, ?)");
        for ($i = 1; $i <= $category['num_groups']; $i++) {
            $insertCluster->execute([$category_id, $i]);
        }
    }
}

// Determine format
$is_round_robin = strtolower($category['format_name']) === 'round robin';

// Fetch clusters
$clusterStmt = $pdo->prepare("SELECT * FROM cluster WHERE category_id = ? ORDER BY cluster_name ASC");
$clusterStmt->execute([$category_id]);
$clusters = $clusterStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch teams
$teamStmt = $pdo->prepare("SELECT * FROM team WHERE category_id = ? ORDER BY seed ASC");
$teamStmt->execute([$category_id]);
$teams = $teamStmt->fetchAll();
$team_count = count($teams);
$remaining_slots = $category['num_teams'] - $team_count;

// Group teams by cluster
$teams_by_cluster = [];
foreach ($clusters as $cluster) {
    $teams_by_cluster[$cluster['id']] = [];
}
foreach ($teams as $team) {
    if ($team['cluster_id']) {
        $teams_by_cluster[$team['cluster_id']][] = $team;
    }
}
?>
