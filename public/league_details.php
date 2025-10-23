<?php
session_start();
include '../src/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (!isset($_GET['id'])) {
    echo "No league ID provided.";
    exit;
}
$league_id = (int) $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM league WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    echo "League not found.";
    exit;
}

// Fetch categories for this league
$catStmt = $pdo->prepare("SELECT * FROM category WHERE league_id = ? ORDER BY category_name ASC");
$catStmt->execute([$league_id]);
$categories = $catStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title><?= htmlspecialchars($league['league_name']) ?> - Details</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include '../src/includes/header.php'; ?>

<div class="dashboard-container">
    <div class="page-header">
        <h1><?= htmlspecialchars($league['league_name']) ?></h1>
    </div>

    <div class="league-info">
        <p><strong>Location:</strong> <?= htmlspecialchars($league['location']) ?></p>
        <p><strong>Start Date:</strong> <?= date('F j, Y', strtotime($league['start_date'])) ?></p>
        <p><strong>End Date:</strong> <?= date('F j, Y', strtotime($league['end_date'])) ?></p>
        <p><strong>Status:</strong> <?= htmlspecialchars($league['status']) ?></p>
    </div>
    
    <div class="section-header">
        <h2>Categories</h2>
        <a href="add_category.php?league_id=<?= $league_id ?>" class="btn btn-primary">+ Add Category</a>
    </div>

    <div class="table-wrapper">
        <?php if ($categories): ?>
            <table class="category-table">
                <thead>
                    <tr>
                        <th>Category Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td>
                                <a href="category_details.php?category_id=<?= $category['id'] ?>">
                                    <?= htmlspecialchars($category['category_name']) ?>
                                </a>
                            </td>
                            <td class="actions">
                                <a href="edit_category.php?id=<?= $category['id'] ?>&league_id=<?= $league_id ?>">Edit</a>
                                <a href="delete_category.php?id=<?= $category['id'] ?>&league_id=<?= $league_id ?>" class="action-delete" onclick="return confirm('Are you sure you want to delete this category?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="info-message">No categories have been created for this league yet.</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
