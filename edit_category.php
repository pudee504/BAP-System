<?php
require 'db.php';
session_start();

$category_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$league_id = filter_var($_GET['league_id'] ?? null, FILTER_VALIDATE_INT);
if (!$category_id || !$league_id) {
    die("Invalid request.");
}

$stmt = $pdo->prepare("SELECT * FROM category WHERE id = ?");
$stmt->execute([$category_id]);
$category = $stmt->fetch();

if (!$category) {
    die("Category not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = trim($_POST['category_name'] ?? '');

    if ($category_name) {
        $update = $pdo->prepare("UPDATE category SET category_name = ? WHERE id = ?");
        $update->execute([$category_name, $category_id]);

        header("Location: league_details.php?id=$league_id");
        exit;
    } else {
        $error = "Category name is required.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Category</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-container">
    <body class="login-page">
    <h1>Edit Category</h1>

    <?php if (!empty($error)): ?>
        <p style="color:red"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <label>Category Name:</label><br>
        <input type="text" name="category_name" value="<?= htmlspecialchars($category['category_name']) ?>" required><br><br>
        <button  class="login-button" type="submit">Update Category</button>
    </form>
</div>
</body>
</html>
