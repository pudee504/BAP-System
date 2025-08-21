<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

include 'db.php';

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$params = [];
$leagues = [];

if ($role_id == 1) {
    // --- User is an ADMIN ---
    $query = "SELECT * FROM league";
    if (!empty($search)) {
        $query .= " WHERE league_name LIKE :search";
        $params['search'] = "%$search%";
    }
    $query .= " ORDER BY start_date DESC";

} elseif ($role_id == 2) {
    // --- User is LEAGUE STAFF ---
    // MODIFIED QUERY: Use GROUP_CONCAT to fetch all assignment IDs for each league
    $query = "SELECT l.*, GROUP_CONCAT(lma.assignment_id) AS assignments
              FROM league l
              JOIN league_manager_assignment lma ON l.id = lma.league_id
              WHERE lma.user_id = :user_id";
    $params['user_id'] = $user_id;

    if (!empty($search)) {
        $query .= " AND l.league_name LIKE :search";
        $params['search'] = "%$search%";
    }

    $query .= " GROUP BY l.id ORDER BY l.start_date DESC";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$leagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - League Management</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="dashboard-container">
    <h1 style="margin-bottom: 20px">Leagues</h1>

    <form method="GET" action="dashboard.php" class="search-form">
        <input type="text" name="search" placeholder="Search leagues..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Search</button>
    </form>

    <?php if ($role_id == 1): ?>
        <a href="create_league.php" class="create-league-button">+ Create League</a>
    <?php endif; ?>
    
    <div class="table-wrapper">
        <table class="leagues-table">
            <thead>
            <tr>
                <th>League Name</th>
                <th>Location</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
                <?php if (count($leagues) > 0): ?>
                    <?php foreach ($leagues as $league): ?>
                        <tr>
                            <td>
                                <a href="league_details.php?id=<?= $league['id'] ?>">
                                    <?= htmlspecialchars($league['league_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($league['location']) ?></td>
                            <td><?= htmlspecialchars($league['status']) ?></td>
                            <td>
                                <?php
                                // --- LOGIC CHANGE ---
                                // A user has manager permissions if:
                                // 1. They are a site-wide Admin (role_id 1)
                                // 2. OR they are Staff (role_id 2) AND have the "Full Management" (assignment_id 1) permission for THIS league.
                                $hasManagerPermission = ($role_id == 1) || (isset($league['assignments']) && in_array('1', explode(',', $league['assignments'])));
                                ?>

                                <?php if ($hasManagerPermission): ?>
                                    <a href="edit_league.php?id=<?= $league['id'] ?>">Edit</a> |
                                    <a href="delete_league.php?id=<?= $league['id'] ?>" onclick="return confirm('Are you sure you want to delete this league?');">Delete</a>
                                <?php else: ?>
                                    <a href="league_details.php?id=<?= $league['id'] ?>">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="no-results">
                        <td colspan="4">
                            <div class="no-results-message">
                                <?php if ($role_id == 2): ?>
                                    You have not been assigned to any leagues.
                                <?php else: ?>
                                    No leagues found.
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>