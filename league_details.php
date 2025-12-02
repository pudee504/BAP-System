<?php
/* =============================================================================
   FILE: league_details.php
   PURPOSE: Displays a league’s details and its associated categories.
   ACCESS: Logged-in users only.
   ========================================================================== */

session_start();
include 'db.php'; // Database connection

// --- USER AUTHENTICATION CHECK ---
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please login first';
    header('Location: index.php');
    exit;
}

// --- AUTHORIZATION CHECK ---
require_once 'includes/auth_functions.php';
$league_id_auth = (int) ($_GET['id'] ?? 0);
if (!has_league_permission($pdo, $_SESSION['user_id'], 'league', $league_id_auth)) {
    $_SESSION['error'] = 'You do not have permission to view this league.';
    header('Location: dashboard.php');
    exit;
}

// --- VALIDATE AND SANITIZE LEAGUE ID ---
if (!isset($_GET['id'])) {
    echo "No league ID provided.";
    exit;
}
$league_id = (int) $_GET['id'];

// --- FETCH LEAGUE DETAILS ---
$stmt = $pdo->prepare("SELECT * FROM league WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

// --- CHECK IF LEAGUE EXISTS ---
if (!$league) {
    echo "League not found.";
    exit;
}

// --- FETCH LEAGUE CATEGORIES ---
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
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="dashboard-container">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <h1><?= htmlspecialchars($league['league_name']) ?></h1>
    </div>

    <!-- LEAGUE INFO -->
    <div class="league-info">
        <p><strong>Location:</strong> <?= htmlspecialchars($league['location']) ?></p>
        <p><strong>Start Date:</strong> <?= date('F j, Y', strtotime($league['start_date'])) ?></p>
        <p><strong>End Date:</strong> <?= date('F j, Y', strtotime($league['end_date'])) ?></p>
        <p><strong>Status:</strong> <?= htmlspecialchars($league['status']) ?></p>
    </div>
    
    <!-- CATEGORIES SECTION -->
    <div class="section-header">
        <h2>Categories</h2>
        <a href="add_category.php?league_id=<?= $league_id ?>" class="btn btn-primary">+ Add Category</a>
    </div>

    <!-- CATEGORY TABLE -->
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
                                <a href="delete_category.php?id=<?= $category['id'] ?>&league_id=<?= $league_id ?>" class="action-delete"
                                   onclick="return confirm('Are you sure you want to delete this category?');">Delete</a>
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
