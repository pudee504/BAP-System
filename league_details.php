<?php
require 'db.php';

if (!isset($_GET['id'])) {
    echo "No league ID provided.";
    exit;
}
$league_id = (int) $_GET['id']; // convert to integer for safety

// Fetch the league using PDO
$stmt = $pdo->prepare("SELECT * FROM league WHERE id = ?");
$stmt->execute([$league_id]);
$league = $stmt->fetch();

if (!$league) {
    echo "League not found.";
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  
  <meta charset="UTF-8">
  <title>Create League</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'header.php'; ?>

<div class="dashboard-container">
  <h1><?= htmlspecialchars($league['league_name']) ?> </h1>

  <div class="league-info">
    <p><strong>Location:</strong> <?= htmlspecialchars($league['location']) ?></p>
    <p><strong>Start Date:</strong> <?= date('F j, Y', strtotime($league['start_date'])) ?></p>
    <p><strong>End Date:</strong> <?= date('F j, Y', strtotime($league['end_date'])) ?></p>
  <hr>

  <!-- Create Category Form -->
  <form>
    <a href="add_category.php?league_id=<?= $league_id ?>" class="create-league-button"> + Add Category</a>


  </form>
  <?php
// Fetch categories for this league
$catStmt = $pdo->prepare("SELECT * FROM category WHERE league_id = ?");
$catStmt->execute([$league_id]);
$categories = $catStmt->fetchAll();
?>

<?php if ($categories): ?>
  <!-- ... Top of your file remains unchanged ... -->

<h2>Categories</h2>
<table class="category-table">
  <thead>
    <tr>
      <th>No.</th>
      <th>Category Name</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($categories as $index => $category): ?>
      <tr>
        <td><?= $index + 1 ?></td>
        <td>
          <a href="category_details.php?category_id=<?= $category['id'] ?>">
            <?= htmlspecialchars($category['category_name']) ?>
          </a>
        </td>
        <td>
          <a href="edit_category.php?id=<?= $category['id'] ?>&league_id=<?= $league_id ?>">Edit</a> |
          <a href="delete_category.php?id=<?= $category['id'] ?>&league_id=<?= $league_id ?>" onclick="return confirm('Are you sure you want to delete this category?');">Delete</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php else: ?>
  <p>No categories created yet.</p>
<?php endif; ?>
</div>
</body>
