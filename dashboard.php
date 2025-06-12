<?php
session_start();

// Protect the page
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=Please login first');
    exit;
}

include 'db.php';

// Handle search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$query = "SELECT * FROM league";
$params = [];

if (!empty($search)) {
    $query .= " WHERE league_name LIKE ?";
    $params[] = "%$search%";
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
<?php include 'header.php'; ?>

<div class="dashboard-container">
    <h1 style="margin-bottom: 20px">Leagues</h1>

    <form method="GET" action="dashboard.php" class="search-form">
        <input type="text" name="search" placeholder="Search leagues..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Search</button>
    </form>

    <a href="create_league.php" class="create-league-button">+ Create League</a>

    <div class="table-wrapper">
        <table class="leagues-table">
            <thead>
                <tr>
                    <th>League Name</th>
                    <th>Location</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($leagues) > 0): ?>
                    <?php foreach ($leagues as $league): ?>
                        <tr>
                            <td><?= htmlspecialchars($league['league_name']) ?></td>
                            <td><?= htmlspecialchars($league['location']) ?></td>
                            <td><?= htmlspecialchars($league['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="no-results">
                        <td colspan="3">
                            <div class="no-results-message">No leagues found.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>


